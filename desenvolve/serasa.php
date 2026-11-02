/**
 * Desenvolve — Consulta Serasa por CPF ou CNPJ (via Asaas, endpoint /creditBureauReport).
 * Fica desligada até o custo "serasa" ser maior que zero em Configurações > custos (ex.: serasa:20)
 * e a conta do Asaas estar liberada para "Consultas Serasa via API" (pedido ao gerente de contas).
 * O relatório é um PDF do Asaas: a plataforma guarda só o número da consulta e baixa o PDF na hora, sem armazenar o arquivo.
 */

function dv_sr_ligado() { return function_exists( 'dv_custo' ) && dv_custo( 'serasa' ) > 0 && strpos( (string) dv_cfg( 'custos' ), 'serasa:' ) !== false; }
function dv_sr_lista( $uid ) { $l = get_user_meta( $uid, 'dv_serasa', true ); return is_array( $l ) ? $l : array(); }
function dv_sr_fmt( $d ) {
	return strlen( $d ) === 14 ? preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d ) : preg_replace( '/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d );
}

add_action( 'init', function () {
	if ( get_option( 'dv_sr_pagina' ) === '1' ) { return; }
	if ( ! get_page_by_path( 'plataforma/serasa' ) ) {
		$pai = get_page_by_path( 'plataforma' );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'serasa', 'post_parent' => $pai ? $pai->ID : 0, 'post_title' => 'Consulta Serasa',
			'post_content' => '[dv_ctx tool="serasa"]<section class="dv-th"><small>Ferramenta Desenvolve</small><h1>Consulta Serasa</h1><p>Score, pendências financeiras, protestos em todo o país, cheques sem fundo e situação do CPF ou do CNPJ, direto do Serasa. O relatório sai em PDF na hora.</p></section>[dv_serasa]' ) );
		if ( $id && ! is_wp_error( $id ) ) { update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); }
	}
	update_option( 'dv_sr_pagina', '1', false );
}, 30 );

/* fora da plataforma (visitante) a página não aparece */
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() && is_page( 'serasa' ) ) { wp_safe_redirect( home_url( '/' ), 302 ); exit; }
} );
add_action( 'wp_head', function () { echo '<style>body:not(.logged-in) .dv-cst{display:none!important}</style>'; } );

add_action( 'rest_api_init', function () {
	$perm = function () { return is_user_logged_in() && function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	register_rest_route( 'desenvolve/v1', '/serasa', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $master = dv_is_master( $uid );
		if ( ! dv_sr_ligado() && ! $master ) { return new WP_Error( 'dv_sr', 'A Consulta Serasa ainda não está disponível.', array( 'status' => 403 ) ); }
		$doc = preg_replace( '/\D/', '', (string) $r['doc'] );
		if ( ! function_exists( 'dv_doc_valido' ) || ! dv_doc_valido( $doc ) ) { return new WP_Error( 'dv_sr', 'CPF ou CNPJ inválido. Confira os números.', array( 'status' => 400 ) ); }
		if ( empty( $r['finalidade'] ) ) { return new WP_Error( 'dv_sr', 'Confirme a finalidade da consulta para continuar.', array( 'status' => 400 ) ); }
		/* mesma consulta nos últimos 30 dias: devolve a que já existe, sem pagar de novo */
		$L = dv_sr_lista( $uid ); $lim = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		foreach ( $L as $x ) { if ( $x['doc'] === $doc && $x['data'] > $lim ) { return array( 'id' => $x['id'], 'repetida' => true, 'lista' => dv_sr_publica( $uid ) ); } }
		$n = max( 1, dv_custo( 'serasa' ) ); $livre = $master || ( function_exists( 'dv_teste_ilimitado' ) && dv_teste_ilimitado( $uid ) );
		if ( ! $livre && dv_saldo( $uid )['total'] < $n ) { return new WP_Error( 'dv_sem_credito', 'A Consulta Serasa usa ' . $n . ' créditos e o seu saldo não é suficiente.', array( 'status' => 402 ) ); }
		$j = dv_asaas( 'POST', '/creditBureauReport', array( 'cpfCnpj' => $doc ) );
		if ( is_wp_error( $j ) ) { return new WP_Error( 'dv_sr', 'O Serasa não respondeu: ' . $j->get_error_message() . ' Nenhum crédito foi cobrado.', array( 'status' => 502 ) ); }
		if ( empty( $j['id'] ) ) { return new WP_Error( 'dv_sr', 'O Serasa não devolveu o relatório. Nenhum crédito foi cobrado.', array( 'status' => 502 ) ); }
		/* cobra só depois que a consulta deu certo */
		$rot = 'Serasa · ' . ( strlen( $doc ) === 14 ? 'CNPJ ' : 'CPF ' ) . dv_sr_fmt( $doc );
		for ( $i = 1; $i <= $n; $i++ ) { $u = dv_usar( $uid, 'serasa', $doc . '|' . $j['id'] . '|' . $i, $i === 1 ? $rot : '' ); if ( is_wp_error( $u ) || ! empty( $u['motivo'] ) && in_array( $u['motivo'], array( 'master', 'teste' ), true ) ) { break; } }
		array_unshift( $L, array( 'id' => (string) $j['id'], 'doc' => $doc, 'data' => current_time( 'mysql' ) ) );
		update_user_meta( $uid, 'dv_serasa', array_slice( $L, 0, 50 ) );
		return array( 'id' => (string) $j['id'], 'lista' => dv_sr_publica( $uid ) );
	} ) );
	register_rest_route( 'desenvolve/v1', '/serasa/pdf', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $r['id'] ); $ok = false;
		foreach ( dv_sr_lista( $uid ) as $x ) { if ( $x['id'] === $id ) { $ok = $x; } }
		if ( ! $ok ) { return new WP_Error( 'dv_sr', 'Consulta não encontrada.', array( 'status' => 404 ) ); }
		$k = get_option( 'dv_asaas_key' ); if ( ! $k ) { return new WP_Error( 'dv_sr', 'Pagamento não configurado.', array( 'status' => 503 ) ); }
		$g = wp_remote_get( dv_asaas_base() . '/creditBureauReport/' . rawurlencode( $id ) . '/download', array( 'timeout' => 40, 'headers' => array( 'access_token' => $k, 'User-Agent' => 'Desenvolve' ) ) );
		$b = is_wp_error( $g ) ? '' : wp_remote_retrieve_body( $g );
		if ( is_wp_error( $g ) || wp_remote_retrieve_response_code( $g ) !== 200 || substr( $b, 0, 4 ) !== '%PDF' ) { return new WP_Error( 'dv_sr', 'Não foi possível baixar o PDF agora. Tente de novo em instantes.', array( 'status' => 502 ) ); }
		nocache_headers(); header( 'Content-Type: application/pdf' ); header( 'Content-Disposition: inline; filename="serasa-' . $ok['doc'] . '.pdf"' ); echo $b; exit; // phpcs:ignore
	} ) );
} );
function dv_sr_publica( $uid ) {
	return array_map( function ( $x ) { return array( 'id' => $x['id'], 'doc' => dv_sr_fmt( $x['doc'] ), 'tipo' => strlen( $x['doc'] ) === 14 ? 'CNPJ' : 'CPF', 'data' => mysql2date( 'd/m/Y H:i', $x['data'] ) ); }, array_slice( dv_sr_lista( $uid ), 0, 20 ) );
}

add_shortcode( 'dv_serasa', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	$uid = get_current_user_id(); $master = dv_is_master( $uid ); $lig = dv_sr_ligado();
	$n = max( 1, dv_custo( 'serasa' ) ); $livre = $master || ( function_exists( 'dv_teste_ilimitado' ) && dv_teste_ilimitado( $uid ) );
	ob_start(); ?>
<div class="dv-tool dv-sr" data-api="<?php echo esc_url( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
<?php if ( ! $lig && ! $master ) { ?><div class="sr-bx"><b>Em breve.</b> A Consulta Serasa está sendo liberada. Enquanto isso, use o Raio-X do concorrente e a Due Diligence.</div></div><?php return ob_get_clean(); } ?>
<?php if ( ! $lig ) { ?><p class="sr-av">Só você (Master) vê esta tela. Para liberar aos clientes, defina o custo em Configurações &gt; custos (ex.: <code>serasa:20</code>) depois que o Asaas liberar a consulta via API.</p><?php } ?>
<div class="sr-bx"><label for="sr-d"><b>CPF ou CNPJ</b></label>
<div class="sr-f"><input id="sr-d" inputmode="numeric" placeholder="000.000.000-00 ou 00.000.000/0000-00"><button type="button" class="sr-go">Consultar no Serasa</button></div>
<label class="sr-ck"><input type="checkbox" id="sr-ok"> Confirmo que a consulta tem finalidade legítima: análise de crédito ou de risco de cliente, fornecedor, concorrente ou parceiro de negócio (LGPD, art. 7º, X).</label>
<p class="sr-c"><?php echo $livre ? 'Sem custo na sua conta.' : 'Usa ' . (int) $n . ' créditos. Consultar o mesmo documento de novo em até 30 dias não cobra.'; ?></p><p class="sr-m"></p></div>
<div class="sr-bx"><h3>O que vem no relatório</h3><ul><li>Situação do CPF ou CNPJ (regular ou irregular)</li><li>Score de 0 a 1000</li><li>Pendências financeiras e negativações</li><li>Protestos em cartório em todo o país</li><li>Cheques sem fundo e documentos roubados ou extraviados</li><li>Quantas vezes o documento foi consultado</li></ul><p class="sr-n">Dados do Serasa Experian, consultados pelo Asaas. O relatório cobre os últimos meses e é só para uso interno da sua empresa.</p></div>
<div class="sr-bx"><h3>Suas consultas</h3><div class="sr-l"></div></div>
</div>
<style>
.dv-sr .sr-bx{background:#fff;border:1px solid #E3DED2;border-radius:16px;padding:18px 20px;margin:0 0 16px;font-family:'Hanken Grotesk',system-ui,sans-serif;color:#16191E}
.dv-sr .sr-f{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 10px}.dv-sr .sr-f input{flex:1 1 280px;border:1px solid #D9D3C4;border-radius:12px;padding:12px 14px;font:inherit;color:#16191E;background:#fff}
.dv-sr .sr-go{background:#C9A45C!important;color:#241A06!important;border:0;border-radius:12px;padding:12px 22px;font:inherit;font-weight:700;cursor:pointer}.dv-sr .sr-go:disabled{opacity:.6}
.dv-sr .sr-ck{display:flex;gap:10px;align-items:flex-start;font-size:.88rem;color:#5E5A52;line-height:1.45}.dv-sr .sr-ck input{margin-top:3px}
.dv-sr .sr-c{color:#5E5A52;font-size:.88rem;margin:10px 0 0}.dv-sr .sr-m{margin:8px 0 0;font-weight:600}.dv-sr .sr-m.er{color:#9A3524}.dv-sr .sr-m.ok{color:#1F5A3B}
.dv-sr ul{margin:6px 0 10px;padding-left:20px;line-height:1.7}.dv-sr .sr-n,.dv-sr .sr-av{font-size:.82rem;color:#6E6A60}.dv-sr .sr-av{background:#FBF1DC;border-radius:10px;padding:10px 14px;color:#7A5410}
.dv-sr .sr-i{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-top:1px solid #EFEBE2}.dv-sr .sr-i a{color:#86621F;font-weight:700;text-decoration:none}
</style>
<script>(function(){var R=document.querySelector('.dv-sr');if(!R||!R.querySelector('.sr-go'))return;var api=R.dataset.api,nonce=R.dataset.nonce,I=R.querySelector('#sr-d'),B=R.querySelector('.sr-go'),M=R.querySelector('.sr-m'),L=R.querySelector('.sr-l');
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function lista(l){L.innerHTML=l&&l.length?l.map(function(x){return'<div class="sr-i"><span><b>'+esc(x.tipo)+' '+esc(x.doc)+'</b><br><small>'+esc(x.data)+' (horário de Brasília)</small></span><a href="'+api+'serasa/pdf?id='+encodeURIComponent(x.id)+'&_wpnonce='+nonce+'" target="_blank" rel="noopener">Abrir PDF</a></div>'}).join(''):'<p class="sr-n">Nenhuma consulta ainda.</p>'}
lista(<?php echo wp_json_encode( dv_sr_publica( $uid ) ); ?>);
I.addEventListener('input',function(){var d=I.value.replace(/\D/g,'').slice(0,14);I.value=d.length<=11?d.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1-$2'):d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})$/,'$1.$2.$3/$4-$5')});
var q=new URLSearchParams(location.search).get('doc');if(q){I.value=q;I.dispatchEvent(new Event('input'))}
B.addEventListener('click',function(){var d=I.value.replace(/\D/g,'');M.className='sr-m';if(d.length!==11&&d.length!==14){M.className='sr-m er';M.textContent='Digite os 11 números do CPF ou os 14 do CNPJ.';return}if(!R.querySelector('#sr-ok').checked){M.className='sr-m er';M.textContent='Confirme a finalidade da consulta.';return}
 B.disabled=true;M.textContent='Consultando o Serasa… pode levar até 30 segundos.';fetch(api+'serasa',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:JSON.stringify({doc:d,finalidade:true})}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})}).then(function(j){B.disabled=false;lista(j.lista);M.className='sr-m ok';M.innerHTML=(j.repetida?'Você já consultou este documento nos últimos 30 dias, sem nova cobrança. ':'Consulta pronta. ')+'<a href="'+api+'serasa/pdf?id='+encodeURIComponent(j.id)+'&_wpnonce='+nonce+'" target="_blank" rel="noopener">Abrir o relatório em PDF</a>'}).catch(function(j){B.disabled=false;M.className='sr-m er';M.textContent=(j&&j.message)||'Não foi possível consultar agora.'})});
})();</script>
	<?php return ob_get_clean();
} );
