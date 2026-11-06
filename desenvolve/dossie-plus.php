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

/* Página do dossiê: espera em segundo plano + parecer de habilitação */
add_action( 'wp_footer', function () {
	global $post; if ( ! is_user_logged_in() || ! $post ) { return; }
	$ds = strpos( (string) $post->post_content, 'tool="dossie"' ) !== false; $cp = strpos( (string) $post->post_content, 'id="cp2"' ) !== false;
	if ( ! $ds && ! $cp ) { return; }
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
 ['gov','Experiência com o governo','Contratos públicos já assinados',/contratos p[uú]blicos|contratos e pagamentos|federal/i],
 ['pes','Quem está por trás','Cadastro, sócios e empresas ligadas',/cadastro na receita|s[oó]cios e admin|grupo econ/i],
 ['rep','Justiça e reputação','Processos, diários oficiais e imprensa',/processos judiciais|di[aá]rios oficiais|imprensa|publica[cç][oõ]es/i]
];
function statusTemas(d){var X=dados(d),p=X.p,pt=X.pt,o={};
 o.san=X.sanc.length||X.ls?['vm',X.sanc.length?X.sanc.length+' registro(s) em cadastros de sanção':'Aparece na lista suja do trabalho escravo']:['ok','Nada consta nos cadastros oficiais'];
 o.fin=p&&p.total>0?[(+p.em_cobranca>0||+p.fgts>0)?'vm':'am',mc(p.total)+' em dívida ativa da União']:['ok','Sem dívida ativa da União'];
 var vV=X.vig.reduce(function(a,x){return a+x.valor},0);
 o.gov=X.cons.length?['ok',X.cons.length+' contrato(s) público(s)'+(X.vig.length?', '+X.vig.length+' ativo(s) ('+mc(vV)+')':'')]:['am','Nenhum contrato público encontrado'];
 var novos=(X.c.qsa||[]).filter(function(s){return s.data_entrada_sociedade&&(Date.now()-new Date(s.data_entrada_sociedade))/31557600000<2}).length;
 o.pes=X.pep.length?['am',X.pep.length+' sócio(s) politicamente exposto(s)']:novos?['am',novos+' sócio(s) entrou(aram) há menos de 2 anos']:['ok',(X.c.qsa||[]).length+' sócio(s) · '+X.grupo.length+' empresa(s) ligada(s)'];
 var grav=(+pt.criminal||0)+(+pt.improbidade||0)+(+pt.falencia||0),tx=+pt.trab_execucao||0;
 o.rep=grav?['vm',grav+' processo(s) grave(s) (criminal, improbidade ou falência)']:tx?['am',tx+' execução(ões) trabalhista(s)']:['ok','Nenhum processo grave encontrado'];
 var L=itens(d),v=veredito(L);o.hab=[v[0]==='ok'?'ok':v[0],v[1]];o._v=v;o._L=L;return o}
function acoes(L){var M=ST.modo;if(M==='concorrente'){var f=L.filter(function(x){return x.s==='vm'||x.s==='am'}).sort(function(x,y){return(x.s==='vm'?0:1)-(y.s==='vm'?0:1)});if(f.length){var r=f.slice(0,2).map(function(x){return'Na sessão, peça diligência sobre: '+x.t.toLowerCase()+' ('+x.f+').'});r.push('Se ela for habilitada, registre a intenção de recorrer e apresente o recurso em 3 dias úteis (art. 165).');return r}}var a=L.filter(function(x){return(x.s==='vm'||x.s==='am')&&x.ac}).sort(function(x,y){return(x.s==='vm'?0:1)-(y.s==='vm'?0:1)}).slice(0,3).map(function(x){return x.ac});
 if(!a.length)a=M==='concorrente'?['Analise a proposta e a exequibilidade do preço dela: na habilitação, os dados públicos não mostram ponto fraco.']:['Peça as certidões do dia da sessão (federal, FGTS e trabalhista).','Peça os atestados de capacidade técnica dos contratos listados.'];
 return a}
function capa(r,d){var o=statusTemas(d),v=o._v,X=dados(d),p=X.p,c=X.c,vV=X.vig.reduce(function(a,x){return a+x.valor},0),pt=X.pt;
 var proc=(+pt.criminal||0)+(+pt.improbidade||0)+(+pt.falencia||0)+(+pt.coletiva||0);
 var K=[[X.sanc.length?X.sanc.length:'Nada consta','Sanções',X.sanc.length?'vm':''],[p&&p.total>0?mc(p.total):'Nada consta','Dívida ativa da União',p&&p.total>0?'am':''],[X.vig.length+' · '+mc(vV),'Contratos públicos ativos',''],[proc||'Nenhum','Processos graves',proc?'vm':''],[c.capital_social?mc(c.capital_social):'—','Capital social','']];
 var M=MODOS.filter(function(m){return m[0]===ST.modo})[0][1];
 var H='<div class="q"><div class="v '+v[0]+'"><small>Conclusão · avaliando como '+e(M.toLowerCase())+'</small><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p></div><div class="acoes"><b>O que fazer agora</b><ol>'+acoes(o._L).map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ol></div></div>';
 H+='<div class="kpi">'+K.map(function(k){return'<div class="'+k[2]+'"><b>'+e(k[0])+'</b><span>'+e(k[1])+'</span></div>'}).join('')+'</div>';
 var cp=document.getElementById('dv-capa');cp.innerHTML=H;
 var nv=r.querySelector('.rp-hd .nv');if(nv){nv.className='nv '+v[0];nv.innerHTML='<b>'+e(v[1])+'</b><span>Parecer de habilitação</span>'}
 document.getElementById('dv-mapa').innerHTML='<p>Os seis temas do relatório. Clique para ir direto ao detalhe.</p><nav>'+TEMAS.map(function(t){var s=o[t[0]]||['na',''];return'<a href="#dv-t-'+t[0]+'" class="'+s[0]+'" data-t="'+t[0]+'"><b>'+e(t[1])+'</b><span>'+e(s[1])+'</span></a>'}).join('')+'</nav>';
 document.querySelectorAll('#dv-mapa a').forEach(function(a){a.onclick=function(ev){var dt=document.getElementById('dv-t-'+a.dataset.t);if(dt){ev.preventDefault();dt.open=true;dt.scrollIntoView({behavior:'smooth',block:'start'})}}});
 TEMAS.forEach(function(t){var dt=document.getElementById('dv-t-'+t[0]);if(!dt)return;var s=o[t[0]]||['na',''];dt.className='dv-tema '+s[0];dt.querySelector('summary span').textContent=s[1]});
}
function organiza(r,d){if(r.dataset.org===String(d.cnpj))return;r.dataset.org=String(d.cnpj);r.classList.add('dv-org');
 var secs=[].slice.call(r.querySelectorAll(':scope > .rp-sec'));
 secs.forEach(function(s){var h=s.querySelector('h3');var t=h?h.textContent.replace(/^\s*[0-9!]+[a-z]?\s*/,''):'';if(/resumo executivo|riscos em destaque/i.test(t))s.style.display='none'});
 var anc=r.querySelector('.ds-parc')||r.querySelector('.rp-bar');
 var cp=document.createElement('section');cp.id='dv-capa';var mp=document.createElement('section');mp.id='dv-mapa';
 anc.parentNode.insertBefore(cp,anc.nextSibling);cp.parentNode.insertBefore(mp,cp.nextSibling);
 var ult=mp,usados=[];
 TEMAS.forEach(function(t,i){var dt=document.createElement('details');dt.id='dv-t-'+t[0];dt.className='dv-tema';if(i===0)dt.open=true;
  dt.innerHTML='<summary><i class="st" aria-hidden="true"></i><div><b>'+e(t[1])+'</b><span>'+e(t[2])+'</span></div></summary>';
  if(t[0]==='hab'){var pa=document.getElementById('dv-par');if(pa)dt.appendChild(pa)}
  else secs.forEach(function(s){if(usados.indexOf(s)>=0||s.style.display==='none')return;var h=s.querySelector('h3');if(h&&t[3].test(h.textContent)){dt.appendChild(s);usados.push(s)}});
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
 return{pts:pts,semDados:semDados,rp:rp,ano:anos[0],sp:sp,ct:ct,ativos:ativos,abertos:abertos,abI:abI,atI:atI,ctI:ctI,top:top,top3:top3,tot:tot,PL:PL,plG:plG,POL:POL,PG:PG,NW:NW,item:d.item||'',cob:cob,nt:nt}}
function veredito(A){var pag=A.semDados?'am':A.pts>=80?'ok':A.pts>=55?'am':'vm';
 if(pag==='vm')return['vm','Evite ou proteja o preço','Os dados fiscais mostram risco alto de atraso nos pagamentos (nota '+A.pts+'/100). Se for vender, ponha no preço o custo de receber com atraso e não comprometa capital de giro alto.'];
 if(pag==='am')return['am','Venda com cuidado',A.semDados?'O ente não publicou os demonstrativos fiscais que usamos para medir se ele paga em dia. Confirme a reputação de pagamento com outros fornecedores antes de investir na disputa.':'Há sinais de atraso nos pagamentos (nota '+A.pts+'/100). Vale vender, mas acompanhe os prazos de pagamento de perto.'];
 return['ok','Bom pagador: vale disputar','Os dados fiscais indicam que o órgão paga em dia (nota '+A.pts+'/100).'+(A.top3>.6?' Atenção: o mercado é concentrado.':'')]}
function acoes(A){var a=[];
 if(A.abI.length)a.push((A.item?'Há '+A.abI.length+' licitação(ões) aberta(s) para '+A.item:'Há '+A.abI.length+' licitação(ões) aberta(s)')+'. A primeira recebe propostas até '+br(A.abI[0].fim)+'.');
 else if(A.atI.length)a.push('O contrato atual'+(A.item?' de '+A.item:'')+' ('+A.atI[0].fornecedor+') vence em '+br(A.atI[0].fim)+'. Comece a se preparar uns 90 dias antes e acompanhe o plano de contratações do órgão.');
 else if(A.item)a.push('Não achamos contrato nem licitação aberta para '+A.item+'. Crie um alerta para ser avisado quando o órgão publicar.');
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
 o.pan=A.top3>.6?['am','Mercado concentrado: top 3 com '+Math.round(A.top3*100)+'%']:['ok',A.ativos.length+' contrato(s) ativo(s) · '+A.abertos.length+' licitação(ões) aberta(s)'];
 var T=A.POL.titular;o.gov=T?['na',(A.POL.cargo||'Gestor')+': '+T.urna+(T.partido?' ('+T.partido+')':'')]:['na','Sem dados da gestão'];
 o.con=A.plG.length?['vm',A.plG.length+' processo(s) grave(s) sobre licitações']:A.PL.length||A.PG.length?['am',(A.PL.length+A.PG.length)+' processo(s) a conferir']:['ok','Nada relevante encontrado'];
 return o}
function capa(r,A){var v=veredito(A),o=status(A);
 var K=[[A.semDados?'—':A.pts+'/100','Nota de pagamento',A.semDados?'':A.pts<55?'vm':A.pts<80?'am':''],[A.ativos.length+' · '+mc(A.ativos.reduce(function(a,x){return a+(x.valor||0)},0)),'Contratos ativos',''],[A.abertos.length+(A.item?' ('+A.abI.length+' do item)':''),'Licitações abertas',''],[A.top.length?Math.round(A.top3*100)+'%':'—','Valor com os 3 maiores fornecedores',A.top3>.6?'am':''],[A.plG.length||'Nenhum','Processos graves sobre licitações',A.plG.length?'vm':'']];
 var H='<div class="q"><div class="v '+v[0]+'"><small>Vale a pena vender para este órgão?</small><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p></div><div class="acoes"><b>O que fazer agora</b><ol>'+acoes(A).map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ol></div></div>';
 H+='<div class="kpi">'+K.map(function(k){return'<div class="'+k[2]+'"><b>'+e(k[0])+'</b><span>'+e(k[1])+'</span></div>'}).join('')+'</div>';
 document.getElementById('dv-capa').innerHTML=H;
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
 var H='<div><b>'+mc(A.todas.valor)+'</b><span>em '+n(A.todas.n)+' licitações abertas agora no Brasil</span></div>';
 H+='<div><b>'+mc(A.rapidas.valor)+'</b><span>em '+n(A.rapidas.n)+' contratações rápidas (dispensa e inexigibilidade) abertas agora</span></div>';
 if(Y&&j.dias_cobertos>=360)H+='<div><b>'+mc(Y.todas.valor)+'</b><span>por ano em licitações publicadas, '+mc(Y.rapidas.valor)+' delas em contratações rápidas. Quanto disso é do seu ramo?</span></div>';
 else H+='<div><b>'+n(A.todas.n+0)+'</b><span>oportunidades abertas hoje. Quanto disso é do seu ramo? Faça a consulta grátis.</span></div>';
 box.innerHTML=H;
 var nt=document.getElementById('dv-val-n');if(!nt){nt=document.createElement('p');nt.id='dv-val-n';box.parentNode.insertBefore(nt,box.nextSibling)}
 nt.textContent='Valores estimados pelos órgãos no PNCP, atualizados em '+String(j.abertas_em||'').split(' ')[0].split('-').reverse().join('/')+'. Licitações com valor sigiloso não entram na soma.';
}).catch(function(){});
})();
</script>
	<?php
}, 80 );
