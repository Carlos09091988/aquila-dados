/**
 * Desenvolve — Análise de risco com IA (Raio-X e Due Diligence)
 * O assinante clica em "Análise de risco com IA"; o relatório vai para a IA (API da Anthropic)
 * junto com o roteiro de análise definido pela Desenvolve (editável em Desenvolve > IA).
 * A chave da API é colada pelo administrador na tela Desenvolve > IA (nunca no código).
 */

function dv_ia_cfg( $k ) {
	$d = array(
		'modelo'   => 'claude-sonnet-5-5',
		'creditos' => 2,
		'prompt'   => "Você é analista sênior de due diligence de fornecedores da Desenvolve Estratégias, consultoria para empresas que vendem ao Poder Público e para quem contrata com elas.\n\nAnalise SOMENTE os dados do relatório abaixo (bases públicas oficiais e imprensa). Não invente fatos, valores, processos ou nomes. Quando algo não constar, diga que não consta. Cite de qual parte do relatório veio cada evidência.\n\nResponda em português do Brasil, em Markdown, com estas seções:\n\n## Conclusão\nNível de risco (Baixo, Moderado, Alto ou Crítico) e uma frase que resuma o porquê.\n\n## Principais riscos\nAté 7 itens, do mais grave ao menos grave, cada um com a evidência e o impacto prático.\n\n## Habilitação em licitações\nO que pode impedir ou dificultar a habilitação pela Lei 14.133/2021 (regularidade fiscal, social e trabalhista; qualificação econômico-financeira; sanções do art. 156; impedimentos do art. 14). Diga o que precisa ser conferido em certidão.\n\n## Execução de contratos\nSinais de risco na execução: dívidas, execuções trabalhistas, reclamações, paralisações, rescisões, penalidades, notícias.\n\n## Grupo econômico e vínculos\nO que os vínculos societários, de contato, solidários e parentes podem indicar (por exemplo: sucessão de empresas, interposta pessoa, atuação coordenada em licitações). Trate como indício, nunca como prova.\n\n## O que verificar antes de decidir\nChecklist objetivo de documentos e consultas complementares.\n\n## Limites desta análise\nO que as bases não cobrem (processos em segredo de justiça, diários estaduais ausentes, homônimos, defasagem das bases).\n\nRegras: não afirme culpa nem crime; use \"consta\", \"há registro\", \"indício\". Não chame a Lei 14.133/2021 de \"nova lei\". Isto não é parecer jurídico; termine recomendando avaliação profissional quando o risco for Alto ou Crítico, mencionando que um consultor da Desenvolve pode ajudar.",
	);
	$v = get_option( 'dv_ia_' . $k, null );
	return ( $v === null || $v === '' ) ? $d[ $k ] : $v;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/ia', array( 'methods' => 'POST',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) {
			$uid = get_current_user_id(); $tipo = $r['tipo'] === 'dossie' ? 'dossie' : 'raiox';
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] ); $txt = (string) $r['conteudo'];
			if ( strlen( $cnpj ) !== 14 || mb_strlen( $txt ) < 200 ) { return new WP_Error( 'dv_ia_param', 'Gere o relatório antes de pedir a análise.', array( 'status' => 400 ) ); }
			$txt = mb_substr( $txt, 0, 150000 );
			$ck = 'dv_ia_' . $uid . '_' . $tipo . '_' . $cnpj; $c = get_transient( $ck );
			if ( $c ) { return array( 'analise' => $c, 'cache' => true, 'saldo' => dv_saldo( $uid ) ); }
			$key = get_option( 'dv_ia_key' );
			if ( ! $key ) { return new WP_Error( 'dv_ia_off', 'A análise com IA ainda está sendo ativada pela Desenvolve.', array( 'status' => 503 ) ); }
			$n = max( 0, (int) dv_ia_cfg( 'creditos' ) );
			if ( ! dv_is_master( $uid ) && $n && dv_saldo( $uid )['total'] < $n ) { return new WP_Error( 'dv_sem_credito', 'A análise com IA usa ' . $n . ' créditos e seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			set_time_limit( 180 );
			$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 'timeout' => 150,
				'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
				'body' => wp_json_encode( array( 'model' => dv_ia_cfg( 'modelo' ), 'max_tokens' => 4000, 'system' => dv_ia_cfg( 'prompt' ),
					'messages' => array( array( 'role' => 'user', 'content' => "Relatório (" . ( $tipo === 'dossie' ? 'Due Diligence completa' : 'Raio-X do fornecedor' ) . ") do CNPJ $cnpj, gerado em " . current_time( 'd/m/Y' ) . ":\n\n" . $txt ) ) ) ) ) );
			if ( is_wp_error( $resp ) ) { return new WP_Error( 'dv_ia_rede', 'A IA não respondeu agora. Tente de novo em instantes.', array( 'status' => 502 ) ); }
			$j = json_decode( wp_remote_retrieve_body( $resp ), true );
			$out = ''; foreach ( (array) ( $j['content'] ?? array() ) as $b ) { if ( ( $b['type'] ?? '' ) === 'text' ) { $out .= $b['text']; } }
			if ( ! $out ) { return new WP_Error( 'dv_ia_erro', 'A IA não conseguiu analisar agora. Tente de novo em instantes.', array( 'status' => 502 ) ); }
			for ( $i = 1; $i <= $n; $i++ ) { $u = dv_usar( $uid, 'ia', 'ia|' . $tipo . '|' . $cnpj . '|' . $i, $i === 1 ? 'Análise IA ' . $cnpj : '' ); if ( is_wp_error( $u ) ) { return $u; } }
			set_transient( $ck, $out, 30 * DAY_IN_SECONDS );
			return array( 'analise' => $out, 'saldo' => dv_saldo( $uid ) );
		} ) );
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Análise com IA', 'Análise com IA', 'manage_options', 'dv-ia', function () {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_ia' ) ) {
			if ( ! empty( $_POST['key'] ) ) { update_option( 'dv_ia_key', trim( wp_unslash( $_POST['key'] ) ), false ); }
			update_option( 'dv_ia_modelo', sanitize_text_field( wp_unslash( $_POST['modelo'] ?? '' ) ), false );
			update_option( 'dv_ia_creditos', max( 0, (int) ( $_POST['creditos'] ?? 2 ) ), false );
			update_option( 'dv_ia_prompt', sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), false );
			echo '<div class="updated"><p>Salvo.</p></div>';
		}
		echo '<div class="wrap"><h1>Análise de risco com IA</h1><p>O botão aparece no Raio-X e na Due Diligence. O relatório é enviado à IA com o roteiro abaixo. Deixe o roteiro em branco para voltar ao padrão.</p><form method="post">';
		wp_nonce_field( 'dv_ia' );
		echo '<table class="form-table"><tr><th>Chave da API da Anthropic</th><td><input type="password" name="key" class="regular-text" autocomplete="new-password" placeholder="' . ( get_option( 'dv_ia_key' ) ? 'Chave salva. Cole outra só para trocar.' : 'Cole a chave (console.anthropic.com)' ) . '"></td></tr>';
		echo '<tr><th>Modelo</th><td><input name="modelo" class="regular-text" value="' . esc_attr( dv_ia_cfg( 'modelo' ) ) . '"></td></tr>';
		echo '<tr><th>Créditos por análise</th><td><input type="number" name="creditos" min="0" value="' . esc_attr( dv_ia_cfg( 'creditos' ) ) . '"></td></tr>';
		echo '<tr><th>Roteiro de análise (prompt)</th><td><textarea name="prompt" rows="26" class="large-text code">' . esc_textarea( dv_ia_cfg( 'prompt' ) ) . '</textarea></td></tr></table>';
		submit_button( 'Salvar' ); echo '</form></div>';
	} );
}, 21 );
