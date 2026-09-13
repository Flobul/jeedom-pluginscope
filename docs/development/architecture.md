# Architecture 0.1.0

Cette version implémente une collecte dynamique. L’étude préalable du workspace n’est pas le moteur d’analyse.

## Exécution

`pluginscope-collector.service` exécute une copie root-owned de `collector.py` et des sondes BCC. Le code PHP ne peut pas choisir un programme BPF ou une commande à faire exécuter au service. `pluginscope.service`, sous `www-data`, reçoit un flux unidirectionnel de métadonnées via socket Unix et sert une API Unix distincte à PHP. Les identités des pairs sont contrôlées.

Les dépendances installent BCC et les en-têtes du noyau. Cela entraîne une installation plus lourde qu’un futur helper libbpf précompilé, mais permet une première version maintenable sans publier un binaire non testé par architecture. Les services exécutent des copies sous `/usr/local/lib/pluginscope`, détenues par root. Les changements de sources imposent une réinstallation des dépendances. Aucun service n’est activé automatiquement au boot : Jeedom gère son lancement.

## Mesure et limites explicites

Sondes d’entrée/sortie `tcp_sendmsg`, `tcp_recvmsg`, `udp_sendmsg`, `udp_recvmsg`, `udpv6_sendmsg`, `udpv6_recvmsg`. Seuls les retours positifs sont comptés. Les peeks et la file d’erreurs sont exclus ; les lectures UDP tronquées comptent les octets copiés. Les appels imbriqués IPv6/IPv4 sont dédoublonnés. Aucun buffer applicatif n’est lu.

La frontière est l’appel socket, pas Ethernet. Ni retransmissions ni en-têtes ; `sendfile`, `splice`, certains offloads et l’attribution d’`io_uring` restent non validés. UDP sans nom de pair récupérable est enregistré sous adresse indéterminée, sans supprimer son volume. Les sondes de retour ont une capacité bornée ; le diagnostic n’est pas une preuve de couverture totale de tous les chemins noyau.

Les volumes globaux comprennent **tous les processus observés**, avec séparation local, Internet, loopback/multicast/broadcast et inconnu. Ils ne sont pas présentés comme le trafic exclusivement produit par Jeedom. Les totaux TX/RX internes comprennent les deux extrémités d’un échange local si elles sont observées ; ne pas les comparer à une seule interface réseau.

Les événements incluent identité PID/date de création, namespace réseau, adresse/port, protocole, octets, nom du programme et contexte nettoyé. Les sondes exec/fork conservent les chemins d’entrée Jeedom et un `cron_id` numérique, même après la sortie du processus. `execveat`, chemins relatifs, arguments au-delà des six premiers et tâches déjà terminées au démarrage peuvent rester inconnus. Le contexte hérité indique un émetteur technique, pas nécessairement la cause fonctionnelle.

Un second inventaire borné rapproche les sockets TCP établies du namespace du collecteur avec les descripteurs des processus. Il apporte un état instantané, pas des volumes. Une socket partagée peut avoir plusieurs propriétaires ; ces lignes ne doivent pas être additionnées comme autant de connexions uniques. Une limite de durée/cardinalité atteinte est signalée.

## Attribution

Le plugin PHP exporte l’identité des plugins et la classe/fonction de chaque cron, jamais leurs paramètres ou secrets. Le démon associe un script sous `plugins/<id>/` ou un cron dédié à une source avec preuve. Les dispatchers PHP mutualisés restent « Jeedom partagé » ; une IP connue ou un nom de programme ressemblant à un plugin ne suffisent pas.

Les versions restent associées aux mesures. Un trafic MQTT produit par un autre plugin appartient à ce plugin/service ; aucune répartition fictive entre ses clients n’est calculée.

## Stockage et qualité

SQLite dans `/var/lib/pluginscope`, hors arborescence web. Écritures groupées par échantillon, agrégats minute, rétention 7–90 jours, plafond de 250 000 lignes de détail vérifié périodiquement et limite SQLite de 512 Mio. Une purge du détail peut réduire les totaux disponibles ; elle est affichée. Le plafond SQLite ne comprend pas strictement le WAL temporaire : surveiller aussi l’espace disque via Santé. En cas d’échec d’écriture, l’erreur de collecte reste visible.

Cadence : calcul sur les secondes d’activité de la dernière heure. Ce n’est pas un comptage de requêtes HTTP. Historique : résolution minute, 15 minutes ou heure selon l’affichage, aucune somme entre résolutions dupliquées. La couverture porte sur les échantillons reçus, pas une assurance de capture de tout le trafic. Les pertes de buffer, de maps et de transmission sont exposées.

Les données d’interface (routes/adresses) servent au diagnostic ; aucun compteur d’interface n’est additionné aux octets socket. Les CIDR configurés permettent de corriger la classification. VPN et proxies locaux peuvent masquer la destination fonctionnelle réelle.

## Validation et suite

Tests unitaires applicatifs, lint PHP/JS/shell et test Linux de vrais échanges loopback, exécutés uniquement en local. Le répertoire `tests/` est exclu de Git et des archives distribuées ; aucun workflow GitHub Actions n’est fourni pour ces tests. Le test eBPF doit réussir sur Debian 12 amd64 et arm64 avant d’annoncer une version validée sur ces cibles.

L’enrichissement pays/ASN utilise `python3-maxminddb` et des imports administrateur bornés. La base est validée avant remplacement atomique ; aucun téléchargement automatique. Les bases GeoLite expirent après 30 jours selon la politique conservatrice indiquée à l’import. Aucun nom d’hôte n’est inventé depuis une IP.

Extensions prévues : meilleure attribution PHP partagé, agrégats longue durée séparés du détail, alertes facultatives, helper CO-RE précompilé et validation des autres chemins d’E/S. Aucun score d’autonomie fictif n’est calculé.
