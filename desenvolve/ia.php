/**
 * Desenvolve — Analisar com IA (Raio-X e Due Diligence)
 * O assinante escolhe a IA dele (ChatGPT, Claude, Gemini, DeepSeek, Copilot...). O site copia o roteiro de
 * análise da Desenvolve + o relatório e abre a IA escolhida; o cliente só cola (Ctrl+V). Nenhuma IA nossa é usada.
 * Roteiro editável em Desenvolve > Análise com IA.
 */

function dv_ia_cfg( $k ) {
	$d = array(
		'prompt'   => "Você é analista sênior de due diligence de fornecedores da Desenvolve Estratégias, consultoria para empresas que vendem ao Poder Público e para quem contrata com elas.\n\nAnalise SOMENTE os dados do relatório abaixo (bases públicas oficiais e imprensa). Não invente fatos, valores, processos ou nomes. Quando algo não constar, diga que não consta. Cite de qual parte do relatório veio cada evidência.\n\nResponda em português do Brasil, em Markdown, com estas seções:\n\n## Conclusão\nNível de risco (Baixo, Moderado, Alto ou Crítico) e uma frase que resuma o porquê.\n\n## Principais riscos\nAté 7 itens, do mais grave ao menos grave, cada um com a evidência e o impacto prático.\n\n## Habilitação em licitações\nO que pode impedir ou dificultar a habilitação pela Lei 14.133/2021 (regularidade fiscal, social e trabalhista; qualificação econômico-financeira; sanções do art. 156; impedimentos do art. 14). Diga o que precisa ser conferido em certidão.\n\n## Execução de contratos\nSinais de risco na execução: dívidas, execuções trabalhistas, reclamações, paralisações, rescisões, penalidades, notícias.\n\n## Grupo econômico e vínculos\nO que os vínculos societários, de contato, solidários e parentes podem indicar (por exemplo: sucessão de empresas, interposta pessoa, atuação coordenada em licitações). Trate como indício, nunca como prova.\n\n## O que verificar antes de decidir\nChecklist objetivo de documentos e consultas complementares.\n\n## Limites desta análise\nO que as bases não cobrem (processos em segredo de justiça, diários estaduais ausentes, homônimos, defasagem das bases).\n\nRegras: não afirme culpa nem crime; use \"consta\", \"há registro\", \"indício\". Não chame a Lei 14.133/2021 de \"nova lei\". Isto não é parecer jurídico; termine recomendando avaliação profissional quando o risco for Alto ou Crítico, mencionando que um consultor da Desenvolve pode ajudar.",
	);
	$v = get_option( 'dv_ia_' . $k, null );
	return ( $v === null || $v === '' ) ? $d[ $k ] : $v;
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Análise com IA', 'Análise com IA', 'manage_options', 'dv-ia', function () {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_ia' ) ) {
			update_option( 'dv_ia_prompt', sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), false );
			echo '<div class="updated"><p>Salvo.</p></div>';
		}
		echo '<div class="wrap"><h1>Análise de risco com IA</h1><p>O botão aparece no Raio-X e na Due Diligence. O cliente escolhe a IA dele; o site copia este roteiro junto com o relatório e abre a IA para ele colar. Deixe o roteiro em branco para voltar ao padrão.</p><form method="post">';
		wp_nonce_field( 'dv_ia' );
		echo '<table class="form-table">';
		echo '<tr><th>Roteiro de análise (prompt)</th><td><textarea name="prompt" rows="26" class="large-text code">' . esc_textarea( dv_ia_cfg( 'prompt' ) ) . '</textarea></td></tr></table>';
		submit_button( 'Salvar' ); echo '</form></div>';
	} );
}, 21 );

/* entrega o roteiro para a página das ferramentas */
add_action( 'wp_footer', function () {
	global $post; if ( ! is_user_logged_in() || ! $post || ! has_shortcode( $post->post_content, 'dv_ctx' ) ) { return; }
	echo '<script>window.DVIA=' . wp_json_encode( dv_ia_cfg( 'prompt' ) ) . ';</script>';
}, 5 );
