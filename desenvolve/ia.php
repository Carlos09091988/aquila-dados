/**
 * Desenvolve — Analisar com IA (Raio-X e Due Diligence)
 * O assinante escolhe a IA dele (ChatGPT, Claude, Gemini, DeepSeek, Copilot...). O site copia o roteiro de
 * análise da Desenvolve + o relatório e abre a IA escolhida; o cliente só cola (Ctrl+V). Nenhuma IA nossa é usada.
 * Roteiro editável em Desenvolve > Análise com IA.
 */

function dv_ia_cfg( $k ) {
	$d = array(
		'prompt'   => "Você é analista sênior de licitações e due diligence, especialista em empresas que disputam e executam contratos públicos.\n\nQuem está pedindo esta análise normalmente é um CONCORRENTE da empresa do relatório (para decidir se vale recorrer, impugnar ou pedir diligência), ou um órgão/empresa que pensa em contratá-la ou se associar a ela. Responda pensando nas duas situações.\n\nAnalise SOMENTE os dados do relatório abaixo (bases públicas oficiais e imprensa). Não invente fatos, valores, processos, artigos ou nomes. Quando algo não constar, diga que não consta. Indique de qual parte do relatório veio cada evidência.\n\nResponda em português do Brasil, em Markdown, nesta ordem:\n\n## Resposta direta\nEm até 6 linhas, responda objetivamente:\n1. Esta empresa tem condições de ganhar e de manter contratos públicos hoje? (Sim / Sim, com ressalvas / Duvidoso / Não) e por quê.\n2. Há fundamento concreto para questioná-la numa licitação (recurso, impugnação, contrarrazões ou pedido de diligência)? (Forte / Moderado / Fraco) e qual o argumento principal.\n3. Nível de risco geral: Baixo, Moderado, Alto ou Crítico.\n\n## Como usar isto no seu recurso ou impugnação\nPara cada argumento aproveitável, do mais forte ao mais fraco:\n- **Fato**: o que consta no relatório (com a fonte).\n- **Por que importa**: o requisito afetado na Lei 14.133/2021 — habilitação jurídica (art. 66), fiscal, social e trabalhista (art. 68), qualificação econômico-financeira (art. 69: capital ou patrimônio líquido mínimo, índices, certidão de falência/recuperação), qualificação técnica (art. 67), impedimentos de participar (art. 14), sanções vigentes (art. 156), inexequibilidade (art. 59), diligências (art. 64) e indícios de atuação coordenada entre licitantes do mesmo grupo.\n- **O que pedir**: diligência, apresentação de certidão ou balanço, desclassificação/inabilitação, ou comunicação ao órgão de controle.\n- **Prova a juntar**: qual documento oficial obter para comprovar (certidão, consulta, publicação no diário, processo).\nSe não houver argumento útil, diga claramente que o relatório não sustenta um questionamento.\n\n## Capacidade de ganhar e executar contratos\nCarteira atual (contratos vigentes, valores, órgãos e estados), porte e capital social diante dos contratos, dívidas, execuções trabalhistas, penalidades, rescisões, paralisações e notícias. Diga se a empresa parece sobrecarregada ou financeiramente frágil.\n\n## Principais riscos\nAté 7 itens, do mais grave ao menos grave, com evidência e impacto prático.\n\n## Grupo econômico e vínculos\nO que vínculos societários, de contato, solidários e possíveis parentes podem indicar (sucessão de empresas, interposta pessoa, atuação coordenada em licitações). Sempre como indício, nunca como prova.\n\n## O que verificar antes de agir\nChecklist objetivo de documentos e consultas complementares, com prazo de validade das certidões.\n\n## Limites desta análise\nO que as bases não cobrem (processos em segredo de justiça, diários estaduais ausentes, homônimos, defasagem das bases).\n\nRegras: não afirme culpa nem crime; use \"consta\", \"há registro\", \"indício\". Não chame a Lei 14.133/2021 de \"nova lei\". Isto não é parecer jurídico: recomende revisão profissional antes de protocolar recurso ou impugnação e recomende a revisão por um consultor especializado.",
	);
	$v = get_option( 'dv_ia_' . $k, null );
	return ( $v === null || $v === '' ) ? $d[ $k ] : ( $k === 'prompt' ? str_replace( array( ' da Desenvolve Estratégias, consultoria para empresas que disputam e executam contratos públicos.', ' da Desenvolve Estratégias', ' e mencione que um consultor da Desenvolve pode ajudar a estruturar o pedido.', ' Lembre que um consultor da Desenvolve pode revisar a análise e redigir a impugnação ou o recurso.', 'um consultor da Desenvolve', 'da Desenvolve', 'Desenvolve' ), array( ', especialista em empresas que disputam e executam contratos públicos.', '', ' e recomende a revisão por um consultor especializado.', ' Ao final, recomende a revisão por um consultor especializado antes de impugnar ou recorrer.', 'um consultor especializado', '', 'especialista' ), $v ) : $v );
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Análise com IA', 'Análise com IA', 'manage_options', 'dv-ia', function () {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_ia' ) ) {
			update_option( 'dv_ia_prompt', sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), false );
			echo '<div class="updated"><p>Salvo.</p></div>';
		}
		echo '<div class="wrap"><h1>Análise de risco com IA</h1><p>O botão aparece no Raio-X e na Due Diligence. O cliente escolhe a IA dele; o site copia este roteiro junto com o relatório e abre a IA para ele colar. Deixe o roteiro em branco para voltar ao padrão.</p><form method="post">';
		wp_nonce_field( 'dv_ia' );
		echo '<table class="form-table">';
		echo '<tr><th>Roteiro de análise (prompt)</th><td><textarea name="prompt" rows="26" class="large-text code">' . esc_textarea( dv_ia_cfg( 'prompt' ) ) . '</textarea></td></tr></table>';
		submit_button( 'Salvar' ); echo '</form></div>';
	} );
}, 21 );

/* entrega o roteiro para a página das ferramentas */
add_action( 'wp_footer', function () {
	global $post; if ( ! is_user_logged_in() || ! $post || ! has_shortcode( $post->post_content, 'dv_ctx' ) ) { return; }
	echo '<script>window.DVIA=' . wp_json_encode( dv_ia_cfg( 'prompt' ) ) . ';</script>';
}, 5 );

/* O texto levado à IA do cliente não cita a marca: troca a linha "gerado pela plataforma Desenvolve" por "consultado em" */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; }
	echo '<script>(function(){var R=/ — gerado pela plataforma Desenvolve em /g,S=" — consultado em ";function f(t){return typeof t==="string"?t.replace(R,S).replace(/gerado%20pela%20plataforma%20Desenvolve%20em/g,"consultado%20em").replace(/gerado\+pela\+plataforma\+Desenvolve\+em/g,"consultado+em"):t}
if(navigator.clipboard&&navigator.clipboard.writeText){var w=navigator.clipboard.writeText.bind(navigator.clipboard);navigator.clipboard.writeText=function(t){return w(f(t))}}
var o=window.open;window.open=function(u){var a=[].slice.call(arguments);if(typeof u==="string"){try{a[0]=f(decodeURIComponent(u))===decodeURIComponent(u)?u:u.split("?")[0]+"?"+u.split("?").slice(1).join("?").split("&").map(function(p){var k=p.split("=");return k[0]+(k.length>1?"="+encodeURIComponent(f(decodeURIComponent(k.slice(1).join("=").replace(/\+/g," ")))):"")}).join("&")}catch(e){}}return o.apply(window,a)};
document.addEventListener("copy",function(){},true);document.querySelectorAll("textarea").forEach(function(t){if(R.test(t.value)){t.value=f(t.value)}R.lastIndex=0})})();</script>';
}, 1 );

/* "Analisar com a sua IA" no Raio-X e na Due Diligence: separa as IAs que recebem o relatório completo direto das que precisam colar; copia antes de abrir a IA */
add_action( 'wp_footer', function () {
	global $post; if ( ! is_user_logged_in() || ! $post || ! has_shortcode( $post->post_content, 'dv_ctx' ) ) { return; } ?>
<script>(function(){var tool=document.querySelector('.dv-tool');if(!tool)return;var dossie=!!document.getElementById('ds');
var IAS=[['ChatGPT','https://chatgpt.com/','https://chatgpt.com/?q=','chatgpt.com',7500],['Claude','https://claude.ai/new','https://claude.ai/new?q=','claude.ai',30000],['Perplexity','https://www.perplexity.ai/','https://www.perplexity.ai/search?q=','perplexity.ai',7500],['Grok','https://grok.com/','https://grok.com/?q=','grok.com',7500],['DeepSeek','https://chat.deepseek.com/','','deepseek.com',0]];
function cnpj(){var m=location.search.match(/cnpj=(\d{14})/);if(m)return m[1];var i=tool.querySelector('input');var d=i?String(i.value).replace(/\D/g,''):'';return d.length===14?d:''}
function relatorio(){var base=dossie?tool.querySelector('.rp'):tool;if(!base)return'';var c=base.cloneNode(true);c.querySelectorAll('.dv-ia,script,style,form,button,.rp-bar').forEach(function(x){x.remove()});return c.innerText.replace(/[ \t]+\n/g,'\n').replace(/\n{3,}/g,'\n\n').trim()}
function texto(){return(window.DVIA||'Analise o relatório abaixo e aponte os riscos.')+'\n\n=====\nRELATÓRIO '+(dossie?'(Due Diligence completa)':'(Raio-X do concorrente)')+' — CNPJ '+cnpj()+' — consultado em '+new Date().toLocaleDateString('pt-BR')+'\n=====\n\n'+relatorio()}
function direto(a,t){return!!a[2]&&encodeURIComponent(t).length<a[4]}
function copiar(t){var a=document.createElement('textarea');a.value=t;a.setAttribute('readonly','');a.style.cssText='position:fixed;top:0;left:0;opacity:0';document.body.appendChild(a);a.select();try{document.execCommand('copy')}catch(x){}a.remove();var c=navigator.clipboard;return c&&window.isSecureContext?Promise.race([c.writeText(t).catch(function(){}),new Promise(function(r){setTimeout(r,1500)})]):Promise.resolve()}
function baixar(t){var b=new Blob([t],{type:'text/plain;charset=utf-8'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='relatorio-'+(dossie?'due-diligence':'raio-x')+'-'+cnpj()+'.txt';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)}
var bl='background:#fff;color:#16191E;border:1px solid #E3DED2;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer;margin:4px 6px 4px 0;display:inline-flex;align-items:center';
function bt(a,i){return'<button type="button" data-i="'+i+'" style="'+bl+'"><img src="https://www.google.com/s2/favicons?domain='+a[3]+'&sz=64" alt="" width="20" height="20" style="width:20px;height:20px;border-radius:4px;margin-right:8px">'+a[0]+'</button>'}
function grupos(c){var t=texto(),D=[],C=[];IAS.forEach(function(a,i){(direto(a,t)?D:C).push(bt(a,i))});var g=function(tit,sub,l){return l.length?'<div style="margin:6px 0 10px"><b style="display:block">'+tit+'</b><small style="display:block;color:#6E6A60;margin:2px 0 4px">'+sub+'</small>'+l.join('')+'</div>':''};
 c.querySelector('.dv-ia-g').innerHTML=g('✅ Levam o relatório completo direto','A IA abre com o relatório e o roteiro já escritos (algumas já enviam sozinhas).',D)+g('📋 Precisa copiar e colar','Copiamos o relatório completo ao clicar; na IA, cole (Ctrl+V ou ⌘+V) e envie.',C)+'<button type="button" data-c style="'+bl+'">Só copiar</button><button type="button" data-d style="'+bl+'">Baixar arquivo</button>'}
function refaz(old){var c=document.createElement('div');c.className='dv-ia';c.dataset.v2=1;c.style.cssText=old.style.cssText;
 c.innerHTML='<div style="color:#A8843F;font-weight:700;letter-spacing:.14em;font-size:.78rem;text-transform:uppercase">Analisar com a sua IA</div><div style="color:#6E6A60;font-size:.9rem;margin:6px 0 8px">Leve este relatório, com um roteiro de análise de risco, para a inteligência artificial que você já usa. Funciona nas versões gratuitas.</div><div class="dv-ia-g"></div><p class="dv-ia-m" style="margin:10px 0 0;font-weight:600"></p><p style="color:#6E6A60;font-size:.78rem;margin:8px 0 0">O relatório vai para a conta de IA que você escolher e segue as regras de privacidade dela. Se a IA não aceitar um texto tão longo, use "Baixar arquivo" e anexe o arquivo na conversa.</p>';
 old.replaceWith(c);grupos(c);var m=c.querySelector('.dv-ia-m');
 c.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b)return;if(!cnpj()){m.textContent='Consulte um CNPJ primeiro.';return}var t=texto();m.style.color='#2F7A55';
  if(b.hasAttribute('data-d')){baixar(t);m.textContent='Arquivo baixado. Anexe na conversa com a sua IA.';return}
  var a=b.hasAttribute('data-i')?IAS[+b.dataset.i]:null;m.textContent='Copiando o relatório…';
  copiar(t).then(function(){if(!a){m.textContent='Copiado! Cole na sua IA (Ctrl+V ou ⌘+V).';return}var d=direto(a,t);window.open(d?a[2]+encodeURIComponent(t):a[1],'_blank','noopener');
   m.textContent=d?'Abrimos o '+a[0]+' com o relatório completo. Se o texto não aparecer lá (por exemplo, quando abre no aplicativo), é só colar: está copiado.':'Copiado! Na aba do '+a[0]+', cole (Ctrl+V ou ⌘+V) e envie.'})})}
function olha(){tool.querySelectorAll('.dv-ia:not([data-v2])').forEach(refaz)}
var tm=null;new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(olha,200)}).observe(tool,{childList:true,subtree:true});olha()})();</script>
	<?php
}, 99 );
