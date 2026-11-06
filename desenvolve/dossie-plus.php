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
	global $post; if ( ! is_user_logged_in() || ! $post || strpos( (string) $post->post_content, 'tool="dossie"' ) === false ) { return; }
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
#dv-par .ver p{margin:0;font-size:.93rem}
#dv-par ol{list-style:none;margin:0;padding:0;display:grid;gap:10px}
#dv-par li{border:1px solid #E3DED2;border-left-width:5px;border-radius:10px;padding:12px 14px;background:#fff}
#dv-par li.ok{border-left-color:#5E9E79}#dv-par li.am{border-left-color:#D9A93A}#dv-par li.vm{border-left-color:#C2513D}#dv-par li.na{border-left-color:#B9B4A7}
#dv-par li h4{margin:0 0 3px;font-size:.98rem}#dv-par li p{margin:0;font-size:.9rem;color:#33363B}
#dv-par li .fu{margin-top:5px;font-size:.8rem;color:#8A6A2E}
#dv-par li .ac{margin-top:6px;font-size:.86rem;color:#16191E}#dv-par li .ac:before{content:"Próximo passo: ";font-weight:700}
#dv-par h4.grp{margin:20px 0 8px;font:700 .82rem "Hanken Grotesk",sans-serif;color:#6E6A60}
#dv-par .docs{margin:16px 0 0;padding:14px 16px;border-radius:12px;background:#F5F1E8;font-size:.9rem}
#dv-par .docs ul{margin:6px 0 0;padding-left:18px}
#dv-par .nota{margin-top:14px;font-size:.8rem;color:#6E6A60}
@media print{#dv-par .modos,#dv-par .ent{display:none}}
</style>
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
  if(imped)add('imp','vm','Impedimento de licitar e contratar','Há registro de impedimento. Ele vale só no ente federativo que aplicou a sanção (a União, o estado ou o município), por até 3 anos.','Lei 14.133, art. 156, III e §4º','Veja quem aplicou: se for o mesmo ente da sua licitação, a empresa está impedida; se for outro, ela pode participar.');
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
  add('fis',cob>0?'vm':'am','Dívida ativa da União: '+mc(p.total),p.inscricoes+' inscrição(ões)'+(p.ajuizadas?', '+p.ajuizadas+' ajuizada(s)':'')+(cob>0?'. Desse total, '+mc(cob)+' está em cobrança, sem suspensão: a certidão federal provavelmente sai positiva.':'. Há sinais de suspensão ou garantia, o que pode permitir uma certidão positiva com efeito de negativa.'),'Lei 14.133, art. 68, III; CTN, art. 206','Peça a Certidão Conjunta da Receita/PGFN atualizada. Só serve se for negativa ou positiva com efeito de negativa.');
  if(+p.fgts>0)add('fis','vm','Débito de FGTS inscrito: '+mc(p.fgts),'Com FGTS em dívida ativa, o Certificado de Regularidade do FGTS tende a sair negado.','Lei 14.133, art. 68, IV','Peça o CRF atualizado (Caixa).');
 } else if(p===null)add('fis','ok','Sem dívida ativa da União','Nada consta na base da PGFN para este CNPJ.','Lei 14.133, art. 68, III');
 var tx=+X.pt.trab_execucao||0,tt=(+X.pt.trabalhista||0)+tx;
 if(tx)add('fis',tx>20?'vm':'am','Execuções trabalhistas: '+tx,'Processos trabalhistas em fase de execução (condenação não paga). Execução sem pagamento nem garantia faz a Certidão Negativa de Débitos Trabalhistas sair positiva.','Lei 14.133, art. 68, V; CLT, art. 642-A','Peça a CNDT emitida no dia (TST).');
 else if(tt)add('fis','ok','Processos trabalhistas sem execução',tt+' processo(s) trabalhista(s) em andamento, nenhum em fase de execução encontrado.','Lei 14.133, art. 68, V');
 if(X.pt.execucao_fiscal)add('fis','am','Execuções fiscais: '+X.pt.execucao_fiscal,'Há cobranças judiciais de tributos (podem ser estaduais ou municipais, que não aparecem na PGFN).','Lei 14.133, art. 68, III','Peça também as certidões estadual e municipal.');
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
function docs(L){var M=ST.modo;if(M==='concorrente')return'<div class="docs"><b>Como usar no certame</b><ul><li>Na habilitação, peça diligência ao agente de contratação sobre os pontos acima (Lei 14.133, art. 64).</li><li>Se ela for habilitada mesmo assim, manifeste a intenção de recorrer na sessão e apresente o recurso em 3 dias úteis (art. 165, I e §1º).</li><li>Junte as certidões e os prints das fontes, com data: a prova tem de ser do dia da sessão.</li></ul></div>';
 var b=['Certidão Conjunta Receita/PGFN, CRF do FGTS e CNDT emitidos no dia','Certidões estadual e municipal do domicílio','Balanços dos 2 últimos exercícios e certidão negativa de falência','Atestados de capacidade técnica com as notas fiscais correspondentes','Contrato social consolidado e procuração de quem assina'];
 if(M==='parceiro')b.push('Minuta do compromisso de consórcio: os consorciados respondem solidariamente pelos atos do consórcio (art. 15, V)');
 if(M==='sub')b.push('Comprovação de capacidade técnica da subcontratada para a parcela que vai executar (art. 122)');
 return'<div class="docs"><b>Documentos para pedir antes de fechar</b><ul>'+b.map(function(x){return'<li>'+e(x)+'</li>'}).join('')+'</ul></div>'}
function pinta(){var s=document.getElementById('dv-par'),d=window.DVDS;if(!s||!d)return;var L=itens(d),v=veredito(L),M=ST.modo;
 var extra=M==='parceiro'?'Em consórcio, as dívidas e os processos dela passam a ser um risco seu: a responsabilidade é solidária (art. 15, V). Para a qualificação econômico-financeira, os valores das consorciadas se somam, e o edital pode pedir de 10% a 30% a mais do que pediria de uma empresa sozinha (art. 15, III e §1º).':M==='sub'?'Na subcontratação, quem responde perante o órgão continua sendo você. Cheque principalmente a regularidade trabalhista: passivo da subcontratada pode respingar no contrato.':'Cada ponto abaixo traz o fundamento para usar em diligência, impugnação ou recurso.';
 var H='<h3>Parecer de habilitação</h3><p class="sub">Leitura dos dados deste dossiê à luz da Lei 14.133/2021. Escolha para que você está avaliando a empresa.</p>';
 H+='<div class="modos" role="group" aria-label="Finalidade da análise">'+MODOS.map(function(m){return'<button type="button" data-m="'+m[0]+'" aria-pressed="'+(M===m[0])+'">'+m[1]+'</button>'}).join('')+'</div>';
 H+='<div class="ent"><div><label for="dv-pv">Valor estimado da licitação</label><input id="dv-pv" inputmode="decimal" placeholder="Ex.: 2.500.000" value="'+(ST.valor?e(ST.valor.toLocaleString('pt-BR')):'')+'"></div><div><label for="dv-po">Objeto (palavras-chave)</label><input id="dv-po" placeholder="Ex.: limpeza hospitalar" value="'+e(ST.obj)+'"></div></div>';
 H+='<div class="ver '+v[0]+'"><div><b>'+e(v[1])+'</b><p>'+e(v[2])+'</p><p style="margin-top:6px">'+e(extra)+'</p></div></div>';
 var gs={};L.forEach(function(x){(gs[x.g]=gs[x.g]||[]).push(x)});
 Object.keys(GR).forEach(function(g){if(!gs[g])return;var arr=gs[g];if(M==='concorrente')arr=arr.slice().sort(function(a,b){var o={vm:0,am:1,na:2,ok:3};return o[a.s]-o[b.s]});
  H+='<h4 class="grp">'+GR[g]+'</h4><ol>'+arr.map(function(x){return'<li class="'+x.s+'"><h4>'+e(x.t)+'</h4><p>'+e(x.a)+'</p>'+(x.f?'<div class="fu">'+e(x.f)+'</div>':'')+(x.ac?'<div class="ac">'+e(x.ac)+'</div>':'')+'</li>'}).join('')+'</ol>'});
 H+=docs(L);
 if((d.parcial||[]).length)H+='<p class="nota"><b>Atenção:</b> estas fontes não responderam a tempo e ficaram fora do parecer: '+e(d.parcial.join(', '))+'.</p>';
 H+='<p class="nota">Parecer automático, feito com dados públicos na data do relatório. Certidões mudam: confira todas no dia da sessão. Para um parecer assinado por advogado, use “Acionar um consultor”.</p>';
 s.innerHTML=H;
 s.querySelectorAll('[data-m]').forEach(function(b){b.onclick=function(){ST.modo=b.getAttribute('data-m');pinta()}});
 var pv=s.querySelector('#dv-pv'),po=s.querySelector('#dv-po'),tm;
 pv.onchange=function(){ST.valor=num(pv.value);pinta()};po.onchange=function(){ST.obj=po.value;pinta()};
}
function monta(){var r=document.querySelector('.dv-tool .rp')||document.querySelector('.rp');if(!r||!window.DVDS)return;var s=document.getElementById('dv-par');
 if(s&&s.parentNode===r&&s.dataset.c===String(window.DVDS.cnpj))return;if(s)s.remove();s=document.createElement('section');s.id='dv-par';s.dataset.c=String(window.DVDS.cnpj);
 var bar=r.querySelector('.rp-bar'),ap=r.querySelector('.ds-parc');var ref=(ap||bar);if(ref&&ref.nextSibling)r.insertBefore(s,ref.nextSibling);else r.insertBefore(s,r.firstChild);pinta()}
new MutationObserver(function(){monta()}).observe(document.body,{childList:true,subtree:true});
})();
</script>
	<?php
}, 70 );
