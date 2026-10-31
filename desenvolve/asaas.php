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
/** Gera a cobrança e devolve o link de pagamento do Asaas (Pix, boleto ou cartão). */
function dv_checkout( $uid, $tipo, $qtd = 0, $plano = '' ) {
	$cli = dv_asaas_cliente( $uid ); if ( is_wp_error( $cli ) ) { return $cli; }
	$hoje = current_time( 'Y-m-d' );
	if ( $tipo === 'assinatura' ) {
		$ps = dv_planos(); $plano = isset( $ps[ $plano ] ) ? $plano : ( isset( $ps[ dv_plano( $uid ) ] ) ? dv_plano( $uid ) : array_key_first( $ps ) );
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
		return dv_checkout( get_current_user_id(), sanitize_key( $r['tipo'] ), (int) $r['qtd'], sanitize_key( (string) $r['plano'] ) );
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
					update_user_meta( $uid, 'dv_fundador_ate', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) dv_cfg( 'fundador_meses' ) . ' months' ) ) );
				}
				$fa = (string) get_user_meta( $uid, 'dv_fundador_ate', true ); $sub = get_user_meta( $uid, 'dv_asaas_assinatura', true );
				if ( $fa && $fa < current_time( 'Y-m-d' ) && $sub ) { // acabou o período de fundador: próximas cobranças pelo preço cheio
					$cheio = dv_plano_info( dv_plano( $uid ) )['preco'];
					if ( (float) get_user_meta( $uid, 'dv_preco_contratado', true ) < $cheio ) { $x = dv_asaas( 'POST', '/subscriptions/' . rawurlencode( $sub ), array( 'value' => $cheio, 'updatePendingPayments' => true ) ); if ( ! is_wp_error( $x ) ) { update_user_meta( $uid, 'dv_preco_contratado', $cheio ); } }
				}
				delete_user_meta( $uid, 'dv_trial' );
				$due = $pg['dueDate'] ?? current_time( 'Y-m-d' );
				$dias = max( 1, (int) round( ( strtotime( $due . ' +1 month' ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) + 3 );
				dv_renovar( $uid, 'Mensalidade paga (Asaas ' . $pid . ')', $dias );
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
	$uid = get_current_user_id(); $s = dv_saldo( $uid ); $ativo = dv_status( $uid ) === 'ativo' || dv_is_master( $uid );
	$pac = dv_pacotes();
	$h = '<div class="dvp" id="dv-cred" data-api="' . esc_attr( rest_url( 'desenvolve/v1/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"><h2>Créditos extras</h2>';
	$h .= '<p>Saldo atual: <b>' . ( dv_is_master( $uid ) ? 'ilimitado' : (int) $s['total'] ) . '</b>. Os créditos extras valem por ' . (int) dv_cfg( 'validade_extra' ) . ' meses e são usados depois dos créditos do mês.</p>';
	if ( ! $ativo ) { return $h . '<p class="dv-err">Créditos extras são exclusivos para assinantes ativos.</p><p><a class="dv-btn" href="/painel/">Ativar assinatura</a></p></div>'; }
	$h .= '<div class="dv-g">';
	foreach ( $pac as $q => $v ) {
		$h .= '<div class="dv-c"><span>Pacote</span><b>' . $q . ' créditos</b><p>R$ ' . number_format( $v, 2, ',', '.' ) . ' <small style="color:#8E938F">(R$ ' . number_format( $v / $q, 2, ',', '.' ) . ' por crédito)</small></p><button type="button" data-q="' . $q . '">Comprar</button></div>';
	}
	return $h . '</div><p class="dv-msg"></p></div>';
} );

add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; } ?>
<script>
(function(){
function pagar(api,n,dados,btn,msg){var t=btn.textContent;btn.disabled=true;btn.textContent='Abrindo pagamento…';
fetch(api+'checkout',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':n,'Content-Type':'application/json'},body:JSON.stringify(dados)}).then(function(r){return r.json()}).then(function(j){if(j&&j.url){location.href=j.url}else{btn.disabled=false;btn.textContent=t;if(msg){msg.className='dv-err';msg.textContent=(j&&j.message)||'Não foi possível abrir o pagamento.'}else alert((j&&j.message)||'Não foi possível abrir o pagamento.')}})}
var c=document.getElementById('dv-cred');
if(c){var m=c.querySelector('.dv-msg');c.addEventListener('click',function(e){var b=e.target.closest('button[data-q]');if(b)pagar(c.dataset.api,c.dataset.nonce,{tipo:'creditos',qtd:+b.dataset.q},b,m)})}
var p=document.getElementById('dv-painel');
if(p){document.addEventListener('click',function(e){var a=e.target.closest('a[href="/assinar/"]');if(!a||!p.contains(a))return;e.preventDefault();pagar(p.dataset.api,p.dataset.nonce,{tipo:'assinatura'},a,null)})}
})();
</script>
<?php }, 80 );
