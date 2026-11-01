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
/** A busca do PNCP traz qualquer edital com UMA das palavras ("limpeza predial" trazia "manutenção predial"); aqui exigimos todas as palavras do termo */
function dv_al_relevante( $termo, $nd ) {
	$stop = array( 'DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM', 'PARA', 'COM', 'A', 'O', 'AS', 'OS', 'NA', 'NO', 'POR' );
	foreach ( preg_split( '/[^A-Z0-9]+/', dv_al_norm( $termo ) ) as $w ) {
		if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
		$raiz = strlen( $w ) > 6 ? substr( $w, 0, max( 5, strlen( $w ) - 3 ) ) : rtrim( $w, 'S' );
		if ( strpos( $nd, $raiz ) === false ) { return false; }
	}
	return true;
}
/** Serviço ou compra, pelo texto do objeto (mesma regra do Buscador) */
function dv_al_tipo( $desc ) {
	$n = strtolower( remove_accents( (string) $desc ) );
	$serv = (bool) preg_match( '/servic|prestacao de|contratacao de empresa|locacao|manutencao|obra|engenharia|terceiriz|mao de obra|limpeza e conservacao|vigilancia|seguranca patrimonial|transporte de|fornecimento de alimentacao|refeic|coleta de/', $n );
	$comp = (bool) preg_match( '/^(aquisicao|compra|fornecimento|registro de precos para (a )?(eventual |futura )*(aquisicao|compra|fornecimento))|aquisicao de|materia(l|is) (de|para|medic|hospitalar|permanente|consumo)|equipamentos? |generos|medicament|insumos/', $n );
	if ( $serv && ! preg_match( '/^(aquisicao|compra)/', $n ) ) { return 'servico'; }
	if ( $comp ) { return 'compra'; }
	return $serv ? 'servico' : 'compra';
}
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
			if ( ! dv_al_relevante( $termo, $nd ) ) { continue; }
			$pula = false; foreach ( $excl as $x ) { if ( $x !== '' && strpos( $nd, $x ) !== false ) { $pula = true; } } if ( $pula ) { continue; }
			if ( ! empty( $a['tipo'] ) && dv_al_tipo( $desc ) !== $a['tipo'] ) { continue; }
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
		$cx = array_values( dv_al_caixa( $uid ) ); usort( $cx, function ( $a, $b ) { return strcmp( $b['achado'] ?? '', $a['achado'] ?? '' ) ?: strcmp( $b['publicado'] ?? '', $a['publicado'] ?? '' ); } );
		return array( 'alertas' => dv_al_lista( $uid ), 'max' => DV_AL_MAX, 'pref' => function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array(), 'caixa' => $cx );
	} ) );
	register_rest_route( $ns, '/alertas/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_al_lista( $uid ); $id = sanitize_key( (string) $r['id'] );
		$termos = mb_substr( sanitize_textarea_field( (string) $r['termos'] ), 0, 400 );
		if ( mb_strlen( trim( $termos ) ) < 3 ) { return new WP_Error( 'dv_al', 'Informe pelo menos uma palavra ou expressão.', array( 'status' => 400 ) ); }
		$ufs = array_values( array_intersect( array_map( 'strtoupper', (array) $r['ufs'] ), array( 'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO' ) ) );
		$a = array( 'nome' => mb_substr( sanitize_text_field( (string) $r['nome'] ), 0, 80 ) ?: mb_substr( $termos, 0, 40 ), 'termos' => $termos, 'excluir' => mb_substr( sanitize_textarea_field( (string) $r['excluir'] ), 0, 300 ),
			'ufs' => $ufs, 'vmin' => max( 0, (float) $r['vmin'] ), 'vmax' => max( 0, (float) $r['vmax'] ), 'tipo' => in_array( $r['tipo'], array( 'servico', 'compra' ), true ) ? $r['tipo'] : '', 'ativo' => ( isset( $r['ativo'] ) && ! $r['ativo'] ) ? 0 : 1 );
		$achou = false; foreach ( $l as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $a ); $achou = true; } } unset( $x );
		if ( ! $achou ) { if ( count( $l ) >= DV_AL_MAX ) { return new WP_Error( 'dv_al', 'Limite de ' . DV_AL_MAX . ' alertas.', array( 'status' => 400 ) ); } $id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $a + array( 'id' => $id, 'criado' => current_time( 'Y-m-d' ) ); }
		dv_al_salvar_lista( $uid, $l ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/alertas/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] );
		dv_al_salvar_lista( $uid, array_filter( dv_al_lista( $uid ), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ); return array( 'ok' => true );
	} ) );
	register_rest_route( $ns, '/alertas/caixa', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $c = dv_al_caixa( $uid ); $ref = preg_replace( '/[^0-9\/]/', '', (string) $r['ref'] );
		if ( ! isset( $c[ $ref ] ) ) { return new WP_Error( 'dv_al', 'Oportunidade não encontrada.', array( 'status' => 404 ) ); }
		$c[ $ref ]['st'] = in_array( $r['st'], array( '', 'quero', 'desc' ), true ) ? $r['st'] : ''; if ( $r['lic'] ) { $c[ $ref ]['lic'] = sanitize_key( (string) $r['lic'] ); }
		dv_al_caixa_salvar( $uid, $c ); return array( 'ok' => true );
	} ) );
	register_rest_route( $ns, '/alertas/atualizar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $n = 0; foreach ( dv_al_rodar( $uid, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -15 days' ) ) ) as $b ) { $n += count( $b['itens'] ); }
		return array( 'ok' => true, 'novas' => $n );
	} ) );
	/* Prévia: o que o alerta encontra nas licitações abertas publicadas nos últimos 15 dias */
	register_rest_route( $ns, '/alertas/testar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$a = array( 'termos' => (string) $r['termos'], 'excluir' => (string) $r['excluir'], 'ufs' => (array) $r['ufs'], 'vmin' => (float) $r['vmin'], 'vmax' => (float) $r['vmax'], 'tipo' => in_array( $r['tipo'], array( 'servico', 'compra' ), true ) ? $r['tipo'] : '' );
		$it = dv_al_buscar( $a, gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -15 days' ) ) );
		return array( 'itens' => array_slice( $it, 0, 80 ), 'total' => count( $it ) );
	} ) );
} );

/* E-mail diário (por volta das 7h40) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_al_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:40 UTC' ), 'daily', 'dv_al_diario' ); } } );
/* Caixa de oportunidades: tudo o que os alertas encontram fica guardado para o cliente marcar "quero" ou "descartar" */
function dv_al_caixa( $uid ) { $c = json_decode( get_user_meta( $uid, 'dv_al_caixa', true ) ?: '{}', true ); return is_array( $c ) ? $c : array(); }
function dv_al_caixa_salvar( $uid, $c ) {
	$hoje = current_time( 'Y-m-d' ); $lim = gmdate( 'Y-m-d', strtotime( $hoje . ' -30 days' ) );
	$c = array_filter( $c, function ( $x ) use ( $hoje, $lim ) { if ( ( $x['st'] ?? '' ) === 'quero' ) { return substr( (string) ( $x['achado'] ?? $hoje ), 0, 10 ) >= $lim; } return ( ! $x['encerra'] || substr( $x['encerra'], 0, 10 ) >= $hoje ) && substr( (string) ( $x['achado'] ?? $hoje ), 0, 10 ) >= $lim; } );
	if ( count( $c ) > 400 ) { uasort( $c, function ( $a, $b ) { return strcmp( $b['achado'] ?? '', $a['achado'] ?? '' ); } ); $c = array_slice( $c, 0, 400, true ); }
	update_user_meta( $uid, 'dv_al_caixa', wp_slash( wp_json_encode( $c, JSON_UNESCAPED_UNICODE ) ) );
}
/** Roda os alertas do usuário: devolve os blocos de novidades (ainda não vistas) e guarda tudo na caixa */
function dv_al_rodar( $uid, $desde ) {
	$vistos = json_decode( get_user_meta( $uid, 'dv_alertas_vistos', true ) ?: '[]', true ) ?: array(); $set = array_flip( $vistos ); $bloco = array(); $cx = dv_al_caixa( $uid ); $agora = current_time( 'mysql' );
	foreach ( dv_al_lista( $uid ) as $a ) {
		if ( empty( $a['ativo'] ) ) { continue; }
		$novos = array_values( array_filter( dv_al_buscar( $a, $desde ), function ( $x ) use ( &$set ) { if ( isset( $set[ $x['ref'] ] ) ) { return false; } $set[ $x['ref'] ] = 1; return true; } ) );
		foreach ( $novos as $x ) { if ( ! isset( $cx[ $x['ref'] ] ) ) { $cx[ $x['ref'] ] = array_merge( $x, array( 'alerta' => $a['nome'], 'achado' => $agora, 'st' => '' ) ); } }
		if ( $novos ) { $bloco[] = array( 'a' => $a, 'itens' => array_slice( $novos, 0, 40 ) ); }
	}
	update_user_meta( $uid, 'dv_alertas_vistos', wp_slash( wp_json_encode( array_slice( array_keys( $set ), -4000 ) ) ) );
	dv_al_caixa_salvar( $uid, $cx );
	return $bloco;
}
function dv_al_urgente( $x ) { $d = $x['encerra'] ? (int) floor( ( strtotime( substr( $x['encerra'], 0, 10 ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 99; return $d <= 5 || stripos( (string) $x['modalidade'], 'dispensa' ) !== false; }
add_action( 'dv_al_diario', function () {
	global $wpdb; @set_time_limit( 600 );
	$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_alertas' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; }
		$bloco = dv_al_rodar( $uid, $desde ); if ( $bloco ) { dv_al_email( $uid, $bloco ); }
	}
} );
/* Rodadas extras às 12h40 e 17h40: novidades vão para a caixa; só manda e-mail do que é urgente (dispensa ou prazo de até 5 dias) */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'dv_al_extra', array( 1 ) ) ) { wp_schedule_event( strtotime( 'tomorrow 15:40 UTC' ), 'daily', 'dv_al_extra', array( 1 ) ); }
	if ( ! wp_next_scheduled( 'dv_al_extra', array( 2 ) ) ) { wp_schedule_event( strtotime( 'tomorrow 20:40 UTC' ), 'daily', 'dv_al_extra', array( 2 ) ); }
} );
add_action( 'dv_al_extra', function () {
	global $wpdb; @set_time_limit( 600 );
	$desde = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 days' ) );
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_alertas' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; if ( ! dv_al_ativo( $uid ) ) { continue; }
		$bloco = dv_al_rodar( $uid, $desde ); $urg = array();
		foreach ( $bloco as $b ) { $it = array_values( array_filter( $b['itens'], 'dv_al_urgente' ) ); if ( $it ) { $urg[] = array( 'a' => $b['a'], 'itens' => $it ); } }
		if ( $urg ) { dv_al_email( $uid, $urg, true ); }
	}
}, 10, 1 );
function dv_al_email( $uid, $bloco, $urgente = false ) {
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
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, ( $urgente ? '⚡ Prazo curto: ' : '' ) . $n . ' licitação(ões) nova(s) para você · Desenvolve', $h, $hd ); } }
}

add_shortcode( 'dv_alertas', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="al" id="dv-al" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="al-cx"><div class="al-cxh"><h3>📥 Caixa de oportunidades</h3><button type="button" class="g al-atu">Atualizar agora</button></div><p class="al-h">Tudo o que os seus alertas encontram cai aqui. Marque <b>Quero</b> (vai para a sua Gestão de licitações) ou <b>Descartar</b>. Os alertas rodam às 7h40, 12h40 e 17h40.</p><div class="al-tabs"></div><div class="al-cxl"></div></div>
	<form class="al-f" autocomplete="off">
		<h3 class="al-ft">Novo alerta</h3>
		<label>O que você vende? <small>Palavras ou expressões separadas por vírgula. Cada uma vira uma busca.</small><input name="termos" placeholder="limpeza predial, conservação, serviços de higienização"></label>
		<div class="al-2"><label>Tipo<select name="tipo"><option value="">Serviços e compras</option><option value="servico">Só serviços</option><option value="compra">Só compras</option></select></label><label>Nome do alerta<input name="nome" placeholder="Ex.: Limpeza em SP"></label></div>
		<div class="al-2" style="grid-template-columns:1fr"><label>Excluir quando aparecer<input name="excluir" placeholder="Ex.: veículos, piscina"></label></div>
		<label>Estados <small>(nenhum marcado = Brasil todo)</small><div class="al-ufs"></div></label>
		<div class="al-2"><label>Valor mínimo (R$)<input name="vmin" inputmode="decimal" placeholder="opcional"></label><label>Valor máximo (R$)<input name="vmax" inputmode="decimal" placeholder="opcional"></label></div>
		<input type="hidden" name="id">
		<div class="al-act"><button type="button" class="g al-test">Ver o que ele encontra</button><button type="submit">Salvar alerta</button><button type="button" class="g al-novo" hidden>Cancelar edição</button></div>
		<p class="al-m al-h">Todo dia, por volta das 7h40, você recebe por e-mail as licitações abertas publicadas no PNCP desde a véspera, e elas entram na Caixa de oportunidades. Às 12h40 e às 17h40 o sistema confere de novo: o que for novo entra na caixa, e dispensas ou editais com prazo de até 5 dias chegam também por e-mail na hora. Os e-mails vão para os mesmos endereços dos avisos de prazo.</p>
	</form>
	<h3>Meus alertas</h3><div class="al-lista"><p class="al-h">Carregando…</p></div>
	<div class="al-res"></div>
</div>
<style>
.al{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.al h3{font-size:1.05rem;margin:22px 0 10px}.al-ft{margin-top:0!important}.al small{color:var(--mu);font-weight:500}
.al button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.al button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:9px 14px}
.al select{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.al input{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.al-f{border:1px solid var(--ln);border-radius:16px;padding:18px 20px;background:#fff;display:grid;gap:12px}.al-f label{font-weight:600;font-size:.88rem;display:grid;gap:5px}
.al-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.al-ufs{display:flex;flex-wrap:wrap;gap:5px}.al-ufs span{border:1px solid var(--ln);border-radius:8px;padding:5px 8px;font-size:.8rem;cursor:pointer;font-weight:600;background:#fff}.al-ufs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.al-act{display:flex;gap:8px;flex-wrap:wrap}.al-h{color:var(--mu);font-size:.86rem;margin:0}.al-m.e{color:var(--rd)}.al-m.o{color:var(--gr);font-weight:600}
.al-a{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.al-a b{display:block}.al-a span{color:var(--mu);font-size:.82rem}.al-a .x{display:flex;gap:6px}
.al-i{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;background:#fff;margin-bottom:8px}.al-i .m{color:var(--mu);font-size:.8rem}.al-i .m b{color:#16191E}.al-i p{margin:6px 0;font-size:.92rem;line-height:1.45}.al-i .k{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.al-i a{color:#8A6A2C;font-weight:700;font-size:.85rem}.al-i button{font-size:.8rem;padding:6px 12px}
.al-tag{display:inline-block;background:#FFF4DC;color:#9A6B12;border-radius:99px;padding:1px 8px;font-size:.72rem;font-weight:700;margin-left:6px}
.al-cx{border:1px solid var(--ln);border-radius:16px;padding:16px 20px;background:#FAF8F3;margin-bottom:22px}.al-cxh{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.al-cxh h3{margin:0!important}
.al-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 8px}.al-tabs span{border:1px solid var(--ln);border-radius:20px;padding:6px 12px;font-size:.85rem;cursor:pointer;font-weight:600;background:#fff}.al-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.al-ch{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.al-ch span{font-size:.76rem;font-weight:700;border-radius:8px;padding:3px 8px;background:#F3F0E8}.al-ch .r{background:#FDEDEA;color:#9A3524}.al-ch .a{background:#FBF1DC;color:#7A5410}.al-ch .v{background:#EAF5EE;color:#1F5A3B}
.al-ba{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.al-ba a,.al-ba button{font-size:.8rem!important;font-weight:600!important;border:1px solid var(--ln)!important;border-radius:9px!important;padding:7px 11px!important;text-decoration:none;color:#16191E!important;background:#fff!important;cursor:pointer}.al-ba .p{background:#C9A45C!important;border-color:#C9A45C!important;color:#241A06!important}.al-ba .ok{background:#EAF5EE!important;color:#1F5A3B!important}.al-ba .x{margin-left:auto;color:#6E6A60!important}
@media(max-width:700px){.al{padding:16px}.al-2{grid-template-columns:1fr}.al-ba .x{margin-left:0}}
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
var CX=[],cab='',GEST={};
function dias(d){if(!d)return null;var a=new Date(String(d).slice(0,10)+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);return Math.round((a-h)/864e5)}
function brh(d){return d?br(d)+(String(d).length>15&&String(d).slice(11,16)!=='00:00'?' às '+String(d).slice(11,16):''):''}
function caixa(){var n={'':0,quero:0,desc:0};CX.forEach(function(x){n[x.st||'']++});var T=R.querySelector('.al-tabs');T.innerHTML=[['','Novas'],['quero','Quero'],['desc','Descartadas']].map(function(t){return'<span data-cx="'+t[0]+'" class="'+(cab===t[0]?'on':'')+'">'+t[1]+' ('+n[t[0]]+')</span>'}).join('');
 var l=CX.filter(function(x){return(x.st||'')===cab});var L=R.querySelector('.al-cxl');
 L.innerHTML=l.length?l.map(function(x){var d=dias(x.encerra),cl=d===null?'':d<=3?'r':d<=7?'a':'',c=x.ref.split('/'),g=x.lic||GEST[x.ref];return'<div class="al-i" data-r="'+e(x.ref)+'"><div class="m"><b>'+e(x.orgao)+'</b> · '+e(x.municipio+'/'+x.uf)+' · '+e(x.modalidade)+' · alerta “'+e(x.alerta||'')+'”</div><p>'+e(x.objeto)+'</p>'+
 '<div class="al-ch">'+(d!==null?'<span class="'+cl+'">'+(d>1?'⏳ faltam '+d+' dias':d===1?'⏳ termina amanhã':'⏳ termina hoje')+'</span><span>Sessão '+brh(x.encerra)+'</span>':'')+'<span class="al-val">'+(x.valor?moeda(x.valor):'Valor estimado: …')+'</span>'+'<span>Publicado '+br(x.publicado)+'</span></div>'+
 '<div class="al-ba">'+(g?'<a class="ok" href="/plataforma/licitacoes/?ver='+g+'">✓ Na sua Gestão · abrir</a>':'<button type="button" class="p" data-q>Quero · levar para a Gestão</button>')+'<button type="button" data-an>Analisar edital</button><a href="/plataforma/raio-x-comprador/?cnpj='+c[0]+'" target="_blank" rel="noopener">Raio-X do órgão · '+((window.DVC&&DVC.comprador)||5)+' créditos</a><a href="'+e(x.link)+'" target="_blank" rel="noopener">Edital no PNCP</a>'+(cab==='desc'?'<button type="button" class="x" data-st="">Restaurar</button>':'<button type="button" class="x" data-st="desc">Descartar</button>')+'</div></div>'}).join(''):'<p class="al-h">'+(cab===''?(S&&S.alertas.length?'Nenhuma oportunidade nova agora. Clique em “Atualizar agora” ou espere a próxima rodada.':'Crie um alerta abaixo: o que ele encontrar cai aqui.'):'Nada por aqui.')+'</p>';valores()}
var VAL={};function valores(){[].slice.call(R.querySelectorAll('.al-cxl .al-i')).slice(0,30).forEach(function(c){var r=c.dataset.r,sp=c.querySelector('.al-val');if(!sp||VAL[r]===null)return;var put=function(v){if(!v){sp.remove();return}sp.className='al-val v';sp.textContent=v.sig?'Valor estimado: sigiloso':v.v?'Valor estimado: '+moeda(v.v):'Valor estimado: não informado'};if(VAL[r])return put(VAL[r]);var k=r.split('/');fetch('https://pncp.gov.br/api/consulta/v1/orgaos/'+k[0]+'/compras/'+k[1]+'/'+k[2]).then(function(x){return x.json()}).then(function(j){VAL[r]={v:j.valorTotalEstimado,sig:j.orcamentoSigilosoCodigo&&j.orcamentoSigilosoCodigo!=1};put(VAL[r])}).catch(function(){VAL[r]=null;sp.remove()})})}
function marca(ref,st,lic){var x=CX.filter(function(y){return y.ref===ref})[0];if(x){x.st=st;if(lic)x.lic=lic}caixa();return req('POST','alertas/caixa',{ref:ref,st:st,lic:lic||''})}
function gestao(x,b,depois){b.disabled=true;var t0=b.textContent;b.textContent='Salvando…';req('GET','lic/pncp?ref='+encodeURIComponent(x.ref)).then(function(j){j.status='analise';return req('POST','lic/salvar',j)}).then(function(r){GEST[x.ref]=r.id;marca(x.ref,'quero',r.id);if(depois)depois(r.id)}).catch(function(j){b.disabled=false;b.textContent=t0;alert((j&&j.message)||'Não foi possível salvar agora.')})}
R.querySelector('.al-cx').addEventListener('click',function(ev){var t=ev.target;
 if(t.dataset.cx!=null){cab=t.dataset.cx;caixa();return}
 if(t.matches('.al-atu')){t.disabled=true;t.textContent='Buscando…';req('POST','alertas/atualizar').then(function(j){t.textContent=j.novas?j.novas+' nova(s)':'Nada novo';return carregar()}).catch(function(){t.textContent='Tente de novo'}).then(function(){setTimeout(function(){t.disabled=false;t.textContent='Atualizar agora'},2500)});return}
 var c=t.closest('.al-i');if(!c)return;var x=CX.filter(function(y){return y.ref===c.dataset.r})[0];if(!x)return;
 if(t.hasAttribute('data-q'))gestao(x,t);
 else if(t.hasAttribute('data-an')){var go=function(id){location.href='/plataforma/licitacoes/?ver='+id+'&analisar=1'};var g=x.lic||GEST[x.ref];if(g)go(g);else gestao(x,t,go)}
 else if(t.dataset.st!=null)marca(x.ref,t.dataset.st)});
req('GET','lic').then(function(j){(j.itens||[]).forEach(function(y){if(y.pncp)GEST[y.pncp]=y.id});caixa()}).catch(function(){});
function carregar(){return req('GET','alertas').then(function(j){S=j;CX=j.caixa||[];caixa();R.querySelector('.al-lista').innerHTML=j.alertas.length?j.alertas.map(function(a){return'<div class="al-a"><div><b>'+e(a.nome)+(a.ativo?'':' <span class="al-tag">pausado</span>')+'</b><span>'+e(a.termos)+' · '+(a.ufs&&a.ufs.length?a.ufs.join(', '):'Brasil todo')+(a.vmin?' · a partir de '+moeda(a.vmin):'')+(a.vmax?' · até '+moeda(a.vmax):'')+(a.excluir?' · sem: '+e(a.excluir):'')+(a.tipo?' · só '+(a.tipo==='servico'?'serviços':'compras'):'')+'</span></div><div class="x"><button class="g" data-v="'+a.id+'">Ver agora</button><button class="g" data-ed="'+a.id+'">Editar</button><button class="g" data-p="'+a.id+'">'+(a.ativo?'Pausar':'Ativar')+'</button><button class="g" data-x="'+a.id+'">Excluir</button></div></div>'}).join(''):'<p class="al-h">Nenhum alerta ainda. Crie o primeiro acima: em segundos você vê quantas licitações abertas ele encontra.</p>'})}
function mostrar(j,titulo){var Rr=R.querySelector('.al-res');Rr.innerHTML='<h3>'+e(titulo)+': '+j.total+' licitação(ões) aberta(s) nos últimos 15 dias</h3>'+(j.itens.length?j.itens.map(function(x){return'<div class="al-i"><div class="m"><b>'+e(x.orgao)+'</b> · '+e(x.municipio+'/'+x.uf)+' · '+e(x.modalidade)+' · publicado em '+br(x.publicado)+(x.valor?' · <b>'+moeda(x.valor)+'</b>':'')+'</div><p>'+e(x.objeto)+'</p><div class="k"><a href="'+e(x.link)+'" target="_blank" rel="noopener">Ver no PNCP</a><button data-imp="'+e(x.ref)+'">Levar para a minha gestão</button>'+x.termos.map(function(t){return'<span class="al-tag">'+e(t)+'</span>'}).join('')+'</div></div>'}).join(''):'<p class="al-h">Nada encontrado. Tente palavras mais gerais ou mais estados.</p>');Rr.scrollIntoView({behavior:'smooth'})}
function testar(o,t){M.className='al-m al-h';M.textContent='Buscando no PNCP…';return req('POST','alertas/testar',o).then(function(j){M.textContent='';mostrar(j,t)}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'O PNCP não respondeu agora. Tente de novo.'})}
F.addEventListener('submit',function(ev){ev.preventDefault();M.className='al-m al-h';M.textContent='Salvando…';req('POST','alertas/salvar',dados()).then(function(){M.className='al-m al-h o';M.textContent='Alerta salvo. Amanhã cedo chega o primeiro e-mail.';limpar();carregar()}).catch(function(j){M.className='al-m al-h e';M.textContent=(j&&j.message)||'Erro ao salvar.'})});
R.addEventListener('click',function(ev){var t=ev.target,a;
 if(t.dataset.uf){ufs[t.dataset.uf]=!ufs[t.dataset.uf];pUfs();return}
 if(t.matches('.al-test')){testar(dados(),'Prévia');return}
 if(t.matches('.al-novo')){limpar();return}
 a=S&&S.alertas.filter(function(x){return x.id===(t.dataset.v||t.dataset.ed||t.dataset.p||t.dataset.x)})[0];
 if(t.dataset.v&&a){testar(a,a.nome);return}
 if(t.dataset.ed&&a){limpar();F.querySelector('[name=tipo]').value=a.tipo||'';['nome','termos','excluir','id'].forEach(function(k){F.querySelector('[name='+k+']').value=a[k]||''});F.querySelector('[name=vmin]').value=a.vmin||'';F.querySelector('[name=vmax]').value=a.vmax||'';(a.ufs||[]).forEach(function(u){ufs[u]=true});pUfs();R.querySelector('.al-ft').textContent='Editar alerta';R.querySelector('.al-novo').hidden=false;F.scrollIntoView({behavior:'smooth'});return}
 if(t.dataset.p&&a){var o=Object.assign({},a,{ativo:a.ativo?0:1});req('POST','alertas/salvar',o).then(carregar);return}
 if(t.dataset.x&&a){if(confirm('Excluir o alerta "'+a.nome+'"?'))req('POST','alertas/excluir',{id:a.id}).then(carregar);return}
 if(t.dataset.imp){t.disabled=true;t.textContent='Importando…';req('GET','lic/pncp?ref='+encodeURIComponent(t.dataset.imp)).then(function(j){return req('POST','lic/salvar',j)}).then(function(){t.textContent='Na sua gestão ✓';var cn=String(t.dataset.imp||'').slice(0,14);if(/^\d{14}$/.test(cn)&&!t.nextElementSibling?.classList?.contains('al-rx')){var a=document.createElement('a');a.className='g al-rx';a.href='/plataforma/raio-x-comprador/?cnpj='+cn;a.textContent='Raio-X do órgão →';a.style.cssText='font-size:.85rem;font-weight:600;color:#16191E;border:1px solid #E3DED2;border-radius:10px;padding:8px 12px;text-decoration:none';t.after(a)}}).catch(function(j){t.disabled=false;t.textContent=(j&&j.message)?'Tentar de novo':'Erro'})}});
(function(){var q=new URLSearchParams(location.search);if(q.get('termos')){F.querySelector('[name=termos]').value=q.get('termos');if(q.get('nome'))F.querySelector('[name=nome]').value=q.get('nome');if(q.get('tipo'))F.querySelector('[name=tipo]').value=q.get('tipo');(q.get('uf')||'').split(',').forEach(function(u){if(u)ufs[u.toUpperCase()]=1});M.className='al-m al-h o';M.textContent='Alerta pré-preenchido com a sua busca. Confira e salve.';history.replaceState(null,'',location.pathname)}})();
pUfs();carregar();
})();
</script>
	<?php return ob_get_clean();
} );

/* Painel: aviso das oportunidades novas na caixa */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! is_page( 'painel' ) ) { return; }
	$n = 0; $urg = 0; foreach ( dv_al_caixa( get_current_user_id() ) as $x ) { if ( ( $x['st'] ?? '' ) === '' ) { $n++; if ( dv_al_urgente( $x ) ) { $urg++; } } }
	if ( ! $n ) { return; } ?>
<script>(function(){var el=document.getElementById('dv-painel');if(!el)return;var b=document.createElement('a');b.href='/plataforma/alertas/';b.style.cssText='display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:#16191E;border:1px solid #C9A45C;border-radius:14px;padding:14px 18px;margin:0 0 18px;color:#F4F2EC;text-decoration:none;font-family:Hanken Grotesk,sans-serif';
b.innerHTML='<span>📥 <b><?php echo (int) $n; ?> oportunidade(s) nova(s)</b> nos seus alertas<?php echo $urg ? ' · <b style="color:#E59A8A">' . (int) $urg . ' com prazo curto</b>' : ''; ?></span><span style="background:#C9A45C;color:#241A06;border-radius:10px;padding:8px 14px;font-weight:700">Ver a caixa</span>';b.style.maxWidth=Math.max(320,el.getBoundingClientRect().width)+'px';b.style.marginLeft='auto';b.style.marginRight='auto';el.parentNode.insertBefore(b,el)})();</script>
	<?php
}, 96 );
