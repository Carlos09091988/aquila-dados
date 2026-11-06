/**
 * Desenvolve — Exportação do banco para a mudança de servidor (uso temporário; desativar depois da virada).
 * Gera um SQL completo (gzip) das tabelas do WordPress, protegido por uma chave derivada da chave secreta do site.
 * A chave só é mostrada a um administrador logado. Não guarda nada em disco.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function dv_exp_chave() { return substr( hash_hmac( 'sha256', 'exportar-banco|' . gmdate( 'Y-m-d' ), wp_salt( 'auth' ) ), 0, 32 ); }
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/exportar-chave', array( 'methods' => 'GET', 'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback' => function () { return array( 'chave' => dv_exp_chave() ); } ) );
} );
add_action( 'init', function () {
	if ( empty( $_GET['dv_exportar'] ) || ! hash_equals( dv_exp_chave(), (string) $_GET['dv_exportar'] ) ) { return; }
	global $wpdb; @set_time_limit( 280 ); @ini_set( 'memory_limit', '512M' ); nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'exportacao' );
	while ( ob_get_level() ) { ob_end_clean(); }
	header( 'Content-Type: application/gzip' ); header( 'Content-Disposition: attachment; filename="banco.sql.gz"' );
	$gz = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
	$w = function ( $s ) use ( $gz ) { echo deflate_add( $gz, $s, ZLIB_NO_FLUSH ); };
	$w( "SET NAMES utf8mb4;\nSET foreign_key_checks=0;\n" );
	$tabelas = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	foreach ( $tabelas as $t ) {
		$cr = $wpdb->get_row( "SHOW CREATE TABLE `$t`", ARRAY_N );
		$w( "DROP TABLE IF EXISTS `$t`;\n" . $cr[1] . ";\n" );
		$off = 0;
		while ( true ) {
			$linhas = $wpdb->get_results( "SELECT * FROM `$t` LIMIT $off, 500", ARRAY_N ); if ( ! $linhas ) { break; }
			$vals = array();
			foreach ( $linhas as $l ) {
				$vals[] = '(' . implode( ',', array_map( function ( $v ) use ( $wpdb ) { return $v === null ? 'NULL' : "'" . $wpdb->_real_escape( $v ) . "'"; }, $l ) ) . ')';
			}
			$w( "INSERT INTO `$t` VALUES " . implode( ",\n", $vals ) . ";\n" );
			$off += 500; flush();
		}
	}
	$w( "SET foreign_key_checks=1;\n" );
	echo deflate_add( $gz, '', ZLIB_FINISH ); exit;
}, 0 );
