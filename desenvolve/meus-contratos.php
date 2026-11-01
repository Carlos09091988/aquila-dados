/**
 * Desenvolve — Meus contratos e garantias
 * A empresa importa do PNCP (pelo CNPJ) ou cadastra os próprios contratos e garantias.
 * Controla vencimentos, prorrogações e garantias (seguro-garantia, fiança, caução) e
 * avisa quando sai a licitação que substitui o contrato (mesmo órgão, objeto parecido).
 * Incluso na assinatura. Conecta: Raio-X do órgão, Radar de contratos vencendo, Gestão de
 * licitações (importar edital), conferência do edital e seguro garantia (Defenseg).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function dv_mc_get( $uid ) { $d = json_decode( get_user_meta( $uid, 'dv_mc', true ) ?: '{}', true ); return array( 'c' => (array) ( $d['c'] ?? array() ), 'g' => (array) ( $d['g'] ?? array() ) ); }
function dv_mc_put( $uid, $d ) { update_user_meta( $uid, 'dv_mc', wp_slash( wp_json_encode( array( 'c' => array_values( $d['c'] ), 'g' => array_values( $d['g'] ) ), JSON_UNESCAPED_UNICODE ) ) ); }
function dv_mc_data( $s ) { return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $s ) ? (string) $s : ''; }
function dv_mc_tokens( $t ) {
	$t = strtolower( remove_accents( (string) $t ) ); $stop = array_flip( explode( ' ', 'contratacao empresa empresas prestacao servico servicos continuos continuo fornecimento aquisicao especializada especializado objeto registro precos eventual futura atender atendimento necessidades secretaria municipal estadual federal mediante conforme demais especificacoes termo referencia anexo edital inclusive incluindo diversos diversas forma regime execucao unidades unidade publica publico durante periodo meses quantidade materiais equipamentos insumos dedicacao exclusiva obra para pelo pela com dos das nos nas sob lei' ) );
	$w = array_filter( preg_split( '/[^a-z0-9]+/', $t ), function ( $x ) use ( $stop ) { return strlen( $x ) > 4 && ! isset( $stop[ $x ] ); } );
	return array_slice( array_values( array_unique( $w ) ), 0, 3 );
}
/** Procura no PNCP a licitação de substituição (mesmo órgão) e oportunidades do mesmo objeto na UF */
function dv_mc_buscar( $c ) {
	$tk = dv_mc_tokens( $c['objeto'] ?? '' ); if ( ! $tk ) { return null; }
	$u = 'https://pncp.gov.br/api/search/?q=' . rawurlencode( implode( ' ', $tk ) ) . '&tipos_documento=edital&ordenacao=-data&pagina=1&tam_pagina=60';
	$g = wp_remote_get( $u, array( 'timeout' => 25 ) ); if ( is_wp_error( $g ) || wp_remote_retrieve_response_code( $g ) >= 400 ) { return null; }
	$j = json_decode( wp_remote_retrieve_body( $g ), true ); $sub = array(); $prox = array(); $hoje = current_time( 'Y-m-d' );
	$desde = ! empty( $c['ini'] ) ? $c['ini'] : gmdate( 'Y-m-d', strtotime( '-2 years' ) );
	foreach ( (array) ( $j['items'] ?? array() ) as $i ) {
		$pub = substr( (string) ( $i['data_publicacao_pncp'] ?? '' ), 0, 10 ); $url = (string) ( $i['item_url'] ?? '' );
		$x = array( 't' => mb_substr( (string) ( $i['description'] ?? $i['title'] ?? '' ), 0, 200 ), 'org' => (string) ( $i['orgao_nome'] ?? '' ), 'uf' => (string) ( $i['uf'] ?? '' ), 'mu' => (string) ( $i['municipio_nome'] ?? '' ), 'pub' => $pub, 'fim' => substr( (string) ( $i['data_fim_recebimento_propostas'] ?? '' ), 0, 10 ), 'url' => $url );
		if ( ! empty( $c['oc'] ) && ( $i['orgao_cnpj'] ?? '' ) === $c['oc'] && $pub >= $desde ) { $sub[] = $x; }
		elseif ( ! empty( $c['uf'] ) && ( $i['uf'] ?? '' ) === $c['uf'] && $x['fim'] >= $hoje && count( $prox ) < 5 ) { $prox[] = $x; }
	}
	return array( 'sub' => array_slice( $sub, 0, 5 ), 'prox' => $prox, 'em' => $hoje );
}
add_action( 'rest_api_init', function () {
	$ns = 'desenvolve/v1'; $perm = function () { return is_user_logged_in(); };
	register_rest_route( $ns, '/mc', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); return dv_mc_get( $uid ) + array( 'doc' => preg_replace( '/\D/', '', (string) get_user_meta( $uid, 'dv_doc', true ) ) );
	} ) );
	register_rest_route( $ns, '/mc/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $d = dv_mc_get( $uid ); $k = $r['tipo'] === 'g' ? 'g' : 'c'; $t = function ( $f, $n = 200 ) use ( $r ) { return mb_substr( sanitize_text_field( (string) $r[ $f ] ), 0, $n ); };
		if ( $k === 'c' ) {
			$it = array( 'orgao' => $t( 'orgao' ), 'oc' => preg_replace( '/\D/', '', (string) $r['oc'] ), 'uf' => strtoupper( $t( 'uf', 2 ) ), 'mu' => $t( 'mu', 120 ), 'objeto' => mb_substr( sanitize_textarea_field( (string) $r['objeto'] ), 0, 600 ), 'numero' => $t( 'numero', 80 ), 'valor' => (float) $r['valor'], 'ini' => dv_mc_data( $r['ini'] ), 'fim' => dv_mc_data( $r['fim'] ), 'pncp' => preg_replace( '#[^a-z0-9/._-]#i', '', (string) $r['pncp'] ), 'notas' => mb_substr( sanitize_textarea_field( (string) $r['notas'] ), 0, 1500 ), 'origem' => $r['origem'] === 'pncp' ? 'pncp' : 'manual' );
			if ( ! $it['orgao'] || ! $it['fim'] ) { return new WP_Error( 'dv_mc', 'Informe ao menos o órgão e a data de fim da vigência.', array( 'status' => 400 ) ); }
		} else {
			$it = array( 'cid' => sanitize_key( (string) $r['cid'] ), 'tipo' => in_array( $r['gtipo'], array( 'seguro', 'fianca', 'caucao', 'titulos' ), true ) ? $r['gtipo'] : 'seguro', 'mod' => in_array( $r['mod'], array( 'execucao', 'proposta', 'adicional', 'judicial', 'outra' ), true ) ? $r['mod'] : 'execucao', 'seguradora' => $t( 'seguradora', 120 ), 'apolice' => $t( 'apolice', 80 ), 'valor' => (float) $r['valor'], 'ini' => dv_mc_data( $r['ini'] ), 'venc' => dv_mc_data( $r['venc'] ), 'notas' => mb_substr( sanitize_textarea_field( (string) $r['notas'] ), 0, 1000 ), 'orgao' => $t( 'orgao' ) );
			if ( ! $it['venc'] ) { return new WP_Error( 'dv_mc', 'Informe a data de vencimento da garantia.', array( 'status' => 400 ) ); }
		}
		$id = sanitize_key( (string) $r['id'] ); $ach = false;
		foreach ( $d[ $k ] as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $it ); if ( $k === 'c' ) { $x['avisos'] = array(); } else { $x['avisos'] = array(); } $ach = true; } } unset( $x );
		if ( ! $ach ) { $d[ $k ][] = $it + array( 'id' => substr( md5( uniqid( '', true ) ), 0, 10 ), 'criado' => current_time( 'Y-m-d' ), 'avisos' => array() ); }
		dv_mc_put( $uid, $d ); return dv_mc_get( $uid );
	} ) );
	register_rest_route( $ns, '/mc/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $d = dv_mc_get( $uid ); $k = $r['tipo'] === 'g' ? 'g' : 'c'; $id = sanitize_key( (string) $r['id'] );
		$d[ $k ] = array_filter( $d[ $k ], function ( $x ) use ( $id ) { return $x['id'] !== $id; } ); dv_mc_put( $uid, $d ); return dv_mc_get( $uid );
	} ) );
	/* Contratos vigentes da empresa no PNCP (busca pelo CNPJ do fornecedor) */
	register_rest_route( $ns, '/mc/importar', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$cnpj = preg_replace( '/\D/', '', (string) ( $r['cnpj'] ?: get_user_meta( get_current_user_id(), 'dv_doc', true ) ) );
		if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_mc', 'Informe o CNPJ da empresa.', array( 'status' => 400 ) ); }
		$out = array();
		for ( $p = 1; $p <= 3; $p++ ) {
			$g = wp_remote_get( 'https://pncp.gov.br/api/search/?q=' . $cnpj . '&tipos_documento=contrato&status=vigente&ordenacao=-data&pagina=' . $p . '&tam_pagina=100', array( 'timeout' => 30 ) );
			if ( is_wp_error( $g ) ) { break; } $j = json_decode( wp_remote_retrieve_body( $g ), true ); $it = (array) ( $j['items'] ?? array() );
			foreach ( $it as $i ) {
				if ( preg_replace( '/\D/', '', (string) ( $i['fornecedor_ni'] ?? '' ) ) !== $cnpj ) { continue; }
				$out[] = array( 'orgao' => (string) ( $i['orgao_nome'] ?? '' ), 'oc' => preg_replace( '/\D/', '', (string) ( $i['orgao_cnpj'] ?? '' ) ), 'uf' => (string) ( $i['uf'] ?? '' ), 'mu' => (string) ( $i['municipio_nome'] ?? '' ), 'objeto' => mb_substr( (string) ( $i['description'] ?? '' ), 0, 600 ), 'numero' => (string) ( $i['numero_contrato_empenho'] ?? $i['title'] ?? '' ), 'valor' => (float) ( $i['valor_global'] ?? 0 ), 'ini' => substr( (string) ( $i['data_inicio_vigencia'] ?? '' ), 0, 10 ), 'fim' => substr( (string) ( $i['data_fim_vigencia'] ?? '' ), 0, 10 ), 'pncp' => (string) ( $i['item_url'] ?? '' ), 'origem' => 'pncp' );
			}
			if ( count( $it ) < 100 ) { break; }
		}
		return array( 'cnpj' => $cnpj, 'itens' => $out );
	} ) );
	register_rest_route( $ns, '/mc/verificar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $d = dv_mc_get( $uid ); $id = sanitize_key( (string) $r['id'] );
		foreach ( $d['c'] as &$x ) { if ( $x['id'] === $id ) { $b = dv_mc_buscar( $x ); if ( $b === null ) { return new WP_Error( 'dv_mc', 'O PNCP não respondeu agora. Tente de novo.', array( 'status' => 502 ) ); } $x['busca'] = $b; } } unset( $x );
		dv_mc_put( $uid, $d ); return dv_mc_get( $uid );
	} ) );
} );
/* Avisos por e-mail: vencimento do contrato, vencimento da garantia, licitação de substituição */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_mc_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 11:10 UTC' ), 'daily', 'dv_mc_diario' ); } } );
add_action( 'dv_mc_diario', function () {
	global $wpdb; @set_time_limit( 900 );
	$hoje = current_time( 'Y-m-d' ); $seg = (int) current_time( 'N' ) === 1;
	$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$wa = 'https://wa.me/5511993500950?text=' . rawurlencode( 'Olá! Preciso renovar/contratar um seguro garantia de contrato público.' );
	$dd = function ( $d ) use ( $hoje ) { return (int) round( ( strtotime( $d ) - strtotime( $hoje ) ) / DAY_IN_SECONDS ); };
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_mc'" ) as $uid ) {
		$uid = (int) $uid; $u = get_userdata( $uid ); if ( ! $u ) { continue; }
		$d = dv_mc_get( $uid ); $L = array(); $mud = false; $byid = array();
		foreach ( $d['c'] as &$c ) {
			$byid[ $c['id'] ] = $c; $n = $dd( $c['fim'] );
			foreach ( array( 180, 120, 90, 60, 30, 15 ) as $m ) { if ( $n <= $m && $n >= 0 && empty( $c['avisos'][ 'v' . $m ] ) ) { $c['avisos'][ 'v' . $m ] = $hoje; $mud = true; $L[] = '⏳ Contrato com <b>' . esc_html( $c['orgao'] ) . '</b> vence em <b>' . $n . ' dias</b> (' . esc_html( implode( '/', array_reverse( explode( '-', $c['fim'] ) ) ) ) . '). ' . esc_html( mb_substr( $c['objeto'], 0, 120 ) ); break; } }
			if ( $seg && $n >= -30 && $n <= 365 ) {
				$b = dv_mc_buscar( $c ); if ( $b !== null ) { $ant = array_column( (array) ( $c['busca']['sub'] ?? array() ), 'url' ); $c['busca'] = $b; $mud = true;
					foreach ( $b['sub'] as $s ) { if ( ! in_array( $s['url'], $ant, true ) ) { $L[] = '🔔 <b>Saiu licitação no mesmo órgão</b> (' . esc_html( $c['orgao'] ) . '), parecida com o seu contrato: ' . esc_html( mb_substr( $s['t'], 0, 140 ) ) . ' · <a href="https://pncp.gov.br/app' . esc_attr( $s['url'] ) . '">ver no PNCP</a>'; } } }
			}
		} unset( $c );
		foreach ( $d['g'] as &$g ) {
			$n = $dd( $g['venc'] ); $oc = ! empty( $g['cid'] ) && isset( $byid[ $g['cid'] ] ) ? $byid[ $g['cid'] ] : null;
			foreach ( array( 60, 30, 15, 7 ) as $m ) { if ( $n <= $m && $n >= 0 && empty( $g['avisos'][ 'g' . $m ] ) ) { $g['avisos'][ 'g' . $m ] = $hoje; $mud = true; $L[] = '🛡️ Garantia (' . esc_html( $g['tipo'] === 'seguro' ? 'seguro-garantia' : $g['tipo'] ) . ( $g['apolice'] ? ' nº ' . esc_html( $g['apolice'] ) : '' ) . ') de <b>' . esc_html( $oc ? $oc['orgao'] : ( $g['orgao'] ?: 'contrato' ) ) . '</b> vence em <b>' . $n . ' dias</b>. <a href="' . esc_url( $wa ) . '">Renovar com a Desenvolve/Defenseg</a>'; break; } }
			if ( $oc && $oc['fim'] > $g['venc'] && $dd( $g['venc'] ) >= 0 && empty( $g['avisos']['desc'] ) ) { $g['avisos']['desc'] = $hoje; $mud = true; $L[] = '⚠️ A garantia de <b>' . esc_html( $oc['orgao'] ) . '</b> vence em ' . esc_html( implode( '/', array_reverse( explode( '-', $g['venc'] ) ) ) ) . ', antes do fim do contrato (' . esc_html( implode( '/', array_reverse( explode( '-', $oc['fim'] ) ) ) ) . '). Garantia vencida no curso do contrato pode gerar penalidade: <a href="' . esc_url( $wa ) . '">renove ou endosse a apólice</a>.'; }
		} unset( $g );
		if ( $mud ) { dv_mc_put( $uid, $d ); }
		if ( $L ) { wp_mail( $u->user_email, 'Seus contratos e garantias: ' . count( $L ) . ' aviso(s) · Desenvolve', '<div style="font-family:Arial,sans-serif;color:#16191E;max-width:680px"><h2>Avisos dos seus contratos e garantias</h2><ul style="padding-left:18px">' . implode( '', array_map( function ( $x ) { return '<li style="margin:8px 0">' . $x . '</li>'; }, $L ) ) . '</ul><p><a href="' . esc_url( home_url( '/plataforma/meus-contratos/' ) ) . '" style="display:inline-block;background:#C9A45C;color:#241A06;padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:700">Abrir Meus contratos</a></p></div>', array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve <' . $from . '>' ) ); }
	}
} );
add_shortcode( 'dv_meus_contratos', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="mc" id="dv-mc" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="mc-kp"></div>
	<div class="mc-act"><button type="button" class="mc-imp">⬇ Importar meus contratos do PNCP</button><button type="button" class="g" data-novo="c">+ Cadastrar contrato</button><button type="button" class="g" data-novo="g">+ Cadastrar garantia</button></div>
	<div class="mc-im"></div>
	<div class="mc-tabs"><span data-t="c" class="on">Contratos</span><span data-t="g">Garantias</span></div>
	<div class="mc-l"><p class="mc-h">Carregando…</p></div>
	<p class="mc-h" style="font-size:.78rem;margin-top:14px">Avisos por e-mail: contrato com 180, 120, 90, 60, 30 e 15 dias para vencer; garantia com 60, 30, 15 e 7 dias; e toda segunda procuramos no PNCP a licitação que substitui cada contrato (mesmo órgão, objeto parecido). Datas conforme publicadas no PNCP ou informadas por você.</p>
	<dialog class="mc-d mc-dc"><form method="dialog" class="mc-f"><h3>Contrato</h3><input type="hidden" name="id"><input type="hidden" name="oc"><input type="hidden" name="pncp"><input type="hidden" name="origem">
		<label>Órgão contratante<input name="orgao" required></label><div class="mc-2"><label>Cidade<input name="mu"></label><label>UF<input name="uf" maxlength="2"></label></div>
		<label>Objeto<textarea name="objeto" rows="3"></textarea></label><div class="mc-2"><label>Número do contrato<input name="numero"></label><label>Valor global (R$)<input name="valor" inputmode="decimal"></label></div>
		<div class="mc-2"><label>Início da vigência<input type="date" name="ini"></label><label>Fim da vigência<input type="date" name="fim" required></label></div>
		<label>Anotações<textarea name="notas" rows="2" placeholder="Ex.: prorrogado até…; gestor do contrato…"></textarea></label><p class="mc-fm mc-h"></p>
		<div class="mc-x"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="mc-ok" data-k="c">Salvar</button></div></form></dialog>
	<dialog class="mc-d mc-dg"><form method="dialog" class="mc-f"><h3>Garantia</h3><input type="hidden" name="id">
		<label>Contrato<select name="cid"></select></label><label class="mc-or">Órgão / beneficiário (se não estiver na lista)<input name="orgao"></label>
		<div class="mc-2"><label>Tipo<select name="gtipo"><option value="seguro">Seguro-garantia</option><option value="fianca">Fiança bancária</option><option value="caucao">Caução em dinheiro</option><option value="titulos">Títulos da dívida pública</option></select></label><label>Finalidade<select name="mod"><option value="execucao">Execução do contrato</option><option value="proposta">Garantia de proposta</option><option value="adicional">Garantia adicional (art. 59, § 5º)</option><option value="judicial">Judicial</option><option value="outra">Outra</option></select></label></div>
		<div class="mc-2"><label>Seguradora / banco<input name="seguradora"></label><label>Nº da apólice / carta<input name="apolice"></label></div>
		<div class="mc-3"><label>Valor garantido (R$)<input name="valor" inputmode="decimal"></label><label>Início<input type="date" name="ini"></label><label>Vencimento<input type="date" name="venc" required></label></div>
		<label>Anotações<textarea name="notas" rows="2"></textarea></label><p class="mc-fm mc-h"></p>
		<div class="mc-x"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="mc-ok" data-k="g">Salvar</button></div></form></dialog>
</div>
<style>
.mc{--ln:#E3DED2;--mu:#6E6A60;--rd:#B04A3A;--am:#9A6B12;--gr:#2F7A55;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.mc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer}.mc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600}
.mc input,.mc select,.mc textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box;margin-top:4px}
.mc label{display:block;font-weight:700;margin:0 0 10px;font-size:.9rem}.mc-h{color:var(--mu);font-size:.88rem;margin:6px 0 0}
.mc-kp{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}.mc-kp div{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:12px 14px}.mc-kp b{display:block;font-size:1.5rem}.mc-kp span{color:var(--mu);font-size:.82rem}
.mc-act{display:flex;flex-wrap:wrap;gap:8px}.mc-tabs{display:flex;gap:6px;margin:18px 0 10px}.mc-tabs span{border:1px solid var(--ln);border-radius:999px;padding:7px 14px;cursor:pointer;font-weight:600;font-size:.86rem;background:#fff}.mc-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.mc-i{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:10px}.mc-i .d{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.mc-i .o{font-weight:700}.mc-i .s{color:var(--mu);font-size:.86rem;margin-top:3px}
.mc-v{font-weight:700;white-space:nowrap;text-align:right}.mc-v.u{color:var(--rd)}.mc-v.m{color:var(--am)}.mc-v.ok{color:var(--gr)}
.mc-al{background:#FFF8E6;border:1px solid #EAD9A8;border-radius:10px;padding:8px 12px;margin-top:8px;font-size:.86rem}.mc-al.r{background:#FDEDEA;border-color:#EBC3BA}
.mc-i .a{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.mc-i .a a,.mc-i .a button{font-size:.8rem;font-weight:600;border:1px solid var(--ln);border-radius:9px;padding:6px 10px;text-decoration:none;color:#16191E;background:#fff;cursor:pointer}.mc-i .a .p{background:#C9A45C;border-color:#C9A45C;color:#241A06}
.mc-im .mc-i label{display:flex;gap:8px;align-items:flex-start;font-weight:500;margin:6px 0}.mc-im .mc-i label input{width:auto;margin-top:3px}
.mc-d{border:0;border-radius:16px;padding:0;max-width:620px;width:94vw}.mc-d::backdrop{background:rgba(12,15,19,.55)}.mc-f{padding:22px}.mc-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.mc-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.mc-x{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:640px){.mc{padding:16px}.mc-kp{grid-template-columns:1fr 1fr}.mc-2,.mc-3{grid-template-columns:1fr}}
</style>
<script>
(function(){var R=document.getElementById('dv-mc');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S={c:[],g:[]},aba='c',CAND=[],WA='https://wa.me/5511993500950?text=';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?String(d).slice(0,10).split('-').reverse().join('/'):'—'}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):''}
function dias(d){if(!d)return null;var a=new Date(d+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);return Math.round((a-h)/864e5)}
function cls(n,u,m){return n==null?'':n<0?'u':n<=u?'u':n<=m?'m':'ok'}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function ed(u){var m=/\/editais\/(\d{14})\/(\d{4})\/(\d+)/.exec(u||'');return m?m[1]+'/'+m[2]+'/'+m[3]:''}
function gsOf(c){return S.g.filter(function(g){return g.cid===c.id})}
function kp(){var v90=S.c.filter(function(c){var n=dias(c.fim);return n!=null&&n>=0&&n<=90}).length,g30=S.g.filter(function(g){var n=dias(g.venc);return n!=null&&n>=0&&n<=30}).length,sub=S.c.filter(function(c){return c.busca&&c.busca.sub&&c.busca.sub.length}).length;
 R.querySelector('.mc-kp').innerHTML='<div><b>'+S.c.length+'</b><span>contratos acompanhados</span></div><div><b style="color:'+(v90?'#B04A3A':'inherit')+'">'+v90+'</b><span>vencem em até 90 dias</span></div><div><b style="color:'+(g30?'#B04A3A':'inherit')+'">'+g30+'</b><span>garantias vencem em até 30 dias</span></div><div><b style="color:'+(sub?'#9A6B12':'inherit')+'">'+sub+'</b><span>com licitação de substituição encontrada</span></div>'}
function pintar(){kp();R.querySelectorAll('.mc-tabs span').forEach(function(s){s.classList.toggle('on',s.dataset.t===aba)});var L=R.querySelector('.mc-l');
 if(aba==='c'){var l=S.c.slice().sort(function(a,b){return a.fim<b.fim?-1:1});L.innerHTML=l.length?l.map(function(c){var n=dias(c.fim),gs=gsOf(c),b=c.busca||{},al='';
   gs.forEach(function(g){if(g.venc&&g.venc<c.fim)al+='<div class="mc-al r">⚠️ A garantia'+(g.apolice?' nº '+e(g.apolice):'')+' vence em '+br(g.venc)+', antes do fim do contrato. <a href="'+WA+encodeURIComponent('Olá! Preciso endossar/renovar o seguro garantia do contrato com '+c.orgao+' (vence em '+br(g.venc)+').')+'" target="_blank" rel="noopener">Renovar ou endossar a apólice →</a></div>'});
   if(!gs.length)al+='<div class="mc-al">🛡️ Nenhuma garantia cadastrada para este contrato. <button type="button" class="g" data-gnova="'+c.id+'">Cadastrar garantia</button></div>';
   (b.sub||[]).forEach(function(s){al+='<div class="mc-al">🔔 <b>Licitação no mesmo órgão, parecida com este contrato:</b> '+e(s.t.slice(0,160))+' · publicada em '+br(s.pub)+(s.fim?' · propostas até '+br(s.fim):'')+' <a href="https://pncp.gov.br/app'+e(s.url)+'" target="_blank" rel="noopener">PNCP ↗</a>'+(ed(s.url)?' · <a href="/plataforma/licitacoes/?importar='+ed(s.url)+'">Levar para a Gestão e conferir o edital →</a>':'')+'</div>'});
   if((b.prox||[]).length)al+='<div class="mc-al" style="background:#F4F1EA;border-color:#E3DED2">📍 Mesmo objeto, outros órgãos em '+e(c.uf)+': '+b.prox.slice(0,3).map(function(s){return'<a href="https://pncp.gov.br/app'+e(s.url)+'" target="_blank" rel="noopener">'+e(s.org)+' ('+e(s.mu)+')</a>'}).join(' · ')+'</div>';
   return'<div class="mc-i"><div class="d"><div><div class="o">'+e(c.orgao)+'</div><div class="s">'+e([c.mu,c.uf].filter(Boolean).join('/'))+(c.numero?' · contrato '+e(c.numero):'')+(c.valor?' · '+moeda(c.valor):'')+' · vigência '+br(c.ini)+' a '+br(c.fim)+'</div></div><div class="mc-v '+cls(n,90,180)+'">'+(n==null?'':n<0?'Vencido há '+(-n)+' dias':'Vence em '+n+' dias')+'<div class="s">'+br(c.fim)+'</div></div></div>'+
    '<div class="s" style="margin-top:6px">'+e(c.objeto)+'</div>'+(c.notas?'<div class="s">📝 '+e(c.notas)+'</div>':'')+al+
    '<div class="a"><button type="button" class="p" data-ver="'+c.id+'">'+(b.em?'Procurar licitação de novo':'Procurar licitação de substituição')+'</button>'+(c.oc?'<a href="/plataforma/raio-x-comprador/?cnpj='+c.oc+'" target="_blank" rel="noopener">Raio-X do órgão</a><a href="/plataforma/contratos-vencendo/?orgao='+c.oc+'">Outros contratos deste órgão vencendo</a>':'')+'<a href="/plataforma/contratos-vencendo/?q='+encodeURIComponent((c.objeto||'').split(/\s+/).slice(0,4).join(' '))+(c.uf?'&uf='+c.uf:'')+'">Mesmo objeto vencendo na região</a>'+(c.pncp?'<a href="https://pncp.gov.br/app'+e(c.pncp)+'" target="_blank" rel="noopener">PNCP ↗</a>':'')+'<button type="button" data-ed="'+c.id+'">Editar</button><button type="button" data-ex="'+c.id+'">Excluir</button></div>'+(b.em?'<div class="s" style="font-size:.76rem;margin-top:6px">Última busca no PNCP: '+br(b.em)+'</div>':'')+'</div>'}).join(''):'<p class="mc-h">Nenhum contrato ainda. Use “Importar meus contratos do PNCP” ou cadastre manualmente.</p>'}
 else{var g=S.g.slice().sort(function(a,b){return a.venc<b.venc?-1:1});L.innerHTML=g.length?g.map(function(x){var n=dias(x.venc),c=S.c.filter(function(y){return y.id===x.cid})[0],T={seguro:'Seguro-garantia',fianca:'Fiança bancária',caucao:'Caução',titulos:'Títulos públicos'},M={execucao:'execução do contrato',proposta:'garantia de proposta',adicional:'garantia adicional',judicial:'judicial',outra:'outra'};
   return'<div class="mc-i"><div class="d"><div><div class="o">'+e(T[x.tipo]||'Garantia')+' · '+e(M[x.mod]||'')+'</div><div class="s">'+e(c?c.orgao:(x.orgao||'Sem contrato vinculado'))+(x.seguradora?' · '+e(x.seguradora):'')+(x.apolice?' · nº '+e(x.apolice):'')+(x.valor?' · '+moeda(x.valor):'')+'</div></div><div class="mc-v '+cls(n,30,60)+'">'+(n==null?'':n<0?'Vencida há '+(-n)+' dias':'Vence em '+n+' dias')+'<div class="s">'+br(x.venc)+'</div></div></div>'+
    (c&&x.venc<c.fim?'<div class="mc-al r">⚠️ Vence antes do fim do contrato ('+br(c.fim)+'). Renove ou endosse a apólice para não ficar descoberto.</div>':'')+(x.notas?'<div class="s">📝 '+e(x.notas)+'</div>':'')+
    '<div class="a"><a class="p" href="'+WA+encodeURIComponent('Olá! Quero cotar a renovação do '+(T[x.tipo]||'seguro garantia')+(x.apolice?' nº '+x.apolice:'')+' de '+(c?c.orgao:(x.orgao||''))+', que vence em '+br(x.venc)+'.')+'" target="_blank" rel="noopener">Renovar / cotar com a Desenvolve</a><button type="button" data-ged="'+x.id+'">Editar</button><button type="button" data-gex="'+x.id+'">Excluir</button></div></div>'}).join(''):'<p class="mc-h">Nenhuma garantia cadastrada. Cadastre as apólices e cartas de fiança para receber os avisos antes de vencer.</p>'}}
function carregar(){return req('GET','mc').then(function(j){S=j;pintar()}).catch(function(){R.querySelector('.mc-l').innerHTML='<p class="mc-h">Não foi possível carregar agora.</p>'})}
function abrir(k,x){var D=R.querySelector(k==='c'?'.mc-dc':'.mc-dg'),F=D.querySelector('form');F.reset();F.querySelector('.mc-fm').textContent='';if(k==='g'){F.querySelector('[name=cid]').innerHTML='<option value="">— sem contrato vinculado —</option>'+S.c.map(function(c){return'<option value="'+c.id+'">'+e(c.orgao.slice(0,50))+' · até '+br(c.fim)+'</option>'}).join('')}
 if(x)Object.keys(x).forEach(function(n){var i=F.querySelector('[name='+(n==='tipo'&&k==='g'?'gtipo':n)+']');if(i&&typeof x[n]!=='object')i.value=x[n]});D.showModal()}
R.addEventListener('click',function(ev){var t=ev.target.closest('button,span');if(!t)return;
 if(t.dataset.t){aba=t.dataset.t;pintar();return}
 if(t.dataset.novo){abrir(t.dataset.novo,null);return}
 if(t.dataset.gnova){abrir('g',{cid:t.dataset.gnova});return}
 if(t.dataset.ed){abrir('c',S.c.filter(function(c){return c.id===t.dataset.ed})[0]);return}
 if(t.dataset.ged){abrir('g',S.g.filter(function(g){return g.id===t.dataset.ged})[0]);return}
 if(t.dataset.ex||t.dataset.gex){if(!confirm('Excluir?'))return;req('POST','mc/excluir',{tipo:t.dataset.gex?'g':'c',id:t.dataset.ex||t.dataset.gex}).then(function(j){S=j;pintar()});return}
 if(t.dataset.ver){t.disabled=true;t.textContent='Procurando no PNCP…';req('POST','mc/verificar',{id:t.dataset.ver}).then(function(j){S=j;pintar()}).catch(function(j){t.disabled=false;t.textContent=(j&&j.message)||'Tente de novo'});return}
 if(t.classList.contains('mc-ok')){var k=t.dataset.k,F=t.closest('form'),o={tipo:k};new FormData(F).forEach(function(v,n){o[n]=v});o.valor=String(o.valor||'').replace(/\./g,'').replace(',','.');F.querySelector('.mc-fm').textContent='Salvando…';req('POST','mc/salvar',o).then(function(j){S=j;t.closest('dialog').close();aba=k;pintar()}).catch(function(j){F.querySelector('.mc-fm').textContent=(j&&j.message)||'Não foi possível salvar.'});return}
 if(t.classList.contains('mc-imp')){var I=R.querySelector('.mc-im'),cn=S.doc&&S.doc.length===14?S.doc:prompt('CNPJ da empresa (14 números):');if(!cn)return;I.innerHTML='<p class="mc-h">Buscando no PNCP os contratos vigentes do CNPJ…</p>';req('GET','mc/importar?cnpj='+cn.replace(/\D/g,'')).then(function(j){var ja={};S.c.forEach(function(c){if(c.pncp)ja[c.pncp]=1});CAND=j.itens.filter(function(x){return!ja[x.pncp]});
  I.innerHTML=CAND.length?'<div class="mc-i"><b>'+CAND.length+' contrato(s) vigente(s) encontrado(s) no PNCP</b><div class="s">Marque os que quer acompanhar.</div>'+CAND.map(function(x,i){return'<label><input type="checkbox" data-c="'+i+'" checked><span><b>'+e(x.orgao)+'</b> · '+e(x.mu+'/'+x.uf)+' · até '+br(x.fim)+(x.valor?' · '+moeda(x.valor):'')+'<br><span class="s">'+e(x.objeto.slice(0,150))+'</span></span></label>'}).join('')+'<div class="a"><button type="button" class="p mc-add">Acompanhar os marcados</button><button type="button" class="g" data-fim>Fechar</button></div></div>':'<p class="mc-h">Nenhum contrato vigente novo encontrado no PNCP para o CNPJ '+e(j.cnpj)+'. Você pode cadastrar manualmente.</p>'}).catch(function(j){I.innerHTML='<p class="mc-h">'+e((j&&j.message)||'O PNCP não respondeu agora. Tente de novo.')+'</p>'});return}
 if(t.dataset.fim!=null){R.querySelector('.mc-im').innerHTML='';return}
 if(t.classList.contains('mc-add')){var sel=[].slice.call(R.querySelectorAll('.mc-im [data-c]:checked')).map(function(i){return CAND[+i.dataset.c]});t.disabled=true;t.textContent='Salvando…';var q=Promise.resolve();sel.forEach(function(x){q=q.then(function(){return req('POST','mc/salvar',Object.assign({tipo:'c'},x)).then(function(j){S=j})})});q.then(function(){R.querySelector('.mc-im').innerHTML='<p class="mc-h">'+sel.length+' contrato(s) adicionados. Cadastre as garantias de cada um para receber os avisos.</p>';aba='c';pintar()});return}});
carregar();
})();
</script>
	<?php return ob_get_clean();
} );
