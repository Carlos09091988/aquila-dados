/**
 * Desenvolve — Meus contratos (carteira de contratos públicos da empresa do assinante)
 * - Importa os contratos da empresa (matriz e filiais) do PNCP e do Portal da Transparência.
 * - Controla fim de vigência, aniversário de reajuste/repactuação e vencimento da garantia contratual.
 * - Avisos por e-mail e botão para cotar seguro garantia com a Desenvolve (franquia de seguros).
 * Incluso na assinatura. Usa a lista de empresas da ferramenta Documentos (dv_docs_emps) e o dossiê (dv_ds_pncp).
 */

define( 'DV_CT_MAX', 500 );
define( 'DV_CT_WA', '5511993500950' );

function dv_ct_itens( $uid ) { $l = json_decode( get_user_meta( $uid, 'dv_contratos', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_ct_salvar_itens( $uid, $l ) { update_user_meta( $uid, 'dv_contratos', wp_slash( wp_json_encode( array_values( $l ), JSON_UNESCAPED_UNICODE ) ) ); }
function dv_ct_dias( $d, $hoje ) { return $d ? (int) floor( ( strtotime( substr( $d, 0, 10 ) ) - strtotime( $hoje ) ) / 86400 ) : null; }
/** Próximo aniversário da data-base (reajuste em sentido estrito ou repactuação: anualidade, art. 25, §§ 7º e 8º, e art. 135 da Lei 14.133/2021) */
function dv_ct_aniversario( $base, $hoje ) {
	if ( ! $base ) { return null; }
	$a = substr( $base, 0, 10 ); $p = $a;
	while ( $p <= $hoje ) { $p = gmdate( 'Y-m-d', strtotime( $p . ' +1 year' ) ); }
	return $p;
}
function dv_ct_calc( $c, $hoje ) {
	$c['dias_fim'] = dv_ct_dias( $c['fim'] ?? '', $hoje );
	$base = ! empty( $c['data_base'] ) ? $c['data_base'] : ( $c['inicio'] ?? ( $c['assinatura'] ?? '' ) );
	$c['prox_reajuste'] = dv_ct_aniversario( $base, $hoje );
	$c['dias_reajuste'] = dv_ct_dias( $c['prox_reajuste'], $hoje );
	$c['dias_garantia'] = dv_ct_dias( $c['garantia_vence'] ?? '', $hoje );
	$c['vigente'] = $c['dias_fim'] === null || $c['dias_fim'] >= 0;
	$c['verificar'] = ! $c['vigente'] && empty( $c['editado'] ) && ( $c['fonte'] ?? '' ) !== 'Manual';
	return $c;
}
function dv_ct_wa_link( $msg ) { return 'https://wa.me/' . DV_CT_WA . '?text=' . rawurlencode( $msg ); }

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/contratos', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' );
		return array( 'itens' => array_map( function ( $c ) use ( $hoje ) { return dv_ct_calc( $c, $hoje ); }, dv_ct_itens( $uid ) ), 'empresas' => function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array(), 'hoje' => $hoje, 'wa' => DV_CT_WA );
	} ) );
	/* Importa do PNCP (matriz, filiais e razão social) e do Portal da Transparência (contratos federais) */
	register_rest_route( $ns, '/contratos/importar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		@set_time_limit( 180 ); $uid = get_current_user_id(); $cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] ); $hoje = current_time( 'Y-m-d' );
		$ok = false; $nome = ''; foreach ( ( function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array() ) as $e ) { if ( $e['cnpj'] === $cnpj ) { $ok = true; $nome = $e['nome']; } }
		if ( ! $ok ) { return new WP_Error( 'dv_ct', 'Cadastre a empresa primeiro.', array( 'status' => 400 ) ); }
		if ( ! function_exists( 'dv_ds_pncp' ) ) { return new WP_Error( 'dv_ct', 'Serviço indisponível.', array( 'status' => 500 ) ); }
		$fil = function_exists( 'dv_capt_filiais' ) ? array_map( function ( $x ) { return explode( '|', $x )[0]; }, (array) dv_capt_filiais( $cnpj ) ) : array();
		$p = dv_ds_pncp( $cnpj, $nome, $fil ); if ( empty( $p['total'] ) || $p['total'] < 3 ) { sleep( 2 ); $p2 = dv_ds_pncp( $cnpj, $nome, $fil ); if ( $p2['total'] > $p['total'] ) { $p = $p2; } } $g = function_exists( 'dv_ds_cgu_contratos' ) ? dv_ds_cgu_contratos( $cnpj ) : array();
		$novos = array();
		foreach ( (array) $p['itens'] as $x ) { $novos[] = array( 'orgao' => $x['orgao'], 'uf' => $x['uf'], 'municipio' => $x['municipio'], 'numero' => $x['titulo'], 'objeto' => $x['objeto'], 'valor' => $x['valor'], 'assinatura' => substr( (string) $x['assinatura'], 0, 10 ), 'inicio' => substr( (string) $x['inicio'], 0, 10 ), 'fim' => substr( (string) $x['fim'], 0, 10 ), 'link' => $x['link'], 'fonte' => 'PNCP', 'fornecedor' => $x['fornecedor'] ); }
		foreach ( $g as $x ) { $novos[] = array( 'orgao' => $x['orgao'] . ( $x['unidade'] ? ' · ' . $x['unidade'] : '' ), 'uf' => '', 'municipio' => '', 'numero' => 'Contrato ' . $x['numero'], 'objeto' => $x['objeto'], 'valor' => $x['valor'], 'assinatura' => substr( (string) $x['assinatura'], 0, 10 ), 'inicio' => substr( (string) $x['inicio'], 0, 10 ), 'fim' => substr( (string) $x['fim'], 0, 10 ), 'link' => $x['link'], 'fonte' => 'Portal da Transparência', 'fornecedor' => $cnpj ); }
		$l = dv_ct_itens( $uid ); $chaves = array();
		foreach ( $l as $c ) { $chaves[ $c['chave'] ?? '' ] = 1; }
		$add = 0; $venc = 0;
		foreach ( $novos as $x ) {
			if ( $x['fim'] && $x['fim'] < gmdate( 'Y-m-d', strtotime( $hoje . ' -365 days' ) ) ) { $venc++; continue; } // vigentes e encerrados há menos de 1 ano (muitos foram prorrogados por aditivo e o PNCP não atualizou a data)
			$k = md5( $cnpj . '|' . round( $x['valor'] ) . '|' . substr( $x['inicio'], 0, 7 ) . '|' . substr( dv_al_norm_ct( $x['orgao'] ), 0, 12 ) );
			if ( isset( $chaves[ $k ] ) ) { continue; } $chaves[ $k ] = 1;
			$dup = false; foreach ( $l as $y ) { if ( ( $y['cnpj'] ?? '' ) === $cnpj && $x['valor'] > 0 && abs( (float) $y['valor'] - $x['valor'] ) < 1 && $y['inicio'] && $x['inicio'] && abs( strtotime( $y['inicio'] ) - strtotime( $x['inicio'] ) ) <= 45 * 86400 ) { $dup = true; break; } } if ( $dup ) { continue; } // mesmo contrato no PNCP e na CGU
			if ( count( $l ) >= DV_CT_MAX ) { break; }
			$l[] = $x + array( 'id' => substr( md5( uniqid( $k, true ) ), 0, 10 ), 'chave' => $k, 'cnpj' => $cnpj, 'valor_inicial' => $x['valor'], 'lei' => ( $x['inicio'] && $x['inicio'] < '2024-01-01' ) ? '' : '14133', 'data_base' => '', 'indice' => '', 'garantia_tipo' => '', 'garantia_valor' => 0, 'garantia_vence' => '', 'notas' => '', 'aviso' => '' ); $add++;
		}
		dv_ct_salvar_itens( $uid, $l );
		return array( 'ok' => true, 'adicionados' => $add, 'encontrados' => count( $novos ), 'encerrados' => $venc, 'pncp' => (int) $p['total'], 'cgu' => count( $g ) );
	} ) );
	register_rest_route( $ns, '/contratos/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_ct_itens( $uid ); $id = sanitize_key( (string) $r['id'] );
		$d = function ( $k ) use ( $r ) { $v = (string) $r[ $k ]; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$t = function ( $k, $n = 300 ) use ( $r ) { return mb_substr( sanitize_text_field( (string) $r[ $k ] ), 0, $n ); };
		$c = array( 'cnpj' => preg_replace( '/\D/', '', (string) $r['cnpj'] ), 'orgao' => $t( 'orgao', 200 ), 'uf' => strtoupper( $t( 'uf', 2 ) ), 'numero' => $t( 'numero', 100 ), 'objeto' => mb_substr( sanitize_textarea_field( (string) $r['objeto'] ), 0, 1000 ),
			'valor' => (float) $r['valor'], 'inicio' => $d( 'inicio' ), 'fim' => $d( 'fim' ), 'data_base' => $d( 'data_base' ), 'indice' => $t( 'indice', 80 ),
			'lei' => in_array( $r['lei'], array( '14133', '8666' ), true ) ? $r['lei'] : '', 'valor_inicial' => (float) $r['valor_inicial'], 'assinatura' => $d( 'assinatura' ),
			'gestor' => $t( 'gestor', 120 ), 'fiscal' => $t( 'fiscal', 120 ), 'preposto' => $t( 'preposto', 120 ),
			'garantia_tipo' => $t( 'garantia_tipo', 40 ), 'garantia_valor' => (float) $r['garantia_valor'], 'garantia_vence' => $d( 'garantia_vence' ), 'link' => esc_url_raw( (string) $r['link'] ), 'notas' => mb_substr( sanitize_textarea_field( (string) $r['notas'] ), 0, 2000 ) );
		if ( ! $c['orgao'] && ! $c['objeto'] ) { return new WP_Error( 'dv_ct', 'Informe ao menos o órgão ou o objeto.', array( 'status' => 400 ) ); }
		$achou = false; foreach ( $l as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $c ); $x['aviso'] = ''; $x['editado'] = 1; $achou = true; } } unset( $x );
		if ( ! $achou ) { if ( count( $l ) >= DV_CT_MAX ) { return new WP_Error( 'dv_ct', 'Limite de contratos atingido.', array( 'status' => 400 ) ); } $id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $c + array( 'id' => $id, 'chave' => 'manual-' . $id, 'fonte' => 'Manual', 'aviso' => '' ); }
		dv_ct_salvar_itens( $uid, $l ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/contratos/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $ids = array_map( 'sanitize_key', (array) $r['ids'] );
		dv_ct_salvar_itens( $uid, array_filter( dv_ct_itens( $uid ), function ( $x ) use ( $ids ) { return ! in_array( $x['id'], $ids, true ); } ) ); return array( 'ok' => true );
	} ) );
} );
/* Ficha do contrato: notificações ligadas e publicações nos diários oficiais */
function dv_ct_num( $n ) { // "Contrato nº 00026/2025" -> "26/2025"
	if ( preg_match( '#(\d{1,8})\s*/\s*(\d{4})#', (string) $n, $m ) ) { return ltrim( $m[1], '0' ) . '/' . $m[2]; }
	return '';
}
function dv_ct_diarios( $c, $nome ) {
	$num = dv_ct_num( $c['numero'] ?? '' ); $out = array(); if ( ! $num ) { return $out; }
	$ck = 'dv_ctd3_' . md5( $num . $nome . ( $c['municipio'] ?? '' ) ); $x = get_transient( $ck ); if ( is_array( $x ) ) { return $x; }
	$pal = array_slice( array_values( array_filter( explode( ' ', preg_replace( '/[^A-Z0-9 ]/', ' ', strtoupper( remove_accents( $nome ) ) ) ), function ( $w ) { return strlen( $w ) > 3 && ! in_array( $w, array( 'LTDA', 'EIRELI', 'EPP' ), true ); } ) ), 0, 2 );
	$desde = ! empty( $c['inicio'] ) ? gmdate( 'Y-m-d', strtotime( $c['inicio'] . ' -60 days' ) ) : gmdate( 'Y-m-d', strtotime( '-3 years' ) );
	$norm = function ( $t ) { return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', remove_accents( (string) $t ) ) ); };
	$mun = $norm( $c['municipio'] ?? '' );
	/* Diários municipais (Querido Diário): só da cidade do contrato */
	if ( $mun ) {
		$q = '"' . $num . '"' . ( $pal ? ' + "' . implode( ' ', $pal ) . '"' : '' );
		$u = add_query_arg( array( 'querystring' => rawurlencode( $q ), 'published_since' => $desde, 'size' => 50, 'excerpt_size' => 400, 'number_of_excerpts' => 2, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' );
		$r = wp_remote_get( $u, array( 'timeout' => 30 ) );
		if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
			foreach ( (array) ( json_decode( wp_remote_retrieve_body( $r ), true )['gazettes'] ?? array() ) as $g ) {
				if ( $norm( $g['territory_name'] ?? '' ) !== $mun ) { continue; }
				$out[] = array( 'data' => $g['date'] ?? '', 'fonte' => ( $g['territory_name'] ?? '' ) . ( ! empty( $g['state_code'] ) ? '/' . $g['state_code'] : '' ), 'url' => $g['url'] ?? '', 'trecho' => mb_substr( implode( ' … ', array_map( function ( $e ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e ) ) ); }, (array) ( $g['excerpts'] ?? array() ) ) ), 0, 700 ) );
			}
		}
	}
	/* Diário Oficial da União: busca pelo número do contrato e fica só com o que cita a empresa */
	if ( $pal ) {
		$u = 'https://www.in.gov.br/consulta/-/buscar/dou?q=' . rawurlencode( '"' . $num . '"' ) . '&s=todos&exactDate=personalizado&sortType=0&delta=75&publishFrom=' . gmdate( 'd-m-Y', strtotime( $desde ) ) . '&publishTo=' . current_time( 'd-m-Y' );
		$r = wp_remote_get( $u, array( 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ) );
		if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 && preg_match( '#<script[^>]*id="_br_com_seatecnologia_in_buscadou_BuscaDouPortlet_params"[^>]*>(.*?)</script>#s', wp_remote_retrieve_body( $r ), $m ) ) {
			$alvo = $norm( implode( '', $pal ) ); $raiz = substr( preg_replace( '/\D/', '', (string) ( $c['cnpj'] ?? '' ) ), 0, 8 );
			foreach ( (array) ( json_decode( trim( $m[1] ), true )['jsonArray'] ?? array() ) as $it ) {
				$tx = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( ( $it['title'] ?? '' ) . ' — ' . ( $it['content'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) );
				$nt = $norm( $tx ); if ( strpos( $nt, $alvo ) === false && ( ! $raiz || strpos( preg_replace( '/\D/', '', $tx ), $raiz ) === false ) ) { continue; }
				$d = (string) ( $it['pubDate'] ?? '' );
				$out[] = array( 'data' => preg_match( '#^(\d{2})/(\d{2})/(\d{4})#', $d, $y ) ? "$y[3]-$y[2]-$y[1]" : '', 'fonte' => 'Diário Oficial da União', 'url' => ! empty( $it['urlTitle'] ) ? 'https://www.in.gov.br/web/dou/-/' . $it['urlTitle'] : '', 'trecho' => mb_substr( $tx, 0, 700 ) );
			}
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	set_transient( $ck, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/contratos/ficha', array( 'methods' => 'GET', 'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); }, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] ); $hoje = current_time( 'Y-m-d' ); $c = null;
		foreach ( dv_ct_itens( $uid ) as $x ) { if ( $x['id'] === $id ) { $c = dv_ct_calc( $x, $hoje ); } }
		if ( ! $c ) { return new WP_Error( 'dv_ct', 'Contrato não encontrado.', array( 'status' => 404 ) ); }
		$not = array(); if ( function_exists( 'dv_rc_itens' ) ) { $T = dv_rc_tipos(); foreach ( dv_rc_itens( $uid ) as $x ) { if ( ( $x['contrato'] ?? '' ) === $id ) { $x = dv_rc_calc( $x, $hoje ); $x['tipo_nome'] = $T[ $x['tipo'] ][0] ?? $x['tipo']; $not[] = $x; } } }
		$nome = ''; foreach ( ( function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array() ) as $e ) { if ( $e['cnpj'] === $c['cnpj'] ) { $nome = $e['nome']; } }
		$num = dv_ct_num( $c['numero'] ); $radar = array();
		if ( $num ) { $radar = $wpdb->get_results( $wpdb->prepare( "SELECT data,municipio,uf,url,trecho FROM {$wpdb->prefix}dv_capt WHERE user_id=%d AND trecho LIKE %s ORDER BY data DESC LIMIT 30", $uid, '%' . $wpdb->esc_like( $num ) . '%' ), ARRAY_A ); }
		$pub = $r['diarios'] ? dv_ct_diarios( $c, $nome ) : null;
		$lics = array(); $ltodas = array();
		if ( function_exists( 'dv_lic_itens' ) ) { foreach ( dv_lic_itens( $uid ) as $x ) { $xx = function_exists( 'dv_lic_calc' ) ? dv_lic_calc( $x, $hoje ) : $x; $it = array( 'id' => $x['id'], 'rot' => trim( $x['orgao'] . ' · ' . $x['numero'], ' ·' ), 'objeto' => mb_substr( (string) $x['objeto'], 0, 200 ), 'status' => $x['status'], 'abertura' => $x['abertura'], 'prazo_imp' => $xx['prazo_imp'] ?? null ); if ( ( $x['contrato'] ?? '' ) === $id ) { $lics[] = $it; } else { $ltodas[] = $it; } } }
		$o = array( 'contrato' => $c, 'notificacoes' => $not, 'radar' => $radar, 'diarios' => $pub, 'numero_busca' => $num, 'licitacoes' => $lics, 'lic_outras' => $ltodas, 'competencia' => dv_ct_comp( 'mensal', $hoje ), 'hoje' => $hoje );
		if ( $r['debug'] && current_user_can( 'manage_options' ) ) { delete_transient( 'dv_ctd3_' . md5( $num . $nome . ( $c['municipio'] ?? '' ) ) ); $o['diarios'] = dv_ct_diarios( $c, $nome ); $o['dbg'] = $GLOBALS['dv_ct_dbg'] ?? null; $o['nome'] = $nome; }
		return $o;
	} ) );
} );
/* Competência do checklist: mensal 2026-10, trimestral 2026-T4, anual 2026, única */
function dv_ct_comp( $per, $hoje ) {
	if ( $per === 'trimestral' ) { return substr( $hoje, 0, 4 ) . '-T' . ceil( (int) substr( $hoje, 5, 2 ) / 3 ); }
	if ( $per === 'anual' ) { return substr( $hoje, 0, 4 ); }
	if ( $per === 'unica' ) { return 'unica'; }
	return substr( $hoje, 0, 7 );
}
function dv_ct_padrao( $tipo ) {
	$mo = array( array( 'Folha de pagamento dos empregados do contrato', 'mensal' ), array( 'Comprovante de pagamento dos salários', 'mensal' ), array( 'Guias e comprovantes de FGTS (FGTS Digital)', 'mensal' ), array( 'Guias e comprovantes de INSS (DCTFWeb)', 'mensal' ), array( 'Vale-transporte e vale-alimentação entregues', 'mensal' ), array( 'Controle de ponto / frequência', 'mensal' ), array( 'Nota fiscal emitida e enviada', 'mensal' ), array( 'Relatório de execução / medição aprovado pelo fiscal', 'mensal' ), array( 'Certidões de regularidade (CND federal, CRF, CNDT) válidas', 'mensal' ), array( 'Entrega de uniformes e EPIs registrada', 'trimestral' ), array( 'Férias e 13º provisionados / pagos', 'trimestral' ), array( 'Exames ocupacionais (ASO) em dia', 'anual' ), array( 'Garantia contratual vigente', 'anual' ), array( 'Pedido de repactuação (nova convenção coletiva)', 'anual' ), array( 'Ordem de serviço e preposto designado', 'unica' ), array( 'Garantia contratual apresentada no início', 'unica' ) );
	$bens = array( array( 'Pedido / empenho recebido', 'mensal' ), array( 'Entrega realizada e recebimento provisório assinado', 'mensal' ), array( 'Nota fiscal emitida e enviada', 'mensal' ), array( 'Recebimento definitivo', 'mensal' ), array( 'Pagamento recebido no prazo', 'mensal' ), array( 'Certidões de regularidade válidas', 'mensal' ), array( 'Garantia contratual vigente', 'anual' ) );
	$obra = array( array( 'Diário de obra atualizado', 'mensal' ), array( 'Medição apresentada e aprovada', 'mensal' ), array( 'Nota fiscal emitida e enviada', 'mensal' ), array( 'ART/RRT e responsável técnico em dia', 'anual' ), array( 'Cronograma físico-financeiro conferido', 'mensal' ), array( 'Folha, FGTS e INSS da equipe da obra', 'mensal' ), array( 'Seguro garantia e seguro de riscos de engenharia vigentes', 'anual' ), array( 'Licenças da obra válidas', 'anual' ) );
	return $tipo === 'bens' ? $bens : ( $tipo === 'obra' ? $obra : $mo );
}
add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	/* Pleitos, checklist de auditoria do contrato e vínculo com licitações */
	register_rest_route( 'desenvolve/v1', '/contratos/extra', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] ); $ac = sanitize_key( (string) $r['acao'] ); $hoje = current_time( 'Y-m-d' );
		$l = dv_ct_itens( $uid ); $k = null; foreach ( $l as $i => $x ) { if ( $x['id'] === $id ) { $k = $i; } }
		if ( $k === null ) { return new WP_Error( 'dv_ct', 'Contrato não encontrado.', array( 'status' => 404 ) ); }
		$c = &$l[ $k ]; $c += array( 'pleitos' => array(), 'checklist' => array(), 'checks' => array() );
		$t = function ( $f, $n = 300 ) use ( $r ) { return mb_substr( sanitize_text_field( (string) $r[ $f ] ), 0, $n ); };
		$d = function ( $f ) use ( $r ) { $v = (string) $r[ $f ]; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$pers = array( 'mensal', 'trimestral', 'anual', 'unica' );
		switch ( $ac ) {
			case 'pleito_salvar':
				$p = array( 'tipo' => in_array( $r['tipo'], array( 'reajuste', 'repactuacao', 'reequilibrio', 'prorrogacao', 'aditivo', 'outro' ), true ) ? $r['tipo'] : 'outro', 'status' => in_array( $r['status'], array( 'a_fazer', 'protocolado', 'deferido', 'parcial', 'indeferido' ), true ) ? $r['status'] : 'a_fazer',
					'data' => $d( 'data' ), 'prazo' => $d( 'prazo' ), 'valor' => (float) $r['valor'], 'pct' => (float) $r['pct'], 'pago' => in_array( $r['pago'], array( 'sim', 'nao', 'parcial' ), true ) ? $r['pago'] : '', 'processo' => $t( 'processo', 80 ), 'obs' => $t( 'obs', 500 ) );
				$pid = sanitize_key( (string) $r['pid'] ); $ach = false; foreach ( $c['pleitos'] as &$y ) { if ( $y['id'] === $pid ) { $y = array_merge( $y, $p ); $ach = true; } } unset( $y );
				if ( ! $ach ) { $c['pleitos'][] = $p + array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ) ); } break;
			case 'pleito_excluir': $pid = sanitize_key( (string) $r['pid'] ); $c['pleitos'] = array_values( array_filter( $c['pleitos'], function ( $y ) use ( $pid ) { return $y['id'] !== $pid; } ) ); break;
			case 'check_item':
				$it = array( 'nome' => $t( 'nome', 160 ), 'per' => in_array( $r['per'], $pers, true ) ? $r['per'] : 'mensal', 'resp' => $t( 'resp', 80 ) ); if ( ! $it['nome'] ) { return new WP_Error( 'dv_ct', 'Dê um nome ao item.', array( 'status' => 400 ) ); }
				$iid = sanitize_key( (string) $r['iid'] ); $ach = false; foreach ( $c['checklist'] as &$y ) { if ( $y['id'] === $iid ) { $y = array_merge( $y, $it ); $ach = true; } } unset( $y );
				if ( ! $ach ) { if ( count( $c['checklist'] ) >= 60 ) { return new WP_Error( 'dv_ct', 'Limite de 60 itens.', array( 'status' => 400 ) ); } $c['checklist'][] = $it + array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ) ); } break;
			case 'check_item_excluir': $iid = sanitize_key( (string) $r['iid'] ); $c['checklist'] = array_values( array_filter( $c['checklist'], function ( $y ) use ( $iid ) { return $y['id'] !== $iid; } ) ); break;
			case 'check_padrao':
				$ja = array_map( function ( $y ) { return mb_strtolower( $y['nome'] ); }, $c['checklist'] );
				foreach ( dv_ct_padrao( sanitize_key( (string) $r['modelo'] ) ) as $y ) { if ( ! in_array( mb_strtolower( $y[0] ), $ja, true ) ) { $c['checklist'][] = array( 'id' => substr( md5( uniqid( $y[0], true ) ), 0, 8 ), 'nome' => $y[0], 'per' => $y[1], 'resp' => '' ); } } break;
			case 'check_marcar':
				$iid = sanitize_key( (string) $r['iid'] ); $per = 'mensal'; foreach ( $c['checklist'] as $y ) { if ( $y['id'] === $iid ) { $per = $y['per']; } }
				$comp = preg_match( '/^(\d{4}(-\d{2}|-T[1-4])?|unica)$/', (string) $r['comp'] ) ? (string) $r['comp'] : dv_ct_comp( $per, $hoje );
				$c['checks'][ $iid . '|' . $comp ] = array( 'em' => $d( 'em' ) ?: $hoje, 'por' => $t( 'por', 80 ), 'local' => $t( 'local', 300 ), 'obs' => $t( 'obs', 300 ) ); break;
			case 'check_desmarcar': unset( $c['checks'][ sanitize_key( (string) $r['iid'] ) . '|' . preg_replace( '/[^0-9T\-a-z]/', '', (string) $r['comp'] ) ] ); break;
			case 'lic_vincular':
				if ( function_exists( 'dv_lic_itens' ) ) { $li = dv_lic_itens( $uid ); $lid = sanitize_key( (string) $r['lic'] ); foreach ( $li as &$y ) { if ( $y['id'] === $lid ) { $y['contrato'] = $r['desvincular'] ? '' : $id; } } unset( $y ); dv_lic_salvar_itens( $uid, $li ); } break;
			case 'evento_salvar':
				$c += array( 'eventos' => array() );
				$ev = array( 'tipo' => sanitize_key( (string) $r['tipo'] ) ?: 'outro', 'data' => $d( 'data' ), 'numero' => $t( 'numero', 80 ), 'desc' => mb_substr( sanitize_textarea_field( (string) $r['desc'] ), 0, 1500 ),
					'vig' => $d( 'vig' ), 'valor' => (float) $r['valor'], 'pct' => (float) $r['pct'], 'saiu' => $t( 'saiu', 120 ), 'entrou' => $t( 'entrou', 120 ), 'pago' => in_array( $r['pago'], array( 'sim', 'nao', 'parcial' ), true ) ? $r['pago'] : '', 'link' => esc_url_raw( (string) $r['link'] ) );
				$eid = sanitize_key( (string) $r['eid'] ); $ach = false; foreach ( $c['eventos'] as &$y ) { if ( $y['id'] === $eid ) { $y = array_merge( $y, $ev ); $ach = true; } } unset( $y );
				if ( ! $ach ) { if ( count( $c['eventos'] ) >= 300 ) { return new WP_Error( 'dv_ct', 'Limite de 300 eventos.', array( 'status' => 400 ) ); } $c['eventos'][] = $ev + array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ) ); }
				if ( $ev['vig'] && $ev['vig'] > (string) ( $c['fim'] ?? '' ) ) { $c['fim'] = $ev['vig']; } // prorrogação atualiza a vigência
				if ( $ev['valor'] > 0 && ( ! $ev['data'] || $ev['data'] >= max( array_merge( array( '' ), array_column( array_filter( $c['eventos'], function ( $z ) { return ( $z['valor'] ?? 0 ) > 0; } ), 'data' ) ) ) ) ) { if ( empty( $c['valor_inicial'] ) ) { $c['valor_inicial'] = $c['valor']; } $c['valor'] = $ev['valor']; }
				break;
			case 'evento_excluir': $eid = sanitize_key( (string) $r['eid'] ); $c['eventos'] = array_values( array_filter( (array) ( $c['eventos'] ?? array() ), function ( $y ) use ( $eid ) { return $y['id'] !== $eid; } ) ); break;
			case 'importar_dou': // extratos do DOU e do diário da cidade viram eventos do histórico
				$c += array( 'eventos' => array() ); $nome = ''; foreach ( ( function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array() ) as $e ) { if ( $e['cnpj'] === $c['cnpj'] ) { $nome = $e['nome']; } }
				$links = array_column( $c['eventos'], 'link' ); $novos = 0; $br2 = function ( $x ) { return preg_match( '#(\d{2})/(\d{2})/(\d{4})#', $x, $m ) ? "$m[3]-$m[2]-$m[1]" : ''; };
				foreach ( dv_ct_diarios( $c, $nome ) as $pb ) {
					if ( $pb['url'] && in_array( $pb['url'], $links, true ) ) { continue; }
					$tx = $pb['trecho']; $U = strtoupper( remove_accents( $tx ) );
					$tp = strpos( $U, 'REPACTUA' ) !== false ? 'repactuacao' : ( strpos( $U, 'REAJUSTE' ) !== false ? 'reajuste' : ( strpos( $U, 'REEQUILIBRIO' ) !== false ? 'reequilibrio' : ( strpos( $U, 'RESCIS' ) !== false ? 'rescisao' : ( preg_match( '/SANCAO|PENALIDADE|MULTA|ADVERTENCIA/', $U ) ? 'penalidade' : ( strpos( $U, 'PRORROGA' ) !== false ? 'prorrogacao' : ( strpos( $U, 'ACRESCIMO' ) !== false ? 'acrescimo' : ( strpos( $U, 'SUPRESS' ) !== false ? 'supressao' : ( strpos( $U, 'TERMO ADITIVO' ) !== false ? 'aditivo_valor' : ( strpos( $U, 'APOSTILAMENTO' ) !== false ? 'apostilamento' : ( strpos( $U, 'EXTRATO DE CONTRATO' ) !== false ? 'assinatura' : 'outro' ) ) ) ) ) ) ) ) ) );
					$vig = preg_match( '#Vig[eê]ncia:?\s*(?:de\s*)?\d{2}/\d{2}/\d{4}\s*(?:a|at[eé])\s*(\d{2}/\d{2}/\d{4})#iu', $tx, $m ) ? $br2( $m[1] ) : '';
					$val = preg_match( '#Valor(?: Total)?(?: Atualizado)?(?: do Contrato)?:?\s*R\$\s*([\d\.]+,\d{2})#iu', $tx, $m ) ? (float) str_replace( array( '.', ',' ), array( '', '.' ), $m[1] ) : 0;
					$num = preg_match( '#(TERMO ADITIVO|APOSTILAMENTO)\s*N[ºo°]?\s*([\d/]+)#iu', $tx, $m ) ? ucfirst( mb_strtolower( $m[1] ) ) . ' nº ' . $m[2] : '';
					$obj = preg_match( '#Objeto:\s*(.{10,400}?)(?:\.\s+(?:Vig|Valor|Data|Fundamento)|$)#iu', $tx, $m ) ? trim( $m[1] ) : mb_substr( $tx, 0, 300 );
					$c['eventos'][] = array( 'id' => substr( md5( uniqid( $pb['url'], true ) ), 0, 8 ), 'tipo' => $tp, 'data' => $pb['data'], 'numero' => $num, 'desc' => $obj . ' (' . $pb['fonte'] . ')', 'vig' => $vig, 'valor' => $val, 'pct' => 0, 'saiu' => '', 'entrou' => '', 'pago' => '', 'link' => $pb['url'], 'auto' => 1 );
					if ( $vig && $vig > (string) ( $c['fim'] ?? '' ) ) { $c['fim'] = $vig; } $novos++;
				}
				usort( $c['eventos'], function ( $a, $b ) { return strcmp( $a['data'], $b['data'] ); } );
				foreach ( $c['eventos'] as $y ) { if ( ( $y['valor'] ?? 0 ) > 0 ) { if ( empty( $c['valor_inicial'] ) ) { $c['valor_inicial'] = $c['valor']; } $c['valor'] = $y['valor']; } }
				$GLOBALS['dv_ct_novos'] = $novos; break;
			default: return new WP_Error( 'dv_ct', 'Ação inválida.', array( 'status' => 400 ) );
		}
		if ( count( $c['checks'] ) > 1500 ) { $c['checks'] = array_slice( $c['checks'], -1500, null, true ); }
		unset( $c ); dv_ct_salvar_itens( $uid, $l );
		return array( 'ok' => true, 'novos' => $GLOBALS['dv_ct_novos'] ?? null );
	} ) );
	/* Processos judiciais que citam o número do contrato e a empresa (Diário de Justiça Eletrônico Nacional) */
	register_rest_route( 'desenvolve/v1', '/contratos/processos', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] ); $c = null; foreach ( dv_ct_itens( $uid ) as $x ) { if ( $x['id'] === $id ) { $c = $x; } }
		if ( ! $c ) { return new WP_Error( 'dv_ct', 'Contrato não encontrado.', array( 'status' => 404 ) ); }
		$num = dv_ct_num( $c['numero'] ?? '' ); if ( ! $num ) { return array( 'itens' => array(), 'aviso' => 'Cadastre o número do contrato (ex.: 26/2025).' ); }
		$nome = ''; foreach ( ( function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array() ) as $e ) { if ( $e['cnpj'] === $c['cnpj'] ) { $nome = $e['nome']; } }
		$ck = 'dv_ctp1_' . md5( $num . $nome ); $x = get_transient( $ck ); if ( is_array( $x ) ) { return $x; }
		$u = add_query_arg( array( 'nomeParte' => rawurlencode( preg_replace( '/\s+(LTDA|S\.?A\.?|EIRELI|ME|EPP)\.?$/i', '', trim( $nome ) ) ), 'texto' => rawurlencode( $num ), 'itensPorPagina' => 100, 'pagina' => 1 ), 'https://comunicaapi.pje.jus.br/api/v1/comunicacao' );
		$g = wp_remote_get( $u, array( 'timeout' => 40 ) ); $out = array(); $vistos = array();
		if ( is_wp_error( $g ) || wp_remote_retrieve_response_code( $g ) !== 200 ) { return array( 'itens' => array(), 'aviso' => 'O Diário de Justiça não respondeu agora. Tente de novo em instantes.' ); }
		$orgw = array_slice( array_filter( explode( ' ', strtoupper( remove_accents( (string) $c['orgao'] ) ) ), function ( $w ) { return strlen( $w ) > 4; } ), 0, 3 );
		foreach ( (array) ( json_decode( wp_remote_retrieve_body( $g ), true )['items'] ?? array() ) as $it ) {
			$tx = wp_strip_all_tags( (string) ( $it['texto'] ?? '' ) ); $pr = (string) ( $it['numeroprocessocommascara'] ?? $it['numero_processo'] ?? '' );
			if ( strpos( $tx, $num ) === false && strpos( $tx, str_pad( explode( '/', $num )[0], 5, '0', STR_PAD_LEFT ) . '/' . explode( '/', $num )[1] ) === false ) { continue; }
			$U = strtoupper( remove_accents( $tx ) ); $hit = 0; foreach ( $orgw as $w ) { if ( strpos( $U, $w ) !== false ) { $hit++; } } if ( $orgw && ! $hit ) { continue; }
			if ( isset( $vistos[ $pr ] ) ) { continue; } $vistos[ $pr ] = 1;
			$out[] = array( 'processo' => $pr, 'tribunal' => $it['siglaTribunal'] ?? '', 'classe' => $it['nomeClasse'] ?? '', 'data' => substr( (string) ( $it['data_disponibilizacao'] ?? '' ), 0, 10 ), 'link' => $it['link'] ?? '', 'trecho' => mb_substr( trim( preg_replace( '/\s+/', ' ', $tx ) ), 0, 400 ) );
		}
		$o = array( 'itens' => $out ); set_transient( $ck, $o, 12 * HOUR_IN_SECONDS ); return $o;
	} ) );
} );
function dv_al_norm_ct( $s ) { return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', remove_accents( (string) $s ) ) ); }

/* Avisos diários: vigência (120, 90, 60, 30, 15 e 0 dias), reajuste/repactuação (60, 30 e 0 dias), garantia (30, 15, 7 e 0 dias) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_ct_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:25 UTC' ), 'daily', 'dv_ct_diario' ); } } );
add_action( 'dv_ct_diario', function () {
	global $wpdb; $hoje = current_time( 'Y-m-d' );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_contratos' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( function_exists( 'dv_al_ativo' ) && ! dv_al_ativo( $uid ) ) { continue; }
		$l = dv_ct_itens( $uid ); $ev = array(); $mud = false;
		foreach ( $l as &$x ) {
			$c = dv_ct_calc( $x, $hoje ); $e = array();
			if ( in_array( $c['dias_fim'], array( 120, 90, 60, 30, 15, 0 ), true ) ) { $e[] = array( 'vig', $c['dias_fim'] ); }
			if ( $c['vigente'] && in_array( $c['dias_reajuste'], array( 60, 30, 0 ), true ) ) { $e[] = array( 'rea', $c['dias_reajuste'] ); }
			if ( in_array( $c['dias_garantia'], array( 30, 15, 7, 0 ), true ) ) { $e[] = array( 'gar', $c['dias_garantia'] ); }
			foreach ( $e as $k ) { $m = $k[0] . $k[1] . $hoje; if ( strpos( (string) ( $x['aviso'] ?? '' ), $m ) !== false ) { continue; } $x['aviso'] = substr( $m . '|' . ( $x['aviso'] ?? '' ), 0, 120 ); $mud = true; $ev[] = array( 'c' => $c, 't' => $k[0], 'd' => $k[1] ); }
		}
		unset( $x );
		if ( $mud ) { dv_ct_salvar_itens( $uid, $l ); }
		if ( $ev ) { dv_ct_email( $uid, $ev ); }
	}
} );
function dv_ct_email( $uid, $ev ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:18px;letter-spacing:2px;font-weight:700">DESENVOLVE </span><b style="color:#C9A45C;font-size:18px;letter-spacing:2px">ESTRATÉGIAS</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Meus contratos · avisos</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Olá, ' . esc_html( $u->display_name ) . '. Estes contratos pedem atenção:</p>';
	foreach ( $ev as $e ) {
		$c = $e['c']; $q = $e['d'] === 0 ? 'hoje' : 'em ' . $e['d'] . ' dias';
		if ( $e['t'] === 'vig' ) { $tx = 'A vigência termina <b>' . $q . '</b> (' . date_i18n( 'd/m/Y', strtotime( $c['fim'] ) ) . '). Se o contrato admite prorrogação, manifeste o interesse ao órgão com antecedência e confira a garantia.'; $cta = ''; }
		elseif ( $e['t'] === 'rea' ) { $tx = 'O contrato completa um ano da data-base <b>' . $q . '</b> (' . date_i18n( 'd/m/Y', strtotime( $c['prox_reajuste'] ) ) . '). Peça o reajuste ou a repactuação: o pedido feito depois pode perder valores (preclusão).'; $cta = '<a href="' . esc_url( home_url( '/plataforma/repactuacao/' ) ) . '" style="color:#8A6A2C;font-weight:bold">Calcular reajuste ou repactuação</a>'; }
		else { $tx = 'A garantia contratual vence <b>' . $q . '</b> (' . date_i18n( 'd/m/Y', strtotime( $c['garantia_vence'] ) ) . '). Garantia vencida com contrato vigente pode gerar retenção de pagamento e penalidade.'; $cta = '<a href="' . esc_url( dv_ct_wa_link( 'Olá! Quero cotar a renovação do seguro garantia do contrato ' . $c['numero'] . ' com ' . $c['orgao'] . '.' ) ) . '" style="color:#8A6A2C;font-weight:bold">Cotar a renovação com a Desenvolve</a>'; }
		$h .= '<div style="border-left:4px solid ' . ( $e['d'] <= 15 ? '#C8604E' : '#C9A45C' ) . ';background:#FAF8F3;padding:12px 14px;margin:10px 0;border-radius:8px"><b>' . esc_html( $c['orgao'] ) . '</b> · ' . esc_html( $c['numero'] ) . '<br><span style="font-size:13px;color:#6E6A60">' . esc_html( mb_substr( $c['objeto'], 0, 160 ) ) . '</span><p style="font-size:14px;margin:6px 0">' . $tx . '</p>' . $cta . '</div>';
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( home_url( '/plataforma/contratos/' ) ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir meus contratos</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Datas calculadas com o que está cadastrado na plataforma. Confira sempre o contrato e os aditivos.</p></div></div>';
	$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, count( $ev ) . ' aviso(s) dos seus contratos · Desenvolve', $h, $hd ); } }
}

add_shortcode( 'dv_contratos', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="ct" id="dv-ct" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="ct-emps"></div>
	<div class="ct-kpi"></div>
	<input class="ct-busca" placeholder="Buscar por número do contrato, órgão, objeto ou cidade" style="margin:0 0 12px">
	<div class="ct-top"><span class="ct-tabs"></span><span><button class="g ct-novo">+ Contrato à mão</button> <button class="ct-imp">Buscar meus contratos no PNCP</button></span></div>
	<p class="ct-msg ct-h"></p>
	<div class="ct-list"><p class="ct-h">Carregando…</p></div>
	<dialog class="ct-dlg ct-fi"><div class="ct-f"><div class="ct-fb"></div><div class="ct-act"><button type="button" class="g ct-fx">Fechar</button></div></div></dialog>
	<dialog class="ct-dlg"><form method="dialog" class="ct-f">
		<h3>Contrato</h3>
		<label>Órgão<input name="orgao"></label>
		<div class="ct-3"><label>Número<input name="numero"></label><label>Valor (R$)<input name="valor" inputmode="decimal"></label><label>UF<input name="uf" maxlength="2"></label></div>
		<label>Objeto<textarea name="objeto" rows="2"></textarea></label>
		<div class="ct-3"><label>Início da vigência<input type="date" name="inicio"></label><label>Fim da vigência<input type="date" name="fim"></label><label>Data-base do reajuste<input type="date" name="data_base"></label></div>
		<p class="ct-h" style="margin-top:-4px">Data-base: data do orçamento estimado (reajuste) ou da convenção coletiva (repactuação). Se ficar em branco, usamos o início da vigência.</p>
		<div class="ct-3"><label>Índice / convenção<input name="indice" placeholder="IPCA, IGP-M, CCT…"></label><label>Garantia contratual<select name="garantia_tipo"><option value="">Sem garantia</option><option>Seguro garantia</option><option>Fiança bancária</option><option>Caução em dinheiro</option><option>Títulos da dívida pública</option></select></label><label>Garantia vence em<input type="date" name="garantia_vence"></label></div>
		<div class="ct-3"><label>Valor da garantia (R$)<input name="garantia_valor" inputmode="decimal"></label><label style="grid-column:span 2">Link<input name="link"></label></div>
		<div class="ct-3"><label>Lei do contrato<select name="lei"><option value="">Não sei</option><option value="14133">Lei 14.133/2021</option><option value="8666">Lei 8.666/1993</option></select></label><label>Valor inicial (R$)<input name="valor_inicial" inputmode="decimal"></label><label>Assinatura<input type="date" name="assinatura"></label></div>
		<div class="ct-3"><label>Gestor do contrato (órgão)<input name="gestor"></label><label>Fiscal (órgão)<input name="fiscal"></label><label>Preposto (empresa)<input name="preposto"></label></div>
		<label>Anotações<textarea name="notas" rows="2"></textarea></label>
		<input type="hidden" name="id"><input type="hidden" name="cnpj">
		<p class="ct-fm ct-h"></p>
		<div class="ct-act"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="ct-ok">Salvar</button></div>
	</form></dialog>
</div>
<style>
.ct{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;--am:#9A6B12;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.ct button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.ct button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:9px 14px}.ct button:disabled{opacity:.6}
.ct input,.ct select,.ct textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.ct-h{color:var(--mu);font-size:.86rem;margin:6px 0 0}.ct-msg.e{color:var(--rd)}.ct-msg.o{color:var(--gr);font-weight:600}
.ct-emps{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}.ct-emps .e{border:1px solid var(--ln);border-radius:999px;padding:8px 14px;background:#fff;cursor:pointer;font-weight:600;font-size:.9rem}.ct-emps .e.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}.ct-emps form{display:flex;gap:8px}.ct-emps input{width:220px}
.ct-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px}.ct-kpi div{border:1px solid var(--ln);border-radius:14px;padding:14px;background:#fff}.ct-kpi b{display:block;font-size:1.45rem}.ct-kpi span{color:var(--mu);font-size:.8rem;font-weight:600}
.ct-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center}.ct-tabs span{border:1px solid var(--ln);border-radius:999px;padding:7px 12px;cursor:pointer;font-size:.84rem;font-weight:600;background:#fff;margin-right:4px;display:inline-block;margin-bottom:6px}.ct-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.ct-c{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-top:10px;display:grid;grid-template-columns:1fr auto;gap:10px}
.ct-c .o{font-weight:700}.ct-c .m{color:var(--mu);font-size:.82rem;margin-top:3px}.ct-c .ob{font-size:.9rem;margin-top:6px}
.ct-ch{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.ct-ch span{font-size:.75rem;font-weight:700;border-radius:99px;padding:3px 9px;background:#EEF1F6;color:#3B5578}.ct-ch .r{background:#FDECE8;color:var(--rd)}.ct-ch .a{background:#FFF4DC;color:var(--am)}.ct-ch .v{background:#EEF7F1;color:var(--gr)}.ct-ch .n{background:#EEE9DE;color:var(--mu)}
.ct-c .ac{display:flex;flex-direction:column;gap:6px;min-width:170px}.ct-c .ac button,.ct-c .ac a{font-size:.8rem;padding:7px 12px;text-align:center;text-decoration:none;border-radius:10px;font-weight:700}.ct-c .ac a{border:1px solid var(--ln);color:#8A6A2C}.ct-c .ac a.s{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.ct-dlg{border:0;border-radius:16px;padding:0;max-width:680px;width:calc(100% - 32px)}.ct-dlg::backdrop{background:rgba(12,15,19,.55)}
.ct-f{padding:22px;display:grid;gap:10px}.ct-f h3{margin:0}.ct-f label{font-weight:600;font-size:.86rem;display:grid;gap:4px}.ct-3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.ct-act{display:flex;justify-content:flex-end;gap:8px}.ct-fm.e{color:var(--rd)}
.ct-fi{max-width:900px}.ct-tb{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0 10px}.ct-tb span{border:1px solid rgba(201,164,92,.45);border-radius:999px;padding:6px 12px;font-size:.82rem;font-weight:600;cursor:pointer}.ct-tb span.on{background:#C9A45C;color:#241A06}.ct-bt{border:1px solid rgba(201,164,92,.45);border-radius:10px;padding:8px 12px;text-decoration:none;font-size:.85rem;font-weight:700;margin-right:6px}.ct-tc label{font-size:.82rem;font-weight:600;display:grid;gap:4px}.ct-tc .ck label{display:flex!important;gap:10px;align-items:flex-start;justify-content:flex-start;text-align:left;font-size:.9rem;font-weight:500}.ct-tc .ck input[type=checkbox]{width:18px;height:18px;flex:0 0 18px;margin-top:2px;padding:0}.ct-tc .ck span{text-align:left}.ct-tc .ck a.m{float:none;display:inline-block;margin-left:28px}.ct-tc input,.ct-tc select{font-size:.88rem;padding:8px 10px}.ct-fb .it a.m{color:#8E938F;font-weight:500;font-size:.78rem}.ct-fb h3{margin:0 0 4px}.ct-fb h4{margin:18px 0 8px;font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gd)}.ct-fb .gr{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.ct-fb .gr div{border:1px solid rgba(201,164,92,.45);border-radius:12px;padding:10px 12px;background:rgba(201,164,92,.06);color:inherit}.ct-fb .gr b{display:block;font-size:1.05rem;color:inherit}.ct-fb .gr span{display:block}.ct-fb .gr span{color:var(--mu);font-size:.78rem;font-weight:600}.ct-fb .it{border-top:1px solid #EEE9DE;padding:8px 0;font-size:.88rem}.ct-fb .it .m{color:var(--mu);font-size:.78rem}.ct-fb a{color:#8A6A2C;font-weight:700}
@media(max-width:700px){.ct-fb .gr{grid-template-columns:1fr}}
.ct-vazio{border:1px dashed var(--ln);border-radius:14px;padding:22px;text-align:center;color:var(--mu);margin-top:10px}
@media(max-width:700px){.ct{padding:16px}.ct-3{grid-template-columns:1fr}.ct-c{grid-template-columns:1fr}.ct-c .ac{flex-direction:row;flex-wrap:wrap;min-width:0}.ct-emps input{width:100%}}
</style>
<script>
(function(){var R=document.getElementById('dv-ct');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,emp='',aba='atencao',F=R.querySelector('form.ct-f'),D=F.closest('dialog'),M=R.querySelector('.ct-msg');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}):''}
function num(v){return String(v||'').replace(/\./g,'').replace(',','.')}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function wa(t){return'https://wa.me/'+S.wa+'?text='+encodeURIComponent(t)}
function atencao(c){return c.verificar||c.vigente&&((c.dias_fim!==null&&c.dias_fim<=120)||(c.dias_reajuste!==null&&c.dias_reajuste<=60)||(c.dias_garantia!==null&&c.dias_garantia<=30))}
function carregar(){return req('GET','contratos').then(function(j){S=j;if(!emp&&j.empresas.length)emp=j.empresas[0].cnpj;pintar()})}
function pintar(){
 R.querySelector('.ct-emps').innerHTML=S.empresas.map(function(x){return'<span class="e'+(x.cnpj===emp?' on':'')+'" data-c="'+x.cnpj+'">'+e(x.nome.split(' ').slice(0,3).join(' ').replace(/[\s.,-]+$/,''))+'</span>'}).join('')+'<form class="ct-add"><input placeholder="CNPJ da sua empresa" inputmode="numeric"><button>Adicionar</button></form>';
 var ds=S.itens.filter(function(c){return c.cnpj===emp}),vig=ds.filter(function(c){return c.vigente});
 var tot=vig.reduce(function(s,c){return s+(+c.valor||0)},0),at=vig.filter(atencao).length,sg=vig.filter(function(c){return!c.garantia_tipo}).length;
 R.querySelector('.ct-kpi').innerHTML=emp?'<div><b>'+vig.length+'</b><span>Contratos vigentes</span></div><div><b>'+moeda(tot)+'</b><span>Valor da carteira vigente</span></div><div><b style="color:var(--rd)">'+at+'</b><span>Pedem atenção agora</span></div><div><b style="color:var(--am)">'+sg+'</b><span>Sem garantia cadastrada</span></div>':'';
 var T={atencao:'Pedem atenção',vigentes:'Vigentes',encerrados:'Encerrados',todos:'Todos'};
 R.querySelector('.ct-tabs').innerHTML=emp?Object.keys(T).map(function(k){return'<span data-t="'+k+'" class="'+(k===aba?'on':'')+'">'+T[k]+'</span>'}).join(''):'';
 var qb=(R.querySelector('.ct-busca').value||'').toLowerCase().replace(/^0+/,'');
 var l=ds.filter(function(c){return!qb||[c.numero,c.orgao,c.objeto,c.municipio,c.uf].join(' ').toLowerCase().replace(/\b0+(\d)/g,'$1').indexOf(qb)>=0}).filter(function(c){return qb||aba==='todos'||(aba==='vigentes'&&c.vigente)||(aba==='encerrados'&&!c.vigente)||(aba==='atencao'&&atencao(c))});
 l.sort(function(a,b){var x=Math.min(a.dias_fim==null?9999:a.dias_fim,a.dias_reajuste==null?9999:a.dias_reajuste,a.dias_garantia==null?9999:a.dias_garantia),y=Math.min(b.dias_fim==null?9999:b.dias_fim,b.dias_reajuste==null?9999:b.dias_reajuste,b.dias_garantia==null?9999:b.dias_garantia);return x-y});
 var L=R.querySelector('.ct-list');
 if(!S.empresas.length){L.innerHTML='<div class="ct-vazio">Informe acima o CNPJ da sua empresa. Buscamos no PNCP e no Portal da Transparência todos os contratos públicos dela (matriz e filiais) e passamos a avisar vigência, reajuste e garantia.</div>';return}
 if(!ds.length){L.innerHTML='<div class="ct-vazio">Nenhum contrato ainda. Clique em <b>Buscar meus contratos no PNCP</b>: leva de 20 a 60 segundos.</div>';return}
 L.innerHTML=l.length?l.map(function(c){var ch=[];
  if(c.verificar)ch.push(['a','Fim registrado em '+br(c.fim)+': se foi prorrogado por aditivo, atualize a data']);else if(c.dias_fim!==null)ch.push(c.dias_fim<0?['n','Encerrado em '+br(c.fim)]:[c.dias_fim<=30?'r':c.dias_fim<=120?'a':'v','Vigência até '+br(c.fim)+' · '+c.dias_fim+' dias']);else ch.push(['n','Sem data de fim']);
  if(c.vigente&&c.prox_reajuste)ch.push([c.dias_reajuste<=30?'r':c.dias_reajuste<=60?'a':'','Reajuste/repactuação em '+br(c.prox_reajuste)+(c.data_base?'':' (estimado pelo início)')]);
  if(c.garantia_tipo)ch.push([c.dias_garantia!==null&&c.dias_garantia<=15?'r':c.dias_garantia!==null&&c.dias_garantia<=30?'a':'v',c.garantia_tipo+(c.garantia_vence?' até '+br(c.garantia_vence):'')]);else if(c.vigente)ch.push(['a','Garantia não cadastrada']);
  ch.push(['',c.fonte]);
  var seg=c.vigente?'<a class="s" target="_blank" rel="noopener" href="'+e(wa('Olá! Quero cotar seguro garantia para o contrato '+(c.numero||'')+' com '+(c.orgao||'')+' (valor '+moeda(c.valor)+', vigência até '+br(c.fim)+').'))+'">Cotar seguro garantia</a>':'';
  return'<div class="ct-c"><div><div class="o">'+e(c.orgao||'Órgão não informado')+(c.uf?' · '+e(c.municipio?c.municipio+'/':'')+e(c.uf):'')+'</div><div class="m">'+e(c.numero||'')+(c.valor?' · '+moeda(c.valor):'')+(c.inicio?' · desde '+br(c.inicio):'')+(c.indice?' · '+e(c.indice):'')+'</div><div class="ob">'+e(String(c.objeto||'').slice(0,240))+'</div><div class="ct-ch">'+ch.map(function(k){return'<span class="'+k[0]+'">'+e(k[1])+'</span>'}).join('')+'</div>'+(c.notas?'<div class="m">📝 '+e(c.notas.slice(0,160))+'</div>':'')+'</div><div class="ac">'+seg+(c.vigente?'<a href="/plataforma/repactuacao/">Calcular repactuação</a>':'')+'<button data-fi="'+c.id+'">Ficha do contrato</button><button class="g" data-ed="'+c.id+'">Editar datas e garantia</button>'+(c.link?'<a href="'+e(c.link)+'" target="_blank" rel="noopener">Ver na fonte</a>':'')+'<button class="g" data-x="'+c.id+'">Remover</button></div></div>'}).join(''):'<div class="ct-vazio">Nada nesta aba.</div>'}
function form(c){F.reset();F.querySelector('.ct-fm').textContent='';if(c)Object.keys(c).forEach(function(k){var i=F.querySelector('[name='+k+']');if(i&&c[k]!=null)i.value=c[k]});F.querySelector('[name=cnpj]').value=emp;if(c&&c.garantia_valor==0)F.querySelector('[name=garantia_valor]').value='';D.showModal()}
F.querySelector('.ct-ok').addEventListener('click',function(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});o.valor=num(o.valor);o.garantia_valor=num(o.garantia_valor);o.valor_inicial=num(o.valor_inicial);var m=F.querySelector('.ct-fm');m.textContent='Salvando…';req('POST','contratos/salvar',o).then(function(){D.close();carregar()}).catch(function(j){m.className='ct-fm ct-h e';m.textContent=(j&&j.message)||'Erro.'})});
R.addEventListener('submit',function(ev){if(!ev.target.matches('.ct-add'))return;ev.preventDefault();var c=ev.target.querySelector('input').value.replace(/\D/g,'');req('POST','docs/empresa',{cnpj:c}).then(function(){emp=c;return carregar()}).catch(function(j){alert((j&&j.message)||'Erro')})});
R.addEventListener('click',function(ev){var t=ev.target,c;
 if(t.matches('.e')){emp=t.dataset.c;pintar();return}
 if(t.dataset.t){aba=t.dataset.t;pintar();return}
 if(t.matches('.ct-novo')){if(!emp){alert('Cadastre a empresa primeiro.');return}form(null);return}
 if(t.matches('.ct-imp')){if(!emp){alert('Cadastre a empresa primeiro.');return}t.disabled=true;M.className='ct-msg ct-h';M.textContent='Buscando no PNCP e no Portal da Transparência (matriz e filiais)… pode levar até 1 minuto.';
  req('POST','contratos/importar',{cnpj:emp}).then(function(j){M.className='ct-msg ct-h o';M.textContent=j.encontrados+' contrato(s) encontrados, '+j.adicionados+' novo(s) na sua carteira'+(j.encerrados?' ('+j.encerrados+' encerrados há mais de 1 ano ficaram de fora)':'')+'. Complete a data-base e a garantia de cada um para receber os avisos certos.'+(j.pncp<3?' O PNCP respondeu pouco agora: rode a busca de novo em alguns minutos (o que já está na carteira não se duplica).':'');aba='vigentes';t.disabled=false;return carregar()}).catch(function(j){t.disabled=false;M.className='ct-msg ct-h e';M.textContent=(j&&j.message)||'A busca demorou demais. Tente de novo.'});return}
 if(t.dataset.ed){c=S.itens.filter(function(x){return x.id===t.dataset.ed})[0];form(c);return}
 if(t.dataset.fi){ficha(t.dataset.fi);return}
 if(t.matches('.ct-fx')){FI.close();return}
 if(t.matches('.ct-dio')){t.disabled=true;t.textContent='Buscando nos diários oficiais…';req('GET','contratos/ficha?diarios=1&id='+t.dataset.id).then(function(j){t.outerHTML=pubs(j.diarios,'Nada encontrado com o número deste contrato e o nome da empresa no Diário Oficial da União e no diário da cidade do contrato (quando disponível).')}).catch(function(){t.disabled=false;t.textContent='Tentar de novo'});return}
 if(t.dataset.x){if(confirm('Remover este contrato da carteira?'))req('POST','contratos/excluir',{ids:[t.dataset.x]}).then(carregar)}});
var FI=R.querySelector('.ct-fi');
function pubs(l,vazio){return l&&l.length?l.map(function(p){return'<div class="it"><div class="m">'+br(p.data)+' · '+e(p.fonte||((p.municipio||'')+(p.uf?'/'+p.uf:'')))+'</div>'+e(String(p.trecho||'').slice(0,500))+(p.url?' <a href="'+e(p.url)+'" target="_blank" rel="noopener">abrir</a>':'')+'</div>'}).join(''):'<p class="ct-h">'+vazio+'</p>'}
var FID=null,FJ=null,FT='resumo';
var EVN={assinatura:'Assinatura do contrato',prorrogacao:'Prorrogação de prazo',aditivo_valor:'Termo aditivo (valor/objeto)',acrescimo:'Acréscimo',supressao:'Supressão',reajuste:'Reajuste',repactuacao:'Repactuação',reequilibrio:'Reequilíbrio',apostilamento:'Apostilamento',notificacao:'Notificação',penalidade:'Penalidade',gestor:'Mudança de gestor, fiscal ou preposto',rescisao:'Rescisão / encerramento',outro:'Outro'};
var IAS=[['ChatGPT','https://chatgpt.com/','chatgpt.com'],['Claude','https://claude.ai/new','claude.ai'],['Grok','https://grok.com/','grok.com'],['Perplexity','https://www.perplexity.ai/','perplexity.ai'],['DeepSeek','https://chat.deepseek.com/','deepseek.com']];
var IAP='Leia o documento anexo (termo aditivo, apostilamento, ofício, notificação, ata, decisão ou e-mail) de um contrato público e responda SOMENTE neste formato, uma linha por campo, sem comentários:\nTIPO: (escolha um: assinatura, prorrogação, termo aditivo, acréscimo, supressão, reajuste, repactuação, reequilíbrio, apostilamento, notificação, penalidade, mudança de gestor, rescisão, outro)\nDATA: dd/mm/aaaa (data do documento)\nNÚMERO: (ex.: 2º Termo Aditivo nº 5/2026)\nNOVA VIGÊNCIA ATÉ: dd/mm/aaaa (ou deixe vazio)\nVALOR TOTAL ATUALIZADO: R$ (ou vazio)\nPERCENTUAL: (ex.: 6,25%, ou vazio)\nQUEM SAIU: (gestor, fiscal ou preposto que saiu, ou vazio)\nQUEM ENTROU: (ou vazio)\nRESUMO: (até 3 linhas, objetivo, com o que muda para a empresa)';
function cp(t){var a=document.createElement('textarea');a.value=t;a.style.cssText='position:fixed;top:0;opacity:0';document.body.appendChild(a);a.select();try{document.execCommand('copy')}catch(z){}a.remove()}
function parseIA(t){var g=function(k){var m=t.match(new RegExp('^[ \\t]*\\**'+k+'\\**[ \\t]*:[ \\t]*(.*)$','im'));return m?m[1].replace(/\*+/g,'').trim():''},d=function(x){var m=x.match(/(\d{2})\/(\d{2})\/(\d{4})/);return m?m[3]+'-'+m[2]+'-'+m[1]:''},tp=(g('TIPO')||'').toLowerCase(),mp={'assinatura':'assinatura','prorroga':'prorrogacao','termo aditivo':'aditivo_valor','acr':'acrescimo','supress':'supressao','reajuste':'reajuste','repactua':'repactuacao','reequil':'reequilibrio','apostil':'apostilamento','notifica':'notificacao','penalidade':'penalidade','gestor':'gestor','rescis':'rescisao'},k='outro';for(var x in mp){if(tp.indexOf(x)>=0){k=mp[x];break}}
 var v=g('VALOR TOTAL ATUALIZADO').replace(/[^\d,\.]/g,'');return{tipo:k,data:d(g('DATA')),numero:g('N[ÚU]MERO'),vig:d(g('NOVA VIG[ÊE]NCIA AT[ÉE]')),valor:v?v.replace(/\./g,'').replace(',','.'):'',pct:g('PERCENTUAL').replace(/[^\d,\.-]/g,'').replace(',','.'),saiu:g('QUEM SAIU').replace(/^vazio$/i,''),entrou:g('QUEM ENTROU').replace(/^vazio$/i,''),desc:g('RESUMO')}}
function addMeses(d,m){var x=new Date(d+'T12:00:00');x.setMonth(x.getMonth()+m);return x.toISOString().slice(0,10)}
function relatorio(){var j=FJ,c=j.contrato,ev=(c.eventos||[]).slice().sort(function(a,b){return String(a.data).localeCompare(String(b.data))}),pl=c.pleitos||[],ck=c.checklist||[],chk=c.checks||{};
 var vi=+c.valor_inicial||+c.valor||0,va=+c.valor||0,evo=vi?((va/vi-1)*100):0,pr=ev.filter(function(x){return x.tipo==='prorrogacao'||(x.vig&&x.tipo!=='assinatura')}).length;
 var ac=ev.filter(function(x){return x.tipo==='acrescimo'}).reduce(function(s,x){return s+(+x.pct||0)},0),su=ev.filter(function(x){return x.tipo==='supressao'}).reduce(function(s,x){return s+(+x.pct||0)},0);
 var ini=c.assinatura||c.inicio,lim='',exc='';if(ini){if(c.lei==='8666'){lim=addMeses(ini,60);exc=addMeses(ini,72)}else if(c.lei==='14133'){lim=addMeses(ini,120)}}
 var rea=pl.filter(function(p){return p.tipo==='reajuste'||p.tipo==='repactuacao'}),rea2=ev.filter(function(x){return x.tipo==='reajuste'||x.tipo==='repactuacao'});
 var ntf=j.notificacoes,hoje=j.hoje,st=c.dias_fim===null?'sem data de fim':c.dias_fim<0?'VENCIDO desde '+br(c.fim):c.dias_fim<=90?'vence em '+c.dias_fim+' dias ('+br(c.fim)+')':'vigente até '+br(c.fim);
 var al=[];if(c.dias_fim!==null&&c.dias_fim>=0&&c.dias_fim<=120)al.push('A vigência termina em '+c.dias_fim+' dias: decidir prorrogação ou preparar a nova licitação.');if(c.dias_fim!==null&&c.dias_fim<0)al.push('Contrato com vigência encerrada em '+br(c.fim)+(c.verificar?' (confirme se houve prorrogação não registrada).':'.'));
 if(c.lei==='8666'&&exc&&c.fim>lim)al.push('Contrato da Lei 8.666 além de 60 meses: em prorrogação excepcional (limite de 12 meses, art. 57, § 4º) até '+br(exc)+'.');else if(lim&&c.fim&&addMeses(c.fim,0)>addMeses(lim,-12))al.push('Perto do limite legal de duração ('+br(lim)+'). Prepare a nova licitação.');
 if(ac>=20)al.push('Acréscimos somam '+ac.toFixed(1).replace('.',',')+'%: o limite em regra é 25% do valor inicial (art. 125 da Lei 14.133/2021; art. 65, § 1º, da Lei 8.666/1993).');
 if(c.dias_garantia!==null&&c.dias_garantia<=30)al.push('A garantia vence em '+c.dias_garantia+' dias.');var np=rea.filter(function(p){return p.status==='deferido'&&p.pago==='nao'}).length;if(np)al.push(np+' reajuste(s)/repactuação(ões) deferido(s) e ainda não pago(s).');
 if(ntf.filter(function(n){return n.status==='aberto'}).length)al.push(ntf.filter(function(n){return n.status==='aberto'}).length+' notificação(ões) a responder.');
 var tl=ev.map(function(x){return'<tr><td>'+br(x.data)+'</td><td>'+e(EVN[x.tipo]||x.tipo)+(x.numero?'<br><small>'+e(x.numero)+'</small>':'')+'</td><td>'+e(x.desc||'')+(x.saiu||x.entrou?'<br><small>Saiu: '+e(x.saiu||'—')+' · Entrou: '+e(x.entrou||'—')+'</small>':'')+'</td><td>'+(x.vig?br(x.vig):'')+'</td><td>'+(x.valor?moeda(x.valor):'')+(x.pct?'<br><small>'+String(x.pct).replace('.',',')+'%</small>':'')+'</td></tr>'}).join('');
 var B=(window.DVBR||{});
 var h='<!doctype html><html><head><meta charset="utf-8"><title>Relatório do contrato '+e(c.numero||'')+'</title><style>body{font-family:Arial,sans-serif;color:#16191E;margin:32px;font-size:12.5px}h1{font-size:20px;margin:0}h2{font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#A8843F;border-bottom:1px solid #E3DED2;padding-bottom:4px;margin:22px 0 8px}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #EEE9DE;padding:6px;text-align:left;vertical-align:top}th{font-size:11px;color:#6E6A60}.k{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.k div{border:1px solid #E3DED2;border-radius:8px;padding:8px}.k b{display:block;font-size:15px}.al li{margin:4px 0;color:#9A3B2E}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #C9A45C;padding-bottom:10px;margin-bottom:16px}.bt{background:#C9A45C;border:0;border-radius:999px;padding:10px 18px;font-weight:bold;cursor:pointer}@media print{.bt{display:none}}</style></head><body>';
 h+='<div class="top"><div><span style="font-size:20px">desenvolve </span><b style="font-size:20px;color:#C9A45C">estratégias</b></div><button class="bt" onclick="print()">⬇ Salvar relatório em PDF</button></div>';
 h+='<h1>Relatório do contrato '+e(String(c.numero||'').replace(/^\s*contrato\s*(n[º°o.]*)?\s*/i,'nº '))+'</h1><p>'+e(c.orgao||'')+(c.uf?' · '+e(c.uf):'')+' · emitido em '+br(hoje)+'</p><p><b>Objeto:</b> '+e(c.objeto||'')+'</p>';
 h+='<div class="k"><div><span>Situação</span><b>'+e(st)+'</b></div><div><span>Valor inicial → atual</span><b>'+moeda(vi)+' → '+moeda(va)+'</b><small>'+(evo?(evo>0?'+':'')+evo.toFixed(2).replace('.',',')+'%':'')+'</small></div><div><span>Prorrogações</span><b>'+pr+'</b><small>'+(lim?'limite legal: '+br(lim)+(exc?' (+12 meses excepcionais até '+br(exc)+')':''):'informe a lei e a assinatura')+'</small></div><div><span>Reajustes / repactuações</span><b>'+(rea.length+rea2.length)+'</b><small>'+rea.filter(function(p){return p.pago==='sim'}).length+' pago(s)</small></div></div>';
 if(al.length)h+='<h2>Pontos de atenção</h2><ul class="al">'+al.map(function(a){return'<li>'+e(a)+'</li>'}).join('')+'</ul>';
 h+='<h2>Dados do contrato</h2><table><tr><th>Início</th><td>'+br(c.inicio)+'</td><th>Fim da vigência</th><td>'+br(c.fim)+'</td></tr><tr><th>Lei</th><td>'+({'14133':'Lei 14.133/2021','8666':'Lei 8.666/1993'}[c.lei]||'não informada')+'</td><th>Data-base / índice</th><td>'+br(c.data_base||c.inicio)+(c.indice?' · '+e(c.indice):'')+'</td></tr><tr><th>Garantia</th><td>'+(c.garantia_tipo?e(c.garantia_tipo)+(c.garantia_valor>0?' · '+moeda(c.garantia_valor):'')+(c.garantia_vence?' · vence '+br(c.garantia_vence):''):'não cadastrada')+'</td><th>Acréscimos / supressões</th><td>'+ac.toFixed(1).replace('.',',')+'% / '+su.toFixed(1).replace('.',',')+'%</td></tr><tr><th>Gestor / fiscal</th><td>'+e(c.gestor||'—')+' / '+e(c.fiscal||'—')+'</td><th>Preposto</th><td>'+e(c.preposto||'—')+'</td></tr></table>';
 h+='<h2>Histórico do contrato</h2>'+(tl?'<table><tr><th>Data</th><th>Evento</th><th>Descrição</th><th>Vigência</th><th>Valor</th></tr>'+tl+'</table>':'<p>Nenhum evento registrado.</p>');
 h+='<h2>Reajustes, repactuações e outros pedidos</h2>'+(pl.length?'<table><tr><th>Pedido</th><th>Situação</th><th>Data</th><th>%</th><th>Valor</th><th>Pago</th><th>Processo</th></tr>'+pl.map(function(p){return'<tr><td>'+PL[p.tipo]+'</td><td>'+PS[p.status]+'</td><td>'+br(p.data)+'</td><td>'+(p.pct?String(p.pct).replace('.',',')+'%':'')+'</td><td>'+(p.valor?moeda(p.valor):'')+'</td><td>'+({sim:'Sim',parcial:'Em parte',nao:'Não'}[p.pago]||'')+'</td><td>'+e(p.processo||'')+'</td></tr>'}).join('')+'</table>':'<p>Nenhum pedido registrado.</p>');
 h+='<h2>Notificações, ofícios e recursos</h2>'+(ntf.length?'<table><tr><th>Recebida</th><th>Tipo</th><th>Documento</th><th>Prazo</th><th>Situação</th></tr>'+ntf.map(function(n){return'<tr><td>'+br(n.intimacao)+'</td><td>'+e(n.tipo_nome)+'</td><td>'+e((n.titulo||'')+(n.numero_doc?' · '+n.numero_doc:''))+'</td><td>'+br(n.prazo)+'</td><td>'+e(n.status)+'</td></tr>'}).join('')+'</table>':'<p>Nenhuma registrada.</p>');
 h+='<h2>Checklist de auditoria (período atual)</h2>'+(ck.length?'<table><tr><th>Item</th><th>Periodicidade</th><th>Situação</th></tr>'+ck.map(function(x){var f=chk[x.id+'|'+comp(x.per)];return'<tr><td>'+e(x.nome)+'</td><td>'+PERN[x.per]+'</td><td>'+(f?'Feito em '+br(f.em)+(f.por?' por '+e(f.por):'')+(f.local?' · '+e(f.local):''):'<b style="color:#9A3B2E">Pendente</b>')+'</td></tr>'}).join('')+'</table>':'<p>Checklist não montado.</p>');
 h+='<h2>Licitações ligadas</h2>'+(j.licitacoes.length?'<ul>'+j.licitacoes.map(function(l){return'<li>'+e(l.rot)+' — '+e(l.objeto)+(l.abertura?' (sessão '+br(l.abertura)+')':'')+'</li>'}).join('')+'</ul>':'<p>Nenhuma.</p>');
 h+='<p style="margin-top:28px;color:#6E6A60;font-size:11px;border-top:1px solid #E3DED2;padding-top:10px">Relatório gerado pela plataforma Desenvolve com os dados cadastrados pela empresa e publicações oficiais. Não constitui parecer jurídico. Confira o contrato, os aditivos e as publicações originais.</p></body></html>';
 var w=window.open('','_blank');w.document.write(h);w.document.close()}
var PL={reajuste:'Reajuste',repactuacao:'Repactuação',reequilibrio:'Reequilíbrio econômico-financeiro',prorrogacao:'Prorrogação',aditivo:'Aditivo',outro:'Outro pedido'},PS={a_fazer:'A fazer',protocolado:'Protocolado',deferido:'Deferido',parcial:'Deferido em parte',indeferido:'Indeferido'},PERN={mensal:'Mensal',trimestral:'Trimestral',anual:'Anual',unica:'Uma vez'};
function comp(per,h){h=h||FJ.hoje;if(per==='trimestral')return h.slice(0,4)+'-T'+Math.ceil(+h.slice(5,7)/3);if(per==='anual')return h.slice(0,4);if(per==='unica')return'unica';return h.slice(0,7)}
function compNome(cp){if(cp==='unica')return'Uma vez';if(/T\d$/.test(cp))return cp.slice(-1)+'º trimestre de '+cp.slice(0,4);if(cp.length===4)return'Ano de '+cp;var M=['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];return M[+cp.slice(5,7)-1]+'/'+cp.slice(0,4)}
function ext(o){o.id=FID;return req('POST','contratos/extra',o).then(function(){return ficha(FID,true)}).catch(function(j){alert((j&&j.message)||'Não foi possível salvar.')})}
function ficha(id,manter){FID=id;if(!manter)FT='resumo';var B=FI.querySelector('.ct-fb');if(!manter)B.innerHTML='<p class="ct-h">Carregando a ficha…</p>';if(!FI.open)FI.showModal();
 return req('GET','contratos/ficha?id='+id).then(function(j){FJ=j;desenha()}).catch(function(j){B.innerHTML='<p class="ct-h">'+e((j&&j.message)||'Não foi possível abrir a ficha.')+'</p>'})}
function desenha(){var j=FJ,c=j.contrato,B=FI.querySelector('.ct-fb'),H='',ck=c.checklist||[],chk=c.checks||{},pl=c.pleitos||[];
 var pend=ck.filter(function(x){return!chk[x.id+'|'+comp(x.per)]}).length;
 H+='<h3>'+e(c.orgao||'')+'</h3><p class="ct-h">'+e(c.numero||'')+(c.valor?' · '+moeda(c.valor):'')+' · '+e(c.fonte||'')+'</p><p style="font-size:.92rem;margin:6px 0 10px">'+e(c.objeto||'')+'</p>';
 var vg=c.dias_fim===null?'Sem data':(c.dias_fim<0?'Encerrado em '+br(c.fim):br(c.fim)+' ('+c.dias_fim+' dias)');
 H+='<div class="gr"><div><span>Vigência</span><b>'+vg+'</b>'+(c.verificar?'<span style="color:#E6C27A">Confirme se foi prorrogado</span>':'')+'</div><div><span>Próximo reajuste / repactuação</span><b>'+(c.vigente&&c.prox_reajuste?br(c.prox_reajuste)+' ('+c.dias_reajuste+' dias)':'—')+'</b>'+(c.indice?'<span>'+e(c.indice)+'</span>':'')+'</div><div><span>Garantia</span><b>'+(c.garantia_tipo?e(c.garantia_tipo):'Não cadastrada')+'</b>'+(c.garantia_vence?'<span>vence '+br(c.garantia_vence)+(c.dias_garantia!==null?' ('+c.dias_garantia+' dias)':'')+'</span>':'')+'</div></div>';
 var T=[['resumo','Resumo'],['hist','Histórico ('+(c.eventos||[]).length+')'],['checklist','Checklist'+(ck.length?' ('+pend+' pendente'+(pend===1?'':'s')+')':'')],['pleitos','Reajuste e pedidos ('+pl.length+')'],['notif','Notificações ('+j.notificacoes.length+')'],['lic','Licitações ('+j.licitacoes.length+')'],['diario','Diário Oficial'],['proc','Processos']];
 H+='<div class="ct-tb">'+T.map(function(t){return'<span data-tab="'+t[0]+'" class="'+(t[0]===FT?'on':'')+'">'+e(t[1])+'</span>'}).join('')+'</div><div class="ct-tc">';
 if(FT==='resumo'){
  H+='<div class="it"><b>Checklist do contrato:</b> '+(ck.length?(ck.length-pend)+' de '+ck.length+' itens em dia no período atual':'ainda não montado')+'</div>';
  H+='<div class="it"><b>Pedidos:</b> '+(pl.length?pl.map(function(p){return PL[p.tipo]+' ('+PS[p.status]+')'}).join(' · '):'nenhum registrado')+'</div>';
  H+='<div class="it"><b>Notificações a responder:</b> '+j.notificacoes.filter(function(n){return n.status==='aberto'}).length+' · <b>Licitações ligadas:</b> '+j.licitacoes.length+'</div>';
  if(c.notas)H+='<div class="it">📝 '+e(c.notas)+'</div>';
  H+='<div class="ct-act" style="justify-content:flex-start;margin-top:12px">'+(c.vigente?'<a class="ct-bt" target="_blank" rel="noopener" href="'+e(wa('Olá! Quero cotar seguro garantia para o contrato '+(c.numero||'')+' com '+(c.orgao||'')+' (valor '+moeda(c.valor)+', vigência até '+br(c.fim)+').'))+'">Cotar seguro garantia</a><a class="ct-bt" href="/plataforma/repactuacao/">Calcular repactuação</a>':'')+'<button class="ct-rel">Relatório do contrato</button><button class="g" data-ed="'+c.id+'">Editar dados do contrato</button>'+(c.link?'<a class="ct-bt" href="'+e(c.link)+'" target="_blank" rel="noopener">Ver na fonte</a>':'')+'</div>';
 }
 if(FT==='hist'){
  var ev=(c.eventos||[]).slice().sort(function(a,b){return String(a.data).localeCompare(String(b.data))});
  H+='<p class="ct-h">A evolução do contrato: aditivos, prorrogações, reajustes, repactuações, acréscimos, notificações, penalidades e mudanças de gestor, fiscal ou preposto. Tudo entra no relatório do contrato.</p>';
  H+='<p style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0"><button class="g ct-imd">Puxar extratos do Diário Oficial</button><button class="g ct-iah">Resumir um documento com a sua IA</button><button class="g ct-evn">+ Registrar evento</button></p>';
  H+='<div class="ct-iab" hidden style="border:1px solid rgba(201,164,92,.45);border-radius:12px;padding:12px;margin-bottom:10px"><p class="ct-h" style="margin-top:0">1. Escolha a sua IA: copiamos as instruções e você anexa o aditivo, apostilamento, ofício ou ata. 2. Cole aqui a resposta dela: preenchemos o evento sozinhos.</p><p class="ct-iai"></p><textarea class="ct-iat" rows="6" style="width:100%" placeholder="Cole aqui a resposta da IA (TIPO: … DATA: … )"></textarea><p><button class="ct-iap">Preencher o evento</button></p></div>';
  H+=ev.length?'<div class="ct-tl">'+ev.map(function(x){return'<div class="it"><b>'+br(x.data)+' · '+e(EVN[x.tipo]||x.tipo)+'</b>'+(x.numero?' · '+e(x.numero):'')+(x.auto?' <span class="m">(Diário Oficial)</span>':'')+'<br>'+e(x.desc||'')+'<br><span class="m">'+[x.vig?'nova vigência até '+br(x.vig):'',x.valor?'valor atualizado '+moeda(x.valor):'',x.pct?String(x.pct).replace('.',',')+'%':'',x.saiu||x.entrou?('saiu: '+(x.saiu||'—')+' · entrou: '+(x.entrou||'—')):'',x.pago?({sim:'pago',parcial:'pago em parte',nao:'não pago'}[x.pago]):''].filter(Boolean).join(' · ')+'</span>'+(x.link?' <a href="'+e(x.link)+'" target="_blank" rel="noopener">abrir</a>':'')+' <a href="#" data-eve="'+x.id+'" class="m">editar</a> · <a href="#" data-evx="'+x.id+'" class="m">excluir</a></div>'}).join('')+'</div>':'<p class="ct-h">Nenhum evento ainda. Comece puxando os extratos do Diário Oficial.</p>';
  H+='<form class="ct-evf" hidden style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px"><input type="hidden" name="eid"><label>Tipo<select name="tipo">'+Object.keys(EVN).map(function(k){return'<option value="'+k+'">'+EVN[k]+'</option>'}).join('')+'</select></label><label>Data<input type="date" name="data"></label><label>Número<input name="numero" placeholder="Ex.: 2º Termo Aditivo"></label><label>Nova vigência até<input type="date" name="vig"></label><label>Valor total atualizado (R$)<input name="valor" inputmode="decimal"></label><label>Percentual (%)<input name="pct" inputmode="decimal"></label><label>Quem saiu<input name="saiu" placeholder="gestor, fiscal, preposto…"></label><label>Quem entrou<input name="entrou"></label><label>Foi pago?<select name="pago"><option value="">—</option><option value="sim">Sim</option><option value="parcial">Em parte</option><option value="nao">Não</option></select></label><label style="grid-column:span 3">Resumo<textarea name="desc" rows="3"></textarea></label><label style="grid-column:span 3">Link (opcional)<input name="link"></label><button style="grid-column:span 3">Salvar evento</button></form>';
 }
 if(FT==='checklist'){
  H+='<p class="ct-h">Monte a auditoria do contrato: o que precisa ser feito e conferido em cada período. Quem marcar informa a data, o responsável e onde o documento está salvo na rede da empresa (nada é enviado para cá).</p>';
  if(!ck.length)H+='<p style="margin:10px 0"><b>Começar com um modelo:</b> <button class="g" data-mod="mo">Serviços com mão de obra</button> <button class="g" data-mod="bens">Fornecimento de bens</button> <button class="g" data-mod="obra">Obras e engenharia</button></p>';
  var grupos=['mensal','trimestral','anual','unica'];grupos.forEach(function(pg){var its=ck.filter(function(x){return x.per===pg});if(!its.length)return;var cp=comp(pg);H+='<h4>'+PERN[pg]+(pg!=='unica'?' · '+compNome(cp):'')+'</h4>';
   its.forEach(function(x){var f=chk[x.id+'|'+cp];H+='<div class="it ck"><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" data-ck="'+x.id+'" data-cp="'+cp+'"'+(f?' checked':'')+'><span><b>'+e(x.nome)+'</b>'+(x.resp?' <span class="m">· responsável: '+e(x.resp)+'</span>':'')+(f?'<br><span class="m">Feito em '+br(f.em)+(f.por?' por '+e(f.por):'')+(f.local?' · salvo em '+e(f.local):'')+(f.obs?' · '+e(f.obs):'')+'</span>':'')+'</span></label><a href="#" data-ckx="'+x.id+'" class="m" style="float:right">remover</a></div>'})});
  H+='<form class="ct-ckf" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-top:12px"><input name="nome" placeholder="Novo item (ex.: Relatório mensal ao fiscal)"><select name="per"><option value="mensal">Mensal</option><option value="trimestral">Trimestral</option><option value="anual">Anual</option><option value="unica">Uma vez</option></select><input name="resp" placeholder="Responsável"><button>Adicionar</button></form>';
  H+='<div class="ct-ckm" hidden style="margin-top:12px;border:1px solid rgba(201,164,92,.45);border-radius:12px;padding:12px"><b>Marcar como feito</b><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px"><label>Data<input type="date" name="em"></label><label>Feito por<input name="por" placeholder="Nome do gerente / gestor"></label><label style="grid-column:span 2">Onde está salvo (pasta da rede)<input name="local" placeholder="Ex.: \\\\servidor\\contratos\\UFES\\2026-10\\FGTS.pdf"></label><label style="grid-column:span 2">Observação<input name="obs"></label></div><p><button class="ct-ckok">Confirmar</button> <button class="g ct-ckno">Cancelar</button></p></div>';
 }
 if(FT==='pleitos'){
  H+='<p class="ct-h">Reajuste, repactuação, reequilíbrio, prorrogação e aditivos: o que já foi pedido e o que falta pedir. O prazo vira lembrete na agenda.</p>';
  H+=pl.length?pl.map(function(p){return'<div class="it"><b>'+PL[p.tipo]+'</b> · '+PS[p.status]+(p.pct?' · '+String(p.pct).replace('.',',')+'%':'')+(p.valor?' · '+moeda(p.valor):'')+(p.pago?' · '+({sim:'pago',parcial:'pago em parte',nao:'não pago'}[p.pago]):'')+'<br><span class="m">'+(p.data?'Pedido em '+br(p.data):'')+(p.prazo?' · fazer até '+br(p.prazo):'')+(p.processo?' · processo '+e(p.processo):'')+(p.obs?' · '+e(p.obs):'')+'</span> <a href="#" data-ple="'+p.id+'" class="m">editar</a> · <a href="#" data-plx="'+p.id+'" class="m">excluir</a></div>'}).join(''):'<p class="ct-h">Nenhum pedido registrado.</p>';
  H+='<form class="ct-plf" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px"><input type="hidden" name="pid"><label>Tipo<select name="tipo">'+Object.keys(PL).map(function(k){return'<option value="'+k+'">'+PL[k]+'</option>'}).join('')+'</select></label><label>Situação<select name="status">'+Object.keys(PS).map(function(k){return'<option value="'+k+'">'+PS[k]+'</option>'}).join('')+'</select></label><label>Valor pedido (R$)<input name="valor" inputmode="decimal"></label><label>Data do pedido<input type="date" name="data"></label><label>Fazer até<input type="date" name="prazo"></label><label>Nº do processo<input name="processo"></label><label>Percentual (%)<input name="pct" inputmode="decimal"></label><label>Foi pago?<select name="pago"><option value="">—</option><option value="sim">Sim, pago</option><option value="parcial">Pago em parte</option><option value="nao">Não pago</option></select></label><span></span><label style="grid-column:span 3">Observação<input name="obs"></label><button style="grid-column:span 3">Salvar pedido</button></form>';
 }
 if(FT==='notif'){
  H+=j.notificacoes.length?j.notificacoes.map(function(n){return'<div class="it"><a href="/plataforma/recursos/?ver='+n.id+'"><b>'+e(n.titulo||n.tipo_nome)+'</b></a>'+(n.numero_doc?' · '+e(n.numero_doc):'')+'<br><span class="m">'+e(n.tipo_nome)+(n.intimacao?' · recebida em '+br(n.intimacao):'')+(n.prazo?' · prazo '+br(n.prazo)+(n.prazo_hora&&n.prazo_hora!=='23:59'?' '+n.prazo_hora:''):'')+' · '+({aberto:'a responder',respondido:'respondido',protocolado:'protocolado',deferido:'deferido',indeferido:'indeferido'}[n.status]||n.status)+'</span></div>'}).join(''):'<p class="ct-h">Nenhuma notificação registrada.</p>';
  H+='<p><a href="/plataforma/recursos/?contrato='+c.id+'&tipo=notificacao">+ Registrar notificação deste contrato</a></p>';
 }
 if(FT==='lic'){
  H+='<p class="ct-h">Licitações ligadas a este contrato: a nova licitação do mesmo objeto, uma ampliação ou a licitação que deu origem a ele.</p>';
  H+=j.licitacoes.length?j.licitacoes.map(function(l){return'<div class="it"><a href="/plataforma/licitacoes/?ver='+l.id+'"><b>'+e(l.rot)+'</b></a><br><span class="m">'+e(l.objeto)+(l.abertura?' · sessão '+br(l.abertura):'')+(l.prazo_imp?' · impugnação até '+br(l.prazo_imp):'')+'</span> <a href="#" data-lux="'+l.id+'" class="m">desvincular</a></div>'}).join(''):'<p class="ct-h">Nenhuma licitação ligada.</p>';
  if(j.lic_outras.length)H+='<p style="display:flex;gap:8px;margin-top:10px"><select class="ct-lsel"><option value="">Ligar uma licitação da sua gestão…</option>'+j.lic_outras.map(function(l){return'<option value="'+l.id+'">'+e(l.rot.slice(0,90))+'</option>'}).join('')+'</select><button class="g ct-lok">Ligar</button></p>';
  H+='<p><a href="/plataforma/alertas/">Criar alerta para a próxima licitação deste objeto</a></p>';
 }
 if(FT==='diario')H+=(j.numero_busca?(j.radar.length?'<p class="ct-h">Já encontradas pelo seu Radar do Diário Oficial:</p>'+pubs(j.radar,''):'')+'<button class="g ct-dio" data-id="'+c.id+'">Buscar publicações deste contrato (nº '+e(j.numero_busca)+') no DOU e no diário da cidade</button>':'<p class="ct-h">Cadastre o número do contrato (ex.: 26/2025) para buscarmos as publicações.</p>');
 if(FT==='proc')H+='<p class="ct-h">Processos judiciais publicados no Diário de Justiça Eletrônico Nacional (CNJ) que citam o número deste contrato, a sua empresa e o órgão.</p><button class="g ct-pro">Buscar processos deste contrato</button><div class="ct-prl"></div>';
 H+='</div>';B.innerHTML=H}
FI.addEventListener('click',function(ev){var t=ev.target;
 if(t.matches('.ct-rel')){relatorio();return}
 if(t.matches('.ct-evn')){var f=FI.querySelector('.ct-evf');f.reset();f.querySelector('[name=eid]').value='';f.hidden=false;f.scrollIntoView({block:'center'});return}
 if(t.dataset.eve){ev.preventDefault();var x=(FJ.contrato.eventos||[]).filter(function(y){return y.id===t.dataset.eve})[0],f=FI.querySelector('.ct-evf');f.hidden=false;['tipo','data','numero','vig','valor','pct','saiu','entrou','pago','desc','link'].forEach(function(k){f.querySelector('[name='+k+']').value=x[k]||''});f.querySelector('[name=eid]').value=x.id;f.scrollIntoView({block:'center'});return}
 if(t.dataset.evx){ev.preventDefault();if(confirm('Excluir este evento do histórico?'))ext({acao:'evento_excluir',eid:t.dataset.evx});return}
 if(t.matches('.ct-imd')){t.disabled=true;t.textContent='Buscando extratos no Diário Oficial…';req('POST','contratos/extra',{id:FID,acao:'importar_dou'}).then(function(r){FT='hist';return ficha(FID,true).then(function(){alert(r.novos?r.novos+' evento(s) do Diário Oficial entraram no histórico. Confira e complete.':'Nenhum extrato novo encontrado para o número deste contrato.')})}).catch(function(){t.disabled=false;t.textContent='Tentar de novo'});return}
 if(t.matches('.ct-iah')){var b=FI.querySelector('.ct-iab');b.hidden=!b.hidden;b.querySelector('.ct-iai').innerHTML=IAS.map(function(a,i){return'<button class="g" data-iax="'+i+'" style="margin:0 6px 6px 0"><img src="https://www.google.com/s2/favicons?domain='+a[2]+'&sz=64" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:6px">'+a[0]+'</button>'}).join('')+'<button class="g" data-iax="c">Só copiar</button>';return}
 if(t.closest('[data-iax]')){var bx=t.closest('[data-iax]');cp(IAP);if(bx.dataset.iax!=='c')window.open(IAS[+bx.dataset.iax][1],'_blank','noopener');bx.textContent='Copiado! Cole na IA e anexe o documento';return}
 if(t.matches('.ct-iap')){var o=parseIA(FI.querySelector('.ct-iat').value||'');var f=FI.querySelector('.ct-evf');f.reset();f.hidden=false;Object.keys(o).forEach(function(k){var i=f.querySelector('[name='+k+']');if(i&&o[k])i.value=o[k]});f.querySelector('[name=eid]').value='';f.scrollIntoView({block:'center'});return}
 if(t.dataset.tab){FT=t.dataset.tab;desenha();return}
 if(t.dataset.mod){ext({acao:'check_padrao',modelo:t.dataset.mod});return}
 if(t.dataset.ckx){ev.preventDefault();if(confirm('Remover este item do checklist?'))ext({acao:'check_item_excluir',iid:t.dataset.ckx});return}
 if(t.dataset.plx){ev.preventDefault();if(confirm('Excluir este pedido?'))ext({acao:'pleito_excluir',pid:t.dataset.plx});return}
 if(t.dataset.ple){ev.preventDefault();var p=(FJ.contrato.pleitos||[]).filter(function(x){return x.id===t.dataset.ple})[0],f=FI.querySelector('.ct-plf');['tipo','status','valor','data','prazo','processo','obs','pct','pago'].forEach(function(k){f.querySelector('[name='+k+']').value=p[k]||''});f.querySelector('[name=pid]').value=p.id;f.scrollIntoView({block:'center'});return}
 if(t.dataset.lux){ev.preventDefault();ext({acao:'lic_vincular',lic:t.dataset.lux,desvincular:1});return}
 if(t.matches('.ct-lok')){var v=FI.querySelector('.ct-lsel').value;if(v)ext({acao:'lic_vincular',lic:v});return}
 if(t.matches('.ct-ckno')){ev.preventDefault();FI.querySelector('.ct-ckm').hidden=true;desenha();return}
 if(t.matches('.ct-ckok')){ev.preventDefault();var M=FI.querySelector('.ct-ckm');ext({acao:'check_marcar',iid:M.dataset.iid,comp:M.dataset.cp,em:M.querySelector('[name=em]').value,por:M.querySelector('[name=por]').value,local:M.querySelector('[name=local]').value,obs:M.querySelector('[name=obs]').value});return}
 if(t.matches('.ct-pro')){t.disabled=true;t.textContent='Buscando no Diário de Justiça…';req('GET','contratos/processos?id='+FID).then(function(r){t.remove();FI.querySelector('.ct-prl').innerHTML=r.itens.length?r.itens.map(function(p){return'<div class="it"><b>'+e(p.processo)+'</b> · '+e(p.tribunal)+' · '+e(p.classe)+'<br><span class="m">'+br(p.data)+'</span> '+e(p.trecho)+(p.link?' <a href="'+e(p.link)+'" target="_blank" rel="noopener">abrir</a>':'')+'</div>'}).join(''):'<p class="ct-h">'+e(r.aviso||'Nenhum processo encontrado com o número deste contrato.')+'</p>'}).catch(function(){t.disabled=false;t.textContent='Tentar de novo'});return}});
FI.addEventListener('change',function(ev){var t=ev.target;if(!t.dataset.ck)return;
 if(!t.checked){if(confirm('Desmarcar este item?'))ext({acao:'check_desmarcar',iid:t.dataset.ck,comp:t.dataset.cp});else t.checked=true;return}
 t.checked=false;var M=FI.querySelector('.ct-ckm');M.dataset.iid=t.dataset.ck;M.dataset.cp=t.dataset.cp;M.querySelector('[name=em]').value=FJ.hoje;M.hidden=false;M.scrollIntoView({block:'center'})});
FI.addEventListener('submit',function(ev){ev.preventDefault();var f=ev.target,o={};new FormData(f).forEach(function(v,k){o[k]=v});
 if(f.matches('.ct-ckf')){o.acao='check_item';ext(o)}else if(f.matches('.ct-plf')){o.acao='pleito_salvar';o.valor=num(o.valor);o.pct=num(o.pct);ext(o)}else if(f.matches('.ct-evf')){o.acao='evento_salvar';o.valor=num(o.valor);o.pct=num(o.pct);ext(o)}});
R.querySelector('.ct-busca').addEventListener('input',function(){pintar()});
carregar().then(function(){var m=location.search.match(/[?&]ficha=([a-z0-9]+)/);if(m)ficha(m[1])});
})();
</script>
	<?php return ob_get_clean();
} );
