/* Desenvolve — "Analisar com IA": leva o relatório + o roteiro da Desenvolve para a IA do próprio cliente */
(function(){
var tool=document.querySelector('.dv-tool');if(!tool||!window.DV||!DV.logado)return;
var dossie=!!document.getElementById('ds');
var IAS=[['ChatGPT','https://chatgpt.com/','https://chatgpt.com/?q='],['Claude','https://claude.ai/new','https://claude.ai/new?q='],['Gemini','https://gemini.google.com/app',''],['DeepSeek','https://chat.deepseek.com/',''],['Copilot','https://copilot.microsoft.com/','']];
function cnpj(){var m=location.search.match(/cnpj=(\d{14})/);if(m)return m[1];var i=tool.querySelector('input');var d=i?String(i.value).replace(/\D/g,''):'';return d.length===14?d:''}
function relatorio(){var base=dossie?tool.querySelector('.rp'):tool;if(!base)return'';var c=base.cloneNode(true);c.querySelectorAll('.dv-ia,script,style,form,button,.rp-bar').forEach(function(x){x.remove()});return c.innerText.replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').trim()}
function texto(){return(window.DVIA||'Analise o relatório abaixo e aponte os riscos.')+'\n\n=====\nRELATÓRIO '+(dossie?'(Due Diligence completa)':'(Raio-X do fornecedor)')+' — CNPJ '+cnpj()+' — gerado pela plataforma Desenvolve em '+new Date().toLocaleDateString('pt-BR')+'\n=====\n\n'+relatorio()}
function copiaSync(t){var a=document.createElement('textarea');a.value=t;a.setAttribute('readonly','');a.style.cssText='position:fixed;top:0;left:0;opacity:0';document.body.appendChild(a);a.select();var ok=false;try{ok=document.execCommand('copy')}catch(x){}a.remove();return ok}
function copiar(t){if(navigator.clipboard&&window.isSecureContext)return navigator.clipboard.writeText(t);return new Promise(function(ok,no){var a=document.createElement('textarea');a.value=t;a.style.position='fixed';a.style.opacity='0';document.body.appendChild(a);a.select();try{document.execCommand('copy');ok()}catch(x){no(x)}a.remove()})}
function baixar(t){var b=new Blob([t],{type:'text/plain;charset=utf-8'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='desenvolve-'+(dossie?'due-diligence':'raio-x')+'-'+cnpj()+'.txt';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)}
function card(){var c=document.createElement('div');c.className='dv-ia';c.style.cssText='background:#fff;border:1px solid #E3DED2;border-left:4px solid #0C0F13;border-radius:8px;padding:20px 24px;margin:18px 0;color:#16191E;font-family:inherit';
 var bt='background:#0C0F13;color:#F4F2EC;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer;margin:4px 6px 4px 0';
 c.innerHTML='<div style="color:#A8843F;font-weight:700;letter-spacing:.14em;font-size:.78rem;text-transform:uppercase">Analisar com a sua IA</div><div style="color:#6E6A60;font-size:.9rem;margin:6px 0 12px">Leve este relatório, com o roteiro de análise de risco da Desenvolve, para a inteligência artificial que você já usa. Copiamos tudo e abrimos a IA escolhida: é só colar (Ctrl+V ou ⌘+V) e enviar. Funciona nas versões gratuitas.</div><div>'+IAS.map(function(x,i){return'<button type="button" data-i="'+i+'" style="'+bt+'">'+x[0]+'</button>'}).join('')+'<button type="button" data-c style="'+bt+';background:#fff;color:#16191E;border:1px solid #E3DED2">Só copiar</button><button type="button" data-d style="'+bt+';background:#fff;color:#16191E;border:1px solid #E3DED2">Baixar arquivo</button></div><p class="dv-ia-m" style="margin:10px 0 0;font-weight:600"></p><p style="color:#6E6A60;font-size:.78rem;margin:8px 0 0">O relatório vai para a conta de IA que você escolher e segue as regras de privacidade dela. Se a IA não aceitar um texto tão longo, use "Baixar arquivo" e anexe o arquivo na conversa.</p>';
 var m=c.querySelector('.dv-ia-m');
 c.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b)return;if(!cnpj()){m.textContent='Consulte um CNPJ primeiro.';return}var t=texto();
  if(b.hasAttribute('data-d')){baixar(t);m.textContent='Arquivo baixado. Anexe na conversa com a sua IA.';return}
  var ia=b.hasAttribute('data-i')?IAS[+b.dataset.i]:null,ok=copiaSync(t);
  if(!ok&&navigator.clipboard){navigator.clipboard.writeText(t).then(function(){},function(){})}
  if(ia){var url=(ia[2]&&t.length<5500)?ia[2]+encodeURIComponent(t):ia[1];window.open(url,'_blank','noopener')}
  m.style.color='#2F7A55';m.textContent=ia?(ia[2]&&t.length<5500?'Abrimos o '+ia[0]+' com o relatório. Confira e envie.':'Copiado! Na aba do '+ia[0]+', cole (Ctrl+V ou ⌘+V) e envie.'):'Copiado! Cole na sua IA (Ctrl+V ou ⌘+V).';
  if(!ok&&!navigator.clipboard){m.style.color='#B04A3A';m.textContent='Não conseguimos copiar automaticamente. Use "Baixar arquivo".'}});
 return c}
function ancora(){if(dossie){var r=tool.querySelector('.rp');return r?r.querySelector('.rp-sec'):null}
 var w=document.createTreeWalker(tool,NodeFilter.SHOW_TEXT,null),n;while((n=w.nextNode())){if(/^\s*pontos de aten[cç][aã]o\s*$/i.test(n.nodeValue))return n.parentElement.parentElement}return null}
var ult='';function checar(){var a=ancora();if(!a)return;var cn=cnpj(),ex=tool.querySelector('.dv-ia');if(ex&&ult===cn)return;if(ex)ex.remove();ult=cn;a.insertAdjacentElement(dossie?'beforebegin':'afterend',card())}
var t=null;new MutationObserver(function(){clearTimeout(t);t=setTimeout(checar,300)}).observe(tool,{childList:true,subtree:true});
})();
