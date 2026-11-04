<?php
/**
 * Desenvolve — Contador de visitas (próprio, sem cookies)
 * Conta páginas vistas e visitantes únicos por dia (IP+navegador embaralhados com uma chave do dia, sem guardar o IP).
 * Ignora administrador, robôs, chamadas internas e arquivos. Origem: anúncio do Google (gclid), Google orgânico, redes, direto.
 * Painel: Desenvolve › Visitas do site.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
	if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) { return; }
	echo '<script>(function(){try{if(navigator.webdriver)return;var u=' . wp_json_encode( rest_url( 'desenvolve/v1/visita' ) ) . ';var b=JSON.stringify({p:location.pathname,r:document.referrer||"",q:location.search||""});if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"application/json"}))}else{fetch(u,{method:"POST",body:b,headers:{"Content-Type":"application/json"},keepalive:true})}}catch(e){}})()</script>';
}, 99 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/visita', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( WP_REST_Request $r ) {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( $ua === '' || preg_match( '/bot|crawl|spider|slurp|preview|lighthouse|headless|python|curl|wget|facebookexternalhit|whatsapp|adsbot|mediapartners/i', $ua ) ) { return array( 'ok' => 1 ); }
		$j = json_decode( $r->get_body(), true ) ?: array();
		$p = substr( preg_replace( '#[^a-z0-9/_\-]#i', '', (string) ( $j['p'] ?? '/' ) ), 0, 80 ) ?: '/';
		$ref = (string) ( $j['r'] ?? '' ); $q = (string) ( $j['q'] ?? '' );
		$ip = function_exists( 'dv_sg_ip' ) ? dv_sg_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$dia = current_time( 'Ymd' ); $sal = get_option( 'dv_vis_sal' ); if ( ! $sal ) { $sal = wp_generate_password( 24, false ); update_option( 'dv_vis_sal', $sal, false ); }
		$hv = substr( md5( $sal . $dia . $ip . $ua ), 0, 12 );
		$fonte = strpos( $q, 'gclid=' ) !== false || strpos( $q, 'gbraid=' ) !== false || strpos( $q, 'wbraid=' ) !== false ? 'anuncio' : ( preg_match( '#google\.#', $ref ) ? 'google' : ( preg_match( '#(facebook|instagram|linkedin|lnkd|t\.co|twitter|whatsapp|wa\.me)#i', $ref ) ? 'redes' : ( $ref && ! preg_match( '#desenvolveestrategias\.com\.br#', $ref ) ? 'outros sites' : 'direto' ) ) );
		$k = 'dv_vis_' . $dia; $d = (array) get_option( $k, array() ) + array( 'pv' => 0, 'u' => array(), 'pg' => array(), 'f' => array() );
		$d['pv']++; $d['pg'][ $p ] = ( $d['pg'][ $p ] ?? 0 ) + 1;
		if ( ! isset( $d['u'][ $hv ] ) ) { $d['u'][ $hv ] = 1; $d['f'][ $fonte ] = ( $d['f'][ $fonte ] ?? 0 ) + 1; }
		update_option( $k, $d, false );
		return array( 'ok' => 1 );
	} ) );
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Visitas do site', 'Visitas do site', 'manage_options', 'dv-visitas', function () {
		echo '<div class="wrap"><h1>Visitas do site</h1><p>Contador próprio, sem cookies, desde ' . esc_html( get_option( 'dv_vis_inicio', '' ) ) . '. Não conta administradores nem robôs.</p><table class="widefat striped" style="max-width:900px"><thead><tr><th>Dia</th><th>Visitantes</th><th>Páginas vistas</th><th>Origem dos visitantes</th><th>Páginas mais vistas</th></tr></thead><tbody>';
		$tv = 0; $tp = 0;
		for ( $i = 0; $i < 30; $i++ ) {
			$dia = gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) . " -$i days" ) ); $d = get_option( 'dv_vis_' . $dia ); if ( ! $d ) { continue; }
			$v = count( (array) $d['u'] ); $tv += $v; $tp += (int) $d['pv']; arsort( $d['pg'] ); arsort( $d['f'] );
			$f = array(); foreach ( (array) $d['f'] as $a => $n ) { $f[] = $a . ': ' . $n; }
			$pg = array(); foreach ( array_slice( (array) $d['pg'], 0, 4, true ) as $a => $n ) { $pg[] = $a . ' (' . $n . ')'; }
			echo '<tr><td>' . esc_html( substr( $dia, 6, 2 ) . '/' . substr( $dia, 4, 2 ) ) . '</td><td><b>' . $v . '</b></td><td>' . (int) $d['pv'] . '</td><td>' . esc_html( implode( ' · ', $f ) ) . '</td><td>' . esc_html( implode( ' · ', $pg ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th>Total (30 dias)</th><th>' . $tv . '</th><th>' . $tp . '</th><th></th><th></th></tr></tfoot></table></div>';
	} );
}, 99 );
add_action( 'init', function () { if ( ! get_option( 'dv_vis_inicio' ) ) { update_option( 'dv_vis_inicio', current_time( 'd/m/Y H:i' ), false ); } } );
