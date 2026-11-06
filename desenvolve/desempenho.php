/**
 * Desenvolve — Desempenho (carregamento enxuto e arquivos estáticos em cache)
 * 1) Para o visitante, tira do carregamento o que o site não usa: CSS do Elementor, chatbot da Hostinger,
 *    bloco de assinatura da Hostinger Reach, Contact Form 7, jQuery Migrate e o script de emoji do WordPress.
 * 2) Move para arquivos estáticos (wp-content/uploads/dv-static) os blocos grandes de CSS e de JavaScript
 *    que hoje vão inline em cada página: o HTML fica pequeno e o navegador guarda os arquivos em cache.
 *    Só move o que é estático: <style> sem url() relativa, scripts embutidos em base64 nas páginas e
 *    scripts marcados com /*DVSTATIC*​/ nos snippets. Scripts com dados do usuário continuam inline.
 * 3) Imagens abaixo da dobra ganham loading="lazy".
 * Desligar este snippet devolve o site exatamente ao estado anterior.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* 1) Carregamento enxuto */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) { return; }
	foreach ( array( 'elementor-icons', 'elementor-common', 'e-theme-ui-light', 'hostinger_chatbot_vendor', 'hostinger_chatbot', 'hostinger-reach-subscription-block', 'contact-form-7' ) as $h ) { wp_dequeue_style( $h ); }
	foreach ( array( 'hostinger_chatbot', 'contact-form-7', 'wp-emoji' ) as $h ) { wp_dequeue_script( $h ); }
}, 100 );
add_action( 'wp_default_scripts', function ( $s ) {
	if ( is_admin() || empty( $s->registered['jquery'] ) ) { return; }
	$s->registered['jquery']->deps = array_values( array_diff( (array) $s->registered['jquery']->deps, array( 'jquery-migrate' ) ) );
} );
add_action( 'init', function () {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );
	add_filter( 'tiny_mce_plugins', function ( $p ) { return is_array( $p ) ? array_diff( $p, array( 'wpemoji' ) ) : $p; } );
} );
/* O widget do chatbot da Hostinger não deve aparecer para o visitante nem para o assinante (fica só o WhatsApp). */
add_filter( 'hostinger_ai_assistant_chatbot_enabled', '__return_false' );
add_action( 'wp_footer', function () { echo '<style>#hostinger-chatbot,.hostinger-chatbot,[id^="hostinger-chatbot"]{display:none!important}</style>'; }, 999 );

/* 2) Arquivos estáticos */
function dv_est_dir() {
	$u = wp_upload_dir();
	return array( trailingslashit( $u['basedir'] ) . 'dv-static', trailingslashit( $u['baseurl'] ) . 'dv-static' );
}
function dv_est_file( $code, $ext, $key = '' ) {
	static $ok = null;
	list( $dir, $url ) = dv_est_dir();
	if ( $ok === null ) {
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$ok = is_dir( $dir ) && is_writable( $dir );
		if ( $ok && ! file_exists( $dir . '/index.html' ) ) { @file_put_contents( $dir . '/index.html', '' ); }
	}
	if ( ! $ok ) { return ''; }
	$key = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $key ) );
	$name = ( $key ? $key . '-' : '' ) . substr( md5( $code ), 0, 10 ) . '.' . $ext;
	$f = $dir . '/' . $name;
	if ( ! file_exists( $f ) && @file_put_contents( $f, $code ) === false ) { return ''; }
	return $url . '/' . $name;
}
function dv_est_html( $html ) {
	if ( stripos( $html, '</html>' ) === false ) { return $html; }
	/* a) scripts embutidos em base64 no conteúdo das páginas */
	$html = preg_replace_callback( '~<script>\(function\(\)\{var s=document\.createElement\("script"\);s\.textContent=new TextDecoder\(\)\.decode\(Uint8Array\.from\(atob\("([A-Za-z0-9+/=]+)"\)[^<]*?\}\)\(\);?</script>~', function ( $m ) {
		$code = base64_decode( $m[1], true ); if ( $code === false || $code === '' ) { return $m[0]; }
		$u = dv_est_file( $code, 'js', 'pg' );
		return $u ? '<script src="' . esc_url( $u ) . '"></script>' : $m[0];
	}, $html );
	$html = preg_replace_callback( '~<script([^>]*)\ssrc="data:text/javascript;base64,([A-Za-z0-9+/=]+)"([^>]*)></script>~', function ( $m ) {
		$code = base64_decode( $m[2], true ); if ( $code === false || $code === '' ) { return $m[0]; }
		$u = dv_est_file( $code, 'js', 'pg' );
		return $u ? '<script' . $m[1] . ' src="' . esc_url( $u ) . '"' . $m[3] . '></script>' : $m[0];
	}, $html );
	/* b) scripts marcados como estáticos nos snippets */
	$html = preg_replace_callback( '~<script>\s*/\*DVSTATIC\*/(.*?)</script>~s', function ( $m ) {
		$u = dv_est_file( trim( $m[1] ), 'js', 'sn' );
		return $u ? '<script src="' . esc_url( $u ) . '"></script>' : $m[0];
	}, $html );
	/* c) e 3): só fora de <script>, para não mexer em HTML que está dentro de strings de JavaScript */
	$parts = preg_split( '~(<script\b.*?</script>)~si', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$n = 0;
	foreach ( $parts as $k => $part ) {
		if ( $k % 2 === 1 ) { continue; }
		/* c) blocos de CSS grandes, sem url() relativa */
		$part = preg_replace_callback( '~<style([^>]*)>(.{2048,}?)</style>~s', function ( $m ) {
			$css = $m[2];
			if ( preg_match( '~url\(\s*["\']?(?!https?:|data:|/|//)~i', $css ) ) { return $m[0]; }
			$id = preg_match( '/id=["\']([^"\']+)/', $m[1], $x ) ? $x[1] : '';
			$u = dv_est_file( $css, 'css', $id ? $id : 'css' );
			return $u ? '<link rel="stylesheet" href="' . esc_url( $u ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' : $m[0];
		}, $part );
		/* 3) lazy loading nas imagens depois das três primeiras */
		$part = preg_replace_callback( '~<img\b([^>]*)>~i', function ( $m ) use ( &$n ) {
			$n++;
			if ( $n <= 3 || stripos( $m[1], 'loading=' ) !== false ) { return $m[0]; }
			return '<img' . $m[1] . ' loading="lazy" decoding="async">';
		}, $part );
		$parts[ $k ] = $part;
	}
	$html = implode( '', $parts );
	return $html;
}
add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) { return; }
	if ( isset( $_GET['dv_sem_estatico'] ) ) { return; }
	ob_start( 'dv_est_html' );
}, 0 );
