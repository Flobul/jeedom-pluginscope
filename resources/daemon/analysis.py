"""Pure metadata attribution and network classification; no network requests."""
import ipaddress
from pathlib import PurePosixPath
import statistics

PRIVATE = tuple(ipaddress.ip_network(n) for n in
                ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16',
                 'fc00::/7', 'fe80::/10'))


def scope(address, local_networks=()):
    ip = ipaddress.ip_address(address)
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped:
        ip = ip.ipv4_mapped
    if ip.is_unspecified:
        return 'unknown'
    if ip.is_loopback:
        return 'loopback'
    if ip.is_multicast:
        return 'multicast'
    if str(ip) == '255.255.255.255':
        return 'broadcast'
    networks = tuple(ipaddress.ip_network(n, strict=False) for n in local_networks)
    for net in networks:
        if ip.version == 4 and net.version == 4 and net.prefixlen < 31 and ip == net.broadcast_address:
            return 'broadcast'
    if any(ip in net for net in PRIVATE + networks if net.version == ip.version):
        return 'local'
    return 'internet' if ip.is_global else 'unknown'


def resolve(row, inventory, jeedom_root):
    """Prefer dedicated evidence; explicit process/core rules retain their provenance."""
    plugins = inventory.get('plugins', {})
    script = row.get('script', '')
    try:
        relative = PurePosixPath(script).relative_to(PurePosixPath(jeedom_root) / 'plugins')
        candidate = relative.parts[0]
        if '..' not in relative.parts and candidate in plugins:
            return candidate, 'high', 'Script ou parent dédié : ' + str(relative)
    except (ValueError, IndexError):
        pass
    cron = inventory.get('crons', {}).get(str(row.get('cron', 0)))
    if cron:
        owner = cron.get('class', '')
        if owner in plugins:
            return owner, 'high', 'Cron dédié : {}::{}'.format(owner, cron.get('function', ''))
        function = cron.get('function', '')
        if owner == 'plugin' and function in ('cron', 'cron5', 'cron10', 'cron15', 'cron30', 'cronHourly', 'cronDaily', 'checkDeamon', 'checkDaemon'):
            return '_core', 'rule', 'Core Jeedom (règle de classement) : {}::{} ; peut exécuter des plugins'.format(owner, function)
        return '_shared', 'unknown', 'Cron partagé : {}::{}'.format(owner, function)
    if row.get('comm', '') in ('homebridge', 'hb-service') and 'homebridge' in plugins:
        return 'homebridge', 'rule', 'Règle explicite de processus : ' + row['comm']
    if script.startswith(jeedom_root + '/core/'):
        return '_shared', 'unknown', 'Contexte PHP Jeedom partagé'
    return '_unknown', 'unknown', 'Aucun contexte de plugin établi'


def observed_mode(local, internet, total):
    if not total:
        return 'Données insuffisantes'
    if local and internet:
        return 'Hybride observé'
    if internet:
        return 'Internet observé'
    if local:
        return 'Local observé'
    return 'Échanges internes ou indéterminés'


def cadence(timestamps):
    """Intervals between occupied one-second buckets, not HTTP requests."""
    times = sorted(set(timestamps))
    intervals = [b-a for a, b in zip(times, times[1:]) if b > a]
    if len(intervals) < 5:
        return {'label': 'Observations insuffisantes', 'samples': len(intervals)}
    median = statistics.median(intervals)
    deviation = statistics.median(abs(x-median) for x in intervals)
    regularity = max(0, round(100 * (1 - deviation / median)))
    if median <= 2:
        label = 'Activité soutenue'
    elif regularity >= 85:
        label = 'Échanges périodiques probables (≈ {} s)'.format(round(median))
    else:
        label = 'Activité irrégulière'
    return {'label': label, 'median_seconds': median, 'regularity': regularity, 'samples': len(intervals)}


def clean_schedule(value):
    result = {k: str(value.get(k, ''))[:128] for k in ('class', 'function', 'schedule')}
    result.update({k: value.get(k, False) in (True, 1, '1') for k in ('enabled', 'daemon', 'once')})
    for key in ('interval_seconds', 'next_run', 'last_run'):
        number = value.get(key)
        result[key] = max(0, float(number)) if isinstance(number, (int, float)) and number > 0 else None
    return result


def clean_refresh(value):
    import math
    result = {k: str(value.get(k, ''))[:128] for k in ('basis', 'label', 'schedule')}
    for key in ('interval_seconds', 'offline_seconds', 'next_run'):
        number = value.get(key)
        result[key] = float(number) if isinstance(number, (int, float)) and math.isfinite(number) and number > 0 else None
    return result


def observation_window(schedules, coverage, enabled, refresh=None, activity=None):
    if enabled is False:
        return {'state':'disabled'}
    refresh, activity = refresh or {}, activity or {}
    active = [s for s in schedules if s.get('enabled') and not s.get('daemon') and not s.get('once') and s.get('interval_seconds')]
    result = {'state':'unknown', 'cron_min':min((s['interval_seconds'] for s in active), default=None),
              'cron_max':max((s['interval_seconds'] for s in active), default=None)}
    if refresh.get('basis') in ('disabled', 'unknown'):
        result['label'] = refresh.get('label', '')
        return result
    interval = refresh.get('interval_seconds')
    basis = refresh.get('basis', 'configured')
    if interval:
        interval = max(interval, refresh.get('offline_seconds') or interval)
    elif activity.get('regularity', 0) >= 85 and activity.get('samples', 0) >= 5 and activity.get('median_seconds', 0) > 2:
        interval = activity['median_seconds']
        basis = 'observed'
    else:
        return result
    target = 3 * interval
    result.update(state='short' if coverage < target else 'covered', interval_seconds=interval, basis=basis,
                  target_seconds=target, equivalent_intervals=round(coverage/interval, 2),
                  progress=min(100, round(100*coverage/target, 1)))
    return result


def link_local_peers(rows):
    """Label the peer, never transfer a shared server's bytes to its clients."""
    def address(value):
        ip = ipaddress.ip_address(value)
        return str(ip.ipv4_mapped or ip) if ip.version == 6 else str(ip)
    def key(row, reverse=False):
        local = (address(row['local_address']), row['local_port'])
        remote = (address(row['address']), row['port'])
        return (row['netns'], row.get('protocol', 'TCP'), *(remote+local if reverse else local+remote))
    peers = {}
    for row in rows:
        if row.get('scope') != 'loopback' or not row.get('local_address') or not row.get('netns'):
            continue
        peers.setdefault(key(row), set()).add(row['source'])
    result = []
    for row in rows:
        entry = dict(row)
        if row.get('scope') == 'loopback' and row.get('local_address') and row.get('netns'):
            candidates = {v for v in peers.get(key(row, True), ()) if not v.startswith('_')}
            if len(candidates) == 1:
                entry['peer_source'] = next(iter(candidates))
        result.append(entry)
    return result
