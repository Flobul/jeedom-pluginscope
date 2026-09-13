<?php
/** PluginScope — intégration Jeedom. Le réseau est observé par le collecteur Linux. */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class pluginscope extends eqLogic
{
    public static $_pluginVersion = '0.1.0';

    /** API Unix privée, sans port TCP ni clé dans une URL. */
    public static function request($action, $parameters = array())
    {
        $socket = @stream_socket_client('unix:///run/pluginscope/api.sock', $errno, $error, 2);
        if (!$socket) {
            throw new RuntimeException(__('Démon PluginScope indisponible. Vérifiez les dépendances puis démarrez-le.', __FILE__));
        }
        stream_set_timeout($socket, 8);
        try {
            $message = json_encode(array_merge($parameters, array('action' => $action)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            while ($message !== '') {
                $written = fwrite($socket, $message);
                if (!$written) {
                    throw new RuntimeException(__('Écriture vers le démon impossible', __FILE__));
                }
                $message = substr($message, $written);
            }
            $line = stream_get_line($socket, 8 * 1024 * 1024, "\n");
            $response = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            if (empty($response['ok'])) {
                throw new RuntimeException((string) ($response['error'] ?? __('Réponse du démon invalide', __FILE__)));
            }
            return $response['result'];
        } finally {
            fclose($socket);
        }
    }

    /** Transmet uniquement les identités des plugins/crons, jamais leurs configurations. */
    public static function synchronizeInventory()
    {
        $inventory = array('plugins' => array(), 'crons' => array());
        foreach (plugin::listPlugin(false) as $plugin) {
            $id = (string) $plugin->getId();
            $version = (string) $plugin->getInfo('pluginVersion', '');
            if ($version === '') {
                $update = $plugin->getUpdate();
                $version = is_object($update) ? (string) $update->getLocalVersion() : '';
            }
            $inventory['plugins'][$id] = array('name' => $plugin->getName(), 'version' => $version, 'enabled' => (bool) $plugin->isActive(), 'schedules' => array(), 'refresh' => self::refreshMetadata($id));
        }
        foreach (cron::all() as $cron) {
            $meta = self::cronMetadata($cron);
            $inventory['crons'][(string) $cron->getId()] = $meta;
            $owner = $cron->getClass();
            if (isset($inventory['plugins'][$owner])) {
                $inventory['plugins'][$owner]['schedules'][] = $meta;
            } elseif ($owner === 'plugin' && in_array($cron->getFunction(), array('cron', 'cron5', 'cron10', 'cron15', 'cron30', 'cronHourly', 'cronDaily'), true)) {
                foreach ($inventory['plugins'] as $id => &$entry) {
                    if ($entry['enabled'] && method_exists($id, $cron->getFunction())) {
                        $shared = $meta;
                        $shared['enabled'] = $meta['enabled'] && (bool) config::byKey('functionality::' . $cron->getFunction() . '::enable', $id, 1);
                        $entry['schedules'][] = $shared;
                    }
                }
                unset($entry);
            }
        }
        // Force les objets JSON même sur une installation sans cron/plugin.
        $inventory['plugins'] = (object) $inventory['plugins'];
        $inventory['crons'] = (object) $inventory['crons'];
        $networks = preg_split('/[\s,;]+/', trim((string) config::byKey('local_networks', __CLASS__, '')), -1, PREG_SPLIT_NO_EMPTY);
        return self::request('configure', array('inventory' => $inventory, 'settings' => array(
            'local_networks' => $networks,
            'retention_days' => (int) config::byKey('retention_days', __CLASS__, 30),
        )));
    }

    /** Planification exposée par Jeedom ; aucun appel à la tâche du plugin. */
    public static function cronMetadata($cron)
    {
        $meta = array('class' => $cron->getClass(), 'function' => $cron->getFunction(),
            'schedule' => (string) $cron->getSchedule(), 'enabled' => (bool) $cron->getEnable(),
            'daemon' => (bool) $cron->getDeamon(), 'once' => (bool) $cron->getOnce(),
            'last_run' => strtotime((string) $cron->getLastRun()) ?: null,
            'next_run' => null, 'interval_seconds' => null);
        if (!$meta['daemon']) {
            try {
                $timing = self::scheduleTiming($meta['schedule']);
                $meta['next_run'] = $timing['next_run'];
                if (!$meta['once']) { $meta['interval_seconds'] = $timing['interval_seconds']; }
            } catch (Throwable $exception) {
                // Expression non interprétable : conserver le texte, aucun délai inventé.
            }
        }
        return $meta;
    }

    private static function scheduleTiming($schedule)
    {
        $expression = new Cron\CronExpression(checkAndFixCron($schedule), new Cron\FieldFactory);
        $next = $expression->getNextRunDate();
        $first = $next->getTimestamp();
        $gaps = array();
        for ($i = 0; $i < 8; $i++) {
            $following = $expression->getNextRunDate($next->format('Y-m-d H:i:s'));
            $gaps[] = $following->getTimestamp() - $next->getTimestamp();
            $next = $following;
        }
        return array('next_run' => $first, 'interval_seconds' => max($gaps));
    }

    /** Only settings whose meaning/unit has been checked in the plugin source. */
    public static function refreshMetadata($id)
    {
        foreach (preg_split('/\R/', (string) config::byKey('refresh_overrides', __CLASS__, '')) as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*$/D', $line, $match)
                && $match[1] === $id && (float) $match[2] >= 1 && (float) $match[2] <= 7776000) {
                return array('interval_seconds' => (float) $match[2], 'basis' => 'declared', 'label' => 'Intervalle déclaré dans PluginScope');
            }
        }
        if ($id === 'smartthings') {
            $schedule = trim((string) config::byKey('autorefresh', $id, 'never'));
            if ($schedule === '' || $schedule === 'never') {
                return array('basis' => 'disabled', 'label' => 'Rafraîchissement automatique désactivé');
            }
            try {
                return self::scheduleTiming($schedule) + array('basis' => 'configured', 'label' => 'SmartThings · autorefresh', 'schedule' => $schedule);
            } catch (Throwable $error) { return array('basis' => 'unknown', 'label' => 'Réglage SmartThings non interprétable'); }
        }
        if ($id === 'localthings') {
            $legacy = config::byKey('poll_interval', $id, 5);
            $seconds = array();
            foreach (array('online' => $legacy, 'offline' => 5) as $state => $fallback) {
                $value = trim((string) config::byKey('poll_interval_' . $state, $id, $fallback));
                if ($value === '') { $value = (string) $fallback; }
                if (preg_match('/^(\d+)s$/iD', $value, $match)) { $seconds[$state] = max(10, min(86400, (int) $match[1])); }
                elseif (ctype_digit($value)) { $seconds[$state] = max(1, min(1440, (int) $value)) * 60; }
                else { return array(); }
            }
            return array('basis' => 'configured', 'interval_seconds' => $seconds['online'],
                'offline_seconds' => $seconds['offline'], 'label' => 'LocalThings · en ligne / hors ligne');
        }
        return array();
    }

    public static function importGeoIp($kind)
    {
        if (!in_array($kind, array('country', 'asn'), true)) { throw new InvalidArgumentException('Type GeoIP invalide'); }
        try { return self::request('geoip_import', array('kind' => $kind)); }
        catch (Throwable $error) {
            if (!is_file('/var/lib/pluginscope/' . $kind . '-upload.mmdb')) { throw $error; }
        }
        $output = array();
        exec('/usr/bin/python3 /usr/local/lib/pluginscope/daemon/geoip.py --import ' . escapeshellarg($kind) . ' 2>/dev/null', $output, $code);
        $result = json_decode(implode("\n", $output), true);
        if ($code !== 0 || !is_array($result) || isset($result['error'])) { throw new RuntimeException($result['error'] ?? __('Import impossible : vérifiez les dépendances', __FILE__)); }
        return $result;
    }

    public static function geoipStatus()
    {
        try { return self::request('health')['geoip']; } catch (Throwable $error) {}
        // Lire la base installée reste possible lorsque le démon est arrêté.
        $output = array();
        exec('/usr/bin/python3 /usr/local/lib/pluginscope/daemon/geoip.py --status 2>/dev/null', $output, $code);
        $result = json_decode(implode("\n", $output), true);
        if ($code !== 0 || !is_array($result)) { throw new RuntimeException(__('Installez les dépendances pour consulter les bases GeoIP', __FILE__)); }
        return $result;
    }

    public static function updateGeoIp()
    {
        $script = '/usr/local/lib/pluginscope/daemon/geoip_update.py';
        if (!is_file($script)) {
            log::add(__CLASS__, 'info', 'Les bases GeoIP seront téléchargées à l’installation des dépendances.');
            return;
        }
        $output = array();
        exec('/usr/bin/python3 ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        log::add(__CLASS__, $code === 0 ? 'info' : 'warning', implode(' ; ', $output));
    }

    public static function dependancy_info()
    {
        $installed = is_file('/usr/local/lib/pluginscope/collector/collector.py') && is_file('/etc/systemd/system/pluginscope.service');
        // Les copies installées doivent correspondre au code du plugin courant.
        foreach (array('collector/collector.py', 'collector/connections.py', 'collector/network.c', 'daemon/main.py', 'daemon/storage.py', 'daemon/analysis.py', 'daemon/geoip.py', 'daemon/geoip_update.py') as $file) {
            $target = '/usr/local/lib/pluginscope/' . $file;
            if (!is_file($target) || hash_file('sha256', $target) !== hash_file('sha256', __DIR__ . '/../../resources/' . $file)) {
                $installed = false;
            }
        }
        return array('state' => $installed ? 'ok' : 'nok', 'log' => __CLASS__ . '_update',
            'progress_file' => jeedom::getTmpFolder(__CLASS__) . '/dependance');
    }

    public static function dependancy_install()
    {
        log::remove(__CLASS__ . '_update');
        $script = realpath(__DIR__ . '/../../resources/install.sh');
        // Le Core vérifie le premier mot avec file_exists(), puis ajoute Bash.
        // Le chemin doit donc être fourni sans interpréteur ni guillemets.
        if ($script === false || !preg_match('#^/[A-Za-z0-9_./-]+$#D', $script)) {
            throw new RuntimeException(__('Script de dépendances introuvable ou chemin non pris en charge', __FILE__));
        }
        return array('script' => $script . ' '
            . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/dependance'), 'log' => log::getPathToLog(__CLASS__ . '_update'));
    }

    /** Commandes système fixes : aucun argument libre ne provient d'AJAX. */
    private static function service($operation)
    {
        if (!in_array($operation, array('start', 'stop'), true)) {
            throw new InvalidArgumentException('Opération interdite');
        }
        $output = array();
        exec(system::getCmdSudo() . ' /bin/systemctl ' . $operation . ' pluginscope-collector.service pluginscope.service 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(__('Gestion du service impossible : ', __FILE__) . implode(' ', $output));
        }
    }

    public static function deamon_info()
    {
        $health = array();
        try {
            $health = self::request('health');
        } catch (Throwable $exception) {
            // État arrêté attendu avant installation/démarrage.
        }
        $launchable = self::dependancy_info()['state'];
        // Le service d'analyse reste utile (historique, Santé) même si les
        // sondes échouent. Ne pas le relancer en boucle pour cette raison.
        return array('log' => __CLASS__, 'state' => !empty($health) ? 'ok' : 'nok',
            'collector_state' => !empty($health['operational']) ? 'ok' : 'nok',
            'launchable' => $launchable, 'launchable_message' => $launchable === 'ok' ? '' : __('Installez ou actualisez les dépendances', __FILE__));
    }

    public static function deamon_start()
    {
        if (self::dependancy_info()['state'] !== 'ok') {
            throw new RuntimeException(__('Installez les dépendances avant de démarrer', __FILE__));
        }
        self::service('start');
        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                self::synchronizeInventory();
                $health = self::request('health');
                log::add(__CLASS__, 'info', !empty($health['operational'])
                    ? __('Service d’analyse et collecte réseau actifs', __FILE__)
                    : __('Service d’analyse actif ; collecte réseau en attente ou indisponible. Consultez Santé.', __FILE__));
                return;
            } catch (Throwable $exception) {
                usleep(250000);
            }
        }
        throw new RuntimeException(__('Le service ne répond pas. Consultez Santé et le journal système pluginscope.', __FILE__));
    }

    public static function deamon_stop()
    {
        if (is_file('/etc/systemd/system/pluginscope.service')) {
            self::service('stop');
        }
    }

    public static function ensureEquipment()
    {
        $eqLogic = self::byLogicalId('server', __CLASS__);
        if (!is_object($eqLogic)) {
            $eqLogic = new self();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId('server');
            $eqLogic->setName('PluginScope');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->save();
        }
        return $eqLogic;
    }

    public function postSave()
    {
        $definitions = array(
            'rx_rate' => array('Débit reçu', 'numeric', 'o/s'), 'tx_rate' => array('Débit envoyé', 'numeric', 'o/s'),
            'rx_day' => array('Reçu aujourd’hui', 'numeric', 'o'), 'tx_day' => array('Envoyé aujourd’hui', 'numeric', 'o'),
            'local_rx' => array('Local reçu aujourd’hui', 'numeric', 'o'), 'local_tx' => array('Local envoyé aujourd’hui', 'numeric', 'o'),
            'internet_rx' => array('Internet reçu aujourd’hui', 'numeric', 'o'), 'internet_tx' => array('Internet envoyé aujourd’hui', 'numeric', 'o'),
            'local_percent' => array('Part locale', 'numeric', '%'), 'internet_percent' => array('Part Internet', 'numeric', '%'),
            'attribution_percent' => array('Trafic attribué', 'numeric', '%'), 'collector_online' => array('Collecteur actif', 'binary', ''),
            'internet_destinations' => array('Destinations Internet', 'numeric', ''), 'top_plugin' => array('Plugin le plus actif', 'string', ''),
        );
        foreach ($definitions as $id => $definition) {
            $command = $this->getCmd('info', $id);
            if (is_object($command)) {
                continue;
            }
            $command = new pluginscopeCmd();
            $command->setEqLogic_id($this->getId());
            $command->setLogicalId($id);
            $command->setName(__($definition[0], __FILE__));
            $command->setType('info');
            $command->setSubType($definition[1]);
            $command->setUnite($definition[2]);
            $command->setIsVisible(1);
            $command->setIsHistorized(0);
            $command->save();
        }
    }

    public function toHtml($_version = 'dashboard')
    {
        if (jeedom::versionAlias($_version) !== 'dashboard') {
            return parent::toHtml($_version);
        }
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        foreach (array('rx_rate', 'tx_rate', 'internet_rx', 'internet_tx', 'collector_online') as $id) {
            $command = $this->getCmd('info', $id);
            $replace['#' . $id . '_id#'] = is_object($command) ? (int) $command->getId() : 0;
            $replace['#' . $id . '#'] = is_object($command) ? (float) $command->execCmd() : 0;
        }
        $template = getTemplate('core', 'dashboard', 'pluginscope', __CLASS__);
        return $this->postToHtml($_version, translate::exec(template_replace($replace, $template), 'plugins/pluginscope/core/template/dashboard/pluginscope.html'));
    }

    /** Publie les seuls indicateurs synthétiques ; le détail reste dans SQLite. */
    public static function cron()
    {
        $eqLogic = self::ensureEquipment();
        try {
            self::synchronizeInventory();
            $data = self::request('snapshot', array('period' => max(60, time() - strtotime('today'))));
            $total = $data['totals'];
            $values = array('rx_rate' => $data['live']['rx_rate'], 'tx_rate' => $data['live']['tx_rate'],
                'rx_day' => $total['rx'], 'tx_day' => $total['tx'], 'collector_online' => !empty($data['live']['operational']) ? 1 : 0);
            foreach (array('local_rx', 'local_tx', 'internet_rx', 'internet_tx') as $key) {
                $values[$key] = $total[$key];
            }
            $external = $total['local'] + $total['internet'];
            if ($external > 0) {
                $values['local_percent'] = round(100 * $total['local'] / $external, 1);
                $values['internet_percent'] = round(100 * $total['internet'] / $external, 1);
            }
            if ($data['attribution_percent'] !== null) {
                $values['attribution_percent'] = $data['attribution_percent'];
            }
            $values['internet_destinations'] = $data['internet_destinations'];
            $values['top_plugin'] = '';
            foreach ($data['plugins'] as $plugin) {
                if (substr($plugin['id'], 0, 1) !== '_' && $plugin['rx'] + $plugin['tx'] > 0) {
                    $values['top_plugin'] = $plugin['name'];
                    break;
                }
            }
            foreach ($values as $id => $value) {
                $eqLogic->checkAndUpdateCmd($id, $value);
            }
        } catch (Throwable $exception) {
            $eqLogic->checkAndUpdateCmd('collector_online', 0);
            $eqLogic->checkAndUpdateCmd('rx_rate', 0);
            $eqLogic->checkAndUpdateCmd('tx_rate', 0);
            log::add(__CLASS__, 'debug', $exception->getMessage());
        }
    }

    public static function health()
    {
        $rows = array(array('test' => __('Dépendances', __FILE__), 'result' => self::dependancy_info()['state'],
            'advice' => __('Debian 12, noyau 6.1+, Python/BCC et services installés', __FILE__), 'state' => self::dependancy_info()['state'] === 'ok'));
        try {
            $health = self::request('health');
            $operational = !empty($health['operational']);
            $rows[] = array('test' => __('Collecte réseau réelle', __FILE__), 'result' => $operational ? 'OK' : 'NOK',
                'advice' => $health['collector_error'] ?: ($operational ? 'eBPF / TCP et UDP / IPv4 et IPv6' : __('En attente de mesures réseau exploitables', __FILE__)), 'state' => $operational);
            $available = !empty($health['connected']) && !empty($health['stats']);
            $losses = array_sum(array_map('intval', $health['stats'] ?? array()));
            $rows[] = array('test' => __('Qualité de collecte', __FILE__),
                'result' => !$available ? __('Indisponible', __FILE__) : ($losses > 0 ? __('À vérifier', __FILE__) : 'OK'),
                'advice' => !$available ? __('Collecte inactive ou compteurs non reçus : qualité non évaluable', __FILE__)
                    : ($losses > 0 ? __('Des pertes ont été détectées ; les volumes peuvent être incomplets', __FILE__) : __('Aucune perte signalée par les compteurs disponibles', __FILE__)),
                'state' => $available && $losses === 0);
        } catch (Throwable $exception) {
            $rows[] = array('test' => __('Démon', __FILE__), 'result' => 'NOK', 'advice' => $exception->getMessage(), 'state' => false);
        }
        return $rows;
    }
}

class pluginscopeCmd extends cmd
{
    public function execute($_options = array())
    {
        return null;
    }
}
