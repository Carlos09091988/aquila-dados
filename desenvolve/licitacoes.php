/**
 * Desenvolve — Gestão de licitações + Análise de edital com a IA do cliente
 * - Quadro das licitações do assinante (importa do PNCP pelo link), com prazos da Lei 14.133/2021
 *   (impugnação e esclarecimento: até 3 dias úteis antes da abertura, art. 164) e avisos por e-mail.
 * - "Analisar edital": junta o roteiro de análise da Desenvolve (por tipo de objeto) + dados da licitação
 *   + texto do edital e leva para a IA que o cliente já usa. Nenhuma IA nossa é usada.
 * Incluso na assinatura. Roteiro base editável em Desenvolve > Roteiro de edital.
 */

define( 'DV_LIC_MAX', 300 );

function dv_lic_itens( $uid ) { $l = json_decode( get_user_meta( $uid, 'dv_lic', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_lic_salvar_itens( $uid, $l ) { update_user_meta( $uid, 'dv_lic', wp_slash( wp_json_encode( array_values( $l ), JSON_UNESCAPED_UNICODE ) ) ); }

/* Feriados nacionais (confira feriados locais do órgão) */
function dv_lic_feriados() {
	return array( '2026-01-01','2026-04-03','2026-04-21','2026-05-01','2026-09-07','2026-10-12','2026-11-02','2026-11-15','2026-11-20','2026-12-25',
		'2027-01-01','2027-03-26','2027-04-21','2027-05-01','2027-09-07','2027-10-12','2027-11-02','2027-11-15','2027-11-20','2027-12-25',
		'2028-01-01','2028-04-14','2028-04-21','2028-05-01','2028-09-07','2028-10-12','2028-11-02','2028-11-15','2028-11-20','2028-12-25' );
}
function dv_lic_util( $d ) { $w = (int) gmdate( 'N', strtotime( $d ) ); return $w < 6 && ! in_array( $d, dv_lic_feriados(), true ); }
/** n-ésimo dia útil antes da data (art. 164: "até 3 dias úteis antes da data de abertura") */
function dv_lic_uteis_antes( $data, $n ) { $d = $data; $c = 0; while ( $c < $n ) { $d = gmdate( 'Y-m-d', strtotime( $d . ' -1 day' ) ); if ( dv_lic_util( $d ) ) { $c++; } } return $d; }

function dv_lic_categorias() {
	return array(
		'mao_obra'    => 'Serviços com dedicação exclusiva de mão de obra (limpeza, vigilância, portaria, apoio)',
		'servicos'    => 'Serviços comuns sem dedicação exclusiva',
		'engenharia'  => 'Obras e serviços de engenharia',
		'alimentacao' => 'Alimentação (escolar, hospitalar, prisional, refeições)',
		'bens'        => 'Fornecimento de bens / materiais',
		'saude'       => 'Saúde (medicamentos, insumos, equipamentos, serviços)',
		'ti'          => 'Tecnologia da informação',
	);
}
function dv_lic_detectar_cat( $obj ) {
	$o = strtoupper( remove_accents( (string) $obj ) );
	if ( preg_match( '/(AQUISICAO|FORNECIMENTO|COMPRA).{0,60}GENEROS|GENEROS ALIMENTICIOS E MATERIA/', $o ) ) { return 'bens'; }
	if ( preg_match( '/ALIMENTA|MERENDA|REFEIC|NUTRI|COZINHA|GENEROS ALIMENT/', $o ) ) { return 'alimentacao'; }
	if ( preg_match( '/OBRA|ENGENHARIA|REFORMA|CONSTRU|PAVIMENT|DRENAGEM|EDIFICA/', $o ) ) { return 'engenharia'; }
	if ( preg_match( '/LIMPEZA|CONSERVACAO|VIGILANCIA|PORTARIA|RECEPCAO|MAO DE OBRA|DEDICACAO EXCLUSIVA|TERCEIRIZ|COPEIRAG|JARDINAG|MOTORISTA/', $o ) ) { return 'mao_obra'; }
	if ( preg_match( '/MEDICAMENT|HOSPITAL|SAUDE|ODONTO|LABORATOR|INSUMOS MEDIC|EQUIPAMENTOS MEDIC/', $o ) ) { return 'saude'; }
	if ( preg_match( '/SOFTWARE|SISTEMA|INFORMATICA|TECNOLOGIA DA INFORMACAO|COMPUTADOR|LICENC|NUVEM|REDE LOGICA/', $o ) ) { return 'ti'; }
	if ( preg_match( '/AQUISICAO|FORNECIMENTO|COMPRA|MATERIA|EQUIPAMENTO|MOBILIARIO|VEICULO/', $o ) ) { return 'bens'; }
	return 'servicos';
}

/** Roteiro base (editável) + bloco específico do tipo de objeto */
function dv_lic_prompt_base() {
	$p = get_option( 'dv_lic_prompt', '' );
	if ( $p ) { return str_replace( array( ' da Desenvolve Estratégias, consultoria para empresas que disputam e executam contratos públicos.', ' da Desenvolve Estratégias', ' e mencione que um consultor da Desenvolve pode ajudar a estruturar o pedido.', ' Lembre que um consultor da Desenvolve pode revisar a análise e redigir a impugnação ou o recurso.', 'um consultor da Desenvolve', 'da Desenvolve', 'Desenvolve' ), array( ', especialista em empresas que disputam e executam contratos públicos.', '', ' e recomende a revisão por um consultor especializado.', ' Ao final, recomende a revisão por um consultor especializado antes de impugnar ou recorrer.', 'um consultor especializado', '', 'especialista' ), $p ); } // o pedido vai para a IA do cliente: sem citar a marca
	return "Você é analista sênior de licitações, especialista na Lei 14.133/2021 e na jurisprudência do TCU. Vou te passar os dados de uma licitação e o texto do edital (ou o PDF anexo). Faça uma ANÁLISE, não um resumo: leia o edital inteiro, incluindo termo de referência, minuta do contrato e anexos, e responda pensando em uma empresa que quer decidir se participa, como se prepara e o que pode questionar.\n\nRegras: use só o que está no edital; cite sempre o item/cláusula e a página de onde tirou cada informação; quando o edital não falar de algo, escreva \"O edital não trata\" (isso também é informação). Não invente artigos nem números. Não chame a Lei 14.133/2021 de \"nova lei\". Isto não é parecer jurídico.\n\nEntregue em português, em Markdown, nesta ordem:\n\n## 1. Decisão rápida\nEm até 8 linhas: vale a pena participar? (Sim / Sim, com cuidados / Avaliar melhor / Não). Os 3 motivos principais. Nível de risco do contrato (baixo, médio, alto). Prazo mais urgente.\n\n## 2. Ficha da licitação\nTabela: órgão; número e processo; modalidade e critério de julgamento; modo de disputa (aberto, fechado, aberto-fechado); objeto; lotes/itens e se é por item, lote ou global; valor estimado (ou se é sigiloso); data e hora da sessão; plataforma; prazo para impugnar e pedir esclarecimento (até 3 dias úteis antes da abertura, art. 164); prazo de envio de documentos após convocação; vigência do contrato e possibilidade de prorrogação; local de execução; reserva ou cota para ME/EPP (LC 123/2006); se aceita consórcio e subcontratação; se é registro de preços (e se admite adesão/carona).\n\n## 3. Checklist do que o edital EXIGE e do que NÃO exige\nTabela com as colunas: Exigência | Exige? (Sim / Não / Não trata) | Detalhe e item do edital | Observação. Percorra no mínimo:\n- Vistoria técnica: obrigatória ou facultativa? Pode ser substituída por declaração do responsável técnico (art. 63, §§ 2º e 3º)? Como agendar e prazo.\n- Garantia de proposta (até 1% do valor estimado, art. 58) e garantia contratual (até 5%, ou até 10% justificado, art. 98; obras de grande vulto até 30%, art. 99): percentual e modalidades aceitas.\n- Amostra, prova de conceito, catálogo, ficha técnica, marca/modelo.\n- Habilitação jurídica, fiscal, social e trabalhista (certidões exigidas e validades).\n- Qualificação econômico-financeira: balanço, índices (quais e os valores), capital social ou patrimônio líquido mínimo (até 10% do valor estimado, art. 69, § 4º), certidão de falência e prazo de emissão.\n- Qualificação técnica: atestados (parcelas de maior relevância e quantitativos mínimos), registro em conselho, responsável técnico, CAT, equipe mínima, certificações (ISO etc.), licenças e alvarás.\n- Declarações exigidas (listar todas).\n- Planilha de custos, composição de BDI, proposta detalhada, prazo de validade da proposta.\n- Visita, reunião ou apresentação presencial.\n- Seguro, programa de integridade, sustentabilidade, carta de solidariedade/credenciamento do fabricante.\nTermine com a lista \"Documentos que preciso providenciar agora\", em ordem de urgência.\n\n## 4. Pontos questionáveis (impugnação ou esclarecimento)\nPara cada exigência que possa restringir a competição ou que esteja confusa: o que o edital diz (item), por que pode ser ilegal ou excessivo (cite o dispositivo: arts. 9º, 14, 40, 41, 47, 62 a 70 da Lei 14.133/2021 e jurisprudência do TCU que você conheça com segurança), força do argumento (forte, moderado, fraco) e o pedido a fazer. Sinais típicos: atestado de quantitativo acima de 50% das parcelas de maior relevância ou parcela que não tem valor significativo (art. 67, §§ 1º e 2º, que fixa parcela de maior relevância em 4% ou mais do valor total); exigência de faturamento mínimo ou índice de rentabilidade/lucratividade (vedados pelo art. 69, § 2º); índices sem justificativa no processo; vistoria obrigatória sem opção de declaração; marca sem justificativa; amostra de todos os licitantes em vez de só do vencedor; prazo de publicidade abaixo do mínimo (art. 55); exigência de registro ou certificação sem previsão legal; aglutinação indevida de itens (parcelamento, art. 40, V, b, e art. 47); garantia acima dos limites. Depois, liste de 3 a 8 PERGUNTAS DE ESCLARECIMENTO prontas para enviar.\n\n## 5. Riscos do contrato e da execução\nPrazos de início e de pagamento, medição e aceite, penalidades e multas (percentuais), matriz de riscos, reajuste e repactuação (índice, data-base, periodicidade), conta vinculada ou pagamento pelo fato gerador, retenções, obrigações que geram custo escondido (uniformes, EPIs, veículos, sistemas, plantões, seguro), possibilidade de rescisão e de supressão de quantidades.\n\n## 6. Preço\nOnde está o valor de referência, se é sigiloso, critérios de aceitabilidade e de inexequibilidade, se pede planilha, se há valor máximo por item, e cuidados para não ser desclassificado.\n\n## 7. Calendário\nTabela com todas as datas e prazos em ordem: impugnação, esclarecimento, vistoria, sessão, envio de documentos e proposta ajustada, recurso (intenção e razões: 3 dias úteis, art. 165), assinatura e início da execução.\n\n## 8. Próximos passos\nLista objetiva do que fazer hoje, nesta semana e antes da sessão.\n\nAo final, pergunte se quero o relatório em PDF e, se eu pedir, gere um PDF bem formatado com todas as seções acima. Ao final, recomende a revisão por um consultor especializado antes de impugnar ou recorrer.";
}
function dv_lic_prompt_cat( $c ) {
	$x = array(
		'mao_obra'    => "Atenção específica (serviços com dedicação exclusiva de mão de obra): convenção coletiva usada como base, piso, data-base e sindicato; jornada e escalas; quantidade de postos e cargos; benefícios exigidos (vale-alimentação, plano de saúde, cesta, PLR); planilha de custos e formação de preços (encargos, provisões, insumos, uniformes, EPIs, equipamentos); se há conta vinculada ou pagamento pelo fato gerador; regras de repactuação; exigências de experiência mínima em gestão de mão de obra (ex.: postos ou anos); responsabilidade trabalhista e retenções; riscos de convenção coletiva nova durante o contrato.",
		'servicos'    => "Atenção específica (serviços comuns): forma de medição e níveis mínimos de serviço (IMR/SLA) com glosas; equipe e qualificação exigidas; materiais e equipamentos inclusos; deslocamentos; regras de subcontratação.",
		'engenharia'  => "Atenção específica (obras e serviços de engenharia): regime de execução (empreitada por preço unitário, global, integral, contratação integrada ou semi-integrada); projeto básico/executivo disponível e completo; orçamento de referência (SINAPI, SICRO ou outro) e data-base; BDI e encargos sociais exigidos; critério de inexequibilidade (propostas abaixo de 75% do orçado, art. 59, § 4º, e garantia adicional); registro no CREA/CAU, ART/RRT, CAT e parcelas de maior relevância; cronograma físico-financeiro; medições; seguro-garantia com cláusula de retomada em obras de grande vulto (art. 99); licenças ambientais e responsabilidade por elas.",
		'alimentacao' => "Atenção específica (alimentação): modelo de serviço (gêneros, preparo, distribuição, cozinha própria ou do órgão); cardápios, per capita, gramaturas e número de refeições/dia; nutricionista responsável técnico e quadro de nutricionistas; regras sanitárias (vigilância sanitária, manual de boas práticas, controle de temperatura, amostras); para alimentação escolar, regras do PNAE/FNDE e cardápios elaborados pelo órgão; equipamentos e utensílios a cargo da contratada; transporte e logística; atestados de quantidade de refeições; reajuste dos gêneros.",
		'bens'        => "Atenção específica (fornecimento de bens): especificações que direcionam para uma marca; exigência de amostra, catálogo ou laudo; prazo e local de entrega, frete e descarga; garantia do produto e assistência técnica; registro de preços (quantidade mínima por pedido, validade da ata, adesão); registro na ANVISA/INMETRO quando aplicável; penalidade por atraso.",
		'saude'       => "Atenção específica (saúde): registro na ANVISA e AFE/AE da empresa e do fabricante; boas práticas de fabricação/distribuição; validade mínima na entrega; cadeia fria; responsável técnico farmacêutico; preço máximo CMED e CAP quando aplicável; comodato de equipamentos e assistência técnica.",
		'ti'          => "Atenção específica (tecnologia da informação): modelo de serviço (licença, SaaS, fábrica de software, sustentação, suporte); métricas (UST, ponto de função, horas); prova de conceito e seus critérios; certificações de equipe ou de parceiro do fabricante; níveis de serviço e glosas; propriedade intelectual e transição contratual; LGPD e segurança da informação; hospedagem e localização dos dados.",
	);
	return $x[ $c ] ?? $x['servicos'];
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'dv-painel', 'Roteiro de edital', 'Roteiro de edital', 'manage_options', 'dv-lic', function () {
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'dv_lic' ) ) { update_option( 'dv_lic_prompt', sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), false ); echo '<div class="updated"><p>Salvo.</p></div>'; }
		echo '<div class="wrap"><h1>Roteiro de análise de edital</h1><p>Usado no botão "Analisar edital" da Gestão de licitações. O bloco específico de cada tipo de objeto é somado automaticamente. Deixe em branco para voltar ao padrão.</p><form method="post">';
		wp_nonce_field( 'dv_lic' );
		echo '<textarea name="prompt" rows="30" class="large-text code">' . esc_textarea( dv_lic_prompt_base() ) . '</textarea>';
		submit_button( 'Salvar' ); echo '</form></div>';
	} );
}, 22 );

function dv_lic_calc( $it, $hoje ) {
	$it['prazo_imp'] = null; $it['dias_sessao'] = null; $it['dias_imp'] = null;
	if ( ! empty( $it['abertura'] ) ) {
		$d = substr( $it['abertura'], 0, 10 );
		$it['prazo_imp'] = dv_lic_uteis_antes( $d, 3 );
		$it['dias_imp'] = (int) floor( ( strtotime( $it['prazo_imp'] ) - strtotime( $hoje ) ) / 86400 );
		$it['dias_sessao'] = (int) floor( ( strtotime( $d ) - strtotime( $hoje ) ) / 86400 );
	}
	return $it;
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/lic', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' );
		$l = array_map( function ( $x ) use ( $hoje ) { return dv_lic_calc( $x, $hoje ); }, dv_lic_itens( $uid ) );
		$emp = function_exists( 'dv_docs_emps' ) ? dv_docs_emps( $uid ) : array();
		$prompts = array(); foreach ( dv_lic_categorias() as $k => $v ) { $prompts[ $k ] = dv_lic_prompt_cat( $k ); }
		return array( 'itens' => $l, 'hoje' => $hoje, 'cats' => dv_lic_categorias(), 'base' => dv_lic_prompt_base(), 'extra' => $prompts, 'empresas' => $emp );
	} ) );
	register_rest_route( $ns, '/lic/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_lic_itens( $uid ); $id = sanitize_key( (string) $r['id'] );
		$t = function ( $k, $n = 300 ) use ( $r ) { return mb_substr( sanitize_text_field( (string) $r[ $k ] ), 0, $n ); };
		$ab = preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?/', (string) $r['abertura'], $m ) ? $m[0] : '';
		$st = in_array( $r['status'], array( 'analise', 'participar', 'proposta', 'disputa', 'recurso', 'ganhou', 'perdeu', 'desistiu' ), true ) ? $r['status'] : 'analise';
		$cats = dv_lic_categorias(); $cat = isset( $cats[ $r['categoria'] ] ) ? $r['categoria'] : dv_lic_detectar_cat( $r['objeto'] );
		$it = array( 'orgao' => $t( 'orgao', 200 ), 'uf' => strtoupper( $t( 'uf', 2 ) ), 'municipio' => $t( 'municipio', 120 ), 'numero' => $t( 'numero', 80 ), 'modalidade' => $t( 'modalidade', 80 ),
			'objeto' => mb_substr( sanitize_textarea_field( (string) $r['objeto'] ), 0, 1500 ), 'abertura' => $ab, 'valor' => (float) $r['valor'], 'link' => esc_url_raw( (string) $r['link'] ),
			'pncp' => preg_replace( '/[^0-9\/-]/', '', (string) $r['pncp'] ), 'categoria' => $cat, 'status' => $st, 'empresa' => preg_replace( '/\D/', '', (string) $r['empresa'] ), 'notas' => mb_substr( sanitize_textarea_field( (string) $r['notas'] ), 0, 3000 ) );
		if ( ! $it['objeto'] && ! $it['orgao'] ) { return new WP_Error( 'dv_lic', 'Informe ao menos o órgão ou o objeto.', array( 'status' => 400 ) ); }
		$achou = false;
		foreach ( $l as &$x ) { if ( $x['id'] === $id ) { $x = array_merge( $x, $it ); $x['aviso'] = ( $x['abertura'] === ( $x['abertura_old'] ?? '' ) ) ? ( $x['aviso'] ?? '' ) : ''; $x['abertura_old'] = $x['abertura']; $achou = true; } }
		unset( $x );
		if ( ! $achou ) {
			if ( count( $l ) >= DV_LIC_MAX ) { return new WP_Error( 'dv_lic', 'Limite de ' . DV_LIC_MAX . ' licitações. Exclua as antigas.', array( 'status' => 400 ) ); }
			$id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $it + array( 'id' => $id, 'criado' => current_time( 'Y-m-d' ), 'aviso' => '', 'abertura_old' => $it['abertura'] );
		}
		dv_lic_salvar_itens( $uid, $l ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/lic/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] );
		dv_lic_salvar_itens( $uid, array_filter( dv_lic_itens( $uid ), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ); return array( 'ok' => true );
	} ) );
	/* Importa do PNCP pelo link (pncp.gov.br/app/editais/CNPJ/ANO/SEQ) ou pelo número de controle (CNPJ-1-SEQ/ANO) */
	register_rest_route( $ns, '/lic/pncp', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$s = (string) $r['ref'];
		if ( preg_match( '#(\d{14})/(\d{4})/(\d+)#', $s, $m ) ) { list( , $c, $a, $q ) = $m; }
		elseif ( preg_match( '#(\d{14})-\d-(\d+)/(\d{4})#', $s, $m ) ) { $c = $m[1]; $q = (int) $m[2]; $a = $m[3]; }
		else { return new WP_Error( 'dv_lic', 'Cole o link do edital no PNCP (pncp.gov.br/app/editais/...) ou o número de controle PNCP.', array( 'status' => 400 ) ); }
		$q = (int) $q; $base = "https://pncp.gov.br/api/consulta/v1/orgaos/$c/compras/$a/$q";
		$g = wp_remote_get( $base, array( 'timeout' => 25 ) ); $j = is_wp_error( $g ) ? null : json_decode( wp_remote_retrieve_body( $g ), true );
		if ( empty( $j['objetoCompra'] ) ) { return new WP_Error( 'dv_lic', 'Não encontramos essa licitação no PNCP agora. Confira o link ou preencha à mão.', array( 'status' => 404 ) ); }
		$f = wp_remote_get( "https://pncp.gov.br/api/pncp/v1/orgaos/$c/compras/$a/$q/arquivos", array( 'timeout' => 20 ) ); $fs = is_wp_error( $f ) ? array() : (array) json_decode( wp_remote_retrieve_body( $f ), true );
		$arqs = array(); foreach ( $fs as $x ) { if ( ! empty( $x['url'] ) && ( $x['statusAtivo'] ?? true ) ) { $arqs[] = array( 'titulo' => $x['titulo'] ?? 'Arquivo', 'tipo' => $x['tipoDocumentoNome'] ?? '', 'url' => $x['url'] ); } }
		$u = (array) ( $j['unidadeOrgao'] ?? array() );
		$cnet = ''; if ( stripos( (string) ( $j['usuarioNome'] ?? '' ), 'compras.gov' ) !== false && (int) ( $j['modalidadeId'] ?? 0 ) === 6 && ! empty( $u['codigoUnidade'] ) ) { $cnet = sprintf( '%06d05%05d%04d', (int) $u['codigoUnidade'], (int) preg_replace( '/\D/', '', (string) $j['numeroCompra'] ), (int) $j['anoCompra'] ); }
		return array( 'cnet' => $cnet, 'orgao' => $j['orgaoEntidade']['razaoSocial'] ?? '', 'uf' => $u['ufSigla'] ?? '', 'municipio' => $u['municipioNome'] ?? '', 'numero' => trim( ( $j['numeroCompra'] ?? '' ) . '/' . ( $j['anoCompra'] ?? '' ) . ( ! empty( $j['processo'] ) ? ' · processo ' . $j['processo'] : '' ) ),
			'modalidade' => $j['modalidadeNome'] ?? '', 'objeto' => $j['objetoCompra'] ?? '', 'abertura' => substr( (string) ( $j['dataEncerramentoProposta'] ?? $j['dataAberturaProposta'] ?? '' ), 0, 16 ),
			'valor' => (float) ( $j['valorTotalEstimado'] ?? 0 ), 'sigiloso' => ( $j['orcamentoSigilosoCodigo'] ?? 1 ) != 1, 'publicado' => substr( (string) ( $j['dataPublicacaoPncp'] ?? '' ), 0, 10 ), 'link' => $j['linkSistemaOrigem'] ?: "https://pncp.gov.br/app/editais/$c/$a/$q", 'pncp' => "$c/$a/$q",
			'categoria' => dv_lic_detectar_cat( $j['objetoCompra'] ?? '' ), 'arquivos' => $arqs, 'srp' => ! empty( $j['srp'] ), 'disputa' => $j['modoDisputaNome'] ?? '', 'amparo' => $j['amparoLegal']['nome'] ?? '' );
	} ) );
	/* Repassa o PDF do edital do PNCP para o navegador do assinante (o PNCP não libera o download direto por script) */
	register_rest_route( $ns, '/lic/arquivo', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$u = (string) $r['u'];
		if ( ! preg_match( '#^https://pncp\.gov\.br/pncp-api/v1/orgaos/\d{14}/compras/\d{4}/\d+/arquivos/\d+$#', $u ) ) { return new WP_Error( 'dv_lic', 'Endereço não permitido.', array( 'status' => 400 ) ); }
		$g = wp_remote_get( $u, array( 'timeout' => 60, 'redirection' => 5, 'limit_response_size' => 40 * 1048576 ) );
		if ( is_wp_error( $g ) || wp_remote_retrieve_response_code( $g ) >= 400 ) { return new WP_Error( 'dv_lic', 'O PNCP não entregou o arquivo agora. Baixe pelo site do PNCP e envie aqui.', array( 'status' => 502 ) ); }
		$b = wp_remote_retrieve_body( $g ); $ct = wp_remote_retrieve_header( $g, 'content-type' ) ?: 'application/octet-stream';
		$cd = wp_remote_retrieve_header( $g, 'content-disposition' );
		nocache_headers(); header( 'Content-Type: ' . $ct ); if ( $cd ) { header( 'X-Arquivo: ' . rawurlencode( $cd ) ); } header( 'Content-Length: ' . strlen( $b ) ); echo $b; exit;
	} ) );
	/* Fornecedores do órgão no mesmo objeto (contratos no PNCP), mais leve que o Raio-X completo */
	register_rest_route( $ns, '/lic/fornecedores', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] ); if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_lic', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$ck = 'dv_forn2_' . $cnpj; $c = get_transient( $ck );
		if ( $c === false ) {
			$x = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 15 ) ); $cad = is_wp_error( $x ) ? array() : (array) json_decode( wp_remote_retrieve_body( $x ), true );
			$B = 'https://pncp.gov.br/api/consulta/v1/contratos?cnpjOrgao=' . $cnpj . '&tamanhoPagina=500&pagina=1'; $ano = (int) current_time( 'Y' ); $u = array();
			for ( $a = $ano; $a >= $ano - 5; $a-- ) { $u[ $a ] = $B . '&dataInicial=' . $a . '0101&dataFinal=' . ( $a === $ano ? current_time( 'Ymd' ) : $a . '1231' ); }
			$res = function_exists( 'dv_ds_multi' ) ? dv_ds_multi( $u, array(), 25 ) : array(); $it = array();
			foreach ( $res as $body ) { $j = is_string( $body ) ? json_decode( $body, true ) : $body; foreach ( (array) ( $j['data'] ?? array() ) as $i ) {
				$it[] = array( 'ni' => preg_replace( '/\D/', '', (string) ( $i['niFornecedor'] ?? '' ) ), 'nome' => $i['nomeRazaoSocialFornecedor'] ?? '', 'objeto' => mb_substr( (string) ( $i['objetoContrato'] ?? '' ), 0, 200 ), 'valor' => (float) ( $i['valorGlobal'] ?? $i['valorInicial'] ?? 0 ) );
			} }
			$c = array( 'orgao' => $cad['razao_social'] ?? '', 'contratos' => $it ); set_transient( $ck, $c, 12 * HOUR_IN_SECONDS );
		}
		return $c;
	} ) );
	/* Vencedores homologados de cada item (PNCP) */
	register_rest_route( $ns, '/lic/vencedores', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		if ( ! preg_match( '#^(\d{14})/(\d{4})/(\d+)$#', (string) $r['ref'], $m ) ) { return new WP_Error( 'dv_lic', 'Licitação sem referência do PNCP.', array( 'status' => 400 ) ); }
		$ck = 'dv_venc_' . md5( $r['ref'] ); $c = get_transient( $ck ); if ( $c !== false ) { return $c; }
		$B = 'https://pncp.gov.br/api/pncp/v1/orgaos/' . $m[1] . '/compras/' . $m[2] . '/' . $m[3] . '/itens';
		$g = wp_remote_get( $B . '?pagina=1&tamanhoPagina=100', array( 'timeout' => 30 ) );
		$it = is_wp_error( $g ) ? array() : (array) json_decode( wp_remote_retrieve_body( $g ), true );
		$u = array(); foreach ( $it as $i ) { if ( ! empty( $i['temResultado'] ) && isset( $i['numeroItem'] ) ) { $u[ $i['numeroItem'] ] = $B . '/' . $i['numeroItem'] . '/resultados'; } }
		$u = array_slice( $u, 0, 60, true ); $res = array();
		if ( $u && function_exists( 'dv_ds_multi' ) ) { $res = dv_ds_multi( $u, array(), 30 ); } else { foreach ( $u as $k => $x ) { $gg = wp_remote_get( $x, array( 'timeout' => 20 ) ); $res[ $k ] = is_wp_error( $gg ) ? null : wp_remote_retrieve_body( $gg ); } }
		$F = array();
		foreach ( $res as $num => $body ) {
			$j = is_string( $body ) ? json_decode( $body, true ) : $body;
			foreach ( (array) $j as $v ) {
				$ni = preg_replace( '/\D/', '', (string) ( $v['niFornecedor'] ?? '' ) ); if ( strlen( $ni ) !== 14 ) { continue; }
				if ( ! isset( $F[ $ni ] ) ) { $F[ $ni ] = array( 'cnpj' => $ni, 'nome' => $v['nomeRazaoSocialFornecedor'] ?? '', 'itens' => 0, 'valor' => 0 ); }
				$F[ $ni ]['itens']++; $F[ $ni ]['valor'] += (float) ( $v['valorTotalHomologado'] ?? 0 );
			}
		}
		usort( $F, function ( $a, $b ) { return $b['valor'] <=> $a['valor']; } );
		$o = array( 'vencedores' => array_slice( array_values( $F ), 0, 15 ), 'itens_com_resultado' => count( $u ) );
		set_transient( $ck, $o, 6 * HOUR_IN_SECONDS ); return $o;
	} ) );
} );

/* Avisos por e-mail: prazo de impugnação (2 dias antes e no último dia) e sessão (véspera e dia) */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_lic_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:15 UTC' ), 'daily', 'dv_lic_diario' ); } } );
add_action( 'dv_lic_diario', function () {
	global $wpdb; $hoje = current_time( 'Y-m-d' );
	$uids = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_lic' AND meta_value<>'[]'" );
	foreach ( $uids as $uid ) {
		$uid = (int) $uid; $l = dv_lic_itens( $uid ); $av = array(); $mud = false;
		foreach ( $l as &$x ) {
			if ( empty( $x['abertura'] ) || ! in_array( $x['status'], array( 'analise', 'participar', 'proposta' ), true ) ) { continue; }
			$c = dv_lic_calc( $x, $hoje ); $ev = '';
			if ( $c['dias_imp'] === 2 ) { $ev = 'imp2'; } elseif ( $c['dias_imp'] === 0 ) { $ev = 'imp0'; } elseif ( $c['dias_sessao'] === 1 ) { $ev = 'ses1'; } elseif ( $c['dias_sessao'] === 0 ) { $ev = 'ses0'; }
			if ( ! $ev || strpos( (string) ( $x['aviso'] ?? '' ), $ev . $hoje ) !== false ) { continue; }
			$x['aviso'] = $ev . $hoje . '|' . substr( (string) ( $x['aviso'] ?? '' ), 0, 60 ); $mud = true; $c['ev'] = $ev; $av[] = $c;
		}
		unset( $x );
		if ( $mud ) { dv_lic_salvar_itens( $uid, $l ); }
		if ( $av ) { dv_lic_email( $uid, $av ); }
	}
} );
function dv_lic_email( $uid, $av ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$tx = array( 'imp2' => 'faltam 2 dias para o fim do prazo de impugnação e esclarecimento', 'imp0' => 'HOJE é o último dia para impugnar ou pedir esclarecimento', 'ses1' => 'a sessão é AMANHÃ', 'ses0' => 'a sessão é HOJE' );
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:20px">desenvolve </span><b style="color:#C9A45C;font-size:20px">estratégias</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Gestão de licitações · prazos</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Olá, ' . esc_html( $u->display_name ) . '.</p>';
	foreach ( $av as $x ) {
		$h .= '<div style="border-left:4px solid ' . ( in_array( $x['ev'], array( 'imp0', 'ses0' ), true ) ? '#C8604E' : '#C9A45C' ) . ';background:#FAF8F3;padding:12px 14px;margin:10px 0;border-radius:8px"><b>' . esc_html( $x['orgao'] ) . ( $x['numero'] ? ' · ' . esc_html( $x['numero'] ) : '' ) . '</b><br><span style="font-size:14px">' . $tx[ $x['ev'] ] . '.</span><br><span style="font-size:13px;color:#6E6A60">' . esc_html( mb_substr( $x['objeto'], 0, 180 ) ) . '<br>Sessão: ' . esc_html( date_i18n( 'd/m/Y H:i', strtotime( $x['abertura'] ) ) ) . ' · prazo de impugnação: ' . esc_html( date_i18n( 'd/m/Y', strtotime( $x['prazo_imp'] ) ) ) . '</span></div>';
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( home_url( '/plataforma/licitacoes/' ) ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir minhas licitações</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Prazo calculado pelo art. 164 da Lei 14.133/2021 (até 3 dias úteis antes da abertura), considerando feriados nacionais. Confira o edital e feriados locais. Precisa de impugnação ou recurso? Responda este e-mail.</p></div></div>';
	$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, 'Prazo de licitação · ' . count( $av ) . ' aviso(s) · Desenvolve', $h, $hd ); } }
}

add_shortcode( 'dv_licitacoes', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="lc" id="dv-lic" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<form class="lc-imp" autocomplete="off"><label>Adicionar licitação</label><div class="lc-row"><input placeholder="Cole o link do edital no PNCP (pncp.gov.br/app/editais/...)"><button>Importar</button><button type="button" class="g lc-man">Preencher à mão</button></div><p class="lc-h lc-msg">Importamos órgão, objeto, data da sessão, valor e os arquivos do edital. O prazo de impugnação é calculado sozinho.</p></form>
	<div class="lc-tabs"></div>
	<div class="lc-list"><p class="lc-h">Carregando…</p></div>
	<dialog class="lc-dlg"><form method="dialog" class="lc-f">
		<h3>Licitação</h3>
		<label>Órgão<input name="orgao" maxlength="200"></label>
		<div class="lc-3"><label>Número<input name="numero"></label><label>Modalidade<input name="modalidade"></label><label>UF<input name="uf" maxlength="2"></label></div>
		<label>Objeto<textarea name="objeto" rows="3"></textarea></label>
		<div class="lc-3"><label>Sessão (data e hora)<input type="datetime-local" name="abertura"></label><label>Valor estimado (R$)<input name="valor" inputmode="decimal"></label><label>Situação<select name="status"></select></label></div>
		<div class="lc-3"><label>Tipo de objeto<select name="categoria"></select></label><label>Empresa que participa<select name="empresa"></select></label><label>Link<input name="link"></label></div>
		<label>Anotações<textarea name="notas" rows="3" placeholder="Ex.: pedir atestado ao cliente X; vistoria marcada dia 12"></textarea></label>
		<input type="hidden" name="municipio"><input type="hidden" name="pncp"><input type="hidden" name="id">
		<p class="lc-fm lc-h"></p>
		<div class="lc-act"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="lc-ok">Salvar</button></div>
	</form></dialog>
	<dialog class="lc-dlg lc-ia"><div class="lc-f">
		<h3>Analisar edital com a sua IA</h3>
		<p class="lc-h">A Desenvolve monta a análise completa pela Lei 14.133/2021: o que o edital exige e o que não exige (vistoria, garantia, amostra, atestados, índices…), pontos para impugnar, perguntas de esclarecimento, riscos do contrato, preço e calendário. A sua IA (até a gratuita) faz a leitura e entrega o relatório, que pode ser salvo em PDF.</p>
		<div class="lc-conf"></div>
		<div class="lc-arqs"></div>
		<label class="lc-drop">Arraste o PDF do edital aqui ou <u>escolha o arquivo</u><input type="file" accept=".pdf"><span class="lc-fn"></span></label>
		<p class="lc-st lc-h"></p>
		<div class="lc-ias"></div>
		<p class="lc-im lc-h" style="font-weight:600"></p>
		<p class="lc-h" style="font-size:.78rem">Dica: se a IA recusar um texto muito longo, use "Copiar só o roteiro", cole na IA e anexe o PDF do edital na mesma mensagem. O edital vai para a conta de IA que você escolher e segue as regras de privacidade dela.</p>
		<div class="lc-act"><button type="button" class="g lc-fechar">Fechar</button></div>
	</div></dialog>
</div>
<style>
.lc-cf{border:1px solid var(--ln);border-radius:12px;padding:12px 14px;margin:10px 0;background:#FBFAF6}.lc-ci{display:flex;gap:8px;margin:7px 0;font-size:.88rem;line-height:1.4}.lc-ci em{display:block;color:var(--mu);font-size:.8rem;margin-top:2px}.lc-ci small{color:var(--mu)}.lc-ci.irr span{color:var(--rd)}.lc-cf .lc-imp2{margin-top:8px}
.arq-dl{border:1px solid rgba(201,164,92,.55);background:rgba(201,164,92,.08);border-radius:12px;padding:10px 12px;margin:8px 0 12px}.arq-dl small{opacity:.75}.arq-dl div{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.arq-dl a{font-size:.82rem;font-weight:600;border:1px solid rgba(127,127,127,.4);border-radius:9px;padding:6px 10px;text-decoration:none;color:inherit}.arq-dl .arq-n{display:block;margin-top:6px}
.lc .lc-lk{display:block;text-align:center;font-size:.82rem;font-weight:600;border:1px solid #E3DED2;border-radius:10px;padding:8px 12px;text-decoration:none;color:#16191E;background:#fff}.lc .lc-lk:hover{border-color:#C9A45C}
.lc{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;--am:#9A6B12;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.lc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.lc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.lc input,.lc select,.lc textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.lc-h{color:var(--mu);font-size:.88rem;margin:6px 0 0}.lc-msg.e{color:var(--rd)}
.lc-imp label{font-weight:700;display:block;margin-bottom:8px}.lc-row{display:flex;gap:8px}.lc-row input{flex:1}
.lc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:22px 0 12px}.lc-tabs span{border:1px solid var(--ln);border-radius:999px;padding:7px 13px;cursor:pointer;font-size:.86rem;font-weight:600;background:#fff}.lc-tabs span.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.lc-c{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:10px;display:grid;grid-template-columns:1fr auto;gap:10px}
.lc-c .o{font-weight:700}.lc-c .m{color:var(--mu);font-size:.82rem;margin-top:3px}.lc-c .ob{font-size:.9rem;margin-top:6px;line-height:1.45}
.lc-ch{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.lc-ch span{font-size:.75rem;font-weight:700;border-radius:99px;padding:3px 9px;background:#EEF1F6;color:#3B5578}
.lc-ch .r{background:#FDECE8;color:var(--rd)}.lc-ch .a{background:#FFF4DC;color:var(--am)}.lc-ch .v{background:#EEF7F1;color:var(--gr)}.lc-ch .n{background:#EEE9DE;color:var(--mu)}
.lc-c .ac{display:flex;flex-direction:column;gap:6px;align-items:stretch;min-width:160px}.lc-c .ac button{font-size:.82rem;padding:8px 12px}
.lc-dlg{border:0;border-radius:16px;padding:0;max-width:640px;width:calc(100% - 32px)}.lc-dlg::backdrop{background:rgba(12,15,19,.55)}
.lc-f{padding:22px;display:grid;gap:10px}.lc-f h3{margin:0}.lc-f label{font-weight:600;font-size:.86rem;display:grid;gap:4px}.lc-3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.lc-act{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}.lc-fm.e{color:var(--rd)}
.lc-drop{border:2px dashed #CFC8B8;border-radius:12px;padding:16px;text-align:center;cursor:pointer;font-weight:500!important}.lc-drop input{display:none}.lc-drop.on{border-color:#C9A45C;background:#FAF8F3}.lc-fn{display:block;font-weight:700;margin-top:4px}
.lc-arqs button{margin:0 6px 6px 0}.lc-ias button{display:inline-flex;align-items:center;margin:4px 6px 4px 0}.lc-ias img{width:18px;height:18px;border-radius:4px;margin-right:8px}
.lc-vazio{border:1px dashed var(--ln);border-radius:14px;padding:22px;text-align:center;color:var(--mu)}
@media(max-width:700px){.lc{padding:16px}.lc-row{flex-direction:column}.lc-3{grid-template-columns:1fr}.lc-c{grid-template-columns:1fr}.lc-c .ac{flex-direction:row;flex-wrap:wrap;min-width:0}}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function(){var R=document.getElementById('dv-lic');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,aba='ativas',cur=null,ED='',imp=null,CJ=null;
if(window.pdfjsLib)pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
var ST={analise:'Em análise',participar:'Vai participar',proposta:'Proposta enviada',disputa:'Disputa / habilitação',recurso:'Recurso',ganhou:'Ganhou',perdeu:'Perdeu',desistiu:'Desistiu'};
var IAS=[['ChatGPT','https://chatgpt.com/','chatgpt.com'],['Claude','https://claude.ai/new','claude.ai'],['Perplexity','https://www.perplexity.ai/','perplexity.ai'],['Grok','https://grok.com/','grok.com'],['DeepSeek','https://chat.deepseek.com/','deepseek.com']];
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function brh(d){return d?br(d)+(d.length>10?' '+d.slice(11,16):''):''}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):''}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function carregar(){return req('GET','lic').then(function(j){S=j;pintar()})}
function chip(x){var c=[];if(x.dias_sessao!==null){if(x.dias_sessao<0)c.push(['n','Sessão encerrada em '+brh(x.abertura)]);else c.push([x.dias_sessao<=1?'r':x.dias_sessao<=7?'a':'v',(x.dias_sessao===0?'Sessão HOJE ':x.dias_sessao===1?'Sessão AMANHÃ ':'Sessão em '+x.dias_sessao+' dias · ')+brh(x.abertura)]);
 if(x.dias_imp>=0&&['analise','participar','proposta'].indexOf(x.status)>=0)c.push([x.dias_imp<=1?'r':'a',(x.dias_imp===0?'Último dia para impugnar':'Impugnação até '+br(x.prazo_imp))]);}
 else c.push(['n','Sem data da sessão']);
 c.push(['',ST[x.status]]);if(x.valor)c.push(['',moeda(x.valor)]);return c.map(function(k){return'<span class="'+k[0]+'">'+e(k[1])+'</span>'}).join('')}
function pintar(){var T={ativas:'Ativas',ganhou:'Ganhas',perdeu:'Perdidas',desistiu:'Desistências',todas:'Todas'},cnt={};
 S.itens.forEach(function(x){var k=['ganhou','perdeu','desistiu'].indexOf(x.status)>=0?x.status:'ativas';cnt[k]=(cnt[k]||0)+1});cnt.todas=S.itens.length;
 R.querySelector('.lc-tabs').innerHTML=Object.keys(T).map(function(k){return'<span data-t="'+k+'" class="'+(k===aba?'on':'')+'">'+T[k]+' ('+(cnt[k]||0)+')</span>'}).join('');
 var l=S.itens.filter(function(x){if(aba==='todas')return true;if(aba==='ativas')return['ganhou','perdeu','desistiu'].indexOf(x.status)<0;return x.status===aba});
 l.sort(function(a,b){return String(a.abertura||'9999').localeCompare(String(b.abertura||'9999'))});
 if(aba==='ativas'){var h=S.hoje;l.sort(function(a,b){var fa=(a.abertura||'9999')<h,fb=(b.abertura||'9999')<h;return fa-fb||String(a.abertura||'9999').localeCompare(String(b.abertura||'9999'))})}
 R.querySelector('.lc-list').innerHTML=l.length?l.map(function(x){return'<div class="lc-c"><div><div class="o">'+e(x.orgao||'Órgão não informado')+(x.uf?' · '+e(x.municipio?x.municipio+'/':'')+e(x.uf):'')+'</div><div class="m">'+e([x.modalidade,x.numero].filter(Boolean).join(' · '))+' · '+e((S.cats[x.categoria]||'').split(' (')[0])+'</div><div class="ob">'+e(String(x.objeto||'').slice(0,320))+'</div><div class="lc-ch">'+chip(x)+'</div>'+(x.notas?'<div class="m" style="margin-top:6px">📝 '+e(x.notas.slice(0,200))+'</div>':'')+'</div><div class="ac"><button data-ia="'+x.id+'">Analisar edital</button>'+(String(x.pncp||'').match(/^\d{14}/)?'<button class="g lc-pac" data-pac="'+e(x.pncp)+'" data-item="'+e(String(x.objeto||'').split(/[,.;]/)[0].slice(0,60))+'" data-st="'+e(x.status||'')+'" data-obj="'+e(String(x.objeto||'').slice(0,240))+'">🔎 Concorrentes e Raio-X</button>':'')+''+(String(x.pncp||'').match(/^\d{14}/)?'<a class="g lc-lk" href="/plataforma/raio-x-comprador/?cnpj='+String(x.pncp).slice(0,14)+'&item='+encodeURIComponent(String(x.objeto||'').split(/[,.;]/)[0].slice(0,60))+'">Raio-X do órgão</a>':'')+(['analise','participar','proposta'].indexOf(x.status)>=0?'<a class="g lc-lk" href="/plataforma/recursos/?tipo=impugnacao&lic='+x.id+'&orgao='+encodeURIComponent(x.orgao||'')+'&titulo='+encodeURIComponent('Impugnação '+(x.numero||''))+'">Impugnar o edital</a>':'<a class="g lc-lk" href="/plataforma/recursos/?tipo=recurso&lic='+x.id+'&orgao='+encodeURIComponent(x.orgao||'')+'&titulo='+encodeURIComponent('Recurso '+(x.numero||''))+'">Recorrer</a>')+'<button class="g" data-ed="'+x.id+'">Editar / situação</button>'+(['analise','participar','proposta'].indexOf(x.status)>=0?'<a class="g" style="text-align:center;font-size:.82rem;color:#F4F2EC;background:#0C0F13;font-weight:700;border-radius:10px;padding:8px 12px;text-decoration:none" target="_blank" rel="noopener" href="https://wa.me/5511993500950?text='+encodeURIComponent('Olá! Quero cotar seguro garantia de proposta/contrato para a licitação '+(x.numero||'')+' de '+(x.orgao||'')+(x.valor?' (valor estimado '+moeda(x.valor)+')':'')+', sessão em '+brh(x.abertura)+'.')+'">Cotar seguro garantia</a>':'')+(x.link?'<a class="g" style="text-align:center;font-size:.82rem;color:#8A6A2C;font-weight:700;border:1px solid var(--ln);border-radius:10px;padding:8px 12px;text-decoration:none" href="'+e(x.link)+'" target="_blank" rel="noopener">Abrir no portal</a>':'')+'<button class="g" data-x="'+x.id+'">Excluir</button></div></div>'}).join(''):'<div class="lc-vazio">'+(S.itens.length?'Nada nesta aba.':'Cole acima o link de um edital no PNCP para começar. Você recebe por e-mail o aviso do último dia de impugnação e da véspera da sessão.')+'</div>'}
var F=R.querySelector('.lc-dlg .lc-f'),D1=F.closest('dialog');
function form(x){F.reset();F.querySelector('.lc-fm').textContent='';
 F.querySelector('[name=status]').innerHTML=Object.keys(ST).map(function(k){return'<option value="'+k+'">'+ST[k]+'</option>'}).join('');
 F.querySelector('[name=categoria]').innerHTML=Object.keys(S.cats).map(function(k){return'<option value="'+k+'">'+e(S.cats[k])+'</option>'}).join('');
 F.querySelector('[name=empresa]').innerHTML='<option value="">—</option>'+(S.empresas||[]).map(function(m){return'<option value="'+m.cnpj+'">'+e(m.nome)+'</option>'}).join('');
 if(x)['orgao','numero','modalidade','uf','objeto','valor','status','categoria','empresa','link','notas','municipio','pncp','id'].forEach(function(k){var i=F.querySelector('[name='+k+']');if(i&&x[k]!=null)i.value=x[k]});
 if(x&&x.abertura)F.querySelector('[name=abertura]').value=x.abertura.length>10?x.abertura.slice(0,16):x.abertura+'T09:00';
 D1.showModal()}
F.querySelector('.lc-ok').addEventListener('click',function(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});o.valor=String(o.valor||'').replace(/\./g,'').replace(',','.');var m=F.querySelector('.lc-fm');m.textContent='Salvando…';
 req('POST','lic/salvar',o).then(function(j){D1.close();aba='ativas';return carregar()}).catch(function(j){m.className='lc-fm lc-h e';m.textContent=(j&&j.message)||'Erro ao salvar.'})});
R.querySelector('.lc-imp').addEventListener('submit',function(ev){ev.preventDefault();var i=this.querySelector('input'),m=this.querySelector('.lc-msg'),b=this.querySelector('button');b.disabled=true;m.className='lc-h lc-msg';m.textContent='Buscando no PNCP…';
 req('GET','lic/pncp?ref='+encodeURIComponent(i.value)).then(function(j){imp=j;b.disabled=false;m.textContent='Importado. Confira e salve.';form(j);i.value='';cur=null}).catch(function(j){b.disabled=false;m.className='lc-h lc-msg e';m.textContent=(j&&j.message)||'Não foi possível importar.'})});
R.querySelector('.lc-man').addEventListener('click',function(){imp=null;form(null)});
/* ---------- Análise de edital ---------- */
var D2=R.querySelector('.lc-ia'),st=D2.querySelector('.lc-st'),im=D2.querySelector('.lc-im');
function roteiro(x){return S.base+'\n\n'+(S.extra[x.categoria]||'')}
function dados(x){return'DADOS DA LICITAÇÃO\n- Órgão: '+(x.orgao||'')+(x.uf?' ('+(x.municipio?x.municipio+'/':'')+x.uf+')':'')+'\n- Número/modalidade: '+[x.numero,x.modalidade].filter(Boolean).join(' · ')+'\n- Objeto: '+(x.objeto||'')+'\n- Sessão: '+brh(x.abertura)+(x.prazo_imp?'\n- Prazo para impugnar/esclarecer (calculado, art. 164): até '+br(x.prazo_imp):'')+(x.valor?'\n- Valor estimado: '+moeda(x.valor):'')+(x.link?'\n- Link: '+x.link:'')+'\n- Tipo de objeto: '+(S.cats[x.categoria]||'')}
function texto(x,comEdital){return roteiro(x)+'\n\n=====\n'+dados(x)+'\n=====\n\n'+(comEdital&&ED?'TEXTO DO EDITAL E ANEXOS (extraído do PDF):\n\n'+ED:'O EDITAL ESTÁ ANEXO A ESTA MENSAGEM. Leia o arquivo inteiro antes de responder.')}
function copiar(t){var dl=document.querySelector('dialog[open]')||document.body,a=document.createElement('textarea');a.value=t;a.setAttribute('readonly','');a.style.cssText='position:fixed;top:0;left:0;width:1px;height:1px;opacity:0';dl.appendChild(a);a.focus();a.select();var ok=false;try{ok=document.execCommand('copy')}catch(z){}a.remove();if(navigator.clipboard&&window.isSecureContext){try{navigator.clipboard.writeText(t)}catch(z){}}return true}
function naIA(a,t){var Q={'ChatGPT':'https://chatgpt.com/?q=','Claude':'https://claude.ai/new?q=','Perplexity':'https://www.perplexity.ai/search?q=','Grok':'https://grok.com/?q='}[a[0]],u=a[1];if(Q&&encodeURIComponent(t).length<7500)u=Q+encodeURIComponent(t);window.open(u,'_blank','noopener');return u!==a[1]}
function baixarTxt(t,n){var b=new Blob([t],{type:'text/plain;charset=utf-8'}),a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=n;document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},600)}
function botoes(){var x=cur,t=ED?'roteiro + edital':'roteiro';D2.querySelector('.lc-ias').innerHTML=IAS.map(function(a,i){return'<button class="g" data-ai="'+i+'"><img src="https://www.google.com/s2/favicons?domain='+a[2]+'&sz=64" alt="">'+a[0]+'</button>'}).join('')+'<br><button class="g" data-cp="1">Copiar só o roteiro</button><button class="g" data-tx="1">Baixar '+t+' (.txt)</button>';im.textContent=''}
function lerPdf(buf,nome){st.textContent='Lendo o edital…';D2.querySelector('.lc-fn').textContent=nome||'';
 return pdfjsLib.getDocument({data:buf}).promise.then(function(pdf){var ps=[];for(var i=1;i<=Math.min(pdf.numPages,250);i++)ps.push(pdf.getPage(i).then(function(p){return p.getTextContent()}).then((function(n){return function(c){return'\n[página '+n+']\n'+c.items.map(function(z){return z.str}).join(' ')}})(i)));return Promise.all(ps).then(function(t){ED=t.join('\n').replace(/[ \t]{2,}/g,' ');q_conf(cur,CJ);var pal=ED.split(/\s+/).length;st.innerHTML='Edital lido: <b>'+pdf.numPages+' página(s)</b>, cerca de '+pal.toLocaleString('pt-BR')+' palavras.'+(ED.replace(/\s|\[página \d+\]/g,'').length<300?' Parece um PDF escaneado (imagem): use "Copiar só o roteiro" e anexe o PDF na IA.':pal>60000?' É um edital longo: se a IA reclamar do tamanho, anexe o PDF e use "Copiar só o roteiro".':'');botoes()})}).catch(function(){st.textContent='Não foi possível ler este PDF. Use "Copiar só o roteiro" e anexe o arquivo na IA.';botoes()})}

/* ===== Conferência do edital pela Lei 14.133/2021 (art. 183: exclui o dia do começo e inclui o do vencimento) ===== */
function q_pascoa(y){var a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e2=b%4,f=Math.floor((b+8)/25),g2=Math.floor((b-f+1)/3),h=(19*a+b-d-g2+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e2+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451),mo=Math.floor((h+l-7*m+114)/31),dia=((h+l-7*m+114)%31)+1;return new Date(Date.UTC(y,mo-1,dia))}
function q_iso(d){return d.toISOString().slice(0,10)}
function q_util(d){var w=d.getUTCDay();if(w===0||w===6)return false;var y=d.getUTCFullYear(),k=q_iso(d).slice(5),F=['01-01','04-21','05-01','09-07','10-12','11-02','11-15','11-20','12-25'];if(F.indexOf(k)>=0)return false;var sx=q_pascoa(y);sx.setUTCDate(sx.getUTCDate()-2);return q_iso(sx)!==q_iso(d)}
function q_dt(s){return new Date(Date.UTC(+s.slice(0,4),+s.slice(5,7)-1,+s.slice(8,10)))}
function q_uteisEntre(a,b){var d=q_dt(a),f=q_dt(b),n=0;while(d<f){d.setUTCDate(d.getUTCDate()+1);if(q_util(d))n++}return n}
function q_uteisAntes(b,n){var d=q_dt(b),c=0;while(c<n){d.setUTCDate(d.getUTCDate()-1);if(q_util(d))c++}return d}
function q_br(d){return q_iso(d).split('-').reverse().join('/')}
function q_pg(t,i){var k=t.lastIndexOf('[página ',i);return k<0?'':(t.slice(k+8,t.indexOf(']',k)))}
function q_tre(t,i,n){return t.slice(Math.max(0,i-90),i+(n||160)).replace(/\s+/g,' ').trim()}
function q_regras(T,x){var o=[],t=T,lo=T.toLowerCase(),m,re;function add(nv,art,msg,i){o.push({nv:nv,art:art,msg:msg,tr:i!=null?q_tre(t,i):'',pg:i!=null?q_pg(t,i):''})}
 function num(z){return parseFloat(String(z).replace(',','.'))}
 if((m=/faturamento\s+(anual\s+|bruto\s+)?m[ií]nimo/.exec(lo)))add('irr','art. 69, § 2º','O edital exige faturamento mínimo. A lei proíbe exigir valores mínimos de faturamento anterior.',m.index);
 if((m=/[ií]ndices?\s+de\s+(rentabilidade|lucratividade)/.exec(lo)))add('irr','art. 69, § 2º','O edital exige índice de rentabilidade ou lucratividade, o que a lei proíbe.',m.index);
 re=/(capital\s+social|patrim[oô]nio\s+l[ií]quido)[^%]{0,160}?(\d{1,2}(?:,\d+)?)\s?%/g;while((m=re.exec(lo))){if(num(m[2])>10){add('irr','art. 69, § 4º','Capital ou patrimônio líquido mínimo de '+m[2]+'%: o limite da lei é 10% do valor estimado.',m.index);break}}
 re=/garantia\s+(de|da)\s+proposta[^%]{0,160}?(\d{1,2}(?:,\d+)?)\s?%/g;while((m=re.exec(lo))){if(num(m[2])>1){add('irr','art. 58, § 1º','Garantia de proposta de '+m[2]+'%: o limite é 1% do valor estimado.',m.index);break}}
 re=/garantia[^%.]{0,60}(contratual|de\s+execu[cç][aã]o|do\s+contrato)[^%]{0,160}?(\d{1,2}(?:,\d+)?)\s?%/g;while((m=re.exec(lo))){var gp=num(m[2]);if(gp>10&&x.categoria!=='engenharia'){add('irr','art. 98','Garantia contratual de '+m[2]+'%: o limite é 5%, ou até 10% com justificativa de complexidade e risco (30% só em obra de grande vulto, art. 99).',m.index);break}if(gp>5&&gp<=10){add('at','art. 98','Garantia contratual de '+m[2]+'%: acima de 5% exige justificativa de complexidade técnica e riscos no processo.',m.index);break}}
 re=/atestad/g;var qa=0,ta=0,la=0;while((m=re.exec(lo))&&(!qa||!ta||!la)){var w=lo.slice(m.index,m.index+450),q;if(!qa&&(q=/(\d{2,3})\s?%[^.]{0,60}(quantitativ|quantidade|volume|do\s+total|do\s+objeto|estimad)/.exec(w))&&num(q[1])>50){qa=1;add('irr','art. 67, § 2º','Atestado com quantidade mínima de '+q[1]+'%: o limite é 50% das parcelas de maior relevância.',m.index)}
  if(!ta&&(q=/(nos|dos)\s+[uú]ltimos\s+\d+\s+(anos|meses)|emitid[oa]s?\s+(h[aá]|a\s+partir)/.exec(w))){ta=1;add('irr','art. 67, § 2º','O edital limita o tempo dos atestados. A lei veda limitações de tempo e de locais específicos.',m.index)}
  if(!la&&(q=/(no\s+(estado|munic[ií]pio)\s+d[eo]|na\s+regi[aã]o\s+d[eo])/.exec(w))){la=1;add('at','art. 67, § 2º','Possível limitação de local dos atestados (vedada pela lei). Confira o trecho.',m.index)}}
 if(lo.indexOf('reajust')<0&&lo.indexOf('repactua')<0)add('at','art. 25, § 7º','Não encontramos regra de reajuste. O edital deve prever o índice, com data-base vinculada ao orçamento.',null);
 if((m=/(visita|vistoria)\s+t[eé]cnica[^.]{0,120}obrigat[oó]ri/.exec(lo))&&!/declara[cç][aã]o[^.]{0,160}(pleno\s+)?conhecimento/.test(lo))add('at','art. 63, §§ 2º e 3º','Vistoria obrigatória sem a alternativa de declaração de conhecimento das condições do local. A lei garante essa substituição.',m.index);
 if((m=/amostra[^.]{0,160}(todos\s+os\s+licitantes|todas\s+as\s+licitantes|juntamente\s+com\s+a\s+proposta|no\s+ato\s+da\s+entrega\s+da\s+proposta)/.exec(lo)))add('at','arts. 17, § 3º, e 42, § 2º','Amostra exigida de todos os licitantes. A lei prevê amostra do licitante provisoriamente vencedor.',m.index);
 if((m=/(somente|apenas|exclusivamente)\s+(ser[aã]o\s+aceit[oa]s?\s+)?(a\s+|da\s+)?marca/.exec(lo)))add('at','art. 41, I','Indicação de marca: só é admitida em hipóteses excepcionais e com justificativa formal.',m.index);
 if((m=/carta\s+de\s+solidariedade/.exec(lo)))add('at','art. 41, IV','Carta de solidariedade do fabricante: só é cabível em caso de fornecedor revendedor ou distribuidor, com justificativa.',m.index);
 if((m=/(documentos?\s+de\s+habilita[cç][aã]o)[^.]{0,120}(juntamente\s+com\s+a\s+proposta|todos\s+os\s+licitantes)/.exec(lo))&&lo.indexOf('invers')<0)add('at','art. 63, II','Documentos de habilitação de todos os licitantes junto com a proposta: a regra é exigi-los só do vencedor (salvo inversão de fases motivada).',m.index);
 if(x.categoria!=='bens'&&x.categoria!=='saude'&&!/(integralidade\s+dos\s+custos|direitos\s+trabalhistas)/.test(lo))add('at','art. 63, § 1º','Não encontramos a declaração de que a proposta cobre todos os custos trabalhistas (lei, convenção coletiva e TACs). Ela é obrigatória.',null);
 if((m=/vedad[ao][^.]{0,50}cons[oó]rcio/.exec(lo)))add('info','art. 15','O edital veda consórcio. É permitido, mas a vedação precisa estar justificada no processo.',m.index);
 return o}
function q_conf(x,j){var C=D2.querySelector('.lc-conf');if(!C)return;var L=[],ab=(x.abertura||(j&&j.abertura)||'').slice(0,10),pub=j&&j.publicado;
 if(/^\d{4}-\d{2}-\d{2}$/.test(ab)){var lim=q_uteisAntes(ab,3),hj=new Date(),dd=Math.ceil((lim-Date.UTC(hj.getFullYear(),hj.getMonth(),hj.getDate()))/864e5);L.push({nv:dd<0?'info':dd<=2?'at':'ok',art:'art. 164',msg:'Prazo para impugnar ou pedir esclarecimento: até <b>'+q_br(lim)+'</b> (3 dias úteis antes da abertura)'+(dd<0?' — já passou.':dd===0?' — é hoje!':' — faltam '+dd+' dia(s).'),tr:'',pg:''})}
 if(pub&&/^\d{4}-\d{2}-\d{2}$/.test(ab)){var cat=x.categoria||'',bens=cat==='bens'||cat==='saude',min=bens?8:10,n=q_uteisEntre(pub,ab),mod=String(x.modalidade||(j&&j.modalidade)||'');L.push({nv:n<min?'irr':n<=min+1?'at':'ok',art:'art. 55',msg:'Prazo entre a divulgação no PNCP ('+q_br(q_dt(pub))+') e a abertura: <b>'+n+' dias úteis</b>. O mínimo é '+min+' ('+(bens?'bens':'serviços comuns e obras comuns')+', menor preço ou maior desconto).'+(n<min?' <b>Prazo abaixo do mínimo: cabe impugnação.</b>':'')+(/concorr/i.test(mod)?' Em concorrência com técnica, serviços especiais ou contratação integrada, os mínimos são maiores (25, 35 ou 60 dias úteis).':''),tr:'',pg:''})}
 if(ED)L=L.concat(q_regras(ED,x));
 var ic={irr:'⛔',at:'⚠️',ok:'✅',info:'ℹ️'},nf=L.filter(function(z){return z.nv==='irr'||z.nv==='at'});
 C.innerHTML='<div class="lc-cf"><b>Conferência pela Lei 14.133/2021</b> <span class="lc-h">· grátis</span>'+L.map(function(z){return'<div class="lc-ci '+z.nv+'">'+ic[z.nv]+' <span>'+z.msg+' <small>('+z.art+')</small>'+(z.tr?'<em>'+(z.pg?'p. '+z.pg+': ':'')+'“…'+e(z.tr)+'…”</em>':'')+'</span></div>'}).join('')+(ED?'':'<p class="lc-h">Leia o edital abaixo (botão do PNCP ou arraste o PDF) para conferirmos também as exigências: atestados, índices, garantias, vistoria, amostra, marca e reajuste.</p>')+(nf.length?'<button type="button" class="g lc-imp2">Levar '+nf.length+' ponto(s) para a impugnação →</button>':'')+'<p class="lc-h" style="font-size:.74rem">Conferência automática por palavras-chave: confirme cada ponto no edital. Não constitui parecer jurídico.</p></div>';
 var b=C.querySelector('.lc-imp2');if(b)b.onclick=function(){var t='Pontos encontrados na conferência do edital pela Lei 14.133/2021:\n'+nf.map(function(z,i){return(i+1)+'. '+z.msg.replace(/<[^>]+>/g,'')+' ('+z.art+')'+(z.tr?'\n   Trecho do edital'+(z.pg?' (p. '+z.pg+')':'')+': "…'+z.tr+'…"':'')}).join('\n');try{localStorage.setItem('dv_imp_pontos',t)}catch(z){}location.href='/plataforma/recursos/?tipo=impugnacao&pontos=1&lic='+encodeURIComponent(x.id||'')+'&orgao='+encodeURIComponent(x.orgao||'')+'&titulo='+encodeURIComponent('Impugnação ao edital '+(x.numero||''))}}
function baixarTodos(l){var i=0;(function n(){if(i>=l.length)return;var a=document.createElement('a');a.href=l[i].url;a.download='';a.target='_blank';a.rel='noopener';(document.querySelector('dialog[open]')||document.body).appendChild(a);a.click();a.remove();i++;setTimeout(n,900)})()}
function listaArqs(l){return l.length?'<div class="arq-dl"><b>Arquivos da licitação no PNCP</b> <small>— baixe e anexe na IA</small><div>'+l.map(function(f,i){return'<a href="'+e(f.url)+'" download target="_blank" rel="noopener">⬇ '+e(String(f.titulo||'Arquivo').slice(0,60))+'</a>'}).join('')+'</div>'+(l.length>1?'<button type="button" class="g arq-all">⬇ Baixar todos ('+l.length+')</button>':'')+'<small class="arq-n">Se o navegador perguntar, permita baixar vários arquivos.</small></div>':''}
function abrirIA(x){cur=x;ED='';st.textContent='';CJ=null;q_conf(x,null);D2.querySelector('.lc-fn').textContent='';D2.querySelector('.lc-arqs').innerHTML='';botoes();D2.showModal();
 if(x.pncp){D2.querySelector('.lc-arqs').innerHTML='<p class="lc-h">Buscando os arquivos no PNCP…</p>';req('GET','lic/pncp?ref='+encodeURIComponent(x.pncp)).then(function(j){CJ=j;q_conf(x,j);var a=(j.arquivos||[]);D2.querySelector('.lc-arqs').innerHTML=a.length?'<p class="lc-h" style="margin-bottom:6px">Arquivos publicados no PNCP — clique para ler aqui:</p>'+a.map(function(f,i){return'<button class="g" data-arq="'+i+'">📄 '+e(f.titulo.slice(0,60))+'</button>'}).join('')+listaArqs(a):'<p class="lc-h">Não conseguimos listar os arquivos agora (o PNCP está instável). Baixe na <a href="https://pncp.gov.br/app/editais/'+e(x.pncp)+'" target="_blank" rel="noopener">página da licitação no PNCP ↗</a> e arraste o edital aqui.</p>';D2._arqs=a;var bA=D2.querySelector('.lc-arqs .arq-all');if(bA)bA.onclick=function(){baixarTodos(a)}}).catch(function(){D2.querySelector('.lc-arqs').innerHTML='<p class="lc-h">O PNCP não respondeu agora. Baixe na <a href="https://pncp.gov.br/app/editais/'+e(x.pncp)+'" target="_blank" rel="noopener">página da licitação no PNCP ↗</a> e arraste o edital aqui.</p>'})}}
D2.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b)return;var x=cur;
 if(b.classList.contains('lc-fechar')){D2.close();return}
 if(b.dataset.arq!=null){var f=D2._arqs[+b.dataset.arq];st.textContent='Baixando do PNCP…';fetch(api+'lic/arquivo?u='+encodeURIComponent(f.url),{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){if(!r.ok)throw 0;var ct=r.headers.get('content-type')||'';return r.arrayBuffer().then(function(buf){var u=new Uint8Array(buf.slice(0,4));if(u[0]===0x25&&u[1]===0x50)return lerPdf(buf,f.titulo);st.textContent='Este arquivo não é PDF ('+(u[0]===0x50?'ZIP':ct)+'). Baixe no portal, extraia e envie o PDF aqui.'})}).catch(function(){st.textContent='O PNCP não entregou o arquivo agora. Baixe pelo portal e arraste aqui.'});return}
 if(b.dataset.cp){copiar(texto(x,false));im.style.color='#2F7A55';im.textContent='Roteiro copiado. Na sua IA, cole (Ctrl+V ou ⌘+V), anexe o PDF do edital e envie.';return}
 if(b.dataset.tx){baixarTxt(texto(x,true),'desenvolve-analise-edital.txt');im.textContent='Arquivo baixado. Anexe na conversa com a sua IA e escreva "siga as instruções do arquivo".';return}
 if(b.dataset.ai!=null){var a=IAS[+b.dataset.ai];var tt=texto(x,true);copiar(tt);var pre=naIA(a,tt);im.style.color='#2F7A55';im.textContent=pre?'Abrimos o '+a[0]+' com o pedido já escrito: é só enviar.':'Copiado! Na aba do '+a[0]+', cole (Ctrl+V ou ⌘+V) e envie.'+(ED?'':' Anexe também o PDF do edital na mesma mensagem.');return}});
var inp=D2.querySelector('[type=file]'),drop=D2.querySelector('.lc-drop');
inp.addEventListener('change',function(){if(inp.files[0])inp.files[0].arrayBuffer().then(function(b){lerPdf(b,inp.files[0].name)})});
['dragover','dragenter'].forEach(function(t){drop.addEventListener(t,function(z){z.preventDefault();drop.classList.add('on')})});['dragleave','drop'].forEach(function(t){drop.addEventListener(t,function(z){z.preventDefault();drop.classList.remove('on')})});
drop.addEventListener('drop',function(z){var f=z.dataTransfer.files[0];if(f)f.arrayBuffer().then(function(b){lerPdf(b,f.name)})});
R.addEventListener('click',function(ev){var t=ev.target,x;
 if(t.dataset.t){aba=t.dataset.t;pintar();return}
 if(t.dataset.ed){x=S.itens.filter(function(y){return y.id===t.dataset.ed})[0];form(x);return}
 if(t.dataset.ia){x=S.itens.filter(function(y){return y.id===t.dataset.ia})[0];abrirIA(x);return}
 if(t.dataset.x){if(confirm('Excluir esta licitação da lista?'))req('POST','lic/excluir',{id:t.dataset.x}).then(carregar)}});
carregar().then(function(){var v=location.search.match(/[?&]ver=([a-z0-9]+)/);if(v){history.replaceState(null,'',location.pathname);var x=S.itens.filter(function(y){return y.id===v[1]})[0];if(x){aba='todas';pintar();form(x)}return}var m=location.search.match(/[?&]importar=([0-9]{14}\/[0-9]{4}\/[0-9]+)/);if(!m)return;history.replaceState(null,'',location.pathname);var f=R.querySelector('.lc-imp');f.querySelector('input').value=m[1];f.requestSubmit()});
})();
</script>
<style>
.lc .lc-pac{font-weight:700}
.pk-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:40px 12px}
.pk{background:#fff;color:#16191E;border-radius:18px;max-width:720px;width:100%;padding:24px 26px;font-family:"Hanken Grotesk",sans-serif;box-shadow:0 20px 60px rgba(0,0,0,.35)}
.pk h3{margin:0 0 4px;font-size:1.25rem}.pk .h{color:#6E6A60;font-size:.88rem;margin:0 0 12px}.pk .fo{display:block;padding:8px 0;border-bottom:1px solid #EEE9DD}.pk .fo small{display:block;color:#6E6A60}.pk .fo .op{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:4px}.pk .fo .op label{display:inline-flex;gap:6px;align-items:center;font-size:14px;cursor:pointer;padding:0;border:0}.pk .al{background:#FFF8E6;border:1px solid #EAD9A8;border-radius:10px;padding:8px 12px;margin:8px 0;font-size:13px}.pk .al p{margin:4px 0}.pk .cl{background:#F8F6F0;border-radius:12px;padding:12px;margin:10px 0}.pk .cl table{width:100%;border-collapse:collapse;font-size:13px;margin:8px 0}.pk .cl th{text-align:left;color:#6E6A60;font-weight:600;border-bottom:1px solid #DDD6C6;padding:4px}.pk .cl td{padding:5px 4px;border-bottom:1px solid #EEE9DD;vertical-align:top}.pk .cl td small{display:block;color:#8A857A}.pk .cl tr.eu td{background:#FFF3D6;font-weight:600}.pk .cl tr.fora td{color:#A09A8C;text-decoration:line-through}.pk .cl tr.fora td small{text-decoration:none}.pk h4{margin:16px 0 6px;font-size:.95rem}
.pk label{display:flex;gap:10px;align-items:flex-start;border:1px solid #E3DED2;border-radius:12px;padding:10px 12px;margin:6px 0;cursor:pointer}.pk label small{display:block;color:#6E6A60}
.pk .tot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #E3DED2;flex-wrap:wrap}
.pk button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.pk button.g{background:#fff;border:1px solid #E3DED2;color:#16191E}
.pk .ok a{display:block;border:1px solid #E3DED2;border-radius:12px;padding:10px 12px;margin:6px 0;color:#16191E;text-decoration:none;font-weight:600}.pk .ok a:hover{border-color:#C9A45C}
.pk .er{color:#B04A3A;font-weight:600}
</style>
<script>
(function(){var R=document.getElementById('dv-lic');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,CUSTO={comprador:(window.DVC&&DVC.comprador)||2,raiox:(window.DVC&&DVC.raiox)||5,dossie:(window.DV&&DV.cr_dossie)||10};
 function forn(c,n,info){return'<div class="fo"><b>'+e(n||'CNPJ '+fc(c))+'</b><small>CNPJ '+fc(c)+(info?' · '+e(info):'')+'</small><span class="op"><label><input type="checkbox" data-t="raiox" data-c="'+c+'" data-n="'+e(n||fc(c))+'"> Raio-X · '+CUSTO.raiox+' créd.</label><label><input type="checkbox" data-t="dossie" data-c="'+c+'" data-n="'+e(n||fc(c))+'"> Dossiê completo · '+CUSTO.dossie+' créd.</label></span></div>'}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function fc(c){c=String(c).replace(/\D/g,'');return c.length===14?c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5'):c}
function moeda(v){return v?Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}):''}
function g(u){return fetch(u,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function usar(f,k,rot){return fetch(api+'usar',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:JSON.stringify({ferramenta:f,chave:k,rotulo:rot})}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
var STOP='contratacao empresa empresas prestacao servico servicos continuos continuo fornecimento aquisicao especializada especializado objeto registro precos eventual eventuais futura futuro atender atendimento necessidades secretaria municipal estadual federal mediante conforme demais especificacoes termo referencia anexo edital inclusive incluindo diversos diversas forma regime execucao unidades unidade publica publico durante periodo meses quantidade materiais equipamentos insumos dedicacao exclusiva mao obra'.split(' ');
function toks(t){return String(t||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').split(/[^a-z0-9]+/).filter(function(w){return w.length>4&&STOP.indexOf(w)<0}).slice(0,6)}
R.addEventListener('click',function(ev){var b=ev.target.closest('[data-pac]');if(!b)return;ev.preventDefault();abrir(b.dataset.pac,b.dataset.item||'',b.dataset.st||'',b.dataset.obj||'')},true);
function abrir(ref,item,st,obj){var cnpj=ref.slice(0,14),bg=document.createElement('div');bg.className='pk-bg';bg.innerHTML='<div class="pk"><h3>Concorrentes e Raio-X desta licitação</h3><p class="h">Buscando no PNCP os vencedores desta licitação e quem mais fornece esse objeto para o órgão… leva alguns segundos.</p></div>';document.body.appendChild(bg);bg.addEventListener('click',function(ev){if(ev.target===bg||ev.target.dataset.f)bg.remove()});var P=bg.querySelector('.pk');
 Promise.all([g(api.replace('desenvolve/v1/','desenvolve/v1/')+'lic/vencedores?ref='+encodeURIComponent(ref)).catch(function(){return{vencedores:[]}}),g(api+'lic/fornecedores?cnpj='+cnpj).catch(function(){return null})]).then(function(a){var V=a[0].vencedores||[],C=a[1],T=toks(obj||item),F={};
  ((C&&C.contratos)||[]).forEach(function(x){var ni=String(x.ni||'');if(ni.length!==14)return;var ob=String(x.objeto||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');if(T.length&&!T.some(function(t){return ob.indexOf(t)>=0}))return;F[ni]=F[ni]||{cnpj:ni,nome:x.nome,n:0,valor:0};F[ni].n++;F[ni].valor+=x.valor||0});
  var vs={};V.forEach(function(v){vs[v.cnpj]=1});var FO=Object.keys(F).map(function(k){return F[k]}).filter(function(x){return!vs[x.cnpj]}).sort(function(a,b){return b.valor-a.valor}).slice(0,5);
  var h='<h3>Concorrentes e Raio-X desta licitação</h3><p class="h">Marque o que você quer analisar. Cada relatório abre completo na ferramenta, já pago, e fica disponível por 30 dias sem nova cobrança.</p>';
  h+='<label><input type="checkbox" data-t="comprador"><span><b>Raio-X do órgão</b> · '+CUSTO.comprador+' créditos<small>Se paga em dia, processos, notícias e contratos'+(C&&C.orgao?' de '+e(C.orgao):'')+'</small></span></label>';
  if(V.length)h+='<h4>Vencedores desta licitação (resultado homologado no PNCP)</h4>'+V.slice(0,6).map(function(v,i){return forn(v.cnpj,v.nome,v.itens+' item(ns) · '+moeda(v.valor))}).join('');
  else if(/disputa|recurso|ganha|perdida/.test(st))h+='<p class="h">O resultado desta licitação ainda não está homologado no PNCP.</p>';
  if(FO.length)h+='<h4>Quem mais fornece esse objeto para este órgão (contratos dos últimos 5 anos)</h4>'+FO.map(function(v,i){return forn(v.cnpj,v.nome,v.n+' contrato(s) · '+moeda(v.valor))}).join('');
  if(!V.length&&!FO.length)h+='<p class="h">Não encontramos fornecedores anteriores desse objeto para este órgão no PNCP.</p>';
  h+='<div class="cls"></div><h4>Participantes da sessão <a href="#" class="todos" style="font-size:13px;font-weight:500;margin-left:8px">marcar Raio-X de todos</a></h4><div class="pt"><p class="h">Lendo a ata e o resultado publicados no PNCP…</p></div><p class="h" style="margin-top:8px">Tem a ata em mãos? Cole aqui o texto ou os CNPJs dos participantes:</p><textarea class="cola" style="width:100%;min-height:60px;border:1px solid #CFC8B8;border-radius:10px;padding:8px;font:inherit" placeholder="Ex.: 12.345.678/0001-90 Empresa X; 98.765.432/0001-10 Empresa Y"></textarea><button type="button" class="g ad" style="margin-top:6px">Adicionar à lista</button>';
  h+='<div class="tot"><b class="tt"></b><span><button class="g" data-f="1">Cancelar</button> <button class="go">Gerar relatórios</button></span></div><p class="h msg"></p>';P.innerHTML=h;
  var VIS={};VIS[cnpj]=1;P.querySelectorAll('input[data-c]').forEach(function(i){VIS[i.dataset.c]=1});
  function linha(c,n,info){if(VIS[c])return'';VIS[c]=1;return forn(c,n,info)}
  function extrai(t,orig){var o={},ord=[],re=/(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/g,m;while(m=re.exec(t)){var c=m[1].replace(/\D/g,'');if(c.length!==14)continue;var a=t.slice(Math.max(0,m.index-120),m.index).replace(/[\s\-–:,(]+$/,'').split(/\n|\s{3,}|;|\|/).pop().replace(/^(.*?(CNPJ|Fornecedor|Licitante|Empresa|Raz[aã]o Social)\s*:\s*)/i,'').replace(/(CNPJ|CPF\/CNPJ|inscrita no|sob o n[ºo°]?|n[ºo°])\s*[:.]?\s*$/i,'').replace(/[-–,:(]+\s*$/,'').trim();var d=t.slice(m.index+m[0].length,m.index+m[0].length+90).replace(/^[\s\-–:,)]+/,'').split(/\n|\s{3,}|;|\|/)[0].trim();var ok=function(x){return x.length>3&&/[A-Za-z]{3}/.test(x)&&!/^\d+\s*dias|^(CNPJ|CPF|Fornecedor|Validade|Valor|Item|R\$)/i.test(x)};var nome=ok(a)?a:(ok(d)?d:'');if(!o[c]){o[c]={n:'',k:0};ord.push(c)}o[c].k++;if(!o[c].n&&nome)o[c].n=nome.slice(0,80)}return ord.map(function(c){return[c,o[c].n,orig+(o[c].k>1?' · aparece '+o[c].k+'x':'')]}).sort(function(x,y){return o[y[0]].k-o[x[0]].k})}
  function num(x){return parseFloat(String(x).replace(/[^\d,]/g,'').replace(',','.'))||0}
  function brl(v){return v.toLocaleString('pt-BR',{style:'currency',currency:'BRL',minimumFractionDigits:2,maximumFractionDigits:4})}
  function cnetParse(t){var k=t.search(/\nPropostas\s*\n/),head=k>0?t.slice(0,k):'',hl=head.split('\n').map(function(x){return x.trim()}).filter(Boolean),it='',est=0,qt=0;hl.forEach(function(l,i){if(/^\d{1,4}\s+\S/.test(l)&&!/^\d+$/.test(l)&&!/R\$/.test(l))it=l;if(/^R\$/.test(l))est=num(l)});var vi=hl.findIndex(function(l){return/^Valor estimado/i.test(l)});if(vi>=0&&/^\d+$/.test(hl[vi+1]||''))qt=+hl[vi+1];
   var re=/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})/g,m,pos=[],rows=[];while(m=re.exec(t))if(m.index>k)pos.push([m.index,m[1]]);pos.forEach(function(p,i){var seg=t.slice(p[0]+p[1].length,i+1<pos.length?pos[i+1][0]:t.length),ls=seg.split('\n').map(function(x){return x.trim()}).filter(Boolean),nome='',st='',vs=[],me=false,tg=[];ls.forEach(function(l){if(/^ME\/EPP/.test(l)){me=true;return}if(/^Equidade/i.test(l))tg.push('equidade de gênero');if(/^Programa de integridade/i.test(l))tg.push('programa de integridade');if(/^R\$/.test(l)){vs.push(num(l));return}if(/^(Equidade|Programa de integridade|Valor |-$|[A-Z]{2}$|Voltar)/.test(l))return;if(/^(Desclassificad|Aceit|Inabilitad|Habilitad|Recusad|Adjudicad|Cancelad|Homologad)/i.test(l)){st=l;return}if(!nome)nome=l});rows.push({c:p[1].replace(/\D/g,''),n:nome.slice(0,80),v:vs[0]||0,neg:vs[1]||0,st:st,me:me,tg:tg})});
   return{it:it,est:est,qt:qt,rows:rows}}
  function extraiCnet(t){var d=cnetParse(t);classif(d);return d.rows.map(function(r){return[r.c,r.n,'na sessão pública'+(r.v?' · lance '+brl(r.neg||r.v):'')+(r.st?' · '+r.st:'')]})}
  var MEU=((window.DV&&DV.doc)||'').replace(/\D/g,'');
  function classif(d){if(!d.rows.length)return;var rs=d.rows.slice().sort(function(a,b){var x=/desclass|inabil|recusad/i.test(a.st),y=/desclass|inabil|recusad/i.test(b.st);if(x!==y)return x?1:-1;return(a.neg||a.v)-(b.neg||b.v)}),best=rs[0]?(rs[0].neg||rs[0].v):0,eu=rs.filter(function(r){return r.c===MEU})[0];
   var h='<div class="cl"><b>'+(d.it?e(d.it):'Item')+'</b>'+(d.est?' · estimado '+brl(d.est)+' (unit.)':' · valor estimado sigiloso')+(d.qt?' · '+d.qt+' un.':'')+
   '<table><tr><th>#</th><th>Empresa</th><th>Lance</th><th>Desconto</th><th>Situação</th></tr>'+rs.map(function(r,i){var v=r.neg||r.v,des=d.est&&v?(1-v/d.est)*100:null,ru=/desclass|inabil|recusad/i.test(r.st);return'<tr class="'+(r.c===MEU?'eu':'')+(ru?' fora':'')+'"><td>'+(ru?'—':(i+1)+'º')+'</td><td>'+e(r.n||fc(r.c))+(r.c===MEU?' <em>(você)</em>':'')+'<small>'+fc(r.c)+(r.me?' · ME/EPP':'')+'</small></td><td>'+(v?brl(v):'—')+(r.neg?'<small>negociado</small>':'')+'</td><td>'+(des===null?'—':des<0?'<span style="color:#B4472F">'+(-des).toFixed(1).replace('.',',')+'% acima</span>':des.toFixed(1).replace('.',',')+'%')+'</td><td>'+e(r.st||'—')+'</td></tr>'}).join('')+'</table>';
   var ENG=/obra|engenharia|reforma|constru|paviment|edifica|drenagem|recapeamento/i.test((obj||'')+' '+(d.it||'')),AL=[],ok=rs.filter(function(r){return!/desclass|inabil|recusad/i.test(r.st)}),v1=ok[0]?(ok[0].neg||ok[0].v):0,v2=ok[1]?(ok[1].neg||ok[1].v):0;
   if(d.est&&v1){var pc=v1/d.est;if(ENG&&pc<.75)AL.push('<b>Inexequível pela lei:</b> o 1º colocado está em '+(pc*100).toFixed(1).replace('.',',')+'% do orçado. Em obras e serviços de engenharia, abaixo de 75% a proposta é considerada inexequível (art. 59, § 4º). Cabe recurso.');
    else if(!ENG&&pc<.5)AL.push('<b>Indício de inexequibilidade:</b> o 1º colocado está abaixo de 50% do orçado. Para bens e serviços, a IN SEGES 73/2022 (âmbito federal e quem a adota) trata isso como indício: o órgão deve exigir a demonstração de exequibilidade (art. 59, IV e § 2º).');
    if(ENG&&pc<.85&&pc>=.75)AL.push('<b>Garantia adicional:</b> abaixo de 85% do orçado em engenharia, o vencedor deve dar garantia adicional igual à diferença (art. 59, § 5º): cerca de <b>'+brl((d.est-v1)*(d.qt||1))+'</b>. <a href="https://wa.me/5511993500950?text='+encodeURIComponent('Olá! Preciso de seguro garantia (garantia adicional do art. 59, § 5º) para uma licitação.')+'" target="_blank" rel="noopener">Cotar seguro garantia →</a>')}
   if(v1&&v2&&v2>=v1*1.05)AL.push('<b>Diferença de '+((v2/v1-1)*100).toFixed(1).replace('.',',')+'% entre o 1º e o 2º:</b> com 5% ou mais, o edital pode prever o reinício da disputa aberta (art. 56, § 4º).');
   if(ok[0]&&!ok[0].me){var fic=ok.slice(1).filter(function(r){var v=r.neg||r.v;return r.me&&v&&v<=v1*1.05});if(fic.length)AL.push('<b>Empate ficto de ME/EPP:</b> '+fic.map(function(r){return e(r.n||fc(r.c))}).join(', ')+' '+(fic.length>1?'estão':'está')+' até 5% acima do 1º, que não é ME/EPP. No pregão, a ME/EPP pode cobrir o lance (LC 123/2006, arts. 44 e 45; art. 60, § 2º).')}
   var tgs=ok.filter(function(r){return r.tg&&r.tg.length});if(tgs.length)AL.push('<b>Critérios de desempate declarados:</b> '+tgs.slice(0,4).map(function(r){return e(r.n||fc(r.c))+' ('+r.tg.join(', ')+')'}).join('; ')+'. Valem em caso de empate (art. 60, III e IV).');
   if(AL.length)h+='<div class="al">'+AL.map(function(a){return'<p>⚖️ '+a+'</p>'}).join('')+'<p class="h" style="font-size:12px">Sinais automáticos a partir dos dados da sessão. Não constitui parecer jurídico.</p></div>';
   var dd=d.est&&best?(1-best/d.est)*100:null;h+='<p class="h">'+rs.length+' participantes'+(dd!==null?' · melhor proposta '+dd.toFixed(1).replace('.',',')+'% abaixo do estimado':'')+(eu&&best?' · você ficou '+((((eu.neg||eu.v)/best)-1)*100).toFixed(1).replace('.',',')+'% acima do 1º':'')+'</p><button type="button" class="g csv">Baixar planilha</button></div>';
   var w=P.querySelector('.cls');w.insertAdjacentHTML('beforeend',h);var bt=w.lastElementChild.querySelector('.csv');bt.onclick=function(){var L=[['Posição','Empresa','CNPJ','ME/EPP','Lance','Desconto %','Situação']].concat(rs.map(function(r,i){var v=r.neg||r.v;return[i+1,r.n,fc(r.c),r.me?'sim':'não',v.toFixed(4).replace('.',','),d.est&&v?((1-v/d.est)*100).toFixed(2).replace('.',','):'',r.st]}));var csv='﻿'+(d.it?'Item: '+d.it+'\n':'')+(d.est?'Estimado unitário;'+d.est.toFixed(4).replace('.',',')+'\n':'')+L.map(function(x){return x.map(function(c){return'"'+String(c).replace(/"/g,'""')+'"'}).join(';')}).join('\n');var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='classificacao-'+(d.it||'item').slice(0,30).replace(/\W+/g,'-')+'.csv';a.click()}}
  function poe(L){var pt=P.querySelector('.pt'),hh=L.map(function(x){return linha(x[0],x[1],x[2],false)}).join('');if(hh){if(pt.querySelector('p.h'))pt.innerHTML='';pt.insertAdjacentHTML('beforeend',hh)}soma();return hh}
  P.querySelector('.todos').onclick=function(ev){ev.preventDefault();P.querySelectorAll('.pt input[data-t=raiox]').forEach(function(i){i.checked=true});soma()};
  P.querySelector('.ad').onclick=function(){var ta=P.querySelector('.cola');var L=/Valor ofertado/i.test(ta.value)?extraiCnet(ta.value):extrai(ta.value,'informado por você');if(!L.length){alert('Não encontramos CNPJ no texto colado.');return}poe(L);ta.value=''};
  function pdfjs(){if(window.pdfjsLib)return Promise.resolve(window.pdfjsLib);return new Promise(function(ok,no){var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';sc.onload=function(){pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';ok(pdfjsLib)};sc.onerror=no;document.head.appendChild(sc)})}
  function jszip(){if(window.JSZip)return Promise.resolve(window.JSZip);return new Promise(function(ok,no){var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';sc.onload=function(){ok(JSZip)};sc.onerror=no;document.head.appendChild(sc)})}
  function lePdf(buf){return pdfjs().then(function(L){return L.getDocument({data:buf}).promise}).then(function(pdf){var ps=[];for(var i=1;i<=Math.min(pdf.numPages,150);i++)ps.push(pdf.getPage(i).then(function(pg){return pg.getTextContent()}).then(function(tc){var y=null,out='';tc.items.forEach(function(it){var yy=Math.round(it.transform[5]);out+=(y!==null&&yy!==y?'\n':' ')+it.str;y=yy});return out}));return Promise.all(ps).then(function(a){return a.join('\n')})})}
  function texto(url){return fetch(api+'lic/arquivo?u='+encodeURIComponent(url),{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){if(!r.ok)throw 0;return r.arrayBuffer()}).then(function(buf){var u8=new Uint8Array(buf);if(u8[0]===37&&u8[1]===80)return lePdf(buf);if(u8[0]===80&&u8[1]===75)return jszip().then(function(Z){return Z.loadAsync(buf)}).then(function(z){var fs=Object.keys(z.files).filter(function(n){return/\.pdf$/i.test(n)}).slice(0,3);return Promise.all(fs.map(function(n){return z.file(n).async('arraybuffer').then(lePdf)})).then(function(a){return a.join('\n')})});return''})}
  P.querySelector('.cola').addEventListener('paste',function(){setTimeout(function(){P.querySelector('.ad').click()},50)});
  g(api+'lic/pncp?ref='+encodeURIComponent(ref)).then(function(L){if(L.cnet){var bx=document.createElement('div');bx.style.cssText='background:#F4F1EA;border-radius:10px;padding:10px 12px;margin:8px 0;font-size:14px';bx.innerHTML='<b>Sessão no Compras.gov.br:</b> <a href="https://cnetmobile.estaleiro.serpro.gov.br/comprasnet-web/public/compras/acompanhamento-compra?compra='+L.cnet+'" target="_blank" rel="noopener">abrir a sessão pública ↗</a><br><small>Lá, clique no ícone de propostas do item, aperte <b>Ctrl+A</b> e <b>Ctrl+C</b> e cole no campo abaixo. Montamos a classificação com o desconto de cada um sobre o estimado. Repita para cada item que quiser.</small>';P.querySelector('.cola').before(bx)}var A=(L.arquivos||[]).filter(function(a){var t=(a.titulo+' '+a.tipo);return/ata|julgamento|resultado|homologa|adjudica|classifica|sess[aã]o|propostas|relat[oó]rio/i.test(t)&&!/edital|termo de refer|minuta|estudo t[eé]cnico|ETP|projeto|impugna|amostra/i.test(t)});var fin=A.some(function(a){return/ata.?final/i.test(a.titulo)});if(fin)A=A.filter(function(a){return!/ata.?parcial/i.test(a.titulo)});A.sort(function(x,y){return(/ata.?final/i.test(y.titulo)?1:0)-(/ata.?final/i.test(x.titulo)?1:0)});A=A.slice(0,3);if(!A.length){P.querySelector('.pt').innerHTML='<p class="h">A ata da sessão ainda não foi publicada no PNCP'+(/analise|participar|proposta/.test(st)?' (a sessão ainda não aconteceu)':'')+'. Quando sair, ela aparece aqui.</p>';return}
   var q=Promise.resolve(),achou=0;A.forEach(function(a){q=q.then(function(){return texto(a.url).then(function(t){achou+=poe(extrai(t,/ata.?final/i.test(a.titulo)?'na ata final da sessão':/ata.?parcial/i.test(a.titulo)?'na ata parcial':'em: '+a.titulo.replace(/\.(pdf|zip)$/i,'').replace(/_/g,' ')).filter(function(x){return x[0]!==cnpj})).length?1:0}).catch(function(){})})});
   q.then(function(){if(!achou)P.querySelector('.pt').innerHTML='<p class="h">Lemos '+A.map(function(a){return'"'+e(a.titulo)+'"'}).join(', ')+', mas não encontramos CNPJs de participantes. Se a ata estiver em outro sistema, cole os CNPJs abaixo.</p>'})}).catch(function(){P.querySelector('.pt').innerHTML='<p class="h">Não conseguimos ler os documentos no PNCP agora.</p>'});
  function soma(){var t=0;P.querySelectorAll('input:checked').forEach(function(i){t+=CUSTO[i.dataset.t]});P.querySelector('.tt').textContent='Total: '+t+' crédito(s)';P.querySelector('.go').disabled=!t}P.addEventListener('change',function(ev){var i=ev.target;if(i.checked&&i.dataset.c)P.querySelectorAll('input[data-c="'+i.dataset.c+'"]').forEach(function(o){if(o!==i)o.checked=false})});soma();P.addEventListener('change',soma);
  P.querySelector('.go').onclick=function(){var go=this;go.disabled=true;go.textContent='Gerando…';var sel=[].slice.call(P.querySelectorAll('input:checked')),links=[],q=Promise.resolve();
   sel.forEach(function(i){q=q.then(function(){if(i.dataset.t==='comprador'){var k='cp2-c='+fc(cnpj)+(item?'|cp2-i='+item:'');return usar('comprador',k,'Pacote: '+(C&&C.orgao?C.orgao:cnpj)).then(function(){links.push(['Raio-X do órgão'+(C&&C.orgao?' · '+C.orgao:''),'/plataforma/raio-x-comprador/?cnpj='+cnpj+'&item='+encodeURIComponent(item)])})}
    var c=i.dataset.c;if(i.dataset.t==='dossie')return usar('dossie','dossie|'+c+'|1','Pacote: '+i.dataset.n).then(function(){links.push(['Dossiê completo · '+i.dataset.n,'/plataforma/dossie/?cnpj='+c+'&auto=1'])});return usar('raiox','rx-cnpj='+fc(c),'Pacote: '+i.dataset.n).then(function(){links.push(['Raio-X · '+i.dataset.n,'/plataforma/raio-x/?cnpj='+c])})})});
   q.then(function(){P.innerHTML='<h3>Relatórios prontos</h3><p class="h">Clique para abrir cada um (abrem em nova aba, sem nova cobrança por 30 dias).</p><div class="ok">'+links.map(function(l){return'<a href="'+l[1]+'" target="_blank" rel="noopener">'+e(l[0])+' →</a>'}).join('')+'</div><div class="tot"><span></span><button class="g" data-f="1">Fechar</button></div>'}).catch(function(j){P.querySelector('.msg').innerHTML='<span class="er">'+e((j&&j.message)||'Não foi possível concluir.')+'</span>'+(links.length?' Os relatórios já gerados: '+links.map(function(l){return'<a href="'+l[1]+'" target="_blank">'+e(l[0])+'</a>'}).join(' · '):'')+' <a href="/creditos/">Comprar créditos</a>';go.disabled=false;go.textContent='Tentar de novo'})}
 })}
})();
</script>
	<?php return ob_get_clean();
} );
