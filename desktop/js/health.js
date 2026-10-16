"use strict";

document.getElementById('ps-health-refresh')?.addEventListener('click', function () {
  jeeDialog.dialog({
    id: 'pluginscopeHealth',
    title: '{{Santé PluginScope}}',
    contentUrl: 'index.php?v=d&plugin=pluginscope&modal=health'
  });
});
