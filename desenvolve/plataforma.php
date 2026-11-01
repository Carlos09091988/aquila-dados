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
		'creditos_dossie'  => 10,
		'email_remetente'  => 'contato@desenvolveestrategias.com.br',
		// chave:Nome:preço:créditos por mês:termos no Radar do Diário Oficial
		'planos'           => 'essencial:Essencial:79:20:1|profissional:Profissional:149:60:5|empresarial:Empresarial:349:200:20',
		'custos'           => 'raiox:2,comprador:2',
		'fundador_desc'    => 30,
		'fundador_vagas'   => 0,
		'fundador_meses'   => 12,
		'trial_dias'       => 7,
		'trial_creditos'   => 5,
	);
	$c = wp_parse_args( (array) get_option( 'dv_cfg', array() ), $d );
	return $k ? ( $c[ $k ] ?? null ) : $c;
}

/* Tabela de preços 3 (out/2026): foco 100% em licitações, R$ 79 / 149 / 349, sem preço de fundador */
add_action( 'init', function () {
	if ( get_option( 'dv_cfg_ver' ) === '3' ) { return; }
	$c = (array) get_option( 'dv_cfg', array() );
	$c['planos'] = 'essencial:Essencial:79:20:1|profissional:Profissional:149:60:5|empresarial:Empresarial:349:200:20'; $c['fundador_vagas'] = 0; $c['preco_assinatura'] = '79';
	update_option( 'dv_cfg', $c, false ); update_option( 'dv_cfg_ver', '3', false );
}, 5 );
/* Tabela de preços 2 (nov/2026): três planos, pacotes com preço próprio, Due Diligence 10 créditos */
add_action( 'init', function () {
	if ( in_array( get_option( 'dv_cfg_ver' ), array( '2', '3' ), true ) ) { return; }
	$c = (array) get_option( 'dv_cfg', array() );
	$c['pacotes'] = '10:39,30:99,100:290'; $c['creditos_dossie'] = 10; $c['preco_assinatura'] = '97'; $c['creditos_mes'] = 20;
	update_option( 'dv_cfg', $c, false ); update_option( 'dv_cfg_ver', '2', false );
} );

/* ---------------- Planos, preços e custos ---------------- */
function dv_planos() {
	$o = array();
	foreach ( explode( '|', (string) dv_cfg( 'planos' ) ) as $l ) {
		$x = array_map( 'trim', explode( ':', $l ) ); if ( count( $x ) < 5 ) { continue; }
		$o[ sanitize_key( $x[0] ) ] = array( 'k' => sanitize_key( $x[0] ), 'nome' => $x[1], 'preco' => (float) $x[2], 'creditos' => (int) $x[3], 'radar' => (int) $x[4] );
	}
	return $o;
}
function dv_plano( $uid ) { $p = (string) get_user_meta( $uid, 'dv_plano', true ); $ps = dv_planos(); return isset( $ps[ $p ] ) ? $p : ( get_user_meta( $uid, 'dv_trial', true ) ? 'teste' : array_key_first( $ps ) ); }
function dv_plano_info( $k ) {
	$ps = dv_planos();
	if ( $k === 'teste' ) { return array( 'k' => 'teste', 'nome' => 'Teste grátis', 'preco' => 0, 'creditos' => (int) dv_cfg( 'trial_creditos' ), 'radar' => 1 ); }
	return $ps[ $k ] ?? reset( $ps );
}
function dv_fundador_restantes() { return max( 0, (int) dv_cfg( 'fundador_vagas' ) - (int) get_option( 'dv_fundadores', 0 ) ); }
function dv_preco_fundador( $preco ) { $v = $preco * ( 1 - (int) dv_cfg( 'fundador_desc' ) / 100 ); return max( 9, ceil( ( $v + 1 ) / 10 ) * 10 - 1 ); } // termina em 9: 69, 139, 349
/** Preço que este assinante paga pelo plano: fundador (30% por 12 meses) enquanto houver vaga ou se ele já é fundador. */
function dv_preco_plano( $uid, $k ) {
	$p = dv_plano_info( $k ); $ate = (string) get_user_meta( $uid, 'dv_fundador_ate', true );
	$fund = $ate ? $ate >= current_time( 'Y-m-d' ) : dv_fundador_restantes() > 0;
	return array( 'valor' => $fund ? dv_preco_fundador( $p['preco'] ) : $p['preco'], 'fundador' => $fund, 'cheio' => $p['preco'] );
}
function dv_custo( $f ) {
	if ( $f === 'dossie' ) { return max( 1, (int) dv_cfg( 'creditos_dossie' ) ); }
	foreach ( explode( ',', (string) dv_cfg( 'custos' ) ) as $x ) { $y = explode( ':', trim( $x ) ); if ( count( $y ) === 2 && sanitize_key( $y[0] ) === $f ) { return max( 1, (int) $y[1] ); } }
	return 1;
}
/** Pacotes de créditos: quantidade => preço */
function dv_pacotes() {
	$o = array();
	foreach ( explode( ',', (string) dv_cfg( 'pacotes' ) ) as $x ) { $y = explode( ':', trim( $x ) ); $q = (int) $y[0]; if ( $q > 0 ) { $o[ $q ] = isset( $y[1] ) ? (float) $y[1] : round( $q * (float) dv_cfg( 'preco_extra' ), 2 ); } }
	return $o;
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
/** Avisa por e-mail, uma vez por mês, quando restarem 3 créditos ou menos. */
function dv_aviso_credito( $uid, $s ) {
	$t = (int) ( $s['total'] ?? 99 ); if ( $t > 3 ) { return; }
	$m = current_time( 'Y-m' ); if ( get_user_meta( $uid, 'dv_aviso_cred', true ) === $m ) { return; }
	update_user_meta( $uid, 'dv_aviso_cred', $m );
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$from = dv_cfg( 'email_remetente' ) ?: 'contato@desenvolveestrategias.com.br';
	wp_mail( $u->user_email, 'Seus créditos na Desenvolve estão acabando',
		'Olá, ' . $u->first_name . "!\n\nRestam " . $t . ' crédito(s) na sua conta Desenvolve. Os créditos do plano renovam na próxima mensalidade.' .
		"\n\nPara não parar no meio de um Raio-X ou de uma Due Diligence, você pode comprar créditos extras agora:\n" . home_url( '/creditos/' ) .
		"\n\nPacotes: 10 créditos por R$ 39, 30 por R$ 99 e 100 por R$ 290. Os créditos extras valem por " . (int) dv_cfg( 'validade_extra' ) . " meses.\n\nEquipe Desenvolve",
		array( 'From: Desenvolve <' . $from . '>' ) );
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
		update_user_meta( $uid, 'dv_lotes', wp_slash( wp_json_encode( $l, JSON_UNESCAPED_UNICODE ) ) );
		dv_extrato( $uid, $qtd, 'ajuste', $motivo );
		return;
	}
	$meses = $meses ?? (int) dv_cfg( 'validade_extra' );
	$l = json_decode( get_user_meta( $uid, 'dv_lotes', true ) ?: '[]', true );
	$l[] = array( 'qtd' => $qtd, 'restante' => $qtd, 'livre' => $livre ? 1 : 0,
		'expira' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$meses months" ) ), 'motivo' => $motivo );
	update_user_meta( $uid, 'dv_lotes', wp_slash( wp_json_encode( $l, JSON_UNESCAPED_UNICODE ) ) );
	dv_extrato( $uid, $qtd, $livre ? 'cortesia' : 'extra', $motivo );
}
/** Renova o ciclo mensal (chamado na confirmação do pagamento ou pelo administrador). */
function dv_renovar( $uid, $motivo = 'Assinatura renovada', $dias = 31 ) {
	update_user_meta( $uid, 'dv_status', 'ativo' );
	$cr = (int) dv_plano_info( dv_plano( $uid ) )['creditos'];
	update_user_meta( $uid, 'dv_mes_saldo', $cr );
	update_user_meta( $uid, 'dv_vence', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$dias days" ) ) );
	dv_extrato( $uid, $cr, 'mensal', $motivo );
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
		update_user_meta( $uid, 'dv_lotes', wp_slash( wp_json_encode( $l, JSON_UNESCAPED_UNICODE ) ) );
	}
	$wpdb->insert( $t, $base + array( 'cobrado' => 1, 'origem' => $origem ) );
	dv_alerta_uso( $uid );
	$sd = dv_saldo( $uid ); dv_aviso_credito( $uid, $sd );
	return array( 'cobrado' => 1, 'motivo' => $origem, 'saldo' => $sd );
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
			'situacao' => dv_status( $u->ID ), 'vence' => get_user_meta( $u->ID, 'dv_vence', true ), 'saldo' => dv_saldo( $u->ID ),
			'plano' => dv_plano_info( dv_plano( $u->ID ) ), 'teste' => (bool) get_user_meta( $u->ID, 'dv_trial', true ) && ! get_user_meta( $u->ID, 'dv_plano', true ), 'fundador_ate' => get_user_meta( $u->ID, 'dv_fundador_ate', true ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/usar', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		$f = sanitize_key( $r['ferramenta'] ); $c = (string) $r['chave'];
		if ( ! $f || $c === '' ) { return new WP_Error( 'dv_param', 'Informe a ferramenta e a consulta.', array( 'status' => 400 ) ); }
		$uid = get_current_user_id(); $n = dv_custo( $f ); $rot = sanitize_text_field( (string) $r['rotulo'] );
		$u = dv_usar( $uid, $f, $c, $rot ); if ( is_wp_error( $u ) || $n <= 1 || empty( $u['cobrado'] ) ) { return $u; }
		if ( dv_saldo( $uid )['total'] < $n - 1 ) { return new WP_Error( 'dv_sem_credito', 'Esta consulta usa ' . $n . ' créditos e o seu saldo não é suficiente.', array( 'status' => 402 ) ); }
		for ( $i = 2; $i <= $n; $i++ ) { $u = dv_usar( $uid, $f, $c . '|' . $i, '' ); if ( is_wp_error( $u ) ) { return $u; } }
		$u['creditos'] = $n; return $u;
	} ) );
	/* Agenda do painel: licitações, contratos, notificações e documentos */
	register_rest_route( 'desenvolve/v1', '/agenda', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( $r ) {
		$tod = ! empty( $r['todos'] ); $uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' ); $A = array( 'licitacoes' => array(), 'contratos' => array(), 'notificacoes' => array(), 'documentos' => array() );
		$dd = function ( $d ) use ( $hoje ) { return $d ? (int) floor( ( strtotime( substr( $d, 0, 10 ) ) - strtotime( $hoje ) ) / 86400 ) : null; };
		if ( function_exists( 'dv_lic_itens' ) ) { foreach ( dv_lic_itens( $uid ) as $x ) {
			if ( ! in_array( $x['status'] ?? '', array( 'analise', 'participar', 'proposta', 'disputa', 'recurso' ), true ) || empty( $x['abertura'] ) ) { continue; }
			$c = dv_lic_calc( $x, $hoje ); $t = trim( ( $x['orgao'] ?? '' ) . ( ! empty( $x['numero'] ) ? ' · ' . $x['numero'] : '' ) );
			if ( $c['dias_imp'] !== null && ( $tod || $c['dias_imp'] >= 0 ) && in_array( $x['status'], array( 'analise', 'participar', 'proposta' ), true ) ) { $A['licitacoes'][] = array( 'o' => 'Último dia para impugnar', 't' => $t, 'd' => $c['prazo_imp'], 'n' => $c['dias_imp'], 'u' => '/plataforma/licitacoes/?ver=' . $x['id'] ); }
			if ( $c['dias_sessao'] !== null && ( $tod || $c['dias_sessao'] >= 0 ) ) { $A['licitacoes'][] = array( 'o' => 'Sessão' . ( strlen( $x['abertura'] ) > 10 ? ' às ' . substr( $x['abertura'], 11, 5 ) : '' ), 't' => $t, 'd' => substr( $x['abertura'], 0, 10 ), 'n' => $c['dias_sessao'], 'u' => '/plataforma/licitacoes/?ver=' . $x['id'] ); }
		} }
		if ( false && function_exists( 'dv_ct_itens' ) ) { foreach ( dv_ct_itens( $uid ) as $x ) {
			$c = dv_ct_calc( $x, $hoje ); $t = trim( ( $x['orgao'] ?? '' ) . ( ! empty( $x['numero'] ) ? ' · ' . $x['numero'] : '' ) );
			if ( ! empty( $c['verificar'] ) ) { $A['contratos'][] = array( 'o' => 'Confirmar se foi prorrogado', 't' => $t, 'd' => $x['fim'], 'n' => $c['dias_fim'], 'id' => $x['id'], 'u' => '/plataforma/contratos/?ficha=' . $x['id'] ); continue; }
			if ( ! $c['vigente'] ) { continue; }
			if ( $c['dias_fim'] !== null && ( $tod || $c['dias_fim'] <= 180 ) ) { $A['contratos'][] = array( 'o' => 'Fim da vigência', 't' => $t, 'd' => $x['fim'], 'n' => $c['dias_fim'], 'id' => $x['id'], 'u' => '/plataforma/contratos/?ficha=' . $x['id'] ); }
			if ( $c['dias_reajuste'] !== null && ( $tod || $c['dias_reajuste'] <= 90 ) ) { $A['contratos'][] = array( 'o' => 'Reajuste / repactuação', 't' => $t, 'd' => $c['prox_reajuste'], 'n' => $c['dias_reajuste'], 'id' => $x['id'], 'u' => '/plataforma/contratos/?ficha=' . $x['id'] ); }
			$u = '/plataforma/contratos/?ficha=' . $x['id'];
			foreach ( (array) ( $x['pleitos'] ?? array() ) as $pp ) { if ( ( $pp['status'] ?? '' ) === 'a_fazer' && ! empty( $pp['prazo'] ) ) { $A['contratos'][] = array( 'o' => 'Pedido a fazer: ' . ( array( 'reajuste' => 'reajuste', 'repactuacao' => 'repactuação', 'reequilibrio' => 'reequilíbrio', 'prorrogacao' => 'prorrogação', 'aditivo' => 'aditivo' )[ $pp['tipo'] ] ?? 'pedido' ), 't' => $t, 'd' => $pp['prazo'], 'n' => $dd( $pp['prazo'] ), 'u' => $u ); } }
			$ck = (array) ( $x['checklist'] ?? array() ); $chk = (array) ( $x['checks'] ?? array() ); $pend = 0;
			foreach ( $ck as $it ) { if ( ( $it['per'] ?? 'mensal' ) === 'mensal' && ! isset( $chk[ $it['id'] . '|' . substr( $hoje, 0, 7 ) ] ) ) { $pend++; } }
			if ( $pend ) { $fm = gmdate( 'Y-m-t', strtotime( $hoje ) ); $A['contratos'][] = array( 'o' => 'Checklist do mês: ' . $pend . ' pendente(s)', 't' => $t, 'd' => $fm, 'n' => $dd( $fm ), 'u' => $u ); }
			if ( $c['dias_garantia'] !== null && ( $tod || $c['dias_garantia'] <= 60 ) ) { $A['contratos'][] = array( 'o' => 'Vencimento da garantia', 't' => $t, 'd' => $x['garantia_vence'], 'n' => $c['dias_garantia'], 'id' => $x['id'], 'u' => '/plataforma/contratos/?ficha=' . $x['id'] ); }
		} }
		if ( function_exists( 'dv_rc_itens' ) ) { $T = dv_rc_tipos(); foreach ( dv_rc_itens( $uid ) as $x ) {
			if ( ( $x['status'] ?? 'aberto' ) !== 'aberto' ) { continue; } $c = dv_rc_calc( $x, $hoje );
			if ( ! $tod && ( ! empty( $c['prazo_ts'] ) ? $c['prazo_ts'] < time() * 1000 : ( $c['dias'] !== null && $c['dias'] < 0 ) ) ) { continue; } // prazo já encerrado sai da agenda
			$A['notificacoes'][] = array( 'o' => $T[ $x['tipo'] ][0] ?? 'Prazo', 't' => trim( ( $x['titulo'] ?: $x['orgao'] ) . ( ! empty( $x['numero_doc'] ) ? ' · ' . $x['numero_doc'] : '' ) ), 'd' => $c['prazo'], 'h' => $c['prazo_hora'] ?? '', 'ts' => $c['prazo_ts'] ?? null, 'n' => $c['dias'], 'u' => '/plataforma/recursos/?ver=' . $x['id'] );
		} }
		if ( function_exists( 'dv_docs_lista' ) ) { $emp = array(); foreach ( dv_docs_emps( $uid ) as $e ) { $emp[ $e['cnpj'] ] = $e['nome']; }
			foreach ( dv_docs_lista( $uid ) as $x ) { $n = $dd( $x['validade'] ); if ( $n === null || ( ! $tod && $n > 45 ) ) { continue; }
				$A['documentos'][] = array( 'o' => $n < 0 ? 'Vencido' : 'Vence', 't' => $x['titulo'] . ' · ' . ( $emp[ $x['cnpj'] ] ?? $x['cnpj'] ), 'd' => $x['validade'], 'n' => $n, 'u' => '/plataforma/documentos/' );
		} }
		foreach ( $A as &$l ) { usort( $l, function ( $a, $b ) { return ( $a['n'] ?? 9999 ) <=> ( $b['n'] ?? 9999 ); } ); } unset( $l );
		return $A;
	} ) );
	/* Planos e preços (público: usado pela home e pela tela de assinatura) */
	register_rest_route( 'desenvolve/v1', '/planos', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
		$uid = get_current_user_id(); $o = array();
		foreach ( dv_planos() as $k => $p ) { $pr = $uid ? dv_preco_plano( $uid, $k ) : array( 'valor' => dv_fundador_restantes() ? dv_preco_fundador( $p['preco'] ) : $p['preco'], 'fundador' => dv_fundador_restantes() > 0, 'cheio' => $p['preco'] ); $o[] = $p + $pr; }
		return array( 'planos' => $o, 'fundador_restantes' => dv_fundador_restantes(), 'fundador_meses' => (int) dv_cfg( 'fundador_meses' ), 'trial_dias' => (int) dv_cfg( 'trial_dias' ), 'trial_creditos' => (int) dv_cfg( 'trial_creditos' ), 'pacotes' => dv_pacotes(), 'pagamento' => (bool) get_option( 'dv_asaas_key' ) );
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
		foreach ( array( 'preco_assinatura', 'preco_extra', 'pacotes', 'email_alertas', 'asaas_ambiente', 'email_remetente', 'planos', 'custos' ) as $k ) { $c[ $k ] = sanitize_text_field( $in[ $k ] ?? $c[ $k ] ); }
		foreach ( array( 'creditos_mes', 'dedupe_dias', 'validade_extra', 'alerta_hora', 'creditos_dossie', 'fundador_desc', 'fundador_vagas', 'fundador_meses', 'trial_dias', 'trial_creditos' ) as $k ) { $c[ $k ] = max( 0, (int) ( $in[ $k ] ?? $c[ $k ] ) ); }
		update_option( 'dv_cfg', $c, false );
		if ( ! empty( $_POST['dv_asaas_key'] ) ) { update_option( 'dv_asaas_key', trim( wp_unslash( $_POST['dv_asaas_key'] ) ), false ); }
		if ( ! get_option( 'dv_webhook_token' ) ) { update_option( 'dv_webhook_token', wp_generate_password( 32, false ), false ); }
		echo '<div class="notice notice-success"><p>Configurações salvas.</p></div>';
	}
	$c = dv_cfg(); $tem = (bool) get_option( 'dv_asaas_key' );
	$f = function ( $k, $l, $tipo = 'text', $ajuda = '' ) use ( $c ) { echo '<tr><th><label>' . esc_html( $l ) . '</label></th><td><input type="' . $tipo . '" name="dv_cfg[' . $k . ']" value="' . esc_attr( $c[ $k ] ) . '" class="regular-text"' . ( $tipo === 'number' ? ' step="1"' : '' ) . '>' . ( $ajuda ? '<p class="description">' . esc_html( $ajuda ) . '</p>' : '' ) . '</td></tr>'; };
	echo '<div class="wrap dv-w"><h1>Configurações da plataforma</h1><form method="post">' . wp_nonce_field( 'dv_cfg', '_wpnonce', true, false ) . '<input type="hidden" name="dv_cfg[_]" value="1"><table class="form-table">';
	$f( 'planos', 'Planos', 'text', 'chave:Nome:preço:créditos por mês:termos no Radar, separados por |. Ex.: essencial:Essencial:97:20:1|profissional:Profissional:197:60:5' );
	$f( 'pacotes', 'Pacotes de créditos extras', 'text', 'quantidade:preço, separados por vírgula. Ex.: 10:39,30:99,100:290' );
	$f( 'creditos_dossie', 'Créditos da Due Diligence completa', 'number' );
	$f( 'custos', 'Outras ferramentas que usam mais de 1 crédito', 'text', 'ferramenta:créditos. Ex.: raiox:2,comprador:2. As ferramentas de gestão não usam créditos.' );
	$f( 'fundador_desc', 'Desconto de fundador (%)', 'number' );
	$f( 'fundador_vagas', 'Vagas de fundador', 'number', 'Já usadas: ' . (int) get_option( 'dv_fundadores', 0 ) );
	$f( 'fundador_meses', 'Meses com preço de fundador', 'number' );
	$f( 'trial_dias', 'Dias de teste grátis (0 desliga)', 'number' );
	$f( 'trial_creditos', 'Créditos no teste grátis', 'number' );
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
	if ( is_user_logged_in() ) { return dv_planos_html( get_current_user_id() ); }
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
				$td = (int) dv_cfg( 'trial_dias' ); $usados = (array) get_option( 'dv_trial_docs', array() ); $hd = md5( $doc );
				if ( $td > 0 && ! in_array( $hd, $usados, true ) ) { // teste grátis: uma vez por CPF/CNPJ
					update_user_meta( $uid, 'dv_trial', current_time( 'Y-m-d' ) ); update_user_meta( $uid, 'dv_status', 'ativo' );
					update_user_meta( $uid, 'dv_mes_saldo', (int) dv_cfg( 'trial_creditos' ) );
					update_user_meta( $uid, 'dv_vence', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . ( $td - 1 ) . ' days' ) ) );
					dv_extrato( $uid, (int) dv_cfg( 'trial_creditos' ), 'teste', 'Teste grátis de ' . $td . ' dias' );
					$usados[] = $hd; update_option( 'dv_trial_docs', array_slice( $usados, -20000 ), false );
				}
				wp_set_current_user( $uid ); wp_set_auth_cookie( $uid, true );
				wp_safe_redirect( home_url( '/painel/?bem-vindo=1' ) ); exit;
			}
		}
	}
	ob_start(); ?>
	<form class="dvp dv-cad" method="post">
		<?php wp_nonce_field( 'dv_cad', '_dvc' ); ?><input type="hidden" name="dv_cad" value="1">
		<h2>Crie sua conta</h2>
		<?php if ( (int) dv_cfg( 'trial_dias' ) ) { echo '<p class="dv-ok">Teste grátis por ' . (int) dv_cfg( 'trial_dias' ) . ' dias, com ' . (int) dv_cfg( 'trial_creditos' ) . ' créditos e todas as ferramentas de gestão. Sem cartão: você só escolhe um plano se quiser continuar.</p>'; } ?>
		<?php if ( $err ) { echo '<p class="dv-err">' . esc_html( $err ) . '</p>'; } ?>
		<label>Nome completo<input name="nome" required value="<?php echo esc_attr( $_POST['nome'] ?? '' ); ?>"></label>
		<label>E-mail<input type="email" name="email" required value="<?php echo esc_attr( $_POST['email'] ?? '' ); ?>"></label>
		<label>CPF ou CNPJ<input name="doc" inputmode="numeric" required value="<?php echo esc_attr( $_POST['doc'] ?? '' ); ?>"></label>
		<label>WhatsApp<input name="tel" inputmode="tel" value="<?php echo esc_attr( $_POST['tel'] ?? '' ); ?>"></label>
		<label>Crie uma senha<input type="password" name="senha" minlength="8" required autocomplete="new-password"></label>
		<label class="dv-chk"><input type="checkbox" name="termos" value="1" required> Li e aceito os <a href="/termos-de-uso-plataforma/" target="_blank">Termos de uso</a> e a <a href="/politica-de-privacidade/" target="_blank">Política de privacidade</a>.</label>
		<button type="submit"><?php echo (int) dv_cfg( 'trial_dias' ) ? 'Criar conta e começar o teste' : 'Criar conta e continuar'; ?></button>
		<p>Já tem conta? <a href="<?php echo esc_url( home_url( '/painel/' ) ); ?>">Entrar</a></p>
	</form>
	<?php return ob_get_clean();
} );

/** Tela de planos (assinante conectado): escolha do plano e pagamento pelo Asaas */
function dv_planos_html( $uid ) {
	$atual = dv_plano( $uid ); $st = dv_status( $uid ); $pago = (bool) get_user_meta( $uid, 'dv_plano', true ) && $st === 'ativo'; $tem = (bool) get_option( 'dv_asaas_key' );
	$rest = dv_fundador_restantes(); $fa = (string) get_user_meta( $uid, 'dv_fundador_ate', true );
	$h = '<div class="dvp" id="dv-planos" data-api="' . esc_attr( rest_url( 'desenvolve/v1/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"><h2>' . ( $pago ? 'Seu plano' : 'Escolha seu plano' ) . '</h2>';
	if ( get_user_meta( $uid, 'dv_trial', true ) && ! get_user_meta( $uid, 'dv_plano', true ) ) {
		$h .= $st === 'ativo' ? '<p class="dv-ok">Seu teste grátis vai até ' . esc_html( date_i18n( 'd/m/Y', strtotime( get_user_meta( $uid, 'dv_vence', true ) ) ) ) . '. Assine quando quiser: os créditos do plano entram na hora.</p>' : '<p class="dv-err">Seu teste grátis terminou. Escolha um plano para continuar usando as ferramentas.</p>';
	}
	if ( $fa ) { $h .= '<p class="dv-ok">Você é assinante fundador: preço com ' . (int) dv_cfg( 'fundador_desc' ) . '% de desconto até ' . esc_html( date_i18n( 'd/m/Y', strtotime( $fa ) ) ) . '.</p>'; }
	elseif ( $rest > 0 ) { $h .= '<p class="dv-ok"><b>Preço de fundador:</b> ' . (int) dv_cfg( 'fundador_desc' ) . '% de desconto por ' . (int) dv_cfg( 'fundador_meses' ) . ' meses para os primeiros assinantes. Restam <b>' . $rest . '</b> vagas.</p>'; }
	$h .= '<div class="dv-g">';
	foreach ( dv_planos() as $k => $p ) {
		$pr = dv_preco_plano( $uid, $k ); $eh = $pago && $atual === $k;
		$h .= '<div class="dv-c"' . ( $k === 'profissional' ? ' style="border-color:var(--gd)"' : '' ) . '><span>' . ( $k === 'profissional' ? 'Mais escolhido' : 'Plano' ) . '</span><b style="font-size:1.4rem">' . esc_html( $p['nome'] ) . '</b>';
		$h .= '<p style="margin:10px 0 4px">' . ( $pr['fundador'] ? '<s style="color:var(--mu)">R$ ' . number_format( $pr['cheio'], 0, ',', '.' ) . '</s> ' : '' ) . '<b style="display:inline;font-size:1.9rem">R$ ' . number_format( $pr['valor'], 0, ',', '.' ) . '</b>/mês</p>';
		$h .= '<ul style="padding-left:18px;color:var(--mu);font-size:.9rem;line-height:1.6;margin:10px 0 16px"><li><b style="display:inline;font-size:inherit;color:var(--fg)">' . $p['creditos'] . ' créditos por mês</b> para consultas</li><li>Ferramentas de gestão sem limite</li><li>' . $p['radar'] . ' CNPJ' . ( $p['radar'] > 1 ? 's ou nomes' : ' ou nome' ) . ' no Radar do Diário Oficial</li>' . ( $k === 'empresarial' ? '<li>Prioridade no consultor</li>' : '' ) . '</ul>';
		if ( $eh ) { $h .= '<p style="color:var(--gd);font-weight:700">Seu plano atual</p>'; }
		elseif ( $tem ) { $h .= '<button type="button" data-plano="' . esc_attr( $k ) . '">' . ( $pago ? 'Mudar para ' : 'Assinar ' ) . esc_html( $p['nome'] ) . '</button>'; }
		else { $h .= '<a class="dv-btn" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/5511993500950?text=' . rawurlencode( 'Olá! Quero assinar o plano ' . $p['nome'] . ' da plataforma Desenvolve.' ) ) . '">Assinar ' . esc_html( $p['nome'] ) . '</a>'; }
		$h .= '</div>';
	}
	$h .= '</div><p class="dv-msg"></p><p style="color:var(--mu);font-size:.85rem">Pix, boleto ou cartão. Cancele quando quiser: o acesso continua até o fim do período pago. Créditos extras: ';
	$pk = array(); foreach ( dv_pacotes() as $q => $v ) { $pk[] = $q . ' por R$ ' . number_format( $v, 0, ',', '.' ); } $h .= esc_html( implode( ' · ', $pk ) ) . '.</p></div>';
	$h .= '<script>(function(){var R=document.getElementById("dv-planos");if(!R)return;R.addEventListener("click",function(e){var b=e.target.closest("[data-plano]");if(!b)return;var m=R.querySelector(".dv-msg");b.disabled=true;m.className="dv-msg";m.textContent="Gerando a cobrança…";fetch(R.dataset.api+"checkout",{method:"POST",credentials:"same-origin",headers:{"X-WP-Nonce":R.dataset.nonce,"Content-Type":"application/json"},body:JSON.stringify({tipo:"assinatura",plano:b.dataset.plano})}).then(function(r){return r.json()}).then(function(j){if(j.url){location.href=j.url}else{b.disabled=false;m.className="dv-err";m.textContent=j.message||"Não foi possível gerar a cobrança."}}).catch(function(){b.disabled=false;m.className="dv-err";m.textContent="Não foi possível gerar a cobrança agora."})})})()</script>';
	return $h;
}

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
	if ( ! $uid && function_exists( 'dv_pre_lancamento' ) && dv_pre_lancamento() ) {
		$h .= '<div class="dv-pw"><h2>Lançamento em breve</h2><p>Esta ferramenta faz parte da Desenvolve, inteligência em licitações. Entre na lista de espera: você recebe os planos antes de todo mundo e ganha ' . (int) get_option( 'dv_bonus_lista', 100 ) . ' créditos de bônus na primeira mensalidade. Planos a partir de R$ ' . number_format( dv_preco_plano( 0, 'essencial' )['valor'], 0, ',', '.' ) . '/mês, sem fidelidade.</p><p><a class="dv-btn" href="/#lista">Entrar na lista de espera</a> <a class="dv-btn g" href="/painel/">Já tenho conta</a></p></div><style>.dv-tool{display:none!important}</style>';
	} elseif ( ! $uid ) {
		$td = (int) dv_cfg( 'trial_dias' );
		$h .= '<div class="dv-pw"><h2>Ferramenta exclusiva para assinantes</h2><p>' . ( $td ? 'Teste ' . $td . ' dias grátis, com ' . (int) dv_cfg( 'trial_creditos' ) . ' créditos, sem cartão. Depois, planos a partir de R$ ' . number_format( dv_preco_plano( 0, 'essencial' )['valor'], 0, ',', '.' ) . '/mês.' : 'Planos a partir de R$ ' . number_format( dv_preco_plano( 0, 'essencial' )['valor'], 0, ',', '.' ) . '/mês.' ) . '</p><p><a class="dv-btn" href="/assinar/">' . ( $td ? 'Começar o teste grátis' : 'Assinar agora' ) . '</a> <a class="dv-btn g" href="/painel/">Já tenho conta</a></p></div><style>.dv-tool{display:none!important}</style>';
	} elseif ( ! $acesso ) {
		$h .= '<div class="dv-pw"><h2>Escolha um plano para continuar</h2><p>' . ( get_user_meta( $uid, 'dv_trial', true ) ? 'O seu teste grátis terminou. ' : '' ) . 'Os planos começam em R$ ' . number_format( dv_preco_plano( $uid, 'essencial' )['valor'], 0, ',', '.' ) . '/mês, com créditos todo mês e as ferramentas de gestão sem limite.</p><p><a class="dv-btn" href="/assinar/">Ver os planos</a></p></div><style>.dv-tool{display:none!important}</style>';
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
.dv-th h1,.dv-th h2,.dv-tool h1,.dv-tool h2,.rp-hd h2,.dv-tool .nv b{font-family:"Hanken Grotesk",-apple-system,sans-serif!important;font-weight:800!important;letter-spacing:-.03em!important}
.dv-tool .aq{background:#FFFFFF!important;font-family:"Hanken Grotesk",-apple-system,sans-serif!important;border:1px solid rgba(203,165,92,.28)!important;border-radius:18px!important}
body .dv-tool .aq h2,body .dv-tool .aq h3,body .dv-tool .aq h4,body .dv-tool .aq .ts h3,body .rp h2,body .rp h3,body .rp h4{font-family:"Hanken Grotesk",-apple-system,sans-serif!important}
.dv-next{max-width:1180px;margin:18px auto 0;padding:16px 22px;border-radius:16px;border:1px solid rgba(203,165,92,.3);background:#151A20;color:#F4F2EC;display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-family:"Hanken Grotesk",sans-serif}
.dv-next b{color:#CBA55C;font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;margin-right:6px}.dv-next a{color:#F4F2EC;text-decoration:none;border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:8px 14px;font-size:.9rem;font-weight:600}.dv-next a:hover{border-color:#CBA55C;color:#CBA55C}
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
(function(){var N={meuscontratos:[['Contratos vencendo','contratos-vencendo'],['Gestão de licitações','licitacoes'],['Documentos e certidões','documentos']],vencendo:[['Meus contratos e garantias','meus-contratos'],['Raio-X de concorrentes','raio-x'],['Alertas de oportunidades','alertas']],alertas:[['Gestão de licitações','licitacoes'],['Contratos vencendo','contratos-vencendo']],buscador:[['Gestão de licitações','licitacoes'],['Contratos vencendo','contratos-vencendo']],captador:[['Raio-X de concorrentes','raio-x'],['Contratos vencendo','contratos-vencendo']],licitacoes:[['Raio-X do comprador','raio-x-comprador'],['Banco de preços','banco-de-precos'],['Impugnações e recursos','recursos']],comprador:[['Contratos vencendo','contratos-vencendo'],['Banco de preços','banco-de-precos'],['Gestão de licitações','licitacoes']],precos:[['Gestão de licitações','licitacoes'],['Calculadora de prazos','prazos']],prazos:[['Impugnações e recursos','recursos'],['Banco de teses','banco-de-teses']],raiox:[['Comparador de licitantes','comparador'],['Due Diligence completa','dossie'],['Impugnações e recursos','recursos']],comparador:[['Raio-X de concorrentes','raio-x'],['Impugnações e recursos','recursos']],dossie:[['Raio-X de concorrentes','raio-x'],['Impugnações e recursos','recursos']],documentos:[['Meus contratos e garantias','meus-contratos'],['Gestão de licitações','licitacoes']],recursos:[['Banco de teses','banco-de-teses'],['Calculadora de prazos','prazos']],teses:[['Impugnações e recursos','recursos'],['Calculadora de prazos','prazos']]};
var l=N[D.tool];if(!l)return;var nx=document.createElement('div');nx.className='dv-next';nx.innerHTML='<b>Próximo passo</b>'+l.map(function(a){return'<a href="/plataforma/'+a[1]+'/">'+a[0]+' →</a>'}).join('')+'<a href="/plataforma/">Todas as ferramentas</a>';cons.parentNode.insertBefore(nx,cons.nextSibling);
if(['raiox','comparador','dossie'].indexOf(D.tool)>=0)nx.addEventListener('click',function(ev){var a=ev.target.closest('a');if(!a||a.getAttribute('href')!=='/plataforma/recursos/')return;var c='';tool.querySelectorAll('input').forEach(function(i){var d=String(i.value||'').replace(/\D/g,'');if(!c&&d.length===14)c=d});if(c){ev.preventDefault();location.href='/plataforma/recursos/?tipo=recurso&conc='+c}});
if(D.tool==='comprador'){nx.addEventListener('click',function(ev){var a=ev.target.closest('a');if(!a||a.getAttribute('href')!=='/plataforma/contratos-vencendo/')return;var c='';tool.querySelectorAll('input').forEach(function(i){var d=String(i.value||'').replace(/\D/g,'');if(!c&&d.length===14)c=d});if(c){ev.preventDefault();location.href='/plataforma/contratos-vencendo/?orgao='+c}})}
if(D.tool==='raiox'){var ul=document.createElement('a');ul.href='/plataforma/recursos/';ul.textContent='Usar no recurso →';nx.insertBefore(ul,nx.children[1])}})();
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

/* Título da home no navegador e no Google */
add_filter( 'pre_get_document_title', function ( $t ) { return is_front_page() ? 'Desenvolve Estratégias · Inteligência em licitações' : $t; }, 99 );

/* Prévia de link (WhatsApp, LinkedIn etc.) com a marca nova */
add_action( 'wp_head', function () {
	if ( is_admin() ) { return; }
	$img = 'https://desenvolveestrategias.com.br/wp-content/uploads/2026/10/desenvolve-og.png';
	$t = is_front_page() ? 'Desenvolve Estratégias · Inteligência em licitações' : wp_get_document_title();
	$d = 'Inteligência em licitações: Raio-X de concorrentes e órgãos, classificação dos lances, due diligence, banco de preços, alertas do Diário Oficial e banco de teses, com dados oficiais.';
	echo "\n" . '<meta property="og:type" content="website"><meta property="og:site_name" content="Desenvolve Estratégias"><meta property="og:title" content="' . esc_attr( $t ) . '"><meta property="og:description" content="' . esc_attr( $d ) . '"><meta name="description" content="' . esc_attr( $d ) . '"><meta property="og:url" content="' . esc_url( home_url( add_query_arg( array() ) ) ) . '"><meta property="og:image" content="' . $img . '"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta name="twitter:card" content="summary_large_image">' . "\n";
}, 1 );

/* Páginas do site antigo levam ao site novo (301). As páginas continuam salvas no WordPress. */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_page() ) { return; }
	$m = array( 'sobre' => '/', 'contato' => '/', 'blog' => '/', 'areas-de-atuacao' => '/', 'home' => '/', 'termos-de-uso' => '/termos-de-uso-plataforma/', 'politicas-de-privacidade' => '/politica-de-privacidade/' );
	$o = get_queried_object(); if ( ! $o || empty( $o->post_name ) || ! isset( $m[ $o->post_name ] ) || (int) $o->post_parent !== 0 ) { return; }
	if ( (int) get_option( 'page_on_front' ) === (int) $o->ID ) { return; }
	wp_safe_redirect( home_url( $m[ $o->post_name ] ), 301 ); exit;
}, 1 );

/* Foco em licitações (out/2026): ferramentas de contratos fora do ar. Código e dados guardados. */
add_action( 'template_redirect', function () {
	$p = trim( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	if ( in_array( $p, array( 'plataforma/contratos', 'plataforma/reajuste', 'plataforma/repactuacao' ), true ) ) { wp_safe_redirect( home_url( '/plataforma/' ), 301 ); exit; }
}, 0 );

/* Enquanto o Asaas não tem chave, "Assinar" e "Comprar créditos" levam ao WhatsApp da Desenvolve */
function dv_wa_venda() { return 'https://wa.me/5511993500950?text=' . rawurlencode( 'Olá! Quero assinar a plataforma Desenvolve.' ); }
add_action( 'template_redirect', function () {
	if ( get_option( 'dv_asaas_key' ) || current_user_can( 'manage_options' ) ) { return; }
	if ( is_page( 'creditos' ) ) { wp_redirect( dv_wa_venda() ); exit; }
} );
add_action( 'wp_footer', function () {
	if ( get_option( 'dv_asaas_key' ) ) { return; }
	echo '<script>(function(){var u=' . wp_json_encode( dv_wa_venda() ) . ';function f(){document.querySelectorAll(\'a[href*="/creditos/"]\').forEach(function(a){a.href=u;a.target="_blank";a.rel="noopener"})}f();new MutationObserver(f).observe(document.body,{childList:true,subtree:true})})()</script>';
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
.dv-agg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}.dv-agc{background:var(--sf);border:1px solid var(--ln);border-radius:16px;padding:16px 18px}.dv-agh{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.dv-agh b{color:var(--fg)}.dv-agh a{font-size:.85rem}
.dv-agi{text-decoration:none;display:grid;grid-template-columns:96px 1fr;gap:12px;padding:9px 0;border-top:1px solid var(--ln);font-size:.88rem;color:var(--mu)}.dv-agi b{color:var(--fg);font-weight:600}a.dv-agi:hover b{color:var(--gd)}.dv-agd{font-weight:700;font-size:.85rem}.dv-agd small{font-weight:600;font-size:.75rem}.dv-agm{color:var(--mu);font-size:.85rem;margin:6px 0 0}
@media(max-width:700px){.dv-agg{grid-template-columns:1fr}}
.dv-agt{display:flex;gap:6px}.dv-agt button,.dv-calh button,.dv-calf button{background:var(--sf);border:1px solid var(--ln);color:var(--mu);border-radius:99px;padding:6px 12px;font:inherit;font-size:.85rem;cursor:pointer}.dv-agt button.on,.dv-calf button.on{border-color:var(--gd);color:var(--fg)}
.dv-cal{background:var(--sf);border:1px solid var(--ln);border-radius:16px;padding:16px 18px;margin:14px 0}.dv-calh{display:flex;align-items:center;gap:10px;margin-bottom:10px}.dv-calh b{color:var(--fg);min-width:150px;text-align:center}.dv-calh .hj{margin-left:auto}
.dv-calf{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}.dv-calf i,.dv-dd i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px}
.dv-calg{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}.dv-calg .dw{text-align:center;color:var(--mu);font-size:.75rem;padding:4px 0}
.dv-dd{background:transparent;border:1px solid var(--ln);border-radius:10px;min-height:62px;padding:6px;text-align:left;color:var(--mu);font:inherit;cursor:pointer;display:flex;flex-direction:column;gap:4px}.dv-dd em{font-style:normal;font-size:.85rem}.dv-dd.tem{color:var(--fg)}.dv-dd.hoje{border-color:var(--gd)}.dv-dd.hoje em{color:var(--gd);font-weight:700}.dv-dd.sel{background:rgba(201,162,90,.12);border-color:var(--gd)}.dv-dd small{font-size:.7rem;color:var(--mu)}.dv-dd i{margin-right:2px}
#dv-ag button{background:transparent!important;color:var(--mu)!important;border:1px solid var(--ln)!important;border-radius:99px!important;padding:6px 12px!important;font:inherit!important;font-size:.85rem!important;text-transform:none!important;letter-spacing:0!important;box-shadow:none!important;min-height:0!important;line-height:1.3!important;width:auto!important}
#dv-ag button.on{border-color:var(--gd)!important;color:var(--fg)!important}
#dv-ag button.dv-dd{border-radius:10px!important;padding:6px!important;min-height:62px!important;width:100%!important;display:flex!important;flex-direction:column!important;align-items:flex-start!important;justify-content:flex-start!important;gap:4px!important;text-align:left!important}
#dv-ag button.dv-dd.tem{color:var(--fg)!important;background:rgba(255,255,255,.03)!important}#dv-ag button.dv-dd.hoje{border-color:var(--gd)!important}#dv-ag button.dv-dd.sel{background:rgba(201,162,90,.16)!important;border-color:var(--gd)!important}
#dv-ag button:hover{border-color:var(--gd)!important}
@media(max-width:700px){#dv-ag button.dv-dd{min-height:46px!important;padding:4px!important}}
.dv-cald{margin-top:14px}.dv-cald>b{color:var(--fg)}
@media(max-width:700px){.dv-dd{min-height:46px;padding:4px}.dv-dd small{display:none}}
.dv-tools a{background:var(--sf);border:1px solid var(--ln);border-radius:14px;padding:16px;color:var(--fg);text-decoration:none;display:flex;flex-direction:column;gap:6px}.dv-tools a b{font-weight:700;font-size:1.02rem}.dv-tools a small{color:var(--mu);font-size:.84rem;line-height:1.45;font-weight:400}.dv-tools a i{font-style:normal;font-size:.72rem;font-weight:700;margin-top:auto;align-self:flex-start;border-radius:99px;padding:2px 9px}.dv-tools a i.in{background:rgba(143,189,163,.14);color:#8FBDA3}.dv-tools a i.cr{background:rgba(201,164,92,.14);color:var(--gd)}
.dv-low{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid rgba(229,154,138,.5);background:rgba(229,154,138,.08);border-radius:14px;padding:14px 18px;margin:12px 0}.dv-low b{color:#F4F2EC;display:block}.dv-low span{color:var(--mu);font-size:.88rem}
.dv-tsub{color:var(--mu);font-size:.9rem;margin:-4px 0 6px}.dv-grp{margin:22px 0 4px}.dv-grh{display:flex;gap:12px;align-items:center}.dv-grh>span{width:28px;height:28px;border-radius:50%;border:1px solid var(--gd);color:var(--gd);display:grid;place-items:center;font-weight:700;font-size:.85rem;flex-shrink:0}.dv-grh b{color:var(--fg);display:block}.dv-grh small{color:var(--mu);font-size:.84rem}.dv-grp .dv-tools{margin:10px 0 0}
.dv-tools a:hover{border-color:var(--gd)}
.dv-h{width:100%;border-collapse:collapse;font-size:.92rem}.dv-h td{padding:10px 8px;border-bottom:1px solid var(--ln);color:var(--mu)}.dv-h td:first-child{color:var(--fg)}
@media(max-width:700px){.dv-g,.dv-tools{grid-template-columns:1fr}}
</style>
<script>
(function(){var el=document.getElementById('dv-painel');if(!el)return;var api=el.dataset.api,n=el.dataset.nonce;
function g(p){return fetch(api+p,{headers:{'X-WP-Nonce':n},credentials:'same-origin'}).then(function(r){return r.json()})}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
var nomes={meuscontratos:'Meus contratos e garantias',vencendo:'Contratos vencendo',raiox:'Raio-X de concorrentes',comprador:'Raio-X do comprador',comparador:'Comparador de licitantes',precos:'Banco de preços',buscador:'Buscador de licitações',prazos:'Calculadora de prazos',captador:'Radar do Diário Oficial',documentos:'Documentos e certidões',licitacoes:'Gestão de licitações',alertas:'Alertas de oportunidades',recursos:'Impugnações e recursos',teses:'Banco de teses',dossie:'Due Diligence completa'};
var links=[['meuscontratos','/plataforma/meus-contratos/'],['vencendo','/plataforma/contratos-vencendo/'],['dossie','/plataforma/dossie/'],['raiox','/plataforma/raio-x/'],['comprador','/plataforma/raio-x-comprador/'],['comparador','/plataforma/comparador/'],['precos','/plataforma/banco-de-precos/'],['buscador','/plataforma/buscador/'],['prazos','/plataforma/prazos/'],['captador','/plataforma/captador/'],['documentos','/plataforma/documentos/'],['licitacoes','/plataforma/licitacoes/'],['alertas','/plataforma/alertas/'],['recursos','/plataforma/recursos/'],['teses','/plataforma/banco-de-teses/']];
Promise.all([g('me'),g('historico')]).then(function(a){var m=a[0],h=a[1]||[];var s=m.saldo||{};
var sit={ativo:m.teste?'Teste grátis':'Plano '+((m.plano&&m.plano.nome)||''),pendente:'Assinatura pendente',atrasado:'Pagamento em atraso',cancelado:'Assinatura cancelada',bloqueado:'Acesso bloqueado'}[m.situacao]||m.situacao;
var H='<h2>Olá, '+e((m.nome||'').split(' ')[0])+'</h2>';
if(/bem-vindo/.test(location.search))H+=m.teste&&m.situacao==='ativo'?'<p class="dv-ok">Conta criada. Seu teste grátis já está ativo, com '+(s.total||0)+' créditos e todas as ferramentas de gestão. Aproveite!</p>':'<p class="dv-ok">Conta criada. Escolha um plano para liberar as ferramentas.</p>';
H+='<div class="dv-g"><div class="dv-c"><span>Créditos disponíveis</span><b>'+(m.master?'Ilimitado':(s.total||0))+'</b></div><div class="dv-c"><span>Situação</span><b style="font-size:1.25rem">'+(m.master?'Conta Master':e(sit))+'</b></div><div class="dv-c"><span>'+(m.vence?'Renova em':'Detalhe do saldo')+'</span><b style="font-size:1.25rem">'+(m.vence?e(m.vence.split('-').reverse().join('/')):('Mês '+(s.mes||0)+' · extra '+(s.extra||0)))+'</b></div></div>';
if(!m.master&&m.situacao==='ativo'&&(s.total||0)<=3)H+='<div class="dv-low"><div><b>'+((s.total||0)?'Seus créditos estão acabando: resta(m) '+s.total+'.':'Seus créditos acabaram.')+'</b><span>Os créditos do plano renovam na próxima mensalidade. Para não parar agora, compre um pacote extra.</span></div><a class="dv-btn" href="/creditos/">Comprar créditos</a></div>';
if(!m.master&&(m.situacao!=='ativo'||m.teste))H+='<p><a class="dv-btn" href="/assinar/">'+(m.teste&&m.situacao==='ativo'?'Ver planos e assinar':'Escolher um plano')+'</a></p>';
else if(!m.master)H+='<p><a class="dv-btn g" href="/creditos/">Comprar créditos extras</a> <a class="dv-btn g" href="/assinar/">Mudar de plano</a></p>';
H+='<div class="dv-ag" id="dv-ag"><p style="color:#8E938F">Carregando a agenda…</p></div>';
var LK={};links.forEach(function(l){LK[l[0]]=l[1]});
var GR=[['1','Encontrar oportunidades','Saiba primeiro o que vai ser licitado no seu segmento.',[['vencendo','Contratos do seu ramo que vencem nos próximos meses, com quem presta hoje. Prepare-se antes do edital.','Incluso'],['alertas','Licitações novas do seu ramo no seu e-mail, todo dia de manhã.','Incluso'],['buscador','Licitações abertas no PNCP, com filtro por estado, valor e palavra-chave.','1 crédito'],['captador','Seu CNPJ e seus concorrentes monitorados nos diários oficiais.','Incluso']]],
 ['2','Analisar antes de disputar','Decida se vale a pena entrar e com qual preço.',[['licitacoes','Cole o link do edital: prazos, exigências e análise completa pela sua IA.','Incluso'],['comprador','O órgão paga em dia? Tem processo, greve ou notícia ruim?','2 créditos'],['precos','Quanto o governo já pagou pelo mesmo item, com fonte.','1 crédito'],['prazos','Impugnação, recurso e contrarrazões em dias úteis, com feriados.','Incluso']]],
 ['3','Conhecer a concorrência','Conheça quem costuma disputar no seu segmento, com dados públicos e oficiais.',[['raiox','Sanções, dívida ativa, processos e contratos de qualquer CNPJ.','2 créditos'],['comparador','Sócios e vínculos em comum entre licitantes da mesma disputa.','1 crédito'],['dossie','O relatório completo da empresa num PDF só.','10 créditos']]],
 ['4','Habilitação, contratos e garantias em dia','Nenhuma certidão, contrato ou garantia vencendo sem aviso.',[['meuscontratos','Seus contratos e garantias: vencimentos, prorrogações e a licitação que substitui cada um.','Incluso'],['documentos','Validade das certidões da sua empresa, com aviso antes de vencer.','Incluso']]],
 ['5','Contestar e defender','Impugne, recorra e se defenda com fundamento.',[['recursos','Prazo contado e roteiro da peça pronto para a sua IA.','Incluso'],['teses','Jurisprudência e súmulas sobre licitações, por tema, com citação pronta.','Incluso']]]];
H+='<h3 style="color:#F4F2EC;margin-top:28px">Ferramentas</h3><p class="dv-tsub">Organizadas pela ordem de uma licitação: do aviso do edital ao recurso.</p>'+GR.map(function(g){var it=g[3].filter(function(t){return LK[t[0]]&&nomes[t[0]]});if(!it.length)return'';return'<div class="dv-grp"><div class="dv-grh"><span>'+g[0]+'</span><div><b>'+g[1]+'</b><small>'+g[2]+'</small></div></div><div class="dv-tools">'+it.map(function(t){return'<a href="'+LK[t[0]]+'"><b>'+nomes[t[0]]+'</b><small>'+t[1]+'</small><i class="'+(t[2]==='Incluso'?'in':'cr')+'">'+t[2]+'</i></a>'}).join('')+'</div></div>'}).join('');
H+='<h3 style="color:#F4F2EC;margin-top:28px">Últimos usos</h3><table class="dv-h"><tbody>'+(h.length?h.map(function(x){return'<tr><td>'+e(nomes[x.ferramenta]||x.ferramenta)+(x.rotulo?' · '+e(x.rotulo):'')+'</td><td>'+(x.cobrado==1?'1 crédito':'sem custo')+'</td><td>'+e(x.criado.slice(0,16).replace(/(\d+)-(\d+)-(\d+)/,'$3/$2/$1'))+'</td></tr>'}).join(''):'<tr><td>Nenhum uso ainda.</td></tr>')+'</tbody></table>';
H+='<p style="margin-top:28px"><a href="'+el.dataset.sair+'">Sair</a></p>';el.innerHTML=H;
 if(m.master||m.situacao==='ativo')g('agenda').then(function(A){var B=[['licitacoes','Agenda de licitações','/plataforma/licitacoes/','Cadastre uma licitação ou crie um alerta de oportunidades.'],['notificacoes','Agenda de impugnações e recursos','/plataforma/recursos/','Nenhum prazo em aberto.'],['documentos','Agenda de documentos','/plataforma/documentos/','Nenhuma certidão vencendo nos próximos 45 dias.']];
  function q(x){if(x.ts){var d=x.ts-Date.now();if(d<0)return'encerrado';var mi=Math.floor(d/60000),dd=Math.floor(mi/1440),h=Math.floor(mi%1440/60);return dd?'em '+dd+'d '+h+'h':'em '+h+'h '+(mi%60)+'min'}return x.n===null||x.n===undefined?'':x.n<0?'há '+(-x.n)+' dia(s)':x.n===0?'HOJE':x.n===1?'amanhã':'em '+x.n+' dias'}
  function cor(n){return n===null||n===undefined?'#8E938F':n<=1?'#E59A8A':n<=7?'#E6C27A':'#8FBDA3'}
  var CAT={licitacoes:['Licitações','#E6C27A'],notificacoes:['Impugnações e recursos','#E59A8A'],documentos:['Documentos','#9DB4E0']};
  var box=document.getElementById('dv-ag'),AT=null,mes=null,dsel=null,filt=null,MS=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  function br(d){return d?e(d.slice(0,10).split('-').reverse().join('/')):''}
  function z(v){return(v<10?'0':'')+v}
  function hdr(v){return'<div style="display:flex;justify-content:space-between;align-items:center;margin-top:28px;flex-wrap:wrap;gap:8px"><h3 style="color:#F4F2EC;margin:0">Minha agenda</h3><div class="dv-agt"><button type="button" data-v="l" class="'+(v==='l'?'on':'')+'">☰ Lista</button><button type="button" data-v="c" class="'+(v==='c'?'on':'')+'">📅 Calendário</button></div></div>'}
  function guarda(v){try{localStorage.setItem('dv_agv',v)}catch(x){}}
  function lista(){guarda('l');box.innerHTML=hdr('l')+'<div class="dv-agg">'+B.map(function(b){var l=A[b[0]]||[];return'<div class="dv-agc"><div class="dv-agh"><b>'+b[1]+'</b><span><a href="#" data-cal="'+b[0]+'">📅 Calendário</a> · <a href="'+b[2]+'">Ver tudo</a></span></div>'+(l.length?l.slice(0,6).map(function(x){return'<a class="dv-agi" href="'+e(x.u||b[2])+'"><span class="dv-agd" style="color:'+cor(x.n)+'">'+br(x.d)+(x.h&&x.h!=='23:59'?' '+e(x.h):'')+'<br><small>'+q(x)+'</small></span><span><b>'+e(x.o)+'</b><br>'+e(x.t)+'</span></a>'}).join('')+(l.length>6?'<p class="dv-agm">+ '+(l.length-6)+' outro(s)</p>':''):'<p class="dv-agm">'+b[3]+'</p>')+'</div>'}).join('')+'</div>'}
  function cal(){guarda('c');if(!AT){box.innerHTML=hdr('c')+'<div class="dv-cal"><p class="dv-agm">Carregando o calendário…</p></div>';g('agenda?todos=1').then(function(r){AT=r;cal()});return}
   var H0=new Date(),th=H0.getFullYear()+'-'+z(H0.getMonth()+1)+'-'+z(H0.getDate());if(!mes)mes=new Date(H0.getFullYear(),H0.getMonth(),1);
   var y=mes.getFullYear(),m=mes.getMonth(),pm=y+'-'+z(m+1),ini=new Date(y,m,1).getDay(),nd=new Date(y,m+1,0).getDate(),ev={};
   Object.keys(CAT).forEach(function(k){if(filt&&filt!==k)return;(AT[k]||[]).forEach(function(x){if(!x.d)return;var d=x.d.slice(0,10);(ev[d]=ev[d]||[]).push([k,x])})});
   var h=hdr('c')+'<div class="dv-cal"><div class="dv-calh"><button type="button" data-m="-1" aria-label="Mês anterior">‹</button><b>'+MS[m].charAt(0).toUpperCase()+MS[m].slice(1)+' de '+y+'</b><button type="button" data-m="1" aria-label="Próximo mês">›</button><button type="button" data-m="0" class="hj">Hoje</button></div>';
   h+='<div class="dv-calf"><button type="button" data-f="" class="'+(!filt?'on':'')+'">Tudo</button>'+Object.keys(CAT).map(function(k){return'<button type="button" data-f="'+k+'" class="'+(filt===k?'on':'')+'"><i style="background:'+CAT[k][1]+'"></i>'+CAT[k][0]+'</button>'}).join('')+'</div>';
   h+='<div class="dv-calg">'+['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].map(function(s){return'<span class="dw">'+s+'</span>'}).join('');
   for(var i=0;i<ini;i++)h+='<span></span>';
   for(var d=1;d<=nd;d++){var k=pm+'-'+z(d),l=ev[k]||[],cs={};l.forEach(function(p){cs[p[0]]=1});
    h+='<button type="button" class="dv-dd'+(k===th?' hoje':'')+(k===dsel?' sel':'')+(l.length?' tem':'')+'" data-d="'+k+'"><em>'+d+'</em><span>'+Object.keys(cs).map(function(c){return'<i style="background:'+CAT[c][1]+'"></i>'}).join('')+'</span>'+(l.length?'<small>'+l.length+' item'+(l.length>1?'s':'')+'</small>':'')+'</button>'}
   h+='</div>';
   var sel=dsel&&dsel.slice(0,7)===pm?dsel:null,it=sel?(ev[sel]||[]):Object.keys(ev).filter(function(k){return k.slice(0,7)===pm}).sort().reduce(function(a,k){return a.concat(ev[k])},[]);
   h+='<div class="dv-cald"><b>'+(sel?'Dia '+br(sel):'Tudo em '+MS[m])+'</b>'+(it.length?it.map(function(p){var x=p[1];return'<a class="dv-agi" href="'+e(x.u||'#')+'"><span class="dv-agd" style="color:'+CAT[p[0]][1]+'">'+br(x.d)+(x.h&&x.h!=='23:59'?' '+e(x.h):'')+'<br><small>'+q(x)+'</small></span><span><b>'+e(x.o)+'</b><br>'+e(x.t)+'</span></a>'}).join(''):'<p class="dv-agm">'+(sel?'Nada marcado neste dia.':'Nada marcado neste mês.')+'</p>')+'</div></div>';
   box.innerHTML=h}
  box.addEventListener('click',function(v){var t=v.target.closest('button,[data-cal]');if(!t||!box.contains(t))return;var D=t.dataset;
   if(D.v){D.v==='c'?cal():lista()}else if(D.cal!==undefined){v.preventDefault();filt=D.cal;mes=null;dsel=null;cal()}
   else if(D.m!==undefined){var sm=+D.m;if(!sm){mes=null;dsel=null}else{mes=new Date(mes.getFullYear(),mes.getMonth()+sm,1);dsel=null}cal()}
   else if(D.f!==undefined){filt=D.f||null;cal()}else if(D.d){dsel=dsel===D.d?null:D.d;cal()}});
  var vw='l';try{vw=localStorage.getItem('dv_agv')||'l'}catch(x){}vw==='c'?cal():lista();});
});
})();
</script>
	<?php
}, 50 );

/* ===== Pré-lançamento: lista de espera (bônus de créditos para quem entrar) ===== */
function dv_pre_lancamento() { return get_option( 'dv_pre_lancamento', '1' ) === '1'; }
function dv_lista() { $l = get_option( 'dv_lista', array() ); return is_array( $l ) ? $l : array(); }
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/lista', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $r ) {
		if ( trim( (string) $r['site'] ) !== '' ) { return array( 'ok' => true ); } // armadilha para robôs
		$ip = $_SERVER['REMOTE_ADDR'] ?? ''; $k = 'dv_lista_ip_' . md5( $ip ); $n = (int) get_transient( $k );
		if ( $n >= 5 ) { return new WP_Error( 'dv_lista', 'Muitas tentativas. Tente de novo mais tarde.', array( 'status' => 429 ) ); }
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		$nome = sanitize_text_field( (string) $r['nome'] ); $email = sanitize_email( (string) $r['email'] );
		$whats = preg_replace( '/[^\d()+\- ]/', '', (string) $r['whats'] ); $ramo = sanitize_text_field( (string) $r['ramo'] ); $emp = sanitize_text_field( (string) $r['empresa'] );
		if ( mb_strlen( $nome ) < 2 || ! is_email( $email ) ) { return new WP_Error( 'dv_lista', 'Informe seu nome e um e-mail válido.', array( 'status' => 400 ) ); }
		$l = dv_lista(); $em = strtolower( $email );
		if ( isset( $l[ $em ] ) ) { return array( 'ok' => true, 'ja' => true ); }
		$l[ $em ] = array( 'nome' => mb_substr( $nome, 0, 120 ), 'email' => $em, 'whats' => mb_substr( $whats, 0, 30 ), 'empresa' => mb_substr( $emp, 0, 120 ), 'ramo' => mb_substr( $ramo, 0, 120 ), 'data' => current_time( 'mysql' ), 'bonus' => 0 );
		update_option( 'dv_lista', $l, false );
		$from = dv_cfg( 'email_remetente' ) ?: 'contato@desenvolveestrategias.com.br'; $h = array( 'From: Desenvolve <' . $from . '>' );
		$b = (int) get_option( 'dv_bonus_lista', 100 );
		wp_mail( $em, 'Você está na lista da Desenvolve', 'Olá, ' . $nome . "!\n\nSeu nome está na lista de espera da Desenvolve Estratégias, inteligência em licitações.\n\nVocê vai receber os planos antes de todo mundo, no dia do lançamento, e ganha " . $b . " créditos de bônus para usar como quiser: Raio-X de concorrentes, Due Diligence e as demais ferramentas. Para receber o bônus, assine com este mesmo e-mail: os créditos entram junto com a primeira mensalidade.\n\nAté breve!\nEquipe Desenvolve", $h );
		wp_mail( get_option( 'admin_email' ), 'Lista de espera: ' . $nome, "Nome: $nome\nE-mail: $em\nWhatsApp: $whats\nEmpresa: $emp\nRamo: $ramo\n\nTotal na lista: " . count( $l ), $h );
		return array( 'ok' => true, 'total' => count( $l ) );
	} ) );
} );
/* Bônus da lista de espera: entra junto com a PRIMEIRA mensalidade paga (chamado pelo webhook do Asaas) */
function dv_bonus_lista( $uid ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$l = dv_lista(); $em = strtolower( $u->user_email );
	if ( empty( $l[ $em ] ) || ! empty( $l[ $em ]['bonus'] ) ) { return; }
	dv_dar_creditos( $uid, (int) get_option( 'dv_bonus_lista', 100 ), 'Bônus da lista de espera (primeira mensalidade)', true );
	$l[ $em ]['bonus'] = $uid; update_option( 'dv_lista', $l, false );
}
/* Marca na lista quem já criou a conta (o bônus só sai no pagamento) */
add_action( 'user_register', function ( $uid ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$l = dv_lista(); $em = strtolower( $u->user_email ); if ( empty( $l[ $em ] ) ) { return; }
	$l[ $em ]['conta'] = $uid; update_option( 'dv_lista', $l, false );
}, 30 );
/* Enquanto for pré-lançamento, a assinatura leva à lista (administrador continua acessando) */
add_action( 'template_redirect', function () {
	if ( ! dv_pre_lancamento() || current_user_can( 'manage_options' ) || ! is_page( 'assinar' ) ) { return; }
	wp_safe_redirect( home_url( '/#lista' ), 302 ); exit;
}, 5 );
/* Tela no admin: lista, exportação e chave de lançamento */
add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Lista de espera', 'Lista de espera', 'manage_options', 'dv-lista', function () {
		if ( isset( $_POST['dv_lanc'] ) && check_admin_referer( 'dv_lanc' ) ) { update_option( 'dv_pre_lancamento', $_POST['dv_lanc'] === '1' ? '1' : '0' ); update_option( 'dv_bonus_lista', max( 0, (int) $_POST['dv_bonus'] ) ); echo '<div class="updated"><p>Salvo.</p></div>'; }
		$l = array_reverse( dv_lista() ); $pre = dv_pre_lancamento();
		echo '<div class="wrap"><h1>Lista de espera (' . count( $l ) . ')</h1>';
		echo '<form method="post" style="background:#fff;padding:12px 16px;border:1px solid #ddd;margin:12px 0">'; wp_nonce_field( 'dv_lanc' );
		echo '<p><label><input type="radio" name="dv_lanc" value="1"' . checked( $pre, true, false ) . '> <b>Pré-lançamento</b> (assinatura leva à lista)</label> &nbsp; <label><input type="radio" name="dv_lanc" value="0"' . checked( $pre, false, false ) . '> <b>Lançado</b> (assinatura aberta)</label></p>';
		echo '<p>Bônus para quem está na lista: <input type="number" name="dv_bonus" value="' . (int) get_option( 'dv_bonus_lista', 100 ) . '" style="width:70px"> créditos</p><p><button class="button button-primary">Salvar</button> &nbsp; <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dv_lista_csv' ), 'dv_lista_csv' ) ) . '">Baixar planilha</a></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Nome</th><th>E-mail</th><th>WhatsApp</th><th>Empresa</th><th>Ramo</th><th>Situação</th></tr></thead><tbody>';
		foreach ( $l as $x ) { echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $x['data'] ) ) . '</td><td>' . esc_html( $x['nome'] ) . '</td><td>' . esc_html( $x['email'] ) . '</td><td>' . esc_html( $x['whats'] ) . '</td><td>' . esc_html( $x['empresa'] ?? '' ) . '</td><td>' . esc_html( $x['ramo'] ) . '</td><td>' . ( $x['bonus'] ? 'pagou (bônus dado)' : ( ! empty( $x['conta'] ) ? 'conta criada' : '—' ) ) . '</td></tr>'; }
		if ( ! $l ) { echo '<tr><td colspan="7">Ninguém ainda.</td></tr>'; }
		echo '</tbody></table></div>';
	} );
}, 20 );
add_action( 'admin_post_dv_lista_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dv_lista_csv' ) ) { wp_die( 'Sem permissão.' ); }
	nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=lista-de-espera.csv' );
	echo "\xEF\xBB\xBF" . "Data;Nome;E-mail;WhatsApp;Empresa;Ramo;Conta criada\n";
	foreach ( dv_lista() as $x ) { echo implode( ';', array_map( function ( $v ) { return '"' . str_replace( '"', '""', (string) $v ) . '"'; }, array( $x['data'], $x['nome'], $x['email'], $x['whats'], $x['empresa'] ?? '', $x['ramo'], $x['bonus'] ? 'sim' : '' ) ) ) . "\n"; }
	exit;
} );

/* Slogan junto do logo em todas as páginas */
add_action( 'wp_head', function () {
	if ( is_admin() ) { return; }
	echo '<style>.logo .lt{display:inline-flex;flex-direction:column;align-items:flex-start}.logo .lt .tag{font-size:.68rem!important;letter-spacing:.2em!important;text-transform:uppercase;color:var(--muted,#8A857A);font-weight:500;margin-top:5px;white-space:nowrap}@media(max-width:600px){.logo .lt .tag{font-size:.5rem!important;letter-spacing:.14em!important;margin-top:3px}}</style>';
}, 99 );
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; }
	echo '<script>document.querySelectorAll(".logo .wm").forEach(function(w){if(w.parentNode.classList.contains("lt"))return;var s=document.createElement("span");s.className="lt";w.parentNode.insertBefore(s,w);s.appendChild(w);var t=document.createElement("span");t.className="tag";t.textContent="Inteligência em licitações";s.appendChild(t)})</script>';
}, 5 );

/* Pré-lançamento: botões "Assine/Assinar" do topo das páginas levam à lista de espera */
add_action( 'wp_footer', function () {
	if ( is_admin() || ! function_exists( 'dv_pre_lancamento' ) || ! dv_pre_lancamento() ) { return; }
	echo '<script>document.querySelectorAll("a").forEach(function(a){var t=a.textContent.trim();if(/^(Assine|Assinar|Assine já|Assinar agora)$/i.test(t)||/\/assinar\/?(\?|$)/.test(a.getAttribute("href")||"")){if(/^(Assine|Assinar)/i.test(t))a.textContent="Lista de espera";a.setAttribute("href","/#lista")}})</script>';
}, 80 );
