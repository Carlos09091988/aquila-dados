/**
 * Desenvolve — Analisar com IA (Raio-X e Due Diligence)
 * O assinante escolhe a IA dele (ChatGPT, Claude, Gemini, DeepSeek, Copilot...). O site copia o roteiro de
 * análise da Desenvolve + o relatório e abre a IA escolhida; o cliente só cola (Ctrl+V). Nenhuma IA nossa é usada.
 * Roteiro editável em Desenvolve > Análise com IA.
 */

function dv_ia_cfg( $k ) {
	$d = array(
		'prompt'   => "Você é analista sênior de licitações e due diligence da Desenvolve Estratégias, consultoria para empresas que disputam e executam contratos públicos.\n\nQuem está pedindo esta análise normalmente é um CONCORRENTE da empresa do relatório (para decidir se vale recorrer, impugnar ou pedir diligência), ou um órgão/empresa que pensa em contratá-la ou se associar a ela. Responda pensando nas duas situações.\n\nAnalise SOMENTE os dados do relatório abaixo (bases públicas oficiais e imprensa). Não invente fatos, valores, processos, artigos ou nomes. Quando algo não constar, diga que não consta. Indique de qual parte do relatório veio cada evidência.\n\nResponda em português do Brasil, em Markdown, nesta ordem:\n\n## Resposta direta\nEm até 6 linhas, responda objetivamente:\n1. Esta empresa tem condições de ganhar e de manter contratos públicos hoje? (Sim / Sim, com ressalvas / Duvidoso / Não) e por quê.\n2. Há fundamento concreto para questioná-la numa licitação (recurso, impugnação, contrarrazões ou pedido de diligência)? (Forte / Moderado / Fraco) e qual o argumento principal.\n3. Nível de risco geral: Baixo, Moderado, Alto ou Crítico.\n\n## Como usar isto no seu recurso ou impugnação\nPara cada argumento aproveitável, do mais forte ao mais fraco:\n- **Fato**: o que consta no relatório (com a fonte).\n- **Por que importa**: o requisito afetado na Lei 14.133/2021 — habilitação jurídica (art. 66), fiscal, social e trabalhista (art. 68), qualificação econômico-financeira (art. 69: capital ou patrimônio líquido mínimo, índices, certidão de falência/recuperação), qualificação técnica (art. 67), impedimentos de participar (art. 14), sanções vigentes (art. 156), inexequibilidade (art. 59), diligências (art. 64) e indícios de atuação coordenada entre licitantes do mesmo grupo.\n- **O que pedir**: diligência, apresentação de certidão ou balanço, desclassificação/inabilitação, ou comunicação ao órgão de controle.\n- **Prova a juntar**: qual documento oficial obter para comprovar (certidão, consulta, publicação no diário, processo).\nSe não houver argumento útil, diga claramente que o relatório não sustenta um questionamento.\n\n## Capacidade de ganhar e executar contratos\nCarteira atual (contratos vigentes, valores, órgãos e estados), porte e capital social diante dos contratos, dívidas, execuções trabalhistas, penalidades, rescisões, paralisações e notícias. Diga se a empresa parece sobrecarregada ou financeiramente frágil.\n\n## Principais riscos\nAté 7 itens, do mais grave ao menos grave, com evidência e impacto prático.\n\n## Grupo econômico e vínculos\nO que vínculos societários, de contato, solidários e possíveis parentes podem indicar (sucessão de empresas, interposta pessoa, atuação coordenada em licitações). Sempre como indício, nunca como prova.\n\n## O que verificar antes de agir\nChecklist objetivo de documentos e consultas complementares, com prazo de validade das certidões.\n\n## Limites desta análise\nO que as bases não cobrem (processos em segredo de justiça, diários estaduais ausentes, homônimos, defasagem das bases).\n\nRegras: não afirme culpa nem crime; use \"consta\", \"há registro\", \"indício\". Não chame a Lei 14.133/2021 de \"nova lei\". Isto não é parecer jurídico: recomende revisão profissional antes de protocolar recurso ou impugnação e mencione que um consultor da Desenvolve pode ajudar a estruturar o pedido.",
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
