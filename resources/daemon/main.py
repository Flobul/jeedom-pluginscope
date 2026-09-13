#!/usr/bin/python3
"""Unprivileged aggregator and authenticated local API. No Internet client."""
import json
import os
from pathlib import Path
import signal
import socket
import socketserver
import struct
import subprocess
import threading
import time
from storage import Store
from geoip import GeoIP

DATA = Path('/var/lib/pluginscope')
API = '/run/pluginscope/api.sock'
COLLECTOR = '/run/pluginscope-collector/events.sock'
LIMITS = [
    'Attribution partielle : processus PHP partagés et services intermédiaires peuvent rester inconnus.',
    'Les volumes mesurent les appels socket couverts, pas les paquets sur les interfaces.',
    'sendfile/splice, io_uring délégué, TLS matériel et namespaces non visibles ne sont pas validés.',
    'Une opération socket ne correspond pas nécessairement à une requête HTTP ou une connexion.',
    'La périodicité décrit les épisodes d’activité de la dernière heure, sans prouver une dépendance fonctionnelle.',
]


def collector_diagnostic(path=None):
    path = Path(path) if path else Path(COLLECTOR).with_name('status.json')
    try:
        with path.open() as handle:
            value = json.loads(handle.read(4096))
        if value.get('state') in ('starting', 'ready', 'error', 'stopped'):
            return {'state': value['state'], 'message': str(value.get('message', ''))[:400], 'time': value.get('time')}
    except (OSError, ValueError, AttributeError):
        pass
    return {}


def topology():
    result = {}
    for key, command in [('addresses', ['ip', '-j', 'address', 'show']),
                         ('routes4', ['ip', '-j', '-4', 'route', 'show']),
                         ('routes6', ['ip', '-j', '-6', 'route', 'show'])]:
        try:
            result[key] = json.loads(subprocess.check_output(command, timeout=3, stderr=subprocess.DEVNULL))
        except (OSError, ValueError, subprocess.SubprocessError):
            result[key] = []
    return result


def main():
    os.umask(0o077)
    root = Path('/etc/pluginscope/jeedom-root').read_text().strip()
    store = Store(DATA / 'observations.sqlite', root)
    geoip = GeoIP(DATA)
    lock = threading.Lock()
    stop = threading.Event()
    status = {'started': time.time(), 'collector_error': 'En attente du collecteur', 'topology': topology()}

    def collect():
        while not stop.is_set():
            try:
                with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as stream:
                    stream.settimeout(5)
                    stream.connect(COLLECTOR)
                    _, uid, _ = struct.unpack('3i', stream.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                    if uid != 0:
                        raise RuntimeError('Identité du collecteur invalide')
                    with stream.makefile('rb') as reader:
                        while not stop.is_set():
                            line = reader.readline(4*1024*1024)
                            if not line or not line.endswith(b'\n'):
                                raise RuntimeError('Flux interrompu ou trop volumineux')
                            sample = json.loads(line)
                            if sample.get('kind') != 'sample' or len(sample.get('rows', [])) > 4096:
                                raise RuntimeError('Message du collecteur invalide')
                            with lock:
                                store.ingest(sample)
                                status['collector_error'] = ''
            except Exception as error:
                # Controlled metadata/errors only; never log the incoming message.
                with lock:
                    status['collector_error'] = str(error)[:240]
                stop.wait(3)

    class Handler(socketserver.StreamRequestHandler):
        def handle(self):
            self.connection.settimeout(3)
            _, uid, _ = struct.unpack('3i', self.connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
            if uid not in (os.getuid(), 0):
                return
            try:
                line = self.rfile.readline(1024*1024)
                if not line.endswith(b'\n'):
                    raise ValueError('Requête trop volumineuse')
                request = json.loads(line)
                action = request.get('action')
                with lock:
                    if action == 'configure':
                        store.configure(request.get('inventory', {}), request.get('settings', {}))
                        result = {'configured': True}
                    elif action == 'compare':
                        result = store.compare_periods(request.get('period',86400), request.get('source',''))
                    elif action == 'diagnostic_start':
                        result = store.diagnostic_start(request.get('source',''))
                    elif action == 'diagnostic_poll':
                        result = store.diagnostic_poll(request.get('id',''))
                    elif action == 'snapshot':
                        geoip.refresh()
                        result = store.snapshot(request.get('period', 86400), request.get('source') or None,
                                                request.get('country') or None, geoip.lookup,
                                                request.get('enabled', 'all'), bool(request.get('internet_only', False)))
                        for destination in result['destinations'] + result['connections']['rows']:
                            if destination['scope'] == 'internet':
                                destination.update(geoip.lookup(destination['address']))
                    elif action == 'geoip_import':
                        geoip.import_database(request.get('kind'))
                        result = geoip.status()
                    elif action == 'health':
                        import resource
                        result = dict(status)
                        live = store.live_status()
                        result.update({key: live.get(key) for key in ('connected', 'operational', 'decoding', 'decoder', 'last_sample', 'stats')})
                        if live['collector_error']:
                            result['collector_error'] = live['collector_error']
                        result.update(backend='eBPF / BCC', limitations=LIMITS,
                                      pid=os.getpid(), memory_kib=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
                                      database_bytes=sum(p.stat().st_size for p in DATA.glob('observations.sqlite*')), geoip=geoip.status())
                        result['collector_diagnostic'] = collector_diagnostic()
                        if not result['connected'] and result['collector_diagnostic'].get('state') in ('starting', 'error', 'stopped'):
                            result['collector_error'] = result['collector_diagnostic']['message']
                    else:
                        raise ValueError('Action inconnue')
                response = {'ok': True, 'result': result}
            except Exception as error:
                response = {'ok': False, 'error': str(error)[:240]}
            try:
                self.wfile.write((json.dumps(response, separators=(',', ':'))+'\n').encode())
            except OSError:
                pass

    Path(API).unlink(missing_ok=True)
    server = socketserver.UnixStreamServer(API, Handler)
    os.chmod(API, 0o600)
    server.timeout = 1
    worker = threading.Thread(target=collect, daemon=True)
    worker.start()
    signal.signal(signal.SIGTERM, lambda *_: stop.set())
    signal.signal(signal.SIGINT, lambda *_: stop.set())
    try:
        while not stop.is_set():
            server.handle_request()
    finally:
        server.server_close()
        worker.join(timeout=6)
        if not worker.is_alive():
            store.db.close()
        Path(API).unlink(missing_ok=True)


if __name__ == '__main__':
    main()
