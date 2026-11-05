/**
 * Desenvolve — Painel de notícias de licitações (/plataforma/noticias/)
 * Fonte: Google Notícias (RSS público de busca). Mostra só título, fonte, data e link para a matéria original.
 * Abas: Geral · Do seu ramo (termos dos alertas) · Seus órgãos (órgãos da Gestão) · Decisões (TCU e Tribunais de Contas).
 * Rota: GET desenvolve/v1/noticias?aba=  (assinantes). Cache: geral/decisões 2h; ramo/órgãos 6h por conjunto de termos.
 * Depende de: notícias do Raio-X (dv_nt_rss, dv_nt_norm).
 */

function dv_np_salvas( $uid ) { $l = json_decode( (string) get_user_meta( $uid, 'dv_np_salvas', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_np_gn( $q ) { return 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419'; }

function dv_np_tag( $t ) {
	$n = function_exists( 'dv_nt_norm' ) ? dv_nt_norm( $t ) : strtoupper( remove_accents( $t ) );
	$R = array(
		'Tribunal de Contas' => '/\b(TCU|TRIBUNAL DE CONTAS|TCE|TCM|ACORDAO)\b/',
		'Investigação'       => '/\b(OPERACAO|POLICIA|FRAUDE|INVESTIGA|MPF|MINISTERIO PUBLICO|PRESO|PRISAO|CARTEL|PROPINA|DESVIO|IMPROBIDADE|GAECO)/',
		'Legislação'         => '/\b(LEI|DECRETO|PROJETO DE LEI|14 133|INSTRUCAO NORMATIVA|PORTARIA|REGULAMENT|SANCIONA|CONGRESSO|SENADO|CAMARA DOS DEPUTADOS)\b/',
		'Disputa'            => '/\b(DESCLASSIFIC|INABILIT|RECURSO|IMPUGNA|VENCEDORA|VENCEDOR|ANULA|CANCELA LICITACAO|REVOGA)/',
		'Licitação aberta'   => '/\b(ABRE|LANCA|LANCAMENTO|PUBLICA EDITAL|EDITAL|PREGAO|CHAMAMENTO|CONCORRENCIA|PREVISTAS?|VAI CONTRATAR|AGENDA|LICITACAO DE R|LICITACOES ABERTAS)/',
		'Contratos'          => '/\b(CONTRAT|ADITIVO|RESCIS|REAJUSTE|REEQUILIBRIO|PAGAMENTO|ATRASO)/',
	);
	foreach ( $R as $k => $re ) { if ( preg_match( $re, $n ) ) { return $k; } }
	return 'Geral';
}

/** Só fontes confiáveis: órgãos oficiais, grande imprensa nacional/regional e imprensa jurídica. */
function dv_np_confiavel( $host, $nome ) {
	$host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
	/* veículos com paywall (o cliente não consegue ler sem assinatura): ficam de fora */
	$PAGO = array( 'folha.uol.com.br', 'estadao.com.br', 'valor.globo.com', 'oglobo.globo.com', 'epocanegocios.globo.com', 'gazetadopovo.com.br', 'jota.info', 'em.com.br', 'gauchazh.clicrbs.com.br', 'clicrbs.com.br', 'otempo.com.br', 'correiobraziliense.com.br', 'veja.abril.com.br', 'exame.com', 'bnamericas.com', 'agenciainfra.com', 'neofeed.com.br', 'opovo.com.br', 'nsctotal.com.br', 'diariodonordeste.verdesmares.com.br', 'agazeta.com.br', 'gazetaonline.com.br', 'correiodoestado.com.br', 'megawhat.energy', 'braziljournal.com', 'istoedinheiro.com.br', 'teletime.com.br', 'atarde.com.br', 'jc.uol.com.br', 'jc.ne10.uol.com.br', 'oliberal.com', 'opopular.com.br' );
	foreach ( $PAGO as $d ) { if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) { return false; } }
	if ( preg_match( '/^(FOLHA DE S\.?PAULO|FOLHA DE SAO PAULO|ESTADAO|O ESTADO DE S\.? ?PAULO|VALOR|VALOR ECONOMICO|VALOR INVESTE|O GLOBO|GAZETA DO POVO|JOTA|JOTA INFO|ESTADO DE MINAS|GZH|ZERO HORA|O TEMPO|CORREIO BRAZILIENSE|VEJA|EXAME|BNAMERICAS|AGENCIA INFRA|NEOFEED|O POVO|NSC TOTAL|DIARIO DO NORDESTE|A GAZETA|MEGAWHAT|BRAZIL JOURNAL|ISTOE DINHEIRO|EPOCA NEGOCIOS|A TARDE|JORNAL DO COMMERCIO|O LIBERAL|O POPULAR)$/', trim( strtoupper( remove_accents( (string) $nome ) ) ) ) ) { return false; }
	if ( $host && preg_match( '/\.(gov|jus|leg|mp|def)\.br$/', $host ) ) { return true; }
	$D = array( 'g1.globo.com', 'oglobo.globo.com', 'valor.globo.com', 'extra.globo.com', 'epocanegocios.globo.com', 'globo.com', 'folha.uol.com.br', 'uol.com.br', 'estadao.com.br', 'cnnbrasil.com.br', 'metropoles.com', 'poder360.com.br', 'exame.com', 'veja.abril.com.br', 'infomoney.com.br', 'band.uol.com.br', 'r7.com', 'terra.com.br', 'correiobraziliense.com.br', 'agenciabrasil.ebc.com.br', 'ebc.com.br', 'istoe.com.br', 'istoedinheiro.com.br', 'cartacapital.com.br', 'jota.info', 'conjur.com.br', 'migalhas.com.br', 'nexojornal.com.br', 'gazetadopovo.com.br', 'otempo.com.br', 'em.com.br', 'gauchazh.clicrbs.com.br', 'clicrbs.com.br', 'correiodopovo.com.br', 'atarde.com.br', 'jc.uol.com.br', 'jc.ne10.uol.com.br', 'diariodepernambuco.com.br', 'folhape.com.br', 'opovo.com.br', 'diariodonordeste.verdesmares.com.br', 'oliberal.com', 'acritica.com', 'tribunadonorte.com.br', 'jornaldocomercio.com', 'opopular.com.br', 'nsctotal.com.br', 'bnamericas.com', 'diariodotransporte.com.br', 'acidadeon.com', 'odia.ig.com.br', 'odia.com.br', 'seudinheiro.com', 'braziljournal.com', 'neofeed.com.br', 'convergenciadigital.com.br', 'teletime.com.br', 'portosenavios.com.br', 'agenciainfra.com', 'sollicita.com.br', 'zenite.blog.br', 'zenite.com.br', 'jornaldebrasilia.com.br', 'campograndenews.com.br', 'gazetaonline.com.br', 'agazeta.com.br', 'correiodoestado.com.br', 'gazetasp.com.br', 'diariodoaco.com.br', 'jornalcruzeiro.com.br' );
	foreach ( $D as $d ) { if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) { return true; } }
	$n = strtoupper( remove_accents( (string) $nome ) );
	return (bool) preg_match( '/^(G1|O GLOBO|VALOR|VALOR ECONOMICO|FOLHA DE S\.?PAULO|FOLHA DE SAO PAULO|UOL ECONOMIA|ESTADAO|CNN BRASIL|METROPOLES|PODER360|AGENCIA BRASIL|JOTA|CONSULTOR JURIDICO|CONJUR|MIGALHAS|EXAME|INFOMONEY|UOL|CORREIO BRAZILIENSE|GAZETA DO POVO|ESTADO DE MINAS|O TEMPO|GZH|A TARDE|TRIBUNA DO NORTE|DIARIO DO NORDESTE|O POVO|O LIBERAL|NSC TOTAL|BNAMERICAS|DIARIO DO TRANSPORTE|AGENCIA SENADO|AGENCIA CAMARA|CAMARA DOS DEPUTADOS|SENADO FEDERAL|R7|TERRA|BAND|VEJA|ISTOE|EPOCA|CARTACAPITAL|JORNAL DO COMMERCIO|CORREIO DO POVO|FOLHA DE PERNAMBUCO|AGENCIA INFRA|NEXO JORNAL|BRAZIL JOURNAL|WWW\.GOV\.BR|GOV\.BR|TCU|TCE[ -][A-Z]{2}|TRIBUNAL DE CONTAS( D[AEO]S?( ESTADO| MUNICIPIO)?( D[AEO])? [A-Z ]+)?)$/', trim( $n ) );
}
function dv_np_rss( $xml ) {
	$prev = libxml_use_internal_errors( true ); $x = simplexml_load_string( (string) $xml ); libxml_use_internal_errors( $prev );
	$out = array(); if ( ! $x || ! isset( $x->channel->item ) ) { return $out; }
	foreach ( $x->channel->item as $it ) {
		$t = html_entity_decode( (string) $it->title, ENT_QUOTES, 'UTF-8' ); $f = (string) $it->source; $su = isset( $it->source['url'] ) ? (string) $it->source['url'] : '';
		if ( $f && substr( $t, -strlen( ' - ' . $f ) ) === ' - ' . $f ) { $t = substr( $t, 0, -strlen( ' - ' . $f ) ); }
		$out[] = array( 'titulo' => trim( $t ), 'fonte' => $f, 'host' => $su ? (string) wp_parse_url( $su, PHP_URL_HOST ) : '', 'url' => (string) $it->link, 'data' => gmdate( 'Y-m-d', strtotime( (string) $it->pubDate ) ?: time() ) );
	}
	return $out;
}
function dv_np_buscar( $qs, $filtrar = true, $lim = 40 ) {
	$bodies = array();
	if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
		$reqs = array(); foreach ( $qs as $k => $q ) { $reqs[ $k ] = array( 'url' => dv_np_gn( $q ), 'type' => 'GET', 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ), 'options' => array( 'timeout' => 15 ) ); }
		try { $rs = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 15 ) ); } catch ( \Throwable $e ) { $rs = array(); }
		foreach ( $rs as $k => $r ) { if ( is_object( $r ) && isset( $r->status_code ) && $r->status_code === 200 ) { $bodies[ $k ] = $r->body; } }
	} else {
		foreach ( $qs as $k => $q ) { $r = wp_remote_get( dv_np_gn( $q ), array( 'timeout' => 15 ) ); if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) { $bodies[ $k ] = wp_remote_retrieve_body( $r ); } }
	}
	if ( ! $bodies ) { return null; }
	$out = array(); $vis = array();
	$rel = '/\b(LICITA|PREGAO|EDITAL|CONTRAT|TCU|TRIBUNAL DE CONTAS|COMPRAS? PUBLICAS?|14 133|DISPENSA|CONCORRENCIA|ATA DE REGISTRO|LICITANTE|FORNECEDOR|CONCESS|PPP|PARCERIA PUBLICO)/';
	foreach ( $bodies as $k => $b ) {
		foreach ( dv_np_rss( $b ) as $it ) {
			if ( ! dv_np_confiavel( $it['host'], $it['fonte'] ) ) { continue; }
			$n = dv_nt_norm( $it['titulo'] ); $h = md5( substr( $n, 0, 70 ) );
			if ( isset( $vis[ $h ] ) || mb_strlen( $it['titulo'] ) < 25 ) { continue; }
			if ( preg_match( '/\b(CONCURSOS?|VAGAS|GABARITO|RESIDENCIA MEDICA|PROCESSO SELETIVO|SIMULADO|APOSTILA|PROVA OBJETIVA|ENEM|VESTIBULAR|NOTA DE CORTE)\b/', $n ) ) { continue; }
			if ( $filtrar && ! preg_match( $rel, $n ) ) { continue; }
			$vis[ $h ] = 1;
			$out[] = array( 't' => $it['titulo'], 'f' => preg_replace( '/^www\./', '', $it['fonte'] ), 'u' => $it['url'], 'd' => $it['data'], 'g' => dv_np_tag( $it['titulo'] ), 'q' => is_string( $k ) && strpos( $k, ':' ) ? substr( $k, strpos( $k, ':' ) + 1 ) : '' );
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['d'], $a['d'] ); } );
	$pf = array(); $out = array_values( array_filter( $out, function ( $x ) use ( &$pf ) { $k = strtolower( $x['f'] ); $pf[ $k ] = ( $pf[ $k ] ?? 0 ) + 1; return $pf[ $k ] <= 4; } ) );
	return array_slice( $out, 0, $lim );
}

function dv_np_orgao( $o ) {
	$o = trim( preg_replace( '/\s+/', ' ', (string) $o ) ); if ( $o === '' ) { return ''; }
	$u = strtoupper( remove_accents( $o ) );
	if ( preg_match( '/^(MUNICIPIO|PREFEITURA MUNICIPAL|PREFEITURA) DE (.+)$/', $u, $m ) ) { $o = 'Prefeitura de ' . mb_convert_case( mb_strtolower( preg_replace( '/^(MUNICIPIO|PREFEITURA MUNICIPAL|PREFEITURA|MUNICÍPIO) DE /iu', '', $o ) ), MB_CASE_TITLE ); }
	else { $o = mb_convert_case( mb_strtolower( $o ), MB_CASE_TITLE ); }
	return str_replace( array( ' De ', ' Da ', ' Do ', ' Das ', ' Dos ', ' E ' ), array( ' de ', ' da ', ' do ', ' das ', ' dos ', ' e ' ), $o );
}

function dv_np_aba( $uid, $aba ) {
	if ( $aba === 'geral' || $aba === 'decisoes' ) {
		$ck = 'dv_np6_' . $aba; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
		$qs = $aba === 'geral'
			? array( 'licitação when:7d', 'licitações when:7d', 'pregão eletrônico when:14d', '"compras públicas" when:14d', '"Lei 14.133" when:30d', 'licitação prefeitura when:7d', 'licitação governo when:7d', 'contrato emergencial when:14d', 'superfaturamento licitação when:30d', 'licitação suspensa when:14d' )
			: array( 'TCU licitação when:30d', '"Tribunal de Contas" licitação when:14d', 'TCU acórdão contratação when:60d', '"Tribunal de Contas" suspende licitação when:30d', 'TCE licitação when:14d', 'TCU contrato irregularidade when:30d' );
		$l = dv_np_buscar( $qs ); if ( $l === null ) { return array( 'itens' => array(), 'aviso' => 'As notícias não carregaram agora. Tente de novo em alguns minutos.' ); }
		$r = array( 'itens' => $l, 'em' => current_time( 'mysql' ) ); set_transient( $ck, $r, 2 * HOUR_IN_SECONDS ); return $r;
	}
	if ( $aba === 'ramo' ) {
		$t = array(); if ( function_exists( 'dv_al_lista' ) ) { foreach ( dv_al_lista( $uid ) as $a ) { foreach ( preg_split( '/[,;\n]+/', (string) ( $a['termos'] ?? '' ) ) as $x ) { $x = trim( $x ); if ( mb_strlen( $x ) >= 3 ) { $t[ mb_strtolower( $x ) ] = $x; } } } }
		$t = array_slice( array_values( $t ), 0, 6 );
		if ( ! $t ) { return array( 'itens' => array(), 'vazio' => 'ramo' ); }
		$qs = array(); foreach ( $t as $x ) { $qs[ 'r:' . $x ] = '"' . $x . '" (licitação OR pregão OR contrato) when:90d'; }
	} elseif ( $aba === 'orgaos' ) {
		$t = array(); if ( function_exists( 'dv_lic_itens' ) ) { $UF = array( 'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins' ); foreach ( array_reverse( dv_lic_itens( $uid ) ) as $x ) { $o = dv_np_orgao( $x['orgao'] ?? '' ); if ( ! $o || mb_strlen( $o ) < 8 ) { continue; } $uf = strtoupper( (string) ( $x['uf'] ?? '' ) ); $mu = trim( (string) ( $x['municipio'] ?? '' ) ); $ctx = ''; if ( stripos( $o, 'Prefeitura de' ) !== 0 ) { if ( preg_match( '/\b(estado|estadual|governo)\b/iu', $o ) && isset( $UF[ $uf ] ) ) { $ctx = $UF[ $uf ]; } elseif ( $mu ) { $ctx = mb_convert_case( mb_strtolower( $mu ), MB_CASE_TITLE ); } } $t[ $o . '|' . $ctx ] = array( $o, $ctx ); } }
		$t = array_slice( array_values( $t ), 0, 6 );
		if ( ! $t ) { return array( 'itens' => array(), 'vazio' => 'orgaos' ); }
		$qs = array(); foreach ( $t as $x ) { $qs[ 'o:' . $x[0] ] = '"' . $x[0] . '"' . ( $x[1] ? ' "' . $x[1] . '"' : '' ) . ' (licitação OR contrato OR pregão OR edital) when:120d'; } $t = array_map( function ( $x ) { return $x[1] ? $x[0] . ' (' . $x[1] . ')' : $x[0]; }, $t );
	} else { return new WP_Error( 'dv_aba', 'Aba inválida.', array( 'status' => 400 ) ); }
	$ck = 'dv_np_v6' . md5( $aba . wp_json_encode( $t ) ); $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	$l = dv_np_buscar( $qs, true, 50 ); if ( $l === null ) { return array( 'itens' => array(), 'aviso' => 'As notícias não carregaram agora. Tente de novo em alguns minutos.', 'termos' => $t ); }
	$r = array( 'itens' => $l, 'termos' => $t, 'em' => current_time( 'mysql' ) ); set_transient( $ck, $r, 6 * HOUR_IN_SECONDS ); return $r;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/noticias', array( 'methods' => 'GET', 'permission_callback' => function () { return is_user_logged_in() && ( ! function_exists( 'dv_tools_perm' ) || dv_tools_perm() ); },
		'callback' => function ( WP_REST_Request $r ) { $a = sanitize_key( (string) ( $r['aba'] ?: 'geral' ) ); if ( $a === 'salvas' ) { $l = dv_np_salvas( get_current_user_id() ); return array( 'itens' => $l, 'vazio' => $l ? null : 'salvas' ); } return dv_np_aba( get_current_user_id(), $a ); } ) );
	register_rest_route( 'desenvolve/v1', '/noticias/salvar', array( 'methods' => 'POST', 'permission_callback' => function () { return is_user_logged_in(); },
		'callback' => function ( WP_REST_Request $r ) {
			$uid = get_current_user_id(); $l = dv_np_salvas( $uid ); $u = esc_url_raw( (string) $r['u'] ); if ( ! $u ) { return new WP_Error( 'dv_np', 'Notícia inválida.', array( 'status' => 400 ) ); }
			$ja = false; foreach ( $l as $i => $x ) { if ( $x['u'] === $u ) { unset( $l[ $i ] ); $ja = true; } }
			if ( ! $ja ) { array_unshift( $l, array( 't' => sanitize_text_field( (string) $r['t'] ), 'f' => sanitize_text_field( (string) $r['f'] ), 'u' => $u, 'd' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['d'] ) ? (string) $r['d'] : gmdate( 'Y-m-d' ), 'g' => sanitize_text_field( (string) $r['g'] ), 'q' => '' ) ); }
			$l = array_slice( array_values( $l ), 0, 200 ); update_user_meta( $uid, 'dv_np_salvas', wp_slash( wp_json_encode( $l, JSON_UNESCAPED_UNICODE ) ) );
			return array( 'salva' => ! $ja, 'total' => count( $l ) );
		} ) );
} );

add_action( 'init', function () {
	if ( get_option( 'dv_np_pagina' ) === '1' ) { return; }
	if ( ! get_page_by_path( 'plataforma/noticias' ) ) {
		$pai = get_page_by_path( 'plataforma' );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'noticias', 'post_parent' => $pai ? $pai->ID : 0, 'post_title' => 'Notícias de licitações',
			'post_content' => '[dv_ctx tool="noticias"]<section class="dv-th"><small>Ferramenta Desenvolve</small><h1>Notícias de licitações</h1><p>O que saiu na imprensa sobre licitações, contratos públicos e Tribunais de Contas, com as notícias do seu ramo e dos órgãos que você acompanha.</p></section>[dv_noticias]' ) );
		if ( $id && ! is_wp_error( $id ) ) { update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); }
	}
	update_option( 'dv_np_pagina', '1', false );
}, 30 );

add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() && is_page( 'noticias' ) ) { wp_safe_redirect( home_url( '/' ), 302 ); exit; }
} );

add_shortcode( 'dv_noticias', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="dv-tool"><div class="np" id="dv-np" data-api="<?php echo esc_url( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
<div class="np-bar"><div class="np-tabs" role="tablist"><button type="button" data-a="geral" class="on">Em destaque</button><button type="button" data-a="ramo">Do seu ramo</button><button type="button" data-a="orgaos">Seus órgãos</button><button type="button" data-a="decisoes">Tribunais de Contas</button><button type="button" data-a="salvas">Salvas</button></div><input type="search" class="np-q" placeholder="Filtrar por palavra…" aria-label="Filtrar notícias"></div>
<div class="np-tags"></div><div class="np-list"></div>
<p class="np-ft">Só fontes confiáveis e de leitura livre: órgãos oficiais, grande imprensa e imprensa jurídica, sem sites que exigem assinatura. Cada notícia abre no site do veículo que a publicou.</p>
</div></div>
<style>
.np{background:#fff;border:1px solid #E6E1D6;border-radius:18px;padding:18px 20px;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif}
.np-bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}
.np-tabs{display:flex;gap:6px;flex-wrap:wrap}.np-tabs button{background:#F4F2EC!important;color:#16191E!important;border:1px solid transparent!important;border-radius:999px;padding:8px 14px;font:700 .86rem inherit;cursor:pointer}.np-tabs button.on{background:#16191E!important;color:#F4F2EC!important}
.np-q{flex:0 1 240px;border:1px solid #D9D3C4!important;border-radius:10px;padding:9px 12px;font:inherit;font-size:.9rem;background:#fff!important;color:#16191E!important}
.np-tags{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 4px}.np-tags button{background:#fff!important;border:1px solid #E6E1D6!important;color:#5E5A52!important;border-radius:999px;padding:5px 11px;font:600 .78rem inherit;cursor:pointer}.np-tags button.on{border-color:#C9A45C!important;color:#16191E!important;background:#FBF6EA!important}
.np-list{display:flex;flex-direction:column}
.np-it{display:flex;gap:14px;align-items:flex-start;padding:14px 4px;border-bottom:1px solid #EFEBE2}.np-it:last-child{border-bottom:0}
.np-it .tx{flex:1;min-width:0}.np-it a.t{display:block;color:#16191E!important;font-weight:700;font-size:1rem;line-height:1.35;text-decoration:none!important}.np-it a.t:hover{color:#86621F!important}
.np-it .m{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px;font-size:.8rem;color:#5E5A52}
.np-g{font-weight:700;font-size:.72rem;padding:2px 8px;border-radius:999px;background:#F1EBDD;color:#6B4F17}.np-g.g1{background:#E7EEF8;color:#2A4A7A}.np-g.g2{background:#FBE9E5;color:#8E2F1F}.np-g.g3{background:#EAF3EC;color:#245C3B}
.np-it .ac{display:flex;gap:6px;flex:none;align-items:center;flex-wrap:wrap}.np-it .sv,.np-it .wa{flex:none;background:#fff!important;border:1px solid #E6E1D6!important;color:#16191E!important;border-radius:9px;padding:7px 10px;font:700 .76rem inherit;cursor:pointer;white-space:nowrap;text-decoration:none!important}.np-it .sv.on{border-color:#C9A45C!important;background:#FBF6EA!important;color:#6B4F17!important}.np-it .wa{color:#1E7D45!important}.np-it .sv:hover,.np-it .wa:hover{border-color:#C9A45C!important}.np-nv{font-weight:800;font-size:.68rem;padding:2px 7px;border-radius:999px;background:#16191E;color:#F4F2EC}
.np-it .ia{flex:none;background:#fff!important;border:1px solid #E6E1D6!important;color:#16191E!important;border-radius:9px;padding:7px 10px;font:700 .76rem inherit;cursor:pointer;white-space:nowrap}.np-it .ia:hover{border-color:#C9A45C!important}
.np-vz{text-align:center;padding:36px 16px;border:1.5px dashed #D9D2C2;border-radius:14px;color:#5E5A52;background:#FBFAF6;margin-top:12px}.np-vz a{color:#86621F!important;font-weight:700}
.np-sk{height:58px;border-radius:12px;margin-top:10px;background:linear-gradient(90deg,#EEEAE1 25%,#F7F5F0 50%,#EEEAE1 75%);background-size:200% 100%;animation:dvsk 1.3s infinite linear}@keyframes dvsk{0%{background-position:200% 0}100%{background-position:-200% 0}}
.np-ft{font-size:.76rem;color:#8E8A80;margin:14px 0 0}
@media(max-width:640px){.np{padding:14px}.np-it{flex-direction:column;gap:8px}.np-q{flex:1 1 100%}}
</style>
<script>
(function(){var R=document.getElementById('dv-np');if(!R)return;var api=R.dataset.api,nc=R.dataset.nonce,C={},aba='geral',tag='',q='';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function nz(s){return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'')}
function quando(d){var h=new Date();h.setHours(0,0,0,0);var x=new Date(d+'T12:00:00');var n=Math.round((h-x)/864e5);return n<=0?'hoje':n===1?'ontem':n<7?'há '+n+' dias':d.split('-').reverse().join('/')}
var SV={},VI=null;function hk(u){var h=0;u=String(u);for(var i=0;i<u.length;i++)h=(h*31+u.charCodeAt(i))|0;return(h>>>0).toString(36)}try{VI=JSON.parse(localStorage.getItem('dv_np_vis')||'null')}catch(x){}
function marca(){var it=[];['geral','ramo','orgaos','decisoes'].forEach(function(k){if(C[k]&&C[k].itens)it=it.concat(C[k].itens)});var o={};try{o=JSON.parse(localStorage.getItem('dv_np_vis')||'{}')||{}}catch(x){}it.forEach(function(x){o[hk(x.u)]=1});var ks=Object.keys(o);if(ks.length>600){var n={};ks.slice(-600).forEach(function(k){n[k]=1});o=n}try{localStorage.setItem('dv_np_vis',JSON.stringify(o))}catch(x){}}
var GC={'Tribunal de Contas':'g1','Investigação':'g2','Licitação aberta':'g3'};
var VZ={salvas:'Nenhuma notícia salva ainda. Toque em <b>Salvar</b> em qualquer notícia para guardar aqui e ler depois.',ramo:'Para ver as notícias do seu ramo, <a href="/plataforma/alertas/">crie um alerta de oportunidades</a> com as palavras do seu segmento (por exemplo: limpeza, vigilância, merenda).',orgaos:'Para ver notícias dos órgãos que você acompanha, <a href="/plataforma/licitacoes/">importe uma licitação na Gestão</a>.'};
function pinta(){var L=R.querySelector('.np-list'),T=R.querySelector('.np-tags'),d=C[aba];if(!d){L.innerHTML='<div class="np-sk"></div><div class="np-sk"></div><div class="np-sk"></div><div class="np-sk"></div>';T.innerHTML='';return}
 if(d.vazio){T.innerHTML='';L.innerHTML='<div class="np-vz">'+VZ[d.vazio]+'</div>';return}
 var it=d.itens||[],cont={};it.forEach(function(x){cont[x.g]=(cont[x.g]||0)+1});
 T.innerHTML=(d.termos&&d.termos.length?'<span style="font-size:.78rem;color:#5E5A52;align-self:center">Buscando: '+d.termos.map(e).join(' · ')+'</span>':'')+(it.length?'<button type="button" data-g="" class="'+(tag?'':'on')+'">Tudo · '+it.length+'</button>'+Object.keys(cont).map(function(k){return'<button type="button" data-g="'+e(k)+'" class="'+(tag===k?'on':'')+'">'+e(k)+' · '+cont[k]+'</button>'}).join(''):'');
 var nq=nz(q),f=it.filter(function(x){return(!tag||x.g===tag)&&(!nq||nz(x.t+' '+x.f).indexOf(nq)>=0)});
 L.innerHTML=f.length?f.map(function(x,i){return'<div class="np-it"><div class="tx"><a class="t" href="'+e(x.u)+'" target="_blank" rel="noopener">'+e(x.t)+'</a><div class="m">'+(aba!=='salvas'&&VI&&!VI[hk(x.u)]?'<span class="np-nv">Nova</span>':'')+'<span class="np-g '+(GC[x.g]||'')+'">'+e(x.g)+'</span><span>'+e(x.f)+'</span><span>·</span><span>'+quando(x.d)+'</span>'+(x.q?'<span>·</span><span>sobre “'+e(x.q)+'”</span>':'')+'</div></div><div class="ac"><button type="button" class="ia" data-i="'+it.indexOf(x)+'">Analisar com a minha IA</button><button type="button" class="sv'+(SV[x.u]?' on':'')+'" data-s="'+it.indexOf(x)+'">'+(SV[x.u]?'Salva ✓':'Salvar')+'</button><a class="wa" target="_blank" rel="noopener" href="https://wa.me/?text='+encodeURIComponent(x.t+'\n'+x.u+'\n\nVi na Desenvolve Estratégias, plataforma de inteligência em licitações: https://desenvolveestrategias.com.br')+'">WhatsApp</a></div></div>'}).join(''):'<div class="np-vz">'+(d.aviso?e(d.aviso):'Nenhuma notícia encontrada'+(q||tag?' com esse filtro.':' agora. Volte mais tarde.'))+'</div>'}
function carrega(a){if(C[a]){pinta();return}pinta();fetch(api+'noticias?aba='+a,{headers:{'X-WP-Nonce':nc},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){C[a]=j&&j.itens?j:{itens:[],aviso:(j&&j.message)||'As notícias não carregaram agora.'};if(a===aba)pinta()}).catch(function(){C[a]={itens:[],aviso:'As notícias não carregaram agora. Tente de novo em alguns minutos.'};if(a===aba)pinta()})}
R.addEventListener('click',function(ev){var b=ev.target.closest('[data-a]');if(b){aba=b.dataset.a;tag='';[].forEach.call(R.querySelectorAll('[data-a]'),function(z){z.classList.toggle('on',z===b)});try{localStorage.setItem('dv_np_aba',aba)}catch(x){}carrega(aba);return}
 var g=ev.target.closest('[data-g]');if(g){tag=g.dataset.g;pinta();return}
 var sv=ev.target.closest('.sv');if(sv){var x=C[aba].itens[+sv.dataset.s];sv.disabled=true;fetch(api+'noticias/salvar',{method:'POST',headers:{'X-WP-Nonce':nc,'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(x)}).then(function(r){return r.json()}).then(function(j){if(j.salva)SV[x.u]=1;else delete SV[x.u];var cur=aba;return fetch(api+'noticias?aba=salvas',{headers:{'X-WP-Nonce':nc},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j2){C.salvas=j2;pinta()})})['catch'](function(){sv.disabled=false});return}
 var ia=ev.target.closest('.ia');if(ia&&window.dvMinhaIA){var x=C[aba].itens[+ia.dataset.i];window.dvMinhaIA('noticia','Notícia: '+x.t+'\nVeículo: '+x.f+'\nData: '+x.d.split('-').reverse().join('/')+'\nLink: '+x.u)}});
R.querySelector('.np-q').addEventListener('input',function(){q=this.value;pinta()});
try{var sa=localStorage.getItem('dv_np_aba');if(sa&&/^(geral|ramo|orgaos|decisoes)$/.test(sa)){aba=sa;[].forEach.call(R.querySelectorAll('[data-a]'),function(z){z.classList.toggle('on',z.dataset.a===aba)})}}catch(x){}
fetch(api+'noticias?aba=salvas',{headers:{'X-WP-Nonce':nc},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){(j.itens||[]).forEach(function(x){SV[x.u]=1});C.salvas=j;pinta()}).catch(function(){});
carrega(aba);setTimeout(marca,4000);window.addEventListener('pagehide',marca);
})();
</script>
	<?php
	return ob_get_clean();
} );

/* contador "N novas" no menu, desde a última visita à página de notícias */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; }
	if ( function_exists( 'dv_tools_perm' ) && ! dv_tools_perm() ) { return; } ?>
<style>
body.dv-app.dv-tkon{padding-top:98px!important}
body.dv-bellon #dv-tk{display:none!important}.dv-bell svg{display:block!important;width:18px!important;height:18px!important;stroke:#0A0A0A!important;fill:none!important;opacity:1!important}.dv-bellp .h b{color:#0A0A0A!important;font-weight:800!important}.dv-sld:has(.dv-bell){padding-left:5px}
.dv-bell{position:relative;border:0;background:transparent;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#0A0A0A;margin-right:8px;flex:none}.dv-bell:hover,.dv-bell[aria-expanded=true]{background:#F2EFE7}
.dv-bell i{position:absolute;top:1px;right:0;min-width:16px;height:16px;border-radius:8px;background:#B3402A;color:#fff;font:700 10px/16px -apple-system,sans-serif;font-style:normal;padding:0 4px;text-align:center}
.dv-bellp{position:fixed;z-index:9990;width:380px;max-width:calc(100vw - 24px);max-height:70vh;overflow:auto;background:#fff;border:1px solid #E0E0E0;border-radius:14px;box-shadow:0 18px 40px rgba(0,0,0,.14);font-family:"Hanken Grotesk",-apple-system,sans-serif}
.dv-bellp .h{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #EBEBEB;position:sticky;top:0;background:#fff}.dv-bellp .h b{font-size:.92rem}.dv-bellp .h a{color:#86621F!important;font-weight:700;font-size:.82rem;text-decoration:none!important}
.dv-bellp .it{display:block;padding:11px 16px;border-bottom:1px solid #F2F2F2;color:#0A0A0A!important;text-decoration:none!important;font-size:.86rem;line-height:1.38}.dv-bellp .it:last-child{border-bottom:0}.dv-bellp .it small{display:block;color:#6B6B6B;font-size:.72rem;margin-bottom:3px}.dv-bellp .it:hover{background:#FAF8F3}
.dv-tkg{position:fixed;top:60px;left:264px;right:0;height:38px;z-index:9979;display:flex;align-items:center;background:#16191E;overflow:hidden;font-family:"Hanken Grotesk",-apple-system,sans-serif}
body.admin-bar .dv-tkg{top:92px}
.dv-tkg>b{flex:none;background:#C9A45C;color:#241A06;height:100%;display:flex;align-items:center;padding:0 14px;font-size:.7rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
.dv-tkg .w{overflow:hidden;flex:1;-webkit-mask-image:linear-gradient(90deg,transparent,#000 3%,#000 97%,transparent);mask-image:linear-gradient(90deg,transparent,#000 3%,#000 97%,transparent)}
.dv-tkg .t{display:inline-flex;gap:44px;white-space:nowrap;animation:dvtkg 120s linear infinite;padding-left:20px;will-change:transform}.dv-tkg:hover .t{animation-play-state:paused}
.dv-tkg .t a{color:#F4F2EC!important;text-decoration:none!important;font-weight:500;font-size:.86rem}.dv-tkg .t a:hover{color:#E6C27A!important}.dv-tkg .t small{color:#C9A45C;font-weight:800;margin-right:8px;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em}.dv-tkg .t i{font-style:normal;color:#8E938F;margin-left:8px;font-size:.74rem}
.dv-tkg .vt{flex:none;color:#C9A45C!important;font-weight:700;font-size:.78rem;padding:0 14px;text-decoration:none!important;border-left:1px solid #2A3038;height:100%;display:flex;align-items:center}
@keyframes dvtkg{from{transform:translateX(0)}to{transform:translateX(-50%)}}@media(prefers-reduced-motion:reduce){.dv-tkg .t{animation:none}.dv-tkg .w{overflow-x:auto}}
body.dv-tkon #dv-tk{display:none!important}
body.dv-tkon .dv-rnav{top:106px!important}body.admin-bar.dv-tkon .dv-rnav{top:138px!important}body.dv-tkon .dv-tool .rx-out>*,body.dv-tkon .dv-tool .rp>*{scroll-margin-top:180px}@media(max-width:980px){body.dv-tkon .dv-rnav{top:96px!important}body.admin-bar.dv-tkon .dv-rnav{top:142px!important}}
@media(max-width:980px){.dv-tkg{left:0;top:56px;height:34px}body.admin-bar .dv-tkg{top:102px}body.dv-app.dv-tkon{padding-top:90px!important}.dv-tkg .vt{display:none}.dv-tkg>b{padding:0 10px}}
@media print{.dv-tkg{display:none!important}body.dv-app.dv-tkon{padding-top:0!important}}
</style>
<script>(function(){function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
window.dvTicker=function(l){if(!l||!l.length||document.querySelector('.dv-bell'))return;var sd=document.querySelector('.dv-sld');if(!sd)return;var N=window.dvNpN||0;
var b=document.createElement('button');b.type='button';b.className='dv-bell';b.setAttribute('aria-label','Notícias de licitações'+(N?' ('+N+' novas)':''));b.setAttribute('aria-expanded','false');
b.innerHTML='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'+(N?'<i>'+(N>9?'9+':N)+'</i>':'');
var p=document.createElement('div');p.className='dv-bellp';p.hidden=true;p.setAttribute('role','dialog');p.setAttribute('aria-label','Notícias de licitações');
p.innerHTML='<div class="h"><b>Notícias de licitações</b><a href="/plataforma/noticias/">Ver todas</a></div>'+l.slice(0,8).map(function(x){return'<a class="it" href="'+e(x.u)+'" target="_blank" rel="noopener"><small>'+e(x.g)+(x.f?' · '+e(x.f):'')+'</small>'+e(x.t)+'</a>'}).join('');
sd.insertBefore(b,sd.firstChild);document.body.appendChild(p);document.body.classList.add('dv-bellon');
function fecha(){p.hidden=true;b.setAttribute('aria-expanded','false')}
b.addEventListener('click',function(ev){ev.stopPropagation();if(!p.hidden)return fecha();var r=sd.getBoundingClientRect();p.style.top=(r.bottom+8)+'px';p.style.right=Math.max(12,window.innerWidth-r.right)+'px';p.hidden=false;b.setAttribute('aria-expanded','true');var bi=b.querySelector('i');if(bi)bi.remove()});
document.addEventListener('click',function(ev){if(!p.hidden&&!p.contains(ev.target))fecha()});document.addEventListener('keydown',function(ev){if(ev.key==='Escape')fecha()})}})();</script>
<script>(function(){var a=document.querySelector('.dv-side nav a[href*="/plataforma/noticias/"]');function hk(u){var h=0;u=String(u);for(var i=0;i<u.length;i++)h=(h*31+u.charCodeAt(i))|0;return(h>>>0).toString(36)}
function pin(l){if(!a)return;if(/\/plataforma\/noticias\//.test(location.pathname))return;var v=null;try{v=JSON.parse(localStorage.getItem('dv_np_vis')||'null')}catch(x){}var n=v?l.filter(function(u){return!v[hk(u)]}).length:Math.min(l.length,9);window.dvNpN=n;if(!n)return;var b=document.createElement('span');b.className='bd';b.textContent=n>20?'20+':n;b.title=n+' notícias novas desde a sua última visita';a.appendChild(b)}
var c=null;try{c=JSON.parse(sessionStorage.getItem('dv_np_g')||'null')}catch(x){}if(c&&Date.now()-c.t<1800000&&c.i){pin(c.l);window.dvTicker(c.i);return}
fetch(<?php echo wp_json_encode( rest_url( 'desenvolve/v1/noticias?aba=geral' ) ); ?>,{headers:{'X-WP-Nonce':<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){var l=(j.itens||[]).map(function(x){return x.u}),i=(j.itens||[]).slice(0,14).map(function(x){return{u:x.u,t:x.t,g:x.g,f:x.f}});try{sessionStorage.setItem('dv_np_g',JSON.stringify({t:Date.now(),l:l,i:i}))}catch(x){}pin(l);window.dvTicker(i)}).catch(function(){})})();</script>
	<?php
}, 120 );
