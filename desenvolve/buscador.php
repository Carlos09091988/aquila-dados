/**
 * Desenvolve — Buscador de licitações abertas (PNCP)
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
	<label>Estados <small>(escolha um ou mais; nenhum = Brasil todo)</small><div class="bq2-ufs"></div></label>
	<input type="hidden" id="bq-uf" name="uf">
	<div class="bq2-g3">
		<label>Modalidade<select name="mod"><option value="">Todas</option><option value="6|7">Pregão</option><option value="4|5">Concorrência</option><option value="8">Dispensa</option><option value="12">Credenciamento</option><option value="9">Inexigibilidade</option><option value="1|13">Leilão</option></select></label>
		<label>Cidade ou órgão <small>(opcional)</small><input name="loc" placeholder="Ex.: Campinas, Exército, Hospital"></label>
		<label>Ordenar por<select name="ord"><option value="rel">Mais parecidos com a busca</option><option value="prazo">Prazo mais próximo</option><option value="novos">Publicados mais recentemente</option></select></label>
	</div>
	<div class="bq2-act"><button type="submit">🔎 Buscar licitações</button><span class="bq2-cnt" id="bq-count"></span></div>
</form>
<div class="bq2-tabs"></div>
<div class="bq2-l"><p class="bq2-h">Diga o que você fornece e onde, e clique em Buscar. Sem custo de crédito.</p></div>
<div class="bq2-pag"></div>
<p class="bq2-h bq2-foot">Fonte: Portal Nacional de Contratações Públicas (PNCP), em tempo real. Confira sempre o edital antes de participar.</p>
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
@media(max-width:760px){.bq2{padding:16px}.bq2-g,.bq2-g3{grid-template-columns:1fr}.bq2-a .x{margin-left:0}}
</style>
<script>
(function(){var R=document.getElementById('bq2');if(!R)return;var T=R.closest('.dv-tool');if(T)T.setAttribute('data-sem-portao','');
var api=R.dataset.api,nonce=R.dataset.nonce,F=R.querySelector('#bq-form'),UF='AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split(' '),ufs={},tipo='',ALL=[],VIS=[],aba='',pag=1,POR=20,GEST={},DESC={},VAL={},verDesc=false,QS=new URLSearchParams(location.search);
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
function buscar(){var q=F.q.value.trim(),M=R.querySelector('#bq-count'),L=R.querySelector('.bq2-l');if(q.length<3){M.textContent='Digite pelo menos 3 letras.';return}guarda();
 var us=Object.keys(ufs).filter(function(k){return ufs[k]}),u='https://pncp.gov.br/api/search/?q='+encodeURIComponent(q.replace(/"/g,''))+'&tipos_documento=edital&ordenacao=-data&pagina=1&tam_pagina=500&status=recebendo_proposta'+(us.length?'&ufs='+us.join('|'):'')+(F.mod.value?'&modalidades='+F.mod.value:'');
 M.textContent='Buscando no PNCP…';L.innerHTML='<p class="bq2-h">Buscando…</p>';R.querySelector('.bq2-tabs').innerHTML='';R.querySelector('.bq2-pag').innerHTML='';
 fetch(u).then(function(r){return r.json()}).then(function(j){var tm=termos(q),loc=nm(F.loc.value.trim());
  ALL=(j.items||[]).filter(function(x){return!x.cancelado}).map(function(x){var d=nm(x.description),org=nm((x.orgao_nome||'')+' '+(x.unidade_nome||'')+' '+(x.municipio_nome||'')),sc=0,ok=true;
   tm.w.forEach(function(w){if(d.indexOf(w)>=0)sc+=2;else if(org.indexOf(w)>=0)sc+=1;else ok=false});tm.fr.forEach(function(f){if(d.indexOf(f)>=0)sc+=4;else ok=false});if(d.indexOf(nm(q.replace(/"/g,'')))>=0)sc+=3;
   return{x:x,ok:ok,sc:sc,tipo:tipoDe(x.description),ref:ref(x),fim:x.data_fim_vigencia||'',loc:!loc||org.indexOf(loc)>=0}}).filter(function(o){return o.ok&&o.loc});
  var tot=j.total||0;M.textContent=ALL.length+' edital(is) com todas as palavras'+(tot>500?' (entre os 500 mais recentes de '+tot+' resultados do PNCP; refine a busca)':'');aba='';pag=1;pinta()})
 .catch(function(){M.textContent='';L.innerHTML='<p class="bq2-h">O PNCP não respondeu agora. Tente de novo em instantes.</p>'})}
function lista(){var hoje=new Date().toISOString().slice(0,10),l=ALL.filter(function(o){return(!tipo||o.tipo===tipo)&&(verDesc||!DESC[o.ref])&&(!o.fim||o.fim.slice(0,10)>=hoje)}),od=F.ord.value;
 l.sort(od==='prazo'?function(a,b){return(a.fim||'9').localeCompare(b.fim||'9')}:od==='novos'?function(a,b){return String(b.x.data_publicacao_pncp).localeCompare(String(a.x.data_publicacao_pncp))}:function(a,b){return b.sc-a.sc||(a.fim||'9').localeCompare(b.fim||'9')});return l}
function pinta(){var l=lista(),ns=ALL.filter(function(o){return o.tipo==='servico'}).length,nd=Object.keys(DESC).filter(function(k){return ALL.some(function(o){return o.ref===k})}).length;
 var q=F.q.value.trim(),us=R.querySelector('#bq-uf').value;
 R.querySelector('.bq2-tabs').innerHTML='<span data-t="" class="'+(tipo===''?'on':'')+'">Tudo ('+ALL.length+')</span><span data-t="servico" class="'+(tipo==='servico'?'on':'')+'">Serviços ('+ns+')</span><span data-t="compra" class="'+(tipo==='compra'?'on':'')+'">Compras ('+(ALL.length-ns)+')</span>'+(nd?'<span data-dsc class="'+(verDesc?'on':'')+'">'+(verDesc?'Ocultar':'Mostrar')+' descartados ('+nd+')</span>':'')+'<a href="/plataforma/alertas/?termos='+encodeURIComponent(q)+(us?'&uf='+encodeURIComponent(us):'')+'&nome='+encodeURIComponent(q+(us?' em '+us:''))+'">🔔 Receber os novos por e-mail</a>';
 VIS=l.slice((pag-1)*POR,pag*POR);var L=R.querySelector('.bq2-l');
 L.innerHTML=VIS.length?VIS.map(card).join(''):'<p class="bq2-h">Nenhum edital aberto com todas essas palavras'+(tipo?' neste tipo':'')+'. Tente menos palavras, outro estado ou “Tudo”.</p>';
 var np=Math.ceil(l.length/POR),P=R.querySelector('.bq2-pag');P.innerHTML=np>1?Array.from({length:np},function(_,i){return'<button type="button" class="g'+(i+1===pag?' on':'')+'" data-p="'+(i+1)+'">'+(i+1)+'</button>'}).join(''):'';valores()}
function card(o,i){var x=o.x,dd=dias(o.fim),g=GEST[o.ref],c=o.ref.split('/'),cl=dd===null?'':dd<=3?'r':dd<=7?'a':'';
 return'<div class="bq2-i'+(DESC[o.ref]?' desc':'')+'" data-r="'+e(o.ref)+'"><div class="t"><span>'+e(x.modalidade_licitacao_nome)+' · '+e(x.municipio_nome)+'/'+e(x.uf)+' · '+e(x.esfera_nome)+' · '+(o.tipo==='servico'?'Serviço':'Compra')+'</span><span>'+e(x.title||'')+'</span></div>'+
 '<div class="o cl" title="Clique para ver tudo">'+e(x.description)+'</div><div class="u">'+e(x.orgao_nome)+(x.unidade_nome&&x.unidade_nome!==x.orgao_nome?' · '+e(x.unidade_nome):'')+'</div>'+
 '<div class="bq2-ch">'+(dd!==null?'<span class="'+cl+'">'+(dd>1?'⏳ faltam '+dd+' dias':dd===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+br(o.fim)+'</span>':'')+'<span class="bq2-val">Valor estimado: …</span><span>Publicado '+br(x.data_publicacao_pncp).slice(0,10)+'</span></div>'+
 '<div class="bq2-a">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-g>+ Acompanhar na Gestão</button>')+'<button type="button" data-an>Analisar edital</button>'+
 '<a href="/plataforma/raio-x-comprador/?cnpj='+c[0]+'" target="_blank" rel="noopener">Raio-X do órgão · '+((window.DVC&&DVC.comprador)||5)+' créditos</a><a href="/plataforma/contratos-vencendo/?orgao='+c[0]+'" target="_blank" rel="noopener">Contratos vencendo do órgão</a><a href="https://pncp.gov.br/app/editais/'+o.ref+'" target="_blank" rel="noopener">Edital no PNCP</a>'+
 '<button type="button" class="x" data-x>'+(DESC[o.ref]?'Restaurar':'Descartar')+'</button></div></div>'}
function valores(){var fila=VIS.filter(function(o){return VAL[o.ref]===undefined}),at=0;function um(){var o=fila.shift();if(!o)return;at++;var c=o.ref.split('/');fetch('https://pncp.gov.br/api/consulta/v1/orgaos/'+c[0]+'/compras/'+c[1]+'/'+c[2]).then(function(r){return r.json()}).then(function(j){VAL[o.ref]={v:j.valorTotalEstimado,sig:j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1,srp:j.srp,disp:j.modoDisputaNome}}).catch(function(){VAL[o.ref]=null}).then(function(){mostra(o.ref);um()})}for(var k=0;k<5;k++)um();VIS.forEach(function(o){if(VAL[o.ref]!==undefined)mostra(o.ref)})}
function mostra(r){var el=R.querySelector('.bq2-i[data-r="'+r+'"] .bq2-val');if(!el)return;var v=VAL[r];if(!v){el.remove();return}el.className='bq2-val v';el.textContent=v.sig?'Valor estimado: sigiloso':v.v?'Valor estimado: '+moeda(v.v):'Valor estimado: não informado';var p=el.parentNode;if(v.srp&&!p.querySelector('.srp'))p.insertAdjacentHTML('beforeend','<span class="srp">Registro de preços</span>');if(v.disp&&!p.querySelector('.dsp'))p.insertAdjacentHTML('beforeend','<span class="dsp">Disputa: '+e(v.disp)+'</span>')}
function aGestao(o,b,depois){b.disabled=true;var t0=b.textContent;b.textContent='Salvando…';req('GET','lic/pncp?ref='+encodeURIComponent(o.ref)).then(function(j){j.status='analise';return req('POST','lic/salvar',j)}).then(function(r){GEST[o.ref]=r.id;if(depois)depois(r.id);else pinta()}).catch(function(j){b.disabled=false;b.textContent=t0;alert((j&&j.message)||'Não foi possível salvar agora. Tente de novo.')})}
R.addEventListener('click',function(ev){var t=ev.target;
 if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];chips();return}
 if(t.parentNode&&t.parentNode.classList&&t.parentNode.classList.contains('bq2-seg')){tipo=t.dataset.v;seg();if(ALL.length){pag=1;pinta()}return}
 if(t.dataset.t!=null&&t.closest('.bq2-tabs')){tipo=t.dataset.t;seg();pag=1;pinta();return}
 if(t.hasAttribute('data-dsc')){verDesc=!verDesc;pinta();return}
 if(t.dataset.p){pag=+t.dataset.p;pinta();R.scrollIntoView({behavior:'smooth'});return}
 if(t.classList.contains('cl')){t.classList.remove('cl');return}
 var c=t.closest('.bq2-i');if(!c)return;var o=ALL.filter(function(y){return y.ref===c.dataset.r})[0];if(!o)return;
 if(t.hasAttribute('data-g'))aGestao(o,t);
 else if(t.hasAttribute('data-an')){var go=function(id){location.href='/plataforma/licitacoes/?ver='+id+'&analisar=1'};if(GEST[o.ref])go(GEST[o.ref]);else aGestao(o,t,go)}
 else if(t.hasAttribute('data-x')){if(DESC[o.ref])delete DESC[o.ref];else DESC[o.ref]=Date.now();try{localStorage.setItem('dv_bq_desc',JSON.stringify(DESC))}catch(x){}pinta()}});
F.addEventListener('submit',function(ev){ev.preventDefault();buscar()});F.ord.addEventListener('change',function(){if(ALL.length){pag=1;pinta()}});
var pf={};try{pf=JSON.parse(localStorage.getItem('dv_bq_pref')||'{}')}catch(x){}
if(QS.get('q')){F.q.value=QS.get('q');(QS.get('uf')||'').split(/[,|]/).forEach(function(u){if(u)ufs[u.toUpperCase()]=1})}else{F.q.value=pf.q||'';(pf.ufs||[]).forEach(function(u){ufs[u]=1});tipo=pf.tipo||'';if(pf.mod)F.mod.value=pf.mod;if(pf.ord)F.ord.value=pf.ord}
chips();seg();
req('GET','lic').then(function(j){(j.itens||[]).forEach(function(x){if(x.pncp)GEST[x.pncp]=x.id});if(ALL.length)pinta()}).catch(function(){});
if(QS.get('q'))buscar()})();
</script>
	<?php return ob_get_clean();
} );
