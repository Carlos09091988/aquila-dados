<?php
/**
 * Dossiê: geração em segundo plano + Parecer de habilitação (Lei 14.133/2021).
 * Instalar como mu-plugin (wp-content/mu-plugins/dv-dossie-plus.php) ou snippet PHP.
 * Depende das funções do snippet do dossiê (dv_dossie_dados, dv_tools_perm, dv_saldo...).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_dj_k( $cnpj ) { return 'dv_dsjob_' . $cnpj; }
function dv_dj_tok( $cnpj ) { return substr( hash_hmac( 'sha256', 'dsjob|' . $cnpj, wp_salt( 'auth' ) ), 0, 32 ); }
function dv_dj_dispara( $cnpj ) {
	wp_remote_post( rest_url( 'desenvolve/v1/dossie/rodar' ), array( 'blocking' => false, 'timeout' => 2, 'sslverify' => false,
		'body' => array( 'cnpj' => $cnpj, 't' => dv_dj_tok( $cnpj ) ) ) );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };

	/* Pede o dossiê: começa (ou acompanha) a geração em segundo plano */
	register_rest_route( 'desenvolve/v1', '/dossie/job', array(
		array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'Informe um CNPJ com 14 números.', array( 'status' => 400 ) ); }
			if ( get_transient( 'dv_ds9_' . $cnpj ) ) { return array( 'estado' => 'pronto' ); }
			$uid = get_current_user_id(); $n = max( 1, (int) ( function_exists( 'dv_cfg' ) ? ( dv_cfg( 'creditos_dossie' ) ?: 3 ) : 3 ) );
			if ( function_exists( 'dv_ilimitado' ) && ! dv_ilimitado( $uid ) && dv_saldo( $uid )['total'] < $n ) {
				global $wpdb;
				$ja = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_uso WHERE user_id=%d AND chave=%s AND criado > %s", $uid,
					substr( hash( 'sha256', 'dossie|' . strtolower( 'dossie|' . $cnpj . '|1' ) ), 0, 64 ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
				if ( ! $ja ) { return new WP_Error( 'dv_sem_credito', 'O dossiê completo usa ' . $n . ' créditos e seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			}
			$j = get_option( dv_dj_k( $cnpj ) );
			if ( ! is_array( $j ) || $j['estado'] !== 'rodando' || time() - $j['ini'] > 330 ) {
				$j = array( 'estado' => 'rodando', 'ini' => time(), 'uids' => array() );
				$j['uids'][ $uid ] = time(); update_option( dv_dj_k( $cnpj ), $j, false );
				dv_dj_dispara( $cnpj );
			} else { $j['uids'][ $uid ] = time(); update_option( dv_dj_k( $cnpj ), $j, false ); }
			return array( 'estado' => 'rodando', 'segundos' => time() - $j['ini'] );
		} ),
		array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( get_transient( 'dv_ds9_' . $cnpj ) ) { return array( 'estado' => 'pronto' ); }
			$j = get_option( dv_dj_k( $cnpj ) ); if ( ! is_array( $j ) ) { return array( 'estado' => 'nenhum' ); }
			if ( $j['estado'] === 'rodando' ) {
				$j['uids'][ get_current_user_id() ] = time(); update_option( dv_dj_k( $cnpj ), $j, false );
				if ( time() - $j['ini'] > 330 ) { return array( 'estado' => 'erro', 'message' => 'A geração passou do tempo. Tente de novo.' ); }
			}
			return array( 'estado' => $j['estado'], 'segundos' => time() - $j['ini'], 'message' => $j['msg'] ?? '' );
		} ),
	) );

	/* Execução em segundo plano (chamada pelo próprio servidor) */
	register_rest_route( 'desenvolve/v1', '/dossie/rodar', array( 'methods' => 'POST', 'permission_callback' => '__return_true',
		'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 || ! hash_equals( dv_dj_tok( $cnpj ), (string) $r['t'] ) ) { return new WP_Error( 'dv_negado', 'Negado.', array( 'status' => 403 ) ); }
			ignore_user_abort( true ); set_time_limit( 320 );
			$D = function_exists( 'dv_dossie_dados' ) ? dv_dossie_dados( $cnpj ) : new WP_Error( 'dv_off', 'Dossiê indisponível.' );
			$j = get_option( dv_dj_k( $cnpj ) ); if ( ! is_array( $j ) ) { $j = array( 'ini' => time(), 'uids' => array() ); }
			$j['estado'] = is_wp_error( $D ) ? 'erro' : 'pronto'; $j['msg'] = is_wp_error( $D ) ? $D->get_error_message() : ''; $j['fim'] = time();
			update_option( dv_dj_k( $cnpj ), $j, false );
			if ( ! is_wp_error( $D ) ) { // quem saiu da página recebe aviso por e-mail
				$nome = $D['cadastro']['razao_social'] ?? $cnpj;
				foreach ( (array) $j['uids'] as $uid => $visto ) {
					if ( time() - (int) $visto < 45 ) { continue; }
					$u = get_userdata( $uid ); if ( ! $u ) { continue; }
					$link = home_url( '/plataforma/dossie/?cnpj=' . $cnpj . '&auto=1' );
					wp_mail( $u->user_email, 'Seu dossiê está pronto: ' . $nome,
						"Olá,\n\nTerminamos o dossiê de {$nome} (CNPJ {$cnpj}).\n\nAbra aqui: {$link}\n\nO relatório fica guardado por 12 horas; abrir de novo nesse período não cobra créditos.\n\nDesenvolve Estratégias" );
				}
			}
			return array( 'ok' => true );
		} ) );
} );


/* Matriz e filiais: qualquer CNPJ informado (matriz ou filial) vira o dossiê da matriz, com todas as unidades */
function dv_uni_matriz( $cnpj ) { return function_exists( 'dv_ds_dv' ) ? dv_ds_dv( substr( $cnpj, 0, 8 ) ) : $cnpj; }
add_filter( 'rest_request_before_callbacks', function ( $resp, $handler, $req ) {
	$rota = $req->get_route();
	if ( ! preg_match( '#^/desenvolve/v1/dossie(/job)?$#', $rota ) ) { return $resp; }
	$c = preg_replace( '/\D/', '', (string) $req->get_param( 'cnpj' ) );
	if ( strlen( $c ) === 14 && substr( $c, 8, 4 ) !== '0001' ) {
		$m = dv_uni_matriz( $c );
		if ( $m !== $c ) { set_transient( 'dv_ds_orig_' . get_current_user_id() . '_' . $m, $c, DAY_IN_SECONDS ); $req->set_param( 'cnpj', $m ); }
	}
	return $resp;
}, 10, 3 );
function dv_unidades( $cnpj ) {
	$raiz = substr( $cnpj, 0, 8 ); $ck = 'dv_uni2_' . $raiz; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	$l = array();
	if ( function_exists( 'dv_ds_gz' ) ) {
		$o = dv_ds_gz( 'filiais/' . substr( $raiz, 0, 3 ) . '.json.gz' ); $lista = (array) ( $o[ $raiz ] ?? array() ); unset( $o );
		$pg = dv_ds_gz( 'pgfn/' . substr( $raiz, 0, 3 ) . '.json.gz' );
		foreach ( $lista as $x ) { $p = explode( '|', (string) $x ); $u = $p[0];
			$l[] = array( 'cnpj' => $u, 'uf' => $p[1] ?? '', 'matriz' => substr( $u, 8, 4 ) === '0001', 'pgfn' => isset( $pg[ $u ] ) ? (float) $pg[ $u ][0] : 0 ); }
		unset( $pg );
	}
	set_transient( $ck, $l, 7 * DAY_IN_SECONDS ); return $l;
}
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( $req->get_route() !== '/desenvolve/v1/dossie' || ! ( $res instanceof WP_REST_Response ) ) { return $res; }
	$d = $res->get_data(); if ( ! is_array( $d ) || empty( $d['cnpj'] ) ) { return $res; }
	$d['unidades'] = dv_unidades( $d['cnpj'] );
	$o = get_transient( 'dv_ds_orig_' . get_current_user_id() . '_' . $d['cnpj'] ); if ( $o ) { $d['consultado'] = $o; }
	$res->set_data( $d ); return $res;
}, 10, 3 );

/* Página do dossiê: espera em segundo plano + parecer de habilitação */
add_action( 'wp_footer', function () {
	global $post; if ( ! is_user_logged_in() || ! $post ) { return; }
	$ds = strpos( (string) $post->post_content, 'tool="dossie"' ) !== false; $cp = strpos( (string) $post->post_content, 'id="cp2"' ) !== false;
	$rx = (bool) preg_match( '#/plataforma/raio-x/?(\?|$)#', (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
	if ( ! $ds && ! $cp && ! $rx ) { return; }
	$api = esc_url_raw( rest_url( 'desenvolve/v1/' ) ); $nonce = wp_create_nonce( 'wp_rest' ); ?>
<style>
#dv-esp{position:fixed;inset:auto 18px 18px auto;z-index:9999;max-width:360px;background:#16191E;color:#F3EFE6;border-radius:16px;padding:18px 20px;box-shadow:0 18px 50px rgba(22,25,30,.35);font:500 .92rem/1.5 "Hanken Grotesk",-apple-system,sans-serif}
#dv-esp b{display:block;font-size:1rem;margin-bottom:4px;color:#fff}#dv-esp .bar{height:4px;background:#2E333B;border-radius:4px;margin:12px 0 8px;overflow:hidden}#dv-esp .bar i{display:block;height:100%;width:0;background:#C9A45C;transition:width 1s linear}
#dv-esp small{color:#B9BDB8}
#dv-par{padding:26px 30px;border-bottom:1px solid #E3DED2;background:#FFFDF8}
#dv-par h3{margin:0 0 4px;font:600 1.3rem/1.25 "Fraunces",Georgia,serif;color:#16191E}
#dv-par .sub{margin:0 0 16px;color:#6E6A60;font-size:.92rem}
#dv-par .modos{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
#dv-par .modos button{background:#fff;border:1px solid #CFC8B8;color:#16191E;border-radius:999px;padding:8px 14px;font:600 .88rem "Hanken Grotesk",sans-serif;cursor:pointer}
#dv-par .modos button[aria-pressed=true]{background:#16191E;color:#fff;border-color:#16191E}
#dv-par .ent{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:18px}
#dv-par .ent label{font-size:.8rem;font-weight:700;color:#6E6A60;display:block;margin-bottom:4px}
#dv-par .ent input{width:100%;box-sizing:border-box;border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff}
#dv-par .ver{display:flex;gap:16px;align-items:flex-start;padding:16px 18px;border-radius:14px;margin-bottom:18px;border:1px solid}
#dv-par .ver.ok{background:#EEF7F1;border-color:#A9D5BC}#dv-par .ver.am{background:#FFF8E6;border-color:#E8C877}#dv-par .ver.vm{background:#FCEDEA;border-color:#F0A08F}
#dv-par .ver b{display:block;font:600 1.15rem "Fraunces",Georgia,serif;margin-bottom:2px}
#dv-par .ver p{margin:0;font-size:.93rem;color:#16191E;font-weight:400}#dv-par .ver b{color:#16191E}
#dv-par ol{list-style:none;margin:0;padding:0;display:grid;gap:10px}
#dv-par li{border:1px solid #E3DED2;border-left-width:5px;border-radius:10px;padding:12px 14px;background:#fff}
#dv-par li.ok{border-left-color:#5E9E79}#dv-par li.am{border-left-color:#D9A93A}#dv-par li.vm{border-left-color:#C2513D}#dv-par li.na{border-left-color:#B9B4A7}
#dv-par li,#dv-par li *{font-weight:400}#dv-par li h4{margin:0 0 3px;font-size:.98rem;font-weight:700;color:#16191E}#dv-par li p{margin:0;font-size:.9rem;color:#33363B}
#dv-par li .fu{margin-top:5px;font-size:.8rem;color:#8A6A2E}
#dv-par li .ac{margin-top:6px;font-size:.86rem;color:#16191E}#dv-par li .ac:before{content:"Próximo passo: ";font-weight:700}#dv-par li .fu{font-weight:600}
#dv-par h4.grp{margin:20px 0 8px;font:700 .82rem "Hanken Grotesk",sans-serif;color:#6E6A60}
#dv-par .docs{margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#F5F1E8;font-size:.9rem}
#dv-par .docs ul{margin:6px 0 0;padding-left:18px}
#dv-par .docs b{color:#16191E}#dv-par .docs .it{margin:6px 0 0;padding-left:26px;position:relative;color:#16191E;font-weight:400}#dv-par .docs .it:before{content:"";position:absolute;left:2px;top:3px;width:13px;height:13px;border:2px solid #A8843F;border-radius:3px}
#dv-par .nota,#dv-par .nota *{font-weight:400}#dv-par .nota b{font-weight:700}
#dv-par .okd{margin-top:16px;border:1px solid #E3DED2;border-radius:12px;padding:4px 14px;background:#fff}#dv-par .okd summary{cursor:pointer;padding:10px 0;font-weight:700;font-size:.9rem;color:#2F6B4F}

#dv-par .nota{margin-top:14px;font-size:.8rem;color:#6E6A60}
@media print{#dv-par .modos,#dv-par .ent{display:none}}
/* Leitura guiada */
.dv-org .dv-tema .dv-pdf-top,.dv-org .dv-tema .dv-rnav{display:none!important}
.rp-bar button.g,.rp-bar .g{color:#16191E!important;background:#fff!important;border:1px solid #CFC8B8!important}
.dv-org #dv-rs{display:none!important}#dv-mia-slot{padding:0 30px 18px;background:#FFFDF8}#dv-mia-slot .dv-mia-w{margin:0}
#dv-capa p,#dv-capa li,#dv-capa span,#dv-mapa span,#dv-mapa p,.dv-tema>summary span{font-weight:400}
.rp.dv-org .rp-sec h3 i{display:none}
#dv-capa{padding:26px 30px 8px;background:#FFFDF8}
#dv-capa .q{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:18px;align-items:stretch}
@media(max-width:760px){#dv-capa .q{grid-template-columns:1fr}}
#dv-capa .v{border-radius:16px;padding:20px 22px;color:#fff}
#dv-capa .v.ok{background:#2F6B4F}#dv-capa .v.am{background:#8A6516}#dv-capa .v.vm{background:#9A3B2C}
#dv-capa .v small{display:block;font-weight:700;font-size:.78rem;opacity:.85;margin-bottom:6px}
#dv-capa .v b{display:block;font:600 1.6rem/1.15 "Fraunces",Georgia,serif;margin-bottom:8px}
#dv-capa .v p{margin:0;font-size:.95rem;opacity:.95}
#dv-capa .acoes{border:1px solid #E3DED2;border-radius:16px;padding:18px 20px;background:#fff}
#dv-capa .acoes b{display:block;margin-bottom:8px;font-size:.95rem}
#dv-capa .acoes ol{margin:0;padding-left:20px;font-size:.92rem}#dv-capa .acoes li{margin:0 0 6px}
#dv-capa .kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:18px 0 6px}@media(max-width:760px){#dv-capa .kpi{grid-template-columns:repeat(2,minmax(0,1fr))}}
#dv-capa .kpi div{border:1px solid #E3DED2;border-radius:12px;padding:12px 14px;background:#fff}
#dv-capa .kpi b{display:block;font:600 1.35rem "Fraunces",Georgia,serif;color:#16191E}
#dv-capa .kpi span{font-size:.8rem;color:#6E6A60}
#dv-capa .kpi .vm b{color:#9A3B2C}#dv-capa .kpi .am b{color:#8A6516}
#dv-mapa{padding:8px 30px 22px;background:#FFFDF8;border-bottom:1px solid #E3DED2}
#dv-mapa p{margin:0 0 10px;font-size:.85rem;color:#6E6A60}
#dv-mapa nav{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}@media(max-width:640px){#dv-mapa nav{grid-template-columns:repeat(2,minmax(0,1fr))}}
#dv-mapa a{display:block;text-decoration:none;color:#16191E;border:1px solid #E3DED2;border-top:4px solid #B9B4A7;border-radius:12px;padding:10px 12px;background:#fff}
#dv-mapa a.ok{border-top-color:#5E9E79}#dv-mapa a.am{border-top-color:#D9A93A}#dv-mapa a.vm{border-top-color:#C2513D}
#dv-mapa a b{display:block;font-size:.9rem}#dv-mapa a span{font-size:.8rem;color:#6E6A60}
#dv-mapa a:focus-visible,.dv-tema>summary:focus-visible{outline:3px solid #C9A45C;outline-offset:2px}
.dv-tema{border-bottom:1px solid #E3DED2}
.dv-tema>summary{list-style:none;cursor:pointer;padding:18px 30px;display:flex;gap:14px;align-items:center}
.dv-tema>summary::-webkit-details-marker{display:none}
.dv-tema>summary .st{flex:none;width:12px;height:12px;border-radius:50%;background:#B9B4A7}
.dv-tema.ok>summary .st{background:#5E9E79}.dv-tema.am>summary .st{background:#D9A93A}.dv-tema.vm>summary .st{background:#C2513D}
.dv-tema>summary b{display:block;font:600 1.12rem "Fraunces",Georgia,serif;color:#16191E}
.dv-tema>summary span{display:block;font-size:.88rem;color:#4A4D52}
.dv-tema>summary:after{content:"Ver detalhes";margin-left:auto;flex:none;font-size:.8rem;font-weight:700;color:#8A6A2E}
.dv-tema[open]>summary:after{content:"Fechar"}
.dv-tema>.rp-sec{border-bottom:0;padding-top:6px}
.dv-tema>.rp-sec+.rp-sec{border-top:1px dashed #E3DED2}
.dv-tema #dv-par{border-bottom:0;padding-top:4px}
.dv-tema #dv-par>h3,.dv-tema #dv-par>.sub{display:none}
@media print{.dv-tema>summary:after{display:none}.dv-tema>*{display:block!important}}

</style>
<?php if ( $ds ) : ?>
<script>
(function(){
var API=<?php echo wp_json_encode( $api ); ?>,NONCE=<?php echo wp_json_encode( $nonce ); ?>,of=window.fetch;
/* 1. Geração em segundo plano: o POST /dossie espera o trabalho terminar no servidor e só então busca o resultado */
function esp(seg,txt){var b=document.getElementById('dv-esp');if(!b){b=document.createElement('div');b.id='dv-esp';b.setAttribute('role','status');b.innerHTML='<b>Gerando o dossiê</b><span class="t"></span><div class="bar"><i></i></div><small>Pode sair desta página: avisamos por e-mail quando ficar pronto.</small>';document.body.appendChild(b)}
b.querySelector('.t').textContent=txt||'Consultando Receita, sanções, dívida ativa, contratos e processos…';b.querySelector('.bar i').style.width=Math.min(95,Math.round(seg/1.2))+'%'}
function fimEsp(){var b=document.getElementById('dv-esp');if(b)b.remove()}
var ETAPAS=[[0,'Cadastro e grupo econômico…'],[15,'Sanções, certidões e dívida ativa…'],[30,'Contratos públicos (PNCP e CGU)…'],[50,'Processos judiciais da empresa e dos sócios…'],[75,'Diários oficiais e imprensa…'],[100,'Fechando o relatório…']];
function etapa(s){var t='';ETAPAS.forEach(function(e){if(s>=e[0])t=e[1]});return t}
function j(u,o){o=o||{};o.credentials='same-origin';o.headers=Object.assign({'X-WP-Nonce':NONCE,'Content-Type':'application/json'},o.headers||{});return of(API+u,o).then(function(r){return r.json().then(function(x){x._st=r.status;return x})})}
window.fetch=function(u,o){
 if(!/\/desenvolve\/v1\/dossie(\?|$)/.test(String(u))||!o||String(o.method||'').toUpperCase()!=='POST')return of.apply(this,arguments);
 var self=this,args=arguments,cnpj='';try{cnpj=String(JSON.parse(o.body).cnpj||'').replace(/\D/g,'')}catch(e){try{cnpj=String(new URLSearchParams(o.body).get('cnpj')||'').replace(/\D/g,'')}catch(e2){}}
 if(cnpj.length!==14)return of.apply(self,args);
 return j('dossie/job',{method:'POST',body:JSON.stringify({cnpj:cnpj})}).then(function(x){
  if(x._st>=400||x.estado==='pronto'){fimEsp();return of.apply(self,args)}
  return new Promise(function(ok){var t0=Date.now()-(x.segundos||0)*1000;
   (function volta(){var s=(Date.now()-t0)/1000;esp(s,etapa(s));
    setTimeout(function(){j('dossie/job?cnpj='+cnpj).then(function(y){if(y.estado==='rodando')return volta();fimEsp();ok(of.apply(self,args))}).catch(function(){volta()})},4000)})()})
 }).catch(function(){fimEsp();return of.apply(self,args)})
};

/* 2. Parecer de habilitação */
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bi':v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function num(s){s=String(s||'').replace(/[^\d,.\s]/g,'').trim();if(!s)return 0;var m=s.match(/[\d.,]+/);if(!m)return 0;var t=m[0];if(/,\d{1,2}$/.test(t))t=t.replace(/\./g,'').replace(',','.');else t=t.replace(/[.,]/g,'');var v=parseFloat(t)||0;if(/mi/i.test(s))v*=1e6;else if(/mil/i.test(s))v*=1e3;return v}
function norm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase()}
var ST={modo:'parceiro',valor:0,obj:''};
function dados(d){
 var c=d.cadastro||{},p=d.pgfn||null,rx=d.raiox||{},ex=d.extras||{},sc=rx.sancoes||{},jd=d.judicial||{},JE=jd.empresa||{},pt=JE.por_tipo||{},pn=d.pncp||{itens:[]},cg=d.cgu_contratos||[];
 var sanc=[].concat(sc.ceis||[],sc.cnep||[],sc.tcu||[],(ex.cepim||{}).itens||[],(ex.tcesp||{}).itens||[]);
 var ls=ex.listasuja,lsN=Array.isArray(ls)?ls.length:(ls&&Array.isArray(ls.itens)?ls.itens.length:0);
 var hoje=new Date().toISOString().slice(0,10);
 var cons=pn.itens.map(function(x){return{obj:(x.objeto||x.titulo||''),org:x.orgao,valor:+x.valor||0,ini:x.inicio||x.assinatura,fim:x.fim}}).concat(cg.map(function(x){return{obj:x.objeto||'',org:x.orgao||'',valor:+x.valor||0,ini:x.inicio,fim:x.fim}}));
 var vig=cons.filter(function(x){return x.fim&&String(x.fim).slice(0,10)>=hoje});
 return{c:c,p:p,sanc:sanc,ls:lsN,lsErro:!!(ls&&ls.erro),pt:pt,cons:cons,vig:vig,pep:d.pep||[],grupo:d.grupo||[],parcial:d.parcial||[],socios:(jd.socios||[]),destaques:JE.destaques||[]};
}
function itens(d){
 var X=dados(d),c=X.c,out=[],V=ST.valor,M=ST.modo,cap=+c.capital_social||0;
 function add(g,s,t,a,f,ac){out.push({g:g,s:s,t:t,a:a,f:f,ac:ac})}
 /* Impedimentos de participar (art. 14) */
 var sit=String(c.descricao_situacao_cadastral||'');
 if(sit&&!/ATIVA/i.test(sit))add('imp','vm','CNPJ não está ativo na Receita','Situação cadastral: '+sit+'. Com o CNPJ nessa situação, a empresa não comprova a habilitação jurídica nem a fiscal.','Lei 14.133, arts. 66 e 68, I','Confirme a situação no site da Receita no dia da sessão.');
 else add('imp','ok','CNPJ ativo','Situação cadastral ativa na Receita Federal desde '+(c.data_inicio_atividade?String(c.data_inicio_atividade).split('-').reverse().join('/'):'—')+'.','Lei 14.133, art. 68, I');
 if(X.sanc.length){var txt=norm(JSON.stringify(X.sanc)),ini=/inidone/.test(txt),imped=/impediment|impedid/.test(txt),susp=/suspens/.test(txt);
  if(ini)add('imp','vm','Declarada inidônea','Há registro de declaração de inidoneidade. Essa sanção vale para toda a Administração Pública, de todos os entes, por 3 a 6 anos.','Lei 14.133, art. 14, III, e art. 156, IV e §5º','Confira a data de término da sanção no CEIS/TCU; se vigente, a empresa não pode disputar nem ser contratada.');
  if(imped)add('imp','vm','Impedimento de licitar e contratar','Há registro de impedimento. Ele vale só no ente federativo que aplicou a sanção (a União, o estado ou o município), por até 3 anos.','Lei 14.133, art. 156, III e §4º',M==='concorrente'?'Veja quem aplicou: se for o mesmo ente desta licitação, ela não pode participar. Leve o registro do CEIS à sessão.':'Veja quem aplicou: se for o mesmo ente da sua licitação, a empresa está impedida; se for outro, ela pode participar.');
  if(susp&&!ini&&!imped)add('imp','am','Suspensão temporária (Lei 8.666)','Há registro de suspensão. Pela regra antiga, ela alcança o órgão que aplicou a sanção.','Lei 8.666/1993, art. 87, III','Confira o órgão que aplicou e a data de término.');
  if(!ini&&!imped&&!susp)add('imp','am','Registro em cadastro de sanções',X.sanc.length+' registro(s) em CEIS, CNEP, TCU, CEPIM ou TCE-SP. Leia o tipo e a abrangência na seção de sanções abaixo.','Lei 14.133, art. 14, III','Confira a abrangência e a vigência de cada registro.');
 } else add('imp','ok','Sem sanções nos cadastros oficiais','Nada consta em CEIS, CNEP, TCU (inidôneos), CEPIM, leniência e TCE-SP na data deste relatório.','Lei 14.133, art. 14, III');
 if(X.ls)add('imp','vm','Aparece na lista suja do trabalho escravo','A empresa ou um sócio está no cadastro de empregadores do Ministério do Trabalho. A lei impede de disputar quem foi condenado, com trânsito em julgado, nos 5 anos anteriores, por trabalho análogo ao de escravo.','Lei 14.133, art. 14, VI','Verifique se já há condenação transitada em julgado. Mesmo sem ela, o risco de imagem e de execução é alto.');
 if(X.pt.improbidade)add('imp','am','Processo de improbidade administrativa',X.pt.improbidade+' processo(s) de improbidade com a empresa como parte. A condenação pode trazer proibição de contratar com o poder público.','Lei 8.429/1992, art. 12','Consulte o Cadastro de Condenações por Improbidade (CNIA/CNJ) e a fase de cada processo.');
 if(X.pep.length)add('imp','am','Sócio politicamente exposto',X.pep.length+' sócio(s) na lista de pessoas politicamente expostas. A lei impede de disputar quem tem vínculo com dirigente do órgão ou com agente público que atue na licitação ou no contrato, inclusive parentes até o 3º grau.','Lei 14.133, art. 14, IV','Cruze o cargo do sócio com o órgão da licitação.');
 if(X.grupo.length)add('imp','na','Empresas ligadas ('+X.grupo.length+')','Empresas do mesmo grupo (sócios, endereço, telefone ou e-mail em comum). Controladoras, controladas ou coligadas não podem disputar entre si o mesmo certame.','Lei 14.133, art. 14, V','Na ata da sessão, veja se alguma delas disputou o mesmo lote.');
 /* Regularidade fiscal, social e trabalhista (art. 68) */
 var p=X.p;
 if(p&&p.total>0){var cob=+p.em_cobranca||0;
  add('fis',cob>0?'vm':'am','Dívida ativa da União: '+mc(p.total),p.inscricoes+' inscrição(ões)'+(p.ajuizadas?', '+p.ajuizadas+' ajuizada(s)':'')+(cob>0?'. Desse total, '+mc(cob)+' está em cobrança, sem suspensão: a certidão federal provavelmente sai positiva.':'. Há sinais de suspensão ou garantia, o que pode permitir uma certidão positiva com efeito de negativa.'),'Lei 14.133, art. 68, III; CTN, art. 206',M==='concorrente'?'Confira a certidão federal que ela apresentou: só vale se for negativa ou positiva com efeito de negativa, e dentro da validade.':'Peça a Certidão Conjunta da Receita/PGFN atualizada. Só serve se for negativa ou positiva com efeito de negativa.');
  if(+p.fgts>0)add('fis','vm','Débito de FGTS inscrito: '+mc(p.fgts),'Com FGTS em dívida ativa, o Certificado de Regularidade do FGTS tende a sair negado.','Lei 14.133, art. 68, IV',M==='concorrente'?'Confira o CRF que ela apresentou e consulte o site da Caixa no dia da sessão.':'Peça o CRF atualizado (Caixa).');
 } else if(p===null)add('fis','ok','Sem dívida ativa da União','Nada consta na base da PGFN para este CNPJ.','Lei 14.133, art. 68, III');
 var fD=(d.unidades||[]).filter(function(u){return!u.matriz&&u.pgfn>0});if(fD.length)add('fis','am','Dívida ativa em '+fD.length+' filial(is): '+mc(fD.reduce(function(a,u){return a+u.pgfn},0)),'Além da matriz, filiais têm inscrições próprias na dívida ativa da União. A certidão conjunta federal vale para a matriz e todas as filiais: débito em qualquer unidade pode torná-la positiva.','Lei 14.133, art. 68, III',M==='concorrente'?'Confira se a certidão apresentada é posterior às inscrições das filiais.':'Peça a certidão conjunta atualizada e o detalhamento das inscrições das filiais.');
 var tx=+X.pt.trab_execucao||0,tt=(+X.pt.trabalhista||0)+tx;
 if(tx)add('fis',tx>20?'vm':'am','Execuções trabalhistas: '+tx,'Processos trabalhistas em fase de execução (condenação não paga). Execução sem pagamento nem garantia faz a Certidão Negativa de Débitos Trabalhistas sair positiva.','Lei 14.133, art. 68, V; CLT, art. 642-A',M==='concorrente'?'Emita a CNDT dela no site do TST no dia da sessão e compare com a apresentada.':'Peça a CNDT emitida no dia (TST).');
 else if(tt)add('fis','ok','Processos trabalhistas sem execução',tt+' processo(s) trabalhista(s) em andamento, nenhum em fase de execução encontrado.','Lei 14.133, art. 68, V');
 if(X.pt.execucao_fiscal)add('fis','am','Execuções fiscais: '+X.pt.execucao_fiscal,'Há cobranças judiciais de tributos (podem ser estaduais ou municipais, que não aparecem na PGFN).','Lei 14.133, art. 68, III',M==='concorrente'?'Confira as certidões estadual e municipal que ela apresentou.':'Peça também as certidões estadual e municipal.');
 /* Econômico-financeira (art. 69) */
 if(X.pt.falencia)add('eco','am','Falência ou recuperação judicial',X.pt.falencia+' processo(s). O edital exige certidão negativa de falência. A recuperação judicial, por si só, não impede a participação, mas a empresa precisa demonstrar capacidade econômica.','Lei 14.133, art. 69, II','Descubra se é falência (impede) ou recuperação (cabe análise do plano e dos balanços).');
 if(cap>0){var lim=cap*10;
  if(V>0){var pct=cap/V;add('eco',pct>=0.1?'ok':'vm','Capital social de '+mc(cap)+(pct>=0.1?' cobre a exigência máxima':' abaixo de 10% do valor'),pct>=0.1?'O edital pode exigir capital ou patrimônio líquido mínimo de até 10% do valor estimado ('+mc(V*0.1)+'). O capital registrado é suficiente.':'Se o edital exigir capital ou patrimônio líquido mínimo de 10% ('+mc(V*0.1)+'), o capital registrado não basta. Ela ainda pode cumprir pelo patrimônio líquido do balanço, se o edital aceitar.','Lei 14.133, art. 69, §4º',pct>=0.1?'':(M==='concorrente'?'Confira no edital qual exigência foi feita e o balanço que ela apresentou.':'Peça o balanço: o patrimônio líquido pode ser maior que o capital.'))}
  else add('eco','na','Capital social de '+mc(cap),'Em editais que exigem capital ou patrimônio líquido mínimo de 10%, este capital cobre licitações de até '+mc(lim)+'. Informe o valor estimado acima para checar uma licitação específica.','Lei 14.133, art. 69, §4º');
 }
 var anos=c.data_inicio_atividade?(Date.now()-new Date(c.data_inicio_atividade))/31557600000:null;
 if(anos!=null&&anos<1)add('eco','am','Empresa aberta há menos de 1 ano','Ainda não tem dois balanços. Empresa criada no exercício da licitação pode apresentar o balanço de abertura no lugar das demonstrações.','Lei 14.133, art. 65, §1º, e art. 69, I','Confira se o balanço de abertura foi registrado.');
 else if(anos!=null&&anos<2)add('eco','na','Empresa com menos de 2 anos','Pode ter só um exercício encerrado. O edital costuma pedir os balanços dos 2 últimos exercícios.','Lei 14.133, art. 69, I');
 /* Técnica (art. 67) */
 var tk=norm(ST.obj).split(/[^a-z0-9]+/).filter(function(w){return w.length>3}),sim=tk.length?X.cons.filter(function(x){var o=norm(x.obj);return tk.filter(function(w){return o.indexOf(w)>=0}).length>=Math.min(2,tk.length)}):X.cons;
 sim.sort(function(a,b){return b.valor-a.valor});
 if(sim.length){var mx=sim[0];add('tec','ok',(tk.length?'Experiência no objeto: ':'Maior contrato público: ')+mc(mx.valor),(tk.length?sim.length+' contrato(s) público(s) com objeto parecido. ':'')+'Maior: '+e(String(mx.obj).slice(0,140))+' — '+e(mx.org)+'. O edital pode pedir atestado de até 50% do quantitativo das parcelas de maior relevância; contratos assim sustentam, em geral, licitações de até cerca de '+mc(mx.valor*2)+' no mesmo objeto.','Lei 14.133, art. 67, §2º',M==='concorrente'?'Confira se os atestados dela são deste objeto e se somam o quantitativo pedido.':'Peça os atestados de capacidade técnica desses contratos.')}
 else add('tec','am',tk.length?'Sem contrato público com esse objeto':'Sem contratos públicos encontrados',tk.length?'Não achamos contratos no PNCP ou no governo federal com esse objeto. Ela vai depender de atestados de clientes privados.':'Nada no PNCP nem no Portal da Transparência. Ela vai depender de atestados de clientes privados.','Lei 14.133, art. 67, II',M==='concorrente'?'Peça diligência sobre os atestados apresentados (art. 64): atestado de cliente privado também precisa comprovar o quantitativo.':'Peça os atestados e as notas fiscais que os comprovam.');
 var vV=X.vig.reduce(function(a,x){return a+x.valor},0);
 if(vV>0&&cap>0&&vV>cap*20)add('tec','am','Carteira ativa grande para o porte',X.vig.length+' contrato(s) vigente(s) somando '+mc(vV)+', '+Math.round(vV/cap)+' vezes o capital social. Pode faltar caixa e equipe para mais um contrato.','Análise de capacidade operacional',M==='concorrente'?'Use na análise de exequibilidade da proposta dela.':'Peça a relação de contratos em execução e o último balanço.');
 /* Benefícios de ME/EPP */
 var porte=norm(c.porte||c.descricao_porte||''),mpe=/micro|pequeno|^me$|^epp$/.test(porte)||c.opcao_pelo_simples===true||c.opcao_pelo_mei===true;
 if(mpe)add('mpe','na','Microempresa ou empresa de pequeno porte','Tem prazo extra para regularizar certidões fiscais (art. 43, §1º) e direito ao empate ficto (art. 44) da LC 123. Não tem esses benefícios em itens de valor acima do teto da EPP (R$ 4,8 milhões)'+(V>4.8e6?': esta licitação passa do teto':'')+'.','LC 123/2006, arts. 43 e 44; Lei 14.133, art. 4º, §1º',M==='concorrente'?'Se ela usou o benefício num item acima do teto, cabe recurso.':'');
 return out;
}
function veredito(L){var M=ST.modo,vm=L.filter(function(x){return x.s==='vm'}),am=L.filter(function(x){return x.s==='am'}),impVm=L.filter(function(x){return x.g==='imp'&&x.s==='vm'});
 if(M==='concorrente'){var n=vm.length+am.length;return n?[vm.length?'ok':'am',vm.length?vm.length+' ponto(s) forte(s) para pedir a inabilitação':'Só pontos para diligência',(vm.length?'Há fundamento para questionar a habilitação desta empresa. ':'Não há impedimento claro, mas há pontos para pedir diligência. ')+n+' ponto(s) listados abaixo, com a base legal de cada um.']:['vm','Sem pontos fracos nos dados públicos','Pelos dados públicos, a empresa tende a ser habilitada. O caminho é a análise da proposta e dos documentos que ela apresentar.']}
 if(impVm.length)return['vm','Risco de impedimento','Há registro que pode impedir a empresa de participar ou de ser contratada. Resolva os pontos em vermelho antes de seguir.'];
 if(vm.length)return['vm','Risco de inabilitação',vm.length+' ponto(s) podem derrubar a habilitação. Peça os documentos indicados antes de assumir compromisso.'];
 if(am.length)return['am','Apta, com ressalvas','Nada impede a participação pelos dados públicos, mas '+am.length+' ponto(s) pedem conferência.'];
 return['ok','Apta pelos dados públicos','Nenhum ponto de impedimento ou de inabilitação encontrado nas fontes oficiais.']}
var GR={imp:'Pode disputar? (impedimentos e sanções)',fis:'Regularidade fiscal e trabalhista',eco:'Qualificação econômico-financeira',tec:'Qualificação técnica e capacidade',mpe:'Tratamento de ME/EPP'};
var MODOS=[['parceiro','Parceiro de consórcio'],['sub','Subcontratada'],['concorrente','Concorrente']];
function docs(L){var M=ST.modo;if(M==='concorrente')return'<div class="docs"><b>Como usar no certame</b><p class="it">Na habilitação, peça diligência ao agente de contratação sobre os pontos acima (Lei 14.133, art. 64).</p><p class="it">Se ela for habilitada mesmo assim, manifeste a intenção de recorrer na sessão e apresente o recurso em 3 dias úteis (art. 165, I e §1º).</p><p class="it">Junte as certidões e os prints das fontes, com data: a prova tem de ser do dia da sessão.</p></div>';
 var b=['Certidão Conjunta Receita/PGFN, CRF do FGTS e CNDT emitidos no dia','Certidões estadual e municipal do domicílio','Balanços dos 2 últimos exercícios e certidão negativa de falência','Atestados de capacidade técnica com as notas fiscais correspondentes','Contrato social consolidado e procuração de quem assina'];
 if(M==='parceiro')b.push('Minuta do compromisso de consórcio: os consorciados respondem solidariamente pelos atos do consórcio (art. 15, V)');
 if(M==='sub')b.push('Comprovação de capacidade técnica da subcontratada para a parcela que vai executar (art. 122)');
 return'<div class="docs"><b>Documentos para pedir antes de fechar</b>'+b.map(function(x){return'<p class="it">'+e(x)+'</p>'}).join('')+'</div>'}
function pinta(){var s=document.getElementById('dv-par'),d=window.DVDS;if(!s||!d)return;var L=itens(d),v=veredito(L),M=ST.modo;
 var extra=M==='parceiro'?'Em consórcio, as dívidas e os processos dela passam a ser um risco seu: a responsabilidade é solidária (art. 15, V). Para a qualificação econômico-financeira, os valores das consorciadas se somam, e o edital pode pedir de 10% a 30% a mais do que pediria de uma empresa sozinha (art. 15, III e §1º).':M==='sub'?'Na subcontratação, quem responde perante o órgão continua sendo você. Cheque principalmente a regularidade trabalhista: passivo da subcontratada pode respingar no contrato.':'Cada ponto abaixo traz o fundamento para usar em diligência, impugnação ou recurso.';
 var H='<h3>Parecer de habilitação</h3><p class="sub">Leitura dos dados deste dossiê à luz da Lei 14.133/2021. Escolha para que você está avaliando a empresa.</p>';
 H+='<div class="modos" role="group" aria-label="Finalidade da análise">'+MODOS.map(function(m){return'<button type="button" data-m="'+m[0]+'" aria-pressed="'+(M===m[0])+'">'+m[1]+'</button>'}).join('')+'</div>';
 H+='<div class="ent"><div><label for="dv-pv">Valor estimado da licitação</label><input id="dv-pv" inputmode="decimal" placeholder="Ex.: 2.500.000" value="'+(ST.valor?e(ST.valor.toLocaleString('pt-BR')):'')+'"></div><div><label for="dv-po">Objeto (palavras-chave)</label><input id="dv-po" placeholder="Ex.: limpeza hospitalar" value="'+e(ST.obj)+'"></div></div>';
 H+='<div class="ver '+v[0]+'"><div><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p><p style="margin-top:6px">'+e(extra)+'</p></div></div>';
 function grupos(arr){var gs={},h='';arr.forEach(function(x){(gs[x.g]=gs[x.g]||[]).push(x)});
  Object.keys(GR).forEach(function(g){if(!gs[g])return;var a=gs[g].slice().sort(function(x,y){var o={vm:0,am:1,na:2,ok:3};return o[x.s]-o[y.s]});
   h+='<h4 class="grp">'+GR[g]+'</h4><ol>'+a.map(function(x){return'<li class="'+x.s+'"><h4>'+e(x.t)+'</h4><p>'+e(x.a)+'</p>'+(x.f?'<div class="fu">'+e(x.f)+'</div>':'')+(x.ac?'<div class="ac">'+e(x.ac)+'</div>':'')+'</li>'}).join('')+'</ol>'});return h}
 var Pn=L.filter(function(x){return x.s==='vm'||x.s==='am'}),Qn=L.filter(function(x){return x.s!=='vm'&&x.s!=='am'});
 H+=Pn.length?'<h4 class="grp" style="color:#16191E;font-size:.95rem">'+(M==='concorrente'?'Pontos fracos dela':'Pontos que pedem atenção')+' ('+Pn.length+')</h4>'+grupos(Pn):'<p class="sub">Nenhum ponto de atenção nos dados públicos.</p>';
 if(Qn.length)H+='<details class="okd"><summary>Itens sem pendência ('+Qn.length+')</summary>'+grupos(Qn)+'</details>';
 H+=docs(L);
 if((d.parcial||[]).length)H+='<p class="nota"><b>Atenção:</b> estas fontes não responderam a tempo e ficaram fora do parecer: '+e(d.parcial.join(', '))+'.</p>';
 H+='<p class="nota">Parecer automático, feito com dados públicos na data do relatório. Certidões mudam: confira todas no dia da sessão. Para um parecer assinado por advogado, use “Acionar um consultor”.</p>';
 s.innerHTML=H;
 s.querySelectorAll('[data-m]').forEach(function(b){b.onclick=function(){ST.modo=b.getAttribute('data-m');pinta()}});
 var pv=s.querySelector('#dv-pv'),po=s.querySelector('#dv-po'),tm;
 pv.onchange=function(){ST.valor=num(pv.value);pinta()};po.onchange=function(){ST.obj=po.value;pinta()};
 var rr=document.querySelector('.rp.dv-org');if(rr&&document.getElementById('dv-capa'))capa(rr,d);
}

/* 3. Leitura guiada: capa com veredito, mapa de temas e seções agrupadas */
var TEMAS=[
 ['hab','Pode ser contratada?','Parecer de habilitação pela Lei 14.133',null],
 ['san','Sanções e punições','Cadastros de punidos e processos administrativos',/san[cç][oõ]es e restri|processos administrativos/i],
 ['fin','Saúde financeira','Dívida ativa e histórico de pagamento',/d[ií]vida ativa|adimpl/i],
 ['gov','Experiência com o governo','Contratos públicos já assinados',/contratos p[uú]blicos|contratos e pagamentos|pagamentos federais|recebimentos/i],
 ['pes','Quem está por trás','Cadastro, matriz e filiais, sócios e empresas ligadas',/cadastro na receita|s[oó]cios e admin|grupo econ/i],
 ['rep','Justiça e reputação','Processos, diários oficiais e imprensa',/processos judiciais|di[aá]rios oficiais|imprensa|publica[cç][oõ]es/i]
];
function statusTemas(d){var X=dados(d),p=X.p,pt=X.pt,o={};
 o.san=X.sanc.length||X.ls?['vm',X.sanc.length?X.sanc.length+' registro(s) em cadastros de sanção':'Aparece na lista suja do trabalho escravo']:['ok','Nada consta nos cadastros oficiais'];
 o.fin=p&&p.total>0?[(+p.em_cobranca>0||+p.fgts>0)?'vm':'am',mc(p.total)+' em dívida ativa da União']:['ok','Sem dívida ativa da União'];
 var vV=X.vig.reduce(function(a,x){return a+x.valor},0);
 o.gov=X.cons.length?['ok',X.cons.length+' contrato(s) público(s)'+(X.vig.length?', '+X.vig.length+' ativo(s) ('+mc(vV)+')':'')]:['am','Nenhum contrato público encontrado'];
 var novos=(X.c.qsa||[]).filter(function(s){return s.data_entrada_sociedade&&(Date.now()-new Date(s.data_entrada_sociedade))/31557600000<2}).length;
 o.pes=X.pep.length?['am',X.pep.length+' sócio(s) politicamente exposto(s)']:novos?['am',novos+' sócio(s) entrou(aram) há menos de 2 anos']:['ok',(X.c.qsa||[]).length+' sócio(s) · '+((d.unidades||[]).length||1)+' unidade(s) · '+X.grupo.length+' empresa(s) ligada(s)'];
 var grav=(+pt.criminal||0)+(+pt.improbidade||0)+(+pt.falencia||0),tx=+pt.trab_execucao||0;
 o.rep=grav?['vm',grav+' processo(s) grave(s) (criminal, improbidade ou falência)']:tx?['am',tx+' execução(ões) trabalhista(s)']:['ok','Nenhum processo grave encontrado'];
 var L=itens(d),v=veredito(L);o.hab=[v[0]==='ok'?'ok':v[0],v[1]];o._v=v;o._L=L;return o}
function acoes(L){var M=ST.modo;if(M==='concorrente'){var f=L.filter(function(x){return x.s==='vm'||x.s==='am'}).sort(function(x,y){return(x.s==='vm'?0:1)-(y.s==='vm'?0:1)});if(f.length){var r=f.slice(0,2).map(function(x){return'Na sessão, peça diligência sobre: '+x.t.toLowerCase()+' ('+x.f+').'});r.push('Se ela for habilitada, registre a intenção de recorrer e apresente o recurso em 3 dias úteis (art. 165).');return r}}var a=L.filter(function(x){return(x.s==='vm'||x.s==='am')&&x.ac}).sort(function(x,y){return(x.s==='vm'?0:1)-(y.s==='vm'?0:1)}).slice(0,3).map(function(x){return x.ac});
 if(!a.length)a=M==='concorrente'?['Analise a proposta e a exequibilidade do preço dela: na habilitação, os dados públicos não mostram ponto fraco.']:['Peça as certidões do dia da sessão (federal, FGTS e trabalhista).','Peça os atestados de capacidade técnica dos contratos listados.'];
 return a}
function capa(r,d){var o=statusTemas(d),v=o._v,X=dados(d),p=X.p,c=X.c,vV=X.vig.reduce(function(a,x){return a+x.valor},0),pt=X.pt;
 var proc=(+pt.criminal||0)+(+pt.improbidade||0)+(+pt.falencia||0)+(+pt.coletiva||0);
 var UU=d.unidades||[],pT=UU.length?UU.reduce(function(a,u){return a+(+u.pgfn||0)},0):(p&&p.total>0?p.total:0);
 var K=[[X.sanc.length?X.sanc.length:'Nada consta','Sanções',X.sanc.length?'vm':''],[pT>0?mc(pT):'Nada consta','Dívida ativa da União'+(UU.length>1?' (todas as unidades)':''),pT>0?'am':''],[X.vig.length+' · '+mc(vV),'Contratos públicos ativos',''],[proc||'Nenhum','Processos graves',proc?'vm':''],[(d.unidades||[]).length?(d.unidades.length+' ('+d.unidades.filter(function(u){return!u.matriz}).length+' filiais)'):'1 (só matriz)','Unidades ativas','']];
 var M=MODOS.filter(function(m){return m[0]===ST.modo})[0][1];
 var H='<div class="q"><div class="v '+v[0]+'"><small>Conclusão · avaliando como '+e(M.toLowerCase())+'</small><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p></div><div class="acoes"><b>O que fazer agora</b><ol>'+acoes(o._L).map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ol></div></div>';
 H+='<div class="kpi">'+K.map(function(k){return'<div class="'+k[2]+'"><b>'+e(k[0])+'</b><span>'+e(k[1])+'</span></div>'}).join('')+'</div>';
 if(d.consultado&&d.consultado!==d.cnpj)H='<p style="margin:0 0 12px;padding:10px 14px;border-radius:10px;background:#F5F1E8;font-size:.9rem">Você informou a filial '+fcnpj(d.consultado)+'. O dossiê foi feito pela matriz ('+fcnpj(d.cnpj)+') e cobre todas as '+((d.unidades||[]).length||1)+' unidades da empresa.</p>'+H;
 var cp=document.getElementById('dv-capa');cp.innerHTML=H;
 var nv=r.querySelector('.rp-hd .nv');if(nv){nv.className='nv '+v[0];nv.innerHTML='<b>'+e(v[1])+'</b><span>Parecer de habilitação</span>'}
 document.getElementById('dv-mapa').innerHTML='<p>Os seis temas do relatório. Clique para ir direto ao detalhe.</p><nav>'+TEMAS.map(function(t){var s=o[t[0]]||['na',''];return'<a href="#dv-t-'+t[0]+'" class="'+s[0]+'" data-t="'+t[0]+'"><b>'+e(t[1])+'</b><span>'+e(s[1])+'</span></a>'}).join('')+'</nav>';
 document.querySelectorAll('#dv-mapa a').forEach(function(a){a.onclick=function(ev){var dt=document.getElementById('dv-t-'+a.dataset.t);if(dt){ev.preventDefault();dt.open=true;dt.scrollIntoView({behavior:'smooth',block:'start'})}}});
 TEMAS.forEach(function(t){var dt=document.getElementById('dv-t-'+t[0]);if(!dt)return;var s=o[t[0]]||['na',''];dt.className='dv-tema '+s[0];dt.querySelector('summary span').textContent=s[1]});
}

function fcnpj(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function secUnidades(d){var U=d.unidades||[];var s=document.createElement('section');s.className='rp-sec';s.id='dv-uni';
 var fil=U.filter(function(u){return!u.matriz}),ufs={};U.forEach(function(u){ufs[u.uf||'—']=(ufs[u.uf||'—']||0)+1});
 var comD=U.filter(function(u){return u.pgfn>0});
 var H='<h3>Matriz e filiais</h3>';
 if(!U.length){H+='<p>A empresa não tem filiais ativas no cadastro da Receita: o relatório cobre a única unidade, o CNPJ '+fcnpj(d.cnpj)+'.</p>'}
 else{H+='<p>'+(U.length)+' unidade(s) ativa(s): a matriz e '+fil.length+' filial(is), em '+Object.keys(ufs).length+' estado(s) ('+Object.keys(ufs).sort().map(function(k){return k+' '+ufs[k]}).join(', ')+'). Os contratos públicos acima já somam as filiais. A dívida ativa da União é inscrita por CNPJ, por isso aparece unidade por unidade.</p>';
  H+='<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.9rem"><thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E3DED2">CNPJ</th><th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E3DED2">Unidade</th><th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E3DED2">UF</th><th style="text-align:right;padding:6px 8px;border-bottom:1px solid #E3DED2">Dívida ativa da União</th></tr></thead><tbody>'+
  U.slice().sort(function(a,b){return(b.matriz-a.matriz)||(b.pgfn-a.pgfn)}).map(function(u){return'<tr><td style="padding:6px 8px;border-bottom:1px solid #F0ECE3">'+fcnpj(u.cnpj)+(u.cnpj===d.consultado?' <b>(consultada)</b>':'')+'</td><td style="padding:6px 8px;border-bottom:1px solid #F0ECE3">'+(u.matriz?'Matriz':'Filial')+'</td><td style="padding:6px 8px;border-bottom:1px solid #F0ECE3">'+e(u.uf)+'</td><td style="padding:6px 8px;border-bottom:1px solid #F0ECE3;text-align:right;'+(u.pgfn>0?'color:#9A3B2C;font-weight:700':'color:#6E6A60')+'">'+(u.pgfn>0?mc(u.pgfn):'Nada consta')+'</td></tr>'}).join('')+'</tbody></table></div>';
  if(comD.length)H+='<p class="mu" style="margin-top:8px">'+comD.length+' unidade(s) com dívida ativa. Na licitação, a regularidade fiscal federal é comprovada pela certidão conjunta, que vale para a matriz e todas as filiais.</p>'}
 s.innerHTML=H;return s}
function organiza(r,d){if(r.dataset.org===String(d.cnpj))return;r.dataset.org=String(d.cnpj);r.classList.add('dv-org');
 var secs=[].slice.call(r.querySelectorAll(':scope > .rp-sec'));
 secs.forEach(function(s){var h=s.querySelector('h3');var t=h?h.textContent.replace(/^\s*[0-9!]+[a-z]?\s*/,''):'';if(/resumo executivo|riscos em destaque/i.test(t))s.style.display='none'});
 [].forEach.call(r.querySelectorAll('.kv>div'),function(x){var b=x.querySelector('b');if(b&&/^(raz[aã]o social|cnpj)$/i.test(b.textContent.trim()))x.style.display='none'});
 var anc=r.querySelector('.ds-parc')||r.querySelector('.rp-bar');
 var cp=document.createElement('section');cp.id='dv-capa';var mp=document.createElement('section');mp.id='dv-mapa';
 anc.parentNode.insertBefore(cp,anc.nextSibling);cp.parentNode.insertBefore(mp,cp.nextSibling);
 var ult=mp,usados=[];
 TEMAS.forEach(function(t,i){var dt=document.createElement('details');dt.id='dv-t-'+t[0];dt.className='dv-tema';if(i===0)dt.open=true;
  dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>'+e(t[1])+'</b><span>'+e(t[2])+'</span></div></summary>';
  if(t[0]==='hab'){var pa=document.getElementById('dv-par');if(pa)dt.appendChild(pa)}
  else secs.forEach(function(s){if(usados.indexOf(s)>=0||s.style.display==='none')return;var h=s.querySelector('h3');if(h&&t[3].test(h.textContent)){dt.appendChild(s);usados.push(s)}});
  if(t[0]==='pes')dt.appendChild(secUnidades(d));
  ult.parentNode.insertBefore(dt,ult.nextSibling);ult=dt});
 var resto=secs.filter(function(s){return usados.indexOf(s)<0&&s.style.display!=='none'});
 if(resto.length){var dt=document.createElement('details');dt.id='dv-t-out';dt.className='dv-tema';dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>Outras informações</b><span>Dados complementares</span></div></summary>';resto.forEach(function(s){dt.appendChild(s)});ult.parentNode.insertBefore(dt,ult.nextSibling)}
 capa(r,d)}
window.addEventListener('beforeprint',function(){document.querySelectorAll('.dv-tema').forEach(function(x){x.open=true})});
function monta(){var r=document.querySelector('.dv-tool .rp')||document.querySelector('.rp');if(!r||!window.DVDS)return;var s=document.getElementById('dv-par');
 if(s&&r.contains(s)&&s.dataset.c===String(window.DVDS.cnpj))return;if(s)s.remove();s=document.createElement('section');s.id='dv-par';s.dataset.c=String(window.DVDS.cnpj);
 var bar=r.querySelector('.rp-bar'),ap=r.querySelector('.ds-parc');var ref=(ap||bar);if(ref&&ref.nextSibling)r.insertBefore(s,ref.nextSibling);else r.insertBefore(s,r.firstChild);pinta();organiza(r,window.DVDS)}
new MutationObserver(function(){monta()}).observe(document.body,{childList:true,subtree:true});
})();
</script>
<?php endif; if ( $cp ) : ?>
<script>
(function(){
/* Raio-X do comprador: capa "vale a pena vender?", mapa de temas e seções agrupadas */
var of=window.fetch;window.fetch=function(u,o){var pr=of.apply(this,arguments);if(/\/dvtools\/v1\/comprador\?/.test(String(u))){pr.then(function(r){r.clone().json().then(function(j){if(j&&j.cadastro){window.DVCP=j;tenta(0)}}).catch(function(){})}).catch(function(){})}return pr};
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bi':v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function br(d){var m=String(d||'').match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''}
function nrm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase()}
function casa(t,tk){if(!tk.length)return true;t=nrm(t);var h=0;tk.forEach(function(k){if(t.indexOf(k)>=0)h++});return h>=Math.max(1,Math.ceil(tk.length*.6))}
function analisa(d){var f=d.fiscal||{},tk=d.tokens||[],agora=new Date().toISOString().slice(0,16),hoje=agora.slice(0,10);
 var ct=(d.contratos||{}).itens||[],ed=(d.editais||{}).itens||[],J=d.justica||{},nt=d.noticias||[];
 var ativos=ct.filter(function(x){return x.fim&&x.fim.slice(0,10)>=hoje}),abertos=ed.filter(function(x){return x.fim&&x.fim>=agora});
 var ctI=ct.filter(function(x){return casa(x.objeto,tk)}),atI=ctI.filter(function(x){return x.fim&&x.fim.slice(0,10)>=hoje}).sort(function(a,b){return a.fim.localeCompare(b.fim)}),abI=abertos.filter(function(x){return casa(x.objeto,tk)}).sort(function(a,b){return String(a.fim).localeCompare(String(b.fim))});
 var anos=Object.keys(f.rp||{}).sort().reverse(),rp=anos.length?f.rp[anos[0]]:null,pts=100;
 if(rp&&rp.proc_pct_pago!=null){var pp=rp.proc_pct_pago;pts-=pp<50?30:pp<75?15:pp<90?5:0}
 var sp=rp&&rp.proc_saldo!=null&&f.rcl?rp.proc_saldo/f.rcl:null;if(sp!=null)pts-=sp>.10?20:sp>.05?10:sp>.02?5:0;
 if(f.pessoal_pct!=null){if(f.pessoal_max&&f.pessoal_pct>f.pessoal_max)pts-=20;else if(f.pessoal_prud&&f.pessoal_pct>f.pessoal_prud)pts-=10}
 if(f.caixa_liquida!=null&&f.caixa_liquida<0)pts-=15;
 var cob=(J.cobranca||0)+(J.monitoria||0)+(J.execucao||0);pts-=cob>50?10:cob>10?5:0;pts-=nt.length>=3?10:nt.length?5:0;
 var semDados=!rp&&f.pessoal_pct==null;pts=Math.max(0,Math.round(pts));
 var forn={},tot=0;ct.forEach(function(x){var k=x.fornecedor||'';if(!k)return;forn[k]=(forn[k]||0)+(x.valor||0);tot+=x.valor||0});
 var top=Object.keys(forn).map(function(k){return[k,forn[k]]}).sort(function(a,b){return b[1]-a[1]}),top3=tot?top.slice(0,3).reduce(function(a,x){return a+x[1]},0)/tot:0;
 var PL=d.proc_lic||[],plG=PL.filter(function(x){return x.grave}),POL=d.politicos||{},PG=d.gestor_proc||[],NW=d.news||{};
 var semCt=!ct.length&&(((d.contratos||{}).falhas||0)>0||!d.contratos);
 return{semCt:semCt,parcial:d.parcial||[],pts:pts,semDados:semDados,rp:rp,ano:anos[0],sp:sp,ct:ct,ativos:ativos,abertos:abertos,abI:abI,atI:atI,ctI:ctI,top:top,top3:top3,tot:tot,PL:PL,plG:plG,POL:POL,PG:PG,NW:NW,item:d.item||'',cob:cob,nt:nt}}
function veredito(A){var pag=A.semDados?'am':A.pts>=80?'ok':A.pts>=55?'am':'vm';
 if(pag==='vm')return['vm','Evite ou proteja o preço','Os dados fiscais mostram risco alto de atraso nos pagamentos (nota '+A.pts+'/100). Se for vender, ponha no preço o custo de receber com atraso e não comprometa capital de giro alto.'];
 if(pag==='am')return['am','Venda com cuidado',A.semDados?'O ente não publicou os demonstrativos fiscais que usamos para medir se ele paga em dia. Confirme a reputação de pagamento com outros fornecedores antes de investir na disputa.':'Há sinais de atraso nos pagamentos (nota '+A.pts+'/100). Vale vender, mas acompanhe os prazos de pagamento de perto.'];
 return['ok','Bom pagador: vale disputar','Os dados fiscais indicam que o órgão paga em dia (nota '+A.pts+'/100).'+(A.top3>.6?' Atenção: o mercado é concentrado.':'')]}
function acoes(A){var a=[];
 if(A.abI.length)a.push((A.item?'Há '+A.abI.length+' licitação(ões) aberta(s) para '+A.item:'Há '+A.abI.length+' licitação(ões) aberta(s)')+'. A primeira recebe propostas até '+br(A.abI[0].fim)+'.');
 else if(A.atI.length)a.push('O contrato atual'+(A.item?' de '+A.item:'')+' ('+A.atI[0].fornecedor+') vence em '+br(A.atI[0].fim)+'. Comece a se preparar uns 90 dias antes e acompanhe o plano de contratações do órgão.');
 else if(A.item&&!A.semCt)a.push('Não achamos contrato nem licitação aberta para '+A.item+'. Crie um alerta para ser avisado quando o órgão publicar.');
 else if(A.item)a.push('Não há licitação aberta para '+A.item+' agora. Os contratos do órgão não puderam ser lidos nesta consulta: gere de novo para saber quando vence o contrato atual.');
 if(A.top3>.6&&A.top.length)a.push('Mercado concentrado: os 3 maiores fornecedores ficam com '+Math.round(A.top3*100)+'% do valor. Entre por itens menores, dispensas ou atas abertas para carona.');
 if(!A.semDados&&A.pts<80)a.push('Ponha no preço o custo de receber com atraso e peça o cronograma de pagamento antes de assinar.');
 if(A.plG.length)a.push('Há '+A.plG.length+' processo(s) grave(s) envolvendo licitações do órgão. Leia antes de investir na disputa.');
 if(a.length<2)a.push('Crie um alerta para este órgão e receba as próximas licitações por e-mail.');
 return a.slice(0,3)}
var TEMAS=[['pag','Paga em dia?','Saúde fiscal, cobranças na Justiça e notícias de atraso',/paga em dia|cobran[cç]as na justi|atraso de pagamento/i],
 ['opo','Oportunidades para você','Licitações abertas e contratos do seu item',/^seu item/i],
 ['pan','Como e de quem compra','Contratos, fornecedores e licitações do órgão',/panorama de compras/i],
 ['gov','Quem governa','Gestão, câmara e movimentos sindicais',/quem governa|sindica/i],
 ['con','Controle e polêmicas','Processos e notícias sobre licitações e o gestor',/na mira/i]];
function status(A){var o={};
 o.pag=A.semDados?['am','Sem dados fiscais publicados']:[A.pts>=80?'ok':A.pts>=55?'am':'vm','Nota '+A.pts+'/100'];
 o.opo=A.abI.length?['ok',A.abI.length+' licitação(ões) aberta(s)'+(A.item?' do seu item':'')]:A.atI.length?['am','Contrato atual vence em '+br(A.atI[0].fim)]:['na',A.item?'Nada aberto para o seu item':'Informe o que você vende'];
 o.pan=A.semCt?['na','Contratos indisponíveis nesta consulta']:A.top3>.6?['am','Mercado concentrado: top 3 com '+Math.round(A.top3*100)+'%']:['ok',A.ativos.length+' contrato(s) ativo(s) · '+A.abertos.length+' licitação(ões) aberta(s)'];
 var T=A.POL.titular;o.gov=T?['na',(A.POL.cargo||'Gestor')+': '+T.urna+(T.partido?' ('+T.partido+')':'')]:['na','Sem dados da gestão'];
 o.con=A.plG.length?['vm',A.plG.length+' processo(s) grave(s) sobre licitações']:A.PL.length||A.PG.length?['am',(A.PL.length+A.PG.length)+' processo(s) a conferir']:['ok','Nada relevante encontrado'];
 return o}
function capa(r,A){var v=veredito(A),o=status(A);
 var K=[[A.semDados?'—':A.pts+'/100','Nota de pagamento',A.semDados?'':A.pts<55?'vm':A.pts<80?'am':''],[A.semCt?'—':A.ativos.length+' · '+mc(A.ativos.reduce(function(a,x){return a+(x.valor||0)},0)),A.semCt?'Contratos (PNCP não respondeu)':'Contratos ativos',''],[A.abertos.length+(A.item?' ('+A.abI.length+' do item)':''),'Licitações abertas',''],[A.top.length?Math.round(A.top3*100)+'%':'—','Valor com os 3 maiores fornecedores',A.top3>.6?'am':''],[A.plG.length||'Nenhum','Processos graves sobre licitações',A.plG.length?'vm':'']];
 var falta=[];if(A.semCt)falta.push('contratos do PNCP');(A.parcial||[]).forEach(function(x){var n={justica:'cobranças na Justiça',noticias:'notícias',processos:'processos sobre licitações',gestor:'processos do gestor',precos:'preços do item'}[x];if(n&&falta.indexOf(n)<0)falta.push(n)});
 var H=(falta.length?'<p style="margin:0 0 12px;padding:10px 14px;border-radius:10px;background:#FFF8E6;border:1px solid #E8C877;font-size:.9rem"><b>Relatório incompleto:</b> estas fontes oficiais não responderam a tempo: '+e(falta.join(', '))+'. A conclusão abaixo não considera essas partes. Gere de novo em alguns minutos para completar.</p>':'')+'<div class="q"><div class="v '+v[0]+'"><small>Vale a pena vender para este órgão?</small><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p></div><div class="acoes"><b>O que fazer agora</b><ol>'+acoes(A).map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ol></div></div>';
 H+='<div class="kpi">'+K.map(function(k){return'<div class="'+k[2]+'"><b>'+e(k[0])+'</b><span>'+e(k[1])+'</span></div>'}).join('')+'</div>';
 var d0=window.DVCP||{},hj=new Date().toISOString().slice(0,10),lim=new Date(Date.now()+183*864e5).toISOString().slice(0,10);
 var vence=A.ct.filter(function(x){return x.fim&&x.fim.slice(0,10)>=hj&&x.fim.slice(0,10)<=lim}).sort(function(a,b){return(b.valor||0)-(a.valor||0)});
 var ab=A.abertos.slice().sort(function(a,b){return String(a.fim).localeCompare(String(b.fim))});
 H+='<div id="dv-cp-capag"></div><div class="dv-agora"><div><h4>Abertas agora ('+ab.length+')</h4>'+(ab.length?'<ul>'+ab.slice(0,5).map(function(x){return'<li><a href="'+e(x.link)+'" target="_blank" rel="noopener">'+e(String(x.objeto||x.titulo).slice(0,110))+'</a><small>'+e(x.modalidade||'')+(x.valor?' · '+mc(x.valor):'')+' · propostas até '+br(x.fim)+'</small></li>'}).join('')+'</ul>':'<p>Nenhuma licitação recebendo propostas agora.</p>')+'</div><div><h4>Contratos que vencem em até 6 meses ('+vence.length+')</h4>'+(vence.length?'<ul>'+vence.slice(0,5).map(function(x){return'<li><a href="'+e(x.link)+'" target="_blank" rel="noopener">'+e(String(x.objeto||x.titulo).slice(0,110))+'</a><small>'+e(String(x.fornecedor||'').slice(0,50))+(x.valor?' · '+mc(x.valor):'')+' · vence '+br(x.fim)+'</small></li>'}).join('')+'</ul><a class="mais" href="/plataforma/contratos-vencendo/?orgao='+e(d0.cnpj||'')+'">Ver todos os contratos vencendo deste órgão</a>':'<p>'+(A.semCt?'Contratos indisponíveis nesta consulta.':'Nenhum contrato vence nos próximos 6 meses.')+'</p>')+'</div></div>';
 document.getElementById('dv-capa').innerHTML=H;
 (function(){var alvo=document.getElementById('dv-cp-capag');var TX={A:['ok','boa capacidade de pagamento'],'A+':['ok','ótima capacidade de pagamento'],B:['ok','capacidade de pagamento razoável'],'B+':['ok','boa capacidade de pagamento'],C:['am','capacidade de pagamento fraca: risco de atraso'],D:['vm','capacidade de pagamento muito fraca: risco alto de atraso']};
  function poe(C){if(!alvo||!C)return;var n=d0.esfera==='M'?C.m[String(d0.id_ente)]:d0.esfera==='E'?C.e[(d0.cadastro||{}).uf]:null;if(!n||!TX[n])return;alvo.innerHTML='<p class="dv-capag '+TX[n][0]+'"><b>CAPAG '+e(n)+'</b> · '+(d0.esfera==='E'?'o estado':'o município')+' tem '+TX[n][1]+' (nota do Tesouro Nacional, de A a D).</p>'}
  if(window.DVCAPAG)poe(window.DVCAPAG);else fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/capag/capag.json').then(function(r){return r.ok?r.json():null}).then(function(j){window.DVCAPAG=j;poe(j)}).catch(function(){})})();
 document.getElementById('dv-mapa').innerHTML='<p>Os cinco temas do relatório. Clique para ir direto ao detalhe.</p><nav>'+TEMAS.map(function(t){var s=o[t[0]]||['na',''];return'<a href="#dv-t-'+t[0]+'" class="'+s[0]+'" data-t="'+t[0]+'"><b>'+e(t[1])+'</b><span>'+e(s[1])+'</span></a>'}).join('')+'</nav>';
 document.querySelectorAll('#dv-mapa a').forEach(function(a){a.onclick=function(ev){var dt=document.getElementById('dv-t-'+a.dataset.t);if(dt){ev.preventDefault();dt.open=true;dt.scrollIntoView({behavior:'smooth',block:'start'})}}});
 TEMAS.forEach(function(t){var dt=document.getElementById('dv-t-'+t[0]);if(!dt)return;var s=o[t[0]]||['na',''];dt.className='dv-tema '+s[0];dt.querySelector('summary span').textContent=s[1]})}
function organiza(r,d){var key=d.cnpj+'|'+(d.item||'');if(r.dataset.org===key)return;r.dataset.org=key;r.classList.add('dv-org');var A=analisa(d);
 var secs=[].slice.call(r.querySelectorAll(':scope > .rp-sec'));
 secs.forEach(function(s){var h=s.querySelector('h3');var t=h?h.textContent:'';if(/^\s*(resumo|riscos em destaque)\s*$/i.test(t.replace(/^!/,'')))s.style.display='none'});
 var anc=r.querySelector('.rp-bar')||r.querySelector('.rp-hd');var cp=document.createElement('section');cp.id='dv-capa';var mp=document.createElement('section');mp.id='dv-mapa';
 anc.parentNode.insertBefore(cp,anc.nextSibling);cp.parentNode.insertBefore(mp,cp.nextSibling);var ult=mp,usados=[];
 TEMAS.forEach(function(t,i){var dt=document.createElement('details');dt.id='dv-t-'+t[0];dt.className='dv-tema';if(i===0)dt.open=true;
  dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>'+e(t[1])+'</b><span>'+e(t[2])+'</span></div></summary>';
  secs.forEach(function(s){if(usados.indexOf(s)>=0||s.style.display==='none')return;var h=s.querySelector('h3');if(h&&t[3].test(h.textContent.trim())){dt.appendChild(s);usados.push(s)}});
  if(dt.querySelector('.rp-sec')){ult.parentNode.insertBefore(dt,ult.nextSibling);ult=dt}});
 var resto=secs.filter(function(s){return usados.indexOf(s)<0&&s.style.display!=='none'});
 if(resto.length){var dt=document.createElement('details');dt.id='dv-t-out';dt.className='dv-tema';dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>Outras informações</b><span>Dados complementares</span></div></summary>';resto.forEach(function(s){dt.appendChild(s)});ult.parentNode.insertBefore(dt,ult.nextSibling)}
 capa(r,A)}
function tenta(n){var r=document.querySelector('.cp2 .rp')||document.querySelector('.rp');if(!r||!window.DVCP){if(n<60)setTimeout(function(){tenta(n+1)},300);return}organiza(r,window.DVCP)}
new MutationObserver(function(){if(window.DVCP){var r=document.querySelector('.cp2 .rp');if(r&&!r.dataset.org)organiza(r,window.DVCP)}}).observe(document.body,{childList:true,subtree:true});
window.addEventListener('beforeprint',function(){document.querySelectorAll('.dv-tema').forEach(function(x){x.open=true})});
})();
</script>
<?php endif; ?>
<?php if ( $rx ) : ?>
<script>
(function(){
/* Raio-X do concorrente: capa "como eu ganho dele?", mapa de temas e seções agrupadas */
var of=window.fetch;window.fetch=function(u,o){var pr=of.apply(this,arguments),U=String(u);
 if(/\/int\/concorrente\?/.test(U))pr.then(function(r){r.clone().json().then(function(j){if(j&&j.resumo){window.DVIC=j;atualiza()}}).catch(function(){})}).catch(function(){});
 if(/\/dvtools\/v1\/fornecedor\?/.test(U))pr.then(function(r){r.clone().json().then(function(j){if(j&&(j.cadastro||j.razao_social)){window.DVFO=j;atualiza()}}).catch(function(){})}).catch(function(){});
 return pr};
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bi':v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function pc(v){return v==null?'—':(+v).toFixed(1).replace('.',',')+'%'}
function fc(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
var TEMAS=[['lan','Como ele dá preço','Descontos, ramos em que atua e onde vence',['dv-ic','dv-pv']],
 ['hab','Pontos fracos na habilitação','Pontos de atenção, sanções, dívida e certidões',['rx-pts','rx-ceis','rx-cnep','rx-tcu','rx-ext','rx-pago','rx-cert']],
 ['gov','Experiência com o governo','Contratos públicos e recebimentos',['rx-ctr']],
 ['pes','Quem é a empresa','Cadastro na Receita',['rx-cad']],
 ['rep','Justiça e imprensa','Processos judiciais e notícias',['dv-s2','dv-s1']]];
function onde(id){for(var i=0;i<TEMAS.length;i++)if(TEMAS[i][3].indexOf(id)>=0)return TEMAS[i][0];return null}
function cnpj(){var m=location.search.match(/cnpj=(\d{14})/);if(m)return m[1];var x=document.querySelector('#dv-rs .id')||document.getElementById('rx-cad');var k=x&&x.textContent.match(/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/);return k?k[0].replace(/\D/g,''):''}
function nome(){var F=window.DVFO||{},c=F.cadastro||F;if(c.razao_social)return c.razao_social;var id=document.querySelector('#dv-rs .id b, #dv-rs .id h2, #dv-rs .id strong');if(id&&id.textContent.trim())return id.textContent.trim();var cad=document.querySelector('#rx-cad h2, #rx-cad h3');return cad?cad.textContent.trim():''}
function analisa(){var R=document.querySelector('.rx-out'),I=window.DVIC||{},rs=I.resumo||{},pf=I.perfil||{},ex=I.experiencia||[];
 var pts=[].slice.call(document.querySelectorAll('#rx-pts li')),forte=pts.filter(function(x){return/n2|n3|vm/.test(x.className)}).length;
 var sanc=['rx-ceis','rx-cnep','rx-tcu'].filter(function(id){var x=document.getElementById(id);return x&&x.textContent.trim().length>40&&!/nenhum registro|n[aã]o consta/i.test(x.textContent)}).length;
 var ufs=Object.keys(pf.uf||{}).map(function(k){return[k,pf.uf[k].n,pf.uf[k].media]}).sort(function(a,b){return b[1]-a[1]});
 var ctr=document.getElementById('rx-ctr'),nC=0;if(ctr){var m=ctr.textContent.match(/(\d[\d.]*)\s*contratos no PNCP/i);if(m)nC=+m[1].replace(/\./g,'')}
 return{vit:+rs.vitorias||0,valor:+rs.valor||0,med:(pf.mediana!=null&&+pf.mediana>0.5)?+pf.mediana:null,semDesc:pf.mediana!=null&&+pf.mediana<=0.5,media:pf.media,nd:+pf.n||0,ufs:ufs,ramo:ex[0]||null,ramos:ex,pts:pts.length,forte:forte,sanc:sanc,nC:nC||(+rs.contratos||0),temI:!!window.DVIC}}
function veredito(A){if(!A.temI)return['am','Analisando o concorrente…','Estamos juntando as vitórias e os descontos dele. Esta conclusão se completa em alguns segundos.'];
 var forca=A.vit>=30||A.valor>=20e6?'forte':A.vit>=5||A.valor>=2e6?'médio':'pequeno';
 var t=forca==='forte'?'Concorrente forte':forca==='médio'?'Concorrente de porte médio':'Concorrente pequeno';
 var d=A.vit+' licitação(ões) vencida(s) no PNCP, somando '+mc(A.valor)+'.'+(A.med!=null?' Dá, em mediana, '+pc(A.med)+' de desconto sobre o valor estimado.':A.semDesc?' Costuma vencer sem desconto relevante.':'');
 if(A.forte||A.sanc)d+=' Tem '+(A.forte+A.sanc)+' ponto(s) fraco(s) na habilitação para explorar.';
 return[forca==='forte'?'vm':forca==='médio'?'am':'ok',t,d]}
function acoes(A){var a=[];
 if(A.med!=null&&A.nd>=3)a.push('Para vencer dele, sua proposta precisa suportar desconto acima de '+pc(A.med)+' (a mediana dele em '+A.nd+' disputas). Confira antes se o seu preço continua exequível.');
 else if(A.semDesc)a.push('Ele costuma vencer sem desconto relevante, o que é típico de contratação direta (dispensa ou inexigibilidade). Mostre ao órgão que há concorrência: um orçamento seu pode levar o próximo processo para pregão.');
 else if(A.temI)a.push('Há poucas disputas com desconto conhecido. Acompanhe as próximas sessões dele para calibrar o seu lance.');
 if(A.ufs.length){var top=A.ufs.slice(0,2).map(function(u){return u[0]}).join(' e ');a.push('Ele concentra as vitórias em '+top+'. Em outros estados a concorrência com ele tende a ser menor.')}
 if(A.forte||A.sanc)a.push('Use os pontos fracos dele na habilitação (veja o tema): peça diligência na sessão (Lei 14.133, art. 64) e, se for o caso, recorra (art. 165).');
 else a.push('Na habilitação, os dados públicos não mostram ponto fraco dele: a disputa vai ser no preço e nos atestados.');
 return a.slice(0,3)}
function status(A){var o={};
 o.lan=A.med!=null?['am','Desconto mediano de '+pc(A.med)+(A.ramo?' · forte em '+A.ramo.ramo:'')]:['na',A.semDesc?'Vence sem desconto relevante'+(A.ramo?' · forte em '+A.ramo.ramo:''):A.temI?'Sem desconto conhecido':'Carregando…'];
 o.hab=A.forte||A.sanc?['ok',(A.forte+A.sanc)+' ponto(s) fraco(s) para explorar']:A.pts?['am',A.pts+' ponto(s) de atenção leve(s)']:['na','Sem ponto fraco nos dados públicos'];
 o.gov=['na',A.nC?A.nC+' contrato(s) no PNCP':'Contratos públicos'];
 o.pes=['na','Cadastro na Receita'];o.rep=['na','Processos e notícias'];return o}
function capa(){var A=analisa(),v=veredito(A),o=status(A),cp=document.getElementById('dv-capa');if(!cp)return;
 var K=[[A.vit||'—','Licitações vencidas',''],[A.valor?mc(A.valor):'—','Valor ganho',''],[A.med!=null?pc(A.med):A.semDesc?'≈ 0%':'—','Desconto mediano',''],[A.nC||'—','Contratos no PNCP',''],[A.forte+A.sanc||'Nenhum','Pontos fracos na habilitação',A.forte+A.sanc?'am':'']];
 var H='<div class="q"><div class="v '+v[0]+'"><small>Como eu ganho dele?</small><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p></div><div class="acoes"><b>O que fazer agora</b><ol>'+acoes(A).map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ol></div></div>';
 H+='<div class="kpi">'+K.map(function(k){return'<div class="'+k[2]+'"><b>'+e(k[0])+'</b><span>'+e(k[1])+'</span></div>'}).join('')+'</div>';
 cp.innerHTML=H+(cnpj()?'<p style="margin:10px 0 0"><a href="/plataforma/habilitacao-concorrente/?cnpj='+cnpj()+'" style="font-weight:700;color:#8A6A2E">Ver as licitações dele com os documentos de habilitação</a></p>':'');
 document.getElementById('dv-mapa').innerHTML='<p>Os cinco temas do relatório. Clique para ir direto ao detalhe.</p><nav>'+TEMAS.map(function(t){var s=o[t[0]]||['na',''];return'<a href="#dv-t-'+t[0]+'" class="'+s[0]+'" data-t="'+t[0]+'"><b>'+e(t[1])+'</b><span>'+e(s[1])+'</span></a>'}).join('')+'</nav>';
 document.querySelectorAll('#dv-mapa a').forEach(function(a){a.onclick=function(ev){var dt=document.getElementById('dv-t-'+a.dataset.t);if(dt){ev.preventDefault();dt.open=true;dt.scrollIntoView({behavior:'smooth',block:'start'})}}});
 TEMAS.forEach(function(t){var dt=document.getElementById('dv-t-'+t[0]);if(!dt)return;var s=o[t[0]]||['na',''];dt.className='dv-tema '+s[0];dt.querySelector('summary span').textContent=s[1]});
 var R=document.querySelector('.rx-out');if(R){R.dataset.nome=nome();R.dataset.sub=cnpj()?'CNPJ '+fc(cnpj()):'';R.dataset.tipo='Raio-X do concorrente';var hd=document.getElementById('dv-rxhd');if(hd)hd.innerHTML='<small>RAIO-X DO CONCORRENTE</small><h2>'+e(R.dataset.nome)+'</h2><p>'+e(R.dataset.sub)+'</p>'}}
function encaixa(R){[].slice.call(R.children).forEach(function(ch){if(!ch.id||ch.classList.contains('dv-tema'))return;var t=onde(ch.id);if(t){var dt=document.getElementById('dv-t-'+t);if(dt&&ch.parentNode!==dt)dt.appendChild(ch)}})}
function organiza(){var R=document.querySelector('.rx-out');if(!R||!document.getElementById('rx-cad'))return;
 if(!R.dataset.org){R.dataset.org='1';R.classList.add('dv-org','rp');
  [].forEach.call(R.querySelectorAll(':scope > .dv-rnav, :scope > #dv-rs'),function(x){x.style.display='none'});
  var hd=document.createElement('div');hd.id='dv-rxhd';hd.className='rp-hd';var cp=document.createElement('section');cp.id='dv-capa';var mp=document.createElement('section');mp.id='dv-mapa';
  var top=R.querySelector(':scope > .dv-pdf-top');var ref=top?top.nextSibling:R.firstChild;R.insertBefore(hd,ref);R.insertBefore(cp,hd.nextSibling);R.insertBefore(mp,cp.nextSibling);
  var ult=mp;TEMAS.forEach(function(t,i){var dt=document.createElement('details');dt.id='dv-t-'+t[0];dt.className='dv-tema';if(i===0)dt.open=true;dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>'+e(t[1])+'</b><span>'+e(t[2])+'</span></div></summary>';R.insertBefore(dt,ult.nextSibling);ult=dt});
  var cad=document.getElementById('rx-cad');if(cad){var h=cad.querySelector('h2,h3,b');}
 }
 encaixa(R);capa()}
var tm=0;function atualiza(){clearTimeout(tm);tm=setTimeout(organiza,150)}
new MutationObserver(atualiza).observe(document.body,{childList:true,subtree:false});
var R0;setInterval(function(){var R=document.querySelector('.rx-out');if(R&&R!==R0){R0=R;new MutationObserver(atualiza).observe(R,{childList:true})}},700);
})();
</script>
<style>
#dv-rxhd{background:#16191E;color:#F3EFE6;padding:22px 30px;border-radius:14px 14px 0 0}#dv-rxhd small{display:block;font:700 .72rem "Hanken Grotesk",sans-serif;letter-spacing:.14em;color:#C9A45C;margin-bottom:6px}#dv-rxhd h2{margin:0;font:600 1.6rem "Fraunces",Georgia,serif;color:#fff}#dv-rxhd p{margin:4px 0 0;color:#B9BDB8;font-size:.9rem}
.rx-out.dv-org .dv-tema>.rx-card,.rx-out.dv-org .dv-tema>section,.rx-out.dv-org .dv-tema>div{margin:0 30px 14px}
.rx-out.dv-org #rx-cad h2,.rx-out.dv-org #rx-cad h3{display:none}
</style>
<?php endif; ?>


<script>
/* Painel judicial em uma página: processos por tipo, tendência e tribunais (Dossiê e Raio-X do concorrente) */
(function(){
var CAT=[['criminal','Penal / criminal','#9A3B2C'],['improbidade','Improbidade administrativa','#B3402A'],['coletiva','Ação civil pública / coletiva','#C2513D'],['falencia','Falência / recuperação judicial','#8A6516'],['execucao_fiscal','Execução fiscal (tributos)','#A57C2C'],['execucao','Execução e cobrança de dívidas','#C9A45C'],['trab_execucao','Trabalhista em execução','#5E5A52'],['trabalhista','Trabalhista','#8E8A80'],['outros','Cível e outros','#B9B4A7']];
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function nf(v){return(+v||0).toLocaleString('pt-BR')}
function barras(L){var mx=Math.max.apply(null,L.map(function(x){return x[1]}).concat([1]));return'<div class="pj-bars">'+L.map(function(x){var w=Math.max(1.5,x[1]/mx*100);return'<div class="pj-l"><span class="pj-n">'+e(x[0])+'</span><span class="pj-t"><i style="width:'+w+'%;background:'+(x[2]||'#16191E')+'"></i></span><b>'+nf(x[1])+'</b></div>'}).join('')+'</div>'}
function colunas(M){var ks=Object.keys(M||{}).sort().slice(-12);if(ks.length<3||!ks.some(function(k){return+M[k]>0}))return'';var mx=Math.max.apply(null,ks.map(function(k){return+M[k]||0}).concat([1]));
 return'<div class="pj-cols">'+ks.map(function(k){var v=+M[k]||0,h=Math.max(2,v/mx*100);return'<div><span style="height:'+h+'%"></span><small>'+k.slice(5,7)+'/'+k.slice(2,4)+'</small></div>'}).join('')+'</div>'}
var DERIV=false;function mensalDe(J,M){DERIV=false;var ok=M&&Object.keys(M).some(function(k){return +M[k]>0});if(ok)return M;var R={},n=0;
 (function vai(o,d){if(!o||d>3)return;if(Array.isArray(o)){o.forEach(function(x){if(x&&typeof x==='object'){var dt=String(x.data||x.date||x.data_disponibilizacao||x.dataDisponibilizacao||x.publicado||'');var m=dt.match(/(\d{4})-(\d{2})/)||dt.match(/(\d{2})\/(\d{2})\/(\d{4})/);if(m){var k=m[3]?m[3]+'-'+m[2]:m[1]+'-'+m[2];R[k]=(R[k]||0)+1;n++}else vai(x,d+1)}});return}if(typeof o==='object')Object.keys(o).forEach(function(k){if(k!=='por_tipo'&&k!=='por_tribunal')vai(o[k],d+1)})})(J,0);
 if(n<3)return null;DERIV=true;var out={},h=new Date();for(var i=11;i>=0;i--){var dd=new Date(h.getFullYear(),h.getMonth()-i,1),k=dd.getFullYear()+'-'+String(dd.getMonth()+1).padStart(2,'0');out[k]=R[k]||0}return out}
window.dvPainelJudicial=function(J,M){if(!J||(!J.distintos&&!J.publicacoes))return'';M=mensalDe(J,M);var pt=J.por_tipo||{},tot=+J.distintos||0;
 var L=CAT.map(function(c){return[c[1],+pt[c[0]]||0,c[2],c[0]]}).filter(function(x){return x[1]>0});
 var grav=(+pt.criminal||0)+(+pt.improbidade||0)+(+pt.coletiva||0)+(+pt.falencia||0),trab=(+pt.trabalhista||0)+(+pt.trab_execucao||0),cob=(+pt.execucao||0)+(+pt.execucao_fiscal||0);
 var tr=Object.keys(J.por_tribunal||{}).map(function(k){return[k,+J.por_tribunal[k]]}).sort(function(a,b){return b[1]-a[1]}).slice(0,6);
 var ks=Object.keys(M||{}).sort(),u3=ks.slice(-3).reduce(function(a,k){return a+(+M[k]||0)},0),a3=ks.slice(-6,-3).reduce(function(a,k){return a+(+M[k]||0)},0);
 var leit=[];if(grav)leit.push('<b>'+nf(grav)+' processo(s) grave(s)</b> (penal, improbidade, ação civil pública ou falência): leia cada um na lista abaixo.');else leit.push('Nenhum processo penal, de improbidade, ação civil pública ou falência encontrado.');
 if(tot)leit.push((trab?Math.round(trab/tot*100)+'% dos processos são trabalhistas':'Sem processos trabalhistas')+(pt.trab_execucao?', '+nf(pt.trab_execucao)+' já em execução (condenação não paga)':'')+'.');
 if(cob)leit.push(nf(cob)+' cobrança(s) judicial(is) de dívida, '+nf(pt.execucao_fiscal||0)+' delas fiscais (tributos).');
 if(a3>10&&u3>a3*1.3)leit.push('As publicações cresceram '+Math.round((u3/a3-1)*100)+'% nos últimos 3 meses.');else if(a3>10&&u3<a3*0.7)leit.push('As publicações caíram '+Math.round((1-u3/a3)*100)+'% nos últimos 3 meses.');
 var H='<section class="rp-sec dv-pj"><h3>Panorama judicial</h3><div class="pj-k"><div><b>'+nf(tot)+'</b><span>processos distintos'+(J.parcial?' (amostra)':'')+'</span></div><div class="'+(grav?'vm':'')+'"><b>'+nf(grav)+'</b><span>graves (penal, improbidade, ACP, falência)</span></div><div><b>'+nf(trab)+'</b><span>trabalhistas</span></div><div><b>'+nf(cob)+'</b><span>cobranças e execuções fiscais</span></div><div><b>'+nf(J.publicacoes)+'</b><span>publicações no Diário de Justiça</span></div></div>';
 H+='<div class="pj-g"><div><h4>Processos por tipo</h4>'+barras(L)+'</div><div>'+(colunas(M)?'<h4>'+(DERIV?'Processos em destaque por mês (últimos 12 meses)':'Publicações por mês')+'</h4>'+colunas(M):'')+(tr.length?'<h4>Tribunais com mais processos</h4>'+barras(tr.map(function(x){return[x[0],x[1],'#16191E']})):'')+'</div></div>';
 H+='<div class="pj-leit">'+leit.map(function(x){return'<p>'+x+'</p>'}).join('')+'</div><p class="pj-ft">Fonte: Diário de Justiça Eletrônico Nacional (CNJ), comunicações publicadas com o nome da empresa como parte. Conta processos distintos; homônimos são possíveis. Não inclui processos em segredo de justiça.</p></section>';
 return H};
function poe(dt,J,M){if(!dt||!J||dt.querySelector('.dv-pj'))return;var h=window.dvPainelJudicial(J,M);if(!h)return;var w=document.createElement('div');w.innerHTML=h;var s=w.firstChild,sm=dt.querySelector('summary');dt.insertBefore(s,sm?sm.nextSibling:dt.firstChild)}
var of=window.fetch;window.fetch=function(u,o){var pr=of.apply(this,arguments);if(/\/fornecedor\/processos\?/.test(String(u)))pr.then(function(r){r.clone().json().then(function(j){if(j&&j.por_tipo)window.DVPJ=j}).catch(function(){})}).catch(function(){});return pr};
setInterval(function(){var D=window.DVDS;if(D&&D.judicial){poe(document.getElementById('dv-t-rep'),(D.judicial||{}).empresa,(D.judicial||{}).mensal)}if(window.DVPJ){poe(document.getElementById('dv-t-rep'),window.DVPJ,window.DVPJ.mensal)}},900);
})();
</script>
<style>
.dv-pj .pj-k{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:14px}@media(max-width:760px){.dv-pj .pj-k{grid-template-columns:repeat(2,minmax(0,1fr))}}
.dv-pj .pj-k div{border:1px solid #E3DED2;border-radius:10px;padding:10px 12px;background:#fff}.dv-pj .pj-k b{display:block;font:600 1.3rem "Fraunces",Georgia,serif;color:#16191E}.dv-pj .pj-k .vm b{color:#9A3B2C}.dv-pj .pj-k span{font-size:.78rem;color:#6E6A60;font-weight:400}
.dv-pj .pj-g{display:grid;grid-template-columns:1.2fr 1fr;gap:22px}@media(max-width:760px){.dv-pj .pj-g{grid-template-columns:1fr}}
.dv-pj h4{margin:4px 0 8px;font-size:.9rem}
.pj-l{display:grid;grid-template-columns:minmax(120px,42%) 1fr 48px;gap:8px;align-items:center;margin:0 0 6px;font-size:.84rem}.dv-pj .pj-n{color:#33363B;font-weight:400}.dv-pj .pj-l b{color:#16191E}.dv-pj .pj-leit b{color:#9A3B2C}.dv-pj .pj-k b{color:#16191E}.dv-pj .pj-k .vm b{color:#9A3B2C}.dv-pj .pj-ft{font-weight:400}.pj-t{height:12px;background:#F0ECE3;border-radius:6px;overflow:hidden}.pj-t i{display:block;height:100%;border-radius:6px}.pj-l b{text-align:right;font-weight:700}
.pj-cols{display:flex;gap:4px;align-items:flex-end;height:110px;padding-bottom:18px;margin-bottom:12px}.pj-cols div{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}.pj-cols span{width:100%;background:#C9A45C;border-radius:3px 3px 0 0}.pj-cols small{position:absolute;bottom:-17px;font-size:.66rem;color:#6E6A60}
.pj-leit{margin-top:12px;padding:12px 14px;border-radius:10px;background:#F5F1E8}.pj-leit p{margin:0 0 4px;font-size:.9rem;font-weight:400}.pj-ft{font-size:.76rem;color:#6E6A60;margin:8px 0 0}
@media print{.dv-pj{break-inside:avoid}}
.dv-capag{margin:12px 0 0;padding:10px 14px;border-radius:10px;font-size:.92rem}.dv-capag.ok{background:#EAF5EE;color:#1F5A3B}.dv-capag.am{background:#FBF1DC;color:#7A5410}.dv-capag.vm{background:#FDEDEA;color:#9A3524}
.dv-agora{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}@media(max-width:760px){.dv-agora{grid-template-columns:1fr}}.dv-agora>div{background:#fff;border:1px solid #E3DED2;border-radius:12px;padding:14px 16px}.dv-agora h4{margin:0 0 8px;font-size:.95rem;font-weight:800;color:#16191E}.dv-agora ul{margin:0;padding:0;list-style:none}.dv-agora li{padding:7px 0;border-top:1px solid #F0ECE3;font-size:.88rem;line-height:1.35}.dv-agora li:first-child{border-top:0}.dv-agora li a{color:#16191E;font-weight:600;text-decoration:none}.dv-agora li small{display:block;color:#6E6A60;font-size:.78rem;margin-top:2px}.dv-agora p{margin:0;color:#6E6A60;font-size:.88rem}.dv-agora .mais{display:inline-block;margin-top:8px;font-size:.82rem;font-weight:700;color:#86621F}
</style>
<script>
/* Leva o botão "Analisar com a minha IA" do resumo antigo (escondido) para baixo do mapa de temas */
setInterval(function(){var R=document.querySelector('.dv-org');if(!R)return;var w=document.querySelector('#dv-rs .dv-mia-w'),mp=document.getElementById('dv-mapa');if(!w||!mp)return;var sl=document.getElementById('dv-mia-slot');if(!sl){sl=document.createElement('div');sl.id='dv-mia-slot';mp.parentNode.insertBefore(sl,mp.nextSibling)}sl.innerHTML='';sl.appendChild(w)},800);
</script>
<script>
/* PDF organizado: o mesmo roteiro da tela (conclusão, o que fazer, números, temas), sem repetição */
(function(){
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function limpa(el){var c=el.cloneNode(true);if(c.id==='rx-cad')[].forEach.call(c.querySelectorAll('h2,h3'),function(x){x.remove()});[].forEach.call(c.querySelectorAll('#dv-rs,.dv-rs,.dv-pdf-top,.dv-ia,.dv-rnav,button,input,select,textarea,script,style,.modos,.ent,.dv-mia-w,.dv-rnav,.dv-pdf-top,[data-mia-r]'),function(x){x.remove()});
 [].forEach.call(c.querySelectorAll('details'),function(d){d.setAttribute('open','')});[].forEach.call(c.querySelectorAll('[hidden]'),function(x){x.removeAttribute('hidden')});
 [].forEach.call(c.querySelectorAll('h3 i'),function(x){x.remove()});[].forEach.call(c.querySelectorAll('[style*="display: none"],[style*="display:none"]'),function(x){x.remove()});
 [].forEach.call(c.querySelectorAll('a[href^="/"],a[href^="#"]'),function(a){var s=document.createElement('span');s.innerHTML=a.innerHTML;a.replaceWith(s)});return c}
var CSS='@page{size:A4;margin:14mm 14mm 16mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0;font:400 10pt/1.45 "Hanken Grotesk",Arial,sans-serif;color:#16191E}'+
 'h1,h2,h3,h4{font-family:Georgia,"Times New Roman",serif;color:#16191E;margin:0}p{margin:0 0 6px}table{width:100%;border-collapse:collapse;font-size:8.6pt}th,td{text-align:left;padding:4px 6px;border-bottom:1px solid #E3DED2;vertical-align:top}th{font-family:Arial,sans-serif;font-size:7.6pt;color:#6E6A60}'+
 '.cab{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #16191E;padding-bottom:10px;margin-bottom:14px}.cab .k{font:700 8pt Arial,sans-serif;letter-spacing:.06em;color:#8A6A2E;margin-bottom:4px}.cab h1{font-size:20pt;line-height:1.15}.cab .s{color:#4A4D52;font-size:9pt;margin-top:3px}.cab .dt{text-align:right;font-size:8pt;color:#6E6A60}.cab .br{background:#16191E;color:#fff;padding:6px 10px;border-radius:6px;display:inline-block;margin-bottom:8px}'+
 '.q{display:grid;grid-template-columns:1.25fr 1fr;gap:10px;margin-bottom:10px}.v{border-radius:10px;padding:12px 14px;color:#fff}.v.ok{background:#2F6B4F}.v.am{background:#8A6516}.v.vm{background:#9A3B2C}.v small{display:block;font-size:7.6pt;opacity:.85}.v b{display:block;font:700 15pt Georgia,serif;margin:3px 0}.v p{color:#fff;margin:0}'+
 '.acoes{border:1px solid #E3DED2;border-radius:10px;padding:10px 12px}.acoes b{display:block;margin-bottom:4px}.acoes ol{margin:0;padding-left:16px}.acoes li{margin-bottom:3px}'+
 '.kpi{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin:8px 0 12px}.kpi div{border:1px solid #E3DED2;border-radius:8px;padding:7px 8px}.kpi b{display:block;font:700 11.5pt Georgia,serif}.kpi span{font-size:7.4pt;color:#6E6A60}.kpi .vm b{color:#9A3B2C}.kpi .am b{color:#8A6516}'+
 '.temas{border:1px solid #E3DED2;border-radius:10px;padding:4px 12px}.temas div{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #F0ECE3}.temas div:last-child{border:0}.temas i{flex:none;width:9px;height:9px;border-radius:50%;margin-top:4px;background:#B9B4A7}.temas .ok i{background:#5E9E79}.temas .am i{background:#D9A93A}.temas .vm i{background:#C2513D}.temas b{min-width:150px}.temas span{color:#4A4D52}'+
 '.nota0{padding:7px 10px;border-radius:8px;background:#F5F1E8;margin-bottom:10px;font-size:9pt}'+
 '.tema{break-before:page}.tema>h2{font-size:16pt;padding-left:10px;border-left:6px solid #B9B4A7;margin-bottom:2px}.tema.ok>h2{border-color:#5E9E79}.tema.am>h2{border-color:#D9A93A}.tema.vm>h2{border-color:#C2513D}.tema>.st{color:#4A4D52;margin:0 0 12px 16px}'+
 '.sec{margin:0 0 14px}.sec>h3,.sec h3{font-size:12pt;margin:12px 0 6px;padding-bottom:3px;border-bottom:1px solid #E3DED2}.sec h4{font-size:10.5pt;margin:10px 0 4px}'+
 '.sec li,.sec tr,.sec .sem>div,.ver,.docs{break-inside:avoid}ol,ul{padding-left:18px}'+
 '#dv-par li{list-style:none;border:1px solid #E3DED2;border-left:4px solid #B9B4A7;border-radius:6px;padding:7px 9px;margin:0 0 6px}#dv-par li.vm{border-left-color:#C2513D}#dv-par li.am{border-left-color:#D9A93A}#dv-par li.ok{border-left-color:#5E9E79}#dv-par ol{padding:0}#dv-par li h4{font:700 10pt Arial,sans-serif;margin:0 0 2px}#dv-par .fu{font-size:8pt;color:#8A6A2E;font-weight:700}#dv-par .ac{font-size:9pt}#dv-par .ac:before{content:"Próximo passo: ";font-weight:700}'+
 '#dv-par .ver{border:1px solid #E3DED2;border-radius:8px;padding:8px 10px;margin-bottom:8px}#dv-par h4.grp{font:700 8.5pt Arial,sans-serif;color:#6E6A60;margin:10px 0 5px}#dv-par .docs{background:#F5F1E8;border-radius:8px;padding:8px 10px;margin-top:10px}#dv-par .docs .it:before{content:"☐ "}#dv-par .okd summary{font-weight:700;color:#2F6B4F;margin:8px 0 4px;list-style:none}#dv-par .nota{font-size:8pt;color:#6E6A60}'+
 '.kv{display:grid;grid-template-columns:repeat(3,1fr);gap:4px 14px}.kv div{padding:3px 0;border-bottom:1px dashed #EEE9DE}.kv b{display:block;font:700 7.4pt Arial,sans-serif;color:#6E6A60}'+
 '.sem{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.sem>div{border:1px solid #E3DED2;border-radius:6px;padding:6px 8px}.sem b{display:block;font-size:8pt;color:#6E6A60}.mu{color:#6E6A60}.tag{display:inline-block;background:#F0ECE3;border-radius:4px;padding:0 5px;margin:1px;font-size:8pt}'+
 '.dv-pj .pj-k{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}.dv-pj .pj-k div{border:1px solid #E3DED2;border-radius:6px;padding:6px 8px}.dv-pj .pj-k b{display:block;font:700 12pt Georgia,serif}.dv-pj .pj-k .vm b{color:#9A3B2C}.dv-pj .pj-k span{font-size:7.4pt;color:#6E6A60}.dv-pj .pj-g{display:grid;grid-template-columns:1.2fr 1fr;gap:14px}.pj-l{display:grid;grid-template-columns:42% 1fr 34px;gap:6px;align-items:center;margin:0 0 4px;font-size:8pt}.pj-t{height:9px;background:#F0ECE3;border-radius:5px;overflow:hidden}.pj-t i{display:block;height:100%}.pj-l b{text-align:right}.pj-cols{display:flex;gap:3px;align-items:flex-end;height:80px;padding-bottom:14px;margin-bottom:8px}.pj-cols div{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}.pj-cols span{width:100%;background:#C9A45C}.pj-cols small{position:absolute;bottom:-13px;font-size:6.5pt;color:#6E6A60}.pj-leit{margin-top:8px;padding:8px 10px;border-radius:6px;background:#F5F1E8}.pj-ft{font-size:7.4pt;color:#6E6A60}.dv-pj{break-inside:avoid;break-after:page}'+
 '.fim{break-before:page;font-size:8.6pt;color:#4A4D52}.fim h2{font-size:14pt;margin-bottom:8px}.rod{margin-top:16px;font-size:7.6pt;color:#8A857A;border-top:1px solid #E3DED2;padding-top:6px}';
function monta(){var R=document.querySelector('.dv-org');if(!R)return null;var hd=R.querySelector('.rp-hd'),nome=R.dataset.nome||((hd&&hd.querySelector('h2'))||{}).textContent||'Relatório',sub=R.dataset.sub||((hd&&hd.querySelector('h2+p,p'))||{}).textContent||'',tipo=R.dataset.tipo||((hd&&hd.querySelector('small'))||{}).textContent||'Relatório';
 var hoje=new Date(),dt=hoje.toLocaleDateString('pt-BR')+' às '+hoje.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
 var H='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>'+e(tipo+' - '+nome)+'</title><link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;600;700&display=swap" rel="stylesheet"><style>'+CSS+'</style></head><body>';
 H+='<div class="cab"><div><div class="k">'+e(tipo)+'</div><h1>'+e(nome)+'</h1><div class="s">'+e(sub)+'</div></div><div class="dt"><div class="br">Desenvolve Estratégias</div><div>Gerado em '+e(dt)+'</div></div></div>';
 var cp=document.getElementById('dv-capa');if(cp){var c=limpa(cp);H+=c.innerHTML}
 var T=[].slice.call(R.querySelectorAll(':scope > details.dv-tema'));
 H+='<h3 style="font-size:11pt;margin:4px 0 6px">Resumo por tema</h3><div class="temas">'+T.map(function(t){var cl=(t.className.match(/\b(ok|am|vm|na)\b/)||['','na'])[1],b=t.querySelector('summary b'),sp=t.querySelector('summary span');return'<div class="'+cl+'"><i></i><b>'+e(b?b.textContent:'')+'</b><span>'+e(sp?sp.textContent:'')+'</span></div>'}).join('')+'</div>';
 T.forEach(function(t){var cl=(t.className.match(/\b(ok|am|vm|na)\b/)||['','na'])[1],b=t.querySelector('summary b'),sp=t.querySelector('summary span');
  H+='<section class="tema '+cl+'"><h2>'+e(b?b.textContent:'')+'</h2><p class="st">'+e(sp?sp.textContent:'')+'</p>';
  [].slice.call(t.children).forEach(function(ch){if(ch.tagName==='SUMMARY'||/^(dv-rs)$/.test(ch.id)||/dv-pdf-top|dv-rnav|dv-ia\b/.test(ch.className))return;var x=limpa(ch);if(x.id==='dv-par'){[].forEach.call(x.querySelectorAll('h3,.sub'),function(y){y.remove()});H+='<div id="dv-par" class="sec">'+x.innerHTML+'</div>'}else H+='<div class="sec">'+x.innerHTML+'</div>'});
  H+='</section>'});
 H+='<section class="fim"><h2>Fontes e aviso</h2><p>Relatório montado com dados públicos oficiais (Receita Federal, PGFN, Portal da Transparência/CGU, TCU, CNJ, PNCP, Tesouro Nacional e diários oficiais), consultados na data acima. As informações podem mudar depois da consulta: confira as certidões no dia da sessão.</p><p>Ausência de registro numa fonte não prova que o registro não existe. Processos e notícias encontrados pelo nome podem envolver homônimos.</p><p>Este relatório não substitui parecer jurídico. Para análise assinada por advogado, fale com a Desenvolve Estratégias.</p><div class="rod">Desenvolve Estratégias · desenvolveestrategias.com.br</div></section></body></html>';
 return H}
function imprime(){var H=monta();if(!H)return false;var f=document.createElement('iframe');f.style.cssText='position:fixed;right:0;bottom:0;width:0;height:0;border:0';document.body.appendChild(f);var d=f.contentDocument;d.open();d.write(H);d.close();
 var go=function(){try{f.contentWindow.focus();f.contentWindow.print()}catch(x){}setTimeout(function(){f.remove()},60000)};(d.fonts&&d.fonts.ready?d.fonts.ready:Promise.resolve()).then(function(){setTimeout(go,500)});return true}
window.dvPdfOrganizado=monta;
/* PDF igual à tela: imprime o próprio relatório (mesmo visual), com todos os temas abertos e sem menus/botões */
var PCSS='@page{size:A4;margin:10mm}'+
'html.dv-printing,html.dv-printing body{background:#fff!important}html.dv-printing *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}'+
'html.dv-printing body :not(.dv-pa):not(.dv-pr):not(.dv-pr *):not(.dv-ph):not(.dv-ph *){display:none!important}'+
'html.dv-printing .dv-pa{display:block!important;margin:0!important;padding:0!important;width:auto!important;max-width:none!important;min-height:0!important;position:static!important;overflow:visible!important;transform:none!important;border:0!important;box-shadow:none!important;background:transparent!important}'+
'html.dv-printing .dv-pr{margin:0!important;max-width:none!important;width:1040px!important;zoom:.69}html.dv-printing .dv-ph{width:1040px;zoom:.69}'+
'html.dv-printing .dv-pr *{max-width:100%}html.dv-printing .dv-pr table{width:100%!important;table-layout:auto}html.dv-printing .dv-pr td,html.dv-printing .dv-pr th{word-break:break-word}'+
'html.dv-printing .dv-pr button,html.dv-printing .dv-pr input,html.dv-printing .dv-pr select,html.dv-printing .dv-pr textarea,html.dv-printing .dv-pr form,html.dv-printing .dv-pr .dv-rnav,html.dv-printing .dv-pr .dv-mia-w,html.dv-printing .dv-pr #dv-mia-slot,html.dv-printing .dv-pr .dv-pdf-top,html.dv-printing .dv-pr .dv-ia,html.dv-printing .dv-pr #dv-rs,html.dv-printing .dv-pr .modos,html.dv-printing .dv-pr .ent{display:none!important}'+
'html.dv-printing .dv-pr details>summary::-webkit-details-marker{display:none}html.dv-printing .dv-pr details>summary{list-style:none}'+
'html.dv-printing .dv-pr details.dv-tema{break-before:page}html.dv-printing .dv-pr details.dv-tema:first-of-type{break-before:auto}'+
'html.dv-printing .dv-pr li,html.dv-printing .dv-pr tr,html.dv-printing .dv-pr img,html.dv-printing .dv-pr svg,html.dv-printing .dv-pr .dv-pj,html.dv-printing .dv-pr [class*=card],html.dv-printing .dv-pr [class*=kpi]>div{break-inside:avoid}'+
'.dv-ph{display:none}html.dv-printing .dv-ph{display:flex!important;justify-content:space-between;align-items:center;border-bottom:2px solid #16191E;padding:0 0 8px;margin:0 0 12px;font:600 9pt "Hanken Grotesk",Arial,sans-serif;color:#16191E}html.dv-printing .dv-ph b{font-weight:800;letter-spacing:.05em}html.dv-printing .dv-ph span{color:#6E6A60;font-weight:400}';
function imprimeTela(){var R=document.querySelector('.dv-org');if(!R)return false;
 var st=document.getElementById('dv-pcss');if(!st){st=document.createElement('style');st.id='dv-pcss';st.textContent=PCSS;document.head.appendChild(st)}
 var fech=[].filter.call(R.querySelectorAll('details'),function(d){return!d.open});fech.forEach(function(d){d.open=true});
 var pa=[],x=R.parentElement;while(x&&x!==document.body){x.classList.add('dv-pa');pa.push(x);x=x.parentElement}R.classList.add('dv-pr');
 var hoje=new Date(),ph=document.createElement('div');ph.className='dv-ph';ph.innerHTML='<b>DESENVOLVE ESTRATÉGIAS</b><span>Gerado em '+hoje.toLocaleDateString('pt-BR')+' às '+hoje.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})+'</span>';R.parentNode.insertBefore(ph,R);
 var t0=document.title,nm=(document.querySelector('#rx-cad h2, .rp-hd h2, #dv-rxhd h2, .dv-org h2')||{}).textContent;if(nm)document.title=(t0.split('|')[0].trim()+' - '+nm.trim()).slice(0,120);
 document.documentElement.classList.add('dv-printing');window.dispatchEvent(new Event('resize'));
 var fim=function(){document.documentElement.classList.remove('dv-printing');pa.forEach(function(y){y.classList.remove('dv-pa')});R.classList.remove('dv-pr');ph.remove();fech.forEach(function(d){d.open=false});document.title=t0;window.removeEventListener('afterprint',fim);window.dispatchEvent(new Event('resize'))};
 window.addEventListener('afterprint',fim);setTimeout(function(){window.print()},350);return true}
window.dvPdfTela=imprimeTela;
/* Excel: baixa os dados do relatório (resumo + um aba por tema) */
function txt(n){return String(n&&n.textContent||'').replace(/\s+/g,' ').trim()}
function linhas(el){var R=[];if(!el)return R;var c=el.cloneNode(true);[].forEach.call(c.querySelectorAll('summary,button,input,select,textarea,script,style,svg,.dv-rnav,.dv-mia-w,#dv-mia-slot,.dv-pdf-top,.dv-ia,#dv-rs'),function(x){x.remove()});
 function vai(n,sec){[].forEach.call(n.children,function(ch){var tg=ch.tagName;if(/^H[1-4]$/.test(tg)){var t=txt(ch);if(t){R.push([]);R.push([t.toUpperCase()])}return}
  if(tg==='TABLE'){[].forEach.call(ch.querySelectorAll('tr'),function(tr){var r=[].map.call(tr.children,function(td){var v=txt(td),num=v.replace(/^R\$\s?/,'').replace(/\./g,'').replace(',','.');return /^-?\d+(\.\d+)?%?$/.test(num)&&!/%$/.test(num)&&v.length<20?+num:v});if(r.join(''))R.push(r)});return}
  if(tg==='LI'||(ch.children.length<=3&&ch.querySelector&&ch.querySelector(':scope>b,:scope>strong')&&txt(ch).length<220&&!ch.querySelector('div div'))){var b=ch.querySelector(':scope>b,:scope>strong'),t=txt(ch);if(b&&tg!=='LI'){var bt=txt(b),rest=t.replace(bt,'').trim();R.push(rest?[rest,bt]:[bt])}else R.push([t]);return}
  if(ch.children.length===0){var t=txt(ch);if(t)R.push([t]);return}
  vai(ch)})}
 vai(c);return R}
function nomeAba(t,usadas){t=String(t||'Aba').replace(/[\\\/\?\*\[\]:]/g,' ').trim().slice(0,28)||'Aba';var n=t,i=2;while(usadas[n])n=t.slice(0,25)+' '+(i++);usadas[n]=1;return n}
/* Todos os dados brutos que compõem o relatório (contratos, publicações, processos, sanções...) viram abas */
var NOMES={contratos:'Contratos',publicacoes:'Publicações',processos:'Processos',sancoes:'Sanções',ceis:'CEIS',cnep:'CNEP',socios:'Sócios',qsa:'Sócios',filiais:'Filiais',unidades:'Unidades',pagamentos:'Pagamentos',licitacoes:'Licitações',vencidas:'Licitações vencidas',participacoes:'Participações',atas:'Atas',noticias:'Notícias',diarios:'Diários oficiais',destaques:'Processos em destaque',por_tipo:'Processos por tipo',por_tribunal:'Processos por tribunal',pgfn:'Dívida ativa',dividas:'Dívida ativa',orgaos:'Órgãos',fornecedores:'Fornecedores',itens:'Itens',empenhos:'Empenhos',vinculos:'Vínculos',cnaes:'CNAEs',cnaes_secundarios:'CNAEs secundários'};
function hum(k){k=String(k);return NOMES[k]||k.replace(/_/g,' ').replace(/^./,function(c){return c.toUpperCase()})}
function plano(o,pre,out){out=out||{};Object.keys(o||{}).forEach(function(k){var v=o[k],n=pre?pre+' · '+hum(k):hum(k);if(v==null)out[n]='';else if(Array.isArray(v)){if(v.length&&typeof v[0]==='object')out[n]=v.length+' itens';else out[n]=v.join('; ')}else if(typeof v==='object')plano(v,n,out);else out[n]=v});return out}
function brutos(X,wb,us){var D=window.DVRAW||{};var ks=Object.keys(D);if(!ks.length){var w0=X.utils.aoa_to_sheet([['Os dados completos ficam disponíveis nos relatórios gerados a partir de hoje.'],['Para baixar tudo deste relatório, faça uma nova consulta.']]);X.utils.book_append_sheet(wb,w0,nomeAba('Dados completos',us));return}
 var ger=[['Fonte','Campo','Valor']],tabs=[];
 ks.forEach(function(src){var fonte=src.replace(/^.*\/(v1|int)\//,'').replace(/[?#].*$/,'').replace(/\//g,' · ');
  (function vai(o,path){if(Array.isArray(o)){if(o.length&&o[0]&&typeof o[0]==='object'){tabs.push({nome:path||fonte,rows:o})}else if(o.length)ger.push([fonte,path,o.join('; ')]);return}
   if(!o||typeof o!=='object')return;Object.keys(o).forEach(function(k){if(k==='_st')return;var v=o[k],pth=path?path+' · '+hum(k):hum(k);
    if(v&&typeof v==='object'){if(!Array.isArray(v)&&Object.keys(v).length&&Object.keys(v).every(function(z){return typeof v[z]==='number'})&&Object.keys(v).length>3){tabs.push({nome:pth,rows:Object.keys(v).map(function(z){return{item:z,quantidade:v[z]}})});return}vai(v,pth)}else ger.push([fonte,pth,v==null?'':v])})})(D[src],'')});
 var wg=X.utils.aoa_to_sheet(ger);wg['!cols']=[{wch:24},{wch:44},{wch:70}];X.utils.book_append_sheet(wb,wg,nomeAba('Dados gerais',us));
 tabs.forEach(function(t){var lin=t.rows.map(function(r){return typeof r==='object'?plano(r,''):{Valor:r}}),cols=[];lin.forEach(function(l){Object.keys(l).forEach(function(c){if(cols.indexOf(c)<0)cols.push(c)})});
  var aoa=[cols].concat(lin.map(function(l){return cols.map(function(c){var v=l[c];return v===undefined?'':v})}));var w=X.utils.aoa_to_sheet(aoa);w['!cols']=cols.map(function(c){return{wch:Math.min(60,Math.max(12,c.length+2))}});if(aoa.length>1)w['!autofilter']={ref:X.utils.encode_range({s:{r:0,c:0},e:{r:aoa.length-1,c:Math.max(0,cols.length-1)}})};
  var nm=t.nome.split(' · ').pop();X.utils.book_append_sheet(wb,w,nomeAba(nm+' ('+t.rows.length+')',us))})}
function excel(){var R=document.querySelector('.dv-org');if(!R)return;
 var go=function(){var X=window.XLSX,wb=X.utils.book_new(),us={},nm=txt(document.querySelector('#rx-cad h2, .rp-hd h2, #dv-rxhd h2, .dv-org h2'))||'Relatório',hoje=new Date();
  var res=[[nm],['Gerado em '+hoje.toLocaleDateString('pt-BR')+' às '+hoje.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})+' · Desenvolve Estratégias'],[]];
  var cp=document.getElementById('dv-capa');if(cp)res=res.concat(linhas(cp));
  var T=[].slice.call(R.querySelectorAll(':scope > details.dv-tema'));if(T.length){res.push([]);res.push(['RESUMO POR TEMA']);T.forEach(function(t){res.push([txt(t.querySelector('summary b')),txt(t.querySelector('summary span'))])})}
  var ws=X.utils.aoa_to_sheet(res);ws['!cols']=[{wch:60},{wch:40},{wch:22},{wch:22},{wch:22}];X.utils.book_append_sheet(wb,ws,nomeAba('Resumo',us));
  (T.length?T:[R]).forEach(function(t){var tt=t.querySelector&&t.querySelector('summary b'),rows=[[txt(tt)||nm]].concat(linhas(t).filter(function(r){return r.length&&!(tt&&r[0]===txt(tt).toUpperCase())}));var w=X.utils.aoa_to_sheet(rows);w['!cols']=[{wch:60},{wch:30},{wch:22},{wch:22},{wch:22},{wch:22}];X.utils.book_append_sheet(wb,w,nomeAba(txt(tt)||'Relatório',us))});
  brutos(X,wb,us);
  var arq=(document.title.split('|')[0].trim()+' - '+nm).replace(/[\\\/:*?"<>|]/g,' ').slice(0,90)+'.xlsx';arq=arq.replace(/\.+\.xlsx$/,'.xlsx');X.writeFile(wb,arq)};
 if(window.XLSX)go();else{var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';sc.onload=go;sc.onerror=function(){alert('Não foi possível gerar o Excel agora. Tente de novo em instantes.')};document.head.appendChild(sc)}}
window.dvExcel=excel;
setInterval(function(){[].forEach.call(document.querySelectorAll('.dv-pdf-top'),function(t){if(t.querySelector('[data-xls]'))return;var pb=t.querySelector('button');if(!pb)return;var b=document.createElement('button');b.type='button';b.setAttribute('data-xls','');b.className=pb.className;b.style.cssText=(pb.getAttribute('style')||'')+';margin-left:8px;background:#1F6F43!important;border-color:#1F6F43!important;color:#fff!important';b.textContent='⬇ Baixar dados em Excel';pb.insertAdjacentElement('afterend',b)})},900);
setInterval(function(){var R=document.querySelector('.dv-org');if(!R||document.getElementById('dv-exp'))return;var mp=document.getElementById('dv-mapa')||document.getElementById('dv-capa');if(!mp)return;var d=document.createElement('div');d.id='dv-exp';d.innerHTML='<button type="button" data-pdfx>⬇ Salvar em PDF</button><button type="button" data-xls class="xl">⬇ Baixar dados em Excel</button>';mp.parentNode.insertBefore(d,mp);
 if(!document.getElementById('dv-exp-css')){var st=document.createElement('style');st.id='dv-exp-css';st.textContent='#dv-exp{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;padding:12px 30px 0;background:#FFFDF8}#dv-exp button{font:700 .85rem "Hanken Grotesk",-apple-system,sans-serif;border-radius:10px;padding:9px 14px;cursor:pointer;border:1px solid #16191E;background:#16191E!important;color:#F4F2EC!important}#dv-exp button.xl{background:#1F6F43!important;border-color:#1F6F43}html.dv-printing #dv-exp{display:none!important}';document.head.appendChild(st)}},900);
window.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('[data-pdfx]');if(!b)return;ev.preventDefault();ev.stopImmediatePropagation();imprimeTela()},true);
window.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('[data-xls]');if(!b)return;ev.preventDefault();ev.stopImmediatePropagation();excel()},true);
window.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('[data-pdf], .dv-pdf-top button');if(b&&b.hasAttribute('data-xls'))return;if(!b||!document.querySelector('.dv-org'))return;ev.preventDefault();ev.stopImmediatePropagation();imprimeTela()},true);
window.addEventListener('beforeprint',function(){});
})();
</script>
	<?php
}, 70 );

/* Contador em valores: R$ em licitações abertas agora e publicadas nos últimos 12 meses (robô diário no GitHub) */
add_action( 'wp_footer', function () {
	global $post; $c = $post ? (string) $post->post_content : '';
	if ( ! is_front_page() && strpos( $c, 'id="viva"' ) === false && ! is_page( 'consulta-gratis' ) ) { return; } ?>
<style>
#dv-val{margin:14px 0 0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-width:880px}
@media(max-width:700px){#dv-val{grid-template-columns:1fr}}
#dv-val div{border:1px solid rgba(201,164,92,.45);border-radius:14px;padding:12px 14px;background:rgba(255,255,255,.04)}
#dv-val b{display:block;font:600 1.45rem/1.15 "Fraunces",Georgia,serif;color:#C9A45C}
#dv-val span{display:block;font-size:.86rem;line-height:1.4;opacity:.9}
#dv-val-n{margin:8px 0 0;font-size:.78rem;opacity:.7}
</style>
<script>
(function(){
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bilhões':v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:0})+' milhões':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function n(v){return(+v||0).toLocaleString('pt-BR')}
fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/valores/valores.json',{cache:'no-store'}).then(function(r){return r.json()}).then(function(j){
 var A=j.abertas&&j.abertas.BR,Y=j.ano&&j.ano.BR,ok=A&&A.todas&&A.todas.valor>0;if(!ok)return;
 var alvo=document.getElementById('viva')||document.querySelector('.cg-hero, .hero, main h1');if(!alvo)return;
 var box=document.getElementById('dv-val');if(!box){box=document.createElement('div');box.id='dv-val';alvo.parentNode.insertBefore(box,alvo.nextSibling)}
 var H='<div><b>'+mc(A.todas.valor)+'</b><span>abertos agora em licitações</span></div>';
 H+='<div><b>'+mc(A.rapidas.valor)+'</b><span>em contratações rápidas</span></div>';
 if(Y&&j.dias_cobertos>=360)H+='<div><b>'+mc(Y.todas.valor)+'</b><span>por ano. Quanto disso pode ser seu?</span></div>';
 else if(Y&&j.dias_cobertos>=7&&Y.todas.valor>0)H+='<div><b>'+mc(Y.todas.valor)+'</b><span>nos últimos '+j.dias_cobertos+' dias. Quanto disso pode ser seu?</span></div>';
 else H+='<div><b>'+n(A.todas.n+0)+'</b><span>oportunidades abertas hoje. Quanto disso pode ser seu?</span></div>';
 box.innerHTML=H;
 var nt=document.getElementById('dv-val-n');if(!nt){nt=document.createElement('p');nt.id='dv-val-n';box.parentNode.insertBefore(nt,box.nextSibling)}
 nt.textContent='Fonte: PNCP · '+String(j.abertas_em||'').split(' ')[0].split('-').reverse().join('/');
}).catch(function(){});
})();
</script>
	<?php
}, 80 );

/* Habilitação do concorrente: últimas licitações que a empresa venceu ou disputou, com link para os documentos */
add_shortcode( 'dv_habilitacao', function () {
	if ( ! is_user_logged_in() ) { return '<p>Entre na sua conta para usar esta ferramenta.</p>'; }
	$nonce = wp_create_nonce( 'wp_rest' ); ob_start(); ?>
<div class="hb" id="hb">
<form class="hb-f" autocomplete="off"><label for="hb-c">CNPJ do concorrente</label><div class="hb-r"><input id="hb-c" inputmode="numeric" placeholder="00.000.000/0000-00" maxlength="18"><button type="submit">Buscar licitações</button></div>
<p class="hb-h">Mostramos as licitações mais recentes que a empresa venceu ou disputou, com o link para os arquivos de cada uma no PNCP (edital, atas e documentos de habilitação, quando o órgão publicou). Sem custo de créditos.</p><p class="hb-m" role="status"></p></form>
<div class="hb-out"></div></div>
<style>
.hb{font-family:"Hanken Grotesk",-apple-system,sans-serif;color:#16191E}.hb-f label{display:block;font-weight:700;margin-bottom:8px}.hb-r{display:flex;gap:10px;flex-wrap:wrap}.hb-r input{flex:1;min-width:220px;border:1px solid #CFC8B8;border-radius:10px;padding:13px 14px;font:inherit;font-size:1.05rem;background:#fff}
.hb-r button{background:#16191E;color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer}.hb-h{color:#6E6A60;font-size:.88rem;margin:8px 0 0}.hb-m{font-weight:600;margin:8px 0 0}.hb-m.e{color:#9A3B2C}
.hb-s{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:22px 0 14px}@media(max-width:700px){.hb-s{grid-template-columns:repeat(2,minmax(0,1fr))}}.hb-s div{border:1px solid #E3DED2;border-radius:12px;padding:12px 14px;background:#fff}.hb-s b{display:block;font:600 1.3rem "Fraunces",Georgia,serif}.hb-s span{font-size:.8rem;color:#6E6A60}
.hb-l{display:grid;gap:10px}.hb-i{border:1px solid #E3DED2;border-left:5px solid #C9A45C;border-radius:12px;padding:14px 16px;background:#fff}.hb-i.v{border-left-color:#2F6B4F}
.hb-i h4{margin:0 0 4px;font-size:1rem}.hb-i p{margin:0 0 6px;font-size:.9rem;color:#33363B}.hb-i .mt{font-size:.82rem;color:#6E6A60}.hb-i .tg{display:inline-block;font-size:.76rem;font-weight:700;border-radius:999px;padding:2px 10px;margin-right:6px;background:#EEF7F1;color:#2F6B4F}.hb-i .tg.p{background:#FFF8E6;color:#8A6516}
.hb-i .bt{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.hb-i .bt a{font-size:.85rem;font-weight:700;text-decoration:none;border:1px solid #CFC8B8;border-radius:8px;padding:7px 12px;color:#16191E;background:#fff}.hb-i .bt a.pr{background:#16191E;color:#fff;border-color:#16191E}
.hb-i .bt a:focus-visible,.hb-r button:focus-visible,.hb-r input:focus-visible{outline:3px solid #C9A45C;outline-offset:2px}
</style>
<script>
(function(){var R=document.getElementById('hb'),NONCE=<?php echo wp_json_encode( $nonce ); ?>,out=R.querySelector('.hb-out'),msg=R.querySelector('.hb-m');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function br(d){var m=String(d||'').match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''}
function fc(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function busca(c){msg.className='hb-m';msg.textContent='Buscando as licitações no PNCP… pode levar até 40 segundos.';out.innerHTML='';
 fetch('/wp-json/desenvolve/v1/vencidas?cnpj='+c,{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}}).then(function(r){return r.json().then(function(j){return{s:r.status,j:j}})}).then(function(x){
  if(x.s>=400){msg.className='hb-m e';msg.textContent=(x.j&&x.j.message)||'Não foi possível buscar agora. Tente de novo em alguns minutos.';return}
  var V=(x.j.vencidas||{}),P=(x.j.participacoes||{}),L=[];
  (V.itens||[]).forEach(function(i){L.push({v:1,ref:i.ref,orgao:i.orgao,mun:i.municipio,uf:i.uf,obj:i.objeto,mod:i.modalidade,num:i.numero,data:i.data,valor:i.valor,desc:i.desconto,link:i.link})});
  (P.itens||[]).forEach(function(i){if(L.some(function(y){return y.ref===i.ref}))return;var pos=Math.min.apply(null,(i.itens||[]).map(function(t){return t.posicao||99}));L.push({v:pos===1?1:0,pos:pos,ref:i.ref,orgao:i.orgao,obj:i.objeto,data:i.data,link:i.link})});
  L.sort(function(a,b){return String(b.data).localeCompare(String(a.data))});
  msg.textContent=L.length?'':'Não encontramos licitações dessa empresa no PNCP nos últimos 3 anos.';if(V.incompleto)msg.textContent='O PNCP respondeu só em parte: a lista pode estar incompleta. Tente de novo em alguns minutos.';
  if(!L.length)return;var ufs=Object.keys(V.ufs||{}).slice(0,3).join(', ');
  var H='<div class="hb-s"><div><b>'+(V.total||0)+'</b><span>licitações vencidas (3 anos)</span></div><div><b>'+mc(V.valor)+'</b><span>valor homologado a ela</span></div><div><b>'+(V.desconto_medio!=null?String(V.desconto_medio).replace('.',',')+'%':'—')+'</b><span>desconto médio</span></div><div><b>'+(ufs||'—')+'</b><span>estados onde mais ganha</span></div></div><div class="hb-l">';
  H+=L.slice(0,40).map(function(i){var oc=String(i.ref||'').split('/')[0];return'<article class="hb-i'+(i.v?' v':'')+'"><span class="tg'+(i.v?'':' p')+'">'+(i.v?'Venceu':'Participou'+(i.pos&&i.pos<99?' · '+i.pos+'º lugar':''))+'</span><span class="mt">'+e(br(i.data))+'</span><h4>'+e(i.orgao||'Órgão '+fc(oc))+(i.mun?' · '+e(i.mun)+'/'+e(i.uf):'')+'</h4><p>'+e(i.obj||'')+'</p><p class="mt">'+e([i.mod,i.num].filter(Boolean).join(' '))+(i.valor?' · Homologado: '+mc(i.valor):'')+(i.desc!=null?' · Desconto: '+String(i.desc).replace('.',',')+'%':'')+'</p><div class="bt"><a class="pr" href="'+e(i.link)+'" target="_blank" rel="noopener">Documentos da licitação no PNCP</a>'+(oc?'<a href="/plataforma/raio-x-comprador/?cnpj='+oc+'">Raio-X do órgão</a>':'')+'</div></article>'}).join('')+'</div>';
  out.innerHTML=H}).catch(function(){msg.className='hb-m e';msg.textContent='Não foi possível buscar agora. Tente de novo em alguns minutos.'})}
R.querySelector('.hb-f').addEventListener('submit',function(ev){ev.preventDefault();var c=R.querySelector('#hb-c').value.replace(/\D/g,'');if(c.length!==14){msg.className='hb-m e';msg.textContent='Digite o CNPJ com 14 números.';return}history.replaceState(null,'','?cnpj='+c);busca(c)});
var q=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'');if(q.length===14){R.querySelector('#hb-c').value=fc(q);busca(q)}
})();
</script>
	<?php return ob_get_clean();
} );

/* Página inicial sempre abre no topo (sem restaurar a rolagem anterior) */
add_action( 'wp_head', function () {
	if ( ! is_front_page() ) { return; }
	echo "<script>if(!location.hash){try{history.scrollRestoration='manual'}catch(e){}window.addEventListener('load',function(){if(!location.hash)window.scrollTo(0,0)})}</script>\n";
}, 1 );

// Analisar com a minha IA: sempre mostra o menu (IA lembrada no topo, outras IAs e "copiar o texto").
add_action( 'wp_footer', function () { ?>
<style>body:has([data-mia-r]) .dv-ia{display:none!important}#dv-mia-m .op button.us{border-color:#C9A45C;background:#FFF9EC!important}#dv-mia-m .op button.cp{background:#16191E!important;color:#F4F2EC!important;border-color:#16191E}#dv-mia-m .op button.cp small{color:#C9C3B6}</style>
<script>(function(){
var IAS={chatgpt:{n:'ChatGPT',u:'https://chatgpt.com/?q='},claude:{n:'Claude',u:'https://claude.ai/new?q='},gemini:{n:'Gemini',b:'https://gemini.google.com/app'},copilot:{n:'Copilot',b:'https://copilot.microsoft.com/'},perplexity:{n:'Perplexity',u:'https://www.perplexity.ai/search?q='},grok:{n:'Grok',u:'https://grok.com/?q='},deepseek:{n:'DeepSeek',b:'https://chat.deepseek.com/'}};
var P={licitacao:'Trabalho em uma empresa que vende para o Poder Público. Analise a licitação abaixo e responda de forma objetiva: 1) o que está sendo contratado e os pontos principais; 2) exigências de habilitação e riscos que merecem atenção; 3) se vale a pena participar e o que preparar; 4) prazos importantes. Se o link do edital estiver disponível, leia o edital completo; se não conseguir, diga o que devo conferir nele.',
concorrente:'Analise o relatório abaixo, feito com dados públicos de uma empresa que concorre comigo em licitações. Responda de forma objetiva: 1) o perfil e a força dessa empresa; 2) pontos fracos e riscos (sanções, dívidas, contratos); 3) como posso me posicionar para competir com ela.',
dossie:'Analise o relatório de due diligence abaixo, feito com dados públicos de uma empresa. Responda de forma objetiva: 1) os principais riscos (sanções, dívidas, processos, situação cadastral); 2) o que pede atenção antes de contratar, fazer parceria ou consórcio com ela; 3) que documentos ou confirmações devo pedir.',
noticia:'Trabalho em uma empresa que vende para o Poder Público. Leia a notícia do link abaixo e me explique, de forma objetiva: 1) o que aconteceu; 2) o que isso muda para quem participa de licitações e contratos públicos; 3) se devo tomar alguma providência. Se não conseguir abrir o link, diga o que dá para concluir pelo título e o que devo conferir.',
comprador:'Analise o relatório abaixo, feito com dados públicos de um órgão público comprador. Responda de forma objetiva: 1) como esse órgão costuma comprar e pagar; 2) oportunidades para quem vende para ele; 3) riscos e cuidados.'};
function ls(k,v){try{if(v===undefined)return localStorage.getItem(k);localStorage.setItem(k,v)}catch(e){return null}}
function toast(h,ms){var t=document.getElementById('dv-mia-t');if(t)t.remove();t=document.createElement('div');t.id='dv-mia-t';t.innerHTML=h;document.body.appendChild(t);setTimeout(function(){t.remove()},ms||7000)}
function copiar(s){var ok=false;try{if(navigator.clipboard){navigator.clipboard.writeText(s);ok=true}}catch(e){}if(!ok){var a=document.createElement('textarea');a.value=s;a.style.position='fixed';a.style.opacity='0';document.body.appendChild(a);a.select();try{document.execCommand('copy')}catch(e){}a.remove()}}
function limpa(t){return String(t||'').split('\n').filter(function(l){return!/desenvolve/i.test(l)}).join('\n').replace(/[ \t]+/g,' ').replace(/\n\s*\n\s*\n+/g,'\n\n').trim().slice(0,20000)}
function prompt(tipo,txt){return (P[tipo]||P.concorrente)+'\n\nResponda em português.\n\n'+limpa(txt)}
function abrir(k,pr,w){var I=IAS[k],enc=encodeURIComponent(pr),curto=I.u&&enc.length<7500,url=curto?I.u+enc:(I.b||I.u.replace(/\?q=$/,''));copiar(pr);if(w){try{w.opener=null}catch(e){}w.location.href=url}else window.open(url,'_blank');
toast(curto?'Abrimos o '+I.n+' com a análise pronta para enviar.':'Abrimos o '+I.n+'. O texto já está copiado: é só colar na conversa (Ctrl+V ou segurar e colar).',curto?6000:12000)}
window.dvMinhaIA=function(tipo,txt){var pr=prompt(tipo,txt),us=ls('dv_minha_ia'),ks=Object.keys(IAS);if(us&&IAS[us])ks=[us].concat(ks.filter(function(k){return k!==us}));
var m=document.getElementById('dv-mia-m');if(m)m.remove();m=document.createElement('div');m.id='dv-mia-m';
m.innerHTML='<div class="bx" role="dialog" aria-modal="true"><h3>Analisar com a sua IA</h3><p>Escolha a IA que você usa. Ela abre em outra aba, na sua conta, com o relatório pronto para análise.</p><div class="op">'+ks.map(function(k){var I=IAS[k];return'<button type="button" data-k="'+k+'"'+(k===us?' class="us"':'')+'>'+I.n+'<small>'+(k===us?'a que você usou da última vez':(I.u?'abre preenchido':'o texto vai copiado'))+'</small></button>'}).join('')+'<button type="button" class="cp" data-cp>Copiar o texto<small>para colar em qualquer IA</small></button><button type="button" data-dl>Baixar arquivo<small>para anexar se o texto for longo demais</small></button></div><button type="button" class="fx">Cancelar</button></div>';
document.body.appendChild(m);
m.addEventListener('click',function(ev){if(ev.target===m||ev.target.classList.contains('fx')){m.remove();return}
if(ev.target.closest('[data-cp]')){copiar(pr);m.remove();toast('Texto copiado. É só colar na IA que você preferir (Ctrl+V ou segurar e colar).',8000);return}
if(ev.target.closest('[data-dl]')){var u=URL.createObjectURL(new Blob([pr],{type:'text/plain;charset=utf-8'})),l=document.createElement('a');l.href=u;l.download='relatorio-'+tipo+'.txt';document.body.appendChild(l);l.click();l.remove();setTimeout(function(){URL.revokeObjectURL(u)},4000);m.remove();toast('Arquivo baixado. Anexe na conversa da sua IA.',8000);return}
var b=ev.target.closest('[data-k]');if(!b)return;ls('dv_minha_ia',b.dataset.k);var w=window.open('about:blank','_blank');m.remove();abrir(b.dataset.k,pr,w)})};
})();</script>
<?php
}, 95 );

// Números grandes (KPIs) que não cabem no quadro: diminui a letra até caber, em qualquer tamanho de tela ou zoom.
add_action( 'wp_footer', function () { ?>
<style>.rx-kpis>div,.dv-kpis>div,.kpi{min-width:0}</style>
<script>(function(){var SEL='.rx-kpis b,.rx-kpis strong,.kpi b,.kpi strong,.dv-kpis b,.dv-kpis strong,#dv-capa b,#dv-capa strong,.dv-pj b,.dv-pj strong,.cp-k b,.cp-k strong,.st .n,.dv-val b';
function ajusta(){[].forEach.call(document.querySelectorAll(SEL),function(e){if(e.children.length>1)return;var tx=e.textContent.trim();if(tx.length>24||!/\d/.test(tx))return;var p=e.parentElement;if(!p)return;if(!e.dataset.fs){var f=parseFloat(getComputedStyle(e).fontSize);if(f<20)return;e.dataset.fs=f}var f0=+e.dataset.fs,f=f0;e.style.fontSize='';e.style.whiteSpace='nowrap';var cs=getComputedStyle(p),lim=p.clientWidth-parseFloat(cs.paddingLeft)-parseFloat(cs.paddingRight);if(lim<=0)return;var w=e.scrollWidth;if(w>lim){f=Math.max(13,Math.floor(f0*lim/w*0.97));e.style.fontSize=f+'px'}})}
var t;function ag(){clearTimeout(t);t=setTimeout(ajusta,250)}window.addEventListener('resize',ag);window.addEventListener('load',ag);new MutationObserver(ag).observe(document.body,{childList:true,subtree:true});ag();})();</script>
<?php
}, 96 );


// Guarda os dados brutos de cada consulta (para o Excel completo), inclusive nos relatórios salvos.
add_action( 'wp_head', function () {
	if ( is_admin() || ! is_user_logged_in() ) { return; }
	$api = esc_url_raw( rest_url( 'desenvolve/v1/' ) ); $nonce = wp_create_nonce( 'wp_rest' ); ?>
<script>(function(){window.DVRAW=window.DVRAW||{};var of=window.fetch;if(!of)return;var API=<?php echo wp_json_encode( $api ); ?>,NONCE=<?php echo wp_json_encode( $nonce ); ?>;
var OK=/\/(dvtools|desenvolve|int)\/[^?]*(fornecedor|comprador|concorrente|dossie|processos|noticias|vencidas|participac|contratos|sanc|diarios|filiais|unidades|orgao)/,NAO=/\/(relatorio|dossie\/job|dossie\/rodar|admin|notific|credit|valores|saldo|alerta|monitor)/;
window.fetch=function(u,o){var U=String(u&&u.url||u),m=String(o&&o.method||'GET').toUpperCase();
 if(/\/desenvolve\/v1\/relatorio(\?|$)/.test(U)&&m==='POST'&&o&&typeof o.body==='string'){try{var b=JSON.parse(o.body),raw=JSON.stringify(window.DVRAW);if(raw.length>2&&raw.length<6e6){b.brutos=raw;o=Object.assign({},o,{body:JSON.stringify(b)});return of.call(this,u,o)}}catch(e){}}
 var pr=of.apply(this,arguments);
 if(OK.test(U)&&!NAO.test(U))pr.then(function(r){if(!r.ok)return;r.clone().json().then(function(j){if(j&&typeof j==='object'&&!j.code){var k=U.replace(/^https?:\/\/[^\/]+/,'').replace(/[?&]_?(nonce|_wpnonce|t)=[^&]*/g,'');window.DVRAW[k.split('?')[0]+(/(processos|vencidas|participacoes)/.test(k)?'':'')]=j}}).catch(function(){})}).catch(function(){});
 return pr};
var REL=new URLSearchParams(location.search).get('rel');if(REL)of(API+'relatorio-dados?id='+encodeURIComponent(REL),{credentials:'same-origin',headers:{'X-WP-Nonce':NONCE}}).then(function(r){return r.ok?r.json():null}).then(function(j){if(j&&j.brutos){try{var d=JSON.parse(j.brutos);Object.keys(d).forEach(function(k){if(!window.DVRAW[k])window.DVRAW[k]=d[k]})}catch(e){}}}).catch(function(){});
})();</script>
<?php
}, 1 );

add_filter( 'rest_request_after_callbacks', function ( $resp, $handler, $req ) {
	if ( $req->get_route() !== '/desenvolve/v1/relatorio' || $req->get_method() !== 'POST' || is_wp_error( $resp ) ) { return $resp; }
	$b = (string) $req->get_param( 'brutos' ); $d = $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;
	$id = is_array( $d ) ? (int) ( $d['id'] ?? 0 ) : 0;
	if ( $id && $b !== '' && strlen( $b ) < 6000000 && json_decode( $b ) !== null ) {
		set_transient( 'dv_relraw_' . $id, base64_encode( gzcompress( $b, 6 ) ), 31 * DAY_IN_SECONDS );
	}
	return $resp;
}, 10, 3 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/relatorio-dados', array( 'methods' => 'GET', 'permission_callback' => function () { return is_user_logged_in(); },
		'callback' => function ( WP_REST_Request $r ) {
			global $wpdb; $id = (int) $r['id']; $t = $wpdb->prefix . 'dv_rel';
			$dono = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $t WHERE id=%d", $id ) );
			if ( ! $id || ( $dono !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) { return new WP_Error( 'dv_rel', 'Relatório não encontrado.', array( 'status' => 404 ) ); }
			$v = get_transient( 'dv_relraw_' . $id );
			return array( 'brutos' => $v ? (string) gzuncompress( base64_decode( $v ) ) : '' );
		} ) );
} );

// Buscador: filtros alinhados no padrão da página inicial (rótulo + "(opcional)" na mesma linha, campos na mesma altura).
add_action( 'wp_head', function () { ?>
<style>
#bq2 label{font-size:.84rem!important;font-weight:600!important;margin:0 0 14px;color:#16191E}
#bq2 label>small{display:inline!important;margin:0 0 0 4px!important;font-size:.72rem!important;font-weight:400!important;color:#6E6A60}
#bq2 input:not([type=checkbox]):not([type=radio]),#bq2 select{height:52px;padding:0 16px;margin-top:7px;font:400 1rem "Hanken Grotesk",-apple-system,sans-serif!important;border:1px solid #CFC8B8;border-radius:12px;color:#16191E;background:#fff}
#bq2 input::placeholder{font-weight:400;color:#8A8F96;opacity:1}
#bq2 .bq2-seg{height:52px;margin-top:7px;border-radius:12px}#bq2 .bq2-seg b{display:flex;align-items:center;justify-content:center;padding:0 10px;font-size:.95rem;font-weight:600}
#bq2 .bq2-ufs{margin-top:9px}#bq2 .bq2-ufs span{font-size:.8rem;font-weight:600}
#bq2 small,#bq2 .bq2-h{font-weight:400}
html body #bq2 .bq2-act button[type=submit],html body.dv-app .dv-tool #bq2 button[type=submit]{background:#C9A45C!important;color:#241A06!important;border:0!important;border-radius:12px!important;height:55px;padding:0 28px!important;font:700 1.05rem "Hanken Grotesk",-apple-system,sans-serif!important}
@media(min-width:701px){
#bq2 .bq2-g{align-items:start}#bq2 .bq2-g3{align-items:end}
#bq2 .bq2-g{grid-template-columns:minmax(0,1fr) 320px}
#bq2 .bq2-g3{grid-template-columns:repeat(3,minmax(0,1fr))}
#bq2 .bq2-g3.bq2-perto{grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) minmax(0,1fr)}
}
</style>
<?php
}, 99 );

// Pedidos de orçamento: "Precisa de apoio nesta licitação?" (em cada licitação do buscador) e "Está começando?" (consultor).
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/orcamento', array( 'methods' => 'POST', 'permission_callback' => '__return_true',
		'callback' => function ( WP_REST_Request $r ) {
			$ip = preg_replace( '/[^0-9a-f:.]/i', '', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); $k = 'dv_orc_ip_' . md5( $ip );
			if ( (int) get_transient( $k ) >= 6 ) { return new WP_Error( 'dv_orc', 'Muitos pedidos seguidos. Tente de novo mais tarde.', array( 'status' => 429 ) ); }
			$p = array(
				'tipo'  => in_array( $r['tipo'], array( 'licitacao', 'consultor', 'seguro', 'revisao', 'cnae' ), true ) ? $r['tipo'] : 'consultor',
				'nome'  => mb_substr( sanitize_text_field( (string) $r['nome'] ), 0, 120 ),
				'email' => sanitize_email( (string) $r['email'] ),
				'whats' => mb_substr( preg_replace( '/[^0-9()+ -]/', '', (string) $r['whats'] ), 0, 30 ),
				'msg'   => mb_substr( sanitize_textarea_field( (string) $r['msg'] ), 0, 3000 ),
				'ref'   => mb_substr( sanitize_textarea_field( (string) $r['ref'] ), 0, 1500 ),
				'url'   => esc_url_raw( (string) $r['url'] ),
				'uid'   => get_current_user_id(),
				'data'  => current_time( 'mysql' ),
			);
			if ( $p['nome'] === '' || ( ! is_email( $p['email'] ) && strlen( preg_replace( '/\D/', '', $p['whats'] ) ) < 10 ) ) {
				return new WP_Error( 'dv_orc', 'Informe seu nome e um e-mail ou WhatsApp para recebermos o pedido.', array( 'status' => 400 ) );
			}
			set_transient( $k, (int) get_transient( $k ) + 1, HOUR_IN_SECONDS );
			$l = get_option( 'dv_orcamentos', array() ); if ( ! is_array( $l ) ) { $l = array(); }
			array_unshift( $l, $p ); update_option( 'dv_orcamentos', array_slice( $l, 0, 1000 ), false );
			$tit = $p['tipo'] === 'licitacao' ? 'Pedido de orçamento: apoio em licitação' : ( $p['tipo'] === 'seguro' ? 'Cotação de seguro garantia' : ( $p['tipo'] === 'revisao' ? 'Pedido de revisão por advogado' : ( $p['tipo'] === 'cnae' ? 'Pedido de orçamento: incluir atividade (CNAE) no contrato social' : 'Pedido de orçamento: consultoria' ) ) );
			$txt = "$tit\n\nNome: {$p['nome']}\nE-mail: {$p['email']}\nWhatsApp: {$p['whats']}\n\nO que precisa:\n{$p['msg']}\n\n" . ( $p['ref'] ? "Licitação:\n{$p['ref']}\n\n" : '' ) . "Página: {$p['url']}\n\nVer todos: " . admin_url( 'admin.php?page=dv-orcamentos' );
			$h = is_email( $p['email'] ) ? array( 'Reply-To: ' . $p['nome'] . ' <' . $p['email'] . '>' ) : array();
			wp_mail( get_option( 'admin_email' ), $tit . ' - ' . $p['nome'], $txt, $h );
			return array( 'ok' => true );
		} ) );
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Pedidos de orçamento', 'Pedidos de orçamento', 'manage_options', 'dv-orcamentos', function () {
		$l = get_option( 'dv_orcamentos', array() ); if ( ! is_array( $l ) ) { $l = array(); }
		echo '<div class="wrap"><h1>Pedidos de orçamento (' . count( $l ) . ')</h1><table class="widefat striped"><thead><tr><th>Data</th><th>Tipo</th><th>Nome</th><th>E-mail</th><th>WhatsApp</th><th>O que precisa</th><th>Licitação</th></tr></thead><tbody>';
		if ( ! $l ) { echo '<tr><td colspan="7">Nenhum pedido ainda.</td></tr>'; }
		foreach ( $l as $p ) {
			$w = preg_replace( '/\D/', '', $p['whats'] ); if ( $w && strlen( $w ) <= 11 ) { $w = '55' . $w; }
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $p['data'] ) ) . '</td><td>' . ( $p['tipo'] === 'licitacao' ? 'Apoio em licitação' : ( $p['tipo'] === 'seguro' ? '<b>Seguro garantia</b>' : ( $p['tipo'] === 'revisao' ? '<b>Revisão por advogado</b>' : ( $p['tipo'] === 'cnae' ? '<b>Alteração de CNAE</b>' : 'Consultor' ) ) ) ) . '</td><td>' . esc_html( $p['nome'] ) . '</td><td>' . esc_html( $p['email'] ) . '</td><td>' . ( $w ? '<a href="https://wa.me/' . esc_attr( $w ) . '" target="_blank" rel="noopener">' . esc_html( $p['whats'] ) . '</a>' : '' ) . '</td><td>' . nl2br( esc_html( $p['msg'] ) ) . '</td><td>' . nl2br( esc_html( $p['ref'] ) ) . ( $p['url'] ? '<br><a href="' . esc_url( $p['url'] ) . '" target="_blank">página</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	} );
}, 30 );

add_action( 'wp_footer', function () {
	$u = wp_get_current_user(); $nome = $u && $u->ID ? $u->display_name : ''; $mail = $u && $u->ID ? $u->user_email : ''; ?>
<style>
.dv-apoio{background:#FFF9EC!important;border:1px solid #C9A45C!important;color:#6B4E14!important;font-weight:700!important}.dv-seg{background:#EEF4FB!important;border:1px solid #9DB6D3!important;color:#1F3F66!important;font-weight:700!important}.dv-decl{background:#fff!important;border:1px solid #CFC8B8!important;color:#16191E!important;font-weight:700!important}
#dv-consult{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#16191E;color:#F4F2EC;border-radius:16px;padding:18px 22px;margin:18px 0 6px;font-family:"Hanken Grotesk",-apple-system,sans-serif}
#dv-consult b{display:block;font-size:1.08rem;font-weight:800}#dv-consult span{font-size:.92rem;color:#C9C3B6}
#dv-consult button{background:#C9A45C!important;color:#241A06!important;border:0!important;border-radius:12px!important;padding:12px 18px!important;font:700 .95rem "Hanken Grotesk",-apple-system,sans-serif!important;cursor:pointer;white-space:nowrap}
#dv-orc{position:fixed;inset:0;background:rgba(12,15,19,.55);display:flex;align-items:center;justify-content:center;z-index:100000;padding:16px}
#dv-orc .bx{background:#FBF9F4;color:#16191E;border-radius:16px;max-width:460px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.3);font-family:"Hanken Grotesk",-apple-system,sans-serif;max-height:92vh;overflow:auto}
#dv-orc h3{margin:0 0 4px;font-size:1.2rem;font-weight:800}#dv-orc p{margin:0 0 12px;font-size:.9rem;color:#5E5A52;line-height:1.45}
#dv-orc .rf{background:#F1ECE1;border-radius:10px;padding:8px 10px;font-size:.82rem;color:#4A4D52;margin-bottom:12px;max-height:90px;overflow:auto}
#dv-orc label{display:block;font-size:.82rem;font-weight:600;margin:0 0 10px}
#dv-orc input,#dv-orc textarea{display:block;width:100%;box-sizing:border-box;margin-top:5px;border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:400 .95rem "Hanken Grotesk",-apple-system,sans-serif;background:#fff;color:#16191E}
#dv-orc textarea{min-height:90px;resize:vertical}
#dv-orc .ac{display:flex;gap:8px;justify-content:flex-end;margin-top:6px}
#dv-orc .ac button{border-radius:10px;padding:11px 16px;font:700 .92rem "Hanken Grotesk",-apple-system,sans-serif;cursor:pointer;border:1px solid #16191E}
#dv-orc .ac .ok{background:#C9A45C!important;border-color:#C9A45C!important;color:#241A06!important}#dv-orc .ac .fx{background:#fff!important;color:#16191E!important;border-color:#E2DCCD!important}
#dv-orc .er{color:#9A3B2C;font-size:.85rem;min-height:1em;margin:4px 0}
</style>
<script>(function(){var API=<?php echo wp_json_encode( esc_url_raw( rest_url( 'desenvolve/v1/orcamento' ) ) ); ?>,NOME=<?php echo wp_json_encode( $nome ); ?>,MAIL=<?php echo wp_json_encode( $mail ); ?>;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function abre(tipo,ref){var m=document.getElementById('dv-orc');if(m)m.remove();m=document.createElement('div');m.id='dv-orc';
 var t=tipo==='cnae'?['Incluir a atividade no contrato social','Fazemos a alteração do contrato social e do CNAE para você poder disputar. Diga o que quer incluir e mandamos o orçamento.','Ex.: vendo limpeza e quero disputar também jardinagem e controle de pragas']:tipo==='revisao'?['Revisão por advogado','Um advogado especialista revisa a sua peça antes de protocolar. Diga o prazo e mandamos o orçamento na hora.','Ex.: a IA montou a impugnação; preciso de revisão até amanhã às 18h']:tipo==='seguro'?['Cotar seguro garantia','Mandamos a cotação rápido, sem você trocar de corretor.','Ex.: garantia de proposta de 1% do valor estimado; vigência até…']:tipo==='licitacao'?['Apoio especializado nesta licitação','Diga o que você precisa e mandamos um orçamento.','Ex.: análise do edital, documentos de habilitação, proposta, impugnação, recurso…']:['Fale com um consultor','Está começando? Conte o que precisa e mandamos um orçamento. Preços acessíveis para pequenas empresas.','Ex.: quero começar a vender para prefeituras, preciso de ajuda com cadastro e documentos…'];
 m.innerHTML='<div class="bx" role="dialog" aria-modal="true"><h3>'+t[0]+'</h3><p>'+t[1]+'</p>'+(ref?'<div class="rf">'+e(ref).replace(/\n/g,'<br>')+'</div>':'')+'<label>Seu nome<input name="nome" value="'+e(NOME)+'" autocomplete="name"></label><label>E-mail<input name="email" type="email" value="'+e(MAIL)+'" autocomplete="email"></label><label>WhatsApp<input name="whats" inputmode="tel" placeholder="(11) 99999-9999" autocomplete="tel"></label>'+(tipo==='seguro'?'<label>CNPJ da sua empresa<input name="cnpj" inputmode="numeric" placeholder="00.000.000/0000-00"></label><label>Tipo de garantia<select name="gar" style="display:block;width:100%;margin-top:5px;border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:400 .95rem Hanken Grotesk,sans-serif;background:#fff"><option>Garantia de proposta (para participar)</option><option>Garantia de execução do contrato</option><option>Ainda não sei</option></select></label>':'')+'<label>'+(tipo==='seguro'?'Percentual ou valor exigido no edital (se souber)':'O que você precisa?')+'<textarea name="msg" placeholder="'+t[2]+'"></textarea></label><div class="er"></div><div class="ac"><button type="button" class="fx">Cancelar</button><button type="button" class="ok">Pedir orçamento</button></div></div>';
 document.body.appendChild(m);var q=function(n){return m.querySelector('[name='+n+']')};(NOME?q('msg'):q('nome')).focus();
 m.addEventListener('click',function(ev){if(ev.target===m||ev.target.classList.contains('fx')){m.remove();return}if(!ev.target.classList.contains('ok'))return;var b=ev.target;b.disabled=true;b.textContent='Enviando…';
  fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tipo:tipo,nome:q('nome').value,email:q('email').value,whats:q('whats').value,msg:(tipo==='seguro'?'CNPJ do tomador: '+q('cnpj').value+'\nGarantia: '+q('gar').value+'\nExigência: ':'')+q('msg').value,ref:ref||'',url:location.href})}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j}})}).then(function(x){if(!x.ok)throw new Error(x.j&&x.j.message||'Não foi possível enviar.');m.querySelector('.bx').innerHTML='<h3>Pedido recebido</h3><p>'+(tipo==='seguro'?'Obrigado! Vamos cotar nas seguradoras e mandar a proposta pelo e-mail ou WhatsApp que você informou.':'Obrigado! Vamos analisar e enviar o orçamento pelo e-mail ou WhatsApp que você informou.')+'</p><div class="ac"><button type="button" class="fx">Fechar</button></div>'}).catch(function(er){m.querySelector('.er').textContent=er.message;b.disabled=false;b.textContent='Pedir orçamento'})})}
window.dvOrcamento=abre;
function cartao(c){var o=c.querySelector('.o'),u=c.querySelector('.u'),t=c.querySelector('.t'),a=[].find.call(c.querySelectorAll('a[href^="http"]'),function(x){return /pncp|edital|compras|licita/i.test(x.href+x.textContent)});return[(o&&o.textContent.trim()),(u&&u.textContent.trim()),(t&&t.textContent.replace(/\s+/g,' ').trim()),(a&&a.href)].filter(Boolean).join('\n')}
setInterval(function(){[].forEach.call(document.querySelectorAll('.bq2-i .bq2-a'),function(a){if(a.querySelector('.dv-apoio'))return;var b=document.createElement('button');b.type='button';b.className='dv-apoio';b.textContent='Precisa de apoio nesta licitação?';var x=a.querySelector('.x');if(x)a.insertBefore(b,x);else a.appendChild(b);var g=document.createElement('button');g.type='button';g.className='dv-seg';g.textContent='🛡 Cotar seguro garantia';a.insertBefore(g,b);var d=document.createElement('button');d.type='button';d.className='dv-decl';d.textContent='📝 Declarações';a.insertBefore(d,b)});
 var f=document.getElementById('bq-form');if(f&&!document.getElementById('dv-consult')){var d=document.createElement('div');d.id='dv-consult';d.innerHTML='<div><b>Está começando? Precisa de ajuda?</b><span>Fale com um consultor. Preços acessíveis para pequenas empresas.</span></div><button type="button">Falar com um consultor</button>';f.parentNode.insertBefore(d,f.nextSibling)}},900);
document.addEventListener('click',function(ev){var sg=ev.target.closest&&ev.target.closest('.dv-seg');if(sg){ev.preventDefault();var c=sg.closest('.bq2-i'),R=c&&c.dataset.r,j=(window.DVBQD||{})[R],ex=cartao(c);if(j&&j.valorTotalEstimado)ex+='\nValor estimado: '+Number(j.valorTotalEstimado).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});if(j&&j.dataEncerramentoProposta)ex+='\nSessão: '+new Date(j.dataEncerramentoProposta).toLocaleString('pt-BR').slice(0,17);if(j&&j.numeroCompra)ex+='\nNº '+j.numeroCompra+'/'+j.anoCompra+(j.processo?' · processo '+j.processo:'');abre('seguro',ex);return}var dc=ev.target.closest&&ev.target.closest('.dv-decl');if(dc){ev.preventDefault();var c2=dc.closest('.bq2-i'),R2=c2&&c2.dataset.r,j2=(window.DVBQD||{})[R2]||{},x2=(window.DVBQ||{})[R2]||{};if(window.dvDeclaracoes)window.dvDeclaracoes({orgao:(j2.orgaoEntidade&&j2.orgaoEntidade.razaoSocial)||x2.orgao_nome||x2.orgao||'',unidade:(j2.unidadeOrgao&&j2.unidadeOrgao.nomeUnidade)||x2.unidade_nome||'',mod:(j2.modalidadeNome||x2.modalidade_licitacao_nome||x2.modalidade||''),num:j2.numeroCompra?j2.numeroCompra+'/'+j2.anoCompra:(x2.numero||''),proc:j2.processo||'',cidade:(j2.unidadeOrgao&&j2.unidadeOrgao.municipioNome)||x2.municipio_nome||x2.municipio||'',uf:(j2.unidadeOrgao&&j2.unidadeOrgao.ufSigla)||x2.uf||''});return}var b=ev.target.closest&&ev.target.closest('.dv-apoio');if(b){ev.preventDefault();abre('licitacao',cartao(b.closest('.bq2-i')||b.parentNode));return}if(ev.target.closest&&ev.target.closest('#dv-consult button')){ev.preventDefault();abre('consultor','')}});
})();</script>
<?php
}, 97 );

// Seleção de licitações (fica salva ao trocar de tela) + relatório único em Word (.docx) com todos os detalhes;
// e, nos cartões, onde acontece a disputa / onde pegar o edital (PNCP e diários oficiais).
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; } ?>
<style>
.dv-sel{display:inline-flex;align-items:center;gap:7px;font:600 .8rem "Hanken Grotesk",-apple-system,sans-serif;color:#16191E;cursor:pointer;margin:0 0 8px;user-select:none}
.dv-sel input{width:18px!important;height:18px!important;margin:0!important;accent-color:#16191E;cursor:pointer}
.bq2-i.dv-on{border-color:#C9A45C!important;box-shadow:0 0 0 2px rgba(201,164,92,.35)}
.dv-mais{margin:10px 0 0;padding:10px 12px;background:#F7F4EC;border-radius:10px;font:400 .84rem/1.5 "Hanken Grotesk",-apple-system,sans-serif;color:#2A2D33}
.dv-mais b{font-weight:700}.dv-mais a{color:#86621F;font-weight:700;word-break:break-all}.dv-mais div+div{margin-top:3px}
.dv-mais details{margin-top:6px}.dv-mais summary{cursor:pointer;font-weight:700;color:#6B4E14}.dv-mais .tx{white-space:pre-wrap;margin-top:6px;color:#4A4D52;max-height:260px;overflow:auto}
.bq2-ch a.dv-onde{font-size:.78rem;font-weight:700;border-radius:8px;padding:4px 9px;background:#EEF2F7;color:#2B4A6B;text-decoration:none}
#dv-selbar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:99990;display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center;background:#16191E;color:#F4F2EC;border-radius:14px;padding:10px 12px 10px 18px;box-shadow:0 14px 40px rgba(0,0,0,.3);font:500 .92rem "Hanken Grotesk",-apple-system,sans-serif;max-width:94vw}
#dv-selbar b{font-weight:800}
#dv-selbar button{border-radius:10px!important;padding:9px 14px!important;font:700 .88rem "Hanken Grotesk",-apple-system,sans-serif!important;cursor:pointer;border:0!important}
#dv-selbar .w{background:#2B579A!important;color:#fff!important}#dv-selbar .l{background:transparent!important;color:#C9C3B6!important;border:1px solid #444!important}
@media print{#dv-selbar{display:none!important}}
</style>
<script>(function(){var K='dv_sel_lic';window.DVBQ=window.DVBQ||{};window.DVBQD=window.DVBQD||{};
/* guarda os dados brutos que o buscador recebe (diários e PNCP) */
var of=window.fetch;window.fetch=function(u,o){var pr=of.apply(this,arguments),U=String(u&&u.url||u);
 if(/buscador\/diarios/.test(U))pr.then(function(r){r.clone().json().then(function(j){(j.itens||[]).forEach(function(x){if(x.ref)DVBQ[x.ref]=x});ag()}).catch(function(){})}).catch(function(){});
 else if(/pncp\.gov\.br\/api\/search/.test(U))pr.then(function(r){r.clone().json().then(function(j){(j.items||[]).forEach(function(x){var m=/compras\/(\d{14})\/(\d{4})\/(\d+)/.exec(x.item_url||'');if(m)DVBQ[m[1]+'/'+m[2]+'/'+m[3]]=x})}).catch(function(){})}).catch(function(){});
 else{var m=/pncp\.gov\.br\/api\/consulta\/v1\/orgaos\/(\d{14})\/compras\/(\d{4})\/(\d+)$/.exec(U);if(m)pr.then(function(r){r.clone().json().then(function(j){DVBQD[m[1]+'/'+m[2]+'/'+m[3]]=j;ag()}).catch(function(){})}).catch(function(){})}
 return pr};
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
var PLAT=[[/bll\.org|\bbll\b/i,'BLL Compras','https://bllcompras.com'],[/bnc\.org|\bbnc\b/i,'BNC','https://bnc.org.br'],[/licitanet/i,'Licitanet','https://licitanet.com.br'],[/portal\s*de\s*compras\s*p[uú]blicas|portaldecompraspublicas/i,'Portal de Compras Públicas','https://www.portaldecompraspublicas.com.br'],[/comprasnet|compras\.gov\.br|gov\.br\/compras|compras governamentais/i,'Compras.gov.br','https://www.gov.br/compras'],[/licitacoes-e|licita[cç][oõ]es-e|banco do brasil/i,'Licitações-e (Banco do Brasil)','https://www.licitacoes-e.com.br'],[/bbmnet/i,'BBMNET','https://bbmnet.com.br'],[/licitar\s*digital/i,'Licitar Digital','https://licitardigital.com.br'],[/bec\.sp|bolsa eletr[oô]nica de compras/i,'BEC/SP','https://www.bec.sp.gov.br'],[/compras\s*br\b|comprasbr/i,'ComprasBR','https://comprasbr.com.br'],[/ammlicita|amm licita/i,'AMM Licita','https://ammlicita.org.br'],[/publinexo/i,'Publinexo',''],[/e-?licitacoes|e-lic/i,'e-Licitações',''],[/licitamaisbrasil|licita\s*\+\s*brasil/i,'Licita+Brasil','']];
function onde(tx){tx=String(tx||'');var r={plat:[],urls:[],mails:[],fones:[],local:''};PLAT.forEach(function(p){if(p[0].test(tx))r.plat.push({n:p[1],u:p[2]})});
 (tx.match(/https?:\/\/[^\s,;)"'<>]+|www\.[^\s,;)"'<>]+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:gov|com|org|net|leg|jus)\.br(?:\/[^\s,;)"'<>]*)?/gi)||[]).forEach(function(u){u=u.replace(/[.,:]+$/,'');if(tx.indexOf('@'+u)>=0)return;if(r.urls.indexOf(u)<0&&r.urls.length<6)r.urls.push(u)});
 (tx.match(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g)||[]).forEach(function(m){m=m.replace(/[.,]+$/,'');if(r.mails.indexOf(m)<0&&r.mails.length<4)r.mails.push(m)});
 (tx.match(/\(?\b\d{2}\)?\s?\d{4,5}[-.\s]?\d{4}\b/g)||[]).forEach(function(f){if(r.fones.indexOf(f)<0&&r.fones.length<3)r.fones.push(f.trim())});
 var S='(?:[^.]|\\.(?=\\S))';var m=tx.match(new RegExp(S+'{0,80}(?:edital|instrumento convocat[oó]rio)'+S+'{0,40}(?:dispon[ií]ve|obtid|retirad|adquirid|encontra)'+S+'{0,220}','i'));if(m)r.local=m[0].trim();
 var m2=tx.match(new RegExp(S+'{0,60}(?:sess[aã]o|disputa|abertura)'+S+'{0,80}(?:realizad|ocorrer|acontecer|atrav[eé]s|por meio|no site|na plataforma|no endere[cç]o)'+S+'{0,180}','i'));if(m2)r.sessao=m2[0].trim();return r}
function href(u){return /^https?:/i.test(u)?u:'https://'+u}
function host(u){try{return new URL(href(u)).hostname.replace(/^www\./,'')}catch(x){return u}}
/* cartões: mostra onde acontece a disputa / onde pegar o edital */
function cartoes(){[].forEach.call(document.querySelectorAll('.bq2-i[data-r]'),function(c){var r=c.dataset.r;
 if(c.classList.contains('bq2-dio')){var x=DVBQ[r];if(!x||c.querySelector('.dv-mais'))return;var tx=(x.titulo||'')+' '+(x.texto||x.trecho||'');var o=onde(tx),h='';
  if(o.plat.length)h+='<div><b>Onde acontece a disputa:</b> '+o.plat.map(function(p){return p.u?'<a href="'+e(p.u)+'" target="_blank" rel="noopener">'+e(p.n)+'</a>':e(p.n)}).join(', ')+'</div>';
  if(o.urls.length)h+='<div><b>Sites citados no aviso:</b> '+o.urls.map(function(u){return'<a href="'+e(href(u))+'" target="_blank" rel="noopener">'+e(u)+'</a>'}).join(' · ')+'</div>';
  if(o.local)h+='<div><b>Onde pegar o edital:</b> '+e(o.local)+'</div>';else if(o.sessao)h+='<div><b>Sessão:</b> '+e(o.sessao)+'</div>';
  if(o.mails.length||o.fones.length)h+='<div><b>Contato:</b> '+o.mails.concat(o.fones).map(e).join(' · ')+'</div>';
  if(!h)h='<div><b>Onde pegar o edital:</b> o aviso não informa o site. Procure no portal da '+e(x.orgao||'prefeitura')+' ou peça pelo e-mail/telefone do setor de licitações.</div>';
  h+='<details><summary>Ver o aviso completo</summary><div class="tx">'+e(tx.trim())+'</div></details>';
  var d=document.createElement('div');d.className='dv-mais';d.innerHTML=h;var u=c.querySelector('.bq2-ch')||c.querySelector('.u');u.parentNode.insertBefore(d,u.nextSibling)}
 else{var j=DVBQD[r];if(!j||c.querySelector('.dv-onde'))return;var ch=c.querySelector('.bq2-ch');if(!ch)return;var l=j.linkSistemaOrigem||j.linkProcessoEletronico;if(l){var a=document.createElement('a');a.className='dv-onde';a.href=href(l);a.target='_blank';a.rel='noopener';a.textContent='Disputa em: '+host(l);ch.appendChild(a)}else if(j.usuarioNome){var sp=document.createElement('span');sp.className='dv-onde';sp.textContent='Sistema: '+j.usuarioNome;ch.appendChild(sp)}}})}
var tq;function ag(){clearTimeout(tq);tq=setTimeout(function(){cartoes();marca()},250)}
/* seleção */
function ler(){try{return JSON.parse(localStorage.getItem(K)||'[]')||[]}catch(x){return[]}}
function grava(l){try{localStorage.setItem(K,JSON.stringify(l))}catch(x){alert('Não foi possível guardar a seleção neste navegador.')}barra()}
function T(n){return n?n.textContent.replace(/\s+/g,' ').trim():''}
function dados(c){var o=c.querySelector('.o'),u=c.querySelector('.u'),t=c.querySelector('.t'),r=c.dataset.r||'',ln=[].find.call(c.querySelectorAll('a[href^="http"]'),function(x){return /pncp|edital|compras|licita|diario/i.test(x.href+x.textContent)&&!x.classList.contains('dv-onde')});
 var ts=t?[].map.call(t.children.length?t.children:[t],T).filter(Boolean):[];var ch=c.querySelector('.bq2-ch');var chips=ch?[].map.call(ch.children,T).filter(Boolean):[];
 return{ref:r,dio:c.classList.contains('bq2-dio'),obj:T(o),org:T(u),meta:ts,chips:chips,link:ln?ln.href:'',raw:DVBQ[r]||null,det:DVBQD[r]||null,em:new Date().toISOString()}}
function chave(d){return d.ref||d.link||(d.obj+'|'+d.org).slice(0,300)}
function marca(){var L=ler(),S={};L.forEach(function(d){S[chave(d)]=1});
 [].forEach.call(document.querySelectorAll('.bq2-i'),function(c){var cb=c.querySelector('.dv-sel input');if(!cb){var lb=document.createElement('label');lb.className='dv-sel';lb.innerHTML='<input type="checkbox"> Selecionar para o relatório';c.insertBefore(lb,c.firstChild);cb=lb.querySelector('input')}
  var k=chave(dados(c));cb.checked=!!S[k];c.classList.toggle('dv-on',cb.checked)})}
function barra(){var L=ler(),b=document.getElementById('dv-selbar');if(!L.length){if(b)b.remove();return}
 if(!b){b=document.createElement('div');b.id='dv-selbar';document.body.appendChild(b)}
 b.innerHTML='<span><b>'+L.length+'</b> licitaç'+(L.length>1?'ões selecionadas':'ão selecionada')+'</span><button type="button" class="w">⬇ Gerar relatório em Word</button><button type="button" class="l">Limpar seleção</button>'}
document.addEventListener('change',function(ev){var cb=ev.target;if(!cb.closest||!cb.closest('.dv-sel'))return;var c=cb.closest('.bq2-i');if(!c)return;var d=dados(c),k=chave(d),L=ler().filter(function(x){return chave(x)!==k});if(cb.checked)L.push(d);c.classList.toggle('dv-on',cb.checked);grava(L)});
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('#dv-selbar button');if(!b)return;ev.preventDefault();if(b.classList.contains('l')){if(confirm('Limpar todas as licitações selecionadas?')){grava([]);marca()}return}word(b)});
/* Word */
function br(d){if(!d)return'';var x=new Date(String(d).length<=10?d+'T12:00:00':d);return isNaN(x)?String(d):x.toLocaleDateString('pt-BR')+(String(d).length>10?' às '+x.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}):'')}
function rs(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):''}
function pj(u){return of(u).then(function(r){return r.ok?r.json():null}).catch(function(){return null})}
function completa(d){if(d.dio||!/^\d{14}\/\d{4}\/\d+$/.test(d.ref))return Promise.resolve(d);var c=d.ref.split('/'),b='https://pncp.gov.br/api/pncp/v1/orgaos/'+c[0]+'/compras/'+c[1]+'/'+c[2];
 return Promise.all([d.det?Promise.resolve(d.det):pj('https://pncp.gov.br/api/consulta/v1/orgaos/'+c[0]+'/compras/'+c[1]+'/'+c[2]),pj(b+'/arquivos'),pj(b+'/itens?pagina=1&tamanhoPagina=200')]).then(function(a){d.det=a[0]||d.det;d.arq=Array.isArray(a[1])?a[1]:[];d.itens=Array.isArray(a[2])?a[2]:[];return d})}
function valorDe(d){if(d.det&&d.det.valorTotalEstimado)return+d.det.valorTotalEstimado;var s=(d.chips.join(' ')).match(/R\$\s?([\d.]+,\d{2})/);return s?parseFloat(s[1].replace(/\./g,'').replace(',','.')):0}
function word(btn){var L0=ler();if(!L0.length)return;btn.disabled=true;btn.textContent='Buscando os detalhes…';
 var lib=window.docx?Promise.resolve():new Promise(function(ok,no){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.umd.js';s.onload=ok;s.onerror=no;document.head.appendChild(s)});
 lib.then(function(){return Promise.all(L0.map(completa))}).then(function(L){btn.textContent='Montando o Word…';var D=window.docx,P=D.Paragraph,R=D.TextRun,H=D.HeadingLevel,hoje=new Date(),tot=0;L.forEach(function(d){tot+=valorDe(d)});
  var cor='16191E',ouro='A8843F',cinza='6E6A60',bd={style:D.BorderStyle.SINGLE,size:4,color:'E3DED2'},bs={top:bd,bottom:bd,left:bd,right:bd};
  function run(t,o){o=o||{};return new R({text:String(t==null?'':t),bold:o.b,italics:o.i,size:o.s||20,color:o.c||cor})}
  function par(ch,o){o=o||{};return new P({children:ch,spacing:{after:o.after==null?80:o.after,before:o.before||0},heading:o.h,pageBreakBefore:o.pb,bullet:o.bl?{level:0}:undefined})}
  function link(u,t){return new D.ExternalHyperlink({link:href(u),children:[new R({text:t||u,style:'Hyperlink',size:20})]})}
  function cel(ch,w,fill){return new D.TableCell({width:{size:w,type:D.WidthType.PERCENTAGE},shading:fill?{fill:fill,type:D.ShadingType.CLEAR,color:'auto'}:undefined,borders:bs,margins:{top:50,bottom:50,left:90,right:90},children:[new P({children:ch})]})}
  function sep(a){var o=[];a.forEach(function(x,i){if(i)o.push(run(' · ',{s:19,c:cinza}));o.push(x)});return o}
  function tabelaKV(rows){return new D.Table({width:{size:100,type:D.WidthType.PERCENTAGE},columnWidths:[3000,7100],rows:rows.filter(function(r){return r[1]&&(!Array.isArray(r[1])||r[1].length)}).map(function(r){return new D.TableRow({children:[cel([run(r[0],{b:1,s:18,c:cinza})],30,'F5F1E8'),cel(Array.isArray(r[1])?sep(r[1]):[run(r[1],{s:19})],70)]})})})}
  var linhas=[new D.TableRow({tableHeader:true,children:[cel([run('Nº',{b:1,s:18,c:'FFFFFF'})],5,cor),cel([run('Objeto',{b:1,s:18,c:'FFFFFF'})],37,cor),cel([run('Órgão e local',{b:1,s:18,c:'FFFFFF'})],25,cor),cel([run('Sessão',{b:1,s:18,c:'FFFFFF'})],15,cor),cel([run('Valor estimado',{b:1,s:18,c:'FFFFFF'})],18,cor)]})].concat(L.map(function(d,i){var j=d.det||{},x=d.raw||{},v=valorDe(d),uo=j.unidadeOrgao||{},lc=uo.municipioNome?uo.municipioNome+'/'+uo.ufSigla:[x.municipio||x.municipio_nome,x.uf].filter(Boolean).join('/');var ses=j.dataEncerramentoProposta||x.encerra||x.data_fim_vigencia||'';
   return new D.TableRow({children:[cel([run(i+1,{s:18})],5),cel([run((function(t){return t.length>220?t.slice(0,217)+'…':t})(String(j.objetoCompra||x.objeto||x.description||d.obj)),{s:18})],37),cel([run((j.orgaoEntidade&&j.orgaoEntidade.razaoSocial)||x.orgao||x.orgao_nome||d.org,{s:18}),run(lc?' · '+lc:'',{s:18,c:cinza})],25),cel([run(br(ses)||'Ver no aviso',{s:18})],15),cel([run(v?rs(v):(j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1?'Sigiloso':'Não informado'),{s:18})],18)]})}));
  var ch=[par([run('DESENVOLVE ESTRATÉGIAS',{b:1,c:ouro,s:18})]),par([run('Licitações selecionadas',{b:1,s:48})],{h:H.TITLE}),
   par([run(L.length+' licitaç'+(L.length>1?'ões':'ão')+(tot?' · '+rs(tot)+' em valor estimado':'')+' · gerado em '+hoje.toLocaleDateString('pt-BR')+' às '+hoje.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}),{c:cinza})],{after:200}),
   new D.Table({width:{size:100,type:D.WidthType.PERCENTAGE},columnWidths:[500,3700,2600,1500,1800],rows:linhas})];
  L.forEach(function(d,i){var j=d.det||{},x=d.raw||{};ch.push(par([run((i+1)+'. '+(j.objetoCompra||x.objeto||x.description||d.obj),{b:1,s:26})],{h:H.HEADING_1,pb:true,after:120}));
   if(!d.dio){var uo=j.unidadeOrgao||{},oe=j.orgaoEntidade||{},lsis=j.linkSistemaOrigem||j.linkProcessoEletronico||'';
    ch.push(tabelaKV([['Órgão',(oe.razaoSocial||x.orgao_nome||d.org)+(oe.cnpj?' · CNPJ '+String(oe.cnpj).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5'):'')],['Unidade compradora',uo.nomeUnidade?uo.nomeUnidade+(uo.codigoUnidade?' (código '+uo.codigoUnidade+')':''):''],['Local',uo.municipioNome?uo.municipioNome+'/'+uo.ufSigla:(x.municipio_nome?x.municipio_nome+'/'+x.uf:'')],['Modalidade',j.modalidadeNome||x.modalidade_licitacao_nome],['Número da compra',(j.numeroCompra?j.numeroCompra+(j.anoCompra?'/'+j.anoCompra:''):'')+(j.processo?' · processo '+j.processo:'')],['Instrumento',j.tipoInstrumentoConvocatorioNome],['Amparo legal',j.amparoLegal&&(j.amparoLegal.nome||j.amparoLegal.descricao)],['Modo de disputa',j.modoDisputaNome],['Registro de preços',j.srp===true?'Sim':j.srp===false?'Não':''],['Situação',j.situacaoCompraNome],['Abertura das propostas',br(j.dataAberturaProposta)],['Encerramento / sessão',br(j.dataEncerramentoProposta||x.data_fim_vigencia)],['Publicado no PNCP',br(j.dataPublicacaoPncp||x.data_publicacao_pncp)],['Valor estimado',j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1?'Sigiloso':rs(j.valorTotalEstimado)],['Nota CAPAG do ente (Tesouro)',(window.dvCapagDe&&dvCapagDe(x))||''],['Onde acontece a disputa',lsis?[link(lsis)]:(onde((j.informacaoComplementar||'')+' '+(j.objetoCompra||'')).plat.map(function(p){return p.n}).join(', ')||'')],['Sistema que publicou',j.usuarioNome||''],['Edital no PNCP',[link('https://pncp.gov.br/app/editais/'+d.ref)]]]));
    if(j.informacaoComplementar)ch.push(par([run('Informações complementares: ',{b:1}),run(j.informacaoComplementar)],{before:160}));
    if(d.arq&&d.arq.length){ch.push(par([run('Documentos publicados',{b:1,s:22})],{before:200,h:H.HEADING_2}));d.arq.forEach(function(a){ch.push(par([run((a.tipoDocumentoNome||'Documento')+': ',{b:1}),link(a.url||a.uri||'',a.titulo||a.url||'arquivo')],{bl:1}))})}
    if(d.itens&&d.itens.length){ch.push(par([run('Itens ('+d.itens.length+(d.itens.length>=200?'+':'')+')',{b:1,s:22})],{before:200,h:H.HEADING_2}));
     var rows=[new D.TableRow({tableHeader:true,children:[cel([run('Item',{b:1,s:16,c:'FFFFFF'})],7,cor),cel([run('Descrição',{b:1,s:16,c:'FFFFFF'})],51,cor),cel([run('Qtd.',{b:1,s:16,c:'FFFFFF'})],10,cor),cel([run('Unid.',{b:1,s:16,c:'FFFFFF'})],10,cor),cel([run('Valor unit.',{b:1,s:16,c:'FFFFFF'})],11,cor),cel([run('Total',{b:1,s:16,c:'FFFFFF'})],11,cor)]})];
     d.itens.forEach(function(t){rows.push(new D.TableRow({children:[cel([run(t.numeroItem,{s:16})],7),cel([run(t.descricao,{s:16}),run(t.criterioJulgamentoNome?' · '+t.criterioJulgamentoNome:'',{s:14,c:cinza})],51),cel([run((+t.quantidade||0).toLocaleString('pt-BR'),{s:16})],10),cel([run(t.unidadeMedida,{s:16})],10),cel([run(rs(t.valorUnitarioEstimado),{s:16})],11),cel([run(rs(t.valorTotal),{s:16})],11)]}))});
     ch.push(new D.Table({width:{size:100,type:D.WidthType.PERCENTAGE},columnWidths:[600,4900,900,800,1450,1450],rows:rows}))}}
   else{var tx=(x.titulo||'')+' '+(x.texto||x.trecho||''),o=onde(tx);
    ch.push(tabelaKV([['Órgão',x.orgao||d.org],['Local',[x.municipio,x.uf].filter(Boolean).join('/')],['Modalidade',(x.modalidade||'')+(x.numero?' nº '+x.numero:'')],['Sessão',br(x.encerra)||'Ver no aviso'],['Nota CAPAG do município (Tesouro)',(window.dvCapagDe&&dvCapagDe(x))||''],['Publicado',br(x.publicado)+(x.fonte_nome?' · '+x.fonte_nome:'')],['Onde acontece a disputa',o.plat.length?o.plat.map(function(p){return p.n}).join(', '):''],['Sites citados no aviso',o.urls.length?o.urls.map(function(u){return link(u)}):''],['Onde pegar o edital',o.local||(o.urls.length||o.plat.length?'':'O aviso não informa o site. Procure no portal do órgão ou peça ao setor de licitações.')],['Como será a sessão',o.sessao||''],['Contato',o.mails.concat(o.fones).join(' · ')],['Publicação no diário',x.link||d.link?[link(x.link||d.link)]:'']]));
    ch.push(par([run('Texto do aviso',{b:1,s:22})],{before:200,h:H.HEADING_2}));String(tx).trim().split(/\n+/).forEach(function(l){if(l.trim())ch.push(par([run(l.trim(),{s:19})]))})}
   ch.push(par([run('Anotações: ',{b:1,c:ouro}),run('_______________________________________________________',{c:'B9B4A7'})],{before:240}))});
  ch.push(par([run('Dados das fontes oficiais (PNCP e diários oficiais) na data da geração. Confira o edital antes de participar. Precisa de apoio especializado nesta licitação? Fale com a Desenvolve Estratégias: desenvolveestrategias.com.br',{i:1,s:17,c:'8A857A'})],{before:400}));
  var doc=new D.Document({creator:'Desenvolve Estratégias',title:'Licitações selecionadas',styles:{default:{document:{run:{font:'Calibri'}}}},sections:[{properties:{page:{margin:{top:900,bottom:900,left:900,right:900}}},children:ch}]});
  return D.Packer.toBlob(doc).then(function(bl){var a=document.createElement('a'),u=URL.createObjectURL(bl);a.href=u;a.download='Licitacoes selecionadas - '+hoje.toLocaleDateString('pt-BR').replace(/\//g,'-')+'.docx';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(u)},5000)})})
 .catch(function(er){console.error(er);alert('Não foi possível gerar o Word agora. Tente de novo em instantes.')}).then(function(){btn.disabled=false;btn.textContent='⬇ Gerar relatório em Word'})}
window.dvWordSel=word;window.dvOnde=onde;
var tm;new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(function(){cartoes();marca()},300)}).observe(document.body,{childList:true,subtree:true});marca();barra();
})();</script>
<?php
}, 98 );

// Gerador de declarações (Word) — preenche empresa (pelo CNPJ) e licitação; página /plataforma/declaracoes/ e botão em cada licitação.
add_shortcode( 'dv_declaracoes', function () { return '<div id="dv-decl-pg"></div>'; } );
add_action( 'init', function () {
	if ( get_option( 'dv_decl_pg' ) ) { return; }
	$pai = get_page_by_path( 'plataforma' ); if ( ! $pai ) { return; }
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Gerador de declarações', 'post_name' => 'declaracoes', 'post_parent' => $pai->ID, 'post_content' => '[dv_declaracoes]' ) );
	if ( $id && ! is_wp_error( $id ) ) { update_post_meta( $id, '_wp_page_template', get_post_meta( 1761, '_wp_page_template', true ) ?: 'default' ); update_option( 'dv_decl_pg', $id, false ); }
} );
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; } ?>
<style>
#dv-dcl{position:fixed;inset:0;background:rgba(12,15,19,.55);display:flex;align-items:flex-start;justify-content:center;z-index:100000;padding:24px 14px;overflow:auto}
#dv-dcl .bx,#dv-decl-pg .bx{background:#FBF9F4;color:#16191E;border-radius:18px;max-width:640px;width:100%;padding:24px 26px;box-shadow:0 20px 60px rgba(0,0,0,.3);font-family:"Hanken Grotesk",-apple-system,sans-serif}
#dv-decl-pg .bx{box-shadow:none;border:1px solid #E3DED2;margin:10px auto;max-width:760px}
.dvd h3{margin:0 0 4px;font-size:1.25rem;font-weight:800}.dvd p.s{margin:0 0 14px;font-size:.9rem;color:#5E5A52}
.dvd h4{margin:16px 0 8px;font-size:.78rem;font-weight:700;color:#8C6A26;letter-spacing:.02em}
.dvd .g{display:grid;grid-template-columns:1fr 1fr;gap:10px}.dvd .g .w{grid-column:1/-1}
@media(max-width:560px){.dvd .g{grid-template-columns:1fr}}
.dvd label{display:block;font-size:.8rem;font-weight:600}
.dvd input,.dvd select{display:block;width:100%;box-sizing:border-box;margin-top:4px;border:1px solid #CFC8B8;border-radius:10px;padding:9px 11px;font:400 .93rem "Hanken Grotesk",-apple-system,sans-serif;background:#fff;color:#16191E;height:auto}
.dvd .ck label{display:flex;gap:9px;align-items:flex-start;font-weight:500;font-size:.88rem;padding:6px 0;border-bottom:1px solid #EEE9DE}
.dvd .ck input{width:17px;height:17px;margin:2px 0 0;flex:none}
.dvd .ac{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
.dvd .ac button{border-radius:10px;padding:11px 16px;font:700 .92rem "Hanken Grotesk",-apple-system,sans-serif;cursor:pointer;border:1px solid #16191E}
.dvd .ac .ok{background:#2B579A!important;border-color:#2B579A!important;color:#fff!important}.dvd .ac .fx{background:#fff!important;color:#16191E!important;border-color:#E2DCCD!important}
.dvd .er{color:#9A3B2C;font-size:.85rem;min-height:1em;margin-top:8px}.dvd .nt{font-size:.78rem;color:#6E6A60;margin-top:10px}
</style>
<script>(function(){var K='dv_decl_emp';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function ls(v){try{if(v===undefined)return JSON.parse(localStorage.getItem(K)||'{}');localStorage.setItem(K,JSON.stringify(v))}catch(x){return{}}}
function fcnpj(c){c=String(c||'').replace(/\D/g,'');return c.length===14?c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5'):c}
var DECL=[
 ['hab','Cumprimento dos requisitos de habilitação',function(d){return'declara, sob as penas da lei, que cumpre plenamente os requisitos de habilitação exigidos no edital, nos termos do art. 63, inciso I, da Lei nº 14.133/2021, e que sua proposta está em conformidade com as exigências do instrumento convocatório.'}],
 ['imp','Inexistência de fato impeditivo',function(d){return'declara, sob as penas da lei, que não se enquadra em nenhuma das vedações de participação previstas no art. 14 da Lei nº 14.133/2021, que não está impedida de licitar ou contratar com a Administração Pública nem foi declarada inidônea, e que até a presente data inexistem fatos impeditivos para a sua habilitação, ciente da obrigatoriedade de declarar ocorrências posteriores.'}],
 ['men','Não emprego de menor',function(d){return'declara, para fins do disposto no art. 68, inciso VI, da Lei nº 14.133/2021, que não emprega menor de 18 (dezoito) anos em trabalho noturno, perigoso ou insalubre e não emprega menor de 16 (dezesseis) anos, salvo a partir de 14 (quatorze) anos, na condição de aprendiz, nos termos do art. 7º, inciso XXXIII, da Constituição Federal.'}],
 ['pcd','Reserva de cargos para pessoa com deficiência e reabilitado',function(d){return'declara, nos termos do art. 63, inciso IV, da Lei nº 14.133/2021, que cumpre as exigências de reserva de cargos para pessoa com deficiência e para reabilitado da Previdência Social, previstas em lei e em outras normas específicas.'}],
 ['trb','Custos trabalhistas incluídos na proposta',function(d){return'declara, nos termos do art. 63, § 1º, da Lei nº 14.133/2021, que sua proposta econômica compreende a integralidade dos custos para atendimento dos direitos trabalhistas assegurados na Constituição Federal, nas leis trabalhistas, nas normas infralegais, nas convenções coletivas de trabalho e nos termos de ajustamento de conduta vigentes na data de entrega das propostas.'}],
 ['mpe','Enquadramento como ME ou EPP',function(d){return'declara, sob as penas da lei, que está enquadrada como '+(d.porte||'microempresa ou empresa de pequeno porte')+', nos termos do art. 3º da Lei Complementar nº 123/2006, que não se enquadra em nenhuma das hipóteses do § 4º do referido artigo e que, no ano-calendário de realização desta licitação, ainda não celebrou contratos com a Administração Pública cujos valores somados extrapolem a receita bruta máxima admitida para fins de enquadramento como empresa de pequeno porte, nos termos do art. 4º, § 2º, da Lei nº 14.133/2021, estando apta a usufruir do tratamento favorecido previsto nos arts. 42 a 49 da Lei Complementar nº 123/2006.'}],
 ['ind','Elaboração independente da proposta',function(d){return'declara, sob as penas da lei, que a proposta apresentada para participar desta licitação foi elaborada de maneira independente e que o seu conteúdo não foi, no todo ou em parte, direta ou indiretamente, informado, discutido ou recebido de qualquer outro participante potencial ou de fato desta licitação, por qualquer meio ou por qualquer pessoa.'}],
 ['deg','Não utilização de trabalho degradante ou forçado',function(d){return'declara que não possui, em sua cadeia produtiva, empregados executando trabalho degradante ou forçado, observando o disposto nos incisos III e IV do art. 1º e no inciso III do art. 5º da Constituição Federal.'}]];
function form(lic){var E=ls();lic=lic||{};var mpe=/micro|pequeno|^me$|^epp$/i.test(E.porte||'');
 return'<div class="dvd"><h3>Gerador de declarações</h3><p class="s">Preencha uma vez. As declarações saem em Word, prontas para assinar.</p>'+
 '<h4>SUA EMPRESA</h4><div class="g"><label>CNPJ<input name="cnpj" value="'+e(fcnpj(E.cnpj))+'" inputmode="numeric" placeholder="00.000.000/0000-00"></label><label>Porte<select name="porte"><option value="">Demais empresas</option><option value="microempresa"'+(E.porte==='microempresa'?' selected':'')+'>Microempresa (ME)</option><option value="empresa de pequeno porte"'+(E.porte==='empresa de pequeno porte'?' selected':'')+'>Empresa de pequeno porte (EPP)</option></select></label>'+
 '<label class="w">Razão social<input name="razao" value="'+e(E.razao)+'"></label><label class="w">Endereço<input name="end" value="'+e(E.end)+'"></label>'+
 '<label>Representante legal<input name="rep" value="'+e(E.rep)+'"></label><label>CPF do representante<input name="cpf" value="'+e(E.cpf)+'" inputmode="numeric"></label><label class="w">Cargo<input name="cargo" value="'+e(E.cargo||'Sócio-administrador')+'"></label></div>'+
 '<h4>LICITAÇÃO</h4><div class="g"><label class="w">Órgão<input name="orgao" value="'+e(lic.orgao)+'"></label><label>Modalidade<input name="mod" value="'+e(lic.mod)+'" placeholder="Pregão eletrônico"></label><label>Número<input name="num" value="'+e(lic.num)+'" placeholder="12/2026"></label><label>Processo (opcional)<input name="proc" value="'+e(lic.proc)+'"></label><label>Cidade da assinatura<input name="cid" value="'+e(E.cid||'')+'"></label></div>'+
 '<h4>DECLARAÇÕES</h4><div class="ck">'+DECL.map(function(d){return'<label><input type="checkbox" name="d_'+d[0]+'"'+(d[0]!=='mpe'||mpe?' checked':'')+'> '+d[1]+'</label>'}).join('')+'</div>'+
 '<div class="er"></div><div class="ac"><button type="button" class="fx">Fechar</button><button type="button" class="ok">⬇ Gerar declarações em Word</button></div><p class="nt">Modelos padrão da Lei 14.133/2021. Se o edital trouxer modelo próprio, use o do edital. Seus dados ficam só neste navegador.</p></div>'}
function liga(box,fechar){var q=function(n){return box.querySelector('[name='+n+']')};
 q('cnpj').addEventListener('blur',function(){var c=q('cnpj').value.replace(/\D/g,'');if(c.length!==14)return;q('cnpj').value=fcnpj(c);fetch('https://brasilapi.com.br/api/cnpj/v1/'+c).then(function(r){return r.ok?r.json():null}).then(function(j){if(!j)return;if(!q('razao').value)q('razao').value=j.razao_social||'';if(!q('end').value)q('end').value=[[j.descricao_tipo_de_logradouro,j.logradouro].filter(Boolean).join(' ')+(j.numero?', '+j.numero:''),j.complemento,j.bairro,(j.municipio||'')+'/'+(j.uf||''),j.cep?'CEP '+String(j.cep).replace(/^(\d{5})(\d{3})$/,'$1-$2'):''].filter(function(x){return x&&String(x).trim()}).join(', ');if(!q('cid').value&&j.municipio)q('cid').value=j.municipio.replace(/\w\S*/g,function(w){return w.charAt(0)+w.slice(1).toLowerCase()})+'/'+j.uf;var p=String(j.porte||'');if(/MICRO/i.test(p)){q('porte').value='microempresa';box.querySelector('[name=d_mpe]').checked=true}else if(/PEQUENO/i.test(p)){q('porte').value='empresa de pequeno porte';box.querySelector('[name=d_mpe]').checked=true}}).catch(function(){})});
 q('porte').addEventListener('change',function(){box.querySelector('[name=d_mpe]').checked=!!q('porte').value});
 box.addEventListener('click',function(ev){if(ev.target.classList.contains('fx')){fechar();return}if(!ev.target.classList.contains('ok'))return;var b=ev.target,d={};['cnpj','porte','razao','end','rep','cpf','cargo','orgao','mod','num','proc','cid'].forEach(function(n){d[n]=q(n).value.trim()});
  var sel=DECL.filter(function(x){return box.querySelector('[name=d_'+x[0]+']').checked});var er=box.querySelector('.er');
  if(!d.razao||d.cnpj.replace(/\D/g,'').length!==14){er.textContent='Informe o CNPJ e a razão social da empresa.';return}if(!d.rep){er.textContent='Informe o representante legal.';return}if(!sel.length){er.textContent='Marque ao menos uma declaração.';return}er.textContent='';
  ls({cnpj:d.cnpj,porte:d.porte,razao:d.razao,end:d.end,rep:d.rep,cpf:d.cpf,cargo:d.cargo,cid:d.cid});b.disabled=true;b.textContent='Gerando…';
  gera(d,sel).catch(function(x){console.error(x);er.textContent='Não foi possível gerar agora. Tente de novo.'}).then(function(){b.disabled=false;b.textContent='⬇ Gerar declarações em Word'})})}
function gera(d,sel){var lib=window.docx?Promise.resolve():new Promise(function(ok,no){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.umd.js';s.onload=ok;s.onerror=no;document.head.appendChild(s)});
 return lib.then(function(){var D=window.docx,P=D.Paragraph,R=D.TextRun,A=D.AlignmentType,hoje=new Date(),mes=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'],data=(d.cid?d.cid+', ':'')+hoje.getDate()+' de '+mes[hoje.getMonth()]+' de '+hoje.getFullYear()+'.';
  var ref=[d.mod,d.num?'nº '+d.num:'',d.proc?'Processo nº '+d.proc:''].filter(Boolean).join(' · ');var ch=[];
  sel.forEach(function(x,i){var qual=d.razao+', inscrita no CNPJ sob o nº '+fcnpj(d.cnpj)+(d.end?', com sede em '+d.end:'')+', por intermédio de seu representante legal, '+d.rep+(d.cpf?', inscrito no CPF sob o nº '+d.cpf:'')+', ';
   ch.push(new P({pageBreakBefore:i>0,spacing:{after:120},children:[new R({text:'Ao(À) '+(d.orgao||'________________________________'),size:22})]}));
   if(ref)ch.push(new P({spacing:{after:480},children:[new R({text:'Ref.: '+ref,size:22})]}));
   ch.push(new P({alignment:A.CENTER,spacing:{after:480},children:[new R({text:'DECLARAÇÃO DE '+x[1].toUpperCase(),bold:true,size:24})]}));
   ch.push(new P({alignment:A.JUSTIFIED,spacing:{after:360,line:360},indent:{firstLine:720},children:[new R({text:qual,size:22}),new R({text:x[2](d),size:22})]}));
   ch.push(new P({alignment:A.JUSTIFIED,spacing:{after:720,line:360},indent:{firstLine:720},children:[new R({text:'Por ser expressão da verdade, firma a presente declaração.',size:22})]}));
   ch.push(new P({alignment:A.RIGHT,spacing:{after:1200},children:[new R({text:data,size:22})]}));
   ch.push(new P({alignment:A.CENTER,children:[new R({text:'_____________________________________________',size:22})]}));
   ch.push(new P({alignment:A.CENTER,children:[new R({text:d.rep,bold:true,size:22})]}));
   ch.push(new P({alignment:A.CENTER,children:[new R({text:(d.cargo||'Representante legal')+(d.cpf?' · CPF '+d.cpf:''),size:20})]}));
   ch.push(new P({alignment:A.CENTER,children:[new R({text:d.razao+' · CNPJ '+fcnpj(d.cnpj),size:20})]}))});
  var doc=new D.Document({creator:d.razao,title:'Declarações',styles:{default:{document:{run:{font:'Arial'}}}},sections:[{properties:{page:{margin:{top:1700,bottom:1400,left:1700,right:1400}}},children:ch}]});
  return D.Packer.toBlob(doc).then(function(bl){var a=document.createElement('a'),u=URL.createObjectURL(bl);a.href=u;a.download='Declaracoes'+(d.num?' - '+d.num.replace(/\//g,'-'):'')+' - '+d.razao.slice(0,40).replace(/[\\\/:*?"<>|]/g,'')+'.docx';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(u)},5000)})})}
window.dvDeclaracoes=function(lic){var m=document.getElementById('dv-dcl');if(m)m.remove();m=document.createElement('div');m.id='dv-dcl';m.innerHTML='<div class="bx">'+form(lic)+'</div>';document.body.appendChild(m);var fe=function(){m.remove()};m.addEventListener('click',function(ev){if(ev.target===m)fe()});liga(m.querySelector('.bx'),fe)};
var pg=document.getElementById('dv-decl-pg');if(pg){var P=new URLSearchParams(location.search);pg.innerHTML='<div class="bx">'+form({orgao:P.get('orgao')||'',mod:P.get('mod')||'',num:P.get('num')||''})+'</div>';var fx=pg.querySelector('.fx');if(fx)fx.style.display='none';liga(pg.querySelector('.bx'),function(){})}
})();</script>
<?php
}, 99 );

// CAPAG (capacidade de pagamento do Tesouro Nacional) do órgão em cada licitação do buscador.
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; } ?>
<style>.bq2-ch .dv-cap{font-size:.78rem;font-weight:700;border-radius:8px;padding:4px 9px;cursor:help}.dv-cap.a{background:#EAF5EE;color:#1F5A3B}.dv-cap.b{background:#EEF6E6;color:#3B5A1F}.dv-cap.c{background:#FBF1DC;color:#7A5410}.dv-cap.d{background:#FDEDEA;color:#9A3524}</style>
<script>(function(){var C=null,pend=false;
function nm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().replace(/\s+/g,' ').trim()}
function carrega(){if(C||pend)return;pend=true;fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/capag/capag.json').then(function(r){return r.ok?r.json():null}).then(function(j){C=j;window.DVCAPAG=j;pinta()}).catch(function(){})}
var TX={A:['a','boa capacidade de pagamento'],'A+':['a','ótima capacidade de pagamento'],B:['b','capacidade de pagamento razoável'],'B+':['b','boa capacidade de pagamento'],C:['c','atenção: capacidade de pagamento fraca'],D:['d','risco de atraso nos pagamentos']};
window.dvCapagDe=function(x){if(!C||!x)return null;var esf=x.esfera_id||'',uf=x.uf||'';if(x.item_url){if(esf==='M'&&x.codigo_ibge)return C.m[String(x.codigo_ibge)]||null;if(esf==='E')return C.e[uf]||null;return null}var k=C.n[uf+'|'+nm(x.municipio)];return k?C.m[k]||null:null};
function pinta(){if(!C)return;[].forEach.call(document.querySelectorAll('.bq2-i[data-r]'),function(c){if(c.querySelector('.dv-cap'))return;var x=(window.DVBQ||{})[c.dataset.r];var v=dvCapagDe(x);if(!v||!TX[v])return;var ch=c.querySelector('.bq2-ch');if(!ch)return;var s=document.createElement('span');s.className='dv-cap '+TX[v][0];s.textContent='CAPAG '+v+' · '+TX[v][1];s.title='Nota de capacidade de pagamento do Tesouro Nacional para '+(x.esfera_id==='E'?'o estado':'o município')+' deste órgão (A = melhor, D = pior).';ch.insertBefore(s,ch.firstChild.nextSibling)})}
var t;new MutationObserver(function(){if(!document.querySelector('.bq2-i'))return;carrega();clearTimeout(t);t=setTimeout(pinta,350)}).observe(document.body,{childList:true,subtree:true});
})();</script>
<?php
}, 99 );

// Habilitação do concorrente e Gerador de declarações dentro da plataforma (menu lateral, cards do Início e moldura da plataforma).
add_action( 'init', function () {
	if ( get_option( 'dv_pg_ctx2' ) ) { return; }
	$d = (int) get_option( 'dv_decl_pg' );
	if ( $d && get_post( $d ) ) {
		wp_update_post( array( 'ID' => $d, 'post_content' => '[dv_ctx tool="declaracoes"]<section class="dv-th"><small>Ferramenta Desenvolve</small><h1>Gerador de declarações</h1><p>As declarações que todo edital pede, preenchidas com os dados da sua empresa e da licitação, em Word, prontas para assinar.</p></section>[dv_declaracoes]' ) );
		update_post_meta( $d, '_wp_page_template', 'elementor_canvas' );
	}
	$h = get_post( 1761 );
	if ( $h && strpos( (string) $h->post_content, 'dv_ctx' ) === false ) {
		wp_update_post( array( 'ID' => 1761, 'post_content' => '[dv_ctx tool="habilitacao"]<section class="dv-th"><small>Ferramenta Desenvolve</small><h1>Habilitação do concorrente</h1><p>As últimas licitações que a empresa venceu ou disputou, com o link para os documentos de habilitação de cada uma.</p></section>[dv_habilitacao]' ) );
	}
	update_option( 'dv_pg_ctx2', 1, false );
}, 20 );
add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_user_logged_in() ) { return; } $meu = preg_replace( '/\D/', '', (string) get_user_meta( get_current_user_id(), 'dv_doc', true ) ); ?>
<style>
/* barra lateral sempre recolhida (só ícones); o nome aparece num balão ao passar o mouse */
@media (min-width:901px){
html.dv-rail .dv-side.ab,html .dv-side.ab{width:76px!important;box-shadow:none!important}
html.dv-rail .dv-side{transition:none!important}html.dv-rail .dv-side .lg{cursor:default!important}
html.dv-rail body.dv-app .dv-side nav a,html.dv-rail body.dv-app .dv-side nav a:hover{color:transparent!important}html.dv-rail body.dv-app .dv-side nav a:hover{background:rgba(255,255,255,.06)!important}html.dv-rail body.dv-app .dv-side nav a .ic{color:#C9CDD3}html.dv-rail body.dv-app .dv-side nav a:hover .ic,html.dv-rail body.dv-app .dv-side nav a.on .ic{color:#E6C27A!important}
html.dv-rail .dv-side.ab .gt{height:1px;margin:10px 14px!important;background:rgba(255,255,255,.07);font-size:0}html.dv-rail .dv-side.ab .ft{display:none}html.dv-rail .dv-side.ab nav a{padding-top:8px!important;padding-bottom:8px!important}
html.dv-rail .dv-side .gt,html.dv-rail .dv-side .lg b,html.dv-rail .dv-side .lg small,html.dv-rail .dv-side .ft,html.dv-rail .dv-side nav a .bd,html.dv-rail .dv-side .dv-pin,.dv-side .dv-pin{opacity:0!important;pointer-events:none!important}
html.dv-rail .dv-side nav .gs{height:1px;background:#262B33;margin:8px 14px}
}
#dv-tip{position:fixed;z-index:100002;background:#16191E;color:#F4F2EC;border:1px solid #2A2F37;border-radius:8px;padding:6px 10px;font:600 .82rem "Hanken Grotesk",-apple-system,sans-serif;pointer-events:none;white-space:nowrap;box-shadow:0 8px 24px rgba(0,0,0,.3)}
#dv-tip small{display:block;color:#C9A45C;font-weight:700;font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;margin-bottom:2px}
.dv-cards .dv-cg{grid-column:1/-1;margin:14px 0 -2px;font:800 .78rem "Hanken Grotesk",-apple-system,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#8C6A26}
.dv-cards .dv-cg:first-child{margin-top:0}
</style>
<script>(function(){var MEU=<?php echo wp_json_encode( strlen( $meu ) === 14 ? $meu : '' ); ?>;
try{localStorage.removeItem('dv_menu_fixo')}catch(x){}document.documentElement.classList.add('dv-rail');
/* a barra não abre nunca: bloqueia os gatilhos de abrir e tira a classe na hora, antes de pintar */
(function(){function sd(){return document.querySelector('.dv-side')}
document.addEventListener('mouseenter',function(ev){var t=ev.target;if(t&&t.classList&&t.classList.contains('lg')&&t.closest&&t.closest('.dv-side'))ev.stopImmediatePropagation()},true);
document.addEventListener('focusin',function(ev){var s=sd();if(s&&s.contains(ev.target))ev.stopImmediatePropagation()},true);
function trava(){var s=sd();if(!s||s.dataset.trava)return;s.dataset.trava=1;s.classList.remove('ab');new MutationObserver(function(){if(s.classList.contains('ab'))s.classList.remove('ab')}).observe(s,{attributes:true,attributeFilter:['class']});var pin=s.querySelector('.dv-pin');if(pin)pin.remove()}
trava();document.addEventListener('DOMContentLoaded',trava);setTimeout(trava,500)})();
/* uma ordem só, igual no menu e nos cards: o caminho de quem vende para o governo */
var G=[
 ['Encontrar licitações',[
  ['Pesquisa de editais','/plataforma/buscador/','Busque licitações abertas por palavra, estado, cidade e raio de atendimento.','busca'],
  ['Alertas do seu ramo','/plataforma/alertas/','Receba por e-mail, todo dia, as licitações novas do que você vende.'],
  ['Contratos vencendo','/plataforma/contratos-vencendo/','Contratos públicos do seu ramo que estão acabando: a próxima licitação vem aí.'],
  ['Notícias de licitações','/plataforma/noticias/','O que mudou nas licitações e nos órgãos que compram de você.']]],
 ['Analisar antes de disputar',[
  ['Raio-X do órgão comprador','/plataforma/raio-x-comprador/','O órgão paga em dia? Quanto compra, de quem e o que está aberto agora.'],
  ['Raio-X do concorrente','/plataforma/raio-x/','Quem disputa com você: onde ganha, por quanto, punições, dívidas e sócios.'],
  ['Habilitação do concorrente','/plataforma/habilitacao-concorrente/','Os documentos de habilitação das últimas licitações que a empresa disputou ou venceu.'],
  ['Banco de preços','/plataforma/banco-de-precos/','Quanto o governo já pagou pelo que você vende.']]],
 ['Preparar e disputar',[
  ['Gestão de licitações','/plataforma/licitacoes/','Organize as licitações que você vai disputar, etapa por etapa.'],
  ['Agenda','/painel/?ver=agenda','Todos os prazos e sessões, separados por dia.'],
  ['Documentos e certidões','/plataforma/documentos/','Guarde a validade das certidões e receba aviso antes de vencer.'],
  ['Gerador de declarações','/plataforma/declaracoes/','As declarações do edital preenchidas com os dados da sua empresa, em Word.'],
  ['Seguro garantia','#seguro','Cote a garantia de proposta ou do contrato sem trocar de corretor.']]],
 ['Contestar',[
  ['Impugnações e recursos','/plataforma/recursos/','Conteste um edital ou um resultado, com o prazo já calculado.'],
  ['Banco de teses','/plataforma/banco-de-teses/','Decisões de tribunais para fundamentar a sua peça.']]],
 ['Empresas',[
  ['Raio-X da minha empresa','/plataforma/dossie/'+(MEU?'?cnpj='+MEU:'?minha=1'),'Veja sua empresa como o órgão e os concorrentes veem.'],
  ['Due Diligence completa','/plataforma/dossie/','Tudo sobre qualquer empresa: sanções, dívidas, processos, contratos e filiais.'],
  ['Raio-X em lote','/plataforma/raio-x-lote/','Até 20 CNPJs de uma vez.']]],
 ['Depois de ganhar',[
  ['Meus contratos e garantias','/plataforma/meus-contratos/','Vencimentos, prorrogações e garantias dos contratos que você já ganhou.']]]];
var ICN={'/plataforma/buscador/':'/plataforma/alertas/','/painel/?ver=agenda':'/plataforma/contratos-vencendo/','/plataforma/declaracoes/':'/plataforma/documentos/','#seguro':'/plataforma/meus-contratos/','/plataforma/banco-de-teses/':'/plataforma/recursos/','/plataforma/habilitacao-concorrente/':'/plataforma/raio-x/'};
function pth(h){return String(h||'').replace(/^https?:\/\/[^\/]+/,'').split('#')[0]}
function menu(){var nav=document.querySelector('.dv-side nav');if(!nav||nav.dataset.dvo)return;var ex={};[].forEach.call(nav.querySelectorAll('a'),function(a){ex[pth(a.getAttribute('href')).split('?')[0]]=a});if(!ex['/painel/'])return;nav.dataset.dvo=1;
 var here=location.pathname,qs=location.search,frag=document.createDocumentFragment();var ini=ex['/painel/'].cloneNode(true);frag.appendChild(ini);
 var busca=document.querySelector('.dv-top .dv-k svg');
 G.forEach(function(g){var gt=document.createElement('div');gt.className='gt';gt.textContent=g[0];frag.appendChild(gt);var gs=document.createElement('div');gs.className='gs';frag.appendChild(gs);
  g[1].forEach(function(it){var own=ex[pth(it[1]).split('?')[0]],base=own||ex[ICN[it[1]]]||ex['/plataforma/raio-x/'];if(!base)return;var a=base.cloneNode(true);if(!own||it[1].indexOf('/dossie/?')>=0){var bd0=a.querySelector('.bd');if(bd0)bd0.remove()}a.setAttribute('href',it[1]);a.removeAttribute('aria-current');a.classList.remove('on');
   if(it[1]==='/plataforma/buscador/'&&busca){var ic=a.querySelector('.ic');if(ic)ic.innerHTML=busca.outerHTML}
   var lab=[].find.call(a.querySelectorAll('span'),function(x){return!x.classList.contains('ic')&&!x.classList.contains('bd')});if(lab)lab.textContent=it[0];
   var same=it[1].split('?')[0]===here&&(it[1].indexOf('?ver=')<0||qs.indexOf(it[1].split('?')[1])>=0)&&!(it[1].indexOf('/dossie/?')>=0);if(same){a.classList.add('on');a.setAttribute('aria-current','page')}
   a.dataset.g=g[0];frag.appendChild(a)})});
 [['Minha conta',['/creditos/','/assinar/']]].forEach(function(g){var gt=document.createElement('div');gt.className='gt';gt.textContent=g[0];frag.appendChild(gt);var gs=document.createElement('div');gs.className='gs';frag.appendChild(gs);g[1].forEach(function(u){if(ex[u]){var a=ex[u].cloneNode(true);a.dataset.g=g[0];frag.appendChild(a)}})});
 if(here==='/painel/'&&!qs){ini.classList.add('on')}else ini.classList.remove('on');
 nav.innerHTML='';nav.appendChild(frag)}
function cards(){var g=document.querySelector('.dv-cards');if(!g||g.dataset.dvo)return;var ex={};[].forEach.call(g.querySelectorAll('a.dv-card'),function(a){ex[a.getAttribute('href')]=a});var mod=g.querySelector('a.dv-card:not(.p1)')||g.querySelector('a.dv-card');if(!mod)return;g.dataset.dvo=1;
 var frag=document.createDocumentFragment();
 G.forEach(function(gr){var h=document.createElement('div');h.className='dv-cg';h.textContent=gr[0];frag.appendChild(h);
  gr[1].forEach(function(it,i){var a=ex[it[1]]||(it[1].indexOf('/plataforma/dossie/')===0&&it[0]==='Due Diligence completa'?ex['/plataforma/dossie/']:null);a=a?a.cloneNode(true):mod.cloneNode(true);a.classList.remove('p1');if(gr[0]==='Encontrar licitações'&&i===0)a.classList.add('p1');a.setAttribute('href',it[1]);
   var b=a.querySelector('b'),sp=a.querySelector('span'),em=a.querySelector('em');if(b)b.textContent=it[0];if(sp)sp.textContent=it[2];if(em&&!a.classList.contains('bq'))em.textContent=it[1]==='#seguro'?'Cotar':'Abrir';frag.appendChild(a)})});
 g.innerHTML='';g.appendChild(frag)}
/* balão com o nome no menu recolhido */
var tip=null;document.addEventListener('mouseover',function(ev){var a=ev.target.closest&&ev.target.closest('.dv-side nav a');if(!a||window.innerWidth<=900){if(tip){tip.remove();tip=null}return}var lab=[].find.call(a.querySelectorAll('span'),function(x){return!x.classList.contains('ic')&&!x.classList.contains('bd')});if(!tip){tip=document.createElement('div');tip.id='dv-tip';document.body.appendChild(tip)}tip.innerHTML=(a.dataset.g?'<small>'+a.dataset.g+'</small>':'')+(lab?lab.textContent:'');var r=a.getBoundingClientRect();tip.style.left=(r.right+10)+'px';tip.style.top=(r.top+r.height/2-tip.offsetHeight/2)+'px'});
document.addEventListener('mouseout',function(ev){var a=ev.target.closest&&ev.target.closest('.dv-side nav a');if(a&&tip&&!a.contains(ev.relatedTarget)){tip.remove();tip=null}});
/* impede abrir a barra */
document.addEventListener('mouseover',function(){var s=document.querySelector('.dv-side.ab');if(s&&window.innerWidth>900)s.classList.remove('ab')},true);
document.addEventListener('click',function(ev){var a=ev.target.closest&&ev.target.closest('a[href="#seguro"]');if(!a)return;ev.preventDefault();if(window.dvOrcamento)window.dvOrcamento('seguro','')});
function vai(){menu();cards();var s=document.querySelector('.dv-side.ab');if(s&&window.innerWidth>900)s.classList.remove('ab')}vai();var t;new MutationObserver(function(){clearTimeout(t);t=setTimeout(vai,120)}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
})();</script>
<?php
}, 99 );

// Raio-X do concorrente: "Onde ele ganha" (por órgão, valor e desconto), com quem disputa e contratos dele que vencem.
add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_user_logged_in() || strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/plataforma/raio-x/' ) === false ) { return; } ?>
<style>#dv-onde{margin:16px 0 0;background:#fff;border:1px solid #E3DED2;border-radius:12px;padding:16px 18px;font-family:"Hanken Grotesk",-apple-system,sans-serif}#dv-onde h4{margin:0 0 4px;font-size:1rem;font-weight:800;color:#16191E}#dv-onde p.s{margin:0 0 10px;color:#6E6A60;font-size:.86rem}#dv-onde table{width:100%;border-collapse:collapse;font-size:.86rem}#dv-onde th{text-align:left;font-size:.74rem;color:#6E6A60;font-weight:700;padding:6px 8px;border-bottom:1px solid #E3DED2}#dv-onde td{padding:7px 8px;border-bottom:1px solid #F0ECE3;color:#16191E;vertical-align:top}#dv-onde td.n{text-align:right;white-space:nowrap}#dv-onde .g{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}@media(max-width:820px){#dv-onde .g{grid-template-columns:1fr}}#dv-onde ul{margin:0;padding:0;list-style:none}#dv-onde li{padding:6px 0;border-top:1px solid #F0ECE3;font-size:.86rem}#dv-onde li:first-child{border-top:0}#dv-onde li small{display:block;color:#6E6A60;font-size:.76rem}#dv-onde a{color:#86621F;font-weight:700}</style>
<script>(function(){var feito='',HTML='';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bi':v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function br(d){var m=String(d||'').match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''}
function cnpj(){var t=document.querySelector('#rx-cad')||document.querySelector('.dv-org');var m=t&&t.textContent.match(/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/);if(m)return m[0].replace(/\D/g,'');var i=document.querySelector('.dv-tool input');return i?String(i.value).replace(/\D/g,''):''}
function contratos(){var F=window.DVFO;if(!F)return[];var out=[];(function vai(o,d){if(!o||d>3)return;if(Array.isArray(o)){if(o.length&&o[0]&&typeof o[0]==='object'&&('fim' in o[0]||'dataVigenciaFim' in o[0]||'vigencia_fim' in o[0]))out=out.concat(o);else o.forEach(function(x){vai(x,d+1)});return}if(typeof o==='object')Object.keys(o).forEach(function(k){vai(o[k],d+1)})})(F,0);return out}
function poe(cp){if(!HTML||!cp)return;var d=document.getElementById('dv-onde');if(!d){d=document.createElement('section');d.id='dv-onde';cp.appendChild(d)}if(d.innerHTML!==HTML)d.innerHTML=HTML}
function monta(){var cp=document.getElementById('dv-capa');if(!cp)return;var c=cnpj();if(c.length!==14)return;if(feito===c){poe(cp);return}feito=c;var nonce=(window.DV&&DV.nonce)||(window.DVREL&&DVREL.nonce)||'';
 fetch('/wp-json/desenvolve/v1/vencidas?cnpj='+c,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.ok?r.json():null}).then(function(j){if(!j)return;var V=j.vencidas||{},I=V.itens||[],G={};
  I.forEach(function(x){var k=(x.orgao||'')+'|'+(x.uf||'');var g=G[k]||(G[k]={o:x.orgao,uf:x.uf,mun:x.municipio,n:0,v:0,d:[],ult:''});g.n++;g.v+=+x.valor||0;if(x.desconto!=null)g.d.push(+x.desconto);if((x.data||'')>g.ult)g.ult=x.data||''});
  var L=Object.keys(G).map(function(k){return G[k]}).sort(function(a,b){return b.v-a.v}).slice(0,10);
  var hj=new Date().toISOString().slice(0,10),lim=new Date(Date.now()+183*864e5).toISOString().slice(0,10);
  var CT=contratos().map(function(x){return{f:String(x.fim||x.dataVigenciaFim||x.vigencia_fim||'').slice(0,10),o:x.orgao||x.unidade||x.orgaoNome||'',ob:x.objeto||x.objetoContrato||'',v:+(x.valor||x.valorGlobal||0),l:x.link||''}}).filter(function(x){return x.f>=hj&&x.f<=lim}).sort(function(a,b){return a.f.localeCompare(b.f)});
  var R=(j.participacoes||{}).rivais||[];
  var h='<h4>Onde ele ganha</h4><p class="s">'+(V.total||0)+' licitações vencidas nos últimos 3 anos · '+mc(V.valor)+' · desconto médio de '+(V.desconto_medio!=null?String(V.desconto_medio).replace('.',','):'—')+'%</p><div class="g"><div>'+(L.length?'<table><thead><tr><th>Órgão</th><th>Vitórias</th><th>Valor</th><th>Desconto médio</th><th>Última</th></tr></thead><tbody>'+L.map(function(g){var dm=g.d.length?(g.d.reduce(function(a,b){return a+b},0)/g.d.length):null;return'<tr><td>'+e(g.o)+'<small style="display:block;color:#6E6A60">'+e([g.mun,g.uf].filter(Boolean).join('/'))+'</small></td><td class="n">'+g.n+'</td><td class="n">'+mc(g.v)+'</td><td class="n">'+(dm!=null?dm.toFixed(1).replace('.',',')+'%':'—')+'</td><td class="n">'+br(g.ult)+'</td></tr>'}).join('')+'</tbody></table>':'<p class="s">Nenhuma vitória encontrada no PNCP.</p>')+'</div><div>';
  h+='<h4 style="font-size:.92rem">Contratos dele que vencem em até 6 meses</h4>'+(CT.length?'<ul>'+CT.slice(0,6).map(function(x){return'<li>'+(x.l?'<a href="'+e(x.l)+'" target="_blank" rel="noopener">':'')+e(String(x.ob||x.o).slice(0,90))+(x.l?'</a>':'')+'<small>'+e(x.o)+(x.v?' · '+mc(x.v):'')+' · vence '+br(x.f)+'</small></li>'}).join('')+'</ul><p class="s" style="margin-top:6px">É onde a próxima licitação deve sair: dá para tomar o lugar dele.</p>':'<p class="s">Nenhum contrato dele vence nos próximos 6 meses'+(window.DVFO?'':' (os contratos aparecem numa consulta nova)')+'.</p>');
  if(R.length)h+='<h4 style="font-size:.92rem;margin-top:14px">Com quem ele mais disputa</h4><ul>'+R.slice(0,5).map(function(x){var n=x.nome||x.razao||x.n||'',q=x.vezes||x.q||x.total||'';return'<li>'+e(n)+(q?'<small>'+e(q)+' disputa(s) em comum</small>':'')+'</li>'}).join('')+'</ul>';
  h+='</div></div>';HTML=h;poe(document.getElementById('dv-capa'))}).catch(function(){feito=''})}
var t;new MutationObserver(function(){clearTimeout(t);t=setTimeout(monta,600)}).observe(document.body,{childList:true,subtree:true});setTimeout(monta,1500);
})();</script>
<?php
}, 99 );


// Gestão de licitações: situação no PNCP, onde é a disputa, CAPAG, seguro e declarações em cada licitação.
// Impugnações e recursos: botão de revisão por advogado.
add_action( 'wp_footer', function () {
	$u = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	if ( is_admin() || ! is_user_logged_in() || ( strpos( $u, '/plataforma/licitacoes/' ) === false && strpos( $u, '/plataforma/recursos/' ) === false ) ) { return; } ?>
<style>.lc-ch .dvx{font-size:.78rem;font-weight:700;border-radius:8px;padding:4px 9px;margin:2px 4px 0 0;display:inline-block;text-decoration:none}.dvx.p{background:#EEF2F7;color:#2B4A6B}.dvx.v{background:#EAF5EE;color:#1F5A3B}.dvx.a{background:#FBF1DC;color:#7A5410}.dvx.r{background:#FDEDEA;color:#9A3524}.dvx.n{background:#F3F0E8;color:#4A4D52}
.lc-c .ac .dv-seg2{background:#EEF4FB!important;border:1px solid #9DB6D3!important;color:#1F3F66!important;font-weight:700}.lc-c .ac .dv-decl2,.rc-c .ac .dv-rev{background:#FFF9EC!important;border:1px solid #C9A45C!important;color:#6B4E14!important;font-weight:700}</style>
<script>(function(){var C=null;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function T(n){return n?n.textContent.replace(/\s+/g,' ').trim():''}
function ref(s){s=String(s||'');var m=s.match(/(\d{14})\/(\d{4})\/(\d+)/);if(m)return m[1]+'/'+m[2]+'/'+(+m[3]);m=s.match(/(\d{14})-\d-0*(\d+)\/(\d{4})/);if(m)return m[1]+'/'+m[3]+'/'+(+m[2]);return''}
function det(r){var k='dvdet_'+r;try{var c=JSON.parse(sessionStorage.getItem(k)||'null');if(c&&Date.now()-c.t<36e5)return Promise.resolve(c.j)}catch(x){}var p=r.split('/');return fetch('https://pncp.gov.br/api/consulta/v1/orgaos/'+p[0]+'/compras/'+p[1]+'/'+p[2]).then(function(x){return x.ok?x.json():null}).then(function(j){try{sessionStorage.setItem(k,JSON.stringify({t:Date.now(),j:j}))}catch(x){}return j}).catch(function(){return null})}
function cap(){if(C)return Promise.resolve(C);return fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/capag/capag.json').then(function(r){return r.ok?r.json():null}).then(function(j){C=j;return j}).catch(function(){return null})}
function host(u){try{return new URL(/^https?:/.test(u)?u:'https://'+u).hostname.replace(/^www\./,'')}catch(x){return u}}
var TX={A:'v',B:'v','A+':'v','B+':'v',C:'a',D:'r'};
function gestao(){[].forEach.call(document.querySelectorAll('.lc-c'),function(c){if(c.dataset.dvx)return;c.dataset.dvx=1;var b=c.querySelector('[data-pac],[data-hist]');var r=ref(b&&(b.getAttribute('data-pac')||b.getAttribute('data-hist')));var ac=c.querySelector('.ac');
  if(ac){var w=[].find.call(ac.querySelectorAll('a[href*="wa.me"]'),function(a){return /seguro/i.test(a.textContent)});var sg=document.createElement('button');sg.type='button';sg.className='g dv-seg2';sg.textContent='🛡 Cotar seguro garantia';if(w){w.replaceWith(sg)}else ac.appendChild(sg);var dc=document.createElement('button');dc.type='button';dc.className='g dv-decl2';dc.textContent='📝 Declarações';sg.parentNode.insertBefore(dc,sg.nextSibling);c.dvRef=r}
  if(!r)return;var ch=c.querySelector('.lc-ch');det(r).then(function(j){if(!j||!ch)return;c.dvDet=j;var h='',st=String(j.situacaoCompraNome||'');if(st){var cl=/suspens|revogad|anulad|desert|fracass/i.test(st)?'r':/homolog|encerr/i.test(st)?'v':'n';h+='<span class="dvx '+cl+'">PNCP: '+e(st)+'</span>'}
   var l=j.linkSistemaOrigem||j.linkProcessoEletronico;if(l)h+='<a class="dvx p" href="'+e(/^https?:/.test(l)?l:'https://'+l)+'" target="_blank" rel="noopener">Disputa em: '+e(host(l))+'</a>';else if(j.usuarioNome)h+='<span class="dvx p">Sistema: '+e(j.usuarioNome)+'</span>';
   ch.insertAdjacentHTML('beforeend',h);var uo=j.unidadeOrgao||{};cap().then(function(C){if(!C)return;var n=null;if(uo.codigoIbge&&C.m[String(uo.codigoIbge)])n=C.m[String(uo.codigoIbge)];else if(uo.ufSigla&&uo.municipioNome&&C.n[uo.ufSigla+'|'+String(uo.municipioNome).normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().trim()])n=C.m[C.n[uo.ufSigla+'|'+String(uo.municipioNome).normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().trim()]];if(n)ch.insertAdjacentHTML('beforeend','<span class="dvx '+(TX[n]||'n')+'" title="Capacidade de pagamento do Tesouro Nacional (A = melhor, D = pior)">CAPAG '+e(n)+'</span>')})})})}
function recursos(){[].forEach.call(document.querySelectorAll('.rc-c'),function(c){if(c.dataset.dvx)return;c.dataset.dvx=1;var ac=c.querySelector('.ac');if(!ac)return;var b=document.createElement('button');b.type='button';b.className='g dv-rev';b.textContent='⚖ Revisão por advogado';var ia=ac.querySelector('[data-ia]');ac.insertBefore(b,ia?ia.nextSibling:ac.firstChild)})}
document.addEventListener('click',function(ev){var t=ev.target;
 var sg=t.closest&&t.closest('.dv-seg2');if(sg){ev.preventDefault();var c=sg.closest('.lc-c'),j=c.dvDet||{};var tx=[T(c.querySelector('.o')),T(c.querySelector('.m')),T(c.querySelector('.ob'))].filter(Boolean).join('\n');if(j.valorTotalEstimado)tx+='\nValor estimado: '+Number(j.valorTotalEstimado).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});if(j.dataEncerramentoProposta)tx+='\nSessão: '+new Date(j.dataEncerramentoProposta).toLocaleString('pt-BR').slice(0,17);if(c.dvRef)tx+='\nhttps://pncp.gov.br/app/editais/'+c.dvRef;if(window.dvOrcamento)dvOrcamento('seguro',tx);return}
 var dc=t.closest&&t.closest('.dv-decl2');if(dc){ev.preventDefault();var c2=dc.closest('.lc-c'),j2=c2.dvDet||{},m=T(c2.querySelector('.m')).split(' · ');if(window.dvDeclaracoes)dvDeclaracoes({orgao:(j2.orgaoEntidade&&j2.orgaoEntidade.razaoSocial)||T(c2.querySelector('.o')).split(' · ')[0],mod:j2.modalidadeNome||m[0]||'',num:j2.numeroCompra?j2.numeroCompra+'/'+j2.anoCompra:(m[1]||''),proc:j2.processo||''});return}
 var rv=t.closest&&t.closest('.dv-rev');if(rv){ev.preventDefault();var c3=rv.closest('.rc-c');if(window.dvOrcamento)dvOrcamento('revisao',[T(c3.querySelector('.o')),T(c3.querySelector('.m')),T(c3.querySelector('.rc-ch'))].filter(Boolean).join('\n'))}});
function vai(){gestao();recursos()}var tm;new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(vai,300)}).observe(document.body,{childList:true,subtree:true});vai();
})();</script>
<?php
}, 99 );

// Sequência de e-mails da lista: as licitações do ramo saem no dia seguinte (antes: 2 dias depois),
// usando o que a pessoa escreveu como ramo (ex.: "uniforme escolar"), com valor e link da busca já pronta.
function dv_seq2_editais( $q ) {
	$u = 'https://pncp.gov.br/api/search/?q=' . rawurlencode( $q ) . '&tipos_documento=edital&ordenacao=-data&pagina=1&tam_pagina=30&status=recebendo_proposta';
	$r = wp_remote_get( $u, array( 'timeout' => 20, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; DesenvolveBot/1.0)' ) ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return null; }
	$j = json_decode( wp_remote_retrieve_body( $r ), true ); $out = array(); $hoje = current_time( 'Y-m-d' );
	foreach ( (array) ( $j['items'] ?? array() ) as $x ) {
		if ( ! empty( $x['cancelado'] ) ) { continue; } $fim = substr( (string) ( $x['data_fim_vigencia'] ?? '' ), 0, 10 ); if ( $fim && $fim < $hoje ) { continue; }
		if ( ! preg_match( '#compras/(\d{14})/(\d{4})/(\d+)#', (string) ( $x['item_url'] ?? '' ), $m ) ) { continue; }
		$out[] = array( 'objeto' => (string) ( $x['description'] ?? '' ), 'orgao' => (string) ( $x['orgao_nome'] ?? '' ), 'local' => trim( ( $x['municipio_nome'] ?? '' ) . '/' . ( $x['uf'] ?? '' ), '/' ), 'fim' => $fim, 'valor' => (float) ( $x['valor_global'] ?? 0 ), 'link' => 'https://pncp.gov.br/app/editais/' . $m[1] . '/' . $m[2] . '/' . $m[3] );
		if ( count( $out ) >= 3 ) { break; }
	}
	return array( 'itens' => $out, 'total' => (int) ( $j['total'] ?? 0 ) );
}
function dv_seq2_rodar() {
	if ( ! function_exists( 'dv_lista' ) ) { return; }
	$l = dv_lista(); if ( ! $l ) { return; }
	$envia = function ( $em, $as, $co ) { if ( function_exists( 'dv_seq_envia' ) ) { return dv_seq_envia( $em, $as, $co ); } return wp_mail( $em, $as, $co . "\n\nEquipe Desenvolve\nhttps://desenvolveestrategias.com.br", array( 'From: Desenvolve <contato@desenvolveestrategias.com.br>' ) ); };
	$agora = current_time( 'timestamp' ); $lanc = function_exists( 'dv_pre_lancamento' ) ? dv_pre_lancamento() : true; $mudou = false; $env = 0;
	foreach ( $l as $em => $c ) {
		if ( $env >= 40 ) { break; }
		if ( empty( $c['data'] ) || empty( $c['email'] ) ) { continue; }
		$u = get_user_by( 'email', $c['email'] ); if ( $u && function_exists( 'dv_status' ) && dv_status( $u->ID ) === 'ativo' ) { continue; }
		$dias = (int) floor( ( $agora - strtotime( $c['data'] ) ) / DAY_IN_SECONDS ); $seq = (int) ( $c['seq'] ?? 0 );
		$nome = trim( (string) ( $c['nome'] ?? '' ) ); $ola = 'Olá' . ( $nome && strpos( $nome, '@' ) === false ? ', ' . explode( ' ', $nome )[0] : '' ) . '!'; $ok = false;
		if ( $dias >= 1 && $seq < 2 ) {
			$ramo = trim( (string) ( $c['ramo'] ?? '' ) ); $q = function_exists( 'dv_seq_ramo_q' ) ? dv_seq_ramo_q( $ramo ) : ''; if ( ! $q && $ramo && mb_strlen( $ramo ) <= 40 && ! preg_match( '/^outro/i', $ramo ) ) { $q = mb_strtolower( $ramo ); }
			$r = dv_seq2_editais( $q ?: 'contratação' ); $lista = '';
			foreach ( (array) ( $r['itens'] ?? array() ) as $x ) { $lista .= "\n• " . mb_substr( $x['objeto'], 0, 150 ) . "\n  " . $x['orgao'] . ( $x['local'] ? ' · ' . $x['local'] : '' ) . ( $x['valor'] > 0 ? ' · R$ ' . number_format( $x['valor'], 2, ',', '.' ) : '' ) . ( $x['fim'] ? ' · propostas até ' . date_i18n( 'd/m', strtotime( $x['fim'] ) ) : '' ) . "\n  " . $x['link'] . "\n"; }
			$tot = (int) ( $r['total'] ?? 0 ); $busca = 'https://desenvolveestrategias.com.br/consulta-gratis/' . ( $q ? '?q=' . rawurlencode( $q ) : '' );
			$ok = $envia( $c['email'], $q ? '3 licitações abertas de ' . $q . ' para você' : '3 licitações abertas hoje para você', $ola . "\n\n" . ( $q && $tot ? 'Hoje há ' . number_format( $tot, 0, ',', '.' ) . ' licitações abertas de ' . $q . ' no Brasil. Separamos três:' : 'Separamos três licitações abertas agora:' ) . "\n" . ( $lista ?: "\n(As fontes oficiais não responderam agora. Faça a busca pelo site.)\n" ) . "\nPara ver as da sua cidade ou região, com raio em km: " . $busca . "\n\nCom o plano Busca (R$ 19,90 por mês), as novas do seu ramo chegam todo dia por e-mail, com os prazos na agenda." );
			if ( $ok ) { $l[ $em ]['seq'] = 2; }
		} elseif ( $dias >= 5 && $seq < 5 ) {
			$ok = $envia( $c['email'], 'Como funciona o plano Busca, em 1 minuto', $ola . "\n\nO plano Busca da Desenvolve custa R$ 19,90 por mês e faz quatro coisas:\n\n1. Todo dia de manhã, manda por e-mail as licitações novas do que você vende, na sua região.\n2. Busca de licitações abertas por palavra, cidade, estado e raio de atendimento em km, com a opção de ver só contratação rápida (dispensa e emergencial).\n3. Gestão das licitações que você escolheu disputar, com a agenda de prazos e lembretes.\n4. Documentos e certidões com aviso antes de vencer.\n\nÉ o plano de entrada, pensado para MEI, microempresa e empresa de pequeno porte. Quem disputa todo mês e quer o Raio-X do concorrente e do comprador usa o plano Completo, por R$ 79,90.\n\nQuem está na lista assina pelo preço de lançamento, garantido enquanto mantiver a assinatura, e ganha 100 créditos de bônus no primeiro mês.\n\nPlanos: https://desenvolveestrategias.com.br/#lista" );
			if ( $ok ) { $l[ $em ]['seq'] = 5; }
		} elseif ( $dias >= 12 && $seq < 12 ) {
			$ok = $envia( $c['email'], $lanc ? 'Lançamento da Desenvolve em 17 de novembro' : 'A plataforma da Desenvolve já está no ar', $ola . "\n\n" . ( $lanc ? "A plataforma da Desenvolve abre no dia 17 de novembro. Quem está na lista recebe o acesso primeiro, por e-mail, com o preço de lançamento garantido e 100 créditos de bônus no primeiro mês." : "A plataforma da Desenvolve está no ar. O seu preço de lançamento e os 100 créditos de bônus continuam valendo: é só assinar com este mesmo e-mail." ) . "\n\nEnquanto isso, a busca grátis continua aberta: https://desenvolveestrategias.com.br/consulta-gratis/\n\nPlanos: https://desenvolveestrategias.com.br/#lista" );
			if ( $ok ) { $l[ $em ]['seq'] = 12; }
		}
		if ( $ok ) { $mudou = true; $env++; }
	}
	if ( $mudou ) { update_option( 'dv_lista', $l, false ); }
	update_option( 'dv_seq_ultima', current_time( 'mysql' ) . ' · ' . $env . ' e-mail(s) (v2)', false );
}
add_action( 'init', function () { remove_all_actions( 'dv_seq_diaria' ); add_action( 'dv_seq_diaria', 'dv_seq2_rodar' ); }, 999 );

// Página inicial e buscadores: vídeo de 30 s ("Veja como funciona") e valor aberto agora no ramo de quem digita.
add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; }
	$home = is_front_page(); ?>
<style>
.dv-vbt{display:inline-flex;align-items:center;gap:10px;margin:14px 0 0;background:#fff;border:1px solid #E3DED2;border-radius:999px;padding:8px 16px 8px 8px;font:700 .92rem "Hanken Grotesk",-apple-system,sans-serif;color:#16191E;cursor:pointer}
.dv-vbt i{display:inline-flex;width:30px;height:30px;border-radius:50%;background:#16191E;color:#F4F2EC;align-items:center;justify-content:center;font-style:normal;font-size:.8rem}
#dv-vmod{position:fixed;inset:0;background:rgba(12,15,19,.75);z-index:100001;display:flex;align-items:center;justify-content:center;padding:16px}
#dv-vmod video{max-height:88vh;max-width:92vw;border-radius:18px;background:#000;box-shadow:0 30px 80px rgba(0,0,0,.5)}
#dv-vmod button{position:absolute;top:16px;right:18px;background:#fff;border:0;border-radius:50%;width:40px;height:40px;font-size:1.3rem;cursor:pointer}
.dv-rv{margin:8px 0 0;font:500 .9rem/1.35 "Hanken Grotesk",-apple-system,sans-serif;color:#4A4D52}.dv-rv b{color:#86621F;font-weight:800}
</style>
<script>(function(){var V='/wp-content/uploads/dv/dv-video-30s.mp4',P='/wp-content/uploads/dv/dv-video-30s.jpg';
function abre(){var m=document.createElement('div');m.id='dv-vmod';m.innerHTML='<video src="'+V+'" poster="'+P+'" controls autoplay playsinline></video><button type="button" aria-label="Fechar">×</button>';document.body.appendChild(m);m.addEventListener('click',function(ev){if(ev.target===m||ev.target.tagName==='BUTTON'){m.remove()}})}
window.dvVideo=abre;
<?php if ( $home ) : ?>
(function(){var t=0,iv=setInterval(function(){if(++t>40){clearInterval(iv);return}var inp=document.querySelector('input[placeholder*="limpeza"]');if(!inp||document.querySelector('.dv-vbt'))return;var f=inp.closest('form');if(!f)return;clearInterval(iv);var b=document.createElement('button');b.type='button';b.className='dv-vbt';b.innerHTML='<i>▶</i>Veja como funciona em 30 segundos';b.onclick=abre;f.parentNode.insertBefore(b,f.nextSibling)},250)})();
<?php endif; ?>
/* valor aberto agora no ramo de quem digita */
var RAMOS=[['Merenda e alimentação',/merenda|alimentacao escolar|refeic|generos alimenticios|hortifruti|cozinha|lanche|nutricao|alimentacao/],['Vigilância e segurança',/vigilancia|seguranca patrimonial|vigilante|monitoramento eletronico|cftv/],['Limpeza e conservação',/limpeza|conservacao|higienizacao|asseio|zeladoria|material de limpeza/],['Portaria e recepção',/portaria|recepcao|recepcionista|controle de acesso/],['Mão de obra e apoio',/apoio administrativo|mao de obra|mao-de-obra|copeira|terceirizacao|servicos gerais|cessao de mao/],['Resíduos e limpeza urbana',/coleta de lixo|residuos|limpeza urbana|varricao|aterro/],['Jardinagem e roçada',/jardinagem|rocada|rocagem|paisagismo|poda/],['Medicamentos e material hospitalar',/medicament|farmac|material hospitalar|medico-hospitalar|insumos hospitalares|ortese|protese|odontolog|laboratori/],['Saúde (serviços)',/saude|hospital|enfermag|ambulancia|exames|clinica|plantao medico/],['Obras e engenharia',/obra|engenharia|reforma|construcao|pavimenta|edificacao|drenagem|recapeamento|asfalt/],['Manutenção predial e equipamentos',/manutencao|ar condicionado|climatizacao|elevador|instalacoes eletricas/],['Tecnologia',/software|sistema de gestao|informatica|tecnologia da informacao|licenca de uso|computador|notebook|impressora|rede de dados|internet|telefonia/],['Transporte e veículos',/transporte|veiculo|motorista|fretamento|onibus|caminhao|locacao de veic|pneu/],['Combustíveis',/combustiv|gasolina|diesel|etanol|lubrificante/],['Uniformes e confecção',/uniforme|fardamento|vestuario|confeccao|calcado|enxoval/],['Material escolar e de escritório',/material escolar|kit escolar|livro|didatic|material de escritorio|material de expediente|papel/],['Mobiliário e equipamentos',/mobiliario|moveis|cadeira|mesa|equipamento|eletrodomest/],['Eventos, comunicação e publicidade',/evento|show|publicidade|comunicacao|grafica|impressao/],['Consultoria e treinamento',/consultoria|assessoria|treinamento|capacitacao|curso|auditoria/],['Materiais de construção',/material de construcao|materiais de construcao|cimento|areia|brita|tubos|eletrico|hidraulic/]];
var D=null,pend=null;function dados(){if(D)return Promise.resolve(D);if(pend)return pend;pend=fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/valores/valores.json',{cache:'no-store'}).then(function(r){return r.ok?r.json():null}).then(function(j){D=j;return j}).catch(function(){return null});return pend}
function nm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase()}
function mc(v){v=+v||0;return v>=1e9?'R$ '+(v/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' bilhões':v>=1e6?'R$ '+Math.round(v/1e6).toLocaleString('pt-BR')+' milhões':'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil'}
function linha(inp){var q=nm(inp.value),r=null;if(q.length>=4)RAMOS.some(function(x){if(x[1].test(q)){r=x[0];return true}});var el=inp.parentNode.querySelector('.dv-rv');if(!r){if(el)el.remove();return}
 dados().then(function(j){var A=j&&j.abertas&&j.abertas.BR&&j.abertas.BR.ramos&&j.abertas.BR.ramos[r];if(!A||!(A.valor>0)){if(el)el.remove();return}if(!el){el=document.createElement('p');el.className='dv-rv';inp.insertAdjacentElement('afterend',el)}
  el.innerHTML='<b>'+mc(A.valor)+'</b> abertos agora em '+r.toLowerCase()+' · '+(+A.n).toLocaleString('pt-BR')+' licitações'+(A.rn?' ('+(+A.rn).toLocaleString('pt-BR')+' em contratação rápida)':'')})}
var tm;document.addEventListener('input',function(ev){var i=ev.target;if(!i.matches||!i.matches('input[placeholder*="limpeza"],#bq2 input[name=q],#bq2 .in input,input[name=q]'))return;clearTimeout(tm);tm=setTimeout(function(){linha(i)},500)});
})();</script>
<?php
}, 99 );


// Notícias: aba "Mundo" (licitações e compras públicas fora do Brasil), faixa de notícias fixa no topo de todas as telas
// da plataforma e sino só com avisos do sistema para o cliente.
function dv_mundo_np() {
	$c = get_transient( 'dv_np_mundo2' ); if ( is_array( $c ) ) { return $c; }
	$F = array(
		array( 'en-US', 'US', 'US:en', 'Inglês', array( 'public procurement when:7d', 'government tender when:7d', 'government contract awarded when:7d', 'procurement fraud when:14d', 'World Bank procurement when:30d' ) ),
		array( 'es-419', 'MX', 'MX:es-419', 'Espanhol', array( '"licitación pública" when:7d', '"contratación pública" when:7d', '"compras públicas" when:14d' ) ),
		array( 'pt-PT', 'PT', 'PT:pt-150', 'Portugal', array( '"contratação pública" when:14d', 'concurso público empreitada when:14d' ) ),
	);
	$OK = array( 'reuters.com', 'apnews.com', 'bbc.com', 'bbc.co.uk', 'theguardian.com', 'aljazeera.com', 'dw.com', 'france24.com', 'euronews.com', 'devex.com', 'worldbank.org', 'oecd.org', 'un.org', 'europa.eu', 'gov.uk', 'govtech.com', 'federalnewsnetwork.com', 'nextgov.com', 'fedscoop.com', 'route-fifty.com', 'route-fifty.com', 'publicfinance.co.uk', 'smartcitiesdive.com', 'npr.org', 'cnbc.com', 'politico.com', 'politico.eu', 'abc.net.au', 'cbc.ca', 'efe.com', 'infobae.com', 'elpais.com', 'eltiempo.com', 'lanacion.com.ar', 'clarin.com', 'elcomercio.pe', 'eluniversal.com.mx', 'expansion.mx', 'publico.es', 'rtve.es', 'eldiario.es', 'publico.pt', 'observador.pt', 'rtp.pt', 'sicnoticias.pt', 'dn.pt', 'jn.pt', 'eco.sapo.pt', 'lusa.pt', 'reuters.co.uk', 'channelnewsasia.com', 'scmp.com', 'thehindu.com', 'timesofindia.indiatimes.com', 'news24.com' );
	$out = array(); $vis = array();
	foreach ( $F as $f ) {
		foreach ( $f[4] as $q ) {
			$u = 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=' . $f[0] . '&gl=' . $f[1] . '&ceid=' . $f[2];
			$r = wp_remote_get( $u, array( 'timeout' => 12, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ) ) );
			if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 || ! function_exists( 'dv_np_rss' ) ) { continue; }
			foreach ( dv_np_rss( wp_remote_retrieve_body( $r ) ) as $it ) {
				$h = strtolower( preg_replace( '/^www\./', '', (string) $it['host'] ) ); $ok = false;
				foreach ( $OK as $d ) { if ( $h === $d || substr( $h, -strlen( '.' . $d ) ) === '.' . $d ) { $ok = true; break; } }
				if ( ! $ok || mb_strlen( $it['titulo'] ) < 25 ) { continue; }
				$k = md5( mb_strtolower( mb_substr( $it['titulo'], 0, 70 ) ) ); if ( isset( $vis[ $k ] ) ) { continue; } $vis[ $k ] = 1;
				$out[] = array( 't' => $it['titulo'], 'f' => $it['fonte'] . ' · ' . $f[3], 'u' => $it['url'], 'd' => $it['data'], 'g' => 'Mundo', 'q' => '' );
			}
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['d'], $a['d'] ); } );
	$pf = array(); $out = array_values( array_filter( $out, function ( $x ) use ( &$pf ) { $k = strtolower( $x['f'] ); $pf[ $k ] = ( $pf[ $k ] ?? 0 ) + 1; return $pf[ $k ] <= 4; } ) );
	$res = array( 'itens' => array_slice( $out, 0, 40 ), 'em' => current_time( 'mysql' ) );
	if ( $out ) { set_transient( 'dv_np_mundo2', $res, 3 * HOUR_IN_SECONDS ); }
	return $res;
}
add_filter( 'rest_pre_dispatch', function ( $res, $srv, $req ) {
	if ( $req->get_route() !== '/desenvolve/v1/noticias' || $req->get_param( 'aba' ) !== 'mundo' ) { return $res; }
	if ( ! is_user_logged_in() ) { return new WP_Error( 'rest_forbidden', 'Entre na sua conta.', array( 'status' => 401 ) ); }
	return rest_ensure_response( dv_mundo_np() );
}, 10, 3 );
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<style>
html.dv-rail .dv-tkg{left:76px}@media(max-width:980px){html.dv-rail .dv-tkg{left:0}}
.dv-tkg .t small.w{color:#8FB3D9}
.dv-bellp .vz{padding:18px 16px;color:#6B6B6B;font-size:.86rem}
#dv-inst{top:72px!important}body.admin-bar #dv-inst{top:104px!important}body.dv-tkon #dv-inst{top:106px!important}body.dv-tkon.admin-bar #dv-inst{top:138px!important}#dv-inst:has(span:empty){display:none!important}
#dv-inst{font-size:.8rem!important;line-height:1.35!important;padding:7px 36px 7px 12px!important;max-width:min(620px,calc(100vw - 32px))!important;box-shadow:0 6px 18px rgba(0,0,0,.08)}
.dv-bellp .it b{display:block;font-weight:700}
</style>
<script>(function(){var API=<?php echo wp_json_encode( rest_url( 'desenvolve/v1/' ) ); ?>,NC=<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
/* faixa de notícias fixa no topo de todas as telas (Brasil + mundo) */
function faixa(l){if(!l||!l.length||document.querySelector('.dv-tkg'))return;var w=document.createElement('div');w.className='dv-tkg';w.setAttribute('aria-label','Notícias de licitações');
 var h=l.map(function(x){return'<a href="'+e(x.u)+'" target="_blank" rel="noopener"><small class="'+(x.g==='Mundo'?'w':'')+'">'+e(x.g==='Mundo'?'Mundo':(x.g||'Notícia'))+'</small>'+e(x.t)+'<i>'+e(x.f||'')+'</i></a>'}).join('');
 w.innerHTML='<b>Notícias</b><div class="w"><div class="t">'+h+h+'</div></div><a class="vt" href="/plataforma/noticias/">Ver todas</a>';document.body.appendChild(w);document.body.classList.add('dv-tkon')}
function mundo(){var c=null;try{c=JSON.parse(sessionStorage.getItem('dv_np_m')||'null')}catch(x){}if(c&&Date.now()-c.t<3600000)return Promise.resolve(c.i);return fetch(API+'noticias?aba=mundo',{headers:{'X-WP-Nonce':NC},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){var i=(j.itens||[]).slice(0,6).map(function(x){return{u:x.u,t:x.t,g:'Mundo',f:x.f}});try{sessionStorage.setItem('dv_np_m',JSON.stringify({t:Date.now(),i:i}))}catch(x){}return i}).catch(function(){return[]})}
window.dvTicker=function(l){mundo().then(function(m){var out=[],a=(l||[]).slice(),b=m.slice();while(a.length||b.length){out.push.apply(out,a.splice(0,3));if(b.length)out.push(b.shift())}faixa(out)})};
/* se a lista do Brasil já veio antes deste script, monta com ela */
try{var c0=JSON.parse(sessionStorage.getItem('dv_np_g')||'null');if(c0&&c0.i&&!document.querySelector('.dv-tkg'))window.dvTicker(c0.i)}catch(x){}
setTimeout(function(){if(!document.querySelector('.dv-tkg')){fetch(API+'noticias?aba=geral',{headers:{'X-WP-Nonce':NC},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){window.dvTicker((j.itens||[]).slice(0,14).map(function(x){return{u:x.u,t:x.t,g:x.g,f:x.f}}))}).catch(function(){})}},2500);
/* sino: só avisos do sistema para o cliente */
var AV=[['/plataforma/alertas/','licitação(ões) nova(s) nos seus alertas','Ver nos alertas'],['/plataforma/licitacoes/','sessão(ões) hoje ou amanhã','Abrir a Gestão'],['/plataforma/recursos/','prazo(s) de impugnação ou recurso em até 3 dias','Ver os prazos'],['/plataforma/documentos/','certidão(ões) vencendo ou vencida(s)','Ver documentos']];
function sino(){var sd=document.querySelector('.dv-sld');if(!sd||document.querySelector('.dv-bell2'))return;var old=document.querySelector('.dv-bell');if(old)old.remove();[].forEach.call(document.querySelectorAll('.dv-bellp'),function(x){x.remove()});
 var L=[];AV.forEach(function(v){var a=document.querySelector('.dv-side nav a[href="'+v[0]+'"] .bd');var n=a?parseInt(a.textContent,10)||0:0;if(n)L.push('<a class="it" href="'+v[0]+'"><b>'+n+' '+v[1]+'</b><small>'+v[2]+'</small></a>')});
 if(sd.classList.contains('low'))L.push('<a class="it" href="/creditos/"><b>Seus créditos estão acabando</b><small>Comprar créditos</small></a>');
 var b=document.createElement('button');b.type='button';b.className='dv-bell dv-bell2';b.setAttribute('aria-label','Avisos'+(L.length?' ('+L.length+')':''));b.setAttribute('aria-expanded','false');
 b.innerHTML='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'+(L.length?'<i>'+L.length+'</i>':'');
 var p=document.createElement('div');p.className='dv-bellp dv-bellp2';p.hidden=true;p.setAttribute('role','dialog');p.setAttribute('aria-label','Avisos');p.innerHTML='<div class="h"><b>Avisos</b></div>'+(L.length?L.join(''):'<p class="vz">Nenhum aviso agora. Aqui aparecem sessões próximas, prazos, certidões vencendo e licitações novas dos seus alertas.</p>');
 sd.insertBefore(b,sd.firstChild);document.body.appendChild(p);
 function fecha(){p.hidden=true;b.setAttribute('aria-expanded','false')}
 b.addEventListener('click',function(ev){ev.stopPropagation();if(!p.hidden)return fecha();var r=sd.getBoundingClientRect();p.style.top=(r.bottom+8)+'px';p.style.right=Math.max(12,window.innerWidth-r.right)+'px';p.hidden=false;b.setAttribute('aria-expanded','true')});
 document.addEventListener('click',function(ev){if(!p.hidden&&!p.contains(ev.target))fecha()})}
var tt;new MutationObserver(function(){clearTimeout(tt);tt=setTimeout(function(){var o=document.querySelector('.dv-bell:not(.dv-bell2)');if(o){o.remove();[].forEach.call(document.querySelectorAll('.dv-bellp:not(.dv-bellp2)'),function(x){x.remove()})}sino()},300)}).observe(document.body,{childList:true,subtree:true});setTimeout(sino,800);
/* aviso de instabilidade: logo abaixo da faixa de notícias, compacto, sem cobrir nada */
function inst(){var el=document.getElementById('dv-inst');if(!el)return;if(!el.dataset.ob){el.dataset.ob=1;new MutationObserver(inst).observe(el,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']})}var sp=el.querySelector('span');if(!sp)return;
 if(!sp.textContent.trim()){if(!el.hidden)el.hidden=true;return}
 if(sp.innerHTML.indexOf('Instabilidade nas fontes')>=0)sp.innerHTML='<b>Fontes oficiais instáveis agora.</b> A busca pode demorar ou falhar; tente de novo em alguns minutos. Consulta que falha não é cobrada.';
}
new MutationObserver(inst).observe(document.body,{childList:true});setTimeout(inst,0);setTimeout(inst,1500);
/* página de notícias: aba Mundo */
var R=document.getElementById('dv-np');if(R){var tb=R.querySelector('.np-tabs');if(tb&&!tb.querySelector('[data-a=mundo]')){var bt=document.createElement('button');bt.type='button';bt.dataset.a='mundo';bt.textContent='Mundo';var d0=tb.querySelector('[data-a=geral]');tb.insertBefore(bt,d0?d0.nextSibling:tb.firstChild)}}
})();</script>
<?php
}, 130 );


// Perfil pelo CNAE: o CNPJ do cliente vira as palavras de busca (CNAE principal, secundários e objeto social colado),
// cada licitação ganha o selo "Compatível com seu CNAE" ou "Confira o CNAE", filtro "Só o que posso disputar",
// alerta criado com um clique e sugestão de incluir a atividade no contrato social.
add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/perfil-cnae', array(
		array( 'methods' => 'GET', 'permission_callback' => 'is_user_logged_in', 'callback' => function () {
			$p = json_decode( (string) get_user_meta( get_current_user_id(), 'dv_cnae', true ), true );
			return array( 'perfil' => is_array( $p ) ? $p : null, 'cnpj' => preg_replace( '/\D/', '', (string) get_user_meta( get_current_user_id(), 'dv_doc', true ) ) );
		} ),
		array( 'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => function ( WP_REST_Request $r ) {
			$cn = array();
			foreach ( array_slice( (array) $r['cnaes'], 0, 60 ) as $c ) {
				$cn[] = array( 'c' => preg_replace( '/[^\d\/-]/', '', (string) ( $c['c'] ?? '' ) ), 'd' => mb_substr( sanitize_text_field( (string) ( $c['d'] ?? '' ) ), 0, 200 ), 'on' => empty( $c['off'] ) ? 1 : 0, 'p' => empty( $c['p'] ) ? 0 : 1 );
			}
			$p = array( 'cnpj' => substr( preg_replace( '/\D/', '', (string) $r['cnpj'] ), 0, 14 ), 'nome' => mb_substr( sanitize_text_field( (string) $r['nome'] ), 0, 160 ), 'cnaes' => $cn,
				'mun' => mb_substr( sanitize_text_field( (string) $r['mun'] ), 0, 80 ), 'uf' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $r['uf'] ), 0, 2 ) ),
				'obj' => mb_substr( sanitize_textarea_field( (string) $r['obj'] ), 0, 3000 ), 'filtro' => empty( $r['filtro'] ) ? 0 : 1, 'em' => current_time( 'mysql' ) );
			update_user_meta( get_current_user_id(), 'dv_cnae', wp_slash( wp_json_encode( $p, JSON_UNESCAPED_UNICODE ) ) );
			return array( 'ok' => true, 'perfil' => $p );
		} ),
	) );
} );
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'dv_app_pagina' ) || ! dv_app_pagina() ) { return; } ?>
<style>
#dv-cnae{display:flex;align-items:center;gap:10px 14px;flex-wrap:wrap;background:#FBFAF6;border:1px solid #E3DED2;border-radius:12px;padding:10px 14px;margin:0 0 14px;font:500 .88rem/1.4 "Hanken Grotesk",-apple-system,sans-serif;color:#1A202C}
#dv-cnae b{font-weight:800}#dv-cnae .s{color:#6B6B6B;flex:1;min-width:200px}
#dv-cnae input[type=text]{border:1px solid #DDD7C9;border-radius:9px;padding:8px 10px;font:inherit;width:190px;background:#fff}
#dv-cnae button,.dv-cnm button{border:0;border-radius:9px;padding:8px 13px;font:700 .84rem "Hanken Grotesk",-apple-system,sans-serif;cursor:pointer;background:#1A202C;color:#fff}
#dv-cnae button.l,.dv-cnm button.l{background:transparent;color:#8C6A26;border:1px solid #E3DED2}
#dv-cnae label{display:flex;align-items:center;gap:7px;font-weight:700;cursor:pointer;white-space:nowrap}
.dv-cnk{font-size:.76rem;font-weight:700;border-radius:8px;padding:4px 9px;cursor:help;white-space:nowrap}
.dv-cnk.ok{background:#E8F3EC;color:#1F6B3A}.dv-cnk.no{background:#FFF4E0;color:#7A4E00}
body.dv-cnf .bq2-i.dv-fora{display:none!important}
#dv-mapa{border:1px solid #E3DED2;border-radius:14px;overflow:hidden;margin:0 0 14px;background:#fff}#dv-mapa .mh{display:flex;align-items:center;gap:14px;padding:10px 14px;font:600 .86rem "Hanken Grotesk",-apple-system,sans-serif;border-bottom:1px solid #EEE9DD}#dv-mapa .mh b{font-weight:800}#dv-mapa .lg{color:#6B6B6B;flex:1;display:flex;align-items:center;gap:6px}#dv-mapa .lg i{display:inline-block;width:12px;height:12px;border-radius:50%;margin-left:8px}#dv-mapa .lg i.e{background:#C9A35C;border:3px solid #15191F}#dv-mapa .lg i.o{background:#C9A35C;opacity:.8;border:2px solid #8C6A26}#dv-mapa .x{background:none;border:0;font-size:22px;cursor:pointer;color:#6B6B6B}#dv-mapa .mm{height:480px}#dv-mapa .ct{color:#8C6A26;font-weight:800;white-space:nowrap}#dv-mapa .mf{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;padding:10px 14px;border-bottom:1px solid #EEE9DD;font:600 .82rem "Hanken Grotesk",-apple-system,sans-serif;background:#FBFAF6}#dv-mapa .mf select{border:1px solid #DDD7C9!important;border-radius:8px!important;padding:5px 26px 5px 9px!important;font:600 .82rem "Hanken Grotesk",-apple-system,sans-serif!important;background-color:#fff!important;width:auto!important;max-width:230px!important;height:auto!important;min-height:0!important;line-height:1.3!important;margin:0!important;box-shadow:none!important}#dv-mapa .mf label{white-space:nowrap!important;font-size:.82rem!important;margin:0!important;width:auto!important}#dv-mapa .mf input[type=checkbox]{width:16px!important;height:16px!important;margin:0!important;flex:none}#dv-mapa .mf select:disabled,#dv-mapa .mf input:disabled{opacity:.45}#dv-mapa .mf label{display:flex;align-items:center;gap:6px;cursor:pointer}#dv-mapa .mf .sp{flex:1}#dv-mapa .pl{max-height:260px;overflow:auto;margin-top:6px}#dv-mapa .pi{padding:6px 0;border-top:1px solid #EEE9DD}#dv-mapa .pi a{color:#1A202C;font-weight:700;text-decoration:none;display:block;line-height:1.3}#dv-mapa .pi a:hover{color:#8C6A26}#dv-mapa .pi small{color:#6B6B6B}@media(max-width:700px){#dv-mapa .mm{height:300px}}
.dv-km{font:700 .78rem "Hanken Grotesk",-apple-system,sans-serif!important;border:0!important;border-radius:8px!important;padding:5px 10px!important;background:#F3EEE2!important;color:#6B4F17!important;opacity:1!important;cursor:pointer;white-space:nowrap;line-height:1.2!important;height:auto!important}.dv-km:hover{background:#E9DFC6}
.dv-cnm{position:fixed;inset:0;z-index:100010;background:rgba(15,18,24,.55);display:flex;align-items:center;justify-content:center;padding:16px}
.dv-cnm>div{background:#fff;border-radius:16px;max-width:640px;width:100%;max-height:88vh;overflow:auto;padding:22px 22px 18px;font:500 .9rem/1.45 "Hanken Grotesk",-apple-system,sans-serif;color:#1A202C}
.dv-cnm h3{margin:0 0 4px;font-size:1.15rem}.dv-cnm p.m{margin:0 0 14px;color:#6B6B6B}
.dv-cnm ul{list-style:none;margin:0 0 14px;padding:0;border:1px solid #EEE9DD;border-radius:10px}
.dv-cnm li{display:flex;gap:10px;padding:9px 12px;border-top:1px solid #EEE9DD}.dv-cnm li:first-child{border-top:0}
.dv-cnm li code{font:700 .78rem ui-monospace,monospace;color:#8C6A26;white-space:nowrap}
.dv-cnm textarea{width:100%;min-height:90px;border:1px solid #DDD7C9;border-radius:10px;padding:9px 11px;font:inherit;box-sizing:border-box}
.dv-cnm .kw{margin:12px 0;color:#6B6B6B;font-size:.82rem}.dv-cnm .ac{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}
#dv-cnt{position:fixed;right:18px;bottom:84px;z-index:100005;max-width:340px;background:#1A202C;color:#F4F2EC;border-radius:12px;padding:14px 16px;font:500 .86rem/1.4 "Hanken Grotesk",-apple-system,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.25)}
#dv-cnt button{margin-top:10px;border:0;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer;background:#C9A35C;color:#1A202C}#dv-cnt .x{position:absolute;right:8px;top:4px;background:none;color:#aaa;margin:0;padding:4px;font-size:18px}
</style>
<script>(function(){var API=<?php echo wp_json_encode( rest_url( 'desenvolve/v1/' ) ); ?>,NC=<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
var P=null,KW=[],carregado=false;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function nm(s){return String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase()}
function cnpjF(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function cF(c){c=String(c).replace(/\D/g,'');return c.length===7?c.slice(0,4)+'-'+c[4]+'/'+c.slice(5):c}
/* palavras de licitação por divisão do CNAE (2 primeiros dígitos) */
var DIV={'01':'hortifruti,agricultura familiar,gêneros alimentícios,mudas,sementes','02':'mudas,madeira,lenha','03':'pescado,peixe','08':'brita,areia,pedra,cascalho','10':'gêneros alimentícios,merenda,carne,pão,leite,alimentos','11':'água mineral,bebidas','13':'enxoval,cama mesa e banho,toalha,lençol,tecido','14':'uniforme,fardamento,confecção,vestuário,camiseta,jaleco,malharia','15':'calçado,tênis,bota','17':'papel,papelaria','18':'gráfica,impressão,impressos','20':'saneantes,desinfetante,produtos químicos,cloro','21':'medicamentos,fármacos','22':'plástico,saco de lixo,pneu','23':'material de construção,cimento,vidro','25':'estrutura metálica,serralheria','26':'computador,informática,equipamentos eletrônicos','27':'equipamentos elétricos,material elétrico','28':'máquinas,equipamentos','29':'veículo,ambulância,ônibus','31':'mobiliário,móveis,cadeira,armário','32':'brinquedos,material esportivo,material médico,odontológico','33':'manutenção de equipamentos,manutenção preventiva e corretiva','35':'energia elétrica,energia solar,fotovoltaico','36':'água,abastecimento','37':'esgoto,saneamento','38':'coleta de lixo,resíduos sólidos,coleta seletiva,resíduos de saúde','39':'descontaminação,remediação','41':'obra,construção,edificação,reforma','42':'pavimentação,infraestrutura,drenagem,saneamento,obra','43':'reforma,instalação elétrica,instalação hidráulica,pintura,demolição,terraplenagem','45':'manutenção de veículos,peças automotivas,pneus,mecânica,frota','49':'transporte escolar,transporte de passageiros,fretamento,transporte de cargas','52':'armazenagem,logística','53':'entrega,malote,courier','55':'hospedagem,hotel','56':'refeição,alimentação,merenda,restaurante,coffee break,buffet,marmita','58':'licença de software,editora,livros','59':'vídeo,produção audiovisual','60':'rádio,televisão','61':'telecomunicações,internet,link de dados,telefonia','62':'software,sistema,desenvolvimento de sistemas,tecnologia da informação,suporte técnico,informática','63':'hospedagem de sites,processamento de dados,dados','68':'locação de imóvel','69':'assessoria jurídica,advocacia,contabilidade,serviços contábeis','70':'consultoria,assessoria,gestão','71':'projeto,engenharia,arquitetura,topografia,fiscalização de obra,laudo','72':'pesquisa','73':'publicidade,propaganda,comunicação,marketing,pesquisa de opinião','74':'fotografia,design,tradução','75':'veterinário,veterinária','77':'locação de equipamentos,locação de veículos,locação de máquinas','78':'mão de obra,terceirização,apoio administrativo,recepção,cessão de mão de obra','79':'passagens aéreas,agência de viagens','80':'vigilância,segurança patrimonial,monitoramento,portaria,vigia','81':'limpeza,conservação,higienização,jardinagem,dedetização,controle de pragas,zeladoria,paisagismo,facilities','82':'apoio administrativo,teleatendimento,call center,organização de eventos,cobrança','84':'serviços públicos','85':'curso,capacitação,treinamento,ensino,educação','86':'saúde,médico,hospitalar,enfermagem,exames,laboratório,plantão médico,home care','87':'acolhimento,residência terapêutica,casa de repouso','88':'assistência social,acolhimento','90':'show,espetáculo,artístico','91':'biblioteca,museu','93':'esporte,evento esportivo,recreação','94':'associação','95':'manutenção de computadores,reparação','96':'lavanderia,serviço funerário,funerária'};
var STOP=' correlatas correlatos construcao construcoes instalacao instalacoes manutencao reparacao locacao fornecimento terceiros recursos humanos utilizados utilizadas destinados destinadas materiais material equipamentos aparelhos combinados intermediarios representantes comercio atacadista varejista servicos atividades atividade outros outras outro outra fabricacao fabrica exceto especificadas especificados anteriormente artigos produtos produto geral predominancia gerais principalmente partes pecas acessorios relacionadas relacionados similares diversos diversas tipos tipo para pelo pela como sobre mesmo estes estas esses essas nao '; 
function kws(){var k={};if(!P)return[];(P.cnaes||[]).forEach(function(c){if(!c.on)return;var d=String(c.c).replace(/\D/g,'').slice(0,2);(DIV[d]||'').split(',').forEach(function(w){if(w)k[w]=c});if(!DIV[d])nm(c.d).split(/[^a-z]+/).forEach(function(w){if(w.length>=6&&STOP.indexOf(' '+w+' ')<0)k[w]=c})});
 nm(P.obj||'').split(/[,;.\n]+/).forEach(function(fr){fr.split(/[^a-z]+/).forEach(function(w){if(w.length>=6&&STOP.indexOf(' '+w+' ')<0)k[w]=k[w]||{c:'objeto social',d:'Objeto do contrato social'}})});
 return Object.keys(k).map(function(w){return{w:w,n:nm(w).split(/\s+/).filter(function(x){return x.length>2}).map(function(x){return x.length>5?x.slice(0,x.length-(x.length>7?2:1)):x}),c:k[w]}})}
function casa(tx){tx=' '+nm(tx).replace(/[^a-z0-9]+/g,' ')+' ';for(var i=0;i<KW.length;i++){var k=KW[i];if(k.n.length&&k.n.every(function(x){return tx.indexOf(' '+x)>=0}))return k}return null}
function salva(){return fetch(API+'perfil-cnae',{method:'POST',headers:{'X-WP-Nonce':NC,'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({cnpj:P.cnpj,nome:P.nome,mun:P.mun,uf:P.uf,obj:P.obj,filtro:P.filtro,cnaes:(P.cnaes||[]).map(function(c){return{c:c.c,d:c.d,off:!c.on,p:c.p}})})}).then(function(r){return r.json()})}
function daReceita(cnpj){return fetch('https://brasilapi.com.br/api/cnpj/v1/'+cnpj).then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(j){var l=[{c:cF(j.cnae_fiscal),d:j.cnae_fiscal_descricao,on:1,p:1}];(j.cnaes_secundarios||[]).forEach(function(x){if(x&&x.codigo)l.push({c:cF(x.codigo),d:x.descricao,on:1,p:0})});return{cnpj:cnpj,nome:j.razao_social||'',mun:j.municipio||'',uf:j.uf||'',cnaes:l,obj:(P&&P.obj)||'',filtro:(P&&P.filtro)||0}})}
/* faixa no buscador */
function faixa(){var R=document.getElementById('bq2');if(!R)return;var f=document.getElementById('dv-cnae');if(!f){f=document.createElement('div');f.id='dv-cnae';R.insertBefore(f,R.firstChild)}
 if(!P||!(P.cnaes||[]).length){f.innerHTML='<span class="s"><b>Mostre só o que você pode disputar.</b> Informe o CNPJ e marcamos cada licitação pelo CNAE da sua empresa.</span><input type="text" inputmode="numeric" placeholder="CNPJ da empresa" aria-label="CNPJ da empresa"><button type="button" data-cn="usar">Usar meu CNAE</button><button type="button" class="l" data-cn="mapa">Ver no mapa</button>';return}
 var pr=(P.cnaes||[]).filter(function(c){return c.p})[0]||P.cnaes[0],n=(P.cnaes||[]).filter(function(c){return c.on}).length;
 f.innerHTML='<span class="s"><b>Seu CNAE:</b> '+e(pr.c)+' · '+e(String(pr.d||'').slice(0,60))+(n>1?' <span style="color:#8C6A26;font-weight:700">+'+(n-1)+'</span>':'')+'</span><label><input type="checkbox" data-cn="filtro"'+(P.filtro?' checked':'')+'> Só o que posso disputar</label><button type="button" class="l" data-cn="mapa">Ver no mapa</button><button type="button" class="l" data-cn="ajustar">Ajustar</button>';
 document.body.classList.toggle('dv-cnf',!!P.filtro)}
function selos(){if(!KW.length)return;[].forEach.call(document.querySelectorAll('.bq2-i[data-r]'),function(c){if(c.querySelector('.dv-cnk'))return;var ch=c.querySelector('.bq2-ch')||c.querySelector('.t');if(!ch)return;var o=c.querySelector('.o'),tx=(o?o.textContent:c.textContent);var k=casa(tx),s=document.createElement('span');
 if(k){s.className='dv-cnk ok';s.textContent='✓ Compatível com seu CNAE';s.title='Bate com: '+k.c.c+' · '+k.c.d;c.classList.remove('dv-fora')}else{s.className='dv-cnk no';s.textContent='Confira o CNAE';s.title='Não achamos este objeto nos CNAEs da sua empresa. Se o edital exigir atividade compatível no contrato social ou no CNPJ, pode dar inabilitação.';c.classList.add('dv-fora')}
 if(ch.classList.contains('t')){var sp=ch.lastElementChild;ch.insertBefore(s,sp)}else ch.insertBefore(s,ch.firstChild)})}
function aplica(){KW=kws();faixa();[].forEach.call(document.querySelectorAll('.dv-cnk'),function(x){x.remove()});selos()}
function janela(){var m=document.createElement('div');m.className='dv-cnm';m.setAttribute('role','dialog');m.setAttribute('aria-modal','true');m.setAttribute('aria-label','Ajustar CNAE');
 var lis=(P.cnaes||[]).map(function(c,i){return'<li><input type="checkbox" data-i="'+i+'"'+(c.on?' checked':'')+' id="cn'+i+'"><label for="cn'+i+'"><code>'+e(c.c)+'</code> '+e(c.d)+(c.p?' <b style="color:#8C6A26">· principal</b>':'')+'</label></li>'}).join('');
 m.innerHTML='<div><h3>'+e(P.nome||'Sua empresa')+'</h3><p class="m">CNPJ '+e(cnpjF(P.cnpj))+'. Desmarque as atividades que você não quer disputar.</p><ul>'+lis+'</ul><label style="font-weight:700;display:block;margin-bottom:6px">Objeto do contrato social (opcional)</label><textarea placeholder="Cole aqui o texto do objeto social. Ele também vira palavra de busca.">'+e(P.obj||'')+'</textarea><p class="kw"></p><div class="ac"><button type="button" class="l" data-a="rec">Atualizar pela Receita</button><button type="button" class="l" data-a="al">Criar alerta com essas palavras</button><button type="button" data-a="ok">Salvar</button></div></div>';
 document.body.appendChild(m);var ta=m.querySelector('textarea');
 function prev(){var t=kws().map(function(k){return k.w});m.querySelector('.kw').textContent=t.length?'Palavras usadas: '+t.slice(0,18).join(', ')+(t.length>18?'…':''):'Nenhuma palavra: marque pelo menos uma atividade.'}prev();
 m.addEventListener('change',function(ev){var i=ev.target.dataset.i;if(i!=null){P.cnaes[i].on=ev.target.checked?1:0;prev()}});ta.addEventListener('input',function(){P.obj=ta.value;prev()});
 function fecha(){m.remove();document.removeEventListener('keydown',esc)}function esc(ev){if(ev.key==='Escape')fecha()}document.addEventListener('keydown',esc);
 m.addEventListener('click',function(ev){if(ev.target===m)return fecha();var a=ev.target.dataset&&ev.target.dataset.a;if(!a)return;var b=ev.target;
  if(a==='ok'){P.obj=ta.value;b.disabled=true;salva().then(function(){fecha();aplica()}).catch(function(){b.disabled=false;b.textContent='Tente de novo'})}
  if(a==='rec'){b.disabled=true;b.textContent='Buscando…';daReceita(P.cnpj).then(function(n){n.obj=ta.value;P=n;fecha();janela()}).catch(function(){b.disabled=false;b.textContent='Receita fora do ar; tente depois'})}
  if(a==='al'){P.obj=ta.value;var t=kws().map(function(k){return k.w}).filter(function(w){return w.indexOf(' ')>=0||DIVW[w]}).slice(0,6);if(!t.length)t=kws().map(function(k){return k.w}).slice(0,6);b.disabled=true;
   fetch(API+'alertas/salvar',{method:'POST',headers:{'X-WP-Nonce':NC,'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({nome:'Pelo meu CNAE',termos:t.join(', ')})}).then(function(r){return r.json()}).then(function(j){b.textContent=j&&j.ok?'Alerta criado ✓':(j&&j.message)||'Não deu certo';if(j&&j.ok)salva()}).catch(function(){b.disabled=false;b.textContent='Tente de novo'})}})}
var DIVW={};Object.keys(DIV).forEach(function(d){DIV[d].split(',').forEach(function(w){DIVW[w]=1})});
document.addEventListener('click',function(ev){var t=ev.target,a=t.dataset&&t.dataset.cn;if(!a)return;
 if(a==='usar'){var inp=document.querySelector('#dv-cnae input[type=text]'),c=String(inp&&inp.value||'').replace(/\D/g,'');if(c.length!==14){inp.focus();inp.style.borderColor='#C0392B';return}t.disabled=true;t.textContent='Buscando na Receita…';daReceita(c).then(function(n){P=n;return salva()}).then(function(){aplica()}).catch(function(){t.disabled=false;t.textContent='Receita fora do ar; tente depois'})}
 if(a==='ajustar')janela();
 if(a==='mapa')mapa(true)});
document.addEventListener('change',function(ev){if(ev.target.dataset&&ev.target.dataset.cn==='filtro'){P.filtro=ev.target.checked?1:0;document.body.classList.toggle('dv-cnf',!!P.filtro);salva()}});
/* marcou para o relatório uma que está fora do CNAE: sugere incluir a atividade */
document.addEventListener('click',function(ev){var c=ev.target.closest&&ev.target.closest('.bq2-i.dv-fora');if(!c||!ev.target.closest('.dv-sel,[data-sel],input[type=checkbox]'))return;try{if(sessionStorage.getItem('dv_cnt'))return;sessionStorage.setItem('dv_cnt',1)}catch(x){}
 setTimeout(function(){var d=document.createElement('div');d.id='dv-cnt';d.setAttribute('role','status');var o=c.querySelector('.o');d.innerHTML='<button type="button" class="x" aria-label="Fechar">×</button><b>Esta parece fora do seu CNAE.</b><br>Para disputar sem risco de inabilitação, dá para incluir a atividade no contrato social.<br><button type="button" data-q="1">Pedir orçamento da alteração</button>';document.body.appendChild(d);
  d.addEventListener('click',function(e2){if(e2.target.classList.contains('x'))d.remove();if(e2.target.dataset.q){d.remove();if(window.dvOrcamento)dvOrcamento('cnae',o?o.textContent.trim():'')}})},300)});
/* mapa: onde é cada licitação e onde fica a sua empresa */
var MUN=null,MAP=null,LAY=null,SIG='';
function muns(){if(MUN)return Promise.resolve(MUN);return fetch('https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/desenvolve/municipios.json').then(function(r){return r.json()}).then(function(l){MUN={};l.forEach(function(m){MUN[nm(m[1])+'|'+m[2]]=[m[3],m[4],m[1],m[2]]});return MUN})}
function leaflet(){if(window.L&&L.map)return Promise.resolve();return new Promise(function(ok,no){var c=document.createElement('link');c.rel='stylesheet';c.href='https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';document.head.appendChild(c);var j=document.createElement('script');j.src='https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';j.onload=ok;j.onerror=no;document.head.appendChild(j)})}
function km(a,b){var R=6371,t=Math.PI/180,dl=(b[0]-a[0])*t,dg=(b[1]-a[1])*t,x=Math.sin(dl/2)*Math.sin(dl/2)+Math.cos(a[0]*t)*Math.cos(b[0]*t)*Math.sin(dg/2)*Math.sin(dg/2);return 2*R*Math.asin(Math.sqrt(x))}
function ondeEmp(){if(P&&P.mun&&P.uf&&MUN)return MUN[nm(P.mun)+'|'+P.uf]||null;var ci=document.querySelector('#bq2 input[placeholder*="Digite e escolha"]');if(ci&&ci.value&&MUN){var m=ci.value.match(/^(.+?)\s*[\/-]\s*([A-Z]{2})$/);if(m)return MUN[nm(m[1])+'|'+m[2]]||null}return null}
function pontoDe(c){var x=(window.DVBQ||{})[c.dataset.r]||{};var mu=x.municipio_nome||x.municipio||'',uf=x.uf||'';if((!mu||!uf)){var t=(c.querySelector('.t')||{}).textContent||'';var m=t.match(/([A-ZÁÉÍÓÚÂÊÔÃÕÇ][^·\/]{1,40})\/([A-Z]{2})/);if(m){mu=m[1].trim();uf=m[2]}}return MUN&&mu&&uf?MUN[nm(mu)+'|'+uf]||null:null}
function kmChips(){if(!MUN)return;var emp=ondeEmp();if(!emp)return;[].forEach.call(document.querySelectorAll('.bq2-i[data-r]'),function(c){if(c.querySelector('.dv-km'))return;var p=pontoDe(c);if(!p)return;var ch=c.querySelector('.bq2-ch')||c.querySelector('.t');if(!ch)return;var d=Math.round(km(emp,p)),s=document.createElement('button');s.type='button';s.className='dv-km';s.dataset.r=c.dataset.r;s.textContent='📍 '+(d<5?'na sua cidade':'a '+d.toLocaleString('pt-BR')+' km da sua empresa');s.title='Ver no mapa';if(ch.classList.contains('t'))ch.insertBefore(s,ch.lastElementChild);else ch.appendChild(s)})}
/* conjunto atual de licitações (todas as da busca, não só as da página) */
var CUR={},CURn=0,FIL={tp:'',rap:0,pz:0,km:0,cn:0,fo:''};
(function(){var of=window.fetch;window.fetch=function(u,o){var pr=of.apply(this,arguments),U=String(u&&u.url||u);
 if(/pncp\.gov\.br\/api\/search/.test(U)&&!/[?&]dvmapa=1/.test(U))pr.then(function(r){r.clone().json().then(function(j){(j.items||[]).forEach(function(x){var m=/compras\/(\d{14})\/(\d{4})\/(\d+)/.exec(x.item_url||'');if(m)CUR[m[1]+'/'+m[2]+'/'+m[3]]={f:'p',x:x}})}).catch(function(){})}).catch(function(){});
 else if(/buscador\/diarios/.test(U))pr.then(function(r){r.clone().json().then(function(j){(j.itens||[]).forEach(function(x){if(x.ref)CUR[x.ref]={f:'d',x:x}})}).catch(function(){})}).catch(function(){});
 return pr}})();
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('#bq2 button');if(b&&/Buscar licita/.test(b.textContent)){CUR={};SIG=''}},true);
function rapida(x){var t=nm((x.modalidade_licitacao_nome||x.modalidade||'')+' '+(x.description||x.objeto||''));return /dispensa|inexig|emergenc/.test(t)}
function tipoX(x){var t=nm((x.title||'')+' '+(x.description||x.objeto||''));if(/^(aquisicao|compra|fornecimento|registro de precos? para (a )?aquisicao)/.test(t.replace(/^[^a-z]+/,''))||/aquisicao de|compra de/.test(t))return'c';if(/servic|manutenc|obra|engenharia|locacao|terceiriz|limpeza|vigilanc|transporte|consultoria|assessoria/.test(t))return's';return''}
function fimX(x){return x.data_fim_vigencia||x.encerra||x.fim||''}
function ptX(x){var mu=x.municipio_nome||x.municipio||'',uf=x.uf||'';return MUN&&mu&&uf?MUN[nm(mu)+'|'+uf]||null:null}
function linkX(r,o){if(o.f==='p')return'https://pncp.gov.br/app/editais/'+r.replace(/\//g,'/');return o.x.link||o.x.url||''}
function passa(r,o,emp){var x=o.x;if(FIL.fo&&o.f!==FIL.fo)return false;if(FIL.tp&&tipoX(x)!==FIL.tp)return false;if(FIL.rap&&!rapida(x))return false;
 if(FIL.pz){var f=fimX(x);if(!f)return false;var d=(new Date(f.replace(' ','T'))-Date.now())/864e5;if(d<0||d>FIL.pz)return false}
 if(FIL.cn&&KW.length&&!casa((x.title||'')+' '+(x.description||x.objeto||'')))return false;
 if(FIL.km&&emp){var p=ptX(x);if(!p||km(emp,p)>FIL.km)return false}return true}
function mapa(abrir,foco){var R=document.getElementById('bq2');if(!R)return;var box=document.getElementById('dv-mapa');
 if(!abrir&&!box)return;
 if(!box){box=document.createElement('div');box.id='dv-mapa';
  box.innerHTML='<div class="mh"><b>Mapa das licitações</b><span class="lg"><i class="e"></i> Sua empresa <i class="o"></i> Licitações</span><span class="ct"></span><button type="button" class="x" aria-label="Fechar mapa">×</button></div>'+
  '<div class="mf"><select data-f="tp" aria-label="Tipo"><option value="">Serviços e compras</option><option value="s">Só serviços</option><option value="c">Só compras</option></select>'+
  '<select data-f="pz" aria-label="Prazo"><option value="0">Qualquer prazo</option><option value="3">Fecham em até 3 dias</option><option value="7">Fecham em até 7 dias</option><option value="30">Fecham em até 30 dias</option></select>'+
  '<select data-f="km" aria-label="Distância"><option value="0">Qualquer distância</option><option value="50">Até 50 km</option><option value="100">Até 100 km</option><option value="300">Até 300 km</option><option value="600">Até 600 km</option></select>'+
  '<select data-f="fo" aria-label="Fonte"><option value="">PNCP e diários</option><option value="p">Só PNCP</option><option value="d">Só diários oficiais</option></select>'+
  '<label><input type="checkbox" data-f="rap"> Contratação rápida</label><label><input type="checkbox" data-f="cn"> Compatível com meu CNAE</label>'+
  '<span class="sp"></span><select data-uf aria-label="Estado"><option value="">Ver todas as abertas de um estado…</option>'+'AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split(' ').map(function(u){return'<option>'+u+'</option>'}).join('')+'</select></div><div class="mm"></div>';
  var f=document.getElementById('dv-cnae');(f&&f.nextSibling?R.insertBefore(box,f.nextSibling):R.insertBefore(box,R.firstChild));
  box.querySelector('.x').onclick=function(){box.remove();MAP=null;SIG=''};
  box.querySelector('.mf').addEventListener('change',function(ev){var t=ev.target;if(t.dataset.uf!==undefined){if(t.value)todasUF(t.value);return}var k=t.dataset.f;if(!k)return;FIL[k]=t.type==='checkbox'?(t.checked?1:0):(isNaN(+t.value)||t.value===''?t.value:+t.value);SIG='';mapa(true)})}
 Promise.all([leaflet(),muns()]).then(function(){var el=box.querySelector('.mm');if(!MAP){MAP=L.map(el,{scrollWheelZoom:false,attributionControl:true,minZoom:4,maxBounds:[[-36,-78],[8,-30]],maxBoundsViscosity:.8,zoomSnap:.25});L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:18,attribution:'© OpenStreetMap'}).addTo(MAP)}
  var emp=ondeEmp(),sig=Object.keys(CUR).length+'|'+JSON.stringify(FIL)+'|'+(emp?emp[2]:'');if(!abrir&&sig===SIG)return;SIG=sig;
  if(LAY)LAY.remove();LAY=L.layerGroup().addTo(MAP);var pts=[],raio=parseFloat((document.querySelector('#bq2 input[placeholder^="Ex.: 30"]')||{}).value)||0;
  var kmSel=box.querySelector('[data-f=km]');if(kmSel)kmSel.disabled=!emp;var cnSel=box.querySelector('[data-f=cn]');if(cnSel)cnSel.disabled=!KW.length;
  if(emp){L.circleMarker([emp[0],emp[1]],{radius:10,color:'#15191F',weight:3,fillColor:'#C9A35C',fillOpacity:1}).bindTooltip('Sua empresa · '+emp[2]+'/'+emp[3]).addTo(LAY);pts.push([emp[0],emp[1]]);var rr=FIL.km||raio;if(rr)L.circle([emp[0],emp[1]],{radius:rr*1000,color:'#C9A35C',weight:1,fillOpacity:.06}).addTo(LAY)}
  var por={},n=0,fora=0;Object.keys(CUR).forEach(function(r){var o=CUR[r];if(!passa(r,o,emp))return;var p=ptX(o.x);if(!p){fora++;return}n++;var k=p[2]+'|'+p[3];(por[k]=por[k]||{p:p,l:[]}).l.push([r,o])});
  var alvo=null,cid=Object.keys(por).length;box.querySelector('.ct').textContent=n.toLocaleString('pt-BR')+' licitaç'+(n===1?'ão':'ões')+' em '+cid+' cidade'+(cid===1?'':'s');
  Object.keys(por).forEach(function(k){var g=por[k],p=g.p;
   var h='<b>'+e(p[2])+'/'+e(p[3])+'</b> · '+g.l.length+' licitaç'+(g.l.length>1?'ões':'ão')+(emp?' · a '+Math.round(km(emp,p)).toLocaleString('pt-BR')+' km':'')+'<div class="pl">'+g.l.slice(0,12).map(function(it){var r=it[0],x=it[1].x,f=fimX(x),c=document.querySelector('.bq2-i[data-r="'+r+'"]'),t=(x.title||'')+(x.description?' · '+x.description:'')||x.objeto||'';t=(x.description||x.objeto||x.title||'').trim();
    return'<div class="pi">'+(c?'<a href="#" data-ir="'+e(r)+'">':'<a href="'+e(linkX(r,it[1]))+'" target="_blank" rel="noopener">')+e(t.slice(0,90))+'</a><small>'+e(x.modalidade_licitacao_nome||x.modalidade||'')+(f?' · até '+e(f.slice(8,10)+'/'+f.slice(5,7)):'')+'</small></div>'}).join('')+(g.l.length>12?'<small>e mais '+(g.l.length-12)+'</small>':'')+'</div>';
   var m=L.circleMarker([p[0],p[1]],{radius:5+Math.min(10,Math.sqrt(g.l.length)*2),color:'#8C6A26',weight:2,fillColor:'#C9A35C',fillOpacity:.75}).bindPopup(h,{maxWidth:340}).addTo(LAY);pts.push([p[0],p[1]]);
   if(foco&&g.l.some(function(it){return it[0]===foco}))alvo=m});
  if(alvo){MAP.setView(alvo.getLatLng(),8);alvo.openPopup()}else if(pts.length>1)MAP.fitBounds(pts,{padding:[24,24],maxZoom:9});else if(pts.length)MAP.setView(pts[0],8);else MAP.setView([-14.2,-51.9],4);
  setTimeout(function(){MAP.invalidateSize()},50);if(foco)box.scrollIntoView({behavior:'smooth',block:'start'})}).catch(function(){box.querySelector('.mm').textContent='Não foi possível carregar o mapa agora.'})}
/* todas as abertas de um estado, direto do PNCP */
function todasUF(uf){var box=document.getElementById('dv-mapa'),ct=box&&box.querySelector('.ct');CUR={};SIG='';var pg=1,tot=0,lidos=0;
 function vai(){if(ct)ct.textContent='Carregando as abertas de '+uf+'… '+lidos.toLocaleString('pt-BR')+(tot?' de '+tot.toLocaleString('pt-BR'):'');
  return fetch('https://pncp.gov.br/api/search/?tipos_documento=edital&status=recebendo_proposta&ordenacao=-data&tam_pagina=500&ufs='+uf+'&pagina='+pg+'&dvmapa=1').then(function(r){return r.json()}).then(function(j){tot=+j.total||0;(j.items||[]).forEach(function(x){var m=/compras\/(\d{14})\/(\d{4})\/(\d+)/.exec(x.item_url||'');if(m){CUR[m[1]+'/'+m[2]+'/'+m[3]]={f:'p',x:x};lidos++}});
   if((j.items||[]).length===500&&pg<20&&lidos<tot){pg++;if(pg%3===0)mapa(true);return vai()}}).catch(function(){})}
 vai().then(function(){SIG='';mapa(true)})}
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('.dv-km');if(b){ev.preventDefault();mapa(true,b.dataset.r);return}var a=ev.target.closest&&ev.target.closest('#dv-mapa a[data-ir]');if(a){ev.preventDefault();var c=document.querySelector('.bq2-i[data-r="'+a.dataset.ir+'"]');if(c){c.scrollIntoView({behavior:'smooth',block:'center'});c.style.boxShadow='0 0 0 3px #C9A35C';setTimeout(function(){c.style.boxShadow=''},1800)}}});
function inicia(){if(carregado||!document.getElementById('bq2'))return;carregado=true;
 fetch(API+'perfil-cnae',{headers:{'X-WP-Nonce':NC},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){P=j.perfil;if(!P&&j.cnpj&&j.cnpj.length===14){return daReceita(j.cnpj).then(function(n){P=n;return salva()}).catch(function(){})}}).then(aplica).catch(faixa)}
var tt,tm;new MutationObserver(function(m){if(m.every(function(x){return x.target.closest&&x.target.closest('#dv-mapa')}))return;inicia();clearTimeout(tt);tt=setTimeout(function(){selos();if(document.querySelector('.bq2-i'))muns().then(kmChips).catch(function(){});if(document.getElementById('dv-mapa')){clearTimeout(tm);tm=setTimeout(function(){mapa(false)},600)}},300)}).observe(document.body,{childList:true,subtree:true});inicia();
})();</script>
<?php
}, 140 );
