<?php
/**
 * Desenvolve — Relatórios salvos por 30 dias (Raio-X do concorrente, Raio-X do comprador, Due Diligence).
 * Abrir um relatório salvo não gasta créditos. Pesquisar de novo gasta.
 * Marcador: DV_REL_V1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_rel_tools() { return array( 'raio-x' => 'raiox', 'raio-x-comprador' => 'comprador', 'dossie' => 'dossie' ); }
function dv_rel_url( $f ) { $m = array_flip( dv_rel_tools() ); return isset( $m[ $f ] ) ? '/plataforma/' . $m[ $f ] . '/' : '/plataforma/'; }

add_action( 'init', function () {
	if ( get_option( 'dv_rel_ver' ) !== '1' ) {
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}dv_rel (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			ferramenta VARCHAR(20) NOT NULL,
			cnpj VARCHAR(14) NOT NULL DEFAULT '',
			rotulo VARCHAR(190) NOT NULL DEFAULT '',
			dados LONGBLOB NOT NULL,
			criado DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY u (user_id, ferramenta, cnpj),
			KEY c (criado)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'dv_rel_ver', '1', false );
	}
	if ( ! wp_next_scheduled( 'dv_rel_limpar' ) ) { wp_schedule_event( time() + 3600, 'daily', 'dv_rel_limpar' ); }
} );
add_action( 'dv_rel_limpar', function () {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dv_rel WHERE criado < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) );
} );

/** Relatórios do usuário nos últimos 30 dias (o mais recente de cada CNPJ). */
function dv_rel_lista( $uid, $f = '' ) {
	global $wpdb; $t = $wpdb->prefix . 'dv_rel';
	$lim = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
	$w = $f ? $wpdb->prepare( ' AND ferramenta=%s', $f ) : '';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, ferramenta, cnpj, rotulo, criado FROM $t WHERE user_id=%d AND criado >= %s$w ORDER BY id DESC LIMIT 200", $uid, $lim ), ARRAY_A );
	$o = array(); $ja = array();
	foreach ( $rows as $r ) {
		$k = $r['ferramenta'] . $r['cnpj']; if ( isset( $ja[ $k ] ) ) { continue; } $ja[ $k ] = 1;
		$r['id'] = (int) $r['id']; $r['ate'] = gmdate( 'Y-m-d', strtotime( $r['criado'] ) + 30 * DAY_IN_SECONDS );
		$r['url'] = dv_rel_url( $r['ferramenta'] ) . '?rel=' . $r['id']; $o[] = $r;
	}
	return $o;
}

add_action( 'rest_api_init', function () {
	$logado = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/relatorio', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $t = $wpdb->prefix . 'dv_rel'; $uid = get_current_user_id();
		$f = sanitize_key( (string) $r['ferramenta'] ); $cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
		$html = (string) $r['html'];
		if ( ! in_array( $f, dv_rel_tools(), true ) || strlen( $cnpj ) !== 14 || $html === '' || strlen( $html ) > 4000000 ) {
			return new WP_Error( 'dv_param', 'Relatório inválido.', array( 'status' => 400 ) );
		}
		$est = array();
		foreach ( (array) $r['estilos'] as $s ) {
			if ( ! is_array( $s ) || empty( $s['css'] ) ) { continue; }
			$est[] = array( 'id' => sanitize_html_class( (string) ( $s['id'] ?? '' ) ), 'css' => str_ireplace( '</style', '', mb_substr( (string) $s['css'], 0, 200000 ) ) );
			if ( count( $est ) >= 12 ) { break; }
		}
		$pai = (array) $r['pai'];
		$d = gzcompress( wp_json_encode( array(
			'html'    => wp_kses_post( $html ),
			'pai'     => array( 'id' => sanitize_html_class( (string) ( $pai['id'] ?? '' ) ), 'cls' => sanitize_text_field( (string) ( $pai['cls'] ?? '' ) ) ),
			'estilos' => $est,
		), JSON_UNESCAPED_UNICODE ), 6 );
		$rot = mb_substr( sanitize_text_field( (string) $r['rotulo'] ), 0, 190 );
		$id = (int) $r['id'];
		if ( $id ) {
			$ok = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id=%d AND user_id=%d AND cnpj=%s AND criado > %s", $id, $uid, $cnpj, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * HOUR_IN_SECONDS ) ) );
			if ( $ok ) { $wpdb->update( $t, array( 'dados' => $d, 'rotulo' => $rot ), array( 'id' => $id ) ); return array( 'id' => $id ); }
		}
		$wpdb->insert( $t, array( 'user_id' => $uid, 'ferramenta' => $f, 'cnpj' => $cnpj, 'rotulo' => $rot, 'dados' => $d, 'criado' => current_time( 'mysql' ) ) );
		return array( 'id' => (int) $wpdb->insert_id );
	} ) );
	register_rest_route( 'desenvolve/v1', '/relatorio/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb;
		$x = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dv_rel WHERE id=%d AND user_id=%d AND criado >= %s", (int) $r['id'], get_current_user_id(), gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ), ARRAY_A );
		if ( ! $x ) { return new WP_Error( 'dv_rel', 'Este relatório não está mais disponível. Os relatórios ficam salvos por 30 dias.', array( 'status' => 404 ) ); }
		$d = json_decode( (string) @gzuncompress( $x['dados'] ), true ) ?: array();
		return $d + array( 'id' => (int) $x['id'], 'ferramenta' => $x['ferramenta'], 'cnpj' => $x['cnpj'], 'rotulo' => $x['rotulo'], 'criado' => $x['criado'], 'ate' => gmdate( 'Y-m-d', strtotime( $x['criado'] ) + 30 * DAY_IN_SECONDS ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/relatorios', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		return dv_rel_lista( get_current_user_id(), sanitize_key( (string) $r['ferramenta'] ) );
	} ) );
} );

add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( array_keys( dv_rel_tools() ) ) ) { return; }
	$slug = get_post_field( 'post_name', get_queried_object_id() ); $T = dv_rel_tools(); $f = $T[ $slug ] ?? ''; if ( ! $f ) { return; }
	$ctx = array( 'tool' => $f, 'custo' => function_exists( 'dv_custo' ) ? dv_custo( $f ) : 5, 'lista' => dv_rel_lista( get_current_user_id(), $f ), 'api' => rest_url( 'desenvolve/v1/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) );
	?>
<style id="dv-rel-css">
.dv-rl{background:#fff;border:1px solid #E3DED2;border-radius:14px;padding:12px 16px;margin:0 0 16px;font-family:'Hanken Grotesk',system-ui,sans-serif;color:#16191E}
.dv-rl b{font-size:.78rem;letter-spacing:.1em;text-transform:uppercase;color:#8A6A2E;display:block;margin-bottom:8px}
.dv-rl .l{display:flex;gap:8px;flex-wrap:wrap}
.dv-rl a{display:inline-block;border:1px solid #E3DED2;background:#FAF8F3;border-radius:10px;padding:7px 11px;font-size:.86rem;color:#16191E!important;text-decoration:none!important;line-height:1.3}
.dv-rl a:hover{border-color:#C9A45C}.dv-rl a small{display:block;color:#6E6A60;font-size:.74rem}
.dv-rb{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:#FBF6EA;border:1px solid #E8D7AE;border-radius:14px;padding:12px 16px;margin:0 0 16px;font-family:'Hanken Grotesk',system-ui,sans-serif;color:#16191E;font-size:.95rem}
.dv-rb span{flex:1 1 260px}.dv-rb button{border:1px solid #C9A45C;background:#fff;color:#241A06;border-radius:10px;padding:8px 14px;font-weight:700;cursor:pointer;font:inherit;font-weight:700}
.dv-md .dv-rlx{display:flex;flex-direction:column;gap:8px;margin-top:14px}.dv-md .dv-rlx button{width:100%}
@media print{.dv-rl,.dv-rb{display:none!important}}
</style>
<script>
/* DV_REL_V1 */
(function(){
var R=<?php echo wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ); ?>;
var Q=new URLSearchParams(location.search),REL=Q.get('rel');if(REL)window.DVREL_VIEW=true;window.DVREL=R;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function fc(c){return c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function dt(s){var m=String(s).match(/(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);return m?m[3]+'/'+m[2]+'/'+m[1]+(m[4]?' às '+m[4]+':'+m[5]:''):''}
function cr(){return R.custo+(R.custo===1?' crédito':' créditos')}
var tool=document.querySelector('.dv-tool');if(!tool)return;
var QC=(Q.get('cnpj')||'').replace(/\D/g,'');if(!REL&&QC.length===14){var sk='';try{sk=sessionStorage.getItem('dv_rel_skip')||''}catch(x){}if(sk!==QC){for(var i=0;i<R.lista.length;i++)if(R.lista[i].cnpj===QC){window.DVREL_VIEW=true;location.replace(R.lista[i].url);return}}}
function urlNova(c){try{sessionStorage.setItem('dv_rel_skip',c)}catch(x){}return location.pathname+'?'+(R.tool==='dossie'?'auto=1&':'')+'cnpj='+c}
/* lista dos relatórios salvos */
if(R.lista.length&&!REL){var bx=document.createElement('div');bx.className='dv-rl';bx.innerHTML='<b>Seus relatórios dos últimos 30 dias · abrir não gasta créditos</b><div class="l">'+R.lista.slice(0,12).map(function(x){return'<a href="'+e(x.url)+'">'+e(x.rotulo||fc(x.cnpj))+'<small>'+fc(x.cnpj)+' · '+dt(x.criado).split(' às')[0]+'</small></a>'}).join('')+'</div>';tool.parentNode.insertBefore(bx,tool)}
/* pesquisar um CNPJ que já tem relatório salvo: oferecer abrir sem custo */
function cnpjTool(){var c='';tool.querySelectorAll('input').forEach(function(i){var d=String(i.value||'').replace(/\D/g,'');if(!c&&d.length===14)c=d});return c}
function salvo(c){for(var i=0;i<R.lista.length;i++)if(R.lista[i].cnpj===c)return R.lista[i];return null}
var livre=false,ACT=/consult|buscar|pesquis|gerar|analis|verific/i;
function acao(b){if(!b||b.disabled||b.closest('.dv-md,.dv-cons,.dv-rl,.dv-rb,#dv-rs'))return false;var t=(b.textContent||b.value||'')+' '+(b.getAttribute('aria-label')||'');return b.type==='submit'||ACT.test(t)}
function pergunta(x,seguir){var m=document.createElement('div');m.className='dv-md';m.innerHTML='<div><h3>Você já tem este relatório</h3><p>'+e(x.rotulo||fc(x.cnpj))+', emitido em '+dt(x.criado)+'. Ele fica disponível sem custo até '+dt(x.ate)+'.</p><div class="dv-rlx"><button type="button" data-a>Abrir o relatório salvo (sem custo)</button><button type="button" class="g" data-n>Pesquisar de novo, com dados atualizados ('+cr()+')</button></div></div>';document.body.appendChild(m);
 m.addEventListener('click',function(ev){if(ev.target===m){m.remove();return}if(ev.target.closest('[data-a]')){location.href=x.url;return}if(ev.target.closest('[data-n]')){m.remove();seguir()}})}
function pular(c){try{if(sessionStorage.getItem('dv_rel_skip')===c){return true}}catch(x){}return false}
/* no relatório salvo, uma nova consulta recarrega a página para pesquisar de verdade */
var RELC='';window.addEventListener('click',function(ev){if(!REL||livre)return;var b=ev.target.closest&&ev.target.closest('button,input[type=submit],input[type=button],a.btn,.btn');if(!b||!tool.contains(b)||!acao(b))return;var c=cnpjTool();if(c.length!==14)return;ev.preventDefault();ev.stopImmediatePropagation();location.href=urlNova(c)},true);
window.addEventListener('submit',function(ev){if(!REL||!tool.contains(ev.target))return;var c=cnpjTool();if(c.length!==14)return;ev.preventDefault();ev.stopImmediatePropagation();location.href=urlNova(c)},true);
window.addEventListener('click',function(ev){if(livre||REL)return;var b=ev.target.closest&&ev.target.closest('button,input[type=submit],input[type=button],a.btn,.btn');if(!b||!tool.contains(b)||!acao(b))return;var c=cnpjTool(),x=c&&salvo(c);if(!x||pular(c))return;ev.preventDefault();ev.stopImmediatePropagation();pergunta(x,function(){try{sessionStorage.setItem('dv_rel_skip',c)}catch(y){}livre=true;b.click();livre=false})},true);
window.addEventListener('submit',function(ev){if(livre||REL||!tool.contains(ev.target))return;var c=cnpjTool(),x=c&&salvo(c);if(!x||pular(c))return;ev.preventDefault();ev.stopImmediatePropagation();var f=ev.target;pergunta(x,function(){try{sessionStorage.setItem('dv_rel_skip',c)}catch(y){}livre=true;if(f.requestSubmit)f.requestSubmit();else f.submit();livre=false})},true);
/* abrir um relatório salvo */
if(REL){fetch(R.api+'relatorio/'+encodeURIComponent(REL),{credentials:'same-origin',headers:{'X-WP-Nonce':R.nonce}}).then(function(r){return r.json().then(function(j){return{s:r.status,j:j}})}).then(function(x){var j=x.j;
 var bn=document.createElement('div');bn.className='dv-rb';
 if(x.s>=400||!j.html){bn.innerHTML='<span>'+e((j&&j.message)||'Não foi possível abrir este relatório.')+'</span>';tool.parentNode.insertBefore(bn,tool);return}
 (j.estilos||[]).forEach(function(s){if(s.id&&document.getElementById(s.id))return;var st=document.createElement('style');if(s.id)st.id=s.id;st.textContent=s.css;document.head.appendChild(st)});
 var anc=(j.pai&&j.pai.id&&document.getElementById(j.pai.id))||tool,c1=j.pai&&j.pai.cls?String(j.pai.cls).split(/\s+/)[0]:'',par=c1?(anc.classList.contains(c1)?anc:anc.querySelector('.'+c1.replace(/[^\w-]/g,''))||anc):anc;
 tool.querySelectorAll('input').forEach(function(i){if(/cnpj|00\.000/i.test((i.name||'')+(i.id||'')+(i.placeholder||'')))i.value=fc(j.cnpj)});
 var tmp=document.createElement('div');tmp.innerHTML=j.html;[].forEach.call(tmp.querySelectorAll('.dv-ia'),function(x){x.removeAttribute('data-v2')});var rt=tmp.firstElementChild;if(!rt)return;var rc=rt.classList[0];if(rc)[].forEach.call(par.querySelectorAll(':scope > .'+rc),function(o){o.remove()});
 par.appendChild(rt);
 bn.innerHTML='<span>📄 <b>Relatório salvo</b>'+(j.rotulo?' de <b>'+e(j.rotulo)+'</b> ('+fc(j.cnpj)+')':'')+', emitido em '+dt(j.criado)+'. Disponível sem custo até '+dt(j.ate)+'.</span><button type="button">Pesquisar de novo ('+cr()+')</button>';
 bn.querySelector('button').onclick=function(){location.href=urlNova(j.cnpj)};rt.parentNode.insertBefore(bn,rt);
 var impresso=false;window.addEventListener('beforeprint',function(){impresso=true});
 rt.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b||!/pdf/i.test(b.textContent+' '+b.className))return;impresso=false;setTimeout(function(){if(!impresso)window.print()},300)});

}).catch(function(){})}
})();
</script>
	<?php
}, 98 );
