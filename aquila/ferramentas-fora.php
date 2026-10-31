/* Aquila — ferramentas do site fora do ar (out/2026): redireciona os links antigos e tira as chamadas das paginas. */
function aq_fora_slugs() { return array( 'ferramentas', 'raio-x-do-fornecedor', 'calculadora-de-prazos', 'calculadora-de-reajuste', 'buscador-de-licitacoes', 'banco-de-precos', 'comparador-de-licitantes', 'repactuacao-e-reequilibrio' ); }
function aq_fora_limpar( $h ) {
	if ( ! is_string( $h ) || $h === '' ) { return $h; }
	$t  = '(?:https?://aquilaadvocacia\.com\.br)?/(?:' . implode( '|', aq_fora_slugs() ) . ')/?(?:\#[^"]*)?';
	$in = '(?:[^<]|<(?!/?div\b))*';
	$re = array(
		'~<section[^>]*id="ferramentas-destaque"[^>]*>.*?</section>~s',
		'~<div class="news"[^>]*>\s*<div>' . $in . '(?:Ferramentas gratuitas|Free tool)' . $in . '</div>\s*<div class="side">' . $in . '</div>\s*</div>~',
		'~<li[^>]*>\s*<a\b[^>]*\bhref="' . $t . '"[^>]*>(?:[^<]|<(?!/a>))*</a>\s*</li>~',
		'~<a\b[^>]*\bhref="' . $t . '"[^>]*>(?:[^<]|<(?!/a>))*</a>~',
	);
	foreach ( $re as $r ) { $x = preg_replace( $r, '', $h ); if ( is_string( $x ) ) { $h = $x; } }
	return $h;
}
add_action( 'template_redirect', function () {
	$p = trim( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	if ( in_array( $p, aq_fora_slugs(), true ) ) { wp_safe_redirect( home_url( '/' ), 301 ); exit; }
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
	ob_start( 'aq_fora_limpar' );
}, 0 );
