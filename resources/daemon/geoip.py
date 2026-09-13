"""Optional local MMDB enrichment. No DNS or remote lookup."""
from functools import lru_cache
from pathlib import Path
import time


class GeoIP:
    def __init__(self, directory):
        self.directory = Path(directory)
        self.readers = {}
        self.errors = {}
        self.modified = {}

    def refresh(self):
        try:
            import maxminddb
        except ImportError:
            self.errors['dependency'] = 'Lecteur MMDB non installé ; relancez les dépendances.'
            return
        for kind in ('country','asn'):
            path = self.directory/(kind+'.mmdb')
            stamp = path.stat().st_mtime_ns if path.exists() else 0
            if self.modified.get(kind) == stamp:
                continue
            self.modified[kind] = stamp
            self.lookup.cache_clear()
            if kind in self.readers:
                self.readers.pop(kind).close()
            self.errors.pop(kind, None)
            if not stamp:
                continue
            try:
                reader = maxminddb.open_database(str(path))
                self.readers[kind] = reader
            except Exception:
                self.errors[kind] = 'Base MMDB illisible'
        # Conservative GeoLite expiry: local uploads need periodic replacement.
        for kind, reader in list(self.readers.items()):
            metadata = reader.metadata()
            if 'GeoLite' in metadata.database_type and time.time()-metadata.build_epoch > 30*86400:
                reader.close()
                del self.readers[kind]
                path = self.directory/(kind+'.mmdb')
                if path.exists() and path.stat().st_mtime_ns == self.modified.get(kind):
                    path.unlink(missing_ok=True)
                self.lookup.cache_clear()
                self.errors[kind] = 'Base GeoLite expirée après 30 jours ; importez une copie récente.'

    def import_database(self, kind):
        if kind not in ('country','asn'):
            raise ValueError('Type de base invalide')
        import maxminddb
        path = self.directory/(kind+'-upload.mmdb')
        try:
            with maxminddb.open_database(str(path)) as reader:
                metadata = reader.metadata()
                if kind == 'asn' and 'ASN' not in metadata.database_type:
                    raise ValueError('Une base ASN est attendue')
                if kind == 'country' and not any(s in metadata.database_type for s in ('Country','City')):
                    raise ValueError('Une base pays est attendue')
                if 'GeoLite' in metadata.database_type and time.time()-metadata.build_epoch > 30*86400:
                    raise ValueError('Base GeoLite trop ancienne : importez une copie récente')
            path.replace(self.directory/(kind+'.mmdb'))
            self.modified.pop(kind, None)
            self.refresh()
        finally:
            path.unlink(missing_ok=True)

    @lru_cache(maxsize=4096)
    def lookup(self, address):
        result = {'country': '', 'country_code':'', 'asn':None, 'organization':''}
        try:
            country = self.readers['country'].get(address) if 'country' in self.readers else None
            asn = self.readers['asn'].get(address) if 'asn' in self.readers else None
            if country:
                value = country.get('country', {})
                result['country_code'] = value.get('iso_code', '')
                result['country'] = value.get('names', {}).get('fr') or value.get('names', {}).get('en', '')
            if asn:
                result['asn'] = asn.get('autonomous_system_number')
                result['organization'] = asn.get('autonomous_system_organization','')
        except Exception:
            # A corrupt optional database must not hide otherwise valid traffic.
            self.errors['lookup'] = 'Lecture GeoIP impossible pour certaines entrées'
        return result

    def status(self):
        self.refresh()
        import json
        try:
            update = json.loads((self.directory/'geoip-update.json').read_text())
        except (OSError, ValueError):
            update = {}
        installed = {}
        for kind, reader in self.readers.items():
            meta = reader.metadata()
            installed[kind] = {'type':meta.database_type, 'build_epoch':meta.build_epoch,
                               'size':(self.directory/(kind+'.mmdb')).stat().st_size}
        return {'loaded':list(self.readers), 'installed':installed, 'errors':dict(self.errors), 'update':update}


if __name__ == '__main__':
    import json
    import sys
    try:
        geo = GeoIP('/var/lib/pluginscope')
        if sys.argv[1:] == ['--status']:
            print(json.dumps(geo.status()))
        elif len(sys.argv) == 3 and sys.argv[1] == '--import' and sys.argv[2] in ('country', 'asn'):
            geo.import_database(sys.argv[2])
            print(json.dumps(geo.status()))
        else:
            raise ValueError('Action GeoIP invalide')
    except Exception as error:
        print(json.dumps({'error':str(error)[:240]}))
        raise SystemExit(1)
