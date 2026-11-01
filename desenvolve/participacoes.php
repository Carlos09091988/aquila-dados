<?php
/**
 * Desenvolve — Licitações vencidas (PNCP / Compras.gov.br dados abertos) e base de participações.
 * - Licitações vencidas: resultados homologados da Lei 14.133 por CNPJ do fornecedor (3 anos).
 * - Participações: cada sessão ou ata lida na plataforma guarda quem disputou, com lance e posição.
 * Marcador: DV_PART_V1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'init', function () {
	if ( get_option( 'dv_part_ver' ) === '1' ) { return; }
	global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}dv_part (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		cnpj VARCHAR(14) NOT NULL,
		nome VARCHAR(190) NOT NULL DEFAULT '',
		ref VARCHAR(40) NOT NULL,
		item VARCHAR(190) NOT NULL DEFAULT '',
		ihash CHAR(8) NOT NULL DEFAULT '',
		objeto VARCHAR(255) NOT NULL DEFAULT '',
		orgao VARCHAR(190) NOT NULL DEFAULT '',
		estimado DECIMAL(18,4) NOT NULL DEFAULT 0,
		valor DECIMAL(18,4) NOT NULL DEFAULT 0,
		posicao SMALLINT NOT NULL DEFAULT 0,
		situacao VARCHAR(120) NOT NULL DEFAULT '',
		me TINYINT NOT NULL DEFAULT 0,
		fonte VARCHAR(10) NOT NULL DEFAULT '',
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		criado DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY u (cnpj, ref, ihash),
		KEY r (ref),
		KEY c (cnpj)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'dv_part_ver', '1', false );
} );

function dv_part_cnpj_ok( $c ) {
	if ( ! preg_match( '/^\d{14}$/', $c ) || preg_match( '/^(\d)\1{13}$/', $c ) ) { return false; }
	foreach ( array( 12, 13 ) as $n ) {
		$s = 0; $p = $n - 7;
		for ( $i = 0; $i < $n; $i++ ) { $s += $c[ $i ] * $p--; if ( $p < 2 ) { $p = 9; } }
		if ( (int) $c[ $n ] !== ( ( $s % 11 ) < 2 ? 0 : 11 - $s % 11 ) ) { return false; }
	}
	return true;
}

function dv_part_get( $urls, $timeout = 25 ) {
	if ( function_exists( 'dv_ds_multi' ) ) { return dv_ds_multi( $urls, array(), $timeout ); }
	$o = array(); foreach ( $urls as $k => $u ) { $g = wp_remote_get( $u, array( 'timeout' => $timeout ) ); $o[ $k ] = is_wp_error( $g ) ? null : wp_remote_retrieve_body( $g ); }
	return $o;
}

/** Licitações vencidas pelo CNPJ (resultados homologados no PNCP, Lei 14.133, últimos 3 anos). */
function dv_part_vencidas( $cnpj ) {
	$ck = 'dv_venc3_' . $cnpj; $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
	$B = 'https://dadosabertos.compras.gov.br/modulo-contratacoes/3_consultarResultadoItensContratacoes_PNCP_14133?tamanhoPagina=500&niFornecedor=' . $cnpj;
	$t = current_time( 'timestamp' ); $u = array();
	for ( $a = 0; $a < 3; $a++ ) {
		$fim = $t - $a * 365 * DAY_IN_SECONDS; $ini = $fim - 364 * DAY_IN_SECONDS;
		for ( $pg = 1; $pg <= 2; $pg++ ) { $u[ $a . '_' . $pg ] = $B . '&pagina=' . $pg . '&dataResultadoPncpInicial=' . gmdate( 'Y-m-d', $ini ) . '&dataResultadoPncpFinal=' . gmdate( 'Y-m-d', $fim ); }
	}
	$res = dv_part_get( $u, 40 ); $itens = array(); $vis = array(); $falhou = 0;
	foreach ( $res as $k => $body ) {
		if ( $k === '__falhas' ) { continue; }
		$j = $body ? json_decode( $body, true ) : null; if ( ! is_array( $j ) ) { if ( substr( $k, -2 ) === '_1' ) { $falhou++; } continue; }
		foreach ( (array) ( $j['resultado'] ?? array() ) as $x ) {
			if ( ! empty( $x['dataCancelamentoPncp'] ) ) { continue; }
			$id = (string) ( $x['idCompraItem'] ?? '' ) . '|' . ( $x['sequencialResultado'] ?? '' ); if ( isset( $vis[ $id ] ) ) { continue; } $vis[ $id ] = 1;
			$itens[] = $x;
		}
	}
	/* agrupa por licitação */
	$L = array();
	foreach ( $itens as $x ) {
		if ( ! preg_match( '#^(\d{14})-\d+-(\d+)/(\d{4})$#', (string) ( $x['idContratacaoPNCP'] ?? '' ), $m ) ) { continue; }
		$ref = $m[1] . '/' . $m[3] . '/' . (int) $m[2];
		if ( ! isset( $L[ $ref ] ) ) { $L[ $ref ] = array( 'ref' => $ref, 'orgao_cnpj' => $m[1], 'uf' => $x['unidadeOrgaoUfSigla'] ?? '', 'data' => substr( (string) ( $x['dataResultadoPncp'] ?? '' ), 0, 10 ), 'itens' => array(), 'valor' => 0 ); }
		$L[ $ref ]['itens'][] = array( 'n' => (int) ( $x['numeroItemPncp'] ?? 0 ), 'q' => (float) ( $x['quantidadeHomologada'] ?? 0 ), 'v' => (float) ( $x['valorTotalHomologado'] ?? 0 ), 'pd' => (float) ( $x['percentualDesconto'] ?? 0 ) );
		$L[ $ref ]['valor'] += (float) ( $x['valorTotalHomologado'] ?? 0 );
	}
	uasort( $L, function ( $a, $b ) { return strcmp( $b['data'], $a['data'] ); } );
	$L = array_slice( $L, 0, 40, true );
	/* dados da licitação e valor estimado dos itens (para o desconto) */
	$uc = array(); $ui = array();
	foreach ( $L as $ref => $l ) {
		list( $oc, $ano, $seq ) = explode( '/', $ref );
		$uc[ $ref ] = 'https://pncp.gov.br/api/consulta/v1/orgaos/' . $oc . '/compras/' . $ano . '/' . $seq;
		foreach ( array_slice( $l['itens'], 0, 5 ) as $i ) { if ( count( $ui ) < 80 ) { $ui[ $ref . '#' . $i['n'] ] = 'https://pncp.gov.br/api/pncp/v1/orgaos/' . $oc . '/compras/' . $ano . '/' . $seq . '/itens/' . $i['n']; } }
	}
	$rc = $uc ? dv_part_get( $uc, 25 ) : array(); $ri = $ui ? dv_part_get( $ui, 25 ) : array();
	$o = array(); $descs = array(); $tot = 0; $orgs = array(); $ufs = array();
	foreach ( $L as $ref => $l ) {
		$cj = ! empty( $rc[ $ref ] ) ? json_decode( $rc[ $ref ], true ) : array();
		$ds = array();
		foreach ( $l['itens'] as $i ) {
			if ( $i['pd'] > 0 && $i['pd'] < 100 ) { $ds[] = $i['pd']; continue; }
			$ij = ! empty( $ri[ $ref . '#' . $i['n'] ] ) ? json_decode( $ri[ $ref . '#' . $i['n'] ], true ) : null;
			if ( ! $ij || empty( $ij['valorUnitarioEstimado'] ) || ! empty( $ij['orcamentoSigiloso'] ) ) { continue; }
			$est = (float) $ij['valorUnitarioEstimado'] * ( $i['q'] ?: (float) ( $ij['quantidade'] ?? 0 ) );
			if ( $est > 0 && $i['v'] > 0 ) { $d = ( 1 - $i['v'] / $est ) * 100; if ( $d > -50 && $d < 95 ) { $ds[] = $d; } }
		}
		$desc = $ds ? array_sum( $ds ) / count( $ds ) : null; if ( $desc !== null ) { $descs[] = $desc; }
		$org = $cj['orgaoEntidade']['razaoSocial'] ?? ''; $mun = $cj['unidadeOrgao']['municipioNome'] ?? '';
		$o[] = array( 'ref' => $ref, 'orgao' => $org, 'municipio' => $mun, 'uf' => $l['uf'], 'objeto' => mb_substr( trim( (string) ( $cj['objetoCompra'] ?? '' ) ), 0, 220 ),
			'modalidade' => $cj['modalidadeNome'] ?? '', 'numero' => trim( ( $cj['numeroCompra'] ?? '' ) . ( ! empty( $cj['anoCompra'] ) ? '/' . $cj['anoCompra'] : '' ) ),
			'data' => $l['data'], 'itens' => count( $l['itens'] ), 'valor' => round( $l['valor'], 2 ), 'desconto' => $desc === null ? null : round( $desc, 1 ),
			'link' => 'https://pncp.gov.br/app/editais/' . str_replace( '/', '/', $ref ) );
		$tot += $l['valor']; if ( $org ) { $orgs[ $org ] = ( $orgs[ $org ] ?? 0 ) + 1; } if ( $l['uf'] ) { $ufs[ $l['uf'] ] = ( $ufs[ $l['uf'] ] ?? 0 ) + 1; }
	}
	arsort( $orgs ); arsort( $ufs );
	$r = array( 'total' => count( $o ), 'valor' => round( $tot, 2 ), 'desconto_medio' => $descs ? round( array_sum( $descs ) / count( $descs ), 1 ) : null,
		'orgaos' => array_slice( $orgs, 0, 5, true ), 'ufs' => array_slice( $ufs, 0, 6, true ), 'itens' => $o, 'incompleto' => $falhou > 0 );
	set_transient( $ck, $r, ( $falhou || ( $ui && ! $descs ) ) ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
	return $r;
}

/** Participações registradas na base da plataforma. */
function dv_part_de( $cnpj ) {
	global $wpdb; $t = $wpdb->prefix . 'dv_part';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ref, item, objeto, orgao, estimado, valor, posicao, situacao, fonte, criado FROM $t WHERE cnpj=%s ORDER BY criado DESC LIMIT 300", $cnpj ), ARRAY_A );
	if ( ! $rows ) { return array( 'total' => 0, 'itens' => array(), 'rivais' => array() ); }
	$refs = array_values( array_unique( array_column( $rows, 'ref' ) ) );
	$ph = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
	$riv = $wpdb->get_results( $wpdb->prepare( "SELECT cnpj, MAX(nome) nome, COUNT(DISTINCT ref) n FROM $t WHERE ref IN ($ph) AND cnpj<>%s GROUP BY cnpj ORDER BY n DESC LIMIT 8", array_merge( $refs, array( $cnpj ) ) ), ARRAY_A );
	$lic = array(); $venc = 0; $descs = array();
	foreach ( $rows as $r ) {
		$k = $r['ref']; if ( ! isset( $lic[ $k ] ) ) { $lic[ $k ] = array( 'ref' => $k, 'objeto' => $r['objeto'], 'orgao' => $r['orgao'], 'data' => substr( $r['criado'], 0, 10 ), 'itens' => array(), 'link' => 'https://pncp.gov.br/app/editais/' . $k ); }
		$d = ( (float) $r['estimado'] > 0 && (float) $r['valor'] > 0 ) ? round( ( 1 - $r['valor'] / $r['estimado'] ) * 100, 1 ) : null; if ( $d !== null && $d > -50 && $d < 95 ) { $descs[] = $d; }
		if ( (int) $r['posicao'] === 1 ) { $venc++; }
		$lic[ $k ]['itens'][] = array( 'item' => $r['item'], 'posicao' => (int) $r['posicao'], 'valor' => (float) $r['valor'], 'desconto' => $d, 'situacao' => $r['situacao'], 'fonte' => $r['fonte'] );
	}
	return array( 'total' => count( $lic ), 'primeiro_lugar' => $venc, 'desconto_medio' => $descs ? round( array_sum( $descs ) / count( $descs ), 1 ) : null, 'itens' => array_values( array_slice( $lic, 0, 30 ) ), 'rivais' => $riv );
}

add_action( 'rest_api_init', function () {
	$logado = function () { return is_user_logged_in(); };
	register_rest_route( 'desenvolve/v1', '/vencidas', array( 'methods' => 'GET', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		$c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); if ( strlen( $c ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		return array( 'vencidas' => dv_part_vencidas( $c ), 'participacoes' => dv_part_de( $c ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/participantes', array( 'methods' => 'POST', 'permission_callback' => $logado, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $t = $wpdb->prefix . 'dv_part';
		$ref = (string) $r['ref']; if ( ! preg_match( '#^\d{14}/\d{4}/\d{1,6}$#', $ref ) ) { return new WP_Error( 'dv_ref', 'Referência inválida.', array( 'status' => 400 ) ); }
		$fonte = in_array( $r['fonte'], array( 'sessao', 'ata' ), true ) ? $r['fonte'] : ''; if ( ! $fonte ) { return array( 'ok' => 0 ); }
		$item = mb_substr( sanitize_text_field( (string) $r['item'] ), 0, 190 ); $ih = substr( md5( $item ), 0, 8 );
		$obj = mb_substr( sanitize_text_field( (string) $r['objeto'] ), 0, 255 ); $org = mb_substr( sanitize_text_field( (string) $r['orgao'] ), 0, 190 );
		$est = max( 0, (float) $r['estimado'] ); $n = 0; $orgc = substr( $ref, 0, 14 );
		foreach ( array_slice( (array) $r['linhas'], 0, 150 ) as $x ) {
			$c = preg_replace( '/\D/', '', (string) ( $x['c'] ?? '' ) ); if ( $c === $orgc || ! dv_part_cnpj_ok( $c ) ) { continue; }
			$val = max( 0, (float) ( $x['v'] ?? 0 ) ); $pos = max( 0, min( 999, (int) ( $x['pos'] ?? 0 ) ) );
			$st = mb_substr( sanitize_text_field( (string) ( $x['st'] ?? '' ) ), 0, 120 ); $nm = mb_substr( sanitize_text_field( (string) ( $x['n'] ?? '' ) ), 0, 190 );
			/* a sessão (com lance e posição) prevalece sobre a ata */
			$ja = $wpdb->get_row( $wpdb->prepare( "SELECT id, fonte FROM $t WHERE cnpj=%s AND ref=%s AND ihash=%s", $c, $ref, $ih ) );
			if ( $ja && $ja->fonte === 'sessao' && $fonte === 'ata' ) { continue; }
			$d = array( 'cnpj' => $c, 'nome' => $nm, 'ref' => $ref, 'item' => $item, 'ihash' => $ih, 'objeto' => $obj, 'orgao' => $org, 'estimado' => $est, 'valor' => $val, 'posicao' => $pos, 'situacao' => $st, 'me' => empty( $x['me'] ) ? 0 : 1, 'fonte' => $fonte, 'user_id' => get_current_user_id(), 'criado' => current_time( 'mysql' ) );
			if ( $ja ) { $wpdb->update( $t, $d, array( 'id' => $ja->id ) ); } else { $wpdb->insert( $t, $d ); }
			$n++;
		}
		return array( 'ok' => $n );
	} ) );
} );

/* Quadro "Licitações vencidas e participações" no Raio-X do concorrente e na Due Diligence */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( array( 'raio-x', 'dossie' ) ) ) { return; }
	?>
<style id="dv-pv-css">
.dv-pv{background:#fff!important;border:1px solid #E3DED2!important;border-radius:16px!important;padding:20px 22px!important;margin:0 0 18px!important;color:#16191E!important;font-family:'Hanken Grotesk',system-ui,sans-serif!important}
.dv-pv *{font-family:inherit!important;box-sizing:border-box}
.dv-pv .k{font-size:.74rem!important;letter-spacing:.14em;text-transform:uppercase;color:#8A6A2E!important;font-weight:700;margin:0 0 6px!important}
.dv-pv h3{margin:0 0 12px!important;font-size:1.15rem!important;color:#16191E!important}
.dv-pv .n{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 14px}
.dv-pv .n div{background:#FAF8F3;border:1px solid #EEE9DD;border-radius:12px;padding:10px 12px}.dv-pv .n b{display:block;font-size:1.25rem!important;color:#16191E!important}.dv-pv .n span{font-size:.8rem;color:#6E6A60}
.dv-pv table{width:100%;border-collapse:collapse;font-size:.88rem!important}.dv-pv th{text-align:left;font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;color:#6E6A60;border-bottom:1px solid #E3DED2;padding:6px 8px}
.dv-pv td{border-bottom:1px solid #F0ECE2;padding:8px;vertical-align:top;color:#16191E!important}.dv-pv td small{display:block;color:#6E6A60;font-size:.78rem}
.dv-pv a{color:#8A6A2E!important;font-weight:700}
.dv-pv .tw{overflow-x:auto}.dv-pv p.m{font-size:.8rem!important;color:#6E6A60!important;margin:10px 0 0!important;line-height:1.45!important}
.dv-pv .rv{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 0}.dv-pv .rv a{border:1px solid #E3DED2;background:#FAF8F3;border-radius:9px;padding:5px 9px;font-weight:600;font-size:.82rem;text-decoration:none}
.dv-pv h4{margin:18px 0 8px!important;font-size:1rem!important;color:#16191E!important}
@media(max-width:700px){.dv-pv .n{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<script>
/* DV_PART_V1 */
(function(){
var dos=/dossie/.test(location.pathname);
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function mc(v){v=+v||0;return v>=1e6?'R$ '+(v/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1})+' mi':v>=1e3?'R$ '+Math.round(v/1e3).toLocaleString('pt-BR')+' mil':'R$ '+Math.round(v).toLocaleString('pt-BR')}
function br(d){var m=String(d||'').match(/(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:''}
function pc(v){return v==null?'—':(v<0?(-v).toFixed(1).replace('.',',')+'% acima':v.toFixed(1).replace('.',',')+'%')}
function fc(c){return String(c).replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
var feito='',ocup=false;
function root(){return dos?document.querySelector('.rp'):document.querySelector('.rx-out')}
function cnpj(){var c=(new URLSearchParams(location.search).get('cnpj')||'').replace(/\D/g,'');if(c.length===14)return c;var i=document.querySelector('.dv-tool input');c=i?String(i.value).replace(/\D/g,''):'';return c.length===14?c:''}
function html(J){var V=J.vencidas||{},P=J.participacoes||{},h='<p class="k">Licitações vencidas e participações</p><h3>Onde esta empresa ganha, e por quanto</h3>';
 h+='<div class="n"><div><b>'+(V.total||0)+'</b><span>licitações vencidas (3 anos)</span></div><div><b>'+mc(V.valor)+'</b><span>homologado a ela</span></div><div><b>'+pc(V.desconto_medio)+'</b><span>desconto médio sobre o estimado</span></div><div><b>'+(P.total||0)+'</b><span>disputas registradas na base</span></div></div>';
 var og=Object.keys(V.orgaos||{}),uf=Object.keys(V.ufs||{});
 if(og.length||uf.length)h+='<p style="margin:0 0 10px;font-size:.92rem">'+(og.length?'<b>Onde mais ganha:</b> '+og.slice(0,3).map(e).join(' · '):'')+(uf.length?(og.length?'<br>':'')+'<b>Estados:</b> '+uf.map(function(u){return u+' ('+V.ufs[u]+')'}).join(', '):'')+'</p>';
 if((V.itens||[]).length)h+='<div class="tw"><table><tr><th>Licitação</th><th>Data</th><th>Valor homologado</th><th>Desconto</th></tr>'+V.itens.slice(0,15).map(function(x){return'<tr><td>'+e(x.orgao||x.ref)+(x.municipio?' · '+e(x.municipio)+'/'+e(x.uf):'')+'<small>'+e(x.modalidade)+(x.numero?' '+e(x.numero):'')+' · '+e(String(x.objeto||'').slice(0,140))+' · <a href="'+e(x.link)+'" target="_blank" rel="noopener">PNCP</a></small></td><td>'+br(x.data)+'</td><td>'+mc(x.valor)+'<small>'+x.itens+' item(ns)</small></td><td>'+pc(x.desconto)+'</td></tr>'}).join('')+'</table></div>';
 else h+='<p style="margin:0">Nenhum resultado homologado em nome deste CNPJ nos últimos 3 anos no PNCP (Lei 14.133).</p>';
 if(P.total){h+='<h4>Disputas registradas na base da Desenvolve</h4><p style="margin:0 0 8px;font-size:.92rem">'+P.total+' licitação(ões) com a sessão ou a ata lida na plataforma'+(P.primeiro_lugar?' · '+P.primeiro_lugar+' vez(es) em 1º lugar':'')+(P.desconto_medio!=null?' · desconto médio dos lances: '+pc(P.desconto_medio):'')+'.</p>';
  h+='<div class="tw"><table><tr><th>Licitação</th><th>Posição</th><th>Lance</th><th>Desconto</th></tr>'+P.itens.slice(0,10).map(function(x){var i=x.itens[0]||{};return'<tr><td>'+e(x.orgao||x.ref)+'<small>'+e(String(x.objeto||i.item||'').slice(0,120))+' · <a href="'+e(x.link)+'" target="_blank" rel="noopener">PNCP</a></small></td><td>'+(i.posicao?i.posicao+'º':'participou')+(i.situacao?'<small>'+e(i.situacao)+'</small>':'')+'</td><td>'+(i.valor?mc(i.valor):'—')+'</td><td>'+pc(i.desconto)+'</td></tr>'}).join('')+'</table></div>';
  if((P.rivais||[]).length)h+='<p style="margin:12px 0 0;font-size:.92rem"><b>Costuma disputar com:</b></p><div class="rv">'+P.rivais.map(function(r){return'<a href="/plataforma/raio-x/?cnpj='+r.cnpj+'">'+e(r.nome||fc(r.cnpj))+' · '+r.n+'x</a>'}).join('')+'</div>'}
 h+='<p class="m">Licitações vencidas: resultados homologados publicados no PNCP (Lei 14.133, todas as esferas), via dados abertos do Compras.gov.br. Disputas registradas: sessões e atas lidas pelos usuários da plataforma; a base cresce a cada consulta. Contratações da Lei 8.666 e atas fora do PNCP não aparecem.</p>';
 return h}
function tenta(){var r=root();if(!r)return;var c=cnpj();if(!c)return;if(r.querySelector('#dv-pv')){feito=c;return}if(ocup||feito===c+'|x')return;
 if(dos?!r.querySelector('.rp-sec'):!r.querySelector('.rx-pts'))return;ocup=true;
 var api=(window.DVREL&&DVREL.api)||(window.DV&&DV.api)||'/wp-json/desenvolve/v1/',nonce=(window.DVREL&&DVREL.nonce)||(window.DV&&DV.nonce)||'';
 var b=document.createElement('section');b.id='dv-pv';b.className='dv-pv';b.innerHTML='<p class="k">Licitações vencidas e participações</p><p style="margin:0">Buscando no PNCP as licitações vencidas por esta empresa…</p>';
 var ref=null;if(dos){var ss=r.querySelectorAll('.rp-sec');ref=ss[Math.min(2,ss.length-1)]}else{ref=[].filter.call(r.querySelectorAll('section.rx-card'),function(s){return/^CONTRATOS P[ÚU]BLICOS/i.test(s.innerText)})[0]}
 if(ref&&ref.nextSibling)ref.parentNode.insertBefore(b,ref.nextSibling);else r.appendChild(b);
 fetch(api+'vencidas?cnpj='+c,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(x){return x.json()}).then(function(J){ocup=false;feito=c;if(!J||!J.vencidas){b.innerHTML='<p class="k">Licitações vencidas</p><p style="margin:0">Não foi possível consultar o PNCP agora.</p>';return}b.innerHTML=html(J)}).catch(function(){ocup=false;feito=c+'|x';b.innerHTML='<p class="k">Licitações vencidas</p><p style="margin:0">Não foi possível consultar o PNCP agora.</p>'})}
var tm=null;new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(tenta,1200)}).observe(document.body,{childList:true,subtree:true});
})();
</script>
	<?php
}, 97 );
