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
			$l[] = $x + array( 'id' => substr( md5( uniqid( $k, true ) ), 0, 10 ), 'chave' => $k, 'cnpj' => $cnpj, 'data_base' => '', 'indice' => '', 'garantia_tipo' => '', 'garantia_valor' => 0, 'garantia_vence' => '', 'notas' => '', 'aviso' => '' ); $add++;
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
	$ck = 'dv_ctd1_' . md5( $num . $nome ); $x = get_transient( $ck ); if ( is_array( $x ) ) { return $x; }
	$pal = array_slice( array_filter( explode( ' ', preg_replace( '/[^A-Z0-9 ]/', ' ', strtoupper( remove_accents( $nome ) ) ) ), function ( $w ) { return strlen( $w ) > 3 && ! in_array( $w, array( 'LTDA', 'EIRELI', 'S/A', 'EPP' ), true ); } ), 0, 2 );
	$q = '"' . $num . '"' . ( $pal ? ' + "' . implode( ' ', $pal ) . '"' : '' );
	$desde = $c['inicio'] ? gmdate( 'Y-m-d', strtotime( $c['inicio'] . ' -60 days' ) ) : gmdate( 'Y-m-d', strtotime( '-3 years' ) );
	$u = add_query_arg( array( 'querystring' => rawurlencode( $q ), 'published_since' => $desde, 'size' => 30, 'excerpt_size' => 400, 'number_of_excerpts' => 2, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' );
	$r = wp_remote_get( $u, array( 'timeout' => 30 ) );
	if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
		foreach ( (array) ( json_decode( wp_remote_retrieve_body( $r ), true )['gazettes'] ?? array() ) as $g ) {
			$out[] = array( 'data' => $g['date'] ?? '', 'fonte' => ( $g['territory_name'] ?? '' ) . ( ! empty( $g['state_code'] ) ? '/' . $g['state_code'] : '' ), 'url' => $g['url'] ?? '', 'trecho' => mb_substr( implode( ' … ', array_map( function ( $e ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e ) ) ); }, (array) ( $g['excerpts'] ?? array() ) ) ), 0, 700 ) );
		}
	}
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
		return array( 'contrato' => $c, 'notificacoes' => $not, 'radar' => $radar, 'diarios' => $pub, 'numero_busca' => $num );
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
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:20px">desenvolve </span><b style="color:#C9A45C;font-size:20px">estratégias</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Meus contratos · avisos</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Olá, ' . esc_html( $u->display_name ) . '. Estes contratos pedem atenção:</p>';
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
.ct-fi{max-width:820px}.ct-fb h3{margin:0 0 4px}.ct-fb h4{margin:18px 0 8px;font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gd)}.ct-fb .gr{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.ct-fb .gr div{border:1px solid var(--ln);border-radius:12px;padding:10px 12px;background:#fff}.ct-fb .gr b{display:block;font-size:1.05rem}.ct-fb .gr span{color:var(--mu);font-size:.78rem;font-weight:600}.ct-fb .it{border-top:1px solid #EEE9DE;padding:8px 0;font-size:.88rem}.ct-fb .it .m{color:var(--mu);font-size:.78rem}.ct-fb a{color:#8A6A2C;font-weight:700}
@media(max-width:700px){.ct-fb .gr{grid-template-columns:1fr}}
.ct-vazio{border:1px dashed var(--ln);border-radius:14px;padding:22px;text-align:center;color:var(--mu);margin-top:10px}
@media(max-width:700px){.ct{padding:16px}.ct-3{grid-template-columns:1fr}.ct-c{grid-template-columns:1fr}.ct-c .ac{flex-direction:row;flex-wrap:wrap;min-width:0}.ct-emps input{width:100%}}
</style>
<script>
(function(){var R=document.getElementById('dv-ct');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,emp='',aba='atencao',F=R.querySelector('.ct-f'),D=F.closest('dialog'),M=R.querySelector('.ct-msg');
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
F.querySelector('.ct-ok').addEventListener('click',function(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});o.valor=num(o.valor);o.garantia_valor=num(o.garantia_valor);var m=F.querySelector('.ct-fm');m.textContent='Salvando…';req('POST','contratos/salvar',o).then(function(){D.close();carregar()}).catch(function(j){m.className='ct-fm ct-h e';m.textContent=(j&&j.message)||'Erro.'})});
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
 if(t.matches('.ct-dio')){t.disabled=true;t.textContent='Buscando nos diários oficiais…';req('GET','contratos/ficha?diarios=1&id='+t.dataset.id).then(function(j){t.outerHTML=pubs(j.diarios,'Nada encontrado nos diários municipais disponíveis com o número deste contrato e o nome da empresa.')}).catch(function(){t.disabled=false;t.textContent='Tentar de novo'});return}
 if(t.dataset.x){if(confirm('Remover este contrato da carteira?'))req('POST','contratos/excluir',{ids:[t.dataset.x]}).then(carregar)}});
var FI=R.querySelector('.ct-fi');
function pubs(l,vazio){return l&&l.length?l.map(function(p){return'<div class="it"><div class="m">'+br(p.data)+' · '+e(p.fonte||((p.municipio||'')+(p.uf?'/'+p.uf:'')))+'</div>'+e(String(p.trecho||'').slice(0,500))+(p.url?' <a href="'+e(p.url)+'" target="_blank" rel="noopener">abrir</a>':'')+'</div>'}).join(''):'<p class="ct-h">'+vazio+'</p>'}
function ficha(id){var B=FI.querySelector('.ct-fb');B.innerHTML='<p class="ct-h">Carregando a ficha…</p>';FI.showModal();
 req('GET','contratos/ficha?id='+id).then(function(j){var c=j.contrato,H='';
  H+='<h3>'+e(c.orgao||'')+'</h3><p class="ct-h">'+e(c.numero||'')+(c.valor?' · '+moeda(c.valor):'')+' · '+e(c.fonte||'')+'</p><p style="font-size:.92rem">'+e(c.objeto||'')+'</p>';
  var vg=c.dias_fim===null?'Sem data':(c.dias_fim<0?'Encerrado em '+br(c.fim):br(c.fim)+' ('+c.dias_fim+' dias)');
  H+='<div class="gr"><div><span>Vigência</span><b>'+vg+'</b>'+(c.verificar?'<span style="color:var(--am)">Confirme se foi prorrogado</span>':'')+'</div><div><span>Próximo reajuste / repactuação</span><b>'+(c.vigente&&c.prox_reajuste?br(c.prox_reajuste)+' ('+c.dias_reajuste+' dias)':'—')+'</b>'+(c.indice?'<span>'+e(c.indice)+'</span>':'')+'</div><div><span>Garantia</span><b>'+(c.garantia_tipo?e(c.garantia_tipo):'Não cadastrada')+'</b>'+(c.garantia_vence?'<span>vence '+br(c.garantia_vence)+(c.dias_garantia!==null?' ('+c.dias_garantia+' dias)':'')+'</span>':'')+(c.garantia_valor>0?'<span> · '+moeda(c.garantia_valor)+'</span>':'')+'</div></div>';
  H+='<h4>Notificações, ofícios e recursos ('+j.notificacoes.length+')</h4>'+(j.notificacoes.length?j.notificacoes.map(function(n){return'<div class="it"><b>'+e(n.titulo||n.tipo_nome)+'</b>'+(n.numero_doc?' · '+e(n.numero_doc):'')+'<div class="m">'+e(n.tipo_nome)+(n.intimacao?' · recebida em '+br(n.intimacao):'')+(n.prazo?' · prazo '+br(n.prazo)+(n.prazo_hora&&n.prazo_hora!=='23:59'?' '+n.prazo_hora:''):'')+' · '+({aberto:'a responder',respondido:'respondido',protocolado:'protocolado',deferido:'deferido',indeferido:'indeferido'}[n.status]||n.status)+'</div></div>'}).join(''):'<p class="ct-h">Nenhuma registrada.</p>')+'<p><a href="/plataforma/recursos/?contrato='+c.id+'&tipo=notificacao">+ Registrar notificação deste contrato</a></p>';
  H+='<h4>No Diário Oficial</h4>'+(j.numero_busca?(j.radar.length?'<p class="ct-h">Já encontradas pelo seu Radar do Diário Oficial:</p>'+pubs(j.radar,''):'')+'<button class="g ct-dio" data-id="'+c.id+'">Buscar publicações deste contrato (nº '+e(j.numero_busca)+') nos diários</button>':'<p class="ct-h">Cadastre o número do contrato (ex.: 26/2025) para buscarmos as publicações.</p>');
  H+='<div class="ct-act" style="justify-content:flex-start;margin-top:14px">'+(c.vigente?'<a class="g" style="border:1px solid var(--ln);border-radius:10px;padding:8px 12px;text-decoration:none" target="_blank" rel="noopener" href="'+e(wa('Olá! Quero cotar seguro garantia para o contrato '+(c.numero||'')+' com '+(c.orgao||'')+' (valor '+moeda(c.valor)+', vigência até '+br(c.fim)+').'))+'">Cotar seguro garantia</a> <a class="g" style="border:1px solid var(--ln);border-radius:10px;padding:8px 12px;text-decoration:none" href="/plataforma/repactuacao/">Calcular repactuação</a>':'')+(c.link?' <a style="padding:8px 12px" href="'+e(c.link)+'" target="_blank" rel="noopener">Ver na fonte</a>':'')+'</div>';
  B.innerHTML=H}).catch(function(j){B.innerHTML='<p class="ct-h">'+e((j&&j.message)||'Não foi possível abrir a ficha.')+'</p>'})}
R.querySelector('.ct-busca').addEventListener('input',function(){pintar()});
carregar().then(function(){var m=location.search.match(/[?&]ficha=([a-z0-9]+)/);if(m)ficha(m[1])});
})();
</script>
	<?php return ob_get_clean();
} );
