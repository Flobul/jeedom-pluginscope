/* Interactive, local-only map of measured destinations. No external assets or libraries. */
(function () {
    'use strict';
    let dialog;
    const element=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
    const svg=(tag,attrs={},text)=>{const e=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attrs).forEach(([k,v])=>e.setAttribute(k,v));if(text!==undefined)e.textContent=text;return e;};
    const bytes=n=>{let u=0;while(n>=1024&&u<3){n/=1024;u++;}return n.toLocaleString(undefined,{maximumFractionDigits:1})+' '+['o','Kio','Mio','Gio'][u];};
    function close(){if(dialog){dialog.close();dialog.remove();dialog=null;}}
    function open({data,state,period,onState,loadData}) {
        close(); const opener=document.activeElement;
        dialog=element('dialog',undefined,'ps-network-modal');
        const current=dialog;
        current.setAttribute('aria-labelledby','ps-network-title');
        const header=element('div',undefined,'ps-network-header');
        const title=element('h2','{{Carte des communications}}');title.id='ps-network-title';
        const dismiss=element('button','×','btn btn-default');dismiss.setAttribute('aria-label','{{Fermer}}');dismiss.onclick=close;
        header.append(title,dismiss);
        const toolbar=element('div',undefined,'ps-network-toolbar'),toggle=element('div',undefined,'ps-network-toggle');toggle.setAttribute('role','group');toggle.setAttribute('aria-label','{{État des plugins}}');
        const filter=element('select',undefined,'form-control');filter.setAttribute('aria-label','{{Centrer la carte sur un plugin}}');filter.add(new Option('{{Tous les plugins}}',''));
        (data?.plugins||[]).forEach(p=>filter.add(new Option(p.name,p.id)));
        const zoom=element('input');zoom.type='range';zoom.min=20;zoom.max=1000;zoom.value=100;zoom.setAttribute('aria-label','{{Zoom}}');
        const reset=element('button','{{Recentrer}}','btn btn-default');
        const search=element('input',undefined,'form-control');search.type='search';search.placeholder='{{Rechercher une IP, un pays ou un plugin}}';search.setAttribute('aria-label',search.placeholder);
        const refresh=element('button','{{Actualiser la carte}}','btn btn-primary');refresh.type='button';
        toolbar.append(toggle,filter,search,element('span','{{Zoom}}'),zoom,reset,refresh);
        const note=element('p',undefined,'ps-network-note'),viewport=element('div',undefined,'ps-network-viewport'),info=element('p','{{Survolez ou sélectionnez un nœud pour lire ses échanges.}}','ps-network-info');info.setAttribute('role','status');
        const legend=element('p','{{Vert : local / interne · Bleu : Internet · Gris : réseau indéterminé · Pointillés : organisation Jeedom / plugins, pas un échange mesuré.}}','ps-network-note');
        current.append(header,toolbar,note,viewport,info,legend);document.body.append(current);
        current.addEventListener('close',()=>{current.remove();if(dialog===current)dialog=null;if(opener?.isConnected)opener.focus();});
        let drawing=null,base=null,view=null,drag=null;
        const apply=()=>{if(drawing&&view)drawing.setAttribute('viewBox',`${view.x} ${view.y} ${view.w} ${view.h}`);};
        const point=e=>{const r=drawing.getBoundingClientRect(),scale=Math.min(r.width/view.w,r.height/view.h);return {x:view.x+(e.clientX-r.left-(r.width-view.w*scale)/2)/scale,y:view.y+(e.clientY-r.top-(r.height-view.h*scale)/2)/scale,scale};};
        const magnify=(value,anchor)=>{if(!view)return;const next=Math.max(20,Math.min(1000,value)),w=base.w*100/next,h=base.h*100/next;anchor=anchor||{x:view.x+view.w/2,y:view.y+view.h/2};view={x:anchor.x-(anchor.x-view.x)*w/view.w,y:anchor.y-(anchor.y-view.y)*h/view.h,w,h};zoom.value=next;apply();};
        viewport.addEventListener('wheel',e=>{if(!drawing)return;e.preventDefault();magnify(Number(zoom.value)*Math.exp(-Math.max(-100,Math.min(100,e.deltaY))*.003),point(e));},{passive:false});
        viewport.addEventListener('pointerdown',e=>{if(e.button!==0||!drawing)return;drag={x:e.clientX,y:e.clientY,view:{...view},scale:point(e).scale};viewport.setPointerCapture(e.pointerId);viewport.classList.add('ps-network-dragging');});
        viewport.addEventListener('pointermove',e=>{if(!drag)return;view={...drag.view,x:drag.view.x-(e.clientX-drag.x)/drag.scale,y:drag.view.y-(e.clientY-drag.y)/drag.scale};apply();});
        const stop=()=>{drag=null;viewport.classList.remove('ps-network-dragging');};viewport.addEventListener('pointerup',stop);viewport.addEventListener('pointercancel',stop);viewport.addEventListener('lostpointercapture',stop);
        reset.onclick=()=>{if(base){view={...base};zoom.value=100;apply();}};
        const buttons=[];
        [['disabled','{{Plugins désactivés}}'],['all','{{Tous}}'],['enabled','{{Plugins activés}}']].forEach(([value,label])=>{const b=element('button',label);b.type='button';b.onclick=()=>{state=value;onState(value);draw();};buttons.push([b,value]);toggle.append(b);});
        const allowed=id=>window.PluginScopeTables.matchesStatus(index[id]?.enabled,state);
        let index=Object.fromEntries((data?.plugins||[]).map(p=>[p.id,p]));
        function draw(){
            buttons.forEach(([b,v])=>b.setAttribute('aria-pressed',String(v===state)));
            const selected=filter.value;filter.replaceChildren(new Option('{{Tous les plugins}}',''));
            (data?.plugins||[]).filter(p=>allowed(p.id)).forEach(p=>filter.add(new Option(p.name,p.id)));
            filter.value=[...filter.options].some(o=>o.value===selected)?selected:'';
            viewport.replaceChildren();drawing=null;
            const normalize=d=>['0.0.0.0','::','::ffff:0.0.0.0'].includes(d.address)?{...d,scope:'unknown',country:'',country_code:''}:d;
            const observed=(data?.destinations||[]).map(normalize);
            const fresh=data?.live?.connected && data.now-(data.connections?.time||0)<25;
            const opened=fresh ? (data.connections?.rows||[]).filter(c=>!observed.some(d=>d.source===c.source&&d.address===c.address&&d.port===c.port&&d.protocol===(c.protocol||'TCP'))).map(c=>({...c,protocol:c.protocol||'TCP',connectionOnly:true,rx:0,tx:0})) : [];
            const query=search.value.trim().toLocaleLowerCase();
            const matches=d=>!query||[d.address,d.country,d.country_code,d.source,index[d.source]?.name].join(' ').toLocaleLowerCase().includes(query);
            const all=[...observed,...opened.map(normalize)].filter(d=>allowed(d.source)&&(!filter.value||d.source===filter.value)&&matches(d));
            const sourceIds=[...new Set([...(data?.plugins||[]).filter(p=>allowed(p.id)&&(!filter.value||p.id===filter.value)&&(!query||[p.id,p.name].join(' ').toLocaleLowerCase().includes(query))).map(p=>p.id),...all.map(d=>d.source)])].sort((a,b)=>(index[a]?.name||a).localeCompare(index[b]?.name||b));
            const sources=sourceIds,rows=all;
            const measured=rows.filter(d=>!d.connectionOnly).length;
            note.textContent=period+' · '+sources.length+' {{sources}} · '+measured+' {{liaisons mesurées}} · '+(rows.length-measured)+' {{connexions ouvertes sans volume mesuré}}. '+ '{{Instantané ; utilisez Actualiser la carte pour relire les observations.}}'+((data?.destinations_limited||all.length>rows.length)?' {{Vue partielle : principales liaisons uniquement. Sélectionnez un plugin pour réduire la carte.}}':'');
            if(!sources.length){viewport.append(element('p','{{Aucun échange mesuré pour ce filtre sur cette période.}}','ps-network-empty'));return;}
            const localScope=d=>['local','loopback','multicast','broadcast'].includes(d.scope);
            const destinations=new Map();rows.forEach(d=>{const key=d.scope+'|'+d.address; if(!destinations.has(key))destinations.set(key,{...d,key});});
            const locals=[...destinations.values()].filter(localScope),externals=[...destinations.values()].filter(d=>!localScope(d));
            const height=Math.max(760,Math.max(locals.length,externals.length,Math.ceil(sources.length/2))*100+180),cy=height/2;
            drawing=svg('svg',{viewBox:`0 0 1200 ${height}`,role:'group','aria-label':'{{Jeedom, plugins et destinations observées}}'});drawing.style.width='100%';drawing.style.height='100%';base={x:0,y:0,w:1200,h:height};view={...base};zoom.value=100;
            viewport.append(drawing);
            drawing.append(svg('rect',{x:15,y:45,width:810,height:height-65,rx:90,class:'ps-net-bubble'}));
            drawing.append(svg('text',{x:75,y:82,class:'ps-net-region'},'{{Réseau local / Jeedom}}'),svg('text',{x:890,y:82,class:'ps-net-region'},'{{Destinations hors bulle : Internet ou inconnues}}'));
            const edges=svg('g'),nodes=svg('g');drawing.append(edges,nodes);
            const positions={};sources.forEach((id,i)=>{const side=i%2,rank=Math.floor(i/2),count=Math.ceil(sources.length/2);positions[id]={x:side?710:350,y:140+rank*(height-220)/Math.max(1,count)};});
            const destPositions={};[locals,externals].forEach((list,side)=>list.forEach((d,i)=>{destPositions[d.key]={x:side?1030:145,y:130+i*(height-180)/Math.max(1,list.length)};}));
            const links=[];
            const highlight=(id,text)=>{info.textContent=text;links.forEach(l=>l.el.style.opacity=(!id||l.ids.includes(id))?'1':'.08');};
            function addNode(id,pos,label,sub,icon,tone,details,symbol){
                const g=svg('g',{transform:`translate(${pos.x},${pos.y})`,class:'ps-net-node '+tone,tabindex:0,role:'button','aria-label':details});
                g.append(svg('title',{},details),svg('circle',{r:25}),svg('text',{'text-anchor':'middle',y:6,class:'ps-net-symbol'},icon?'':symbol|| (tone==='ps-net-local'?'⌂':tone==='ps-net-internet'?'🌐':tone==='ps-net-unknown'?'?':label.slice(0,2).toUpperCase())));
                if(icon){const img=svg('image',{href:icon,x:-19,y:-19,width:38,height:38});img.addEventListener('error',()=>{img.remove();g.querySelector('.ps-net-symbol').textContent=label.slice(0,2).toUpperCase();});g.append(img);}
                g.append(svg('text',{'text-anchor':'middle',y:40},label.length>27?label.slice(0,25)+'…':label));
                if(sub)g.append(svg('text',{'text-anchor':'middle',y:54,class:'ps-net-sub'},sub));
                const act=()=>highlight(id,details);g.addEventListener('mouseenter',act);g.addEventListener('focus',act);g.addEventListener('click',act);g.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();act();}});nodes.append(g);
            }
            function link(a,b,ids,tone,description,weight=1){const path=svg('path',{d:`M${a.x},${a.y} Q${(a.x+b.x)/2},${a.y} ${b.x},${b.y}`,class:'ps-net-link '+tone,'stroke-width':weight});path.append(svg('title',{},description));edges.append(path);links.push({el:path,ids});}
            const center={x:510,y:cy};
            addNode('jeedom',center,'Jeedom','', 'core/img/logo-jeedom-petit-nom-couleur-128x128.png','ps-net-center','{{Jeedom — organisation des plugins ; les traits pointillés ne représentent pas du trafic.}}');
            sources.forEach(id=>{const name=index[id]?.name||({'_core':'{{Core Jeedom}}','_unknown':'{{Non attribué}}','_shared':'{{Jeedom partagé}}'}[id]||id);link(center,positions[id],['jeedom',id],'ps-net-structure','{{Organisation Jeedom / plugin}}');addNode(id,positions[id],name,!rows.some(d=>d.source===id)?'{{Aucune liaison disponible}}':index[id]?.enabled===false?'{{Désactivé}}':'',id==='_core'?'core/img/logo-jeedom-petit-nom-couleur-128x128.png':window.pluginscopeIcons?.[id],'ps-net-plugin',name+' · '+ '{{Survolez une destination pour lire les volumes et protocoles.}}');});
            destinations.forEach(d=>{const group=rows.filter(r=>r.scope===d.scope&&r.address===d.address),details=d.address+' · '+group.map(r=>(index[r.source]?.name||r.source)+' · '+r.protocol+':'+r.port+(r.connectionOnly?' · {{Connexion ouverte — volume non mesuré}}':' · ↑ '+bytes(r.tx)+' / ↓ '+bytes(r.rx))).join(' ; ');addNode(d.key,destPositions[d.key],d.address,d.country|| (localScope(d)?'{{Local / interne}}':d.scope==='internet'?'Internet':'{{Indéterminé}}'),null,localScope(d)?'ps-net-local':d.scope==='internet'?'ps-net-internet':'ps-net-unknown',details,d.scope==='internet'&&/^[A-Z]{2}$/.test(d.country_code||'')?String.fromCodePoint(...[...d.country_code].map(c=>127397+c.charCodeAt(0))):undefined);});
            rows.forEach(d=>{const key=d.scope+'|'+d.address;link(positions[d.source],destPositions[key],[d.source,key],localScope(d)?'ps-net-local':d.scope==='internet'?'ps-net-internet':'ps-net-unknown',d.protocol+':'+d.port+(d.connectionOnly?' · {{Connexion ouverte — volume non mesuré}}':' · ↑ '+bytes(d.tx)+' / ↓ '+bytes(d.rx)),1+Math.min(4,Math.log10(1+d.tx+d.rx)/2));});
        }
        refresh.onclick=async()=>{if(!loadData)return;refresh.disabled=true;try{const result=await loadData();if(!current.isConnected)return;data=result;index=Object.fromEntries((data.plugins||[]).map(p=>[p.id,p]));draw();info.textContent='{{Carte actualisée}}';}catch(error){if(current.isConnected)info.textContent=error.message;}finally{refresh.disabled=false;}};
        search.oninput=draw;
        filter.onchange=draw;zoom.oninput=()=>magnify(Number(zoom.value));draw();current.showModal();
    }
    window.PluginScopeNetwork={open,close};
})();
