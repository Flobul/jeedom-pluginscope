<?php
require_once __DIR__ . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) { throw new Exception(__('401 - Accès non autorisé', __FILE__)); }
$plugin = plugin::byId('pluginscope');
$update = $plugin->getUpdate();
include_file('desktop', 'pluginscope', 'css', 'pluginscope');
?>
<div class="ps-page ps-config-page" id="configuration_plugin_pluginscope">
    <header class="ps-hero ps-config-hero">
        <div class="ps-config-identity">
            <img class="ps-config-logo" src="plugins/pluginscope/plugin_info/pluginscope_icon.png" alt="PluginScope">
            <div><span class="ps-eyebrow">{{Configuration du plugin}}</span><h1>PluginScope</h1><p>{{Comprendre les communications de vos plugins, depuis votre Jeedom.}}</p></div>
        </div>
        <div class="ps-config-meta">
            <span><i class="fas fa-code-branch"></i> {{Version}} <strong>v0.1.0</strong></span>
            <?php if (is_object($update)) { ?><span><i class="fas fa-stream"></i> <?php echo htmlspecialchars((string) $update->getConfiguration('version', 'stable'), ENT_QUOTES, 'UTF-8'); ?></span><span><i class="fas fa-cloud-download-alt"></i> <?php echo htmlspecialchars((string) $update->getSource(), ENT_QUOTES, 'UTF-8'); ?></span><?php } ?>
        </div>
        <nav class="ps-actions ps-config-links" aria-label="{{Ressources du plugin}}">
            <a class="btn btn-primary" href="index.php?v=d&m=pluginscope&p=pluginscope"><i class="fas fa-chart-line"></i> {{Ouvrir l’analyse}}</a>
            <a class="btn btn-default" href="<?php echo htmlspecialchars((string) $plugin->getDocumentation(), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-book"></i> {{Documentation}}</a>
            <a class="btn btn-default" href="<?php echo htmlspecialchars((string) $plugin->getChangelog(), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-list"></i> {{Changelog}}</a>
            <a class="btn btn-default ps-config-link-community" href="https://community.jeedom.com/tag/plugin-pluginscope" target="_blank" rel="noopener noreferrer"><i class="fas fa-comments"></i> {{Communauté}}</a>
        </nav>
    </header>

    <div class="ps-config-grid">
        <section class="ps-card"><h2><i class="fas fa-network-wired"></i> {{Surveillance réseau}}</h2><p>{{Installez les dépendances puis démarrez le démon depuis les contrôles Jeedom. La collecte commence après le chargement des sondes eBPF.}}</p><p>{{Cette première version nécessite Debian 12 avec un noyau Linux 6.1 ou plus récent, sur un système 64 bits. Les conteneurs peuvent restreindre la collecte.}}</p><p>{{Aucune règle de pare-feu n’est ajoutée. Aucun contenu de communication n’est enregistré.}}</p></section>
        <section class="ps-card"><h2><i class="fas fa-history"></i> {{Réseaux et historique}}</h2><label for="ps-networks">{{Réseaux locaux supplémentaires}}</label><textarea id="ps-networks" class="configKey form-control" data-l1key="local_networks" rows="3" placeholder="192.168.20.0/24&#10;fd00::/64"></textarea><p class="ps-muted">{{Les adresses privées et link-local sont reconnues automatiquement. Ajoutez vos autres réseaux locaux en notation CIDR, un par ligne. Un proxy local peut masquer une destination Internet.}}</p><label for="ps-retention">{{Durée de conservation}}</label><select id="ps-retention" class="configKey form-control" data-l1key="retention_days"><option value="7">{{7 jours}}</option><option value="30">{{30 jours}}</option><option value="90">{{90 jours}}</option></select><p class="ps-muted">{{Les réglages sont transmis au démon au prochain passage de la tâche Jeedom ou à l’ouverture de l’analyse.}}</p></section>
    </div>
    <section class="ps-card"><h2><i class="fas fa-stopwatch"></i> {{Intervalles de rafraîchissement complémentaires}}</h2><p>{{Si le réglage d’un démon n’est pas reconnu automatiquement, indiquez son intervalle en secondes : identifiant_plugin=secondes, une ligne par plugin. Ce repère ne modifie pas le fonctionnement du plugin surveillé.}}</p><textarea class="configKey form-control" data-l1key="refresh_overrides" rows="3" placeholder="monplugin=10"></textarea></section>
    <section class="ps-card"><h2><i class="fas fa-globe"></i> {{Pays et opérateurs — facultatif}}</h2><p>{{Les bases Pays et ASN MaxMind GeoLite2 sont vérifiées et téléchargées depuis le miroir P3TERX à l’installation des dépendances et aux mises à jour du plugin. Les fichiers valides ne sont remplacés que par une version plus récente.}}</p><div class="ps-scroll"><table class="table"><thead><tr><th>{{Base}}</th><th>{{Version installée}}</th><th>{{Format}}</th><th>{{Taille}}</th></tr></thead><tbody id="ps-geoip-versions"><tr><td colspan="4">{{Chargement des versions…}}</td></tr></tbody></table></div><p id="ps-geoip-version-error" role="status"></p><h3>{{Installer ma propre base MMDB}}</h3><div class="ps-actions"><select id="ps-geoip-kind" class="form-control"><option value="country">{{Pays}}</option><option value="asn">ASN</option></select><input type="file" id="ps-geoip-file" accept=".mmdb"><button type="button" class="btn btn-primary" id="ps-geoip-import">{{Importer la base}}</button></div><p id="ps-geoip-result" role="status"></p><p class="ps-muted">{{Seul le téléchargement des bases utilise Internet. La recherche de pays reste locale : aucune adresse IP observée n’est transmise au fournisseur.}}</p><p class="ps-muted">{{Données GeoLite créées par}} <a href="https://www.maxmind.com/" target="_blank" rel="noopener">MaxMind</a> · <a href="https://github.com/P3TERX/GeoLite.mmdb" target="_blank" rel="noopener">P3TERX</a> · <a href="https://www.maxmind.com/en/geolite/eula" target="_blank" rel="noopener">{{Conditions GeoLite}}</a> · <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">CC BY-SA 4.0</a></p></section>
</div>
<?php include_file('desktop', 'configuration', 'js', 'pluginscope'); ?>
