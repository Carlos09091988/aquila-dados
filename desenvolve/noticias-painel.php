/**
 * Desenvolve — Painel de notícias de licitações (/plataforma/noticias/)
 * Fonte: Google Notícias (RSS público de busca). Mostra só título, fonte, data e link para a matéria original.
 * Abas: Geral · Do seu ramo (termos dos alertas) · Seus órgãos (órgãos da Gestão) · Decisões (TCU e Tribunais de Contas).
 * Rota: GET desenvolve/v1/noticias?aba=  (assinantes). Cache: geral/decisões 2h; ramo/órgãos 6h por conjunto de termos.
 * Depende de: notícias do Raio-X (dv_nt_rss, dv_nt_norm).
 */

function dv_np_gn( $q ) { return 'https://news.google.com/rss/search?q=' . rawurlencode( $q ) . '&hl=pt-BR&gl=BR&ceid=BR:pt-419'; }

function dv_np_tag( $t ) {
	$n = function_exists( 'dv_nt_norm' ) ? dv_nt_norm( $t ) : strtoupper( remove_accents( $t ) );
	$R = array(
		'Tribunal de Contas' => '/\b(TCU|TRIBUNAL DE CONTAS|TCE|TCM|ACORDAO)\b/',
		'Investigação'       => '/\b(OPERACAO|POLICIA|FRAUDE|INVESTIGA|MPF|MINISTERIO PUBLICO|PRESO|PRISAO|CARTEL|PROPINA|DESVIO|IMPROBIDADE|GAECO)/',
		'Legislação'         => '/\b(LEI|DECRETO|PROJETO DE LEI|14 133|INSTRUCAO NORMATIVA|PORTARIA|REGULAMENT|SANCIONA|CONGRESSO|SENADO|CAMARA DOS DEPUTADOS)\b/',
		'Licitação aberta'   => '/\b(ABRE|LANCA|LANCAMENTO|PUBLICA EDITAL|EDITAL|INSCRICOES|PREGAO|CHAMAMENTO|CONCORRENCIA)\b/',
		'Contratos'          => '/\b(CONTRAT|ADITIVO|RESCIS|REAJUSTE|REEQUILIBRIO|PAGAMENTO|ATRASO)/',
	);
	foreach ( $R as $k => $re ) { if ( preg_match( $re, $n ) ) { return $k; } }
	return 'Mercado';
}

function dv_np_buscar( $qs, $filtrar = true, $lim = 40 ) {
	$bodies = array();
	if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
		$reqs = array(); foreach ( $qs as $k => $q ) { $reqs[ $k ] = array( 'url' => dv_np_gn( $q ), 'type' => 'GET', 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Desenvolve/1.0)' ), 'options' => array( 'timeout' => 15 ) ); }
		try { $rs = \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 15 ) ); } catch ( \Throwable $e ) { $rs = array(); }
		foreach ( $rs as $k => $r ) { if ( is_object( $r ) && isset( $r->status_code ) && $r->status_code === 200 ) { $bodies[ $k ] = $r->body; } }
	} else {
		foreach ( $qs as $k => $q ) { $r = wp_remote_get( dv_np_gn( $q ), array( 'timeout' => 15 ) ); if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) { $bodies[ $k ] = wp_remote_retrieve_body( $r ); } }
	}
	if ( ! $bodies || ! function_exists( 'dv_nt_rss' ) ) { return null; }
	$out = array(); $vis = array();
	$rel = '/\b(LICITA|PREGAO|EDITAL|CONTRAT|TCU|TRIBUNAL DE CONTAS|COMPRAS? PUBLICAS?|14 133|DISPENSA|CONCORRENCIA|ATA DE REGISTRO|LICITANTE|FORNECEDOR|CONCESS|PPP|PARCERIA PUBLICO)/';
	foreach ( $bodies as $k => $b ) {
		foreach ( dv_nt_rss( $b ) as $it ) {
			$n = dv_nt_norm( $it['titulo'] ); $h = md5( substr( $n, 0, 70 ) );
			if ( isset( $vis[ $h ] ) || mb_strlen( $it['titulo'] ) < 25 ) { continue; }
			if ( $filtrar && ! preg_match( $rel, $n ) ) { continue; }
			$vis[ $h ] = 1;
			$out[] = array( 't' => $it['titulo'], 'f' => $it['fonte'], 'u' => $it['url'], 'd' => $it['data'], 'g' => dv_np_tag( $it['titulo'] ), 'q' => is_string( $k ) && strpos( $k, ':' ) ? substr( $k, strpos( $k, ':' ) + 1 ) : '' );
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['d'], $a['d'] ); } );
	return array_slice( $out, 0, $lim );
}

function dv_np_orgao( $o ) {
	$o = trim( preg_replace( '/\s+/', ' ', (string) $o ) ); if ( $o === '' ) { return ''; }
	$u = strtoupper( remove_accents( $o ) );
	if ( preg_match( '/^(MUNICIPIO|PREFEITURA MUNICIPAL|PREFEITURA) DE (.+)$/', $u, $m ) ) { $o = 'Prefeitura de ' . mb_convert_case( mb_strtolower( preg_replace( '/^(MUNICIPIO|PREFEITURA MUNICIPAL|PREFEITURA|MUNICÍPIO) DE /iu', '', $o ) ), MB_CASE_TITLE ); }
	else { $o = mb_convert_case( mb_strtolower( $o ), MB_CASE_TITLE ); }
	return str_replace( array( ' De ', ' Da ', ' Do ', ' Das ', ' Dos ', ' E ' ), array( ' de ', ' da ', ' do ', ' das ', ' dos ', ' e ' ), $o );
}

function dv_np_aba( $uid, $aba ) {
	if ( $aba === 'geral' || $aba === 'decisoes' ) {
		$ck = 'dv_np_' . $aba; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
		$qs = $aba === 'geral'
			? array( 'licitação when:3d', 'pregão eletrônico when:3d', '"compras públicas" when:7d', '"Lei 14.133" when:14d', 'licitação prefeitura edital when:3d' )
			: array( 'TCU licitação when:14d', '"Tribunal de Contas" licitação when:7d', 'TCU acórdão contratação when:30d', '"Tribunal de Contas" suspende licitação when:30d' );
		$l = dv_np_buscar( $qs ); if ( $l === null ) { return array( 'itens' => array(), 'aviso' => 'As notícias não carregaram agora. Tente de novo em alguns minutos.' ); }
		$r = array( 'itens' => $l, 'em' => current_time( 'mysql' ) ); set_transient( $ck, $r, 2 * HOUR_IN_SECONDS ); return $r;
	}
	if ( $aba === 'ramo' ) {
		$t = array(); if ( function_exists( 'dv_al_lista' ) ) { foreach ( dv_al_lista( $uid ) as $a ) { foreach ( preg_split( '/[,;\n]+/', (string) ( $a['termos'] ?? '' ) ) as $x ) { $x = trim( $x ); if ( mb_strlen( $x ) >= 3 ) { $t[ mb_strtolower( $x ) ] = $x; } } } }
		$t = array_slice( array_values( $t ), 0, 6 );
		if ( ! $t ) { return array( 'itens' => array(), 'vazio' => 'ramo' ); }
		$qs = array(); foreach ( $t as $x ) { $qs[ 'r:' . $x ] = '"' . $x . '" (licitação OR pregão OR contrato) when:30d'; }
	} elseif ( $aba === 'orgaos' ) {
		$t = array(); if ( function_exists( 'dv_lic_itens' ) ) { foreach ( array_reverse( dv_lic_itens( $uid ) ) as $x ) { $o = dv_np_orgao( $x['orgao'] ?? '' ); if ( $o && mb_strlen( $o ) >= 8 ) { $t[ $o ] = $o; } } }
		$t = array_slice( array_values( $t ), 0, 6 );
		if ( ! $t ) { return array( 'itens' => array(), 'vazio' => 'orgaos' ); }
		$qs = array(); foreach ( $t as $x ) { $qs[ 'o:' . $x ] = '"' . $x . '" (licitação OR contrato OR pregão OR edital) when:60d'; }
	} else { return new WP_Error( 'dv_aba', 'Aba inválida.', array( 'status' => 400 ) ); }
	$ck = 'dv_np_u' . md5( $aba . wp_json_encode( $t ) ); $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	$l = dv_np_buscar( $qs, false, 50 ); if ( $l === null ) { return array( 'itens' => array(), 'aviso' => 'As notícias não carregaram agora. Tente de novo em alguns minutos.', 'termos' => $t ); }
	$r = array( 'itens' => $l, 'termos' => $t, 'em' => current_time( 'mysql' ) ); set_transient( $ck, $r, 6 * HOUR_IN_SECONDS ); return $r;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/noticias', array( 'methods' => 'GET', 'permission_callback' => function () { return is_user_logged_in() && ( ! function_exists( 'dv_tools_perm' ) || dv_tools_perm() ); },
		'callback' => function ( WP_REST_Request $r ) { return dv_np_aba( get_current_user_id(), sanitize_key( (string) ( $r['aba'] ?: 'geral' ) ) ); } ) );
} );

add_action( 'init', function () {
	if ( get_option( 'dv_np_pagina' ) === '1' ) { return; }
	if ( ! get_page_by_path( 'plataforma/noticias' ) ) {
		$pai = get_page_by_path( 'plataforma' );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'noticias', 'post_parent' => $pai ? $pai->ID : 0, 'post_title' => 'Notícias de licitações',
			'post_content' => '[dv_ctx tool="noticias"]<section class="dv-th"><small>Ferramenta Desenvolve</small><h1>Notícias de licitações</h1><p>O que saiu na imprensa sobre licitações, contratos públicos e Tribunais de Contas, com as notícias do seu ramo e dos órgãos que você acompanha.</p></section>[dv_noticias]' ) );
		if ( $id && ! is_wp_error( $id ) ) { update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); }
	}
	update_option( 'dv_np_pagina', '1', false );
}, 30 );

add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() && is_page( 'noticias' ) ) { wp_safe_redirect( home_url( '/' ), 302 ); exit; }
} );

add_shortcode( 'dv_noticias', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="dv-tool"><div class="np" id="dv-np" data-api="<?php echo esc_url( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
<div class="np-bar"><div class="np-tabs" role="tablist"><button type="button" data-a="geral" class="on">Em destaque</button><button type="button" data-a="ramo">Do seu ramo</button><button type="button" data-a="orgaos">Seus órgãos</button><button type="button" data-a="decisoes">Tribunais de Contas</button></div><input type="search" class="np-q" placeholder="Filtrar por palavra…" aria-label="Filtrar notícias"></div>
<div class="np-tags"></div><div class="np-list"></div>
<p class="np-ft">Notícias públicas reunidas automaticamente a partir do Google Notícias. Cada notícia abre no site do veículo que a publicou.</p>
</div></div>
<style>
.np{background:#fff;border:1px solid #E6E1D6;border-radius:18px;padding:18px 20px;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif}
.np-bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}
.np-tabs{display:flex;gap:6px;flex-wrap:wrap}.np-tabs button{background:#F4F2EC!important;color:#16191E!important;border:1px solid transparent!important;border-radius:999px;padding:8px 14px;font:700 .86rem inherit;cursor:pointer}.np-tabs button.on{background:#16191E!important;color:#F4F2EC!important}
.np-q{flex:0 1 240px;border:1px solid #D9D3C4!important;border-radius:10px;padding:9px 12px;font:inherit;font-size:.9rem;background:#fff!important;color:#16191E!important}
.np-tags{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 4px}.np-tags button{background:#fff!important;border:1px solid #E6E1D6!important;color:#5E5A52!important;border-radius:999px;padding:5px 11px;font:600 .78rem inherit;cursor:pointer}.np-tags button.on{border-color:#C9A45C!important;color:#16191E!important;background:#FBF6EA!important}
.np-list{display:flex;flex-direction:column}
.np-it{display:flex;gap:14px;align-items:flex-start;padding:14px 4px;border-bottom:1px solid #EFEBE2}.np-it:last-child{border-bottom:0}
.np-it .tx{flex:1;min-width:0}.np-it a.t{display:block;color:#16191E!important;font-weight:700;font-size:1rem;line-height:1.35;text-decoration:none!important}.np-it a.t:hover{color:#86621F!important}
.np-it .m{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px;font-size:.8rem;color:#5E5A52}
.np-g{font-weight:700;font-size:.72rem;padding:2px 8px;border-radius:999px;background:#F1EBDD;color:#6B4F17}.np-g.g1{background:#E7EEF8;color:#2A4A7A}.np-g.g2{background:#FBE9E5;color:#8E2F1F}.np-g.g3{background:#EAF3EC;color:#245C3B}
.np-it .ia{flex:none;background:#fff!important;border:1px solid #E6E1D6!important;color:#16191E!important;border-radius:9px;padding:7px 10px;font:700 .76rem inherit;cursor:pointer;white-space:nowrap}.np-it .ia:hover{border-color:#C9A45C!important}
.np-vz{text-align:center;padding:36px 16px;border:1.5px dashed #D9D2C2;border-radius:14px;color:#5E5A52;background:#FBFAF6;margin-top:12px}.np-vz a{color:#86621F!important;font-weight:700}
.np-sk{height:58px;border-radius:12px;margin-top:10px;background:linear-gradient(90deg,#EEEAE1 25%,#F7F5F0 50%,#EEEAE1 75%);background-size:200% 100%;animation:dvsk 1.3s infinite linear}@keyframes dvsk{0%{background-position:200% 0}100%{background-position:-200% 0}}
.np-ft{font-size:.76rem;color:#8E8A80;margin:14px 0 0}
@media(max-width:640px){.np{padding:14px}.np-it{flex-direction:column;gap:8px}.np-q{flex:1 1 100%}}
</style>
<script>
(function(){var R=document.getElementById('dv-np');if(!R)return;var api=R.dataset.api,nc=R.dataset.nonce,C={},aba='geral',tag='',q='';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function nz(s){return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'')}
function quando(d){var h=new Date();h.setHours(0,0,0,0);var x=new Date(d+'T12:00:00');var n=Math.round((h-x)/864e5);return n<=0?'hoje':n===1?'ontem':n<7?'há '+n+' dias':d.split('-').reverse().join('/')}
var GC={'Tribunal de Contas':'g1','Investigação':'g2','Licitação aberta':'g3'};
var VZ={ramo:'Para ver as notícias do seu ramo, <a href="/plataforma/alertas/">crie um alerta de oportunidades</a> com as palavras do seu segmento (por exemplo: limpeza, vigilância, merenda).',orgaos:'Para ver notícias dos órgãos que você acompanha, <a href="/plataforma/licitacoes/">importe uma licitação na Gestão</a>.'};
function pinta(){var L=R.querySelector('.np-list'),T=R.querySelector('.np-tags'),d=C[aba];if(!d){L.innerHTML='<div class="np-sk"></div><div class="np-sk"></div><div class="np-sk"></div><div class="np-sk"></div>';T.innerHTML='';return}
 if(d.vazio){T.innerHTML='';L.innerHTML='<div class="np-vz">'+VZ[d.vazio]+'</div>';return}
 var it=d.itens||[],cont={};it.forEach(function(x){cont[x.g]=(cont[x.g]||0)+1});
 T.innerHTML=(d.termos&&d.termos.length?'<span style="font-size:.78rem;color:#5E5A52;align-self:center">Buscando: '+d.termos.map(e).join(' · ')+'</span>':'')+(it.length?'<button type="button" data-g="" class="'+(tag?'':'on')+'">Tudo · '+it.length+'</button>'+Object.keys(cont).map(function(k){return'<button type="button" data-g="'+e(k)+'" class="'+(tag===k?'on':'')+'">'+e(k)+' · '+cont[k]+'</button>'}).join(''):'');
 var nq=nz(q),f=it.filter(function(x){return(!tag||x.g===tag)&&(!nq||nz(x.t+' '+x.f).indexOf(nq)>=0)});
 L.innerHTML=f.length?f.map(function(x,i){return'<div class="np-it"><div class="tx"><a class="t" href="'+e(x.u)+'" target="_blank" rel="noopener">'+e(x.t)+'</a><div class="m"><span class="np-g '+(GC[x.g]||'')+'">'+e(x.g)+'</span><span>'+e(x.f)+'</span><span>·</span><span>'+quando(x.d)+'</span>'+(x.q?'<span>·</span><span>sobre “'+e(x.q)+'”</span>':'')+'</div></div><button type="button" class="ia" data-i="'+it.indexOf(x)+'">Analisar com a minha IA</button></div>'}).join(''):'<div class="np-vz">'+(d.aviso?e(d.aviso):'Nenhuma notícia encontrada'+(q||tag?' com esse filtro.':' agora. Volte mais tarde.'))+'</div>'}
function carrega(a){if(C[a]){pinta();return}pinta();fetch(api+'noticias?aba='+a,{headers:{'X-WP-Nonce':nc},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){C[a]=j&&j.itens?j:{itens:[],aviso:(j&&j.message)||'As notícias não carregaram agora.'};if(a===aba)pinta()}).catch(function(){C[a]={itens:[],aviso:'As notícias não carregaram agora. Tente de novo em alguns minutos.'};if(a===aba)pinta()})}
R.addEventListener('click',function(ev){var b=ev.target.closest('[data-a]');if(b){aba=b.dataset.a;tag='';[].forEach.call(R.querySelectorAll('[data-a]'),function(z){z.classList.toggle('on',z===b)});try{localStorage.setItem('dv_np_aba',aba)}catch(x){}carrega(aba);return}
 var g=ev.target.closest('[data-g]');if(g){tag=g.dataset.g;pinta();return}
 var ia=ev.target.closest('.ia');if(ia&&window.dvMinhaIA){var x=C[aba].itens[+ia.dataset.i];window.dvMinhaIA('noticia','Notícia: '+x.t+'\nVeículo: '+x.f+'\nData: '+x.d.split('-').reverse().join('/')+'\nLink: '+x.u)}});
R.querySelector('.np-q').addEventListener('input',function(){q=this.value;pinta()});
try{var sa=localStorage.getItem('dv_np_aba');if(sa&&/^(geral|ramo|orgaos|decisoes)$/.test(sa)){aba=sa;[].forEach.call(R.querySelectorAll('[data-a]'),function(z){z.classList.toggle('on',z.dataset.a===aba)})}}catch(x){}
carrega(aba);
})();
</script>
	<?php
	return ob_get_clean();
} );
