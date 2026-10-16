"use strict";

(function () {
  const root = document.getElementById('configuration_plugin_pluginscope');
  if (!root) return;

  function renderGeoVersions(value) {
    const body = document.getElementById('ps-geoip-versions');
    body.replaceChildren();
    [['country', '{{Pays}}'], ['asn', 'ASN']].forEach(function (item) {
      const kind = item[0], label = item[1], entry = value.installed?.[kind], row = document.createElement('tr');
      const loaded = (value.loaded || []).includes(kind);
      const cells = [label, entry ? new Date(entry.build_epoch * 1000).toLocaleString() : loaded ? '{{Version indisponible : actualisez les dépendances}}' : '{{Non installée}}', entry?.type || '—', entry ? (entry.size / 1048576).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' Mio' : '—'];
      cells.forEach(function (text) { const cell = document.createElement('td'); cell.textContent = text; row.append(cell); });
      body.append(row);
    });
    document.getElementById('ps-geoip-version-error').textContent = Object.values(value.errors || {}).join(' · ');
  }

  async function loadGeoVersions() {
    try {
      const response = await fetch('plugins/pluginscope/core/ajax/pluginscope.ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'geoipStatus' }) });
      const result = await response.json();
      if (result.state !== 'ok') throw new Error(result.result);
      renderGeoVersions(result.result);
    } catch (error) {
      document.getElementById('ps-geoip-version-error').textContent = error.message;
      document.getElementById('ps-geoip-versions').textContent = '{{Versions indisponibles}}';
    }
  }

  document.getElementById('ps-geoip-import')?.addEventListener('click', async function () {
    const output = document.getElementById('ps-geoip-result');
    const file = document.getElementById('ps-geoip-file').files[0];
    if (!file) { output.textContent = '{{Sélectionnez une base MMDB}}'; return; }
    const data = new FormData();
    data.append('action', 'importGeoip');
    data.append('kind', document.getElementById('ps-geoip-kind').value);
    data.append('file', file);
    this.disabled = true;
    try {
      const response = await fetch('plugins/pluginscope/core/ajax/pluginscope.ajax.php', { method: 'POST', body: data });
      const result = await response.json();
      if (result.state !== 'ok') throw new Error(result.result);
      output.textContent = '{{Base importée}}';
      renderGeoVersions(result.result);
    } catch (error) {
      output.textContent = error.message;
    } finally {
      this.disabled = false;
    }
  });

  const saveButton = document.getElementById('bt_savePluginConfig');
  const configInputs = root.querySelectorAll('.configKey');
  if (saveButton && configInputs.length && !document.querySelector('.modificationWithoutSave')) {
    const initialValues = new Map();
    let baselineReady = false;
    const message = document.createElement('i');
    message.className = 'modificationWithoutSave label label-warning pull-right';
    message.textContent = '{{Modification en cours...}}';
    message.style.display = 'none';
    saveButton.parentNode.insertBefore(message, saveButton.nextSibling);
    const valueOf = input => input.type === 'checkbox' || input.type === 'radio' ? input.checked : input.value;
    const refresh = () => {
      if (!baselineReady) return;
      let modified = false;
      configInputs.forEach(function (input) { modified = modified || valueOf(input) !== initialValues.get(input); });
      message.style.display = modified ? 'inline-block' : 'none';
    };
    configInputs.forEach(function (input) { input.addEventListener('input', refresh); input.addEventListener('change', refresh); });
    window.setTimeout(function () { configInputs.forEach(input => initialValues.set(input, valueOf(input))); baselineReady = true; refresh(); }, 500);
    saveButton.addEventListener('click', function () { window.setTimeout(function () { configInputs.forEach(input => initialValues.set(input, valueOf(input))); refresh(); }, 0); });
  }

  loadGeoVersions();
})();
