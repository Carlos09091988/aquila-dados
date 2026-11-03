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
	if ( is_page( 'painel' ) ) { return true; }
	foreach ( array( 'dv_ctx', 'dv_creditos', 'dv_lote', 'dv_cadastro', 'dv_painel' ) as $sc ) { if ( has_shortcode( (string) $post->post_content, $sc ) ) { return true; } }
	return false;
}
function dv_app_menu() {
	return array(
		array( '', array( array( 'Início', '/painel/', '🏠' ) ) ),
		array( 'Raio-X e Due Diligence', array( array( 'Raio-X do comprador', '/plataforma/raio-x-comprador/', '🏛️' ), array( 'Raio-X do concorrente', '/plataforma/raio-x/', '🩻' ), array( 'Due Diligence completa', '/plataforma/dossie/', '📑' ), array( 'Raio-X em lote', '/plataforma/raio-x-lote/', '🗂️' ) ) ),
		array( 'Encontrar', array( array( 'Alertas e oportunidades', '/plataforma/alertas/', '📥', 'caixa' ), array( 'Contratos vencendo', '/plataforma/contratos-vencendo/', '⏳' ) ) ),
		array( 'Analisar e disputar', array( array( 'Gestão de licitações', '/plataforma/licitacoes/', '📋', 'sessoes' ), array( 'Banco de preços', '/plataforma/banco-de-precos/', '💲' ) ) ),
		array( 'Habilitação e garantias', array( array( 'Documentos e certidões', '/plataforma/documentos/', '🗂️', 'docs' ), array( 'Meus contratos e garantias', '/plataforma/meus-contratos/', '🛡️' ) ) ),
		array( 'Contestar', array( array( 'Impugnações e recursos', '/plataforma/recursos/', '⚖️', 'prazos' ) ) ),
		array( 'Minha conta', array( array( 'Comprar créditos', '/creditos/', '💳' ), array( 'Minha assinatura', '/assinar/', '⭐' ) ) ),
	);
}
add_filter( 'body_class', function ( $c ) { if ( dv_app_pagina() ) { $c[] = 'dv-app'; } return $c; } );

add_action( 'wp_head', function () {
	if ( ! dv_app_pagina() ) { return; } ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style id="dv-app-css">
body.dv-app{--side:#0C0F13;--side2:#16191E;--bg:#F4F2EC;--card:#fff;--ln:#E6E1D6;--ink:#16191E;--mu:#6E6A60;--gold:#C9A45C;--gold2:#86621F;background:var(--bg)!important;padding-left:264px!important;font-family:"Hanken Grotesk",-apple-system,BlinkMacSystemFont,sans-serif}
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
.dv-sld{position:fixed;top:14px;right:24px;z-index:9980;display:flex;align-items:center;gap:4px;background:#fff;border:1px solid var(--ln);border-radius:999px;padding:5px 5px 5px 16px;box-shadow:0 6px 20px rgba(22,25,30,.08);font-family:"Hanken Grotesk",sans-serif;font-size:.88rem;color:var(--ink)}
body.admin-bar .dv-sld{top:46px}.dv-sld span{color:var(--mu);margin-right:2px}.dv-sld b{font-weight:800;margin-right:10px}.dv-sld a{background:var(--ink);color:#F4F2EC!important;text-decoration:none;border-radius:999px;padding:8px 14px;font-weight:700;font-size:.82rem}.dv-sld a:hover{background:var(--gold2)}.dv-sld.low{border-color:#E8B4A8}.dv-sld.low b{color:#9A3524}
body.dv-app .dvp button:not(.dv-dd):not([data-v]):not(.dv-hmais),body.dv-app .dvp a.dv-btn{background:#C9A45C;color:#241A06}body.dv-app .dvp button:not(.dv-dd):not([data-v]):not(.dv-hmais):hover,body.dv-app .dvp a.dv-btn:hover{background:#B8924A}
body.dv-app .dv-tool h2,body.dv-app .dv-tool h3,body.dv-app .dv-tool h4,body.dv-app .dv-tool h5,body.dv-app .rp-sec h3,body.dv-app .rp-sec h4{color:#16191E!important;opacity:1!important}body.dv-app .dv-tool .rp-sec h4{font-size:1.05rem;font-weight:800}body.dv-app .dv-tool .rp-hd h2,body.dv-app .dv-tool .rp-hd h3,body.dv-app .dv-tool .rp-hd h4,body.dv-app .dv-tool .ink h2,body.dv-app .dv-tool .ink h3,body.dv-app .dv-tool .ink h4{color:#F4F2EC!important}body.dv-app .dv-tool style{display:none!important}body.dv-app .dv-tool .ink .rx-qsa li{border-color:rgba(248,247,243,.18)!important}body.dv-app .dv-tool .ink .rx-qsa p,body.dv-app .dv-tool .ink .rx-qsa .side span{color:rgba(248,247,243,.72)!important}body.dv-app .dv-tool .ink .rx-qsa .side b{color:#F8F7F3!important}
body.dv-app .dv-th{padding-right:280px!important}body.dv-app .dv-cnpj{margin-top:72px!important}
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
body.dv-app .dvp{--sf:#fff;--ln:#E6E1D6;--fg:#16191E;--mu:#5E5A52;--gd:#86621F;max-width:1180px;padding:0 32px;margin:26px auto}
body.dv-app .dvp [style*="color:#F4F2EC"]{color:#16191E!important}
body.dv-app .dvp a:not(.dv-btn):not(.dv-agi){font-weight:600}body.dv-app .dvp [style*="color:#C9A45C"]{color:#86621F!important}
body.dv-app .dvp .dv-c,body.dv-app .dvp .dv-tools a{box-shadow:0 1px 2px rgba(22,25,30,.04)}
/* consulta rápida de CNPJ no painel */
.dv-cnpj{max-width:1116px;margin:26px auto 16px;background:#fff;border:1px solid var(--ln);border-radius:18px;padding:18px 20px;box-shadow:0 8px 28px rgba(22,25,30,.05);font-family:"Hanken Grotesk",sans-serif;color:var(--ink)}
.dv-cnpj .t{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px}.dv-cnpj .t b{font-size:1.1rem}.dv-cnpj .t span{color:var(--mu);font-size:.9rem}
.dv-cnpj form{display:flex;gap:8px;flex-wrap:wrap}.dv-cnpj input{flex:1 1 220px;border:1px solid #CFC8B8!important;border-radius:11px;padding:12px 14px;font:inherit;font-size:1.05rem;letter-spacing:.02em;background:#fff!important;color:#16191E!important}
.dv-cnpj button{border:1px solid var(--ln)!important;background:#FAF8F3!important;color:#16191E!important;border-radius:11px;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer;text-align:left;line-height:1.2}.dv-cnpj button small{display:block;font-weight:500;color:var(--mu);font-size:.72rem}.dv-cnpj button.p{background:#0C0F13!important;color:#F4F2EC!important;border-color:#0C0F13!important}.dv-cnpj button.p small{color:#B9BDB8}.dv-cnpj button:hover{border-color:var(--gold)}
.dv-cnpj .m{color:#B04A3A;font-size:.85rem;margin:6px 0 0}
body.dv-app .dv-md{position:fixed;inset:0;background:rgba(5,7,10,.6);display:flex;align-items:center;justify-content:center;z-index:99999;font-family:"Hanken Grotesk",sans-serif}body.dv-app .dv-md>div{background:#fff;color:#16191E;border-radius:18px;padding:26px;max-width:480px;width:calc(100% - 32px)}body.dv-app .dv-md h3{margin:0 0 8px;color:#16191E}body.dv-app .dv-md p{color:#4A4F55}body.dv-app .dv-md button{border:0;border-radius:999px;padding:12px 20px;font-weight:700;cursor:pointer;background:#C9A45C;color:#241A06;font:inherit;font-weight:700}body.dv-app .dv-md button.g{background:#fff;color:#16191E;border:1px solid #D9D3C4}
@media(max-width:980px){.dv-cnpj{margin:16px 12px}.dv-cnpj button{flex:1 1 30%}}
@media(max-width:420px){.dv-topm b i{display:none}.dv-topm a{font-size:.8rem!important}}
/* componentes comuns dentro das ferramentas */
body.dv-app .dv-tool input:focus,body.dv-app .dv-tool select:focus,body.dv-app .dv-tool textarea:focus{outline:2px solid rgba(201,164,92,.45);outline-offset:1px;border-color:var(--gold)!important}
body.dv-app #ht-ctc-chat{bottom:18px!important}
@media(max-width:980px){
 body.dv-app{padding-left:0!important;padding-top:56px!important}
 .dv-side{transform:translateX(-100%);transition:transform .2s ease;width:280px;box-shadow:0 0 40px rgba(0,0,0,.4)}
 body.dv-side-on .dv-side{transform:none}
 .dv-sld{display:none} .dv-topm a{white-space:nowrap;margin-left:10px;background:rgba(201,164,92,.14);border-radius:999px;padding:6px 10px} body.dv-app .dv-th{padding-right:16px!important} body.dv-app .dv-cnpj{margin-top:16px!important} .dv-topm{display:flex;position:fixed;top:0;left:0;right:0;height:56px;background:var(--side);z-index:9989;align-items:center;justify-content:space-between;padding:0 14px;color:#F4F2EC;font-family:"Hanken Grotesk",sans-serif}
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
	echo '<div class="dv-topm"><button type="button" class="dv-burg" aria-label="Abrir menu">☰</button><b>desenvolve <i>estratégias</i></b><a href="/creditos/">' . ( $master ? '∞' : (int) $saldo ) . ' créditos · Comprar</a></div><div class="dv-veil"></div>';
	if ( rtrim( $uri, '/' ) !== '/creditos' ) { $low = ! $master && function_exists( 'dv_custo' ) && (int) $saldo < dv_custo( 'raiox' ); echo '<div class="dv-sld' . ( $low ? ' low' : '' ) . '"><span>Saldo</span><b>' . ( $master ? 'Ilimitado' : (int) $saldo . ' crédito' . ( (int) $saldo === 1 ? '' : 's' ) ) . '</b><a href="/creditos/">+ Comprar créditos</a></div>'; }
	echo '<aside class="dv-side" aria-label="Menu da plataforma"><a class="lg" href="/painel/">' . $logo . '<span><b>desenvolve <i>estratégias</i></b><small>Inteligência em licitações</small></span></a><nav>';
	foreach ( dv_app_menu() as $g ) {
		if ( $g[0] ) { echo '<div class="gt">' . esc_html( $g[0] ) . '</div>'; }
		foreach ( $g[1] as $it ) {
			if ( $it[1] === '/plataforma/serasa/' && ! ( ( function_exists( 'dv_sr_ligado' ) && dv_sr_ligado() ) || dv_is_master( $uid ) ) ) { continue; }
			$on = rtrim( $uri, '/' ) === rtrim( $it[1], '/' ); $b = isset( $it[3], $bd[ $it[3] ] ) ? $bd[ $it[3] ] : null;
			echo '<a href="' . esc_url( $it[1] ) . '"' . ( $on ? ' class="on" aria-current="page"' : '' ) . '><span class="ic">' . $it[2] . '</span><span>' . esc_html( $it[0] ) . '</span>' . ( $b ? '<span class="bd ' . $b[1] . '">' . (int) $b[0] . '</span>' : '' ) . '</a>';
		}
	}
	echo '</nav><div class="ft"><a class="cr" href="/creditos/"><span style="color:inherit;font-weight:400"><small>Créditos disponíveis</small><b>' . ( $master ? 'Ilimitado' : (int) $saldo ) . '</b></span><span>' . ( $master ? '' : '+ Comprar' ) . '</span></a>';
	echo '<div class="us"><span><b>' . esc_html( $u->display_name ) . '</b>' . esc_html( $plano ) . '</span><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sair</a></div>';
	echo '<a class="hp" href="https://wa.me/5511993500950" target="_blank" rel="noopener">💬 Ajuda pelo WhatsApp</a></div></aside>';
	$html = ob_get_clean(); ?>
<script>(function(){var w=document.createElement('div');w.innerHTML=<?php echo wp_json_encode( $html ); ?>;while(w.firstChild)document.body.insertBefore(w.firstChild,document.body.firstChild);
if(!document.getElementById('mark')){var mk=document.createElement('div');mk.innerHTML='<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><mask id="eye" maskUnits="userSpaceOnUse" x="0" y="0" width="1536" height="1536"><rect width="1536" height="1536" fill="#fff"/><ellipse cx="566" cy="406" rx="44" ry="17" transform="rotate(-24 566 406)" fill="#000"/></mask><symbol id="mark" viewBox="317 133 833 1253"><path mask="url(#eye)" fill="currentColor" d="M 607.500 153.934 C 600.898 156.673, 600.010 161.369, 600.016 193.500 C 600.021 223.159, 601 238.303, 604.087 256.500 C 607.436 276.239, 607.901 275.357, 578.991 304 C 538.863 343.757, 516.105 372.812, 476.201 435.235 C 464.949 452.837, 462.461 456.191, 430.008 497.500 C 408.819 524.472, 371.037 573.796, 352.260 599 C 336.455 620.216, 334.265 631.977, 339.506 667.500 C 344.016 698.070, 352.490 710.646, 378.535 725.423 C 386.216 729.780, 397.393 736.642, 403.374 740.671 C 433.717 761.112, 463.844 753.460, 481 720.952 C 512.983 660.353, 562.022 627.895, 624.500 625.975 C 663.864 624.766, 677.461 621.781, 704.500 608.413 C 726.952 597.313, 731.086 601.489, 724.385 628.500 C 714.352 668.944, 694.472 699.254, 617.474 791.500 C 525.361 901.855, 491.339 964.828, 482.409 1041.500 C 478.668 1073.618, 485.015 1081.987, 498.527 1062.754 C 537.004 1007.987, 584.065 963.269, 714.011 858 C 725.214 848.925, 740.527 836.325, 748.040 830 C 755.553 823.675, 769.080 812.377, 778.100 804.893 C 918.630 688.298, 967.441 610.207, 955.560 520.978 C 945.106 442.464, 873.051 356.548, 760.587 288.498 C 747.606 280.643, 741.068 274.492, 728.239 258.065 C 686.974 205.223, 621.007 148.330, 607.500 153.934 M 599 396.528 C 573.037 403.170, 551.793 421.420, 551.569 437.273 C 551.256 459.464, 606.913 440.843, 620.944 414.063 C 630.055 396.674, 622.386 390.546, 599 396.528 M 1117.101 741.892 C 1115.615 743.482, 1111.306 751.639, 1107.526 760.017 C 1070.528 842.004, 1012.470 901.373, 880.500 992.172 C 839.043 1020.696, 830.958 1026.344, 813.342 1039.088 C 719.236 1107.167, 673.596 1156.532, 701.124 1160.465 C 714.830 1162.423, 1037.490 1160.506, 1040.997 1158.446 C 1045.700 1155.683, 1048.600 1151.109, 1051.045 1142.598 C 1058.566 1116.423, 1062.872 1102.597, 1075.681 1063.500 C 1115.263 942.678, 1126.614 889.603, 1129.471 812 C 1130.132 794.031, 1128.619 754.913, 1126.966 747.248 C 1125.218 739.144, 1121.536 737.145, 1117.101 741.892 M 488.994 1190.930 C 478.134 1194.253, 476.676 1196.477, 459.466 1236 C 444.590 1270.162, 428.031 1307.719, 421.770 1321.500 C 408.367 1350.999, 407.633 1358.611, 417.706 1363.615 C 423.918 1366.701, 1114.685 1366.555, 1120 1363.466 C 1132.118 1356.423, 1133.784 1361.856, 1078.350 1227.647 C 1066.806 1199.698, 1064.729 1196.162, 1057.500 1192.161 C 1054.267 1190.371, 494.779 1189.160, 488.994 1190.930"/><path fill="currentColor" d="M 744.500 208.502 C 729.615 213.765, 731.838 227.498, 749.888 241.797 C 755.175 245.984, 763.550 252.976, 768.500 257.332 C 773.450 261.689, 785.375 270.442, 795 276.783 C 859.253 319.116, 861.794 320.993, 895.057 350.729 C 985.477 431.560, 1013.992 537.323, 970.083 629 C 965.209 639.175, 959.894 650.425, 958.272 654 C 956.649 657.575, 953.083 663.425, 950.348 667 C 947.613 670.575, 941.001 679.225, 935.656 686.222 C 916.343 711.502, 890.648 737.460, 855.500 767.198 C 848.900 772.782, 840.575 780.114, 837 783.491 C 833.425 786.868, 825.100 793.917, 818.500 799.156 C 811.900 804.394, 803.574 811.340, 799.997 814.590 C 796.420 817.841, 789.445 823.871, 784.497 827.991 C 779.549 832.111, 771.225 839.122, 766 843.572 C 760.775 848.022, 750.972 855.814, 744.216 860.887 C 737.460 865.961, 727.365 874.429, 721.783 879.705 C 716.201 884.981, 705.625 893.753, 698.281 899.199 C 690.937 904.644, 681.232 912.295, 676.714 916.200 C 672.196 920.106, 663.853 926.894, 658.174 931.285 C 652.495 935.677, 644.170 942.717, 639.674 946.930 C 635.178 951.143, 628.350 957.222, 624.500 960.438 C 607.620 974.538, 557.691 1025.175, 542.105 1044 C 501.968 1092.479, 484.803 1145.282, 504.873 1158.528 C 514.308 1164.755, 607.623 1167.977, 627 1162.745 C 644.671 1157.973, 656.611 1149.135, 670.564 1130.500 C 679.730 1118.257, 695.282 1102.650, 713.711 1087.200 C 720.427 1081.569, 729.256 1073.871, 733.331 1070.093 C 737.406 1066.314, 745.861 1059.428, 752.120 1054.789 C 758.379 1050.151, 766.425 1043.784, 770 1040.642 C 773.575 1037.499, 781.900 1031.147, 788.500 1026.525 C 795.100 1021.903, 806.686 1013.461, 814.246 1007.765 C 821.807 1002.070, 833.507 994.064, 840.246 989.975 C 846.986 985.886, 856.775 979.106, 862 974.907 C 867.225 970.708, 877.350 963.445, 884.500 958.767 C 891.650 954.089, 901.169 947.391, 905.654 943.881 C 910.139 940.371, 920.264 932.708, 928.154 926.850 C 963.377 900.702, 1004.241 862.559, 1021.735 839.500 C 1027.576 831.800, 1036 821.225, 1040.454 816 C 1052.380 802.010, 1053.627 800.227, 1065.841 779.714 C 1076 762.651, 1081.557 751.405, 1096.085 718.500 C 1100.771 707.886, 1109.015 679.499, 1113.074 660 C 1116.926 641.494, 1120.553 576.906, 1118.981 554.803 C 1116.164 515.188, 1111.240 487.479, 1103.295 466.528 C 1093.653 441.105, 1062.666 384.871, 1047.623 365.500 C 1038.052 353.176, 1016.295 328.633, 1010.370 323.477 C 1006.591 320.190, 998.141 312.562, 991.591 306.527 C 975.264 291.483, 952.015 274.339, 938.532 267.402 C 932.465 264.280, 919.899 257.653, 910.610 252.675 C 873.671 232.881, 839.162 221.237, 794.500 213.497 C 786.250 212.067, 774.865 210.020, 769.199 208.949 C 757.146 206.668, 750.049 206.540, 744.500 208.502"/></symbol></defs></svg>';document.body.insertBefore(mk.firstChild,document.body.firstChild)}
function tg(o){document.body.classList.toggle('dv-side-on',o)}document.querySelector('.dv-burg').onclick=function(){tg(true)};document.querySelector('.dv-veil').onclick=function(){tg(false)};
var pn=document.getElementById('dv-painel');if(pn){var C=window.DVC||{},q=document.createElement('div');q.className='dv-cnpj';q.innerHTML='<div class="t"><b>Consultar um CNPJ</b><span>Órgão público ou empresa: veja tudo antes de disputar.</span></div><form><input inputmode="numeric" placeholder="00.000.000/0000-00" aria-label="CNPJ"><button type="button" data-u="/plataforma/raio-x-comprador/?auto=1&cnpj=">🏛️ Raio-X do comprador<small>'+(C.comprador||5)+' créditos</small></button><button type="button" data-u="/plataforma/raio-x/?auto=1&cnpj=" class="p">🩻 Raio-X do concorrente<small>'+(C.raiox||5)+' créditos</small></button><button type="button" data-u="/plataforma/dossie/?auto=1&cnpj=">📑 Due Diligence<small>'+(C.dossie||10)+' créditos</small></button></form><p class="m"></p>';pn.parentNode.insertBefore(q,pn);q.style.maxWidth=Math.max(320,pn.getBoundingClientRect().width)+'px';var I=q.querySelector('input');I.addEventListener('input',function(){var d=I.value.replace(/\D/g,'').slice(0,14);I.value=d.replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d)/,'$1-$2')});q.querySelector('form').addEventListener('submit',function(e){e.preventDefault();q.querySelector('button.p').click()});q.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var d=I.value.replace(/\D/g,'');if(d.length!==14){q.querySelector('.m').textContent='Digite os 14 números do CNPJ.';I.focus();return}var ft=/comprador/.test(b.dataset.u)?'comprador':/dossie/.test(b.dataset.u)?'dossie':'raiox',ir=function(){location.href=b.dataset.u+d};if(!pn.dataset.api){ir();return}b.disabled=true;fetch(pn.dataset.api+'relatorios?ferramenta='+ft,{headers:{'X-WP-Nonce':pn.dataset.nonce},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(L){b.disabled=false;var x=(Array.isArray(L)?L:[]).filter(function(y){return y.cnpj===d})[0];if(!x){ir();return}var cu=(window.DVC||{})[ft]||(ft==='dossie'?10:5),dt=function(s){var m=String(s).match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''},m=document.createElement('div');m.className='dv-md';m.innerHTML='<div><h3>Você já tem este relatório</h3><p>Emitido em '+dt(x.criado)+'. Fica disponível sem custo até '+dt(x.ate)+'.</p><div class="dv-rlx" style="display:flex;flex-direction:column;gap:8px;margin-top:14px"><button type="button" data-a>Abrir o relatório salvo (sem custo)</button><button type="button" class="g" data-n>Pesquisar de novo, com dados atualizados ('+cu+' créditos)</button></div></div>';document.body.appendChild(m);m.addEventListener('click',function(ev){if(ev.target===m){m.remove();return}if(ev.target.closest('[data-a]'))location.href=x.url;if(ev.target.closest('[data-n]')){try{sessionStorage.setItem('dv_rel_skip',d)}catch(z){}ir()}})}).catch(function(){b.disabled=false;ir()})})}
(function(){var Q=new URLSearchParams(location.search),c=(Q.get('cnpj')||'').replace(/\D/g,'');if(Q.get('auto')!=='1'||c.length!==14||/\/dossie\//.test(location.pathname))return;var n=0,t=setInterval(function(){var T=document.querySelector('.dv-tool');if(!T||++n>40){clearInterval(t);return}var I=[].slice.call(T.querySelectorAll('input')).filter(function(i){return i.offsetParent&&/0000|cnpj/i.test((i.placeholder||'')+(i.id||'')+(i.name||''))})[0];if(!I)return;var b=[].slice.call((I.closest('form,section,.calc,div')||T).querySelectorAll('button')).concat([].slice.call(T.querySelectorAll('button'))).filter(function(x){return x.offsetParent&&/consultar|pesquisar|gerar|ver raio|analisar/i.test(x.textContent)})[0];if(!b)return;clearInterval(t);if(I.value.replace(/\D/g,'')!==c){I.value=c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5');I.dispatchEvent(new Event('input',{bubbles:true}))}history.replaceState(null,'',location.pathname+location.search.replace(/([?&])auto=1&?/,'$1').replace(/[?&]$/,''));setTimeout(function(){b.click()},150)},250)})();
[].forEach.call(document.querySelectorAll('.dv-cst-n'),function(b){var C=window.DVC||{},v=b.dataset.f==='dossie'&&window.DV&&DV.cr_dossie?DV.cr_dossie:C[b.dataset.f];if(v!=null)b.textContent=v});
var on=document.querySelector('.dv-side nav a.on'),nv=document.querySelector('.dv-side nav');if(on&&nv){var t=on.offsetTop-nv.offsetTop;if(t>nv.clientHeight-60)nv.scrollTop=t-nv.clientHeight/2}})();</script>
	<?php
}, 1 );
