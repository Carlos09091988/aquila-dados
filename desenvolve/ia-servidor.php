<?php
/**
 * Desenvolve — IA no servidor (Groq, compatível com a API da OpenAI)
 * Prioridade das oportunidades (nota 0–10, alta/média/baixa e motivo) pelo perfil da empresa
 * e conversa com a IA sobre cada licitação. Chave e modelos em Desenvolve > IA do servidor.
 * Sem chave (ou com o limite do mês atingido), a prioridade sai por regras e a conversa fica desligada.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_ai_opt( $k ) {
	$d = array( 'base' => 'https://api.groq.com/openai/v1', 'modelo' => 'openai/gpt-oss-20b', 'modelo_chat' => 'openai/gpt-oss-120b', 'lim_mes' => 4000 );
	$v = get_option( 'dv_ai_' . $k, '' );
	return $v === '' || $v === false ? ( $d[ $k ] ?? '' ) : $v;
}
function dv_ai_ligada() { return (string) get_option( 'dv_ai_key', '' ) !== '' && dv_ai_uso() < (int) dv_ai_opt( 'lim_mes' ); }
function dv_ai_uso() { return (int) get_option( 'dv_ai_uso_' . gmdate( 'Ym' ), 0 ); }

/** Chamada à IA. $json = true pede resposta em JSON. Devolve texto ou WP_Error. */
function dv_ai_req( $msgs, $json = false, $chat = false, $max = 1800 ) {
	$key = (string) get_option( 'dv_ai_key', '' );
	if ( $key === '' ) { return new WP_Error( 'dv_ai', 'A IA do servidor não está configurada.' ); }
	if ( dv_ai_uso() >= (int) dv_ai_opt( 'lim_mes' ) ) { return new WP_Error( 'dv_ai', 'Limite mensal de uso da IA atingido.' ); }
	$body = array( 'model' => dv_ai_opt( $chat ? 'modelo_chat' : 'modelo' ), 'messages' => $msgs, 'temperature' => 0.2, 'max_completion_tokens' => $max );
	if ( strpos( $body['model'], 'gpt-oss' ) !== false ) { $body['reasoning_effort'] = 'low'; }
	if ( $json ) { $body['response_format'] = array( 'type' => 'json_object' ); }
	$r = wp_remote_post( rtrim( dv_ai_opt( 'base' ), '/' ) . '/chat/completions', array( 'timeout' => 60, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	update_option( 'dv_ai_uso_' . gmdate( 'Ym' ), dv_ai_uso() + 1, false );
	if ( is_wp_error( $r ) ) { return $r; }
	$j = json_decode( wp_remote_retrieve_body( $r ), true ); $c = wp_remote_retrieve_response_code( $r );
	if ( $c !== 200 ) { update_option( 'dv_ai_erro', current_time( 'mysql' ) . ' · ' . $c . ' · ' . mb_substr( (string) ( $j['error']['message'] ?? wp_remote_retrieve_body( $r ) ), 0, 300 ), false ); return new WP_Error( 'dv_ai', 'A IA não respondeu agora.' ); }
	return (string) ( $j['choices'][0]['message']['content'] ?? '' );
}
function dv_ai_json( $t ) { $j = json_decode( $t, true ); if ( is_array( $j ) ) { return $j; } if ( preg_match( '/\{.*\}/s', (string) $t, $m ) ) { $j = json_decode( $m[0], true ); } return is_array( $j ) ? $j : array(); }

/* ---------- Admin: chave, modelos e uso ---------- */
add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'IA do servidor', 'IA do servidor', 'manage_options', 'dv-ai', function () {
		$msg = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_ai' ) ) {
			$k = trim( (string) wp_unslash( $_POST['key'] ?? '' ) );
			if ( ! empty( $_POST['apagar'] ) ) { delete_option( 'dv_ai_key' ); }
			elseif ( $k !== '' ) { update_option( 'dv_ai_key', sanitize_text_field( $k ), false ); }
			foreach ( array( 'base', 'modelo', 'modelo_chat' ) as $f ) { update_option( 'dv_ai_' . $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ?? '' ) ), false ); }
			update_option( 'dv_ai_lim_mes', max( 0, (int) ( $_POST['lim_mes'] ?? 0 ) ), false );
			$msg = 'Salvo.';
			if ( ! empty( $_POST['testar'] ) ) { $t = dv_ai_req( array( array( 'role' => 'user', 'content' => 'Responda só: OK' ) ), false, false, 200 ); $msg = is_wp_error( $t ) ? 'Teste falhou: ' . $t->get_error_message() . ' ' . esc_html( (string) get_option( 'dv_ai_erro', '' ) ) : 'Teste OK: a IA respondeu "' . esc_html( mb_substr( trim( $t ), 0, 40 ) ) . '".'; }
		}
		$tem = (string) get_option( 'dv_ai_key', '' ) !== '';
		echo '<div class="wrap"><h1>IA do servidor</h1>' . ( $msg ? '<div class="updated"><p>' . esc_html( $msg ) . '</p></div>' : '' );
		echo '<p>Usada para dar prioridade às oportunidades dos alertas e para a conversa sobre cada licitação. Padrão: Groq (barata). Crie a chave em <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a> e cole abaixo. Sem chave, a prioridade sai por regras.</p><form method="post">';
		wp_nonce_field( 'dv_ai' );
		echo '<table class="form-table"><tr><th>Chave da API</th><td><input type="password" name="key" class="regular-text" autocomplete="off" placeholder="' . ( $tem ? '•••••••• (configurada — deixe em branco para manter)' : 'cole a chave aqui' ) . '"> ' . ( $tem ? '<label><input type="checkbox" name="apagar" value="1"> apagar a chave</label>' : '' ) . '</td></tr>';
		echo '<tr><th>Endereço da API</th><td><input name="base" class="regular-text" value="' . esc_attr( dv_ai_opt( 'base' ) ) . '"></td></tr>';
		echo '<tr><th>Modelo da prioridade</th><td><input name="modelo" class="regular-text" value="' . esc_attr( dv_ai_opt( 'modelo' ) ) . '"></td></tr>';
		echo '<tr><th>Modelo da conversa</th><td><input name="modelo_chat" class="regular-text" value="' . esc_attr( dv_ai_opt( 'modelo_chat' ) ) . '"></td></tr>';
		echo '<tr><th>Limite de chamadas por mês</th><td><input name="lim_mes" type="number" min="0" value="' . (int) dv_ai_opt( 'lim_mes' ) . '"> <span class="description">Usadas neste mês: ' . dv_ai_uso() . '</span></td></tr></table>';
		submit_button( 'Salvar', 'primary', 'salvar', false ); echo ' '; submit_button( 'Salvar e testar', 'secondary', 'testar', false );
		$e = (string) get_option( 'dv_ai_erro', '' ); if ( $e ) { echo '<p class="description">Último erro: ' . esc_html( $e ) . '</p>'; }
		echo '</form></div>';
	} );
}, 22 );

/* ---------- Perfil da empresa (o que a IA usa para priorizar) ---------- */
function dv_ai_perfil( $uid ) { $p = json_decode( (string) get_user_meta( $uid, 'dv_perfil', true ), true ); return is_array( $p ) ? $p : array(); }
add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/perfil', array(
		array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () { return array( 'perfil' => dv_ai_perfil( get_current_user_id() ), 'ia' => dv_ai_ligada() ); } ),
		array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
			$p = array(); foreach ( array( 'vende' => 600, 'nao' => 400, 'regioes' => 200, 'porte' => 200, 'obs' => 600 ) as $k => $n ) { $p[ $k ] = mb_substr( sanitize_textarea_field( (string) $r[ $k ] ), 0, $n ); }
			update_user_meta( get_current_user_id(), 'dv_perfil', wp_slash( wp_json_encode( $p, JSON_UNESCAPED_UNICODE ) ) );
			/* perfil mudou: as notas antigas deixam de valer */
			if ( function_exists( 'dv_al_caixa' ) ) { $uid = get_current_user_id(); $c = dv_al_caixa( $uid ); foreach ( $c as &$x ) { unset( $x['nota'], $x['prio'], $x['motivo'], $x['nota_ia'] ); } unset( $x ); dv_al_caixa_salvar( $uid, $c ); }
			return array( 'ok' => true );
		} ),
	) );
	/* dá nota às oportunidades da caixa que ainda não têm (até 45 por vez) e devolve a caixa */
	register_rest_route( $ns, '/alertas/priorizar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $n = dv_ai_priorizar_caixa( $uid, 45 );
		$cx = array_values( dv_al_caixa( $uid ) ); return array( 'ok' => true, 'novas' => $n, 'caixa' => $cx, 'ia' => dv_ai_ligada() );
	} ) );
} );

/** Prioridade por regras (sem IA): termos, valor, prazo, modalidade e o histórico do que a pessoa quis/descartou */
function dv_ai_regra( $x, $uid ) {
	$n = 5; $mot = array();
	$d = $x['encerra'] ? (int) floor( ( strtotime( substr( $x['encerra'], 0, 10 ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
	if ( $d !== null && $d < 2 ) { $n -= 2; $mot[] = 'prazo muito curto'; } elseif ( $d !== null && $d >= 5 ) { $n += 1; $mot[] = 'prazo confortável'; }
	if ( count( (array) ( $x['termos'] ?? array() ) ) > 1 ) { $n += 1; $mot[] = 'bate com mais de um termo do alerta'; }
	if ( stripos( (string) $x['modalidade'], 'dispensa' ) !== false ) { $n += 1; $mot[] = 'dispensa (disputa rápida)'; }
	$p = dv_ai_perfil( $uid ); $o = dv_al_norm( $x['objeto'] . ' ' . $x['orgao'] );
	foreach ( preg_split( '/[,;\n]+/', (string) ( $p['nao'] ?? '' ) ) as $w ) { $w = dv_al_norm( trim( $w ) ); if ( strlen( $w ) > 3 && strpos( $o, $w ) !== false ) { $n -= 4; $mot[] = 'cita algo que a empresa não faz'; break; } }
	if ( ! empty( $p['regioes'] ) && $x['uf'] && stripos( $p['regioes'], $x['uf'] ) !== false ) { $n += 1; $mot[] = 'na região de atuação'; }
	if ( function_exists( 'dv_ap_ordenar' ) ) { $b = dv_ap_ordenar( $uid, array( array( 'a' => array(), 'itens' => array( $x ) ) ) ); $ap = (int) ( $b[0]['itens'][0]['_ap'] ?? 0 ); if ( $ap >= 65 ) { $n += 2; $mot[] = 'parecida com as que você marcou como Quero'; } }
	$n = max( 0, min( 10, $n ) );
	return array( 'nota' => $n, 'prio' => $n >= 7 ? 'alta' : ( $n >= 4 ? 'media' : 'baixa' ), 'motivo' => $mot ? ucfirst( implode( ', ', array_slice( $mot, 0, 3 ) ) ) . '.' : 'Sem sinais fortes a favor ou contra.', 'nota_ia' => 0 );
}

/** Notas da IA para uma lista de oportunidades. Devolve [ref => [nota, prio, motivo, nota_ia]] (só as que a IA avaliou). */
function dv_ai_notas( $uid, $itens ) {
	if ( ! $itens || ! dv_ai_ligada() ) { return array(); }
	$p = dv_ai_perfil( $uid ); $alertas = function_exists( 'dv_al_lista' ) ? implode( '; ', array_map( function ( $a ) { return $a['termos']; }, dv_al_lista( $uid ) ) ) : '';
	$perfil = 'O que a empresa vende: ' . ( $p['vende'] ?? '' ?: $alertas ) . "\nO que NÃO faz: " . ( $p['nao'] ?? '' ?: 'não informado' ) . "\nOnde atua: " . ( $p['regioes'] ?? '' ?: 'Brasil todo' ) . "\nPorte/capacidade: " . ( $p['porte'] ?? '' ?: 'não informado' ) . "\nObservações: " . ( $p['obs'] ?? '' ?: '-' );
	$lista = array(); foreach ( $itens as $x ) { $lista[] = array( 'ref' => $x['ref'], 'orgao' => $x['orgao'], 'local' => trim( $x['municipio'] . '/' . $x['uf'], '/' ), 'modalidade' => $x['modalidade'], 'objeto' => mb_substr( (string) $x['objeto'], 0, 380 ), 'valor' => $x['valor'] ? 'R$ ' . number_format( (float) $x['valor'], 2, ',', '.' ) : 'não informado', 'sessao' => $x['encerra'] ?: 'não informada' ); }
	$sys = 'Você faz a triagem de licitações para uma empresa que vende ao poder público no Brasil. Para cada licitação, dê uma nota de 0 a 10 de quanto vale a pena a empresa analisar e disputar, considerando aderência do objeto ao que ela vende, o que ela não faz, região, porte e prazo até a sessão. 7 a 10 = alta, 4 a 6 = media, 0 a 3 = baixa. Motivo em português, até 140 caracteres, direto. Responda só JSON: {"itens":[{"ref":"...","nota":0,"prio":"alta|media|baixa","motivo":"..."}]}';
	$out = array();
	foreach ( array_chunk( $lista, 15 ) as $ch ) {
		$t = dv_ai_req( array( array( 'role' => 'system', 'content' => $sys ), array( 'role' => 'user', 'content' => "PERFIL DA EMPRESA\n" . $perfil . "\n\nLICITAÇÕES\n" . wp_json_encode( $ch, JSON_UNESCAPED_UNICODE ) ) ), true, false, 2500 );
		if ( is_wp_error( $t ) ) { break; }
		foreach ( (array) ( dv_ai_json( $t )['itens'] ?? array() ) as $r ) {
			$ref = (string) ( $r['ref'] ?? '' ); if ( $ref === '' ) { continue; } $n = max( 0, min( 10, (int) round( (float) ( $r['nota'] ?? 5 ) ) ) );
			$out[ $ref ] = array( 'nota' => $n, 'prio' => $n >= 7 ? 'alta' : ( $n >= 4 ? 'media' : 'baixa' ), 'motivo' => mb_substr( sanitize_text_field( (string) ( $r['motivo'] ?? '' ) ), 0, 180 ), 'nota_ia' => 1 );
		}
	}
	return $out;
}
/** Dá nota às oportunidades novas da caixa que ainda não têm. Devolve quantas receberam nota. */
function dv_ai_priorizar_caixa( $uid, $max = 45 ) {
	if ( ! function_exists( 'dv_al_caixa' ) ) { return 0; }
	$c = dv_al_caixa( $uid ); $falta = array();
	foreach ( $c as $ref => $x ) { if ( ( $x['st'] ?? '' ) === '' && ! isset( $x['nota'] ) ) { $falta[ $ref ] = $x; } }
	if ( ! $falta ) { return 0; }
	uasort( $falta, function ( $a, $b ) { return strcmp( $b['achado'] ?? '', $a['achado'] ?? '' ); } );
	$falta = array_slice( $falta, 0, $max, true );
	$ia = dv_ai_notas( $uid, array_values( $falta ) );
	foreach ( $falta as $ref => $x ) { $c[ $ref ] = array_merge( $x, $ia[ $ref ] ?? dv_ai_regra( $x, $uid ) ); }
	dv_al_caixa_salvar( $uid, $c );
	return count( $falta );
}
/* nas rodadas automáticas dos alertas, a caixa ganha nota logo depois de atualizada */
add_action( 'dv_al_rodou', function ( $uid ) { dv_ai_priorizar_caixa( $uid, 60 ); } );

/* ---------- Conversa com a IA sobre cada licitação (Gestão e Caixa de oportunidades) ---------- */
function dv_ai_ctx( $uid, $chave ) {
	list( $tp, $id ) = array_pad( explode( ':', $chave, 2 ), 2, '' );
	$x = null;
	if ( $tp === 'lic' && function_exists( 'dv_lic_itens' ) ) { foreach ( dv_lic_itens( $uid ) as $y ) { if ( (string) $y['id'] === $id ) { $x = $y; } } }
	if ( $tp === 'cx' && function_exists( 'dv_al_caixa' ) ) { $c = dv_al_caixa( $uid ); $x = $c[ $id ] ?? null; }
	if ( ! $x ) { return ''; }
	$ref = $tp === 'lic' ? (string) ( $x['pncp'] ?? '' ) : ( preg_match( '#^\d{14}/\d{4}/\d+$#', $id ) ? $id : '' );
	$l = array();
	foreach ( array( 'orgao' => 'Órgão', 'municipio' => 'Município', 'uf' => 'UF', 'modalidade' => 'Modalidade', 'numero' => 'Número', 'objeto' => 'Objeto', 'abertura' => 'Abertura/sessão', 'encerra' => 'Sessão', 'publicado' => 'Publicado em', 'status' => 'Situação na gestão', 'notas' => 'Anotações da empresa', 'trecho' => 'Trecho do aviso no diário oficial', 'fonte_nome' => 'Fonte', 'link' => 'Link' ) as $k => $rot ) { if ( ! empty( $x[ $k ] ) && is_string( $x[ $k ] ) ) { $l[] = $rot . ': ' . mb_substr( $x[ $k ], 0, 1500 ); } }
	if ( ! empty( $x['valor'] ) ) { $l[] = 'Valor estimado: R$ ' . number_format( (float) $x['valor'], 2, ',', '.' ); }
	if ( $ref ) {
		$ck = 'dv_ai_pn_' . md5( $ref ); $pn = get_transient( $ck );
		if ( $pn === false ) {
			$k = explode( '/', $ref ); $pn = '';
			$r = wp_remote_get( 'https://pncp.gov.br/api/consulta/v1/orgaos/' . $k[0] . '/compras/' . $k[1] . '/' . $k[2], array( 'timeout' => 15 ) );
			$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
			if ( is_array( $j ) ) { $pn = trim( 'Dados do PNCP: modalidade ' . ( $j['modalidadeNome'] ?? '' ) . '; disputa ' . ( $j['modoDisputaNome'] ?? '' ) . '; registro de preços: ' . ( ! empty( $j['srp'] ) ? 'sim' : 'não' ) . '; valor total estimado: ' . ( isset( $j['valorTotalEstimado'] ) ? 'R$ ' . number_format( (float) $j['valorTotalEstimado'], 2, ',', '.' ) : 'não informado' ) . '; abertura das propostas: ' . ( $j['dataAberturaProposta'] ?? '-' ) . '; encerramento: ' . ( $j['dataEncerramentoProposta'] ?? '-' ) . '; amparo legal: ' . ( $j['amparoLegal']['nome'] ?? '-' ) . '; objeto: ' . mb_substr( (string) ( $j['objetoCompra'] ?? '' ), 0, 1200 ) . '; informação complementar: ' . mb_substr( (string) ( $j['informacaoComplementar'] ?? '' ), 0, 1200 ) . '; unidade: ' . ( $j['unidadeOrgao']['nomeUnidade'] ?? '' ) . ' ' . ( $j['unidadeOrgao']['municipioNome'] ?? '' ) . '/' . ( $j['unidadeOrgao']['ufSigla'] ?? '' ) ); }
			set_transient( $ck, $pn, DAY_IN_SECONDS );
		}
		if ( $pn ) { $l[] = $pn; } $l[] = 'Edital no PNCP: https://pncp.gov.br/app/editais/' . $ref;
	}
	return implode( "\n", $l );
}
function dv_ai_cv_get( $uid ) { $c = json_decode( (string) get_user_meta( $uid, 'dv_ai_cv', true ), true ); return is_array( $c ) ? $c : array(); }
add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	register_rest_route( 'desenvolve/v1', '/ia/conversa', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $ch = preg_replace( '/[^a-z0-9:\/A-Z_-]/', '', (string) $r['id'] ); $msg = trim( sanitize_textarea_field( (string) $r['msg'] ) );
		$all = dv_ai_cv_get( $uid ); $h = (array) ( $all[ $ch ]['m'] ?? array() );
		if ( ! empty( $r['reset'] ) ) { $h = array(); unset( $all[ $ch ] ); update_user_meta( $uid, 'dv_ai_cv', wp_slash( wp_json_encode( $all, JSON_UNESCAPED_UNICODE ) ) ); }
		if ( $msg === '' ) { return array( 'historico' => $h, 'ia' => dv_ai_ligada() ); }
		if ( ! dv_ai_ligada() ) { return new WP_Error( 'dv_ai', 'A conversa com a IA ainda não está ligada.', array( 'status' => 400 ) ); }
		$ctx = dv_ai_ctx( $uid, $ch ); if ( $ctx === '' ) { return new WP_Error( 'dv_ai', 'Licitação não encontrada.', array( 'status' => 404 ) ); }
		$p = dv_ai_perfil( $uid );
		$sys = "Você é consultor sênior de licitações públicas no Brasil (Lei 14.133/2021 e jurisprudência do TCU), ajudando uma empresa fornecedora a decidir e se preparar para disputar.\nResponda em português, direto e prático, em até 12 linhas, com listas curtas quando ajudar.\nUse os DADOS DA LICITAÇÃO abaixo. O que não estiver nos dados (exigências de habilitação, atestados, prazos detalhados), diga que precisa ser conferido no edital e indique o que procurar. Nunca invente número, data ou exigência.\nPerfil da empresa: " . mb_substr( trim( ( $p['vende'] ?? '' ) . ' | não faz: ' . ( $p['nao'] ?? '' ) . ' | atua em: ' . ( $p['regioes'] ?? '' ) . ' | porte: ' . ( $p['porte'] ?? '' ) ), 0, 800 ) . "\n\nDADOS DA LICITAÇÃO\n" . $ctx;
		$msgs = array( array( 'role' => 'system', 'content' => $sys ) );
		foreach ( array_slice( $h, -10 ) as $m ) { $msgs[] = array( 'role' => $m['r'] === 'u' ? 'user' : 'assistant', 'content' => $m['t'] ); }
		$msgs[] = array( 'role' => 'user', 'content' => mb_substr( $msg, 0, 2000 ) );
		$t = dv_ai_req( $msgs, false, true, 1500 );
		if ( is_wp_error( $t ) ) { return new WP_Error( 'dv_ai', $t->get_error_message(), array( 'status' => 502 ) ); }
		$h[] = array( 'r' => 'u', 't' => mb_substr( $msg, 0, 2000 ), 'd' => current_time( 'mysql' ) ); $h[] = array( 'r' => 'a', 't' => trim( $t ), 'd' => current_time( 'mysql' ) );
		$all[ $ch ] = array( 'm' => array_slice( $h, -20 ), 'u' => time() );
		if ( count( $all ) > 40 ) { uasort( $all, function ( $a, $b ) { return $b['u'] <=> $a['u']; } ); $all = array_slice( $all, 0, 40, true ); }
		update_user_meta( $uid, 'dv_ai_cv', wp_slash( wp_json_encode( $all, JSON_UNESCAPED_UNICODE ) ) );
		if ( function_exists( 'dv_log_uso' ) ) { dv_log_uso( $uid, 'ia_conversa', $ch, 0 ); }
		return array( 'historico' => $h, 'resposta' => trim( $t ) );
	} ) );
} );

/* Botão "Perguntar à IA" ao lado de "Analisar com a minha IA" (Gestão e Caixa) + janela da conversa */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! dv_ai_ligada() ) { return; } ?>
<style>.dv-cvb{font-size:.8rem!important;font-weight:700!important;border:1px solid #C9A45C!important;border-radius:9px!important;padding:7px 11px!important;color:#241A06!important;background:#FBF4E4!important;cursor:pointer}
.dv-cv{position:fixed;inset:0;background:rgba(12,15,19,.45);z-index:99990;display:flex;justify-content:flex-end}.dv-cv>div{background:#fff;width:min(520px,100%);height:100%;display:flex;flex-direction:column;font-family:"Hanken Grotesk",sans-serif;color:#16191E}
.dv-cv header{padding:16px 18px;border-bottom:1px solid #E3DED2;display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.dv-cv header b{display:block;font-size:1rem}.dv-cv header small{color:#6E6A60}.dv-cv header button{background:none;border:0;font-size:1.4rem;cursor:pointer;color:#6E6A60}
.dv-cvl{flex:1;overflow:auto;padding:16px 18px;background:#FAF8F3;display:flex;flex-direction:column;gap:10px}.dv-cvm{max-width:88%;padding:10px 13px;border-radius:14px;line-height:1.45;font-size:.92rem;white-space:pre-wrap}.dv-cvm.u{align-self:flex-end;background:#16191E;color:#F4F2EC;border-bottom-right-radius:4px}.dv-cvm.a{align-self:flex-start;background:#fff;border:1px solid #E3DED2;border-bottom-left-radius:4px}
.dv-cvs{display:flex;flex-wrap:wrap;gap:6px}.dv-cvs span{border:1px solid #E3DED2;background:#fff;border-radius:99px;padding:6px 11px;font-size:.8rem;cursor:pointer;font-weight:600}
.dv-cv form{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #E3DED2}.dv-cv textarea{flex:1;border:1px solid #CFC8B8;border-radius:12px;padding:10px 12px;font:inherit;resize:none;height:46px}.dv-cv form button{background:#C9A45C;color:#241A06;border:0;border-radius:12px;padding:0 16px;font-weight:800;cursor:pointer}
.dv-cv .rs{font-size:.78rem;color:#6E6A60;background:none;border:0;text-decoration:underline;cursor:pointer;padding:0 18px 10px;text-align:left}</style>
<script>(function(){var api=<?php echo wp_json_encode( rest_url( 'desenvolve/v1/' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function req(b){return fetch(api+'ia/conversa',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:JSON.stringify(b)}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
var SUG=['Vale a pena disputar? Por quê?','Quais documentos de habilitação costumam ser exigidos nesse tipo de licitação?','Quais riscos devo olhar no edital?','Que perguntas posso fazer no pedido de esclarecimento?','Como montar o preço para essa disputa?'];
function abre(id,tit){var W=document.createElement('div');W.className='dv-cv';W.innerHTML='<div><header><div><b>Perguntar à IA</b><small>'+e(tit)+'</small></div><button type="button" data-x aria-label="Fechar">×</button></header><div class="dv-cvl"><p style="color:#6E6A60;font-size:.86rem;margin:0">Carregando…</p></div><button type="button" class="rs" data-r>Começar a conversa do zero</button><form><textarea placeholder="Pergunte sobre esta licitação…"></textarea><button>Enviar</button></form></div>';document.body.appendChild(W);
 var L=W.querySelector('.dv-cvl'),T=W.querySelector('textarea'),ocup=false;
 function pinta(h){L.innerHTML=h.length?h.map(function(m){return'<div class="dv-cvm '+(m.r==='u'?'u':'a')+'">'+e(m.t)+'</div>'}).join(''):'<p style="color:#6E6A60;font-size:.86rem;margin:0">A IA já conhece os dados desta licitação e o perfil da sua empresa. Comece com uma sugestão ou escreva a sua pergunta.</p><div class="dv-cvs">'+SUG.map(function(s){return'<span>'+e(s)+'</span>'}).join('')+'</div>';L.scrollTop=L.scrollHeight}
 function envia(t){if(ocup||!t.trim())return;ocup=true;L.querySelectorAll('.dv-cvs').forEach(function(x){x.remove()});L.insertAdjacentHTML('beforeend','<div class="dv-cvm u">'+e(t)+'</div><div class="dv-cvm a dv-cvw">Pensando…</div>');L.scrollTop=L.scrollHeight;T.value='';
  req({id:id,msg:t}).then(function(j){ocup=false;pinta(j.historico||[])}).catch(function(j){ocup=false;var w=L.querySelector('.dv-cvw');if(w){w.textContent=(j&&j.message)||'A IA não respondeu agora. Tente de novo.';w.classList.remove('dv-cvw')}})}
 req({id:id}).then(function(j){pinta(j.historico||[])}).catch(function(){pinta([])});
 W.addEventListener('click',function(ev){var t=ev.target;if(t===W||t.hasAttribute('data-x')){W.remove();return}if(t.hasAttribute('data-r')){pinta([]);req({id:id,reset:1}).catch(function(){});return}if(t.closest('.dv-cvs')&&t.tagName==='SPAN')envia(t.textContent)});
 W.querySelector('form').addEventListener('submit',function(ev){ev.preventDefault();envia(T.value)});T.addEventListener('keydown',function(ev){if(ev.key==='Enter'&&!ev.shiftKey){ev.preventDefault();envia(T.value)}});T.focus()}
window.dvConversa=abre;
function poe(){[].forEach.call(document.querySelectorAll('.lc-c [data-mia]:not([data-cvok]),.al-cx .al-i [data-mia-al]:not([data-cvok]),.al-cx .al-i:not(.al-dio) [data-an]:not([data-cvok])'),function(b){b.setAttribute('data-cvok','1');var id='',tit='';
  if(b.dataset.mia){id='lic:'+b.dataset.mia;var c=b.closest('.lc-c');tit=c?(c.querySelector('.o')||{}).textContent||'':''}
  else{var c2=b.closest('.al-i');if(!c2)return;id='cx:'+c2.dataset.r;tit=(c2.querySelector('.m b')||{}).textContent||''}
  var n=document.createElement('button');n.type='button';n.className='dv-cvb';n.textContent='💬 Perguntar à IA';n.dataset.cv=id;n.dataset.tit=tit;b.parentNode.insertBefore(n,b)})}
document.addEventListener('click',function(ev){var b=ev.target.closest('[data-cv]');if(b){ev.preventDefault();ev.stopPropagation();abre(b.dataset.cv,b.dataset.tit||'')}},true);
var tm=0;new MutationObserver(function(){clearTimeout(tm);tm=setTimeout(poe,250)}).observe(document.body,{childList:true,subtree:true});poe()})();</script>
	<?php
}, 120 );
