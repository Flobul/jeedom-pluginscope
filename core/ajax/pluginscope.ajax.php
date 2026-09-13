<?php
try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    require_once __DIR__ . '/../class/pluginscope.class.php';
    include_file('core', 'authentification', 'php');
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();
    switch ((string) init('action')) {
        case 'compare':
        case 'diagnostic_start':
        case 'diagnostic_poll':
            $source = (string) init('source', '');
            if ($source !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $source)) { throw new InvalidArgumentException('Plugin invalide'); }
            ajax::success(pluginscope::request((string) init('action'), array('source'=>$source, 'id'=>(string) init('id',''), 'period'=>max(60,min(2592000,(int) init('period',86400))))));
            break;
        case 'snapshot':
            $source = (string) init('source', '');
            if ($source !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $source)) {
                throw new InvalidArgumentException(__('Plugin invalide', __FILE__));
            }
            $country = (string) init('country', '');
            if ($country !== '' && !preg_match('/^(?:[A-Z]{2}|_local|_unknown)$/D', $country)) { throw new InvalidArgumentException(__('Pays invalide', __FILE__)); }
            $enabled = (string) init('enabled', 'all');
            if (!in_array($enabled, array('all', 'enabled', 'disabled'), true)) { throw new InvalidArgumentException(__('État invalide', __FILE__)); }
            ajax::success(pluginscope::request('snapshot', array('country' => $country, 'enabled' => $enabled, 'internet_only' => (string) init('internet_only', '0') === '1', 'period' => max(60, min(7776000, (int) init('period', 86400))), 'source' => $source)));
            break;
        case 'ensureEquipment':
            ajax::success(array('id' => pluginscope::ensureEquipment()->getId()));
            break;
        case 'geoipStatus':
            ajax::success(pluginscope::geoipStatus());
            break;
        case 'sync':
            ajax::success(pluginscope::synchronizeInventory());
            break;
        case 'health':
            ajax::success(pluginscope::request('health'));
            break;
        case 'importGeoip':
            $kind = (string) init('kind');
            if (!in_array($kind, array('country', 'asn'), true)) {
                throw new InvalidArgumentException(__('Type de base invalide', __FILE__));
            }
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 100 * 1024 * 1024) {
                throw new InvalidArgumentException(__('Fichier MMDB invalide ou trop volumineux (100 Mio maximum)', __FILE__));
            }
            $path = '/var/lib/pluginscope/' . $kind . '-upload.mmdb';
            $lock = fopen('/var/lib/pluginscope/geoip-import.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                if ($lock) { fclose($lock); }
                throw new RuntimeException(__('Un import est déjà en cours', __FILE__));
            }
            try {
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    throw new RuntimeException(__('Copie impossible ; vérifiez les dépendances', __FILE__));
                }
                chmod($path, 0600);
                $result = pluginscope::importGeoIp($kind);
            } finally {
                if (is_file($path)) { unlink($path); }
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            ajax::success($result);
            break;
        default:
            throw new InvalidArgumentException(__('Action inconnue', __FILE__));
    }
} catch (Throwable $exception) {
    // Le client utilise textContent : renvoyer du texte, pas le HTML Jeedom.
    ajax::error($exception->getMessage(), $exception->getCode());
}
