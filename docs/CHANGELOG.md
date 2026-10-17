# Changelog

## Corrections en cours

- Classement des crons partagés plugin::cron* et checkDeamon/checkDaemon sous Core Jeedom, avec provenance explicite.
- Attribution des noms de processus homebridge et hb-service au plugin Homebridge, sans assimiler les processus Node.js génériques.

- Diagnostic ciblé de deux minutes : processus, destinations, volumes, attribution, couverture, crons et connexions TCP ; contexte partagé explicitement séparé.
- Comparaison de périodes consécutives : volumes, écarts, couverture et différences de destinations ; référence nulle et limites de conservation signalées.

- États de mesure explicites pour les plugins sans volumes et prise en compte des connexions TCP ouvertes récentes.
- Vue des processus non attribués avec raisons et accès aux destinations historiques.
- Couverture de collecte globale et par intervalle, plages absentes ou partielles hachurées.
- Recherche par IP, pays ou plugin et actualisation à la demande dans la carte réseau.

- Compteurs Local, Internet, reçu et envoyé : icônes et couleurs distinctes, compatibles avec les thèmes Jeedom.
- Documentation des limites d’attribution SSH depuis PHP et de la visibilité des flux RTSP / WebSocket.

- Zoom à la molette centré sur le pointeur, déplacement par clic gauche maintenu et bouton Recentrer.
- Liste déroulante synchronisée avec le filtre d’état ; sélection réinitialisée si elle devient incompatible.
- Clarification des destinations indéterminées dans la carte et protection de l’affichage des adresses non spécifiées.

- Carte réseau adaptée au thème Jeedom clair/sombre et drapeaux des pays Internet.
- Suppression des limites de 12 sources et 40 liaisons : inventaire complet selon le filtre, y compris sans mesure ; ajout des connexions TCP ouvertes avec volumes explicitement non mesurés.

- Correction du rafraîchissement lorsque la section Versions observées est absente.
- Modale Carte réseau : logos locaux, bulle du réseau local, destinations Internet, liens issus des mesures, filtres d’état partagés, sélection par plugin, zoom et détail au survol/clavier.

- En-tête de gestion Jeedom avec Ajouter, Configuration, Santé et Documentation.
- Filtres du détail par plugin et pays, synchronisés avec le clic sur le tableau des plugins ; application aux volumes et au graphique avant la limite des destinations affichées.
- Sélecteur Désactivés / Tous / Activés mémorisé dans le navigateur.
- Échelles de volumes et de temps sur le graphique, avec infobulle stable au survol.
- Versions réelles des bases Pays/ASN dans la configuration et actualisation immédiate après import manuel ; suppression de la carte Comprendre les résultats.

- Correction du repère de rafraîchissement : priorité au réglage SmartThings `autorefresh` et aux intervalles en ligne/hors ligne LocalThings, y compris sous la minute ; déclaration complémentaire en secondes et estimation depuis l’activité réseau régulière.
- Les crons d’entretien restent informatifs et ne fixent plus la jauge réseau ; aucune cible n’est inventée sans référence connue ou observée.
- Téléchargement automatique GeoLite2 Pays/ASN depuis P3TERX à l’installation des dépendances et aux mises à jour du plugin : contrôle HTTP conditionnel, validation MMDB/date, remplacement atomique uniquement par une version plus récente et erreurs non bloquantes visibles dans Santé.
- Attribution MaxMind et conditions des données documentées ; bases MMDB exclues de la distribution et recherches de pays toujours locales.

- Drapeaux GeoIP devant les IP publiques connues, icônes distinctes pour les réseaux locaux/internes et les pays inconnus ; enrichissement des connexions TCP ouvertes.
- Badges colorés pour les états, comportements, volumes, destinations et protocoles TCP/UDP, avec légende reçu/envoyé du graphique.
- Planification des crons Jeedom dédiés et partagés : fréquence approximative, prochain passage et jauge de durée collectée comparée à trois intervalles ; distinction des démons, tâches ponctuelles et plugins désactivés.
- Attribution améliorée des processus existants : chemins de scripts relatifs et parents dédiés, contrôle de l’identité du PID ; identification du pair des connexions TCP loopback sans réattribuer les volumes des processus partagés.

- Pourcentages Local/Internet dans le résumé et pour chaque plugin, calculés sur les octets envoyés et reçus Local + Internet ; affichage indéterminé sans trafic.
- Clic sur les plugins rétabli : sélection immédiate, défilement vers le détail, indication de chargement et bouton de retour à tous les plugins.

- Prise en compte de l’alignement des événements perf Linux : les 4 octets ajoutés après la structure réseau ne provoquent plus le rejet systématique des mesures.
- Santé affiche les événements reçus et décodés ainsi que la dernière erreur ; un décodage bloqué ne donne plus une collecte OK et n’augmente plus la durée d’observation. Diagnostic de décodage limité à un message par minute dans le journal système.
- Décodage explicite des événements eBPF imbriqués avec ctypes : correction des volumes absents lorsque BCC ne reconnaît pas `struct context_t`. Les erreurs de décodage sont désormais comptabilisées dans Santé.
- Filtres Tous / Activés / Désactivés et tri ascendant/descendant sur les colonnes des tableaux plugins, destinations, connexions et processus.
- Libellé lisible pour les sources non attribuées et présentation non ambiguë des adresses IPv6 avec port.
- Correction du rejet de `io_return` : lectures explicites des pointeurs noyau via `bpf_probe_read_kernel`, y compris les pointeurs intermédiaires ; compteur d’échecs de lecture des métadonnées.
- Santé indique une qualité indisponible sans collecte active ni compteurs reçus, et précise que les bases GeoIP sont facultatives.
- Correction du rejet eBPF à l’analyse des arguments : suppression des accès à la pile avec indice variable.
- Distinction entre service d’analyse et collecteur : suppression des relances Jeedom injustifiées et arrêt des tentatives automatiques après un rejet de chargement eBPF, avec diagnostic conservé dans Santé.
- Messages d’erreur AJAX en texte brut, sans balises HTML affichées dans les alertes.
- Prise en compte des plugins désactivés : statut explicite, conservation de l’historique et des échanges réellement observés.
- Correction du lancement des dépendances : le plugin fournit le chemin du script, le Core Jeedom ajoutant lui-même `/bin/bash`.
- Tests conservés uniquement en local : exclusion de `tests/` de Git et des archives, suppression du workflow GitHub Actions associé.

## 0.1.0 — 2026-09-12

- Première version du plugin PluginScope : observation dynamique des échanges TCP/UDP IPv4/IPv6 avec eBPF/BCC.
- Attribution aux scripts de plugins, contextes hérités et crons dédiés ; conservation explicite des échanges non attribués.
- Tableau des plugins, destinations locales/Internet, historique des volumes, activité périodique probable et connexions TCP ouvertes.
- Historique local SQLite, versions de plugins associées aux mesures, commandes Jeedom synthétiques.
- Import facultatif de bases MMDB pour les pays et opérateurs réseau, avec consultation entièrement locale.
- Pages configuration et Santé cohérentes avec les plugins récents du workspace.
- Collecteur installé séparément avec privilèges, démon d’analyse non privilégié et API Unix privée.
- Tests applicatifs et banc de validation Linux. Validation eBPF sur machines Jeedom encore nécessaire avant diffusion stable.
