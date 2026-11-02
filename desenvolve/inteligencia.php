<?php
/**
 * Desenvolve — Inteligência dos três produtos principais.
 * Concorrente: experiência comprovável (atestados), perfil de preço, comparar com a sua empresa, monitoramento.
 * Comprador: quem ganha no órgão, histórico das licitações, calendário (contratos vencendo), pagamentos (TCE-SP).
 * Due Diligence: sócios a fundo e linha do tempo.
 * Raio-X em lote ([dv_lote]) e importação diária da base de participações do robô das atas.
 * Marcador: DV_INT_V1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_int_get( $urls, $timeout = 30 ) {
	if ( function_exists( 'dv_ds_multi' ) ) { $r = dv_ds_multi( $urls, array(), $timeout ); unset( $r['__falhas'] ); return $r; }
	$o = array(); foreach ( $urls as $k => $u ) { $g = wp_remote_get( $u, array( 'timeout' => $timeout ) ); $o[ $k ] = is_wp_error( $g ) ? null : wp_remote_retrieve_body( $g ); }
	return $o;
}
function dv_int_n( $s ) { return function_exists( 'dv_ds_nrm' ) ? dv_ds_nrm( $s ) : strtoupper( remove_accents( (string) $s ) ); }
function dv_int_cad( $cnpj ) {
	$ck = 'dv_icad_' . $cnpj; $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$c = function_exists( 'dv_ds_interno' ) ? dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cnpj ) ) : null;
	if ( ! is_array( $c ) || empty( $c['cadastro'] ) ) {
		$x = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 20 ) ); $b = is_wp_error( $x ) ? array() : (array) json_decode( wp_remote_retrieve_body( $x ), true );
		if ( empty( $b['razao_social'] ) ) { return null; }
		$c = array( 'cadastro' => array( 'razao_social' => $b['razao_social'], 'situacao' => $b['descricao_situacao_cadastral'] ?? '', 'abertura' => $b['data_inicio_atividade'] ?? '', 'natureza' => $b['natureza_juridica'] ?? '', 'porte' => $b['porte'] ?? '', 'capital_social' => (float) ( $b['capital_social'] ?? 0 ), 'municipio' => $b['municipio'] ?? '', 'uf' => $b['uf'] ?? '', 'qsa' => $b['qsa'] ?? array() ), 'sancoes' => null );
	}
	set_transient( $ck, $c, DAY_IN_SECONDS ); return $c;
}
function dv_int_pode( $uid, $f, $cnpj ) {
	if ( function_exists( 'dv_ilimitado' ) && dv_ilimitado( $uid ) ) { return true; }
	global $wpdb; $lim = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
	$rel = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_rel WHERE user_id=%d AND ferramenta=%s AND cnpj=%s AND criado>=%s LIMIT 1", $uid, $f, $cnpj, $lim ) );
	if ( $rel ) { return true; }
	$fc = preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $cnpj );
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_uso WHERE user_id=%d AND ferramenta=%s AND (rotulo LIKE %s OR rotulo LIKE %s) AND criado>=%s LIMIT 1", $uid, $f, '%' . $fc . '%', '%' . $cnpj . '%', $lim ) )
		|| ( $f === 'dossie' && get_transient( 'dv_ds9_' . $cnpj ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_uso WHERE user_id=%d AND ferramenta='dossie' AND criado>=%s LIMIT 1", $uid, $lim ) ) );
}

/* ---------------- Concorrente ---------------- */
function dv_int_ramo( $t ) {
	$t = strtolower( remove_accents( (string) $t ) );
	$R = array( 'Vigilância e segurança' => 'vigilancia|seguranca patrimonial|vigilante|monitoramento eletronico', 'Limpeza e conservação' => 'limpeza|conservacao|higienizacao|asseio|zeladoria',
		'Portaria e recepção' => 'portaria|recepcao|recepcionista|controlador de acesso|controle de acesso', 'Alimentação' => 'alimentacao|refeic|merenda|nutricao|cozinha|lanche',
		'Manutenção predial' => 'manutencao predial|manutencao preventiva|ar condicionado|climatizacao|elevador|instalacoes', 'Apoio e mão de obra' => 'apoio administrativo|mao de obra|mao-de-obra|copeira|terceirizacao|servicos gerais|auxiliar',
		'Jardinagem e roçada' => 'jardinagem|rocada|rocagem|paisagismo|poda', 'Transporte' => 'transporte|veiculo|motorista|fretamento', 'Resíduos e limpeza urbana' => 'coleta de lixo|residuos|limpeza urbana|varricao',
		'Obras e engenharia' => 'obra|engenharia|reforma|construcao|pavimenta|edificacao', 'Saúde' => 'saude|hospital|medic|enfermag|ambulancia', 'Tecnologia' => 'software|sistema|informatica|tecnologia da informacao|licenca' );
	foreach ( $R as $k => $re ) { if ( preg_match( '/' . $re . '/', $t ) ) { return $k; } }
	return 'Outros';
}
function dv_int_postos( $t ) {
	$m = array(); $max = 0;
	if ( preg_match_all( '/(\d{1,4})\s*(?:\(\w+\)\s*)?(postos?|vigilantes?|serventes?|porteiros?|recepcionistas?|profissionais|funcion[aá]rios|colaboradores|motoristas|merendeiras?|cozinheir\w+|agentes|controladores)/iu', (string) $t, $m ) ) {
		foreach ( $m[1] as $n ) { $n = (int) $n; if ( $n > $max && $n < 5000 ) { $max = $n; } }
	}
	return $max;
}
function dv_int_meses( $a, $b ) { $x = strtotime( (string) $a ); $y = strtotime( (string) $b ); return ( $x && $y && $y > $x ) ? (int) round( ( $y - $x ) / ( 30.44 * DAY_IN_SECONDS ) ) : 0; }

function dv_int_concorrente( $cnpj ) {
	$ck = 'dv_ic4_' . $cnpj; $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$V = function_exists( 'dv_part_vencidas' ) ? dv_part_vencidas( $cnpj ) : array( 'itens' => array() );
	$cad = dv_int_cad( $cnpj ); $nome = $cad['cadastro']['razao_social'] ?? '';
	$P = function_exists( 'dv_ds_pncp' ) ? dv_ds_pncp( $cnpj, '' ) : array( 'itens' => array() );
	$E = array();
	$add = function ( $obj, $valor, $meses, $fonte, $orgao, $data ) use ( &$E ) {
		$r = dv_int_ramo( $obj ); if ( ! isset( $E[ $r ] ) ) { $E[ $r ] = array( 'ramo' => $r, 'n' => 0, 'maior_valor' => 0, 'maior_valor_orgao' => '', 'max_postos' => 0, 'max_meses' => 0, 'soma' => 0, 'ultimo' => '' ); }
		$e = &$E[ $r ]; $e['n']++; $e['soma'] += $valor;
		if ( $valor > $e['maior_valor'] ) { $e['maior_valor'] = $valor; $e['maior_valor_orgao'] = $orgao; }
		$p = dv_int_postos( $obj ); if ( $p > $e['max_postos'] ) { $e['max_postos'] = $p; }
		if ( $meses > $e['max_meses'] ) { $e['max_meses'] = $meses; }
		if ( $data > $e['ultimo'] ) { $e['ultimo'] = $data; }
	};
	foreach ( (array) $P['itens'] as $i ) { $add( $i['objeto'] ?? '', (float) ( $i['valor'] ?? 0 ), dv_int_meses( $i['inicio'] ?? '', $i['fim'] ?? '' ), 'contrato', $i['orgao'] ?? '', substr( (string) ( $i['assinatura'] ?? '' ), 0, 10 ) ); }
	foreach ( (array) $V['itens'] as $i ) { $add( $i['objeto'] ?? '', (float) ( $i['valor'] ?? 0 ), 0, 'vitoria', $i['orgao'] ?? '', $i['data'] ?? '' ); }
	uasort( $E, function ( $a, $b ) { return $b['soma'] <=> $a['soma']; } );
	/* perfil de preço */
	$ds = array(); $porUf = array(); $faixas = array( 'ate10' => 0, '10a25' => 0, '25a50' => 0, 'acima50' => 0 );
	foreach ( (array) $V['itens'] as $i ) {
		if ( $i['desconto'] === null ) { continue; } $d = (float) $i['desconto']; $ds[] = $d;
		$porUf[ $i['uf'] ][] = $d;
		$faixas[ $d < 10 ? 'ate10' : ( $d < 25 ? '10a25' : ( $d < 50 ? '25a50' : 'acima50' ) ) ]++;
	}
	sort( $ds ); $med = $ds ? $ds[ (int) floor( ( count( $ds ) - 1 ) / 2 ) ] : null;
	$uf = array(); foreach ( $porUf as $k => $v ) { $uf[ $k ] = array( 'n' => count( $v ), 'media' => round( array_sum( $v ) / count( $v ), 1 ) ); }
	$c = array( 'nome' => $nome, 'experiencia' => array_values( array_slice( $E, 0, 8 ) ), 'fontes' => array( 'contratos' => count( (array) $P['itens'] ), 'vitorias' => count( (array) $V['itens'] ) ),
		'perfil' => array( 'n' => count( $ds ), 'media' => $ds ? round( array_sum( $ds ) / count( $ds ), 1 ) : null, 'mediana' => $med, 'faixas' => $faixas, 'uf' => $uf ),
		'resumo' => array( 'vitorias' => (int) ( $V['total'] ?? 0 ), 'valor' => (float) ( $V['valor'] ?? 0 ), 'desconto' => $V['desconto_medio'] ?? null, 'capital' => (float) ( $cad['cadastro']['capital_social'] ?? 0 ), 'abertura' => $cad['cadastro']['abertura'] ?? '', 'porte' => $cad['cadastro']['porte'] ?? '', 'contratos' => count( (array) $P['itens'] ), 'contratos_valor' => array_sum( array_map( function ( $i ) { return (float) ( $i['valor'] ?? 0 ); }, (array) $P['itens'] ) ) ) );
	set_transient( $ck, $c, 12 * HOUR_IN_SECONDS );
	return $c;
}

/* ---------------- Comprador ---------------- */
function dv_int_toks( $t ) {
	$stop = '|contratacao|empresa|empresas|prestacao|servico|servicos|continuos|fornecimento|aquisicao|especializada|objeto|registro|precos|eventual|futura|atender|secretaria|municipal|conforme|demais|termo|referencia|edital|';
	$o = array(); foreach ( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( (string) $t ) ) ) as $w ) { if ( strlen( $w ) > 4 && strpos( $stop, '|' . $w . '|' ) === false ) { $o[] = $w; } }
	return array_slice( array_unique( $o ), 0, 6 );
}
function dv_int_bate( $txt, $tk ) { if ( ! $tk ) { return true; } $t = strtolower( remove_accents( (string) $txt ) ); foreach ( $tk as $w ) { if ( strpos( $t, $w ) !== false ) { return true; } } return false; }

function dv_int_orgao( $cnpj, $item ) {
	$tk = dv_int_toks( $item ); $ck = 'dv_io6_' . $cnpj . '_' . md5( implode( ',', $tk ) ); $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$prod = (bool) preg_match( '/aquisi|material|produto|genero|equipament|fornecimento de|compra de/', strtolower( remove_accents( $item ) ) );
	$bate = function ( $t ) use ( $tk, $prod ) { if ( ! dv_int_bate( $t, $tk ) ) { return false; } return ! $tk || $prod || ! preg_match( '/aquisicao de|produtos? de|materia(l|is) de|generos|compra de|equipamentos?/', strtolower( remove_accents( (string) $t ) ) ); };
	$hoje = current_time( 'timestamp' ); $ano = (int) gmdate( 'Y', $hoje );
	/* contratos do órgão (3 anos) */
	$u = array(); for ( $a = $ano; $a >= $ano - 2; $a-- ) { for ( $pg = 1; $pg <= 2; $pg++ ) { $u[ $a . '_' . $pg ] = 'https://pncp.gov.br/api/consulta/v1/contratos?cnpjOrgao=' . $cnpj . '&tamanhoPagina=500&pagina=' . $pg . '&dataInicial=' . $a . '0101&dataFinal=' . ( $a === $ano ? gmdate( 'Ymd', $hoje ) : $a . '1231' ); } }
	$ct = array(); $vis = array();
	foreach ( dv_int_get( $u, 35 ) as $b ) { $j = $b ? json_decode( $b, true ) : null; foreach ( (array) ( $j['data'] ?? array() ) as $x ) {
		$k = $x['numeroControlePNCP'] ?? md5( wp_json_encode( $x ) ); if ( isset( $vis[ $k ] ) ) { continue; } $vis[ $k ] = 1;
		$ct[] = array( 'ni' => preg_replace( '/\D/', '', (string) ( $x['niFornecedor'] ?? '' ) ), 'nome' => $x['nomeRazaoSocialFornecedor'] ?? '', 'objeto' => mb_substr( (string) ( $x['objetoContrato'] ?? '' ), 0, 220 ),
			'valor' => (float) ( $x['valorGlobal'] ?? $x['valorInicial'] ?? 0 ), 'inicio' => substr( (string) ( $x['dataVigenciaInicio'] ?? '' ), 0, 10 ), 'fim' => substr( (string) ( $x['dataVigenciaFim'] ?? '' ), 0, 10 ),
			'link' => ! empty( $x['numeroControlePNCP'] ) && preg_match( '#^(\d{14})-\d-(\d+)/(\d{4})$#', $x['numeroControlePNCP'], $m ) ? 'https://pncp.gov.br/app/contratos/' . $m[1] . '/' . $m[3] . '/' . (int) $m[2] : '' );
	} }
	/* quem ganha */
	$F = array(); foreach ( $ct as $x ) { if ( strlen( $x['ni'] ) !== 14 || $x['valor'] < 5000 || ! $bate( $x['objeto'] ) ) { continue; } $k = $x['ni']; if ( ! isset( $F[ $k ] ) ) { $F[ $k ] = array( 'cnpj' => $k, 'nome' => $x['nome'], 'n' => 0, 'valor' => 0 ); } $F[ $k ]['n']++; $F[ $k ]['valor'] += $x['valor']; }
	usort( $F, function ( $a, $b ) { return $b['valor'] <=> $a['valor']; } );
	$totF = array_sum( array_column( $F, 'valor' ) );
	/* calendário: contratos que vencem nos próximos 6 meses */
	$cal = array(); $lim = gmdate( 'Y-m-d', $hoje + 183 * DAY_IN_SECONDS ); $hj = gmdate( 'Y-m-d', $hoje );
	foreach ( $ct as $x ) { if ( $x['fim'] >= $hj && $x['fim'] <= $lim && $bate( $x['objeto'] ) && $x['valor'] > 0 ) { $cal[] = $x; } }
	usort( $cal, function ( $a, $b ) { return strcmp( $a['fim'], $b['fim'] ); } );
	/* histórico das licitações (12 meses, pregão eletrônico) */
	$ini = gmdate( 'Ymd', $hoje - 365 * DAY_IN_SECONDS ); $fim = gmdate( 'Ymd', $hoje ); $u = array();
	for ( $pg = 1; $pg <= 6; $pg++ ) { $u[ $pg ] = 'https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao?dataInicial=' . $ini . '&dataFinal=' . $fim . '&codigoModalidadeContratacao=6&cnpj=' . $cnpj . '&tamanhoPagina=50&pagina=' . $pg; }
	$cp = array(); foreach ( dv_int_get( $u, 35 ) as $b ) { $j = $b ? json_decode( $b, true ) : null; foreach ( (array) ( $j['data'] ?? array() ) as $x ) { $cp[ $x['numeroControlePNCP'] ?? count( $cp ) ] = $x; } }
	$todos = array(); $PI = array();
	$H = array( 'total' => 0, 'revogadas' => 0, 'anuladas' => 0, 'suspensas' => 0, 'sem_resultado' => 0, 'do_item' => 0, 'descontos' => array(), 'srp' => 0 );
	foreach ( $cp as $x ) {
		$H['total']++; $s = strtolower( remove_accents( (string) ( $x['situacaoCompraNome'] ?? '' ) ) );
		if ( strpos( $s, 'revog' ) !== false ) { $H['revogadas']++; } elseif ( strpos( $s, 'anul' ) !== false ) { $H['anuladas']++; } elseif ( strpos( $s, 'suspen' ) !== false ) { $H['suspensas']++; }
		$enc = substr( (string) ( $x['dataEncerramentoProposta'] ?? '' ), 0, 10 );
		if ( empty( $x['existeResultado'] ) && $enc && $enc < gmdate( 'Y-m-d', $hoje - 45 * DAY_IN_SECONDS ) && strpos( $s, 'divulgada' ) !== false ) { $H['sem_resultado']++; }
		if ( ! empty( $x['srp'] ) ) { $H['srp']++; }
		if ( $bate( $x['objetoCompra'] ?? '' ) ) { $H['do_item']++; }
		if ( ( ! empty( $x['existeResultado'] ) || (float) ( $x['valorTotalHomologado'] ?? 0 ) > 0 || ( substr( (string) ( $x['dataEncerramentoProposta'] ?? '' ), 0, 10 ) && substr( (string) ( $x['dataEncerramentoProposta'] ?? '' ), 0, 10 ) < gmdate( 'Y-m-d', $hoje - 5 * DAY_IN_SECONDS ) ) ) && ( ! $tk || $bate( $x['objetoCompra'] ?? '' ) ) && preg_match( '#^(\d{14})-\d-(\d+)/(\d{4})$#', (string) ( $x['numeroControlePNCP'] ?? '' ), $mm ) ) {
			$e1 = (float) ( $x['valorTotalEstimado'] ?? 0 ); $h1 = (float) ( $x['valorTotalHomologado'] ?? 0 ); $s1 = (int) ( $x['orcamentoSigilosoCodigo'] ?? 1 );
			$PI[] = array( 'ref' => $mm[1] . '/' . $mm[3] . '/' . (int) $mm[2], 'objeto' => mb_substr( (string) ( $x['objetoCompra'] ?? '' ), 0, 200 ), 'data' => substr( (string) ( $x['dataEncerramentoProposta'] ?? $x['dataPublicacaoPncp'] ?? '' ), 0, 10 ),
				'desconto' => ( $e1 > 0 && $h1 > 0 && $s1 <= 1 && $h1 < $e1 * 1.3 ) ? round( ( 1 - $h1 / $e1 ) * 100, 1 ) : null, 'numero' => trim( ( $x['numeroCompra'] ?? '' ) . '/' . ( $x['anoCompra'] ?? '' ) ) );
		}
		$e = (float) ( $x['valorTotalEstimado'] ?? 0 ); $h = (float) ( $x['valorTotalHomologado'] ?? 0 ); $sig = (int) ( $x['orcamentoSigilosoCodigo'] ?? 1 );
		if ( $e > 0 && $h > 0 && $sig <= 1 ) { $d = ( 1 - $h / $e ) * 100; if ( $d > -30 && $d < 95 ) { $todos[] = $d; if ( $tk && $bate( $x['objetoCompra'] ?? '' ) ) { $H['descontos'][] = $d; } } }
	}
	if ( ! $tk || ! $H['descontos'] ) { $H['descontos'] = $todos; $H['desconto_do_item'] = false; } else { $H['desconto_do_item'] = true; }
	$H['desconto_medio'] = $H['descontos'] ? round( array_sum( $H['descontos'] ) / count( $H['descontos'] ), 1 ) : null; $H['n_desconto'] = count( $H['descontos'] ); unset( $H['descontos'] );
	usort( $PI, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$c = array( 'item' => $item, 'tokens' => $tk, 'contratos' => count( $ct ), 'pregoes_item' => array_slice( $PI, 0, 10 ), 'vencedores' => array_slice( $F, 0, 8 ), 'concentracao' => ( $F && $totF > 0 ) ? round( $F[0]['valor'] / $totF * 100 ) : null,
		'calendario' => array_slice( $cal, 0, 10 ), 'n_calendario' => count( $cal ), 'historico' => $H );
	set_transient( $ck, $c, ( $ct || $cp ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $c;
}

/** Prazo real de pagamento (TCE-SP, municípios paulistas): dias entre a liquidação e o pagamento. */
function dv_int_pagamentos( $cnpj ) {
	$ck = 'dv_ipg2_' . $cnpj; $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$cad = dv_int_cad( $cnpj ); $cd = $cad['cadastro'] ?? array();
	$nat = dv_int_n( $cd['natureza'] ?? '' );
	if ( ( $cd['uf'] ?? '' ) !== 'SP' || strpos( $nat, 'MUNICIP' ) === false ) { $c = array( 'disponivel' => false, 'motivo' => 'O prazo real de pagamento está disponível para as prefeituras de São Paulo (dados do TCE-SP).' ); set_transient( $ck, $c, 7 * DAY_IN_SECONDS ); return $c; }
	$mu = get_transient( 'dv_tcesp_mun' );
	if ( ! $mu ) { $g = wp_remote_get( 'https://transparencia.tce.sp.gov.br/api/json/municipios', array( 'timeout' => 30 ) ); $mu = is_wp_error( $g ) ? array() : (array) json_decode( wp_remote_retrieve_body( $g ), true ); if ( $mu ) { set_transient( 'dv_tcesp_mun', $mu, 30 * DAY_IN_SECONDS ); } }
	$alvo = dv_int_n( $cd['municipio'] ?? '' ); $slug = '';
	foreach ( (array) $mu as $m ) { if ( dv_int_n( $m['municipio_extenso'] ?? '' ) === $alvo ) { $slug = $m['municipio'] ?? ''; break; } }
	if ( ! $slug ) { $c = array( 'disponivel' => false, 'motivo' => 'Município sem dados no TCE-SP (a capital é fiscalizada pelo TCM-SP).' ); set_transient( $ck, $c, 7 * DAY_IN_SECONDS ); return $c; }
	@set_time_limit( 150 );
	$liq = array(); $pag = array(); $meses = array(); $t = current_time( 'timestamp' );
	for ( $i = 1; $i <= 14 && count( $meses ) < 3; $i++ ) {
		$ts = strtotime( gmdate( 'Y-m-01', $t ) . " -$i month" ); $a = gmdate( 'Y', $ts ); $mm = (int) gmdate( 'n', $ts );
		$g = wp_remote_get( 'https://transparencia.tce.sp.gov.br/api/json/despesas/' . rawurlencode( $slug ) . '/' . $a . '/' . $mm, array( 'timeout' => 60 ) );
		if ( is_wp_error( $g ) ) { continue; } $b = wp_remote_retrieve_body( $g ); if ( strlen( $b ) < 10 || strlen( $b ) > 40000000 ) { continue; }
		$j = json_decode( $b, true ); unset( $b ); if ( ! $j ) { continue; }
		$meses[] = sprintf( '%02d/%d', $mm, $a );
		foreach ( $j as $x ) {
			$f = (string) ( $x['id_fornecedor'] ?? '' ); if ( stripos( $f, 'CNPJ' ) === false || strpos( $f, $cnpj ) !== false ) { continue; }
			$d = DateTime::createFromFormat( 'd/m/Y', (string) ( $x['dt_emissao_despesa'] ?? '' ) ); if ( ! $d ) { continue; } $d = $d->format( 'Y-m-d' );
			$ev = (string) ( $x['evento'] ?? '' ); $e = (string) ( $x['nr_empenho'] ?? '' );
			if ( stripos( $ev, 'Liquidado' ) !== false ) { $liq[ $e ][] = $d; } elseif ( stripos( $ev, 'Pago' ) !== false ) { $pag[] = array( $e, $d ); }
		}
		unset( $j );
	}
	$dias = array();
	foreach ( $pag as $p ) { if ( empty( $liq[ $p[0] ] ) ) { continue; } $best = ''; foreach ( $liq[ $p[0] ] as $l ) { if ( $l <= $p[1] && $l > $best ) { $best = $l; } } if ( $best ) { $dias[] = (int) round( ( strtotime( $p[1] ) - strtotime( $best ) ) / DAY_IN_SECONDS ); } }
	if ( count( $dias ) < 20 ) { $c = array( 'disponivel' => false, 'motivo' => $meses ? 'Poucos pagamentos com liquidação no período para calcular o prazo.' : 'O TCE-SP ainda não publicou os dados recentes deste município.' ); set_transient( $ck, $c, 2 * DAY_IN_SECONDS ); return $c; }
	sort( $dias ); $n = count( $dias ); $q = function ( $p ) use ( $dias, $n ) { return $dias[ min( $n - 1, (int) floor( $p * ( $n - 1 ) ) ) ]; };
	$m30 = count( array_filter( $dias, function ( $d ) { return $d > 30; } ) );
	$c = array( 'disponivel' => true, 'municipio' => $cd['municipio'] ?? '', 'meses' => $meses, 'pagamentos' => $n, 'mediana' => $q( .5 ), 'p75' => $q( .75 ), 'p90' => $q( .9 ), 'acima30' => round( $m30 / $n * 100 ), 'mesmo_dia' => round( count( array_filter( $dias, function ( $d ) { return $d === 0; } ) ) / $n * 100 ) );
	set_transient( $ck, $c, 7 * DAY_IN_SECONDS ); return $c;
}

/** Participantes registrados na base (sessões, atas e robô) nas licitações do órgão. */
function dv_int_base_orgao( $cnpj, $refs ) {
	global $wpdb; $t = $wpdb->prefix . 'dv_part';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) !== $t ) { return array( 'licitacoes' => 0 ); }
	$refs = array_values( array_filter( (array) $refs ) );
	if ( $refs ) { $ph = implode( ',', array_fill( 0, count( $refs ), '%s' ) ); $w = $wpdb->prepare( "ref IN ($ph)", $refs ); } else { $w = $wpdb->prepare( 'ref LIKE %s', $wpdb->esc_like( $cnpj . '/' ) . '%' ); }
	$por = $wpdb->get_results( "SELECT ref, COUNT(DISTINCT cnpj) n FROM $t WHERE $w GROUP BY ref", ARRAY_A );
	if ( ! $por ) { return array( 'licitacoes' => 0, 'refs' => array() ); }
	$quem = $wpdb->get_results( "SELECT cnpj, MAX(nome) nome, COUNT(DISTINCT ref) n, SUM(posicao=1) v FROM $t WHERE $w GROUP BY cnpj ORDER BY n DESC, v DESC LIMIT 10", ARRAY_A );
	$ns = array_map( 'intval', array_column( $por, 'n' ) );
	return array( 'licitacoes' => count( $por ), 'media' => round( array_sum( $ns ) / count( $ns ), 1 ), 'min' => min( $ns ), 'max' => max( $ns ), 'refs' => array_column( $por, 'ref' ), 'por' => array_combine( array_column( $por, 'ref' ), $ns ), 'empresas' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cnpj) FROM $t WHERE $w" ), 'frequentes' => $quem );
}

/* ---------------- Due Diligence: sócios e linha do tempo ---------------- */
/* Bases públicas de compliance publicadas pelo robô no GitHub (lista suja do trabalho escravo e embargos do Ibama) */
function dv_int_comp( $arq ) {
	$k = 'dv_comp_' . md5( $arq ); $c = get_transient( $k ); if ( $c !== false ) { return $c; }
	$r = wp_remote_get( 'https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/compliance/' . $arq, array( 'timeout' => 25 ) );
	$c = ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : null;
	set_transient( $k, is_array( $c ) ? $c : array(), is_array( $c ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS ); return is_array( $c ) ? $c : array();
}
function dv_int_compliance( $cnpj, $grupo ) {
	$E = (array) ( dv_int_comp( 'escravo.json' )['itens'] ?? array() ); $meta = dv_int_comp( 'meta.json' ); $o = array( 'escravo' => array(), 'embargos' => array(), 'atualizado' => $meta['atualizado'] ?? '' );
	$alvos = array( $cnpj => 'a própria empresa' ); foreach ( (array) $grupo as $g ) { $c = preg_replace( '/\D/', '', (string) ( $g['cnpj'] ?? '' ) ); if ( strlen( $c ) === 14 && count( $alvos ) < 40 ) { $alvos[ $c ] = $g['razao'] ?? 'empresa do grupo'; } }
	foreach ( $E as $doc => $x ) { if ( strlen( $doc ) !== 14 ) { continue; } foreach ( $alvos as $c => $quem ) { if ( substr( $doc, 0, 8 ) === substr( $c, 0, 8 ) ) { $o['escravo'][] = array( 'cnpj' => $doc, 'nome' => $x['nome'] ?? '', 'uf' => $x['uf'] ?? '', 'trabalhadores' => $x['trabalhadores'] ?? '', 'inclusao' => $x['inclusao'] ?? '', 'quem' => $c === $cnpj ? 'a própria empresa' : $quem ); } } }
	foreach ( $alvos as $c => $quem ) { $L = (array) ( dv_int_comp( 'embargos/' . substr( $c, 0, 2 ) . '.json' )[ substr( $c, 0, 8 ) ] ?? array() ); foreach ( array_slice( $L, 0, 8 ) as $x ) { $x['quem'] = $c === $cnpj ? 'a própria empresa' : $quem; $o['embargos'][] = $x; } if ( count( $o['embargos'] ) >= 20 ) { break; } }
	return $o;
}
/* Sinais de empresa de fachada (indícios, não conclusão) */
function dv_int_fachada( $D ) {
	$cad = (array) ( $D['cadastro'] ?? array() ); $si = array();
	$cap = (float) ( $cad['capital_social'] ?? 0 ); $vc = 0; foreach ( (array) ( $D['pncp']['itens'] ?? array() ) as $x ) { $vc += (float) ( $x['valor'] ?? 0 ); }
	$ab = strtotime( (string) ( $cad['data_inicio_atividade'] ?? '' ) ); $anos = $ab ? ( current_time( 'timestamp' ) - $ab ) / ( 365 * DAY_IN_SECONDS ) : null;
	if ( $cap > 0 && $cap < 10000 && $vc > 500000 ) { $si[] = 'Capital social de R$ ' . number_format( $cap, 0, ',', '.' ) . ' para R$ ' . number_format( $vc, 0, ',', '.' ) . ' em contratos públicos.'; }
	if ( $anos !== null && $anos < 1 ) { $si[] = 'Empresa aberta há menos de 1 ano.'; }
	$end = 0; $tel = 0; foreach ( (array) ( $D['grupo'] ?? array() ) as $g ) { foreach ( (array) ( $g['vias'] ?? array() ) as $v ) { if ( strpos( $v, 'Mesmo endereço' ) === 0 ) { $end++; } if ( strpos( $v, 'Mesmo telefone' ) === 0 ) { $tel++; } } }
	if ( $end >= 3 ) { $si[] = $end . ' outras empresas registradas no mesmo endereço.'; }
	if ( $tel >= 3 ) { $si[] = $tel . ' outras empresas com o mesmo telefone de cadastro.'; }
	$soc = (array) ( $cad['qsa'] ?? array() ); $ind = preg_match( '/EMPRES[AÁ]RIO|INDIVIDUAL|MEI/i', (string) ( $cad['natureza_juridica'] ?? '' ) . ' ' . ( ! empty( $cad['opcao_pelo_mei'] ) ? 'MEI' : '' ) ); if ( ! $soc && ! $ind ) { $si[] = 'Quadro de sócios não informado na Receita.'; }
	$sit = strtoupper( (string) ( $cad['descricao_situacao_cadastral'] ?? '' ) ); if ( $sit && $sit !== 'ATIVA' ) { $si[] = 'Situação na Receita: ' . $sit . '.'; }
	if ( preg_match( '/^(ME|MICRO|EPP|EMPRESA DE PEQUENO)/i', (string) ( $cad['porte'] ?? '' ) ) && $vc > 4800000 ) { $si[] = 'Porte de micro ou pequena empresa com mais de R$ 4,8 milhões em contratos públicos.'; }
	return array( 'sinais' => $si, 'nivel' => count( $si ) >= 3 ? 'alto' : ( count( $si ) >= 1 ? 'medio' : 'baixo' ) );
}
function dv_int_dossie( $cnpj ) {
	$D = get_transient( 'dv_ds9_' . $cnpj ); if ( ! $D || ! is_array( $D ) ) { return null; }
	$cad = (array) ( $D['cadastro'] ?? array() ); $S = array();
	$crim = array(); foreach ( (array) ( $D['judicial']['socios'] ?? array() ) as $s ) { $crim[ dv_int_n( $s['nome'] ?? '' ) ] = count( (array) ( $s['processos'] ?? array() ) ); }
	$pep = array(); foreach ( (array) ( $D['pep'] ?? array() ) as $p ) { $pep[ dv_int_n( $p['nome'] ?? '' ) ] = $p['cargos']; }
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) {
		$nm = $s['nome_socio'] ?? ''; $k = dv_int_n( $nm ); $emp = array(); $div = 0;
		foreach ( (array) ( $D['grupo'] ?? array() ) as $g ) { foreach ( (array) $g['vias'] as $v ) { if ( strpos( dv_int_n( $v ), $k ) !== false ) { $emp[] = array( 'cnpj' => $g['cnpj'], 'razao' => $g['razao'], 'pgfn' => (float) ( $g['pgfn'] ?? 0 ) ); $div += (float) ( $g['pgfn'] ?? 0 ); break; } } }
		$par = array_values( array_filter( (array) ( $D['parentes'] ?? array() ), function ( $p ) use ( $nm ) { return ( $p['socio'] ?? '' ) === $nm; } ) );
		$S[] = array( 'nome' => $nm, 'qualificacao' => $s['qualificacao_socio'] ?? '', 'entrada' => $s['data_entrada_sociedade'] ?? '', 'faixa' => $s['faixa_etaria'] ?? '', 'pj' => strlen( preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) ) ) === 14,
			'empresas' => array_slice( $emp, 0, 8 ), 'n_empresas' => count( $emp ), 'divida_grupo' => $div, 'criminais' => $crim[ $k ] ?? 0, 'pep' => $pep[ $k ] ?? null, 'parentes' => count( $par ) );
	}
	$T = array(); $ev = function ( $d, $t, $tipo, $link = '' ) use ( &$T ) { $d = substr( (string) $d, 0, 10 ); if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) { $T[] = array( 'data' => $d, 'texto' => $t, 'tipo' => $tipo, 'link' => $link ); } };
	$ev( $cad['data_inicio_atividade'] ?? '', 'Abertura da empresa' . ( ! empty( $cad['capital_social'] ) ? ' (capital social atual de R$ ' . number_format( (float) $cad['capital_social'], 0, ',', '.' ) . ')' : '' ), 'cadastro' );
	if ( ! empty( $cad['data_situacao_cadastral'] ) && ( $cad['data_situacao_cadastral'] !== ( $cad['data_inicio_atividade'] ?? '' ) ) ) { $ev( $cad['data_situacao_cadastral'], 'Situação cadastral: ' . ( $cad['descricao_situacao_cadastral'] ?? '' ), 'cadastro' ); }
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) { $ev( $s['data_entrada_sociedade'] ?? '', 'Entrada de ' . ( $s['nome_socio'] ?? '' ) . ' (' . ( $s['qualificacao_socio'] ?? 'sócio' ) . ')', 'socio' ); }
	foreach ( array_slice( (array) ( $D['pncp']['itens'] ?? array() ), 0, 40 ) as $x ) { $ev( $x['assinatura'] ?? '', 'Contrato com ' . ( $x['orgao'] ?? 'órgão público' ) . ( ! empty( $x['valor'] ) ? ' (R$ ' . number_format( (float) $x['valor'], 0, ',', '.' ) . ')' : '' ), 'contrato', $x['link'] ?? '' ); }
	$grav = array( 'criminal' => 'Processo criminal', 'improbidade' => 'Ação de improbidade', 'coletiva' => 'Ação coletiva / civil pública', 'falencia' => 'Falência ou recuperação judicial', 'trab_execucao' => 'Execução trabalhista' );
	$pr = (array) ( $D['judicial']['empresa']['processos'] ?? array() ); $nTrab = array();
	foreach ( $pr as $p ) {
		$tp = $p['tipo'] ?? '';
		if ( isset( $grav[ $tp ] ) ) { $ev( $p['data'] ?? '', $grav[ $tp ] . ' · ' . ( $p['tribunal'] ?? '' ) . ' · ' . ( $p['processo'] ?? '' ), 'processo', $p['link'] ?? '' ); }
		elseif ( $tp === 'trabalhista' ) { $y = substr( (string) ( $p['data'] ?? '' ), 0, 4 ); if ( $y ) { $nTrab[ $y ] = ( $nTrab[ $y ] ?? 0 ) + 1; } }
	}
	foreach ( $nTrab as $y => $n ) { $ev( min( $y . '-12-31', current_time( 'Y-m-d' ) ), $n . ' processo(s) trabalhista(s) publicado(s) em ' . $y, 'trabalhista' ); }
	foreach ( (array) ( $D['noticias']['itens'] ?? array() ) as $x ) { $ev( $x['data'] ?? '', 'Notícia: ' . ( $x['titulo'] ?? '' ), 'noticia', $x['url'] ?? '' ); }
	foreach ( array( 'ceis', 'cnep' ) as $k ) { foreach ( (array) ( $D['raiox']['sancoes'][ $k ] ?? array() ) as $x ) { $ev( $x['dataInicioSancao'] ?? $x['inicio'] ?? '', 'Sanção ' . strtoupper( $k ) . ': ' . ( $x['tipoSancao']['descricaoResumida'] ?? $x['tipo'] ?? 'registro' ), 'sancao' ); } }
	usort( $T, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	/* alertas da linha do tempo */
	$al = array(); $ent = array_filter( array_map( function ( $s ) { return $s['entrada']; }, $S ) ); rsort( $ent );
	$ultC = ''; foreach ( (array) ( $D['pncp']['itens'] ?? array() ) as $x ) { if ( ( $x['assinatura'] ?? '' ) > $ultC ) { $ultC = substr( $x['assinatura'], 0, 10 ); } }
	if ( $ent && strtotime( $ent[0] ) > current_time( 'timestamp' ) - 730 * DAY_IN_SECONDS ) { $al[] = 'Mudança no quadro societário nos últimos 2 anos (' . date_i18n( 'd/m/Y', strtotime( $ent[0] ) ) . ').'; }
	$ab = strtotime( (string) ( $cad['data_inicio_atividade'] ?? '' ) ); if ( $ab && $ab > current_time( 'timestamp' ) - 3 * 365 * DAY_IN_SECONDS ) { $al[] = 'Empresa com menos de 3 anos de atividade.'; }
	$NT = array( 'execucao' => 'Execuções e cobranças', 'execucao_fiscal' => 'Execuções fiscais', 'trab_execucao' => 'Execuções trabalhistas', 'trabalhista' => 'Trabalhistas', 'falencia' => 'Falência ou recuperação judicial', 'criminal' => 'Criminais', 'improbidade' => 'Improbidade', 'coletiva' => 'Ações coletivas', 'outros' => 'Outros' );
	$PT = array(); foreach ( $pr as $p ) { $tp = $p['tipo'] ?? 'outros'; $tp = isset( $NT[ $tp ] ) ? $tp : 'outros'; $PT[ $tp ] = ( $PT[ $tp ] ?? 0 ) + 1; }
	$ptipos = array(); foreach ( $NT as $k => $n ) { if ( ! empty( $PT[ $k ] ) ) { $ptipos[] = array( 'tipo' => $k, 'nome' => $n, 'n' => $PT[ $k ] ); } }
	return array( 'socios' => $S, 'timeline' => array_slice( $T, 0, 80 ), 'alertas' => $al, 'processos_tipo' => $ptipos, 'compliance' => dv_int_compliance( $cnpj, $D['grupo'] ?? array() ), 'fachada' => dv_int_fachada( $D ) );
}

/* ---------------- Monitoramento de concorrentes ---------------- */
function dv_int_mon_lista( $uid ) { $l = json_decode( (string) get_user_meta( $uid, 'dv_mon', true ), true ); return is_array( $l ) ? $l : array(); }
function dv_int_snap( $cnpj ) {
	delete_transient( 'dv_icad_' . $cnpj ); $cad = dv_int_cad( $cnpj ); $V = function_exists( 'dv_part_vencidas' ) ? dv_part_vencidas( $cnpj ) : array( 'itens' => array() );
	$s = (array) ( $cad['sancoes'] ?? array() ); $p = function_exists( 'dv_ds_pgfn' ) ? dv_ds_pgfn( $cnpj ) : null;
	return array( 'refs' => array_column( (array) $V['itens'], 'ref' ), 'vitorias' => array_map( function ( $i ) { return array( $i['ref'], $i['orgao'], $i['valor'], $i['link'] ); }, (array) $V['itens'] ),
		'sancoes' => count( (array) ( $s['ceis'] ?? array() ) ) + count( (array) ( $s['cnep'] ?? array() ) ) + count( (array) ( $s['tcu'] ?? array() ) ), 'situacao' => $cad['cadastro']['situacao'] ?? '',
		'pgfn' => $p ? (float) $p['total'] : 0, 'cobranca' => $p ? (float) $p['em_cobranca'] : 0, 'nome' => $cad['cadastro']['razao_social'] ?? '' );
}
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_int_mon_cron' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:37 UTC' ), 'daily', 'dv_int_mon_cron' ); } if ( ! wp_next_scheduled( 'dv_int_part_cron' ) ) { wp_schedule_event( strtotime( 'tomorrow 11:13 UTC' ), 'daily', 'dv_int_part_cron' ); } } );
add_action( 'dv_int_mon_cron', function () {
	global $wpdb; @set_time_limit( 600 ); $feitos = 0;
	$uids = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_mon' AND meta_value<>'[]'" );
	foreach ( $uids as $uid ) {
		$uid = (int) $uid; $u = get_userdata( $uid ); if ( ! $u ) { continue; }
		$snaps = json_decode( (string) get_user_meta( $uid, 'dv_mon_snap', true ), true ) ?: array(); $msg = array();
		foreach ( dv_int_mon_lista( $uid ) as $c ) {
			if ( ++$feitos > 300 ) { break 2; }
			delete_transient( 'dv_venc5_' . $c ); $n = dv_int_snap( $c ); $o = $snaps[ $c ] ?? null; $snaps[ $c ] = $n; if ( ! $o ) { continue; }
			$nm = $n['nome'] ?: $c; $L = array();
			foreach ( $n['vitorias'] as $v ) { if ( ! in_array( $v[0], (array) $o['refs'], true ) ) { $L[] = 'Venceu licitação: ' . ( $v[1] ?: $v[0] ) . ' (R$ ' . number_format( (float) $v[2], 0, ',', '.' ) . ') ' . $v[3]; } }
			if ( $n['sancoes'] > $o['sancoes'] ) { $L[] = 'Nova sanção registrada em cadastro público (CEIS, CNEP ou TCU).'; }
			if ( $n['situacao'] && $o['situacao'] && $n['situacao'] !== $o['situacao'] ) { $L[] = 'Situação na Receita mudou de ' . $o['situacao'] . ' para ' . $n['situacao'] . '.'; }
			if ( $n['cobranca'] > 0 && $o['cobranca'] <= 0 ) { $L[] = 'Passou a ter dívida ativa da União em cobrança (R$ ' . number_format( $n['cobranca'], 0, ',', '.' ) . ').'; }
			if ( $L ) { $msg[] = $nm . "\n" . implode( "\n", array_map( function ( $x ) { return '• ' . $x; }, $L ) ) . "\nRaio-X: " . home_url( '/plataforma/raio-x/?cnpj=' . $c ); }
		}
		update_user_meta( $uid, 'dv_mon_snap', wp_slash( wp_json_encode( $snaps, JSON_UNESCAPED_UNICODE ) ) );
		if ( $msg ) { wp_mail( $u->user_email, 'Novidades dos concorrentes que você monitora', "Olá!\n\nEncontramos novidades nos concorrentes que você acompanha na Desenvolve:\n\n" . implode( "\n\n", $msg ) . "\n\nInformações de fontes públicas e oficiais. Não constitui parecer jurídico.\nDesenvolve Estratégias" ); }
	}
} );

/* ---------------- Importação diária da base de participações (robô das atas) ---------------- */
add_action( 'dv_int_part_cron', function () {
	global $wpdb; $t = $wpdb->prefix . 'dv_part'; if ( ! function_exists( 'dv_part_cnpj_ok' ) ) { return; }
	$g = wp_remote_get( 'https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/participacoes/novos.json', array( 'timeout' => 60 ) );
	if ( is_wp_error( $g ) ) { return; } $L = json_decode( wp_remote_retrieve_body( $g ), true ); if ( ! is_array( $L ) ) { return; }
	foreach ( $L as $x ) {
		$ref = (string) ( $x['ref'] ?? '' ); if ( ! preg_match( '#^\d{14}/\d{4}/\d{1,6}$#', $ref ) ) { continue; } $orgc = substr( $ref, 0, 14 );
		foreach ( array_slice( (array) ( $x['participantes'] ?? array() ), 0, 80 ) as $p ) {
			$c = preg_replace( '/\D/', '', (string) ( $p['c'] ?? '' ) ); if ( $c === $orgc || ! dv_part_cnpj_ok( $c ) ) { continue; }
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (cnpj,nome,ref,item,ihash,objeto,orgao,estimado,valor,posicao,situacao,me,fonte,user_id,criado) VALUES (%s,%s,%s,'',%s,%s,%s,0,%f,%d,%s,0,'ata',0,%s)",
				$c, mb_substr( sanitize_text_field( (string) ( $p['n'] ?? '' ) ), 0, 190 ), $ref, substr( md5( '' ), 0, 8 ), mb_substr( sanitize_text_field( (string) ( $x['objeto'] ?? '' ) ), 0, 255 ), mb_substr( sanitize_text_field( (string) ( $x['orgao'] ?? '' ) ), 0, 190 ),
				max( 0, (float) ( $p['v'] ?? 0 ) ), max( 0, (int) ( $p['pos'] ?? 0 ) ), mb_substr( sanitize_text_field( (string) ( $p['st'] ?? '' ) ), 0, 120 ), current_time( 'mysql' ) ) );
		}
	}
	update_option( 'dv_part_import', current_time( 'mysql' ), false );
} );

/* ---------------- REST ---------------- */
add_action( 'rest_api_init', function () {
	$logado = function () { return is_user_logged_in(); };
	$cn = function ( $r ) { $c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); return strlen( $c ) === 14 ? $c : ''; };
	register_rest_route( 'desenvolve/v1', '/int/concorrente', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$uid = get_current_user_id(); @set_time_limit( 150 );
		$o = dv_int_concorrente( $c ); $nm = preg_replace( '/\D/', '', (string) $r['meu'] ); if ( strlen( $nm ) === 14 && function_exists( 'dv_part_cnpj_ok' ) && dv_part_cnpj_ok( $nm ) ) { update_user_meta( $uid, 'dv_cnpj_empresa', $nm ); } elseif ( (string) $r['meu'] === '0' ) { delete_user_meta( $uid, 'dv_cnpj_empresa' ); }
		$meu = preg_replace( '/\D/', '', (string) ( get_user_meta( $uid, 'dv_cnpj_empresa', true ) ?: get_user_meta( $uid, 'dv_doc', true ) ) );
		$o['meu'] = ( strlen( $meu ) === 14 && $meu !== $c ) ? $meu : '';
		$o['monitorado'] = in_array( $c, dv_int_mon_lista( $uid ), true );
		if ( ! empty( $r['comparar'] ) && $o['meu'] ) { $m = dv_int_concorrente( $o['meu'] ); $o['eu'] = array( 'nome' => $m['nome'], 'resumo' => $m['resumo'], 'perfil' => $m['perfil'] ); }
		return $o;
	} ) );
	register_rest_route( 'desenvolve/v1', '/int/monitor', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$uid = get_current_user_id(); $l = dv_int_mon_lista( $uid ); $c = $cn( $r );
		if ( $c && $r['acao'] === 'add' && ! in_array( $c, $l, true ) ) { if ( count( $l ) >= 20 ) { return new WP_Error( 'dv_mon', 'Você já monitora 20 concorrentes. Remova um para incluir outro.', array( 'status' => 400 ) ); } $l[] = $c;
			$s = json_decode( (string) get_user_meta( $uid, 'dv_mon_snap', true ), true ) ?: array(); $s[ $c ] = dv_int_snap( $c ); update_user_meta( $uid, 'dv_mon_snap', wp_slash( wp_json_encode( $s, JSON_UNESCAPED_UNICODE ) ) ); }
		if ( $c && $r['acao'] === 'del' ) { $l = array_values( array_diff( $l, array( $c ) ) ); }
		update_user_meta( $uid, 'dv_mon', wp_json_encode( $l ) );
		return array( 'lista' => $l );
	} ) );
	register_rest_route( 'desenvolve/v1', '/int/orgao', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); } @set_time_limit( 150 );
		$o = dv_int_orgao( $c, sanitize_text_field( (string) $r['item'] ) );
		$o['base'] = dv_int_base_orgao( $c, array_column( (array) $o['pregoes_item'], 'ref' ) );
		return $o;
	} ) );
	register_rest_route( 'desenvolve/v1', '/int/pagamentos', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		return dv_int_pagamentos( $c );
	} ) );
	register_rest_route( 'desenvolve/v1', '/int/dossie', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		if ( ! dv_int_pode( get_current_user_id(), 'dossie', $c ) ) { return new WP_Error( 'dv_pago', 'Gere a Due Diligence deste CNPJ para ver os sócios e a linha do tempo.', array( 'status' => 403 ) ); }
		return dv_int_dossie( $c ) ?: new WP_Error( 'dv_ds', 'Dados da Due Diligence indisponíveis. Gere o relatório de novo.', array( 'status' => 404 ) );
	} ) );
	/* Raio-X em lote: preço fixo por lote de até 20 CNPJs (cobra uma vez ao abrir; depois um CNPJ por chamada) */
	register_rest_route( 'desenvolve/v1', '/int/lote/abrir', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		$L = array(); foreach ( (array) $r['cnpjs'] as $x ) { $x = preg_replace( '/\D/', '', (string) $x ); if ( strlen( $x ) === 14 ) { $L[ $x ] = 1; } }
		$L = array_map( 'strval', array_keys( $L ) ); if ( ! $L ) { return new WP_Error( 'dv_cnpj', 'Cole pelo menos um CNPJ válido.', array( 'status' => 400 ) ); }
		if ( count( $L ) > 20 ) { return new WP_Error( 'dv_cnpj', 'O lote aceita até 20 CNPJs.', array( 'status' => 400 ) ); }
		sort( $L ); $h = substr( hash( 'sha256', implode( ',', $L ) ), 0, 16 ); $uid = get_current_user_id();
		$n = function_exists( 'dv_custo' ) ? dv_custo( 'lote' ) : 15;
		if ( $n > 0 && function_exists( 'dv_usar' ) ) {
			if ( ! dv_ilimitado( $uid ) && dv_saldo( $uid )['total'] < $n ) { return new WP_Error( 'dv_sem_credito', 'O Raio-X em lote usa ' . $n . ' créditos e o seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			$u = dv_usar( $uid, 'lote', 'lote=' . $h, 'Raio-X em lote (' . count( $L ) . ' CNPJs)' ); if ( is_wp_error( $u ) ) { return $u; }
			if ( ! empty( $u['cobrado'] ) ) { for ( $i = 2; $i <= $n; $i++ ) { $x = dv_usar( $uid, 'lote', 'lote=' . $h . '|' . $i, '' ); if ( is_wp_error( $x ) ) { return $x; } } }
		}
		set_transient( 'dv_lt_' . $uid . '_' . $h, $L, DAY_IN_SECONDS );
		return array( 'lote' => $h, 'total' => count( $L ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/int/lote', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$uid = get_current_user_id(); @set_time_limit( 150 );
		$L = get_transient( 'dv_lt_' . $uid . '_' . preg_replace( '/[^a-f0-9]/', '', (string) $r['lote'] ) );
		if ( ! is_array( $L ) || ! in_array( $c, $L, true ) ) { return new WP_Error( 'dv_lote', 'Lote expirado. Clique em "Gerar Raio-X de todos" de novo.', array( 'status' => 403 ) ); }
		$cad = dv_int_cad( $c ); if ( ! $cad ) { return new WP_Error( 'dv_cad', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
		$cd = $cad['cadastro']; $s = (array) ( $cad['sancoes'] ?? array() ); $p = function_exists( 'dv_ds_pgfn' ) ? dv_ds_pgfn( $c ) : null;
		$V = function_exists( 'dv_part_vencidas' ) ? dv_part_vencidas( $c ) : array();
		$fed = function_exists( 'dv_ds_interno' ) ? dv_ds_interno( '/dvtools/v1/fornecedor/federal', array( 'cnpj' => $c ) ) : null;
		$P = function_exists( 'dv_part_de' ) ? dv_part_de( $c ) : array();
		/* Comparador dentro do lote: sinais para cruzar os participantes (sócios, telefone, e-mail, endereço) */
		$ck = 'dv_lt_bra_' . $c; $b = get_transient( $ck );
		if ( $b === false ) { $x = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $c, array( 'timeout' => 20 ) ); $b = is_wp_error( $x ) ? array() : (array) json_decode( wp_remote_retrieve_body( $x ), true ); set_transient( $ck, $b, empty( $b['razao_social'] ) ? HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS ); }
		$nr = function ( $v ) { return trim( preg_replace( '/\s+/', ' ', strtoupper( remove_accents( (string) $v ) ) ) ); }; $vk = array();
		foreach ( (array) ( $b['qsa'] ?? array() ) as $q ) { $n = $nr( $q['nome_socio'] ?? '' ); if ( strlen( $n ) > 5 ) { $vk[ 'S|' . $n . '|' . preg_replace( '/\D/', '', (string) ( $q['cnpj_cpf_do_socio'] ?? '' ) ) ] = 'Sócio em comum: ' . $n; } }
		foreach ( array( 'ddd_telefone_1', 'ddd_telefone_2' ) as $f ) { $t = preg_replace( '/\D/', '', (string) ( $b[ $f ] ?? '' ) ); if ( strlen( $t ) >= 10 ) { $vk[ 'T|' . $t ] = 'Mesmo telefone: ' . $t; } }
		if ( ! empty( $b['email'] ) ) { $vk[ 'E|' . strtolower( trim( $b['email'] ) ) ] = 'Mesmo e-mail de cadastro'; }
		$cep = preg_replace( '/\D/', '', (string) ( $b['cep'] ?? '' ) ); $num = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $b['numero'] ?? '' ) ) );
		if ( strlen( $cep ) === 8 && $num !== '' && $num !== 'SN' ) { $vk[ 'A|' . $cep . '|' . $num . '|' . preg_replace( '/[^A-Z0-9]/', '', $nr( $b['complemento'] ?? '' ) ) ] = 'Mesmo endereço: ' . $nr( ( $b['logradouro'] ?? '' ) . ', ' . ( $b['numero'] ?? '' ) ); }
		$vk[ 'R|' . substr( $c, 0, 8 ) ] = 'Mesma empresa (matriz e filial)';
		return array( 'cnpj' => $c, 'nome' => $cd['razao_social'] ?? '', 'situacao' => $cd['situacao'] ?? '', 'abertura' => $cd['abertura'] ?? '', 'porte' => $cd['porte'] ?? '', 'capital' => (float) ( $cd['capital_social'] ?? 0 ), 'municipio' => ( $cd['municipio'] ?? '' ) . '/' . ( $cd['uf'] ?? '' ),
			'sancoes' => count( (array) ( $s['ceis'] ?? array() ) ) + count( (array) ( $s['cnep'] ?? array() ) ) + count( (array) ( $s['tcu'] ?? array() ) ),
			'pgfn' => $p ? (float) $p['total'] : 0, 'cobranca' => $p ? (float) $p['em_cobranca'] : 0, 'fgts' => $p ? (float) $p['fgts'] : 0,
			'vitorias' => (int) ( $V['total'] ?? 0 ), 'vitorias_valor' => (float) ( $V['valor'] ?? 0 ), 'desconto' => $V['desconto_medio'] ?? null,
			'contratos_federais' => (int) ( $fed['contratos_total'] ?? 0 ), 'disputas' => (int) ( $P['total'] ?? 0 ), 'primeiro' => (int) ( $P['primeiro_lugar'] ?? 0 ), 'vk' => $vk );
	} ) );
} );

/* ---------------- Página do Raio-X em lote ---------------- */
add_shortcode( 'dv_lote', function () {
	if ( ! is_user_logged_in() ) { return '<p>Entre na sua conta para usar o Raio-X em lote.</p>'; }
	$custo = function_exists( 'dv_teste_ilimitado' ) && dv_teste_ilimitado( get_current_user_id() ) ? 0 : ( function_exists( 'dv_custo' ) ? dv_custo( 'lote' ) : 15 );
	ob_start(); ?>
<div class="dv-tool dv-lote" data-sem-portao="1" data-custo="<?php echo (int) $custo; ?>" data-api="<?php echo esc_url( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
<div class="lt-in"><label for="lt-t"><b>Cole os CNPJs dos participantes</b> (um por linha, ou o texto da ata; até 20)</label>
<textarea id="lt-t" placeholder="12.345.678/0001-90&#10;98.765.432/0001-10"></textarea>
<div class="lt-b"><button type="button" class="lt-go">Gerar Raio-X de todos</button><span class="lt-c"></span></div><p class="lt-m"></p></div>
<div class="lt-out"></div>
</div>
	<?php return ob_get_clean();
} );

/* ---------------- Interface ---------------- */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( array( 'raio-x', 'raio-x-comprador', 'dossie', 'raio-x-lote' ) ) ) { return; }
	?>
<style id="dv-int-css">
.dv-ix{background:#fff!important;border:1px solid #E3DED2!important;border-radius:16px!important;padding:20px 22px!important;margin:0 0 18px!important;color:#16191E!important;font-family:'Hanken Grotesk',system-ui,sans-serif!important}
.dv-ix *{font-family:inherit!important;box-sizing:border-box}
.dv-ix .k{font-size:.74rem!important;letter-spacing:.14em;text-transform:uppercase;color:#8A6A2E!important;font-weight:700;margin:0 0 6px!important}
.dv-ix h3{margin:0 0 10px!important;font-size:1.15rem!important;color:#16191E!important}.dv-ix h4{margin:18px 0 8px!important;font-size:1rem!important;color:#16191E!important}
.dv-ix p{font-size:.93rem!important;line-height:1.5!important;color:#16191E!important;margin:0 0 8px!important}
.dv-ix .m{font-size:.8rem!important;color:#6E6A60!important}
.dv-ix table{width:100%;border-collapse:collapse;font-size:.88rem!important}.dv-ix th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#6E6A60;border-bottom:1px solid #E3DED2;padding:6px 8px}
.dv-ix td{border-bottom:1px solid #F0ECE2;padding:8px;vertical-align:top;color:#16191E!important}.dv-ix td small{display:block;color:#6E6A60;font-size:.78rem}
.dv-ix .tw{overflow-x:auto}.dv-ix a{color:#8A6A2E!important;font-weight:700}
.dv-ix .n{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 12px}.dv-ix .n div{background:#FAF8F3;border:1px solid #EEE9DD;border-radius:12px;padding:10px 12px}.dv-ix .n b{display:block;font-size:1.2rem!important}.dv-ix .n span{font-size:.78rem;color:#6E6A60}
.dv-ix .bt{display:inline-block;border:1px solid #C9A45C;background:#fff;color:#241A06!important;border-radius:10px;padding:8px 13px;font-weight:700;font-size:.88rem;cursor:pointer;text-decoration:none!important;margin:4px 6px 0 0}
.dv-ix .bt.p{background:#C9A45C}.dv-ix .bt.on{background:#EAF5EE;border-color:#8FBDA3;color:#1F5A3B!important}
.dv-ix .calc{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#FAF8F3;border:1px solid #EEE9DD;border-radius:12px;padding:10px 12px;margin:10px 0}
.dv-ix .calc input{width:110px;border:1px solid #D9D3C4;border-radius:8px;padding:7px 9px;font:inherit}
.dv-ix .bar{display:flex;height:12px;border-radius:6px;overflow:hidden;background:#EEE9DD;margin:6px 0}.dv-ix .bar i{display:block;height:100%}
.dv-ix .tl{list-style:none;margin:0!important;padding:0 0 0 14px!important;border-left:2px solid #E3DED2}.dv-ix .tl li{position:relative;margin:0 0 10px!important;padding-left:8px;font-size:.9rem!important;line-height:1.4!important}
.dv-ix .tl li:before{content:'';position:absolute;left:-21px;top:5px;width:10px;height:10px;border-radius:50%;background:#C9A45C}.dv-ix .tl li.processo:before,.dv-ix .tl li.sancao:before{background:#B4472F}.dv-ix .tl li.socio:before{background:#2F6FA8}.dv-ix .tl li.contrato:before{background:#2F7A55}
.dv-ix .tl li b{display:inline-block;min-width:86px;color:#6E6A60;font-weight:600}
.dv-ix .al{background:#FBF1DC;border:1px solid #EBD39A;border-radius:10px;padding:8px 12px;margin:0 0 10px;font-size:.9rem}
.dv-ix .sc{border:1px solid #EEE9DD;border-radius:12px;padding:12px 14px;margin:0 0 10px;background:#FAF8F3}
.dv-ix .tag{display:inline-block;border-radius:6px;padding:2px 7px;font-size:.74rem;font-weight:700;margin:2px 4px 0 0;background:#F3F0E8}.dv-ix .tag.r{background:#FDEDEA;color:#9A3524}.dv-ix .tag.a{background:#FBF1DC;color:#7A5410}.dv-ix .tag.v{background:#EAF5EE;color:#1F5A3B}
.dv-lote .lt-v{list-style:none;padding:0;margin:8px 0}.dv-lote .lt-v li{padding:8px 0;border-bottom:1px solid #EFEBE2;line-height:1.5}
.dv-lote .lt-in{background:#fff;border:1px solid #E3DED2;border-radius:16px;padding:18px;margin:0 0 16px;font-family:'Hanken Grotesk',system-ui,sans-serif}
.dv-lote textarea{width:100%;min-height:130px;border:1px solid #D9D3C4;border-radius:12px;padding:10px;font:inherit;margin:8px 0}
.dv-lote .lt-b{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.dv-lote .lt-go{background:#C9A45C;border:0;border-radius:999px;padding:12px 22px;font-weight:700;cursor:pointer;color:#241A06;font:inherit;font-weight:700}
.dv-lote .lt-c{color:#6E6A60;font-size:.9rem}.dv-lote .lt-m{color:#9A3524;font-size:.9rem;margin:8px 0 0}
@media(max-width:700px){.dv-ix .n{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media print{.dv-ix .bt,.dv-ix .calc{display:none!important}}
</style>
<script>
/* DV_INT_V1 */
(function(){
var path=location.pathname,PG=/raio-x-lote/.test(path)?'lote':/raio-x-comprador/.test(path)?'comprador':/dossie/.test(path)?'dossie':'concorrente';
var API=(window.DVREL&&DVREL.api)||(window.DV&&DV.api)||'/wp-json/desenvolve/v1/',NONCE=(window.DVREL&&DVREL.nonce)||(window.DV&&DV.nonce)||'';
var lt=document.querySelector('.dv-lote');if(lt){API=lt.dataset.api;NONCE=lt.dataset.nonce}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function pc(v){return v==null?'—':v.toFixed(1).replace('.',',')+'%'}
function br(d){var m=String(d||'').match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''}
function fc(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function get(u){return fetch(API+u,{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function post(u,b){return fetch(API+u,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'},body:JSON.stringify(b)}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function cnpj(){var c=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'');if(c.length===14)return c;var i=document.querySelector('.dv-tool input');c=i?String(i.value).replace(/\D/g,''):'';return c.length===14?c:''}
function root(){return PG==='concorrente'?document.querySelector('.rx-out'):document.querySelector('.rp')}
function pronto(r){return PG==='concorrente'?r.querySelector('.rx-pts'):r.querySelector('.rp-sec')}
function caixa(id,depois){var r=root(),b=document.getElementById(id);if(b)return b;b=document.createElement('section');b.id=id;b.className='dv-ix';var a=depois&&r.querySelector(depois);if(a&&a.parentNode)a.parentNode.insertBefore(b,a.nextSibling);else r.appendChild(b);return b}

/* ---------- Concorrente (Raio-X e Due Diligence) ---------- */
function concorrente(c){var b=caixa('dv-ic','#dv-pv');if(b.dataset.c===c&&b._ok)return;b._ok=1;b.dataset.c=c;b.innerHTML='<p class="k">Experiência, preço e acompanhamento</p><p>Calculando a experiência comprovável e o perfil de preço…</p>';
 get('int/concorrente?cnpj='+c).then(function(J){b.innerHTML=htmlC(J,c);ligaC(b,J,c)}).catch(function(x){b.innerHTML='<p class="k">Experiência e preço</p><p>'+e((x&&x.message)||'Não foi possível calcular agora.')+'</p>'})}
function htmlC(J,c){var h='<p class="k">Experiência, preço e acompanhamento</p><h3>O que ela consegue comprovar e como costuma dar preço</h3>';
 var E=J.experiencia||[];h+='<h4>Experiência comprovável (atestados)</h4>';
 if(E.length){h+='<div class="tw"><table><tr><th>Ramo</th><th>Contratos e vitórias</th><th>Maior contrato</th><th>Maior nº de postos</th><th>Maior duração</th></tr>'+E.map(function(x){return'<tr><td><b>'+e(x.ramo)+'</b></td><td>'+x.n+'<small>último: '+(br(x.ultimo)||'—')+'</small></td><td>'+mc(x.maior_valor)+'<small>'+e(String(x.maior_valor_orgao||'').slice(0,60))+'</small></td><td>'+(x.max_postos||'—')+'</td><td>'+(x.max_meses?x.max_meses+' meses':'—')+'</td></tr>'}).join('')+'</table></div>';
  h+='<div class="calc"><span>O edital exige atestado de</span><input type="number" min="1" class="c-q" placeholder="postos"><span>postos ou</span><input type="number" min="1" class="c-v" placeholder="R$ mil"><span>mil por ano?</span><b class="c-r"></b></div>';
  h+='<p class="m">Estimado a partir dos contratos publicados no PNCP e das licitações vencidas. Os postos são lidos do texto do objeto e podem não aparecer. O edital pode exigir até 50% das parcelas de maior relevância (art. 67, § 2º); peça diligência se o atestado apresentado não bater com este histórico.</p>'}
 else h+='<p>Nenhum contrato ou vitória encontrado no PNCP para estimar a experiência. Exija os atestados com rigor (art. 67).</p>';
 var P=J.perfil||{};h+='<h4>Perfil de preço</h4>';
 if(P.n){var f=P.faixas||{},t=P.n||1,cs=[['ate10','#8FBDA3','até 10%'],['10a25','#C9A45C','10 a 25%'],['25a50','#E59A6A','25 a 50%'],['acima50','#B4472F','acima de 50%']];
  h+='<p>Desconto médio de <b>'+pc(P.media)+'</b> sobre o estimado (mediana '+pc(P.mediana)+') em '+P.n+' licitação(ões) com valor estimado aberto.</p><div class="bar">'+cs.map(function(k){return'<i style="width:'+((f[k[0]]||0)/t*100)+'%;background:'+k[1]+'"></i>'}).join('')+'</div><p class="m">'+cs.map(function(k){return k[2]+': '+(f[k[0]]||0)}).join(' · ')+'</p>';
  var U=Object.keys(P.uf||{});if(U.length>1)h+='<p class="m">Por estado: '+U.map(function(u){return u+' '+pc(P.uf[u].media)+' ('+P.uf[u].n+')'}).join(' · ')+'</p>';
  if((f['acima50']||0)>0)h+='<div class="al">Já venceu com desconto acima de 50% do estimado. Em bens e serviços, isso é indício de inexequibilidade a ser demonstrado (art. 59, § 2º; IN SEGES 73/2022).</div>'}
 else h+='<p>Sem licitações vencidas com valor estimado aberto para calcular o desconto.</p>';
 var hc=(window.DVC&&DVC.habilitacao!=null)?DVC.habilitacao:0;h+='<h4>Habilitação recente</h4><p>Encontre os documentos de habilitação que esta empresa apresentou nas licitações mais recentes (atas, julgamento, propostas e documentos publicados no PNCP) e o link de cada sessão pública.</p><button type="button" class="bt p c-hab">Buscar habilitação recente'+(hc?' · '+hc+' créditos':' · incluso no Raio-X')+'</button><div class="c-habo"></div>';
 h+='<h4>Acompanhar e comparar</h4><p>Receba um e-mail quando esta empresa vencer uma licitação, sofrer sanção, mudar de situação na Receita ou passar a ter dívida ativa em cobrança.</p>';
 h+='<button type="button" class="bt '+(J.monitorado?'on':'p')+' c-mon">'+(J.monitorado?'✓ Monitorando · parar':'Monitorar este concorrente')+'</button>';
 h+='<button type="button" class="bt c-cmp">Comparar com a minha empresa</button>'+(J.meu?'':'<span class="c-meu" style="display:none"> <input class="c-mi" inputmode="numeric" placeholder="CNPJ da sua empresa" style="border:1px solid #D9D3C4;border-radius:8px;padding:7px 9px;font:inherit;width:190px"></span>');
 h+='<a class="bt" href="/plataforma/captador/">Monitorar nos diários oficiais</a><div class="c-out"></div>';
 return h}
function ligaC(b,J,c){var E=J.experiencia||[];var q=b.querySelector('.c-q'),v=b.querySelector('.c-v'),r=b.querySelector('.c-r');
 function calc(){if(!r)return;var mp=Math.max.apply(null,E.map(function(x){return x.max_postos||0}).concat([0])),mv=Math.max.apply(null,E.map(function(x){return x.maior_valor||0}).concat([0])),o=[];
  if(+q.value>0){var nec=Math.ceil(+q.value*.5);o.push(mp?(mp>=nec?'✓ Já teve contrato com '+mp+' postos (o máximo exigível seria '+nec+').':'⚠ Maior contrato encontrado tem '+mp+' postos; o máximo exigível seria '+nec+'. Confira o atestado.'):'Postos não informados nos contratos.')}
  if(+v.value>0){var nv=+v.value*1000*.5;o.push(mv>=nv?'✓ Já teve contrato de '+mc(mv)+'.':'⚠ Maior contrato encontrado: '+mc(mv)+' (metade do exigido seria '+mc(nv)+').')}
  r.textContent=o.join(' ')}
 if(q){q.oninput=calc;v.oninput=calc}
 var hb=b.querySelector('.c-hab');if(hb)hb.onclick=function(){var hc=(window.DVC&&DVC.habilitacao!=null)?DVC.habilitacao:0;if(hc&&!confirm('Buscar a habilitação recente desta empresa? Usa '+hc+' créditos (repetir a busca nos próximos 30 dias não cobra de novo).'))return;var o=b.querySelector('.c-habo');hb.disabled=true;o.innerHTML='<p>Buscando os documentos das licitações mais recentes no PNCP…</p>';
  post('int/habilitacao',{cnpj:c}).then(function(H){hb.disabled=false;var L=H.itens||[];if(!L.length){o.innerHTML='<p>Não encontramos licitações recentes desta empresa no PNCP para buscar a habilitação.</p>';return}
   o.innerHTML=L.map(function(x){return'<div class="sc"><b>'+e(x.orgao||x.ref)+'</b> · '+br(x.data)+(x.venceu?' <span class="tag v">venceu</span>':' <span class="tag">participou</span>')+'<p class="m" style="margin:4px 0!important">'+e(String(x.objeto||'').slice(0,160))+'</p>'+(x.docs.length?'<ul style="margin:4px 0 0 18px!important;padding:0!important">'+x.docs.map(function(d){return'<li style="font-size:.88rem!important"><a href="'+e(d.url)+'" target="_blank" rel="noopener">'+e(d.titulo)+'</a>'+(d.tipo?' <span class="m">('+e(d.tipo)+')</span>':'')+'</li>'}).join('')+'</ul>':'<p class="m" style="margin:4px 0!important">Nenhum documento de habilitação publicado no PNCP para esta licitação.</p>')+'<a class="bt" href="'+e(x.sessao)+'" target="_blank" rel="noopener">Sessão pública e habilitação ↗</a><a class="bt" href="'+e(x.pncp)+'" target="_blank" rel="noopener">PNCP</a></div>'}).join('')+(H.parcial?'<p class="m">O PNCP não respondeu para parte das licitações agora. Tente de novo mais tarde, sem nova cobrança.</p>':'')+'<p class="m">No Compras.gov.br, os documentos de habilitação ficam no item da sessão pública, depois da fase de habilitação.</p>'}).catch(function(x){hb.disabled=false;o.innerHTML='<p>'+e((x&&x.message)||'Não foi possível buscar agora.')+'</p>'})};
 var m=b.querySelector('.c-mon');if(m)m.onclick=function(){m.disabled=true;var on=m.classList.contains('on');post('int/monitor',{cnpj:c,acao:on?'del':'add'}).then(function(){m.disabled=false;m.classList.toggle('on',!on);m.classList.toggle('p',on);m.textContent=on?'Monitorar este concorrente':'✓ Monitorando · parar'}).catch(function(x){m.disabled=false;alert((x&&x.message)||'Não foi possível agora.')})};
 var k=b.querySelector('.c-cmp');if(k)k.onclick=function(){var o=b.querySelector('.c-out'),mw=b.querySelector('.c-meu'),mi=b.querySelector('.c-mi'),mv='';if(mi){mw.style.display='inline';mv=String(mi.value).replace(/\D/g,'');if(mv.length!==14){o.innerHTML='<p>Digite o CNPJ da sua empresa e clique de novo.</p>';mi.focus();return}}o.innerHTML='<p>Comparando…</p>';get('int/concorrente?cnpj='+c+'&comparar=1'+(mv?'&meu='+mv:'')).then(function(X){if(!X.eu){o.innerHTML='<p>Não foi possível comparar.</p>';return}var a=X.eu.resumo,z=X.resumo;
  function an(d){var t=Date.parse(d);return t?Math.floor((Date.now()-t)/31557600000)+' anos':'—'}
  var L=[['Licitações vencidas (3 anos)',a.vitorias,z.vitorias],['Valor homologado',mc(a.valor),mc(z.valor)],['Desconto médio',pc(a.desconto),pc(z.desconto)],['Contratos no PNCP',a.contratos,z.contratos],['Valor dos contratos',mc(a.contratos_valor),mc(z.contratos_valor)],['Capital social',mc(a.capital),mc(z.capital)],['Tempo de atividade',an(a.abertura),an(z.abertura)],['Porte',a.porte||'—',z.porte||'—']];
  o.innerHTML='<div class="tw" style="margin-top:12px"><table><tr><th></th><th>Sua empresa</th><th>'+e(X.nome||'Concorrente')+'</th></tr>'+L.map(function(l){return'<tr><td>'+l[0]+'</td><td><b>'+e(l[1])+'</b></td><td><b>'+e(l[2])+'</b></td></tr>'}).join('')+'</table></div>'}).catch(function(){o.innerHTML='<p>Não foi possível comparar agora.</p>'})}}

/* ---------- Comprador ---------- */
function comprador(c){var r=root(),ref=[].filter.call(r.querySelectorAll('.rp-sec'),function(s){return/^Resumo/i.test((s.querySelector('h3')||{}).textContent||'')})[0];
 var b=document.getElementById('dv-io');if(!b){b=document.createElement('section');b.id='dv-io';b.className='dv-ix';if(ref)ref.parentNode.insertBefore(b,ref.nextSibling);else r.appendChild(b)}
 if(b.dataset.c===c&&b._ok)return;b._ok=1;b.dataset.c=c;var it=(document.querySelectorAll('.dv-tool input')[1]||{}).value||new URLSearchParams(location.search).get('item')||'';
 b.innerHTML='<p class="k">Inteligência do comprador</p><p>Levantando quem ganha aqui, o histórico das licitações e os contratos que vão vencer…</p>';
 get('int/orgao?cnpj='+c+'&item='+encodeURIComponent(it)).then(function(J){b.innerHTML=htmlO(J,c);pagamentos(b,c);concorr(b,J,c,it)}).catch(function(){b.innerHTML='<p class="k">Inteligência do comprador</p><p>Não foi possível consultar o PNCP agora.</p>'})}
function htmlO(J,c){var H=J.historico||{},it=J.item?' de "'+e(J.item)+'"':'';var h='<p class="k">Inteligência do comprador</p><h3>Quem ganha aqui, como o órgão licita e o que vem por aí</h3>';
 h+='<div class="n"><div><b>'+(H.total||0)+'</b><span>pregões eletrônicos em 12 meses</span></div><div><b>'+pc(H.desconto_medio)+'</b><span>desconto médio homologado</span></div><div><b>'+((H.revogadas||0)+(H.anuladas||0))+'</b><span>revogados ou anulados</span></div><div><b>'+(J.n_calendario||0)+'</b><span>contratos'+(J.item?' do seu item':'')+' vencem em 6 meses</span></div></div>';
 var V=J.vencedores||[];h+='<h4>Quem ganha aqui'+it+'</h4>';
 if(V.length){h+='<div class="tw"><table><tr><th>Empresa</th><th>Contratos</th><th>Valor</th><th></th></tr>'+V.map(function(v){return'<tr><td>'+e(v.nome)+'<small>'+fc(v.cnpj)+'</small></td><td>'+v.n+'</td><td>'+mc(v.valor)+'</td><td><a href="/plataforma/raio-x/?cnpj='+v.cnpj+'">Raio-X</a></td></tr>'}).join('')+'</table></div>';if(J.concentracao>=50)h+='<div class="al">'+J.concentracao+'% do valor'+(J.item?' do seu item':'')+' está com um único fornecedor. Vale entender por quê antes de entrar (exigências do edital, prazos de mobilização).</div>'}
 else h+='<p>Nenhum contrato'+it+' encontrado no PNCP nos últimos 3 anos.</p>';
 h+='<h4>Concorrência'+(J.item?' no seu ramo':'')+' neste órgão</h4><div class="cc"><p class="m st">Contando os participantes…</p></div>';
 h+='<h4>Como o órgão licita (12 meses)</h4><p>'+(H.total?H.total+' pregão(ões) eletrônico(s) publicado(s)'+(H.srp?', '+H.srp+' por registro de preços':'')+'. Revogados: <b>'+(H.revogadas||0)+'</b> · anulados: <b>'+(H.anuladas||0)+'</b> · suspensos: <b>'+(H.suspensas||0)+'</b>.':'Nenhum pregão eletrônico publicado no PNCP nos últimos 12 meses.')+'</p>';
 if(H.total&&((H.revogadas||0)+(H.anuladas||0)+(H.suspensas||0))/H.total>=.15)h+='<div class="al">Taxa alta de revogação, anulação ou suspensão. Leia o edital com atenção: há espaço para impugnação e o certame pode não chegar ao fim.</div>';
 if(H.desconto_medio!=null)h+='<p class="m">Desconto médio entre o estimado e o homologado em '+H.n_desconto+' licitação(ões). Use como referência para o seu preço.</p>';
 var C=J.calendario||[];h+='<h4>Contratos'+(J.item?' do seu item':'')+' que vencem nos próximos 6 meses</h4>';
 h+=C.length?'<div class="tw"><table><tr><th>Objeto</th><th>Fornecedor atual</th><th>Valor</th><th>Vence em</th></tr>'+C.map(function(x){return'<tr><td>'+e(String(x.objeto).slice(0,130))+(x.link?' · <a href="'+e(x.link)+'" target="_blank" rel="noopener">PNCP</a>':'')+'</td><td>'+e(x.nome)+'</td><td>'+mc(x.valor)+'</td><td>'+br(x.fim)+'</td></tr>'}).join('')+'</table></div><p class="m">Contratos de serviço contínuo podem ser prorrogados (art. 107); a nova licitação costuma sair alguns meses antes do vencimento.</p>':'<p>Nenhum contrato'+(J.item?' do seu item':'')+' vencendo nos próximos 6 meses.</p>';
 h+='<a class="bt" href="/plataforma/contratos-vencendo/?orgao='+c+'">Ver todos os contratos vencendo</a><a class="bt" href="https://pncp.gov.br/app/pca" target="_blank" rel="noopener">Plano de contratações (PCA) no PNCP</a>';
 h+='<h4>Prazo real de pagamento</h4><div class="pg"><p>Consultando os pagamentos no TCE-SP…</p></div>';
 return h}
function pagamentos(b,c){var o=b.querySelector('.pg');get('int/pagamentos?cnpj='+c).then(function(P){if(!P.disponivel){o.innerHTML='<p class="m">'+e(P.motivo||'Dado indisponível.')+'</p>';return}
 var cor=P.mediana<=15?'v':P.mediana<=30?'a':'r';o.innerHTML='<p>Entre a liquidação (serviço atestado) e o pagamento, '+e(P.municipio)+' levou <span class="tag '+cor+'">'+P.mediana+' dias na mediana</span>. Um quarto dos pagamentos levou mais de '+P.p75+' dias; 10% levaram mais de '+P.p90+' dias. <b>'+P.acima30+'%</b> passaram de 30 dias.</p><p class="m">'+P.pagamentos.toLocaleString('pt-BR')+' pagamentos a empresas em '+P.meses.join(', ')+' (dados do TCE-SP, publicados com atraso de alguns meses). A lei fixa a ordem cronológica de pagamentos (art. 141).</p>'}).catch(function(){o.innerHTML='<p class="m">Não foi possível consultar o TCE-SP agora.</p>'})}


function cnpjOk(c){if(!/^\d{14}$/.test(c)||/^(\d)\1+$/.test(c))return false;for(var n=12;n<=13;n++){var s=0,p=n-7;for(var i=0;i<n;i++){s+=c[i]*p--;if(p<2)p=9}var d=s%11<2?0:11-s%11;if(+c[n]!==d)return false}return true}
function pdfjs(){if(window.pdfjsLib)return Promise.resolve(window.pdfjsLib);return new Promise(function(ok,no){var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';sc.onload=function(){pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';ok(pdfjsLib)};sc.onerror=no;document.head.appendChild(sc)})}
function lePdf(buf){return pdfjs().then(function(L){return L.getDocument({data:buf}).promise}).then(function(pdf){var ps=[];for(var i=1;i<=Math.min(pdf.numPages,40);i++)ps.push(pdf.getPage(i).then(function(pg){return pg.getTextContent()}).then(function(tc){var y=null,out='';tc.items.forEach(function(it){var yy=Math.round(it.transform[5]);out+=(y!==null&&yy!==y?'\n':'  ')+it.str;y=yy});return out}));return Promise.all(ps).then(function(a){return a.join('\n')})})}
function extraiP(t,org){var o={},re=/(\d{2}\.?\d{3}\.?\d{3}\s?\/?\s?\d{4}\s?-?\s?\d{2})/g,m;while(m=re.exec(t)){var c=m[1].replace(/\D/g,'');if(c===org||o[c]||!cnpjOk(c))continue;var ln=t.slice(Math.max(0,t.lastIndexOf('\n',m.index)+1),m.index).replace(/[,;\s]*(CPF\/CNPJ|CNPJ\/MF|CNPJ|inscrita no|sob o n[ºo°]?)\s*[:n°º.]*\s*$/i,'').replace(/^.*?(Empresa|Fornecedor|Licitante|Raz[aã]o Social)\s*:\s*/i,'');var sg=ln.split(/\s{2,}|\t|\|/).filter(function(x){return/[A-Za-zÀ-ú]{3}/.test(x)});o[c]={c:c,n:(sg.pop()||'').trim().slice(0,120)}}return Object.keys(o).map(function(k){return o[k]})}
function lerAta(p,org,orgao){return get('lic/pncp?ref='+encodeURIComponent(p.ref)).then(function(L){var A=(L.arquivos||[]).filter(function(a){var t=(a.titulo+' '+(a.tipo||''));return/ata|julgamento|resultado|homologa|adjudica|classifica|sess[aã]o|propostas|relat[oó]rio/i.test(t)&&!/edital|termo de refer|minuta|estudo t[eé]cnico|ETP|projeto|impugna|amostra|registro de pre/i.test(t)}).slice(0,2);var P={},q=Promise.resolve();
 A.forEach(function(a){q=q.then(function(){if(Object.keys(P).length>=2)return;return fetch(API+'lic/arquivo?u='+encodeURIComponent(a.url),{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}}).then(function(r){if(!r.ok)throw 0;return r.arrayBuffer()}).then(function(buf){var u=new Uint8Array(buf);if(u[0]!==37||u[1]!==80)return;return lePdf(buf).then(function(t){extraiP(t,org).forEach(function(x){P[x.c]=x})})}).catch(function(){})})});
 return q.then(function(){var Ls=Object.keys(P).map(function(k){return P[k]});if(Ls.length<2)return;return post('participantes',{ref:p.ref,fonte:'ata',item:'',estimado:0,objeto:p.objeto,orgao:orgao,linhas:Ls})})})}
function concorr(b,J,c,it){var o=b.querySelector('.cc');if(!o)return;var PI=J.pregoes_item||[],org=c;
 function render(B){B=B||{};var por=B.por||{},h='';
  var ds=PI.map(function(p){return p.desconto}).filter(function(x){return x!=null});var dm=ds.length?ds.reduce(function(a,x){return a+x},0)/ds.length:null;
  if(B.licitacoes)h+='<p>Nos <b>'+B.licitacoes+'</b> pregão(ões)'+(J.item?' do seu ramo':'')+' com participantes conhecidos: <b>'+String(B.media).replace('.',',')+' empresas por disputa</b> em média (de '+B.min+' a '+B.max+'), '+B.empresas+' empresas diferentes no total.'+(dm!=null?' Desconto médio homologado: <b>'+pc(dm)+'</b>.':'')+'</p>';
  else h+='<p>'+(PI.length?'Ainda não conseguimos ler os participantes destes pregões no PNCP (a ata pode não ter sido publicada).':'Nenhum pregão'+(J.item?' do seu ramo':'')+' com resultado neste órgão nos últimos 12 meses.')+(dm!=null?' Desconto médio homologado: <b>'+pc(dm)+'</b>.':'')+'</p>';
  if(PI.length)h+='<div class="tw"><table><tr><th>Pregão</th><th>Data</th><th>Participantes</th><th>Desconto</th></tr>'+PI.map(function(p){return'<tr><td>'+e(p.numero)+'<small>'+e(String(p.objeto).slice(0,110))+' · <a href="https://pncp.gov.br/app/editais/'+e(p.ref)+'" target="_blank" rel="noopener">PNCP</a></small></td><td>'+br(p.data)+'</td><td>'+(por[p.ref]||'—')+'</td><td>'+pc(p.desconto)+'</td></tr>'}).join('')+'</table></div>';
  if((B.frequentes||[]).length)h+='<p style="margin-top:10px!important"><b>Quem mais disputa aqui:</b></p><p>'+B.frequentes.map(function(f){return'<a class="bt" href="/plataforma/raio-x/?cnpj='+f.cnpj+'">'+e(f.nome||fc(f.cnpj))+' · '+f.n+(f.n>1?' disputas':' disputa')+(+f.v?' · '+f.v+'× 1º':'')+'</a>'}).join('')+'</p>';
  h+='<p class="m st"></p><p class="m">Participantes lidos das atas publicadas no PNCP e das sessões consultadas na plataforma. Desconto entre o valor estimado e o homologado de cada pregão.</p>';o.innerHTML=h}
 render(J.base);var tem={};((J.base||{}).refs||[]).forEach(function(r){tem[r]=1});var falta=PI.filter(function(p){return!tem[p.ref]}).slice(0,5);if(!falta.length)return;
 var st=o.querySelector('.st');if(st)st.textContent='Lendo as atas de '+falta.length+' pregão(ões) no PNCP para contar os participantes…';var q=Promise.resolve();
 falta.forEach(function(p,i){q=q.then(function(){var s2=o.querySelector('.st');if(s2)s2.textContent='Lendo a ata '+(i+1)+' de '+falta.length+'…';return lerAta(p,org,(document.querySelector('.rp-hd h2')||{}).textContent||'').catch(function(){})})});
 q.then(function(){return get('int/orgao?cnpj='+c+'&item='+encodeURIComponent(it||''))}).then(function(J2){render(J2.base)}).catch(function(){var s3=o.querySelector('.st');if(s3)s3.textContent=''})}
/* ---------- Due Diligence: sócios e linha do tempo ---------- */
function dossie(c){var b=caixa('dv-id','#dv-ic');if(b.dataset.c===c&&b._ok)return;b._ok=1;b.dataset.c=c;b.innerHTML='<p class="k">Sócios e linha do tempo</p><p>Montando…</p>';
 get('int/dossie?cnpj='+c).then(function(J){b.innerHTML=htmlD(J)}).catch(function(x){b.innerHTML='<p class="k">Sócios e linha do tempo</p><p>'+e((x&&x.message)||'Indisponível agora.')+'</p>'})}
function htmlD(J){var h='<p class="k">Sócios e linha do tempo</p><h3>Quem está por trás e como a empresa chegou até aqui</h3>';(J.alertas||[]).forEach(function(a){h+='<div class="al">'+e(a)+'</div>'});
 var C=J.compliance||{},F=J.fachada||{},PT=J.processos_tipo||[];
 h+='<h4>Trabalho escravo e meio ambiente</h4>'+((C.escravo||[]).length?(C.escravo||[]).map(function(x){return'<div class="sc"><span class="tag r">Lista suja do trabalho escravo</span> <b>'+e(x.nome)+'</b> ('+fc(x.cnpj)+') · '+e(x.quem)+(x.trabalhadores?' · '+e(x.trabalhadores)+' trabalhador(es)':'')+(x.inclusao?' · incluída em '+e(x.inclusao):'')+'</div>'}).join(''):'<p><span class="tag v">Fora da lista suja do trabalho escravo</span> (empresa e grupo econômico)</p>')+((C.embargos||[]).length?(C.embargos||[]).map(function(x){return'<div class="sc"><span class="tag a">Embargo ambiental (Ibama)</span> '+e(x.data||'')+' · '+e((x.municipio||'')+(x.uf?'/'+x.uf:''))+(x.area?' · '+e(x.area)+' ha':'')+' · '+e(x.quem)+(x.situacao?' · '+e(x.situacao):'')+(x.motivo?'<br><small>'+e(x.motivo)+'</small>':'')+'</div>'}).join(''):'<p><span class="tag v">Nenhum embargo ambiental do Ibama</span> (empresa e grupo econômico)</p>')+'<p class="m">Cadastro de Empregadores do Ministério do Trabalho e termos de embargo do Ibama'+(C.atualizado?', atualizados em '+br(C.atualizado):'')+'.</p>';
 h+='<h4>Sinais de empresa de fachada</h4>'+((F.sinais||[]).length?'<p><span class="tag '+(F.nivel==='alto'?'r':'a')+'">'+(F.nivel==='alto'?'Vários sinais':'Algum sinal')+'</span></p><ul>'+F.sinais.map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ul>':'<p><span class="tag v">Nenhum sinal encontrado</span></p>')+'<p class="m">Indícios a conferir, não uma conclusão: capital, tempo de abertura, endereço e telefone compartilhados, sócios e porte.</p>';
 if(PT.length)h+='<h4>Processos por tipo</h4><p>'+PT.map(function(x){return'<span class="tag '+(/execucao|falencia|criminal|improbidade/.test(x.tipo)?'r':'a')+'">'+e(x.nome)+': '+x.n+'</span>'}).join(' ')+'</p>';
 h+='<h4>Sócios a fundo</h4>'+((J.socios||[]).length?(J.socios||[]).map(function(s){var t=[];if(s.criminais)t.push('<span class="tag r">'+s.criminais+' processo(s) criminal(is)</span>');if(s.pep)t.push('<span class="tag r">Pessoa politicamente exposta</span>');if(s.divida_grupo>0)t.push('<span class="tag a">Empresas com dívida ativa: '+mc(s.divida_grupo)+'</span>');if(s.parentes)t.push('<span class="tag a">'+s.parentes+' possível(is) parente(s) em outras empresas</span>');if(!t.length)t.push('<span class="tag v">Nada encontrado</span>');
  return'<div class="sc"><b>'+e(s.nome)+'</b> · '+e(s.qualificacao)+(s.entrada?' · desde '+br(s.entrada):'')+(s.faixa?' · '+e(s.faixa):'')+'<br>'+t.join('')+(s.n_empresas?'<p class="m" style="margin:6px 0 0!important">Outras empresas ('+s.n_empresas+'): '+s.empresas.map(function(x){return'<a href="/plataforma/raio-x/?cnpj='+x.cnpj+'">'+e(x.razao)+'</a>'+(x.pgfn>0?' ('+mc(x.pgfn)+' em dívida)':'')}).join(' · ')+'</p>':'')+'</div>'}).join(''):'<p>Quadro de sócios não informado pela Receita.</p>');
 h+='<h4>Linha do tempo</h4><ul class="tl">'+(J.timeline||[]).map(function(t){return'<li class="'+e(t.tipo)+'"><b>'+br(t.data)+'</b> '+e(t.texto)+(t.link?' · <a href="'+e(t.link)+'" target="_blank" rel="noopener">abrir</a>':'')+'</li>'}).join('')+'</ul><p class="m">Cadastro da Receita, contratos no PNCP, processos (DJEN/CNJ), notícias e sanções. Processos trabalhistas comuns aparecem somados por ano.</p>';
 return h}

/* ---------- Raio-X em lote ---------- */
if(lt){(function(){var q=(new URLSearchParams(location.search).get('cnpjs')||'').split(',').filter(function(x){return x.replace(/\D/g,'').length===14});var t=lt.querySelector('textarea');if(q.length&&t&&!t.value.trim())t.value=q.join('\n')})();var T=lt.querySelector('textarea'),go=lt.querySelector('.lt-go'),cu=+lt.dataset.custo||0,cc=lt.querySelector('.lt-c'),mm=lt.querySelector('.lt-m'),out=lt.querySelector('.lt-out');
 function lista(){var s={},o=[];(T.value.match(/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/g)||[]).forEach(function(x){x=x.replace(/\D/g,'');if(!s[x]){s[x]=1;o.push(x)}});return o.slice(0,20)}
 T.oninput=function(){var n=lista().length;cc.textContent=n?n+' CNPJ(s)'+(cu?' · '+cu+' créditos pelo lote todo':''):''};
 go.onclick=function(){var L=lista();mm.textContent='';if(!L.length){mm.textContent='Cole pelo menos um CNPJ.';return}if(cu&&!confirm('Gerar o Raio-X de '+L.length+' empresa(s)? Usa '+cu+' créditos pelo lote todo (até 20 CNPJs).'))return;
  go.disabled=true;var R=[];out.innerHTML='<section class="dv-ix"><p>Gerando 0 de '+L.length+'…</p></section>';var lid='';var q=post('int/lote/abrir',{cnpjs:L}).then(function(x){lid=x.lote});
  L.forEach(function(c,i){q=q.then(function(){out.querySelector('p').textContent='Gerando '+(i+1)+' de '+L.length+'…';return post('int/lote',{cnpj:c,lote:lid}).then(function(x){R.push(x)}).catch(function(x){R.push({cnpj:c,erro:(x&&x.message)||'Falhou'});if(x&&(x.code==='dv_sem_credito'||x.code==='dv_lote'))throw x})})});
  q.catch(function(x){mm.textContent=(x&&x.message)||''}).then(function(){go.disabled=false;out.innerHTML=R.length?tabela(R):''})};
 function tabela(R){var h='<section class="dv-ix"><p class="k">Raio-X em lote</p><h3>Comparativo dos participantes</h3><div class="tw"><table><tr><th>Empresa</th><th>Receita</th><th>Sanções</th><th>Dívida ativa</th><th>Vitórias (3 anos)</th><th>Desconto médio</th><th>Disputas na base</th><th></th></tr>';
  R.forEach(function(x){if(x.erro){h+='<tr><td>'+fc(x.cnpj)+'</td><td colspan="7">'+e(x.erro)+'</td></tr>';return}var at=Date.parse(x.abertura),an=at?Math.floor((Date.now()-at)/31557600000):null;
   h+='<tr><td><b>'+e(x.nome)+'</b><small>'+fc(x.cnpj)+' · '+e(x.municipio)+'</small></td><td>'+(x.situacao==='ATIVA'?'<span class="tag v">ATIVA</span>':'<span class="tag r">'+e(x.situacao||'?')+'</span>')+'<small>'+(an!=null?an+' anos · ':'')+'capital '+mc(x.capital)+'</small></td><td>'+(x.sancoes?'<span class="tag r">'+x.sancoes+'</span>':'<span class="tag v">nada</span>')+'</td><td>'+(x.pgfn>0?'<span class="tag '+(x.cobranca>0?'r':'a')+'">'+mc(x.pgfn)+'</span><small>'+(x.cobranca>0?'em cobrança':'parcelada ou suspensa')+(x.fgts>0?' · FGTS':'')+'</small>':'<span class="tag v">nada</span>')+'</td><td>'+x.vitorias+'<small>'+mc(x.vitorias_valor)+'</small></td><td>'+pc(x.desconto)+'</td><td>'+(x.disputas?x.disputas+(x.primeiro?' · '+x.primeiro+'× 1º':''):'—')+'</td><td><a href="/plataforma/raio-x/?cnpj='+x.cnpj+'">Raio-X completo</a></td></tr>'});
  var V={},N={};R.forEach(function(x){if(x.erro||!x.vk)return;N[x.cnpj]=x.nome;Object.keys(x.vk).forEach(function(k){(V[k]=V[k]||{t:x.vk[k],l:[]}).l.push(x.cnpj)})});var vl=Object.keys(V).filter(function(k){return V[k].l.length>1});
  h+='</table></div><h3 style="margin-top:22px">Vínculos entre os participantes</h3>'+(vl.length?'<ul class="lt-v">'+vl.map(function(k){return'<li><span class="tag r">'+e(V[k].t)+'</span> '+V[k].l.map(function(c){return'<b>'+e(N[c]||fc(c))+'</b>'}).join(' e ')+'</li>'}).join('')+'</ul><p class="m">Sócio, telefone, e-mail ou endereço em comum entre licitantes da mesma disputa pode indicar combinação de propostas e merece apuração (Lei 14.133, art. 155, IX e XI; Código Penal, art. 337-F).</p>':'<p>Nenhum sócio, telefone, e-mail ou endereço em comum entre os participantes.</p>');
  return h+'<p class="m">Dados da Receita, CEIS/CNEP/TCU, PGFN (base trimestral) e PNCP. O lote cobre o comparativo. O Raio-X completo de cada empresa, com habilitação e relatório, é cobrado à parte e fica salvo por 30 dias. Não constitui parecer jurídico.</p></section>'}
 return}

/* ---------- gatilho ---------- */
var tm=null;function tenta(){var r=root();if(!r||!pronto(r))return;var c=cnpj();if(!c)return;
 if(PG==='comprador'){comprador(c);return}
 if(!document.getElementById('dv-pv'))return;concorrente(c);if(PG==='dossie')dossie(c)}
new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(tenta,1500)}).observe(document.body,{childList:true,subtree:true});var nv=0,iv=setInterval(function(){if(++nv>60)clearInterval(iv);tenta()},3000);
})();
</script>
	<?php
}, 96 );

/* Comparador: endereço antigo (/comparador-de-licitantes/) leva à página da plataforma, já com os CNPJs */
add_action( 'template_redirect', function () {
	$p = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	if ( ! preg_match( '#^/(comparador-de-licitantes|plataforma/comparador)/?$#', $p ) ) { return; }
	$c = preg_replace( '/[^0-9,]/', '', (string) ( $_GET['cnpjs'] ?? $_GET['cnpj'] ?? '' ) );
	wp_safe_redirect( home_url( '/plataforma/raio-x-lote/' . ( $c ? '?cnpjs=' . $c : '' ) ), 302 ); exit;
}, 1 );
add_action( 'wp_footer', function () {
	if ( ! is_page( 'comparador' ) ) { return; }
	echo '<script>(function(){var q=new URLSearchParams(location.search),c=(q.get("cnpjs")||"").split(",").map(function(x){return x.replace(/\D/g,"")}).filter(function(x){return x.length===14});if(!c.length)return;var t=document.getElementById("cp-in");if(!t||t.value.trim())return;t.value=c.map(function(x){return x.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,"$1.$2.$3/$4-$5")}).join("\n");t.dispatchEvent(new Event("input",{bubbles:true}));var b=document.getElementById("cp-go");if(b){b.scrollIntoView({block:"center"});b.focus()}})();</script>';
}, 99 );

/* Sessão pública e documentos de habilitação: leva ao sistema onde a licitação aconteceu (Compras.gov.br ou o portal de origem) */
function dv_int_sessao_url( $ref ) {
	if ( ! preg_match( '#^(\d{14})/(\d{4})/(\d+)$#', $ref, $m ) ) { return ''; }
	$ck = 'dv_ses_' . md5( $ref ); $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$g = wp_remote_get( 'https://pncp.gov.br/api/consulta/v1/orgaos/' . $m[1] . '/compras/' . $m[2] . '/' . (int) $m[3], array( 'timeout' => 20 ) );
	$j = is_wp_error( $g ) ? null : json_decode( wp_remote_retrieve_body( $g ), true ); $u = (array) ( $j['unidadeOrgao'] ?? array() ); $url = '';
	if ( $j && stripos( (string) ( $j['usuarioNome'] ?? '' ), 'compras.gov' ) !== false && (int) ( $j['modalidadeId'] ?? 0 ) === 6 && ! empty( $u['codigoUnidade'] ) ) {
		$url = 'https://cnetmobile.estaleiro.serpro.gov.br/comprasnet-web/public/compras/acompanhamento-compra?compra=' . sprintf( '%06d05%05d%04d', (int) $u['codigoUnidade'], (int) preg_replace( '/\D/', '', (string) $j['numeroCompra'] ), (int) $j['anoCompra'] );
	} elseif ( $j && ! empty( $j['linkSistemaOrigem'] ) && preg_match( '#^https?://#', $j['linkSistemaOrigem'] ) ) { $url = esc_url_raw( $j['linkSistemaOrigem'] ); }
	if ( ! $url ) { $url = 'https://pncp.gov.br/app/editais/' . $ref; }
	set_transient( $ck, $url, $j ? 7 * DAY_IN_SECONDS : HOUR_IN_SECONDS ); return $url;
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/int/sessao', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $r ) {
		$ref = preg_replace( '/[^0-9\/]/', '', (string) $r['ref'] ); $url = dv_int_sessao_url( $ref );
		if ( ! $url ) { return new WP_Error( 'dv_ref', 'Licitação inválida.', array( 'status' => 400 ) ); }
		$x = new WP_REST_Response( null, 302 ); $x->header( 'Location', $url ); return $x;
	} ) );
} );
/* Link "Sessão e habilitação" ao lado de cada link do PNCP nas listas de licitações */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( array( 'raio-x', 'raio-x-comprador', 'dossie', 'alertas', 'raio-x-lote' ) ) ) { return; }
	$api = esc_url( rest_url( 'desenvolve/v1/int/sessao' ) );
	echo '<script>/* DV_SESSAO */(function(){var API=' . wp_json_encode( $api ) . ',TT="Abre a sessão pública no sistema onde a licitação aconteceu. No Compras.gov.br, depois da fase de habilitação, os documentos de habilitação e as propostas de cada licitante ficam no item da sessão.";'
		. 'function liga(){[].forEach.call(document.querySelectorAll(\'a[href*="pncp.gov.br/app/editais/"]:not([data-ses]), .al-i:not([data-ses])\'),function(a){var ref="",alvo=a;if(a.classList.contains("al-i")){a.setAttribute("data-ses","1");ref=a.dataset.r||"";alvo=a.querySelector(\'.al-ba a[href*="pncp.gov.br"]\');if(!alvo)return}else{a.setAttribute("data-ses","1");if(a.closest(".al-i"))return;var m=a.href.match(/editais\/(\d{14})\/(\d{4})\/(\d+)/);if(!m)return;ref=m[1]+"/"+m[2]+"/"+(+m[3])}if(!/^\d{14}\/\d{4}\/\d+$/.test(ref))return;var l=document.createElement("a");l.href=API+"?ref="+encodeURIComponent(ref);l.target="_blank";l.rel="noopener";l.title=TT;l.textContent=alvo.closest(".al-ba")?"Sessão e habilitação":"sessão e habilitação";l.setAttribute("data-ses","1");if(alvo.closest(".al-ba")){alvo.parentNode.insertBefore(l,alvo.nextSibling)}else{alvo.parentNode.insertBefore(document.createTextNode(" · "),alvo.nextSibling);alvo.parentNode.insertBefore(l,alvo.nextSibling.nextSibling)}})}'
		. 'var t=null;new MutationObserver(function(){clearTimeout(t);t=setTimeout(liga,400)}).observe(document.body,{childList:true,subtree:true});liga()})();</script>';
}, 100 );

/* ---------------- Habilitação recente do concorrente (incluída no Raio-X, sem cobrança à parte) ---------------- */
add_action( 'init', function () {
	if ( get_option( 'dv_custos_hab' ) === '1' ) { return; }
	$c = (array) get_option( 'dv_cfg', array() ); $s = (string) ( $c['custos'] ?? '' );
	if ( strpos( $s, 'habilitacao:' ) === false ) { $c['custos'] = trim( $s . ',habilitacao:0', ',' ); update_option( 'dv_cfg', $c, false ); }
	update_option( 'dv_custos_hab', '1', false );
}, 7 );
function dv_int_habilitacao( $cnpj ) {
	$ck = 'dv_hab_' . $cnpj; $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$refs = array();
	if ( function_exists( 'dv_part_vencidas' ) ) { foreach ( (array) ( dv_part_vencidas( $cnpj )['itens'] ?? array() ) as $i ) { $refs[ $i['ref'] ] = array( 'orgao' => $i['orgao'], 'objeto' => $i['objeto'], 'data' => $i['data'], 'venceu' => true ); } }
	if ( function_exists( 'dv_part_de' ) ) { foreach ( (array) ( dv_part_de( $cnpj )['itens'] ?? array() ) as $i ) { if ( ! isset( $refs[ $i['ref'] ] ) ) { $refs[ $i['ref'] ] = array( 'orgao' => $i['orgao'], 'objeto' => $i['objeto'], 'data' => $i['data'], 'venceu' => false ); } } }
	uasort( $refs, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } ); $refs = array_slice( $refs, 0, 8, true );
	$u = array(); foreach ( $refs as $ref => $x ) { list( $oc, $an, $sq ) = explode( '/', $ref ); $u[ $ref ] = "https://pncp.gov.br/api/pncp/v1/orgaos/$oc/compras/$an/$sq/arquivos"; }
	$res = $u ? dv_int_get( $u, 25 ) : array(); $o = array(); $falhas = 0;
	$OK = '/habilita|documenta|atestad|balan|certid|propost|contrato social|qualifica|julgamento|ata |ata$|^ata|resultado|adjudica|homologa/i';
	foreach ( $refs as $ref => $x ) {
		$b = $res[ $ref ] ?? null; $L = $b ? json_decode( $b, true ) : null; if ( ! is_array( $L ) ) { $falhas++; $L = array(); }
		$docs = array();
		foreach ( $L as $a ) {
			$t = trim( ( $a['titulo'] ?? '' ) . ' ' . ( $a['tipoDocumentoNome'] ?? '' ) ); if ( empty( $a['url'] ) || ! ( $a['statusAtivo'] ?? true ) ) { continue; }
			if ( ! preg_match( $OK, $t ) || preg_match( '/edital|termo de refer|minuta|estudo t|etp|aviso|errata|esclarec|impugna/i', (string) ( $a['titulo'] ?? '' ) ) ) { continue; }
			$docs[] = array( 'titulo' => mb_substr( (string) ( $a['titulo'] ?? 'Documento' ), 0, 140 ), 'tipo' => $a['tipoDocumentoNome'] ?? '', 'url' => esc_url_raw( $a['url'] ), 'data' => substr( (string) ( $a['dataPublicacaoPncp'] ?? '' ), 0, 10 ) );
		}
		$o[] = $x + array( 'ref' => $ref, 'sessao' => rest_url( 'desenvolve/v1/int/sessao' ) . '?ref=' . rawurlencode( $ref ), 'pncp' => 'https://pncp.gov.br/app/editais/' . $ref, 'docs' => array_slice( $docs, 0, 10 ) );
	}
	$c = array( 'itens' => $o, 'parcial' => $falhas > 0 );
	set_transient( $ck, $c, ( $falhas && $falhas * 2 >= count( $refs ) ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
	return $c;
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/int/habilitacao', array( 'methods' => 'POST', 'permission_callback' => function () { return is_user_logged_in(); }, 'callback' => function ( WP_REST_Request $r ) {
		$c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); if ( strlen( $c ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$uid = get_current_user_id(); @set_time_limit( 120 ); $n = function_exists( 'dv_custo' ) ? dv_custo( 'habilitacao' ) : 0;
		if ( $n > 0 && function_exists( 'dv_usar' ) ) {
			if ( ! dv_ilimitado( $uid ) && dv_saldo( $uid )['total'] < $n ) { return new WP_Error( 'dv_sem_credito', 'A busca de habilitação usa ' . $n . ' créditos e o seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			$fc = preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $c ); $k = 'hab|' . $c;
			$u = dv_usar( $uid, 'habilitacao', $k, $fc ); if ( is_wp_error( $u ) ) { return $u; }
			if ( ! empty( $u['cobrado'] ) ) { for ( $i = 2; $i <= $n; $i++ ) { $x = dv_usar( $uid, 'habilitacao', $k . '|' . $i, '' ); if ( is_wp_error( $x ) ) { return $x; } } }
		}
		return dv_int_habilitacao( $c );
	} ) );
} );
