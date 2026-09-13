"""Bounded local observations. All byte sums use the same socket-I/O basis."""
import json
import sqlite3
import time
from analysis import cadence, observed_mode, resolve, scope, clean_refresh, clean_schedule, observation_window, link_local_peers

SCHEMA = '''
PRAGMA journal_mode=WAL;
PRAGMA auto_vacuum=INCREMENTAL;
CREATE TABLE IF NOT EXISTS buckets (
 minute INTEGER, source TEXT, version TEXT, address TEXT, port INTEGER,
 protocol TEXT, scope TEXT, confidence TEXT, rx INTEGER, tx INTEGER,
 operations INTEGER, first REAL, last REAL,
 PRIMARY KEY(minute,source,version,address,port,protocol,scope,confidence)
);
CREATE TABLE IF NOT EXISTS destinations (
 source TEXT, address TEXT, port INTEGER, protocol TEXT, first REAL, last REAL,
 PRIMARY KEY(source,address,port,protocol)
);
CREATE TABLE IF NOT EXISTS quality (minute INTEGER PRIMARY KEY, seconds REAL);
CREATE TABLE IF NOT EXISTS activity (source TEXT, second INTEGER, PRIMARY KEY(source,second));
CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT);
CREATE INDEX IF NOT EXISTS buckets_source_time ON buckets(source,minute);
'''


class Store:
    def __init__(self, path, root):
        self.db = sqlite3.connect(str(path), check_same_thread=False)
        self.db.executescript(SCHEMA)
        self.db.execute('PRAGMA max_page_count=131072')
        self.root = root
        self.inventory = self.read_state('inventory', {'plugins': {}, 'crons': {}})
        self.settings = self.read_state('settings', {'local_networks': [], 'retention_days': 30})
        self.recent_processes = {}
        self.connections = {'rows':[], 'time':0, 'limited':False}
        self.live = {'rx_rate': 0, 'tx_rate': 0, 'last_sample': None, 'stats': {}}
        self.last_purge = 0

    def read_state(self, key, default):
        row = self.db.execute('SELECT value FROM state WHERE key=?', (key,)).fetchone()
        return json.loads(row[0]) if row else default

    def state(self, key, value):
        self.db.execute('INSERT OR REPLACE INTO state VALUES (?,?)', (key, json.dumps(value)))

    def configure(self, inventory, settings):
        import ipaddress
        plugins = inventory.get('plugins', {})
        if not isinstance(plugins, dict) or len(plugins) > 1000:
            raise ValueError('Inventaire invalide')
        clean = {}
        for key, value in plugins.items():
            if not isinstance(key, str) or not key.replace('_', '').isalnum() or len(key) > 64:
                raise ValueError('Identifiant de plugin invalide')
            clean[key] = {'name': str(value.get('name', key))[:128], 'version': str(value.get('version', ''))[:128],
                          'enabled': value.get('enabled', True) in (True, 1, '1'),
                          'schedules': [clean_schedule(v) for v in value.get('schedules', [])[:100]],
                          'refresh': clean_refresh(value.get('refresh', {}))}
        crons = inventory.get('crons', {})
        if not isinstance(crons, dict) or len(crons) > 10000:
            raise ValueError('Inventaire de crons invalide')
        clean_crons = {str(int(k)): {'class': str(v.get('class', ''))[:128],
                                    'function': str(v.get('function', ''))[:128]}
                       for k, v in crons.items()}
        networks = settings.get('local_networks', [])
        if not isinstance(networks, list) or len(networks) > 100:
            raise ValueError('Réseaux locaux invalides')
        normalized = [str(ipaddress.ip_network(n.strip(), strict=False)) for n in networks if n.strip()]
        settings = {'local_networks': normalized,
                    'retention_days': min(90, max(1, int(settings.get('retention_days', 30))))}
        self.inventory = {'plugins': clean, 'crons': clean_crons}
        self.settings = settings
        with self.db:
            self.state('inventory', self.inventory)
            self.state('settings', settings)

    def ingest(self, sample):
        now = float(sample['time'])
        minute = int(now) // 60 * 60
        elapsed = max(0, min(5, float(sample['elapsed'])))
        same_session = sample['collector_started'] == self.live.get('collector_started')
        previous = self.live.get('decoder', {}) if same_session else {}
        decoder = dict(sample.get('decoder', {}))
        decoding = self.live.get('decoding', 'waiting') if same_session else 'waiting'
        if decoder:
            received = decoder.get('received', 0) - previous.get('received', 0)
            decoded = decoder.get('decoded', 0) - previous.get('decoded', 0)
            if received > 0:
                decoding = 'ready' if decoded > 0 else 'blocked'
        elif sample['rows']:
            decoding = 'ready'
        elif sample.get('stats', {}).get('decode_errors', 0):
            # Transitional support for a collector installed before counters.
            decoding = 'blocked'
        self.diagnostic_record(sample, now, elapsed, decoding)
        total_rx = total_tx = 0
        maintenance = now - self.last_purge > 300
        connection_sample = sample.get('connections')
        if connection_sample and connection_sample['time'] != self.connections['time']:
            self.connections = dict(connection_sample)
            self.connections['rows'] = []
            for connection in connection_sample['rows'][:2048]:
                source, confidence, reason = resolve(connection, self.inventory, self.root)
                self.connections['rows'].append(dict(connection, source=source, confidence=confidence,
                                                     scope=scope(connection['address'], self.settings['local_networks']), reason=reason))
        with self.db:
            self.db.execute('INSERT INTO quality VALUES (?,?) ON CONFLICT(minute) DO UPDATE SET seconds=MIN(60,seconds+excluded.seconds)',
                            (minute, elapsed if decoding == 'ready' else 0))
            for row in sample['rows']:
                source, confidence, reason = resolve(row, self.inventory, self.root)
                category = scope(row['address'], self.settings['local_networks'])
                version = self.inventory.get('plugins', {}).get(source, {}).get('version', '')
                rx, tx = max(0, int(row['rx'])), max(0, int(row['tx']))
                total_rx += rx
                total_tx += tx
                key = (minute, source, version, row['address'], row['port'], row['protocol'], category, confidence)
                self.db.execute('''INSERT INTO buckets VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT(minute,source,version,address,port,protocol,scope,confidence)
                    DO UPDATE SET rx=rx+excluded.rx,tx=tx+excluded.tx,
                    operations=operations+excluded.operations,last=excluded.last''',
                                key + (rx, tx, row['operations'], now, now))
                self.db.execute('''INSERT INTO destinations VALUES (?,?,?,?,?,?)
                    ON CONFLICT(source,address,port,protocol) DO UPDATE SET last=excluded.last''',
                                (source, row['address'], row['port'], row['protocol'], now, now))
                self.db.execute('INSERT OR IGNORE INTO activity VALUES (?,?)', (source, int(now)))
                process_key = '{}:{}:{}:{}:{}'.format(sample['boot'], row['pid'], row['started'], source, reason)
                if process_key not in self.recent_processes and len(self.recent_processes) >= 2048:
                    oldest = min(self.recent_processes, key=lambda k: self.recent_processes[k]['last'])
                    del self.recent_processes[oldest]
                old = self.recent_processes.get(process_key, {'rx': 0, 'tx': 0})
                self.recent_processes[process_key] = {
                    'pid': row['pid'], 'comm': row.get('comm', ''), 'source': source,
                    'reason': reason, 'confidence': confidence, 'rx': old['rx']+rx,
                    'tx': old['tx']+tx, 'last': now, 'netns': row.get('netns')}
            self.live = {'rx_rate': total_rx / elapsed if elapsed else 0,
                         'tx_rate': total_tx / elapsed if elapsed else 0,
                         'last_sample': now, 'collector_started': sample['collector_started'],
                         'stats': sample.get('stats', {}), 'decoder': decoder, 'decoding': decoding}
            if maintenance:
                cutoff = now - self.settings['retention_days'] * 86400
                for table, field in [('buckets', 'minute'), ('quality', 'minute'), ('destinations', 'last')]:
                    self.db.execute('DELETE FROM {} WHERE {}<?'.format(table, field), (cutoff,))
                self.db.execute('DELETE FROM activity WHERE second<?', (now-3600,))
                self.recent_processes = {k: v for k, v in self.recent_processes.items() if v['last'] > now-3600}
                self.last_purge = now
        # Hard cardinality bound independent of retention; prune oldest detail.
        if maintenance and self.db.execute('SELECT COUNT(*) FROM buckets').fetchone()[0] > 250000:
            with self.db:
                self.db.execute('DELETE FROM buckets WHERE minute IN (SELECT minute FROM buckets ORDER BY minute LIMIT 25000)')
                self.state('detail_pruned', now)
        if maintenance:
            with self.db:
                self.db.execute('DELETE FROM activity WHERE rowid IN (SELECT rowid FROM activity ORDER BY second DESC LIMIT -1 OFFSET 250000)')
            self.db.execute('PRAGMA wal_checkpoint(TRUNCATE)')

    def live_status(self):
        live = dict(self.live)
        live['connected'] = bool(live['last_sample'] and time.time()-live['last_sample'] < 8)
        live['operational'] = live['connected'] and live.get('decoding') == 'ready'
        live['collector_error'] = ''
        if live['connected'] and live.get('decoding') == 'blocked':
            live['collector_error'] = 'Événements reçus mais décodage bloqué. ' + live.get('decoder', {}).get('last_error', '')
        elif live['connected'] and not live['operational']:
            live['collector_error'] = 'Collecteur connecté ; en attente du premier événement réseau décodé'
        if not live['connected']:
            live['rx_rate'] = live['tx_rate'] = 0
        return live

    def compare_periods(self, period=86400, source=''):
        period = min(30*86400, max(60, int(period))) // 60 * 60
        end = int(time.time()) // 60 * 60
        def window(start, stop):
            condition = 'minute>=? AND minute<?'
            args = [start, stop]
            if source:
                condition += ' AND source=?'
                args.append(source)
            volumes = self.db.execute('SELECT source,SUM(rx),SUM(tx) FROM buckets WHERE '+condition+' GROUP BY source', args).fetchall()
            destinations = self.db.execute('SELECT DISTINCT address,port,protocol FROM buckets WHERE '+condition, args).fetchall()
            coverage = self.db.execute('SELECT COALESCE(SUM(seconds),0) FROM quality WHERE minute>=? AND minute<?', (start, stop)).fetchone()[0]
            return {'since':start, 'until':stop, 'coverage':coverage, 'volumes':volumes, 'destinations':destinations}
        previous, current = window(end-2*period, end-period), window(end-period, end)
        old, new = set(map(tuple, previous.pop('destinations'))), set(map(tuple, current.pop('destinations')))
        return {'period':period, 'previous':previous, 'current':current,
                'new_destinations':sorted(new-old)[:100], 'absent_destinations':sorted(old-new)[:100],
                'new_count':len(new-old), 'absent_count':len(old-new)}

    def diagnostic_start(self, source):
        import uuid
        if source not in self.inventory.get('plugins', {}):
            raise ValueError('Plugin inconnu')
        previous = getattr(self, 'diagnostic', None)
        if previous and time.time()<previous['until']:
            raise ValueError('Un diagnostic est déjà en cours. Attendez sa fin.')
        now = time.time()
        self.diagnostic = {'id':uuid.uuid4().hex, 'source':source, 'since':now, 'until':now+120,
                           'rows':{}, 'limited':False, 'samples':0, 'coverage':0}
        return self.diagnostic_poll(self.diagnostic['id'])

    def diagnostic_poll(self, identifier):
        value = getattr(self, 'diagnostic', None)
        if not value or value['id'] != identifier:
            raise ValueError('Diagnostic indisponible ; le démon a peut-être redémarré')
        live = self.live_status()
        fresh = live['connected'] and time.time()-self.connections.get('time',0)<25
        connections = [c for c in self.connections['rows'] if c['source']==value['source']] if fresh else []
        return dict(value, rows=list(value['rows'].values()), finished=time.time()>=value['until'], live=live,
                    connections=connections, connections_available=bool(fresh),
                    schedules=self.inventory['plugins'].get(value['source'],{}).get('schedules',[]))

    def diagnostic_record(self, sample, now, elapsed, decoding):
        value = getattr(self, 'diagnostic', None)
        if not value or now>value['until']:
            return
        # Ignore the first overlapping batch: it may contain pre-session bytes.
        if now-elapsed < value['since']:
            return
        value['samples'] += 1
        if decoding == 'ready':
            value['coverage'] += min(elapsed, 5)
        for row in sample['rows']:
            source, confidence, reason = resolve(row, self.inventory, self.root)
            if source not in (value['source'], '_core', '_shared', '_unknown'):
                continue
            key = (source,row['pid'],row['started'],row['address'],row['port'],row['protocol'])
            if key not in value['rows']:
                if len(value['rows'])>=500:
                    value['limited'] = True
                    continue
                value['rows'][key] = dict(source=source,pid=row['pid'],comm=row.get('comm',''),reason=reason,
                    address=row['address'],port=row['port'],protocol=row['protocol'],rx=0,tx=0)
            target = value['rows'][key]
            target['rx'] += max(0,int(row['rx']))
            target['tx'] += max(0,int(row['tx']))

    def snapshot(self, period=86400, source=None, country=None, country_lookup=None, enabled="all", internet_only=False):
        period = min(90*86400, max(60, int(period)))
        now = time.time()
        since = int(now-period) // 60 * 60
        where = 'minute>=?'
        parameters = [since]
        if source:
            where += ' AND source=?'
            parameters.append(source)
        if enabled in ('enabled', 'disabled'):
            ids = [key for key, value in self.inventory.get('plugins', {}).items()
                   if value.get('enabled', True) == (enabled == 'enabled')]
            where += ' AND source IN (' + ','.join('?' for _ in ids) + ')' if ids else ' AND 0'
            parameters.extend(ids)
        if internet_only:
            where += " AND scope='internet'"
        countries = {}
        address_countries = {}
        if country_lookup:
            for address, category in self.db.execute('SELECT DISTINCT address,scope FROM buckets WHERE '+where, parameters):
                geo = country_lookup(address) if category == 'internet' else {}
                code = geo.get('country_code', '')
                code = code if len(code) == 2 and code.isalpha() and code.isupper() else '_unknown'
                if category in ('local', 'loopback', 'multicast', 'broadcast'):
                    code = '_local'
                address_countries[(address, category)] = code
                countries[code] = geo.get('country') or code
        if country:
            self.db.create_function('ps_country', 2, lambda address, category: address_countries.get((address, category), '_unknown'))
            where += ' AND ps_country(address,scope)=?'
            parameters.append(country)
        rows = self.db.execute('SELECT source,scope,confidence,SUM(rx),SUM(tx),MAX(last) FROM buckets WHERE '+where+
                               ' GROUP BY source,scope,confidence', parameters).fetchall()
        plugins = {key: dict(id=key, name=value['name'], version=value['version'], enabled=value.get('enabled', True), rx=0, tx=0,
                            local=0, internet=0, internal=0, unknown=0, high=0, last=None)
                   for key, value in self.inventory.get('plugins', {}).items() if not source or source == key}
        names = {'_core': 'Core Jeedom', '_unknown': 'Non attribué / autres processus', '_shared': 'Jeedom partagé'}
        totals = {'rx': 0, 'tx': 0, 'local': 0, 'internet': 0, 'internal': 0, 'unknown': 0,
                  'high': 0, 'local_rx': 0, 'local_tx': 0, 'internet_rx': 0, 'internet_tx': 0}
        for key, category, confidence, rx, tx, last in rows:
            if key not in plugins:
                plugins[key] = dict(id=key, name=names.get(key, key), version='', enabled=None, rx=0, tx=0,
                                    local=0, internet=0, internal=0, unknown=0, high=0, last=None)
            target = plugins[key]
            bucket = category if category in ('local', 'internet', 'unknown') else 'internal'
            for item in (target, totals):
                item['rx'] += rx
                item['tx'] += tx
                item[bucket] += rx+tx
                item['high'] += rx+tx if confidence == 'high' else 0
            if category in ('local', 'internet'):
                totals[category+'_rx'] += rx
                totals[category+'_tx'] += tx
            target['last'] = max(target['last'] or 0, last)
        for item in plugins.values():
            item['mode'] = observed_mode(item['local'], item['internet'], item['rx']+item['tx'])
            times = [r[0] for r in self.db.execute('SELECT second FROM activity WHERE source=? ORDER BY second DESC LIMIT 600', (item['id'],))]
            item['cadence'] = cadence(times)
        destinations = self.db.execute('''SELECT source,address,port,protocol,scope,SUM(rx),SUM(tx),MIN(first),MAX(last),SUM(operations)
            FROM buckets WHERE '''+where+' GROUP BY source,address,port,protocol,scope ORDER BY SUM(rx+tx) DESC LIMIT 500', parameters).fetchall()
        detail = []
        for row in destinations:
            entry = dict(zip(('source','address','port','protocol','scope','rx','tx','first','last','operations'), row))
            first = self.db.execute('SELECT first FROM destinations WHERE source=? AND address=? AND port=? AND protocol=?', row[:4]).fetchone()
            entry['first_seen'] = first[0] if first else entry['first']
            entry['new'] = entry['first_seen'] >= now-86400
            detail.append(entry)
        internet_destinations = self.db.execute('SELECT COUNT(*) FROM (SELECT address,port,protocol FROM buckets WHERE '+where+
                                               " AND scope='internet' GROUP BY address,port,protocol)", parameters).fetchone()[0]
        resolution = 60 if period <= 3600 else (900 if period <= 86400 else 3600)
        timeline = self.db.execute('SELECT CAST(minute/? AS INTEGER)*?,SUM(rx),SUM(tx) FROM buckets WHERE '+where+
                                  ' GROUP BY CAST(minute/? AS INTEGER) ORDER BY minute', [resolution,resolution]+parameters+[resolution]).fetchall()
        coverage = self.db.execute('SELECT COALESCE(SUM(seconds),0) FROM quality WHERE minute>=?', (since,)).fetchone()[0]
        for item in plugins.values():
            item['schedules'] = self.inventory.get('plugins', {}).get(item['id'], {}).get('schedules', [])
            item['refresh'] = self.inventory.get('plugins', {}).get(item['id'], {}).get('refresh', {})
            item['observation'] = observation_window(item['schedules'], coverage, item['enabled'], item['refresh'], item['cadence'])
        total = totals['rx']+totals['tx']
        live = self.live_status()
        quality_timeline = self.db.execute(
            'SELECT CAST(minute/? AS INTEGER)*?,SUM(seconds) FROM quality WHERE minute>=? GROUP BY CAST(minute/? AS INTEGER)',
            (resolution, resolution, since, resolution)).fetchall()
        fresh_connections = self.connections['rows'] if live['connected'] and now-self.connections.get('time', 0)<25 else []
        for item in plugins.values():
            connections = sum(c['source'] == item['id'] for c in fresh_connections)
            if item['rx']+item['tx']:
                state, label = 'measured', 'Échanges mesurés'
            elif connections:
                state, label = 'connection_only', 'Connexion ouverte sans volume mesuré'
            elif not live['operational']:
                state, label = 'interrupted', 'Collecte indisponible — aucune mesure attribuée'
            elif item['enabled'] is False:
                state, label = 'disabled', 'Plugin désactivé — aucune mesure attribuée'
            elif item['observation']['state'] == 'short':
                state, label = 'short', 'Observation trop courte — aucune mesure attribuée'
            else:
                state, label = 'unattributed', 'Aucune mesure attribuée sur la période'
            item['measurement'] = {'state': state, 'label': label, 'open_connections': connections}
        versions = self.db.execute('SELECT source,version,SUM(rx),SUM(tx),MIN(first),MAX(last) FROM buckets WHERE '+where+
                                  ' GROUP BY source,version', parameters).fetchall()
        return {'plugins': sorted(plugins.values(), key=lambda p: p['rx']+p['tx'], reverse=True),
                'totals': totals, 'attribution_percent': round(100*totals['high']/total, 1) if total else None,
                'destinations': detail, 'destinations_limited': len(detail) == 500, 'internet_destinations': internet_destinations,
                'countries': [{'code':code, 'name':name} for code, name in sorted(countries.items())],
                'country_filter': country, 'timeline': timeline, 'timeline_resolution': resolution, 'versions': versions,
                'processes': [v for v in self.recent_processes.values() if not source or v['source'] == source],
                'quality_timeline': quality_timeline, 'coverage_seconds': coverage, 'period': period, 'since': since, 'now': now,
                'live': live, 'settings': self.settings, 'connections': dict(self.connections, rows=link_local_peers(self.connections['rows'])), 'detail_pruned': self.read_state('detail_pruned', None),
                'basis': 'Octets acceptés/lus aux appels socket TCP/UDP observés ; hors en-têtes et retransmissions.'}
