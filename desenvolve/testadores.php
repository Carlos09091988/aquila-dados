/**
 * Desenvolve — Programa de testadores especialistas
 * Convite único (/teste/?convite=CODIGO) → conta com 7 dias de uso ilimitado →
 * avaliação em /plataforma/avaliacao/ → recompensa automática: 1 mês de assinatura (80 créditos).
 * Admin: Desenvolve → Testadores (gerar convites, acompanhar e baixar as avaliações).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'DV_TS_DIAS', 7 ); define( 'DV_TS_CREDITOS', 30 );
function dv_ts_codigos() { $c = get_option( 'dv_testers', array() ); return is_array( $c ) ? $c : array(); }
function dv_ts_ferramentas() { return array( 'vencendo' => 'Contratos vencendo', 'alertas' => 'Alertas de oportunidades', 'licitacoes' => 'Gestão de licitações e conferência do edital', 'concorrentes' => 'Concorrentes e classificação dos lances', 'raiox' => 'Raio-X do concorrente', 'lote' => 'Raio-X em lote', 'dossie' => 'Due Diligence completa', 'comprador' => 'Raio-X do comprador', 'precos' => 'Banco de preços', 'recursos' => 'Impugnações e recursos', 'meuscontratos' => 'Meus contratos e garantias', 'documentos' => 'Documentos e certidões' ); }
function dv_ts_e_testador( $uid ) { return (string) get_user_meta( $uid, 'dv_tester', true ); }

/* ---------- Cadastro pelo convite ---------- */
add_shortcode( 'dv_teste', function () {
	$cod = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_REQUEST['convite'] ?? '' ) ) ); $C = dv_ts_codigos(); $err = '';
	if ( is_user_logged_in() ) { $u = get_current_user_id(); return '<div class="ts"><h2>Você já está conectado</h2><p>' . ( dv_ts_e_testador( $u ) ? 'Siga para as ferramentas e, ao final, preencha a <a href="/plataforma/avaliacao/">avaliação</a> para ganhar 1 mês de assinatura.' : 'Este convite é para criar uma conta nova de testador.' ) . '</p><p><a class="ts-b" href="/painel/">Ir para as ferramentas</a></p></div>' . dv_ts_css(); }
	if ( ! $cod || ! isset( $C[ $cod ] ) ) { return '<div class="ts"><h2>Convite inválido</h2><p>Este link de teste não existe ou foi digitado errado. Peça um novo convite à Desenvolve pelo WhatsApp <a href="https://wa.me/5511993500950">(11) 99350-0950</a>.</p></div>' . dv_ts_css(); }
	if ( ! empty( $C[ $cod ]['uid'] ) ) { return '<div class="ts"><h2>Convite já utilizado</h2><p>Cada convite vale para uma pessoa. Se foi você quem ativou, <a href="/painel/">entre na sua conta</a>.</p></div>' . dv_ts_css(); }
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dv_ts'] ) && wp_verify_nonce( $_POST['_dvts'] ?? '', 'dv_ts' ) ) {
		$nome = sanitize_text_field( wp_unslash( $_POST['nome'] ?? '' ) ); $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$doc = preg_replace( '/\D/', '', $_POST['doc'] ?? '' ); $tel = preg_replace( '/\D/', '', $_POST['tel'] ?? '' ); $senha = (string) wp_unslash( $_POST['senha'] ?? '' ); $atua = sanitize_text_field( wp_unslash( $_POST['atuacao'] ?? '' ) );
		if ( ! $nome || ! is_email( $email ) ) { $err = 'Preencha nome e um e-mail válido.'; }
		elseif ( ! in_array( strlen( $doc ), array( 11, 14 ), true ) ) { $err = 'Informe um CPF ou CNPJ válido (só números).'; }
		elseif ( strlen( $senha ) < 8 ) { $err = 'A senha precisa ter pelo menos 8 caracteres.'; }
		elseif ( email_exists( $email ) ) { $err = 'Já existe uma conta com este e-mail. Fale com a Desenvolve para ativar o teste nela.'; }
		elseif ( empty( $_POST['termos'] ) ) { $err = 'É preciso aceitar as condições do teste, os Termos de uso e a Política de privacidade.'; }
		else {
			$uid = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => $senha, 'display_name' => $nome, 'first_name' => $nome, 'role' => 'subscriber' ) );
			if ( is_wp_error( $uid ) ) { $err = $uid->get_error_message(); }
			else {
				foreach ( array( 'dv_doc' => $doc, 'dv_tel' => $tel, 'dv_termos' => current_time( 'mysql' ), 'dv_tester' => $cod, 'dv_tester_atuacao' => $atua, 'dv_trial' => current_time( 'Y-m-d' ), 'dv_status' => 'ativo', 'dv_mes_saldo' => DV_TS_CREDITOS ) as $k => $v ) { update_user_meta( $uid, $k, $v ); }
				update_user_meta( $uid, 'dv_vence', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . ( DV_TS_DIAS - 1 ) . ' days' ) ) );
				if ( function_exists( 'dv_extrato' ) ) { dv_extrato( $uid, DV_TS_CREDITOS, 'teste', 'Teste de especialista (' . DV_TS_DIAS . ' dias)' ); }
				$C[ $cod ]['uid'] = $uid; $C[ $cod ]['ativado'] = current_time( 'mysql' ); $C[ $cod ]['nome'] = $nome; $C[ $cod ]['email'] = $email; update_option( 'dv_testers', $C, false );
				$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
				wp_mail( $email, 'Seu teste de especialista na Desenvolve começou', "Olá, $nome!\n\nObrigado por aceitar testar a Desenvolve. Você tem " . DV_TS_DIAS . " dias de acesso completo e ilimitado ao Raio-X, à Due Diligence e às demais ferramentas.\n\nAo final, preencha a avaliação (leva uns 10 minutos):\n" . home_url( '/plataforma/avaliacao/' ) . "\n\nAssim que você enviar, liberamos automaticamente 1 mês de assinatura, com 80 créditos.\n\nEquipe Desenvolve", array( 'From: Desenvolve <' . $from . '>' ) );
				wp_mail( get_option( 'admin_email' ), 'Testador ativou o convite: ' . $nome, "Convite: $cod\nNome: $nome\nE-mail: $email\nAtuação: $atua", array( 'From: Desenvolve <' . $from . '>' ) );
				wp_set_current_user( $uid ); wp_set_auth_cookie( $uid, true ); wp_safe_redirect( home_url( '/plataforma/?teste=1' ) ); exit;
			}
		}
	}
	ob_start(); ?>
<form class="ts" method="post"><?php wp_nonce_field( 'dv_ts', '_dvts' ); ?><input type="hidden" name="dv_ts" value="1"><input type="hidden" name="convite" value="<?php echo esc_attr( $cod ); ?>">
	<span class="ts-k">Convite de especialista</span><h2><?php echo ! empty( $C[ $cod ]['rotulo'] ) ? esc_html( $C[ $cod ]['rotulo'] ) . ', teste' : 'Teste'; ?> a Desenvolve antes de todo mundo</h2>
	<ul class="ts-l"><li><b><?php echo DV_TS_DIAS; ?> dias</b> de acesso completo e <b>ilimitado</b> ao Raio-X, à Due Diligence e às demais ferramentas</li><li>No fim, uma <b>avaliação rápida</b> (cerca de 10 minutos) com as suas sugestões</li><li>Enviou a avaliação: ganha <b>1 mês de assinatura</b> (80 créditos), liberado na hora</li></ul>
	<?php if ( $err ) { echo '<p class="ts-err">' . esc_html( $err ) . '</p>'; } ?>
	<label>Nome completo<input name="nome" required value="<?php echo esc_attr( $_POST['nome'] ?? ( $C[ $cod ]['rotulo'] ?? '' ) ); ?>"></label>
	<div class="ts-2"><label>E-mail<input type="email" name="email" required value="<?php echo esc_attr( $_POST['email'] ?? '' ); ?>"></label><label>WhatsApp<input name="tel" value="<?php echo esc_attr( $_POST['tel'] ?? '' ); ?>"></label></div>
	<div class="ts-2"><label>CPF ou CNPJ<input name="doc" required inputmode="numeric" value="<?php echo esc_attr( $_POST['doc'] ?? '' ); ?>"></label><label>Senha (mín. 8 caracteres)<input type="password" name="senha" required minlength="8"></label></div>
	<label>Sua atuação<input name="atuacao" placeholder="Ex.: consultor de licitações há 10 anos, advogado, gerente comercial público" value="<?php echo esc_attr( $_POST['atuacao'] ?? '' ); ?>"></label>
	<label class="ts-c"><input type="checkbox" name="termos" value="1"> <span>Li e aceito as condições do teste (acesso por <?php echo DV_TS_DIAS; ?> dias; a recompensa é liberada após o envio da avaliação completa; as sugestões podem ser usadas para melhorar a plataforma), os <a href="/termos-de-uso-plataforma/" target="_blank">Termos de uso</a> e a <a href="/politica-de-privacidade/" target="_blank">Política de privacidade</a>.</span></label>
	<button class="ts-b" type="submit">Ativar meu teste</button>
</form>
	<?php return ob_get_clean() . dv_ts_css();
} );
function dv_ts_css() { return '<style>.ts{max-width:640px;margin:30px auto;padding:28px;border:1px solid rgba(201,164,92,.55);border-radius:18px;font-family:"Hanken Grotesk",-apple-system,sans-serif;color:inherit}.ts h2{margin:6px 0 12px;font-size:1.7rem}.ts-k{color:#C9A45C;font-weight:700;letter-spacing:.18em;text-transform:uppercase;font-size:.78rem}.ts-l{margin:0 0 18px 18px;line-height:1.6}.ts label{display:block;font-weight:600;margin:0 0 12px;font-size:.92rem}.ts input:not([type=checkbox]),.ts textarea,.ts select{width:100%;box-sizing:border-box;margin-top:5px;padding:11px 13px;border:1px solid rgba(127,127,127,.4);border-radius:10px;font:inherit;background:#fff;color:#16191E}.ts-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ts-c{display:flex!important;gap:10px;font-weight:500!important;font-size:.85rem!important}.ts-c input{margin-top:3px}.ts-b{display:inline-block;background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}.ts-err{background:#FDEDEA;color:#8A2E1F;border-radius:10px;padding:10px 12px}.ts-ok{background:#EAF5EE;color:#1F5A3B;border-radius:10px;padding:10px 12px}.ts table{width:100%;border-collapse:collapse}.ts td{padding:6px 4px;border-bottom:1px solid rgba(127,127,127,.25);font-size:.9rem}.ts td select{margin:0;width:90px}@media(max-width:640px){.ts-2{grid-template-columns:1fr}.ts{padding:18px;margin:16px}}</style>'; }

/* ---------- Avaliação e recompensa ---------- */
add_shortcode( 'dv_avaliacao', function () {
	if ( ! is_user_logged_in() ) { return '<div class="ts"><h2>Entre na sua conta</h2><p>A avaliação é para quem está no teste de especialista. <a href="/painel/">Entrar</a>.</p></div>' . dv_ts_css(); }
	$uid = get_current_user_id(); $cod = dv_ts_e_testador( $uid ); $F = dv_ts_ferramentas();
	if ( ! $cod ) { return '<div class="ts"><h2>Avaliação de especialistas</h2><p>Esta página é para quem recebeu um convite de teste.</p></div>' . dv_ts_css(); }
	if ( get_user_meta( $uid, 'dv_tester_av', true ) ) { return '<div class="ts"><h2>Obrigado pela avaliação!</h2><p class="ts-ok">Seu mês de assinatura está ativo, com 80 créditos, até ' . esc_html( date_i18n( 'd/m/Y', strtotime( (string) get_user_meta( $uid, 'dv_vence', true ) ) ) ) . '.</p><p><a class="ts-b" href="/painel/">Ir para as ferramentas</a></p></div>' . dv_ts_css(); }
	$err = '';
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dv_av'] ) && wp_verify_nonce( $_POST['_dvav'] ?? '', 'dv_av' ) ) {
		$notas = array(); foreach ( $F as $k => $n ) { $v = $_POST['n_' . $k] ?? ''; $notas[ $k ] = $v === '' ? null : max( 0, min( 10, (int) $v ) ); }
		$t = function ( $k, $n = 3000 ) { return mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $k ] ?? '' ) ), 0, $n ); };
		$av = array( 'notas' => $notas, 'ajudou' => $t( 'ajudou' ), 'faltou' => $t( 'faltou' ), 'erros' => $t( 'erros' ), 'preco' => $t( 'preco', 60 ), 'nps' => ( $_POST['nps'] ?? '' ) === '' ? null : max( 0, min( 10, (int) $_POST['nps'] ) ), 'depoimento' => $t( 'depoimento', 1500 ), 'autoriza' => ! empty( $_POST['autoriza'] ) ? 1 : 0, 'assinatura' => $t( 'assinatura', 150 ), 'data' => current_time( 'mysql' ) );
		$respondidas = count( array_filter( $notas, function ( $v ) { return $v !== null; } ) );
		if ( $respondidas < 5 ) { $err = 'Dê nota para pelo menos 5 ferramentas.'; }
		elseif ( mb_strlen( $av['ajudou'] ) < 20 || mb_strlen( $av['faltou'] ) < 20 ) { $err = 'Conte um pouco mais no que mais ajudou e no que faltou (pelo menos uma ou duas frases em cada).'; }
		elseif ( $av['nps'] === null || ! $av['preco'] ) { $err = 'Responda quanto pagaria e a nota de recomendação.'; }
		else {
			update_user_meta( $uid, 'dv_tester_av', wp_slash( wp_json_encode( $av, JSON_UNESCAPED_UNICODE ) ) );
			$C = dv_ts_codigos(); if ( isset( $C[ $cod ] ) ) { $C[ $cod ]['avaliado'] = current_time( 'mysql' ); update_option( 'dv_testers', $C, false ); }
			// recompensa: 1 mês de assinatura
			delete_user_meta( $uid, 'dv_trial' ); update_user_meta( $uid, 'dv_plano', array_key_first( function_exists( 'dv_planos' ) ? dv_planos() : array( 'desenvolve' => 1 ) ) ); update_user_meta( $uid, 'dv_tester_recompensa', current_time( 'mysql' ) );
			if ( function_exists( 'dv_renovar' ) ) { dv_renovar( $uid, 'Recompensa de testador: 1 mês de assinatura', 31 ); }
			$u = wp_get_current_user(); $from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
			$txt = "Testador: {$u->display_name} <{$u->user_email}> (convite $cod)\nAtuação: " . get_user_meta( $uid, 'dv_tester_atuacao', true ) . "\n\nNOTAS\n"; foreach ( $notas as $k => $v ) { $txt .= '- ' . $F[ $k ] . ': ' . ( $v === null ? 'não usou' : $v ) . "\n"; }
			$txt .= "\nMAIS AJUDOU\n{$av['ajudou']}\n\nFALTOU\n{$av['faltou']}\n\nERROS\n{$av['erros']}\n\nQUANTO PAGARIA: {$av['preco']}\nRECOMENDAÇÃO (0-10): {$av['nps']}\n\nDEPOIMENTO" . ( $av['autoriza'] ? ' (AUTORIZADO para divulgação como: ' . $av['assinatura'] . ')' : ' (não autorizado)' ) . "\n{$av['depoimento']}";
			wp_mail( get_option( 'admin_email' ), 'Avaliação de testador: ' . $u->display_name, $txt, array( 'From: Desenvolve <' . $from . '>' ) );
			wp_safe_redirect( home_url( '/plataforma/avaliacao/?ok=1' ) ); exit;
		}
	}
	$v = (string) get_user_meta( $uid, 'dv_vence', true ); $dl = $v ? (int) round( ( strtotime( $v ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 0;
	ob_start(); ?>
<form class="ts" method="post"><?php wp_nonce_field( 'dv_av', '_dvav' ); ?><input type="hidden" name="dv_av" value="1">
	<span class="ts-k">Avaliação de especialista</span><h2>Sua opinião vale 1 mês de assinatura</h2>
	<p><?php echo $dl >= 0 ? 'Seu teste vai até <b>' . esc_html( date_i18n( 'd/m/Y', strtotime( $v ) ) ) . '</b>. ' : ''; ?>Responda com sinceridade: o que você disser vai direto para a melhoria da plataforma. Ao enviar, liberamos na hora 1 mês de assinatura, com 80 créditos.</p>
	<?php if ( $err ) { echo '<p class="ts-err">' . esc_html( $err ) . '</p>'; } ?>
	<label>Nota de 0 a 10 para cada ferramenta que você usou <small>(deixe em branco o que não usou)</small></label>
	<table><?php foreach ( $F as $k => $n ) { echo '<tr><td>' . esc_html( $n ) . '</td><td style="text-align:right"><select name="n_' . esc_attr( $k ) . '"><option value="">—</option>'; for ( $i = 10; $i >= 0; $i-- ) { echo '<option' . ( isset( $_POST[ 'n_' . $k ] ) && $_POST[ 'n_' . $k ] === (string) $i ? ' selected' : '' ) . '>' . $i . '</option>'; } echo '</select></td></tr>'; } ?></table><br>
	<label>O que mais ajudou no seu dia a dia?<textarea name="ajudou" rows="3"><?php echo esc_textarea( wp_unslash( $_POST['ajudou'] ?? '' ) ); ?></textarea></label>
	<label>O que faltou ou poderia ser melhor?<textarea name="faltou" rows="3"><?php echo esc_textarea( wp_unslash( $_POST['faltou'] ?? '' ) ); ?></textarea></label>
	<label>Encontrou algum erro? Onde? <small>(opcional)</small><textarea name="erros" rows="2"><?php echo esc_textarea( wp_unslash( $_POST['erros'] ?? '' ) ); ?></textarea></label>
	<div class="ts-2"><label>Quanto pagaria por mês?<select name="preco"><option value="">—</option><?php foreach ( array( 'Até R$ 50', 'R$ 50 a R$ 100', 'R$ 100 a R$ 200', 'R$ 200 a R$ 400', 'Mais de R$ 400', 'Não pagaria' ) as $o ) { echo '<option' . ( ( $_POST['preco'] ?? '' ) === $o ? ' selected' : '' ) . '>' . esc_html( $o ) . '</option>'; } ?></select></label>
	<label>De 0 a 10, recomendaria a um colega?<select name="nps"><option value="">—</option><?php for ( $i = 10; $i >= 0; $i-- ) { echo '<option' . ( isset( $_POST['nps'] ) && $_POST['nps'] === (string) $i ? ' selected' : '' ) . '>' . $i . '</option>'; } ?></select></label></div>
	<label>Depoimento <small>(opcional: em poucas linhas, o que você diria a um colega sobre a Desenvolve)</small><textarea name="depoimento" rows="3"><?php echo esc_textarea( wp_unslash( $_POST['depoimento'] ?? '' ) ); ?></textarea></label>
	<label class="ts-c"><input type="checkbox" name="autoriza" value="1"<?php checked( ! empty( $_POST['autoriza'] ) ); ?>> <span>Autorizo a Desenvolve a divulgar meu depoimento, assinado como:</span></label>
	<label><input name="assinatura" placeholder="Ex.: Maria Silva, consultora de licitações" value="<?php echo esc_attr( wp_unslash( $_POST['assinatura'] ?? '' ) ); ?>"></label>
	<button class="ts-b" type="submit">Enviar avaliação e liberar meu mês grátis</button>
</form>
	<?php return ob_get_clean() . dv_ts_css();
} );

/* ---------- Faixa para o testador nas páginas da plataforma ---------- */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) { return; } $uid = get_current_user_id(); if ( ! dv_ts_e_testador( $uid ) || get_user_meta( $uid, 'dv_tester_av', true ) ) { return; }
	if ( strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/plataforma' ) === false && strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/painel' ) === false ) { return; }
	if ( strpos( (string) $_SERVER['REQUEST_URI'], '/avaliacao' ) !== false ) { return; }
	$v = (string) get_user_meta( $uid, 'dv_vence', true ); $dl = $v ? (int) round( ( strtotime( $v ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : 0;
	echo '<div style="position:fixed;left:16px;right:16px;bottom:16px;z-index:9998;max-width:760px;margin:0 auto;background:#0C0F13;color:#F4F2EC;border:1px solid #C9A45C;border-radius:14px;padding:12px 16px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;font-family:Hanken Grotesk,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35)"><span>🧪 <b>Teste de especialista</b> · ' . ( $dl > 0 ? 'faltam ' . $dl . ' dia(s)' : ( $dl === 0 ? 'último dia' : 'terminou' ) ) . '. Avalie e ganhe <b>1 mês de assinatura</b>.</span><a href="/plataforma/avaliacao/" style="background:#C9A45C;color:#241A06;border-radius:10px;padding:8px 14px;font-weight:700;text-decoration:none">Avaliar agora</a></div>';
}, 95 );
/* Lembrete por e-mail 2 dias antes do fim e no último dia */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_ts_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 12:30 UTC' ), 'daily', 'dv_ts_diario' ); } } );
add_action( 'dv_ts_diario', function () {
	$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br'; $hoje = current_time( 'Y-m-d' );
	foreach ( dv_ts_codigos() as $cod => $c ) {
		if ( empty( $c['uid'] ) || ! empty( $c['avaliado'] ) ) { continue; } $uid = (int) $c['uid']; $u = get_userdata( $uid ); if ( ! $u ) { continue; }
		$v = (string) get_user_meta( $uid, 'dv_vence', true ); $dl = (int) round( ( strtotime( $v ) - strtotime( $hoje ) ) / DAY_IN_SECONDS ); $env = (array) get_user_meta( $uid, 'dv_tester_lemb', true );
		/* dia seguinte à ativação, sem nenhuma consulta: empurrão para o primeiro uso */
		$da = ! empty( $c['ativado'] ) ? (int) floor( ( strtotime( $hoje ) - strtotime( substr( (string) $c['ativado'], 0, 10 ) ) ) / DAY_IN_SECONDS ) : -1;
		if ( $da >= 1 && $da <= 3 && empty( $env['u1'] ) ) { global $wpdb; $nu = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_uso WHERE user_id=%d", $uid ) );
			if ( $nu === 0 ) { $env['u1'] = $hoje; update_user_meta( $uid, 'dv_tester_lemb', $env );
				wp_mail( $u->user_email, 'Seu primeiro Raio-X leva 1 minuto', 'Olá, ' . $u->first_name . "!\n\nSeu acesso de especialista à Desenvolve está ativo, mas você ainda não fez nenhuma consulta. Três sugestões para começar:\n\n1. Raio-X de um concorrente: cole o CNPJ e veja sanções, dívida ativa, processos, contratos e como ele dá preço.\n" . home_url( '/plataforma/raio-x/' ) . "\n\n2. Gestão de licitações: cole o link de um edital do PNCP e a plataforma calcula os prazos e avisa você.\n" . home_url( '/plataforma/licitacoes/' ) . "\n\n3. Notícias de licitações do seu ramo e dos seus órgãos.\n" . home_url( '/plataforma/noticias/' ) . "\n\nNo fim do teste, a sua avaliação (uns 10 minutos) vale 1 mês de assinatura.\n\nObrigado!\nEquipe Desenvolve", array( 'From: Desenvolve <' . $from . '>' ) ); } }
		foreach ( array( 2, 0, -3 ) as $m ) { if ( $dl === $m && empty( $env[ $m ] ) ) { $env[ $m ] = $hoje; update_user_meta( $uid, 'dv_tester_lemb', $env );
			wp_mail( $u->user_email, $m > 0 ? 'Faltam 2 dias do seu teste: avalie e ganhe 1 mês grátis' : ( $m === 0 ? 'Último dia do seu teste na Desenvolve' : 'Ainda dá tempo: sua avaliação vale 1 mês grátis' ), 'Olá, ' . $u->first_name . "!\n\n" . ( $m >= 0 ? 'Seu teste de especialista na Desenvolve está acabando.' : 'Seu teste terminou, mas a avaliação continua aberta.' ) . " Conte o que achou (uns 10 minutos) e liberamos na hora 1 mês de assinatura, com 80 créditos:\n" . home_url( '/plataforma/avaliacao/' ) . "\n\nObrigado!\nEquipe Desenvolve", array( 'From: Desenvolve <' . $from . '>' ) ); } }
	}
} );

/* ---------- Admin: gerar convites e ver avaliações ---------- */
add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Testadores', 'Testadores', 'manage_options', 'dv-testadores', function () {
		$C = dv_ts_codigos();
		if ( isset( $_POST['dv_ts_gerar'] ) && check_admin_referer( 'dv_ts_gerar' ) ) {
			$rot = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['rotulos'] ?? '' ) ) ) ) ); $n = max( count( $rot ), min( 50, (int) ( $_POST['qtd'] ?? 0 ) ) );
			for ( $i = 0; $i < $n; $i++ ) { do { $k = strtoupper( wp_generate_password( 8, false, false ) ); } while ( isset( $C[ $k ] ) ); $C[ $k ] = array( 'criado' => current_time( 'mysql' ), 'rotulo' => mb_substr( $rot[ $i ] ?? '', 0, 80 ) ); }
			update_option( 'dv_testers', $C, false ); echo '<div class="updated"><p>' . $n . ' convite(s) gerado(s).</p></div>';
		}
		echo '<div class="wrap"><h1>Testadores especialistas</h1><p>Cada convite vale para uma pessoa: ' . DV_TS_DIAS . ' dias de uso ilimitado (trava de 60 consultas por dia; depois disso usa os ' . DV_TS_CREDITOS . ' créditos de reserva). Ao enviar a avaliação, ganha 1 mês de assinatura (80 créditos).</p>';
		echo '<form method="post" style="background:#fff;border:1px solid #ddd;padding:12px 16px;margin:12px 0">'; wp_nonce_field( 'dv_ts_gerar' );
		echo '<p><b>Gerar convites</b>: escreva um nome por linha (aparece no convite) ou só a quantidade.</p><p><textarea name="rotulos" rows="4" cols="50" placeholder="Maria Silva&#10;João Souza"></textarea></p><p>Quantidade (se não colocar nomes): <input type="number" name="qtd" min="0" max="50" value="0" style="width:70px"> <button class="button button-primary" name="dv_ts_gerar" value="1">Gerar</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>Convite</th><th>Nome</th><th>Link</th><th>Ativou</th><th>Avaliou</th><th>Recomendação</th><th>Quanto pagaria</th></tr></thead><tbody>';
		foreach ( array_reverse( $C, true ) as $k => $c ) {
			$av = ! empty( $c['uid'] ) ? json_decode( (string) get_user_meta( (int) $c['uid'], 'dv_tester_av', true ), true ) : null; $lk = home_url( '/teste/?convite=' . $k );
			echo '<tr><td><code>' . esc_html( $k ) . '</code></td><td>' . esc_html( $c['nome'] ?? $c['rotulo'] ?? '' ) . ( ! empty( $c['email'] ) ? '<br><small>' . esc_html( $c['email'] ) . '</small>' : '' ) . '</td><td><input readonly value="' . esc_attr( $lk ) . '" style="width:330px" onclick="this.select()"></td><td>' . ( ! empty( $c['ativado'] ) ? esc_html( mysql2date( 'd/m/Y', $c['ativado'] ) ) : '—' ) . '</td><td>' . ( ! empty( $c['avaliado'] ) ? esc_html( mysql2date( 'd/m/Y', $c['avaliado'] ) ) : '—' ) . '</td><td>' . esc_html( $av['nps'] ?? '' ) . '</td><td>' . esc_html( $av['preco'] ?? '' ) . '</td></tr>';
			if ( $av ) { $F = dv_ts_ferramentas(); $ns = array(); foreach ( (array) $av['notas'] as $f => $v ) { if ( $v !== null ) { $ns[] = ( $F[ $f ] ?? $f ) . ': ' . $v; } } echo '<tr><td></td><td colspan="6"><b>Notas:</b> ' . esc_html( implode( ' · ', $ns ) ) . '<br><b>Ajudou:</b> ' . esc_html( $av['ajudou'] ) . '<br><b>Faltou:</b> ' . esc_html( $av['faltou'] ) . ( $av['erros'] ? '<br><b>Erros:</b> ' . esc_html( $av['erros'] ) : '' ) . ( $av['depoimento'] ? '<br><b>Depoimento' . ( $av['autoriza'] ? ' (autorizado: ' . esc_html( $av['assinatura'] ) . ')' : ' (não autorizado)' ) . ':</b> ' . esc_html( $av['depoimento'] ) : '' ) . '</td></tr>'; }
		}
		if ( ! $C ) { echo '<tr><td colspan="7">Nenhum convite ainda.</td></tr>'; }
		echo '</tbody></table></div>';
	} );
}, 25 );
