var DADOS='https://carlos09091988.github.io/aquila-dados/',DMETA=null;
function gzJ(u){return fetch(u,{cache:'force-cache'}).then(function(r){if(r.status===404)return{};if(!r.ok)throw new Error('http '+r.status);
 if(typeof DecompressionStream==='undefined')throw new Error('navegador sem suporte');
 return new Response(r.body.pipeThrough(new DecompressionStream('gzip'))).text().then(function(t){return JSON.parse(t)})})}
function sha(s){return crypto.subtle.digest('SHA-256',new TextEncoder().encode(s)).then(function(b){return Array.from(new Uint8Array(b)).map(function(x){return x.toString(16).padStart(2,'0')}).join('')})}
function nrm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase().replace(/\s+/g,' ').trim()}
function dvC(b){var c=b.split('').map(Number),w=[5,4,3,2,9,8,7,6,5,4,3,2];var s=0;for(var i=0;i<12;i++)s+=c[i]*w[i];var d1=s%11<2?0:11-s%11;c.push(d1);w.unshift(6);s=0;for(i=0;i<13;i++)s+=c[i]*w[i];var d2=s%11<2?0:11-s%11;return b+d1+d2}
function fData8(d){d=String(d||'');return d.length===8?d.slice(6,8)+'/'+d.slice(4,6)+'/'+d.slice(0,4):''}
function pagoIni(cnpj){var box=$('#rx-pago');if(!box)return;
 box.innerHTML=card('rx-pg','Dívida ativa e grupo econômico',skel());
 var c=(S.base&&S.base.cadastro)||{},q=c.qsa||[];
 var chaves=q.map(function(x){var d=String(x.doc||'').replace(/\D/g,'');return{k:d.length===14?'PJ|'+d:nrm(x.nome)+'|'+d,nome:x.nome}});chaves.push({k:'PJ|'+cnpj,nome:'',propria:true});
 var meta=DMETA?Promise.resolve(DMETA):fetch(DADOS+'meta.json',{cache:'no-cache'}).then(function(r){return r.ok?r.json():{}}).catch(function(){return{}});
 var pg=gzJ(DADOS+'pgfn/'+cnpj.slice(0,3)+'.json.gz').then(function(o){var r=o[cnpj];if(!r)return{consta:false};
  var nat=[['Previdenciária',r[1]],['Não previdenciária (tributos e multas)',r[2]],['FGTS',r[3]]].filter(function(x){return x[1]>0}).map(function(x){return{descricao:x[0],total:x[1]}});
  return{consta:true,total:r[0],cobranca:r[4],qtd:r[5],ajuiz:r[6],tipos:r[7],naturezas:nat}}).catch(function(e){return{erro:'Índice da PGFN indisponível agora.'}});
 var vs=Promise.all(chaves.map(function(ch){return sha(ch.k).then(function(h){return gzJ(DADOS+'socios/'+h.slice(0,3)+'.json.gz')}).then(function(o){return{ch:ch,l:o[ch.k]||[]}})})).then(function(res){
  var raiz=cnpj.slice(0,8),emp={};
  res.forEach(function(x){x.l.forEach(function(e){if(e[0]===raiz)return;var k=e[0];emp[k]=emp[k]||{doc:dvC(k+'0001'),pj:true,nome:e[1]||('CNPJ raiz '+k),via:[],hist:[],ativo:true};
   var via=x.ch.propria?'a própria empresa é sócia ('+e[2]+')':x.ch.nome;if(emp[k].via.indexOf(via)<0)emp[k].via.push(via);
   if(!x.ch.propria)emp[k].hist.push({cargo:e[2]+' · '+x.ch.nome,inicio:fData8(e[3])})})});
  return{itens:Object.keys(emp).map(function(k){return emp[k]})}}).catch(function(e){return{erro:'Índice de sócios indisponível agora.'}});
 Promise.all([pg,vs,meta]).then(function(a){DMETA=a[2];S.pago={pgfn:a[0],vinculos:a[1],meta:a[2]};box.innerHTML=card('rx-pg','Dívida ativa e grupo econômico',pagoHtml(S.pago));pontos()})}
function pagoHtml(d){var h='',p=d.pgfn||{},v=d.vinculos||{},m=d.meta||{},mp=(m.pgfn||{}).referencia,ms=(m.socios||{}).referencia;
 h+='<p style="font:600 17px/1.3 Georgia,serif;margin:6px 0 6px">Dívida ativa da União e FGTS (PGFN)</p>';
 if(p.erro)h+='<p class="rx-err">'+esc(p.erro)+'</p>';
 else if(!p.consta)h+='<p class="rx-ok">Não consta na base de devedores da PGFN'+(mp?' ('+esc(mp)+')':'')+'.</p>';
 else h+='<p class="rx-count"><span class="rx-bad">'+fV(p.total)+'</span> inscritos em dívida ativa · '+p.qtd+' inscriç'+(p.qtd===1?'ão':'ões')+(p.cobranca>0?' · '+fV(p.cobranca)+' em cobrança':' · nenhuma em cobrança (parcelada, garantida ou suspensa)')+(p.ajuiz?' · '+p.ajuiz+' ajuizada'+(p.ajuiz>1?'s':''):'')+(/CORRESP|SOLID/.test(p.tipos||'')?' · como corresponsável ou solidária':'')+'</p><ul class="rx-list">'+(p.naturezas||[]).map(function(n){return'<li><div><h3>'+esc(n.descricao)+'</h3></div><div class="side"><span>Total<b>'+fV(n.total)+'</b></span></div></li>'}).join('')+'</ul>';
 h+='<p class="rx-note">Base trimestral da PGFN'+(mp?' ('+esc(mp)+')':'')+'. Para a situação de hoje, consulte o <a href="https://www.dividaaberta.pgfn.gov.br/consultar-devedores" target="_blank" rel="noopener">Dívida Aberta</a> e emita a CND federal.</p>';
 h+='<p style="font:600 17px/1.3 Georgia,serif;margin:22px 0 6px">Grupo econômico: empresas ligadas pelos sócios</p>';
 if(v.erro)h+='<p class="rx-err">'+esc(v.erro)+'</p>';
 else{var pj=v.itens||[];
  if(!pj.length)h+='<p class="rx-ok">Nenhuma outra empresa ligada aos sócios desta empresa'+(ms?' (cadastro da Receita de '+esc(ms)+')':'')+'.</p>';
  else h+='<p class="rx-count">'+pj.length+' empresa'+(pj.length>1?'s':'')+' ligada'+(pj.length>1?'s':'')+' pelos mesmos sócios</p><ul class="rx-list">'+pj.slice(0,60).map(function(x){return'<li><div><h3>'+esc(x.nome)+'</h3><p>CNPJ '+mask(x.doc)+' (matriz)</p>'+(x.via.length?'<p>Ligação: '+esc(x.via.join('; '))+'</p>':'')+(x.hist.length?'<p>'+esc(x.hist.map(function(y){return y.cargo+(y.inicio?' desde '+y.inicio:'')}).join(' · '))+'</p>':'')+'</div><div class="side"><a href="/raio-x-do-fornecedor/?cnpj='+x.doc+'" target="_blank" rel="noopener">Raio-X</a><a href="/comparador-de-licitantes/?cnpjs='+(S.base&&S.base.cnpj||'')+','+x.doc+'" target="_blank" rel="noopener">Comparar</a></div></li>'}).join('')+'</ul>'+(pj.length>60?'<p class="rx-note">Mostrando 60 de '+pj.length+'. A lista completa sai no relatório em PDF.</p>':'')}
 h+='<p class="rx-note">Ligação pelo nome do sócio e pelos dígitos visíveis do CPF, como a Receita publica; homônimos são raros, mas possíveis. Cadastro da Receita'+(ms?' de '+esc(ms):'')+'. Fontes: dados abertos da PGFN e da Receita Federal.</p>';
 return h}
function pagoPontos(add){var p=S.pago&&S.pago.pgfn;if(p&&p.consta&&Number(p.total)>0){if(p.cobranca>0)add(2,'Inscrita em dívida ativa da União: '+fV(p.cobranca)+' em cobrança','Consta na base de devedores da PGFN. Sem parcelamento, garantia ou suspensão, a certidão federal sai positiva e a empresa não comprova a regularidade fiscal exigida na habilitação (Lei 14.133, art. 68, III). Confira a situação atual no Dívida Aberta.');
  else add(1,'Dívida ativa da União parcelada, garantida ou suspensa: '+fV(p.total),'Nessas situações a certidão federal costuma sair positiva com efeito de negativa, o que vale para a habilitação. Confira a certidão atual.')}
 var v=S.pago&&S.pago.vinculos;if(v&&v.itens&&v.itens.length>=5)add(1,'Grupo econômico com '+v.itens.length+' empresas ligadas','Os sócios têm participação em outras empresas. Vale verificar se alguma delas disputa as mesmas licitações (use o Comparador de licitantes).')}
function pagoRel(d){if(!d)return'';var p=d.pgfn||{},v=d.vinculos||{},m=d.meta||{},h='<h2>Dívida ativa e grupo econômico</h2>';
 if(p.consta)h+='<p><b>Dívida ativa da União e FGTS:</b> '+fV(p.total)+' inscritos ('+p.qtd+' inscrições'+(p.cobranca>0?'; '+fV(p.cobranca)+' em cobrança':'; nenhuma em cobrança')+').</p>'+((p.naturezas||[]).length?'<table><tr><th>Natureza</th><th>Total</th></tr>'+p.naturezas.map(function(n){return'<tr><td>'+esc(n.descricao)+'</td><td class="v">'+fV(n.total)+'</td></tr>'}).join('')+'</table>':'');
 else if(p.consta===false)h+='<p><b>Dívida ativa da União e FGTS:</b> não consta na base de devedores da PGFN.</p>';
 var pj=v.itens||[];
 if(v.itens)h+=pj.length?'<p><b>Empresas ligadas pelos sócios:</b> '+pj.length+'</p><table><tr><th>Empresa</th><th>CNPJ (matriz)</th><th>Ligação</th></tr>'+pj.map(function(x){return'<tr><td>'+esc(x.nome)+'</td><td>'+mask(x.doc)+'</td><td>'+esc(x.via.join('; '))+'</td></tr>'}).join('')+'</table>':'<p><b>Empresas ligadas pelos sócios:</b> nenhuma encontrada.</p>';
 return h+'<p class="n">Fontes: dados abertos da PGFN ('+esc((m.pgfn||{}).referencia||'')+') e da Receita Federal (cadastro de '+esc((m.socios||{}).referencia||'')+').</p>'}
