/**
 * Desenvolve — Raio-X do comprador (órgão público)
 * O fornecedor consulta a prefeitura/órgão: se paga em dia (Tesouro/SICONFI), contratos ativos e antigos,
 * licitações abertas, preços praticados para o item que ele vende, cobranças na Justiça e notícias.
 * Rota: GET dvtools/v1/comprador?cnpj=&item=   (só assinantes; o crédito é cobrado pela tela)
 * Depende de: núcleo, ferramentas (dv_tools_perm), dossiê (dv_ds_multi, dv_ds_nrm), notícias (dv_nt_rss).
 */

function dv_cp_tok( $s ) {
	$pare = array( 'DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM', 'PARA', 'COM', 'SEM', 'POR', 'A', 'O', 'AS', 'OS', 'UM', 'UMA', 'NA', 'NO', 'AO', 'SERVICO', 'SERVICOS', 'CONTRATACAO', 'EMPRESA', 'AQUISICAO' );
	$o = array();
	foreach ( explode( ' ', preg_replace( '/[^A-Z0-9 ]/', ' ', dv_ds_nrm( $s ) ) ) as $w ) {
		if ( strlen( $w ) < 3 || in_array( $w, $pare, true ) ) { continue; }
		if ( strlen( $w ) > 4 && substr( $w, -1 ) === 'S' ) { $w = substr( $w, 0, -1 ); }
		if ( strlen( $w ) > 5 ) { $w = substr( $w, 0, strlen( $w ) - 1 ); } // raiz simples: ALIMENTACAO ~ ALIMENTACA
		$o[] = $w;
	}
	return array_values( array_unique( $o ) );
}
function dv_cp_casa( $txt, $toks ) {
	if ( ! $toks ) { return true; }
	$t = dv_ds_nrm( $txt ); $hit = 0;
	foreach ( $toks as $k ) { if ( strpos( $t, $k ) !== false ) { $hit++; } }
	return $hit >= max( 1, (int) ceil( count( $toks ) * 0.6 ) );
}

/* ---------- Saúde fiscal do ente (SICONFI / Tesouro Nacional) ---------- */
function dv_cp_siconfi( $id_ente, $esfera ) {
	$ck = 'dv_cp_sic_' . $id_ente; $c = get_transient( $ck ); if ( $c ) { return $c; }
	$B = 'https://apidatalake.tesouro.gov.br/ords/siconfi/tt/'; $ano = (int) current_time( 'Y' ) - 1; $esf = $esfera === 'E' ? 'E' : 'M';
	$o = array( 'ano' => $ano, 'rp' => array() );
	$urls = array();
	foreach ( array( $ano, $ano - 1 ) as $a ) {
		$urls[ 'rp' . $a ] = $B . 'rreo?an_exercicio=' . $a . '&nr_periodo=6&co_tipo_demonstrativo=RREO&no_anexo=RREO-Anexo%2007&id_ente=' . $id_ente;
	}
	$urls['rcl'] = $B . 'rreo?an_exercicio=' . $ano . '&nr_periodo=6&co_tipo_demonstrativo=RREO&no_anexo=RREO-Anexo%2003&id_ente=' . $id_ente;
	foreach ( array( 'Q' => 3, 'S' => 2 ) as $per => $n ) {
		$urls[ 'pes' . $per ] = $B . 'rgf?an_exercicio=' . $ano . '&in_periodicidade=' . $per . '&nr_periodo=' . $n . '&co_tipo_demonstrativo=RGF&no_anexo=RGF-Anexo%2001&co_esfera=' . $esf . '&co_poder=E&id_ente=' . $id_ente;
		$urls[ 'cx' . $per ] = $B . 'rgf?an_exercicio=' . $ano . '&in_periodicidade=' . $per . '&nr_periodo=' . $n . '&co_tipo_demonstrativo=RGF&no_anexo=RGF-Anexo%2005&co_esfera=' . $esf . '&co_poder=E&id_ente=' . $id_ente;
	}
	$b = dv_ds_multi( $urls, array(), 40 ); $it = function ( $k ) use ( $b ) { $j = ! empty( $b[ $k ] ) ? json_decode( $b[ $k ], true ) : null; return (array) ( $j['items'] ?? array() ); };
	foreach ( array( $ano, $ano - 1 ) as $a ) {
		$r = array();
		foreach ( $it( 'rp' . $a ) as $i ) {
			if ( ! preg_match( '/^RESTOS A PAGAR \(EXCETO/', $i['conta'] ?? '' ) ) { continue; }
			$col = $i['coluna'] ?? ''; $v = (float) $i['valor']; $cc = $i['cod_conta'] ?? '';
			if ( strpos( $cc, 'ProcessadosENaoProcessadosLiquidados' ) !== false || strpos( $cc, 'RestosAPagarProcessados' ) === 0 ) {
				if ( strpos( $col, '(a)' ) !== false ) { $r['proc_ant'] = $v; } elseif ( strpos( $col, '(b)' ) !== false ) { $r['proc_ano'] = $v; }
				elseif ( strpos( $col, '(c)' ) !== false ) { $r['proc_pagos'] = $v; } elseif ( strpos( $col, '(d)' ) !== false ) { $r['proc_cancel'] = $v; }
				elseif ( strpos( $col, '(e)' ) !== false ) { $r['proc_saldo'] = $v; }
			}
			if ( strpos( $col, 'Saldo Total' ) !== false ) { $r['saldo_total'] = $v; }
			if ( strpos( $col, '(i)' ) !== false ) { $r['np_pagos'] = $v; }
		}
		if ( $r ) { $ins = ( $r['proc_ant'] ?? 0 ) + ( $r['proc_ano'] ?? 0 ); $r['proc_pct_pago'] = $ins > 0 ? round( ( $r['proc_pagos'] ?? 0 ) / $ins * 100, 1 ) : null; $o['rp'][ $a ] = $r; }
	}
	foreach ( $it( 'rcl' ) as $i ) { if ( ( $i['cod_conta'] ?? '' ) === 'RREO3ReceitaCorrenteLiquida' && stripos( $i['coluna'] ?? '', 'TOTAL' ) !== false ) { $o['rcl'] = (float) $i['valor']; $o['ente'] = $i['instituicao'] ?? ''; $o['populacao'] = $i['populacao'] ?? null; } }
	$pes = $it( 'pesQ' ) ?: $it( 'pesS' );
	foreach ( $pes as $i ) {
		$cc = $i['cod_conta'] ?? ''; $col = $i['coluna'] ?? '';
		if ( $cc === 'DespesaComPessoalTotal' && strpos( $col, '%' ) !== false ) { $o['pessoal_pct'] = (float) $i['valor']; }
		if ( strpos( $cc, 'LimiteMaximo' ) === 0 && strpos( $col, '%' ) !== false ) { $o['pessoal_max'] = (float) $i['valor']; }
		if ( strpos( $cc, 'LimitePrudencial' ) === 0 && strpos( $col, '%' ) !== false ) { $o['pessoal_prud'] = (float) $i['valor']; }
	}
	$cx = $it( 'cxQ' ) ?: $it( 'cxS' );
	foreach ( $cx as $i ) {
		$col = $i['coluna'] ?? ''; $conta = $i['conta'] ?? '';
		if ( preg_match( '/^TOTAL \(IV\)|^TOTAL \(III\)/', $conta ) || ( stripos( $conta, 'TOTAL' ) === 0 && stripos( $conta, 'VINCULA' ) === false ) ) {
			if ( stripos( $col, 'APÓS A INSCRIÇÃO' ) !== false ) { $o['caixa_liquida'] = (float) $i['valor']; }
			if ( stripos( $col, 'CAIXA BRUTA' ) !== false ) { $o['caixa_bruta'] = (float) $i['valor']; }
		}
		if ( stripos( $conta, 'NÃO VINCULADOS' ) !== false && stripos( $col, 'APÓS A INSCRIÇÃO' ) !== false && stripos( $conta, 'TOTAL' ) === 0 ) { $o['caixa_livre'] = (float) $i['valor']; }
	}
	set_transient( $ck, $o, ( $o['rp'] || isset( $o['rcl'] ) ) ? 7 * DAY_IN_SECONDS : HOUR_IN_SECONDS );
	return $o;
}

/* ---------- Contratos e licitações do órgão no PNCP (API de consulta) ---------- */
function dv_cp_contratos( $cnpj ) {
	$B = 'https://pncp.gov.br/api/consulta/v1/contratos?cnpjOrgao=' . $cnpj . '&tamanhoPagina=500';
	$ano = (int) current_time( 'Y' ); $u = array();
	for ( $a = $ano; $a >= $ano - 3; $a-- ) { $u[ $a . '_1' ] = $B . '&dataInicial=' . $a . '0101&dataFinal=' . ( $a === $ano ? current_time( 'Ymd' ) : $a . '1231' ) . '&pagina=1'; }
	$b = dv_ds_multi_lento( $u, 2, 50 ); unset( $b['__falhas'] ); $mais = array();
	foreach ( $b as $k => $body ) { $j = $body ? json_decode( $body, true ) : null; $tp = (int) ( $j['totalPaginas'] ?? 1 ); for ( $p = 2; $p <= min( $tp, 4 ); $p++ ) { $mais[ str_replace( '_1', '_' . $p, $k ) ] = str_replace( '&pagina=1', '&pagina=' . $p, $u[ $k ] ); } }
	if ( $mais ) { $mm = dv_ds_multi_lento( $mais, 2, 50 ); unset( $mm['__falhas'] ); $b += $mm; }
	$o = array(); $v = array(); $erro = 0;
	foreach ( $b as $body ) {
		$j = $body ? json_decode( $body, true ) : null; if ( ! $j ) { $erro++; continue; }
		foreach ( (array) ( $j['data'] ?? array() ) as $i ) {
			$k = $i['numeroControlePNCP'] ?? ''; if ( isset( $v[ $k ] ) ) { continue; } $v[ $k ] = 1;
			$ac = (string) ( $i['anoContrato'] ?? '' ); $sc = (string) ( $i['sequencialContrato'] ?? '' );
			$o[] = array( 'titulo' => 'Contrato ' . ( $i['numeroContratoEmpenho'] ?? '' ), 'objeto' => mb_substr( trim( (string) ( $i['objetoContrato'] ?? '' ) ), 0, 260 ),
				'fornecedor' => $i['nomeRazaoSocialFornecedor'] ?? '', 'fornecedor_ni' => $i['niFornecedor'] ?? '', 'valor' => (float) ( $i['valorGlobal'] ?? $i['valorInicial'] ?? 0 ),
				'unidade' => $i['unidadeOrgao']['nomeUnidade'] ?? '', 'assinatura' => $i['dataAssinatura'] ?? '', 'inicio' => (string) ( $i['dataVigenciaInicio'] ?? '' ), 'fim' => (string) ( $i['dataVigenciaFim'] ?? '' ),
				'compra' => $i['numeroControlePncpCompra'] ?? '', 'link' => ( $ac && $sc ) ? 'https://pncp.gov.br/app/contratos/' . $cnpj . '/' . $ac . '/' . $sc : '' );
		}
	}
	return array( 'total' => count( $o ), 'itens' => $o, 'falhas' => $erro );
}
function dv_cp_abertas( $cnpj ) {
	$u = 'https://pncp.gov.br/api/consulta/v1/contratacoes/proposta?cnpj=' . $cnpj . '&dataFinal=' . gmdate( 'Ymd', strtotime( '+1 year' ) ) . '&pagina=1&tamanhoPagina=50';
	$b = dv_ds_multi( array( 1 => $u ), array(), 40 ); $j = $b[1] ? json_decode( $b[1], true ) : null; $o = array();
	foreach ( (array) ( $j['data'] ?? array() ) as $i ) {
		$a = $i['anoCompra'] ?? ''; $s = $i['sequencialCompra'] ?? '';
		$o[] = array( 'titulo' => trim( ( $i['modalidadeNome'] ?? '' ) . ' ' . ( $i['numeroCompra'] ?? '' ) ), 'objeto' => mb_substr( trim( (string) ( $i['objetoCompra'] ?? '' ) ), 0, 300 ),
			'valor' => (float) ( $i['valorTotalEstimado'] ?? 0 ), 'modalidade' => $i['modalidadeNome'] ?? '', 'unidade' => $i['unidadeOrgao']['nomeUnidade'] ?? '',
			'inicio' => (string) ( $i['dataAberturaProposta'] ?? '' ), 'fim' => (string) ( $i['dataEncerramentoProposta'] ?? '' ), 'ano' => $a, 'seq' => $s, 'situacao' => $i['situacaoCompraNome'] ?? '',
			'link' => ( $a && $s ) ? 'https://pncp.gov.br/app/editais/' . $cnpj . '/' . $a . '/' . $s : ( $i['linkSistemaOrigem'] ?? '' ) );
	}
	return array( 'total' => (int) ( $j['totalRegistros'] ?? count( $o ) ), 'itens' => $o );
}
/* itens e preços (estimado e homologado) das compras do órgão que tratam do produto */
function dv_cp_precos( $cnpj, $compras, $toks ) {
	$alvo = array_slice( $compras, 0, 12 );
	$u = array(); foreach ( $alvo as $k => $e ) { $u[ $k ] = 'https://pncp.gov.br/api/pncp/v1/orgaos/' . $cnpj . '/compras/' . $e['ano'] . '/' . $e['seq'] . '/itens?pagina=1&tamanhoPagina=100'; }
	$b = $u ? dv_ds_multi_lento( $u, 4, 40 ) : array(); unset( $b['__falhas'] ); $itens = array(); $res = array();
	$algum = false; foreach ( $b as $body ) { foreach ( (array) ( $body ? json_decode( $body, true ) : array() ) as $i ) { if ( dv_cp_casa( $i['descricao'] ?? '', $toks ) ) { $algum = true; break 2; } } }
	foreach ( $b as $k => $body ) {
		$nk = 0;
		foreach ( (array) ( $body ? json_decode( $body, true ) : array() ) as $i ) {
			if ( $algum ? ! dv_cp_casa( $i['descricao'] ?? '', $toks ) : ( ++$nk > 8 ) ) { continue; }
			$x = array( 'descricao' => mb_substr( (string) ( $i['descricao'] ?? '' ), 0, 220 ), 'unidade' => $i['unidadeMedida'] ?? '', 'quantidade' => (float) ( $i['quantidade'] ?? 0 ),
				'estimado' => (float) ( $i['valorUnitarioEstimado'] ?? 0 ), 'homologado' => null, 'vencedor' => '', 'data' => substr( (string) $alvo[ $k ]['data'], 0, 10 ),
				'compra' => $alvo[ $k ]['titulo'], 'link' => $alvo[ $k ]['link'] );
			if ( ! empty( $i['temResultado'] ) && count( $res ) < 30 ) { $res[ count( $itens ) ] = 'https://pncp.gov.br/api/pncp/v1/orgaos/' . $cnpj . '/compras/' . $alvo[ $k ]['ano'] . '/' . $alvo[ $k ]['seq'] . '/itens/' . (int) $i['numeroItem'] . '/resultados'; }
			$itens[] = $x; if ( count( $itens ) >= 80 ) { break 2; }
		}
	}
	if ( $res ) {
		$rr = dv_ds_multi_lento( $res, 5, 30 ); unset( $rr['__falhas'] );
		foreach ( $rr as $k => $body ) {
			$r = $body ? json_decode( $body, true ) : null; $r = is_array( $r ) && isset( $r[0] ) ? $r[0] : $r;
			if ( $r ) { $itens[ $k ]['homologado'] = isset( $r['valorUnitarioHomologado'] ) ? (float) $r['valorUnitarioHomologado'] : null; $itens[ $k ]['vencedor'] = $r['nomeRazaoSocialFornecedor'] ?? ''; }
		}
	}
	return $itens;
}

function dv_comprador( $cnpj, $item ) {
	$ck = 'dv_cp4_' . $cnpj . '_' . md5( $item ); $c = get_transient( $ck ); if ( $c ) { return $c; }
	$x = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 20 ) );
	$cad = is_wp_error( $x ) ? array() : (array) json_decode( wp_remote_retrieve_body( $x ), true );
	if ( empty( $cad['razao_social'] ) ) { return new WP_Error( 'dv_cnpj', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
	$nat = dv_ds_nrm( $cad['natureza_juridica'] ?? '' );
	$publico = preg_match( '/MUNICIP|ESTAD|FEDERAL|UNIAO|AUTARQUIA|FUNDACAO PUBLICA|ORGAO PUBLICO|CONSORCIO PUBLICO|FUNDO PUBLICO|DISTRITO FEDERAL|PODER|MINISTERIO PUBLICO|TRIBUNAL|CAMARA|ASSEMBLEIA|EMPRESA PUBLICA|ECONOMIA MISTA/', $nat );
	$esfera = preg_match( '/MUNICIP/', $nat ) ? 'M' : ( preg_match( '/ESTAD|DISTRITO FEDERAL/', $nat ) ? 'E' : ( preg_match( '/FEDERAL|UNIAO/', $nat ) ? 'U' : '' ) );
	$ufs = array( 'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17, 'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27, 'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41, 'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53 );
	$id_ente = $esfera === 'M' ? (string) ( $cad['codigo_municipio_ibge'] ?? '' ) : ( $esfera === 'E' ? (string) ( $ufs[ $cad['uf'] ?? '' ] ?? '' ) : '' );
	$toks = dv_cp_tok( $item );
	$D = array( 'cnpj' => $cnpj, 'item' => $item, 'tokens' => $toks, 'gerado_em' => current_time( 'mysql' ), 'cadastro' => $cad, 'publico' => (bool) $publico, 'esfera' => $esfera, 'id_ente' => $id_ente );
	$D['fiscal'] = $id_ente ? dv_cp_siconfi( $id_ente, $esfera ) : null;
	$ct = dv_cp_contratos( $cnpj ); $ed = dv_cp_abertas( $cnpj );
	$D['contratos'] = $ct; $D['editais'] = $ed;
	$compras = array(); $vis = array();
	if ( $toks ) {
		foreach ( $ed['itens'] as $x ) { if ( $x['ano'] && $x['seq'] && dv_cp_casa( $x['objeto'], $toks ) ) { $k = $x['ano'] . '/' . $x['seq']; if ( ! isset( $vis[ $k ] ) ) { $vis[ $k ] = 1; $compras[] = array( 'ano' => $x['ano'], 'seq' => $x['seq'], 'objeto' => $x['objeto'], 'titulo' => $x['titulo'], 'link' => $x['link'], 'data' => $x['inicio'] ); } } }
		foreach ( $ct['itens'] as $x ) {
			if ( ! dv_cp_casa( $x['objeto'], $toks ) || ! preg_match( '#-(\d+)/(\d{4})$#', (string) $x['compra'], $m ) ) { continue; }
			$k = $m[2] . '/' . (int) $m[1]; if ( isset( $vis[ $k ] ) ) { continue; } $vis[ $k ] = 1;
			$compras[] = array( 'ano' => $m[2], 'seq' => (int) $m[1], 'objeto' => $x['objeto'], 'titulo' => 'Compra ' . (int) $m[1] . '/' . $m[2] . ' (' . $x['fornecedor'] . ')', 'link' => 'https://pncp.gov.br/app/editais/' . $cnpj . '/' . $m[2] . '/' . (int) $m[1], 'data' => $x['assinatura'] );
		}
	}
	$D['precos'] = $toks ? dv_cp_precos( $cnpj, $compras, $toks ) : array();
	/* cobranças na Justiça contra o órgão (12 meses) */
	$nome = dv_ds_nrm( $cad['razao_social'] ); $ini = gmdate( 'Y-m-d', strtotime( '-12 months' ) ); $fim = current_time( 'Y-m-d' );
	$bj = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao?nomeParte=' . rawurlencode( $nome ) . '&dataDisponibilizacaoInicio=' . $ini . '&dataDisponibilizacaoFim=' . $fim . '&itensPorPagina=1&pagina=1';
	$q = array( 'total' => $bj );
	foreach ( array( 'cobranca' => 'ação de cobrança', 'monitoria' => 'monitória', 'execucao' => 'execução de título extrajudicial', 'precatorio' => 'precatório', 'rpv' => 'requisição de pequeno valor' ) as $k => $t ) { $q[ $k ] = $bj . '&texto=' . rawurlencode( $t ); }
	$rj = dv_ds_multi( $q, array(), 30 ); $J = array();
	foreach ( $rj as $k => $b ) { $j = $b ? json_decode( $b, true ) : null; $J[ $k ] = $j ? (int) ( $j['count'] ?? 0 ) : null; }
	$D['justica'] = $J; $D['justica_nome'] = $nome;
	/* notícias sobre pagamento a fornecedores */
	$mun = trim( (string) ( $cad['municipio'] ?? '' ) ); $alvoN = $esfera === 'M' && $mun ? 'Prefeitura de ' . ucwords( mb_strtolower( $mun ) ) : $cad['razao_social'];
	$g = function ( $q ) { return 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419'; };
	$nb = dv_ds_multi( array( 'pag' => $g( '"' . $alvoN . '" (atraso OR atrasa OR calote OR "sem pagamento" OR "não paga" OR dívida OR débito) fornecedores' ), 'emp' => $g( '"' . $alvoN . '" (empresa OR terceirizada) (paralisação OR "falta de pagamento" OR "salários atrasados")' ) ), array( 'User-Agent' => 'Mozilla/5.0' ), 20 );
	$nt = array(); $vis = array();
	foreach ( $nb as $b ) { if ( ! $b || ! function_exists( 'dv_nt_rss' ) ) { continue; } foreach ( dv_nt_rss( $b ) as $i ) { $h = md5( $i['url'] ); if ( isset( $vis[ $h ] ) ) { continue; } $vis[ $h ] = 1; $nt[] = $i; } }
	usort( $nt, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$D['noticias'] = array_slice( $nt, 0, 25 ); $D['noticias_termo'] = $alvoN;
	set_transient( $ck, $D, 6 * HOUR_IN_SECONDS );
	return $D;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'dvtools/v1', '/comprador', array( 'methods' => 'GET',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'Informe o CNPJ do órgão (14 números).', array( 'status' => 400 ) ); }
			if ( ! function_exists( 'dv_ds_multi' ) ) { return new WP_Error( 'dv_dep', 'Ferramenta indisponível no momento.', array( 'status' => 503 ) ); }
			set_time_limit( 120 );
			return dv_comprador( $cnpj, mb_substr( sanitize_text_field( (string) $r['item'] ), 0, 120 ) );
		} ) );
} );
