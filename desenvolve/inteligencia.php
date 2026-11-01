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
	if ( function_exists( 'dv_is_master' ) && dv_is_master( $uid ) ) { return true; }
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
	$tk = dv_int_toks( $item ); $ck = 'dv_io3_' . $cnpj . '_' . md5( implode( ',', $tk ) ); $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
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
	$F = array(); foreach ( $ct as $x ) { if ( strlen( $x['ni'] ) !== 14 || $x['valor'] < 5000 || ! dv_int_bate( $x['objeto'], $tk ) ) { continue; } $k = $x['ni']; if ( ! isset( $F[ $k ] ) ) { $F[ $k ] = array( 'cnpj' => $k, 'nome' => $x['nome'], 'n' => 0, 'valor' => 0 ); } $F[ $k ]['n']++; $F[ $k ]['valor'] += $x['valor']; }
	usort( $F, function ( $a, $b ) { return $b['valor'] <=> $a['valor']; } );
	$totF = array_sum( array_column( $F, 'valor' ) );
	/* calendário: contratos que vencem nos próximos 6 meses */
	$cal = array(); $lim = gmdate( 'Y-m-d', $hoje + 183 * DAY_IN_SECONDS ); $hj = gmdate( 'Y-m-d', $hoje );
	foreach ( $ct as $x ) { if ( $x['fim'] >= $hj && $x['fim'] <= $lim && dv_int_bate( $x['objeto'], $tk ) && $x['valor'] > 0 ) { $cal[] = $x; } }
	usort( $cal, function ( $a, $b ) { return strcmp( $a['fim'], $b['fim'] ); } );
	/* histórico das licitações (12 meses, pregão eletrônico) */
	$ini = gmdate( 'Ymd', $hoje - 365 * DAY_IN_SECONDS ); $fim = gmdate( 'Ymd', $hoje ); $u = array();
	for ( $pg = 1; $pg <= 6; $pg++ ) { $u[ $pg ] = 'https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao?dataInicial=' . $ini . '&dataFinal=' . $fim . '&codigoModalidadeContratacao=6&cnpj=' . $cnpj . '&tamanhoPagina=50&pagina=' . $pg; }
	$cp = array(); foreach ( dv_int_get( $u, 35 ) as $b ) { $j = $b ? json_decode( $b, true ) : null; foreach ( (array) ( $j['data'] ?? array() ) as $x ) { $cp[ $x['numeroControlePNCP'] ?? count( $cp ) ] = $x; } }
	$todos = array();
	$H = array( 'total' => 0, 'revogadas' => 0, 'anuladas' => 0, 'suspensas' => 0, 'sem_resultado' => 0, 'do_item' => 0, 'descontos' => array(), 'srp' => 0 );
	foreach ( $cp as $x ) {
		$H['total']++; $s = strtolower( remove_accents( (string) ( $x['situacaoCompraNome'] ?? '' ) ) );
		if ( strpos( $s, 'revog' ) !== false ) { $H['revogadas']++; } elseif ( strpos( $s, 'anul' ) !== false ) { $H['anuladas']++; } elseif ( strpos( $s, 'suspen' ) !== false ) { $H['suspensas']++; }
		$enc = substr( (string) ( $x['dataEncerramentoProposta'] ?? '' ), 0, 10 );
		if ( empty( $x['existeResultado'] ) && $enc && $enc < gmdate( 'Y-m-d', $hoje - 45 * DAY_IN_SECONDS ) && strpos( $s, 'divulgada' ) !== false ) { $H['sem_resultado']++; }
		if ( ! empty( $x['srp'] ) ) { $H['srp']++; }
		if ( dv_int_bate( $x['objetoCompra'] ?? '', $tk ) ) { $H['do_item']++; }
		$e = (float) ( $x['valorTotalEstimado'] ?? 0 ); $h = (float) ( $x['valorTotalHomologado'] ?? 0 ); $sig = (int) ( $x['orcamentoSigilosoCodigo'] ?? 1 );
		if ( $e > 0 && $h > 0 && $sig <= 1 ) { $d = ( 1 - $h / $e ) * 100; if ( $d > -30 && $d < 95 ) { $todos[] = $d; if ( $tk && dv_int_bate( $x['objetoCompra'] ?? '', $tk ) ) { $H['descontos'][] = $d; } } }
	}
	if ( ! $tk || ! $H['descontos'] ) { $H['descontos'] = $todos; $H['desconto_do_item'] = false; } else { $H['desconto_do_item'] = true; }
	$H['desconto_medio'] = $H['descontos'] ? round( array_sum( $H['descontos'] ) / count( $H['descontos'] ), 1 ) : null; $H['n_desconto'] = count( $H['descontos'] ); unset( $H['descontos'] );
	$c = array( 'item' => $item, 'tokens' => $tk, 'contratos' => count( $ct ), 'vencedores' => array_slice( $F, 0, 8 ), 'concentracao' => ( $F && $totF > 0 ) ? round( $F[0]['valor'] / $totF * 100 ) : null,
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

/* ---------------- Due Diligence: sócios e linha do tempo ---------------- */
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
	return array( 'socios' => $S, 'timeline' => array_slice( $T, 0, 80 ), 'alertas' => $al );
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
			delete_transient( 'dv_venc4_' . $c ); $n = dv_int_snap( $c ); $o = $snaps[ $c ] ?? null; $snaps[ $c ] = $n; if ( ! $o ) { continue; }
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
		$o = dv_int_concorrente( $c ); $meu = preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'dv_doc', true ) );
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
		return dv_int_orgao( $c, sanitize_text_field( (string) $r['item'] ) );
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
	/* Raio-X em lote: um CNPJ por chamada (cobra como o Raio-X do concorrente) */
	register_rest_route( 'desenvolve/v1', '/int/lote', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) use ( $cn ) {
		$c = $cn( $r ); if ( ! $c ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$uid = get_current_user_id(); @set_time_limit( 150 );
		if ( function_exists( 'dv_usar' ) ) {
			$fc = preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $c ); $k = 'rx-cnpj=' . $fc; $n = function_exists( 'dv_custo' ) ? dv_custo( 'raiox' ) : 5;
			if ( $n > 0 ) {
				if ( ! dv_is_master( $uid ) && dv_saldo( $uid )['total'] < $n ) { return new WP_Error( 'dv_sem_credito', 'Saldo insuficiente para continuar o lote.', array( 'status' => 402 ) ); }
				$u = dv_usar( $uid, 'raiox', $k, $fc . ' (lote)' ); if ( is_wp_error( $u ) ) { return $u; }
				if ( ! empty( $u['cobrado'] ) ) { for ( $i = 2; $i <= $n; $i++ ) { $x = dv_usar( $uid, 'raiox', $k . '|' . $i, '' ); if ( is_wp_error( $x ) ) { return $x; } } }
			}
		}
		$cad = dv_int_cad( $c ); if ( ! $cad ) { return new WP_Error( 'dv_cad', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
		$cd = $cad['cadastro']; $s = (array) ( $cad['sancoes'] ?? array() ); $p = function_exists( 'dv_ds_pgfn' ) ? dv_ds_pgfn( $c ) : null;
		$V = function_exists( 'dv_part_vencidas' ) ? dv_part_vencidas( $c ) : array();
		$fed = function_exists( 'dv_ds_interno' ) ? dv_ds_interno( '/dvtools/v1/fornecedor/federal', array( 'cnpj' => $c ) ) : null;
		$P = function_exists( 'dv_part_de' ) ? dv_part_de( $c ) : array();
		return array( 'cnpj' => $c, 'nome' => $cd['razao_social'] ?? '', 'situacao' => $cd['situacao'] ?? '', 'abertura' => $cd['abertura'] ?? '', 'porte' => $cd['porte'] ?? '', 'capital' => (float) ( $cd['capital_social'] ?? 0 ), 'municipio' => ( $cd['municipio'] ?? '' ) . '/' . ( $cd['uf'] ?? '' ),
			'sancoes' => count( (array) ( $s['ceis'] ?? array() ) ) + count( (array) ( $s['cnep'] ?? array() ) ) + count( (array) ( $s['tcu'] ?? array() ) ),
			'pgfn' => $p ? (float) $p['total'] : 0, 'cobranca' => $p ? (float) $p['em_cobranca'] : 0, 'fgts' => $p ? (float) $p['fgts'] : 0,
			'vitorias' => (int) ( $V['total'] ?? 0 ), 'vitorias_valor' => (float) ( $V['valor'] ?? 0 ), 'desconto' => $V['desconto_medio'] ?? null,
			'contratos_federais' => (int) ( $fed['contratos_total'] ?? 0 ), 'disputas' => (int) ( $P['total'] ?? 0 ), 'primeiro' => (int) ( $P['primeiro_lugar'] ?? 0 ) );
	} ) );
} );

/* ---------------- Página do Raio-X em lote ---------------- */
add_shortcode( 'dv_lote', function () {
	if ( ! is_user_logged_in() ) { return '<p>Entre na sua conta para usar o Raio-X em lote.</p>'; }
	$custo = function_exists( 'dv_custo' ) ? dv_custo( 'raiox' ) : 5;
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
 h+='<h4>Acompanhar e comparar</h4><p>Receba um e-mail quando esta empresa vencer uma licitação, sofrer sanção, mudar de situação na Receita ou passar a ter dívida ativa em cobrança.</p>';
 h+='<button type="button" class="bt '+(J.monitorado?'on':'p')+' c-mon">'+(J.monitorado?'✓ Monitorando · parar':'Monitorar este concorrente')+'</button>';
 h+=J.meu?'<button type="button" class="bt c-cmp">Comparar com a minha empresa</button>':'<span class="m"> Cadastre o CNPJ da sua empresa no painel para comparar.</span>';
 h+='<a class="bt" href="/plataforma/captador/">Monitorar nos diários oficiais</a><div class="c-out"></div>';
 return h}
function ligaC(b,J,c){var E=J.experiencia||[];var q=b.querySelector('.c-q'),v=b.querySelector('.c-v'),r=b.querySelector('.c-r');
 function calc(){if(!r)return;var mp=Math.max.apply(null,E.map(function(x){return x.max_postos||0}).concat([0])),mv=Math.max.apply(null,E.map(function(x){return x.maior_valor||0}).concat([0])),o=[];
  if(+q.value>0){var nec=Math.ceil(+q.value*.5);o.push(mp?(mp>=nec?'✓ Já teve contrato com '+mp+' postos (o máximo exigível seria '+nec+').':'⚠ Maior contrato encontrado tem '+mp+' postos; o máximo exigível seria '+nec+'. Confira o atestado.'):'Postos não informados nos contratos.')}
  if(+v.value>0){var nv=+v.value*1000*.5;o.push(mv>=nv?'✓ Já teve contrato de '+mc(mv)+'.':'⚠ Maior contrato encontrado: '+mc(mv)+' (metade do exigido seria '+mc(nv)+').')}
  r.textContent=o.join(' ')}
 if(q){q.oninput=calc;v.oninput=calc}
 var m=b.querySelector('.c-mon');if(m)m.onclick=function(){m.disabled=true;var on=m.classList.contains('on');post('int/monitor',{cnpj:c,acao:on?'del':'add'}).then(function(){m.disabled=false;m.classList.toggle('on',!on);m.classList.toggle('p',on);m.textContent=on?'Monitorar este concorrente':'✓ Monitorando · parar'}).catch(function(x){m.disabled=false;alert((x&&x.message)||'Não foi possível agora.')})};
 var k=b.querySelector('.c-cmp');if(k)k.onclick=function(){var o=b.querySelector('.c-out');o.innerHTML='<p>Comparando…</p>';get('int/concorrente?cnpj='+c+'&comparar=1').then(function(X){if(!X.eu){o.innerHTML='<p>Não foi possível comparar.</p>';return}var a=X.eu.resumo,z=X.resumo;
  function an(d){var t=Date.parse(d);return t?Math.floor((Date.now()-t)/31557600000)+' anos':'—'}
  var L=[['Licitações vencidas (3 anos)',a.vitorias,z.vitorias],['Valor homologado',mc(a.valor),mc(z.valor)],['Desconto médio',pc(a.desconto),pc(z.desconto)],['Contratos no PNCP',a.contratos,z.contratos],['Valor dos contratos',mc(a.contratos_valor),mc(z.contratos_valor)],['Capital social',mc(a.capital),mc(z.capital)],['Tempo de atividade',an(a.abertura),an(z.abertura)],['Porte',a.porte||'—',z.porte||'—']];
  o.innerHTML='<div class="tw" style="margin-top:12px"><table><tr><th></th><th>Sua empresa</th><th>'+e(X.nome||'Concorrente')+'</th></tr>'+L.map(function(l){return'<tr><td>'+l[0]+'</td><td><b>'+e(l[1])+'</b></td><td><b>'+e(l[2])+'</b></td></tr>'}).join('')+'</table></div>'}).catch(function(){o.innerHTML='<p>Não foi possível comparar agora.</p>'})}}

/* ---------- Comprador ---------- */
function comprador(c){var r=root(),ref=[].filter.call(r.querySelectorAll('.rp-sec'),function(s){return/^Resumo/i.test((s.querySelector('h3')||{}).textContent||'')})[0];
 var b=document.getElementById('dv-io');if(!b){b=document.createElement('section');b.id='dv-io';b.className='dv-ix';if(ref)ref.parentNode.insertBefore(b,ref.nextSibling);else r.appendChild(b)}
 if(b.dataset.c===c&&b._ok)return;b._ok=1;b.dataset.c=c;var it=(document.querySelectorAll('.dv-tool input')[1]||{}).value||new URLSearchParams(location.search).get('item')||'';
 b.innerHTML='<p class="k">Inteligência do comprador</p><p>Levantando quem ganha aqui, o histórico das licitações e os contratos que vão vencer…</p>';
 get('int/orgao?cnpj='+c+'&item='+encodeURIComponent(it)).then(function(J){b.innerHTML=htmlO(J,c);pagamentos(b,c)}).catch(function(){b.innerHTML='<p class="k">Inteligência do comprador</p><p>Não foi possível consultar o PNCP agora.</p>'})}
function htmlO(J,c){var H=J.historico||{},it=J.item?' de "'+e(J.item)+'"':'';var h='<p class="k">Inteligência do comprador</p><h3>Quem ganha aqui, como o órgão licita e o que vem por aí</h3>';
 h+='<div class="n"><div><b>'+(H.total||0)+'</b><span>pregões eletrônicos em 12 meses</span></div><div><b>'+pc(H.desconto_medio)+'</b><span>desconto médio homologado</span></div><div><b>'+((H.revogadas||0)+(H.anuladas||0))+'</b><span>revogados ou anulados</span></div><div><b>'+(J.n_calendario||0)+'</b><span>contratos'+(J.item?' do seu item':'')+' vencem em 6 meses</span></div></div>';
 var V=J.vencedores||[];h+='<h4>Quem ganha aqui'+it+'</h4>';
 if(V.length){h+='<div class="tw"><table><tr><th>Empresa</th><th>Contratos</th><th>Valor</th><th></th></tr>'+V.map(function(v){return'<tr><td>'+e(v.nome)+'<small>'+fc(v.cnpj)+'</small></td><td>'+v.n+'</td><td>'+mc(v.valor)+'</td><td><a href="/plataforma/raio-x/?cnpj='+v.cnpj+'">Raio-X</a></td></tr>'}).join('')+'</table></div>';if(J.concentracao>=50)h+='<div class="al">'+J.concentracao+'% do valor'+(J.item?' do seu item':'')+' está com um único fornecedor. Vale entender por quê antes de entrar (exigências do edital, prazos de mobilização).</div>'}
 else h+='<p>Nenhum contrato'+it+' encontrado no PNCP nos últimos 3 anos.</p>';
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

/* ---------- Due Diligence: sócios e linha do tempo ---------- */
function dossie(c){var b=caixa('dv-id','#dv-ic');if(b.dataset.c===c&&b._ok)return;b._ok=1;b.dataset.c=c;b.innerHTML='<p class="k">Sócios e linha do tempo</p><p>Montando…</p>';
 get('int/dossie?cnpj='+c).then(function(J){b.innerHTML=htmlD(J)}).catch(function(x){b.innerHTML='<p class="k">Sócios e linha do tempo</p><p>'+e((x&&x.message)||'Indisponível agora.')+'</p>'})}
function htmlD(J){var h='<p class="k">Sócios e linha do tempo</p><h3>Quem está por trás e como a empresa chegou até aqui</h3>';(J.alertas||[]).forEach(function(a){h+='<div class="al">'+e(a)+'</div>'});
 h+='<h4>Sócios a fundo</h4>'+((J.socios||[]).length?(J.socios||[]).map(function(s){var t=[];if(s.criminais)t.push('<span class="tag r">'+s.criminais+' processo(s) criminal(is)</span>');if(s.pep)t.push('<span class="tag r">Pessoa politicamente exposta</span>');if(s.divida_grupo>0)t.push('<span class="tag a">Empresas com dívida ativa: '+mc(s.divida_grupo)+'</span>');if(s.parentes)t.push('<span class="tag a">'+s.parentes+' possível(is) parente(s) em outras empresas</span>');if(!t.length)t.push('<span class="tag v">Nada encontrado</span>');
  return'<div class="sc"><b>'+e(s.nome)+'</b> · '+e(s.qualificacao)+(s.entrada?' · desde '+br(s.entrada):'')+(s.faixa?' · '+e(s.faixa):'')+'<br>'+t.join('')+(s.n_empresas?'<p class="m" style="margin:6px 0 0!important">Outras empresas ('+s.n_empresas+'): '+s.empresas.map(function(x){return'<a href="/plataforma/raio-x/?cnpj='+x.cnpj+'">'+e(x.razao)+'</a>'+(x.pgfn>0?' ('+mc(x.pgfn)+' em dívida)':'')}).join(' · ')+'</p>':'')+'</div>'}).join(''):'<p>Quadro de sócios não informado pela Receita.</p>');
 h+='<h4>Linha do tempo</h4><ul class="tl">'+(J.timeline||[]).map(function(t){return'<li class="'+e(t.tipo)+'"><b>'+br(t.data)+'</b> '+e(t.texto)+(t.link?' · <a href="'+e(t.link)+'" target="_blank" rel="noopener">abrir</a>':'')+'</li>'}).join('')+'</ul><p class="m">Cadastro da Receita, contratos no PNCP, processos (DJEN/CNJ), notícias e sanções. Processos trabalhistas comuns aparecem somados por ano.</p>';
 return h}

/* ---------- Raio-X em lote ---------- */
if(lt){var T=lt.querySelector('textarea'),go=lt.querySelector('.lt-go'),cu=+lt.dataset.custo||0,cc=lt.querySelector('.lt-c'),mm=lt.querySelector('.lt-m'),out=lt.querySelector('.lt-out');
 function lista(){var s={},o=[];(T.value.match(/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/g)||[]).forEach(function(x){x=x.replace(/\D/g,'');if(!s[x]){s[x]=1;o.push(x)}});return o.slice(0,20)}
 T.oninput=function(){var n=lista().length;cc.textContent=n?n+' CNPJ(s)'+(cu?' · '+n*cu+' créditos':''):''};
 go.onclick=function(){var L=lista();mm.textContent='';if(!L.length){mm.textContent='Cole pelo menos um CNPJ.';return}if(cu&&!confirm('Gerar o Raio-X de '+L.length+' empresa(s)? Usa '+L.length*cu+' créditos (CNPJs consultados nos últimos 15 minutos não são cobrados de novo).'))return;
  go.disabled=true;var R=[];out.innerHTML='<section class="dv-ix"><p>Gerando 0 de '+L.length+'…</p></section>';var q=Promise.resolve();
  L.forEach(function(c,i){q=q.then(function(){out.querySelector('p').textContent='Gerando '+(i+1)+' de '+L.length+'…';return post('int/lote',{cnpj:c}).then(function(x){R.push(x)}).catch(function(x){R.push({cnpj:c,erro:(x&&x.message)||'Falhou'});if(x&&x.code==='dv_sem_credito')throw x})})});
  q.catch(function(x){mm.textContent=(x&&x.message)||''}).then(function(){go.disabled=false;out.innerHTML=tabela(R)})};
 function tabela(R){var h='<section class="dv-ix"><p class="k">Raio-X em lote</p><h3>Comparativo dos participantes</h3><div class="tw"><table><tr><th>Empresa</th><th>Receita</th><th>Sanções</th><th>Dívida ativa</th><th>Vitórias (3 anos)</th><th>Desconto médio</th><th>Disputas na base</th><th></th></tr>';
  R.forEach(function(x){if(x.erro){h+='<tr><td>'+fc(x.cnpj)+'</td><td colspan="7">'+e(x.erro)+'</td></tr>';return}var at=Date.parse(x.abertura),an=at?Math.floor((Date.now()-at)/31557600000):null;
   h+='<tr><td><b>'+e(x.nome)+'</b><small>'+fc(x.cnpj)+' · '+e(x.municipio)+'</small></td><td>'+(x.situacao==='ATIVA'?'<span class="tag v">ATIVA</span>':'<span class="tag r">'+e(x.situacao||'?')+'</span>')+'<small>'+(an!=null?an+' anos · ':'')+'capital '+mc(x.capital)+'</small></td><td>'+(x.sancoes?'<span class="tag r">'+x.sancoes+'</span>':'<span class="tag v">nada</span>')+'</td><td>'+(x.pgfn>0?'<span class="tag '+(x.cobranca>0?'r':'a')+'">'+mc(x.pgfn)+'</span><small>'+(x.cobranca>0?'em cobrança':'parcelada ou suspensa')+(x.fgts>0?' · FGTS':'')+'</small>':'<span class="tag v">nada</span>')+'</td><td>'+x.vitorias+'<small>'+mc(x.vitorias_valor)+'</small></td><td>'+pc(x.desconto)+'</td><td>'+(x.disputas?x.disputas+(x.primeiro?' · '+x.primeiro+'× 1º':''):'—')+'</td><td><a href="/plataforma/raio-x/?cnpj='+x.cnpj+'">Raio-X completo</a></td></tr>'});
  return h+'</table></div><p class="m">Dados da Receita, CEIS/CNEP/TCU, PGFN (base trimestral) e PNCP. O Raio-X completo de cada empresa abre sem nova cobrança nos próximos 15 minutos e fica salvo por 30 dias depois de aberto. Não constitui parecer jurídico.</p></section>'}
 return}

/* ---------- gatilho ---------- */
var tm=null;function tenta(){var r=root();if(!r||!pronto(r))return;var c=cnpj();if(!c)return;
 if(PG==='comprador'){comprador(c);return}
 if(!document.getElementById('dv-pv'))return;concorrente(c);if(PG==='dossie')dossie(c)}
new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(tenta,1500)}).observe(document.body,{childList:true,subtree:true});
})();
</script>
	<?php
}, 96 );
