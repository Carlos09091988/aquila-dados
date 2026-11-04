<?php
/**
 * Desenvolve — Tag do Google Ads e conversões
 * Conta 796-868-4221 · tag AW-1011331382
 * "Entrou na lista de espera": quando o formulário da lista é enviado com sucesso.
 * "Assinou o plano": uma vez por cliente, na primeira página que ele abre depois que a assinatura fica ativa.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'DV_ADS_ID', 'AW-1011331382' );
define( 'DV_ADS_LISTA', 'AW-1011331382/vyOuCP67gbIdELbinuID' );
define( 'DV_ADS_ASSINOU', 'AW-1011331382/h3_KCIG8gbIdELbinuID' );

add_action( 'wp_head', function () {
	if ( is_admin() || ( is_user_logged_in() && function_exists( 'dv_is_master' ) && dv_is_master( get_current_user_id() ) ) ) { return; } ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( DV_ADS_ID ); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?php echo esc_js( DV_ADS_ID ); ?>');
(function(){var f=window.fetch;if(!f)return;window.fetch=function(u,o){var p=f.apply(this,arguments);try{var s=typeof u==='string'?u:(u&&u.url)||'';if(/desenvolve\/v1\/lista(\?|$)/.test(s)&&o&&String(o.method||'').toUpperCase()==='POST'){p.then(function(r){if(r&&r.ok){gtag('event','conversion',{send_to:'<?php echo esc_js( DV_ADS_LISTA ); ?>',value:1.0,currency:'BRL'})}}).catch(function(){})}}catch(e){}return p}})();</script>
	<?php
}, 1 );

/* Assinatura: dispara uma única vez por cliente */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; }
	$uid = get_current_user_id();
	if ( function_exists( 'dv_is_master' ) && dv_is_master( $uid ) ) { return; }
	if ( get_user_meta( $uid, 'dv_ads_assinou', true ) ) { return; }
	if ( ! get_user_meta( $uid, 'dv_plano', true ) || ! function_exists( 'dv_status' ) || dv_status( $uid ) !== 'ativo' ) { return; }
	$v = 79.90; if ( function_exists( 'dv_preco_plano' ) && function_exists( 'dv_plano' ) ) { $p = dv_preco_plano( $uid, dv_plano( $uid ) ); if ( ! empty( $p['valor'] ) ) { $v = (float) $p['valor']; } }
	update_user_meta( $uid, 'dv_ads_assinou', current_time( 'mysql' ) ); ?>
<script>if(window.gtag)gtag('event','conversion',{send_to:'<?php echo esc_js( DV_ADS_ASSINOU ); ?>',value:<?php echo esc_js( number_format( $v, 2, '.', '' ) ); ?>,currency:'BRL',transaction_id:'assinatura-<?php echo (int) $uid; ?>'});</script>
	<?php
}, 99 );
