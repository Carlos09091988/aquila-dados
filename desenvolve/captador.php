/**
 * Desenvolve — Captador de publicações (diários oficiais por CNPJ e por nome)
 * Fonte: Querido Diário (Open Knowledge Brasil), diários oficiais municipais.
 * Cobrança: cada termo monitorado usa 1 crédito por mês (cobrado ao cadastrar e a cada renovação).
 * Depende do núcleo (dv_usar, dv_saldo) e do snippet de ferramentas (dv_tools_perm).
 */

define( 'DV_CAPT_MAX', 30 ); // termos por assinante

add_action( 'init', function () {
	if ( get_option( 'dv_capt_db' ) === '1' ) { return; }
	global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_capt (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		termo VARCHAR(190) NOT NULL,
		data DATE NOT NULL,
		municipio VARCHAR(120) NOT NULL DEFAULT '',
		uf CHAR(2) NOT NULL DEFAULT '',
		url VARCHAR(500) NOT NULL DEFAULT '',
		txt VARCHAR(500) NOT NULL DEFAULT '',
		trecho TEXT,
		visto TINYINT(1) NOT NULL DEFAULT 0,
		criado DATETIME NOT NULL,
		hash CHAR(40) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uh (user_id,hash),
		KEY ud (user_id,data)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'dv_capt_db', '1' );
	if ( ! wp_next_scheduled( 'dv_capt_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 09:30 UTC' ), 'daily', 'dv_capt_diario' ); }
} );

function dv_capt_fmt_cnpj( $d ) { return preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d ); }
function dv_capt_termos( $uid ) { $t = json_decode( get_user_meta( $uid, 'dv_capt_termos', true ) ?: '[]', true ); return is_array( $t ) ? $t : array(); }
function dv_capt_salvar( $uid, $t ) { update_user_meta( $uid, 'dv_capt_termos', wp_json_encode( array_values( $t ) ) ); }

/** Consulta o Querido Diário e grava as publicações novas do termo. Devolve quantas entraram. */
function dv_capt_buscar( $uid, $t, $desde ) {
	global $wpdb;
	if ( $t['tipo'] === 'cnpj' ) { $q = '"' . dv_capt_fmt_cnpj( $t['valor'] ) . '" | "' . $t['valor'] . '"'; $rot = dv_capt_fmt_cnpj( $t['valor'] ); }
	else { $q = '"' . str_replace( '"', '', $t['valor'] ) . '"'; $rot = $t['valor']; }
	$novos = 0; $off = 0;
	do {
		$u = add_query_arg( array( 'querystring' => rawurlencode( $q ), 'published_since' => $desde, 'size' => 50, 'offset' => $off,
			'excerpt_size' => 500, 'number_of_excerpts' => 2, 'sort_by' => 'descending_date' ), 'https://queridodiario.ok.org.br/api/gazettes' );
		$r = wp_remote_get( $u, array( 'timeout' => 30 ) );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { break; }
		$j = json_decode( wp_remote_retrieve_body( $r ), true ); $gs = $j['gazettes'] ?? array();
		foreach ( $gs as $g ) {
			$tr = implode( "\n…\n", array_map( function ( $e ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $e ) ) ); }, (array) ( $g['excerpts'] ?? array() ) ) );
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}dv_capt (user_id,termo,data,municipio,uf,url,txt,trecho,visto,criado,hash) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,0,%s,%s)",
				$uid, $rot, $g['date'] ?? current_time( 'Y-m-d' ), mb_substr( (string) ( $g['territory_name'] ?? '' ), 0, 120 ), substr( (string) ( $g['state_code'] ?? '' ), 0, 2 ),
				substr( (string) ( $g['url'] ?? '' ), 0, 500 ), substr( (string) ( $g['txt_url'] ?? '' ), 0, 500 ), mb_substr( $tr, 0, 4000 ), current_time( 'mysql' ),
				sha1( $rot . '|' . ( $g['url'] ?? '' ) . '|' . ( $g['date'] ?? '' ) ) ) );
			$novos += (int) $ok;
		}
		$off += 50;
	} while ( count( $gs ) === 50 && $off < 300 );
	return $novos;
}

/** Cobra o mês do termo (1 crédito). Devolve true ou WP_Error. */
function dv_capt_cobrar( $uid, $t ) {
	$r = dv_usar( $uid, 'captador', 'termo|' . $t['tipo'] . '|' . $t['valor'] . '|' . current_time( 'Y-m' ), 'Monitorar: ' . ( $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor'] ) );
	return is_wp_error( $r ) ? $r : true;
}

add_action( 'dv_capt_diario', function () {
	$us = get_users( array( 'meta_key' => 'dv_capt_termos', 'fields' => 'ID' ) );
	$hoje = current_time( 'Y-m-d' );
	foreach ( $us as $uid ) {
		$ts = dv_capt_termos( $uid ); if ( ! $ts ) { continue; }
		$tot = 0; $novos = array();
		foreach ( $ts as &$t ) {
			if ( $t['ate'] < $hoje ) {
				$c = dv_capt_cobrar( $uid, $t );
				if ( is_wp_error( $c ) ) { $t['pausado'] = 1; continue; }
				$t['ate'] = gmdate( 'Y-m-d', strtotime( $hoje . ' +30 days' ) ); $t['pausado'] = 0;
			}
			if ( ! empty( $t['pausado'] ) ) { continue; }
			$n = dv_capt_buscar( $uid, $t, gmdate( 'Y-m-d', strtotime( ( $t['ult'] ?? $hoje ) . ' -3 days' ) ) );
			$t['ult'] = $hoje; $tot += $n; if ( $n ) { $novos[] = ( $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor'] ) . ': ' . $n; }
		}
		unset( $t ); dv_capt_salvar( $uid, $ts );
		if ( $tot ) {
			$u = get_userdata( $uid );
			wp_mail( $u->user_email, 'Desenvolve: ' . $tot . ' nova(s) publicação(ões) nos diários oficiais',
				"Olá, {$u->display_name}.\n\nO Captador de publicações encontrou novidades:\n\n- " . implode( "\n- ", $novos ) . "\n\nVeja tudo em " . home_url( '/plataforma/captador/' ) . "\n\nDesenvolve Estratégias" );
		}
	}
} );

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	register_rest_route( 'desenvolve/v1', '/captador', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $uid = get_current_user_id();
		$ts = array_map( function ( $t ) { $t['rotulo'] = $t['tipo'] === 'cnpj' ? dv_capt_fmt_cnpj( $t['valor'] ) : $t['valor']; return $t; }, dv_capt_termos( $uid ) );
		$w = ''; $f = sanitize_text_field( (string) $r['termo'] );
		if ( $f !== '' ) { $w = $wpdb->prepare( ' AND termo=%s', $f ); }
		$pubs = $wpdb->get_results( $wpdb->prepare( "SELECT id,termo,data,municipio,uf,url,txt,trecho,visto FROM {$wpdb->prefix}dv_capt WHERE user_id=%d$w ORDER BY data DESC, id DESC LIMIT 200", $uid ), ARRAY_A );
		$nv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_capt WHERE user_id=%d AND visto=0", $uid ) );
		return array( 'termos' => $ts, 'pubs' => $pubs, 'nao_vistas' => $nv, 'max' => DV_CAPT_MAX, 'saldo' => dv_saldo( $uid ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/termo', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $v = trim( (string) $r['valor'] ); $d = preg_replace( '/\D/', '', $v );
		if ( strlen( $d ) === 14 && ! preg_match( '/[a-zà-ú]{3,}/i', $v ) ) { $t = array( 'tipo' => 'cnpj', 'valor' => $d ); }
		else {
			$v = mb_strtoupper( preg_replace( '/\s+/', ' ', sanitize_text_field( $v ) ) );
			if ( mb_strlen( $v ) < 6 ) { return new WP_Error( 'dv_termo', 'Digite um CNPJ (14 números) ou um nome com pelo menos 6 letras.', array( 'status' => 400 ) ); }
			$t = array( 'tipo' => 'nome', 'valor' => mb_substr( $v, 0, 150 ) );
		}
		$ts = dv_capt_termos( $uid );
		foreach ( $ts as $x ) { if ( $x['tipo'] === $t['tipo'] && $x['valor'] === $t['valor'] ) { return new WP_Error( 'dv_dup', 'Esse termo já está sendo monitorado.', array( 'status' => 409 ) ); } }
		if ( count( $ts ) >= DV_CAPT_MAX ) { return new WP_Error( 'dv_max', 'Limite de ' . DV_CAPT_MAX . ' termos. Remova um para incluir outro.', array( 'status' => 400 ) ); }
		$c = dv_capt_cobrar( $uid, $t ); if ( is_wp_error( $c ) ) { return $c; }
		$hoje = current_time( 'Y-m-d' );
		$t += array( 'desde' => $hoje, 'ate' => gmdate( 'Y-m-d', strtotime( $hoje . ' +30 days' ) ), 'ult' => $hoje, 'pausado' => 0, 'id' => substr( md5( $t['tipo'] . $t['valor'] ), 0, 10 ) );
		$n = dv_capt_buscar( $uid, $t, gmdate( 'Y-m-d', strtotime( $hoje . ' -180 days' ) ) );
		$ts[] = $t; dv_capt_salvar( $uid, $ts );
		return array( 'ok' => true, 'encontradas' => $n, 'saldo' => dv_saldo( $uid ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/remover', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( $r['id'] );
		dv_capt_salvar( $uid, array_filter( dv_capt_termos( $uid ), function ( $t ) use ( $id ) { return $t['id'] !== $id; } ) );
		return array( 'ok' => true );
	} ) );
	register_rest_route( 'desenvolve/v1', '/captador/visto', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function () {
		global $wpdb; $wpdb->update( $wpdb->prefix . 'dv_capt', array( 'visto' => 1 ), array( 'user_id' => get_current_user_id() ) );
		return array( 'ok' => true );
	} ) );
} );

add_shortcode( 'dv_captador', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="cp" id="dv-capt" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<form class="cp-add" autocomplete="off">
		<label for="cp-v">CNPJ ou nome a monitorar</label>
		<div class="cp-row"><input id="cp-v" placeholder="00.000.000/0000-00 ou NOME DA EMPRESA / PESSOA" maxlength="150"><button type="submit">Monitorar</button></div>
		<p class="cp-h">Cada termo usa 1 crédito por mês. Ao cadastrar, buscamos os últimos 6 meses; depois, verificamos todo dia e avisamos por e-mail quando sair publicação nova.</p>
		<p class="cp-msg"></p>
	</form>
	<div class="cp-grid">
		<aside><h3>Termos monitorados <small class="cp-n"></small></h3><ul class="cp-t"></ul></aside>
		<section><div class="cp-top"><h3>Publicações encontradas</h3><button type="button" class="cp-lido g">Marcar tudo como visto</button></div><div class="cp-p"><p class="cp-h">Carregando…</p></div></section>
	</div>
	<p class="cp-src">Fonte: Querido Diário (Open Knowledge Brasil), que reúne diários oficiais de municípios. A cobertura depende dos municípios disponíveis na base; o Diário Oficial da União e os diários estaduais ainda não fazem parte da busca. Confira sempre a edição original.</p>
</div>
<style>
.cp{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.cp h3{font-size:1.05rem;margin:0 0 12px;color:#16191E}.cp small{color:var(--mu);font-weight:500}
.cp label{font-weight:700;display:block;margin-bottom:8px}
.cp-row{display:flex;gap:10px}.cp-row input{flex:1;border:1px solid #CFC8B8;border-radius:10px;padding:13px 14px;font:inherit;background:#fff}
.cp button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer}
.cp button.g{background:transparent;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.cp-h{color:var(--mu);font-size:.88rem;margin:8px 0 0}.cp-msg{margin:8px 0 0;font-weight:600}.cp-msg.e{color:#B04A3A}.cp-msg.o{color:#2F7A55}
.cp-grid{display:grid;grid-template-columns:300px 1fr;gap:24px;margin-top:26px}
.cp-t{list-style:none;margin:0;padding:0}.cp-t li{border:1px solid var(--ln);border-radius:12px;padding:10px 12px;margin-bottom:8px;background:#fff;cursor:pointer}
.cp-t li.on{border-color:var(--gd);box-shadow:0 0 0 2px rgba(201,164,92,.25)}
.cp-t b{display:block;font-size:.92rem;word-break:break-word}.cp-t span{color:var(--mu);font-size:.8rem}.cp-t a{float:right;color:#B04A3A;font-size:.8rem;text-decoration:none}
.cp-top{display:flex;justify-content:space-between;align-items:center;gap:10px}
.cp-i{border:1px solid var(--ln);border-radius:14px;padding:14px 16px;margin-bottom:10px;background:#fff}
.cp-i.nv{border-left:4px solid var(--gd)}.cp-i .m{font-size:.82rem;color:var(--mu);margin-bottom:6px}.cp-i .m b{color:#16191E}
.cp-i p{margin:0;font-size:.92rem;line-height:1.5;white-space:pre-line}.cp-i mark{background:#F4E3B8;padding:0 2px}
.cp-i .a{margin-top:8px;font-size:.85rem}.cp-i .a a{color:#8A6A2C;font-weight:700;margin-right:14px}
.cp-src{color:var(--mu);font-size:.8rem;margin-top:22px}
@media(max-width:800px){.cp{padding:18px}.cp-grid{grid-template-columns:1fr}.cp-row{flex-direction:column}}
</style>
<script>
(function(){var R=document.getElementById('dv-capt');if(!R)return;var api=R.dataset.api,n=R.dataset.nonce,filtro='',msg=R.querySelector('.cp-msg');
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':n,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.split('-').reverse().join('/'):''}
function hl(t,k){var x=e(t);var ks=[k,k.replace(/\D/g,'').length===14?k.replace(/\D/g,''):''].filter(Boolean);ks.forEach(function(q){x=x.split(e(q)).join('<mark>'+e(q)+'</mark>')});return x}
function saldo(s){var el=document.getElementById('dv-saldo');if(el&&s&&!(window.DV&&DV.master))el.textContent='Créditos: '+s.total}
function carregar(){req('GET','captador'+(filtro?'?termo='+encodeURIComponent(filtro):'')).then(function(j){
R.querySelector('.cp-n').textContent=j.termos.length+' de '+j.max;
R.querySelector('.cp-t').innerHTML=j.termos.length?j.termos.map(function(t){return'<li data-r="'+e(t.rotulo)+'" class="'+(filtro===t.rotulo?'on':'')+'"><a href="#" data-x="'+e(t.id)+'">remover</a><b>'+e(t.rotulo)+'</b><span>'+(t.tipo==='cnpj'?'CNPJ':'Nome')+' · '+(+t.pausado?'pausado: sem créditos':'ativo até '+br(t.ate))+'</span></li>'}).join(''):'<li style="cursor:default"><span>Nenhum termo ainda. Cadastre um CNPJ ou nome acima.</span></li>';
R.querySelector('.cp-p').innerHTML=j.pubs.length?j.pubs.map(function(p){var ver=p.txt&&/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/.test(p.termo)?'<a target="_blank" rel="noopener" href="/?aq_pub=1&txt='+encodeURIComponent(p.txt)+'&cnpj='+encodeURIComponent(p.termo.replace(/\D/g,''))+'&orig='+encodeURIComponent(p.url)+'&t='+encodeURIComponent((p.municipio||'')+' - '+br(p.data))+'">Ver o ato</a>':'';return'<div class="cp-i'+(+p.visto?'':' nv')+'"><div class="m"><b>'+e(p.municipio)+(p.uf?'/'+e(p.uf):'')+'</b> · '+br(p.data)+' · '+e(p.termo)+'</div><p>'+hl(p.trecho||'Trecho não disponível.',p.termo)+'</p><div class="a">'+ver+(p.url?'<a target="_blank" rel="noopener" href="'+e(p.url)+'">Abrir o diário</a>':'')+'</div></div>'}).join(''):'<p class="cp-h">'+(j.termos.length?'Nenhuma publicação encontrada até agora. Verificamos todos os dias.':'As publicações aparecem aqui.')+'</p>';
saldo(j.saldo);if(j.pubs.length&&window.DV){var c=document.querySelector('.dv-cons');if(c)c.classList.add('on')}
}).catch(function(x){R.querySelector('.cp-p').innerHTML='<p class="cp-h">'+e((x&&x.message)||'Não foi possível carregar.')+'</p>'})}
R.querySelector('.cp-add').addEventListener('submit',function(ev){ev.preventDefault();var i=R.querySelector('#cp-v'),b=R.querySelector('.cp-add button');if(!i.value.trim())return;b.disabled=true;b.textContent='Buscando…';msg.className='cp-msg';msg.textContent='';
req('POST','captador/termo',{valor:i.value}).then(function(j){msg.className='cp-msg o';msg.textContent='Monitorando. '+(j.encontradas?j.encontradas+' publicação(ões) dos últimos 6 meses encontradas.':'Nenhuma publicação nos últimos 6 meses; seguimos verificando todo dia.');i.value='';filtro='';carregar()}).catch(function(x){msg.className='cp-msg e';msg.textContent=(x&&x.message)||'Erro.';if(x&&x.data&&x.data.status===402&&window.DV)msg.innerHTML=e(x.message)+' <a href="'+(DV.acesso?'/creditos/':'/assinar/')+'">'+(DV.acesso?'Comprar créditos':'Assinar')+'</a>'}).then(function(){b.disabled=false;b.textContent='Monitorar'})},true);
R.querySelector('.cp-t').addEventListener('click',function(ev){var x=ev.target.closest('[data-x]');if(x){ev.preventDefault();if(window.confirm&&!confirm('Parar de monitorar este termo? O crédito do mês não é devolvido.'))return;req('POST','captador/remover',{id:x.dataset.x}).then(carregar);return}var li=ev.target.closest('li[data-r]');if(li){filtro=filtro===li.dataset.r?'':li.dataset.r;carregar()}});
R.querySelector('.cp-lido').addEventListener('click',function(){req('POST','captador/visto').then(carregar)});
carregar()})();
</script>
	<?php return ob_get_clean();
} );
