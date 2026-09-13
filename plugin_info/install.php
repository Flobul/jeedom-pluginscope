<?php
function pluginscope_install()
{
    config::save('local_networks', '', 'pluginscope');
    config::save('retention_days', 30, 'pluginscope');
    pluginscope::ensureEquipment();
    pluginscope::updateGeoIp();
}

function pluginscope_update()
{
    // Le code privilégié est actualisé par les dépendances, pas par le serveur web.
    pluginscope::ensureEquipment()->postSave();
    pluginscope::deamon_stop();
    pluginscope::updateGeoIp();
    log::add('pluginscope', 'info', __('Mise à jour : relancez les dépendances puis le démon pour installer le collecteur actualisé.', __FILE__));
}

function pluginscope_remove()
{
    pluginscope::deamon_stop();
    // L’historique privé est conservé ; aucune suppression irréversible implicite.
}
