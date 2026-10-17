# PluginScope pour Jeedom

[![Licence](https://img.shields.io/badge/Licence-GPL--3.0-blue?style=flat-square)](https://github.com/Flobul/jeedom-pluginscope/blob/HEAD/LICENSE)
[![Langage](https://img.shields.io/badge/Langage-Python-informational?style=flat-square)](https://github.com/Flobul/jeedom-pluginscope)
[![Dernière mise à jour](https://img.shields.io/badge/Derniere_mise_a_jour-13/09/2026-brightgreen?style=flat-square)](https://github.com/Flobul/jeedom-pluginscope/commits)
[![Tickets ouverts](https://img.shields.io/badge/Tickets_ouverts-0-brightgreen?style=flat-square)](https://github.com/Flobul/jeedom-pluginscope/issues)
[![Demandes de fusion ouvertes](https://img.shields.io/badge/Demandes_de_fusion_ouvertes-0-brightgreen?style=flat-square)](https://github.com/Flobul/jeedom-pluginscope/pulls)

PluginScope **observe les échanges réseau réels** du serveur Jeedom. Il mesure les octets envoyés/reçus en TCP et UDP, affiche les destinations locales ou Internet et attribue les échanges aux plugins lorsque leur contexte d’exécution est identifiable.

Il ne déduit pas les volumes en analysant le code des plugins. Les données proviennent d’un collecteur Linux eBPF, puis sont agrégées et conservées localement.

## Installation

1. Installer ce répertoire sous `plugins/pluginscope` dans Jeedom.
2. Activer le plugin, puis installer ses dépendances depuis Jeedom.
3. Démarrer le démon et ouvrir **PluginScope**.
4. Consulter **Santé** pour vérifier que **Collecte réseau réelle** est OK.

Version initiale : **0.1.0**. Cible : Jeedom 4.5+, Debian 12, noyau Linux 6.1+, amd64/arm64 et systemd. Le collecteur nécessite les permissions eBPF et les en-têtes du noyau en cours. Un conteneur limité peut empêcher son fonctionnement.

## Fonctionnalités disponibles

- Tableau des plugins, recherche, tri et fiche de détail.
- TCP/UDP, IPv4/IPv6 : octets aux appels socket observés, local/Internet/interne/indéterminé.
- Attribution par script dédié, contexte hérité et cron Jeedom ; inconnus conservés.
- Destinations et premières/dernières observations ; historique 1 h, 24 h, 7 j, 30 j.
- Activité périodique probable, connexions TCP établies même momentanément silencieuses.
- Versions de plugins associées aux mesures, détails des processus et preuves d’attribution.
- Pays et opérateurs via les bases GeoLite2 Pays/ASN téléchargées depuis P3TERX à l’installation et aux mises à jour ; recherches entièrement locales.
- Rafraîchissement configuré identifié pour SmartThings et LocalThings, intervalles déclarables en secondes et rythme réseau observé ; crons d’entretien séparés.
- SQLite privée avec rétention et limites de détail ; commandes Jeedom synthétiques.
- Configuration et Santé intégrées, diagnostic de collecte et pertes visibles.

L’absence d’échanges ne prouve pas qu’un plugin fonctionne sans Internet. Les processus PHP partagés, les scénarios et les services MQTT intermédiaires peuvent rester non attribués. Les appels socket ne sont ni des requêtes HTTP ni les octets Ethernet : en-têtes, retransmissions et chemins d’E/S non couverts ne sont pas inclus.

Les bases GeoLite2 sont téléchargées et validées automatiquement, avec conservation d’une base valide en cas d’échec. Le miroir P3TERX est un tiers ; les données viennent de MaxMind. La recherche de pays reste locale, sans envoi des IP observées. [Provenance et licence](docs/attributions/GEOLITE.md). Le DNS actif, un score d’autonomie et les notifications automatiques ne sont pas activés dans cette première version. Aucun transfert Internet d’analyse, aucune capture de payload et aucun changement de pare-feu.

## Vérification

```bash
python3 -m unittest discover -s tests -p 'test_*.py' -v
# Sur Linux seulement, avec BCC et les en-têtes du noyau :
sudo /usr/bin/python3 tests/linux_smoke.py
```

Ces commandes sont réservées au workspace de développement : `tests/` est conservé localement, exclu de Git et des archives distribuées. Aucun workflow GitHub Actions n’exécute ces tests.

Le test Linux génère uniquement des échanges loopback contrôlés et vérifie TCP/UDP IPv4/IPv6, les lectures `MSG_PEEK` et l’attribution d’un processus terminé avant la lecture des événements. La réussite des tests applicatifs sur macOS ne vaut pas validation des sondes Linux.

Voir le [guide utilisateur](docs/fr_FR/index.md) et les [détails techniques](docs/development/architecture.md).

La page des résultats propose des filtres par plugin et par pays, un sélecteur Désactivés / Tous / Activés mémorisé et un graphique gradué avec les volumes au survol. La configuration affiche les versions des bases Pays/ASN installées et permet d’importer vos propres fichiers MMDB.

### Carte réseau

Le bouton **Carte réseau** ouvre une vue des communications mesurées sur la période choisie : Jeedom au centre, les logos des plugins et leurs destinations. La bulle verte contient le réseau local et les échanges internes ; Internet et les réseaux indéterminés sont à l’extérieur. Les liens pointillés entre Jeedom et les plugins indiquent leur organisation, pas du trafic supplémentaire.

Le sélecteur **Désactivés / Tous / Activés** est partagé avec le tableau. Sélectionnez un plugin pour simplifier la carte, ajustez le zoom et survolez ou sélectionnez un nœud pour lire les protocoles et volumes. Tous les plugins correspondant au filtre apparaissent, même sans liaison disponible. Les connexions TCP ouvertes sont ajoutées sans leur inventer de volumes. La limite des destinations fournie par le collecteur reste signalée si elle est atteinte. La carte suit le thème clair ou sombre Jeedom et affiche les drapeaux des destinations Internet connues. Elle présente un instantané ; utilisez Actualiser la carte pour le renouveler.

Dans la carte, utilisez la molette pour zoomer autour du pointeur, maintenez le clic gauche pour déplacer la vue, et cliquez sur **Recentrer** pour retrouver le cadrage initial. La liste des plugins suit le filtre Activés / Désactivés / Tous.

Une adresse `0.0.0.0:0` signifie que le correspondant n’a pas été identifié ; elle ne prouve pas un échange Internet. Les appels SSH provenant d’un contexte PHP partagé peuvent rester sous **Jeedom partagé** : l’absence de données attribuées au plugin ne signifie pas une absence de trafic.

### Comprendre les absences de données

Le tableau distingue une connexion ouverte sans volume mesuré, une collecte indisponible, un plugin désactivé, une observation trop courte et une absence de mesure attribuée sur la période. Ces états ne signifient pas que le plugin ne communique jamais. Les mesures historiques restent visibles lorsque la collecte s’arrête.

La section **Échanges non attribués** affiche les processus récents, leur PID, leur programme, leurs volumes et la raison de l’attribution. Ses boutons ouvrent les destinations historiques **Non attribué** ou **Jeedom partagé** et remettent les filtres à Tous. Les processus récents ne constituent pas un historique exhaustif des PID.

La **couverture de collecte** présente la durée enregistrée par rapport à la période choisie. Dans le graphique, les hachures grises indiquent une couverture absente, les hachures ambrées une couverture partielle ; le survol donne le pourcentage par intervalle. Une plage non couverte peut précéder l’installation, correspondre à une interruption ou à des données supprimées : elle n’est pas automatiquement une panne. Une couverture présente ne garantit pas l’absence de pertes d’événements ; les compteurs de Santé restent à consulter.

La carte permet de rechercher une IP, un pays ou un plugin. **Actualiser la carte** relit les données sans fermer la modale ; les filtres restent appliqués et le cadrage est recalculé.

### Diagnostic ciblé et comparaison

Dans le filtre des échanges, sélectionnez un plugin puis cliquez sur **Démarrer le diagnostic ciblé**. Pendant les deux minutes d’observation, actualisez vous-même ce plugin ou lancez une de ses commandes. PluginScope ne pilote aucun appareil. Le résultat se met à jour et affiche les volumes, PID, programmes, destinations, preuves d’attribution, crons déclarés et connexions TCP actuellement connues.

Les échanges partagés ou inconnus observés simultanément sont présentés séparément : ils ne sont pas attribués au plugin testé. Le premier lot chevauchant le début du diagnostic est exclu pour éviter de compter des octets antérieurs. La couverture et les éventuelles limites sont indiquées. Une seule session peut fonctionner à la fois ; elle expire après deux minutes. Le résultat temporaire disparaît au redémarrage du démon ou au lancement du diagnostic suivant ; fermer la page ne conserve pas de rapport consultable dans l’interface.

**Comparer à la période précédente** compare le plugin sélectionné, ou tous les plugins si aucun n’est sélectionné. Les deux périodes sont consécutives et de même durée, limitées à trente jours chacune, et se terminent à la dernière minute complète. Les volumes reçus et envoyés sont additionnés pour comparer le trafic total. Un pourcentage n’est pas calculé lorsque la référence est nulle.

La comparaison affiche la couverture de chaque période et les destinations présentes dans une seule des deux périodes (jusqu’à cent par catégorie). « Actuelle uniquement » ne signifie pas une première connexion certaine : une purge ou une collecte incomplète peut expliquer l’absence dans l’autre période. Ces outils utilisent le plugin et la période sélectionnés, pas les filtres pays, texte ou Internet uniquement.

### Règles Core Jeedom et Homebridge

Les tâches partagées `plugin::cron`, `cron5`, `cron10`, `cron15`, `cron30`, `cronHourly`, `cronDaily`, `checkDeamon` et `checkDaemon` sont classées **Core Jeedom**. Ce classement identifie leur contexte d’exécution ; ces tâches peuvent appeler plusieurs plugins. Les autres contextes PHP partagés restent distincts.

Les noms de processus exacts `homebridge` et `hb-service` sont classés **Homebridge** lorsque le plugin est installé, y compris désactivé si ses processus continuent à communiquer. Un simple processus `node` ne suffit pas. Une preuve de script ou de cron dédié reste prioritaire. Ces règles sont explicites dans les raisons d’attribution et ne sont pas assimilées à une preuve forte du plugin appelant.

Les nouvelles observations utilisent ces règles après actualisation du démon. Les volumes historiques agrégés ne sont pas reclassés. **Core Jeedom**, sans statut de plugin, apparaît dans le filtre **Tous**. La couverture de collecte et les pertes restent indépendantes de ces règles d’attribution.

## Documentation

La documentation détaillée est disponible dans [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Sécurité

Consultez la [politique de sécurité](SECURITY.md) avant de transmettre un rapport ou des journaux. Ne publiez jamais de secret, de jeton ou de mot de passe dans une issue publique.

## Licence

Ce projet est distribué sous licence **GPL-3.0**. Consultez le fichier [LICENSE](LICENSE) pour le texte complet.

---

<p align="center">
  <a href="https://github.com/sponsors/Flobul">
    <img src="https://img.shields.io/badge/Sponsoriser%20ce%20projet-%E2%9D%A4-EA4AAA?style=for-the-badge&logo=githubsponsors" alt="Sponsoriser ce projet sur GitHub">
  </a>
</p>
