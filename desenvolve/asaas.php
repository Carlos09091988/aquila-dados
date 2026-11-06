/**
 * Desenvolve — Asaas (assinatura, créditos extras e webhook)
 * Depende do núcleo (snippet "Desenvolve — Plataforma (núcleo)").
 * A chave de API fica em Desenvolve > Configurações (opção dv_asaas_key); nunca no código.
 */

function dv_asaas_base() {
	return dv_cfg( 'asaas_ambiente' ) === 'producao' ? 'https://api.asaas.com/v3' : 'https://api-sandbox.asaas.com/v3';
}
function dv_asaas( $metodo, $rota, $dados = null ) {
	$k = get_option( 'dv_asaas_key' );
	if ( ! $k ) { return new WP_Error( 'dv_asaas_sem_chave', 'Pagamento ainda não configurado. Fale com a Desenvolve.', array( 'status' => 503 ) ); }
	$a = array( 'method' => $metodo, 'timeout' => 30, 'headers' => array( 'access_token' => $k, 'Content-Type' => 'application/json', 'User-Agent' => 'Desenvolve' ) );
	if ( $dados !== null ) { $a['body'] = wp_json_encode( $dados ); }
	$r = wp_remote_request( dv_asaas_base() . $rota, $a );
	if ( is_wp_error( $r ) ) { return new WP_Error( 'dv_asaas_rede', 'Não foi possível falar com o Asaas agora. Tente de novo.', array( 'status' => 502 ) ); }
	$c = wp_remote_retrieve_response_code( $r ); $j = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( $c >= 400 ) {
		$m = $j['errors'][0]['description'] ?? 'Erro no Asaas.';
		return new WP_Error( 'dv_asaas_erro', $m, array( 'status' => 502 ) );
	}
	return $j;
}
/** Cria (uma vez) o cliente do assinante no Asaas. */
/* CPF (11) ou CNPJ (14) com dígitos verificadores; o Asaas exige um deles para emitir a cobrança */
function dv_doc_valido( $d ) {
	if ( ! preg_match( '/^(\d{11}|\d{14})$/', $d ) || preg_match( '/^(\d)\1+$/', $d ) ) { return false; }
	if ( strlen( $d ) === 11 ) {
		for ( $t = 9; $t < 11; $t++ ) { $s = 0; for ( $i = 0; $i < $t; $i++ ) { $s += $d[ $i ] * ( $t + 1 - $i ); } if ( (int) $d[ $t ] !== ( ( 10 * $s ) % 11 ) % 10 ) { return false; } }
		return true;
	}
	foreach ( array( 12, 13 ) as $n ) { $s = 0; $p = $n - 7; for ( $i = 0; $i < $n; $i++ ) { $s += $d[ $i ] * $p--; if ( $p < 2 ) { $p = 9; } } $r = $s % 11 < 2 ? 0 : 11 - $s % 11; if ( (int) $d[ $n ] !== $r ) { return false; } }
	return true;
}
function dv_asaas_cliente( $uid ) {
	$id = get_user_meta( $uid, 'dv_asaas_cliente', true );
	if ( $id ) { return $id; }
	$u = get_userdata( $uid );
	$j = dv_asaas( 'POST', '/customers', array(
		'name' => $u->display_name, 'email' => $u->user_email,
		'cpfCnpj' => preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'dv_doc', true ) ),
		'mobilePhone' => preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'dv_tel', true ) ),
		'externalReference' => 'dv:' . $uid, 'notificationDisabled' => false,
	) );
	if ( is_wp_error( $j ) ) { return $j; }
	update_user_meta( $uid, 'dv_asaas_cliente', $j['id'] );
	return $j['id'];
}
/** Porte da empresa na Receita Federal (BrasilAPI). true = a empresa NÃO é MEI, ME nem EPP ("DEMAIS"). Sem CNPJ ou sem resposta, false (não bloqueia). */
function dv_porte_fora( $uid, $forcar = false ) {
	$doc = preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'dv_doc', true ) );
	if ( strlen( $doc ) !== 14 ) { return false; }
	$porte = $forcar ? false : get_transient( 'dv_porte_' . $doc );
	if ( $porte === false ) {
		$x = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $doc, array( 'timeout' => 20 ) ); $b = is_wp_error( $x ) ? array() : (array) json_decode( wp_remote_retrieve_body( $x ), true );
		$porte = ! empty( $b['porte'] ) ? strtoupper( (string) $b['porte'] ) . ( ! empty( $b['opcao_pelo_mei'] ) ? '|MEI' : '' ) : '';
		if ( $porte ) { set_transient( 'dv_porte_' . $doc, $porte, 30 * DAY_IN_SECONDS ); update_user_meta( $uid, 'dv_porte', $porte ); }
	}
	return $porte && strpos( $porte, 'DEMAIS' ) === 0;
}
/** Reconferência do porte a cada mensalidade do plano Busca: se a empresa deixou de ser MEI, ME ou EPP, o plano passa a Completo na cobrança seguinte e o assinante é avisado. */
function dv_porte_reconferir( $uid ) {
	if ( dv_plano( $uid ) !== 'busca' || ! dv_porte_fora( $uid, true ) ) { return; }
	$sub = get_user_meta( $uid, 'dv_asaas_assinatura', true ); $ps = dv_planos(); if ( ! $sub || empty( $ps['desenvolve'] ) ) { return; }
	$pr = dv_preco_plano( $uid, 'desenvolve' );
	$x = dv_asaas( 'POST', '/subscriptions/' . rawurlencode( $sub ), array( 'value' => (float) $pr['valor'], 'description' => 'Desenvolve — plano Completo (80 créditos por mês)', 'updatePendingPayments' => false ) );
	if ( is_wp_error( $x ) ) { return; }
	update_user_meta( $uid, 'dv_plano_pedido', 'desenvolve' ); update_user_meta( $uid, 'dv_preco_contratado', (float) $pr['valor'] ); update_user_meta( $uid, 'dv_porte_migrado', current_time( 'Y-m-d' ) );
	$u = get_user_by( 'id', $uid ); if ( ! $u ) { return; }
	$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	wp_mail( $u->user_email, 'Seu plano na Desenvolve vai mudar para o Completo', 'Olá, ' . $u->display_name . "!\n\nO plano Busca é exclusivo para MEI, microempresa e empresa de pequeno porte. Pelo cadastro atual na Receita Federal, a sua empresa passou a ter outro porte. Parabéns pelo crescimento!\n\nPor isso, a partir da próxima mensalidade, a sua assinatura passa para o plano Completo (R$ " . dv_rs( $pr['valor'] ) . " por mês), com todas as ferramentas e 80 créditos por mês. Este mês continua como está.\n\nSe o cadastro na Receita estiver desatualizado, responda este e-mail que a gente confere.\n\nEquipe Desenvolve", array( 'From: Desenvolve <' . $from . '>' ) );
	wp_mail( get_option( 'admin_email' ), 'Porte mudou: ' . $u->display_name . ' passa ao Completo', "Assinante do plano Busca cujo CNPJ deixou de ser MEI/ME/EPP na Receita Federal. A assinatura no Asaas foi ajustada para o valor do Completo a partir da próxima cobrança.\n\nUsuário: " . $u->user_email, array( 'From: Desenvolve <' . $from . '>' ) );
}
/** Gera a cobrança e devolve o link de pagamento do Asaas (Pix, boleto ou cartão). */
function dv_checkout( $uid, $tipo, $qtd = 0, $plano = '' ) {
	$cli = dv_asaas_cliente( $uid ); if ( is_wp_error( $cli ) ) { return $cli; }
	$hoje = current_time( 'Y-m-d' );
	if ( $tipo === 'assinatura' ) {
		$ps = dv_planos(); $plano = isset( $ps[ $plano ] ) ? $plano : ( isset( $ps[ dv_plano( $uid ) ] ) ? dv_plano( $uid ) : array_key_first( $ps ) );
		if ( $plano === 'busca' && dv_porte_fora( $uid ) ) { return new WP_Error( 'dv_porte', 'O plano Busca é só para MEI, microempresa e empresa de pequeno porte. Pelo cadastro na Receita Federal, a sua empresa tem outro porte: escolha o plano Completo.', array( 'status' => 400 ) ); }
		$p = $ps[ $plano ]; $pr = dv_preco_plano( $uid, $plano );
		$desc = 'Desenvolve — plano ' . $p['nome'] . ' (' . $p['creditos'] . ' créditos por mês)' . ( $pr['fundador'] ? ' — preço de fundador' : '' );
		$sub = get_user_meta( $uid, 'dv_asaas_assinatura', true );
		if ( ! $sub ) {
			$j = dv_asaas( 'POST', '/subscriptions', array(
				'customer' => $cli, 'billingType' => 'UNDEFINED', 'value' => (float) $pr['valor'],
				'nextDueDate' => $hoje, 'cycle' => 'MONTHLY', 'description' => $desc,
				'externalReference' => 'dv:' . $uid . ':assinatura',
			) );
			if ( is_wp_error( $j ) ) { return $j; }
			$sub = $j['id']; update_user_meta( $uid, 'dv_asaas_assinatura', $sub ); delete_user_meta( $uid, 'dv_cancelado' );
		} elseif ( (string) get_user_meta( $uid, 'dv_plano_pedido', true ) !== $plano || (float) get_user_meta( $uid, 'dv_preco_contratado', true ) !== (float) $pr['valor'] ) {
			$j = dv_asaas( 'POST', '/subscriptions/' . rawurlencode( $sub ), array( 'value' => (float) $pr['valor'], 'description' => $desc, 'updatePendingPayments' => true ) );
			if ( is_wp_error( $j ) ) { return $j; }
		}
		update_user_meta( $uid, 'dv_plano_pedido', $plano ); update_user_meta( $uid, 'dv_preco_contratado', (float) $pr['valor'] );
		update_user_meta( $uid, 'dv_fundador_pedido', $pr['fundador'] ? 1 : 0 );
		if ( get_user_meta( $uid, 'dv_plano', true ) && dv_status( $uid ) === 'ativo' && get_user_meta( $uid, 'dv_plano', true ) !== $plano ) {
			return array( 'url' => home_url( '/painel/?plano=alterado' ), 'alterado' => true ); // troca de plano: vale na próxima cobrança
		}
		$p = dv_asaas( 'GET', '/subscriptions/' . rawurlencode( $sub ) . '/payments?status=PENDING' );
		if ( is_wp_error( $p ) ) { return $p; }
		if ( empty( $p['data'] ) ) { $p = dv_asaas( 'GET', '/subscriptions/' . rawurlencode( $sub ) . '/payments?status=OVERDUE' ); }
		if ( is_wp_error( $p ) || empty( $p['data'] ) ) { return new WP_Error( 'dv_sem_cobranca', 'Não há cobrança em aberto. Se você acabou de pagar, aguarde a confirmação.', array( 'status' => 409 ) ); }
		return array( 'url' => $p['data'][0]['invoiceUrl'] );
	}
	if ( $tipo === 'creditos' ) {
		if ( dv_status( $uid ) !== 'ativo' && ! dv_is_master( $uid ) ) { return new WP_Error( 'dv_so_assinante', 'Créditos extras são exclusivos para assinantes ativos.', array( 'status' => 403 ) ); }
		$pac = dv_pacotes();
		$qtd = (int) $qtd; if ( ! isset( $pac[ $qtd ] ) ) { return new WP_Error( 'dv_pacote', 'Pacote inválido.', array( 'status' => 400 ) ); }
		$j = dv_asaas( 'POST', '/payments', array(
			'customer' => $cli, 'billingType' => 'UNDEFINED', 'value' => (float) $pac[ $qtd ],
			'dueDate' => gmdate( 'Y-m-d', strtotime( $hoje . ' +2 days' ) ), 'description' => 'Desenvolve — pacote de ' . $qtd . ' créditos',
			'externalReference' => 'dv:' . $uid . ':creditos:' . $qtd,
		) );
		if ( is_wp_error( $j ) ) { return $j; }
		return array( 'url' => $j['invoiceUrl'] );
	}
	return new WP_Error( 'dv_tipo', 'Tipo inválido.', array( 'status' => 400 ) );
}
/** Acha o assinante a partir do pagamento recebido no webhook. */
function dv_asaas_uid( $pg ) {
	if ( preg_match( '/^dv:(\d+)/', (string) ( $pg['externalReference'] ?? '' ), $m ) ) { return (int) $m[1]; }
	foreach ( array( 'subscription' => 'dv_asaas_assinatura', 'customer' => 'dv_asaas_cliente' ) as $campo => $meta ) {
		if ( ! empty( $pg[ $campo ] ) ) {
			$u = get_users( array( 'meta_key' => $meta, 'meta_value' => $pg[ $campo ], 'number' => 1, 'fields' => 'ID' ) );
			if ( $u ) { return (int) $u[0]; }
		}
	}
	return 0;
}

add_action( 'rest_api_init', function () {
	$logado = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/checkout', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $doc = preg_replace( '/\D/', '', (string) $r['doc'] );
		if ( $doc !== '' ) {
			if ( ! dv_doc_valido( $doc ) ) { return new WP_Error( 'dv_doc', 'CPF ou CNPJ inválido. Confira os números.', array( 'status' => 400 ) ); }
			update_user_meta( $uid, 'dv_doc', $doc );
			$cli = get_user_meta( $uid, 'dv_asaas_cliente', true );
			if ( $cli ) { $up = dv_asaas( 'POST', '/customers/' . rawurlencode( $cli ), array( 'cpfCnpj' => $doc ) ); if ( is_wp_error( $up ) ) { delete_user_meta( $uid, 'dv_asaas_cliente' ); } }
		}
		if ( strlen( (string) get_user_meta( $uid, 'dv_doc', true ) ) < 11 ) { return new WP_Error( 'dv_doc', 'Informe o CPF ou CNPJ de quem vai pagar. Ele é exigido para emitir a cobrança.', array( 'status' => 400 ) ); }
		return dv_checkout( $uid, sanitize_key( $r['tipo'] ), (int) $r['qtd'], sanitize_key( (string) $r['plano'] ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/cancelar', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function () {
		$uid = get_current_user_id(); $sub = get_user_meta( $uid, 'dv_asaas_assinatura', true );
		if ( ! $sub ) { return new WP_Error( 'dv_sem_assinatura', 'Não há assinatura para cancelar.', array( 'status' => 404 ) ); }
		$j = dv_asaas( 'DELETE', '/subscriptions/' . rawurlencode( $sub ) );
		if ( is_wp_error( $j ) ) { return $j; }
		delete_user_meta( $uid, 'dv_asaas_assinatura' );
		update_user_meta( $uid, 'dv_cancelado', current_time( 'mysql' ) );
		dv_extrato( $uid, 0, 'cancelamento', 'Assinatura cancelada pelo assinante' );
		return array( 'ok' => true, 'acesso_ate' => get_user_meta( $uid, 'dv_vence', true ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/asaas', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $r ) {
		$tk = (string) get_option( 'dv_webhook_token' );
		if ( ! $tk || ! hash_equals( $tk, (string) $r->get_header( 'asaas-access-token' ) ) ) { return new WP_Error( 'dv_token', 'Token inválido.', array( 'status' => 401 ) ); }
		$ev = (string) $r['event']; $pg = (array) $r['payment'];
		$pid = (string) ( $pg['id'] ?? '' ); $uid = dv_asaas_uid( $pg );
		if ( ! $uid ) { return array( 'ok' => true, 'ignorado' => 'assinante não encontrado' ); }
		$feitos = (array) get_user_meta( $uid, 'dv_asaas_feitos', true );
		$ref = (string) ( $pg['externalReference'] ?? '' );
		if ( in_array( $ev, array( 'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' ), true ) ) {
			if ( $pid && in_array( $pid, $feitos, true ) ) { return array( 'ok' => true, 'repetido' => true ); }
			if ( preg_match( '/:creditos:(\d+)$/', $ref, $m ) ) {
				dv_dar_creditos( $uid, (int) $m[1], 'Pacote comprado (Asaas ' . $pid . ')' );
			} else {
				// plano escolhido passa a valer; fundador ocupa a vaga no primeiro pagamento
				$pp = (string) get_user_meta( $uid, 'dv_plano_pedido', true ); if ( $pp && isset( dv_planos()[ $pp ] ) ) { update_user_meta( $uid, 'dv_plano', $pp ); }
				elseif ( ! get_user_meta( $uid, 'dv_plano', true ) ) { update_user_meta( $uid, 'dv_plano', array_key_first( dv_planos() ) ); }
				if ( get_user_meta( $uid, 'dv_fundador_pedido', true ) && ! get_user_meta( $uid, 'dv_fundador_ate', true ) ) {
					update_option( 'dv_fundadores', (int) get_option( 'dv_fundadores', 0 ) + 1, false );
					update_user_meta( $uid, 'dv_fundador_ate', ( (int) dv_cfg( 'fundador_meses' ) === 0 ? '2099-12-31' : gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) dv_cfg( 'fundador_meses' ) . ' months' ) ) ) );
				}
				$fa = (string) get_user_meta( $uid, 'dv_fundador_ate', true ); $sub = get_user_meta( $uid, 'dv_asaas_assinatura', true );
				if ( $fa && ( $fa < current_time( 'Y-m-d' ) || (int) dv_cfg( 'fundador_meses' ) === 1 ) && $sub ) { // acabou o período de fundador (ou o desconto é só do 1º mês): próximas cobranças pelo preço cheio
					$cheio = dv_plano_info( dv_plano( $uid ) )['preco'];
					if ( (float) get_user_meta( $uid, 'dv_preco_contratado', true ) < $cheio ) { $x = dv_asaas( 'POST', '/subscriptions/' . rawurlencode( $sub ), array( 'value' => $cheio, 'updatePendingPayments' => true ) ); if ( ! is_wp_error( $x ) ) { update_user_meta( $uid, 'dv_preco_contratado', $cheio ); } }
				}
				delete_user_meta( $uid, 'dv_trial' );
				$due = $pg['dueDate'] ?? current_time( 'Y-m-d' );
				$dias = max( 1, (int) round( ( strtotime( $due . ' +1 month' ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) + 3 );
				dv_renovar( $uid, 'Mensalidade paga (Asaas ' . $pid . ')', $dias ); if ( function_exists( 'dv_bonus_lista' ) ) { dv_bonus_lista( $uid ); }
				dv_porte_reconferir( $uid ); // plano Busca: reconfere o porte a cada mensalidade
				delete_user_meta( $uid, 'dv_cancelado' );
			}
			$feitos[] = $pid; update_user_meta( $uid, 'dv_asaas_feitos', array_slice( $feitos, -200 ) );
		} elseif ( in_array( $ev, array( 'PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED' ), true ) ) {
			if ( preg_match( '/:creditos:(\d+)$/', $ref, $m ) ) { dv_dar_creditos( $uid, -(int) $m[1], 'Estorno do pacote (Asaas ' . $pid . ')' ); }
			else { update_user_meta( $uid, 'dv_status', 'cancelado' ); update_user_meta( $uid, 'dv_mes_saldo', 0 ); dv_extrato( $uid, 0, 'estorno', 'Mensalidade estornada (Asaas ' . $pid . ')' ); }
		}
		return array( 'ok' => true );
	} ) );
} );

/* Tela de compra de créditos: [dv_creditos] */
add_shortcode( 'dv_creditos', function () {
	if ( ! is_user_logged_in() ) { return '<div class="dvp"><h2>Entre na sua conta</h2><p><a class="dv-btn" href="/painel/">Entrar</a></p></div>'; }
	$uid = get_current_user_id(); $s = dv_saldo( $uid ); $master = dv_is_master( $uid ); $ativo = dv_status( $uid ) === 'ativo' || $master;
	$pac = dv_pacotes(); $val = (int) dv_cfg( 'validade_extra' ); $rx = max( 1, dv_custo( 'raiox' ) );
	$css = '<style>
.dvk{--k:#16191E;--m:#6E6A60;--l:#E6E1D6;--g:#C9A45C;--g2:#A8843F;color:var(--k);font-family:"Hanken Grotesk",-apple-system,sans-serif}
.dvk .hd{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:22px}.dvk h1,.dvk h2{margin:0;font-size:1.9rem;letter-spacing:-.03em}.dvk .hd p{margin:6px 0 0;color:var(--m);max-width:560px}
.dvk .sd{display:flex;gap:10px;flex-wrap:wrap}.dvk .sd div{background:#fff;border:1px solid var(--l);border-radius:14px;padding:12px 16px;min-width:120px}.dvk .sd small{display:block;color:var(--m);font-size:.78rem}.dvk .sd b{font-size:1.5rem;letter-spacing:-.02em}.dvk .sd .t{background:var(--k);border-color:var(--k);color:#F4F2EC}.dvk .sd .t small{color:#B9B4A8}.dvk .sd .t b{color:#E8D29A}
.dvk .pk{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px}
.dvk .c{position:relative;background:#fff;border:1px solid var(--l);border-radius:18px;padding:22px 20px 20px;display:flex;flex-direction:column;box-shadow:0 1px 2px rgba(22,25,30,.04);transition:transform .15s,box-shadow .15s}.dvk .c:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(22,25,30,.08)}
.dvk .c.dest{border:2px solid var(--g);padding:21px 19px 19px}.dvk .c .tg{position:absolute;top:-11px;left:18px;background:var(--k);color:#E8D29A;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:4px 10px}
.dvk .c .q{font-size:2.6rem;font-weight:800;letter-spacing:-.04em;line-height:1}.dvk .c .q small{font-size:1rem;font-weight:600;letter-spacing:0;color:var(--m);margin-left:4px}
.dvk .c .pr{margin-top:10px;font-size:1.25rem;font-weight:700}.dvk .c .pr span{display:block;font-size:.8rem;font-weight:400;color:var(--m)}
.dvk .c ul{list-style:none;padding:0;margin:14px 0 18px;color:var(--m);font-size:.88rem;line-height:1.5;flex:1}.dvk .c li{padding-left:20px;position:relative;margin:4px 0}.dvk .c li:before{content:"✓";position:absolute;left:0;color:var(--g2);font-weight:800}
.dvk .c button{width:100%;background:var(--k)!important;color:#F4F2EC!important;border-radius:12px!important;padding:13px 0!important}.dvk .c.dest button{background:var(--g)!important;color:#241A06!important}
.dvk .us{margin-top:26px;display:grid;grid-template-columns:1.3fr 1fr;gap:16px}@media(max-width:780px){.dvk .us{grid-template-columns:1fr}}
.dvk .bx{background:#fff;border:1px solid var(--l);border-radius:18px;padding:20px 22px}.dvk .bx h3{margin:0 0 10px;font-size:1.05rem}
.dvk table{width:100%;border-collapse:collapse;font-size:.92rem}.dvk td{padding:8px 0;border-bottom:1px solid #EFEBE2}.dvk tr:last-child td{border:0}.dvk td:last-child{text-align:right;font-weight:700;white-space:nowrap}.dvk td small{display:block;color:var(--m);font-weight:400}
.dvk .bx p{margin:0 0 10px;color:var(--m);font-size:.9rem;line-height:1.55}.dvk .bx p b{color:var(--k)}.dvk .pg{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}.dvk .pg span{background:#F4F2EC;border-radius:8px;padding:5px 10px;font-size:.82rem;font-weight:700}
</style>';
	$h = $css . '<div class="dvp dvk" id="dv-cred" data-api="' . esc_attr( rest_url( 'desenvolve/v1/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
	$h .= '<div class="hd"><div><h1>Comprar créditos</h1><p>Para não parar no meio de uma análise quando os créditos do mês acabarem. Os créditos entram assim que o pagamento é confirmado.</p></div>';
	$h .= '<div class="sd">' . ( $master ? '<div class="t"><small>Seu saldo</small><b>Ilimitado</b></div>' : '<div class="t"><small>Seu saldo</small><b>' . (int) $s['total'] . '</b></div><div><small>Do mês</small><b>' . (int) $s['mes'] . '</b></div><div><small>Comprados</small><b>' . (int) ( $s['extra'] + $s['cortesia'] ) . '</b></div>' ) . '</div></div>';
	if ( ! $ativo ) { return $h . '<div class="bx"><h3>Créditos extras são para assinantes</h3><p>Ative a assinatura para receber os créditos do mês e poder comprar mais quando precisar.</p><p><a class="dv-btn" href="/assinar/">Ver a assinatura</a></p></div></div>'; }
	$ks = array_keys( $pac ); $dest = count( $ks ) >= 3 ? $ks[1] : 0;
	$h .= '<div class="pk">';
	foreach ( $pac as $q => $v ) {
		$n = (int) floor( $q / $rx ); $ref = $pac[ $ks[0] ] / $ks[0]; $eco = $ref > 0 ? (int) round( ( 1 - ( $v / $q ) / $ref ) * 100 ) : 0;
		$h .= '<div class="c' . ( $q === $dest ? ' dest' : '' ) . '">' . ( $q === $dest ? '<span class="tg">Recomendado</span>' : '' ) . '<div class="q">' . $q . '<small>créditos</small></div>';
		$h .= '<div class="pr">R$ ' . number_format( $v, 2, ',', '.' ) . '<span>R$ ' . number_format( $v / $q, 2, ',', '.' ) . ' por crédito' . ( $eco > 0 ? ' · <b style="color:#1F5A3B">' . $eco . '% de desconto</b>' : '' ) . '</span></div>';
		$h .= '<ul>' . ( $n >= 1 ? '<li>Rende ' . $n . ' Raio-X do concorrente</li>' : '<li>Para consultas avulsas</li>' ) . '<li>Válidos por ' . $val . ' meses</li><li>Acumulam com os do mês</li></ul>';
		$h .= '<button type="button" data-q="' . $q . '">Comprar</button></div>';
	}
	$h .= '</div>';
	$usos = array( array( 'Raio-X do concorrente', 'habilitação e pontos fracos incluídos', dv_custo( 'raiox' ) ), array( 'Raio-X do comprador', 'pagamentos, concorrência e últimos contratos', dv_custo( 'comprador' ) ), array( 'Raio-X em lote', 'até 20 CNPJs de uma vez', dv_custo( 'lote' ) ), array( 'Due Diligence completa', 'relatório da empresa e do grupo', dv_custo( 'dossie' ) ), array( 'Contratos vencendo', 'lista para prospectar', dv_custo( 'vencendo' ) ) );
	$h .= '<div class="us"><div class="bx"><h3>Quanto custa cada consulta</h3><table>';
	foreach ( $usos as $u ) { if ( $u[2] > 0 ) { $h .= '<tr><td>' . esc_html( $u[0] ) . '<small>' . esc_html( $u[1] ) . '</small></td><td>' . (int) $u[2] . ' créditos</td></tr>'; } }
	$h .= '<tr><td>Gestão de licitações, documentos, contratos e recursos<small>ferramentas de gestão</small></td><td>sem custo</td></tr></table></div>';
	$h .= '<div class="bx"><h3>Como funciona</h3><p><b>Pagamento seguro pelo Asaas.</b> Você escolhe a forma na página de pagamento.</p><div class="pg"><span>Pix</span><span>Boleto</span><span>Cartão</span></div><p style="margin-top:14px"><b>Valem ' . $val . ' meses</b> a partir da compra e são usados depois dos créditos do mês.</p><p><b>Se cancelar a assinatura,</b> eles ficam guardados e voltam a valer se você reativar dentro desse prazo.</p><p>O custo de cada consulta aparece antes de você confirmar.</p></div></div>';
	return $h . '<p class="dv-msg"></p></div>';
} );

add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; } ?>
<script>
(function(){
function pedeDoc(onde,txt,cb){var w=onde.querySelector('.dv-doc');if(!w){w=document.createElement('div');w.className='dv-doc';w.style.cssText='margin:14px 0;padding:14px 16px;border:1px solid #C9A45C;border-radius:12px;background:#FFFCF5;max-width:460px';w.innerHTML='<p style="margin:0 0 8px;font-weight:700"></p><input inputmode="numeric" placeholder="CPF ou CNPJ" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #CFCAC0;border-radius:8px;font:inherit;background:#fff!important;color:#16191E!important"><button type="button" style="margin-top:10px">Continuar para o pagamento</button>';onde.appendChild(w)}w.querySelector('p').textContent=txt;var i=w.querySelector('input'),b=w.querySelector('button');i.focus();b.onclick=function(){var v=i.value.replace(/\D/g,'');if(v.length!==11&&v.length!==14){w.querySelector('p').textContent='Digite os 11 números do CPF ou os 14 do CNPJ.';return}cb(v,b)}}
function dvBRL(v){return Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}
function confirma(dados,btn,ok){var P=<?php $pl = function_exists( 'dv_planos' ) ? dv_planos() : array(); $pp = array(); foreach ( (array) $pl as $k => $x ) { $pp[ $k ] = is_array( $x ) ? (float) ( $x['preco'] ?? $x[1] ?? 0 ) : 0; } $pk = array(); foreach ( explode( ',', (string) ( function_exists( 'dv_cfg' ) ? dv_cfg( 'pacotes' ) : '' ) ) as $x ) { $y = explode( ':', trim( $x ) ); if ( count( $y ) === 2 ) { $pk[ (int) $y[0] ] = (float) $y[1]; } } echo wp_json_encode( array( 'planos' => $pp, 'pacotes' => $pk ) ); ?>;
 var tit,txt;if(dados.tipo==='creditos'){var v=P.pacotes[dados.qtd];tit='Comprar '+dados.qtd+' créditos?';txt='Você vai comprar <b>'+dados.qtd+' créditos</b>'+(v?' por <b>'+dvBRL(v)+'</b>':'')+'. Ao confirmar, você será levado à página de pagamento segura do Asaas, onde escolhe Pix, boleto ou cartão. Os créditos entram assim que o pagamento for confirmado.'}
 else{var pv=0;for(var k in P.planos){pv=P.planos[k];break}tit='Assinar a Desenvolve?';txt='Você vai assinar o plano mensal'+(pv?' de <b>'+dvBRL(pv)+' por mês</b>':'')+'. Ao confirmar, você será levado à página de pagamento segura do Asaas, onde escolhe Pix, boleto ou cartão.'}
 var m=document.createElement('div');m.style.cssText='position:fixed;inset:0;background:rgba(12,15,19,.55);display:flex;align-items:center;justify-content:center;z-index:100003;padding:16px;font-family:"Hanken Grotesk",-apple-system,sans-serif';
 m.innerHTML='<div role="dialog" aria-modal="true" style="background:#FBF9F4;color:#16191E;border-radius:16px;max-width:440px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)"><h3 style="margin:0 0 8px;font-size:1.2rem;font-weight:800;color:#16191E">'+tit+'</h3><p style="margin:0 0 18px;font-size:.93rem;line-height:1.5;color:#4A4F55">'+txt+'</p><div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap"><button type="button" data-n style="background:#fff;color:#16191E;border:1px solid #D9D3C4;border-radius:10px;padding:11px 18px;font:700 .92rem inherit;cursor:pointer">Cancelar</button><button type="button" data-s style="background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:800 .92rem inherit;cursor:pointer">Sim, ir para o pagamento</button></div></div>';
 document.body.appendChild(m);var fx=function(){m.remove();document.removeEventListener('keydown',esc)},esc=function(e){if(e.key==='Escape')fx()};document.addEventListener('keydown',esc);
 m.addEventListener('click',function(e){if(e.target===m||e.target.closest('[data-n]')){fx();return}if(e.target.closest('[data-s]')){fx();ok()}});m.querySelector('[data-s]').focus()}
function pagar(api,n,dados,btn,msg,onde){if(!dados._ok){confirma(dados,btn,function(){dados._ok=1;pagar(api,n,dados,btn,msg,onde)});return}var t=btn.textContent;btn.disabled=true;btn.textContent='Abrindo pagamento…';
fetch(api+'checkout',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':n,'Content-Type':'application/json'},body:JSON.stringify(dados)}).then(function(r){return r.json()}).then(function(j){if(j&&j.url){location.href=j.url;return}btn.disabled=false;btn.textContent=t;if(j&&j.code==='dv_doc'){pedeDoc(onde||btn.parentNode,j.message,function(v,b2){dados.doc=v;pagar(api,n,dados,b2,msg,onde)});return}if(msg){msg.className='dv-err';msg.textContent=(j&&j.message)||'Não foi possível abrir o pagamento.'}else{pedeDoc(onde||btn.parentNode,(j&&j.message)||'Não foi possível abrir o pagamento.',function(){location.reload()})}}).catch(function(){btn.disabled=false;btn.textContent=t})}
window.dvPagar=pagar;
var c=document.getElementById('dv-cred');
if(c){var m=c.querySelector('.dv-msg');c.addEventListener('click',function(e){var b=e.target.closest('button[data-q]');if(b)pagar(c.dataset.api,c.dataset.nonce,{tipo:'creditos',qtd:+b.dataset.q},b,m,c)})}
var p=document.getElementById('dv-painel');
if(p){document.addEventListener('click',function(e){var a=e.target.closest('a[href="/assinar/"]');if(!a||!p.contains(a))return;e.preventDefault();pagar(p.dataset.api,p.dataset.nonce,{tipo:'assinatura'},a,null,a.parentNode)})}
})();
</script>
<?php }, 80 );
