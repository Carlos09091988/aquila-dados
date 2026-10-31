/**
 * Desenvolve — Dossiê completo da empresa
 * Junta num relatório só: cadastro, sócios, grupo econômico (sócios em comum, holdings, contatos
 * e endereço em comum, possíveis parentes), dívida ativa da União e solidários, risco das empresas
 * do grupo, sanções, contratos públicos, publicações em diários oficiais e notícias negativas.
 * Rota: POST desenvolve/v1/dossie {cnpj}  — cobra dv_cfg('creditos_dossie') créditos (padrão 3).
 * Depende de: núcleo (dv_usar, dv_cfg), ferramentas (rotas dvtools/v1, dv_tools_perm), notícias (dv_noticias).
 */

define( 'DV_DADOS', 'https://carlos09091988.github.io/aquila-dados/' );

function dv_ds_gz( $rel ) {
	$r = wp_remote_get( DV_DADOS . $rel, array( 'timeout' => 25 ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return array(); }
	$b = wp_remote_retrieve_body( $r );
	$t = substr( $rel, -3 ) === '.gz' ? @gzdecode( $b ) : $b;
	return $t ? (array) json_decode( $t, true ) : array();
}
function dv_ds_shard( $pasta, $k ) { $o = dv_ds_gz( $pasta . '/' . substr( hash( 'sha256', $k ), 0, 3 ) . '.json.gz' ); $v = $o[ $k ] ?? array(); unset( $o ); return $v; }
function dv_ds_nrm( $s ) { return trim( preg_replace( '/\s+/', ' ', strtoupper( remove_accents( (string) $s ) ) ) ); }
function dv_ds_dv( $b ) { // completa o CNPJ da matriz a partir da raiz
	$b = substr( $b, 0, 8 ) . '0001'; $c = array_map( 'intval', str_split( $b ) ); $w = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
	$s = 0; for ( $i = 0; $i < 12; $i++ ) { $s += $c[ $i ] * $w[ $i ]; } $d1 = $s % 11 < 2 ? 0 : 11 - $s % 11; $c[] = $d1; array_unshift( $w, 6 );
	$s = 0; for ( $i = 0; $i < 13; $i++ ) { $s += $c[ $i ] * $w[ $i ]; } $d2 = $s % 11 < 2 ? 0 : 11 - $s % 11;
	return $b . $d1 . $d2;
}
function dv_ds_pgfn( $cnpj ) {
	$o = dv_ds_gz( 'pgfn/' . substr( $cnpj, 0, 3 ) . '.json.gz' ); $r = $o[ $cnpj ] ?? null; unset( $o );
	if ( ! $r ) { return null; }
	return array( 'total' => (float) $r[0], 'previdenciaria' => (float) $r[1], 'nao_previdenciaria' => (float) $r[2], 'fgts' => (float) $r[3],
		'em_cobranca' => (float) $r[4], 'inscricoes' => (int) $r[5], 'ajuizadas' => (int) $r[6], 'tipos' => $r[7] ?? '',
		'solidarios' => array_map( function ( $x ) { return array( 'doc' => $x[0], 'nome' => $x[1], 'tipo' => $x[2], 'valor' => (float) $x[3], 'inscricoes' => (int) $x[4] ); }, (array) ( $r[8] ?? array() ) ) );
}
function dv_ds_interno( $rota, $params ) {
	$q = new WP_REST_Request( 'GET', $rota ); $q->set_query_params( $params );
	$r = rest_do_request( $q );
	return $r->is_error() ? null : $r->get_data();
}


/* ---------- Requisições em paralelo ---------- */
function dv_ds_multi( $urls, $headers = array(), $timeout = 25 ) {
	$out = array_fill_keys( array_keys( $urls ), null );
	if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
		$reqs = array();
		foreach ( $urls as $k => $u ) { $reqs[ $k ] = array( 'url' => $u, 'type' => 'GET', 'headers' => $headers + array( 'User-Agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)', 'Accept' => 'application/json' ), 'options' => array( 'timeout' => $timeout, 'connect_timeout' => 10 ) ); }
		try { $res = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => $timeout ) ); } catch ( \Throwable $e ) { $res = array(); }
		foreach ( $res as $k => $r ) { if ( is_object( $r ) && isset( $r->status_code ) && $r->status_code === 200 ) { $out[ $k ] = $r->body; } }
		return $out;
	}
	foreach ( $urls as $k => $u ) { $r = wp_remote_get( $u, array( 'timeout' => $timeout, 'headers' => $headers ) ); if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) { $out[ $k ] = wp_remote_retrieve_body( $r ); } }
	return $out;
}

/* ---------- Contratos no PNCP (todas as esferas) ---------- */
function dv_ds_pncp( $cnpj, $nome = '', $filiais = array() ) {
	$raiz = substr( $cnpj, 0, 8 ); $consultas = array( $cnpj ); if ( $nome ) { $consultas[] = '"' . $nome . '"'; }
	foreach ( array_slice( $filiais, 0, 20 ) as $f ) { if ( $f !== $cnpj ) { $consultas[] = $f; } }
	$itens = array(); $vistos = array(); $fil = array();
	foreach ( $consultas as $q ) {
		$base = 'https://pncp.gov.br/api/search/?q=' . rawurlencode( $q ) . '&tipos_documento=contrato&ordenacao=-data&tam_pagina=100&pagina=';
		$body = null; for ( $t = 0; $t < 2 && ! $body; $t++ ) { $x = dv_ds_multi( array( 1 => $base . '1' ), array(), 30 ); $body = $x[1]; }
		$j1 = $body ? json_decode( $body, true ) : null; if ( ! $j1 ) { continue; }
		$pags = array( $j1 ); $n = min( 5, (int) ceil( (int) ( $j1['total'] ?? 0 ) / 100 ) );
		for ( $pg = 2; $pg <= $n; $pg++ ) { $x = dv_ds_multi( array( $pg => $base . $pg ), array(), 30 ); if ( $x[ $pg ] ) { $pags[] = json_decode( $x[ $pg ], true ); } }
		foreach ( $pags as $j ) {
			foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
				$ni = preg_replace( '/\D/', '', (string) ( $i['fornecedor_ni'] ?? '' ) );
				if ( $ni && substr( $ni, 0, 8 ) !== $raiz ) { continue; } // só contratos da empresa (matriz ou filial)
				$k = $i['numero_controle_pncp'] ?? $i['id'] ?? ''; if ( isset( $vistos[ $k ] ) || ! empty( $i['cancelado'] ) ) { continue; } $vistos[ $k ] = 1;
				if ( $ni ) { $fil[ $ni ] = ( $fil[ $ni ] ?? 0 ) + 1; }
				$itens[] = array( 'titulo' => $i['title'] ?? '', 'objeto' => mb_substr( trim( (string) ( $i['description'] ?? '' ) ), 0, 220 ), 'orgao' => $i['orgao_nome'] ?? '',
					'esfera' => $i['esfera_nome'] ?? '', 'uf' => $i['uf'] ?? '', 'municipio' => $i['municipio_nome'] ?? '', 'modalidade' => $i['modalidade_licitacao_nome'] ?? '',
					'valor' => (float) ( $i['valor_global'] ?? 0 ), 'assinatura' => $i['data_assinatura'] ?? '', 'inicio' => $i['data_inicio_vigencia'] ?? '', 'fim' => $i['data_fim_vigencia'] ?? '',
					'fornecedor' => $ni, 'link' => ! empty( $i['item_url'] ) ? 'https://pncp.gov.br/app' . $i['item_url'] : '' );
			}
		}
	}
	return array( 'total' => count( $itens ), 'itens' => $itens, 'por_unidade' => $fil );
}

/* ---------- Processos judiciais (Diário de Justiça Eletrônico Nacional — CNJ) ---------- */
function dv_ds_classe( $c, $trib, $dest ) {
	$c = dv_ds_nrm( $c );
	if ( preg_match( '/PENAL|CRIMINAL|CRIME|INQUERITO|PRISAO|HABEAS|DENUNCIA|TERMO CIRCUNSTANCIADO|MEDIDAS? CAUTELAR(ES)? (CRIMINA|PENA)|BUSCA E APREENSAO CRIMINAL|EXECUCAO DA PENA|RESTITUICAO DE COISAS APREENDIDAS|SEQUESTRO/', $c ) ) { return 'criminal'; }
	if ( preg_match( '/MINISTERIO PUBLICO DO TRABALHO/', $dest ) || preg_match( '/ACAO CIVIL (PUBLICA|COLETIVA)/', $c ) ) { return 'coletiva'; }
	if ( preg_match( '/IMPROBIDADE/', $c ) ) { return 'improbidade'; }
	if ( preg_match( '/FALENCIA|RECUPERACAO JUDICIAL|INSOLVENCIA/', $c ) ) { return 'falencia'; }
	if ( preg_match( '/^TRT|^TST/', $trib ) || preg_match( '/TRABALHIST/', $c ) ) { return preg_match( '/EXECUCAO|CUMPRIMENTO/', $c ) ? 'trab_execucao' : 'trabalhista'; }
	if ( preg_match( '/EXECUCAO FISCAL/', $c ) ) { return 'execucao_fiscal'; }
	if ( preg_match( '/EXECUCAO|CUMPRIMENTO DE SENTENCA|MONITORIA|COBRANCA/', $c ) ) { return 'execucao'; }
	return 'outros';
}
function dv_ds_djen( $nome, $paginas = 3, $so_criminal = false ) {
	$base = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao?nomeParte=' . rawurlencode( $nome ) . '&itensPorPagina=100&pagina=';
	$urls = array(); for ( $p = 1; $p <= $paginas; $p++ ) { $urls[ $p ] = $base . $p; }
	$b = dv_ds_multi( $urls ); $alvo = dv_ds_nrm( $nome ); $itens = array(); $vistos = array(); $count = null;
	foreach ( $b as $body ) {
		$j = $body ? json_decode( $body, true ) : null; if ( ! $j ) { continue; } $count = $count ?? (int) ( $j['count'] ?? 0 );
		foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
			$dest = implode( ' ; ', array_map( function ( $d ) { return dv_ds_nrm( $d['nome'] ?? '' ); }, (array) ( $i['destinatarios'] ?? array() ) ) );
			if ( strpos( $dest, $alvo ) === false ) { continue; }
			$proc = $i['numeroprocessocommascara'] ?? $i['numero_processo'] ?? ''; $tipo = dv_ds_classe( $i['nomeClasse'] ?? '', (string) ( $i['siglaTribunal'] ?? '' ), $dest );
			if ( $so_criminal && $tipo !== 'criminal' ) { continue; }
			if ( isset( $vistos[ $proc ] ) ) { continue; } $vistos[ $proc ] = 1;
			$itens[] = array( 'tipo' => $tipo, 'processo' => $proc, 'classe' => $i['nomeClasse'] ?? '', 'tribunal' => $i['siglaTribunal'] ?? '', 'orgao' => $i['nomeOrgao'] ?? '',
				'data' => substr( (string) ( $i['data_disponibilizacao'] ?? '' ), 0, 10 ), 'link' => $i['link'] ?? '',
				'trecho' => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $i['texto'] ?? '' ) ) ) ), 0, 400 ) );
		}
	}
	return array( 'publicacoes' => $count, 'processos' => $itens );
}
/* volume de publicações por mês nos últimos 12 meses (tendência) */
function dv_ds_djen_mensal( $nome ) {
	$urls = array(); $hoje = current_time( 'Y-m-01' );
	for ( $m = 11; $m >= 0; $m-- ) {
		$ini = gmdate( 'Y-m-01', strtotime( "$hoje -$m months" ) ); $fim = gmdate( 'Y-m-t', strtotime( $ini ) );
		$urls[ substr( $ini, 0, 7 ) ] = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao?nomeParte=' . rawurlencode( $nome ) . '&dataDisponibilizacaoInicio=' . $ini . '&dataDisponibilizacaoFim=' . $fim . '&itensPorPagina=1&pagina=1';
	}
	$b = dv_ds_multi( $urls ); $o = array();
	foreach ( $b as $k => $body ) { $j = $body ? json_decode( $body, true ) : null; $o[ $k ] = $j ? (int) ( $j['count'] ?? 0 ) : null; }
	return $o;
}

/* ---------- Pessoas politicamente expostas (CGU) ---------- */
function dv_ds_pep( $nome ) {
	if ( ! defined( 'AQ_TRANSP_KEY' ) || ! AQ_TRANSP_KEY ) { return null; }
	$r = wp_remote_get( 'https://api.portaldatransparencia.gov.br/api-de-dados/peps?nome=' . rawurlencode( $nome ) . '&pagina=1', array( 'timeout' => 20, 'headers' => array( 'chave-api-dados' => AQ_TRANSP_KEY, 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return null; }
	$alvo = dv_ds_nrm( $nome ); $o = array();
	foreach ( (array) json_decode( wp_remote_retrieve_body( $r ), true ) as $p ) {
		if ( dv_ds_nrm( $p['nome'] ?? '' ) !== $alvo ) { continue; }
		$o[] = array( 'funcao' => $p['descricao_funcao'] ?? $p['descricaoFuncao'] ?? '', 'orgao' => $p['nome_orgao'] ?? $p['nomeOrgao'] ?? '', 'inicio' => $p['dt_inicio_exercicio'] ?? $p['dataInicioExercicio'] ?? '', 'fim' => $p['dt_fim_exercicio'] ?? $p['dataFimExercicio'] ?? '', 'cpf' => $p['cpf'] ?? '' );
	}
	return $o;
}

/* ---------- Diários oficiais: DOU + municipais, e processos administrativos/sanções em andamento ---------- */
function dv_ds_diarios( $cnpj, $nome ) {
	$fmt = preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $cnpj ); $desde = gmdate( 'Y-m-d', strtotime( '-24 months' ) );
	$urls = array(
		'qd' => add_query_arg( array( 'querystring' => rawurlencode( '"' . $fmt . '" | "' . $cnpj . '"' . ( $nome ? ' | "' . $nome . '"' : '' ) ), 'published_since' => $desde, 'size' => 50, 'excerpt_size' => 500, 'number_of_excerpts' => 2, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' ),
		'dou1' => 'https://www.in.gov.br/consulta/-/buscar/dou?q=' . rawurlencode( '"' . $fmt . '"' ) . '&s=todos&exactDate=personalizado&sortType=0&delta=75&publishFrom=' . gmdate( 'd-m-Y', strtotime( $desde ) ) . '&publishTo=' . current_time( 'd-m-Y' ),
	);
	if ( $nome ) { $urls['dou2'] = 'https://www.in.gov.br/consulta/-/buscar/dou?q=' . rawurlencode( '"' . $nome . '"' ) . '&s=todos&exactDate=personalizado&sortType=0&delta=75&publishFrom=' . gmdate( 'd-m-Y', strtotime( $desde ) ) . '&publishTo=' . current_time( 'd-m-Y' ); }
	$b = dv_ds_multi( $urls, array( 'Accept' => 'text/html,application/json' ) ); $itens = array(); $vistos = array();
	$j = $b['qd'] ? json_decode( $b['qd'], true ) : null;
	foreach ( (array) ( $j['gazettes'] ?? array() ) as $g ) {
		$tr = implode( ' … ', array_map( function ( $e ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e ) ) ); }, (array) ( $g['excerpts'] ?? array() ) ) );
		$itens[] = array( 'data' => $g['date'] ?? '', 'fonte' => trim( ( $g['territory_name'] ?? '' ) . '/' . ( $g['state_code'] ?? '' ), '/' ), 'tipo' => 'Municipal', 'trecho' => mb_substr( $tr, 0, 700 ), 'link' => $g['url'] ?? '' );
	}
	foreach ( array( 'dou1', 'dou2' ) as $k ) {
		if ( empty( $b[ $k ] ) || ! preg_match( '#<script[^>]*id="_br_com_seatecnologia_in_buscadou_BuscaDouPortlet_params"[^>]*>(.*?)</script>#s', $b[ $k ], $m ) ) { continue; }
		$jj = json_decode( trim( $m[1] ), true );
		foreach ( (array) ( $jj['jsonArray'] ?? array() ) as $it ) {
			$u = ! empty( $it['urlTitle'] ) ? 'https://www.in.gov.br/web/dou/-/' . $it['urlTitle'] : ''; if ( isset( $vistos[ $u ] ) ) { continue; } $vistos[ $u ] = 1;
			$d = (string) ( $it['pubDate'] ?? '' ); $data = preg_match( '#^(\d{2})/(\d{2})/(\d{4})#', $d, $x ) ? "$x[3]-$x[2]-$x[1]" : '';
			$itens[] = array( 'data' => $data, 'fonte' => 'Diário Oficial da União' . ( ! empty( $it['pubName'] ) ? ' (' . $it['pubName'] . ')' : '' ), 'tipo' => 'União',
				'trecho' => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( ( $it['title'] ?? '' ) . ' — ' . ( $it['content'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) ), 0, 700 ), 'link' => $u );
		}
	}
	usort( $itens, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$re = '/PENALIDADE|PUNITIVO|DEFESA PREVIA|APURACAO DE RESPONSABILIDADE|PROCESSO (ADMINISTRATIVO )?(SANCIONATORIO|PUNITIVO|DE RESPONSABILIZACAO|DE APURACAO)|IMPEDIMENTO DE LICITAR|IMPEDID[AO] DE LICITAR|INIDONE|SUSPENSAO TEMPORARIA|APLICA(CAO|R) (DE |A )?MULTA|MULTA (CONTRATUAL|MORATORIA|COMPENSATORIA)|RESCISAO (UNILATERAL|CONTRATUAL|DO CONTRATO)|ADVERTENCIA|SANCAO|SANCIONATORIO|INADIMPLEMENTO|NOTIFICACAO (EXTRAJUDICIAL|PARA (APRESENTAR )?DEFESA)|INTIMACAO PARA (APRESENTAR )?DEFESA|DESCUMPRIMENTO CONTRATUAL/';
	foreach ( $itens as &$i ) { $t = dv_ds_nrm( $i['trecho'] ); $i['alerta'] = (bool) preg_match( $re, $t ); } unset( $i );
	return $itens;
}

function dv_dossie_dados( $cnpj ) {
	$ck = 'dv_ds4_' . $cnpj; $c = get_transient( $ck ); if ( $c ) { return $c; }
	$t0 = microtime( true );
	$r = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 25 ) );
	$cad = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $cad['razao_social'] ) ) { return new WP_Error( 'dv_cnpj', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
	$raiz = substr( $cnpj, 0, 8 );
	$D = array( 'cnpj' => $cnpj, 'gerado_em' => current_time( 'mysql' ), 'cadastro' => $cad, 'meta' => dv_ds_gz( 'meta.json' ) ?: null );

	/* Grupo econômico */
	$emp = array();
	$add = function ( $basico, $razao, $via, $detalhe = '' ) use ( &$emp, $raiz ) {
		if ( ! $basico || $basico === $raiz ) { return; }
		if ( ! isset( $emp[ $basico ] ) ) { if ( count( $emp ) >= 60 ) { return; } $emp[ $basico ] = array( 'cnpj' => dv_ds_dv( $basico ), 'razao' => $razao ?: 'CNPJ raiz ' . $basico, 'vias' => array() ); }
		$v = $via . ( $detalhe ? ': ' . $detalhe : '' );
		if ( count( $emp[ $basico ]['vias'] ) < 5 && ! in_array( $v, $emp[ $basico ]['vias'], true ) ) { $emp[ $basico ]['vias'][] = $v; }
	};
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) {
		$doc = preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) );
		$k = strlen( $doc ) === 14 ? 'PJ|' . $doc : dv_ds_nrm( $s['nome_socio'] ?? '' ) . '|' . $doc;
		foreach ( dv_ds_shard( 'socios', $k ) as $e ) { $add( $e[0], $e[1], 'Sócio em comum', ( $s['nome_socio'] ?? '' ) . ( $e[2] ? ' (' . $e[2] . ')' : '' ) ); }
		if ( strlen( $doc ) === 14 ) { $add( substr( $doc, 0, 8 ), $s['nome_socio'] ?? '', 'Sócia pessoa jurídica (holding)' ); }
	}
	foreach ( dv_ds_shard( 'socios', 'PJ|' . $cnpj ) as $e ) { $add( $e[0], $e[1], 'Participação da empresa', $e[2] ?? '' ); }
	$tels = array_filter( array( preg_replace( '/\D/', '', (string) ( $cad['ddd_telefone_1'] ?? '' ) ), preg_replace( '/\D/', '', (string) ( $cad['ddd_telefone_2'] ?? '' ) ) ) );
	foreach ( $tels as $t ) { if ( strlen( $t ) >= 10 ) { foreach ( dv_ds_shard( 'vinc', 'T|' . $t ) as $e ) { $add( $e[0], $e[1], 'Mesmo telefone', $t ); } } }
	if ( ! empty( $cad['email'] ) ) { foreach ( dv_ds_shard( 'vinc', 'E|' . strtolower( trim( $cad['email'] ) ) ) as $e ) { $add( $e[0], $e[1], 'Mesmo e-mail' ); } }
	$cep = preg_replace( '/\D/', '', (string) ( $cad['cep'] ?? '' ) ); $num = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $cad['numero'] ?? '' ) ) );
	$comp = preg_replace( '/[^A-Z0-9]/', '', dv_ds_nrm( $cad['complemento'] ?? '' ) );
	if ( strlen( $cep ) === 8 && $num !== '' ) { foreach ( dv_ds_shard( 'vinc', 'A|' . $cep . '|' . $num . '|' . $comp ) as $e ) { $add( $e[0], $e[1], 'Mesmo endereço' ); } }
	$parentes = array(); $PART = array( 'DE', 'DA', 'DO', 'DOS', 'DAS', 'DI', 'DEL', 'FILHO', 'FILHA', 'JUNIOR', 'NETO', 'NETA', 'SOBRINHO', 'SEGUNDO', 'TERCEIRO' );
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) {
		if ( strlen( preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) ) ) === 14 ) { continue; }
		$t = array_values( array_filter( explode( ' ', preg_replace( '/[^A-Z ]/', ' ', dv_ds_nrm( $s['nome_socio'] ?? '' ) ) ), function ( $x ) use ( $PART ) { return strlen( $x ) > 1 && ! in_array( $x, $PART, true ); } ) );
		if ( count( $t ) < 3 ) { continue; }
		$sob = $t[ count( $t ) - 2 ] . ' ' . $t[ count( $t ) - 1 ];
		foreach ( dv_ds_shard( 'vinc', 'S|' . $sob ) as $e ) {
			$nome = explode( '|', (string) ( $e[2] ?? '' ) )[0];
			if ( $nome === dv_ds_nrm( $s['nome_socio'] ) ) { continue; }
			$parentes[] = array( 'socio' => $s['nome_socio'], 'sobrenome' => $sob, 'pessoa' => $nome, 'empresa' => $e[1], 'cnpj' => dv_ds_dv( $e[0] ), 'qualificacao' => $e[3] ?? '' );
			if ( count( $parentes ) >= 40 ) { break 2; }
		}
	}
	/* Risco de cada empresa do grupo (dívida ativa da União) — até 25 */
	$alvo = array_slice( array_keys( $emp ), 0, 30 ); $sh = array();
	foreach ( $alvo as $b ) { $sh[ substr( $emp[ $b ]['cnpj'], 0, 3 ) ] = DV_DADOS . 'pgfn/' . substr( $emp[ $b ]['cnpj'], 0, 3 ) . '.json.gz'; }
	$bodies = dv_ds_multi( $sh, array(), 30 );
	foreach ( $alvo as $b ) { $emp[ $b ]['pgfn'] = 0; }
	foreach ( array_keys( $bodies ) as $k3 ) {
		if ( empty( $bodies[ $k3 ] ) ) { continue; }
		$o = json_decode( (string) @gzdecode( $bodies[ $k3 ] ), true ); $bodies[ $k3 ] = null;
		foreach ( $alvo as $b ) { if ( substr( $emp[ $b ]['cnpj'], 0, 3 ) === $k3 && isset( $o[ $emp[ $b ]['cnpj'] ] ) ) { $emp[ $b ]['pgfn'] = (float) $o[ $emp[ $b ]['cnpj'] ][0]; } }
		unset( $o );
	}
	unset( $bodies );
	uasort( $emp, function ( $a, $b ) { return count( $b['vias'] ) <=> count( $a['vias'] ) ?: ( $b['pgfn'] ?? 0 ) <=> ( $a['pgfn'] ?? 0 ); } );
	$D['grupo'] = array_values( $emp ); $D['parentes'] = $parentes;

	/* Dívida ativa da União e solidários */
	$D['pgfn'] = dv_ds_pgfn( $cnpj );

	/* Ferramentas já existentes (sanções, contratos, certidões, diários, lista suja) */
	$D['raiox'] = dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cnpj ) );
	$D['federal'] = dv_ds_interno( '/dvtools/v1/fornecedor/federal', array( 'cnpj' => $cnpj ) );
	$D['extras'] = dv_ds_interno( '/dvtools/v1/fornecedor/extras', array( 'cnpj' => $cnpj ) );
	$D['diarios'] = dv_ds_interno( '/dvtools/v1/fornecedor/diarios', array( 'cnpj' => $cnpj ) );

	/* Contratos públicos (PNCP, todas as esferas) */
	$nucleoC = function_exists( 'dv_nt_nucleo' ) ? dv_nt_nucleo( $cad['razao_social'] ) : '';
	$filiais = function_exists( 'dv_capt_filiais' ) ? dv_capt_filiais( $cnpj ) : array();
	$D['pncp'] = dv_ds_pncp( $cnpj, ( $nucleoC && count( explode( ' ', $nucleoC ) ) >= 2 ) ? $nucleoC : '', $filiais );
	$D['filiais'] = count( $filiais );

	/* Processos judiciais: empresa (todos os tipos) e sócios pessoa física (só criminais) */
	$nomeEmp = dv_ds_nrm( $cad['razao_social'] ); $nomeEmp = trim( preg_replace( '/\s+(EM RECUPERACAO JUDICIAL)$/', '', $nomeEmp ) );
	$D['judicial'] = array( 'empresa' => dv_ds_djen( $nomeEmp, 3 ), 'mensal' => dv_ds_djen_mensal( $nomeEmp ), 'socios' => array() );
	$D['pep'] = array();
	foreach ( array_slice( (array) ( $cad['qsa'] ?? array() ), 0, 8 ) as $s ) {
		$doc = preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) ); if ( strlen( $doc ) === 14 ) { continue; }
		$nm = dv_ds_nrm( $s['nome_socio'] ?? '' ); if ( count( explode( ' ', $nm ) ) < 3 ) { continue; }
		$cr = dv_ds_djen( $nm, 2, true ); $D['judicial']['socios'][] = array( 'nome' => $s['nome_socio'], 'processos' => $cr['processos'] );
		$pep = dv_ds_pep( $nm ); if ( $pep ) { $D['pep'][] = array( 'nome' => $s['nome_socio'], 'cargos' => $pep ); }
	}

	/* Diários oficiais (DOU e municipais, 24 meses) */
	$nucleo = function_exists( 'dv_nt_nucleo' ) ? dv_nt_nucleo( $cad['razao_social'] ) : ''; if ( $nucleo && function_exists( 'dv_nt_util' ) && ! dv_nt_util( $nucleo ) ) { $nucleo = ''; }
	$D['publicacoes'] = dv_ds_diarios( $cnpj, $nucleo );

	/* Notícias negativas */
	$D['noticias'] = function_exists( 'dv_noticias' ) ? dv_noticias( $cnpj ) : null;
	if ( is_wp_error( $D['noticias'] ) ) { $D['noticias'] = null; }

	$D['tempo'] = round( microtime( true ) - $t0, 1 );
	set_transient( $ck, $D, 12 * HOUR_IN_SECONDS );
	return $D;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/dossie', array( 'methods' => 'POST',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'Informe um CNPJ com 14 números.', array( 'status' => 400 ) ); }
			$uid = get_current_user_id(); $n = max( 1, (int) ( dv_cfg( 'creditos_dossie' ) ?: 3 ) );
			if ( ! dv_is_master( $uid ) && dv_saldo( $uid )['total'] < $n ) {
				global $wpdb; // se já pagou este dossiê nos últimos 30 dias, deixa reabrir
				$ja = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_uso WHERE user_id=%d AND chave=%s AND criado > %s", $uid,
					substr( hash( 'sha256', 'dossie|' . strtolower( 'dossie|' . $cnpj . '|1' ) ), 0, 64 ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
				if ( ! $ja ) { return new WP_Error( 'dv_sem_credito', 'O dossiê completo usa ' . $n . ' créditos e seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			}
			set_time_limit( 120 );
			$D = dv_dossie_dados( $cnpj ); if ( is_wp_error( $D ) ) { return $D; }
			for ( $i = 1; $i <= $n; $i++ ) { $u = dv_usar( $uid, 'dossie', 'dossie|' . $cnpj . '|' . $i, $i === 1 ? ( $D['cadastro']['razao_social'] ?? $cnpj ) : '' ); if ( is_wp_error( $u ) ) { return $u; } }
			$D['saldo'] = dv_saldo( $uid );
			return $D;
		} ) );
} );
