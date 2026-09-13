#!/usr/bin/python3
"""Install/update helper, run as www-data outside the offline analysis service.

Downloads data only from the fixed P3TERX mirror of MaxMind GeoLite2.
No observed IP, inventory, token or log is sent to the provider.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import tempfile
import time
from urllib import request, error

DATA = Path('/var/lib/pluginscope')
SOURCES = {kind: 'https://raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-' + name + '.mmdb'
           for kind, name in [('country', 'Country'), ('asn', 'ASN')]}
MAX_BYTES = 100 * 1024 * 1024


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Redirection GeoIP inattendue')


def metadata(path, kind):
    import maxminddb
    with maxminddb.open_database(str(path)) as reader:
        value = reader.metadata()
        expected = 'GeoLite2-Country' if kind == 'country' else 'GeoLite2-ASN'
        if value.database_type != expected:
            raise ValueError('Type MMDB inattendu')
        if not time.time()-30*86400 <= value.build_epoch <= time.time()+86400:
            raise ValueError('Date de base MMDB trop ancienne ou invalide')
        # Read an actual node as well as the metadata before replacement.
        reader.get('8.8.8.8')
        return value.build_epoch


def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as file:
        for block in iter(lambda: file.read(65536), b''):
            result.update(block)
    return result.hexdigest()


def update_one(directory, kind, previous, opener=None):
    target = directory/(kind+'.mmdb')
    old_epoch = None
    try:
        old_epoch = metadata(target, kind)
    except Exception:
        pass
    headers = {'User-Agent':'Jeedom-PluginScope-GeoIP', 'Accept':'application/octet-stream'}
    if old_epoch and previous.get('etag') and previous.get('sha256') == digest(target):
        headers['If-None-Match'] = previous['etag']
    opener = opener or request.build_opener(NoRedirect()).open
    temporary = None
    try:
        try:
            response = opener(request.Request(SOURCES[kind], headers=headers), timeout=15)
        except error.HTTPError as exc:
            if exc.code == 304 and old_epoch:
                return dict(previous, checked_at=time.time(), result='already_current')
            raise
        with response, tempfile.NamedTemporaryFile(dir=directory, prefix='geoip-', suffix='.tmp', delete=False) as file:
            temporary = Path(file.name)
            total, deadline = 0, time.monotonic()+45
            while True:
                block = response.read(65536)
                if not block:
                    break
                total += len(block)
                if total > MAX_BYTES or time.monotonic() > deadline:
                    raise ValueError('Téléchargement GeoIP trop volumineux ou trop lent')
                file.write(block)
            file.flush()
            os.fsync(file.fileno())
            etag = response.headers.get('ETag', '')[:256]
        new_epoch = metadata(temporary, kind)
        result = 'already_current'
        if old_epoch is None or new_epoch > old_epoch:
            os.chmod(temporary, 0o600)
            temporary.replace(target)
            result = 'updated'
        return {'etag':etag, 'sha256':digest(target), 'build_epoch':max(new_epoch, old_epoch or 0),
                'checked_at':time.time(), 'result':result}
    finally:
        if temporary:
            temporary.unlink(missing_ok=True)


def update_all(directory=DATA, opener=None):
    directory = Path(directory)
    state_path = directory/'geoip-update.json'
    with (directory/'geoip-import.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {'errors':{'update':'Une mise à jour ou un import GeoIP est déjà en cours'}}
        try:
            state = json.loads(state_path.read_text())
        except (OSError, ValueError):
            state = {}
        versions = state.get('versions', {})
        failures = {}
        for kind in SOURCES:
            try:
                versions[kind] = update_one(directory, kind, versions.get(kind, {}), opener)
            except Exception as exc:
                failures[kind] = str(exc) if isinstance(exc, ValueError) else 'Téléchargement ou validation impossible ('+type(exc).__name__+')'
        state = {'provider':'P3TERX / MaxMind GeoLite2', 'checked_at':time.time(), 'versions':versions, 'errors':failures}
        with tempfile.NamedTemporaryFile(dir=directory, prefix='geoip-status-', delete=False, mode='w') as file:
            json.dump(state, file, ensure_ascii=False)
            temporary = Path(file.name)
        temporary.replace(state_path)
        return state


if __name__ == '__main__':
    os.umask(0o077)
    try:
        result = update_all()
        for kind, value in result.get('versions', {}).items():
            if kind not in result['errors']:
                print('GeoIP '+kind+' : '+value['result'], flush=True)
        for kind, message in result['errors'].items():
            print('GeoIP '+kind+' : '+message+' ; base existante conservée.', flush=True)
        raise SystemExit(1 if result['errors'] else 0)
    except (OSError, ValueError) as exc:
        print('Mise à jour GeoIP impossible : '+type(exc).__name__, flush=True)
        raise SystemExit(1)
