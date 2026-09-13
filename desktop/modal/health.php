<?php
require_once __DIR__ . '/../../core/class/pluginscope.class.php';
if (!isConnect('admin')) { throw new Exception('401 Unauthorized'); }
include_file('desktop', 'pluginscope', 'css', 'pluginscope');
$escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$health = null;
try { $health = pluginscope::request('health'); } catch (Throwable $exception) { $error = $exception->getMessage(); }
?>
<div class="ps-page"><header class="ps-hero"><div><span class="ps-eyebrow">{{Diagnostic en direct}}</span><h1>{{Santé PluginScope}}</h1></div><button class="btn btn-primary" id="ps-health-refresh">{{Rafraîchir}}</button></header>
    <div class="ps-health-list">
    <?php foreach (pluginscope::health() as $row) { ?>
        <article><strong><?= $escape($row['test']) ?> — <?= $escape(in_array(strtoupper($row['result']), array('OK', 'NOK'), true) ? strtoupper($row['result']) : $row['result']) ?></strong><?= $escape($row['advice']) ?></article>
    <?php } ?>
    <?php if ($health) { ?>
        <article><strong>{{Analyse locale}}</strong>PID <?= $escape($health['pid']) ?> · {{Mémoire maximale}} <?= $escape(round($health['memory_kib']/1024, 1)) ?> Mio · {{Historique}} <?= $escape(round($health['database_bytes']/1048576, 1)) ?> Mio</article>
        <article><strong>{{Compteurs de collecte}}</strong>
            <?php if (!empty($health['decoder'])) { ?>
                <p>{{Depuis le démarrage du collecteur :}} <?= $escape($health['decoder']['received'] ?? 0) ?> {{événements reçus}}, <?= $escape($health['decoder']['decoded'] ?? 0) ?> {{événements décodés}}.</p>
                <?php if (!empty($health['decoder']['last_error'])) { ?><p>{{Dernière erreur de décodage :}} <?= $escape($health['decoder']['last_error']) ?></p><?php } ?>
            <?php } ?>
            <?php if (!empty($health['stats'])) { ?>
                <?php if (empty($health['connected'])) { ?><p>{{Collecte inactive : derniers compteurs reçus, sans évaluation de la période actuelle.}}</p><?php } ?>
                <pre><?= $escape(json_encode($health['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><p>{{Les compteurs indiquent des événements perdus, pas un nombre d’octets. Des pertes peuvent rendre les volumes incomplets.}}</p>
            <?php } else { ?><p>{{Aucun compteur reçu : le collecteur n’a pas encore transmis de mesures.}}</p><?php } ?>
        </article>
        <article><strong>{{Bases géographiques locales}}</strong>
            <?php if (!empty($health['geoip']['update']['checked_at'])) { ?><p>{{Dernière vérification automatique :}} <?= $escape(date('d/m/Y H:i', $health['geoip']['update']['checked_at'])) ?> · MaxMind / P3TERX</p><?php } ?>
            <?php foreach (($health['geoip']['update']['errors'] ?? array()) as $kind => $message) { ?><p><?= $escape($kind . ' : ' . $message) ?></p><?php } ?>
            <?php if (empty($health['geoip']['loaded']) && empty($health['geoip']['errors'])) { ?>
                <p>{{Aucune base importée. Fonction facultative, sans effet sur le démarrage de la collecte.}}</p>
            <?php } else { ?><pre><?= $escape(json_encode($health['geoip'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php } ?>
        </article>
        <article><strong>{{Limites de cette version}}</strong><ul><?php foreach ($health['limitations'] as $limit) { ?><li><?= $escape($limit) ?></li><?php } ?></ul></article>
        <details><summary>{{Interfaces et routes détectées}}</summary><pre><?= $escape(json_encode($health['topology'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
    <?php } else { ?><p class="alert alert-warning"><?= $escape($error) ?></p><?php } ?>
    </div>
    <p class="ps-muted">{{Si le collecteur ne démarre pas, son diagnostic système est disponible avec :}}</p><code>journalctl -u pluginscope-collector -n 50 --no-pager</code>
</div>
<script>document.getElementById('ps-health-refresh').addEventListener('click',function(){jeeDialog.dialog({id:'pluginscopeHealth',title:'{{Santé PluginScope}}',contentUrl:'index.php?v=d&plugin=pluginscope&modal=health'});});</script>
