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
.dv-rs .id{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin:0 0 12px;padding:0 0 12px;border-bottom:1px solid #EEE9DD}.dv-rs .id b{font-size:1.35rem!important;font-weight:800;color:#16191E!important;letter-spacing:-.01em}.dv-rs .id span{color:#6E6A60!important;font-size:.9rem}
.dv-rs .h{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.dv-rs .h small{font-size:.72rem!important;letter-spacing:.14em;text-transform:uppercase;color:#8A6A2E!important;font-weight:700}
.dv-rs .h h3{margin:0!important;font-size:1.25rem!important;color:#16191E!important;letter-spacing:-.01em;flex:1 1 auto}
.dv-rs .nv{border-radius:999px;padding:6px 14px;font-weight:800;font-size:.9rem}
.dv-rs .h .nv+.nv{margin-left:-4px}.dv-rs .nv.a{background:#FDEDEA;color:#9A3524}.dv-rs .nv.m{background:#FBF1DC;color:#7A5410}.dv-rs .nv.b{background:#EAF5EE;color:#1F5A3B}
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
html body #rx-full{display:flex!important;align-items:center;justify-content:center;gap:8px;width:100%!important;max-width:100%!important;box-sizing:border-box!important;white-space:normal!important;text-align:center!important;line-height:1.25!important;font-size:1rem!important;padding:14px 18px!important;height:auto!important;overflow:visible!important}
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
 var ac='<a class="p dv-rec" href="/plataforma/recursos/?tipo=recurso&pontos=1'+(PG==='concorrente'&&S.cnpj?'&conc='+S.cnpj+'&titulo='+encodeURIComponent('Recurso contra '+((window.DVNOME)||S.cnpj)):'')+'">Montar o recurso com estes pontos</a>';
 if(PG==='concorrente'&&S.cnpj)ac+='<a href="/plataforma/dossie/?cnpj='+S.cnpj+'">Due Diligence completa</a>';
 var prox=A.length?'Junte as certidões e o print das fontes e prepare o recurso no prazo (3 dias úteis da intenção, art. 165, §1º, I).':M.length?'Confira as certidões apresentadas no certame e peça diligência sobre os pontos acima antes do recurso.':'Não há o que explorar aqui. Foque nos documentos da proposta e da habilitação.';
 var pts=100,comp=[];function tira(v,t){v=Math.round(v);if(v>0){pts-=v;comp.push([v,t])}}
 if(S.sit&&S.sit!=='ATIVA')tira(40,'CNPJ '+S.sit.toLowerCase());if(S.sanc.length)tira(40,'sanção registrada');
 if(S.cobranca>0)tira(25,'dívida ativa em cobrança');else if(S.cobranca<0&&S.pgfn>0)tira(8,'dívida ativa da União');else if(S.parcelada)tira(3,'dívida ativa parcelada');
 if(S.fgts)tira(15,'débito de FGTS');if(S.falen)tira(25,'falência ou recuperação judicial');tira(Math.min(15,S.trabEx*1.5),'execuções trabalhistas');
 tira(Math.min(10,S.comum*4),'empresas ligadas com contato em comum');if(S.impr)tira(10,'improbidade');if(S.crim)tira(10,'processo criminal');if(S.colet)tira(5,'ação coletiva');
 tira(Math.min(10,S.noticias*3),'notícias negativas');if(S.trab>=50)tira(5,'volume de ações trabalhistas');if(S.anos!=null&&S.anos<2)tira(5,'menos de 2 anos de atividade');
 pts=Math.max(0,pts);window.DVRS={S:S,arg:arg,pts:pts,comp:comp,inab:inab};if(!window._dvRec){window._dvRec=1;document.addEventListener('click',function(ev){var a=ev.target&&ev.target.closest?ev.target.closest('a.dv-rec'):null;if(!a||!window.DVRS)return;var R=window.DVRS,L=(R.arg||[]).map(function(x){return'- '+x[0]}).concat((R.inab||[]).map(function(x){return'- Pode ser inabilitado: '+x}));try{localStorage.setItem('dv_imp_pontos',L.length?'PONTOS FRACOS DO CONCORRENTE ENCONTRADOS NO RAIO-X (dados oficiais):\n'+L.join('\n'):'')}catch(z){}},true)}
 var ncor=pts>=80?'b':pts>=55?'m':'a';
 ac+='<a href="#" data-pdfr>PDF para anexar ao recurso</a>';
 return '<div class="h"><small>Resumo executivo</small><h3>O que importa para a decisão</h3><span class="nv '+nv[0]+'">'+nv[1]+'</span><span class="nv '+ncor+'" title="Nota de risco de 0 a 100: quanto maior, menor o risco">Nota '+pts+'/100</span></div>'+
 '<div class="g"><div class="q w"><b>Nível de atenção</b><p>'+(mot.length?e(mot.join(' · '))+'.':'Nenhum ponto relevante nas fontes consultadas.')+'</p></div>'+
 '<div class="q"><b>Pode ser inabilitada?</b><p>'+pode+'</p></div>'+
 '<div class="q"><b>Argumento mais forte para o recurso</b><p>'+forte+'</p></div>'+
 '<div class="q"><b>Tem fôlego para executar?</b><p>'+fol+'</p></div>'+
 '<div class="q"><b>Próximo passo</b><p>'+e(prox)+'</p><div class="ac">'+ac+'</div></div>'+
 '<div class="q w"><b>Nota de risco: '+pts+'/100</b><p>'+(comp.length?'Começa em 100 e desconta: '+e(comp.map(function(c){return c[1]+' (−'+c[0]+')'}).join(' · '))+'. ':'Nada a descontar nas fontes consultadas. ')+'De 80 a 100, risco baixo; de 55 a 79, moderado; abaixo de 55, alto.</p></div></div>';
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
 if(cobv)paga+=' Cobranças na Justiça (12 meses): '+e(cobv.replace(/publ\.?$/,'publicações'))+'.';
 var op=[];if(at)op.push('contratos ativos: '+at);if(item)op.push('do seu item: '+item);if(ab)op.push('licitações abertas: '+ab);
 var prev=pts!=null&&pts<55||atr>=3?'Coloque na proposta o custo de capital de giro para atrasos. Se o atraso passar de 2 meses da nota fiscal, o contratado pode suspender a execução ou pedir a extinção (art. 137, § 2º, IV, e § 3º, II).':grav||proc?'Leia os processos sobre licitações abaixo antes de participar: mostram onde o órgão costuma errar e o que impugnar.':'Nada impede a participação. Confira o edital e o preço que o órgão já pagou.';
 return '<div class="h"><small>Resumo executivo</small><h3>Vale participar?</h3><span class="nv '+nv[0]+'">'+nv[1]+'</span></div>'+
 '<div class="g"><div class="q w"><b>Nível de atenção</b><p>'+(A.concat(M).length?e(A.concat(M).join(' · '))+'.':'Nenhum sinal relevante nas fontes consultadas.')+'</p></div>'+
 '<div class="q"><b>Paga em dia?</b><p>'+paga+'</p></div>'+
 '<div class="q"><b>Tamanho da oportunidade</b><p>'+e(cap1(op.join(' · '))||'Sem contratos encontrados no PNCP')+'.</p></div>'+
 '<div class="q w"><b>Próximo passo</b><p>'+e(prev)+'</p><div class="ac"><a class="p" href="/plataforma/buscador/">Ver licitações abertas</a><a href="/plataforma/contratos-vencendo/">Contratos vencendo</a></div></div></div>';
}

function pdfRecurso(){var X=window.DVRS;if(!X)return;var r=root(),S=X.S,h2=r.querySelector('.rx-card.ink h2, .rp-hd h2, h2'),nome=h2?h2.textContent.trim():'',hoje=new Date().toLocaleDateString('pt-BR');
 var L=[],vi={};[].forEach.call(r.querySelectorAll('.pj-card a, .rd a, .rp-sec.rd a'),function(a){if(L.length>=15||!/^https?:/.test(a.href))return;var li=a.closest('li,div'),tx=String((li||a).innerText||'').slice(0,90);if(vi[tx])return;vi[tx]=1;L.push([String((li||a).innerText||'').replace(/\s+/g,' ').replace(/(ver processo.*|abrir|todas as publica.*)$/i,'').trim().slice(0,220),a.href])});
 var F=[['Receita Federal (comprovante de CNPJ)','https://solucoes.receita.fazenda.gov.br/servicos/cnpjreva/cnpjreva_solicitacao.asp'],['CEIS e CNEP (Portal da Transparência)','https://portaldatransparencia.gov.br/sancoes/consulta'],['Certidão consolidada TCU, CNJ e CGU','https://certidoes-apf.apps.tcu.gov.br/'],['Lista de devedores da PGFN','https://www.listadevedores.pgfn.gov.br/'],['CNDT (Justiça do Trabalho)','https://cndt-certidao.tst.jus.br/inicio.faces'],['Regularidade do FGTS (Caixa)','https://consulta-crf.caixa.gov.br/consultacrf/pages/consultaEmpregador.jsf'],['PNCP','https://pncp.gov.br/'],['Comunicações processuais (CNJ)','https://comunica.pje.jus.br/']];
 var fat=X.arg.map(function(a){return a[0]});if(S.cobranca===0&&S.pgfn>0)fat.push('Dívida ativa da União de '+mc(S.pgfn)+' em situação parcelada, garantida ou suspensa (base trimestral da PGFN).');
 var css='body{font-family:Georgia,serif;color:#111;max-width:760px;margin:30px auto;padding:0 20px;line-height:1.5;font-size:13pt}h1{font-size:16pt;margin:0 0 4px}h2{font-size:13pt;margin:22px 0 8px;border-bottom:1px solid #999}small{color:#555}li{margin:0 0 8px}a{color:#111}.n{font-size:10pt;color:#444;margin-top:26px;border-top:1px solid #999;padding-top:8px}';
 var H='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Fatos públicos - '+e(nome)+'</title><style>'+css+'</style></head><body><h1>Fatos públicos sobre '+e(nome)+'</h1><small>CNPJ '+e(String(S.cnpj).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5'))+' · levantamento em '+hoje+'</small>'+
  '<h2>Fatos apurados</h2><ol>'+(fat.length?fat.map(function(f){return'<li>'+e(f)+'</li>'}).join(''):'<li>Nenhum fato restritivo encontrado nas fontes consultadas.</li>')+'</ol>'+
  (L.length?'<h2>Processos citados</h2><ol>'+L.map(function(l){return'<li>'+e(l[0])+'<br><small>'+e(l[1])+'</small></li>'}).join('')+'</ol>':'')+
  '<h2>Situação cadastral e capacidade</h2><p>Situação na Receita: '+e(S.sit||'não informada')+'. '+(S.capital?'Capital social de '+mc(S.capital)+'. ':'')+(S.anos!=null?S.anos+' ano(s) de atividade. ':'')+(S.contratos?S.contratos+' contrato(s) público(s) localizado(s) no PNCP.':'')+'</p>'+
  '<h2>Fontes oficiais para conferência</h2><ul>'+F.map(function(f){return'<li>'+f[0]+': '+f[1]+'</li>'}).join('')+'</ul>'+
  '<p class="n">Levantamento de dados públicos e oficiais, na data indicada. Cada fato deve ser confirmado na fonte e instruído com a respectiva certidão ou documento. Não constitui parecer jurídico.</p></body></html>';
 var w=window.open('','_blank');if(!w){alert('Permita a abertura de janelas para gerar o PDF.');return}w.document.open();w.document.write(H);w.document.close();setTimeout(function(){w.focus();w.print()},400)}

/* Relatório completo (PDF): leva o resumo executivo, os processos, as licitações vencidas e a experiência; corrige textos antigos */
if(PG==='concorrente'){(function(){var _open=window.open;window.open=function(u){var w=_open.apply(window,arguments);if(!w||(u&&u!=='about:blank'))return w;window.DVXW=w;try{var d=w.document,_w=d.write.bind(d),_p=w.print?w.print.bind(w):null;
 d.write=function(h){try{if(/RAIO-X|Raio-X|raio-x/.test(h)&&/<html|<body|<div/i.test(h)){var x=new DOMParser().parseFromString(h,'text/html');melhora(x);h='<!doctype html>'+x.documentElement.outerHTML;if(!w._dvAuto){w._dvAuto=1;setTimeout(function(){try{w.focus();w.print()}catch(e){}},1500)}}}catch(e){}return _w(h)};
 if(_p)w.print=function(){try{melhora(w.document)}catch(e){}return _p()}}catch(e){}return w}})()}
window.DVXM=function(D){return melhora(D)};
function melhora(D){if(!D||!D.body||D.body.getAttribute('data-dvx')||!/EMPRESA ANALISADA|RAIO-X/i.test(D.body.textContent))return;D.body.setAttribute('data-dvx','1');var r=root();
 var css=[].map.call(document.querySelectorAll('#dv-rs-css,#dv-pv-css,#dv-int-css'),function(s){return s.textContent}).join('\n')+'\n@page{margin:12mm 10mm}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.dvx{margin:18px 0;break-inside:auto}.dvx,.dvx *{font-family:"Hanken Grotesk",Arial,sans-serif!important}.dvx h2{font-size:15pt;margin:0 0 8px}.dvx .pj{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 10px}.dvx .pj span{border:1px solid #E3DED2;border-radius:8px;padding:6px 10px;font-size:10pt}.dvx .pj b{font-size:12pt;margin-right:4px}.dvx ol{margin:6px 0 0 18px;padding:0}.dvx li{font-size:9.5pt;margin:0 0 6px}.dvx li small{color:#555}.dv-rs .ac,.dv-ix .bt,.dv-ix .calc,.dv-ix .c-out,.dv-pv .rv a{display:none!important}';
 var lk=D.createElement('link');lk.rel='stylesheet';lk.href='https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;600;700;800&display=swap';(D.head||D.body).appendChild(lk);
 css+='\nbody{font-family:"Hanken Grotesk",Arial,sans-serif!important;color:#16191E!important}'+
  '.hd{background:#16191E!important;border-bottom:3px solid #C9A45C!important;border-radius:10px 10px 0 0}.hd div{color:#B9BDB8!important}.hd b{color:#C9A45C!important}'+
  'h1{font:800 22pt "Hanken Grotesk",Arial,sans-serif!important;letter-spacing:-.02em;color:#16191E}'+
  'h2{font:700 13pt "Hanken Grotesk",Arial,sans-serif!important;color:#16191E!important;border-bottom:2px solid #C9A45C!important;padding-bottom:5px!important;margin-top:24px!important}'+
  '.k{color:#8A6A2E!important;letter-spacing:.14em}.g dt,.n,.ft{color:#6E6A60!important}'+
  'th{background:#16191E!important;color:#F4F2EC!important;font-weight:600!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}td{border-color:#E3DED2!important}'+
  '.pt{background:#FAF8F3!important;border-left-color:#C9A45C!important;border-radius:0 8px 8px 0}.pt.n3{background:#FDEDEA!important;border-left-color:#9A3524!important}'+
  'a{color:#8A6A2E!important}.ft{border-top:2px solid #C9A45C!important}'+
  '.bar button{font:700 11pt "Hanken Grotesk",Arial,sans-serif!important;background:#C9A45C!important;color:#241A06!important;border-radius:999px!important;padding:10px 22px!important}'+
  '.dv-rs,.dv-ix,.dv-pv{box-shadow:none!important;border:1px solid #E3DED2!important;border-radius:12px!important;padding:14px 16px!important;margin:14px 0!important;break-inside:auto!important;page-break-inside:auto!important}'+
  '.dv-rs{border-left:5px solid #C9A45C!important}.dv-rs .q,.dv-pv .n div,.dv-ix .n div{break-inside:avoid}'+
  '.dv-pv .n,.dv-ix .n{font-size:inherit!important;color:inherit!important}.dv-pv .n b,.dv-ix .n b{font-size:14pt!important}'+
  '.dv-rs .h h3,.dv-pv h3,.dv-ix h3{font-size:13pt!important}.dv-pv table,.dv-ix table{font-size:9pt!important}';
 var st=D.createElement('style');st.textContent=css;(D.head||D.body).appendChild(st);
 /* textos antigos */
 var tw=D.createTreeWalker(D.body,4),n;while(n=tw.nextNode()){var t=n.nodeValue;if(/raio-x-do-fornecedor/.test(t))n.nodeValue=t.replace(/desenvolveestrategias\.com\.br\/raio-x-do-fornecedor\/?/,'desenvolveestrategias.com.br/plataforma/raio-x');if(/raio-x de concorrentes/i.test(t))n.nodeValue=t.replace(/raio-x de concorrentes/i,'Raio-X do concorrente');if(/^\s*google\.com\s*$/.test(t))n.nodeValue='site da prefeitura'}
 [].forEach.call(D.querySelectorAll('p,div'),function(el){if(el.children.length<=2&&/^\s*Quer apoio para decidir\?/.test(el.textContent))el.remove()});
 /* diários oficiais: trecho resumido */
 [].forEach.call(D.querySelectorAll('td,p,div'),function(el){if(el.children.length===0&&el.textContent.length>700&&/CNPJ|Contrat/i.test(el.textContent)){var t=el.textContent.replace(/https?:\/\/\S+/g,'').replace(/\s+/g,' ').trim(),i=Math.max(0,t.search(/Objeto|Contrat/i)-60);el.textContent=(i?'… ':'')+t.slice(i,i+600)+'…'}});
 /* blocos novos antes de "Pontos de atenção" */
 var alvo=[].filter.call(D.querySelectorAll('h1,h2,h3,h4,p,div'),function(el){return el.children.length===0&&/^\s*Pontos de atenção\s*$/i.test(el.textContent)})[0];
 var H='',rs=document.getElementById('dv-rs');if(rs)H+='<section class="dv-rs">'+rs.innerHTML+'</section>';
 var pj=r&&r.querySelector('.pj-card');if(pj){var P=[];[].forEach.call(pj.querySelectorAll('div'),function(dv){var b=dv.querySelector(':scope > b'),sp=dv.querySelector(':scope > span');if(b&&sp&&dv.children.length===2)P.push('<span><b>'+e(b.textContent)+'</b>'+e(sp.textContent)+'</span>')});
  var dest=[],vis={};[].forEach.call(pj.querySelectorAll('a[href^="http"]'),function(a){var bx=a.closest('div');var tx=String(bx?bx.innerText:'').split('\n')[0].trim();if(!tx||vis[tx]||dest.length>=12)return;vis[tx]=1;dest.push('<li>'+e(tx.replace(/\s*·?\s*(ver processo.*|todas as publica.*|publicação)$/i,''))+'<br><small>'+e(a.href)+'</small></li>')});
  var tot=(pj.querySelector('p b')||{}).textContent||'';H+='<section class="dvx"><h2>Processos judiciais</h2><p>'+e(tot)+' com a empresa como parte (Diário de Justiça Eletrônico Nacional · CNJ).</p><div class="pj">'+P.join('')+'</div>'+(dest.length?'<p><b>Processos que merecem atenção</b></p><ol>'+dest.join('')+'</ol>':'')+'</section>'}
 ['dv-pv','dv-ic'].forEach(function(id){var x=document.getElementById(id);if(x)H+='<section class="'+x.className+'">'+x.innerHTML+'</section>'});
 if(H){var box=D.createElement('div');box.innerHTML=H;if(alvo&&alvo.parentNode)alvo.parentNode.insertBefore(box,alvo);else D.body.insertBefore(box,D.body.firstChild?D.body.firstChild.nextSibling:null)}}
document.addEventListener('click',function(ev){var a=ev.target.closest&&ev.target.closest('[data-pdfr]');if(!a)return;ev.preventDefault();pdfRecurso()});
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
 var r0=root(),h2=r0&&r0.querySelector('.rx-card.ink h2, .rp-hd h2'),nm=h2?h2.textContent.trim():'',_n=(window.DVNOME=nm),cn=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'');if(!cn){var ii=document.querySelector('.dv-tool input');cn=ii?String(ii.value).replace(/\D/g,''):''}
 if(nm)h='<div class="id"><b>'+e(nm)+'</b>'+(cn.length===14?'<span>CNPJ '+cn.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')+'</span>':'')+'</div>'+h;
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
