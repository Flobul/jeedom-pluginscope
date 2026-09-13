#!/bin/bash
set -euo pipefail
umask 022
progress="${1:-/tmp/pluginscope-dependance}"
trap 'rm -f -- "$progress"' EXIT
echo 0 > "$progress"
if [ "$(id -u)" -ne 0 ]; then
    echo 'Installation des dépendances à exécuter via Jeedom avec les droits root.' >&2
    exit 1
fi
source_dir="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
jeedom_dir="$(cd -- "$source_dir/../.." && pwd -P)"
if [[ ! "$jeedom_dir" =~ ^/[A-Za-z0-9_./-]{1,90}$ ]] || [ ! -f "$jeedom_dir/core/php/core.inc.php" ]; then
    echo 'Installation attendue sous le répertoire plugins/pluginscope de Jeedom.' >&2
    exit 1
fi
if [ "$(uname -s)" != Linux ] || ! command -v systemctl >/dev/null; then
    echo 'Linux avec systemd est nécessaire.' >&2
    exit 1
fi
if ! dpkg --compare-versions "$(uname -r | cut -d- -f1)" ge 6.1; then
    echo 'Cette version nécessite un noyau Linux 6.1 ou plus récent (Debian 12).' >&2
    exit 1
fi
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y python3 python3-bpfcc python3-maxminddb iproute2 "linux-headers-$(uname -r)"
echo 50 > "$progress"
# No root execution of files that remain writable by the Jeedom account.
systemctl stop pluginscope.service pluginscope-collector.service 2>/dev/null || true
install -d -o root -g root -m 0755 /usr/local/lib/pluginscope/{collector,daemon} /etc/pluginscope
install -o root -g root -m 0644 "$source_dir"/resources/collector/{collector.py,connections.py,network.c} /usr/local/lib/pluginscope/collector/
install -o root -g root -m 0644 "$source_dir"/resources/daemon/*.py /usr/local/lib/pluginscope/daemon/
printf '%s\n' "$jeedom_dir" > /etc/pluginscope/jeedom-root
chown root:root /etc/pluginscope/jeedom-root
chmod 0644 /etc/pluginscope/jeedom-root
install -d -o www-data -g www-data -m 0700 /var/lib/pluginscope
cat > /etc/systemd/system/pluginscope-collector.service <<'UNIT'
[Unit]
Description=PluginScope socket metadata collector
After=local-fs.target
[Service]
Type=simple
User=root
Group=www-data
ExecStart=/usr/bin/python3 -I /usr/local/lib/pluginscope/collector/collector.py
RuntimeDirectory=pluginscope-collector
RuntimeDirectoryMode=0750
RuntimeDirectoryPreserve=yes
Restart=on-failure
RestartSec=15
RestartPreventExitStatus=78
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
RestrictAddressFamilies=AF_UNIX AF_NETLINK
ReadWritePaths=/run/pluginscope-collector
Nice=10
MemoryMax=512M
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/pluginscope.service <<'UNIT'
[Unit]
Description=PluginScope local network analysis
After=pluginscope-collector.service
Wants=pluginscope-collector.service
[Service]
Type=simple
User=www-data
Group=www-data
ExecStart=/usr/bin/python3 /usr/local/lib/pluginscope/daemon/main.py
Environment=PYTHONDONTWRITEBYTECODE=1
RuntimeDirectory=pluginscope
RuntimeDirectoryMode=0700
Restart=on-failure
RestartSec=5
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
RestrictAddressFamilies=AF_UNIX AF_NETLINK
ReadWritePaths=/run/pluginscope /var/lib/pluginscope
Nice=10
MemoryMax=192M
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
# Jeedom controls enablement; installing does not start network observation.
echo 90 > "$progress"
/usr/bin/python3 -c 'from bcc import BPF; import sqlite3'
# Optional data download as the analysis user, never as root. Failure must not
# prevent the collector from being installed or discard a valid local database.
if ! /usr/sbin/runuser -u www-data -- /usr/bin/python3 /usr/local/lib/pluginscope/daemon/geoip_update.py; then
    echo 'GeoIP non actualisé ; la collecte réseau reste disponible.'
fi
echo 100 > "$progress"
echo 'Dépendances installées. Démarrez le démon depuis Jeedom.'
