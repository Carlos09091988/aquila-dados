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

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; }
	ob_start( function ( $html ) {
		if ( ! is_string( $html ) || $html === '' ) { return $html; }
		$html = strtr( $html, dv_tema_mapa() );
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
