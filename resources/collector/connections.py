"""Bounded host-namespace TCP socket inventory; never used as byte counters."""
import os
from pathlib import Path
import socket
import struct
import time


def decode_endpoint(value, ipv6=False):
    raw, port = value.split(':')
    if ipv6:
        packed = b''.join(struct.pack('=I', int(raw[i:i+8], 16)) for i in range(0,32,8))
        address = socket.inet_ntop(socket.AF_INET6, packed)
    else:
        address = socket.inet_ntop(socket.AF_INET, struct.pack('=I', int(raw,16)))
    return address, int(port,16)


class Connections:
    def __init__(self):
        self.seen = {}

    def snapshot(self, contexts, root, safe_context):
        now = time.time()
        sockets = {}
        for table, ipv6 in [('tcp',False), ('tcp6',True)]:
            try:
                with open('/proc/net/'+table) as handle:
                    for line in list(handle)[1:8193]:
                        fields = line.split()
                        if fields[3] != '01':  # ESTABLISHED only
                            continue
                        address, port = decode_endpoint(fields[2], ipv6)
                        local_address, local_port = decode_endpoint(fields[1], ipv6)
                        sockets[fields[9]] = {'address':address, 'port':port, 'protocol':'TCP',
                                               'local_address':local_address, 'local_port':local_port}
            except (OSError, ValueError, IndexError):
                continue
        result = []
        active = set()
        scanned = 0
        limited = False
        deadline = time.monotonic()+0.2
        for entry in Path('/proc').iterdir():
            if time.monotonic() > deadline:
                limited = True
                break
            if not entry.name.isdigit():
                continue
            pid = int(entry.name)
            try:
                # Reading stat after ')' handles process names containing spaces.
                stat = (entry/'stat').read_text().rsplit(')',1)[1].split()
                started = stat[19]
                source = contexts.get(contexts.Key(pid))
                if source:
                    script = bytes(source.script).split(b'\0')[0].decode('utf-8','replace')
                    cron = int(source.cron)
                else:
                    script, cron = '', 0
                if not script and not cron:
                    script, cron = safe_context(pid, root)
                    # Feed a newly identified running daemon to future eBPF events.
                    if (script or cron) and (entry/'stat').read_text().rsplit(')',1)[1].split()[19] == started:
                        value = contexts.Leaf()
                        value.script = script.encode()
                        value.cron = cron
                        try:
                            contexts[contexts.Key(pid)] = value
                        except Exception:
                            # A full context map must not stop socket collection.
                            limited = True
                netns = os.readlink(entry/'ns/net')
                for fd in (entry/'fd').iterdir():
                    scanned += 1
                    if scanned > 32768 or time.monotonic() > deadline:
                        limited = True
                        break
                    try:
                        link = os.readlink(fd)
                    except OSError:
                        continue
                    inode = link[8:-1] if link.startswith('socket:[') else ''
                    if inode not in sockets:
                        continue
                    key = (pid, started, inode)
                    if key in active:
                        continue
                    active.add(key)
                    since = self.seen.get(key, now)
                    self.seen[key] = since
                    result.append(dict(sockets[inode], pid=pid, script=script, cron=cron, since=since, netns=netns))
                    if len(result) >= 2048:
                        limited = True
                        break
            except (OSError, ValueError, IndexError):
                continue
            if limited:
                break
        self.seen = {k:v for k,v in self.seen.items() if k in active}
        return {'rows':result, 'time':now, 'limited':limited, 'scope':'Namespace réseau du collecteur, TCP établi uniquement'}
