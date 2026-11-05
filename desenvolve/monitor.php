<?php
/**
 * Desenvolve — Monitor das fontes (de hora em hora)
 * Testa PNCP, Diário Oficial da União, Querido Diário, SIGPub (associações de municípios) e DOM/SC. Se uma fonte falhar 3 vezes seguidas (cerca de 3 horas),
 * aparece um comunicado no topo da plataforma para os clientes e o administrador recebe um e-mail.
 * Quando a fonte volta, o comunicado some sozinho e o administrador é avisado.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dv_mon_fontes() {
	return array(
		'pncp' => array( 'nome' => 'PNCP (Portal Nacional de Contratações Públicas)', 'efeito' => 'As licitações do PNCP podem chegar com atraso nos alertas e no buscador.', 'url' => 'https://pncp.gov.br/api/search/?q=limpeza&tipos_documento=edital&status=recebendo_proposta&pagina=1&tam_pagina=1', 'ok' => '"items"', 'accept' => 'application/json' ),
		'dou'  => array( 'nome' => 'Diário Oficial da União', 'efeito' => 'Os avisos de licitação do Diário Oficial da União podem chegar com atraso.', 'url' => 'https://www.in.gov.br/consulta/-/buscar/dou?q=%22aviso+de+licita%C3%A7%C3%A3o%22&s=do3&delta=1', 'ok' => 'BuscaDouPortlet', 'accept' => 'text/html' ),
		'qd'   => array( 'nome' => 'diários oficiais municipais (Querido Diário)', 'efeito' => 'Os avisos publicados nos diários oficiais dos municípios podem chegar com atraso.', 'url' => 'https://api.queridodiario.ok.org.br/gazettes?querystring=licita%C3%A7%C3%A3o&size=1', 'ok' => 'gazettes', 'accept' => 'application/json' ),
		'sigpub' => array( 'nome' => 'diários das associações de municípios (SIGPub, 20 estados)', 'efeito' => 'Os avisos e citações nos diários dos municípios atendidos pelas associações podem chegar com atraso.', 'url' => 'https://www.diariomunicipal.com.br/amm-mg/pesquisar?busca_avancada%5Btexto%5D=licita%C3%A7%C3%A3o&busca_avancada%5BdataInicio%5D=2026-10-01', 'ok' => 'materia-card', 'accept' => 'text/html' ),
		'domsc' => array( 'nome' => 'Diário Oficial dos Municípios de Santa Catarina (DOM/SC)', 'efeito' => 'Os avisos e citações nos diários dos municípios de Santa Catarina podem chegar com atraso.', 'url' => 'https://diariomunicipal.sc.gov.br/?q=licita%C3%A7%C3%A3o', 'ok' => 'resultado-pesquisa', 'accept' => 'text/html' ),
	);
}
define( 'DV_MON_LIMITE', 3 ); // falhas seguidas para virar comunicado

add_filter( 'cron_schedules', function ( $s ) { if ( ! isset( $s['hourly'] ) ) { $s['hourly'] = array( 'interval' => HOUR_IN_SECONDS, 'display' => 'De hora em hora' ); } return $s; } );
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_mon_cron' ) ) { wp_schedule_event( time() + 300, 'hourly', 'dv_mon_cron' ); } } );

function dv_mon_testar( $f ) {
	for ( $i = 0; $i < 2; $i++ ) { // duas tentativas: só conta falha se as duas derem errado
		$g = wp_remote_get( $f['url'], array( 'timeout' => 25, 'headers' => array( 'Accept' => $f['accept'], 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' ) ) );
		if ( ! is_wp_error( $g ) && wp_remote_retrieve_response_code( $g ) === 200 && strpos( wp_remote_retrieve_body( $g ), $f['ok'] ) !== false ) { return ''; }
		$erro = is_wp_error( $g ) ? $g->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $g );
		if ( $i === 0 ) { sleep( 5 ); }
	}
	return mb_substr( $erro, 0, 120 );
}

add_action( 'dv_mon_cron', function () {
	$st = (array) get_option( 'dv_mon', array() ); $agora = current_time( 'mysql' ); $mud = array();
	foreach ( dv_mon_fontes() as $k => $f ) {
		$s = (array) ( $st[ $k ] ?? array() ) + array( 'falhas' => 0, 'desde' => '', 'aviso' => 0, 'erro' => '', 'ok' => '' );
		$erro = dv_mon_testar( $f );
		if ( $erro === '' ) {
			if ( $s['aviso'] ) { $mud[] = 'VOLTOU: ' . $f['nome'] . ' (fora do ar desde ' . $s['desde'] . ')'; }
			$s = array( 'falhas' => 0, 'desde' => '', 'aviso' => 0, 'erro' => '', 'ok' => $agora );
		} else {
			if ( ! $s['falhas'] ) { $s['desde'] = $agora; }
			$s['falhas']++; $s['erro'] = $erro;
			if ( $s['falhas'] >= DV_MON_LIMITE && ! $s['aviso'] ) { $s['aviso'] = 1; $mud[] = 'FORA DO AR: ' . $f['nome'] . ' desde ' . $s['desde'] . ' (' . $erro . '). Comunicado publicado na plataforma.'; }
		}
		$st[ $k ] = $s;
	}
	$st['_ultima'] = $agora; update_option( 'dv_mon', $st, false );
	if ( $mud ) {
		$l = (array) get_option( 'dv_mon_log', array() ); foreach ( $mud as $m ) { array_unshift( $l, $agora . ' · ' . $m ); } update_option( 'dv_mon_log', array_slice( $l, 0, 60 ), false );
		wp_mail( get_option( 'admin_email' ), 'Desenvolve: status das fontes', implode( "\n", $mud ) . "\n\nPainel: " . admin_url( 'admin.php?page=dv-monitor' ) );
	}
} );

/** Comunicados ativos (fontes fora do ar de forma persistente) */
function dv_mon_avisos() {
	$st = (array) get_option( 'dv_mon', array() ); $o = array();
	foreach ( dv_mon_fontes() as $k => $f ) { if ( ! empty( $st[ $k ]['aviso'] ) ) { $o[] = array( 'nome' => $f['nome'], 'efeito' => $f['efeito'], 'desde' => $st[ $k ]['desde'] ); } }
	return $o;
}

/* Comunicado no topo da plataforma */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; }
	$p = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( ! preg_match( '#^/(plataforma|painel)(/|$)#', $p ) ) { return; }
	$av = dv_mon_avisos(); if ( ! $av ) { return; }
	$h = '';
	foreach ( $av as $a ) { $h .= '<p style="margin:0 0 4px"><b>Aviso:</b> a fonte <b>' . esc_html( $a['nome'] ) . '</b> está fora do ar desde ' . esc_html( date_i18n( 'd/m \à\s H\h', strtotime( $a['desde'] ) ) ) . '. ' . esc_html( $a['efeito'] ) . '</p>'; }
	$h .= '<p style="margin:0;opacity:.85">Estamos acompanhando de hora em hora. Assim que a fonte voltar, buscamos automaticamente o que foi publicado nesse período. As demais fontes seguem funcionando normalmente.</p>';
	echo '<script>(function(){var d=document.createElement("div");d.id="dv-mon";d.setAttribute("role","status");d.style.cssText="background:#FFF6E0;border:1px solid #E7C873;color:#5A4410;border-radius:10px;padding:10px 14px;margin:12px 16px;font-size:14px;line-height:1.45";d.innerHTML=' . wp_json_encode( $h ) . ';var t=document.querySelector(".dv-top");if(t&&t.parentNode){t.parentNode.insertBefore(d,t.nextSibling)}else{document.body.insertBefore(d,document.body.firstChild)}})()</script>';
}, 60 );

/* Painel do administrador: Desenvolve › Monitor das fontes */
add_action( 'admin_menu', function () {
	$pai = 'dv-painel';
	add_submenu_page( $pai, 'Monitor das fontes', 'Monitor das fontes', 'manage_options', 'dv-monitor', function () {
		if ( isset( $_POST['dv_mon_agora'] ) && check_admin_referer( 'dv_mon' ) ) { do_action( 'dv_mon_cron' ); echo '<div class="notice notice-success"><p>Verificação feita agora.</p></div>'; }
		$st = (array) get_option( 'dv_mon', array() );
		echo '<div class="wrap"><h1>Monitor das fontes</h1><p>Verificação automática de hora em hora. Uma fonte vira comunicado para os clientes depois de ' . DV_MON_LIMITE . ' falhas seguidas (cerca de ' . DV_MON_LIMITE . ' horas). Última verificação: ' . esc_html( $st['_ultima'] ?? '—' ) . '.</p>';
		echo '<form method="post">' . wp_nonce_field( 'dv_mon', '_wpnonce', true, false ) . '<p><button class="button" name="dv_mon_agora" value="1">Verificar agora</button></p></form>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Fonte</th><th>Situação</th><th>Falhas seguidas</th><th>Último OK</th><th>Erro</th></tr></thead><tbody>';
		foreach ( dv_mon_fontes() as $k => $f ) { $s = (array) ( $st[ $k ] ?? array() ); $sit = empty( $s['falhas'] ) ? '✅ Funcionando' : ( ! empty( $s['aviso'] ) ? '🔴 Fora do ar (comunicado publicado)' : '🟡 Instável' ); echo '<tr><td>' . esc_html( $f['nome'] ) . '</td><td>' . $sit . '</td><td>' . (int) ( $s['falhas'] ?? 0 ) . '</td><td>' . esc_html( $s['ok'] ?? '' ) . '</td><td>' . esc_html( $s['erro'] ?? '' ) . '</td></tr>'; }
		echo '</tbody></table><h2>Histórico</h2><ul>';
		foreach ( (array) get_option( 'dv_mon_log', array() ) as $x ) { echo '<li>' . esc_html( $x ) . '</li>'; }
		echo '</ul></div>';
	} );
}, 99 );
