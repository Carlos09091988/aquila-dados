/**
 * Desenvolve — Casca da plataforma (área logada)
 * Um só padrão para o painel e todas as ferramentas: menu lateral fixo com o fluxo da licitação,
 * barra superior com créditos, área de trabalho clara com cartões brancos. No celular, o menu vira gaveta.
 * Não altera as páginas: troca o cabeçalho/rodapé de site pelo menu da plataforma só para quem está logado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function dv_app_pagina() {
	if ( ! is_user_logged_in() || is_admin() || ! is_page() ) { return false; }
	global $post; if ( ! $post ) { return false; }
	return is_page( 'painel' ) || has_shortcode( (string) $post->post_content, 'dv_ctx' );
}
function dv_app_menu() {
	return array(
		array( '', array( array( 'Início', '/painel/', '🏠' ) ) ),
		array( 'Raio-X e Due Diligence', array( array( 'Raio-X do comprador', '/plataforma/raio-x-comprador/', '🏛️' ), array( 'Raio-X do concorrente', '/plataforma/raio-x/', '🩻' ), array( 'Due Diligence completa', '/plataforma/dossie/', '📑' ), array( 'Raio-X em lote', '/plataforma/raio-x-lote/', '🗂️' ) ) ),
		array( 'Encontrar', array( array( 'Buscador de licitações', '/plataforma/buscador/', '🔎' ), array( 'Alertas e oportunidades', '/plataforma/alertas/', '📥', 'caixa' ), array( 'Contratos vencendo', '/plataforma/contratos-vencendo/', '⏳' ) ) ),
		array( 'Analisar e disputar', array( array( 'Gestão de licitações', '/plataforma/licitacoes/', '📋', 'sessoes' ), array( 'Banco de preços', '/plataforma/banco-de-precos/', '💲' ), array( 'Calculadora de prazos', '/plataforma/prazos/', '📅' ) ) ),
		array( 'Concorrência', array( array( 'Comparador de licitantes', '/plataforma/comparador/', '🔗' ) ) ),
		array( 'Habilitação e garantias', array( array( 'Documentos e certidões', '/plataforma/documentos/', '🗂️', 'docs' ), array( 'Meus contratos e garantias', '/plataforma/meus-contratos/', '🛡️' ) ) ),
		array( 'Contestar', array( array( 'Impugnações e recursos', '/plataforma/recursos/', '⚖️', 'prazos' ), array( 'Banco de teses', '/plataforma/banco-de-teses/', '📚' ) ) ),
	);
}
add_filter( 'body_class', function ( $c ) { if ( dv_app_pagina() ) { $c[] = 'dv-app'; } return $c; } );

add_action( 'wp_head', function () {
	if ( ! dv_app_pagina() ) { return; } ?>
<style id="dv-app-css">
body.dv-app{--side:#0C0F13;--side2:#16191E;--bg:#F4F2EC;--card:#fff;--ln:#E6E1D6;--ink:#16191E;--mu:#6E6A60;--gold:#C9A45C;--gold2:#A8843F;background:var(--bg)!important;padding-left:264px!important;font-family:"Hanken Grotesk",-apple-system,BlinkMacSystemFont,sans-serif}
body.dv-app>header.nav,body.dv-app>footer.band,body.dv-app>.dv-tbar,body.dv-app .kadence-scroll-to-top{display:none!important}
/* menu lateral */
.dv-side{position:fixed;top:0;left:0;bottom:0;width:264px;background:var(--side);color:#C9CDC8;display:flex;flex-direction:column;z-index:9990;font-family:"Hanken Grotesk",sans-serif;border-right:1px solid #1F242B}
body.admin-bar .dv-side{top:32px}
.dv-side .lg{display:flex;align-items:center;gap:10px;padding:20px 20px 14px;text-decoration:none}.dv-side .lg svg{width:30px;height:30px;color:#F4F2EC}.dv-side .lg b{color:#F4F2EC;font-size:1.05rem;font-weight:600;line-height:1.1}.dv-side .lg b i{font-style:normal;color:var(--gold)}.dv-side .lg small{display:block;color:#7E837E;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;margin-top:3px}
.dv-side nav{flex:1;overflow-y:auto;padding:4px 10px 10px}
.dv-side .gt{font-size:.66rem;letter-spacing:.14em;text-transform:uppercase;color:#6F746F;font-weight:700;margin:16px 10px 6px}
.dv-side nav a{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:9px;color:#C9CDC8;text-decoration:none;font-size:.9rem;font-weight:500;line-height:1.25}
.dv-side nav a:hover{background:#1A1F26;color:#fff}.dv-side nav a.on{background:#232933;color:#fff;font-weight:700;box-shadow:inset 3px 0 0 var(--gold)}
.dv-side nav a .ic{width:20px;text-align:center;font-size:.95rem;flex:none}.dv-side nav a .bd{margin-left:auto;background:var(--gold);color:#241A06;border-radius:20px;font-size:.7rem;font-weight:800;padding:1px 7px}.dv-side nav a .bd.r{background:#C8604E;color:#fff}
.dv-side .ft{border-top:1px solid #1F242B;padding:12px 14px 16px;font-size:.84rem}
.dv-side .cr{display:flex;justify-content:space-between;align-items:center;background:#161B21;border:1px solid #232933;border-radius:10px;padding:10px 12px;margin-bottom:10px;color:#F4F2EC;text-decoration:none}.dv-side .cr small{display:block;color:#8E938F;font-size:.7rem}.dv-side .cr b{font-size:1.05rem}.dv-side .cr span{color:var(--gold);font-weight:700;font-size:.8rem}
.dv-side .us{display:flex;justify-content:space-between;align-items:center;gap:8px;color:#8E938F}.dv-side .us b{color:#E7E9E4;font-weight:600;display:block;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dv-side .us a{color:#8E938F;text-decoration:none;font-size:.8rem}.dv-side .us a:hover{color:#fff}
.dv-side .hp{display:block;color:#8E938F;text-decoration:none;font-size:.8rem;margin-top:8px}.dv-side .hp:hover{color:#fff}
/* barra superior (celular) */
.dv-topm{display:none}
/* área de trabalho */
body.dv-app .dv-th{background:none!important;max-width:1180px;margin:0 auto!important;padding:30px 32px 6px!important;color:var(--ink)!important}
body.dv-app .dv-th *{color:inherit}
body.dv-app .dv-th h1{color:var(--ink)!important;font-size:clamp(1.6rem,2.4vw,2.1rem)!important;letter-spacing:-.02em;margin:4px 0 6px!important;font-family:"Hanken Grotesk",sans-serif!important}
body.dv-app .dv-th p{color:var(--mu)!important;max-width:820px;font-size:.98rem!important;margin:0!important}
body.dv-app .dv-th [class*=kick],body.dv-app .dv-th .eyebrow,body.dv-app .dv-th small{color:var(--gold2)!important}
body.dv-app .dv-tool{max-width:1180px;margin:14px auto 22px!important;padding:0 32px!important;box-sizing:border-box}
body.dv-app .dv-tool>*{border-radius:18px}
body.dv-app .dv-tool>div:first-child:not([class*=dv-pw]){box-shadow:0 1px 2px rgba(22,25,30,.04),0 8px 28px rgba(22,25,30,.05)}
body.dv-app .dv-cons,body.dv-app .dv-next{max-width:1116px;margin:0 auto 18px!important;box-sizing:border-box}
body.dv-app .dv-next{background:#fff!important;border:1px solid var(--ln)!important;border-radius:14px!important;color:var(--ink)!important}
body.dv-app .dv-next a{color:var(--gold2)!important}
/* painel em tema claro */
body.dv-app .dvp{--sf:#fff;--ln:#E6E1D6;--fg:#16191E;--mu:#6E6A60;--gd:#A8843F;max-width:1180px;padding:0 32px;margin:26px auto}
body.dv-app .dvp [style*="color:#F4F2EC"]{color:#16191E!important}
body.dv-app .dvp .dv-c,body.dv-app .dvp .dv-tools a{box-shadow:0 1px 2px rgba(22,25,30,.04)}
/* consulta rápida de CNPJ no painel */
.dv-cnpj{max-width:1116px;margin:26px auto 16px;background:#fff;border:1px solid var(--ln);border-radius:18px;padding:18px 20px;box-shadow:0 8px 28px rgba(22,25,30,.05);font-family:"Hanken Grotesk",sans-serif;color:var(--ink)}
.dv-cnpj .t{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px}.dv-cnpj .t b{font-size:1.1rem}.dv-cnpj .t span{color:var(--mu);font-size:.9rem}
.dv-cnpj form{display:flex;gap:8px;flex-wrap:wrap}.dv-cnpj input{flex:1 1 220px;border:1px solid #CFC8B8!important;border-radius:11px;padding:12px 14px;font:inherit;font-size:1.05rem;letter-spacing:.02em;background:#fff!important;color:#16191E!important}
.dv-cnpj button{border:1px solid var(--ln)!important;background:#FAF8F3!important;color:#16191E!important;border-radius:11px;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer;text-align:left;line-height:1.2}.dv-cnpj button small{display:block;font-weight:500;color:var(--mu);font-size:.72rem}.dv-cnpj button.p{background:#0C0F13!important;color:#F4F2EC!important;border-color:#0C0F13!important}.dv-cnpj button.p small{color:#B9BDB8}.dv-cnpj button:hover{border-color:var(--gold)}
.dv-cnpj .m{color:#B04A3A;font-size:.85rem;margin:6px 0 0}
body.dv-app .dv-md{position:fixed;inset:0;background:rgba(5,7,10,.6);display:flex;align-items:center;justify-content:center;z-index:99999;font-family:"Hanken Grotesk",sans-serif}body.dv-app .dv-md>div{background:#fff;color:#16191E;border-radius:18px;padding:26px;max-width:480px;width:calc(100% - 32px)}body.dv-app .dv-md h3{margin:0 0 8px;color:#16191E}body.dv-app .dv-md p{color:#4A4F55}body.dv-app .dv-md button{border:0;border-radius:999px;padding:12px 20px;font-weight:700;cursor:pointer;background:#C9A45C;color:#241A06;font:inherit;font-weight:700}body.dv-app .dv-md button.g{background:#fff;color:#16191E;border:1px solid #D9D3C4}
@media(max-width:980px){.dv-cnpj{margin:16px 12px}.dv-cnpj button{flex:1 1 30%}}
/* componentes comuns dentro das ferramentas */
body.dv-app .dv-tool input:focus,body.dv-app .dv-tool select:focus,body.dv-app .dv-tool textarea:focus{outline:2px solid rgba(201,164,92,.45);outline-offset:1px;border-color:var(--gold)!important}
body.dv-app #ht-ctc-chat{bottom:18px!important}
@media(max-width:980px){
 body.dv-app{padding-left:0!important;padding-top:56px!important}
 .dv-side{transform:translateX(-100%);transition:transform .2s ease;width:280px;box-shadow:0 0 40px rgba(0,0,0,.4)}
 body.dv-side-on .dv-side{transform:none}
 .dv-topm{display:flex;position:fixed;top:0;left:0;right:0;height:56px;background:var(--side);z-index:9989;align-items:center;justify-content:space-between;padding:0 14px;color:#F4F2EC;font-family:"Hanken Grotesk",sans-serif}
 body.admin-bar .dv-topm{top:46px}
 .dv-topm button{background:none;border:0;color:#F4F2EC;font-size:1.5rem;cursor:pointer;padding:6px}
 .dv-topm b{font-weight:600}.dv-topm b i{font-style:normal;color:var(--gold)}.dv-topm a{color:var(--gold);font-weight:700;font-size:.85rem;text-decoration:none}
 .dv-veil{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9988}body.dv-side-on .dv-veil{display:block}
 body.dv-app .dv-th{padding:20px 16px 4px!important}body.dv-app .dv-tool{padding:0 12px!important}body.dv-app .dvp{padding:0 14px}
 body.dv-app .dv-cons,body.dv-app .dv-next{margin:0 12px 16px!important}
}
</style>
	<?php
}, 99 );

add_action( 'wp_footer', function () {
	if ( ! dv_app_pagina() ) { return; }
	$uid = get_current_user_id(); $u = wp_get_current_user();
	$master = function_exists( 'dv_ilimitado' ) && dv_ilimitado( $uid );
	$saldo = $master ? 'ilimitado' : ( function_exists( 'dv_saldo' ) ? (int) dv_saldo( $uid )['total'] : 0 );
	$plano = ''; if ( function_exists( 'dv_plano' ) && function_exists( 'dv_plano_info' ) ) { $pi = dv_plano_info( dv_plano( $uid ) ); $plano = is_array( $pi ) ? ( $pi['nome'] ?? '' ) : ''; }
	if ( $master ) { $plano = 'Conta Master'; }
	/* contadores */
	$bd = array();
	if ( function_exists( 'dv_al_caixa' ) ) { $n = 0; foreach ( dv_al_caixa( $uid ) as $x ) { if ( ( $x['st'] ?? '' ) === '' ) { $n++; } } if ( $n ) { $bd['caixa'] = array( $n, '' ); } }
	if ( function_exists( 'dv_lic_itens' ) ) { $h = current_time( 'Y-m-d' ); $am = gmdate( 'Y-m-d', strtotime( $h . ' +1 day' ) ); $n = 0; foreach ( dv_lic_itens( $uid ) as $x ) { $d = substr( (string) ( $x['abertura'] ?? '' ), 0, 10 ); if ( ( $d === $h || $d === $am ) && in_array( $x['status'] ?? '', array( 'analise', 'participar', 'proposta', 'disputa' ), true ) ) { $n++; } } if ( $n ) { $bd['sessoes'] = array( $n, 'r' ); } }
	if ( function_exists( 'dv_rc_itens' ) && function_exists( 'dv_rc_calc' ) ) { $h = current_time( 'Y-m-d' ); $n = 0; foreach ( dv_rc_itens( $uid ) as $x ) { if ( ( $x['status'] ?? 'aberto' ) !== 'aberto' ) { continue; } $c = dv_rc_calc( $x, $h ); if ( isset( $c['dias'] ) && $c['dias'] !== null && $c['dias'] >= 0 && $c['dias'] <= 3 ) { $n++; } } if ( $n ) { $bd['prazos'] = array( $n, 'r' ); } }
	$uri = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
	$logo = '<svg aria-hidden="true"><use href="#mark" width="100%" height="100%"/></svg>';
	ob_start();
	echo '<div class="dv-topm"><button type="button" class="dv-burg" aria-label="Abrir menu">☰</button><b>desenvolve <i>estratégias</i></b><a href="/painel/">' . ( $master ? '∞' : (int) $saldo ) . ' créditos</a></div><div class="dv-veil"></div>';
	echo '<aside class="dv-side" aria-label="Menu da plataforma"><a class="lg" href="/painel/">' . $logo . '<span><b>desenvolve <i>estratégias</i></b><small>Inteligência em licitações</small></span></a><nav>';
	foreach ( dv_app_menu() as $g ) {
		if ( $g[0] ) { echo '<div class="gt">' . esc_html( $g[0] ) . '</div>'; }
		foreach ( $g[1] as $it ) {
			$on = rtrim( $uri, '/' ) === rtrim( $it[1], '/' ); $b = isset( $it[3], $bd[ $it[3] ] ) ? $bd[ $it[3] ] : null;
			echo '<a href="' . esc_url( $it[1] ) . '"' . ( $on ? ' class="on" aria-current="page"' : '' ) . '><span class="ic">' . $it[2] . '</span><span>' . esc_html( $it[0] ) . '</span>' . ( $b ? '<span class="bd ' . $b[1] . '">' . (int) $b[0] . '</span>' : '' ) . '</a>';
		}
	}
	echo '</nav><div class="ft"><a class="cr" href="/painel/#dv-planos"><span style="color:inherit;font-weight:400"><small>Créditos disponíveis</small><b>' . ( $master ? 'Ilimitado' : (int) $saldo ) . '</b></span><span>' . ( $master ? '' : 'Comprar' ) . '</span></a>';
	echo '<div class="us"><span><b>' . esc_html( $u->display_name ) . '</b>' . esc_html( $plano ) . '</span><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sair</a></div>';
	echo '<a class="hp" href="https://wa.me/5511993500950" target="_blank" rel="noopener">💬 Ajuda pelo WhatsApp</a></div></aside>';
	$html = ob_get_clean(); ?>
<script>(function(){var w=document.createElement('div');w.innerHTML=<?php echo wp_json_encode( $html ); ?>;while(w.firstChild)document.body.insertBefore(w.firstChild,document.body.firstChild);
if(!document.getElementById('mark')){var lg=document.querySelector('.dv-side .lg svg');if(lg)lg.outerHTML='<span style="width:30px;height:30px;border-radius:8px;background:#C9A45C;display:inline-block"></span>'}
function tg(o){document.body.classList.toggle('dv-side-on',o)}document.querySelector('.dv-burg').onclick=function(){tg(true)};document.querySelector('.dv-veil').onclick=function(){tg(false)};
var pn=document.getElementById('dv-painel');if(pn){var C=window.DVC||{},q=document.createElement('div');q.className='dv-cnpj';q.innerHTML='<div class="t"><b>Consultar um CNPJ</b><span>Órgão público ou empresa: veja tudo antes de disputar.</span></div><form><input inputmode="numeric" placeholder="00.000.000/0000-00" aria-label="CNPJ"><button type="button" data-u="/plataforma/raio-x-comprador/?cnpj=">🏛️ Raio-X do comprador<small>'+(C.comprador||5)+' créditos</small></button><button type="button" data-u="/plataforma/raio-x/?cnpj=" class="p">🩻 Raio-X do concorrente<small>'+(C.raiox||5)+' créditos</small></button><button type="button" data-u="/plataforma/dossie/?auto=1&cnpj=">📑 Due Diligence<small>'+(C.dossie||10)+' créditos</small></button></form><p class="m"></p>';pn.parentNode.insertBefore(q,pn);q.style.maxWidth=Math.max(320,pn.getBoundingClientRect().width)+'px';var I=q.querySelector('input');I.addEventListener('input',function(){var d=I.value.replace(/\D/g,'').slice(0,14);I.value=d.replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d)/,'$1-$2')});q.querySelector('form').addEventListener('submit',function(e){e.preventDefault();q.querySelector('button.p').click()});q.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var d=I.value.replace(/\D/g,'');if(d.length!==14){q.querySelector('.m').textContent='Digite os 14 números do CNPJ.';I.focus();return}var ft=/comprador/.test(b.dataset.u)?'comprador':/dossie/.test(b.dataset.u)?'dossie':'raiox',ir=function(){location.href=b.dataset.u+d};if(!pn.dataset.api){ir();return}b.disabled=true;fetch(pn.dataset.api+'relatorios?ferramenta='+ft,{headers:{'X-WP-Nonce':pn.dataset.nonce},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(L){b.disabled=false;var x=(Array.isArray(L)?L:[]).filter(function(y){return y.cnpj===d})[0];if(!x){ir();return}var cu=(window.DVC||{})[ft]||(ft==='dossie'?10:5),dt=function(s){var m=String(s).match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''},m=document.createElement('div');m.className='dv-md';m.innerHTML='<div><h3>Você já tem este relatório</h3><p>Emitido em '+dt(x.criado)+'. Fica disponível sem custo até '+dt(x.ate)+'.</p><div class="dv-rlx" style="display:flex;flex-direction:column;gap:8px;margin-top:14px"><button type="button" data-a>Abrir o relatório salvo (sem custo)</button><button type="button" class="g" data-n>Pesquisar de novo, com dados atualizados ('+cu+' créditos)</button></div></div>';document.body.appendChild(m);m.addEventListener('click',function(ev){if(ev.target===m){m.remove();return}if(ev.target.closest('[data-a]'))location.href=x.url;if(ev.target.closest('[data-n]')){try{sessionStorage.setItem('dv_rel_skip',d)}catch(z){}ir()}})}).catch(function(){b.disabled=false;ir()})})}
var on=document.querySelector('.dv-side nav a.on');if(on&&on.scrollIntoView)on.scrollIntoView({block:'nearest'})})();</script>
	<?php
}, 1 );
