<?php
/**
 * Desenvolve — Diários municipais (cobertura nacional)
 * Conectores para as plataformas que concentram os diários oficiais dos municípios:
 *  - SIGPub (diariomunicipal.com.br): diários das associações de municípios de 20 estados
 *  - DOM/SC (diariomunicipal.sc.gov.br): todos os municípios de Santa Catarina
 * Uso: dv_dm_buscar( $texto, $desde, $ufs ) devolve as publicações normalizadas.
 */

/** Fontes: chave => plataforma, UF e nome para o cliente */
function dv_dm_fontes() {
	static $f = null; if ( $f !== null ) { return $f; }
	$f = array();
	$sig = array(
		'amm-mg' => array( 'MG', 'Minas Gerais (AMM)' ), 'famurs' => array( 'RS', 'Rio Grande do Sul (FAMURS)' ), 'amp' => array( 'PR', 'Paraná (AMP)' ),
		'amupe' => array( 'PE', 'Pernambuco (AMUPE)' ), 'femurn' => array( 'RN', 'Rio Grande do Norte (FEMURN)' ), 'amm-mt' => array( 'MT', 'Mato Grosso (AMM)' ),
		'famup' => array( 'PB', 'Paraíba (FAMUP)' ), 'agm' => array( 'GO', 'Goiás (AGM)' ), 'fgm' => array( 'GO', 'Goiás (FGM)' ), 'aprece' => array( 'CE', 'Ceará (APRECE)' ),
		'famep' => array( 'PA', 'Pará (FAMEP)' ), 'ama' => array( 'AL', 'Alagoas (AMA)' ), 'aam' => array( 'AM', 'Amazonas (AAM)' ), 'ms' => array( 'MS', 'Mato Grosso do Sul (ASSOMASUL)' ),
		'arom' => array( 'RO', 'Rondônia (AROM)' ), 'appm' => array( 'PI', 'Piauí (APPM)' ), 'amr' => array( 'RR', 'Roraima (AMR)' ), 'amurc' => array( 'BA', 'Bahia — Sul e Sudoeste (AMURC)' ),
		'sergipe' => array( 'SE', 'Sergipe' ), 'aemerj' => array( 'RJ', 'Rio de Janeiro (AEMERJ)' ), 'apm' => array( 'SP', 'São Paulo (APM)' ), 'bahia' => array( 'BA', 'Bahia — Recôncavo' ),
	);
	foreach ( $sig as $e => $v ) { $f[ 'S.' . $e ] = array( 'plat' => 'sigpub', 'ent' => $e, 'uf' => $v[0], 'nome' => 'Diário Oficial dos Municípios — ' . $v[1] ); }
	$f['SC'] = array( 'plat' => 'domsc', 'uf' => 'SC', 'nome' => 'Diário Oficial dos Municípios de Santa Catarina (DOM/SC)' );
	return $f;
}
function dv_dm_eh( $k ) { $f = dv_dm_fontes(); return isset( $f[ $k ] ); }

/** URL de busca de uma fonte */
function dv_dm_url( $k, $texto, $desde, $ate = '', $pag = 1 ) {
	$f = dv_dm_fontes(); if ( empty( $f[ $k ] ) ) { return ''; } $f = $f[ $k ];
	$ate = $ate ?: current_time( 'Y-m-d' );
	if ( $f['plat'] === 'sigpub' ) {
		return 'https://www.diariomunicipal.com.br/' . $f['ent'] . '/pesquisar?' . http_build_query( array( 'busca_avancada' => array( 'texto' => $texto, 'titulo' => '', 'entidade' => '', 'dataInicio' => $desde, 'dataFim' => $ate, 'ordenacao' => 'recente', 'pagina' => $pag > 1 ? (string) $pag : '' ) ) );
	}
	return 'https://diariomunicipal.sc.gov.br/?' . http_build_query( array( 'q' => $texto, 'dataInicial' => gmdate( 'd/m/Y', strtotime( $desde ) ), 'dataFinal' => gmdate( 'd/m/Y', strtotime( $ate ) ) ) + ( $pag > 1 ? array( 'Search_page' => $pag ) : array() ) );
}
function dv_dm_urls( $texto, $desde, $ufs = array(), $ate = '' ) {
	$out = array(); foreach ( dv_dm_fontes() as $k => $f ) { if ( $ufs && ! in_array( $f['uf'], $ufs, true ) ) { continue; } $out[ $k ] = dv_dm_url( $k, $texto, $desde, $ate ); }
	return $out;
}

/** "Prefeitura Municipal de X" -> "X" */
function dv_dm_municipio( $ent ) {
	$e = trim( preg_replace( '/\s+/', ' ', $ent ) );
	$e = preg_replace( '/^(prefeitura(\s+municipal)?|c[âa]mara(\s+municipal)?(\s+de\s+vereadores)?|munic[íi]pio|poder\s+(executivo|legislativo)(\s+municipal)?|fundo\s+municipal\s+de\s+\S+|instituto\s+de\s+previd[eê]ncia[^,]*?|serviço\s+aut[ôo]nomo[^,]*?|secretaria\s+municipal\s+de\s+\S+)\s+(de|do|da|dos|das)\s+/iu', '', $e );
	$e = preg_replace( '/\s*[-–\/]\s*[A-Z]{2}$/', '', $e );
	return $e;
}
function dv_dm_txt( $s ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) ) ); }

/** Converte a página de resultados de uma fonte em publicações */
function dv_dm_itens( $k, $body ) {
	$out = array(); $fs = dv_dm_fontes(); if ( ! $body || empty( $fs[ $k ] ) ) { return $out; } $f = $fs[ $k ];
	if ( $f['plat'] === 'sigpub' ) {
		if ( ! preg_match_all( '#<li class="materia-card">(.*?)</dl>#s', $body, $mm ) ) { return $out; }
		foreach ( $mm[1] as $c ) {
			if ( ! preg_match( '#materia-card__titulo">\s*<a href="([^"]+)"[^>]*>(.*?)</a>#s', $c, $t ) ) { continue; }
			$ent = preg_match( '#materia-card__entidade">(.*?)</span>#s', $c, $x ) ? dv_dm_txt( $x[1] ) : '';
			$org = preg_match( '#materia-card__orgao">(.*?)</span>#s', $c, $x ) ? dv_dm_txt( $x[1] ) : '';
			$tr = preg_match( '#materia-card__trecho">(.*?)</p>#s', $c, $x ) ? dv_dm_txt( $x[1] ) : '';
			$dt = preg_match( '#Circula[^<]*</dt>\s*<dd>\s*(\d{2})/(\d{2})/(\d{4})#u', $c, $x ) ? "$x[3]-$x[2]-$x[1]" : '';
			$link = ( strpos( $t[1], 'http' ) === 0 ? '' : 'https://www.diariomunicipal.com.br' ) . html_entity_decode( $t[1] );
			$out[] = array( 'id' => 'S' . sprintf( '%u', crc32( $link ) ), 'titulo' => dv_dm_txt( $t[2] ), 'entidade' => $ent, 'municipio' => dv_dm_municipio( $ent ), 'orgao' => $org ?: $ent, 'uf' => $f['uf'], 'data' => $dt, 'link' => $link, 'trecho' => $tr, 'fonte_nome' => $f['nome'] );
		}
		return $out;
	}
	/* DOM/SC */
	$i = strpos( $body, 'resultado-pesquisa' ); if ( $i === false ) { return $out; }
	$h = substr( $body, $i, 400000 );
	$partes = preg_split( '#<h4[^>]*>#', $h ); array_shift( $partes );
	foreach ( $partes as $p ) {
		if ( ! preg_match( '#<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>\s*</h4>#s', $p, $t ) ) { continue; }
		$sp = preg_match( '#</h4>\s*<span[^>]*>(.*?)<br#s', $p, $x ) ? dv_dm_txt( $x[1] ) : '';
		$tr = preg_match( '#<p[^>]*>(.*?)</p>#s', $p, $x ) ? dv_dm_txt( $x[1] ) : '';
		$dt = preg_match( '#(\d{2})/(\d{2})/(\d{4})#', $sp, $x ) ? "$x[3]-$x[2]-$x[1]" : '';
		$seg = array_map( 'trim', explode( ' - ', $sp ) ); $ent = end( $seg ) ?: ''; $cat = count( $seg ) >= 5 ? $seg[ count( $seg ) - 2 ] : '';
		$link = html_entity_decode( $t[1] ); if ( strpos( $link, 'http' ) !== 0 ) { $link = 'https://diariomunicipal.sc.gov.br' . ( $link[0] === '/' ? '' : '/' ) . $link; }
		$out[] = array( 'id' => 'C' . sprintf( '%u', crc32( $link ) ), 'titulo' => dv_dm_txt( $t[2] ), 'entidade' => $ent, 'municipio' => dv_dm_municipio( $ent ), 'orgao' => $ent, 'uf' => 'SC', 'data' => $dt, 'link' => $link, 'trecho' => $tr, 'categoria' => $cat, 'fonte_nome' => $f['nome'] );
	}
	return $out;
}

/** Baixa várias páginas com no máximo $lote conexões simultâneas por vez (não sobrecarrega as plataformas). Guarda 3h. */
function dv_dm_baixar( $urls, $lote = 8, $timeout = 20 ) {
	$out = array(); $falta = array();
	foreach ( $urls as $k => $u ) { $c = get_transient( 'dv_dm_' . md5( $u ) ); if ( is_string( $c ) ) { $out[ $k ] = $c; } else { $falta[ $k ] = $u; } }
	$hd = array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pt-BR,pt;q=0.9' );
	foreach ( array_chunk( $falta, $lote, true ) as $ch ) {
		$res = function_exists( 'dv_ds_multi' ) ? dv_ds_multi( $ch, $hd, $timeout ) : array();
		foreach ( $ch as $k => $u ) {
			$b = $res[ $k ] ?? null;
			if ( ! $b ) { $g = wp_remote_get( $u, array( 'timeout' => $timeout, 'headers' => $hd ) ); $b = ( ! is_wp_error( $g ) && wp_remote_retrieve_response_code( $g ) === 200 ) ? wp_remote_retrieve_body( $g ) : null; }
			if ( $b ) { $b = dv_dm_enxugar( $b ); set_transient( 'dv_dm_' . md5( $u ), $b, 3 * HOUR_IN_SECONDS ); dv_dm_saude( $k, true ); } else { dv_dm_saude( $k, false ); }
			$out[ $k ] = $b;
		}
	}
	return $out;
}
/** Guarda só o trecho dos resultados (as páginas trazem listas enormes de municípios nos filtros) */
function dv_dm_enxugar( $b ) {
	$i = strpos( $b, 'lista-materias' ); if ( $i !== false ) { $f = strpos( $b, 'paginacao', $i ); return substr( $b, $i - 20, ( $f ? $f + 2000 : $i + 200000 ) - $i + 20 ); }
	$i = strpos( $b, 'resultado-pesquisa' ); if ( $i !== false ) { return substr( $b, $i - 20, 200000 ); }
	return strlen( $b ) > 300000 ? substr( $b, 0, 300000 ) : $b;
}
/** Saúde de cada fonte (para o monitor das fontes) */
function dv_dm_saude( $k, $ok ) {
	$s = get_option( 'dv_dm_saude', array() ); if ( ! is_array( $s ) ) { $s = array(); }
	$p = $s[ $k ] ?? array( 'ok' => 0, 'falha' => 0, 'ult_ok' => '', 'ult_falha' => '' );
	if ( $ok ) { $p['ok']++; $p['ult_ok'] = current_time( 'mysql' ); $p['seguidas'] = 0; } else { $p['falha']++; $p['ult_falha'] = current_time( 'mysql' ); $p['seguidas'] = ( $p['seguidas'] ?? 0 ) + 1; }
	$s[ $k ] = $p; update_option( 'dv_dm_saude', $s, false );
}

/**
 * Busca um texto em todos os diários municipais (ou só nos estados pedidos).
 * $paginas: quantas páginas por fonte (25 resultados cada no SIGPub, 10 no DOM/SC).
 */
function dv_dm_buscar( $texto, $desde, $ufs = array(), $ate = '', $paginas = 1, $frase = false ) {
	$texto = trim( str_replace( '"', '', (string) $texto ) ); if ( mb_strlen( $texto ) < 3 ) { return array(); }
	$urls = array();
	foreach ( dv_dm_fontes() as $k => $f ) {
		if ( $ufs && ! in_array( $f['uf'], $ufs, true ) ) { continue; }
		$np = $f['plat'] === 'domsc' ? $paginas * 2 : $paginas;
		$q = ( $frase && $f['plat'] === 'domsc' ) ? '"' . $texto . '"' : $texto;
		for ( $p = 1; $p <= $np; $p++ ) { $urls[ $k . '#' . $p ] = dv_dm_url( $k, $q, $desde, $ate, $p ); }
	}
	$res = dv_dm_baixar( $urls ); $itens = array();
	foreach ( $res as $kp => $b ) { $k = explode( '#', $kp )[0]; foreach ( dv_dm_itens( $k, $b ) as $x ) { $itens[ $x['id'] ] = $x; } }
	if ( $frase ) { $itens = dv_dm_conferir( $itens, $texto ); }
	$itens = array_values( $itens );
	foreach ( $itens as &$x ) { if ( $x['municipio'] !== '' && mb_strtoupper( $x['municipio'] ) === $x['municipio'] ) { $x['municipio'] = mb_convert_case( mb_strtolower( $x['municipio'] ), MB_CASE_TITLE ); $x['municipio'] = preg_replace_callback( '/\b(De|Da|Do|Das|Dos|E)\b/u', function ( $m ) { return mb_strtolower( $m[1] ); }, $x['municipio'] ); } } unset( $x );
	usort( $itens, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	return $itens;
}
function dv_dm_norm( $s ) { return trim( preg_replace( '/[^A-Z0-9]+/', ' ', strtoupper( remove_accents( (string) $s ) ) ) ); }
/** Texto integral de uma matéria do SIGPub (guardado 30 dias) */
function dv_dm_materia( $links ) {
	$out = array(); $falta = array();
	foreach ( $links as $l ) { $c = get_transient( 'dv_dmm_' . md5( $l ) ); if ( is_string( $c ) ) { $out[ $l ] = $c; } else { $falta[ md5( $l ) ] = $l; } }
	foreach ( array_chunk( $falta, 8, true ) as $ch ) {
		$res = function_exists( 'dv_ds_multi' ) ? dv_ds_multi( $ch, array( 'Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' ), 20 ) : array();
		foreach ( $ch as $h => $l ) {
			$b = $res[ $h ] ?? null; if ( ! $b ) { continue; }
			$i = strpos( $b, 'documento__corpo' ); $t = $i !== false ? substr( $b, $i, 150000 ) : $b;
			$t = mb_substr( dv_dm_txt( preg_replace( '#<(script|style)[^>]*>.*?</\1>#s', ' ', $t ) ), 0, 60000 );
			set_transient( 'dv_dmm_' . $h, $t, 30 * DAY_IN_SECONDS ); $out[ $l ] = $t;
		}
	}
	return $out;
}
/** Mantém só as publicações que trazem a frase exata (o SIGPub busca palavra por palavra). Confere no texto integral quando o trecho não basta. */
function dv_dm_conferir( $itens, $frase ) {
	$nf = dv_dm_norm( $frase ); if ( $nf === '' ) { return $itens; }
	$ok = array(); $cand = array();
	foreach ( $itens as $id => $x ) {
		if ( strpos( ' ' . dv_dm_norm( $x['titulo'] . ' ' . $x['trecho'] ) . ' ', ' ' . $nf . ' ' ) !== false ) { $ok[ $id ] = $x; continue; }
		if ( strpos( $id, 'S' ) === 0 ) { $cand[ $id ] = $x; } /* DOM/SC já buscou a frase exata */ else { $ok[ $id ] = $x; }
	}
	$cand = array_slice( $cand, 0, 80, true );
	if ( $cand ) {
		$tx = dv_dm_materia( array_column( $cand, 'link' ) );
		foreach ( $cand as $id => $x ) {
			$t = $tx[ $x['link'] ] ?? ''; if ( $t === '' ) { continue; }
			$n = dv_dm_norm( $t ); $p = strpos( ' ' . $n . ' ', ' ' . $nf . ' ' ); if ( $p === false ) { continue; }
			/* trecho em volta da citação, no texto original (posição aproximada) */
			$pos = mb_stripos( remove_accents( $t ), remove_accents( mb_substr( $frase, 0, 40 ) ) );
			if ( $pos !== false ) { $x['trecho'] = '… ' . mb_substr( $t, max( 0, $pos - 260 ), 620 ) . ' …'; }
			$ok[ $id ] = $x;
		}
	}
	return $ok;
}

/** Cobertura: quantos municípios cada fonte atende (contado na lista de entidades da própria plataforma; atualiza 1x por semana) */
function dv_dm_cobertura( $forcar = false ) {
	$c = get_option( 'dv_dm_cob' ); if ( ! $forcar && is_array( $c ) && ! empty( $c['quando'] ) && strtotime( $c['quando'] ) > time() - 7 * DAY_IN_SECONDS ) { return $c; }
	$urls = array(); foreach ( dv_dm_fontes() as $k => $f ) { $urls[ $k ] = $f['plat'] === 'sigpub' ? 'https://www.diariomunicipal.com.br/' . $f['ent'] . '/pesquisar' : 'https://diariomunicipal.sc.gov.br/?r=site/entidades'; }
	$res = function_exists( 'dv_ds_multi' ) ? dv_ds_multi( $urls, array( 'Accept' => 'text/html' ), 30 ) : array();
	$por = array(); $mun = array();
	foreach ( $res as $k => $b ) {
		$f = dv_dm_fontes()[ $k ]; $n = 0;
		if ( $f['plat'] === 'sigpub' && $b && preg_match( '#name="busca_avancada\[entidade\]"[^>]*>(.*?)</select>#s', $b, $m ) && preg_match_all( '#<option[^>]*>(.*?)</option>#s', $m[1], $o ) ) {
			foreach ( $o[1] as $t ) { $t = dv_dm_txt( $t ); if ( preg_match( '/^(prefeitura|munic[íi]pio|c[âa]mara)/iu', $t ) ) { $mun[ $f['uf'] . '|' . remove_accents( mb_strtolower( dv_dm_municipio( $t ) ) ) ] = 1; } }
		}
	}
	foreach ( array_keys( $mun ) as $x ) { $u = explode( '|', $x )[0]; $por[ $u ] = ( $por[ $u ] ?? 0 ) + 1; }
	$por['SC'] = 295; /* DOM/SC atende todos os municípios catarinenses */
	$c = array( 'quando' => current_time( 'mysql' ), 'por_uf' => $por, 'total' => array_sum( $por ) );
	update_option( 'dv_dm_cob', $c, false );
	return $c;
}

add_action( 'rest_api_init', function () {
	$adm = function () { return current_user_can( 'manage_options' ); };
	/* sonda e teste (só administrador) */
	register_rest_route( 'desenvolve/v1', '/dm/sonda', array( 'methods' => 'POST', 'permission_callback' => $adm, 'callback' => function ( WP_REST_Request $r ) {
		$urls = (array) $r->get_param( 'urls' ); $out = array();
		foreach ( array_slice( $urls, 0, 12 ) as $k => $u ) {
			$u = esc_url_raw( $u ); if ( ! $u ) { continue; }
			$t0 = microtime( true );
			$res = wp_remote_get( $u, array( 'timeout' => 25, 'redirection' => 3, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept' => 'text/html,application/json,*/*', 'Accept-Language' => 'pt-BR,pt;q=0.9' ) ) );
			if ( is_wp_error( $res ) ) { $out[ $k ] = array( 'u' => $u, 'erro' => $res->get_error_message() ); continue; }
			$b = wp_remote_retrieve_body( $res );
			$out[ $k ] = array( 'u' => $u, 'st' => wp_remote_retrieve_response_code( $res ), 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ), 'tipo' => wp_remote_retrieve_header( $res, 'content-type' ), 'tam' => strlen( $b ), 'ini' => mb_substr( preg_replace( '/\s+/', ' ', $b ), 0, (int) ( $r->get_param( 'n' ) ?: 1500 ) ) );
		}
		return $out;
	} ) );
	register_rest_route( 'desenvolve/v1', '/dm/teste', array( 'methods' => 'GET', 'permission_callback' => $adm, 'callback' => function ( WP_REST_Request $r ) {
		$t0 = microtime( true ); $ufs = array_filter( explode( '|', strtoupper( (string) $r['ufs'] ) ) );
		$it = dv_dm_buscar( (string) $r['q'], (string) ( $r['desde'] ?: gmdate( 'Y-m-d', strtotime( '-7 days' ) ) ), $ufs, '', (int) ( $r['pag'] ?: 1 ), ! empty( $r['frase'] ) );
		$por = array(); foreach ( $it as $x ) { $por[ $x['uf'] ] = ( $por[ $x['uf'] ] ?? 0 ) + 1; }
		return array( 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ), 'total' => count( $it ), 'por_uf' => $por, 'itens' => array_slice( $it, 0, (int) ( $r['n'] ?: 20 ) ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/dm/cobertura', array( 'methods' => 'GET', 'permission_callback' => $adm, 'callback' => function ( WP_REST_Request $r ) { return dv_dm_cobertura( ! empty( $r['forcar'] ) ) + array( 'saude' => get_option( 'dv_dm_saude', array() ) ); } ) );
} );
