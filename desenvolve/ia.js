/* Desenvolve — botão "Análise de risco com IA" (Raio-X e Due Diligence) */
(function(){
var tool=document.querySelector('.dv-tool');if(!tool||!window.DV||!DV.logado)return;
var dossie=!!document.getElementById('ds');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function md(t){var L=e(t).split('\n'),H='',lista=null;
 function fecha(){if(lista){H+='</'+lista+'>';lista=null}}
 L.forEach(function(l){var x=l.trim();x=x.replace(/\*\*(.+?)\*\*/g,'<b>$1</b>');
  if(/^#{1,3} /.test(x)){fecha();H+='<h4 style="margin:18px 0 6px;font-size:1rem;color:#16191E">'+x.replace(/^#{1,3} /,'')+'</h4>'}
  else if(/^[-*] /.test(x)){if(lista!=='ul'){fecha();H+='<ul style="margin:4px 0 8px 18px;padding:0">';lista='ul'}H+='<li style="margin:3px 0">'+x.slice(2)+'</li>'}
  else if(/^\d+[.)] /.test(x)){if(lista!=='ol'){fecha();H+='<ol style="margin:4px 0 8px 18px;padding:0">';lista='ol'}H+='<li style="margin:3px 0">'+x.replace(/^\d+[.)] /,'')+'</li>'}
  else if(!x){fecha()}else{fecha();H+='<p style="margin:6px 0">'+x+'</p>'}});
 fecha();return H}
function cnpj(){var m=location.search.match(/cnpj=(\d{14})/);if(m)return m[1];var i=tool.querySelector('input');var d=i?String(i.value).replace(/\D/g,''):'';return d.length===14?d:''}
function conteudo(){if(dossie&&window.DVDS){var d=JSON.parse(JSON.stringify(window.DVDS));delete d.saldo;delete d.meta;return JSON.stringify(d)}
 var c=tool.cloneNode(true);c.querySelectorAll('.dv-ia,script,style,form').forEach(function(x){x.remove()});return c.innerText.replace(/\n{3,}/g,'\n\n')}
function card(){var c=document.createElement('div');c.className='dv-ia';c.style.cssText='background:#fff;border:1px solid #E3DED2;border-left:4px solid #0C0F13;border-radius:8px;padding:20px 24px;margin:18px 0;color:#16191E;font-family:inherit';
 c.innerHTML='<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap"><div><div style="color:#A8843F;font-weight:700;letter-spacing:.14em;font-size:.78rem;text-transform:uppercase">Análise de risco com IA</div><div style="color:#6E6A60;font-size:.88rem;margin-top:4px">A IA lê todo o relatório e entrega conclusão, riscos, pontos de habilitação e o que verificar. Usa '+(DV.cr_ia||2)+' créditos.</div></div><button type="button" style="background:#0C0F13;color:#F4F2EC;border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer">Gerar análise</button></div><div class="dv-ia-out"></div>';
 c.querySelector('button').onclick=function(){var b=this,o=c.querySelector('.dv-ia-out'),cn=cnpj();if(!cn){o.innerHTML='<p style="color:#B04A3A">Consulte um CNPJ primeiro.</p>';return}
  b.disabled=true;b.textContent='Analisando…';o.innerHTML='<p style="color:#6E6A60;margin-top:14px">A IA está lendo o relatório. Leva de 20 a 60 segundos.</p>';
  fetch(DV.api+'ia',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':DV.nonce,'Content-Type':'application/json'},body:JSON.stringify({tipo:dossie?'dossie':'raiox',cnpj:cn,conteudo:conteudo()})}).then(function(r){return r.json().then(function(j){return{s:r.status,j:j}})}).then(function(x){b.disabled=false;b.textContent='Gerar de novo';
   if(x.s>=400){o.innerHTML='<p style="color:#B04A3A;margin-top:14px">'+e(x.j.message||'Erro.')+(x.s===402?' <a href="'+(DV.acesso?'/creditos/':'/assinar/')+'">'+(DV.acesso?'Comprar créditos':'Assinar')+'</a>':'')+'</p>';return}
   o.innerHTML='<div style="margin-top:12px;border-top:1px solid #EEE9DE;padding-top:8px;font-size:.95rem;line-height:1.55">'+md(x.j.analise)+'</div><p style="color:#6E6A60;font-size:.78rem;margin-top:12px">Análise gerada por inteligência artificial a partir dos dados do relatório. Pode conter erros e não substitui parecer profissional.</p>';
   var s=document.getElementById('dv-saldo');if(s&&x.j.saldo&&!DV.master)s.textContent='Créditos: '+x.j.saldo.total;
   var k=document.querySelector('.dv-cons');if(k)k.classList.add('on')}).catch(function(){b.disabled=false;b.textContent='Gerar análise';o.innerHTML='<p style="color:#B04A3A;margin-top:14px">Não foi possível falar com a IA agora.</p>'})};
 return c}
function ancora(){if(dossie){var r=tool.querySelector('.rp');return r?r.querySelector('.rp-sec'):null}
 var w=document.createTreeWalker(tool,NodeFilter.SHOW_TEXT,null),n;while((n=w.nextNode())){if(/^\s*pontos de aten[cç][aã]o\s*$/i.test(n.nodeValue))return n.parentElement.parentElement}return null}
var ult='';function checar(){var a=ancora();if(!a)return;var cn=cnpj();var ex=tool.querySelector('.dv-ia');if(ex&&ult===cn&&ex.isConnected)return;if(ex)ex.remove();ult=cn;a.insertAdjacentElement(dossie?'beforebegin':'afterend',card())}
var t=null;new MutationObserver(function(){clearTimeout(t);t=setTimeout(checar,300)}).observe(tool,{childList:true,subtree:true});
})();
