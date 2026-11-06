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
	$b = dv_ds_multi( $urls, array(), 18 ); $it = function ( $k ) use ( $b ) { $j = ! empty( $b[ $k ] ) ? json_decode( $b[ $k ], true ) : null; return (array) ( $j['items'] ?? array() ); };
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
	/* cache por órgão (12h): a mesma consulta com outro item não refaz a busca */
	$ck = 'dv_cpct2_' . $cnpj; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	/* os 4 anos em paralelo; páginas extras só para órgãos médios (órgão grande = amostra de 500 por ano, que já basta para o perfil) */
	$t0 = microtime( true ); $b = dv_ds_multi( $u, array(), 20 ); unset( $b['__falhas'] ); $mais = array(); $treg = 0;
	foreach ( $b as $k => $body ) { $j = $body ? json_decode( $body, true ) : null; $tp = (int) ( $j['totalPaginas'] ?? 1 ); $treg += (int) ( $j['totalRegistros'] ?? 0 ); if ( $tp <= 3 ) { for ( $p = 2; $p <= $tp; $p++ ) { $mais[ str_replace( '_1', '_' . $p, $k ) ] = str_replace( '&pagina=1', '&pagina=' . $p, $u[ $k ] ); } } }
	if ( $mais && microtime( true ) - $t0 < 12 && dv_cp_resta() > 25 ) { $mm = dv_ds_multi( $mais, array(), 12 ); unset( $mm['__falhas'] ); $b += $mm; }
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
	$r = array( 'total' => max( count( $o ), $treg ), 'itens' => $o, 'falhas' => $erro, 'amostra' => $treg > count( $o ) ); if ( ! $erro || $o ) { set_transient( $ck, $r, 12 * HOUR_IN_SECONDS ); } return $r;
}
function dv_cp_abertas( $cnpj ) {
	$u = 'https://pncp.gov.br/api/consulta/v1/contratacoes/proposta?cnpj=' . $cnpj . '&dataFinal=' . gmdate( 'Ymd', strtotime( '+1 year' ) ) . '&pagina=1&tamanhoPagina=50';
	$b = dv_ds_multi( array( 1 => $u ), array(), 15 ); $j = $b[1] ? json_decode( $b[1], true ) : null; $o = array();
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
	$b = $u ? dv_ds_multi( $u, array(), 15 ) : array(); unset( $b['__falhas'] ); $itens = array(); $res = array();
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
		$rr = dv_ds_multi( $res, array(), 12 ); unset( $rr['__falhas'] );
		foreach ( $rr as $k => $body ) {
			$r = $body ? json_decode( $body, true ) : null; $r = is_array( $r ) && isset( $r[0] ) ? $r[0] : $r;
			if ( $r ) { $itens[ $k ]['homologado'] = isset( $r['valorUnitarioHomologado'] ) ? (float) $r['valorUnitarioHomologado'] : null; $itens[ $k ]['vencedor'] = $r['nomeRazaoSocialFornecedor'] ?? ''; }
		}
	}
	return $itens;
}


/* ---------- Quem governa: prefeito, vice e vereadores eleitos (TSE) / governador ---------- */
function dv_cp_politicos( $cad, $esfera ) {
	$uf = strtoupper( (string) ( $cad['uf'] ?? '' ) ); $mun = trim( preg_replace( '/[^A-Z0-9 ]+/', ' ', dv_ds_nrm( $cad['municipio'] ?? '' ) ) );
	$o = array( 'cargo' => '', 'titular' => null, 'vice' => null, 'legislativo' => array() );
	if ( ! $uf || ! in_array( $esfera, array( 'M', 'E' ), true ) ) { return $o; }
	$ck = 'dv_cp_el_' . $uf; $m = get_transient( $ck );
	if ( ! is_array( $m ) ) {
		$r = wp_remote_get( 'https://raw.githubusercontent.com/Carlos09091988/aquila-dados/eleitos/eleitos/' . $uf . '.json', array( 'timeout' => 25 ) );
		$m = ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) ? (array) json_decode( wp_remote_retrieve_body( $r ), true ) : array();
		set_transient( $ck, $m, $m ? 7 * DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	}
	$pp = function ( $x ) { return $x ? array( 'nome' => $x[0], 'urna' => $x[1], 'partido' => $x[2] ) : null; };
	if ( $esfera === 'M' && isset( $m[ $mun ] ) ) {
		$d = $m[ $mun ]; $o['cargo'] = 'Prefeito(a)'; $o['eleicao'] = 2024; $o['titular'] = $pp( $d['p'] ?? null ); $o['vice'] = $pp( $d['v'] ?? null );
		$o['legislativo'] = array_map( function ( $c ) { return array( 'urna' => $c[0], 'partido' => $c[1] ); }, (array) ( $d['c'] ?? array() ) );
	} elseif ( $esfera === 'E' && isset( $m['_GOV'] ) ) {
		$o['cargo'] = 'Governador(a)'; $o['eleicao'] = 2022; $o['titular'] = $pp( $m['_GOV']['p'] ?? null ); $o['vice'] = $pp( $m['_GOV']['v'] ?? null );
	}
	return $o;
}

/* ---------- Processos judiciais do órgão ligados a licitações / ao objeto ---------- */
function dv_cp_proc_lic( $nome, $termos, $tj = '' ) {
	$base = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao?nomeParte=' . rawurlencode( $nome ) . '&itensPorPagina=100&pagina=1' . ( $tj ? '&siglaTribunal=' . $tj : '' ) . '&texto=';
	$u = array(); foreach ( $termos as $k => $t ) { $u[ $k ] = $base . rawurlencode( $t ); }
	$b = dv_ds_multi( $u, array(), 15 ); unset( $b['__falhas'] ); $alvo = dv_ds_nrm( $nome ); $o = array();
	foreach ( $b as $k => $body ) {
		$j = $body ? json_decode( $body, true ) : null; if ( ! $j ) { continue; }
		foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
			$dl = array_map( function ( $d ) { return trim( dv_ds_nrm( $d['nome'] ?? '' ) ); }, (array) ( $i['destinatarios'] ?? array() ) ); $dest = implode( ' ; ', $dl );
			if ( strpos( $dest, $alvo ) === false ) { continue; }
			/* órgão público: só processos do tribunal estadual da UF do órgão, ou de outro tribunal quando o nome do destinatário é exatamente o do ente */
			if ( $tj && strtoupper( (string) ( $i['siglaTribunal'] ?? '' ) ) !== $tj && ! in_array( $alvo, $dl, true ) ) { continue; }
			$n = $i['numeroprocessocommascara'] ?? $i['numero_processo'] ?? ''; if ( ! $n || isset( $o[ $n ] ) ) { continue; }
			$cl = dv_ds_nrm( $i['nomeClasse'] ?? '' );
			if ( preg_match( '/TRABALHIST|RECLAMACAO|EXECUCAO FISCAL|PRECATORIO|REQUISICAO DE PEQUENO/', $cl ) ) { continue; }
			$o[ $n ] = array( 'processo' => $n, 'classe' => $i['nomeClasse'] ?? '', 'tribunal' => $i['siglaTribunal'] ?? '', 'orgao' => $i['nomeOrgao'] ?? '', 'termo' => $termos[ $k ],
				'data' => substr( (string) ( $i['data_disponibilizacao'] ?? '' ), 0, 10 ), 'link' => $i['link'] ?? '',
				'grave' => (bool) preg_match( '/IMPROBIDADE|ACAO CIVIL PUBLICA|ACAO POPULAR|MANDADO DE SEGURANCA|PENAL|CRIMINAL|INQUERITO/', $cl ),
				'trecho' => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $i['texto'] ?? '' ) ) ) ), 0, 360 ) );
		}
	}
	$o = array_values( $o ); usort( $o, function ( $a, $b ) { return ( $b['grave'] <=> $a['grave'] ) ?: strcmp( $b['data'], $a['data'] ); } );
	return array_slice( $o, 0, 60 );
}

/* ---------- Imprensa: várias buscas temáticas em paralelo ---------- */
function dv_cp_news( $consultas ) {
	$g = function ( $q ) { return 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419'; };
	$u = array(); foreach ( $consultas as $k => $q ) { $u[ $k ] = $g( $q ); }
	$b = dv_ds_multi( $u, array( 'User-Agent' => 'Mozilla/5.0' ), 10 ); $o = array();
	foreach ( $b as $k => $body ) {
		$o[ $k ] = array(); $vis = array(); if ( ! $body || ! function_exists( 'dv_nt_rss' ) ) { continue; }
		foreach ( dv_nt_rss( $body ) as $i ) { $h = md5( $i['url'] ); if ( isset( $vis[ $h ] ) ) { continue; } $vis[ $h ] = 1; $o[ $k ][] = array( 'titulo' => $i['titulo'], 'fonte' => $i['fonte'], 'url' => $i['url'], 'data' => $i['data'] ); }
		usort( $o[ $k ], function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } ); $o[ $k ] = array_slice( $o[ $k ], 0, 20 );
	}
	return $o;
}

/* Orçamento de tempo: o relatório consulta várias fontes em sequência; quando uma fonte está lenta, as etapas
 * opcionais são puladas (o relatório sai parcial) e cada etapa concluída fica em cache, para a próxima tentativa completar. */
function dv_cp_t0( $set = false ) { static $t0 = 0; if ( $set ) { $t0 = microtime( true ); } return $t0; }
function dv_cp_resta() { return 50 - ( microtime( true ) - dv_cp_t0() ); } // o servidor corta a resposta aos 60 s
function dv_cp_etapa( $chave, $ttl, $fn ) {
	$ck = 'dv_cpe_' . md5( $chave ); $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$r = $fn(); if ( $r !== null ) { set_transient( $ck, $r, $ttl ); } return $r;
}
function dv_comprador( $cnpj, $item ) {
	$ck = 'dv_cp7_' . $cnpj . '_' . md5( $item ); $c = get_transient( $ck ); if ( $c ) { return $c; }
	dv_cp_t0( true ); $pulou = array(); @ignore_user_abort( true ); // se o servidor cortar a resposta, a coleta termina e fica em cache para a próxima tentativa
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
	$ct = dv_cp_contratos( $cnpj ); $ed = dv_cp_etapa( 'ab|' . $cnpj, HOUR_IN_SECONDS, function () use ( $cnpj ) { return dv_cp_abertas( $cnpj ); } );
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
	$D['precos'] = $toks ? ( dv_cp_resta() > 20 ? dv_cp_etapa( 'pr|' . $cnpj . '|' . md5( $item ), 6 * HOUR_IN_SECONDS, function () use ( $cnpj, $compras, $toks ) { return dv_cp_precos( $cnpj, $compras, $toks ); } ) : ( $pulou[] = 'precos' ) && array() ) : array();
	/* cobranças na Justiça contra o órgão (12 meses) */
	$nome = dv_ds_nrm( $cad['razao_social'] ); $munN = dv_ds_nrm( $cad['municipio'] ?? '' ); $tj = $esfera ? 'TJ' . strtoupper( (string) ( $cad['uf'] ?? '' ) ) : '';
	/* secretarias e órgãos do Executivo municipal não têm personalidade jurídica: na Justiça a parte é o Município */
	if ( $esfera === 'M' && $munN && ( preg_match( '/EXECUTIVO/', $nat ) || strpos( $nome, $munN ) === false ) ) { $nome = 'MUNICIPIO DE ' . $munN; }
	$ini = gmdate( 'Y-m-d', strtotime( '-12 months' ) ); $fim = current_time( 'Y-m-d' );
	$bj = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao?nomeParte=' . rawurlencode( $nome ) . '&dataDisponibilizacaoInicio=' . $ini . '&dataDisponibilizacaoFim=' . $fim . '&itensPorPagina=1&pagina=1' . ( $tj ? '&siglaTribunal=' . $tj : '' );
	$q = array( 'total' => $bj );
	foreach ( array( 'cobranca' => 'ação de cobrança', 'monitoria' => 'monitória', 'execucao' => 'execução de título extrajudicial', 'precatorio' => 'precatório', 'rpv' => 'requisição de pequeno valor' ) as $k => $t ) { $q[ $k ] = $bj . '&texto=' . rawurlencode( $t ); }
	if ( dv_cp_resta() > 15 ) {
		$J = dv_cp_etapa( 'ju|' . $nome . '|' . $tj . '|' . $fim, 12 * HOUR_IN_SECONDS, function () use ( $q ) {
			$rj = dv_ds_multi( $q, array(), 25 ); $J = array(); $ok = false;
			foreach ( $rj as $k => $b ) { $j = $b ? json_decode( $b, true ) : null; $J[ $k ] = $j ? (int) ( $j['count'] ?? 0 ) : null; if ( $j ) { $ok = true; } }
			return $ok ? $J : null;
		} );
	} else { $J = null; $pulou[] = 'justica'; }
	$D['justica'] = is_array( $J ) ? $J : array(); $D['justica_nome'] = $nome;
	/* quem governa */
	$D['politicos'] = dv_cp_politicos( $cad, $esfera );
	$tit = $D['politicos']['titular'] ?? null;
	/* imprensa (pagamentos, licitações, gestor, greves) */
	$mun = trim( (string) ( $cad['municipio'] ?? '' ) ); $munT = ucwords( mb_strtolower( $mun ) );
	$alvoN = $esfera === 'M' && $mun ? 'Prefeitura de ' . $munT : ( $esfera === 'E' ? 'Governo do Estado' : $cad['razao_social'] );
	$loc = $esfera === 'M' ? $munT : ( $cad['uf'] ?? '' );
	$it1 = $toks ? ' ' . $item : '';
	$cons = array(
		'pagamento' => '"' . $alvoN . '" (atraso OR atrasa OR calote OR "sem pagamento" OR "não paga" OR dívida OR débito) fornecedores',
		'licitacoes' => '"' . $alvoN . '" (licitação OR pregão OR contrato OR contratação) (irregular OR irregularidade OR suspensa OR suspende OR fraude OR investigação OR TCE OR "Ministério Público" OR superfaturamento OR direcionamento OR cartel OR denúncia)',
		'sindical' => '"' . $alvoN . '" (greve OR paralisação OR sindicato OR protesto) (servidores OR professores OR terceirizados OR trabalhadores)',
	);
	if ( $toks ) { $cons['objeto'] = '"' . $alvoN . '"' . $it1 . ' (licitação OR contrato OR empresa)'; }
	if ( $tit && $esfera === 'M' ) { $cons['gestor'] = '"' . $tit['urna'] . '" ' . $loc . ' (investigação OR operação OR denúncia OR improbidade OR cassação OR "Ministério Público" OR polêmica OR condenado OR processo)'; }
	elseif ( $tit ) { $cons['gestor'] = '"' . $tit['urna'] . '" governador (investigação OR operação OR denúncia OR improbidade OR polêmica OR processo)'; }
	if ( dv_cp_resta() > 12 ) { $N = dv_cp_etapa( 'nw|' . $cnpj . '|' . md5( serialize( $cons ) ), 3 * HOUR_IN_SECONDS, function () use ( $cons ) { return dv_cp_news( $cons ); } ); }
	else { $N = array(); $pulou[] = 'noticias'; }
	$D['noticias'] = $N['pagamento'] ?? array(); $D['noticias_termo'] = $alvoN;
	$D['news'] = array( 'licitacoes' => $N['licitacoes'] ?? array(), 'sindical' => $N['sindical'] ?? array(), 'objeto' => $N['objeto'] ?? array(), 'gestor' => $N['gestor'] ?? array() );
	/* processos ligados a licitações e ao objeto */
	$termos = array( 'licitação', 'pregão', 'improbidade', 'ação popular' ); if ( $toks ) { $termos[] = $item; }
	if ( dv_cp_resta() > 15 ) { $D['proc_lic'] = dv_cp_etapa( 'pl|' . $nome . '|' . $tj . '|' . md5( serialize( $termos ) ), 12 * HOUR_IN_SECONDS, function () use ( $nome, $termos, $tj ) { return dv_cp_proc_lic( $nome, $termos, $tj ); } ); }
	else { $D['proc_lic'] = array(); $pulou[] = 'processos'; }
	/* processos do gestor (criminal / improbidade) */
	$D['gestor_proc'] = array();
	if ( $tit && count( explode( ' ', dv_ds_nrm( $tit['nome'] ) ) ) >= 3 && dv_cp_resta() <= 15 ) { $pulou[] = 'gestor'; }
	elseif ( $tit && count( explode( ' ', dv_ds_nrm( $tit['nome'] ) ) ) >= 3 ) { $g = dv_cp_etapa( 'gp|' . $tit['nome'], 12 * HOUR_IN_SECONDS, function () use ( $tit ) { return dv_ds_djen( dv_ds_nrm( $tit['nome'] ), 2, false ); } ); $D['gestor_proc'] = array_values( array_filter( $g['processos'], function ( $x ) { return in_array( $x['tipo'], array( 'criminal', 'improbidade', 'coletiva' ), true ) || preg_match( '/ELEITORAL|CASSACAO|INVESTIGACAO JUDICIAL/', dv_ds_nrm( $x['classe'] ) ); } ) ); }
	$D['parcial'] = array_values( array_unique( $pulou ) ); $D['segundos'] = (int) round( microtime( true ) - dv_cp_t0() );
	set_transient( $ck, $D, $pulou ? 10 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	return $D;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'dvtools/v1', '/comprador', array( 'methods' => 'GET',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'Informe o CNPJ do órgão (14 números).', array( 'status' => 400 ) ); }
			if ( ! function_exists( 'dv_ds_multi' ) ) { return new WP_Error( 'dv_dep', 'Ferramenta indisponível no momento.', array( 'status' => 503 ) ); }
			set_time_limit( 300 );
			return dv_comprador( $cnpj, mb_substr( sanitize_text_field( (string) $r['item'] ), 0, 120 ) );
		} ) );
} );
