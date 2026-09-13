#!/usr/bin/python3
"""Privileged, installed copy only. One-way socket metadata stream to Jeedom.

No caller-supplied program, command, path or network destination is accepted.
"""
import ctypes
import ipaddress
import json
import logging
import os
from pathlib import Path
import pwd
import re
import signal
import socket
import struct
import time

SOCKET = '/run/pluginscope-collector/events.sock'
MAX_ROWS = 4096


class EventContext(ctypes.Structure):
    _fields_ = [('script', ctypes.c_char * 160), ('cron', ctypes.c_uint32)]


class NetworkEvent(ctypes.Structure):
    """Native ABI of network.c/event_t, including its nested context and padding.

    BCC's automatic perf-event decoder cannot resolve this nested struct on
    Debian 12. Keep this layout in sync with the C declaration (local ABI test).
    """
    _fields_ = [
        ('timestamp', ctypes.c_uint64), ('started', ctypes.c_uint64), ('bytes', ctypes.c_uint64),
        ('pid', ctypes.c_uint32), ('ppid', ctypes.c_uint32), ('netns', ctypes.c_uint32),
        ('family', ctypes.c_uint16), ('port', ctypes.c_uint16),
        ('protocol', ctypes.c_uint8), ('direction', ctypes.c_uint8),
        ('address', ctypes.c_uint8 * 16), ('comm', ctypes.c_char * 16), ('context', EventContext),
    ]


class EventDecodeError(ValueError):
    """Safe diagnostic containing only layout sizes or numeric metadata."""


def decode_event(data, size):
    expected = ctypes.sizeof(NetworkEvent)
    # Linux perf_prepare_sample(): round_up(payload + sizeof(u32), 8) - 4.
    # BCC passes that raw size, including trailing padding, to the callback.
    # Accept the native struct or this precise perf framing, not arbitrary ABIs.
    perf_size = ((expected + 4 + 7) // 8) * 8 - 4
    if size not in (expected, perf_size) or not data:
        raise EventDecodeError('Taille reçue {} ; attendue {} ou {} avec alignement perf'.format(size, expected, perf_size))
    # Copy before the perf buffer callback returns and its storage is reused.
    event = NetworkEvent.from_buffer_copy(ctypes.string_at(data, expected))
    if event.family not in (2, 10) or event.protocol not in (6, 17) or event.direction not in (0, 1):
        raise EventDecodeError('Métadonnées invalides : famille={}, protocole={}, direction={}'.format(
            event.family, event.protocol, event.direction))
    return event


def accumulate_event(pending, stats, data, size, decoder=None):
    """Decode and aggregate one event; a malformed callback stays observable."""
    if decoder is not None:
        decoder['received'] = decoder.get('received', 0) + 1
    try:
        event = decode_event(data, size)
        raw = bytes(event.address)[:4 if event.family == 2 else 16]
        address = str(ipaddress.ip_address(raw))
        script = bytes(event.context.script).split(b'\0')[0].decode('utf-8', 'replace')
        if not re.fullmatch(r'[A-Za-z0-9_./ -]+\.(php|py|js)', script):
            script = ''
        key = (event.pid, event.started, event.netns, address, socket.ntohs(event.port),
               event.protocol, script, event.context.cron)
        if decoder is not None:
            decoder['decoded'] = decoder.get('decoded', 0) + 1
        if key not in pending:
            if len(pending) >= MAX_ROWS:
                stats['overflow_events'] += 1
                return
            pending[key] = dict(pid=event.pid, started=event.started, netns=event.netns,
                                address=address, port=socket.ntohs(event.port),
                                protocol='TCP' if event.protocol == 6 else 'UDP', script=script,
                                cron=event.context.cron, comm=bytes(event.comm).split(b'\0')[0].decode('utf-8', 'replace'),
                                rx=0, tx=0, operations=0)
        row = pending[key]
        row['tx' if event.direction else 'rx'] += int(event.bytes)
        row['operations'] += 1
    except Exception as error:
        # Never let ctypes swallow a decoder exception while health looks OK.
        # Do not log raw event memory or produce a traceback per network I/O.
        stats['decode_errors'] = stats.get('decode_errors', 0) + 1
        if decoder is not None:
            decoder['last_error'] = str(error) if isinstance(error, EventDecodeError) else type(error).__name__


def write_status(state, message):
    """Root-owned status remains readable if initialization fails before bind."""
    path = Path(SOCKET).with_name('status.json')
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps({'state': state, 'message': message[:400], 'time': time.time()}))
    os.chmod(temporary, 0o640)
    temporary.replace(path)


def safe_context(pid, root, proc_root='/proc'):
    """Resolve bounded script metadata, including relative paths and parents.

    Never export argv, cwd or environment. A shared core script is a boundary:
    its caller is not proof of the plugin currently executing inside it.
    """
    base = Path(proc_root)
    origin = base / str(pid)
    def identity(path):
        fields = (path/'stat').read_text().rsplit(')', 1)[1].split()
        return fields[1], fields[19]  # parent PID and start ticks, not PID alone
    try:
        origin_identity = identity(origin)
        current = origin
        visited = set()
        for _ in range(8):
            if current.name in visited or int(current.name) <= 1:
                break
            visited.add(current.name)
            before = identity(current)
            with (current/'cmdline').open('rb') as handle:
                args = handle.read(4096).split(b'\0')[:12]
            script, cron = '', 0
            for raw in args:
                arg = raw.decode('utf-8', 'replace')
                if re.fullmatch(r'cron_id=[0-9]{1,9}', arg):
                    cron = int(arg[8:])
                if script or not re.fullmatch(r'[A-Za-z0-9_./ -]+\.(php|py|js)', arg):
                    continue
                candidate = Path(arg)
                if not candidate.is_absolute():
                    try:
                        candidate = (Path(os.readlink(current/'cwd')) / candidate).resolve()
                    except OSError:
                        continue
                candidate = str(candidate)
                if '..' in Path(candidate).parts:
                    continue
                if (candidate.startswith(root+'/plugins/') or candidate.startswith(root+'/core/')) and len(candidate.encode()) < 160:
                    script = candidate
            if identity(current) != before or identity(origin) != origin_identity:
                return '', 0
            if script or cron:
                return script, cron
            current = base / before[0]
    except (OSError, ValueError, IndexError):
        pass
    return '', 0


def source_text(root):
    # Exact prefix comparisons compiled into BPF; no arbitrary config-as-code.
    prefixes = [root + '/plugins/', root + '/core/php/']
    checks = ['(' + ' && '.join('arg[{}]=={}'.format(i, ord(c)) for i, c in enumerate(prefix)) + ')' for prefix in prefixes]
    return Path(__file__).with_name('network.c').read_text().replace('SCRIPT_MATCH', ' || '.join(checks))


def build_bpf(root):
    from bcc import BPF
    if not re.fullmatch(r'/[A-Za-z0-9_./-]{1,90}', root):
        raise RuntimeError('Chemin Jeedom non pris en charge')
    # This first release targets Debian 12's 6.1+ recvmsg ABI explicitly.
    version = tuple(int(x) for x in os.uname().release.split('-')[0].split('.')[:2])
    if version < (6, 1):
        raise RuntimeError('Noyau Linux 6.1 minimum pour ce collecteur')
    bpf = BPF(text=source_text(root))
    for symbol, entry in [('tcp_sendmsg', 'tcp_tx'), ('tcp_recvmsg', 'tcp_rx'),
                          ('udp_sendmsg', 'udp_tx'), ('udp_recvmsg', 'udp_rx'),
                          ('udpv6_sendmsg', 'udp_tx'), ('udpv6_recvmsg', 'udp_rx')]:
        bpf.attach_kprobe(event=symbol, fn_name=entry)
        bpf.attach_kretprobe(event=symbol, fn_name='io_return', maxactive=512)
    return bpf


def main():
    # -I deliberately excludes cwd; load our root-owned sibling explicitly.
    import sys
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from connections import Connections
    root = Path('/etc/pluginscope/jeedom-root').read_text().strip()
    write_status('starting', 'Initialisation des sondes eBPF')
    try:
        bpf = build_bpf(root)
    except Exception as error:
        write_status('error', 'Chargement eBPF impossible : ' + str(error))
        import traceback
        traceback.print_exc()
        # A verifier/configuration rejection needs a correction, not endless
        # automatic reloads of exactly the same program.
        raise SystemExit(78)
    # Seed already running daemons. BPF fork/exec handling then preserves hints
    # even if a PHP task finishes before the next userspace aggregation.
    contexts = bpf['contexts']
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        script, cron = safe_context(entry.name, root)
        if script or cron:
            value = contexts.Leaf()
            value.script = script.encode()
            value.cron = cron
            contexts[contexts.Key(int(entry.name))] = value

    pending = {}
    stats = {'lost_events': 0, 'overflow_events': 0, 'stream_drops': 0, 'decode_errors': 0}
    decoder = {'received': 0, 'decoded': 0, 'last_error': ''}
    last_decode_log = None
    logged_decode_errors = 0
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    started = time.time()
    connections = Connections()
    connections_snapshot = {'rows':[], 'time':0, 'limited':False}
    running = True

    def receive(cpu, data, size):
        accumulate_event(pending, stats, data, size, decoder)

    def lost(cpu, count):
        stats['lost_events'] += count

    def stop(*_):
        nonlocal running
        running = False

    bpf['events'].open_perf_buffer(receive, page_cnt=64, lost_cb=lost)
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    os.umask(0o077)
    path = Path(SOCKET)
    path.unlink(missing_ok=True)
    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(SOCKET)
    os.chown(SOCKET, 0, pwd.getpwnam('www-data').pw_gid)
    os.chmod(SOCKET, 0o660)
    server.listen(2)
    server.setblocking(False)
    write_status('ready', 'Collecteur eBPF prêt')
    client = None
    last = time.monotonic()
    try:
        while running:
            bpf.perf_buffer_poll(timeout=100)
            try:
                connection, _ = server.accept()
                _, uid, _ = struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                if uid != pwd.getpwnam('www-data').pw_uid:
                    connection.close()
                else:
                    if client:
                        client.close()
                    client = connection
                    client.settimeout(0.1)
            except BlockingIOError:
                pass
            now = time.monotonic()
            if now - last < 1:
                continue
            stats['map_errors'] = int(bpf['errors'][ctypes.c_int(0)].value)
            stats['submit_errors'] = int(bpf['errors'][ctypes.c_int(1)].value)
            stats['metadata_read_errors'] = int(bpf['errors'][ctypes.c_int(2)].value)
            if stats['decode_errors'] > logged_decode_errors and (last_decode_log is None or now-last_decode_log >= 60):
                logging.error('Décodage réseau : %s ; reçus=%d, décodés=%d, erreurs=%d',
                              decoder['last_error'], decoder['received'], decoder['decoded'], stats['decode_errors'])
                last_decode_log = now
                logged_decode_errors = stats['decode_errors']
            if time.time() - connections_snapshot['time'] >= 15:
                connections_snapshot = connections.snapshot(contexts, root, safe_context)
            payload = {'kind': 'sample', 'time': time.time(), 'elapsed': now-last,
                       'boot': boot, 'collector_started': started, 'stats': stats, 'decoder': decoder,
                       'connections': connections_snapshot,
                       'rows': list(pending.values())}
            if client:
                try:
                    client.sendall((json.dumps(payload, separators=(',', ':')) + '\n').encode())
                except OSError:
                    stats['stream_drops'] += len(pending)
                    client.close()
                    client = None
            elif pending:
                stats['stream_drops'] += len(pending)
            pending.clear()
            last = now
    finally:
        if client:
            client.close()
        server.close()
        path.unlink(missing_ok=True)
        write_status('stopped', 'Collecteur arrêté')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        write_status('error', 'Collecteur interrompu : ' + str(error))
        raise
