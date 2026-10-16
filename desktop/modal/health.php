<?php
require_once __DIR__ . '/../../core/class/pluginscope.class.php';
if (!isConnect('admin')) { throw new Exception(__('401 - Accès non autorisé', __FILE__)); }
include_file('desktop', 'pluginscope', 'css', 'pluginscope');
$escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$health = null;
$error = '';
try { $health = pluginscope::request('health'); } catch (Throwable $exception) { $error = $exception->getMessage(); }
$checks = pluginscope::health();
$passedChecks = 0;
foreach ($checks as $check) {
    if (strtoupper((string) ($check['result'] ?? '')) === 'OK' || !empty($check['state'])) $passedChecks++;
}
$connected = !empty($health['connected']);
$memoryMiB = $health ? round(((float) ($health['memory_kib'] ?? 0)) / 1024, 1) : 0;
$databaseMiB = $health ? round(((float) ($health['database_bytes'] ?? 0)) / 1048576, 1) : 0;
?>
<div class="ps-page ps-health-page" id="pluginscope-health">
    <header class="ps-hero ps-health-hero"><span class="ps-health-hero-icon"><i class="fas fa-heartbeat"></i></span><div><span class="ps-eyebrow">{{Diagnostic en direct}}</span><h1>{{Santé PluginScope}}</h1><p>{{État du collecteur eBPF, stockage local et contrôles système.}}</p></div><button type="button" class="btn btn-primary" id="ps-health-refresh"><i class="fas fa-sync-alt"></i> {{Rafraîchir}}</button></header>

    <section class="ps-health-summary" aria-label="{{Résumé de la santé PluginScope}}">
        <article><span class="ps-health-summary-icon"><i class="fas fa-clipboard-check"></i></span><div><span>{{Contrôles réussis}}</span><strong><?php echo (int) $passedChecks; ?> / <?php echo count($checks); ?></strong><small>{{diagnostic système}}</small></div></article>
        <article><span class="ps-health-summary-icon"><i class="fas fa-satellite-dish"></i></span><div><span>{{Collecteur}}</span><strong><?php echo $connected ? '{{Connecté}}' : '{{Inactif}}'; ?></strong><small>PID <?php echo $health ? $escape($health['pid'] ?? '—') : '—'; ?></small></div></article>
        <article><span class="ps-health-summary-icon"><i class="fas fa-memory"></i></span><div><span>{{Mémoire maximale}}</span><strong><?php echo $escape($memoryMiB); ?> Mio</strong><small>{{processus collecteur}}</small></div></article>
        <article><span class="ps-health-summary-icon"><i class="fas fa-database"></i></span><div><span>{{Historique}}</span><strong><?php echo $escape($databaseMiB); ?> Mio</strong><small>{{base locale}}</small></div></article>
    </section>

    <section class="ps-card ps-health-panel">
        <div class="ps-health-list">
        <?php foreach ($checks as $row) { ?>
            <article><strong><?php echo $escape($row['test']); ?> — <?php echo $escape(in_array(strtoupper((string) $row['result']), array('OK', 'NOK'), true) ? strtoupper((string) $row['result']) : $row['result']); ?></strong><?php echo $escape($row['advice']); ?></article>
        <?php } ?>
        <?php if ($health) { ?>
            <article><strong>{{Analyse locale}}</strong>PID <?php echo $escape($health['pid']); ?> · {{Mémoire maximale}} <?php echo $escape($memoryMiB); ?> Mio · {{Historique}} <?php echo $escape($databaseMiB); ?> Mio</article>
            <article><strong>{{Compteurs de collecte}}</strong>
                <?php if (!empty($health['decoder'])) { ?><p>{{Depuis le démarrage du collecteur :}} <?php echo $escape($health['decoder']['received'] ?? 0); ?> {{événements reçus}}, <?php echo $escape($health['decoder']['decoded'] ?? 0); ?> {{événements décodés}}.</p><?php if (!empty($health['decoder']['last_error'])) { ?><p>{{Dernière erreur de décodage :}} <?php echo $escape($health['decoder']['last_error']); ?></p><?php } ?><?php } ?>
                <?php if (!empty($health['stats'])) { ?><?php if (!$connected) { ?><p>{{Collecte inactive : derniers compteurs reçus, sans évaluation de la période actuelle.}}</p><?php } ?><pre><?php echo $escape(json_encode($health['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre><p>{{Les compteurs indiquent des événements perdus, pas un nombre d’octets. Des pertes peuvent rendre les volumes incomplets.}}</p><?php } else { ?><p>{{Aucun compteur reçu : le collecteur n’a pas encore transmis de mesures.}}</p><?php } ?>
            </article>
            <article><strong>{{Bases géographiques locales}}</strong><?php if (!empty($health['geoip']['update']['checked_at'])) { ?><p>{{Dernière vérification automatique :}} <?php echo $escape(date('d/m/Y H:i', $health['geoip']['update']['checked_at'])); ?> · MaxMind / P3TERX</p><?php } ?><?php foreach (($health['geoip']['update']['errors'] ?? array()) as $kind => $message) { ?><p><?php echo $escape($kind . ' : ' . $message); ?></p><?php } ?><?php if (empty($health['geoip']['loaded']) && empty($health['geoip']['errors'])) { ?><p>{{Aucune base importée. Fonction facultative, sans effet sur le démarrage de la collecte.}}</p><?php } else { ?><pre><?php echo $escape(json_encode($health['geoip'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre><?php } ?></article>
            <article><strong>{{Limites de cette version}}</strong><ul><?php foreach ($health['limitations'] as $limit) { ?><li><?php echo $escape($limit); ?></li><?php } ?></ul></article>
            <details><summary>{{Interfaces et routes détectées}}</summary><pre><?php echo $escape(json_encode($health['topology'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        <?php } else { ?><p class="alert alert-warning"><?php echo $escape($error); ?></p><?php } ?>
        </div>
    </section>
    <p class="ps-muted">{{Si le collecteur ne démarre pas, son diagnostic système est disponible avec :}}</p><code>journalctl -u pluginscope-collector -n 50 --no-pager</code>
</div>
<?php include_file('desktop', 'health', 'js', 'pluginscope'); ?>
