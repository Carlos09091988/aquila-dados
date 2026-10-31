/**
 * Desenvolve — Notícias negativas na imprensa (seção do Raio-X)
 * Rota: GET dvtools/v1/fornecedor/noticias?cnpj=  (só assinantes: dv_tools_perm)
 * Fonte: Google Notícias (RSS público de busca). Filtra por palavras de risco e exige o nome da empresa no texto.
 * Faz parte da consulta do Raio-X: não cobra crédito à parte.
 */

function dv_nt_norm( $s ) {
	$s = remove_accents( (string) $s );
	return trim( preg_replace( '/\s+/', ' ', strtoupper( preg_replace( '/[^A-Za-z0-9 ]/', ' ', $s ) ) ) );
}
/** Tira a forma jurídica e sufixos do nome para buscar como a imprensa escreve. */
function dv_nt_nucleo( $nome ) {
	$n = ' ' . dv_nt_norm( $nome ) . ' ';
	$n = preg_replace( '/\b(EM RECUPERACAO JUDICIAL|RECUPERACAO JUDICIAL|EM LIQUIDACAO|MASSA FALIDA)\b/', ' ', $n );
	$n = preg_replace( '/\b(LTDA|LIMITADA|EIRELI|S A|SA|S S|SS|ME|EPP|MEI|CIA|COMPANHIA|SOCIEDADE SIMPLES|EM|E)\s*$/', ' ', $n );
	$n = preg_replace( '/\b(LTDA|LIMITADA|EIRELI|S A|EPP|ME)\b/', ' ', $n );
	return trim( preg_replace( '/\s+/', ' ', $n ) );
}
/** Nome é distintivo o bastante para buscar sem trazer homônimos? */
function dv_nt_util( $n ) {
	$gen = array( 'SERVICOS', 'SERVICO', 'TERCEIRIZADOS', 'TERCEIRIZACAO', 'COMERCIO', 'CONSTRUCOES', 'CONSTRUTORA', 'ENGENHARIA', 'ALIMENTOS', 'ALIMENTACAO', 'LIMPEZA', 'TRANSPORTES', 'SEGURANCA', 'VIGILANCIA', 'PARTICIPACOES', 'EMPREENDIMENTOS', 'SOLUCOES', 'TECNOLOGIA', 'BRASIL', 'GERAIS', 'DO', 'DA', 'DE', 'DOS', 'DAS', 'E' );
	$p = array_diff( explode( ' ', $n ), $gen );
	$w = count( explode( ' ', $n ) );
	return mb_strlen( $n ) >= 8 && ( ( $w >= 2 && count( $p ) >= 1 ) || $w >= 3 || ( $w === 1 && count( $p ) === 1 && mb_strlen( $n ) >= 9 ) );
}

function dv_nt_riscos() {
	return array(
		'Investigação e polícia' => '/\b(OPERACAO|POLICIA FEDERAL|PF |DEFLAGR|MANDADOS? DE BUSCA|BUSCA E APREENSAO|PRESO|PRISAO|INQUERITO|INVESTIGA)/',
		'Ministério Público e improbidade' => '/\b(MINISTERIO PUBLICO|MPF|MPE|MP DENUNCIA|DENUNCIA|IMPROBIDADE|ACAO CIVIL PUBLICA|GAECO)/',
		'Fraude, cartel e corrupção' => '/\b(FRAUDE|FRAUDA|CARTEL|CONLUIO|CORRUPCAO|PROPINA|DIRECIONAMENTO|SUPERFATURA|SOBREPRECO|DESVIO|LAVAGEM)/',
		'Tribunal de Contas e sanções' => '/\b(TRIBUNAL DE CONTAS|TCU|TCE|IRREGULAR|INIDONE|IMPEDIDA DE LICITAR|SUSPENSA DE LICITAR|MULTA|MULTAD|SANCAO|PUNI|CONDENA|FAVORECIMENTO|SUSPENDE LICITACAO|SUSPENSAO DA LICITACAO)/',
		'Problemas na execução do contrato' => '/\b(RESCIS|ROMPE CONTRATO|SUSPEND|SUSPENS|PARALISA|ABANDON|ATRAS|SALARIOS? ATRASADOS?|SEM PAGAMENTO|SEM SALARIO|GREVE|CALOTE|DEMISS|TRABALHADORES SEM|RECLAMAC|DENUNCI|PROBLEMAS? NA|PRECARI|FALTA DE|INTERROMP)/',
		'Recuperação judicial e dívidas' => '/\b(RECUPERACAO JUDICIAL|FALENCIA|FALIU|DIVIDA|PENHORA|BLOQUEIO DE BENS|BLOQUEIA BENS)/',
		'Trabalho e segurança' => '/\b(TRABALHO ESCRAVO|ANALOGA A ESCRAVIDAO|LISTA SUJA|ACIDENTE DE TRABALHO|MPT|JUSTICA DO TRABALHO)/',
	);
}

function dv_nt_rss( $xml ) {
	$prev = libxml_use_internal_errors( true ); $x = simplexml_load_string( (string) $xml ); libxml_use_internal_errors( $prev );
	$out = array(); if ( ! $x || ! isset( $x->channel->item ) ) { return $out; }
	foreach ( $x->channel->item as $it ) {
		$t = html_entity_decode( (string) $it->title, ENT_QUOTES, 'UTF-8' ); $fonte = (string) $it->source;
		if ( $fonte && substr( $t, -strlen( ' - ' . $fonte ) ) === ' - ' . $fonte ) { $t = substr( $t, 0, -strlen( ' - ' . $fonte ) ); }
		$out[] = array( 'titulo' => $t, 'fonte' => $fonte, 'url' => (string) $it->link, 'data' => gmdate( 'Y-m-d', strtotime( (string) $it->pubDate ) ?: time() ),
			'resumo' => trim( wp_strip_all_tags( html_entity_decode( (string) $it->description, ENT_QUOTES, 'UTF-8' ) ) ) );
	}
	return $out;
}
/** Várias buscas em paralelo (um ano por vez, mais uma focada em termos de risco) para não ficar preso ao limite de resultados do Google. */
function dv_nt_buscar( $termo ) {
	$g = function ( $q ) { return 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419'; };
	$urls = array( 'geral' => $g( '"' . $termo . '"' ),
		'risco' => $g( '"' . $termo . '" (multa OR suspensão OR rescisão OR investigação OR operação OR paralisação OR salários OR denúncia OR condenada OR improbidade OR "Ministério Público" OR TCE OR TCU)' ) );
	$ano = (int) current_time( 'Y' );
	for ( $a = $ano; $a >= $ano - 5; $a-- ) { $urls[ 'a' . $a ] = $g( '"' . $termo . '" after:' . $a . '-01-01 before:' . ( $a + 1 ) . '-01-01' ); }
	$bodies = array();
	if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
		$reqs = array(); foreach ( $urls as $k => $u ) { $reqs[ $k ] = array( 'url' => $u, 'type' => 'GET', 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ), 'options' => array( 'timeout' => 20 ) ); }
		try { $rs = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 20 ) ); } catch ( \Throwable $e ) { $rs = array(); }
		foreach ( $rs as $k => $r ) { if ( is_object( $r ) && isset( $r->status_code ) && $r->status_code === 200 ) { $bodies[ $k ] = $r->body; } }
	} else {
		foreach ( $urls as $k => $u ) { $r = wp_remote_get( $u, array( 'timeout' => 20 ) ); if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) { $bodies[ $k ] = wp_remote_retrieve_body( $r ); } }
	}
	if ( ! $bodies ) { return null; }
	$out = array(); $vistos = array();
	foreach ( $bodies as $b ) { foreach ( dv_nt_rss( $b ) as $it ) { $h = md5( $it['url'] ); if ( isset( $vistos[ $h ] ) ) { continue; } $vistos[ $h ] = 1; $out[] = $it; } }
	return $out;
}

function dv_noticias( $cnpj ) {
	$cnpj = preg_replace( '/\D/', '', (string) $cnpj );
	if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
	$ck = 'dv_nt7_' . $cnpj; $c = empty( $_GET['debug'] ) ? get_transient( $ck ) : false; if ( $c ) { return $c; }
	$r = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 20 ) );
	$j = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $j['razao_social'] ) ) { return array( 'cnpj' => $cnpj, 'termos' => array(), 'itens' => array(), 'aviso' => 'Não foi possível consultar o cadastro da empresa agora. Tente de novo em alguns minutos.' ); }
	$nomes = array();
	$nat = dv_nt_norm( $j['natureza_juridica'] ?? '' ); $mun = dv_nt_norm( $j['municipio'] ?? '' ); $uf = strtoupper( (string) ( $j['uf'] ?? '' ) );
	$publico = preg_match( '/MUNICIP|ESTAD|FEDERAL|UNIAO|AUTARQUIA|FUNDACAO PUBLICA|ORGAO PUBLICO|CONSORCIO PUBLICO|FUNDO PUBLICO|DISTRITO FEDERAL|PODER|MINISTERIO PUBLICO|TRIBUNAL|CAMARA|ASSEMBLEIA/', $nat );
	$UFN = array( 'AC' => 'ACRE', 'AL' => 'ALAGOAS', 'AP' => 'AMAPA', 'AM' => 'AMAZONAS', 'BA' => 'BAHIA', 'CE' => 'CEARA', 'DF' => 'DISTRITO FEDERAL', 'ES' => 'ESPIRITO SANTO', 'GO' => 'GOIAS', 'MA' => 'MARANHAO', 'MT' => 'MATO GROSSO', 'MS' => 'MATO GROSSO DO SUL', 'MG' => 'MINAS GERAIS', 'PA' => 'PARA', 'PB' => 'PARAIBA', 'PR' => 'PARANA', 'PE' => 'PERNAMBUCO', 'PI' => 'PIAUI', 'RJ' => 'RIO DE JANEIRO', 'RN' => 'RIO GRANDE DO NORTE', 'RS' => 'RIO GRANDE DO SUL', 'RO' => 'RONDONIA', 'RR' => 'RORAIMA', 'SC' => 'SANTA CATARINA', 'SP' => 'SAO PAULO', 'SE' => 'SERGIPE', 'TO' => 'TOCANTINS' );
	foreach ( array( $j['razao_social'] ?? '', $j['nome_fantasia'] ?? '' ) as $n ) {
		$k = dv_nt_nucleo( $n ); if ( ! $k ) { continue; }
		/* órgão público com nome genérico (ex.: "Secretaria Municipal da Fazenda"): busca com a cidade ou o estado junto, para não trazer órgãos homônimos de outros lugares */
		if ( $publico ) {
			if ( preg_match( '/MUNICIP/', $nat ) && $mun && strpos( $k, $mun ) === false ) { $k .= ' DE ' . $mun; }
			elseif ( preg_match( '/ESTAD/', $nat ) && isset( $UFN[ $uf ] ) && strpos( $k, $UFN[ $uf ] ) === false ) { $k .= ' DO ESTADO DE ' . $UFN[ $uf ]; }
			if ( ! in_array( $k, $nomes, true ) ) { $nomes[] = $k; } continue;
		}
		if ( dv_nt_util( $k ) && ! in_array( $k, $nomes, true ) ) { $nomes[] = $k; }
	}
	$res = array( 'cnpj' => $cnpj, 'termos' => $nomes, 'cidade' => trim( ( $j['municipio'] ?? '' ) . '/' . ( $j['uf'] ?? '' ), '/' ), 'itens' => array(), 'aviso' => '' );
	if ( ! $nomes ) {
		$res['aviso'] = 'O nome da empresa é muito genérico para uma busca confiável na imprensa (traria notícias de outras empresas). Pesquise manualmente se precisar.';
		set_transient( $ck, $res, DAY_IN_SECONDS ); return $res;
	}
	$vistos = array(); $falhou = false; $res['brutos'] = 0; $res['mencoes'] = array();
	foreach ( $nomes as $n ) {
		$lista = dv_nt_buscar( $n ); if ( $lista === null ) { $falhou = true; continue; }
		$res['brutos'] += count( $lista );
		if ( current_user_can( 'manage_options' ) && ! empty( $_GET['debug'] ) ) { foreach ( $lista as $it ) { $res['debug'][] = $it['data'] . ' | ' . $it['fonte'] . ' | ' . $it['titulo']; } }
		foreach ( $lista as $it ) {
			$txt = dv_nt_norm( $it['titulo'] . ' ' . $it['resumo'] );
			// a busca é por frase exata (entre aspas): o Google só traz matérias que citam o nome no texto
			$cats = array();
			foreach ( dv_nt_riscos() as $cat => $re ) { if ( preg_match( $re, ' ' . $txt ) ) { $cats[] = $cat; } }
			$h = md5( dv_nt_norm( $it['titulo'] ) ); if ( isset( $vistos[ $h ] ) ) { continue; } $vistos[ $h ] = 1;
			if ( ! $cats ) { $res['mencoes'][] = array( 'titulo' => $it['titulo'], 'fonte' => $it['fonte'], 'url' => esc_url_raw( $it['url'] ), 'data' => $it['data'], 'riscos' => array(), 'termo' => $n ); continue; }
			$res['itens'][] = array( 'titulo' => $it['titulo'], 'fonte' => $it['fonte'], 'url' => esc_url_raw( $it['url'] ), 'data' => $it['data'], 'riscos' => $cats, 'termo' => $n );
		}
	}
	usort( $res['itens'], function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$res['itens'] = array_slice( $res['itens'], 0, 60 );
	usort( $res['mencoes'], function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$res['mencoes'] = array_slice( $res['mencoes'], 0, 40 );
	if ( $falhou && ! $res['itens'] ) { $res['aviso'] = 'A busca na imprensa não respondeu agora. Tente consultar de novo em alguns minutos.'; return $res; }
	set_transient( $ck, $res, 12 * HOUR_IN_SECONDS );
	return $res;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'dvtools/v1', '/fornecedor/noticias', array( 'methods' => 'GET',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) { return dv_noticias( $r['cnpj'] ); } ) );
} );
