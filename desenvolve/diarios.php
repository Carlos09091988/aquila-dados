<?php
/**
 * Desenvolve — Diários municipais (cobertura nacional)
 * Conectores para as plataformas que concentram os diários oficiais dos municípios.
 * Etapa 1: sonda (só administrador) para testar as plataformas a partir do servidor.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/dm/sonda', array( 'methods' => 'POST', 'permission_callback' => function () { return current_user_can( 'manage_options' ); }, 'callback' => function ( WP_REST_Request $r ) {
		$urls = (array) $r->get_param( 'urls' ); $out = array();
		foreach ( array_slice( $urls, 0, 12 ) as $k => $u ) {
			$u = esc_url_raw( $u ); if ( ! $u ) { continue; }
			$t0 = microtime( true );
			$res = wp_remote_get( $u, array( 'timeout' => 25, 'redirection' => 3, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept' => 'text/html,application/json,*/*', 'Accept-Language' => 'pt-BR,pt;q=0.9' ) ) );
			if ( is_wp_error( $res ) ) { $out[ $k ] = array( 'u' => $u, 'erro' => $res->get_error_message() ); continue; }
			$b = wp_remote_retrieve_body( $res );
			$out[ $k ] = array( 'u' => $u, 'st' => wp_remote_retrieve_response_code( $res ), 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ), 'tipo' => wp_remote_retrieve_header( $res, 'content-type' ), 'tam' => strlen( $b ), 'ini' => mb_substr( preg_replace( '/\s+/', ' ', $b ), 0, (int) ( $r->get_param( 'n' ) ?: 1500 ) ) );
		}
		return $out;
	} ) );
} );
