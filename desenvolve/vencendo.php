/**
 * Desenvolve — Radar de contratos vencendo
 * Contratos de serviços contínuos (facilities) publicados no PNCP que vencem nos próximos meses,
 * com o fornecedor atual. Dados: data/contratos/uf/<UF>.json do repositório (robô semanal no GitHub).
 * Incluso na assinatura (sem créditos). Conecta: Raio-X do fornecedor, Due Diligence, Raio-X do órgão,
 * Gestão de licitações, Alertas e Banco de preços. E-mail semanal (segunda) para quem ativar.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'DV_VENC_RAW', 'https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/contratos/' );
function dv_venc_ramos() { return array( 'limpeza' => 'Limpeza e conservação', 'vigilancia' => 'Vigilância', 'portaria' => 'Portaria e recepção', 'manutencao' => 'Manutenção predial e climatização', 'alimentacao' => 'Alimentação', 'apoio' => 'Apoio administrativo e mão de obra', 'jardinagem' => 'Jardinagem e roçada', 'transporte' => 'Transporte e motoristas', 'lavanderia' => 'Lavanderia', 'residuos' => 'Coleta de resíduos e limpeza urbana' ); }
function dv_venc_uf( $uf ) {
	$uf = strtoupper( preg_replace( '/[^a-z]/i', '', $uf ) ); if ( strlen( $uf ) !== 2 ) { return array(); }
	$ck = 'dv_venc_uf_' . $uf; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	$g = wp_remote_get( DV_VENC_RAW . 'uf/' . $uf . '.json', array( 'timeout' => 30 ) );
	$j = is_wp_error( $g ) ? null : json_decode( wp_remote_retrieve_body( $g ), true );
	$l = (array) ( $j['itens'] ?? array() ); set_transient( $ck, $l, 12 * HOUR_IN_SECONDS ); return $l;
}
add_action( 'rest_api_init', function () {
	$perm = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/venc/prefs', array(
		array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () { return array( 'prefs' => json_decode( get_user_meta( get_current_user_id(), 'dv_venc_prefs', true ) ?: '{}', true ) ?: new stdClass() ); } ),
		array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
			$ufs = array_values( array_filter( array_map( function ( $u ) { return strtoupper( preg_replace( '/[^a-z]/i', '', $u ) ); }, (array) $r['ufs'] ), function ( $u ) { return strlen( $u ) === 2; } ) );
			$ra = array_values( array_intersect( (array) $r['ramos'], array_keys( dv_venc_ramos() ) ) );
			$p = array( 'ufs' => array_slice( $ufs, 0, 27 ), 'ramos' => $ra, 'email' => ! empty( $r['email'] ) ? 1 : 0, 'meses' => min( 18, max( 1, (int) $r['meses'] ) ) );
			update_user_meta( get_current_user_id(), 'dv_venc_prefs', wp_json_encode( $p ) ); return array( 'ok' => true, 'prefs' => $p );
		} ),
	) );
} );
/* E-mail semanal (segunda de manhã) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_venc_semanal' ) ) { wp_schedule_event( strtotime( 'next monday 10:20 UTC' ), 'weekly', 'dv_venc_semanal' ); } } );
add_action( 'dv_venc_semanal', function () {
	global $wpdb; @set_time_limit( 600 );
	$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$R = dv_venc_ramos();
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_venc_prefs' AND meta_value LIKE '%\"email\":1%'" ) as $uid ) {
		$uid = (int) $uid; $u = get_userdata( $uid ); if ( ! $u ) { continue; }
		if ( function_exists( 'dv_status' ) && ! in_array( dv_status( $uid ), array( 'ativo', 'trial' ), true ) && ! ( function_exists( 'dv_is_master' ) && dv_is_master( $uid ) ) ) { continue; }
		$p = json_decode( get_user_meta( $uid, 'dv_venc_prefs', true ), true ); if ( empty( $p['ufs'] ) ) { continue; }
		$lim = gmdate( 'Y-m-d', strtotime( '+' . (int) ( $p['meses'] ?? 6 ) . ' months' ) ); $hoje = gmdate( 'Y-m-d' ); $L = array();
		foreach ( $p['ufs'] as $uf ) { foreach ( dv_venc_uf( $uf ) as $x ) { if ( $x['fim'] >= $hoje && $x['fim'] <= $lim && ( empty( $p['ramos'] ) || in_array( $x['ra'], $p['ramos'], true ) ) ) { $x['uf'] = $uf; $L[] = $x; } } }
		if ( ! $L ) { continue; }
		usort( $L, function ( $a, $b ) { return strcmp( $a['fim'], $b['fim'] ); } );
		$link = home_url( '/plataforma/contratos-vencendo/' );
		$h = '<div style="font-family:Arial,sans-serif;color:#16191E;max-width:680px"><h2 style="margin:0 0 6px">' . count( $L ) . ' contrato(s) do seu ramo vencem nos próximos ' . (int) ( $p['meses'] ?? 6 ) . ' meses</h2><p style="color:#6E6A60;margin:0 0 14px">Quem presta hoje, quanto vale e quando acaba. A licitação de substituição costuma sair antes do vencimento: prepare-se agora.</p>';
		foreach ( array_slice( $L, 0, 25 ) as $x ) {
			$h .= '<div style="border:1px solid #E3DED2;border-radius:10px;padding:10px 12px;margin:8px 0"><b>' . esc_html( $x['on'] ) . '</b> · ' . esc_html( $x['mu'] . '/' . $x['uf'] ) . '<br><span style="color:#6E6A60">' . esc_html( mb_substr( $x['ob'], 0, 160 ) ) . '</span><br>Vence em <b>' . esc_html( implode( '/', array_reverse( explode( '-', $x['fim'] ) ) ) ) . '</b> · ' . ( $x['v'] ? 'R$ ' . number_format( $x['v'], 2, ',', '.' ) . ' · ' : '' ) . 'Fornecedor atual: ' . esc_html( $x['fn'] ?: '—' ) . ( $x['fc'] ? ' · <a href="' . esc_url( home_url( '/plataforma/raio-x/?cnpj=' . $x['fc'] ) ) . '">Raio-X do fornecedor</a>' : '' ) . '</div>';
		}
		$h .= '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#C9A45C;color:#241A06;padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:700">Ver todos no Radar</a></p><p style="color:#8A857A;font-size:12px">Datas de vencimento como publicadas no PNCP; prorrogações não informadas pelo órgão podem não aparecer. Para parar este e-mail, desative no Radar de contratos vencendo.</p></div>';
		wp_mail( $u->user_email, count( $L ) . ' contratos do seu ramo vencem em breve · Desenvolve', $h, array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve <' . $from . '>' ) );
	}
} );
add_shortcode( 'dv_vencendo', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="vc" id="dv-vc" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-raw="<?php echo esc_attr( DV_VENC_RAW ); ?>" data-ramos="<?php echo esc_attr( wp_json_encode( dv_venc_ramos() ) ); ?>">
	<div class="vc-f">
		<label>Estados <small>(escolha um ou mais)</small><div class="vc-ufs"></div></label>
		<label>Ramos <small>(nenhum marcado = todos)</small><div class="vc-ras"></div></label>
		<div class="vc-row"><label>Vencendo em até<select name="meses"><option value="3">3 meses</option><option value="6" selected>6 meses</option><option value="12">12 meses</option><option value="18">18 meses</option></select></label><label>Buscar<input name="q" placeholder="órgão, cidade, fornecedor ou objeto"></label></div>
		<label class="vc-em"><input type="checkbox" name="email"> Receber estes contratos por e-mail toda segunda de manhã</label>
		<p class="vc-m vc-h"></p>
	</div>
	<div class="vc-top"><b class="vc-n"></b><span><button type="button" class="g vc-csv">Baixar planilha</button></span></div>
	<div class="vc-l"><p class="vc-h">Escolha um estado para ver os contratos.</p></div>
	<p class="vc-h" style="font-size:.78rem;margin-top:14px">Fonte: contratos publicados no PNCP (dados públicos). As datas de vencimento são as publicadas pelo órgão; prorrogações não informadas podem não aparecer. Atualização semanal.</p>
</div>
<style>
.vc{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.vc label{display:block;font-weight:700;margin:0 0 12px}.vc small{color:var(--mu);font-weight:500}.vc-h{color:var(--mu);font-size:.88rem;margin:6px 0 0}
.vc input,.vc select{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box;margin-top:5px}
.vc-em{display:flex!important;gap:8px;align-items:center;font-weight:600!important}.vc-em input{width:auto;margin:0}
.vc-row{display:grid;grid-template-columns:200px 1fr;gap:12px}
.vc-ufs,.vc-ras{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}.vc-ufs span,.vc-ras span{border:1px solid var(--ln);border-radius:8px;padding:5px 9px;font-size:.8rem;cursor:pointer;font-weight:600;background:#fff}.vc-ufs span.on,.vc-ras span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.vc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:9px 14px;font:inherit;font-weight:700;cursor:pointer}.vc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600}
.vc-top{display:flex;justify-content:space-between;align-items:center;margin:18px 0 8px;gap:10px;flex-wrap:wrap}
.vc-i{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:10px}
.vc-i .d{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.vc-i .o{font-weight:700}.vc-i .s{color:var(--mu);font-size:.86rem;margin-top:3px}
.vc-i .ven{font-weight:700;white-space:nowrap}.vc-i .ven.u{color:#B04A3A}.vc-i .ven.m{color:#9A6B12}
.vc-i .f{margin-top:8px;font-size:.9rem}.vc-i .a{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.vc-i .a a,.vc-i .a button{font-size:.8rem;font-weight:600;border:1px solid var(--ln);border-radius:9px;padding:6px 10px;text-decoration:none;color:#16191E;background:#fff;cursor:pointer}.vc-i .a a.p{background:#C9A45C;border-color:#C9A45C;color:#241A06}
@media(max-width:640px){.vc{padding:16px}.vc-row{grid-template-columns:1fr}}
</style>
<script>
(function(){var R=document.getElementById('dv-vc');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,RAW=R.dataset.raw,RA=JSON.parse(R.dataset.ramos),UF='AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split(' '),ufs={},ras={},C={},LIST=[],P={},q=new URLSearchParams(location.search),FO=q.get('orgao')||'',FF=q.get('fornecedor')||'',QQ=q.get('q')||'',M=R.querySelector('.vc-m');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.split('-').reverse().join('/'):''}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}):''}
function fc(c){c=String(c||'');return c.length===14?c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5'):c}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function chips(){R.querySelector('.vc-ufs').innerHTML=UF.map(function(u){return'<span data-uf="'+u+'" class="'+(ufs[u]?'on':'')+'">'+u+'</span>'}).join('');R.querySelector('.vc-ras').innerHTML=Object.keys(RA).map(function(k){return'<span data-ra="'+k+'" class="'+(ras[k]?'on':'')+'">'+e(RA[k])+'</span>'}).join('')}
function carregaUF(u){if(C[u])return Promise.resolve(C[u]);return fetch(RAW+'uf/'+u+'.json').then(function(r){return r.ok?r.json():{itens:[]}}).catch(function(){return{itens:[]}}).then(function(j){C[u]=(j.itens||[]).map(function(x){x.uf=u;return x});return C[u]})}
function pncp(id){var m=/^(\d{14})-\d+-(\d+)\/(\d{4})$/.exec(id||'');return m?'https://pncp.gov.br/app/contratos/'+m[1]+'/'+m[3]+'/'+(+m[2]):''}
function dias(d){var a=new Date(d+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);return Math.round((a-h)/864e5)}
function pintar(){var us=Object.keys(ufs).filter(function(k){return ufs[k]});if(!us.length&&!FO&&!FF&&!QQ){R.querySelector('.vc-l').innerHTML='<p class="vc-h">Escolha um estado para ver os contratos.</p>';R.querySelector('.vc-n').textContent='';LIST=[];return}
 R.querySelector('.vc-l').innerHTML='<p class="vc-h">Carregando…</p>';var lst=us.length?us:UF;
 Promise.all(lst.map(carregaUF)).then(function(a){var m=+R.querySelector('[name=meses]').value,lim=new Date();lim.setMonth(lim.getMonth()+m);var L=lim.toISOString().slice(0,10),hoje=new Date().toISOString().slice(0,10),t=R.querySelector('[name=q]').value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''),rs=Object.keys(ras).filter(function(k){return ras[k]});
  LIST=[].concat.apply([],a).filter(function(x){if(x.fim<hoje||x.fim>L)return false;if(rs.length&&rs.indexOf(x.ra)<0)return false;if(FO&&x.oc!==FO)return false;if(FF&&x.fc!==FF)return false;if(t){var s=(x.on+' '+x.un+' '+x.mu+' '+x.fn+' '+x.ob+' '+x.fc).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');if(!t.split(/\s+/).filter(function(w){return w.length>2&&['dos','das','para','com','por','servico','servicos','contratacao','empresa','prestacao'].indexOf(w)<0}).every(function(w){return s.indexOf(w.replace(/s$/,''))>=0}))return false}return true}).sort(function(a,b){return a.fim<b.fim?-1:1});
  var tot=LIST.reduce(function(s,x){return s+(x.v||0)},0);R.querySelector('.vc-n').textContent=LIST.length.toLocaleString('pt-BR')+' contrato(s) vencendo'+(tot?' · '+moeda(tot)+' em contratos':'')+(FO?' · filtrado por órgão':'')+(FF?' · filtrado por fornecedor':'');
  R.querySelector('.vc-l').innerHTML=LIST.length?LIST.slice(0,300).map(function(x,i){var d=dias(x.fim),lk=pncp(x.id);return'<div class="vc-i"><div class="d"><div><div class="o">'+e(x.on)+'</div><div class="s">'+e((x.un&&x.un!==x.on?x.un+' · ':'')+x.mu+'/'+x.uf)+' · '+e(RA[x.ra]||'')+'</div></div><div class="ven '+(d<=90?'u':d<=180?'m':'')+'">Vence em '+br(x.fim)+'<div class="s" style="text-align:right">'+(d>=0?'faltam '+d+' dias':'venceu')+'</div></div></div>'+
   '<div class="s" style="margin-top:6px">'+e(x.ob)+'</div><div class="f">Fornecedor atual: <b>'+e(x.fn||'não informado')+'</b>'+(x.fc?' · CNPJ '+fc(x.fc):'')+(x.v?' · '+moeda(x.v):'')+'</div>'+
   '<div class="a">'+(x.fc&&x.fc.length===14?'<a class="p" href="/plataforma/raio-x/?cnpj='+x.fc+'" target="_blank" rel="noopener">Raio-X do fornecedor atual · 2 créditos</a><a href="/plataforma/dossie/?cnpj='+x.fc+'" target="_blank" rel="noopener">Due Diligence completa</a><a href="?fornecedor='+x.fc+'">Outros contratos dele vencendo</a>':'')+
   (x.oc?'<a href="/plataforma/raio-x-comprador/?cnpj='+x.oc+'" target="_blank" rel="noopener">Raio-X do órgão</a>':'')+'<button type="button" data-g="'+i+'">Acompanhar na Gestão</button><a href="/plataforma/alertas/?termos='+encodeURIComponent((RA[x.ra]||'').split(' ')[0].toLowerCase())+'&uf='+x.uf+'&nome='+encodeURIComponent((RA[x.ra]||'')+' em '+x.uf)+'">Criar alerta do edital</a><a href="/plataforma/banco-de-precos/" target="_blank" rel="noopener">Banco de preços</a>'+(lk?'<a href="'+lk+'" target="_blank" rel="noopener">Ver no PNCP ↗</a>':'')+'</div></div>'}).join('')+(LIST.length>300?'<p class="vc-h">Mostrando os 300 que vencem primeiro. Baixe a planilha para ver todos.</p>':''):'<p class="vc-h">Nenhum contrato encontrado com esses filtros. Os dados podem estar em carga inicial: tente outro estado ou mais meses.</p>'})}
function salvar(){P={ufs:Object.keys(ufs).filter(function(k){return ufs[k]}),ramos:Object.keys(ras).filter(function(k){return ras[k]}),email:R.querySelector('[name=email]').checked?1:0,meses:+R.querySelector('[name=meses]').value};req('POST','venc/prefs',P).then(function(){M.textContent=P.email?(P.ufs.length?'E-mail semanal ativado para '+P.ufs.join(', ')+'.':'Escolha ao menos um estado para receber o e-mail.'):''}).catch(function(){})}
R.addEventListener('click',function(ev){var t=ev.target;if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];chips();salvar();pintar();return}if(t.dataset.ra){ras[t.dataset.ra]=!ras[t.dataset.ra];chips();salvar();pintar();return}
 if(t.dataset.g!=null){var x=LIST[+t.dataset.g];t.disabled=true;t.textContent='Salvando…';req('POST','lic/salvar',{orgao:x.on,uf:x.uf,municipio:x.mu,objeto:x.ob,valor:x.v,status:'analise',categoria:'',notas:'Contrato atual vence em '+br(x.fim)+'. Fornecedor atual: '+(x.fn||'')+(x.fc?' (CNPJ '+fc(x.fc)+')':'')+'. Acompanhar a publicação do novo edital. Origem: Radar de contratos vencendo.'+(pncp(x.id)?' Contrato no PNCP: '+pncp(x.id):'')}).then(function(){t.textContent='Na sua gestão ✓'}).catch(function(j){t.disabled=false;t.textContent=(j&&j.message)||'Tente de novo'});return}
 if(t.classList.contains('vc-csv')){if(!LIST.length)return;var L=[['Vencimento','Dias','UF','Município','Órgão','CNPJ órgão','Ramo','Objeto','Fornecedor atual','CNPJ fornecedor','Valor','Início','Link PNCP']].concat(LIST.map(function(x){return[br(x.fim),dias(x.fim),x.uf,x.mu,x.on,fc(x.oc),RA[x.ra]||'',x.ob,x.fn,fc(x.fc),String(x.v||'').replace('.',','),br(x.ini),pncp(x.id)]}));var csv='﻿'+L.map(function(r){return r.map(function(c){return'"'+String(c==null?'':c).replace(/"/g,'""')+'"'}).join(';')}).join('\n');var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='contratos-vencendo.csv';a.click()}});
R.querySelector('[name=meses]').addEventListener('change',function(){salvar();pintar()});R.querySelector('[name=email]').addEventListener('change',salvar);var tm;R.querySelector('[name=q]').addEventListener('input',function(){clearTimeout(tm);tm=setTimeout(pintar,300)});
req('GET','venc/prefs').then(function(j){var p=j.prefs||{};(p.ufs||[]).forEach(function(u){ufs[u]=1});(p.ramos||[]).forEach(function(r){ras[r]=1});if(p.meses)R.querySelector('[name=meses]').value=String(p.meses);R.querySelector('[name=email]').checked=!!p.email;(q.get('uf')||'').split(',').forEach(function(u){if(u)ufs[u.toUpperCase()]=1});if(QQ)R.querySelector('[name=q]').value=QQ;if(q.get('uf')||QQ)history.replaceState(null,'',location.pathname+(FO?'?orgao='+FO:FF?'?fornecedor='+FF:''));chips();pintar()}).catch(function(){chips();pintar()});
})();
</script>
	<?php return ob_get_clean();
} );

/* Botão "contratos vencendo deste objeto nesta região" no Banco de preços e no Buscador de licitações */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; } ?>
<script>(function(){var P=[['bp-f','bp-q','bp-uf'],['bq-form','bq-q','bq-uf']];function liga(){P.forEach(function(p){var f=document.getElementById(p[0]);if(!f||f.dataset.vc)return;f.dataset.vc=1;f.addEventListener('submit',function(){setTimeout(function(){var q=(document.getElementById(p[1])||{}).value||'',u=(document.getElementById(p[2])||{}).value||'';q=q.trim();if(q.length<3)return;var b=document.getElementById('dv-vc-btn');if(!b){b=document.createElement('a');b.id='dv-vc-btn';b.style.cssText='display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:14px 0;padding:12px 16px;border:1px solid #C9A45C;border-radius:12px;background:#FFF8E6;color:#16191E;text-decoration:none;font-weight:600;font-family:inherit';f.parentNode.insertBefore(b,f.nextSibling)}b.href='/plataforma/contratos-vencendo/?q='+encodeURIComponent(q)+(u&&u.length===2?'&uf='+u:'');b.innerHTML='<span>📋 Contratos de “'+q.replace(/[<>&"]/g,'')+'” vencendo '+(u&&u.length===2?'em '+u:'no Brasil')+': veja quem presta hoje e quando acaba</span><span style="background:#C9A45C;color:#241A06;border-radius:9px;padding:6px 12px;font-size:.85rem">Ver contratos vencendo →</span>'},50)},true)})}liga();new MutationObserver(liga).observe(document.body,{childList:true,subtree:true})})();</script>
	<?php
}, 90 );
