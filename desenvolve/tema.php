<?php
/**
 * Desenvolve — Tema "claro premium" da área logada (painel e ferramentas)
 * 1) Troca a paleta creme/bege antiga por neutros (branco, cinzas, preto) e a fonte Hanken Grotesk pela Geist,
 *    em toda a saída das páginas da plataforma (estilos das ferramentas, estilos em linha e relatórios).
 * 2) Acrescenta uma camada de acabamento: menu lateral preto, barra superior, cartões, botões, tabelas e etiquetas.
 * Para voltar ao visual antigo, basta desativar este snippet.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_tema_mapa() {
	return array(
		// texto e fundos escuros
		'#16191E' => '#0A0A0A', '#16191e' => '#0A0A0A', '#0C0F13' => '#000000', '#0c0f13' => '#000000',
		// fundo creme e superfícies claras
		'#F4F2EC' => '#F5F5F5', '#f4f2ec' => '#F5F5F5', '#FAF8F3' => '#FAFAFA', '#faf8f3' => '#FAFAFA', '#FBFAF6' => '#FAFAFA', '#fbfaf6' => '#FAFAFA',
		'#EEE9DD' => '#F2F2F2', '#EEE9DE' => '#F2F2F2', '#EFEBE2' => '#F2F2F2', '#F3F0E8' => '#F3F3F3', '#F1EBDD' => '#F2F2F2', '#ECE7DC' => '#EDEDED',
		// linhas e bordas
		'#E3DED2' => '#E8E8E8', '#e3ded2' => '#E8E8E8', '#E6E1D6' => '#EAEAEA', '#e6e1d6' => '#EAEAEA', '#CFC8B8' => '#D4D4D4', '#cfc8b8' => '#D4D4D4',
		'#D9D3C4' => '#DADADA', '#D9D2C2' => '#DADADA',
		// textos secundários
		'#6E6A60' => '#666666', '#6e6a60' => '#666666', '#5E5A52' => '#5C5C5C', '#5e5a52' => '#5C5C5C', '#8E8A80' => '#8A8A8A', '#4A4F55' => '#4D4D4D',
		// dourados de destaque viram um dourado só, mais sóbrio
		'#86621F' => '#8C6A24', '#86621f' => '#8C6A24', '#8A6A2C' => '#8C6A24', '#A8843F' => '#8C6A24', '#8A6A2E' => '#8C6A24', '#7A5410' => '#8C6A24', '#A57C2C' => '#8C6A24', '#9A6B12' => '#8C6A24',
		'"Hanken Grotesk"' => 'Geist', "'Hanken Grotesk'" => 'Geist', 'Hanken Grotesk' => 'Geist', 'family=Hanken+Grotesk' => 'family=Geist',
	);
}

function dv_tema_escuro() {
	$u = get_current_user_id(); $m = $u ? (string) get_user_meta( $u, 'dv_tema', true ) : '';
	if ( $m === 'claro' || $m === 'escuro' ) { return $m === 'escuro'; }
	return get_option( 'dv_tema_modo', 'escuro' ) !== 'claro';
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/tema', array( 'methods' => 'POST', 'permission_callback' => function () { return is_user_logged_in(); }, 'callback' => function ( WP_REST_Request $r ) {
		$m = $r['modo'] === 'claro' ? 'claro' : 'escuro'; update_user_meta( get_current_user_id(), 'dv_tema', $m ); return array( 'ok' => true, 'modo' => $m );
	} ) );
} );

/* ---- tema escuro: converte cores pelo contexto (fundo, texto, borda), preservando o matiz ---- */
function dv_te_rgb( $h ) { $h = ltrim( $h, '#' ); if ( strlen( $h ) === 3 ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; } return array( hexdec( substr( $h, 0, 2 ) ) / 255, hexdec( substr( $h, 2, 2 ) ) / 255, hexdec( substr( $h, 4, 2 ) ) / 255 ); }
function dv_te_hsl( $r, $g, $b ) { $mx = max( $r, $g, $b ); $mn = min( $r, $g, $b ); $l = ( $mx + $mn ) / 2; if ( $mx == $mn ) { return array( 0, 0, $l ); } $d = $mx - $mn; $s = $l > .5 ? $d / ( 2 - $mx - $mn ) : $d / ( $mx + $mn ); if ( $mx == $r ) { $h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 ); } elseif ( $mx == $g ) { $h = ( $b - $r ) / $d + 2; } else { $h = ( $r - $g ) / $d + 4; } return array( $h / 6, $s, $l ); }
function dv_te_hex( $h, $s, $l ) { $f = function ( $p, $q, $t ) { if ( $t < 0 ) { $t += 1; } if ( $t > 1 ) { $t -= 1; } if ( $t < 1 / 6 ) { return $p + ( $q - $p ) * 6 * $t; } if ( $t < .5 ) { return $q; } if ( $t < 2 / 3 ) { return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6; } return $p; };
	if ( $s == 0 ) { $r = $g = $b = $l; } else { $q = $l < .5 ? $l * ( 1 + $s ) : $l + $s - $l * $s; $p = 2 * $l - $q; $r = $f( $p, $q, $h + 1 / 3 ); $g = $f( $p, $q, $h ); $b = $f( $p, $q, $h - 1 / 3 ); }
	return sprintf( '#%02X%02X%02X', round( $r * 255 ), round( $g * 255 ), round( $b * 255 ) ); }
function dv_te_cor( $hex, $papel ) {
	list( $r, $g, $b ) = dv_te_rgb( $hex ); list( $h, $s, $l ) = dv_te_hsl( $r, $g, $b ); $neutro = $s < .18 || $l > .96;
	if ( $papel === 'fundo' ) {
		if ( $l < .78 ) { return $hex; }
		if ( $neutro ) { return $l > .975 ? '#101216' : '#15181D'; }
		return dv_te_hex( $h, min( $s, .5 ), .11 );
	}
	if ( $papel === 'borda' ) {
		if ( $l < .62 ) { return $hex; }
		return $neutro ? '#262A31' : dv_te_hex( $h, min( $s, .45 ), .27 );
	}
	// texto
	if ( $l > .5 ) { return $hex; }
	if ( $neutro ) { return $l < .2 ? '#ECEAE4' : ( $l < .36 ? '#C9C7C0' : '#9D9B94' ); }
	return dv_te_hex( $h, min( 1, $s + .1 ), .7 );
}
function dv_te_bloco( $t ) {
	// fundo do próprio bloco: se for cor média/saturada (botão dourado, selo), o texto dele fica como está
	$fundo_medio = false;
	if ( preg_match( '/(?<![\w-])background(?:-color)?\s*:\s*[^;}"\']*?(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})\b/', $t, $m ) ) { list( $r, $g, $b ) = dv_te_rgb( $m[1] ); list( , $s, $l ) = dv_te_hsl( $r, $g, $b ); $fundo_medio = $l >= .3 && $l < .78; }
	return preg_replace_callback( '/(?<![\w-])(background(?:-color)?|color|border(?:-(?:top|right|bottom|left))?(?:-color)?|outline(?:-color)?|--[\w-]+)(\s*:\s*)([^;}"\']*)/', function ( $m ) use ( $fundo_medio ) {
		$p = strtolower( $m[1] );
		if ( strpos( $p, '--' ) === 0 ) {
			$n = substr( $p, 2 );
			if ( preg_match( '/^(sf|card|paper|stone|bg|t-bg|t-card|surface|white)$/', $n ) ) { $papel = 'fundo'; }
			elseif ( preg_match( '/^(ln|l|rule|t-ln|t-ln2|line|border)$/', $n ) ) { $papel = 'borda'; }
			elseif ( preg_match( '/^(fg|ink|ink-2|k|t-ink|mu|m|slate|t-mu|gd|gold2|t-ouro2|am|rd|gr)$/', $n ) ) { $papel = 'texto'; }
			else { return $m[0]; }
		} elseif ( strpos( $p, 'background' ) === 0 ) { $papel = 'fundo'; }
		elseif ( $p === 'color' ) { if ( $fundo_medio ) { return $m[0]; } $papel = 'texto'; }
		else { $papel = 'borda'; }
		$v = preg_replace_callback( '/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', function ( $c ) use ( $papel ) { return dv_te_cor( $c[0], $papel ); }, $m[3] );
		if ( $papel === 'fundo' ) { $v = preg_replace( '/rgba\(\s*255\s*,\s*255\s*,\s*255\s*,\s*(0?\.[5-9]\d*|1(?:\.0+)?)\s*\)/', 'rgba(13,15,19,$1)', $v ); }
		return $m[1] . $m[2] . $v;
	}, $t );
}
function dv_te_converter( $html ) {
	$prot = array(); $html = preg_replace_callback( '/<!--dv-tema-off-->.*?<!--\/dv-tema-off-->/s', function ( $m ) use ( &$prot ) { $prot[] = $m[0]; return '%%DVTE' . ( count( $prot ) - 1 ) . '%%'; }, $html );
	$html = preg_replace_callback( '/\{[^{}]*\}/', function ( $m ) { return strpos( $m[0], ':' ) === false ? $m[0] : dv_te_bloco( $m[0] ); }, $html );
	$html = preg_replace_callback( '/style=(["\'])(.*?)\1/s', function ( $m ) { return 'style=' . $m[1] . dv_te_bloco( $m[2] ) . $m[1]; }, $html );
	return preg_replace_callback( '/%%DVTE(\d+)%%/', function ( $m ) use ( $prot ) { return $prot[ (int) $m[1] ]; }, $html );
}

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; }
	ob_start( function ( $html ) {
		if ( ! is_string( $html ) || $html === '' ) { return $html; }
		$html = strtr( $html, dv_tema_mapa() );
		if ( dv_tema_escuro() ) { return dv_te_converter( $html ); }
		// botões dourados viram pretos só onde o dourado é FUNDO; o dourado como texto/borda continua dourado
		$html = preg_replace( '/(background(?:-color)?\s*:\s*)#C9A45C/i', '${1}#111111', $html );
		$html = preg_replace( '/(background(?:-color)?\s*:\s*)#B8924A/i', '${1}#2B2B2B', $html );
		$html = preg_replace( '/(background(?:-color)?\s*:\s*)#CBA55C/i', '${1}#111111', $html );
		// texto escuro que ficava sobre o dourado: branco só quando o mesmo bloco de estilo tem o fundo preto novo
		$html = preg_replace_callback( '/\{[^{}]*background(?:-color)?\s*:\s*#111111[^{}]*\}/i', function ( $m ) { return preg_replace( '/(?<![\w-])(color\s*:\s*)#241A06/i', '${1}#FFFFFF', $m[0] ); }, $html );
		$html = preg_replace_callback( '/style="[^"]*background(?:-color)?\s*:\s*#111111[^"]*"/i', function ( $m ) { return preg_replace( '/(?<![\w-])(color\s*:\s*)#241A06/i', '${1}#FFFFFF', $m[0] ); }, $html );
		return $html;
	} );
}, 1 );

add_action( 'wp_head', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style id="dv-tema">
body.dv-app{--t-bg:#FAFAFA;--t-card:#FFFFFF;--t-ln:#EBEBEB;--t-ln2:#E0E0E0;--t-ink:#0A0A0A;--t-mu:#6B6B6B;--t-ouro:#D4B26A;--t-ouro2:#8C6A24;background:var(--t-bg)!important;-webkit-font-smoothing:antialiased;font-feature-settings:"ss01","tnum"}
body.dv-app,body.dv-app input,body.dv-app button,body.dv-app select,body.dv-app textarea{font-family:Geist,Inter,system-ui,sans-serif!important}
/* títulos: peso médio, espaçamento justo */
body.dv-app h1,body.dv-app h2,body.dv-app h3,body.dv-app h4,body.dv-app .dv-th h1,body.dv-app .dv-tool h1,body.dv-app .dv-tool h2{font-family:Geist,Inter,sans-serif!important;font-weight:600!important;letter-spacing:-.025em!important}
body.dv-app b,body.dv-app strong{font-weight:600}
body.dv-app .dv-th{padding-top:34px!important}
body.dv-app .dv-th small{display:none!important}
body.dv-app .dv-th h1{font-size:clamp(1.55rem,2.2vw,2rem)!important;margin:0 0 8px!important}
body.dv-app .dv-th p{color:var(--t-mu)!important;font-size:.95rem!important;line-height:1.6!important}

/* menu lateral preto */
body.dv-app .dv-side{background:#000!important;border-right:1px solid #161616!important}
body.dv-app .dv-side .lg b{color:#fff!important;letter-spacing:.12em!important;font-weight:500!important;font-size:.78rem!important}
body.dv-app .dv-side .lg b i{color:#fff!important;font-weight:700}
body.dv-app .dv-side .lg small{color:#6B6B6B!important;letter-spacing:.04em!important;text-transform:none!important;font-size:.7rem!important}
body.dv-app .dv-side .lg svg{color:#fff!important}
body.dv-app .dv-side .gt{text-transform:none!important;letter-spacing:0!important;font-size:.72rem!important;font-weight:500!important;color:#5E5E5E!important;margin:18px 10px 6px!important}
body.dv-app .dv-side nav a{color:#A1A1A1!important;font-size:.875rem!important;font-weight:400!important;padding:7px 10px!important;border-radius:8px!important}
body.dv-app .dv-side nav a .ic{color:#6B6B6B!important}
body.dv-app .dv-side nav a:hover{background:#111!important;color:#fff!important}
body.dv-app .dv-side nav a.on{background:#161616!important;color:#fff!important;font-weight:500!important;box-shadow:inset 2px 0 0 var(--t-ouro)!important}
body.dv-app .dv-side nav a.on .ic,body.dv-app .dv-side nav a:hover .ic{color:var(--t-ouro)!important}
body.dv-app .dv-side nav a .bd{background:#1F1F1F!important;color:#EDEDED!important;font-weight:500!important;font-size:.68rem!important}
body.dv-app .dv-side nav a .bd.r{background:#3A1712!important;color:#F4A090!important}
body.dv-app .dv-side .ft{border-top:1px solid #161616!important}
body.dv-app .dv-side .cr{background:#0B0B0B!important;border:1px solid #1C1C1C!important;border-radius:10px!important}
body.dv-app .dv-side .cr small{color:#6B6B6B!important}body.dv-app .dv-side .cr b{color:#fff!important;font-weight:500!important}body.dv-app .dv-side .cr span{color:var(--t-ouro)!important;font-weight:500!important}
body.dv-app .dv-side .us b{color:#EDEDED!important;font-weight:500!important}body.dv-app .dv-side .us,body.dv-app .dv-side .us a,body.dv-app .dv-side .hp{color:#6B6B6B!important}

/* barra superior */
body.dv-app .dv-top{background:rgba(250,250,250,.82)!important;border-bottom:1px solid var(--t-ln)!important}
body.dv-app .dv-k{border:1px solid var(--t-ln2)!important;border-radius:8px!important;box-shadow:0 1px 2px rgba(0,0,0,.04)}
body.dv-app .dv-k kbd{background:#fff!important;border-color:var(--t-ln2)!important;font-family:Geist,sans-serif!important;font-weight:500!important}
body.dv-app .dv-sld{border-color:var(--t-ln2)!important;box-shadow:none!important}
body.dv-app .dv-sld b{font-weight:600!important}
body.dv-app .dv-sld a{background:#0A0A0A!important;color:#fff!important;font-weight:500!important}
body.dv-app .dv-topm{background:#000!important}body.dv-app .dv-topm b i{color:#fff!important;font-weight:700}body.dv-app .dv-topm a{color:#fff!important;background:#1A1A1A!important}

/* faixa de notícias e avisos */
body.dv-app [class*=ticker],body.dv-app .dv-nt{font-family:Geist,sans-serif!important}
body.dv-app #dv-mon{background:#fff!important;border:1px solid var(--t-ln2)!important;border-left:3px solid #E0A33B!important;color:#3D3D3D!important;border-radius:10px!important;font-size:13.5px!important}

body.dv-app .dv-tk,body.dv-app .dv-tkg{background:#0A0A0A!important}
body.dv-app .dv-tk small,body.dv-app .dv-tkg small{color:var(--t-ouro)!important;font-weight:500!important;letter-spacing:.04em!important}
body.dv-app .dv-tk .vt,body.dv-app .dv-tkg .vt{color:#fff!important;font-weight:500!important}
body.dv-app .dv-tk b,body.dv-app .dv-tkg b{background:#1A1A1A!important;color:#fff!important;font-weight:500!important;letter-spacing:.04em!important}
/* cartões e áreas de trabalho */
body.dv-app .dv-tool>*,body.dv-app .dv-cnpj,body.dv-app .dv-next{border-radius:14px!important}
body.dv-app .dv-tool>div:first-child:not([class*=dv-pw]),body.dv-app .dv-cnpj{box-shadow:0 1px 2px rgba(0,0,0,.04)!important;border:1px solid var(--t-ln)!important}
body.dv-app .dvp .dv-c,body.dv-app .dvp .dv-tools a,body.dv-app .dv-hk{box-shadow:none!important;border-color:var(--t-ln)!important;border-radius:12px!important}
body.dv-app .dv-hk b{font-weight:500!important;letter-spacing:-.03em}
body.dv-app .dv-hi h2{font-weight:600!important}

/* campos */
body.dv-app .dv-tool input:not([type=checkbox]):not([type=radio]),body.dv-app .dv-tool select,body.dv-app .dv-tool textarea,body.dv-app .dv-cnpj input{border-radius:8px!important;border-color:var(--t-ln2)!important;box-shadow:0 1px 2px rgba(0,0,0,.03)}
body.dv-app .dv-tool input:focus,body.dv-app .dv-tool select:focus,body.dv-app .dv-tool textarea:focus,body.dv-app .dv-cnpj input:focus{outline:0!important;border-color:#0A0A0A!important;box-shadow:0 0 0 3px rgba(10,10,10,.08)!important}

/* botões: preto, peso médio, cantos de 8px */
body.dv-app .dv-tool button,body.dv-app .dvp button,body.dv-app .dv-cnpj button,body.dv-app a.dv-btn{border-radius:8px!important;font-weight:500!important;letter-spacing:0!important;transition:background .15s,border-color .15s}
body.dv-app .dv-cnpj button{background:#fff!important;border-color:var(--t-ln2)!important}
body.dv-app .dv-cnpj button.p{background:#0A0A0A!important;border-color:#0A0A0A!important;color:#fff!important}
body.dv-app .dv-cnpj button:hover{border-color:#0A0A0A!important}

/* tabelas: cabeçalho discreto, linhas finas, números alinhados */
body.dv-app .dv-tool table{border-collapse:separate!important;border-spacing:0!important;font-variant-numeric:tabular-nums}
body.dv-app .dv-tool th{background:#FAFAFA!important;color:#6B6B6B!important;font-weight:500!important;font-size:.78rem!important;text-transform:none!important;letter-spacing:0!important;border-bottom:1px solid var(--t-ln)!important}
body.dv-app .dv-tool td{border-color:var(--t-ln)!important}
body.dv-app .dv-tool tr:hover>td{background:#FAFAFA}

/* abas e filtros em pílula */
body.dv-app .dv-rnav{border-color:var(--t-ln)!important;border-radius:10px!important;box-shadow:0 1px 2px rgba(0,0,0,.04)!important}
body.dv-app .dv-rnav a{font-weight:500!important;border-radius:6px!important}
body.dv-app .dv-rnav a.on{background:#0A0A0A!important;color:#fff!important}

/* paleta de busca */
#dv-pal .bx{border-radius:12px!important;border:1px solid var(--t-ln2)}#dv-pal li .ic{color:#0A0A0A!important}#dv-pal li.on{background:#F5F5F5!important}#dv-pal .gp{text-transform:none!important;letter-spacing:0!important;font-weight:500!important}

/* vazio */
body.dv-app .lc-vazio{border:1px dashed var(--t-ln2)!important;background:#fff!important;border-radius:12px!important}
body.dv-app .lc-vazio::before{background-color:#F5F5F5!important}

/* seleção e rolagem */
body.dv-app ::selection{background:#0A0A0A;color:#fff}
</style>
	<?php
}, 120 );

/* rede de segurança: texto marrom-escuro que sobrou em cima de fundo agora preto vira branco */
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; }
	echo '<script>(function(){var T="rgb(36, 26, 6)";function bgDe(e){var p=e;while(p&&p.nodeType===1){var b=getComputedStyle(p).backgroundColor;if(b&&b!=="rgba(0, 0, 0, 0)"&&b!=="transparent")return b;p=p.parentElement}return "rgb(255, 255, 255)"}function fx(){var L=document.querySelectorAll(".dv-tool *,.dvp *,.dv-main *");for(var i=0;i<L.length;i++){var e=L[i];if(e.children.length>3)continue;var c=getComputedStyle(e).color;if(c!==T)continue;var m=bgDe(e).match(/\\d+/g);if(m&&(+m[0])+(+m[1])+(+m[2])<240){e.style.setProperty("color","#fff","important")}}}var t;function ag(){clearTimeout(t);t=setTimeout(fx,120)}if(document.readyState!=="loading")fx();else document.addEventListener("DOMContentLoaded",fx);new MutationObserver(ag).observe(document.body,{childList:true,subtree:true})})()</script>';
}, 99 );

/* explicação do topo das ferramentas: aberta nas 2 primeiras visitas, depois recolhida atrás de "Como funciona" */
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<style>.dv-ih{margin-left:14px;vertical-align:middle;font:600 13px/1 "Hanken Grotesk",-apple-system,sans-serif;padding:7px 12px;border:1px solid var(--t-ln2,#E0E0E0);border-radius:99px;background:#fff;color:var(--t-mu,#6B6B6B);cursor:pointer;letter-spacing:0;position:relative;top:-4px}.dv-ih:hover{border-color:#C9A45C;color:var(--t-ink,#0A0A0A)}</style>
<script>(function(){var s=document.querySelector('section.dv-th');if(!s)return;var h=s.querySelector('h1'),p=h&&h.nextElementSibling;if(!p||p.tagName!=='P'||p.textContent.trim().length<60)return;
var k='dv_intro_'+location.pathname,v=0;try{v=+localStorage.getItem(k)||0}catch(x){}
var b=document.createElement('button');b.type='button';b.className='dv-ih';h.appendChild(b);
function set(ab){p.hidden=!ab;b.textContent=ab?'Ocultar explicação':'Como funciona';b.setAttribute('aria-expanded',ab?'true':'false')}
set(v<2);try{localStorage.setItem(k,v+1)}catch(x){}
b.addEventListener('click',function(){set(p.hidden);try{localStorage.setItem(k,9)}catch(x){}})})();</script>
	<?php
}, 130 );

/* ===== Tema escuro futurista + menu lateral recolhível ===== */
add_action( 'wp_head', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<script>(function(){var d=document.documentElement;<?php if ( dv_tema_escuro() ) { echo "d.classList.add('dv-escuro');"; } ?>var f=null;try{f=localStorage.getItem('dv_menu_fixo')}catch(x){}if(f!=='1')d.classList.add('dv-rail')})();</script>
<!--dv-tema-off--><style id="dv-rail">
@media(min-width:981px){
html.dv-rail body.dv-app{padding-left:76px!important}
html.dv-rail body.dv-app .dv-top{left:76px!important}
html.dv-rail .dv-side{width:76px!important;overflow:hidden;transition:width .24s cubic-bezier(.2,.8,.2,1),box-shadow .24s}
html.dv-rail .dv-side.ab{width:264px!important;box-shadow:30px 0 70px rgba(0,0,0,.55)}
html.dv-rail .dv-side nav a,html.dv-rail .dv-side .lg{white-space:nowrap}
html.dv-rail .dv-side .lg{padding-left:23px!important;cursor:pointer}html.dv-rail .dv-side .lg svg{flex:none!important;min-width:30px!important;width:30px!important;height:30px!important;transition:filter .2s,transform .2s}
html.dv-rail .dv-side:not(.ab) .lg:hover svg{filter:drop-shadow(0 0 10px rgba(201,164,92,.75));transform:scale(1.06)}
html.dv-rail .dv-side:not(.ab) .lg svg{color:#E6C27A!important}
html.dv-rail .dv-side .gt,html.dv-rail .dv-side .lg b,html.dv-rail .dv-side .lg small,html.dv-rail .dv-side .ft,html.dv-rail .dv-side nav a .bd{transition:opacity .18s}
html.dv-rail .dv-side:not(.ab) .gt,html.dv-rail .dv-side:not(.ab) .lg b,html.dv-rail .dv-side:not(.ab) .lg small,html.dv-rail .dv-side:not(.ab) .ft,html.dv-rail .dv-side:not(.ab) nav a .bd,html.dv-rail .dv-side:not(.ab) .dv-pin{opacity:0;pointer-events:none}
html.dv-rail .dv-side:not(.ab) .gt{height:1px;margin:10px 14px!important;background:rgba(255,255,255,.07)}
html.dv-rail .dv-side nav{overflow-y:auto!important;overflow-x:hidden!important;scrollbar-width:none}html.dv-rail .dv-side nav::-webkit-scrollbar{display:none}html.dv-rail .dv-side:not(.ab) .ft{display:none}html.dv-rail .dv-side:not(.ab) nav a{padding-top:8px!important;padding-bottom:8px!important}
html.dv-rail body.dv-app .dv-side:not(.ab) nav a,html.dv-rail body.dv-app .dv-side:not(.ab) nav a.on,html.dv-rail body.dv-app .dv-side:not(.ab) nav a:hover{color:transparent!important;background:transparent!important}html.dv-rail body.dv-app .dv-side:not(.ab) nav a.on .ic{color:#E6C27A!important}html.dv-rail body.dv-app .dv-side:not(.ab) nav a.on{box-shadow:inset 2px 0 0 #C9A45C!important}html.dv-rail .dv-side nav a{transition:color .18s,background .18s}
html.dv-rail .dv-side nav a{padding-left:16px!important}
}
.dv-dica{position:fixed;z-index:9995;pointer-events:none;background:#0F1115;color:#ECEAE4;border:1px solid rgba(201,164,92,.35);border-radius:8px;padding:7px 11px;font:600 13px/1.2 Geist,"Hanken Grotesk",sans-serif;white-space:nowrap;box-shadow:0 10px 30px rgba(0,0,0,.5);opacity:0;transform:translateX(-4px);transition:opacity .12s,transform .12s}.dv-dica.on{opacity:1;transform:none}.dv-dica:before{content:"";position:absolute;left:-5px;top:50%;margin-top:-5px;width:8px;height:8px;background:#0F1115;border-left:1px solid rgba(201,164,92,.35);border-bottom:1px solid rgba(201,164,92,.35);transform:rotate(45deg)}
.dv-modo{border:0;background:transparent;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#0A0A0A;margin-right:2px;flex:none}.dv-modo svg{width:17px!important;height:17px!important;min-width:17px;flex:none;display:block}.dv-modo:hover{background:rgba(127,127,127,.14)}html.dv-escuro .dv-modo{color:#ECEAE4}
.dv-pin{position:absolute;top:24px;right:12px;width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:#A1A1A1;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;transition:opacity .18s,color .15s,border-color .15s}
.dv-pin:hover,.dv-pin[aria-pressed=true]{color:#E6C27A;border-color:rgba(201,164,92,.5)}
body.admin-bar .dv-pin{top:24px}
@media(max-width:980px){.dv-pin{display:none}}
</style><!--/dv-tema-off-->
<?php if ( dv_tema_escuro() ) { ?>
<!--dv-tema-off--><style id="dv-escuro">
html.dv-escuro body.dv-app{background:#0D0F12!important;color:#ECEAE4;color-scheme:dark;isolation:isolate}
html.dv-escuro body.dv-app::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(1100px 560px at 78% -12%,rgba(201,164,92,.13),transparent 62%),radial-gradient(800px 480px at 8% 112%,rgba(110,130,170,.08),transparent 60%)}
html.dv-escuro body.dv-app::after{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,.028) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px);background-size:56px 56px;-webkit-mask-image:radial-gradient(ellipse 70% 60% at 65% 0%,#000,transparent 75%);mask-image:radial-gradient(ellipse 70% 60% at 65% 0%,#000,transparent 75%)}
/* topo de vidro */
html.dv-escuro body.dv-app .dv-top{background:rgba(13,15,18,.7)!important;border-bottom:1px solid rgba(255,255,255,.06)!important;-webkit-backdrop-filter:saturate(160%) blur(16px);backdrop-filter:saturate(160%) blur(16px)}
html.dv-escuro body.dv-app .dv-k{background:rgba(255,255,255,.035)!important;border:1px solid rgba(255,255,255,.08)!important;color:#8F8D86!important;box-shadow:none!important}
html.dv-escuro body.dv-app .dv-k kbd{background:rgba(255,255,255,.06)!important;border-color:rgba(255,255,255,.1)!important;color:#A9A7A0!important}
html.dv-escuro body.dv-app .dv-sld{background:rgba(255,255,255,.035)!important;border:1px solid rgba(255,255,255,.08)!important;color:#ECEAE4!important}
html.dv-escuro body.dv-app .dv-sld span{color:#8F8D86!important}html.dv-escuro body.dv-app .dv-sld b{color:#F4E7C6!important}
html.dv-escuro body.dv-app .dv-sld a{background:linear-gradient(180deg,#E6C885,#C9A45C)!important;color:#14100A!important;font-weight:600!important}
html.dv-escuro .dv-bell{color:#ECEAE4!important}html.dv-escuro .dv-bell svg{stroke:#ECEAE4!important}html.dv-escuro .dv-bell:hover,html.dv-escuro .dv-bell[aria-expanded=true]{background:rgba(255,255,255,.07)!important}
html.dv-escuro .dv-bellp{background:#0F1115!important;border-color:rgba(255,255,255,.1)!important;box-shadow:0 30px 80px rgba(0,0,0,.6)!important}html.dv-escuro .dv-bellp .h{background:#0F1115!important;border-color:rgba(255,255,255,.07)!important}html.dv-escuro .dv-bellp .h b{color:#ECEAE4!important}html.dv-escuro .dv-bellp .it{color:#ECEAE4!important;border-color:rgba(255,255,255,.05)!important}html.dv-escuro .dv-bellp .it:hover{background:rgba(201,164,92,.07)!important}html.dv-escuro .dv-bellp .h a{color:#E6C27A!important}
/* títulos */
html.dv-escuro body.dv-app .dv-th h1,html.dv-escuro body.dv-app .dv-hi h2{background:linear-gradient(180deg,#FFFFFF 20%,#A8A59C);-webkit-background-clip:text;background-clip:text;color:transparent!important}
html.dv-escuro body.dv-app h2,html.dv-escuro body.dv-app h3,html.dv-escuro body.dv-app h4,html.dv-escuro body.dv-app .dv-tool h2,html.dv-escuro body.dv-app .dv-tool h3,html.dv-escuro body.dv-app .dv-tool h4,html.dv-escuro body.dv-app .rp-sec h3,html.dv-escuro body.dv-app .rp-sec h4{color:#F1EFE9!important}
html.dv-escuro body.dv-app .dv-th p,html.dv-escuro body.dv-app .dv-hi span{color:#8F8D86!important}
html.dv-escuro .dv-ih{background:rgba(255,255,255,.04)!important;border-color:rgba(255,255,255,.1)!important;color:#A9A7A0!important}html.dv-escuro .dv-ih:hover{color:#E6C27A!important;border-color:rgba(201,164,92,.5)!important}
/* cartões de vidro */
html.dv-escuro body.dv-app .dv-cx,html.dv-escuro body.dv-app .dv-agc,html.dv-escuro body.dv-app .dv-hk,html.dv-escuro body.dv-app .dv-cnpj,html.dv-escuro body.dv-app .dv-next,html.dv-escuro body.dv-app .dv-tool>div:first-child:not([class*=dv-pw]),html.dv-escuro body.dv-app .dv-pp,html.dv-escuro body.dv-app .lc-vazio{background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.015)),#14171C!important;border:1px solid rgba(255,255,255,.075)!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.05),0 24px 48px -24px rgba(0,0,0,.7)!important}
html.dv-escuro body.dv-app .dv-hk{transition:transform .2s,border-color .2s,box-shadow .2s!important}
html.dv-escuro body.dv-app .dv-hk:hover{border-color:rgba(201,164,92,.4)!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 0 0 1px rgba(201,164,92,.15),0 18px 40px -18px rgba(201,164,92,.35)!important}
html.dv-escuro body.dv-app .dv-hk b{background:linear-gradient(180deg,#F6E6BC,#C9A45C);-webkit-background-clip:text;background-clip:text;color:transparent!important;font-weight:600!important;font-variant-numeric:tabular-nums}
html.dv-escuro body.dv-app .dv-hk:not(.at):not(.ur) b{background:linear-gradient(180deg,#FFFFFF,#8F8D86);-webkit-background-clip:text;background-clip:text}
html.dv-escuro body.dv-app .dv-hk.ur b{background:linear-gradient(180deg,#FFC2B4,#E0705A);-webkit-background-clip:text;background-clip:text}
html.dv-escuro body.dv-app .dv-hk.at,html.dv-escuro body.dv-app .dv-hk.ur{position:relative;overflow:hidden}
html.dv-escuro body.dv-app .dv-hk.at::after,html.dv-escuro body.dv-app .dv-hk.ur::after{content:"";position:absolute;left:0;right:0;top:0;height:2px;background:linear-gradient(90deg,transparent,#C9A45C,transparent)}
html.dv-escuro body.dv-app .dv-hk.ur::after{background:linear-gradient(90deg,transparent,#E0705A,transparent)}
html.dv-escuro body.dv-app .dv-hk span{color:#ECEAE4!important}html.dv-escuro body.dv-app .dv-hk small{color:#8F8D86!important}
/* agenda em linha do tempo */
html.dv-escuro .dv-tl{position:relative}
html.dv-escuro .dv-tld{border-color:rgba(255,255,255,.06)!important}
html.dv-escuro .dv-tlk{box-shadow:0 0 0 3px rgba(255,255,255,.04),0 0 12px 1px currentColor;color:#C9A45C}
html.dv-escuro .dv-tlt b{color:#ECEAE4!important}html.dv-escuro .dv-tlt span{color:#8F8D86!important}
html.dv-escuro .dv-tli{border-radius:10px;padding:8px 8px!important;margin:0 -8px;transition:background .15s}html.dv-escuro .dv-tli:hover{background:rgba(255,255,255,.03)}
/* botões: primário dourado com brilho, secundário de vidro */
html.dv-escuro body.dv-app .dv-tla a.p,html.dv-escuro body.dv-app .dv-opf a.p,html.dv-escuro body.dv-app .dv-cnpj button.p,html.dv-escuro body.dv-app a.dv-btn,html.dv-escuro body.dv-app .dvp button:not(.dv-dd):not([data-v]):not(.dv-hmais):not(.dv-ppx):not([data-x]):not(.dv-ih),html.dv-escuro body.dv-app [style*="background:#0A0A0A"],html.dv-escuro body.dv-app [style*="background:#111111"],html.dv-escuro body.dv-app .dv-tool button[type=submit],html.dv-escuro body.dv-app .dv-tool .btn-gold{background:linear-gradient(180deg,#E8CB8A,#C9A45C)!important;color:#14100A!important;border-color:transparent!important;box-shadow:0 0 0 1px rgba(201,164,92,.35),0 10px 26px -10px rgba(201,164,92,.6)!important;font-weight:600!important}
html.dv-escuro body.dv-app .dv-tla a.p:hover,html.dv-escuro body.dv-app .dv-opf a.p:hover,html.dv-escuro body.dv-app .dv-cnpj button.p:hover,html.dv-escuro body.dv-app a.dv-btn:hover,html.dv-escuro body.dv-app .dv-tool button[type=submit]:hover{filter:brightness(1.08);box-shadow:0 0 0 1px rgba(201,164,92,.6),0 12px 34px -8px rgba(201,164,92,.75)!important}
html.dv-escuro body.dv-app .dv-tla a:not(.p),html.dv-escuro body.dv-app .dv-opf button,html.dv-escuro body.dv-app .dv-cnpj button:not(.p),html.dv-escuro body.dv-app .dv-agt button,html.dv-escuro body.dv-app .dv-calh button,html.dv-escuro body.dv-app .dv-calf button,html.dv-escuro body.dv-app .dv-hmais,html.dv-escuro body.dv-app .dv-qal a{background:rgba(255,255,255,.035)!important;border:1px solid rgba(255,255,255,.1)!important;color:#ECEAE4!important}
html.dv-escuro body.dv-app .dv-tla a:not(.p):hover,html.dv-escuro body.dv-app .dv-opf button:hover,html.dv-escuro body.dv-app .dv-cnpj button:not(.p):hover,html.dv-escuro body.dv-app .dv-qal a:hover,html.dv-escuro body.dv-app .dv-agt button.on{border-color:rgba(201,164,92,.55)!important;color:#F4E7C6!important;background:rgba(201,164,92,.06)!important}
html.dv-escuro body.dv-app .dv-cnpj button small,html.dv-escuro body.dv-app .dv-cnpj button span{color:inherit!important;opacity:.7}
html.dv-escuro body.dv-app .dv-tool button:not(.btn-gold):not([type=submit]),html.dv-escuro body.dv-app .dv-tool a.btn:not(.btn-gold){border:1px solid rgba(255,255,255,.14)!important}html.dv-escuro body.dv-app .dv-tool button:not(.btn-gold):not([type=submit]):hover{border-color:rgba(201,164,92,.55)!important}
/* campos */
html.dv-escuro body.dv-app input:not([type=checkbox]):not([type=radio]):not([type=range]),html.dv-escuro body.dv-app select,html.dv-escuro body.dv-app textarea{background:rgba(255,255,255,.03)!important;border:1px solid rgba(255,255,255,.1)!important;color:#ECEAE4!important;box-shadow:none!important}
html.dv-escuro body.dv-app input::placeholder,html.dv-escuro body.dv-app textarea::placeholder{color:#6F6D67!important}
html.dv-escuro body.dv-app input:focus,html.dv-escuro body.dv-app select:focus,html.dv-escuro body.dv-app textarea:focus{border-color:rgba(201,164,92,.7)!important;box-shadow:0 0 0 3px rgba(201,164,92,.16)!important;outline:0!important}
html.dv-escuro body.dv-app select option{background:#0F1115;color:#ECEAE4}
/* tabelas */
html.dv-escuro body.dv-app .dv-tool th,html.dv-escuro body.dv-app table th{background:rgba(255,255,255,.03)!important;color:#8F8D86!important;border-color:rgba(255,255,255,.07)!important}
html.dv-escuro body.dv-app td{border-color:rgba(255,255,255,.06)!important}
html.dv-escuro body.dv-app .dv-tool tr:hover>td,html.dv-escuro body.dv-app table.dv-h tr:hover>td{background:rgba(201,164,92,.04)!important}
html.dv-escuro body.dv-app .dv-h td{color:#8F8D86!important}html.dv-escuro body.dv-app .dv-h td:first-child{color:#ECEAE4!important}
/* menu lateral */
html.dv-escuro body.dv-app .dv-side{background:linear-gradient(180deg,#0A0B0D,#08090B)!important;border-right:1px solid rgba(255,255,255,.06)!important}
html.dv-escuro body.dv-app .dv-side nav a.on{background:linear-gradient(90deg,rgba(201,164,92,.16),rgba(201,164,92,.02))!important;box-shadow:inset 2px 0 0 #C9A45C!important;color:#fff!important}
html.dv-escuro body.dv-app .dv-side nav a.on .ic{color:#E6C27A!important;filter:drop-shadow(0 0 6px rgba(201,164,92,.6))}
html.dv-escuro body.dv-app .dv-rnav{background:rgba(10,11,14,.82)!important;border-color:rgba(255,255,255,.08)!important}html.dv-escuro body.dv-app .dv-rnav a{color:#A9A7A0!important}html.dv-escuro body.dv-app .dv-rnav a:hover{background:rgba(255,255,255,.05)!important;color:#ECEAE4!important}html.dv-escuro body.dv-app .dv-rnav a.on{background:rgba(201,164,92,.16)!important;color:#F4E7C6!important;box-shadow:inset 0 0 0 1px rgba(201,164,92,.45)}
/* gráfico */
html.dv-escuro .dv-gr rect{fill:rgba(201,164,92,.28)!important}html.dv-escuro .dv-gr rect.at{fill:#D9B86E!important;filter:drop-shadow(0 0 8px rgba(201,164,92,.7))}html.dv-escuro .dv-gr text{fill:#ECEAE4!important}html.dv-escuro .dv-gr text.lb{fill:#77756F!important}
/* links e destaques dourados */
html.dv-escuro body.dv-app .dv-cxh a,html.dv-escuro body.dv-app .dv-agm a,html.dv-escuro body.dv-app .dv-h a,html.dv-escuro body.dv-app .dv-tool a:not([class]){color:#E6C27A!important}
/* avisos */
html.dv-escuro body.dv-app #dv-mon{background:rgba(224,163,59,.08)!important;border:1px solid rgba(224,163,59,.3)!important;color:#F1D9A8!important}
/* rolagem e seleção */
html.dv-escuro{scrollbar-color:#2A2D33 #0D0F12}html.dv-escuro ::-webkit-scrollbar{width:10px;height:10px}html.dv-escuro ::-webkit-scrollbar-track{background:#0D0F12}html.dv-escuro ::-webkit-scrollbar-thumb{background:#24272D;border-radius:10px;border:2px solid #0D0F12}
html.dv-escuro body.dv-app ::selection{background:#C9A45C;color:#14100A}
/* entrada: um único momento orquestrado */
@media(prefers-reduced-motion:no-preference){html.dv-escuro body.dv-app .dv-hk,html.dv-escuro body.dv-app .dv-cx,html.dv-escuro body.dv-app .dv-agc{animation:dvIn .5s cubic-bezier(.2,.8,.2,1) both}html.dv-escuro body.dv-app .dv-hk:nth-child(2){animation-delay:.05s}html.dv-escuro body.dv-app .dv-hk:nth-child(3){animation-delay:.1s}html.dv-escuro body.dv-app .dv-hk:nth-child(4){animation-delay:.15s}@keyframes dvIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}
@media print{html.dv-escuro body.dv-app{background:#fff!important;color:#000!important}html.dv-escuro body.dv-app::before,html.dv-escuro body.dv-app::after{display:none}}
</style><!--/dv-tema-off-->
<?php }
}, 200 );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<?php if ( dv_tema_escuro() ) { ?>
<!--dv-tema-off--><script>(function(){
function rgb(h){h=h.slice(1);if(h.length===3)h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];return[parseInt(h.slice(0,2),16)/255,parseInt(h.slice(2,4),16)/255,parseInt(h.slice(4,6),16)/255]}
function hsl(r,g,b){var mx=Math.max(r,g,b),mn=Math.min(r,g,b),l=(mx+mn)/2,h=0,s=0;if(mx!==mn){var d=mx-mn;s=l>.5?d/(2-mx-mn):d/(mx+mn);h=mx===r?(g-b)/d+(g<b?6:0):mx===g?(b-r)/d+2:(r-g)/d+4;h/=6}return[h,s,l]}
function hex(h,s,l){function f(p,q,t){if(t<0)t+=1;if(t>1)t-=1;if(t<1/6)return p+(q-p)*6*t;if(t<.5)return q;if(t<2/3)return p+(q-p)*(2/3-t)*6;return p}var r,g,b;if(!s){r=g=b=l}else{var q=l<.5?l*(1+s):l+s-l*s,p=2*l-q;r=f(p,q,h+1/3);g=f(p,q,h);b=f(p,q,h-1/3)}return'#'+[r,g,b].map(function(x){return('0'+Math.round(x*255).toString(16)).slice(-2)}).join('').toUpperCase()}
function cor(x,papel){var c=rgb(x),k=hsl(c[0],c[1],c[2]),h=k[0],s=k[1],l=k[2],n=s<.18||l>.96;
 if(papel==='fundo'){if(l<.78)return x;if(n)return l>.975?'#101216':'#15181D';return hex(h,Math.min(s,.5),.11)}
 if(papel==='borda'){if(l<.62)return x;return n?'#262A31':hex(h,Math.min(s,.45),.27)}
 if(l>.5)return x;if(n)return l<.2?'#ECEAE4':l<.36?'#C9C7C0':'#9D9B94';return hex(h,Math.min(1,s+.1),.7)}
var RX=/(^|[^\w-])(background(?:-color)?|color|border(?:-(?:top|right|bottom|left))?(?:-color)?)(\s*:\s*)([^;]*)/g,HX=/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/g;
function bloco(t){var fm=false,m=t.match(/(?:^|[^\w-])background(?:-color)?\s*:\s*[^;]*?(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})\b/);if(m){var c=rgb(m[1]),k=hsl(c[0],c[1],c[2]);fm=k[2]>=.3&&k[2]<.78}
 return t.replace(RX,function(a,pre,p,sep,v){p=p.toLowerCase();var papel=p.indexOf('background')===0?'fundo':p==='color'?'texto':'borda';if(papel==='texto'&&fm)return a;return pre+p+sep+v.replace(HX,function(x){return cor(x,papel)})})}
var S=new WeakMap();function trata(el){if(el.nodeType!==1)return;var st=el.getAttribute('style');if(st&&/#[0-9a-fA-F]{3}/.test(st)&&S.get(el)!==st){var nv=bloco(st);S.set(el,nv);if(nv!==st)el.setAttribute('style',nv)}}
function varre(r){trata(r);if(r.querySelectorAll)[].forEach.call(r.querySelectorAll('[style]'),trata)}
var alvo=document.body;varre(alvo);new MutationObserver(function(ms){ms.forEach(function(m){if(m.type==='attributes')trata(m.target);else[].forEach.call(m.addedNodes,function(n){if(n.nodeType===1)varre(n)})})}).observe(alvo,{childList:true,subtree:true,attributes:true,attributeFilter:['style']});
})();</script><!--/dv-tema-off-->
<?php } ?>
<script>(function(){var sd=document.querySelector('.dv-sld');if(!sd)return;var esc=document.documentElement.classList.contains('dv-escuro'),b=document.createElement('button');b.type='button';b.className='dv-modo';b.title=esc?'Usar tema claro':'Usar tema escuro';b.setAttribute('aria-label',b.title);
b.innerHTML=esc?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/></svg>';
sd.insertBefore(b,sd.firstChild);b.addEventListener('click',function(){b.disabled=true;fetch(<?php echo wp_json_encode( rest_url( 'desenvolve/v1/tema' ) ); ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>},body:JSON.stringify({modo:esc?'claro':'escuro'})}).then(function(){var u=new URL(location.href);u.searchParams.set('tema',Date.now());location.replace(u.toString())}).catch(function(){b.disabled=false})})})();</script>
<script>(function(){var d=document.documentElement,s=document.querySelector('.dv-side');if(!s)return;
var b=document.createElement('button');b.type='button';b.className='dv-pin';s.appendChild(b);
function pinta(){var f=!d.classList.contains('dv-rail');b.setAttribute('aria-pressed',f?'true':'false');b.title=f?'Recolher o menu':'Deixar o menu fixo';b.setAttribute('aria-label',b.title);b.innerHTML=f?'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>':'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16h14v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>'}
pinta();
b.addEventListener('click',function(){var fixo=d.classList.contains('dv-rail');d.classList.toggle('dv-rail',!fixo);try{localStorage.setItem('dv_menu_fixo',fixo?'1':'0')}catch(x){}if(!fixo)s.classList.remove('ab');pinta()});
var t,lg=s.querySelector('.lg');if(lg)lg.addEventListener('mouseenter',function(){if(!d.classList.contains('dv-rail'))return;clearTimeout(t);dica.classList.remove('on');t=setTimeout(function(){s.classList.add('ab')},80)});
var dica=document.createElement('div');dica.className='dv-dica';dica.setAttribute('role','tooltip');document.body.appendChild(dica);
[].forEach.call(s.querySelectorAll('nav a'),function(a){a.addEventListener('mouseenter',function(){if(!d.classList.contains('dv-rail')||s.classList.contains('ab'))return;var c=a.cloneNode(true);[].forEach.call(c.querySelectorAll('.bd,.ic'),function(x){x.remove()});var tx=c.textContent.replace(/\s+/g,' ').trim();if(!tx)return;var bd=a.querySelector('.bd');dica.textContent=tx+(bd&&bd.textContent.trim()?' · '+bd.textContent.trim():'');var r=a.getBoundingClientRect();dica.style.left=(s.getBoundingClientRect().right+12)+'px';dica.style.top=(r.top+r.height/2-16)+'px';dica.classList.add('on')});a.addEventListener('mouseleave',function(){dica.classList.remove('on')})});
s.addEventListener('mouseleave',function(){clearTimeout(t);s.classList.remove('ab');dica.classList.remove('on')});
s.addEventListener('focusin',function(){if(d.classList.contains('dv-rail'))s.classList.add('ab')});s.addEventListener('focusout',function(ev){if(!s.contains(ev.relatedTarget))s.classList.remove('ab')});
if(d.classList.contains('dv-escuro')&&!(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)){var feito=new WeakSet();function conta(el){if(feito.has(el))return;feito.add(el);var alvo=parseInt(el.textContent,10);if(!(alvo>0)||alvo>9999)return;var t0=null;function q(ts){if(!t0)t0=ts;var p=Math.min(1,(ts-t0)/700);el.textContent=Math.round(alvo*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(q)}el.textContent='0';requestAnimationFrame(q)}
 var mo=new MutationObserver(function(){[].forEach.call(document.querySelectorAll('.dv-hk b'),conta)});mo.observe(document.body,{childList:true,subtree:true});setTimeout(function(){mo.disconnect()},8000)}
})();</script>
	<?php
}, 140 );
