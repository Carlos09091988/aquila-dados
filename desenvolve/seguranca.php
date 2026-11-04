<?php
/**
 * Desenvolve — Segurança do site
 * Cabeçalhos de proteção, bloqueio de descoberta de usuários, XML-RPC desligado,
 * limite de tentativas de login, mensagem de erro genérica e edição de arquivos pelo painel desligada.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Cabeçalhos de proteção em todas as páginas (site e painel) */
function dv_sg_headers() {
	if ( headers_sent() ) { return; }
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
	if ( is_ssl() ) { header( 'Strict-Transport-Security: max-age=31536000' ); }
	header_remove( 'X-Powered-By' );
}
add_action( 'send_headers', 'dv_sg_headers' );
add_action( 'admin_init', 'dv_sg_headers' );
add_action( 'login_init', 'dv_sg_headers' );

/* Sem versão do WordPress no código das páginas */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/* XML-RPC desligado (porta comum de ataques de senha) */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', function () { return array(); } );
add_filter( 'wp_headers', function ( $h ) { unset( $h['X-Pingback'] ); return $h; } );
add_action( 'init', function () { if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) { status_header( 403 ); exit; } }, 0 );

/* Ninguém de fora descobre os nomes de usuário (API de usuários, ?author=, páginas de autor, sitemap de usuários) */
add_filter( 'rest_endpoints', function ( $e ) {
	if ( current_user_can( 'list_users' ) ) { return $e; }
	foreach ( array_keys( $e ) as $k ) { if ( preg_match( '#^/wp/v2/users#', $k ) ) { unset( $e[ $k ] ); } }
	return $e;
} );
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() && current_user_can( 'list_users' ) ) { return; }
	if ( isset( $_GET['author'] ) || is_author() ) { wp_safe_redirect( home_url( '/' ), 301 ); exit; }
}, 1 );
add_filter( 'wp_sitemaps_add_provider', function ( $p, $n ) { return $n === 'users' ? false : $p; }, 10, 2 );
add_filter( 'oembed_response_data', function ( $d ) { unset( $d['author_name'], $d['author_url'] ); return $d; } );

/* Login: erro genérico (não diz se o usuário existe) */
add_filter( 'login_errors', function ( $m ) {
	if ( strpos( (string) $m, 'dv-sg' ) !== false ) { return $m; }
	return '<span class="dv-sg"></span>Usuário ou senha incorretos.';
} );

/* Login: 6 erros em 15 minutos bloqueiam aquele IP por 15 minutos */
function dv_sg_ip() { return preg_replace( '/[^0-9a-f:.]/i', '', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); }
add_filter( 'authenticate', function ( $u, $login ) {
	if ( $login === '' || $login === null ) { return $u; }
	$k = 'dv_sg_f_' . md5( dv_sg_ip() );
	if ( (int) get_transient( $k ) >= 6 ) { return new WP_Error( 'dv_sg_bloq', '<span class="dv-sg"></span>Muitas tentativas de entrar. Espere 15 minutos e tente de novo, ou use "Perdeu a senha?".' ); }
	return $u;
}, 30, 2 );
add_action( 'wp_login_failed', function () {
	$k = 'dv_sg_f_' . md5( dv_sg_ip() ); $n = (int) get_transient( $k ) + 1; set_transient( $k, $n, 15 * MINUTE_IN_SECONDS );
	if ( $n === 6 ) { $l = (array) get_option( 'dv_sg_log', array() ); array_unshift( $l, current_time( 'mysql' ) . ' · bloqueado · ' . dv_sg_ip() ); update_option( 'dv_sg_log', array_slice( $l, 0, 50 ), false ); }
} );
add_action( 'wp_login', function () { delete_transient( 'dv_sg_f_' . md5( dv_sg_ip() ) ); } );

/* Edição de plugins, temas e arquivos pelo painel desligada (se alguém roubar uma senha de admin, não consegue mexer no código por ali) */
add_filter( 'map_meta_cap', function ( $caps, $cap ) {
	return in_array( $cap, array( 'edit_plugins', 'edit_themes', 'edit_files' ), true ) ? array( 'do_not_allow' ) : $caps;
}, 10, 2 );

