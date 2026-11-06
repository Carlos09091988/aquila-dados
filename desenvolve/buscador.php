/**
 * Desenvolve — Buscador de licitações abertas (PNCP + diários oficiais)
 * Busca no PNCP pelo navegador do cliente, filtra por relevância (todas as palavras), separa serviços de compras,
 * mostra prazo, valor estimado e leva cada edital para a Gestão, para a análise do edital ou para o Raio-X do órgão.
 * Sem custo de crédito. Shortcode: [dv_buscador]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_shortcode( 'dv_buscador', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="bq2" id="bq2" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
<form id="bq-form" class="bq2-f" autocomplete="off">
	<div class="bq2-g">
		<label>O que você fornece<input id="bq-q" name="q" placeholder="Ex.: limpeza hospitalar, vigilância, merenda escolar"><small>Mostramos só os editais com todas as palavras. Para uma expressão exata, use aspas.</small></label>
		<label>Tipo<span class="bq2-seg" data-k="tipo"><b data-v="servico">Serviços</b><b data-v="compra">Compras</b><b data-v="" class="on">Tudo</b></span></label>
	</div>
	<div class="bq2-g3 bq2-perto"><label class="bq2-cid">Perto de uma cidade <small>(opcional)</small><input name="cid" placeholder="Digite e escolha na lista" autocomplete="off"><div class="bq2-sug" hidden></div></label><label>Distância<select name="raio" disabled><option value="0">Só nesta cidade</option><option value="10">Até 10 km</option><option value="25" selected>Até 25 km</option><option value="50">Até 50 km</option><option value="100">Até 100 km</option></select></label><p class="bq2-h bq2-pinfo"></p></div>
	<label class="bq2-ufl">Estados <small>(escolha um ou mais; nenhum = Brasil todo)</small><div class="bq2-ufs"></div></label>
	<input type="hidden" id="bq-uf" name="uf">
	<div class="bq2-g3">
		<label>Modalidade<select name="mod"><option value="">Todas</option><option value="6|7">Pregão</option><option value="4|5">Concorrência</option><option value="8">Dispensa</option><option value="12">Credenciamento</option><option value="9">Inexigibilidade</option><option value="1|13">Leilão</option></select></label>
		<label>Órgão <small>(opcional)</small><input name="loc" placeholder="Ex.: Prefeitura, Exército, Hospital"></label>
		<label>Ordenar por<select name="ord"><option value="perto">Mais perto da cidade</option><option value="rel">Mais parecidos com a busca</option><option value="prazo">Prazo mais próximo</option><option value="novos">Publicados mais recentemente</option></select></label>
	</div>
	<div class="bq2-act"><button type="submit">🔎 Buscar licitações</button><span class="bq2-cnt" id="bq-count"></span></div>
</form>
<div class="bq2-tabs"></div>
<div class="bq2-l"><p class="bq2-h">Diga o que você fornece e onde, e clique em Buscar. Sem custo de crédito.</p></div>
<div class="bq2-pag"></div>
<p class="bq2-h bq2-foot">Fontes: Portal Nacional de Contratações Públicas (PNCP), em tempo real, e diários oficiais (municípios e Diário Oficial da União, seção 3), dos últimos 20 dias. O aviso de diário oficial traz só o resumo: baixe o edital no site do órgão. Confira sempre o edital antes de participar.</p>
</div>
<style>
.bq2{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px;background:#FAF8F3;border-radius:22px}
.bq2 label{display:block;font-weight:700;margin:0 0 12px}.bq2 small{color:var(--mu);font-weight:500;font-size:.8rem}.bq2 label>small{display:block;margin-top:4px}
.bq2 input,.bq2 select{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box;margin-top:5px;color:#16191E}
.bq2-g{display:grid;grid-template-columns:1fr 300px;gap:14px}.bq2-g3{display:grid;grid-template-columns:200px 1fr 260px;gap:14px}
.bq2-seg{display:flex;margin-top:5px;border:1px solid #CFC8B8;border-radius:10px;overflow:hidden}.bq2-seg b{flex:1;text-align:center;padding:10px 6px;cursor:pointer;font-weight:600;background:#fff}.bq2-seg b.on{background:#0C0F13;color:#F4F2EC}
.bq2-ufs{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}.bq2-ufs span{border:1px solid var(--ln);border-radius:8px;padding:5px 9px;font-size:.8rem;cursor:pointer;font-weight:600;background:#fff}.bq2-ufs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.bq2 button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.bq2 button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600}
.bq2-act{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:4px}.bq2-cnt{color:var(--mu);font-size:.9rem}
.bq2-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:18px 0 6px}.bq2-tabs span{border:1px solid var(--ln);border-radius:20px;padding:6px 12px;font-size:.85rem;cursor:pointer;font-weight:600;background:#fff}.bq2-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}.bq2-tabs a{margin-left:auto;font-size:.85rem;color:var(--gd);font-weight:700;align-self:center}
.bq2-h{color:var(--mu);font-size:.88rem;margin:8px 0}
.bq2-i{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:16px 18px;margin-bottom:10px}.bq2-i.desc{opacity:.55}
.bq2-i .t{font-size:.8rem;color:var(--mu);font-weight:600;display:flex;gap:8px;flex-wrap:wrap;justify-content:space-between}.bq2-i .o{font-weight:700;margin:6px 0 2px;line-height:1.4}.bq2-i .o.cl{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer}.bq2-i .u{font-size:.86rem;color:var(--mu)}
.bq2-ch{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.bq2-ch span{font-size:.78rem;font-weight:700;border-radius:8px;padding:4px 9px;background:#F3F0E8}.bq2-ch .r{background:#FDEDEA;color:#9A3524}.bq2-ch .a{background:#FBF1DC;color:#7A5410}.bq2-ch .v{background:#EAF5EE;color:#1F5A3B}
.bq2-a{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}.bq2-a a,.bq2-a button{font-size:.8rem;font-weight:600;border:1px solid var(--ln);border-radius:9px;padding:7px 11px;text-decoration:none;color:#16191E;background:#fff;cursor:pointer}.bq2-a .p{background:#C9A45C;border-color:#C9A45C;color:#241A06}.bq2-a .ok{background:#EAF5EE;border-color:#BFDCC9;color:#1F5A3B}.bq2-a .x{margin-left:auto;color:var(--mu)}
.bq2-pag{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.bq2-pag button{padding:7px 12px}.bq2-pag button.on{background:#0C0F13;color:#F4F2EC}
.bq2-foot{font-size:.78rem;margin-top:14px}
.bq2-dio{border-left:3px solid #8FA7C2}.bq2-fo{display:inline-block;background:#EEF2F7;color:#2B4A6B;border-radius:6px;padding:1px 7px;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;margin-right:4px}
@media(max-width:760px){.bq2{padding:16px}.bq2-g,.bq2-g3{grid-template-columns:1fr}.bq2-a .x{margin-left:0}}
.bq2-cid{position:relative}.bq2-sug{position:absolute;top:100%;left:0;right:0;margin-top:4px;background:#fff;border:1px solid #DDD6C6;border-radius:10px;box-shadow:0 14px 34px -18px rgba(0,0,0,.35);z-index:30;max-height:260px;overflow:auto;padding:4px}.bq2-sug button{display:flex;justify-content:space-between;width:100%;text-align:left;background:none;border:0;padding:9px 10px;border-radius:7px;font:inherit;cursor:pointer}.bq2-sug button:hover{background:#F4F2EC}.bq2-sug small{color:#8A8F96}.bq2-sug p{margin:6px 10px;color:#5E636B}.bq2-sug[hidden]{display:none!important}.bq2-perto .bq2-pinfo{grid-column:1/-1;margin:0}</style>
<script>
(function(){var R=document.getElementById('bq2');if(!R)return;var T=R.closest('.dv-tool');if(T)T.setAttribute('data-sem-portao','');
var api=R.dataset.api,nonce=R.dataset.nonce,F=R.querySelector('#bq-form'),UF='AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split(' '),ufs={},tipo='',fonte='',ALL=[],VIS=[],aba='',DIO=null,PN=null,pag=1,POR=20,GEST={},DESC={},VAL={},verDesc=false,QS=new URLSearchParams(location.search);
try{DESC=JSON.parse(localStorage.getItem('dv_bq_desc')||'{}')}catch(x){}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function nm(s){return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'')}
function br(d){if(!d)return'';var x=String(d);return x.slice(8,10)+'/'+x.slice(5,7)+'/'+x.slice(0,4)+(x.length>15&&x.slice(11,16)!=='00:00'?' às '+x.slice(11,16):'')}
function moeda(v){return Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}
function ref(x){var m=/compras\/(\d{14})\/(\d{4})\/(\d+)/.exec(x.item_url||'');return m?m[1]+'/'+m[2]+'/'+m[3]:''}
function dias(d){if(!d)return null;var a=new Date(String(d).slice(0,10)+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);return Math.round((a-h)/864e5)}
var STOP=['de','da','do','das','dos','e','a','o','em','para','com','por','na','no','nas','nos','servico','servicos','contratacao','empresa','prestacao','aquisicao','fornecimento'];
function termos(q){var fr=[],r=String(q).replace(/"([^"]+)"/g,function(_,f){fr.push(nm(f));return' '});return{fr:fr,w:nm(r).split(/[^a-z0-9]+/).filter(function(w){return w.length>2&&STOP.indexOf(w)<0}).map(function(w){return w.length>6?w.slice(0,w.length-2):w.replace(/s$/,'')})}}
function tipoDe(d){var n=nm(d);var serv=/servic|prestacao de|contratacao de empresa|locacao|manutencao|obra|engenharia|terceiriz|mao de obra|limpeza e conservacao|vigilancia|seguranca patrimonial|transporte de|fornecimento de alimentacao|refeic|coleta de/.test(n),comp=/^(aquisicao|compra|fornecimento|registro de precos para (a )?(eventual |futura )*(aquisicao|compra|fornecimento))|aquisicao de|materia(l|is) (de|para|medic|hospitalar|permanente|consumo)|equipamentos? |generos|medicament|insumos/.test(n);if(serv&&!/^(aquisicao|compra)/.test(n))return'servico';if(comp)return'compra';return serv?'servico':'compra'}
function chips(){R.querySelector('.bq2-ufs').innerHTML=UF.map(function(u){return'<span data-uf="'+u+'" class="'+(ufs[u]?'on':'')+'">'+u+'</span>'}).join('');R.querySelector('#bq-uf').value=Object.keys(ufs).filter(function(k){return ufs[k]}).join(',')}
function seg(){R.querySelectorAll('.bq2-seg b').forEach(function(b){b.classList.toggle('on',b.dataset.v===tipo)})}
function guarda(){try{localStorage.setItem('dv_bq_pref',JSON.stringify({q:F.q.value,ufs:Object.keys(ufs).filter(function(k){return ufs[k]}),tipo:tipo,mod:F.mod.value,ord:F.ord.value}))}catch(x){}}
var REG=null,MUNB={},tmb;
function regB(){var c=MUNB[F.cid.value.trim().toLowerCase()];F.raio.disabled=!c;if(!c&&F.ord.value==='perto')F.ord.value='rel';R.querySelector('.bq2-ufl').style.opacity=c?.4:1;var inf=R.querySelector('.bq2-pinfo');if(!c){REG=null;inf.textContent='';return Promise.resolve()}
 F.ord.value='perto';return fetch('/wp-json/desenvolve/v1/municipios?c='+c.c+'&raio='+F.raio.value).then(function(r){return r.json()}).then(function(L){REG={cods:[],ufs:[],nomes:{}};(L||[]).forEach(function(m){REG.cods.push(m.c);if(REG.ufs.indexOf(m.uf)<0)REG.ufs.push(m.uf);REG.nomes[nm(m.n)+'|'+m.uf]=m.km});inf.textContent=+F.raio.value?'Busca em '+REG.cods.length+' cidade(s) até '+F.raio.value+' km de '+c.n+'/'+c.uf+'. Os estados marcados abaixo são ignorados.':'Busca só em '+c.n+'/'+c.uf+'.'})}
(function(){var sg=R.querySelector('.bq2-sug'),ci=F.cid;function esc(k){ci.value=k;sg.hidden=true;regB()}
 sg.addEventListener('mousedown',function(ev){var b=ev.target.closest('button');if(b){ev.preventDefault();esc(b.getAttribute('data-k'))}});
 ci.addEventListener('blur',function(){setTimeout(function(){sg.hidden=true},150)});
 ci.addEventListener('keydown',function(ev){if(ev.key==='Enter'&&!sg.hidden&&sg.querySelector('button')){ev.preventDefault();esc(sg.querySelector('button').getAttribute('data-k'))}});
 F.raio.addEventListener('change',regB);
 ci.addEventListener('input',function(){REG=null;regB();clearTimeout(tmb);var v=ci.value.trim();if(v.length<2){sg.hidden=true;return}tmb=setTimeout(function(){fetch('/wp-json/desenvolve/v1/municipios?q='+encodeURIComponent(v)).then(function(r){return r.json()}).then(function(L){sg.innerHTML='';(L||[]).forEach(function(m){var k=m.n+' - '+m.uf;MUNB[k.toLowerCase()]=m;var b=document.createElement('button');b.type='button';b.setAttribute('data-k',k);b.innerHTML=e(m.n)+' <small>'+m.uf+'</small>';sg.appendChild(b)});if(!sg.firstChild)sg.innerHTML='<p>Nenhuma cidade com esse nome.</p>';sg.hidden=false}).catch(function(){})},180)})})();
(function(){var P=new URLSearchParams(location.search);if(P.get('auto')!=='1')return;if(P.get('q'))F.q.value=P.get('q');var cn=P.get('cn'),cd=+P.get('cidade');history.replaceState(null,'',location.pathname);setTimeout(function(){if(cn&&cd){var k=cn,m={c:cd,n:cn.replace(/ - [A-Z]{2}$/,''),uf:cn.slice(-2)};MUNB[k.toLowerCase()]=m;F.cid.value=k;if(P.get('raio'))F.raio.value=P.get('raio');regB().then(buscar)}else if(F.q.value.trim().length>=3)buscar()},50)})();
function buscar(){var q=F.q.value.trim(),M=R.querySelector('#bq-count'),L=R.querySelector('.bq2-l');if(q.length<3&&!REG){M.textContent=F.cid.value.trim()?'Escolha a cidade na lista que aparece enquanto você digita.':'Digite o que você fornece ou escolha uma cidade.';return}guarda();
 var us=Object.keys(ufs).filter(function(k){return ufs[k]}),u='https://pncp.gov.br/api/search/?q='+encodeURIComponent(q.replace(/"/g,''))+'&tipos_documento=edital&ordenacao=-data&pagina=1&tam_pagina=500&status=recebendo_proposta'+(REG?'&municipios='+REG.cods.join('|'):(us.length?'&ufs='+us.join('|'):''))+(F.mod.value?'&modalidades='+F.mod.value:'');
 M.textContent='Buscando no PNCP e nos diários oficiais…';L.innerHTML='<p class="bq2-h">Buscando…</p>';var tk=Date.now();R.dataset.tk=tk;DIO=null;PN=null;ALL=[];fonte='';
 req('GET','buscador/diarios?q='+encodeURIComponent(q)+'&ufs='+(REG?REG.ufs.join('|'):us.join('|'))).then(function(j){if(R.dataset.tk!=tk)return;DIO=(j.itens||[]).filter(function(x){return!REG||REG.nomes[nm(x.municipio)+'|'+x.uf]!==undefined});junta(q)}).catch(function(){if(R.dataset.tk!=tk)return;DIO=[];junta(q)});R.querySelector('.bq2-tabs').innerHTML='';R.querySelector('.bq2-pag').innerHTML='';
 fetch(u).then(function(r){return r.json()}).then(function(j){var tm=termos(q),loc=nm(F.loc.value.trim());
  ALL=(j.items||[]).filter(function(x){return!x.cancelado}).map(function(x){var d=nm(x.description),org=nm((x.orgao_nome||'')+' '+(x.unidade_nome||'')+' '+(x.municipio_nome||'')),sc=0,ok=true;
   tm.w.forEach(function(w){if(d.indexOf(w)>=0)sc+=2;else if(org.indexOf(w)>=0)sc+=1;else ok=false});tm.fr.forEach(function(f){if(d.indexOf(f)>=0)sc+=4;else ok=false});if(d.indexOf(nm(q.replace(/"/g,'')))>=0)sc+=3;
   if(REG){var km=REG.nomes[nm(x.municipio_nome)+'|'+x.uf];if(km!==undefined)x.dist=km}return{x:x,ok:ok,sc:sc,tipo:tipoDe(x.description),ref:ref(x),fim:x.data_fim_vigencia||'',loc:!loc||org.indexOf(loc)>=0}}).filter(function(o){return o.ok&&o.loc});
  if(R.dataset.tk!=tk)return;PN={n:ALL.length,tot:j.total||0};aba='';pag=1;junta(q)})
 .catch(function(){if(R.dataset.tk!=tk)return;ALL=[];PN={n:0,tot:0,erro:1};junta(q)})}
/* diários oficiais: avisos de municípios (Querido Diário) e do DOU seção 3 que não estão no PNCP */
var MODS={'6|7':/preg/i,'4|5':/concorr/i,'8':/dispensa/i,'12':/credenc|chamamento/i,'9':/inexig/i,'1|13':/leil/i};
function junta(q){var M=R.querySelector('#bq-count');if(!PN)return;
 if(PN&&DIO!==null){var ja={};ALL.forEach(function(o){if(o.dio)return;var t=(o.x.title||'')+' '+(o.x.description||''),re=/(\d{1,6})\s*\/\s*(\d{4})/g,m;while((m=re.exec(t)))ja[(+m[1])+'/'+m[2]+'|'+nm(o.x.municipio_nome)]=1});
  var tm=termos(q),loc=nm(F.loc.value.trim()),mr=MODS[F.mod.value];
  DIO.forEach(function(x){var m=/^0*(\d+)\/(\d{4})$/.exec(x.numero||'');if(m&&ja[(+m[1])+'/'+m[2]+'|'+nm(x.municipio)])return;if(mr&&!mr.test(x.modalidade))return;var d=nm(x.objeto+' '+x.trecho),org=nm(x.orgao+' '+x.municipio),sc=0,ok=true;
   tm.w.forEach(function(w){if(d.indexOf(w)>=0)sc+=2;else if(org.indexOf(w)>=0)sc+=1;else ok=false});tm.fr.forEach(function(f){if(d.indexOf(f)>=0)sc+=4;else ok=false});if(!ok||(loc&&org.indexOf(loc)<0))return;
   if(!ALL.some(function(o){return o.ref===x.ref}))ALL.push({x:x,dio:true,ok:true,sc:sc,tipo:tipoDe(x.objeto),ref:x.ref,fim:x.encerra||'',loc:true})})}
 var nd=ALL.filter(function(o){return o.dio}).length,np=ALL.length-nd;
 M.textContent=(PN?(PN.erro?'O PNCP não respondeu agora · ':'')+np+' no PNCP'+(PN.tot>500?' (entre os 500 mais recentes; refine a busca)':''):'Buscando no PNCP…')+' · '+(DIO===null?'buscando nos diários oficiais…':nd+' em diários oficiais')+' · com todas as palavras';pinta()}
function lista(){var hoje=new Date().toISOString().slice(0,10),l=ALL.filter(function(o){return(!tipo||o.tipo===tipo)&&(!fonte||(fonte==='dio')===!!o.dio)&&(verDesc||!DESC[o.ref])&&(!o.fim||o.fim.slice(0,10)>=hoje)}),od=F.ord.value;
 l.sort(od==='perto'?function(a,b){return(a.x.dist==null?999:a.x.dist)-(b.x.dist==null?999:b.x.dist)||(a.fim||'9').localeCompare(b.fim||'9')}:od==='prazo'?function(a,b){return(a.fim||'9').localeCompare(b.fim||'9')}:od==='novos'?function(a,b){return String(b.x.data_publicacao_pncp||b.x.publicado).localeCompare(String(a.x.data_publicacao_pncp||a.x.publicado))}:function(a,b){return b.sc-a.sc||(a.fim||'9').localeCompare(b.fim||'9')});return l}
function pinta(){var l=lista(),ns=ALL.filter(function(o){return o.tipo==='servico'}).length,nd=Object.keys(DESC).filter(function(k){return ALL.some(function(o){return o.ref===k})}).length;
 var q=F.q.value.trim(),us=R.querySelector('#bq-uf').value;
 R.querySelector('.bq2-tabs').innerHTML='<span data-t="" class="'+(tipo===''?'on':'')+'">Tudo ('+ALL.length+')</span><span data-t="servico" class="'+(tipo==='servico'?'on':'')+'">Serviços ('+ns+')</span><span data-t="compra" class="'+(tipo==='compra'?'on':'')+'">Compras ('+(ALL.length-ns)+')</span>'+(ALL.some(function(o){return o.dio})?'<span data-fo="" class="'+(fonte===''?'on':'')+'">Todas as fontes</span><span data-fo="pncp" class="'+(fonte==='pncp'?'on':'')+'">PNCP</span><span data-fo="dio" class="'+(fonte==='dio'?'on':'')+'">Diários oficiais ('+ALL.filter(function(o){return o.dio}).length+')</span>':'')+(nd?'<span data-dsc class="'+(verDesc?'on':'')+'">'+(verDesc?'Ocultar':'Mostrar')+' descartados ('+nd+')</span>':'')+'<a href="/plataforma/alertas/?termos='+encodeURIComponent(q)+(us?'&uf='+encodeURIComponent(us):'')+'&nome='+encodeURIComponent(q+(us?' em '+us:''))+(tipo?'&tipo='+tipo:'')+'">🔔 Receber os novos por e-mail</a>';
 VIS=l.slice((pag-1)*POR,pag*POR);var L=R.querySelector('.bq2-l');
 L.innerHTML=VIS.length?VIS.map(card).join(''):'<p class="bq2-h">Nenhum edital aberto com todas essas palavras'+(tipo?' neste tipo':'')+'. Tente menos palavras, outro estado ou “Tudo”.</p>';
 var np=Math.ceil(l.length/POR),P=R.querySelector('.bq2-pag');P.innerHTML=np>1?Array.from({length:np},function(_,i){return'<button type="button" class="g'+(i+1===pag?' on':'')+'" data-p="'+(i+1)+'">'+(i+1)+'</button>'}).join(''):'';valores()}
function cardD(o){var x=o.x,dd=dias(o.fim),g=GEST[o.ref]||GEST[x.link],cl=dd===null?'':dd<=3?'r':dd<=7?'a':'',lc=[x.municipio,x.uf].filter(Boolean).join('/');
 return'<div class="bq2-i bq2-dio'+(DESC[o.ref]?' desc':'')+'" data-r="'+e(o.ref)+'"><div class="t"><span><b class="bq2-fo">Diário oficial</b> '+e(x.modalidade)+(lc?' · '+e(lc):'')+' · '+(o.tipo==='servico'?'Serviço':'Compra')+'</span><span>'+(x.numero?'Nº '+e(x.numero):'')+'</span></div>'+
 '<div class="o cl" title="Clique para ver tudo">'+e(x.objeto)+'</div><div class="u">'+e(x.orgao)+' · '+e(x.fonte_nome)+'</div>'+
 '<div class="bq2-ch">'+(dd!==null?'<span class="'+cl+'">'+(dd>1?'⏳ faltam '+dd+' dias':dd===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+br(o.fim)+'</span>':'<span>Data da sessão: ver no aviso</span>')+'<span>Publicado '+br(x.publicado).slice(0,10)+'</span></div>'+
 '<div class="bq2-a">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-g>+ Acompanhar na Gestão</button>')+'<button type="button" data-mia-d>Analisar com a minha IA</button><a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no diário oficial</a>'+
 '<button type="button" class="x" data-x>'+(DESC[o.ref]?'Restaurar':'Descartar')+'</button></div></div>'}
function dioLic(x){return{orgao:x.orgao,objeto:x.objeto,municipio:x.municipio,uf:x.uf,modalidade:x.modalidade,numero:x.numero||'',abertura:x.encerra?String(x.encerra).replace(' ','T'):'',link:x.link,status:'analise',notas:'Encontrada no '+(x.fonte_nome||'diário oficial')+' em '+br(x.publicado).slice(0,10)+'. Confira o aviso completo e baixe o edital no site do órgão.\n\nTrecho: '+String(x.trecho||'').slice(0,700)}}
function dioTxt(x){var lc=[x.municipio,x.uf].filter(Boolean).join('/');return'Órgão: '+x.orgao+(lc?'\nLocal: '+lc:'')+'\nModalidade: '+x.modalidade+(x.numero?' nº '+x.numero:'')+(x.encerra?'\nSessão: '+br(x.encerra):'')+'\nPublicado em: '+br(x.publicado).slice(0,10)+' ('+(x.fonte_nome||'diário oficial')+')\n\nTrecho do aviso:\n'+(x.trecho||x.objeto)+'\n\nLink: '+x.link}
function card(o,i){if(o.dio)return cardD(o);var x=o.x,dd=dias(o.fim),g=GEST[o.ref],c=o.ref.split('/'),cl=dd===null?'':dd<=3?'r':dd<=7?'a':'';
 return'<div class="bq2-i'+(DESC[o.ref]?' desc':'')+'" data-r="'+e(o.ref)+'"><div class="t"><span>'+e(x.modalidade_licitacao_nome)+' · '+e(x.municipio_nome)+'/'+e(x.uf)+(x.dist!=null?(x.dist>0?' (a '+Math.round(x.dist)+' km)':' (na cidade)'):'')+' · '+e(x.esfera_nome)+' · '+(o.tipo==='servico'?'Serviço':'Compra')+'</span><span>'+e(x.title||'')+'</span></div>'+
 '<div class="o cl" title="Clique para ver tudo">'+e(x.description)+'</div><div class="u">'+e(x.orgao_nome)+(x.unidade_nome&&x.unidade_nome!==x.orgao_nome?' · '+e(x.unidade_nome):'')+'</div>'+
 '<div class="bq2-ch">'+(dd!==null?'<span class="'+cl+'">'+(dd>1?'⏳ faltam '+dd+' dias':dd===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+br(o.fim)+'</span>':'')+'<span class="bq2-val">Valor estimado: …</span><span>Publicado '+br(x.data_publicacao_pncp).slice(0,10)+'</span></div>'+
 '<div class="bq2-a">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-g>+ Acompanhar na Gestão</button>')+'<button type="button" data-an>Analisar edital</button>'+
 '<a href="/plataforma/raio-x-comprador/?cnpj='+c[0]+'" target="_blank" rel="noopener">Raio-X do órgão · '+((window.DVC&&DVC.comprador)||5)+' créditos</a><a href="/plataforma/contratos-vencendo/?orgao='+c[0]+'" target="_blank" rel="noopener">Contratos vencendo do órgão</a><a href="https://pncp.gov.br/app/editais/'+o.ref+'" target="_blank" rel="noopener">Edital no PNCP</a>'+
 '<button type="button" class="x" data-x>'+(DESC[o.ref]?'Restaurar':'Descartar')+'</button></div></div>'}
function valores(){var fila=VIS.filter(function(o){return!o.dio&&VAL[o.ref]===undefined}),at=0;function um(){var o=fila.shift();if(!o)return;at++;var c=o.ref.split('/');fetch('https://pncp.gov.br/api/consulta/v1/orgaos/'+c[0]+'/compras/'+c[1]+'/'+c[2]).then(function(r){return r.json()}).then(function(j){VAL[o.ref]={v:j.valorTotalEstimado,sig:j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1,srp:j.srp,disp:j.modoDisputaNome}}).catch(function(){VAL[o.ref]=null}).then(function(){mostra(o.ref);um()})}for(var k=0;k<5;k++)um();VIS.forEach(function(o){if(VAL[o.ref]!==undefined)mostra(o.ref)})}
function mostra(r){var el=R.querySelector('.bq2-i[data-r="'+r+'"] .bq2-val');if(!el)return;var v=VAL[r];if(!v){el.remove();return}el.className='bq2-val v';el.textContent=v.sig?'Valor estimado: sigiloso':v.v?'Valor estimado: '+moeda(v.v):'Valor estimado: não informado';var p=el.parentNode;if(v.srp&&!p.querySelector('.srp'))p.insertAdjacentHTML('beforeend','<span class="srp">Registro de preços</span>');if(v.disp&&!p.querySelector('.dsp'))p.insertAdjacentHTML('beforeend','<span class="dsp">Disputa: '+e(v.disp)+'</span>')}
function aGestao(o,b,depois){b.disabled=true;var t0=b.textContent;b.textContent='Salvando…';(o.dio?req('POST','lic/salvar',dioLic(o.x)):req('GET','lic/pncp?ref='+encodeURIComponent(o.ref)).then(function(j){j.status='analise';return req('POST','lic/salvar',j)})).then(function(r){GEST[o.ref]=r.id;if(depois)depois(r.id);else pinta()}).catch(function(j){b.disabled=false;b.textContent=t0;alert((j&&j.message)||'Não foi possível salvar agora. Tente de novo.')})}
R.addEventListener('click',function(ev){var t=ev.target;
 if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];chips();return}
 if(t.parentNode&&t.parentNode.classList&&t.parentNode.classList.contains('bq2-seg')){tipo=t.dataset.v;seg();if(ALL.length){pag=1;pinta()}return}
 if(t.dataset.t!=null&&t.closest('.bq2-tabs')){tipo=t.dataset.t;seg();pag=1;pinta();return}
 if(t.dataset.fo!=null&&t.closest('.bq2-tabs')){fonte=t.dataset.fo;pag=1;pinta();return}
 if(t.hasAttribute('data-dsc')){verDesc=!verDesc;pinta();return}
 if(t.dataset.p){pag=+t.dataset.p;pinta();R.scrollIntoView({behavior:'smooth'});return}
 if(t.classList.contains('cl')){t.classList.remove('cl');return}
 var c=t.closest('.bq2-i');if(!c)return;var o=ALL.filter(function(y){return y.ref===c.dataset.r})[0];if(!o)return;
 if(t.hasAttribute('data-g'))aGestao(o,t);
 else if(t.hasAttribute('data-mia-d')){if(window.dvMinhaIA)dvMinhaIA('licitacao',dioTxt(o.x))}
 else if(t.hasAttribute('data-an')){var go=function(id){location.href='/plataforma/licitacoes/?ver='+id+'&analisar=1'};if(GEST[o.ref])go(GEST[o.ref]);else aGestao(o,t,go)}
 else if(t.hasAttribute('data-x')){if(DESC[o.ref])delete DESC[o.ref];else DESC[o.ref]=Date.now();try{localStorage.setItem('dv_bq_desc',JSON.stringify(DESC))}catch(x){}pinta()}});
F.addEventListener('submit',function(ev){ev.preventDefault();buscar()});F.ord.addEventListener('change',function(){if(ALL.length){pag=1;pinta()}});
var pf={};try{pf=JSON.parse(localStorage.getItem('dv_bq_pref')||'{}')}catch(x){}
if(QS.get('q')){F.q.value=QS.get('q');(QS.get('uf')||'').split(/[,|]/).forEach(function(u){if(u)ufs[u.toUpperCase()]=1})}else{F.q.value=pf.q||'';(pf.ufs||[]).forEach(function(u){ufs[u]=1});tipo=pf.tipo||'';if(pf.mod)F.mod.value=pf.mod;if(pf.ord)F.ord.value=pf.ord}
chips();seg();
req('GET','lic').then(function(j){(j.itens||[]).forEach(function(x){if(x.pncp)GEST[x.pncp]=x.id;if(x.link)GEST[x.link]=x.id});if(ALL.length)pinta()}).catch(function(){});
if(QS.get('q'))buscar()})();
</script>
	<?php return ob_get_clean();
} );
