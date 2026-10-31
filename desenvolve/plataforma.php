/**
 * Desenvolve — Plataforma (núcleo)
 * Créditos, perfil Master, uma sessão por vez, histórico de uso, pedidos de consultor e Painel Desenvolve.
 * Instalado como snippet PHP do WPCode ("Executar em todos os lugares").
 * Nenhuma chave ou senha fica neste código: ficam nas opções do WordPress, preenchidas pelo painel.
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

define( 'DV_VER', '1.0.0' );

/* ---------------- Configuração ---------------- */
function dv_cfg( $k = null ) {
	$d = array(
		'preco_assinatura' => '79.90',
		'creditos_mes'     => 20,
		'preco_extra'      => '2.50',
		'pacotes'          => '10,20,50',
		'dedupe_dias'      => 30,
		'validade_extra'   => 12,
		'alerta_hora'      => 30,
		'email_alertas'    => get_option( 'admin_email' ),
		'asaas_ambiente'   => 'sandbox',
		'creditos_dossie'  => 3,
		'email_remetente'  => 'contato@desenvolveestrategias.com.br',
	);
	$c = wp_parse_args( (array) get_option( 'dv_cfg', array() ), $d );
	return $k ? ( $c[ $k ] ?? null ) : $c;
}

/* ---------------- Tabelas ---------------- */
add_action( 'init', function () {
	if ( get_option( 'dv_db_ver' ) === DV_VER ) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_uso (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		ferramenta VARCHAR(40) NOT NULL,
		chave VARCHAR(64) NOT NULL,
		rotulo VARCHAR(190) NOT NULL DEFAULT '',
		cobrado TINYINT NOT NULL DEFAULT 1,
		origem VARCHAR(12) NOT NULL DEFAULT '',
		ip VARCHAR(45) NOT NULL DEFAULT '',
		criado DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY u (user_id, criado),
		KEY uc (user_id, chave)
	) $cs;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_extrato (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		delta INT NOT NULL,
		tipo VARCHAR(20) NOT NULL,
		motivo VARCHAR(190) NOT NULL DEFAULT '',
		autor BIGINT UNSIGNED NOT NULL DEFAULT 0,
		criado DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY u (user_id, criado)
	) $cs;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_pedidos (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		ferramenta VARCHAR(40) NOT NULL DEFAULT '',
		mensagem TEXT NOT NULL,
		previa LONGTEXT NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'novo',
		nota TEXT NOT NULL,
		criado DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY s (status, criado)
	) $cs;" );
	update_option( 'dv_db_ver', DV_VER );
} );

/* ---------------- Usuário ---------------- */
function dv_is_master( $uid ) {
	return get_user_meta( $uid, 'dv_master', true ) === '1' || user_can( $uid, 'manage_options' );
}
function dv_status( $uid ) {
	$s = get_user_meta( $uid, 'dv_status', true ) ?: 'pendente';
	if ( $s === 'ativo' ) {
		$v = get_user_meta( $uid, 'dv_vence', true );
		if ( $v && strtotime( $v . ' 23:59:59' ) < current_time( 'timestamp' ) ) { $s = 'atrasado'; }
	}
	return $s;
}
function dv_lotes( $uid ) {
	$l = json_decode( get_user_meta( $uid, 'dv_lotes', true ) ?: '[]', true );
	$hoje = current_time( 'Y-m-d' );
	return array_values( array_filter( (array) $l, function ( $x ) use ( $hoje ) {
		return ( $x['restante'] ?? 0 ) > 0 && ( $x['expira'] ?? '9999-12-31' ) >= $hoje;
	} ) );
}
function dv_saldo( $uid ) {
	$st = dv_status( $uid );
	$mes = $st === 'ativo' ? (int) get_user_meta( $uid, 'dv_mes_saldo', true ) : 0;
	$extra = 0; $livre = 0;
	foreach ( dv_lotes( $uid ) as $l ) {
		if ( ! empty( $l['livre'] ) ) { $livre += $l['restante']; } elseif ( $st === 'ativo' ) { $extra += $l['restante']; }
	}
	return array( 'mes' => $mes, 'extra' => $extra, 'cortesia' => $livre, 'total' => $mes + $extra + $livre );
}
function dv_extrato( $uid, $delta, $tipo, $motivo = '' ) {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'dv_extrato', array(
		'user_id' => $uid, 'delta' => $delta, 'tipo' => $tipo, 'motivo' => mb_substr( $motivo, 0, 190 ),
		'autor' => get_current_user_id(), 'criado' => current_time( 'mysql' ),
	) );
}
/** Dá créditos em lote (extras comprados, cortesia do administrador). */
function dv_dar_creditos( $uid, $qtd, $motivo, $livre = false, $meses = null ) {
	$qtd = (int) $qtd; if ( ! $qtd ) { return; }
	if ( $qtd < 0 ) { // retirar: tira do mês primeiro, depois dos lotes
		$falta = -$qtd;
		$m = (int) get_user_meta( $uid, 'dv_mes_saldo', true );
		$t = min( $m, $falta ); update_user_meta( $uid, 'dv_mes_saldo', $m - $t ); $falta -= $t;
		$l = json_decode( get_user_meta( $uid, 'dv_lotes', true ) ?: '[]', true );
		foreach ( $l as &$x ) { if ( $falta <= 0 ) { break; } $t = min( $x['restante'], $falta ); $x['restante'] -= $t; $falta -= $t; }
		unset( $x );
		update_user_meta( $uid, 'dv_lotes', wp_json_encode( $l ) );
		dv_extrato( $uid, $qtd, 'ajuste', $motivo );
		return;
	}
	$meses = $meses ?? (int) dv_cfg( 'validade_extra' );
	$l = json_decode( get_user_meta( $uid, 'dv_lotes', true ) ?: '[]', true );
	$l[] = array( 'qtd' => $qtd, 'restante' => $qtd, 'livre' => $livre ? 1 : 0,
		'expira' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$meses months" ) ), 'motivo' => $motivo );
	update_user_meta( $uid, 'dv_lotes', wp_json_encode( $l ) );
	dv_extrato( $uid, $qtd, $livre ? 'cortesia' : 'extra', $motivo );
}
/** Renova o ciclo mensal (chamado na confirmação do pagamento ou pelo administrador). */
function dv_renovar( $uid, $motivo = 'Assinatura renovada', $dias = 31 ) {
	update_user_meta( $uid, 'dv_status', 'ativo' );
	update_user_meta( $uid, 'dv_mes_saldo', (int) dv_cfg( 'creditos_mes' ) );
	update_user_meta( $uid, 'dv_vence', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$dias days" ) ) );
	dv_extrato( $uid, (int) dv_cfg( 'creditos_mes' ), 'mensal', $motivo );
}

/** Registra um uso e desconta um crédito. Retorna array ou WP_Error. */
function dv_usar( $uid, $ferramenta, $chave, $rotulo = '' ) {
	global $wpdb;
	$t = $wpdb->prefix . 'dv_uso';
	$st = dv_status( $uid );
	if ( $st === 'bloqueado' ) { return new WP_Error( 'dv_bloqueado', 'Acesso bloqueado. Fale com a Desenvolve.', array( 'status' => 403 ) ); }
	$chave = substr( hash( 'sha256', $ferramenta . '|' . strtolower( trim( $chave ) ) ), 0, 64 );
	$base = array( 'user_id' => $uid, 'ferramenta' => $ferramenta, 'chave' => $chave, 'rotulo' => mb_substr( $rotulo, 0, 190 ),
		'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'criado' => current_time( 'mysql' ) );
	$dias = (int) dv_cfg( 'dedupe_dias' );
	$ja = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id=%d AND chave=%s AND criado > %s LIMIT 1",
		$uid, $chave, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $dias * DAY_IN_SECONDS ) ) );
	if ( $ja ) { return array( 'cobrado' => 0, 'motivo' => 'repetido', 'saldo' => dv_saldo( $uid ) ); }
	if ( dv_is_master( $uid ) ) {
		$wpdb->insert( $t, $base + array( 'cobrado' => 0, 'origem' => 'master' ) );
		return array( 'cobrado' => 0, 'motivo' => 'master', 'saldo' => null );
	}
	$origem = '';
	if ( $st === 'ativo' && (int) get_user_meta( $uid, 'dv_mes_saldo', true ) > 0 ) {
		update_user_meta( $uid, 'dv_mes_saldo', (int) get_user_meta( $uid, 'dv_mes_saldo', true ) - 1 ); $origem = 'mes';
	} else {
		$l = json_decode( get_user_meta( $uid, 'dv_lotes', true ) ?: '[]', true );
		usort( $l, function ( $a, $b ) { return strcmp( $a['expira'], $b['expira'] ); } );
		$hoje = current_time( 'Y-m-d' );
		foreach ( $l as &$x ) {
			if ( $x['restante'] > 0 && $x['expira'] >= $hoje && ( ! empty( $x['livre'] ) || $st === 'ativo' ) ) {
				$x['restante']--; $origem = ! empty( $x['livre'] ) ? 'cortesia' : 'extra'; break;
			}
		}
		unset( $x );
		if ( ! $origem ) {
			return new WP_Error( 'dv_sem_credito', $st === 'ativo' ? 'Seus créditos acabaram. Compre créditos extras para continuar.' : 'É preciso ter uma assinatura ativa para usar as ferramentas.', array( 'status' => 402, 'situacao' => $st ) );
		}
		update_user_meta( $uid, 'dv_lotes', wp_json_encode( $l ) );
	}
	$wpdb->insert( $t, $base + array( 'cobrado' => 1, 'origem' => $origem ) );
	dv_alerta_uso( $uid );
	return array( 'cobrado' => 1, 'motivo' => $origem, 'saldo' => dv_saldo( $uid ) );
}

/** Alerta de uso fora do padrão: muitas consultas por hora ou muitos IPs no dia. */
function dv_alerta_uso( $uid ) {
	global $wpdb; $t = $wpdb->prefix . 'dv_uso';
	$h = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id=%d AND cobrado=1 AND criado > %s", $uid, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ) ) );
	$ips = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM $t WHERE user_id=%d AND criado > %s", $uid, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ) ) );
	$motivo = $h >= (int) dv_cfg( 'alerta_hora' ) ? "$h consultas na última hora" : ( $ips >= 4 ? "$ips endereços de internet diferentes em 24 horas" : '' );
	if ( ! $motivo || get_transient( 'dv_alerta_' . $uid ) ) { return; }
	set_transient( 'dv_alerta_' . $uid, 1, 6 * HOUR_IN_SECONDS );
	$u = get_userdata( $uid );
	$al = (array) get_option( 'dv_alertas', array() );
	array_unshift( $al, array( 'user_id' => $uid, 'motivo' => $motivo, 'quando' => current_time( 'mysql' ) ) );
	update_option( 'dv_alertas', array_slice( $al, 0, 100 ), false );
	wp_mail( dv_cfg( 'email_alertas' ), 'Desenvolve: uso fora do padrão', "Conta: {$u->display_name} <{$u->user_email}>\nMotivo: $motivo\n\nVeja no Painel Desenvolve > Alertas." );
}

/* ---------------- Uma sessão por vez ---------------- */
add_action( 'set_logged_in_cookie', function ( $c, $e, $x, $uid, $scheme, $token ) {
	if ( $uid && $token && ! dv_is_master( $uid ) ) { WP_Session_Tokens::get_instance( $uid )->destroy_others( $token ); }
}, 10, 6 );

/* Assinantes não usam o wp-admin nem a barra de administração */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || current_user_can( 'edit_posts' ) ) { return; }
	wp_safe_redirect( home_url( '/painel/' ) ); exit;
} );
add_filter( 'show_admin_bar', function ( $s ) { return current_user_can( 'edit_posts' ) ? $s : false; } );
add_filter( 'login_redirect', function ( $to, $req, $user ) {
	return ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) ) ? home_url( '/painel/' ) : $to;
}, 10, 3 );

/* ---------------- API ---------------- */
add_action( 'rest_api_init', function () {
	$logado = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/me', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
		if ( ! is_user_logged_in() ) { return array( 'logado' => false ); }
		$u = wp_get_current_user();
		return array( 'logado' => true, 'nome' => $u->display_name, 'email' => $u->user_email,
			'doc' => get_user_meta( $u->ID, 'dv_doc', true ), 'master' => dv_is_master( $u->ID ),
			'situacao' => dv_status( $u->ID ), 'vence' => get_user_meta( $u->ID, 'dv_vence', true ), 'saldo' => dv_saldo( $u->ID ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/usar', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		$f = sanitize_key( $r['ferramenta'] ); $c = (string) $r['chave'];
		if ( ! $f || $c === '' ) { return new WP_Error( 'dv_param', 'Informe a ferramenta e a consulta.', array( 'status' => 400 ) ); }
		return dv_usar( get_current_user_id(), $f, $c, sanitize_text_field( (string) $r['rotulo'] ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/historico', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function () {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT ferramenta, rotulo, cobrado, origem, criado FROM {$wpdb->prefix}dv_uso WHERE user_id=%d ORDER BY id DESC LIMIT 50", get_current_user_id() ), ARRAY_A );
	} ) );
	register_rest_route( 'desenvolve/v1', '/consultor', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $u = wp_get_current_user();
		$msg = sanitize_textarea_field( (string) $r['mensagem'] );
		$prev = wp_kses_post( mb_substr( (string) $r['previa'], 0, 60000 ) );
		$f = sanitize_key( $r['ferramenta'] );
		$wpdb->insert( $wpdb->prefix . 'dv_pedidos', array( 'user_id' => $u->ID, 'ferramenta' => $f, 'mensagem' => $msg, 'previa' => $prev, 'nota' => '', 'criado' => current_time( 'mysql' ) ) );
		$tel = get_user_meta( $u->ID, 'dv_tel', true );
		wp_mail( dv_cfg( 'email_alertas' ), 'Desenvolve: novo pedido de consultor (' . $f . ')',
			"Cliente: {$u->display_name} <{$u->user_email}> $tel\nFerramenta: $f\n\nMensagem:\n$msg\n\nVeja a prévia completa no Painel Desenvolve > Pedidos de consultor." );
		return array( 'ok' => true, 'pedido' => $wpdb->insert_id );
	} ) );
} );

/* ---------------- Painel Desenvolve (administração) ---------------- */
add_action( 'admin_menu', function () {
	add_menu_page( 'Painel Desenvolve', 'Desenvolve', 'manage_options', 'dv-painel', 'dv_admin_resumo', 'dashicons-chart-line', 3 );
	add_submenu_page( 'dv-painel', 'Resumo', 'Resumo', 'manage_options', 'dv-painel', 'dv_admin_resumo' );
	add_submenu_page( 'dv-painel', 'Assinantes', 'Assinantes', 'manage_options', 'dv-assinantes', 'dv_admin_assinantes' );
	add_submenu_page( 'dv-painel', 'Uso e alertas', 'Uso e alertas', 'manage_options', 'dv-uso', 'dv_admin_uso' );
	add_submenu_page( 'dv-painel', 'Pedidos de consultor', 'Pedidos de consultor', 'manage_options', 'dv-pedidos', 'dv_admin_pedidos' );
	add_submenu_page( 'dv-painel', 'Configurações', 'Configurações', 'manage_options', 'dv-config', 'dv_admin_config' );
} );

function dv_admin_css() {
	echo '<style>.dv-w{max-width:1200px}.dv-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:16px 0}.dv-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.dv-card b{display:block;font-size:26px;line-height:1.1;margin-top:6px}.dv-card span{color:#646970}.dv-tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}.dv-ativo{background:#e6f4ea;color:#1e6b34}.dv-pendente{background:#f0f0f1;color:#50575e}.dv-atrasado{background:#fcf0e3;color:#8a4b00}.dv-cancelado,.dv-bloqueado{background:#fbeaea;color:#8a1f1f}.dv-master{background:#f5ecd6;color:#7a5a16}.dv-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.dv-inline input[type=number]{width:70px}.dv-inline input[type=text]{width:180px}</style>';
}
function dv_tag( $s ) { return '<span class="dv-tag dv-' . esc_attr( $s ) . '">' . esc_html( ucfirst( $s ) ) . '</span>'; }

function dv_admin_resumo() {
	global $wpdb; dv_admin_css();
	$us = get_users( array( 'fields' => 'ID', 'role__not_in' => array( 'administrator', 'editor' ) ) );
	$c = array( 'ativo' => 0, 'atrasado' => 0, 'pendente' => 0, 'cancelado' => 0, 'bloqueado' => 0 );
	foreach ( $us as $id ) { $s = dv_status( $id ); $c[ $s ] = ( $c[ $s ] ?? 0 ) + 1; }
	$ini = current_time( 'Y-m-01 00:00:00' );
	$usos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_uso WHERE criado >= %s", $ini ) );
	$extras = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(delta),0) FROM {$wpdb->prefix}dv_extrato WHERE tipo='extra' AND criado >= %s", $ini ) );
	$ped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_pedidos WHERE status='novo'" );
	$receita = $c['ativo'] * (float) dv_cfg( 'preco_assinatura' ) + $extras * (float) dv_cfg( 'preco_extra' );
	$top = $wpdb->get_results( $wpdb->prepare( "SELECT ferramenta, COUNT(*) n FROM {$wpdb->prefix}dv_uso WHERE criado >= %s GROUP BY ferramenta ORDER BY n DESC", $ini ) );
	echo '<div class="wrap dv-w"><h1>Painel Desenvolve</h1>';
	echo '<div class="dv-cards">';
	foreach ( array( array( 'Assinantes ativos', $c['ativo'] ), array( 'Receita estimada do mês', 'R$ ' . number_format( $receita, 2, ',', '.' ) ), array( 'Usos no mês', $usos ), array( 'Pedidos de consultor novos', $ped ) ) as $k ) {
		echo '<div class="dv-card"><span>' . esc_html( $k[0] ) . '</span><b>' . esc_html( $k[1] ) . '</b></div>';
	}
	echo '</div><p>Atrasados: ' . $c['atrasado'] . ' · Pendentes: ' . $c['pendente'] . ' · Cancelados: ' . $c['cancelado'] . ' · Bloqueados: ' . $c['bloqueado'] . ' · Créditos extras vendidos no mês: ' . $extras . '</p>';
	echo '<h2>Ferramentas mais usadas no mês</h2><table class="widefat striped" style="max-width:520px"><tbody>';
	foreach ( $top as $t ) { echo '<tr><td>' . esc_html( $t->ferramenta ) . '</td><td>' . (int) $t->n . '</td></tr>'; }
	if ( ! $top ) { echo '<tr><td>Nenhum uso registrado ainda.</td></tr>'; }
	echo '</tbody></table><p style="color:#646970">A receita é estimada pelos assinantes ativos e créditos vendidos. O valor recebido de fato aparece no Asaas.</p></div>';
}

function dv_admin_assinantes() {
	dv_admin_css();
	$q = sanitize_text_field( $_GET['q'] ?? '' );
	$args = array( 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC' );
	if ( $q ) { $args['search'] = '*' . $q . '*'; $args['search_columns'] = array( 'user_email', 'display_name', 'user_login' ); }
	$us = get_users( $args );
	echo '<div class="wrap dv-w"><h1>Assinantes</h1>';
	if ( isset( $_GET['ok'] ) ) { echo '<div class="notice notice-success"><p>Alteração salva.</p></div>'; }
	echo '<form method="get"><input type="hidden" name="page" value="dv-assinantes"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Nome ou e-mail"> <button class="button">Buscar</button></form>';
	echo '<p style="color:#646970">Para criar um usuário novo, use Usuários &gt; Adicionar novo. Depois ele aparece aqui para ativar, dar créditos ou marcar como Master.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Pessoa</th><th>Situação</th><th>Saldo</th><th>Vence</th><th>Ações</th></tr></thead><tbody>';
	foreach ( $us as $u ) {
		$m = dv_is_master( $u->ID ); $s = dv_status( $u->ID ); $sd = dv_saldo( $u->ID );
		$url = admin_url( 'admin-post.php' );
		$n = wp_nonce_field( 'dv_acao_' . $u->ID, '_dvn', true, false );
		echo '<tr><td><b>' . esc_html( $u->display_name ) . '</b><br>' . esc_html( $u->user_email ) . '<br><small>' . esc_html( get_user_meta( $u->ID, 'dv_doc', true ) ) . ' ' . esc_html( get_user_meta( $u->ID, 'dv_tel', true ) ) . '</small></td>';
		echo '<td>' . ( $m ? dv_tag( 'master' ) . ( $s === 'bloqueado' ? ' ' . dv_tag( $s ) : '' ) : dv_tag( $s ) ) . '</td>';
		echo '<td>' . ( $m ? 'Ilimitado' : (int) $sd['total'] . ' <small>(mês ' . $sd['mes'] . ', extra ' . $sd['extra'] . ', cortesia ' . $sd['cortesia'] . ')</small>' ) . '</td>';
		echo '<td>' . esc_html( get_user_meta( $u->ID, 'dv_vence', true ) ) . '</td><td>';
		echo '<form class="dv-inline" method="post" action="' . esc_url( $url ) . '">' . $n . '<input type="hidden" name="action" value="dv_acao"><input type="hidden" name="uid" value="' . $u->ID . '"><input type="hidden" name="op" value="creditos">';
		echo '<input type="number" name="qtd" value="10" step="1"><input type="text" name="motivo" placeholder="Motivo (ex.: cortesia)"><label><input type="checkbox" name="livre" value="1" checked> sem precisar de assinatura</label><button class="button">Dar / tirar créditos</button></form>';
		echo '<form class="dv-inline" method="post" action="' . esc_url( $url ) . '" style="margin-top:6px">' . $n . '<input type="hidden" name="action" value="dv_acao"><input type="hidden" name="uid" value="' . $u->ID . '">';
		echo '<button class="button" name="op" value="ativar">Ativar 30 dias</button> ';
		echo '<button class="button" name="op" value="' . ( get_user_meta( $u->ID, 'dv_master', true ) === '1' ? 'desmaster">Tirar Master' : 'master">Tornar Master' ) . '</button> ';
		echo $s === 'bloqueado' ? '<button class="button" name="op" value="desbloquear">Desbloquear</button>' : '<button class="button" name="op" value="bloquear" onclick="return confirm(\'Bloquear o acesso desta pessoa?\')">Bloquear</button>';
		echo ' <button class="button" name="op" value="sessoes">Encerrar sessões</button></form></td></tr>';
	}
	if ( ! $us ) { echo '<tr><td colspan="5">Nenhum usuário encontrado.</td></tr>'; }
	echo '</tbody></table></div>';
}
add_action( 'admin_post_dv_acao', function () {
	$uid = (int) ( $_POST['uid'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_dvn'] ?? '', 'dv_acao_' . $uid ) ) { wp_die( 'Sem permissão.' ); }
	$op = sanitize_key( $_POST['op'] ?? '' ); $motivo = sanitize_text_field( $_POST['motivo'] ?? '' );
	switch ( $op ) {
		case 'creditos': dv_dar_creditos( $uid, (int) $_POST['qtd'], $motivo ?: 'Ajuste do administrador', ! empty( $_POST['livre'] ) ); break;
		case 'ativar': dv_renovar( $uid, 'Ativado pelo administrador' ); break;
		case 'master': update_user_meta( $uid, 'dv_master', '1' ); dv_extrato( $uid, 0, 'master', 'Tornado Master' ); break;
		case 'desmaster': delete_user_meta( $uid, 'dv_master' ); dv_extrato( $uid, 0, 'master', 'Master removido' ); break;
		case 'bloquear': update_user_meta( $uid, 'dv_status_antes', get_user_meta( $uid, 'dv_status', true ) ); update_user_meta( $uid, 'dv_status', 'bloqueado' ); WP_Session_Tokens::get_instance( $uid )->destroy_all(); dv_extrato( $uid, 0, 'bloqueio', 'Bloqueado' ); break;
		case 'desbloquear': update_user_meta( $uid, 'dv_status', get_user_meta( $uid, 'dv_status_antes', true ) ?: 'pendente' ); dv_extrato( $uid, 0, 'bloqueio', 'Desbloqueado' ); break;
		case 'sessoes': WP_Session_Tokens::get_instance( $uid )->destroy_all(); break;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=dv-assinantes&ok=1' ) ); exit;
} );

function dv_admin_uso() {
	global $wpdb; dv_admin_css();
	echo '<div class="wrap dv-w"><h1>Uso e alertas</h1><h2>Alertas de uso fora do padrão</h2><table class="widefat striped"><tbody>';
	$al = (array) get_option( 'dv_alertas', array() );
	foreach ( array_slice( $al, 0, 30 ) as $a ) { $u = get_userdata( $a['user_id'] ); echo '<tr><td>' . esc_html( $a['quando'] ) . '</td><td>' . esc_html( $u ? $u->display_name . ' <' . $u->user_email . '>' : '#' . $a['user_id'] ) . '</td><td>' . esc_html( $a['motivo'] ) . '</td></tr>'; }
	if ( ! $al ) { echo '<tr><td>Nenhum alerta.</td></tr>'; }
	echo '</tbody></table><h2>Últimos usos</h2><table class="widefat striped"><thead><tr><th>Quando</th><th>Pessoa</th><th>Ferramenta</th><th>Consulta</th><th>Crédito</th><th>IP</th></tr></thead><tbody>';
	foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dv_uso ORDER BY id DESC LIMIT 200" ) as $r ) {
		$u = get_userdata( $r->user_id );
		echo '<tr><td>' . esc_html( $r->criado ) . '</td><td>' . esc_html( $u ? $u->display_name : '#' . $r->user_id ) . '</td><td>' . esc_html( $r->ferramenta ) . '</td><td>' . esc_html( $r->rotulo ) . '</td><td>' . ( $r->cobrado ? esc_html( $r->origem ) : 'não cobrado (' . esc_html( $r->origem ?: 'repetido' ) . ')' ) . '</td><td>' . esc_html( $r->ip ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

function dv_admin_pedidos() {
	global $wpdb; dv_admin_css(); $t = $wpdb->prefix . 'dv_pedidos';
	if ( isset( $_POST['pid'] ) && check_admin_referer( 'dv_ped' ) ) {
		$wpdb->update( $t, array( 'status' => sanitize_key( $_POST['status'] ), 'nota' => sanitize_textarea_field( $_POST['nota'] ) ), array( 'id' => (int) $_POST['pid'] ) );
		echo '<div class="notice notice-success"><p>Pedido atualizado.</p></div>';
	}
	echo '<div class="wrap dv-w"><h1>Pedidos de consultor</h1><table class="widefat striped"><thead><tr><th>Quando</th><th>Cliente</th><th>Ferramenta e mensagem</th><th>Prévia</th><th>Andamento</th></tr></thead><tbody>';
	foreach ( $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 100" ) as $p ) {
		$u = get_userdata( $p->user_id );
		echo '<tr><td>' . esc_html( $p->criado ) . '</td><td>' . esc_html( $u ? $u->display_name : '' ) . '<br>' . esc_html( $u ? $u->user_email : '' ) . '<br>' . esc_html( get_user_meta( $p->user_id, 'dv_tel', true ) ) . '</td>';
		echo '<td><b>' . esc_html( $p->ferramenta ) . '</b><br>' . nl2br( esc_html( $p->mensagem ) ) . '</td>';
		echo '<td><details><summary>Ver prévia</summary><div style="max-width:520px;max-height:420px;overflow:auto">' . wp_kses_post( $p->previa ) . '</div></details></td>';
		echo '<td><form method="post">' . wp_nonce_field( 'dv_ped', '_wpnonce', true, false ) . '<input type="hidden" name="pid" value="' . (int) $p->id . '"><select name="status">';
		foreach ( array( 'novo' => 'Novo', 'orcado' => 'Orçado', 'fechado' => 'Fechado', 'perdido' => 'Não fechou' ) as $k => $v ) { echo '<option value="' . $k . '"' . selected( $p->status, $k, false ) . '>' . $v . '</option>'; }
		echo '</select><br><textarea name="nota" rows="2" cols="24" placeholder="Anotação">' . esc_textarea( $p->nota ) . '</textarea><br><button class="button">Salvar</button></form></td></tr>';
	}
	echo '</tbody></table></div>';
}

function dv_admin_config() {
	dv_admin_css();
	if ( isset( $_POST['dv_cfg'] ) && check_admin_referer( 'dv_cfg' ) ) {
		$in = wp_unslash( $_POST['dv_cfg'] ); $c = dv_cfg();
		foreach ( array( 'preco_assinatura', 'preco_extra', 'pacotes', 'email_alertas', 'asaas_ambiente', 'email_remetente' ) as $k ) { $c[ $k ] = sanitize_text_field( $in[ $k ] ?? $c[ $k ] ); }
		foreach ( array( 'creditos_mes', 'dedupe_dias', 'validade_extra', 'alerta_hora', 'creditos_dossie' ) as $k ) { $c[ $k ] = max( 0, (int) ( $in[ $k ] ?? $c[ $k ] ) ); }
		update_option( 'dv_cfg', $c, false );
		if ( ! empty( $_POST['dv_asaas_key'] ) ) { update_option( 'dv_asaas_key', trim( wp_unslash( $_POST['dv_asaas_key'] ) ), false ); }
		if ( ! get_option( 'dv_webhook_token' ) ) { update_option( 'dv_webhook_token', wp_generate_password( 32, false ), false ); }
		echo '<div class="notice notice-success"><p>Configurações salvas.</p></div>';
	}
	$c = dv_cfg(); $tem = (bool) get_option( 'dv_asaas_key' );
	$f = function ( $k, $l, $tipo = 'text', $ajuda = '' ) use ( $c ) { echo '<tr><th><label>' . esc_html( $l ) . '</label></th><td><input type="' . $tipo . '" name="dv_cfg[' . $k . ']" value="' . esc_attr( $c[ $k ] ) . '" class="regular-text"' . ( $tipo === 'number' ? ' step="1"' : '' ) . '>' . ( $ajuda ? '<p class="description">' . esc_html( $ajuda ) . '</p>' : '' ) . '</td></tr>'; };
	echo '<div class="wrap dv-w"><h1>Configurações da plataforma</h1><form method="post">' . wp_nonce_field( 'dv_cfg', '_wpnonce', true, false ) . '<input type="hidden" name="dv_cfg[_]" value="1"><table class="form-table">';
	$f( 'preco_assinatura', 'Preço da assinatura (R$/mês)', 'text', 'Use ponto nos centavos. Ex.: 79.90' );
	$f( 'creditos_mes', 'Créditos por mês na assinatura', 'number' );
	$f( 'preco_extra', 'Preço do crédito extra (R$)', 'text', 'Ex.: 2.50' );
	$f( 'pacotes', 'Pacotes de créditos à venda', 'text', 'Quantidades separadas por vírgula. Ex.: 10,20,50' );
	$f( 'creditos_dossie', 'Créditos cobrados pelo dossiê completo', 'number' );
	$f( 'email_remetente', 'E-mail que envia os avisos aos assinantes', 'email' );
	$f( 'validade_extra', 'Validade dos créditos extras (meses)', 'number' );
	$f( 'dedupe_dias', 'Dias em que repetir a mesma consulta não gasta crédito', 'number' );
	$f( 'alerta_hora', 'Alerta quando uma conta passar de X usos em uma hora', 'number' );
	$f( 'email_alertas', 'E-mail que recebe alertas e pedidos de consultor', 'email' );
	echo '<tr><th>Ambiente do Asaas</th><td><select name="dv_cfg[asaas_ambiente]"><option value="sandbox"' . selected( $c['asaas_ambiente'], 'sandbox', false ) . '>Testes (sandbox)</option><option value="producao"' . selected( $c['asaas_ambiente'], 'producao', false ) . '>Produção (cobranças reais)</option></select></td></tr>';
	echo '<tr><th>Chave de API do Asaas</th><td><input type="password" name="dv_asaas_key" value="" class="regular-text" autocomplete="new-password" placeholder="' . ( $tem ? 'Chave salva. Cole uma nova só para trocar.' : 'Cole aqui a chave do Asaas' ) . '"><p class="description">A chave fica guardada só no banco de dados do site e nunca é exibida de novo.</p></td></tr>';
	$wt = get_option( 'dv_webhook_token' );
	if ( $wt ) { echo '<tr><th>Webhook do Asaas</th><td><code>' . esc_html( rest_url( 'desenvolve/v1/asaas' ) ) . '</code><p class="description">Token de autenticação do webhook (cole no Asaas): <code>' . esc_html( $wt ) . '</code></p></td></tr>'; }
	echo '</table><p><button class="button button-primary">Salvar</button></p></form></div>';
}

/* ---------------- Telas do assinante (shortcodes) ---------------- */
add_shortcode( 'dv_painel', function () {
	if ( ! is_user_logged_in() ) {
		return '<div class="dvp"><h2>Entre na sua conta</h2>' . wp_login_form( array( 'echo' => false, 'redirect' => home_url( '/painel/' ), 'label_username' => 'E-mail', 'label_log_in' => 'Entrar' ) ) . '<p><a href="' . esc_url( wp_lostpassword_url( home_url( '/painel/' ) ) ) . '">Esqueci a senha</a> · <a href="' . esc_url( home_url( '/assinar/' ) ) . '">Criar conta</a></p></div>';
	}
	return '<div id="dv-painel" class="dvp" data-api="' . esc_attr( rest_url( 'desenvolve/v1/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-sair="' . esc_attr( wp_logout_url( home_url( '/' ) ) ) . '"></div>';
} );

add_shortcode( 'dv_cadastro', function () {
	if ( is_user_logged_in() ) { return '<p>Você já está conectado. <a href="' . esc_url( home_url( '/painel/' ) ) . '">Ir para o painel</a></p>'; }
	$err = '';
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dv_cad'] ) && wp_verify_nonce( $_POST['_dvc'] ?? '', 'dv_cad' ) ) {
		$nome = sanitize_text_field( wp_unslash( $_POST['nome'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$doc = preg_replace( '/\D/', '', $_POST['doc'] ?? '' );
		$tel = preg_replace( '/\D/', '', $_POST['tel'] ?? '' );
		$senha = (string) wp_unslash( $_POST['senha'] ?? '' );
		if ( ! $nome || ! is_email( $email ) ) { $err = 'Preencha nome e um e-mail válido.'; }
		elseif ( ! in_array( strlen( $doc ), array( 11, 14 ), true ) ) { $err = 'Informe um CPF ou CNPJ válido (só números).'; }
		elseif ( strlen( $senha ) < 8 ) { $err = 'A senha precisa ter pelo menos 8 caracteres.'; }
		elseif ( email_exists( $email ) ) { $err = 'Já existe uma conta com este e-mail. Use "Esqueci a senha" na tela de entrada.'; }
		elseif ( empty( $_POST['termos'] ) ) { $err = 'É preciso aceitar os Termos de uso e a Política de privacidade.'; }
		else {
			$uid = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => $senha, 'display_name' => $nome, 'first_name' => $nome, 'role' => 'subscriber' ) );
			if ( is_wp_error( $uid ) ) { $err = $uid->get_error_message(); }
			else {
				update_user_meta( $uid, 'dv_doc', $doc ); update_user_meta( $uid, 'dv_tel', $tel ); update_user_meta( $uid, 'dv_status', 'pendente' );
				update_user_meta( $uid, 'dv_termos', current_time( 'mysql' ) );
				wp_set_current_user( $uid ); wp_set_auth_cookie( $uid, true );
				wp_safe_redirect( home_url( '/painel/?bem-vindo=1' ) ); exit;
			}
		}
	}
	ob_start(); ?>
	<form class="dvp dv-cad" method="post">
		<?php wp_nonce_field( 'dv_cad', '_dvc' ); ?><input type="hidden" name="dv_cad" value="1">
		<h2>Crie sua conta</h2>
		<?php if ( $err ) { echo '<p class="dv-err">' . esc_html( $err ) . '</p>'; } ?>
		<label>Nome completo<input name="nome" required value="<?php echo esc_attr( $_POST['nome'] ?? '' ); ?>"></label>
		<label>E-mail<input type="email" name="email" required value="<?php echo esc_attr( $_POST['email'] ?? '' ); ?>"></label>
		<label>CPF ou CNPJ<input name="doc" inputmode="numeric" required value="<?php echo esc_attr( $_POST['doc'] ?? '' ); ?>"></label>
		<label>WhatsApp<input name="tel" inputmode="tel" value="<?php echo esc_attr( $_POST['tel'] ?? '' ); ?>"></label>
		<label>Crie uma senha<input type="password" name="senha" minlength="8" required autocomplete="new-password"></label>
		<label class="dv-chk"><input type="checkbox" name="termos" value="1" required> Li e aceito os <a href="/termos-de-uso/" target="_blank">Termos de uso</a> e a <a href="/politica-de-privacidade/" target="_blank">Política de privacidade</a>.</label>
		<button type="submit">Criar conta e continuar</button>
		<p>Já tem conta? <a href="<?php echo esc_url( home_url( '/painel/' ) ); ?>">Entrar</a></p>
	</form>
	<?php return ob_get_clean();
} );

/* ---------------- Ferramentas: contexto, portão de créditos, marca d'água e consultor ---------------- */
add_shortcode( 'dv_ctx', function ( $a ) {
	$a = shortcode_atts( array( 'tool' => '' ), $a );
	$tool = sanitize_key( $a['tool'] );
	$uid = get_current_user_id();
	$u = $uid ? wp_get_current_user() : null;
	$acesso = $uid && function_exists( 'dv_tools_perm' ) && dv_tools_perm();
	$doc = $uid ? (string) get_user_meta( $uid, 'dv_doc', true ) : '';
	if ( strlen( $doc ) === 14 ) { $doc = preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $doc ); }
	elseif ( strlen( $doc ) === 11 ) { $doc = preg_replace( '/^(\d{3})\d{6}(\d{2})$/', '$1.***.***-$2', $doc ); }
	$ctx = array( 'api' => rest_url( 'desenvolve/v1/' ), 'nonce' => $uid ? wp_create_nonce( 'wp_rest' ) : '', 'tool' => $tool,
		'logado' => (bool) $uid, 'acesso' => (bool) $acesso, 'master' => $uid ? dv_is_master( $uid ) : false,
		'nome' => $u ? $u->display_name : '', 'doc' => $doc, 'saldo' => $uid ? dv_saldo( $uid )['total'] : 0, 'cr_dossie' => (int) dv_cfg( 'creditos_dossie' ) );
	$h = '<script>window.DV=' . wp_json_encode( $ctx ) . ';window.DVWM="";</script>';
	$h .= '<div class="dv-tbar"><a href="/painel/">&larr; Meu painel</a><span id="dv-saldo">' . ( $uid ? ( $ctx['master'] ? 'Créditos: ilimitado' : 'Créditos: ' . (int) $ctx['saldo'] ) : '' ) . '</span></div>';
	if ( ! $uid ) {
		$h .= '<div class="dv-pw"><h2>Ferramenta exclusiva para assinantes</h2><p>Assine por R$ 79,90/mês e receba 20 créditos. Cada consulta, busca, cálculo ou relatório usa 1 crédito.</p><p><a class="dv-btn" href="/assinar/">Assinar agora</a> <a class="dv-btn g" href="/painel/">Já sou assinante</a></p></div><style>.dv-tool{display:none!important}</style>';
	} elseif ( ! $acesso ) {
		$h .= '<div class="dv-pw"><h2>Ative sua assinatura para usar</h2><p>Sua conta está criada. Ative a assinatura de R$ 79,90/mês para liberar 20 créditos.</p><p><a class="dv-btn" href="/assinar/">Ativar assinatura</a></p></div><style>.dv-tool{display:none!important}</style>';
	}
	return $h;
} );

add_action( 'wp_footer', function () {
	global $post;
	if ( ! $post || ! has_shortcode( $post->post_content, 'dv_ctx' ) ) { return; }
	?>
<style>
.dv-tbar{max-width:1180px;margin:18px auto 0;padding:0 20px;display:flex;justify-content:space-between;font:600 .9rem "Hanken Grotesk",sans-serif}
.dv-tbar a{color:#CBA55C;text-decoration:none}.dv-tbar span{color:#8E938F}
.dv-pw{max-width:720px;margin:60px auto;padding:36px;border:1px solid rgba(203,165,92,.35);border-radius:20px;background:#151A20;color:#F4F2EC;font-family:"Hanken Grotesk",sans-serif;text-align:center}
.dv-pw h2{color:#F4F2EC;margin:0 0 10px}.dv-pw p{color:#B9BDB8}
.dv-btn{background:#CBA55C;color:#241A06!important;border-radius:999px;padding:12px 22px;font-weight:700;text-decoration:none;display:inline-block;margin:4px}
.dv-btn.g{background:transparent;color:#F4F2EC!important;border:1px solid rgba(255,255,255,.2)}
.dv-cons{max-width:1180px;margin:24px auto;padding:22px 26px;border-radius:18px;background:#0C0F13;color:#F4F2EC;display:none;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;font-family:"Hanken Grotesk",sans-serif;border:1px solid rgba(203,165,92,.35)}
.dv-cons.on{display:flex}.dv-cons b{font-size:1.1rem}.dv-cons p{margin:4px 0 0;color:#B9BDB8}
.dv-cons button{background:#CBA55C;color:#241A06;border:0;border-radius:999px;padding:12px 22px;font-weight:700;cursor:pointer}
.dv-md{position:fixed;inset:0;background:rgba(5,7,10,.7);display:flex;align-items:center;justify-content:center;z-index:99999;font-family:"Hanken Grotesk",sans-serif}
.dv-md>div{background:#151A20;color:#F4F2EC;border-radius:18px;padding:28px;max-width:480px;width:calc(100% - 32px);border:1px solid rgba(255,255,255,.1)}
.dv-md h3{margin:0 0 8px;color:#F4F2EC}.dv-md p{color:#B9BDB8}.dv-md textarea{width:100%;min-height:110px;background:#0C0F13;color:#F4F2EC;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px;font:inherit}
.dv-md .x{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}.dv-md button{border:0;border-radius:999px;padding:11px 20px;font-weight:700;cursor:pointer;background:#CBA55C;color:#241A06}.dv-md button.g{background:transparent;color:#F4F2EC;border:1px solid rgba(255,255,255,.2)}
.dv-wm{position:fixed;left:14px;bottom:12px;z-index:9998;font:600 11px "Hanken Grotesk",sans-serif;color:rgba(20,24,30,.45);background:rgba(255,255,255,.7);padding:4px 10px;border-radius:999px;pointer-events:none}
@media print{.dv-wm{position:fixed;bottom:0;left:0;color:#555}.dv-tbar,.dv-cons{display:none!important}}
</style>
<script>
(function(){var D=window.DV;if(!D||!D.logado)return;
var tool=document.querySelector('.dv-tool');if(!tool)return;
var ok={},ACT=/consult|buscar|pesquis|calcul|compar|gerar|analis|simul|verific|estimar/i;
function chave(){var v=[];tool.querySelectorAll('input,select,textarea').forEach(function(i){if(i.type==='button'||i.type==='submit'||i.type==='hidden')return;if((i.type==='checkbox'||i.type==='radio')&&!i.checked)return;if(i.value!=='')v.push((i.name||i.id||'')+'='+i.value)});return v.join('|')}
function rot(){var i=tool.querySelector('input[type=text],input:not([type]),textarea');return i?String(i.value).slice(0,80):''}
function md(h){var m=document.createElement('div');m.className='dv-md';m.innerHTML='<div>'+h+'</div>';document.body.appendChild(m);m.addEventListener('click',function(e){if(e.target===m||e.target.hasAttribute('data-f'))m.remove()});return m}
function semCredito(msg){md('<h3>Créditos insuficientes</h3><p>'+(msg||'Seus créditos acabaram.')+'</p><div class="x"><button class="g" data-f>Fechar</button><a class="dv-btn" href="'+(D.acesso?'/creditos/':'/assinar/')+'">'+(D.acesso?'Comprar créditos':'Assinar')+'</a></div>')}
function saldo(s){var e=document.getElementById('dv-saldo');if(e&&s&&!D.master)e.textContent='Créditos: '+s.total}
var cons=document.createElement('div');cons.className='dv-cons';cons.innerHTML='<div><b>Quer apoio para decidir?</b><p>Um consultor da Desenvolve analisa este resultado com você. Serviço à parte, com proposta antes de qualquer cobrança.</p></div><button type="button">Acionar um consultor</button>';
tool.parentNode.insertBefore(cons,tool.nextSibling);
cons.querySelector('button').onclick=function(){var m=md('<h3>Acionar um consultor</h3><p>Conte o que você precisa. Enviamos junto a prévia desta ferramenta.</p><textarea placeholder="Ex.: quero avaliar se vale impugnar / pedir reequilíbrio"></textarea><div class="x"><button class="g" data-f>Cancelar</button><button data-s>Enviar pedido</button></div>');
m.querySelector('[data-s]').onclick=function(){var b=this;b.disabled=true;b.textContent='Enviando…';fetch(D.api+'consultor',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':D.nonce,'Content-Type':'application/json'},body:JSON.stringify({ferramenta:D.tool,mensagem:m.querySelector('textarea').value,previa:tool.innerHTML.slice(0,60000)})}).then(function(r){return r.json()}).then(function(j){m.firstChild.innerHTML=j&&j.ok?'<h3>Pedido enviado</h3><p>Um consultor da Desenvolve vai falar com você em breve pelo e-mail ou WhatsApp do cadastro.</p><div class="x"><button data-f>Fechar</button></div>':'<h3>Não foi possível enviar</h3><p>Tente de novo em instantes.</p><div class="x"><button data-f>Fechar</button></div>'})}};
function cobrar(depois){var k=chave();if(!k){depois();return}if(ok[k]){depois();return}
fetch(D.api+'usar',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':D.nonce,'Content-Type':'application/json'},body:JSON.stringify({ferramenta:D.tool,chave:k,rotulo:rot()})}).then(function(r){return r.json().then(function(j){return{s:r.status,j:j}})}).then(function(x){if(x.s>=400){semCredito(x.j&&x.j.message);return}ok[k]=1;saldo(x.j.saldo);cons.classList.add('on');depois()}).catch(function(){semCredito('Não foi possível validar seus créditos agora. Tente de novo.')})}
var passe=false;if(tool.hasAttribute('data-sem-portao'))return;
function acao(b){if(!b||b.disabled)return false;if(b.closest('.dv-md,.dv-cons'))return false;var t=(b.textContent||b.value||'')+' '+(b.getAttribute('aria-label')||'');return b.type==='submit'||ACT.test(t)}
document.addEventListener('click',function(e){if(passe)return;var b=e.target.closest('button,input[type=submit],input[type=button],a.btn,.btn');if(!b||!tool.contains(b)||!acao(b))return;var k=chave();if(!k||ok[k])return;e.preventDefault();e.stopImmediatePropagation();cobrar(function(){passe=true;b.click();passe=false})},true);
document.addEventListener('submit',function(e){if(passe||!tool.contains(e.target))return;var k=chave();if(!k||ok[k])return;e.preventDefault();e.stopImmediatePropagation();var f=e.target;cobrar(function(){passe=true;if(f.requestSubmit)f.requestSubmit();else f.submit();passe=false})},true);
document.addEventListener('keydown',function(e){if(passe||e.key!=='Enter'||!tool.contains(e.target)||e.target.tagName==='TEXTAREA')return;var k=chave();if(!k||ok[k])return;e.preventDefault();e.stopImmediatePropagation();var b=[].slice.call(tool.querySelectorAll('button,input[type=submit]')).filter(acao)[0];cobrar(function(){if(b){passe=true;b.click();passe=false}})},true);
})();
</script>
	<?php
}, 60 );

/* "Entrar" vira "Meu painel" para quem está conectado */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; }
	echo '<script>document.querySelectorAll(\'a[href$="/painel/"]\').forEach(function(a){if(/^\s*Entrar\s*$/i.test(a.textContent))a.textContent="Meu painel"})</script>';
}, 70 );

/* Enquanto o Asaas não tem chave, "Assinar" e "Comprar créditos" levam ao WhatsApp da Desenvolve */
function dv_wa_venda() { return 'https://wa.me/5511993500950?text=' . rawurlencode( 'Olá! Quero assinar a plataforma Desenvolve.' ); }
add_action( 'template_redirect', function () {
	if ( get_option( 'dv_asaas_key' ) || current_user_can( 'manage_options' ) ) { return; }
	if ( is_page( array( 'assinar', 'creditos' ) ) ) { wp_redirect( dv_wa_venda() ); exit; }
} );
add_action( 'wp_footer', function () {
	if ( get_option( 'dv_asaas_key' ) ) { return; }
	echo '<script>(function(){var u=' . wp_json_encode( dv_wa_venda() ) . ';function f(){document.querySelectorAll(\'a[href*="/assinar/"],a[href*="/creditos/"]\').forEach(function(a){a.href=u;a.target="_blank";a.rel="noopener"})}f();new MutationObserver(f).observe(document.body,{childList:true,subtree:true})})()</script>';
}, 71 );

/* ---------------- Aparência das telas do assinante ---------------- */
add_action( 'wp_footer', function () {
	global $post;
	if ( ! $post || ( ! has_shortcode( $post->post_content, 'dv_painel' ) && ! has_shortcode( $post->post_content, 'dv_cadastro' ) ) ) { return; }
	?>
<style>
.dvp{--bg:#0C0F13;--sf:#151A20;--ln:rgba(255,255,255,.1);--fg:#F4F2EC;--mu:#8E938F;--gd:#CBA55C;max-width:980px;margin:40px auto;padding:0 20px;color:var(--fg);font-family:"Hanken Grotesk",-apple-system,sans-serif}
.dvp h2{color:var(--fg);font-size:2rem;letter-spacing:-.02em;margin:0 0 18px}
.dvp a{color:var(--gd)}
.dv-cad{max-width:460px;display:flex;flex-direction:column;gap:14px}
.dvp label{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:.95rem}
.dvp input[type=text],.dvp input[type=email],.dvp input[type=password],.dvp input:not([type]),.dvp textarea,.dvp select{background:#10151B;border:1px solid var(--ln);color:var(--fg);border-radius:10px;padding:12px 14px;font:inherit}
.dvp .dv-chk{flex-direction:row;align-items:flex-start;font-weight:400;color:var(--mu)}
.dvp button,.dvp .dv-btn,.dvp #wp-submit{background:var(--gd);color:#241A06;border:0;border-radius:999px;padding:13px 24px;font-weight:700;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.dvp .dv-btn.g{background:transparent;color:var(--fg);border:1px solid var(--ln)}
.dv-err{background:rgba(214,137,122,.12);border:1px solid rgba(214,137,122,.4);color:#F2C2B8;padding:10px 14px;border-radius:10px}
.dv-ok{background:rgba(143,189,163,.12);border:1px solid rgba(143,189,163,.4);color:#CFE6D8;padding:10px 14px;border-radius:10px}
.dv-g{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}
.dv-c{background:var(--sf);border:1px solid var(--ln);border-radius:16px;padding:18px}
.dv-c span{color:var(--mu);font-size:.85rem}.dv-c b{display:block;font-size:2rem;letter-spacing:-.03em;margin-top:4px}
.dv-tools{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0}
.dv-tools a{background:var(--sf);border:1px solid var(--ln);border-radius:14px;padding:16px;color:var(--fg);text-decoration:none;font-weight:700}
.dv-tools a:hover{border-color:var(--gd)}
.dv-h{width:100%;border-collapse:collapse;font-size:.92rem}.dv-h td{padding:10px 8px;border-bottom:1px solid var(--ln);color:var(--mu)}.dv-h td:first-child{color:var(--fg)}
@media(max-width:700px){.dv-g,.dv-tools{grid-template-columns:1fr}}
</style>
<script>
(function(){var el=document.getElementById('dv-painel');if(!el)return;var api=el.dataset.api,n=el.dataset.nonce;
function g(p){return fetch(api+p,{headers:{'X-WP-Nonce':n},credentials:'same-origin'}).then(function(r){return r.json()})}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
var nomes={raiox:'Raio-X de concorrentes',comprador:'Raio-X do comprador',comparador:'Comparador de licitantes',precos:'Banco de preços',buscador:'Buscador de licitações',prazos:'Calculadora de prazos',reajuste:'Calculadora de reajuste',repactuacao:'Repactuação e reequilíbrio',captador:'Captador de publicações',dossie:'Due Diligence completa'};
var links=[['dossie','/plataforma/dossie/'],['raiox','/plataforma/raio-x/'],['comprador','/plataforma/raio-x-comprador/'],['comparador','/plataforma/comparador/'],['precos','/plataforma/banco-de-precos/'],['buscador','/plataforma/buscador/'],['prazos','/plataforma/prazos/'],['reajuste','/plataforma/reajuste/'],['repactuacao','/plataforma/repactuacao/'],['captador','/plataforma/captador/']];
Promise.all([g('me'),g('historico')]).then(function(a){var m=a[0],h=a[1]||[];var s=m.saldo||{};
var sit={ativo:'Assinatura ativa',pendente:'Assinatura pendente',atrasado:'Pagamento em atraso',cancelado:'Assinatura cancelada',bloqueado:'Acesso bloqueado'}[m.situacao]||m.situacao;
var H='<h2>Olá, '+e((m.nome||'').split(' ')[0])+'</h2>';
if(/bem-vindo/.test(location.search))H+='<p class="dv-ok">Conta criada. Falta só ativar a assinatura para liberar seus 20 créditos do mês.</p>';
H+='<div class="dv-g"><div class="dv-c"><span>Créditos disponíveis</span><b>'+(m.master?'Ilimitado':(s.total||0))+'</b></div><div class="dv-c"><span>Situação</span><b style="font-size:1.25rem">'+(m.master?'Conta Master':e(sit))+'</b></div><div class="dv-c"><span>'+(m.vence?'Renova em':'Detalhe do saldo')+'</span><b style="font-size:1.25rem">'+(m.vence?e(m.vence.split('-').reverse().join('/')):('Mês '+(s.mes||0)+' · extra '+(s.extra||0)))+'</b></div></div>';
if(!m.master&&m.situacao!=='ativo')H+='<p><a class="dv-btn" href="/assinar/">Ativar assinatura</a></p>';
else if(!m.master)H+='<p><a class="dv-btn g" href="/creditos/">Comprar créditos extras</a></p>';
H+='<h3 style="color:#F4F2EC;margin-top:28px">Ferramentas</h3><div class="dv-tools">'+links.map(function(l){return'<a href="'+l[1]+'">'+nomes[l[0]]+'</a>'}).join('')+'</div>';
H+='<h3 style="color:#F4F2EC;margin-top:28px">Últimos usos</h3><table class="dv-h"><tbody>'+(h.length?h.map(function(x){return'<tr><td>'+e(nomes[x.ferramenta]||x.ferramenta)+(x.rotulo?' · '+e(x.rotulo):'')+'</td><td>'+(x.cobrado==1?'1 crédito':'sem custo')+'</td><td>'+e(x.criado.slice(0,16).replace(/(\d+)-(\d+)-(\d+)/,'$3/$2/$1'))+'</td></tr>'}).join(''):'<tr><td>Nenhum uso ainda.</td></tr>')+'</tbody></table>';
H+='<p style="margin-top:28px"><a href="'+el.dataset.sair+'">Sair</a></p>';el.innerHTML=H;});
})();
</script>
	<?php
}, 50 );
