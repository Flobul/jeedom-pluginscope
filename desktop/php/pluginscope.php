<?php
require_once __DIR__ . '/../../core/class/pluginscope.class.php';
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('pluginscope');
sendVarToJS('eqType', $plugin->getId());
$networkIcons = array();
foreach (plugin::listPlugin() as $networkPlugin) { $networkIcons[$networkPlugin->getId()] = $networkPlugin->getPathImgIcon(); }
sendVarToJS('pluginscopeIcons', $networkIcons);
$eqLogics = eqLogic::byType($plugin->getId());
include_file('desktop', 'pluginscope', 'css', 'pluginscope');
?>
<div id="pluginscope" class="ps-page row">
    <div class="eqLogicThumbnailDisplay ps-management">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf"><i class="fas fa-wrench"></i><br><span>{{Configuration}}</span></div>
            <div class="cursor logoSecondary" id="ps-network"><i class="fas fa-project-diagram"></i><br><span>{{Carte réseau}}</span></div>
            <div class="cursor logoSecondary" id="ps-health"><i class="fas fa-heartbeat"></i><br><span>{{Santé}}</span></div>
            <div class="cursor logoSecondary" data-location="<?php echo $plugin->getDocumentation(); ?>" id="bt_documentationPluginscope"><i class="fas fa-book-reader"></i><br><span>{{Documentation}}</span></div>
        </div>
    </div>

    <legend><i class="fas fa-table"></i> {{Résumé}}</legend>
    <div class="eqLogicThumbnailDisplay col-xs-10 col-xs-offset-1 ps-summary">
    <div class="ps-toolbar ps-results-heading"><h1>PluginScope</h1><select id="ps-period" class="form-control" aria-label="{{Période}}"><option value="3600">{{1 heure}}</option><option value="86400" selected>{{24 heures}}</option><option value="604800">{{7 jours}}</option><option value="2592000">{{30 jours}}</option></select><button type="button" id="ps-refresh" class="btn btn-primary"><i class="fas fa-sync"></i> {{Rafraîchir}}</button></div>
    <div id="ps-error" class="alert alert-warning" hidden></div>
    <p id="ps-coverage" class="ps-muted" role="status"></p>
    <div id="ps-status" class="ps-status" role="status">{{Chargement des observations…}}</div>
    <div class="ps-counters" id="ps-counters"></div>
    <section class="ps-card">
        <div class="ps-toolbar"><h2 id="ps-plugin-heading" tabindex="-1">{{Plugins}}</h2><div id="ps-enabled" class="ps-toggle" role="group" aria-label="{{État du plugin}}"><button type="button" data-state="disabled" aria-pressed="false">{{Plugins désactivés}}</button><button type="button" data-state="all" aria-pressed="true">{{Tous}}</button><button type="button" data-state="enabled" aria-pressed="false">{{Plugins activés}}</button></div><input id="ps-search" class="form-control" type="search" placeholder="{{Rechercher un plugin ou une destination}}" aria-label="{{Recherche}}"><button class="btn btn-default" id="ps-clear">{{Réinitialiser les filtres}}</button></div>
        <p class="ps-muted">{{Cliquez sur un plugin pour explorer ses communications. « Local observé » ne garantit pas que toutes ses fonctions sont autonomes.}}</p>
        <p class="ps-muted">{{Les filtres d’état et de recherche s’appliquent aux tableaux. Cliquez sur un en-tête pour trier, puis à nouveau pour inverser l’ordre. Les compteurs restent ceux de la période complète.}}</p>
        <p class="ps-muted">{{Les pourcentages portent sur les octets envoyés et reçus en Local + Internet, hors échanges internes et destinations indéterminées. Sans trafic correspondant : —.}}</p>
        <p class="ps-muted">{{La jauge utilise le rafraîchissement configuré ou déclaré, sinon un rythme réseau régulier observé. Les crons Jeedom restent distincts : un cron d’entretien ne définit pas le rafraîchissement. Survolez la colonne pour consulter la source du repère.}}</p>
        <div class="ps-scroll"><table class="table ps-table" id="ps-plugins" data-ps-table="plugins"><thead><tr>
            <th><button type="button" data-sort="name">{{Plugin}}</button></th><th><button type="button" data-sort="statusLabel">{{État}}</button></th><th><button type="button" data-sort="mode">{{Comportement}}</button></th><th><button type="button" data-sort="local">{{Local}}</button></th><th><button type="button" data-sort="internet">{{Internet}}</button></th><th><button type="button" data-sort="interval_seconds">{{Planification / recul}}</button></th><th><button type="button" data-sort="cadenceLabel">{{Activité récente}}</button></th><th><button type="button" data-sort="tx">{{Envoyé}}</button></th><th><button type="button" data-sort="rx">{{Reçu}}</button></th>
        </tr></thead><tbody></tbody></table></div>
    </section>
    <section class="ps-card" id="ps-investigate">
        <h2>{{Diagnostic et comparaison}}</h2>
        <p>{{Choisissez un plugin dans le filtre des échanges ci-dessous. Le diagnostic observe pendant deux minutes ; lancez ensuite vous-même une actualisation ou une commande dans le plugin concerné. Aucun appareil n’est piloté automatiquement.}}</p>
        <button class="btn btn-primary" id="ps-diagnostic-start">{{Démarrer le diagnostic ciblé}}</button>
        <button class="btn btn-default" id="ps-compare">{{Comparer à la période précédente}}</button>
        <p id="ps-investigate-status" role="status"></p>
        <div id="ps-diagnostic-result" class="ps-scroll"></div>
        <div id="ps-comparison-result" class="ps-scroll"></div>
    </section>
    <section class="ps-card" id="ps-unassigned">
        <h2>{{Échanges non attribués}}</h2>
        <p>{{Processus récents sans plugin identifié. Les volumes restent associés au processus ; aucun plugin n’est déduit du seul port ou de l’adresse.}}</p>
        <div class="ps-toolbar"><button class="btn btn-default" id="ps-show-unknown">{{Voir les destinations non attribuées}}</button><button class="btn btn-default" id="ps-show-shared">{{Voir les destinations Jeedom partagé}}</button></div>
        <div class="ps-scroll"><table class="table"><thead><tr><th>{{Contexte}}</th><th>PID</th><th>{{Programme}}</th><th>{{Raison de l’attribution}}</th><th>{{Envoyé}}</th><th>{{Reçu}}</th></tr></thead><tbody id="ps-unassigned-rows"></tbody></table></div>
        <p class="ps-muted">{{Cette vue reste indépendante du filtre activé/désactivé : ces processus n’ont pas de statut de plugin établi. Les boutons ouvrent leurs destinations sur la période choisie.}}</p>
    </section>
    <section class="ps-card" id="ps-detail">
        <div class="ps-toolbar"><h2 id="ps-selection" tabindex="-1">{{Tous les échanges observés}}</h2><button type="button" class="btn btn-default" id="ps-all-plugins" hidden>{{Tous les plugins}}</button><label><input id="ps-cloud" type="checkbox"> {{Internet uniquement}}</label></div>
        <div class="ps-detail-filters">
            <label><i class="fas fa-puzzle-piece"></i> {{Plugin}} <select id="ps-detail-plugin" class="form-control" aria-label="{{Filtrer les échanges par plugin}}"><option value="">{{Tous les plugins}}</option></select></label>
            <label><i class="fas fa-globe"></i> {{Pays}} <select id="ps-country" class="form-control" aria-label="{{Filtrer les échanges par pays}}"><option value="">{{Tous les pays}}</option></select></label>
            <button type="button" id="ps-detail-clear" class="btn btn-default"><i class="fas fa-times"></i> {{Effacer les filtres des échanges}}</button>
        </div>
        <p id="ps-detail-status" class="ps-muted" role="status"></p>
        <div class="ps-chart-frame"><div id="ps-chart-y" class="ps-chart-y" aria-label="{{Échelle des volumes}}"></div><div class="ps-chart" id="ps-chart" aria-label="{{Historique des volumes}}"></div><div id="ps-chart-tooltip" class="ps-chart-tooltip" role="tooltip" hidden></div></div><div id="ps-chart-x" class="ps-chart-x" aria-label="{{Échelle de temps}}"></div>
        <div class="ps-chart-legend"><span class="ps-pill ps-tone-rx">↓ {{Reçu}}</span><span class="ps-pill ps-tone-tx">↑ {{Envoyé}}</span></div>
        <p class="ps-muted">{{Barres : reçu et envoyé sur chaque intervalle. Les périodes sans observation ne prouvent pas une absence de trafic.}}</p>
        <div class="ps-scroll"><table class="table ps-table" data-ps-table="destinations"><thead><tr><th><button type="button" data-sort="sourceName">{{Plugin}}</button></th><th><button type="button" data-sort="destination">{{Destination}}</button></th><th><button type="button" data-sort="scopeLabel">{{Réseau}}</button></th><th><button type="button" data-sort="countryLabel">{{Pays / opérateur}}</button></th><th><button type="button" data-sort="tx">{{Envoyé}}</button></th><th><button type="button" data-sort="rx">{{Reçu}}</button></th><th><button type="button" data-sort="first_seen">{{Première observation}}</button></th><th><button type="button" data-sort="last">{{Dernière activité}}</button></th></tr></thead><tbody id="ps-destinations"></tbody></table></div>
        <p class="ps-muted">{{Drapeaux : pays fournis par la base GeoIP locale. Maison : réseau local ; ordinateur : échanges internes ; globe : pays inconnu. Le pair local identifie l’autre extrémité d’une connexion, sans lui réattribuer les volumes du serveur partagé.}}</p>
        <p id="ps-detail-note" class="ps-muted"></p>
        <details open><summary>{{Connexions TCP actuellement ouvertes}}</summary><p id="ps-connections-note" class="ps-muted"></p><div class="ps-scroll"><table class="table" data-ps-table="connections"><thead><tr><th><button type="button" data-sort="sourceName">{{Plugin}}</button></th><th><button type="button" data-sort="destination">{{Destination}}</button></th><th><button type="button" data-sort="pid">PID</button></th><th><button type="button" data-sort="since">{{Observée ouverte depuis}}</button></th></tr></thead><tbody id="ps-connections"></tbody></table></div></details>
        <details><summary>{{Pourquoi cette attribution ? Processus récemment observés}}</summary><div class="ps-scroll"><table class="table" data-ps-table="processes"><thead><tr><th><button type="button" data-sort="pid">PID</button></th><th><button type="button" data-sort="comm">{{Programme}}</button></th><th><button type="button" data-sort="sourceName">{{Plugin}}</button></th><th><button type="button" data-sort="reason">{{Preuve}}</button></th><th><button type="button" data-sort="tx">{{Envoyé}}</button></th><th><button type="button" data-sort="rx">{{Reçu}}</button></th></tr></thead><tbody id="ps-processes"></tbody></table></div></details>
        <details><summary>{{Versions observées sur la période}}</summary><div class="ps-versions" id="ps-versions"></div></details>
    </section>
    <footer class="ps-muted">PluginScope v0.1.0 · {{Analyse locale, sans télémétrie ni lecture du contenu des communications.}}</footer>
    </div>
</div>
<?php include_file('desktop', 'table-utils', 'js', 'pluginscope'); ?>
<?php include_file('desktop', 'network', 'js', 'pluginscope'); ?>
<?php include_file('desktop', 'investigate', 'js', 'pluginscope'); ?>
<?php include_file('desktop', 'pluginscope', 'js', 'pluginscope'); ?>
