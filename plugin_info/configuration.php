<?php
require_once __DIR__ . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) { throw new Exception('401 Unauthorized'); }
include_file('desktop', 'pluginscope', 'css', 'pluginscope');
?>
<div class="ps-page">
    <header class="ps-hero"><div><span class="ps-eyebrow">{{Configuration du plugin}} · v0.1.0</span><h1>PluginScope</h1><p>{{Comprendre les communications de vos plugins, depuis votre Jeedom.}}</p></div><div class="ps-actions"><a class="btn btn-primary" href="index.php?v=d&m=pluginscope&p=pluginscope">{{Ouvrir l’analyse}}</a><a class="btn btn-default" href="plugins/pluginscope/docs/fr_FR/index.md" target="_blank" rel="noopener">{{Documentation}}</a><a class="btn btn-default" href="plugins/pluginscope/CHANGELOG.md" target="_blank" rel="noopener">{{Changelog}}</a></div></header>
    <div class="ps-config-grid">
        <section class="ps-card"><h2>{{Surveillance réseau}}</h2><p>{{Installez les dépendances puis démarrez le démon depuis les contrôles Jeedom. La collecte commence après le chargement des sondes eBPF.}}</p><p>{{Cette première version nécessite Debian 12 avec un noyau Linux 6.1 ou plus récent, sur un système 64 bits. Les conteneurs peuvent restreindre la collecte.}}</p><p>{{Aucune règle de pare-feu n’est ajoutée. Aucun contenu de communication n’est enregistré.}}</p></section>
        <section class="ps-card"><h2>{{Réseaux et historique}}</h2><label for="ps-networks">{{Réseaux locaux supplémentaires}}</label><textarea id="ps-networks" class="configKey form-control" data-l1key="local_networks" rows="3" placeholder="192.168.20.0/24&#10;fd00::/64"></textarea><p class="ps-muted">{{Les adresses privées et link-local sont reconnues automatiquement. Ajoutez vos autres réseaux locaux en notation CIDR, un par ligne. Un proxy local peut masquer une destination Internet.}}</p><label for="ps-retention">{{Durée de conservation}}</label><select id="ps-retention" class="configKey form-control" data-l1key="retention_days"><option value="7">{{7 jours}}</option><option value="30">{{30 jours}}</option><option value="90">{{90 jours}}</option></select><p class="ps-muted">{{Enregistrez la configuration. Les réglages sont transmis au démon au prochain passage de la tâche Jeedom, ou à l’ouverture de l’analyse.}}</p></section>
    </div>
    <section class="ps-card"><h2>{{Intervalles de rafraîchissement complémentaires}}</h2><p>{{Si le réglage d’un démon n’est pas reconnu automatiquement, indiquez son intervalle en secondes : identifiant_plugin=secondes, une ligne par plugin. Exemple : monplugin=10. Ce repère ne modifie pas le fonctionnement du plugin surveillé.}}</p><textarea class="configKey form-control" data-l1key="refresh_overrides" rows="3" placeholder="monplugin=10"></textarea></section>
    <section class="ps-card"><h2>{{Pays et opérateurs — facultatif}}</h2><p>{{Les bases Pays et ASN MaxMind GeoLite2 sont vérifiées et téléchargées depuis le miroir P3TERX à l’installation des dépendances et aux mises à jour du plugin. Les fichiers valides ne sont remplacés que par une version plus récente. L’import manuel reste disponible ; la prochaine actualisation automatique peut le remplacer. Par précaution, les bases GeoLite de plus de 30 jours ne sont pas utilisées.}}</p><div class="ps-scroll"><table class="table"><thead><tr><th>{{Base}}</th><th>{{Version installée}}</th><th>{{Format}}</th><th>{{Taille}}</th></tr></thead><tbody id="ps-geoip-versions"><tr><td colspan="4">{{Chargement des versions…}}</td></tr></tbody></table></div><p id="ps-geoip-version-error" role="status"></p><h3>{{Installer ma propre base MMDB}}</h3><div class="ps-actions"><select id="ps-geoip-kind" class="form-control"><option value="country">{{Pays}}</option><option value="asn">ASN</option></select><input type="file" id="ps-geoip-file" accept=".mmdb"><button type="button" class="btn btn-primary" id="ps-geoip-import">{{Importer la base}}</button></div><p id="ps-geoip-result" role="status"></p><p class="ps-muted">{{Seul le téléchargement des bases utilise Internet. La recherche de pays reste locale : aucune adresse IP observée n’est transmise au fournisseur. Le pays estimé ne prouve pas où vos données sont stockées.}}</p><p class="ps-muted">{{Données GeoLite créées par}} <a href="https://www.maxmind.com/" target="_blank" rel="noopener">MaxMind</a> · <a href="https://github.com/P3TERX/GeoLite.mmdb" target="_blank" rel="noopener">P3TERX</a> · <a href="https://www.maxmind.com/en/geolite/eula" target="_blank" rel="noopener">{{Conditions GeoLite}}</a> · <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">CC BY-SA 4.0</a></p></section>
</div>
<script>
function pluginscopeRenderGeoVersions(value) {
    const body=document.getElementById('ps-geoip-versions');body.replaceChildren();
    for (const [kind,label] of [['country','{{Pays}}'],['asn','ASN']]) {
        const entry=value.installed?.[kind], tr=document.createElement('tr');
        const loaded=(value.loaded || []).includes(kind);
        const cells=[label,entry ? new Date(entry.build_epoch*1000).toLocaleString() : loaded ? '{{Version indisponible : actualisez les dépendances}}' : '{{Non installée}}',entry?.type || '—',entry ? (entry.size/1048576).toLocaleString(undefined,{maximumFractionDigits:1})+' Mio':'—'];
        cells.forEach(text=>{const td=document.createElement('td');td.textContent=text;tr.append(td);});body.append(tr);
    }
    document.getElementById('ps-geoip-version-error').textContent=Object.values(value.errors || {}).join(' · ');
}
async function pluginscopeLoadGeoVersions() {
    try {
        const response=await fetch('plugins/pluginscope/core/ajax/pluginscope.ajax.php',{method:'POST',body:new URLSearchParams({action:'geoipStatus'})});
        const result=await response.json();if(result.state!=='ok')throw new Error(result.result);
        pluginscopeRenderGeoVersions(result.result);
    } catch(error){document.getElementById('ps-geoip-version-error').textContent=error.message;document.getElementById('ps-geoip-versions').textContent='{{Versions indisponibles}}';}
}
pluginscopeLoadGeoVersions();
document.getElementById('ps-geoip-import').addEventListener('click', async function () {
    const output=document.getElementById('ps-geoip-result'), file=document.getElementById('ps-geoip-file').files[0];
    if(!file){output.textContent='{{Sélectionnez une base MMDB}}';return;}
    const data=new FormData();data.append('action','importGeoip');data.append('kind',document.getElementById('ps-geoip-kind').value);data.append('file',file);
    this.disabled=true;
    try{const response=await fetch('plugins/pluginscope/core/ajax/pluginscope.ajax.php',{method:'POST',body:data});const result=await response.json();if(result.state!=='ok')throw new Error(result.result);output.textContent='{{Base importée}}';pluginscopeRenderGeoVersions(result.result);}catch(error){output.textContent=error.message;}finally{this.disabled=false;}
});
</script>
