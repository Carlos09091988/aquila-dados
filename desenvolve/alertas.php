/**
 * Desenvolve — Alerta diário de oportunidades (licitações abertas no PNCP)
 * Buscas salvas por palavras, estados, valor e palavras a excluir. Todo dia de manhã, e-mail com as
 * licitações novas, com botão para mandar direto para a Gestão de licitações. Incluso na assinatura.
 */

define( 'DV_AL_MAX', 10 );

function dv_al_lista( $uid ) { $l = json_decode( get_user_meta( $uid, 'dv_alertas', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_al_salvar_lista( $uid, $l ) { update_user_meta( $uid, 'dv_alertas', wp_slash( wp_json_encode( array_values( $l ), JSON_UNESCAPED_UNICODE ) ) ); }
function dv_al_ativo( $uid ) { return function_exists( 'dv_is_master' ) && ( dv_is_master( $uid ) || ( function_exists( 'dv_status' ) && dv_status( $uid ) === 'ativo' ) ); }
function dv_al_norm( $s ) { return strtoupper( remove_accents( (string) $s ) ); }

/** Busca no PNCP as licitações abertas que batem com o alerta, publicadas desde $desde (Y-m-d). */
/** A busca do PNCP traz qualquer edital com UMA das palavras ("limpeza predial" trazia "manutenção predial"); aqui exigimos todas as palavras do termo */
function dv_al_relevante( $termo, $nd ) {
	$stop = array( 'DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM', 'PARA', 'COM', 'A', 'O', 'AS', 'OS', 'NA', 'NO', 'POR' );
	foreach ( preg_split( '/[^A-Z0-9]+/', dv_al_norm( $termo ) ) as $w ) {
		if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
		$raiz = strlen( $w ) > 6 ? substr( $w, 0, max( 5, strlen( $w ) - 3 ) ) : rtrim( $w, 'S' );
		if ( strpos( $nd, $raiz ) === false ) { return false; }
	}
	return true;
}
/** Serviço ou compra, pelo texto do objeto (mesma regra do Buscador) */
function dv_al_tipo( $desc ) {
	$n = strtolower( remove_accents( (string) $desc ) );
	$serv = (bool) preg_match( '/servic|prestacao de|contratacao de empresa|locacao|manutencao|obra|engenharia|terceiriz|mao de obra|limpeza e conservacao|vigilancia|seguranca patrimonial|transporte de|fornecimento de alimentacao|refeic|coleta de/', $n );
	$comp = (bool) preg_match( '/^(aquisicao|compra|fornecimento|registro de precos para (a )?(eventual |futura )*(aquisicao|compra|fornecimento))|aquisicao de|materia(l|is) (de|para|medic|hospitalar|permanente|consumo)|equipamentos? |generos|medicament|insumos/', $n );
	if ( $serv && ! preg_match( '/^(aquisicao|compra)/', $n ) ) { return 'servico'; }
	if ( $comp ) { return 'compra'; }
	return $serv ? 'servico' : 'compra';
}
/* ---------- Diários oficiais (municípios fora do PNCP): Querido Diário + DOU seção 3 ---------- */
function dv_al_uf_nome( $n ) {
	static $m = array( 'ACRE'=>'AC','ALAGOAS'=>'AL','AMAPA'=>'AP','AMAZONAS'=>'AM','BAHIA'=>'BA','CEARA'=>'CE','DISTRITO FEDERAL'=>'DF','ESPIRITO SANTO'=>'ES','GOIAS'=>'GO','MARANHAO'=>'MA','MATO GROSSO'=>'MT','MATO GROSSO DO SUL'=>'MS','MINAS GERAIS'=>'MG','PARA'=>'PA','PARAIBA'=>'PB','PARANA'=>'PR','PERNAMBUCO'=>'PE','PIAUI'=>'PI','RIO DE JANEIRO'=>'RJ','RIO GRANDE DO NORTE'=>'RN','RIO GRANDE DO SUL'=>'RS','RONDONIA'=>'RO','RORAIMA'=>'RR','SANTA CATARINA'=>'SC','SAO PAULO'=>'SP','SERGIPE'=>'SE','TOCANTINS'=>'TO' );
	$n = trim( preg_replace( '/^(ESTADO|GOVERNO) D[OAE]S?\s+/', '', dv_al_norm( $n ) ) ); return $m[ $n ] ?? '';
}
/** Data da sessão: a primeira data futura (até 120 dias) citada no texto, com hora se houver */
function dv_al_dt_sessao( $t ) {
	$hoje = current_time( 'Y-m-d' ); $max = gmdate( 'Y-m-d', strtotime( $hoje . ' +120 days' ) ); $c = array();
	$mes = array( 'JANEIRO'=>1,'FEVEREIRO'=>2,'MARCO'=>3,'ABRIL'=>4,'MAIO'=>5,'JUNHO'=>6,'JULHO'=>7,'AGOSTO'=>8,'SETEMBRO'=>9,'OUTUBRO'=>10,'NOVEMBRO'=>11,'DEZEMBRO'=>12 );
	$n = dv_al_norm( $t ); $hr = '(?:[^0-9]{0,25}?\b(\d{1,2})\s*(?:HORAS|H|:)\s*(?:E\s*)?(\d{2})?)?';
	$add = function ( $y, $m, $d, $x, $pos ) use ( &$c, $n ) {
		if ( ! checkdate( (int) $m, (int) $d, (int) $y ) ) { return; }
		$h = isset( $x[4] ) && $x[4][0] !== '' && (int) $x[4][0] < 24 ? sprintf( '%02d:%02d', $x[4][0], (int) ( $x[5][0] ?? 0 ) ) : '';
		/* data perto de "abertura/sessão/disputa" é a da sessão; "edital disponível a partir de…" não é */
		$ant = substr( $n, max( 0, $pos - 110 ), min( 110, $pos ) );
		$pref = (bool) preg_match( '/ABERTURA|SESSAO|REALIZA|DISPUTA|LANCES|CERTAME|JULGAMENTO|ENTREGA DOS ENVELOPES|RECEBIMENTO DAS PROPOSTAS ATE|ACOLHIMENTO/', $ant );
		$c[] = array( sprintf( '%04d-%02d-%02d', $y, $m, $d ), $h, $pref );
	};
	if ( preg_match_all( '#\b(\d{1,2})[/.](\d{1,2})[/.](\d{4})' . $hr . '#', $n, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) { foreach ( $mm as $x ) { $add( $x[3][0], $x[2][0], $x[1][0], $x, $x[0][1] ); } }
	if ( preg_match_all( '#\b(\d{1,2})\s+DE\s+(' . implode( '|', array_keys( $mes ) ) . ')\s+DE\s+(\d{4})' . $hr . '#', $n, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) { foreach ( $mm as $x ) { $add( $x[3][0], $mes[ $x[2][0] ], $x[1][0], $x, $x[0][1] ); } }
	$f = array_values( array_filter( $c, function ( $d ) use ( $hoje, $max ) { return $d[0] >= $hoje && $d[0] <= $max; } ) );
	if ( ! $f ) { return ''; }
	$p = array_values( array_filter( $f, function ( $d ) { return $d[2]; } ) ); if ( $p ) { $f = $p; }
	usort( $f, function ( $a, $b ) { return strcmp( $a[0], $b[0] ); } );
	return $f[0][0] . ( $f[0][1] ? ' ' . $f[0][1] : '' );
}
function dv_al_modalidade( $n ) {
	foreach ( array( 'PREGAO ELETRONICO' => 'Pregão Eletrônico', 'PREGAO PRESENCIAL' => 'Pregão Presencial', 'PREGAO' => 'Pregão', 'CONCORRENCIA' => 'Concorrência', 'TOMADA DE PRECOS' => 'Tomada de Preços', 'DISPENSA' => 'Dispensa', 'INEXIGIBILIDADE' => 'Inexigibilidade', 'CHAMAMENTO PUBLICO' => 'Chamamento Público', 'CREDENCIAMENTO' => 'Credenciamento', 'CONVITE' => 'Convite', 'LEILAO' => 'Leilão' ) as $k => $v ) { if ( strpos( $n, $k ) !== false ) { return $v; } }
	return 'Licitação';
}
function dv_al_numero( $n ) { if ( preg_match( '#(?:PREGAO|CONCORRENCIA|DISPENSA|TOMADA DE PRECOS|CHAMAMENTO PUBLICO|CREDENCIAMENTO|EDITAL)[A-Z\s/]{0,40}?\bN[\s.ºO°\x{00BA}]*\s*(\d{1,3}\.\d{3}|\d{1,6})\s*/\s*(\d{4})#u', $n, $m ) ) { return str_replace( '.', '', $m[1] ) . '/' . $m[2]; } return preg_match( '#\bN[\s.ºO°\x{00BA}]*\s*(\d{1,3}\.\d{3}|\d{1,6})\s*/\s*(\d{4})#u', $n, $m ) ? str_replace( '.', '', $m[1] ) . '/' . $m[2] : ''; }
function dv_al_objeto( $txt ) {
	if ( preg_match( '#OBJETO\s*(?:[:\-–]|\b(?:A|O|É|E|DE)\b)?\s*(.{20,420}?)(?:\.\s|;|\s(?:DATA|ABERTURA|SESS[AÃ]O|ENTREGA|EDITAL|VALOR|PRAZO|LOCAL|INFORMA|OS INTERESSADOS)|$)#iu', $txt, $m ) ) { return rtrim( trim( $m[1], ' "“”' ), '.' ); }
	$n = dv_al_norm( $txt ); if ( preg_match( DV_AL_QUER, $n, $q, PREG_OFFSET_CAPTURE ) && $q[0][1] > 30 ) { $txt = mb_substr( $txt, $q[0][1] ); }
	return mb_substr( $txt, 0, 480 );
}
/** URLs dos diários para um termo (entra no mesmo disparo paralelo do PNCP) */
function dv_al_diario_urls( $t, $desde ) {
	$d0 = gmdate( 'Y-m-d', min( strtotime( $desde ), strtotime( current_time( 'Y-m-d' ) . ' -5 days' ) ) );
	return array(
		'q' => add_query_arg( array( 'querystring' => rawurlencode( '"' . $t . '" + (pregão | concorrência | "aviso de licitação" | "dispensa eletrônica" | "chamamento público" | "tomada de preços")' ), 'published_since' => $d0, 'size' => 60, 'excerpt_size' => 650, 'number_of_excerpts' => 3, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' ),
		'd' => 'https://www.in.gov.br/consulta/-/buscar/dou?q=' . rawurlencode( '"' . $t . '"' ) . '&s=do3&exactDate=personalizado&sortType=0&delta=75&publishFrom=' . gmdate( 'd-m-Y', strtotime( $d0 ) ) . '&publishTo=' . current_time( 'd-m-Y' ),
	);
}
define( 'DV_AL_QUER', '/AVISO D[EO] (LICITACAO|PREGAO|ABERTURA|DISPENSA|CHAMAMENTO|EDITAL|REABERTURA|CONCORRENCIA)|AVISOS DE LICITACAO|PREGAO (ELETRONICO|PRESENCIAL)|CONCORRENCIA (ELETRONICA|PUBLICA)|TOMADA DE PRECOS|DISPENSA (ELETRONICA|DE LICITACAO)|CHAMAMENTO PUBLICO|SESSAO PUBLICA|ABERTURA DAS? (PROPOSTAS|SESSAO|ENVELOPES|LICITACAO)|RECEBIMENTO DAS PROPOSTAS|EDITAL DE (LICITACAO|PREGAO)/' );
define( 'DV_AL_NAO', '/HOMOLOG|ADJUDICA|EXTRATO D|RATIFICA|RESULTADO|TERMO ADITIVO|ATA DE REGISTRO DE PRECOS N|REVOGA|ANULA|DESERT|FRACASSAD|VENCEDOR|CONCURSO PUBLICO|PROCESSO SELETIVO|EMPENHO|CONTRATACAO DIRETA - D|ORDEM DE (SERVICO|FORNECIMENTO)/' );
function dv_al_aceita( $nd, $enc, $termo, $excl, $a ) {
	if ( ! dv_al_relevante( $termo, $nd ) || ! preg_match( DV_AL_QUER, $nd ) ) { return false; }
	if ( preg_match( DV_AL_NAO, $nd ) && ! $enc ) { return false; }
	foreach ( $excl as $x ) { if ( $x !== '' && strpos( $nd, $x ) !== false ) { return false; } }
	if ( ! empty( $a['tipo'] ) && dv_al_tipo( $nd ) !== $a['tipo'] ) { return false; }
	return true;
}
/** Texto integral de uma publicação do DOU, separado por aviso (uma publicação pode trazer vários). Guardado 7 dias. */
function dv_al_dou_blocos( $html ) {
	$i = strpos( $html, 'class="texto-dou"' ); if ( $i === false ) { return array(); }
	$f = strpos( $html, 'informacao-conteudo-dou', $i ); $h = substr( $html, $i, $f ? $f - $i : 60000 );
	preg_match_all( '#<p class="([^"]*)"[^>]*>(.*?)</p>#s', $h, $mm, PREG_SET_ORDER ); $bl = array(); $cab = ''; $cor = '';
	foreach ( $mm as $m ) {
		$t = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) ) ); if ( $t === '' ) { continue; }
		if ( strpos( $m[1], 'identifica' ) !== false ) { if ( $cor !== '' ) { $bl[] = array( $cab, $cor ); $cab = ''; $cor = ''; } $cab = trim( $cab . ' ' . $t ); }
		else { $cor = trim( $cor . ' ' . $t ); }
	}
	if ( $cab !== '' || $cor !== '' ) { $bl[] = array( $cab, $cor ); }
	if ( count( $bl ) > 1 && preg_match( '/^AVISOS? D/', dv_al_norm( $bl[0][0] ) ) && $bl[0][1] === '' ) { array_shift( $bl ); }
	return array_slice( $bl, 0, 30 );
}
function dv_al_dou_completar( $dia, $excl, $a ) {
	$links = array(); foreach ( $dia as $r => $x ) { if ( strpos( $r, 'DOU' ) === 0 && count( $links ) < 45 ) { $links[ $x['link'] ] = 1; } }
	if ( ! $links ) { return $dia; }
	$txt = array(); $falta = array();
	foreach ( array_keys( $links ) as $l ) { $c = get_transient( 'dv_dou_' . md5( $l ) ); if ( is_array( $c ) ) { $txt[ $l ] = $c; } else { $falta[ md5( $l ) ] = $l; } }
	if ( $falta && function_exists( 'dv_ds_multi' ) ) {
		foreach ( array_chunk( $falta, 15, true ) as $ch ) {
			foreach ( dv_ds_multi( $ch, array( 'Accept' => 'text/html' ), 20 ) as $k => $b ) { if ( ! $b ) { continue; } $bl = dv_al_dou_blocos( $b ); $txt[ $ch[ $k ] ] = $bl; set_transient( 'dv_dou_' . $k, $bl, 7 * DAY_IN_SECONDS ); }
		}
	}
	$out = array();
	foreach ( $dia as $r => $x ) {
		if ( strpos( $r, 'DOU' ) !== 0 || empty( $txt[ $x['link'] ] ) ) { $out[ $r ] = $x; continue; }
		$achou = false;
		foreach ( $txt[ $x['link'] ] as $k => $b ) {
			$tx = trim( $b[0] . ' — ' . $b[1], ' —' ); $nd = dv_al_norm( $tx ); $ok = false;
			foreach ( $x['termos'] as $t ) { if ( dv_al_aceita( $nd . ' ' . dv_al_norm( $x['titulo'] ), dv_al_dt_sessao( $tx ), $t, $excl, $a ) ) { $ok = true; } }
			if ( ! $ok ) { continue; } $achou = true;
			$y = $x; $y['ref'] = $r . 'B' . $k; $y['trecho'] = mb_substr( $tx, 0, 1500 ); $y['objeto'] = dv_al_objeto( $b[1] ?: $tx ); $y['encerra'] = dv_al_dt_sessao( $tx );
			$y['numero'] = dv_al_numero( $nd ) ?: $x['numero']; $y['modalidade'] = dv_al_modalidade( dv_al_norm( $b[0] ) . ' ' . $nd );
			if ( preg_match( '#VALOR (?:TOTAL |GLOBAL )?(?:ESTIMADO|MAXIMO|DE REFERENCIA)?[^0-9]{0,25}R\$\s*([\d.]+,\d{2})#', $nd, $v ) ) { $y['valor'] = (float) str_replace( array( '.', ',' ), array( '', '.' ), $v[1] ); }
			$out[ $y['ref'] ] = $y;
		}
		if ( ! $achou && count( $txt[ $x['link'] ] ) <= 1 ) { $out[ $r ] = $x; }
	}
	return $out;
}
/** Converte as respostas dos diários em oportunidades no mesmo formato do PNCP (fonte = diario) */
function dv_al_diario_itens( $tipo, $body, $termo, $a, $ufs, $excl ) {
	$out = array(); if ( ! $body ) { return $out; }
	$quer = DV_AL_QUER; $nao = DV_AL_NAO;
	$aceita = function ( $nd, $enc ) use ( $termo, $excl, $a ) { return dv_al_aceita( $nd, $enc, $termo, $excl, $a ); };
	if ( $tipo === 'q' ) {
		$j = json_decode( $body, true );
		foreach ( (array) ( $j['gazettes'] ?? array() ) as $g ) {
			$uf = strtoupper( (string) ( $g['state_code'] ?? '' ) ); if ( $ufs && ! in_array( $uf, $ufs, true ) ) { continue; }
			foreach ( (array) ( $g['excerpts'] ?? array() ) as $ex ) {
				$tx = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $ex ), ENT_QUOTES, 'UTF-8' ) ) ); $nd = dv_al_norm( $tx );
				$enc = dv_al_dt_sessao( $tx ); if ( ! $aceita( $nd, $enc ) ) { continue; }
				$num = dv_al_numero( $nd ); $mun = (string) ( $g['territory_name'] ?? '' );
				$ref = 'QD' . sprintf( '%u', crc32( ( $g['url'] ?? '' ) . '|' . ( $num ?: mb_substr( $nd, 0, 120 ) ) ) );
				$out[ $ref ] = array( 'ref' => $ref, 'fonte' => 'diario', 'fonte_nome' => 'Diário Oficial de ' . $mun, 'orgao' => 'Município de ' . $mun, 'municipio' => $mun, 'uf' => $uf, 'modalidade' => dv_al_modalidade( $nd ), 'numero' => $num,
					'titulo' => '', 'objeto' => dv_al_objeto( $tx ), 'trecho' => mb_substr( $tx, 0, 900 ), 'valor' => 0, 'publicado' => (string) ( $g['date'] ?? '' ), 'encerra' => $enc, 'link' => (string) ( $g['url'] ?? '' ), 'termos' => array( $termo ) );
			}
		}
	} else {
		if ( ! preg_match( '#<script[^>]*id="_br_com_seatecnologia_in_buscadou_BuscaDouPortlet_params"[^>]*>(.*?)</script>#s', $body, $m ) ) { return $out; }
		$j = json_decode( trim( $m[1] ), true );
		foreach ( (array) ( $j['jsonArray'] ?? array() ) as $it ) {
			$tit = dv_al_norm( ( $it['artType'] ?? '' ) . ' ' . ( $it['title'] ?? '' ) );
			if ( preg_match( '/HOMOLOG|ADJUDICA|EXTRATO|RATIFICA|RESULTADO|REVOGA|ANULA|SUSPENS|DESERT|FRACASS|CONTRATO|ADITIVO|PENALIDADE|SANCAO|IMPUGNA|ESCLARECIMENTO|JULGAMENTO/', $tit ) ) { continue; }
			$h = (array) ( $it['hierarchyList'] ?? array() ); $uf = '';
			foreach ( $h as $nv ) { if ( preg_match( '/^(ESTADO|GOVERNO) D/', dv_al_norm( $nv ) ) ) { $uf = dv_al_uf_nome( $nv ); } }
			$tx = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( ( $it['title'] ?? '' ) . ' — ' . ( $it['content'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) ); $nd = dv_al_norm( $tx );
			if ( ! $uf && preg_match( '#[\s/\-]\s?(AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)\b[\s.,;)]#', $tx, $u ) ) { $uf = $u[1]; }
			if ( $ufs && ! in_array( $uf, $ufs, true ) ) { continue; }
			$enc = dv_al_dt_sessao( $tx ); if ( ! $aceita( $nd . ' ' . $tit, $enc ) ) { continue; }
			$org = $h ? (string) end( $h ) : 'Órgão publicado no DOU';
			$mun = preg_match( '/^PREFEITURA MUNICIPAL DE (.+)$/', dv_al_norm( $org ) ) ? trim( preg_replace( '/^prefeitura municipal de\s+/iu', '', $org ) ) : '';
			$u2 = ! empty( $it['urlTitle'] ) ? 'https://www.in.gov.br/web/dou/-/' . $it['urlTitle'] : ''; if ( ! $u2 ) { continue; }
			$d = (string) ( $it['pubDate'] ?? '' ); $pub = preg_match( '#^(\d{2})/(\d{2})/(\d{4})#', $d, $x ) ? "$x[3]-$x[2]-$x[1]" : '';
			$ref = 'DOU' . preg_replace( '/\D/', '', (string) ( $it['classPK'] ?? crc32( $u2 ) ) );
			$out[ $ref ] = array( 'ref' => $ref, 'fonte' => 'diario', 'fonte_nome' => 'Diário Oficial da União · seção 3', 'orgao' => $org, 'municipio' => $mun, 'uf' => $uf, 'modalidade' => dv_al_modalidade( $nd . ' ' . $tit ), 'numero' => dv_al_numero( $nd ),
				'titulo' => (string) ( $it['title'] ?? '' ), 'objeto' => dv_al_objeto( $tx ), 'trecho' => mb_substr( $tx, 0, 900 ), 'valor' => 0, 'publicado' => $pub, 'encerra' => $enc, 'link' => $u2, 'termos' => array( $termo ) );
		}
	}
	return $out;
}
/** Publicações dos diários das associações de municípios / DOM/SC -> oportunidades (fonte = diario) */
function dv_al_dm_itens( $lista, $termo, $a, $ufs, $excl ) {
	$out = array();
	foreach ( (array) $lista as $g ) {
		if ( $ufs && ! in_array( $g['uf'], $ufs, true ) ) { continue; }
		$tx = trim( $g['titulo'] . ' — ' . $g['trecho'], ' —' ); $nd = dv_al_norm( $tx ); $enc = dv_al_dt_sessao( $tx );
		if ( ! dv_al_aceita( $nd, $enc, $termo, $excl, $a ) ) { continue; }
		$nt = dv_al_norm( $g['titulo'] );
		if ( preg_match( '/HOMOLOG|ADJUDICA|EXTRATO|RATIFICA|RESULTADO|REVOGA|ANULA|ADITIVO|APOSTILA|RESCIS|^CONTRATO|ATA DE REGISTRO|PORTARIA|DECRETO|^LEI |JULGAMENTO|DESERT|FRACASS|SUSPENS|ADESAO|INTENCAO DE REGISTRO|CONVOCACAO|NOTIFICA|PENALIDADE/', $nt ) ) { continue; }
		if ( ! preg_match( '/AVISO|EDITAL|PREGAO|CONCORRENCIA|DISPENSA|CHAMAMENTO|CREDENCIAMENTO|TOMADA DE PRECO|LICITA|COTACAO|LEILAO|CONVITE/', $nt ) && ! ( ! empty( $g['categoria'] ) && preg_match( '/LICITA/', dv_al_norm( $g['categoria'] ) ) ) ) { continue; }
		$ref = 'DM' . sprintf( '%u', crc32( $g['link'] ) ); $mun = $g['municipio'] ?: $g['entidade'];
		$out[ $ref ] = array( 'ref' => $ref, 'fonte' => 'diario', 'fonte_nome' => $g['fonte_nome'], 'orgao' => $g['orgao'] ?: $g['entidade'], 'municipio' => $mun, 'uf' => $g['uf'], 'modalidade' => dv_al_modalidade( $nd ), 'numero' => dv_al_numero( $nd ),
			'titulo' => $g['titulo'], 'objeto' => dv_al_objeto( $g['trecho'] ?: $tx ), 'trecho' => mb_substr( $tx, 0, 900 ), 'valor' => 0, 'publicado' => $g['data'], 'encerra' => $enc, 'link' => $g['link'], 'termos' => array( $termo ) );
	}
	return $out;
}
/** Completa os avisos dos diários municipais com o texto integral (objeto, número, sessão e valor) */
function dv_al_dm_completar( $dia ) {
	if ( ! function_exists( 'dv_dm_materia' ) ) { return $dia; }
	$links = array(); foreach ( $dia as $r => $x ) { if ( strpos( $r, 'DM' ) === 0 && count( $links ) < 40 ) { $links[] = $x['link']; } }
	if ( ! $links ) { return $dia; }
	$tx = dv_dm_materia( $links );
	foreach ( $dia as $r => &$x ) {
		if ( strpos( $r, 'DM' ) !== 0 || empty( $tx[ $x['link'] ] ) ) { continue; }
		$t = $tx[ $x['link'] ]; $nd = dv_al_norm( $t );
		$enc = dv_al_dt_sessao( $t ) ?: $x['encerra']; $x['encerra'] = ( $enc && substr( $enc, 0, 10 ) > $x['publicado'] ) ? $enc : '';
		if ( preg_match( '/\bOBJETO\s*(?:DA LICITA[ÇC][ÃA]O|DO CERTAME|DO PREG[ÃA]O)?\s*[:\-–]\s*(.{20,480}?)(?:\.\s|;|\s(?:DATA|ABERTURA|SESS[ÃA]O|ENTREGA|VALOR|PRAZO|LOCAL|INFORMA[ÇC]|O EDITAL|EDITAL|OS INTERESSADOS|TIPO)\b|$)/iu', $t, $ob ) ) { $x['objeto'] = rtrim( trim( $ob[1], ' "“”' ), '.' ); }
		$x['numero'] = dv_al_numero( $nd ) ?: $x['numero']; $x['modalidade'] = dv_al_modalidade( dv_al_norm( $x['titulo'] ) . ' ' . $nd );
		if ( preg_match( '#VALOR (?:TOTAL |GLOBAL )?(?:ESTIMADO|MAXIMO|DE REFERENCIA)?[^0-9]{0,25}R\$\s*([\d.]+,\d{2})#', $nd, $v ) ) { $x['valor'] = (float) str_replace( array( '.', ',' ), array( '', '.' ), $v[1] ); }
		$x['trecho'] = mb_substr( $t, 0, 1500 );
	}
	unset( $x );
	return $dia;
}
function dv_al_buscar( $a, $desde ) {
	$termos = array_slice( array_filter( array_map( 'trim', preg_split( '/[,;\n]+/', (string) $a['termos'] ) ) ), 0, 6 );
	$excl = array_filter( array_map( function ( $x ) { return dv_al_norm( trim( $x ) ); }, preg_split( '/[,;\n]+/', (string) ( $a['excluir'] ?? '' ) ) ) );
	$ufs = array_values( array_filter( (array) ( $a['ufs'] ?? array() ), function ( $u ) { return preg_match( '/^[A-Z]{2}$/', $u ); } ) );
	$urls = array();
	foreach ( $termos as $t ) {
		$q = array( 'q' => $t, 'tipos_documento' => 'edital', 'ordenacao' => '-data', 'pagina' => 1, 'tam_pagina' => 100, 'status' => 'recebendo_proposta' );
		if ( $ufs ) { $q['ufs'] = implode( '|', $ufs ); }
		$urls[ 'p|' . $t ] = 'https://pncp.gov.br/api/search/?' . http_build_query( $q );
		if ( ! isset( $a['diarios'] ) || $a['diarios'] ) { foreach ( dv_al_diario_urls( $t, $desde ) as $k => $u ) { $urls[ $k . '|' . $t ] = $u; } }
	}
	$res = array();
	if ( function_exists( 'dv_ds_multi' ) ) {
		/* PNCP e diários em disparos separados: um diário lento não derruba a busca do PNCP */
		$up = array_filter( $urls, function ( $k ) { return strpos( $k, 'p|' ) === 0; }, ARRAY_FILTER_USE_KEY );
		$res = dv_ds_multi( $up, array(), 30 );
		$ud = array_diff_key( $urls, $up ); if ( $ud ) { $res += dv_ds_multi( $ud, array( 'Accept' => 'text/html,application/json' ), 18 ); }
		/* uma nova tentativa, uma a uma, para o que falhou (PNCP e DOU às vezes recusam o primeiro acesso) */
		foreach ( $res as $k => $b ) { if ( $b ) { continue; } $g = wp_remote_get( $urls[ $k ], array( 'timeout' => strpos( $k, 'd|' ) === 0 ? 15 : 30, 'headers' => array( 'Accept' => strpos( $k, 'p|' ) === 0 ? 'application/json' : 'text/html,application/json' ) ) ); $GLOBALS['dv_al_cod'][ $k ] = is_wp_error( $g ) ? $g->get_error_message() : wp_remote_retrieve_response_code( $g ) . ' ' . substr( wp_remote_retrieve_body( $g ), 0, 120 ); if ( ! is_wp_error( $g ) && wp_remote_retrieve_response_code( $g ) === 200 ) { $res[ $k ] = wp_remote_retrieve_body( $g ); } }
	}
	else { foreach ( $urls as $k => $u ) { $g = wp_remote_get( $u, array( 'timeout' => 30 ) ); $res[ $k ] = is_wp_error( $g ) ? null : wp_remote_retrieve_body( $g ); } }
	$GLOBALS['dv_al_dbg'] = array_map( function ( $b ) { return is_string( $b ) ? strlen( $b ) : -1; }, $res );
	$out = array(); $dia = array();
	foreach ( $res as $chave => $j ) {
		list( $fo, $termo ) = explode( '|', $chave, 2 );
		if ( $fo !== 'p' ) { foreach ( dv_al_diario_itens( $fo, $j, $termo, $a, $ufs, $excl ) as $r => $x ) { if ( isset( $dia[ $r ] ) ) { $dia[ $r ]['termos'][] = $termo; } else { $dia[ $r ] = $x; } } continue; }
		if ( is_string( $j ) ) { $j = json_decode( $j, true ); }
		/* o PNCP às vezes recusa a conexão do servidor: guarda a última resposta boa de cada busca (6h) e usa quando ele falhar */
		$ck = 'dv_alp_' . md5( $urls[ $chave ] );
		if ( ! empty( $j['items'] ) ) { set_transient( $ck, array( 'items' => array_map( function ( $i ) { return array_intersect_key( $i, array_flip( array( 'data_publicacao_pncp', 'createdAt', 'item_url', 'description', 'title', 'valor_global', 'orgao_nome', 'municipio_nome', 'uf', 'modalidade_licitacao_nome', 'data_fim_vigencia' ) ) ); }, $j['items'] ) ), 6 * HOUR_IN_SECONDS ); }
		elseif ( ! is_array( $j ) ) { $j = get_transient( $ck ) ?: array(); $GLOBALS['dv_al_cache'] = 1; }
		foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
			$pub = substr( (string) ( $i['data_publicacao_pncp'] ?? $i['createdAt'] ?? '' ), 0, 10 );
			if ( $pub && $pub < $desde ) { continue; }
			if ( ! preg_match( '#/compras/(\d{14})/(\d{4})/(\d+)#', (string) ( $i['item_url'] ?? '' ), $m ) ) { continue; }
			$ref = $m[1] . '/' . $m[2] . '/' . $m[3];
			$desc = (string) ( $i['description'] ?? '' ); $nd = dv_al_norm( $desc . ' ' . ( $i['title'] ?? '' ) );
			if ( ! dv_al_relevante( $termo, $nd ) ) { continue; }
			$pula = false; foreach ( $excl as $x ) { if ( $x !== '' && strpos( $nd, $x ) !== false ) { $pula = true; } } if ( $pula ) { continue; }
			if ( ! empty( $a['tipo'] ) && dv_al_tipo( $desc ) !== $a['tipo'] ) { continue; }
			$v = (float) ( $i['valor_global'] ?? 0 );
			if ( ! empty( $a['vmin'] ) && $v && $v < (float) $a['vmin'] ) { continue; }
			if ( ! empty( $a['vmax'] ) && $v && $v > (float) $a['vmax'] ) { continue; }
			if ( isset( $out[ $ref ] ) ) { $out[ $ref ]['termos'][] = $termo; continue; }
			$out[ $ref ] = array( 'ref' => $ref, 'orgao' => $i['orgao_nome'] ?? '', 'municipio' => $i['municipio_nome'] ?? '', 'uf' => $i['uf'] ?? '', 'modalidade' => $i['modalidade_licitacao_nome'] ?? '',
				'titulo' => $i['title'] ?? '', 'objeto' => mb_substr( $desc, 0, 600 ), 'valor' => $v, 'publicado' => $pub, 'encerra' => substr( (string) ( $i['data_fim_vigencia'] ?? '' ), 0, 16 ),
				'link' => 'https://pncp.gov.br/app/editais/' . $ref, 'termos' => array( $termo ) );
		}
	}
	/* diários das associações de municípios (SIGPub) e DOM/SC: cobertura das cidades que não publicam no PNCP */
	if ( ( ! isset( $a['diarios'] ) || $a['diarios'] ) && function_exists( 'dv_dm_buscar' ) ) {
		$d0 = gmdate( 'Y-m-d', min( strtotime( $desde ), strtotime( current_time( 'Y-m-d' ) . ' -5 days' ) ) );
		foreach ( $termos as $t ) { foreach ( dv_al_dm_itens( dv_dm_buscar( $t, $d0, $ufs ), $t, $a, $ufs, $excl ) as $r => $x ) { if ( isset( $dia[ $r ] ) ) { $dia[ $r ]['termos'][] = $t; } else { $dia[ $r ] = $x; } } }
	}
	/* o mesmo edital pode estar no PNCP e no diário: descarta o do diário quando o número e o município (ou órgão) batem */
	if ( $dia ) { $dia = dv_al_dou_completar( dv_al_dm_completar( $dia ), $excl, $a ); }
	if ( $dia ) {
		$ja = array(); foreach ( $out as $x ) { if ( preg_match_all( '#(\d{1,6})\s*/\s*(\d{4})#', $x['titulo'] . ' ' . $x['objeto'], $mm, PREG_SET_ORDER ) ) { foreach ( $mm as $q ) { $ja[ (int) $q[1] . '/' . $q[2] . '|' . dv_al_norm( $x['municipio'] ) ] = 1; } } }
		$dv = array();
		foreach ( $dia as $r => $x ) {
			if ( $x['numero'] && preg_match( '#(\d+)/(\d{4})#', $x['numero'], $q ) && isset( $ja[ (int) $q[1] . '/' . $q[2] . '|' . dv_al_norm( $x['municipio'] ) ] ) ) { continue; }
			$k = $x['numero'] ? $x['numero'] . '|' . dv_al_norm( $x['municipio'] ?: $x['orgao'] ) : $r; $k2 = md5( dv_al_norm( $x['orgao'] . mb_substr( $x['objeto'], 0, 120 ) ) ); if ( isset( $dv[ $k ] ) || isset( $dv[ $k2 ] ) ) { continue; } $dv[ $k ] = 1; $dv[ $k2 ] = 1;
			$x['termos'] = array_values( array_unique( $x['termos'] ) ); $out[ $r ] = $x;
		}
	}
	uasort( $out, function ( $x, $y ) { return strcmp( $y['publicado'], $x['publicado'] ); } );
	return array_values( $out );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/alertas', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id();
		$cx = array_values( dv_al_caixa( $uid ) ); usort( $cx, function ( $a, $b ) { return strcmp( $b['achado'] ?? '', $a['achado'] ?? '' ) ?: strcmp( $b['publicado'] ?? '', $a['publicado'] ?? '' ); } );
		return array( 'alertas' => dv_al_lista( $uid ), 'max' => DV_AL_MAX, 'pref' => function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array(), 'caixa' => $cx );
	} ) );
	register_rest_route( $ns, '/alertas/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_al_lista( $uid ); $id = sanitize_key( (string) $r['id'] );
		$termos = mb_substr( sanitize_textarea_field( (string) $r['termos'] ), 0, 400 );
		if ( mb_strlen( trim( $termos ) ) < 3 ) { return new WP_Error( 'dv_al', 'Informe pelo menos uma palavra ou expressão.', array( 'status' => 400 ) ); }
		$ufs = array_values( array_intersect( array_map( 'strtoupper', (array) $r['ufs'] ), array( 'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO' ) ) );
		$a = array( 'nome' => mb_substr( sanitize_text_field( (string) $r['nome'] ), 0, 80 ) ?: mb_substr( $termos, 0, 40 ), 'termos' => $termos, 'excluir' => mb_substr( sanitize_textarea_field( (string) $r['excluir'] ), 0, 300 ),
			'ufs' => $ufs, 'vmin' => max( 0, (float) $r['vmin'] ), 'vmax' => max( 0, (float) $r['vmax'] ), 'tipo' => in_array( $r['tipo'], array( 'servico', 'compra' ), true ) ? $r['tipo'] : '', 'ativo' => ( isset( $r['ativo'] ) && ! $r['ativo'] ) ? 0 : 1 );
		$achou = false; foreach ( $l as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $a ); $achou = true; } } unset( $x );
		if ( ! $achou ) { if ( count( $l ) >= DV_AL_MAX ) { return new WP_Error( 'dv_al', 'Limite de ' . DV_AL_MAX . ' alertas.', array( 'status' => 400 ) ); } $id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $a + array( 'id' => $id, 'criado' => current_time( 'Y-m-d' ) ); }
		dv_al_salvar_lista( $uid, $l ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/alertas/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] );
		dv_al_salvar_lista( $uid, array_filter( dv_al_lista( $uid ), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ); return array( 'ok' => true );
	} ) );
	register_rest_route( $ns, '/alertas/caixa', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $c = dv_al_caixa( $uid ); $ref = preg_replace( '/[^0-9A-Z\/]/', '', (string) $r['ref'] );
		if ( ! isset( $c[ $ref ] ) ) { return new WP_Error( 'dv_al', 'Oportunidade não encontrada.', array( 'status' => 404 ) ); }
		$c[ $ref ]['st'] = in_array( $r['st'], array( '', 'quero', 'desc' ), true ) ? $r['st'] : ''; if ( $r['lic'] ) { $c[ $ref ]['lic'] = sanitize_key( (string) $r['lic'] ); }
		dv_al_caixa_salvar( $uid, $c ); return array( 'ok' => true );
	} ) );
	register_rest_route( $ns, '/alertas/atualizar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $n = 0; foreach ( dv_al_rodar( $uid, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -15 days' ) ) ) as $b ) { $n += count( $b['itens'] ); }
		return array( 'ok' => true, 'novas' => $n );
	} ) );
	/* Buscador: avisos de licitação nos diários oficiais (municípios + DOU seção 3) para a mesma busca; o PNCP o navegador já consulta direto */
	register_rest_route( $ns, '/buscador/diarios', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$q = trim( str_replace( '"', '', sanitize_text_field( (string) $r['q'] ) ) ); if ( mb_strlen( $q ) < 3 ) { return array( 'itens' => array() ); }
		$q = mb_substr( $q, 0, 80 );
		$ufs = array_values( array_intersect( array_map( 'strtoupper', array_filter( explode( '|', (string) $r['ufs'] ) ) ), array( 'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO' ) ) );
		$ck = 'dv_bqd_' . md5( mb_strtolower( $q ) . '|' . implode( ',', $ufs ) ); $c = get_transient( $ck ); if ( is_array( $c ) ) { return array( 'itens' => $c, 'cache' => 1 ); }
		$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -20 days' ) ); $a = array( 'termos' => $q ); $dia = array();
		$urls = array(); foreach ( dv_al_diario_urls( $q, $desde ) as $k => $u ) { $urls[ $k ] = $u; }
		$res = function_exists( 'dv_ds_multi' ) ? dv_ds_multi( $urls, array( 'Accept' => 'text/html,application/json' ), 20 ) : array();
		foreach ( $res as $k => $b ) { if ( ! $b ) { $g = wp_remote_get( $urls[ $k ], array( 'timeout' => 15 ) ); if ( ! is_wp_error( $g ) && wp_remote_retrieve_response_code( $g ) === 200 ) { $res[ $k ] = wp_remote_retrieve_body( $g ); } } }
		foreach ( $res as $k => $b ) { foreach ( dv_al_diario_itens( $k, $b, $q, $a, $ufs, array() ) as $rf => $x ) { $dia[ $rf ] = $x; } }
		if ( function_exists( 'dv_dm_buscar' ) ) { foreach ( dv_al_dm_itens( dv_dm_buscar( $q, $desde, $ufs ), $q, $a, $ufs, array() ) as $rf => $x ) { $dia[ $rf ] = $x; } }
		$dia = dv_al_dou_completar( dv_al_dm_completar( $dia ), array(), $a ); $dv = array(); $out = array();
		foreach ( $dia as $x ) { $k = $x['numero'] ? $x['numero'] . '|' . dv_al_norm( $x['municipio'] ?: $x['orgao'] ) : $x['ref']; $k2 = md5( dv_al_norm( $x['orgao'] . mb_substr( $x['objeto'], 0, 120 ) ) ); if ( isset( $dv[ $k ] ) || isset( $dv[ $k2 ] ) ) { continue; } $dv[ $k ] = 1; $dv[ $k2 ] = 1; unset( $x['termos'] ); $out[] = $x; }
		usort( $out, function ( $x, $y ) { return strcmp( $y['publicado'], $x['publicado'] ); } );
		$out = array_slice( $out, 0, 150 );
		if ( array_filter( $res ) ) { set_transient( $ck, $out, 30 * MINUTE_IN_SECONDS ); }
		return array( 'itens' => $out );
	} ) );
	/* Prévia: o que o alerta encontra nas licitações abertas publicadas nos últimos 15 dias */
	register_rest_route( $ns, '/alertas/testar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$a = array( 'termos' => (string) $r['termos'], 'excluir' => (string) $r['excluir'], 'ufs' => (array) $r['ufs'], 'vmin' => (float) $r['vmin'], 'vmax' => (float) $r['vmax'], 'tipo' => in_array( $r['tipo'], array( 'servico', 'compra' ), true ) ? $r['tipo'] : '' );
		$it = dv_al_buscar( $a, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -15 days' ) ) );
		return array( 'itens' => array_slice( $it, 0, 80 ), 'total' => count( $it ) ) + ( function_exists( 'dv_is_master' ) && dv_is_master( get_current_user_id() ) && ! empty( $r['dbg'] ) ? array( 'dbg' => $GLOBALS['dv_al_dbg'] ?? null, 'cod' => $GLOBALS['dv_al_cod'] ?? null ) : array() );
	} ) );
} );

/* E-mail diário (por volta das 7h40) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_al_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:40 UTC' ), 'daily', 'dv_al_diario' ); } } );
/* Caixa de oportunidades: tudo o que os alertas encontram fica guardado para o cliente marcar "quero" ou "descartar" */
function dv_al_caixa( $uid ) { $c = json_decode( get_user_meta( $uid, 'dv_al_caixa', true ) ?: '{}', true ); return is_array( $c ) ? $c : array(); }
function dv_al_caixa_salvar( $uid, $c ) {
	$hoje = current_time( 'Y-m-d' ); $lim = gmdate( 'Y-m-d', strtotime( $hoje . ' -30 days' ) );
	$c = array_filter( $c, function ( $x ) use ( $hoje, $lim ) { if ( ( $x['st'] ?? '' ) === 'quero' ) { return substr( (string) ( $x['achado'] ?? $hoje ), 0, 10 ) >= $lim; } return ( ! $x['encerra'] || substr( $x['encerra'], 0, 10 ) >= $hoje ) && substr( (string) ( $x['achado'] ?? $hoje ), 0, 10 ) >= $lim; } );
	if ( count( $c ) > 400 ) { uasort( $c, function ( $a, $b ) { return strcmp( $b['achado'] ?? '', $a['achado'] ?? '' ); } ); $c = array_slice( $c, 0, 400, true ); }
	update_user_meta( $uid, 'dv_al_caixa', wp_slash( wp_json_encode( $c, JSON_UNESCAPED_UNICODE ) ) );
}
/** Roda os alertas do usuário: devolve os blocos de novidades (ainda não vistas) e guarda tudo na caixa */
function dv_al_rodar( $uid, $desde ) {
	$vistos = json_decode( get_user_meta( $uid, 'dv_alertas_vistos', true ) ?: '[]', true ) ?: array(); $set = array_flip( $vistos ); $bloco = array(); $cx = dv_al_caixa( $uid ); $agora = current_time( 'mysql' );
	foreach ( dv_al_lista( $uid ) as $a ) {
		if ( empty( $a['ativo'] ) ) { continue; }
		$novos = array_values( array_filter( dv_al_buscar( $a, $desde ), function ( $x ) use ( &$set ) { if ( isset( $set[ $x['ref'] ] ) ) { return false; } $set[ $x['ref'] ] = 1; return true; } ) );
		foreach ( $novos as $x ) { if ( ! isset( $cx[ $x['ref'] ] ) ) { $cx[ $x['ref'] ] = array_merge( $x, array( 'alerta' => $a['nome'], 'achado' => $agora, 'st' => '' ) ); } }
		if ( $novos ) { $bloco[] = array( 'a' => $a, 'itens' => array_slice( $novos, 0, 40 ) ); }
	}
	update_user_meta( $uid, 'dv_alertas_vistos', wp_slash( wp_json_encode( array_slice( array_keys( $set ), -4000 ) ) ) );
	dv_al_caixa_salvar( $uid, $cx );
	do_action( 'dv_al_rodou', $uid );
	if ( $bloco ) { $cx = dv_al_caixa( $uid ); foreach ( $bloco as &$b ) { foreach ( $b['itens'] as &$x ) { foreach ( array( 'nota', 'prio', 'motivo' ) as $k ) { if ( isset( $cx[ $x['ref'] ][ $k ] ) ) { $x[ $k ] = $cx[ $x['ref'] ][ $k ]; } } } unset( $x ); usort( $b['itens'], function ( $p, $q ) { return ( $q['nota'] ?? 5 ) <=> ( $p['nota'] ?? 5 ); } ); } unset( $b ); }
	return $bloco;
}
function dv_al_urgente( $x ) { $d = $x['encerra'] ? (int) floor( ( strtotime( substr( $x['encerra'], 0, 10 ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 99; return $d <= 5 || stripos( (string) $x['modalidade'], 'dispensa' ) !== false; }
add_action( 'dv_al_diario', function () {
	global $wpdb; @set_time_limit( 600 ); $rep = array();
	$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_alertas' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; }
		$GLOBALS['dv_al_cod'] = array();
		$bloco = dv_al_rodar( $uid, $desde ); if ( $bloco ) { dv_al_email( $uid, $bloco ); }
		if ( dv_al_falhou() ) { $rep[] = $uid; }
	}
	/* repescagem: quem teve alguma fonte fora do ar (PNCP, diários) roda de novo em 25 minutos; só o que for novo entra */
	if ( ! empty( $rep ) ) { wp_schedule_single_event( time() + 1500, 'dv_al_repescar', array( $rep ) ); }
} );
function dv_al_falhou() { foreach ( (array) ( $GLOBALS['dv_al_cod'] ?? array() ) as $v ) { if ( strpos( (string) $v, '200 ' ) !== 0 ) { return true; } } return false; }
add_action( 'dv_al_repescar', function ( $uids ) {
	@set_time_limit( 600 ); $desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) );
	foreach ( (array) $uids as $uid ) { $uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; } $bloco = dv_al_rodar( $uid, $desde ); if ( $bloco ) { dv_al_email( $uid, $bloco ); } }
	$l = (array) get_option( 'dv_al_rep_log', array() ); array_unshift( $l, current_time( 'mysql' ) . ' · repescagem de ' . count( (array) $uids ) . ' cliente(s)' ); update_option( 'dv_al_rep_log', array_slice( $l, 0, 30 ), false );
}, 10, 1 );
/* Rodadas extras às 12h40 e 17h40: novidades vão para a caixa; só manda e-mail do que é urgente (dispensa ou prazo de até 5 dias) */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'dv_al_extra', array( 1 ) ) ) { wp_schedule_event( strtotime( 'tomorrow 15:40 UTC' ), 'daily', 'dv_al_extra', array( 1 ) ); }
	if ( ! wp_next_scheduled( 'dv_al_extra', array( 2 ) ) ) { wp_schedule_event( strtotime( 'tomorrow 20:40 UTC' ), 'daily', 'dv_al_extra', array( 2 ) ); }
} );
add_action( 'dv_al_extra', function () {
	global $wpdb; @set_time_limit( 600 );
	$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 days' ) );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_alertas' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; }
		$bloco = dv_al_rodar( $uid, $desde ); $urg = array();
		foreach ( $bloco as $b ) { $it = array_values( array_filter( $b['itens'], 'dv_al_urgente' ) ); if ( $it ) { $urg[] = array( 'a' => $b['a'], 'itens' => $it ); } }
		if ( $urg ) { dv_al_email( $uid, $urg, true ); }
	}
}, 10, 1 );
function dv_al_email( $uid, $bloco, $urgente = false ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	if ( function_exists( 'dv_ap_ordenar' ) ) { $bloco = dv_ap_ordenar( $uid, $bloco ); }
	$n = 0; foreach ( $bloco as $b ) { $n += count( $b['itens'] ); }
	$g = home_url( '/plataforma/licitacoes/' );
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:18px;letter-spacing:2px;font-weight:700">DESENVOLVE </span><b style="color:#C9A45C;font-size:18px;letter-spacing:2px">ESTRATÉGIAS</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Oportunidades do dia · ' . date_i18n( 'd/m/Y' ) . '</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Bom dia, ' . esc_html( $u->display_name ) . '. Encontramos <b>' . $n . ' licitação(ões) aberta(s)</b> novas para os seus alertas:</p>';
	foreach ( $bloco as $b ) {
		$h .= '<h3 style="font-size:15px;color:#A8843F;letter-spacing:.08em;text-transform:uppercase;margin:22px 0 6px">' . esc_html( $b['a']['nome'] ) . ' (' . count( $b['itens'] ) . ')</h3>';
		foreach ( $b['itens'] as $x ) {
			$h .= '<div style="border-left:4px solid #C9A45C;background:#FAF8F3;padding:12px 14px;margin:8px 0;border-radius:8px"><div style="font-size:13px;color:#6E6A60">' . ( isset( $x['prio'] ) ? '<span style="background:' . ( $x['prio'] === 'alta' ? '#EAF5EE;color:#1F5A3B' : ( $x['prio'] === 'media' ? '#FBF1DC;color:#7A5410' : '#F3F0E8;color:#6E6A60' ) ) . ';border-radius:6px;padding:2px 7px;font-weight:bold">Prioridade ' . ( $x['prio'] === 'media' ? 'média' : $x['prio'] ) . ' · ' . (int) $x['nota'] . '/10</span> ' . esc_html( (string) ( $x['motivo'] ?? '' ) ) . '<br>' : ( ( $x['_ap'] ?? 0 ) >= 65 ? '<span style="background:#EAF5EE;color:#1F5A3B;border-radius:6px;padding:2px 7px;font-weight:bold">⭐ parecido com o que você aproveita</span><br>' : '' ) ) . '<b style="color:#16191E">' . esc_html( $x['orgao'] ) . '</b> · ' . esc_html( $x['municipio'] . '/' . $x['uf'] ) . ' · ' . esc_html( $x['modalidade'] ) . ( $x['valor'] ? ' · <b>' . esc_html( 'R$ ' . number_format( $x['valor'], 2, ',', '.' ) ) . '</b>' : '' ) . '</div><p style="font-size:14px;line-height:1.45;margin:6px 0">' . esc_html( mb_substr( $x['objeto'], 0, 320 ) ) . '</p>' . ( ( $x['fonte'] ?? '' ) === 'diario' ? '<div style="font-size:12px;color:#6E6A60;margin:-2px 0 6px">' . esc_html( $x['fonte_nome'] . ' · publicado em ' . date_i18n( 'd/m/Y', strtotime( $x['publicado'] ) ) ) . ( $x['encerra'] ? esc_html( ' · sessão ' . date_i18n( 'd/m/Y', strtotime( substr( $x['encerra'], 0, 10 ) ) ) ) : '' ) . '</div><a href="' . esc_url( $x['link'] ) . '" style="color:#8A6A2C;font-weight:bold;margin-right:14px">Ver no diário oficial</a><a href="' . esc_url( home_url( '/plataforma/alertas/' ) ) . '" style="color:#8A6A2C;font-weight:bold">Abrir na caixa de oportunidades</a>' : '<a href="' . esc_url( $x['link'] ) . '" style="color:#8A6A2C;font-weight:bold;margin-right:14px">Ver no PNCP</a><a href="' . esc_url( add_query_arg( 'importar', $x['ref'], $g ) ) . '" style="color:#8A6A2C;font-weight:bold">Levar para a minha gestão</a>' ) . '</div>';
		}
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( home_url( '/plataforma/alertas/' ) ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Ajustar meus alertas</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Fontes: Portal Nacional de Contratações Públicas (PNCP), diários oficiais municipais (Querido Diário) e Diário Oficial da União, seção 3. Avisos de diário oficial trazem só o resumo: confira o edital completo no site do órgão.</p></div></div>';
	$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, ( $urgente ? '⚡ Prazo curto: ' : '' ) . $n . ' licitação(ões) nova(s) para você · Desenvolve', $h, $hd ); } }
}

add_shortcode( 'dv_alertas', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="al" id="dv-al" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="al-cx"><div class="al-cxh"><h3>📥 Caixa de oportunidades</h3><span><button type="button" class="g al-pf">Perfil da empresa</button> <button type="button" class="g al-atu">Atualizar agora</button></span></div>
	<form class="al-pff" hidden><p class="al-h">Com o perfil, cada oportunidade ganha uma <b>nota de 0 a 10</b> e uma prioridade (alta, média ou baixa), com o motivo.</p>
	<label>O que a empresa vende ou presta<textarea name="vende" rows="2" placeholder="Ex.: limpeza e conservação predial, portaria, jardinagem"></textarea></label>
	<label>O que a empresa NÃO faz<input name="nao" placeholder="Ex.: limpeza hospitalar, piscina, obras"></label>
	<div class="al-2"><label>Onde atua<input name="regioes" placeholder="Ex.: SP, MG, RJ"></label><label>Porte ou capacidade<input name="porte" placeholder="Ex.: contratos até R$ 5 milhões; até 200 postos"></label></div>
	<label>Observações<input name="obs" placeholder="Ex.: preferimos contratos de 12 meses ou mais; não temos atestado de hospital"></label>
	<div class="al-act"><button type="submit">Salvar perfil e reavaliar</button><span class="al-h al-pfm"></span></div></form><p class="al-h">Tudo o que os seus alertas encontram cai aqui. Marque <b>Quero</b> (vai para a sua Gestão de licitações) ou <b>Descartar</b>. Os alertas rodam às 7h40, 12h40 e 17h40.</p><div class="al-tabs"></div><div class="al-pri"></div><div class="al-cxl"></div></div>
	<form class="al-f" autocomplete="off">
		<h3 class="al-ft">Novo alerta</h3>
		<label>O que você vende? <small>Palavras ou expressões separadas por vírgula. Cada uma vira uma busca.</small><input name="termos" placeholder="limpeza predial, conservação, serviços de higienização"></label>
		<div class="al-2"><label>Tipo<select name="tipo"><option value="">Serviços e compras</option><option value="servico">Só serviços</option><option value="compra">Só compras</option></select></label><label>Nome do alerta<input name="nome" placeholder="Ex.: Limpeza em SP"></label></div>
		<div class="al-2" style="grid-template-columns:1fr"><label>Excluir quando aparecer<input name="excluir" placeholder="Ex.: veículos, piscina"></label></div>
		<label>Estados <small>(nenhum marcado = Brasil todo)</small><div class="al-ufs"></div></label>
		<div class="al-2"><label>Valor mínimo (R$)<input name="vmin" inputmode="decimal" placeholder="opcional"></label><label>Valor máximo (R$)<input name="vmax" inputmode="decimal" placeholder="opcional"></label></div>
		<input type="hidden" name="id">
		<div class="al-act"><button type="button" class="g al-test">Ver o que ele encontra</button><button type="submit">Salvar alerta</button><button type="button" class="g al-novo" hidden>Cancelar edição</button></div>
		<p class="al-m al-h">Todo dia, por volta das 7h40, você recebe por e-mail as licitações abertas publicadas no PNCP e nos diários oficiais (municípios e Diário Oficial da União) desde a véspera, e elas entram na Caixa de oportunidades. Às 12h40 e às 17h40 o sistema confere de novo: o que for novo entra na caixa, e dispensas ou editais com prazo de até 5 dias chegam também por e-mail na hora. Os e-mails vão para os mesmos endereços dos avisos de prazo.</p>
	</form>
	<h3>Meus alertas</h3><div class="al-lista"><p class="al-h">Carregando…</p></div>
	<div class="al-res"></div>
</div>
<style>
.al{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.al h3{font-size:1.05rem;margin:22px 0 10px}.al-ft{margin-top:0!important}.al small{color:var(--mu);font-weight:500}
.al button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.al button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:9px 14px}
.al select{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.al input{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.al-f{border:1px solid var(--ln);border-radius:16px;padding:18px 20px;background:#fff;display:grid;gap:12px}.al-f label{font-weight:600;font-size:.88rem;display:grid;gap:5px}
.al-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.al-ufs{display:flex;flex-wrap:wrap;gap:5px}.al-ufs span{border:1px solid var(--ln);border-radius:8px;padding:5px 8px;font-size:.8rem;cursor:pointer;font-weight:600;background:#fff}.al-ufs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.al-act{display:flex;gap:8px;flex-wrap:wrap}.al-h{color:var(--mu);font-size:.86rem;margin:0}.al-m.e{color:var(--rd)}.al-m.o{color:var(--gr);font-weight:600}
.al-a{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.al-a b{display:block}.al-a span{color:var(--mu);font-size:.82rem}.al-a .x{display:flex;gap:6px}
.al-i{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px}.al-i .m{color:var(--mu);font-size:.8rem}.al-i .m b{color:#16191E}.al-i p{margin:6px 0;font-size:.92rem;line-height:1.45}.al-i .k{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.al-i a{color:#8A6A2C;font-weight:700;font-size:.85rem}.al-i button{font-size:.8rem;padding:6px 12px}
.al-fo{display:inline-block;background:#EEF2F7;color:#2B4A6B;border-radius:6px;padding:1px 7px;font-size:.72rem;font-weight:700;margin-right:4px;text-transform:uppercase;letter-spacing:.04em}.al-dio{border-left:3px solid #8FA7C2}
.al-pri{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 8px}.al-pri span{border:1px solid var(--ln);border-radius:20px;padding:4px 10px;font-size:.78rem;cursor:pointer;font-weight:600;background:#fff}.al-pri span.on{background:#C9A45C;border-color:#C9A45C;color:#241A06}.al-pri .al-prw{border:0;background:none;cursor:default}
.al-pr{font-weight:800!important}.al-mot{font-size:.84rem;color:#5E5A52;background:#FAF8F3;border-radius:8px;padding:6px 9px;margin:2px 0 4px}
.al-pff{display:grid;gap:10px;background:#fff;border:1px solid var(--ln);border-radius:14px;padding:14px 16px;margin:12px 0}.al-pff label{font-weight:600;font-size:.86rem;display:grid;gap:4px}.al-pff textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;width:100%;box-sizing:border-box}
.al-tag{display:inline-block;background:#FFF4DC;color:#9A6B12;border-radius:99px;padding:1px 8px;font-size:.72rem;font-weight:700;margin-left:6px}
.al-cx{border:1px solid var(--ln);border-radius:16px;padding:16px 20px;background:#FAF8F3;margin-bottom:22px}.al-cxh{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.al-cxh h3{margin:0!important}
.al-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 8px}.al-tabs span{border:1px solid var(--ln);border-radius:20px;padding:6px 12px;font-size:.85rem;cursor:pointer;font-weight:600;background:#fff}.al-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.al-ch{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.al-ch span{font-size:.76rem;font-weight:700;border-radius:8px;padding:3px 8px;background:#F3F0E8}.al-ch .r{background:#FDEDEA;color:#9A3524}.al-ch .a{background:#FBF1DC;color:#7A5410}.al-ch .v{background:#EAF5EE;color:#1F5A3B}
.al-ba{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.al-ba a,.al-ba button{font-size:.8rem!important;font-weight:600!important;border:1px solid var(--ln)!important;border-radius:9px!important;padding:7px 11px!important;text-decoration:none;color:#16191E!important;background:#fff!important;cursor:pointer}.al-ba .p{background:#C9A45C!important;border-color:#C9A45C!important;color:#241A06!important}.al-ba .ok{background:#EAF5EE!important;color:#1F5A3B!important}.al-ba .x{margin-left:auto;color:#6E6A60!important}
@media(max-width:700px){.al{padding:16px}.al-2{grid-template-columns:1fr}.al-ba .x{margin-left:0}}
</style>
<script>
(function(){var R=document.getElementById('dv-al');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,F=R.querySelector('.al-f'),M=R.querySelector('.al-m'),ufs={};
var UF='AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):''}
function num(v){return String(v||'').replace(/\./g,'').replace(',','.')}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function pUfs(){R.querySelector('.al-ufs').innerHTML=UF.map(function(u){return'<span data-uf="'+u+'" class="'+(ufs[u]?'on':'')+'">'+u+'</span>'}).join('')}
function dados(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});o.ufs=Object.keys(ufs).filter(function(k){return ufs[k]});o.vmin=num(o.vmin);o.vmax=num(o.vmax);return o}
function limpar(){F.reset();ufs={};pUfs();R.querySelector('.al-ft').textContent='Novo alerta';R.querySelector('.al-novo').hidden=true}
var CX=[],cab='',GEST={},PRI='',IA=false,PRZ=false;
function prS(x){if(x.nota==null)return'';var c=x.prio==='alta'?'v':x.prio==='media'?'a':'',t=x.prio==='media'?'média':x.prio;return'<span class="al-pr '+c+'" title="'+e(x.motivo||'')+'">Prioridade '+t+' · '+x.nota+'/10</span>'}
function prM(x){return x.nota==null?'':'<div class="al-mot">'+(x.nota_ia?'🤖 ':'')+e(x.motivo||'')+'</div>'}
function priorizar(){if(PRZ)return;if(!CX.some(function(x){return!x.st&&x.nota==null}))return;PRZ=true;var P=R.querySelector('.al-pri');P.insertAdjacentHTML('beforeend','<span class="al-h al-prw">Dando prioridade às oportunidades…</span>');
 req('POST','alertas/priorizar').then(function(j){CX=ordena(j.caixa||CX);IA=j.ia;PRZ=false;caixa()}).catch(function(){PRZ=false;var w=R.querySelector('.al-prw');if(w)w.remove()})}
function ordena(l){return l.slice().sort(function(a,b){return((b.nota==null?-1:b.nota)-(a.nota==null?-1:a.nota))||String(b.achado||'').localeCompare(String(a.achado||''))})}
function dias(d){if(!d)return null;var a=new Date(String(d).slice(0,10)+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);return Math.round((a-h)/864e5)}
function brh(d){return d?br(d)+(String(d).length>15&&String(d).slice(11,16)!=='00:00'?' às '+String(d).slice(11,16):''):''}
function caixa(){var n={'':0,quero:0,desc:0};CX.forEach(function(x){n[x.st||'']++});var T=R.querySelector('.al-tabs');T.innerHTML=[['','Novas'],['quero','Quero'],['desc','Descartadas']].map(function(t){return'<span data-cx="'+t[0]+'" class="'+(cab===t[0]?'on':'')+'">'+t[1]+' ('+n[t[0]]+')</span>'}).join('');
 var np={alta:0,media:0,baixa:0};CX.forEach(function(x){if((x.st||'')===cab&&x.prio)np[x.prio]++});R.querySelector('.al-pri').innerHTML=(np.alta+np.media+np.baixa)?[['','Todas'],['alta','Alta ('+np.alta+')'],['media','Média ('+np.media+')'],['baixa','Baixa ('+np.baixa+')']].map(function(t){return'<span data-pr="'+t[0]+'" class="'+(PRI===t[0]?'on':'')+'">'+t[1]+'</span>'}).join(''):'';
 var l=CX.filter(function(x){return(x.st||'')===cab&&(!PRI||x.prio===PRI)});var L=R.querySelector('.al-cxl');
 L.innerHTML=l.length?l.map(function(x){var d=dias(x.encerra),cl=d===null?'':d<=3?'r':d<=7?'a':'',c=x.ref.split('/'),g=x.lic||GEST[x.ref]||(x.link&&GEST[x.link]),dio=x.fonte==='diario';if(dio)return dioC(x,d,cl,g);return'<div class="al-i" data-r="'+e(x.ref)+'"><div class="m"><b>'+e(x.orgao)+'</b> · '+e(x.municipio+'/'+x.uf)+' · '+e(x.modalidade)+' · alerta “'+e(x.alerta||'')+'”</div><p>'+e(x.objeto)+'</p>'+prM(x)+
 '<div class="al-ch">'+prS(x)+(d!==null?'<span class="'+cl+'">'+(d>1?'⏳ faltam '+d+' dias':d===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+brh(x.encerra)+'</span>':'')+'<span class="al-val">'+(x.valor?moeda(x.valor):'Valor estimado: …')+'</span>'+'<span>Publicado '+br(x.publicado)+'</span></div>'+
 '<div class="al-ba">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-q>Quero · levar para a Gestão</button>')+'<button type="button" data-an>Analisar edital</button><a href="/plataforma/raio-x-comprador/?cnpj='+c[0]+'" target="_blank" rel="noopener">Raio-X do órgão · '+((window.DVC&&DVC.comprador)||5)+' créditos</a><a href="/plataforma/licitacoes/?hist='+encodeURIComponent(x.ref)+'&obj='+encodeURIComponent(String(x.objeto||'').slice(0,200))+'">📊 Licitações anteriores</a><a href="'+e(x.link)+'" target="_blank" rel="noopener">Edital no PNCP</a>'+(cab==='desc'?'<button type="button" class="x" data-st="">Restaurar</button>':'<button type="button" class="x" data-st="desc">Descartar</button>')+'</div></div>'}).join(''):'<p class="al-h">'+(!S?'Carregando a caixa de oportunidades…':cab===''?(S.alertas.length?'Nenhuma oportunidade nova agora. Clique em “Atualizar agora” ou espere a próxima rodada.':'Crie um alerta abaixo: o que ele encontrar cai aqui.'):'Nada por aqui.')+'</p>';valores()}
function loc(x){return[x.municipio,x.uf].filter(Boolean).join('/')}
function dioC(x,d,cl,g){return'<div class="al-i al-dio" data-r="'+e(x.ref)+'"><div class="m"><span class="al-fo">Diário oficial</span> <b>'+e(x.orgao)+'</b>'+(loc(x)?' · '+e(loc(x)):'')+' · '+e(x.modalidade)+(x.numero?' nº '+e(x.numero):'')+(x.alerta?' · alerta “'+e(x.alerta)+'”':'')+'</div><p>'+e(x.objeto)+'</p>'+prM(x)+
 '<div class="al-ch">'+prS(x)+(d!==null?'<span class="'+cl+'">'+(d>1?'⏳ faltam '+d+' dias':d===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+brh(x.encerra)+'</span>':'<span>Data da sessão: ver no aviso</span>')+'<span>Publicado '+br(x.publicado)+' · '+e(x.fonte_nome||'Diário oficial')+'</span></div>'+
 '<div class="al-ba">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-q>Quero · levar para a Gestão</button>')+'<button type="button" data-mia-al>Analisar com a minha IA</button><a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no diário oficial</a>'+(cab==='desc'?'<button type="button" class="x" data-st="">Restaurar</button>':'<button type="button" class="x" data-st="desc">Descartar</button>')+'</div></div>'}
function dioLic(x){return{orgao:x.orgao,objeto:x.objeto,municipio:x.municipio,uf:x.uf,modalidade:x.modalidade,numero:x.numero||'',abertura:x.encerra?String(x.encerra).replace(' ','T'):'',link:x.link,status:'analise',notas:'Encontrada no '+(x.fonte_nome||'diário oficial')+' em '+br(x.publicado)+'. Confira o aviso completo e baixe o edital no site do órgão.\n\nTrecho: '+String(x.trecho||'').slice(0,700)}}
function dioTxt(x){return'Órgão: '+x.orgao+(loc(x)?'\nLocal: '+loc(x):'')+'\nModalidade: '+x.modalidade+(x.numero?' nº '+x.numero:'')+(x.encerra?'\nSessão: '+brh(x.encerra):'')+'\nPublicado em: '+br(x.publicado)+' ('+(x.fonte_nome||'diário oficial')+')\n\nTrecho do aviso:\n'+(x.trecho||x.objeto)+'\n\nLink: '+x.link}
var VAL={};function valores(){[].slice.call(R.querySelectorAll('.al-cxl .al-i')).slice(0,30).forEach(function(c){var r=c.dataset.r,sp=c.querySelector('.al-val');if(!sp||VAL[r]===null||!/^\d{14}\//.test(r))return;var put=function(v){if(!v){sp.remove();return}sp.className='al-val v';sp.textContent=v.sig?'Valor estimado: sigiloso':v.v?'Valor estimado: '+moeda(v.v):'Valor estimado: não informado'};if(VAL[r])return put(VAL[r]);var k=r.split('/');fetch('https://pncp.gov.br/api/consulta/v1/orgaos/'+k[0]+'/compras/'+k[1]+'/'+k[2]).then(function(x){return x.json()}).then(function(j){VAL[r]={v:j.valorTotalEstimado,sig:j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1};put(VAL[r])}).catch(function(){VAL[r]=null;sp.remove()})})}
function marca(ref,st,lic){var x=CX.filter(function(y){return y.ref===ref})[0];if(x){x.st=st;if(lic)x.lic=lic}caixa();return req('POST','alertas/caixa',{ref:ref,st:st,lic:lic||''})}
function gestao(x,b,depois){b.disabled=true;var t0=b.textContent;b.textContent='Salvando…';(x.fonte==='diario'?req('POST','lic/salvar',dioLic(x)):req('GET','lic/pncp?ref='+encodeURIComponent(x.ref)).then(function(j){j.status='analise';return req('POST','lic/salvar',j)})).then(function(r){GEST[x.ref]=r.id;marca(x.ref,'quero',r.id);if(depois)depois(r.id)}).catch(function(j){b.disabled=false;b.textContent=t0;alert((j&&j.message)||'Não foi possível salvar agora.')})}
R.querySelector('.al-cx').addEventListener('click',function(ev){var t=ev.target;
 if(t.dataset.cx!=null){cab=t.dataset.cx;PRI='';caixa();return}
 if(t.dataset.pr!=null&&t.closest('.al-pri')){PRI=t.dataset.pr;caixa();return}
 if(t.matches('.al-pf')){var f=R.querySelector('.al-pff');f.hidden=!f.hidden;if(!f.hidden)req('GET','perfil').then(function(j){var p=j.perfil||{};['vende','nao','regioes','porte','obs'].forEach(function(k){f.querySelector('[name='+k+']').value=p[k]||''})});return}
 if(t.matches('.al-atu')){t.disabled=true;t.textContent='Buscando…';req('POST','alertas/atualizar').then(function(j){t.textContent=j.novas?j.novas+' nova(s)':'Nada novo';return carregar()}).catch(function(){t.textContent='Tente de novo'}).then(function(){setTimeout(function(){t.disabled=false;t.textContent='Atualizar agora'},2500)});return}
 var c=t.closest('.al-i');if(!c)return;var x=CX.filter(function(y){return y.ref===c.dataset.r})[0];if(!x)return;
 if(t.hasAttribute('data-q'))gestao(x,t);
 else if(t.hasAttribute('data-mia-al')){if(window.dvMinhaIA)dvMinhaIA('licitacao',dioTxt(x))}
 else if(t.hasAttribute('data-an')){var go=function(id){location.href='/plataforma/licitacoes/?ver='+id+'&analisar=1'};var g=x.lic||GEST[x.ref];if(g)go(g);else gestao(x,t,go)}
 else if(t.dataset.st!=null)marca(x.ref,t.dataset.st)});
req('GET','lic').then(function(j){(j.itens||[]).forEach(function(y){if(y.pncp)GEST[y.pncp]=y.id;if(y.link&&/in\.gov\.br|queridodiario|diario/i.test(y.link))GEST[y.link]=y.id});caixa()}).catch(function(){});
R.querySelector('.al-pff').addEventListener('submit',function(ev){ev.preventDefault();var f=ev.target,o={},m=f.querySelector('.al-pfm');['vende','nao','regioes','porte','obs'].forEach(function(k){o[k]=f.querySelector('[name='+k+']').value});m.textContent='Salvando…';req('POST','perfil',o).then(function(){m.textContent='Perfil salvo. Reavaliando as oportunidades…';CX.forEach(function(x){delete x.nota;delete x.prio;delete x.motivo});PRZ=false;priorizar();setTimeout(function(){f.hidden=true;m.textContent=''},1500)}).catch(function(){m.textContent='Não foi possível salvar agora.'})});
function carregar(){return req('GET','alertas').then(function(j){S=j;CX=ordena(j.caixa||[]);caixa();priorizar();R.querySelector('.al-lista').innerHTML=j.alertas.length?j.alertas.map(function(a){return'<div class="al-a"><div><b>'+e(a.nome)+(a.ativo?'':' <span class="al-tag">pausado</span>')+'</b><span>'+e(a.termos)+' · '+(a.ufs&&a.ufs.length?a.ufs.join(', '):'Brasil todo')+(a.vmin?' · a partir de '+moeda(a.vmin):'')+(a.vmax?' · até '+moeda(a.vmax):'')+(a.excluir?' · sem: '+e(a.excluir):'')+(a.tipo?' · só '+(a.tipo==='servico'?'serviços':'compras'):'')+'</span></div><div class="x"><button class="g" data-v="'+a.id+'">Ver agora</button><button class="g" data-ed="'+a.id+'">Editar</button><button class="g" data-p="'+a.id+'">'+(a.ativo?'Pausar':'Ativar')+'</button><button class="g" data-x="'+a.id+'">Excluir</button></div></div>'}).join(''):'<p class="al-h">Nenhum alerta ainda. Crie o primeiro acima: em segundos você vê quantas licitações abertas ele encontra.</p>'})}
var PREV=[];function mostrar(j,titulo){PREV=j.itens||[];var nd=PREV.filter(function(x){return x.fonte==='diario'}).length;var Rr=R.querySelector('.al-res');Rr.innerHTML='<h3>'+e(titulo)+': '+j.total+' licitação(ões) aberta(s) nos últimos 15 dias</h3>'+(nd?'<p class="al-h" style="margin:-4px 0 10px">'+(j.total-nd)+' do PNCP e '+nd+' de diários oficiais (municípios e Diário Oficial da União).</p>':'')+(j.itens.length?j.itens.map(function(x,i){var dio=x.fonte==='diario';return'<div class="al-i'+(dio?' al-dio':'')+'"><div class="m">'+(dio?'<span class="al-fo">Diário oficial</span> ':'')+'<b>'+e(x.orgao)+'</b>'+(loc(x)?' · '+e(loc(x)):'')+' · '+e(x.modalidade)+(dio&&x.numero?' nº '+e(x.numero):'')+' · publicado em '+br(x.publicado)+(dio?' · '+e(x.fonte_nome):'')+(x.encerra?' · sessão '+brh(x.encerra):'')+(x.valor?' · <b>'+moeda(x.valor)+'</b>':'')+'</div><p>'+e(x.objeto)+'</p><div class="k">'+(dio?'<a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no diário oficial</a><button data-impd="'+i+'">Levar para a minha gestão</button>':'<a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no PNCP</a><button data-imp="'+e(x.ref)+'">Levar para a minha gestão</button>')+x.termos.map(function(t){return'<span class="al-tag">'+e(t)+'</span>'}).join('')+'</div></div>'}).join(''):'<p class="al-h">Nada encontrado. Tente palavras mais gerais ou mais estados.</p>');Rr.scrollIntoView({behavior:'smooth'})}
function testar(o,t){M.className='al-m al-h';M.textContent='Buscando no PNCP e nos diários oficiais…';return req('POST','alertas/testar',o).then(function(j){M.textContent='';mostrar(j,t)}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'As fontes não responderam agora. Tente de novo.'})}
F.addEventListener('submit',function(ev){ev.preventDefault();M.className='al-m al-h';M.textContent='Salvando…';req('POST','alertas/salvar',dados()).then(function(){M.className='al-m al-h o';M.textContent='Alerta salvo. Amanhã cedo chega o primeiro e-mail.';limpar();carregar()}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'Erro ao salvar.'})});
R.addEventListener('click',function(ev){var t=ev.target,a;
 if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];pUfs();return}
 if(t.matches('.al-test')){testar(dados(),'Prévia');return}
 if(t.matches('.al-novo')){limpar();return}
 a=S&&S.alertas.filter(function(x){return x.id===(t.dataset.v||t.dataset.ed||t.dataset.p||t.dataset.x)})[0];
 if(t.dataset.v&&a){testar(a,a.nome);return}
 if(t.dataset.ed&&a){limpar();F.querySelector('[name=tipo]').value=a.tipo||'';['nome','termos','excluir','id'].forEach(function(k){F.querySelector('[name='+k+']').value=a[k]||''});F.querySelector('[name=vmin]').value=a.vmin||'';F.querySelector('[name=vmax]').value=a.vmax||'';(a.ufs||[]).forEach(function(u){ufs[u]=true});pUfs();R.querySelector('.al-ft').textContent='Editar alerta';R.querySelector('.al-novo').hidden=false;F.scrollIntoView({behavior:'smooth'});return}
 if(t.dataset.p&&a){var o=Object.assign({},a,{ativo:a.ativo?0:1});req('POST','alertas/salvar',o).then(carregar);return}
 if(t.dataset.x&&a){if(confirm('Excluir o alerta "'+a.nome+'"?'))req('POST','alertas/excluir',{id:a.id}).then(carregar);return}
 if(t.dataset.impd!=null){var y=PREV[+t.dataset.impd];if(!y)return;t.disabled=true;t.textContent='Importando…';req('POST','lic/salvar',dioLic(y)).then(function(){t.textContent='Na sua gestão ✓'}).catch(function(j){t.disabled=false;t.textContent='Tentar de novo'});return}
 if(t.dataset.imp){t.disabled=true;t.textContent='Importando…';req('GET','lic/pncp?ref='+encodeURIComponent(t.dataset.imp)).then(function(j){return req('POST','lic/salvar',j)}).then(function(){t.textContent='Na sua gestão ✓';var cn=String(t.dataset.imp||'').slice(0,14);if(/^\d{14}$/.test(cn)&&!t.nextElementSibling?.classList?.contains('al-rx')){var a=document.createElement('a');a.className='g al-rx';a.href='/plataforma/raio-x-comprador/?cnpj='+cn;a.textContent='Raio-X do órgão →';a.style.cssText='font-size:.85rem;font-weight:600;color:#16191E;border:1px solid #E3DED2;border-radius:10px;padding:8px 12px;text-decoration:none';t.after(a)}}).catch(function(j){t.disabled=false;t.textContent=(j&&j.message)?'Tentar de novo':'Erro'})}});
(function(){var q=new URLSearchParams(location.search);if(q.get('termos')){F.querySelector('[name=termos]').value=q.get('termos');if(q.get('nome'))F.querySelector('[name=nome]').value=q.get('nome');if(q.get('tipo'))F.querySelector('[name=tipo]').value=q.get('tipo');(q.get('uf')||'').split(',').forEach(function(u){if(u)ufs[u.toUpperCase()]=1});M.className='al-m al-h o';M.textContent='Alerta pré-preenchido com a sua busca. Confira e salve.';history.replaceState(null,'',location.pathname)}})();
pUfs();carregar();
})();
</script>
	<?php return ob_get_clean();
} );

/* Painel: aviso das oportunidades novas na caixa */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( 'painel' ) ) { return; }
	$n = 0; $urg = 0; $alta = 0; foreach ( dv_al_caixa( get_current_user_id() ) as $x ) { if ( ( $x['st'] ?? '' ) === '' ) { $n++; if ( dv_al_urgente( $x ) ) { $urg++; } if ( ( $x['prio'] ?? '' ) === 'alta' ) { $alta++; } } }
	if ( ! $n ) { return; } ?>
<script>(function(){var el=document.getElementById('dv-painel');if(!el)return;var b=document.createElement('a');b.href='/plataforma/alertas/';b.style.cssText='display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#16191E;border:1px solid #C9A45C;border-radius:14px;padding:14px 18px;margin:0 0 18px;color:#F4F2EC;text-decoration:none;font-family:Hanken Grotesk,sans-serif';
b.innerHTML='<span>📥 <b><?php echo (int) $n; ?> oportunidade(s) nova(s)</b> nos seus alertas<?php echo $alta ? ' · <b style="color:#9FD9B4">' . (int) $alta . ' de prioridade alta</b>' : ''; ?><?php echo $urg ? ' · <b style="color:#E59A8A">' . (int) $urg . ' com prazo curto</b>' : ''; ?></span><span style="background:#C9A45C;color:#241A06;border-radius:10px;padding:8px 14px;font-weight:700">Ver a caixa</span>';b.style.maxWidth=Math.max(320,el.getBoundingClientRect().width)+'px';b.style.marginLeft='auto';b.style.marginRight='auto';el.parentNode.insertBefore(b,el)})();</script>
	<?php
}, 96 );
