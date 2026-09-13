/* PluginScope: local API only; all network-derived strings rendered as text. */
(function () {
    'use strict';
    const root = document.getElementById('pluginscope');
    if (!root) return;
    if (window.pluginscopeController) window.pluginscopeController.abort();
    const controller = new AbortController();
    window.pluginscopeController = controller;
    let selection = '', data = null, detailData = null, busy = false;
    const tables = window.PluginScopeTables;
    const sorts = {plugins: {key:'rx', descending:true}, destinations: {key:'tx', descending:true},
        connections: {key:'sourceName', descending:false}, processes: {key:'tx', descending:true}};
    let pluginIndex = {};
    const el = id => root.querySelector('#' + id);
    let enabledState='all';
    try { const saved=localStorage.getItem('pluginscope.enabled'); if (['all','enabled','disabled'].includes(saved)) enabledState=saved; } catch {}
    const enabledMatches = id => tables.matchesStatus(pluginIndex[id]?.enabled, enabledState);
    function updateToggle() {
        el('ps-enabled').querySelectorAll('[data-state]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.state===enabledState)));
        try { localStorage.setItem('pluginscope.enabled',enabledState); } catch {}
    }
    updateToggle();
    const countryKey = d => ['local','loopback','multicast','broadcast'].includes(d.scope) ? '_local' : /^[A-Z]{2}$/.test(d.country_code || '') ? d.country_code : '_unknown';
    const countryMatches = d => !el('ps-country').value || countryKey(d)===el('ps-country').value;
    function options(select, entries, selected, allLabel) {
        const signature=JSON.stringify(entries);
        if (select.dataset.options!==signature) {
            select.replaceChildren(new Option(allLabel,''));
            entries.forEach(([value,label])=>select.add(new Option(label,value)));
            select.dataset.options=signature;
        }
        if (selected && ![...select.options].some(o=>o.value===selected)) select.add(new Option(selected,selected));
        select.value=selected;
    }
    const sorted = (table, rows) => tables.sortRows(rows, sorts[table].key, sorts[table].descending);
    function sortIndicators() {
        root.querySelectorAll('[data-ps-table]').forEach(table => {
            const current = sorts[table.dataset.psTable];
            table.querySelectorAll('[data-sort]').forEach(button => button.closest('th').setAttribute('aria-sort',
                button.dataset.sort === current.key ? (current.descending ? 'descending' : 'ascending') : 'none'));
        });
    }
    const bytes = n => {
        if (n === null || n === undefined) return '—';
        const units = ['o', 'Kio', 'Mio', 'Gio']; let i = 0;
        while (n >= 1024 && i < 3) { n /= 1024; i++; }
        return n.toLocaleString(undefined, {maximumFractionDigits: i ? 1 : 0}) + ' ' + units[i];
    };
    const percent = n => n === null ? '—' : n.toLocaleString(undefined, {maximumFractionDigits:1}) + ' %';
    const date = n => n ? new Date(n * 1000).toLocaleString() : '—';
    const node = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
    const badge = (text, tone='neutral') => node('span',text,'ps-pill ps-tone-'+tone);
    const duration = seconds => {
        if (!seconds) return '—';
        const unit = seconds >= 86400 ? [86400,'j'] : seconds >= 3600 ? [3600,'h'] : seconds >= 60 ? [60,'min'] : [1,'s'];
        return (seconds/unit[0]).toLocaleString(undefined,{maximumFractionDigits:1})+' '+unit[1];
    };
    function destinationCell(d) {
        const cell=node('div',undefined,'ps-destination');
        const code=(d.country_code || '').toUpperCase();
        const known=d.scope==='internet' && /^[A-Z]{2}$/.test(code);
        const flag=node('span',known ? String.fromCodePoint(...[...code].map(c=>127397+c.charCodeAt(0))) :
            d.scope==='local' ? '🏠' : d.scope==='loopback' ? '🖥️' : '🌐','ps-flag');
        flag.setAttribute('role','img');
        flag.setAttribute('aria-label',known ? (d.country || code) : d.scope==='local' ? '{{Réseau local}}' : d.scope==='loopback' ? '{{Communication interne}}' : '{{Pays inconnu}}');
        flag.title=flag.getAttribute('aria-label');
        cell.append(flag,node('span',tables.endpoint(d.address,d.port),'ps-address'),badge(d.protocol || 'TCP',d.protocol==='UDP'?'udp':'tcp'));
        return cell;
    }
    function scheduleCell(p) {
        const cell=node('div',undefined,'ps-schedule');
        const schedules=p.schedules || [], observation=p.observation || {state:'unknown'};
        if (observation.state==='disabled') { cell.append(badge('{{Plugin désactivé}}')); return cell; }
        if (observation.interval_seconds) {
            const refresh=p.refresh || {};
            const label=observation.basis==='observed' ? '{{Rythme réseau observé}}' : observation.basis==='declared' ? '{{Rafraîchissement déclaré}}' : '{{Rafraîchissement configuré}}';
            const value=refresh.offline_seconds ? duration(refresh.interval_seconds)+' / '+duration(refresh.offline_seconds)+' {{en ligne / hors ligne}}' : duration(observation.interval_seconds);
            cell.append(badge(label+' : ≈ '+value,'tcp'));
            const progress=node('progress'); progress.max=100; progress.value=observation.progress;
            progress.setAttribute('aria-label','{{Durée collectée comparée à trois intervalles de référence}}');
            progress.title=duration(data.coverage_seconds)+' / '+duration(observation.target_seconds);
            cell.append(progress,node('small',observation.state==='short' ? '{{Recul court}}' : '{{Durée cible atteinte}}','ps-share'));
            cell.append(node('small',duration(data.coverage_seconds)+' / '+duration(observation.target_seconds)+' {{de collecte}}'));
        } else {
            cell.append(badge(observation.label || (schedules.some(c=>c.enabled && c.daemon) ? '{{Démon : rythme réseau non exposé}}' :
                schedules.some(c=>c.enabled && c.once) ? '{{Tâche ponctuelle}}' : schedules.length && schedules.every(c=>!c.enabled) ? '{{Crons désactivés}}' : '{{Rafraîchissement non exposé}}')));
        }
        if (observation.cron_min) cell.append(node('small','{{Crons Jeedom}} : '+duration(observation.cron_min)+(observation.cron_max!==observation.cron_min ? ' – '+duration(observation.cron_max) : '')+' · {{hors fréquence réseau}}'));
        const next=schedules.filter(c=>c.enabled && !c.daemon && c.next_run).map(c=>c.next_run);
        if (p.refresh?.next_run) cell.append(node('small','{{Prochain rafraîchissement prévu}} : '+date(p.refresh.next_run)));
        else if (next.length) cell.append(node('small','{{Prochain cron}} : '+date(Math.min(...next))));
        cell.title=(p.refresh?.label ? p.refresh.label+'\n' : '')+schedules.map(c=>c.class+'::'+c.function+' · '+c.schedule+(c.enabled?'':' · {{Désactivé}}')).join('\n');
        return cell;
    }
    function networkVolume(value, share, tone) {
        const cell = node('div');
        cell.append(badge(bytes(value),share === null ? 'neutral' : tone),node('small',percent(share),'ps-share'));
        return cell;
    }
    function row(body, values) { const tr = node('tr'); values.forEach(value => { const td = node('td'); if (value instanceof Node) td.append(value); else td.textContent = value; tr.append(td); }); body.append(tr); return tr; }
    async function api(action, extra = {}) {
        const response = await fetch('plugins/pluginscope/core/ajax/pluginscope.ajax.php', {
            method: 'POST', signal: controller.signal, body: new URLSearchParams({action, ...extra}), credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || result.state !== 'ok') throw new Error(result.result || '{{Requête impossible}}');
        return result.result;
    }
    function render(result) {
        data = result;
        pluginIndex = Object.fromEntries(data.plugins.map(p => [p.id, p]));
        options(el('ps-detail-plugin'),data.plugins.filter(p=>enabledMatches(p.id)).map(p=>[p.id,p.name]).sort((a,b)=>a[1].localeCompare(b[1])),selection,'{{Tous les plugins}}');
        const query = el('ps-search').value.toLocaleLowerCase();
        const names = Object.assign({_core:'{{Core Jeedom}}', _unknown:'{{Non attribué}}', _shared:'{{Jeedom partagé}}'}, Object.fromEntries(data.plugins.map(p => [p.id, p.name])));
        const filtered = p => enabledMatches(p.id) && (!query || [p.name, p.id, p.mode, p.statusLabel].join(' ').toLocaleLowerCase().includes(query));
        const collectionStatus = !data.live.connected ? '{{Collecte interrompue}}' : data.live.operational ? '{{Collecte active}}' :
            data.live.decoding === 'blocked' ? '{{Décodage bloqué}}' : '{{En attente de mesures}}';
        el('ps-status').textContent = collectionStatus + ' · {{Observation}} : ' +
            Math.round(data.coverage_seconds / 60) + ' min / ' + Math.round(data.period / 60) + ' min · {{Attribution établie}} : ' +
            (data.attribution_percent === null ? '—' : data.attribution_percent + ' %');
        const stats = data.live.stats || {};
        const lost = Object.values(stats).some(value => Number(value) > 0);
        const blocked = data.live.connected && data.live.decoding === 'blocked';
        el('ps-error').hidden = !blocked && !lost && !data.detail_pruned;
        el('ps-error').textContent = blocked ? '{{Le collecteur reçoit des événements mais ne parvient pas à les décoder. Consultez Santé.}}' :
            data.detail_pruned ? '{{Une partie du détail ancien a été purgée pour limiter le stockage. Les totaux affichés portent sur les données conservées.}}' : '{{Des pertes de collecte ont été détectées. Consultez Santé : les volumes peuvent être incomplets.}}';
        const cards = el('ps-counters'); cards.replaceChildren();
        const shares = tables.networkShares(data.totals.local,data.totals.internet);
        [['{{Local}}', bytes(data.totals.local), percent(shares.local)+' {{du total Local + Internet}}'], ['{{Internet}}', bytes(data.totals.internet), percent(shares.internet)+' {{du total Local + Internet}}'],
         ['{{Débit reçu}}', bytes(data.live.rx_rate)+'/s', '{{Tous les processus observés}}'], ['{{Débit envoyé}}', bytes(data.live.tx_rate)+'/s', '{{Tous les processus observés}}']].forEach(([label,value,note], index) => {
            const [tone,icon]=[['local','fa-house-user'],['internet','fa-globe'],['rx','fa-arrow-down'],['tx','fa-arrow-up']][index];
            const card = node('article', undefined, 'ps-counter ps-counter-'+tone);
            const heading=node('div',undefined,'ps-counter-heading'),symbol=node('i',undefined,'fas '+icon);symbol.setAttribute('aria-hidden','true');
            heading.append(symbol,node('span',label));card.append(heading,node('strong',value),node('span',note));cards.append(card);
        });
        const body = el('ps-plugins').querySelector('tbody'); body.replaceChildren();
        const pluginRows = data.plugins.map(p => ({...p, statusLabel:p.enabled === true ? '{{Activé}}' : p.enabled === false ? '{{Désactivé}}' : '—', cadenceLabel:p.cadence.label, interval_seconds:p.observation?.interval_seconds ?? null}));
        sorted('plugins', pluginRows.filter(filtered)).forEach(p => {
            const button = node('button', p.name, 'ps-plugin-link'); button.type = 'button'; button.title=p.id;
            button.setAttribute('aria-controls','ps-detail'); button.setAttribute('aria-pressed',String(selection === p.id));
            button.addEventListener('click', () => select(p.id, true));
            const shares = tables.networkShares(p.local,p.internet);
            const identity = node('div'); identity.append(button);
            const behavior=p.rx+p.tx ? p.mode : (p.measurement?.label || '{{Aucune mesure attribuée sur la période}}');
            const tone = p.local && p.internet ? 'warning' : p.internet ? 'internet' : p.local ? 'local' : 'neutral';
            const tr = row(body, [identity, badge(p.statusLabel,p.enabled?'local':'neutral'), badge(behavior,tone), networkVolume(p.local,shares.local,'local'), networkVolume(p.internet,shares.internet,'internet'), scheduleCell(p), badge(p.cadenceLabel,'udp'), badge(bytes(p.tx),'tx'), badge(bytes(p.rx),'rx')]);
            if (selection === p.id) tr.className = 'ps-selected';
        });
        if (!body.children.length) row(body, ['{{Aucun plugin correspondant}}', '', '', '', '', '', '', '', '']);
        const unattributed=el('ps-unassigned-rows');unattributed.replaceChildren();
        (data.processes||[]).filter(p=>['_unknown','_shared'].includes(p.source)).forEach(p=>row(unattributed,[names[p.source],p.pid,p.comm,p.reason,bytes(p.tx),bytes(p.rx)]));
        if(!unattributed.children.length)row(unattributed,['{{Aucun processus non attribué récemment observé. Consultez les destinations pour l’historique.}}','','','','','']);
        el('ps-coverage').textContent='{{Couverture de collecte}} : '+percent(Math.min(100,100*data.coverage_seconds/data.period))+' · '+duration(data.coverage_seconds)+' / '+duration(data.period)+' · {{Les plages hachurées du graphique indiquent une couverture absente ou partielle, pas nécessairement une panne.}}';
        renderDetails(detailData || data, names, query);
        sortIndicators();
    }
    function renderDetails(detail, names, query) {
        const cloud = el('ps-cloud').checked;
        const countries=(detail.countries || []).map(c=>[c.code,c.code==='_local'?'{{Local / interne}}':c.code==='_unknown'?'{{Pays inconnu}}':c.name]);
        options(el('ps-country'),countries,el('ps-country').value,'{{Tous les pays}}');
        const body = el('ps-destinations'); body.replaceChildren();
        const scopes = {local:'{{Local}}',internet:'{{Internet}}',loopback:'{{Interne}}',multicast:'Multicast',broadcast:'Broadcast',unknown:'{{Indéterminé}}'};
        const destinations = detail.destinations.map(d => ({...d, sourceName:names[d.source]||d.source,
            destination:tables.endpoint(d.address,d.port)+' / '+d.protocol, scopeLabel:scopes[d.scope]||d.scope,
            countryLabel:[d.country,d.organization].filter(Boolean).join(' / ')||'—'}));
        sorted('destinations', destinations.filter(d => countryMatches(d) && enabledMatches(d.source) && (!selection || d.source === selection) && (!cloud || d.scope === 'internet') &&
            (!query || [d.destination,d.sourceName,d.scopeLabel,d.countryLabel].join(' ').toLocaleLowerCase().includes(query)))).forEach(d => {
            row(body, [badge(d.sourceName), destinationCell(d), badge(d.scopeLabel,d.scope==='local'?'local':d.scope==='internet'?'internet':'neutral'), d.countryLabel, badge(bytes(d.tx),'tx'), badge(bytes(d.rx),'rx'), badge(date(d.first_seen),d.new?'warning':'neutral'), badge(date(d.last))]);
        });
        if (!body.children.length) row(body, ['{{Aucun échange observé pour ce filtre}}','','','','','','','']);
        el('ps-detail-note').textContent = detail.basis + (detail.destinations_limited ? ' {{Affichage limité aux 500 principales destinations.}}' : '');
        const connections=el('ps-connections'); connections.replaceChildren();
        const current=detail.connections || {rows:[],time:0};
        const fresh=detail.live.connected && detail.now-current.time<25;
        const connectionRows = current.rows.map(c=>({...c, sourceName:(names[c.source]||c.source)+(c.peer_source ? ' ↔ '+(names[c.peer_source]||c.peer_source)+' ({{pair local}})' : ''), destination:tables.endpoint(c.address,c.port)}));
        if(fresh) sorted('connections', connectionRows.filter(c=>countryMatches(c) && (enabledMatches(c.source) || (c.peer_source && enabledMatches(c.peer_source))) && (!selection || c.source===selection || c.peer_source===selection) && (!cloud || c.scope==='internet') &&
            (!query || [c.sourceName,c.destination,c.pid].join(' ').toLocaleLowerCase().includes(query)))).forEach(c=>row(connections,[badge(c.sourceName),destinationCell(c),badge(c.pid,'tcp'),badge(date(c.since))]));
        if (!connections.children.length) row(connections, [fresh ? '{{Aucune connexion correspondant aux filtres}}' : '{{Inventaire indisponible}}','','','']);
        el('ps-connections-note').textContent=(fresh ? '{{Inventaire actualisé toutes les 15 secondes. La date est le début de l’observation, pas nécessairement l’ouverture de la connexion.}}' : '{{Inventaire indisponible ou périmé.}}')+(current.limited ? ' {{Inventaire partiel : limite de collecte atteinte.}}' : '');
        const processes = el('ps-processes'); processes.replaceChildren();
        const processRows = (el('ps-country').value ? [] : detail.processes).map(p=>({...p, sourceName:names[p.source]||p.source}));
        sorted('processes', processRows.filter(p=>enabledMatches(p.source) && (!selection || p.source === selection) &&
            (!query || [p.sourceName,p.pid,p.comm,p.reason].join(' ').toLocaleLowerCase().includes(query)))).slice(0,200).forEach(p => row(processes,[badge(p.pid,'tcp'),badge(p.comm,'udp'),badge(p.sourceName),p.reason,badge(bytes(p.tx),'tx'),badge(bytes(p.rx),'rx')]));
        if (el('ps-country').value) row(processes,['{{Les volumes par processus ne sont pas ventilés par pays.}}','','','','','']);
        const versions = el('ps-versions');
        if (!versions) return; // Section facultative : ne pas interrompre le reste du rafraîchissement.
        versions.replaceChildren();
        (detail.versions || []).filter(v => enabledMatches(v[0]) && (!selection || v[0] === selection)).forEach(v => versions.append(node('p', (names[v[0]]||v[0])+' · '+(v[1]||'{{Version inconnue}}')+' · ↑ '+bytes(v[3])+' / ↓ '+bytes(v[2])+' · '+date(v[4])+' → '+date(v[5]))));
    }
    function chart(detail) {
        const target = el('ps-chart'), tooltip=el('ps-chart-tooltip'); target.replaceChildren(); tooltip.hidden=true;
        const maximum = Math.max(2, ...detail.timeline.flatMap(r => [r[1],r[2]]));
        el('ps-chart-y').replaceChildren(...[maximum,maximum/2,0].map(v=>node('span',bytes(v))));
        el('ps-chart-x').replaceChildren(...[detail.since,(detail.since+detail.now)/2,detail.now].map(v=>node('span',date(v))));
        const rows = new Map(detail.timeline.map(r => [r[0], r]));
        const step = detail.timeline_resolution;
        const quality=new Map(detail.quality_timeline || []);
        const show=(bar,text)=>{
            tooltip.textContent=text; tooltip.hidden=false;
            const frame=tooltip.parentElement.getBoundingClientRect(), rect=bar.getBoundingClientRect();
            tooltip.style.left=Math.max(0,Math.min(rect.left-frame.left,frame.width-tooltip.offsetWidth))+'px';
        };
        for (let t = Math.floor(detail.since/step)*step; t <= detail.now; t += step) {
            const values = rows.get(t);
            const bar = node('div', undefined, 'ps-bar'); bar.tabIndex=0;
            const expected=Math.max(1,Math.min(t+step,detail.now)-Math.max(t,detail.since));
            const covered=quality.has(t)?Math.min(expected,quality.get(t)):0;
            const coverageKnown=Array.isArray(detail.quality_timeline);
            if(coverageKnown && covered<expected*.98)bar.classList.add(covered ? 'ps-coverage-partial':'ps-coverage-missing');
            const coverageNote=coverageKnown?' · {{Couverture}} '+percent(Math.min(100,100*covered/expected)):'';
            const text=date(t)+' → '+date(Math.min(t+step,detail.now))+(values ? ' · ↓ {{Reçu}} '+bytes(values[1])+' / ↑ {{Envoyé}} '+bytes(values[2]) : ' · {{Aucun octet enregistré}}')+coverageNote;
            bar.setAttribute('aria-label',text);
            bar.addEventListener('mouseenter',()=>show(bar,text)); bar.addEventListener('focus',()=>show(bar,text));
            bar.addEventListener('mouseleave',()=>{tooltip.hidden=true;}); bar.addEventListener('blur',()=>{tooltip.hidden=true;});
            [1,2].forEach(i => { const part=node('i'); part.style.height=(values ? values[i]/maximum*100 : 0)+'%'; bar.append(part); });
            target.append(bar);
        }
    }
    async function select(id, reveal = false) {
        selection=id; el('ps-detail-plugin').value=id;
        detailData=null;
        el('ps-selection').textContent=id ? (pluginIndex[id]?.name||id) : '{{Tous les échanges observés}}';
        el('ps-all-plugins').hidden=!id;
        el('ps-detail-status').textContent='{{Chargement du détail…}}';
        if (data) render(data);
        el('ps-chart').replaceChildren();
        if (reveal) {
            const target=el(id ? 'ps-selection' : 'ps-plugin-heading');
            target.focus({preventScroll:true});
            target.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',block:'start'});
        }
        await refresh();
    }
    async function refresh() {
        if (busy || !root.isConnected) return;
        busy = true;
        const requestedSelection = selection, requestedPeriod = el('ps-period').value;
        const requestedCountry=el('ps-country').value, requestedEnabled=enabledState, requestedCloud=el('ps-cloud').checked;
        const changed=()=>requestedSelection!==selection || requestedPeriod!==el('ps-period').value || requestedCountry!==el('ps-country').value || requestedEnabled!==enabledState || requestedCloud!==el('ps-cloud').checked;
        try {
            const result = await api('snapshot', {period:requestedPeriod});
            const detail = requestedSelection || requestedCountry || requestedEnabled!=='all' || requestedCloud ? await api('snapshot', {period:requestedPeriod,source:requestedSelection,country:requestedCountry,enabled:requestedEnabled,internet_only:requestedCloud?'1':'0'}) : result;
            if (changed()) return;
            detailData = detail;
            render(result);
            chart(detail);
            el('ps-detail-status').textContent='';
        } catch(error) {
            if (error.name !== 'AbortError' && !changed()) {
                el('ps-error').hidden=false; el('ps-error').textContent=error.message;
                el('ps-detail-status').textContent=error.message;
            }
        } finally {
            busy=false;
            if (root.isConnected && !controller.signal.aborted && changed()) refresh();
        }
    }
    ['unknown','shared'].forEach(kind=>el('ps-show-'+kind).addEventListener('click',()=>{enabledState='all';updateToggle();el('ps-country').value='';el('ps-cloud').checked=false;el('ps-search').value='';select('_'+kind,true);}));
    el('ps-network').addEventListener('click',()=>window.PluginScopeNetwork.open({data, loadData:()=>api('snapshot',{period:el('ps-period').value}), state:enabledState, period:el('ps-period').selectedOptions[0].textContent, onState:state=>{
        enabledState=state; updateToggle(); if(selection && !enabledMatches(selection)) select(''); else {if(data) render(data);refresh();}
    }}));
    controller.signal.addEventListener('abort',()=>window.PluginScopeNetwork?.close());
    el('ps-refresh').addEventListener('click',refresh);
    el('ps-all-plugins').addEventListener('click',()=>select('',true));
    el('ps-period').addEventListener('change',refresh);
    el('ps-search').addEventListener('input',()=> { if(data) render(data); });
    el('ps-enabled').querySelectorAll('[data-state]').forEach(button=>button.addEventListener('click',()=>{
        enabledState=button.dataset.state; updateToggle();
        if (selection && !enabledMatches(selection)) { select(''); return; }
        if (data) render(data); refresh();
    }));
    el('ps-detail-plugin').addEventListener('change',()=>select(el('ps-detail-plugin').value));
    el('ps-country').addEventListener('change',()=>{el('ps-detail-status').textContent='{{Chargement du détail…}}';refresh();});
    el('ps-detail-clear').addEventListener('click',()=>{el('ps-country').value='';el('ps-cloud').checked=false;select('');});
    el('ps-cloud').addEventListener('change',refresh);
    el('ps-clear').addEventListener('click',()=>{ el('ps-search').value=''; enabledState='all'; updateToggle(); el('ps-country').value=''; el('ps-cloud').checked=false; select(''); });
    root.querySelectorAll('[data-ps-table] [data-sort]').forEach(button=>button.addEventListener('click',()=> {
        const state=sorts[button.closest('table').dataset.psTable], next=button.dataset.sort;
        state.descending=next===state.key ? !state.descending : ['rx','tx','local','internet','last','first_seen','since'].includes(next);
        state.key=next; if(data) render(data);
    }));
    el('ps-health').addEventListener('click',()=>jeeDialog.dialog({id:'pluginscopeHealth',title:'{{Santé PluginScope}}',contentUrl:'index.php?v=d&plugin=pluginscope&modal=health'}));
    ['ps-health'].forEach(id=>el(id).addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();el(id).click();}}));
    el('bt_documentationPluginscope').addEventListener('click',()=>window.open(el('bt_documentationPluginscope').dataset.location,'_blank','noopener'));
    window.PluginScopeInvestigate(root,api,()=>({source:selection,period:el('ps-period').value,names:pluginIndex}),controller.signal);
    api('sync').catch(()=>{}).finally(refresh);
    const interval=setInterval(()=>{ if(!root.isConnected || controller.signal.aborted){clearInterval(interval);controller.abort();return;} if(!document.hidden && !el('ps-chart').matches(':hover') && !el('ps-chart').contains(document.activeElement)) refresh(); },5000);
})();
