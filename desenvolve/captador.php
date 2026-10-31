/**
 * Desenvolve — Captador de publicações (diários oficiais por CNPJ e por nome)
 * Fonte: Querido Diário (Open Knowledge Brasil), diários oficiais municipais.
 * Cobrança: cada termo monitorado usa 1 crédito por mês (cobrado ao cadastrar e a cada renovação).
 * Depende do núcleo (dv_usar, dv_saldo) e do snippet de ferramentas (dv_tools_perm).
 */

define( 'DV_CAPT_MAX', 30 ); // termos por assinante

add_action( 'init', function () {
	if ( get_option( 'dv_capt_db' ) === '1' ) { return; }
	global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_capt (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		termo VARCHAR(190) NOT NULL,
		data DATE NOT NULL,
		municipio VARCHAR(120) NOT NULL DEFAULT '',
		uf CHAR(2) NOT NULL DEFAULT '',
		url VARCHAR(500) NOT NULL DEFAULT '',
		txt VARCHAR(500) NOT NULL DEFAULT '',
		trecho TEXT,
		visto TINYINT(1) NOT NULL DEFAULT 0,
		criado DATETIME NOT NULL,
		hash CHAR(40) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uh (user_id,hash),
		KEY ud (user_id,data)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'dv_capt_db', '1' );
	if ( ! wp_next_scheduled( 'dv_capt_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 09:30 UTC' ), 'daily', 'dv_capt_diario' ); }
} );

function dv_capt_fmt_cnpj( $d ) { return preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d ); }
function dv_capt_termos( $uid ) { $t = json_decode( get_user_meta( $uid, 'dv_capt_termos', true ) ?: '[]', true ); return is_array( $t ) ? $t : array(); }
function dv_capt_salvar( $uid, $t ) { update_user_meta( $uid, 'dv_capt_termos', wp_json_encode( array_values( $t ) ) ); }

function dv_capt_gravar( $uid, $rot, $data, $local, $uf, $url, $txt, $trecho ) {
	global $wpdb;
	return (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}dv_capt (user_id,termo,data,municipio,uf,url,txt,trecho,visto,criado,hash) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,0,%s,%s)",
		$uid, $rot, $data ?: current_time( 'Y-m-d' ), mb_substr( (string) $local, 0, 120 ), substr( (string) $uf, 0, 2 ), substr( (string) $url, 0, 500 ), substr( (string) $txt, 0, 500 ),
		mb_substr( (string) $trecho, 0, 4000 ), current_time( 'mysql' ), sha1( $rot . '|' . $url . '|' . $data ) ) );
}
/** Nome da empresa para buscar junto com o CNPJ (muitas publicações citam só a razão social). */
function dv_capt_nome_cnpj( $cnpj ) {
	$r = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 20 ) );
	$j = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $j['razao_social'] ) ) { return null; }
	$n = function_exists( 'dv_nt_nucleo' ) ? dv_nt_nucleo( $j['razao_social'] ) : strtoupper( remove_accents( $j['razao_social'] ) );
	return ( function_exists( 'dv_nt_util' ) ? dv_nt_util( $n ) : count( explode( ' ', $n ) ) >= 2 ) ? $n : '';
}
/** Querido Diário (diários municipais). */
function dv_capt_qd( $uid, $rot, $q, $desde ) {
	$novos = 0; $off = 0;
	do {
		$u = add_query_arg( array( 'querystring' => rawurlencode( $q ), 'published_since' => $desde, 'size' => 50, 'offset' => $off,
			'excerpt_size' => 500, 'number_of_excerpts' => 2, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' );
		$r = wp_remote_get( $u, array( 'timeout' => 30 ) );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { break; }
		$j = json_decode( wp_remote_retrieve_body( $r ), true ); $gs = $j['gazettes'] ?? array();
		foreach ( $gs as $g ) {
			$tr = implode( "\n…\n", array_map( function ( $e ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e ) ) ); }, (array) ( $g['excerpts'] ?? array() ) ) );
			$novos += dv_capt_gravar( $uid, $rot, $g['date'] ?? '', $g['territory_name'] ?? '', $g['state_code'] ?? '', $g['url'] ?? '', $g['txt_url'] ?? '', $tr );
		}
		$off += 50;
	} while ( count( $gs ) === 50 && $off < 300 );
	return $novos;
}
/** Diário Oficial da União (busca pública da Imprensa Nacional). */
function dv_capt_dou( $uid, $rot, $termo, $desde ) {
	$u = 'https://www.in.gov.br/consulta/-/buscar/dou?q=' . rawurlencode( '"' . $termo . '"' ) . '&s=todos&exactDate=personalizado&sortType=0&delta=75'
		. '&publishFrom=' . gmdate( 'd-m-Y', strtotime( $desde ) ) . '&publishTo=' . current_time( 'd-m-Y' );
	$r = wp_remote_get( $u, array( 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return 0; }
	$h = wp_remote_retrieve_body( $r );
	if ( ! preg_match( '#<script[^>]*id="_br_com_seatecnologia_in_buscadou_BuscaDouPortlet_params"[^>]*>(.*?)</script>#s', $h, $m ) ) { return 0; }
	$j = json_decode( trim( $m[1] ), true ); $novos = 0;
	foreach ( (array) ( $j['jsonArray'] ?? array() ) as $it ) {
		$d = (string) ( $it['pubDate'] ?? '' ); $data = preg_match( '#^(\d{2})/(\d{2})/(\d{4})#', $d, $x ) ? "$x[3]-$x[2]-$x[1]" : '';
		$tr = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( ( $it['title'] ?? '' ) . ' — ' . ( $it['content'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) );
		$url = ! empty( $it['urlTitle'] ) ? 'https://www.in.gov.br/web/dou/-/' . $it['urlTitle'] : '';
		$novos += dv_capt_gravar( $uid, $rot, $data, 'Diário Oficial da União' . ( ! empty( $it['pubName'] ) ? ' (' . $it['pubName'] . ')' : '' ), '', $url, '', $tr );
	}
	return $novos;
}
/** Busca o termo em todas as fontes e grava o que for novo. Devolve quantas entraram. */
function dv_capt_buscar( $uid, &$t, $desde ) {
	if ( $t['tipo'] === 'cnpj' ) {
		$rot = dv_capt_fmt_cnpj( $t['valor'] );
		if ( ! isset( $t['nome'] ) ) { $nm = dv_capt_nome_cnpj( $t['valor'] ); if ( $nm !== null ) { $t['nome'] = $nm; } }
		$raiz = substr( $rot, 0, 10 ); // 00.000.000 — pega a matriz e todas as filiais
		$n = dv_capt_qd( $uid, $rot, '"' . $raiz . '" | "' . $t['valor'] . '"', $desde ) + dv_capt_dou( $uid, $rot, $raiz, $desde );
		if ( ! empty( $t['nome'] ) ) { $n += dv_capt_qd( $uid, $rot, '"' . $t['nome'] . '"', $desde ) + dv_capt_dou( $uid, $rot, $t['nome'], $desde ); }
		return $n;
	}
	$v = str_replace( '"', '', $t['valor'] );
	return dv_capt_qd( $uid, $t['valor'], '"' . $v . '"', $desde ) + dv_capt_dou( $uid, $t['valor'], $v, $desde );
}

/** Cobra o mês do termo (1 crédito). Devolve true ou WP_Error. */
function dv_capt_cobrar( $uid, $t ) {
	$r = dv_usar( $uid, 'captador', 'termo|' . $t['tipo'] . '|' . $t['valor'] . '|' . current_time( 'Y-m' ), 'Monitorar: ' . ( $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor'] ) );
	return is_wp_error( $r ) ? $r : true;
}

add_action( 'dv_capt_diario', function () {
	$us = get_users( array( 'meta_key' => 'dv_capt_termos', 'fields' => 'ID' ) );
	$hoje = current_time( 'Y-m-d' );
	foreach ( $us as $uid ) {
		$ts = dv_capt_termos( $uid ); if ( ! $ts ) { continue; }
		$tot = 0; $novos = array();
		foreach ( $ts as &$t ) {
			if ( $t['ate'] < $hoje ) {
				$c = dv_capt_cobrar( $uid, $t );
				if ( is_wp_error( $c ) ) { $t['pausado'] = 1; continue; }
				$t['ate'] = gmdate( 'Y-m-d', strtotime( $hoje . ' +30 days' ) ); $t['pausado'] = 0;
			}
			if ( ! empty( $t['pausado'] ) ) { continue; }
			$n = dv_capt_buscar( $uid, $t, gmdate( 'Y-m-d', strtotime( ( $t['ult'] ?? $hoje ) . ' -3 days' ) ) );
			$t['ult'] = $hoje; $tot += $n; if ( $n ) { $novos[] = ( $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor'] ) . ': ' . $n; }
		}
		unset( $t ); dv_capt_salvar( $uid, $ts );
		dv_capt_enviar( $uid, $hoje );
	}
} );

/* ---------- Entrega diária: e-mail (da Desenvolve, para até 5 endereços) e WhatsApp ---------- */
function dv_capt_pref( $uid ) {
	$p = json_decode( get_user_meta( $uid, 'dv_capt_pref', true ) ?: '{}', true ) ?: array();
	$u = get_userdata( $uid );
	return $p + array( 'emails' => array( $u->user_email ), 'whats' => 0, 'tel' => (string) get_user_meta( $uid, 'dv_tel', true ), 'sempre' => 0 );
}
/** Envia o resumo do dia com as publicações gravadas hoje. Com "sempre", avisa também quando não houve novidade. */
function dv_capt_enviar( $uid, $hoje ) {
	global $wpdb; $p = dv_capt_pref( $uid ); $u = get_userdata( $uid );
	$pubs = $wpdb->get_results( $wpdb->prepare( "SELECT termo,data,municipio,uf,url,trecho FROM {$wpdb->prefix}dv_capt WHERE user_id=%d AND criado >= %s ORDER BY termo, data DESC LIMIT 100", $uid, $hoje . ' 00:00:00' ), ARRAY_A );
	$n = count( $pubs ); if ( ! $n && empty( $p['sempre'] ) ) { return; }
	$link = home_url( '/plataforma/captador/' ); $dt = date_i18n( 'd/m/Y', strtotime( $hoje ) );
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><b style="color:#C9A45C;letter-spacing:.2em;font-family:Georgia,serif">DESENVOLVE</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Captador de publicações · ' . $dt . '</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px">';
	$h .= '<p>Olá, ' . esc_html( $u->display_name ) . '.</p><p>' . ( $n ? '<b>' . $n . ' nova(s) publicação(ões)</b> nos diários oficiais para os termos que você monitora:' : 'Hoje não saiu nenhuma publicação nova para os termos que você monitora.' ) . '</p>';
	foreach ( $pubs as $x ) {
		$h .= '<div style="border-left:4px solid #C9A45C;background:#FAF8F3;padding:12px 14px;margin:12px 0;border-radius:8px"><div style="font-size:13px;color:#6E6A60"><b style="color:#16191E">' . esc_html( $x['termo'] ) . '</b> · ' . esc_html( $x['municipio'] . ( $x['uf'] ? '/' . $x['uf'] : '' ) ) . ' · ' . esc_html( date_i18n( 'd/m/Y', strtotime( $x['data'] ) ) ) . '</div><p style="font-size:14px;line-height:1.5;margin:6px 0">' . esc_html( mb_substr( $x['trecho'], 0, 700 ) ) . '</p>' . ( $x['url'] ? '<a href="' . esc_url( $x['url'] ) . '" style="color:#8A6A2C;font-weight:bold">Abrir o diário</a>' : '' ) . '</div>';
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( $link ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Ver no painel</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Precisa de apoio sobre alguma publicação? Responda este e-mail ou acione um consultor pela plataforma. Fonte: Querido Diário (Open Knowledge Brasil). Para mudar quem recebe, acesse o Captador no seu painel.</p></div></div>';
	$rem = dv_cfg( 'email_remetente' ) ?: 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, 'Captador Desenvolve · ' . $dt . ' · ' . ( $n ? $n . ' nova(s) publicação(ões)' : 'sem novidades' ), $h, $hd ); } }
	if ( ! empty( $p['whats'] ) && $n ) { dv_whats( $p['tel'], array( $u->display_name, (string) $n, $link ) ); }
}
/**
 * WhatsApp pela API oficial da Meta (WhatsApp Cloud API), com modelo de mensagem aprovado.
 * Modelo sugerido (nome dv_publicacoes, pt_BR, categoria Utilidade):
 * "Olá, {{1}}! O Captador da Desenvolve encontrou {{2}} nova(s) publicação(ões) nos diários oficiais hoje. Veja: {{3}}"
 * Configuração em Desenvolve > WhatsApp (ID do número, token e nome do modelo) — preenchida pelo administrador.
 */
function dv_whats( $tel, $params ) {
	$id = get_option( 'dv_wa_phone_id' ); $tk = get_option( 'dv_wa_token' ); $tpl = get_option( 'dv_wa_template', 'dv_publicacoes' );
	$tel = preg_replace( '/\D/', '', (string) $tel ); if ( ! $id || ! $tk || strlen( $tel ) < 10 ) { return false; }
	if ( strlen( $tel ) <= 11 ) { $tel = '55' . $tel; }
	$r = wp_remote_post( 'https://graph.facebook.com/v20.0/' . rawurlencode( $id ) . '/messages', array( 'timeout' => 20,
		'headers' => array( 'Authorization' => 'Bearer ' . $tk, 'Content-Type' => 'application/json' ),
		'body' => wp_json_encode( array( 'messaging_product' => 'whatsapp', 'to' => $tel, 'type' => 'template', 'template' => array(
			'name' => $tpl, 'language' => array( 'code' => 'pt_BR' ),
			'components' => array( array( 'type' => 'body', 'parameters' => array_map( function ( $v ) { return array( 'type' => 'text', 'text' => mb_substr( (string) $v, 0, 200 ) ); }, $params ) ) ) ) ) ) ) );
	return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
}
add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'WhatsApp', 'WhatsApp', 'manage_options', 'dv-whats', function () {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_wa' ) ) {
			update_option( 'dv_wa_phone_id', sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ), false );
			update_option( 'dv_wa_template', sanitize_key( wp_unslash( $_POST['tpl'] ?? 'dv_publicacoes' ) ), false );
			if ( ! empty( $_POST['tk'] ) ) { update_option( 'dv_wa_token', trim( wp_unslash( $_POST['tk'] ) ), false ); }
			echo '<div class="updated"><p>Salvo.</p></div>';
		}
		echo '<div class="wrap"><h1>WhatsApp (API oficial da Meta)</h1><p>Usado para avisar os assinantes das publicações do dia. É preciso uma conta WhatsApp Business na Meta, um número dedicado e o modelo de mensagem aprovado.</p><form method="post">';
		wp_nonce_field( 'dv_wa' );
		echo '<table class="form-table"><tr><th>ID do número de telefone</th><td><input name="id" class="regular-text" value="' . esc_attr( get_option( 'dv_wa_phone_id' ) ) . '"></td></tr>';
		echo '<tr><th>Token de acesso</th><td><input type="password" name="tk" class="regular-text" autocomplete="new-password" placeholder="' . ( get_option( 'dv_wa_token' ) ? 'Token salvo. Cole outro só para trocar.' : 'Cole o token permanente' ) . '"></td></tr>';
		echo '<tr><th>Nome do modelo</th><td><input name="tpl" class="regular-text" value="' . esc_attr( get_option( 'dv_wa_template', 'dv_publicacoes' ) ) . '"></td></tr></table>';
		submit_button( 'Salvar' ); echo '</form></div>';
	} );
}, 20 );

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	register_rest_route( 'desenvolve/v1', '/captador', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $uid = get_current_user_id();
		$ts = array_map( function ( $t ) { $t['rotulo'] = $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor']; return $t; }, dv_capt_termos( $uid ) );
		$w = ''; $f = sanitize_text_field( (string) $r['termo'] );
		if ( $f !== '' ) { $w = $wpdb->prepare( ' AND termo=%s', $f ); }
		$pubs = $wpdb->get_results( $wpdb->prepare( "SELECT id,termo,data,municipio,uf,url,txt,trecho,visto FROM {$wpdb->prefix}dv_capt WHERE user_id=%d$w ORDER BY data DESC, id DESC LIMIT 200", $uid ), ARRAY_A );
		$nv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_capt WHERE user_id=%d AND visto=0", $uid ) );
		return array( 'termos' => $ts, 'pubs' => $pubs, 'nao_vistas' => $nv, 'max' => DV_CAPT_MAX, 'saldo' => dv_saldo( $uid ), 'pref' => dv_capt_pref( $uid ), 'wa' => (bool) get_option( 'dv_wa_token' ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/termo', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $v = trim( (string) $r['valor'] ); $d = preg_replace( '/\D/', '', $v );
		if ( strlen( $d ) === 14 && ! preg_match( '/[a-zà-ú]{3,}/i', $v ) ) { $t = array( 'tipo' => 'cnpj', 'valor' => $d ); }
		else {
			$v = mb_strtoupper( preg_replace( '/\s+/', ' ', sanitize_text_field( $v ) ) );
			if ( mb_strlen( $v ) < 6 ) { return new WP_Error( 'dv_termo', 'Digite um CNPJ (14 números) ou um nome com pelo menos 6 letras.', array( 'status' => 400 ) ); }
			$t = array( 'tipo' => 'nome', 'valor' => mb_substr( $v, 0, 150 ) );
		}
		$ts = dv_capt_termos( $uid );
		foreach ( $ts as $x ) { if ( $x['tipo'] === $t['tipo'] && $x['valor'] === $t['valor'] ) { return new WP_Error( 'dv_dup', 'Esse termo já está sendo monitorado.', array( 'status' => 409 ) ); } }
		if ( count( $ts ) >= DV_CAPT_MAX ) { return new WP_Error( 'dv_max', 'Limite de ' . DV_CAPT_MAX . ' termos. Remova um para incluir outro.', array( 'status' => 400 ) ); }
		$c = dv_capt_cobrar( $uid, $t ); if ( is_wp_error( $c ) ) { return $c; }
		$hoje = current_time( 'Y-m-d' );
		$t += array( 'desde' => $hoje, 'ate' => gmdate( 'Y-m-d', strtotime( $hoje . ' +30 days' ) ), 'ult' => $hoje, 'pausado' => 0, 'id' => substr( md5( $t['tipo'] . $t['valor'] ), 0, 10 ) );
		$n = dv_capt_buscar( $uid, $t, gmdate( 'Y-m-d', strtotime( $hoje . ' -180 days' ) ) );
		$ts[] = $t; dv_capt_salvar( $uid, $ts );
		return array( 'ok' => true, 'encontradas' => $n, 'saldo' => dv_saldo( $uid ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/remover', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( $r['id'] );
		dv_capt_salvar( $uid, array_filter( dv_capt_termos( $uid ), function ( $t ) use ( $id ) { return $t['id'] !== $id; } ) );
		return array( 'ok' => true );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/pref', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$em = array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $r['emails'] ) ), 'is_email' ) );
		if ( ! $em ) { return new WP_Error( 'dv_email', 'Informe pelo menos um e-mail válido.', array( 'status' => 400 ) ); }
		$p = array( 'emails' => array_slice( array_unique( $em ), 0, 5 ), 'whats' => $r['whats'] ? 1 : 0, 'tel' => preg_replace( '/\D/', '', (string) $r['tel'] ), 'sempre' => $r['sempre'] ? 1 : 0 );
		update_user_meta( $uid, 'dv_capt_pref', wp_json_encode( $p ) );
		return array( 'ok' => true, 'pref' => $p );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/rebuscar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( $r['id'] ); $ts = dv_capt_termos( $uid ); $n = 0;
		foreach ( $ts as &$t ) { if ( $t['id'] === $id && empty( $t['pausado'] ) ) { $n = dv_capt_buscar( $uid, $t, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -180 days' ) ) ); } }
		unset( $t ); dv_capt_salvar( $uid, $ts );
		return array( 'ok' => true, 'encontradas' => $n );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/visto', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function () {
		global $wpdb; $wpdb->update( $wpdb->prefix . 'dv_capt', array( 'visto' => 1 ), array( 'user_id' => get_current_user_id() ) );
		return array( 'ok' => true );
	} ) );
} );

add_shortcode( 'dv_captador', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="cp" id="dv-capt" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<form class="cp-add" autocomplete="off">
		<label for="cp-v">CNPJ ou nome a monitorar</label>
		<div class="cp-row"><input id="cp-v" placeholder="00.000.000/0000-00 ou NOME DA EMPRESA / PESSOA" maxlength="150"><button type="submit">Monitorar</button></div>
		<p class="cp-h">Ao informar um CNPJ, monitoramos também todas as filiais e a razão social. Cada termo usa 1 crédito por mês. Ao cadastrar, buscamos os últimos 6 meses; depois, verificamos todo dia e avisamos por e-mail quando sair publicação nova.</p>
		<p class="cp-msg"></p>
	</form>
	<details class="cp-pref"><summary>Onde receber o resumo diário</summary>
		<label>E-mails (até 5, separados por vírgula)<input class="cp-em" placeholder="voce@empresa.com.br, licitacoes@empresa.com.br"></label>
		<label class="cp-ck"><input type="checkbox" class="cp-wa"> Receber aviso também por WhatsApp no número <input class="cp-tel" inputmode="tel" placeholder="(11) 99999-9999" style="max-width:180px"></label>
		<label class="cp-ck"><input type="checkbox" class="cp-sem"> Mandar o e-mail todo dia, mesmo sem publicação nova</label>
		<button type="button" class="cp-sv g">Salvar preferências</button> <span class="cp-pm cp-h"></span>
	</details>
	<div class="cp-grid">
		<aside><h3>Termos monitorados <small class="cp-n"></small></h3><ul class="cp-t"></ul></aside>
		<section><div class="cp-top"><h3>Publicações encontradas</h3><button type="button" class="cp-lido g">Marcar tudo como visto</button></div><div class="cp-p"><p class="cp-h">Carregando…</p></div></section>
	</div>
	<p class="cp-src">Fontes: Diário Oficial da União (Imprensa Nacional) e diários oficiais de municípios reunidos pelo Querido Diário (Open Knowledge Brasil). Para CNPJ, buscamos a matriz, todas as filiais (mesma raiz de CNPJ) e a razão social. A cobertura municipal depende dos municípios disponíveis na base; os diários estaduais estão sendo incluídos. Confira sempre a edição original.</p>
</div>
<style>
.cp{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.cp h3{font-size:1.05rem;margin:0 0 12px;color:#16191E}.cp small{color:var(--mu);font-weight:500}
.cp label{font-weight:700;display:block;margin-bottom:8px}
.cp-row{display:flex;gap:10px}.cp-row input{flex:1;border:1px solid #CFC8B8;border-radius:10px;padding:13px 14px;font:inherit;background:#fff}
.cp button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer}
.cp button.g{background:transparent;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.cp-h{color:var(--mu);font-size:.88rem;margin:8px 0 0}.cp-msg{margin:8px 0 0;font-weight:600}.cp-msg.e{color:#B04A3A}.cp-msg.o{color:#2F7A55}
.cp-grid{display:grid;grid-template-columns:300px 1fr;gap:24px;margin-top:26px}
.cp-t{list-style:none;margin:0;padding:0}.cp-t li{border:1px solid var(--ln);border-radius:12px;padding:10px 12px;margin-bottom:8px;background:#fff;cursor:pointer}
.cp-t li.on{border-color:var(--gd);box-shadow:0 0 0 2px rgba(201,164,92,.25)}
.cp-t b{display:block;font-size:.92rem;word-break:break-word}.cp-t span{color:var(--mu);font-size:.8rem}.cp-t a{float:right;color:#B04A3A;font-size:.8rem;text-decoration:none}
.cp-top{display:flex;justify-content:space-between;align-items:center;gap:10px}
.cp-i{border:1px solid var(--ln);border-radius:14px;padding:14px 16px;margin-bottom:10px;background:#fff}
.cp-i.nv{border-left:4px solid var(--gd)}.cp-i .m{font-size:.82rem;color:var(--mu);margin-bottom:6px}.cp-i .m b{color:#16191E}
.cp-i p{margin:0;font-size:.92rem;line-height:1.5;white-space:pre-line}.cp-i mark{background:#F4E3B8;padding:0 2px}
.cp-i .a{margin-top:8px;font-size:.85rem}.cp-i .a a{color:#8A6A2C;font-weight:700;margin-right:14px}
.cp-pref{margin-top:18px;border:1px solid var(--ln);border-radius:12px;padding:12px 16px;background:#fff}.cp-pref summary{font-weight:700;cursor:pointer}.cp-pref label{font-weight:600;margin:12px 0 0}.cp-pref .cp-em{width:100%;border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;margin-top:6px}.cp-ck{display:flex!important;gap:8px;align-items:center;font-weight:500!important;flex-wrap:wrap}.cp-ck .cp-tel{border:1px solid #CFC8B8;border-radius:8px;padding:6px 10px;font:inherit}.cp-sv{margin-top:12px}
.cp-src{color:var(--mu);font-size:.8rem;margin-top:22px}
@media(max-width:800px){.cp{padding:18px}.cp-grid{grid-template-columns:1fr}.cp-row{flex-direction:column}}
</style>
<script>
(function(){var R=document.getElementById('dv-capt');if(!R)return;var api=R.dataset.api,n=R.dataset.nonce,filtro='',msg=R.querySelector('.cp-msg');
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':n,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.split('-').reverse().join('/'):''}
function hl(t,k){var x=e(t);var ks=[k,k.replace(/\D/g,'').length===14?k.replace(/\D/g,''):''].filter(Boolean);ks.forEach(function(q){x=x.split(e(q)).join('<mark>'+e(q)+'</mark>')});return x}
function saldo(s){var el=document.getElementById('dv-saldo');if(el&&s&&!(window.DV&&DV.master))el.textContent='Créditos: '+s.total}
function carregar(){req('GET','captador'+(filtro?'?termo='+encodeURIComponent(filtro):'')).then(function(j){
R.querySelector('.cp-n').textContent=j.termos.length+' de '+j.max;
R.querySelector('.cp-t').innerHTML=j.termos.length?j.termos.map(function(t){return'<li data-r="'+e(t.rotulo)+'" class="'+(filtro===t.rotulo?'on':'')+'"><a href="#" data-x="'+e(t.id)+'">remover</a><b>'+e(t.rotulo)+'</b><span>'+(t.tipo==='cnpj'?'CNPJ':'Nome')+' · '+(+t.pausado?'pausado: sem créditos':'ativo até '+br(t.ate))+'</span></li>'}).join(''):'<li style="cursor:default"><span>Nenhum termo ainda. Cadastre um CNPJ ou nome acima.</span></li>';
R.querySelector('.cp-p').innerHTML=j.pubs.length?j.pubs.map(function(p){var ver=p.txt&&/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/.test(p.termo)?'<a target="_blank" rel="noopener" href="/?aq_pub=1&txt='+encodeURIComponent(p.txt)+'&cnpj='+encodeURIComponent(p.termo.replace(/\D/g,''))+'&orig='+encodeURIComponent(p.url)+'&t='+encodeURIComponent((p.municipio||'')+' - '+br(p.data))+'">Ver o ato</a>':'';return'<div class="cp-i'+(+p.visto?'':' nv')+'"><div class="m"><b>'+e(p.municipio)+(p.uf?'/'+e(p.uf):'')+'</b> · '+br(p.data)+' · '+e(p.termo)+'</div><p>'+hl(p.trecho||'Trecho não disponível.',p.termo)+'</p><div class="a">'+ver+(p.url?'<a target="_blank" rel="noopener" href="'+e(p.url)+'">Abrir o diário</a>':'')+'</div></div>'}).join(''):'<p class="cp-h">'+(j.termos.length?'Nenhuma publicação encontrada até agora. Verificamos todos os dias.':'As publicações aparecem aqui.')+'</p>';
var pf=j.pref||{};if(!R.dataset.pf){R.dataset.pf=1;R.querySelector('.cp-em').value=(pf.emails||[]).join(', ');R.querySelector('.cp-wa').checked=!!+pf.whats;R.querySelector('.cp-tel').value=pf.tel||'';R.querySelector('.cp-sem').checked=!!+pf.sempre;if(!j.wa)R.querySelector('.cp-wa').closest('label').insertAdjacentHTML('beforeend','<small>(o envio por WhatsApp está sendo ativado pela Desenvolve)</small>')}
saldo(j.saldo);if(j.pubs.length&&window.DV){var c=document.querySelector('.dv-cons');if(c)c.classList.add('on')}
}).catch(function(x){R.querySelector('.cp-p').innerHTML='<p class="cp-h">'+e((x&&x.message)||'Não foi possível carregar.')+'</p>'})}
R.querySelector('.cp-add').addEventListener('submit',function(ev){ev.preventDefault();var i=R.querySelector('#cp-v'),b=R.querySelector('.cp-add button');if(!i.value.trim())return;b.disabled=true;b.textContent='Buscando…';msg.className='cp-msg';msg.textContent='';
req('POST','captador/termo',{valor:i.value}).then(function(j){msg.className='cp-msg o';msg.textContent='Monitorando. '+(j.encontradas?j.encontradas+' publicação(ões) dos últimos 6 meses encontradas.':'Nenhuma publicação nos últimos 6 meses; seguimos verificando todo dia.');i.value='';filtro='';carregar()}).catch(function(x){msg.className='cp-msg e';msg.textContent=(x&&x.message)||'Erro.';if(x&&x.data&&x.data.status===402&&window.DV)msg.innerHTML=e(x.message)+' <a href="'+(DV.acesso?'/creditos/':'/assinar/')+'">'+(DV.acesso?'Comprar créditos':'Assinar')+'</a>'}).then(function(){b.disabled=false;b.textContent='Monitorar'})},true);
R.querySelector('.cp-t').addEventListener('click',function(ev){var x=ev.target.closest('[data-x]');if(x){ev.preventDefault();if(window.confirm&&!confirm('Parar de monitorar este termo? O crédito do mês não é devolvido.'))return;req('POST','captador/remover',{id:x.dataset.x}).then(carregar);return}var li=ev.target.closest('li[data-r]');if(li){filtro=filtro===li.dataset.r?'':li.dataset.r;carregar()}});
R.querySelector('.cp-sv').addEventListener('click',function(){var m=R.querySelector('.cp-pm');m.textContent='Salvando…';req('POST','captador/pref',{emails:R.querySelector('.cp-em').value,whats:R.querySelector('.cp-wa').checked,tel:R.querySelector('.cp-tel').value,sempre:R.querySelector('.cp-sem').checked}).then(function(){m.textContent='Preferências salvas.'}).catch(function(x){m.textContent=(x&&x.message)||'Erro.'})});
R.querySelector('.cp-lido').addEventListener('click',function(){req('POST','captador/visto').then(carregar)});
carregar()})();
</script>
	<?php return ob_get_clean();
} );
