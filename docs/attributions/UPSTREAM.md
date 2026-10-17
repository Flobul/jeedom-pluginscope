# Sources et dépendances

Le code du plugin est sous GPL-3.0-or-later, voir `LICENSE`.

- **Jeedom Core** : API eqLogic/cmd, plugins, crons et AJAX. https://github.com/jeedom/core
- **BCC / iovisor** : bibliothèque système de chargement eBPF, installée depuis Debian ; licences de ses composants à consulter en amont. https://github.com/iovisor/bcc
- **Linux** : signatures des fonctions socket et structures consultées dans les sources du noyau. https://github.com/torvalds/linux
- **Python / SQLite** : bibliothèques installées par le système ; aucun service distant.
- **maxminddb** : lecteur MMDB Python (Apache-2.0), installé depuis Debian. https://github.com/maxmind/MaxMind-DB-Reader-python

Les bases pays/ASN ne sont pas distribuées avec le plugin. Leur import reste soumis à la licence du fournisseur : https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/

La conception des sondes a été confrontée à la documentation BCC et à l’outil `tcptop` (Apache-2.0), sans recopier son implémentation. Sources et méthode de comptage : https://github.com/iovisor/bcc/blob/master/tools/tcptop.py

L’intégration et l’interface reprennent les conventions des dépôts de l’auteur : Beem, LocalHomeConnect, LocalThings, Tasmota, Palazzetti et Bosch eBike. Aucun secret, profil d’appareil ou certificat de ces plugins n’est inclus.
