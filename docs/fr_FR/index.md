# PluginScope

PluginScope permet de voir **quels plugins communiquent, avec quelles machines et combien de données ils échangent**. Tout est analysé sur votre Jeedom.

## Pour commencer

Installez les dépendances, puis démarrez le démon depuis la configuration du plugin. Ouvrez **Santé** : le contrôle **Collecte réseau réelle** doit afficher **OK**. Les informations apparaissent à mesure que vos plugins communiquent.

Cette première version nécessite Debian 12 avec un noyau Linux 6.1 ou plus récent. Si Santé reste en erreur, le collecteur n’enregistre pas de nouvelles mesures.

## Lire les résultats

L’en-tête Jeedom donne accès à **Ajouter**, **Configuration**, **Santé** et **Documentation**. **Ajouter** crée ou ouvre l’équipement de synthèse PluginScope.

La liste principale présente vos plugins et leurs échanges sur la période choisie. Cliquez sur un nom pour accéder directement à ses destinations, son historique et ses connexions ouvertes. Le bouton **Tous les plugins** permet de revenir à la vue complète.

Les pourcentages Local et Internet sont affichés dans le résumé et pour chaque plugin. Ils se basent sur les volumes envoyés et reçus en Local + Internet, hors échanges internes et destinations indéterminées. Sans trafic correspondant, le pourcentage reste **—**.

Le sélecteur **Plugins désactivés — Tous — Plugins activés**, mémorisé dans votre navigateur, limite les tableaux aux plugins souhaités. La recherche s’applique aussi aux destinations et aux connexions. Cliquez sur un en-tête pour trier la colonne ; un second clic inverse le tri. Ces choix sont conservés lors du rafraîchissement automatique. **Réinitialiser les filtres** réaffiche l’ensemble des observations. Les compteurs du bandeau restent ceux de la période complète.

- **Local observé** : les échanges attribués au plugin concernent le réseau local.
- **Internet observé** : des échanges avec des adresses Internet ont été mesurés.
- **Hybride observé** : le plugin communique en local et avec Internet.
- **Données insuffisantes** : aucun trafic permettant de conclure n’a encore été mesuré.

**Non attribué** et **Jeedom partagé** regroupent les échanges dont le plugin responsable n’est pas établi. Ils restent dans les résultats pour ne pas donner une fausse impression de précision.

Les plugins désactivés portent le libellé **Désactivé**. Leur historique reste visible. Si un processus resté actif continue à communiquer, ses échanges sont conservés ; un plugin désactivé sans données n’est pas automatiquement considéré comme local.

Dans **Tous les échanges observés**, sélectionnez un **plugin** et un **pays**, ou activez **Internet uniquement**. Cliquer sur un nom dans le premier tableau sélectionne aussi ce plugin dans le détail. Ces filtres s’appliquent aux destinations, aux connexions et au graphique. La recherche textuelle filtre les tableaux uniquement. Avec un pays sélectionné, les volumes par processus sont masqués car ils ne sont pas ventilés par pays.

Le graphique affiche une échelle de volumes et des repères horaires. Survolez une barre pour lire sa période et les volumes reçus et envoyés ; l’infobulle reste affichée tant que la souris reste dessus.

Les bases **Pays** et **ASN** GeoLite2 de MaxMind sont téléchargées depuis le miroir [P3TERX](https://github.com/P3TERX/GeoLite.mmdb) à l’installation des dépendances et vérifiées à chaque mise à jour du plugin. Seule une version plus récente remplace un fichier valide. Le téléchargement et la validation échouent sans bloquer la collecte ni remplacer une base valide. La date du contrôle et les erreurs éventuelles figurent dans **Santé**.

La géolocalisation se fait ensuite entièrement en local : aucune IP observée n’est envoyée au miroir. Le téléchargement nécessite Internet. Sans base disponible, les IP restent affichées avec un globe. La carte **Pays et opérateurs** de la configuration affiche la date de construction, le format et la taille de chaque base installée. Pour installer votre propre version, choisissez Pays ou ASN, sélectionnez votre fichier `.mmdb`, puis cliquez sur **Importer la base**. L’import manuel reste possible ; une actualisation automatique peut le remplacer par une base GeoLite2 plus récente. Par précaution, les bases GeoLite construites depuis plus de 30 jours sont exclues.

Données GeoLite créées par [MaxMind](https://www.maxmind.com/), soumises aux [conditions GeoLite](https://www.maxmind.com/en/geolite/eula) et à la [licence CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/). Le pays d’une IP est indicatif et ne prouve pas où les données sont stockées.

Une connexion peut rester ouverte sans transmettre de données à cet instant. La section **Connexions TCP actuellement ouvertes** permet de la voir. Son inventaire est actualisé environ toutes les 15 secondes.

### Pays et couleurs

Dans les destinations et les connexions ouvertes, un drapeau précède l’IP lorsque la base GeoIP locale connaît son pays. Une maison représente le réseau local, un ordinateur les communications internes à Jeedom et un globe un pays inconnu. Aucun pays n’est déduit d’une adresse privée.

Les badges distinguent Local, Internet, les volumes reçus et envoyés, ainsi que TCP et UDP. Ces couleurs facilitent la lecture ; une connexion Internet n’est pas signalée comme dangereuse.

### Rafraîchissement et durée d’observation

La colonne **Planification / recul** distingue le **rafraîchissement configuré**, un intervalle **déclaré** dans PluginScope et le **rythme réseau observé**. Un cron quotidien d’entretien n’est plus présenté comme un rafraîchissement quotidien.

Le réglage `autorefresh` de SmartThings est lu directement : une configuration à une minute affiche une minute. Pour LocalThings, les intervalles en ligne et hors ligne sont affichés séparément, y compris les valeurs sous la minute. Pour les autres démons, la colonne peut utiliser un rythme régulier réellement observé, clairement signalé comme une estimation. Les crons disponibles restent indiqués à part.

Si le réglage d’un plugin n’est pas reconnu, vous pouvez indiquer son intervalle dans la configuration de PluginScope : `monplugin=10` pour dix secondes. Cela sert de référence à l’analyse et ne change pas le plugin surveillé.

La jauge compare la durée collectée à **trois intervalles de référence**. Un rafraîchissement connu ou déclaré tous les trois jours donne un recul court après douze heures ; la durée cible est d’environ neuf jours. Sans référence connue ni rythme régulier, aucune cible réseau n’est inventée à partir des crons. Pour LocalThings, la jauge prend le plus lent des deux intervalles affichés.

Cette durée ne prouve pas que trois appels réseau ont été observés : les interruptions, les conditions internes au plugin et les tâches sans trafic peuvent modifier ce qui est mesuré.

### Communications internes et attribution

Une destination `127.0.0.1` ou `::1` reste sur Jeedom. Il peut s’agir d’un échange entre un démon et PHP, mais cette adresse ou un numéro de port ne suffit pas à désigner un plugin.

PluginScope recherche le script du processus et, lorsque cela est pertinent, son parent dédié. Les démons lancés avec un chemin relatif sont également reconnus. Pour les connexions TCP internes actuellement ouvertes, les deux extrémités peuvent être rapprochées précisément : **Jeedom partagé ↔ Beem (pair local)** indique alors le plugin de l’autre côté.

Le trafic d’un processus PHP partagé n’est pas automatiquement attribué à ce pair. Les anciennes mesures non attribuées restent conservées telles quelles ; l’amélioration concerne les observations suivantes. Un processus disparu avant son identification ou un contexte partagé peut rester non attribué.

## Ce que l’on peut en conclure

Un échange Internet ne prouve pas qu’il est indispensable au fonctionnement du plugin. À l’inverse, une période sans accès Internet ne garantit pas que toutes ses fonctions sont autonomes.

PluginScope ne lit pas les messages échangés. Il ne peut donc pas expliquer leur contenu ou déterminer avec certitude le service utilisé à partir du seul numéro de port.

## Réglages utiles

Dans la configuration, choisissez la durée de conservation et ajoutez vos réseaux locaux particuliers si nécessaire. Les réseaux privés habituels sont déjà reconnus.

Les réglages enregistrés sont appliqués lors du prochain passage de la tâche Jeedom, ou en rouvrant l’analyse. L’historique détaillé est limité pour protéger l’espace disque ; une purge anticipée est signalée dans l’interface.

Le widget Jeedom et les commandes de l’équipement principal donnent les indicateurs synthétiques utilisables dans vos scénarios. Le détail reste dans la page du plugin.

## En cas de problème

Ouvrez **Santé**. Une collecte interrompue ou des événements perdus peuvent rendre les volumes incomplets. Après une mise à jour du plugin, relancez les dépendances pour installer la nouvelle copie du collecteur, puis démarrez le démon.

Le service d’analyse peut être actif alors que le contrôle **Collecte réseau réelle** est en erreur : l’historique reste consultable, mais aucune nouvelle mesure n’est garantie. En cas de rejet du programme eBPF, le diagnostic reste visible dans Santé et le collecteur attend une correction puis un démarrage manuel, au lieu de retenter indéfiniment le même chargement.

Santé affiche les nombres d’événements reçus et décodés depuis le démarrage du collecteur. Si les événements arrivent mais qu’aucun n’est décodé, la collecte est signalée comme bloquée et la dernière erreur est affichée. Un plugin désactivé ou sans activité peut toutefois rester en **Données insuffisantes**, même lorsque la collecte fonctionne.

Les conteneurs et certaines configurations système peuvent empêcher l’observation réseau. Le plugin ne modifie pas automatiquement leurs permissions ou votre pare-feu.

Les données restent localement sur Jeedom. Aucun contenu des communications, mot de passe ou jeton n’est enregistré.

### Carte réseau

Le bouton **Carte réseau** ouvre une vue des communications mesurées sur la période choisie : Jeedom au centre, les logos des plugins et leurs destinations. La bulle verte contient le réseau local et les échanges internes ; Internet et les réseaux indéterminés sont à l’extérieur. Les liens pointillés entre Jeedom et les plugins indiquent leur organisation, pas du trafic supplémentaire.

Le sélecteur **Désactivés / Tous / Activés** est partagé avec le tableau. Sélectionnez un plugin pour simplifier la carte, ajustez le zoom et survolez ou sélectionnez un nœud pour lire les protocoles et volumes. Tous les plugins correspondant au filtre apparaissent, même sans liaison disponible. Les connexions TCP ouvertes sont ajoutées sans leur inventer de volumes. La limite des destinations fournie par le collecteur reste signalée si elle est atteinte. La carte suit le thème clair ou sombre Jeedom et affiche les drapeaux des destinations Internet connues. Elle présente un instantané ; utilisez Actualiser la carte pour le renouveler.

Dans la carte, utilisez la molette pour zoomer autour du pointeur, maintenez le clic gauche pour déplacer la vue, et cliquez sur **Recentrer** pour retrouver le cadrage initial. La liste des plugins suit le filtre Activés / Désactivés / Tous.

Une adresse `0.0.0.0:0` signifie que le correspondant n’a pas été identifié ; elle ne prouve pas un échange Internet. Les appels SSH provenant d’un contexte PHP partagé peuvent rester sous **Jeedom partagé** : l’absence de données attribuées au plugin ne signifie pas une absence de trafic.

### SSH, caméras et WebSocket

Les connexions SSH sont mesurées comme du TCP. SSH Manager utilise une bibliothèque PHP et peut être appelé par un autre plugin, comme hdsentinel. Un cron dédié permet d’attribuer les échanges à son plugin ; un appel depuis un scénario ou un processus PHP partagé peut rester sous **Jeedom partagé**. Le nom de la bibliothèque appelée n’est pas une preuve d’attribution à SSH Manager. Jailbreak utilise également des connexions SSH depuis PHP.

Les WebSocket classiques (WS/WSS) utilisent TCP. RTSP pilote une session multimédia dont les données peuvent circuler en TCP ou UDP. PluginScope affiche ces transports et les volumes observés, sans analyser les messages pour certifier « RTSP » ou « WebSocket ». Un port seul ne suffit pas à identifier le protocole applicatif.

Un flux reçu ou relayé par un processus sur Jeedom peut être mesuré, sous réserve des chemins système couverts par le collecteur. Un flux direct entre une caméra et un téléphone, un navigateur distant ou un enregistreur externe n’est pas observé par PluginScope. Les chemins de transfert particuliers et les espaces réseau isolés peuvent limiter la collecte ; l’attribution d’un relais partagé reste parfois indéterminée.

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
