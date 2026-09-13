/* Administrator-initiated observations; never executes a command in another plugin. */
window.PluginScopeInvestigate=function(root,api,context,signal){
    const el=id=>root.querySelector('#'+id), status=el('ps-investigate-status');
    const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
    const volume=n=>Number(n).toLocaleString()+' o';
    const date=n=>new Date(n*1000).toLocaleString();
    const table=(target,headers,rows)=>{const t=make('table');t.className='table';const h=make('tr');headers.forEach(v=>h.append(make('th',v)));const head=make('thead');head.append(h);t.append(head);const body=make('tbody');rows.forEach(values=>{const tr=make('tr');values.forEach(v=>tr.append(make('td',v)));body.append(tr);});t.append(body);target.append(t);};
    let timer;
    signal.addEventListener('abort',()=>clearTimeout(timer));
    el('ps-diagnostic-start').onclick=async function(){
        const c=context();if(!c.source||c.source.startsWith('_')){status.textContent='{{Sélectionnez un plugin dans le filtre des échanges avant de lancer le diagnostic.}}';return;}
        this.disabled=true;const button=this,target=el('ps-diagnostic-result');target.replaceChildren();
        const render=result=>{
            target.replaceChildren();
            const owned=result.rows.filter(r=>r.source===result.source),others=result.rows.filter(r=>r.source!==result.source);
            target.append(make('h3','{{Diagnostic}} · '+(c.names[result.source]?.name||result.source)));
            target.append(make('p',date(result.since)+' → '+date(result.until)+' · {{Couverture enregistrée}} : '+Math.round(result.coverage)+' s / 120 s · '+(result.finished?'{{Terminé}}':'{{En cours — actualisez maintenant votre plugin}}')));
            target.append(make('p',owned.length?'{{Des échanges ont été attribués au plugin pendant ce diagnostic.}}':'{{Aucun échange attribué au plugin dans les lots observés. Cela ne prouve pas une absence de communication.}}'));
            target.append(make('p','{{Les échanges partagés ou inconnus ci-dessous sont simultanés, mais ne sont pas attribués au plugin testé. La première portion chevauchant le démarrage est exclue.}}'));
            if(!result.live.operational)target.append(make('p','{{Collecte actuellement indisponible : résultat incomplet.}}'));
            if(result.limited)target.append(make('p','{{Résultat limité à 500 lignes.}}'));
            if(Object.values(result.live.stats||{}).some(Number))target.append(make('p','{{Des compteurs de pertes sont présents depuis le démarrage du collecteur ; consultez Santé.}}'));
            target.append(make('p',result.connections_available ? '{{Connexions TCP actuellement attribuées au plugin}} : '+result.connections.length : '{{Inventaire TCP actuellement indisponible}}'));
            (result.connections||[]).forEach(c=>target.append(make('p','PID '+c.pid+' · '+c.address+':'+c.port+' · '+(c.reason||''))));
            (result.schedules||[]).forEach(c=>target.append(make('p','{{Cron}} : '+c.class+'::'+c.function+' · '+c.schedule+' · '+(c.enabled?'{{Activé}}':'{{Désactivé}}'))));
            table(target,['{{Attribution}}','PID','{{Programme}}','{{Destination}}','{{Envoyé}}','{{Reçu}}','{{Raison}}'],[...owned,...others].map(r=>[r.source===result.source?'{{Plugin testé}}':r.source==='_core'?'{{Core Jeedom}}':r.source==='_shared'?'{{Jeedom partagé}}':'{{Non attribué}}',r.pid,r.comm,r.address+' · '+r.protocol+':'+r.port,volume(r.tx),volume(r.rx),r.reason]));
        };
        try{
            const started=await api('diagnostic_start',{source:c.source});render(started);
            const poll=async()=>{try{const result=await api('diagnostic_poll',{id:started.id});if(signal.aborted)return;render(result);if(result.finished)button.disabled=false;else timer=setTimeout(poll,2000);}catch(e){if(!signal.aborted){status.textContent=e.message;button.disabled=false;}}};
            timer=setTimeout(poll,2000);
        }catch(e){if(!signal.aborted)status.textContent=e.message;button.disabled=false;}
    };
    el('ps-compare').onclick=async function(){
        this.disabled=true;const c=context(),target=el('ps-comparison-result');
        try{
            const r=await api('compare',{source:c.source,period:c.period});if(signal.aborted)return;
            target.replaceChildren();target.append(make('h3','{{Comparaison}} · '+(c.names[c.source]?.name||c.source||'{{Tous les plugins}}')));
            for(const [label,w] of [['{{Précédente}}',r.previous],['{{Actuelle}}',r.current]])target.append(make('p',label+' : '+date(w.since)+' → '+date(w.until)+' · {{Couverture}} '+Math.min(100,100*w.coverage/r.period).toFixed(1)+' %'));
            target.append(make('p','{{Périodes consécutives de même durée, arrêtées à la dernière minute complète. Des couvertures différentes ou des purges rendent la comparaison incomplète. Les volumes ne sont pas extrapolés.}}'));
            const before=new Map(r.previous.volumes.map(v=>[v[0],v[1]+v[2]])),after=new Map(r.current.volumes.map(v=>[v[0],v[1]+v[2]]));
            table(target,['{{Plugin}}','{{Volume précédent}}','{{Volume actuel}}','{{Écart}}','{{Variation}}'],[...new Set([...before.keys(),...after.keys()])].map(id=>{const a=before.get(id)||0,b=after.get(id)||0;return[c.names[id]?.name||id,volume(a),volume(b),volume(b-a),a?((b-a)*100/a).toFixed(1)+' %':'{{Non calculable : référence nulle}}'];}));
            target.append(make('p',r.new_count+' {{destinations présentes uniquement dans la période actuelle}} · '+r.absent_count+' {{présentes uniquement dans la précédente}}. {{Ce constat porte sur les données conservées, pas sur une première connexion certaine.}}'));
            table(target,['{{Différence}}','{{Adresse}}','{{Port}}','{{Protocole}}'],[...r.new_destinations.map(d=>['{{Actuelle uniquement}}',...d]),...r.absent_destinations.map(d=>['{{Précédente uniquement}}',...d])]);
            if(r.new_count>100||r.absent_count>100)target.append(make('p','{{Liste limitée à 100 destinations par catégorie.}}'));
        }catch(e){if(!signal.aborted)status.textContent=e.message;}finally{this.disabled=false;}
    };
};
