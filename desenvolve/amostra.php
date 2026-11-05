<?php
/**
 * Desenvolve — Amostra grátis (página dos anúncios)
 * Rota pública /desenvolve/v1/amostra: o visitante digita um CNPJ (empresa ou órgão) ou o que vende,
 * vê na hora uma parte do resultado e libera o restante deixando o e-mail (entra na lista de espera).
 * Limite: 6 consultas por dia por visitante. Resultados ficam em cache por 12 horas.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_am_get( $url, $t = 20 ) {
	$r = wp_remote_get( $url, array( 'timeout' => $t, 'headers' => array( 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return null; }
	return json_decode( wp_remote_retrieve_body( $r ), true );
}
function dv_am_cad( $cnpj ) {
	$k = 'dv_amc_' . $cnpj; $c = get_transient( $k ); if ( $c !== false ) { return $c; }
	$c = dv_am_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, 25 );
	if ( ! is_array( $c ) || empty( $c['razao_social'] ) ) { return null; }
	set_transient( $k, $c, DAY_IN_SECONDS ); return $c;
}
function dv_am_pncp_total( $q, $tipo ) {
	$j = dv_am_get( 'https://pncp.gov.br/api/search/?q=' . rawurlencode( $q ) . '&tipos_documento=' . $tipo . '&pagina=1&tam_pagina=1', 20 );
	return is_array( $j ) ? (int) ( $j['total'] ?? 0 ) : null;
}
function dv_am_edital( $i ) {
	$url = (string) ( $i['item_url'] ?? '' ); if ( $url && strpos( $url, 'http' ) !== 0 ) { $url = 'https://pncp.gov.br/app' . str_replace( '/compras/', '/editais/', $url ); }
	return array(
		'objeto' => mb_substr( trim( (string) ( $i['description'] ?? $i['title'] ?? '' ) ), 0, 260 ),
		'orgao' => (string) ( $i['orgao_nome'] ?? '' ), 'local' => trim( ( $i['municipio_nome'] ?? '' ) . ( ! empty( $i['uf'] ) ? '/' . $i['uf'] : '' ), '/' ),
		'modalidade' => (string) ( $i['modalidade_licitacao_nome'] ?? '' ), 'fim' => substr( (string) ( $i['data_fim_vigencia'] ?? '' ), 0, 10 ),
		'valor' => (float) ( $i['valor_global'] ?? 0 ), 'link' => $url,
	);
}

function dv_am_empresa( $cnpj ) {
	$cad = dv_am_cad( $cnpj ); if ( ! $cad ) { return new WP_Error( 'dv_am', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
	$raiz = substr( $cnpj, 0, 8 );
	/* grupo econômico: empresas ligadas por sócios em comum */
	$grupo = array();
	if ( function_exists( 'dv_ds_shard' ) && function_exists( 'dv_ds_nrm' ) ) {
		foreach ( array_slice( (array) ( $cad['qsa'] ?? array() ), 0, 8 ) as $s ) {
			$doc = preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) );
			$k = strlen( $doc ) === 14 ? 'PJ|' . $doc : dv_ds_nrm( $s['nome_socio'] ?? '' ) . '|' . $doc;
			foreach ( dv_ds_shard( 'socios', $k ) as $e ) {
				if ( empty( $e[0] ) || $e[0] === $raiz || isset( $grupo[ $e[0] ] ) || count( $grupo ) >= 40 ) { continue; }
				$grupo[ $e[0] ] = array( 'razao' => (string) ( $e[1] ?? '' ), 'via' => 'Sócio em comum: ' . ( $s['nome_socio'] ?? '' ) );
			}
		}
	}
	/* sanções */
	$sanc = array();
	if ( function_exists( 'dv_ds_interno' ) ) {
		$rx = dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cnpj ) );
		foreach ( array( 'ceis' => 'CEIS', 'cnep' => 'CNEP' ) as $k => $rot ) { foreach ( (array) ( $rx['sancoes'][ $k ] ?? array() ) as $x ) { $sanc[] = $rot . ': ' . ( $x['tipoSancao']['descricaoResumida'] ?? $x['tipo'] ?? 'registro' ) . ( ! empty( $x['orgaoSancionador']['nome'] ) ? ' (' . $x['orgaoSancionador']['nome'] . ')' : '' ); } }
	}
	$pg = function_exists( 'dv_ds_pgfn' ) ? dv_ds_pgfn( $cnpj ) : null;
	$contratos = dv_am_pncp_total( $cnpj, 'contrato' );
	return array(
		'tipo' => 'empresa', 'nome' => $cad['razao_social'], 'fantasia' => $cad['nome_fantasia'] ?? '',
		'livre' => array(
			array( 'Situação na Receita', $cad['descricao_situacao_cadastral'] ?? '—' ),
			array( 'Aberta em', $cad['data_inicio_atividade'] ?? '—' ),
			array( 'Local', trim( ( $cad['municipio'] ?? '' ) . '/' . ( $cad['uf'] ?? '' ), '/' ) ),
			array( 'Capital social', 'R$ ' . number_format( (float) ( $cad['capital_social'] ?? 0 ), 2, ',', '.' ) ),
		),
		'achados' => array(
			array( 'n' => count( $grupo ), 'rot' => count( $grupo ) === 1 ? 'empresa ligada (grupo econômico)' : 'empresas ligadas (grupo econômico)', 'alerta' => count( $grupo ) >= 5 ),
			array( 'n' => count( $sanc ), 'rot' => count( $sanc ) === 1 ? 'sanção em cadastro oficial' : 'sanções em cadastros oficiais', 'alerta' => count( $sanc ) > 0 ),
			array( 'n' => $pg ? 'R$ ' . number_format( (float) $pg['total'], 0, ',', '.' ) : 'R$ 0', 'rot' => 'em dívida ativa da União', 'alerta' => $pg && $pg['total'] > 0 ),
			array( 'n' => $contratos === null ? '—' : $contratos, 'rot' => 'contratos públicos no PNCP', 'alerta' => false ),
		),
		'bloqueado' => array(
			'grupo' => array_slice( array_values( $grupo ), 0, 15 ),
			'sancoes' => array_slice( $sanc, 0, 10 ),
			'socios' => array_map( function ( $s ) { return ( $s['nome_socio'] ?? '' ) . ( ! empty( $s['qualificacao_socio'] ) ? ' · ' . $s['qualificacao_socio'] : '' ); }, array_slice( (array) ( $cad['qsa'] ?? array() ), 0, 10 ) ),
			'pgfn' => $pg,
		),
	);
}

function dv_am_orgao( $cnpj ) {
	$cad = dv_am_cad( $cnpj ); if ( ! $cad ) { return new WP_Error( 'dv_am', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
	$ab = function_exists( 'dv_cp_abertas' ) ? dv_cp_abertas( $cnpj ) : array( 'total' => 0, 'itens' => array() );
	$it = array_map( function ( $i ) { return array( 'objeto' => $i['objeto'], 'orgao' => $i['unidade'], 'local' => '', 'modalidade' => $i['modalidade'], 'fim' => substr( (string) $i['fim'], 0, 10 ), 'valor' => (float) $i['valor'], 'link' => $i['link'] ); }, (array) $ab['itens'] );
	$contratos = dv_am_pncp_total( $cnpj, 'contrato' );
	return array(
		'tipo' => 'orgao', 'nome' => $cad['razao_social'], 'fantasia' => '',
		'livre' => array(
			array( 'Natureza', $cad['natureza_juridica'] ?? '—' ),
			array( 'Local', trim( ( $cad['municipio'] ?? '' ) . '/' . ( $cad['uf'] ?? '' ), '/' ) ),
		),
		'achados' => array(
			array( 'n' => (int) $ab['total'], 'rot' => (int) $ab['total'] === 1 ? 'licitação aberta agora' : 'licitações abertas agora', 'alerta' => false ),
			array( 'n' => $contratos === null ? '—' : $contratos, 'rot' => 'contratos publicados no PNCP', 'alerta' => false ),
		),
		'editais' => array_slice( $it, 0, 1 ),
		'bloqueado' => array( 'editais' => array_slice( $it, 1, 15 ) ),
	);
}

function dv_am_norm( $t ) { return strtolower( remove_accents( (string) $t ) ); }
function dv_am_editais( $q, $uf ) {
	$u = 'https://pncp.gov.br/api/search/?q=' . rawurlencode( $q ) . '&tipos_documento=edital&status=recebendo_proposta&ordenacao=-data&pagina=1&tam_pagina=60' . ( $uf ? '&ufs=' . rawurlencode( $uf ) : '' );
	$j = dv_am_get( $u, 20 ); if ( ! is_array( $j ) ) { $j = dv_am_get( $u, 25 ); }
	if ( ! is_array( $j ) ) { return new WP_Error( 'dv_am', 'O PNCP não respondeu agora. Tente de novo em instantes.', array( 'status' => 502 ) ); }
	$it = array_map( 'dv_am_edital', (array) ( $j['items'] ?? array() ) );
	// relevância: primeiro os editais com o termo no próprio objeto; depois os que só têm o termo nos itens
	$ws = array_filter( preg_split( '/[^a-z0-9]+/', dv_am_norm( $q ) ), function ( $w ) { return strlen( $w ) >= 3; } );
	$raiz = array_map( function ( $w ) { $w = preg_replace( '/(oes|aes|ais|eis|ns|s)$/', '', $w ); return strlen( $w ) > 6 ? substr( $w, 0, 6 ) : $w; }, $ws );
	$no = array(); $nos = array();
	foreach ( $it as $x ) { $o = dv_am_norm( $x['objeto'] ); $ok = $raiz && count( array_filter( $raiz, function ( $r ) use ( $o ) { return strpos( $o, $r ) !== false; } ) ) === count( $raiz ); $x['em'] = $ok ? 'objeto' : 'itens'; if ( $ok ) { $no[] = $x; } else { $nos[] = $x; } }
	$it = array_merge( $no, $nos ); $tot = (int) ( $j['total'] ?? count( $it ) );
	return array(
		'tipo' => 'editais', 'nome' => 'Licitações abertas: ' . $q . ( $uf ? ' (' . $uf . ')' : '' ), 'fantasia' => '', 'termo' => $q, 'uf' => $uf,
		'livre' => array(),
		'achados' => array( array( 'n' => $tot, 'rot' => 'editais abertos citam “' . $q . '”' . ( $uf ? ' em ' . $uf : '' ) . ', no objeto ou na lista de itens', 'alerta' => false ) ),
		'editais' => array_slice( $it, 0, 1 ),
		'bloqueado' => array( 'editais' => array_slice( $it, 1, 19 ) ),
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/amostra', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $r ) {
		if ( trim( (string) $r['site'] ) !== '' ) { return new WP_Error( 'dv_am', 'Não foi possível consultar agora.', array( 'status' => 400 ) ); }
		$tipo = (string) $r['tipo']; $q = trim( sanitize_text_field( (string) $r['q'] ) ); $uf = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $r['uf'] ) ); $uf = strlen( $uf ) === 2 ? $uf : '';
		if ( ! in_array( $tipo, array( 'empresa', 'orgao', 'editais' ), true ) ) { $tipo = 'editais'; }
		$cnpj = preg_replace( '/\D/', '', $q );
		if ( $tipo !== 'editais' && strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_am', 'Digite um CNPJ com 14 números.', array( 'status' => 400 ) ); }
		if ( $tipo === 'editais' && mb_strlen( $q ) < 3 ) { return new WP_Error( 'dv_am', 'Digite o que você vende, por exemplo: limpeza, merenda, vigilância.', array( 'status' => 400 ) ); }
		$ck = 'dv_am4_' . md5( $tipo . '|' . ( $tipo === 'editais' ? strtolower( remove_accents( $q ) ) . '|' . $uf : $cnpj ) ); $c = get_transient( $ck );
		if ( $c === false ) {
			$ip = function_exists( 'dv_sg_ip' ) ? dv_sg_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); $kl = 'dv_am_ip_' . md5( $ip . current_time( 'Ymd' ) ); $n = (int) get_transient( $kl );
			if ( $n >= 6 && ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'dv_am', 'Você já fez as consultas grátis de hoje. Entre na lista de espera para usar sem limite no lançamento.', array( 'status' => 429 ) ); }
			set_transient( $kl, $n + 1, DAY_IN_SECONDS );
			$c = $tipo === 'empresa' ? dv_am_empresa( $cnpj ) : ( $tipo === 'orgao' ? dv_am_orgao( $cnpj ) : dv_am_editais( $q, $uf ) );
			if ( is_wp_error( $c ) ) { return $c; }
			$falhou = false; foreach ( (array) $c['achados'] as $a ) { if ( $a['n'] === '—' ) { $falhou = true; } }
			$c['achados'] = array_values( array_filter( (array) $c['achados'], function ( $a ) { return $a['n'] !== '—'; } ) );
			if ( $c['tipo'] === 'orgao' && $falhou && empty( $c['editais'] ) ) { $c['achados'] = array_values( array_filter( $c['achados'], function ( $a ) { return strpos( $a['rot'], 'aberta' ) === false; } ) ); }
			set_transient( $ck, $c, $falhou ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		}
		/* registra toda busca (também as que vieram do cache); marca quando é o administrador */
		$log = (array) get_option( 'dv_am_log', array() ); array_unshift( $log, array( current_time( 'mysql' ), $tipo, ( $tipo === 'editais' ? $q . ( $uf ? ' (' . $uf . ')' : '' ) : $cnpj ) . ( current_user_can( 'manage_options' ) ? ' · teste interno' : '' ) ) ); update_option( 'dv_am_log', array_slice( $log, 0, 300 ), false );
		$c['paginas'] = dv_am_paginas( $c );
		// o resultado completo só sai do servidor para quem já deixou o e-mail na lista de espera
		$em = strtolower( sanitize_email( (string) $r['email'] ) );
		$lib = current_user_can( 'manage_options' ) || ( $em && function_exists( 'dv_lista' ) && isset( dv_lista()[ $em ] ) );
		if ( $lib ) { return $c; }
		return dv_am_trava( $c );
	} ) );
} );

/** Páginas do relatório completo (A4): capa/resumo + conteúdo, ~5 editais ou ~12 linhas de lista por página */
function dv_am_paginas( $c ) {
	$B = (array) ( $c['bloqueado'] ?? array() ); $p = 1;
	if ( ! empty( $B['editais'] ) ) { $p += (int) ceil( ( count( $B['editais'] ) + count( (array) ( $c['editais'] ?? array() ) ) ) / 5 ); }
	foreach ( array( 'grupo', 'sancoes', 'socios' ) as $k ) { if ( ! empty( $B[ $k ] ) ) { $p += (int) ceil( count( $B[ $k ] ) / 12 ); } }
	if ( ! empty( $B['pgfn'] ) ) { $p++; }
	if ( ( $c['tipo'] ?? '' ) !== 'empresa' ) { $p++; } // análise: prazos, fornecedor atual e concorrentes
	return max( 2, $p );
}
/** Substitui o conteúdo bloqueado por marcadores (mantém só as quantidades) */
function dv_am_trava( $c ) {
	$B = (array) ( $c['bloqueado'] ?? array() ); $t = array();
	foreach ( $B as $k => $v ) {
		if ( ! is_array( $v ) ) { $t[ $k ] = null; continue; }
		$n = count( $v );
		if ( $k === 'editais' ) { $t[ $k ] = array_fill( 0, $n, array( 'objeto' => 'Licitação liberada depois do e-mail ●●●●●●●●●●●●●●●●●●●●●●●●', 'orgao' => '●●●●●●●●●●●●', 'local' => '●●●●●', 'modalidade' => '', 'fim' => '', 'valor' => 0, 'link' => '' ) ); }
		elseif ( $k === 'grupo' ) { $t[ $k ] = array_fill( 0, $n, array( 'razao' => '●●●●●●●●●●●●●●●● LTDA', 'via' => 'Sócio em comum: ●●●●●●●●' ) ); }
		elseif ( array_keys( $v ) === range( 0, $n - 1 ) ) { $t[ $k ] = array_fill( 0, $n, '●●●●●●●●●●●●●●●●●●●●' ); }
		else { $t[ $k ] = null; }
	}
	$c['bloqueado'] = $t; $c['travado'] = true;
	return $c;
}

/* Painel: últimas consultas grátis (o que os visitantes procuram) */
add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Consultas grátis', 'Consultas grátis', 'manage_options', 'dv-amostra', function () {
		echo '<div class="wrap"><h1>Consultas grátis da página de anúncios</h1><p>O que os visitantes pesquisaram (útil para escolher palavras dos anúncios).</p><table class="widefat striped" style="max-width:900px"><thead><tr><th>Quando</th><th>Tipo</th><th>Busca</th></tr></thead><tbody>';
		foreach ( (array) get_option( 'dv_am_log', array() ) as $l ) { echo '<tr><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	} );
}, 99 );
