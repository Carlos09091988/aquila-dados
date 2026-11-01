<?php
/**
 * Desenvolve — Resumo executivo (Raio-X do concorrente, Due Diligence e Raio-X do comprador).
 * Lê o relatório já renderizado na tela e responde às perguntas da decisão.
 * Não chama nenhuma API nova e não cobra créditos. Marcador: DV_RESUMO_V1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
	if ( ! is_page( array( 'raio-x', 'raio-x-comprador', 'dossie' ) ) ) { return; }
	?>
<style id="dv-rs-css">
.dv-rs{background:#fff!important;border:1px solid #E3DED2!important;border-left:6px solid #C9A45C!important;border-radius:16px!important;padding:20px 22px!important;margin:0 0 18px!important;color:#16191E!important;font-family:'Hanken Grotesk',system-ui,sans-serif!important;box-shadow:0 6px 24px rgba(22,25,30,.06)!important;break-inside:avoid;page-break-inside:avoid}
.dv-rs *{font-family:inherit!important;box-sizing:border-box}
.dv-rs .h{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.dv-rs .h small{font-size:.72rem!important;letter-spacing:.14em;text-transform:uppercase;color:#8A6A2E!important;font-weight:700}
.dv-rs .h h3{margin:0!important;font-size:1.25rem!important;color:#16191E!important;letter-spacing:-.01em;flex:1 1 auto}
.dv-rs .nv{border-radius:999px;padding:6px 14px;font-weight:800;font-size:.9rem}
.dv-rs .nv.a{background:#FDEDEA;color:#9A3524}.dv-rs .nv.m{background:#FBF1DC;color:#7A5410}.dv-rs .nv.b{background:#EAF5EE;color:#1F5A3B}
.dv-rs .g{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.dv-rs .q{background:#FAF8F3;border:1px solid #EEE9DD;border-radius:12px;padding:12px 14px;min-width:0}
.dv-rs .q b{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:#6E6A60!important;margin:0 0 4px}
.dv-rs .q p{margin:0!important;font-size:.95rem!important;line-height:1.45!important;color:#16191E!important}
.dv-rs .q p em{font-style:normal;font-weight:700}
.dv-rs .q.w{grid-column:1/-1}
.dv-rs .q ul{margin:4px 0 0 18px!important;padding:0!important}.dv-rs .q li{font-size:.92rem!important;line-height:1.45!important;margin:2px 0!important}
.dv-rs .ac{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.dv-rs .ac a{display:inline-block;border:1px solid #C9A45C;border-radius:10px;padding:7px 12px;font-weight:700;font-size:.86rem;color:#241A06!important;text-decoration:none!important;background:#fff}
.dv-rs .ac a.p{background:#C9A45C}
.dv-rs .ft{margin:12px 0 0!important;font-size:.76rem!important;color:#6E6A60!important;line-height:1.4!important}
@media(max-width:640px){.dv-rs .g{grid-template-columns:1fr}.dv-rs{padding:16px!important}}
@media print{.dv-rs .ac{display:none}.dv-rs{box-shadow:none!important}}
</style>
<script>
/* DV_RESUMO_V1 */
(function(){
var path=location.pathname,PG=/raio-x-comprador/.test(path)?'comprador':/dossie/.test(path)?'dossie':'concorrente';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function num(v){if(v==null)return 0;v=String(v);if(/^\s*[—-]\s*$/.test(v))return 0;var m=v.replace(/\./g,'').match(/\d+/);return m?parseInt(m[0],10):0}
function brl(t){if(!t)return 0;var m=String(t).match(/R\$\s*([\d.]+(?:,\d+)?)\s*(mi|mil|bi)?/i);if(!m)return 0;var v=parseFloat(m[1].replace(/\./g,'').replace(',','.'));var u=(m[2]||'').toLowerCase();return v*(u==='mil'?1e3:u==='mi'?1e6:u==='bi'?1e9:1)}
function mc(v){return v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function cap1(t){return t.charAt(0).toUpperCase()+t.slice(1)}
function root(){return PG==='concorrente'?document.querySelector('.rx-out'):document.querySelector('.rp')}
/* pares número/rótulo (contagens) e rótulo/valor (quadros) */
function pares(r){var o={};[].forEach.call(r.querySelectorAll('div'),function(d){var b=null,s=null;for(var i=0;i<d.children.length;i++){var c=d.children[i];if(c.tagName==='B'&&!b)b=c;else if(c.tagName==='SPAN'&&!s)s=c}if(!b||!s||d.children.length>3)return;var bt=b.textContent.trim(),st=s.textContent.trim();if(/^(≥\s*)?[\d.]+$|^[—-]$/.test(bt))o[st]=bt;else o[bt]=st});return o}
function val(P,re){for(var k in P)if(re.test(k))return P[k];return null}

function sinaisEmpresa(r){
 var T=r.innerText,P=pares(r),S={},X=document.getElementById('dv-rs');if(X&&r.contains(X))T=T.replace(X.innerText,'');
 var sit=T.match(/Situa[çc][ãa]o(?: cadastral)?:?\s*\n?\s*(ATIVA|BAIXADA|INAPTA|SUSPENSA|NULA)/i);S.sit=sit?sit[1].toUpperCase():'';
 var cap=T.match(/Capital social\s*\n?\s*(R\$\s*[\d.,]+(?:\s*(?:mi|mil))?)/i);S.capital=cap?brl(cap[1]):0;
 var ab=T.match(/Abertura\s*\n?\s*(\d{2})\/(\d{2})\/(\d{4})/i);S.anos=ab?Math.floor((Date.now()-new Date(+ab[3],+ab[2]-1,+ab[1]))/31557600000):null;
 S.porte=(T.match(/Porte\s*\n?\s*([A-ZÇÃÉ ]{2,30})\n/)||[])[1]||'';
 /* sanções */
 S.sanc=[];
 if(PG==='concorrente'){
  [].forEach.call(r.querySelectorAll('section.rx-card'),function(c){var t=c.innerText,h=t.split('\n')[0];
   if(/^(CEIS|CNEP|TCU)/.test(h)&&!/Nenhum registro|N[ãa]o consta|indispon[íi]vel/i.test(t))S.sanc.push(h.split('·')[0].trim());
   if(/^OUTROS CADASTROS/.test(h)){var L=t.split('\n');for(var i=0;i<L.length;i++){if(/^(Consta|Encontrad|\d+ registro|Sim,)/i.test(L[i].trim())){S.sanc.push((L[i-1]||'cadastro público').split('·')[0].trim())}}}
  });
 }else{var ns=num(val(P,/^Sanções/));if(ns)S.sanc.push(ns+' registro(s) em cadastros de sanções')}
 /* dívida ativa */
 var tot=T.match(/R\$\s*([\d.,]+(?:\s*(?:mi|mil))?)\s*inscritos em d[íi]vida ativa/i);S.pgfn=tot?brl('R$ '+tot[1]):brl(val(P,/^D[íi]vida ativa da Uni/));
 var cob=T.match(/(\d+)\s+(?:inscri[çc][ãa]o\(?õ?e?s?\)?\s+)?em cobran[çc]a/i);S.cobranca=/nenhuma em cobran/i.test(T)?0:cob?+cob[1]:(S.pgfn>0&&/ajuizad/i.test(T)?1:(S.pgfn>0&&PG==='dossie'?-1:0));
 var ec=T.match(/EM COBRAN[ÇC]A\s*\n\s*(R\$\s*[\d.,]+)/i);if(ec)S.cobranca=brl(ec[1])>0?1:0;
 S.parcelada=S.pgfn>0&&S.cobranca===0&&(!!ec||/parcelad|garantid|suspens/i.test(T));
 var fg=T.match(/\nFGTS\s*\n\s*(R\$\s*[\d.,]+)/i);S.fgts=fg?brl(fg[1])>0:/D[ée]bito de FGTS inscrito/i.test(T);
 /* processos */
 S.impr=num(val(P,/^Improbidade/));S.crim=num(val(P,/^Criminal/));S.colet=num(val(P,/^A[çc][õo]es coletivas/));S.falen=num(val(P,/^Fal[êe]ncia/));
 S.trab=num(val(P,/^(Processos )?[Tt]rabalhistas$/));S.trabEx=num(val(P,/^Execu[çc][õo]es trabalhistas/));
 /* grupo */
 var gc=T.match(/(\d+)\s+com telefone, e-mail ou endere[çc]o em comum/i);S.comum=gc?+gc[1]:Math.max((T.match(/CONTATO EM COMUM/g)||[]).length,(T.match(/\tMesmo (endere[çc]o|telefone|e-mail)/gi)||[]).length);
 /* contratos */
 var pn=T.match(/(\d+)\s*\n?\s*contratos no PNCP/i),ca=val(P,/^Contratos ativos/);S.contratos=pn?+pn[1]:num(ca);
 var un=T.match(/(R\$\s*[\d.,]+(?:\s*(?:mi|mil))?)\s*\n?\s*recebidos da Uni/i);S.uniao=un?brl(un[1]):0;S.vAtivos=ca?brl(ca):0;
 S.noticias=/Nenhuma not[íi]cia negativa|Nada negativo/i.test(T)?0:num(val(P,/^(Imprensa|Not[íi]cias negativas)/));
 S.cnpj=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'')||((T.match(/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/)||[''])[0].replace(/\D/g,''));
 return S;
}

function htmlEmpresa(S){
 var A=[],M=[],arg=[],inab=[];
 if(S.sit&&S.sit!=='ATIVA'){A.push('CNPJ '+S.sit.toLowerCase()+' na Receita');inab.push('situação cadastral '+S.sit.toLowerCase());arg.push(['Situação cadastral '+S.sit.toLowerCase()+' na Receita Federal: falha na habilitação jurídica e fiscal (arts. 66 e 68, I).',9])}
 if(S.sanc.length){A.push('sanção em '+S.sanc.join(', '));inab.push('sanção registrada ('+S.sanc.join(', ')+')');arg.push(['Sanção registrada em '+S.sanc.join(', ')+'. Se estiver vigente e alcançar este órgão, a empresa não pode participar (art. 14, III). Confira o tipo e a abrangência da penalidade (art. 156, §§ 4º e 5º).',10])}
 if(S.cobranca>0){A.push('dívida ativa da União em cobrança');inab.push('dívida ativa em cobrança (a CND federal tende a sair positiva)');arg.push(['Dívida ativa da União de '+mc(S.pgfn)+' em cobrança: sem certidão negativa ou positiva com efeito de negativa, falta regularidade fiscal (art. 68, III).',8])}
 if(S.fgts){A.push('débito de FGTS inscrito');inab.push('débito de FGTS (certificado de regularidade)');arg.push(['Débito de FGTS inscrito em dívida ativa: confira o Certificado de Regularidade do FGTS apresentado (art. 68, IV).',8])}
 if(S.falen){A.push('processo de falência ou recuperação');inab.push('processo de falência ou recuperação judicial');arg.push(['Há processo de falência ou recuperação judicial: exija a certidão negativa de feitos sobre falência (art. 69, II) e, em recuperação, o plano homologado.',7])}
 if(S.trabEx){M.push(S.trabEx+' execução(ões) trabalhista(s)');inab.push('execuções trabalhistas podem gerar CNDT positiva');arg.push([S.trabEx+' execução(ões) trabalhista(s): condenações não pagas podem constar no Banco Nacional de Devedores Trabalhistas. Confira a CNDT apresentada (art. 68, V).',6])}
 if(S.comum){M.push(S.comum+' empresa(s) ligada(s) com contato em comum');arg.push([S.comum+' empresa(s) do mesmo grupo com telefone, e-mail ou endereço em comum. Se alguma disputou este mesmo certame, é indício de atuação coordenada: peça diligência ao agente de contratação e cite a frustração do caráter competitivo (art. 337-F do Código Penal).',5])}
 if(S.impr||S.crim||S.colet){M.push([S.impr?S.impr+' de improbidade':'',S.crim?S.crim+' criminal(is)':'',S.colet?S.colet+' ação(ões) coletiva(s)':''].filter(Boolean).join(', '));arg.push(['Processos sensíveis ('+[S.impr?'improbidade':'',S.crim?'criminal':'',S.colet?'ação coletiva':''].filter(Boolean).join(', ')+'). Sozinhos não inabilitam, mas reforçam o pedido de diligência sobre a capacidade de execução.',3])}
 if(S.parcelada){M.push('dívida ativa parcelada ('+mc(S.pgfn)+')')}
 if(S.cobranca<0&&S.pgfn>0){M.push('dívida ativa de '+mc(S.pgfn));arg.push(['Dívida ativa da União de '+mc(S.pgfn)+': confira se a certidão federal apresentada está válida (art. 68, III).',4])}
 if(S.noticias)M.push(S.noticias+' notícia(s) negativa(s)');
 var nv=A.length?['a','Atenção alta']:M.length?['m','Atenção moderada']:['b','Atenção baixa'];
 var mot=A.concat(M);
 var pode=A.length?'<em>Pode, sim.</em> Pontos que atingem a habilitação: '+e(inab.slice(0,3).join('; '))+'.':inab.length?'<em>Depende das certidões.</em> '+e(cap1(inab.join('; ')))+'. Confira as que foram apresentadas.':S.parcelada?'<em>Pouco provável.</em> A dívida está parcelada ou garantida; a certidão positiva com efeito de negativa vale para a habilitação.':'<em>Pelos dados públicos, não.</em> Nenhum impedimento encontrado nas fontes consultadas.';
 arg.sort(function(a,b){return b[1]-a[1]});
 var forte=arg.length?e(arg[0][0]):'Nenhum argumento forte nos dados públicos. Concentre o recurso nos documentos apresentados no certame (atestados, proposta e exequibilidade).';
 var cap=[];if(S.contratos)cap.push(S.contratos+(PG==='dossie'?' contrato(s) ativo(s)':' contrato(s) público(s)'));if(S.uniao)cap.push(mc(S.uniao)+' recebidos da União no ano');if(S.vAtivos)cap.push(mc(S.vAtivos)+(PG==='dossie'?' em valor':' em contratos ativos'));if(S.capital)cap.push('capital social de '+mc(S.capital));if(S.anos!=null)cap.push(S.anos+' ano(s) de atividade');
 var fol=(S.contratos>=3&&(S.anos==null||S.anos>=3))?'<em>Tem histórico de execução.</em> ':S.contratos?'<em>Histórico curto.</em> Vale exigir com rigor os atestados (art. 67). ':'<em>Sem contratos públicos encontrados.</em> Vale exigir com rigor os atestados (art. 67). ';
 fol+=e(cap.join(' · ')||'Sem dados de contratos.')+'.';if(S.trab>=50)fol+=' '+S.trab.toLocaleString('pt-BR')+' ações trabalhistas: compare o volume com o porte da empresa.';
 var ac='<a class="p" href="/plataforma/recursos/">Usar no recurso</a>';
 if(PG==='concorrente'&&S.cnpj)ac+='<a href="/plataforma/dossie/?cnpj='+S.cnpj+'">Due Diligence completa</a>';
 var prox=A.length?'Junte as certidões e o print das fontes e prepare o recurso no prazo (3 dias úteis da intenção, art. 165, §1º, I).':M.length?'Confira as certidões apresentadas no certame e peça diligência sobre os pontos acima antes do recurso.':'Não há o que explorar aqui. Foque nos documentos da proposta e da habilitação.';
 return '<div class="h"><small>Resumo executivo</small><h3>O que importa para a decisão</h3><span class="nv '+nv[0]+'">'+nv[1]+'</span></div>'+
 '<div class="g"><div class="q w"><b>Nível de atenção</b><p>'+(mot.length?e(mot.join(' · '))+'.':'Nenhum ponto relevante nas fontes consultadas.')+'</p></div>'+
 '<div class="q"><b>Pode ser inabilitada?</b><p>'+pode+'</p></div>'+
 '<div class="q"><b>Argumento mais forte para o recurso</b><p>'+forte+'</p></div>'+
 '<div class="q"><b>Tem fôlego para executar?</b><p>'+fol+'</p></div>'+
 '<div class="q"><b>Próximo passo</b><p>'+e(prox)+'</p><div class="ac">'+ac+'</div></div></div>';
}

function htmlComprador(r){
 var P=pares(r);
 var pg=val(P,/^Paga em dia\?$/)||val(P,/^Paga em dia \(nota\)/)||'',pts=/\d/.test(pg)?num(pg):null;
 var proc=num(val(P,/^Processos sobre licita/)),grav=num(val(P,/graves/)),ntl=num(val(P,/^Not[íi]cias negativas de licita/)),ger=num(val(P,/^Processos d[oa] /)),ntg=num(val(P,/^Not[íi]cias sobre o gestor/)),grev=num(val(P,/^Greves/)),atr=num(val(P,/^Not[íi]cias de atraso/));
 var cobv=val(P,/^Cobran[çc]as na Justi/)||'',cob=num(cobv),at=val(P,/^Contratos ativos/)||'',ab=val(P,/^Licita[çc][õo]es abertas/)||'',item=val(P,/^Seu item: contratos ativos/);
 var A=[],M=[];
 if(pts!=null&&pts<55)A.push('nota de pagamento '+pts+'/100');else if(pts!=null&&pts<80)M.push('nota de pagamento '+pts+'/100');
 if(atr>=3)A.push(atr+' notícias de atraso de pagamento');else if(atr)M.push(atr+' notícia(s) de atraso de pagamento');
 if(cob>10)M.push(cob+' cobranças na Justiça em 12 meses');
 if(grav)A.push(grav+' processo(s) grave(s) sobre licitações');else if(proc)M.push(proc+' processo(s) sobre licitações');
 if(ntl)M.push(ntl+' notícia(s) negativa(s) de licitações');if(ger||ntg)M.push('gestor com '+(ger?ger+' processo(s)':'')+(ger&&ntg?' e ':'')+(ntg?ntg+' notícia(s)':''));if(grev)M.push(grev+' notícia(s) de greve');
 var nv=A.length?['a','Atenção alta']:M.length?['m','Atenção moderada']:['b','Atenção baixa'];
 var paga=pts==null?'<em>Sem dados fiscais do ente.</em> Confira as notícias e as cobranças na Justiça abaixo.':pts>=80?'<em>Sim, risco baixo.</em> Nota '+pts+'/100.':pts>=55?'<em>Com atenção.</em> Nota '+pts+'/100: há sinais de atraso.':'<em>Risco alto de atraso.</em> Nota '+pts+'/100.';
 if(cobv)paga+=' Cobranças na Justiça (12 meses): '+e(cobv)+'.';
 var op=[];if(at)op.push('contratos ativos: '+at);if(item)op.push('do seu item: '+item);if(ab)op.push('licitações abertas: '+ab);
 var prev=pts!=null&&pts<55||atr>=3?'Coloque na proposta o custo de capital de giro para atrasos. Se o atraso passar de 2 meses da nota fiscal, o contratado pode suspender a execução ou pedir a extinção (art. 137, § 2º, IV, e § 3º, II).':grav||proc?'Leia os processos sobre licitações abaixo antes de participar: mostram onde o órgão costuma errar e o que impugnar.':'Nada impede a participação. Confira o edital e o preço que o órgão já pagou.';
 return '<div class="h"><small>Resumo executivo</small><h3>Vale participar?</h3><span class="nv '+nv[0]+'">'+nv[1]+'</span></div>'+
 '<div class="g"><div class="q w"><b>Nível de atenção</b><p>'+(A.concat(M).length?e(A.concat(M).join(' · '))+'.':'Nenhum sinal relevante nas fontes consultadas.')+'</p></div>'+
 '<div class="q"><b>Paga em dia?</b><p>'+paga+'</p></div>'+
 '<div class="q"><b>Tamanho da oportunidade</b><p>'+e(op.join(' · ')||'Sem contratos encontrados no PNCP.')+'.</p></div>'+
 '<div class="q w"><b>Próximo passo</b><p>'+e(prev)+'</p><div class="ac"><a class="p" href="/plataforma/buscador/">Ver licitações abertas</a><a href="/plataforma/contratos-vencendo/">Contratos vencendo</a></div></div></div>';
}

var ult='',tm=null,ts=null,relId=0,est0={};[].forEach.call(document.querySelectorAll('style[id]'),function(x){est0[x.id]=1});
var TOOL={concorrente:'raiox',comprador:'comprador',dossie:'dossie'}[PG];
function salvar(){
 if(window.DVREL_VIEW||!window.DVREL)return;var r=root();if(!r||!document.getElementById('dv-rs'))return;
 var c='';[].forEach.call(document.querySelectorAll('.dv-tool input'),function(i){var d=String(i.value||'').replace(/\D/g,'');if(!c&&d.length===14)c=d});
 if(!c)c=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'');if(c.length!==14)return;
 var h2=r.querySelector('.rx-card.ink h2, .rp-hd h2, h2'),rot=h2?h2.textContent.trim().slice(0,180):'';
 var p=r.parentElement,a=p;while(a&&!a.id)a=a.parentElement;
 var es=[];[].forEach.call(document.querySelectorAll('style[id]'),function(x){if(!est0[x.id]&&x.id!=='dv-rs-css'&&x.id!=='dv-rel-css')es.push({id:x.id,css:x.textContent})});
 fetch(window.DVREL.api+'relatorio',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':window.DVREL.nonce,'Content-Type':'application/json'},body:JSON.stringify({id:relId,ferramenta:TOOL,cnpj:c,rotulo:rot,html:r.outerHTML,pai:{id:a?a.id:'',cls:p.className||''},estilos:es})}).then(function(x){return x.json()}).then(function(j){if(j&&j.id){relId=j.id;var L=window.DVREL.lista,k=-1;for(var i=0;i<L.length;i++)if(L[i].cnpj===c)k=i;var it={id:j.id,cnpj:c,rotulo:rot,criado:new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16).replace('T',' '),url:location.pathname+'?rel='+j.id};it.ate=new Date(Date.now()+30*864e5).toISOString().slice(0,10);if(k>=0)L[k]=it;else L.unshift(it)}}).catch(function(){})
}
function montar(){
 var r=root();if(!r)return;
 if(PG==='concorrente'&&!r.querySelector('.rx-pts'))return;
 if(PG!=='concorrente'&&!r.querySelector('.rp-sec'))return;
 var h;try{h=PG==='comprador'?htmlComprador(r):htmlEmpresa(sinaisEmpresa(r))}catch(x){return}
 h+='<p class="ft">Leitura automática dos dados públicos deste relatório, na data da consulta. Não constitui parecer jurídico: confirme cada ponto nas fontes oficiais e com o seu especialista.</p>';
 clearTimeout(ts);ts=setTimeout(salvar,7000);
 if(h===ult&&document.getElementById('dv-rs'))return;ult=h;
 var b=document.getElementById('dv-rs');if(!b){b=document.createElement('section');b.id='dv-rs';b.className='dv-rs'}
 b.innerHTML=h;
 if(!b.parentNode){if(PG==='concorrente'){var p=r.querySelector('.rx-pts');p.parentNode.insertBefore(b,p)}else{var bar=r.querySelector('.rp-bar')||r.querySelector('.rp-hd');bar.parentNode.insertBefore(b,bar.nextSibling)}}
}
new MutationObserver(function(ms){for(var i=0;i<ms.length;i++){var t=ms[i].target;if(t.closest&&t.closest('#dv-rs'))continue;clearTimeout(tm);tm=setTimeout(montar,900);return}}).observe(document.body,{childList:true,subtree:true});
})();
</script>
	<?php
}, 99 );
