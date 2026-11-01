/**
 * Desenvolve — Alerta diário de oportunidades (licitações abertas no PNCP)
 * Buscas salvas por palavras, estados, valor e palavras a excluir. Todo dia de manhã, e-mail com as
 * licitações novas, com botão para mandar direto para a Gestão de licitações. Incluso na assinatura.
 */

define( 'DV_AL_MAX', 10 );

function dv_al_lista( $uid ) { $l = json_decode( get_user_meta( $uid, 'dv_alertas', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_al_salvar_lista( $uid, $l ) { update_user_meta( $uid, 'dv_alertas', wp_slash( wp_json_encode( array_values( $l ), JSON_UNESCAPED_UNICODE ) ) ); }
function dv_al_ativo( $uid ) { return function_exists( 'dv_is_master' ) && ( dv_is_master( $uid ) || ( function_exists( 'dv_status' ) && dv_status( $uid ) === 'ativo' ) ); }
function dv_al_norm( $s ) { return strtoupper( remove_accents( (string) $s ) ); }

/** Busca no PNCP as licitações abertas que batem com o alerta, publicadas desde $desde (Y-m-d). */
function dv_al_buscar( $a, $desde ) {
	$termos = array_slice( array_filter( array_map( 'trim', preg_split( '/[,;\n]+/', (string) $a['termos'] ) ) ), 0, 6 );
	$excl = array_filter( array_map( function ( $x ) { return dv_al_norm( trim( $x ) ); }, preg_split( '/[,;\n]+/', (string) ( $a['excluir'] ?? '' ) ) ) );
	$ufs = array_values( array_filter( (array) ( $a['ufs'] ?? array() ), function ( $u ) { return preg_match( '/^[A-Z]{2}$/', $u ); } ) );
	$urls = array();
	foreach ( $termos as $t ) {
		$q = array( 'q' => $t, 'tipos_documento' => 'edital', 'ordenacao' => '-data', 'pagina' => 1, 'tam_pagina' => 100, 'status' => 'recebendo_proposta' );
		if ( $ufs ) { $q['ufs'] = implode( '|', $ufs ); }
		$urls[ $t ] = 'https://pncp.gov.br/api/search/?' . http_build_query( $q );
	}
	$res = array();
	if ( function_exists( 'dv_ds_multi' ) ) { $res = dv_ds_multi( $urls, array(), 30 ); }
	else { foreach ( $urls as $k => $u ) { $g = wp_remote_get( $u, array( 'timeout' => 30 ) ); $res[ $k ] = is_wp_error( $g ) ? null : json_decode( wp_remote_retrieve_body( $g ), true ); } }
	$out = array();
	foreach ( $res as $termo => $j ) {
		if ( is_string( $j ) ) { $j = json_decode( $j, true ); }
		foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
			$pub = substr( (string) ( $i['data_publicacao_pncp'] ?? $i['createdAt'] ?? '' ), 0, 10 );
			if ( $pub && $pub < $desde ) { continue; }
			if ( ! preg_match( '#/compras/(\d{14})/(\d{4})/(\d+)#', (string) ( $i['item_url'] ?? '' ), $m ) ) { continue; }
			$ref = $m[1] . '/' . $m[2] . '/' . $m[3];
			$desc = (string) ( $i['description'] ?? '' ); $nd = dv_al_norm( $desc . ' ' . ( $i['title'] ?? '' ) );
			$pula = false; foreach ( $excl as $x ) { if ( $x !== '' && strpos( $nd, $x ) !== false ) { $pula = true; } } if ( $pula ) { continue; }
			$v = (float) ( $i['valor_global'] ?? 0 );
			if ( ! empty( $a['vmin'] ) && $v && $v < (float) $a['vmin'] ) { continue; }
			if ( ! empty( $a['vmax'] ) && $v && $v > (float) $a['vmax'] ) { continue; }
			if ( isset( $out[ $ref ] ) ) { $out[ $ref ]['termos'][] = $termo; continue; }
			$out[ $ref ] = array( 'ref' => $ref, 'orgao' => $i['orgao_nome'] ?? '', 'municipio' => $i['municipio_nome'] ?? '', 'uf' => $i['uf'] ?? '', 'modalidade' => $i['modalidade_licitacao_nome'] ?? '',
				'titulo' => $i['title'] ?? '', 'objeto' => mb_substr( $desc, 0, 600 ), 'valor' => $v, 'publicado' => $pub, 'encerra' => substr( (string) ( $i['data_fim_vigencia'] ?? '' ), 0, 16 ),
				'link' => 'https://pncp.gov.br/app/editais/' . $ref, 'termos' => array( $termo ) );
		}
	}
	uasort( $out, function ( $x, $y ) { return strcmp( $y['publicado'], $x['publicado'] ); } );
	return array_values( $out );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/alertas', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id();
		return array( 'alertas' => dv_al_lista( $uid ), 'max' => DV_AL_MAX, 'pref' => function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array() );
	} ) );
	register_rest_route( $ns, '/alertas/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_al_lista( $uid ); $id = sanitize_key( (string) $r['id'] );
		$termos = mb_substr( sanitize_textarea_field( (string) $r['termos'] ), 0, 400 );
		if ( mb_strlen( trim( $termos ) ) < 3 ) { return new WP_Error( 'dv_al', 'Informe pelo menos uma palavra ou expressão.', array( 'status' => 400 ) ); }
		$ufs = array_values( array_intersect( array_map( 'strtoupper', (array) $r['ufs'] ), array( 'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO' ) ) );
		$a = array( 'nome' => mb_substr( sanitize_text_field( (string) $r['nome'] ), 0, 80 ) ?: mb_substr( $termos, 0, 40 ), 'termos' => $termos, 'excluir' => mb_substr( sanitize_textarea_field( (string) $r['excluir'] ), 0, 300 ),
			'ufs' => $ufs, 'vmin' => max( 0, (float) $r['vmin'] ), 'vmax' => max( 0, (float) $r['vmax'] ), 'ativo' => ( isset( $r['ativo'] ) && ! $r['ativo'] ) ? 0 : 1 );
		$achou = false; foreach ( $l as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $a ); $achou = true; } } unset( $x );
		if ( ! $achou ) { if ( count( $l ) >= DV_AL_MAX ) { return new WP_Error( 'dv_al', 'Limite de ' . DV_AL_MAX . ' alertas.', array( 'status' => 400 ) ); } $id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $a + array( 'id' => $id, 'criado' => current_time( 'Y-m-d' ) ); }
		dv_al_salvar_lista( $uid, $l ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/alertas/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] );
		dv_al_salvar_lista( $uid, array_filter( dv_al_lista( $uid ), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ); return array( 'ok' => true );
	} ) );
	/* Prévia: o que o alerta encontra nas licitações abertas publicadas nos últimos 15 dias */
	register_rest_route( $ns, '/alertas/testar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$a = array( 'termos' => (string) $r['termos'], 'excluir' => (string) $r['excluir'], 'ufs' => (array) $r['ufs'], 'vmin' => (float) $r['vmin'], 'vmax' => (float) $r['vmax'] );
		$it = dv_al_buscar( $a, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -15 days' ) ) );
		return array( 'itens' => array_slice( $it, 0, 80 ), 'total' => count( $it ) );
	} ) );
} );

/* E-mail diário (por volta das 7h40) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_al_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:40 UTC' ), 'daily', 'dv_al_diario' ); } } );
add_action( 'dv_al_diario', function () {
	global $wpdb; @set_time_limit( 600 );
	$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_alertas' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; }
		$vistos = json_decode( get_user_meta( $uid, 'dv_alertas_vistos', true ) ?: '[]', true ) ?: array(); $set = array_flip( $vistos ); $bloco = array();
		foreach ( dv_al_lista( $uid ) as $a ) {
			if ( empty( $a['ativo'] ) ) { continue; }
			$novos = array_values( array_filter( dv_al_buscar( $a, $desde ), function ( $x ) use ( &$set ) { if ( isset( $set[ $x['ref'] ] ) ) { return false; } $set[ $x['ref'] ] = 1; return true; } ) );
			if ( $novos ) { $bloco[] = array( 'a' => $a, 'itens' => array_slice( $novos, 0, 40 ) ); }
		}
		update_user_meta( $uid, 'dv_alertas_vistos', wp_slash( wp_json_encode( array_slice( array_keys( $set ), -4000 ) ) ) );
		if ( $bloco ) { dv_al_email( $uid, $bloco ); }
	}
} );
function dv_al_email( $uid, $bloco ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$n = 0; foreach ( $bloco as $b ) { $n += count( $b['itens'] ); }
	$g = home_url( '/plataforma/licitacoes/' );
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:20px">desenvolve </span><b style="color:#C9A45C;font-size:20px">estratégias</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Oportunidades do dia · ' . date_i18n( 'd/m/Y' ) . '</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Bom dia, ' . esc_html( $u->display_name ) . '. Encontramos <b>' . $n . ' licitação(ões) aberta(s)</b> novas para os seus alertas:</p>';
	foreach ( $bloco as $b ) {
		$h .= '<h3 style="font-size:15px;color:#A8843F;letter-spacing:.08em;text-transform:uppercase;margin:22px 0 6px">' . esc_html( $b['a']['nome'] ) . ' (' . count( $b['itens'] ) . ')</h3>';
		foreach ( $b['itens'] as $x ) {
			$h .= '<div style="border-left:4px solid #C9A45C;background:#FAF8F3;padding:12px 14px;margin:8px 0;border-radius:8px"><div style="font-size:13px;color:#6E6A60"><b style="color:#16191E">' . esc_html( $x['orgao'] ) . '</b> · ' . esc_html( $x['municipio'] . '/' . $x['uf'] ) . ' · ' . esc_html( $x['modalidade'] ) . ( $x['valor'] ? ' · <b>' . esc_html( 'R$ ' . number_format( $x['valor'], 2, ',', '.' ) ) . '</b>' : '' ) . '</div><p style="font-size:14px;line-height:1.45;margin:6px 0">' . esc_html( mb_substr( $x['objeto'], 0, 320 ) ) . '</p><a href="' . esc_url( $x['link'] ) . '" style="color:#8A6A2C;font-weight:bold;margin-right:14px">Ver no PNCP</a><a href="' . esc_url( add_query_arg( 'importar', $x['ref'], $g ) ) . '" style="color:#8A6A2C;font-weight:bold">Levar para a minha gestão</a></div>';
		}
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( home_url( '/plataforma/alertas/' ) ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Ajustar meus alertas</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Fonte: Portal Nacional de Contratações Públicas (PNCP), licitações com propostas abertas publicadas desde ontem. Órgãos que ainda não publicam no PNCP podem não aparecer.</p></div></div>';
	$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, $n . ' licitação(ões) nova(s) para você · Desenvolve', $h, $hd ); } }
}

add_shortcode( 'dv_alertas', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="al" id="dv-al" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<form class="al-f" autocomplete="off">
		<h3 class="al-ft">Novo alerta</h3>
		<label>O que você vende? <small>Palavras ou expressões separadas por vírgula. Cada uma vira uma busca.</small><input name="termos" placeholder="limpeza predial, conservação, serviços de higienização"></label>
		<div class="al-2"><label>Nome do alerta<input name="nome" placeholder="Ex.: Limpeza em SP"></label><label>Excluir quando aparecer<input name="excluir" placeholder="Ex.: veículos, piscina"></label></div>
		<label>Estados <small>(nenhum marcado = Brasil todo)</small><div class="al-ufs"></div></label>
		<div class="al-2"><label>Valor mínimo (R$)<input name="vmin" inputmode="decimal" placeholder="opcional"></label><label>Valor máximo (R$)<input name="vmax" inputmode="decimal" placeholder="opcional"></label></div>
		<input type="hidden" name="id">
		<div class="al-act"><button type="button" class="g al-test">Ver o que ele encontra</button><button type="submit">Salvar alerta</button><button type="button" class="g al-novo" hidden>Cancelar edição</button></div>
		<p class="al-m al-h">Todo dia, por volta das 7h40, você recebe por e-mail as licitações abertas publicadas no PNCP desde a véspera. Os e-mails de destino são os mesmos do Radar do Diário Oficial e dos avisos de prazo.</p>
	</form>
	<h3>Meus alertas</h3><div class="al-lista"><p class="al-h">Carregando…</p></div>
	<div class="al-res"></div>
</div>
<style>
.al{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.al h3{font-size:1.05rem;margin:22px 0 10px}.al-ft{margin-top:0!important}.al small{color:var(--mu);font-weight:500}
.al button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.al button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:9px 14px}
.al input{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.al-f{border:1px solid var(--ln);border-radius:16px;padding:18px 20px;background:#fff;display:grid;gap:12px}.al-f label{font-weight:600;font-size:.88rem;display:grid;gap:5px}
.al-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.al-ufs{display:flex;flex-wrap:wrap;gap:5px}.al-ufs span{border:1px solid var(--ln);border-radius:8px;padding:5px 8px;font-size:.8rem;cursor:pointer;font-weight:600;background:#fff}.al-ufs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.al-act{display:flex;gap:8px;flex-wrap:wrap}.al-h{color:var(--mu);font-size:.86rem;margin:0}.al-m.e{color:var(--rd)}.al-m.o{color:var(--gr);font-weight:600}
.al-a{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.al-a b{display:block}.al-a span{color:var(--mu);font-size:.82rem}.al-a .x{display:flex;gap:6px}
.al-i{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px}.al-i .m{color:var(--mu);font-size:.8rem}.al-i .m b{color:#16191E}.al-i p{margin:6px 0;font-size:.92rem;line-height:1.45}.al-i .k{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.al-i a{color:#8A6A2C;font-weight:700;font-size:.85rem}.al-i button{font-size:.8rem;padding:6px 12px}
.al-tag{display:inline-block;background:#FFF4DC;color:#9A6B12;border-radius:99px;padding:1px 8px;font-size:.72rem;font-weight:700;margin-left:6px}
@media(max-width:700px){.al{padding:16px}.al-2{grid-template-columns:1fr}}
</style>
<script>
(function(){var R=document.getElementById('dv-al');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,F=R.querySelector('.al-f'),M=R.querySelector('.al-m'),ufs={};
var UF='AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ');
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):''}
function num(v){return String(v||'').replace(/\./g,'').replace(',','.')}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function pUfs(){R.querySelector('.al-ufs').innerHTML=UF.map(function(u){return'<span data-uf="'+u+'" class="'+(ufs[u]?'on':'')+'">'+u+'</span>'}).join('')}
function dados(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});o.ufs=Object.keys(ufs).filter(function(k){return ufs[k]});o.vmin=num(o.vmin);o.vmax=num(o.vmax);return o}
function limpar(){F.reset();ufs={};pUfs();R.querySelector('.al-ft').textContent='Novo alerta';R.querySelector('.al-novo').hidden=true}
function carregar(){return req('GET','alertas').then(function(j){S=j;R.querySelector('.al-lista').innerHTML=j.alertas.length?j.alertas.map(function(a){return'<div class="al-a"><div><b>'+e(a.nome)+(a.ativo?'':' <span class="al-tag">pausado</span>')+'</b><span>'+e(a.termos)+' · '+(a.ufs&&a.ufs.length?a.ufs.join(', '):'Brasil todo')+(a.vmin?' · a partir de '+moeda(a.vmin):'')+(a.vmax?' · até '+moeda(a.vmax):'')+(a.excluir?' · sem: '+e(a.excluir):'')+'</span></div><div class="x"><button class="g" data-v="'+a.id+'">Ver agora</button><button class="g" data-ed="'+a.id+'">Editar</button><button class="g" data-p="'+a.id+'">'+(a.ativo?'Pausar':'Ativar')+'</button><button class="g" data-x="'+a.id+'">Excluir</button></div></div>'}).join(''):'<p class="al-h">Nenhum alerta ainda. Crie o primeiro acima: em segundos você vê quantas licitações abertas ele encontra.</p>'})}
function mostrar(j,titulo){var Rr=R.querySelector('.al-res');Rr.innerHTML='<h3>'+e(titulo)+': '+j.total+' licitação(ões) aberta(s) nos últimos 15 dias</h3>'+(j.itens.length?j.itens.map(function(x){return'<div class="al-i"><div class="m"><b>'+e(x.orgao)+'</b> · '+e(x.municipio+'/'+x.uf)+' · '+e(x.modalidade)+' · publicado em '+br(x.publicado)+(x.valor?' · <b>'+moeda(x.valor)+'</b>':'')+'</div><p>'+e(x.objeto)+'</p><div class="k"><a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no PNCP</a><button data-imp="'+e(x.ref)+'">Levar para a minha gestão</button>'+x.termos.map(function(t){return'<span class="al-tag">'+e(t)+'</span>'}).join('')+'</div></div>'}).join(''):'<p class="al-h">Nada encontrado. Tente palavras mais gerais ou mais estados.</p>');Rr.scrollIntoView({behavior:'smooth'})}
function testar(o,t){M.className='al-m al-h';M.textContent='Buscando no PNCP…';return req('POST','alertas/testar',o).then(function(j){M.textContent='';mostrar(j,t)}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'O PNCP não respondeu agora. Tente de novo.'})}
F.addEventListener('submit',function(ev){ev.preventDefault();M.className='al-m al-h';M.textContent='Salvando…';req('POST','alertas/salvar',dados()).then(function(){M.className='al-m al-h o';M.textContent='Alerta salvo. Amanhã cedo chega o primeiro e-mail.';limpar();carregar()}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'Erro ao salvar.'})});
R.addEventListener('click',function(ev){var t=ev.target,a;
 if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];pUfs();return}
 if(t.matches('.al-test')){testar(dados(),'Prévia');return}
 if(t.matches('.al-novo')){limpar();return}
 a=S&&S.alertas.filter(function(x){return x.id===(t.dataset.v||t.dataset.ed||t.dataset.p||t.dataset.x)})[0];
 if(t.dataset.v&&a){testar(a,a.nome);return}
 if(t.dataset.ed&&a){limpar();['nome','termos','excluir','id'].forEach(function(k){F.querySelector('[name='+k+']').value=a[k]||''});F.querySelector('[name=vmin]').value=a.vmin||'';F.querySelector('[name=vmax]').value=a.vmax||'';(a.ufs||[]).forEach(function(u){ufs[u]=true});pUfs();R.querySelector('.al-ft').textContent='Editar alerta';R.querySelector('.al-novo').hidden=false;F.scrollIntoView({behavior:'smooth'});return}
 if(t.dataset.p&&a){var o=Object.assign({},a,{ativo:a.ativo?0:1});req('POST','alertas/salvar',o).then(carregar);return}
 if(t.dataset.x&&a){if(confirm('Excluir o alerta "'+a.nome+'"?'))req('POST','alertas/excluir',{id:a.id}).then(carregar);return}
 if(t.dataset.imp){t.disabled=true;t.textContent='Importando…';req('GET','lic/pncp?ref='+encodeURIComponent(t.dataset.imp)).then(function(j){return req('POST','lic/salvar',j)}).then(function(){t.textContent='Na sua gestão ✓';var cn=String(t.dataset.imp||'').slice(0,14);if(/^\d{14}$/.test(cn)&&!t.nextElementSibling?.classList?.contains('al-rx')){var a=document.createElement('a');a.className='g al-rx';a.href='/plataforma/raio-x-comprador/?cnpj='+cn;a.textContent='Raio-X do órgão →';a.style.cssText='font-size:.85rem;font-weight:600;color:#16191E;border:1px solid #E3DED2;border-radius:10px;padding:8px 12px;text-decoration:none';t.after(a)}}).catch(function(j){t.disabled=false;t.textContent=(j&&j.message)?'Tentar de novo':'Erro'})}});
(function(){var q=new URLSearchParams(location.search);if(q.get('termos')){F.querySelector('[name=termos]').value=q.get('termos');if(q.get('nome'))F.querySelector('[name=nome]').value=q.get('nome');(q.get('uf')||'').split(',').forEach(function(u){if(u)ufs[u.toUpperCase()]=1});M.className='al-m al-h o';M.textContent='Alerta pré-preenchido a partir do Radar de contratos vencendo. Confira e salve.';history.replaceState(null,'',location.pathname)}})();
pUfs();carregar();
})();
</script>
	<?php return ob_get_clean();
} );
