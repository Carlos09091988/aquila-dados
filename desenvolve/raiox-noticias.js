/* Desenvolve — Raio-X: seção "Notícias negativas na imprensa" (complemento independente do relatório) */
(function(){
var tool=document.querySelector('.dv-tool');if(!tool)return;
var API='/wp-json/dvtools/v1/fornecedor/noticias?cnpj=',atual='',dados=null,carregando='';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.split('-').reverse().join('/'):''}
function cnpjAtual(){var m=location.search.match(/[?&]cnpj=(\d{14})/);if(m)return m[1];var i=tool.querySelector('input');var d=i?String(i.value).replace(/\D/g,''):'';return d.length===14?d:''}
function ancora(){var w=document.createTreeWalker(tool,NodeFilter.SHOW_TEXT,null),n;while((n=w.nextNode())){if(/^\s*pontos de aten[cç][aã]o\s*$/i.test(n.nodeValue))return n.parentElement.parentElement}return null}
function temResultado(){return !!ancora()}
function mencoes(d){var m=d.mencoes||[];if(!m.length)return'';return'<div style="margin-top:14px;font-weight:700;font-size:.92rem">Outras menções na imprensa ('+m.length+')</div>'+m.map(function(i){return'<div style="border-top:1px solid #EEE9DE;padding:8px 0"><div style="font-size:.8rem;color:#6E6A60">'+br(i.data)+' · '+e(i.fonte||'')+'</div><a href="'+e(i.url)+'" target="_blank" rel="noopener" style="color:#16191E;text-decoration:none">'+e(i.titulo)+'</a></div>'}).join('')}
function html(d,pdf){
 var H='<div class="'+(pdf?'':'nt-card ')+'nt" style="background:#fff;border:1px solid #E3DED2;border-left:4px solid #A8843F;border-radius:6px;padding:22px 26px;margin:18px 0;font-family:inherit">';
 H+='<div style="color:#A8843F;font-weight:700;letter-spacing:.14em;font-size:.78rem;text-transform:uppercase">Na imprensa</div>';
 if(!d){return H+'<p style="margin:10px 0 0;color:#6E6A60">Buscando notícias…</p></div>'}
 if(d.aviso&&!d.itens.length){return H+'<p style="margin:10px 0 0;color:#6E6A60">'+e(d.aviso)+'</p></div>'}
 if(!d.itens.length){return H+'<p style="margin:10px 0 0">Nenhuma notícia negativa encontrada nos últimos 6 anos para '+e(d.termos.join(' / '))+'.</p>'+mencoes(d)+'<p style="margin:6px 0 0;color:#6E6A60;font-size:.85rem">Busca automática na imprensa (Google Notícias) por palavras de risco: investigação, Ministério Público, fraude, Tribunal de Contas, problemas na execução de contratos, recuperação judicial e trabalho.</p></div>'}
 var cont={};d.itens.forEach(function(i){i.riscos.forEach(function(r){cont[r]=(cont[r]||0)+1})});
 var um=d.itens.filter(function(i){return new Date(i.data)>new Date(Date.now()-365*864e5)}).length;
 H+='<p style="margin:10px 0 4px;font-size:1.05rem"><b>'+d.itens.length+' notícia(s)</b> com termos de risco nos últimos 6 anos'+(um?', <b>'+um+'</b> nos últimos 12 meses':'')+'.</p>';
 H+='<p style="margin:0 0 12px;color:#6E6A60;font-size:.88rem">'+Object.keys(cont).map(function(k){return e(k)+' ('+cont[k]+')'}).join(' · ')+'</p>';
 H+=d.itens.map(function(i){return'<div style="border-top:1px solid #EEE9DE;padding:10px 0"><div style="font-size:.8rem;color:#6E6A60">'+br(i.data)+' · '+e(i.fonte||'')+' · '+i.riscos.map(e).join(', ')+'</div><a href="'+e(i.url)+'" target="_blank" rel="noopener" style="color:#16191E;font-weight:600;text-decoration:none">'+e(i.titulo)+'</a></div>'}).join('');
 H+=mencoes(d);H+='<p style="margin:12px 0 0;color:#6E6A60;font-size:.8rem">Notícias localizadas automaticamente pelo nome '+e(d.termos.join(' / '))+'. Podem tratar de outra empresa com nome parecido: abra a matéria e confira. A Desenvolve não afirma a veracidade do que foi publicado.</p></div>';
 return H}
function pintar(){var a=ancora();if(!a)return;var c=tool.querySelector('.nt-card');if(c&&c.getAttribute('data-c')===atual&&c.getAttribute('data-ok')===(dados?'1':'0'))return;if(c)c.remove();
 a.insertAdjacentHTML('afterend',html(dados,false));var n=tool.querySelector('.nt-card');n.setAttribute('data-c',atual);n.setAttribute('data-ok',dados?'1':'0')}
function checar(){if(!temResultado())return;var c=cnpjAtual();if(!c)return;
 if(c!==atual){atual=c;dados=null}
 pintar();
 if(!dados&&carregando!==c){carregando=c;fetch(API+c,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(atual!==c)return;dados=j&&j.itens?j:{itens:[],termos:[],aviso:(j&&j.message)||'Não foi possível buscar notícias agora.'};carregando='';pintar()}).catch(function(){carregando='';dados={itens:[],termos:[],aviso:'Não foi possível buscar notícias agora.'};pintar()})}}
var t=null;new MutationObserver(function(){clearTimeout(t);t=setTimeout(checar,250)}).observe(tool,{childList:true,subtree:true});
/* Leva a seção também para o relatório em PDF (janela de impressão do Raio-X) */
var abrir=window.open;window.open=function(){var w=abrir.apply(window,arguments);if(w&&dados){var k=0,iv=setInterval(function(){k++;try{var d=w.document,ft=d&&d.querySelector('.ft');if(ft&&!d.querySelector('.nt')){ft.insertAdjacentHTML('beforebegin',html(dados,true));clearInterval(iv)}}catch(x){clearInterval(iv)}if(k>40)clearInterval(iv)},150)}return w};
})();
