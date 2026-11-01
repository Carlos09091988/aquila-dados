<?php
/**
 * Desenvolve — Caixa de oportunidades que aprende com cada cliente.
 * Cada "Quero" e "Descartar" (e o resultado na Gestão: ganha ou perdida) ensina a caixa.
 * Nota de afinidade 0-100 por edital, motivo da nota, ordenação da caixa e do e-mail, sugestões de exclusão.
 * O aprendizado é individual: as decisões de um cliente nunca influenciam outro.
 * Marcador: DV_AP_V1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_ap_norm( $t ) { return trim( preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( remove_accents( (string) $t ) ) ) ); }
function dv_ap_stop() {
	static $s = null; if ( $s ) { return $s; }
	$s = array_flip( explode( ' ', 'para pela pelo pelos pelas com dos das nos nas uma umas uns que por sob sobre entre ate apos como mais seus suas este esta estes estas esse essa desse dessa deste desta forma conforme demais termo referencia edital anexo anexos objeto contratacao empresa empresas especializada especializado prestacao servico servicos registro precos preco eventual eventuais futura futuras futuro aquisicoes atender atendimento necessidades secretaria municipal municipio estadual federal publica publico unidade unidades meses periodo prazo incluindo inclusive diversos diversas acordo itens item lote lotes quantidade quantidades estimada estimado valor execucao regime tipo menor global sistema srp pregao eletronico licitacao processo numero sendo serao sera durante destinado destinados destinadas destinada visando atraves mediante pelo' ) );
	return $s;
}
/** Características de um edital: palavras, pares de palavras, órgão, estado, modalidade e faixa de valor. */
function dv_ap_feat( $x ) {
	$w = array_values( array_filter( explode( ' ', dv_ap_norm( $x['objeto'] ?? '' ) ), function ( $p ) { return strlen( $p ) > 3 && ! isset( dv_ap_stop()[ $p ] ) && ! ctype_digit( $p ); } ) );
	$w = array_slice( $w, 0, 30 ); $f = array();
	foreach ( $w as $p ) { $f[ 'w:' . $p ] = 1; }
	for ( $i = 0; $i < count( $w ) - 1 && $i < 20; $i++ ) { $f[ 'b:' . $w[ $i ] . ' ' . $w[ $i + 1 ] ] = 1; }
	if ( ! empty( $x['orgao'] ) ) { $f[ 'o:' . dv_ap_norm( $x['orgao'] ) ] = 1; }
	if ( ! empty( $x['uf'] ) ) { $f[ 'u:' . strtoupper( $x['uf'] ) ] = 1; }
	if ( ! empty( $x['modalidade'] ) ) { $f[ 'm:' . dv_ap_norm( $x['modalidade'] ) ] = 1; }
	$v = (float) ( $x['valor'] ?? 0 ); if ( $v > 0 ) { $f[ 'v:' . ( $v < 1e5 ? 'ate100mil' : ( $v < 1e6 ? '100mila1mi' : ( $v < 1e7 ? '1a10mi' : 'acima10mi' ) ) ) ] = 1; }
	return array_keys( $f );
}
function dv_ap_hist( $uid ) { $h = json_decode( (string) get_user_meta( $uid, 'dv_al_hist', true ), true ); return is_array( $h ) ? $h : array(); }
function dv_ap_registrar( $uid, $ref, $st ) {
	if ( ! function_exists( 'dv_al_caixa' ) ) { return; }
	$h = dv_ap_hist( $uid ); $cx = dv_al_caixa( $uid );
	if ( $st === 'quero' || $st === 'desc' ) {
		$x = $cx[ $ref ] ?? null; if ( ! $x ) { return; }
		$h[ $ref ] = array( 'f' => dv_ap_feat( $x ), 's' => $st === 'quero' ? 'q' : 'd', 't' => current_time( 'Y-m-d' ), 'o' => mb_substr( (string) ( $x['objeto'] ?? '' ), 0, 160 ) );
	} else { unset( $h[ $ref ] ); }
	if ( count( $h ) > 800 ) { uasort( $h, function ( $a, $b ) { return strcmp( $b['t'], $a['t'] ); } ); $h = array_slice( $h, 0, 800, true ); }
	update_user_meta( $uid, 'dv_al_hist', wp_slash( wp_json_encode( $h, JSON_UNESCAPED_UNICODE ) ) );
}
/** Modelo do cliente (contagens por característica). Licitação ganha na Gestão pesa o dobro. */
function dv_ap_modelo( $uid ) {
	static $cache = array(); if ( isset( $cache[ $uid ] ) ) { return $cache[ $uid ]; }
	$M = array( 'Q' => 0, 'D' => 0, 'f' => array(), 'ganhas' => 0 );
	$add = function ( $feat, $lado, $peso ) use ( &$M ) { $M[ $lado ] += $peso; foreach ( $feat as $k ) { if ( ! isset( $M['f'][ $k ] ) ) { $M['f'][ $k ] = array( 0, 0 ); } $M['f'][ $k ][ $lado === 'Q' ? 0 : 1 ] += $peso; } };
	$vistos = array();
	foreach ( dv_ap_hist( $uid ) as $ref => $x ) { $add( (array) $x['f'], $x['s'] === 'q' ? 'Q' : 'D', 1 ); $vistos[ $ref ] = 1; }
	if ( function_exists( 'dv_lic_itens' ) ) {
		foreach ( dv_lic_itens( $uid ) as $l ) {
			$st = $l['status'] ?? ''; if ( ! in_array( $st, array( 'ganha', 'perdida' ), true ) ) { continue; }
			$feat = dv_ap_feat( array( 'objeto' => $l['objeto'] ?? '', 'orgao' => $l['orgao'] ?? '', 'uf' => $l['uf'] ?? '', 'modalidade' => $l['modalidade'] ?? '', 'valor' => $l['valor'] ?? 0 ) );
			$add( $feat, 'Q', $st === 'ganha' ? 2 : 0.5 ); if ( $st === 'ganha' ) { $M['ganhas']++; }
		}
	}
	return $cache[ $uid ] = $M;
}
function dv_ap_rotulo( $k ) {
	$t = substr( $k, 2 );
	switch ( $k[0] ) { case 'o': return 'órgão ' . mb_strtoupper( $t ); case 'u': return 'estado ' . $t; case 'm': return $t; case 'v': return array( 'ate100mil' => 'valor até R$ 100 mil', '100mila1mi' => 'valor de R$ 100 mil a 1 mi', '1a10mi' => 'valor de R$ 1 a 10 mi', 'acima10mi' => 'valor acima de R$ 10 mi' )[ $t ] ?? $t; default: return '“' . $t . '”'; }
}
/** Nota de afinidade 0-100 (Bayes ingênuo, com suavização) e os motivos. Nulo enquanto houver poucas decisões. */
function dv_ap_nota( $M, $x ) {
	if ( $M['Q'] + $M['D'] < 5 || ! $M['Q'] || ! $M['D'] ) { return null; }
	$Q = $M['Q']; $D = $M['D']; $s = log( ( $Q + 1 ) / ( $D + 1 ) ) * 0.5; $c = array(); $peso = array( 'w' => 0.35, 'b' => 0.6, 'o' => 1.0, 'u' => 0.4, 'm' => 0.5, 'v' => 0.35 );
	foreach ( dv_ap_feat( $x ) as $k ) {
		$n = $M['f'][ $k ] ?? null; if ( ! $n || $n[0] + $n[1] < 2 ) { continue; }
		$l = log( ( $n[0] + 0.5 ) / ( $Q + 1 ) ) - log( ( $n[1] + 0.5 ) / ( $D + 1 ) ); $l = max( -3, min( 3, $l ) ) * ( $peso[ $k[0] ] ?? 0.4 );
		$s += $l; $c[ $k ] = array( $l, $n );
	}
	$nota = (int) round( 100 / ( 1 + exp( -$s ) ) );
	uasort( $c, function ( $a, $b ) { return $b[0] <=> $a[0]; } ); $mais = array(); $menos = array();
	foreach ( $c as $k => $v ) { if ( $v[0] > 0.25 && count( $mais ) < 2 ) { $mais[] = dv_ap_rotulo( $k ) . ' (' . round( $v[1][0] ) . ' aproveitado' . ( $v[1][0] >= 2 ? 's' : '' ) . ')'; } }
	foreach ( array_reverse( $c, true ) as $k => $v ) { if ( $v[0] < -0.25 && count( $menos ) < 2 ) { $menos[] = dv_ap_rotulo( $k ) . ' (' . round( $v[1][1] ) . ' descartado' . ( $v[1][1] >= 2 ? 's' : '' ) . ')'; } }
	return array( 'nota' => $nota, 'mais' => $mais, 'menos' => $menos );
}
/** Sugestões: palavras sempre descartadas que podem virar exclusão nos alertas. */
function dv_ap_sugestoes( $uid ) {
	$M = dv_ap_modelo( $uid ); if ( $M['D'] < 6 ) { return array(); }
	$ign = (array) json_decode( (string) get_user_meta( $uid, 'dv_ap_ign', true ), true );
	$excl = ''; $termos = '';
	if ( function_exists( 'dv_al_lista' ) ) { foreach ( dv_al_lista( $uid ) as $a ) { $excl .= ' ' . dv_ap_norm( $a['excluir'] ?? '' ); $termos .= ' ' . dv_ap_norm( $a['termos'] ?? '' ); } }
	$o = array();
	foreach ( $M['f'] as $k => $n ) {
		if ( ! in_array( $k[0], array( 'w', 'b' ), true ) || $n[0] > 0 || $n[1] < 4 ) { continue; }
		$t = substr( $k, 2 ); if ( in_array( $t, $ign, true ) || strpos( $excl, $t ) !== false || strpos( $termos, $t ) !== false ) { continue; }
		$o[] = array( 'termo' => $t, 'desc' => (int) $n[1], 'par' => $k[0] === 'b' );
	}
	usort( $o, function ( $a, $b ) { return ( $b['desc'] + ( $b['par'] ? 1 : 0 ) ) <=> ( $a['desc'] + ( $a['par'] ? 1 : 0 ) ); } );
	/* evita sugerir a palavra solta quando o par que a contém já foi sugerido */
	$f = array(); foreach ( $o as $s ) { $dup = false; foreach ( $f as $g ) { if ( strpos( $g['termo'], $s['termo'] ) !== false || strpos( $s['termo'], $g['termo'] ) !== false ) { $dup = true; break; } } if ( ! $dup ) { $f[] = $s; } if ( count( $f ) >= 3 ) { break; } }
	return $f;
}
/** Ordena os blocos do e-mail pela nota de afinidade. */
function dv_ap_ordenar( $uid, $bloco ) {
	$M = dv_ap_modelo( $uid );
	foreach ( $bloco as &$b ) {
		foreach ( $b['itens'] as &$x ) { $n = dv_ap_nota( $M, $x ); $x['_ap'] = $n ? $n['nota'] : 50; } unset( $x );
		usort( $b['itens'], function ( $a, $c ) { return $c['_ap'] <=> $a['_ap']; } );
	}
	unset( $b );
	return $bloco;
}

/* Registra cada decisão na caixa (sem alterar a rota original) */
add_filter( 'rest_request_after_callbacks', function ( $resp, $handler, $req ) {
	if ( $req->get_method() === 'POST' && $req->get_route() === '/desenvolve/v1/alertas/caixa' && ! is_wp_error( $resp ) ) {
		dv_ap_registrar( get_current_user_id(), preg_replace( '/[^0-9\/]/', '', (string) $req['ref'] ), (string) $req['st'] );
	}
	if ( $req->get_method() === 'GET' && $req->get_route() === '/desenvolve/v1/alertas' && is_array( $resp ) && isset( $resp['caixa'] ) ) {
		$uid = get_current_user_id(); $M = dv_ap_modelo( $uid );
		foreach ( $resp['caixa'] as &$x ) { $n = dv_ap_nota( $M, $x ); if ( $n ) { $x['ap'] = $n; } } unset( $x );
		$resp['ap'] = array( 'quero' => (int) round( $M['Q'] ), 'desc' => (int) $M['D'], 'ganhas' => $M['ganhas'], 'ativo' => ( $M['Q'] + $M['D'] >= 5 && $M['Q'] && $M['D'] ), 'sugestoes' => dv_ap_sugestoes( $uid ) );
	}
	return $resp;
}, 10, 3 );

add_action( 'rest_api_init', function () {
	$perm = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/alertas/aprende', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $t = dv_ap_norm( (string) $r['termo'] ); if ( strlen( $t ) < 3 ) { return new WP_Error( 'dv_ap', 'Termo inválido.', array( 'status' => 400 ) ); }
		if ( $r['acao'] === 'ignorar' ) { $ign = (array) json_decode( (string) get_user_meta( $uid, 'dv_ap_ign', true ), true ); $ign[] = $t; update_user_meta( $uid, 'dv_ap_ign', wp_json_encode( array_values( array_unique( $ign ) ) ) ); return array( 'ok' => true ); }
		if ( ! function_exists( 'dv_al_lista' ) || ! function_exists( 'dv_al_salvar_lista' ) ) { return new WP_Error( 'dv_ap', 'Alertas indisponíveis.', array( 'status' => 500 ) ); }
		$l = dv_al_lista( $uid ); $n = 0;
		foreach ( $l as &$a ) { if ( empty( $a['ativo'] ) ) { continue; } $ex = trim( (string) ( $a['excluir'] ?? '' ) ); if ( stripos( dv_ap_norm( $ex ), $t ) !== false ) { continue; } $a['excluir'] = mb_substr( $ex ? $ex . ', ' . $t : $t, 0, 300 ); $n++; } unset( $a );
		dv_al_salvar_lista( $uid, $l ); return array( 'ok' => true, 'alertas' => $n );
	} ) );
} );

/* Interface na caixa de oportunidades */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( 'alertas' ) ) { return; }
	?>
<style id="dv-ap-css">
.al-ap{display:inline-block;border-radius:6px;padding:2px 8px;font-size:.76rem;font-weight:700;margin-right:6px;cursor:help}
.al-ap.a{background:#EAF5EE;color:#1F5A3B}.al-ap.m{background:#F3F0E8;color:#4A4F55}.al-ap.b{background:#FDEDEA;color:#9A3524}
.al-apw{font-size:.8rem;color:#6E6A60;margin:4px 0 0}
.al-aps{background:#FBF6EA;border:1px solid #E8D7AE;border-radius:12px;padding:10px 14px;margin:8px 0 12px;font-size:.9rem}
.al-aps b{color:#16191E}.al-aps button{border:1px solid #C9A45C;background:#fff;border-radius:8px;padding:4px 10px;margin:4px 6px 0 0;font:inherit;font-size:.82rem;font-weight:700;cursor:pointer}
.al-aps button.g{border-color:#D9D3C4;color:#6E6A60;font-weight:600}
</style>
<script>
/* DV_AP_V1 — decora a caixa depois de cada renderização */
(function(){
var R=document.querySelector('.al-cx');if(!R)return;var AP=null,MAP={};
var nonce=(window.wpApiSettings&&wpApiSettings.nonce)||((document.querySelector('[data-nonce]')||{}).dataset||{}).nonce||(window.DV&&DV.nonce)||'';
var api=(window.DV&&DV.api)||'/wp-json/desenvolve/v1/';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function carrega(){return fetch(api+'alertas',{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.json()}).then(function(j){AP=j.ap||null;MAP={};(j.caixa||[]).forEach(function(x){if(x.ap)MAP[x.ref]=x.ap});decora()}).catch(function(){})}
var ocupado=false;
function decora(){if(ocupado)return;ocupado=true;try{
 var L=R.querySelector('.al-cxl');if(!L)return;var tab=(R.querySelector('.al-tabs .on')||{}).dataset||{},novas=(tab.cx||'')==='';
 var it=[].slice.call(L.querySelectorAll('.al-i'));
 it.forEach(function(c){if(c.querySelector('.al-ap'))return;var a=MAP[c.dataset.r];if(!a)return;var cl=a.nota>=65?'a':a.nota<=35?'b':'m',lb=a.nota>=65?'⭐ Parecido com o que você aproveita':a.nota<=35?'Parecido com o que você descarta':'Afinidade média';
  var tt='Nota de afinidade '+a.nota+'/100.'+(a.mais.length?' A favor: '+a.mais.join(', ')+'.':'')+(a.menos.length?' Contra: '+a.menos.join(', ')+'.':'');var m=c.querySelector('.m');if(!m)return;
  var s=document.createElement('div');s.innerHTML='<span class="al-ap '+cl+'" title="'+e(tt)+'">'+lb+' · '+a.nota+'</span>'+(a.mais.length||a.menos.length?'<span class="al-apw">'+e((a.mais.length?'a favor: '+a.mais.join(', '):'')+(a.mais.length&&a.menos.length?' · ':'')+(a.menos.length?'contra: '+a.menos.join(', '):''))+'</span>':'');m.parentNode.insertBefore(s,m)});
 if(novas&&Object.keys(MAP).length){var ord=it.slice().sort(function(x,y){var a=(MAP[x.dataset.r]||{nota:50}).nota,b=(MAP[y.dataset.r]||{nota:50}).nota;return b-a});var mud=ord.some(function(c,i){return c!==it[i]});if(mud)ord.forEach(function(c){L.appendChild(c)})}
 var bx=R.querySelector('.al-aps');if(!bx){bx=document.createElement('div');bx.className='al-aps';L.parentNode.insertBefore(bx,L)}
 if(!AP){bx.style.display='none'}else{bx.style.display='';var S=AP.sugestoes||[];
  var h=AP.ativo?'<b>A caixa está aprendendo com você</b> ('+AP.quero+' aproveitados, '+AP.desc+' descartados'+(AP.ganhas?', '+AP.ganhas+' ganha(s) na Gestão':'')+'). As novas estão ordenadas pelo que você costuma aproveitar.':'<b>A caixa aprende com você.</b> Marque "Quero" ou "Descartar" em pelo menos 5 editais (dos dois tipos) para ela começar a ordenar pelo seu perfil.';
  if(S.length)h+='<br>'+S.map(function(s){return'Você descartou '+s.desc+' editais com <b>“'+e(s.termo)+'”</b> e não aproveitou nenhum. <button type="button" data-apx="'+e(s.termo)+'">Excluir dos meus alertas</button><button type="button" class="g" data-api="'+e(s.termo)+'">Não sugerir</button>'}).join('<br>');
  if(bx.innerHTML!==h)bx.innerHTML=h}
}finally{ocupado=false}}
R.addEventListener('click',function(ev){var t=ev.target,x=t.dataset&&(t.dataset.apx||t.dataset.api);if(!x)return;t.disabled=true;fetch(api+'alertas/aprende',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:JSON.stringify({termo:x,acao:t.dataset.apx?'excluir':'ignorar'})}).then(function(r){return r.json()}).then(function(j){if(t.dataset.apx)t.parentNode.insertAdjacentHTML('beforeend','<br>✓ “'+e(x)+'” incluído nas exclusões de '+(j.alertas||0)+' alerta(s).');carrega()})});
/* decisões novas: recarrega a nota depois de cada Quero/Descartar */
R.addEventListener('click',function(ev){var t=ev.target;if(t.dataset&&(t.dataset.st!=null||t.hasAttribute('data-q')))setTimeout(carrega,1200)});
new MutationObserver(function(){setTimeout(decora,0)}).observe(R,{childList:true,subtree:true});
carrega();
})();
</script>
	<?php
}, 99 );
