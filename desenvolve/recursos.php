/**
 * Desenvolve — Recursos e defesas (prazos da Lei 14.133/2021 + roteiro para a IA do cliente)
 * Recurso, contrarrazões, pedido de reconsideração, resposta a diligência e defesa em processo sancionatório.
 * Calcula o prazo final em dias úteis (art. 183), avisa por e-mail e monta o roteiro para a IA do cliente,
 * já com os dados oficiais do concorrente quando houver. Não é parecer jurídico. Incluso na assinatura.
 */

function dv_rc_tipos() {
	// chave => [rótulo, dias úteis (0 = informado pelo órgão), fundamento]
	return array(
		'impugnacao'    => array( 'Impugnação ao edital', 0, 'art. 164 da Lei 14.133/2021: até 3 dias úteis antes da data de abertura do certame (informe a data-limite abaixo)' ),
		'esclarecimento'=> array( 'Pedido de esclarecimento', 0, 'art. 164: até 3 dias úteis antes da data de abertura do certame (informe a data-limite abaixo)' ),
		'recurso'       => array( 'Razões de recurso (contra julgamento, habilitação ou inabilitação)', 3, 'art. 165, I, da Lei 14.133/2021 (3 dias úteis da intimação ou da lavratura da ata; a intenção deve ser manifestada imediatamente, art. 165, § 1º, I)' ),
		'contrarrazoes' => array( 'Contrarrazões (defender a sua vitória)', 3, 'art. 165, § 4º (3 dias úteis contados da intimação ou da divulgação da interposição do recurso)' ),
		'reconsideracao'=> array( 'Pedido de reconsideração (ato sem recurso hierárquico)', 3, 'art. 165, II (3 dias úteis da intimação)' ),
		'diligencia'    => array( 'Resposta a diligência', 0, 'art. 64 (prazo fixado pelo agente de contratação ou pela comissão)' ),
		'defesa'        => array( 'Defesa em processo sancionatório', 15, 'arts. 157 e 158 (15 dias úteis da intimação)' ),
		'alegacoes'     => array( 'Alegações finais (sancionatório)', 15, 'art. 158, § 2º (15 dias úteis)' ),
		'recurso_sancao'=> array( 'Recurso contra sanção aplicada', 15, 'art. 166 (15 dias úteis da intimação)' ),
		'reconsid_inid' => array( 'Pedido de reconsideração contra declaração de inidoneidade', 15, 'art. 167 (15 dias úteis da intimação)' ),
		'outro'         => array( 'Outro prazo administrativo', 0, 'prazo informado pelo órgão' ),
	);
}
/** Prazo em dias úteis: exclui o dia do começo e inclui o do vencimento (art. 183) */
function dv_rc_uteis_depois( $data, $n ) {
	$ok = function ( $d ) { return function_exists( 'dv_lic_util' ) ? dv_lic_util( $d ) : ( (int) gmdate( 'N', strtotime( $d ) ) < 6 ); };
	$d = $data; $c = 0; while ( $c < $n ) { $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ); if ( $ok( $d ) ) { $c++; } } return $d;
}
/** Jurisprudência sugerida para o caso, conforme a esfera e o estado do órgão e o assunto da peça */
function dv_rc_juris( $x, $uid ) {
	if ( ! function_exists( 'dv_ts_buscar' ) ) { return array( 'itens' => array(), 'nota' => '' ); }
	$lic = null; foreach ( ( function_exists( 'dv_lic_itens' ) ? dv_lic_itens( $uid ) : array() ) as $l ) { if ( ! empty( $x['lic'] ) && $l['id'] === $x['lic'] ) { $lic = $l; } }
	$org = remove_accents( strtoupper( ( $x['orgao'] ?? '' ) . ' ' . ( $lic['orgao'] ?? '' ) ) );
	$fed = (bool) preg_match( '/FEDERAL|\bUNIAO\b|MINISTERIO|EXERCITO|MARINHA|AERONAUTICA|COMANDO D[AO]|TRIBUNAL REGIONAL|TRIBUNAL SUPERIOR|SUPERIOR TRIBUNAL|SUPREMO|EBSERH|\bINSS\b|IBAMA|ICMBIO|ANVISA|AGENCIA NACIONAL|CADETES|CAMARA DOS DEPUTADOS|SENADO FEDERAL|FUNDACAO OSWALDO|FIOCRUZ|CAIXA ECONOMICA|BANCO DO BRASIL|PETROBRAS|CORREIOS|DNIT|INCRA|FUNAI|IBGE/', $org );
	$uf = strtoupper( (string) ( $lic['uf'] ?? '' ) );
	if ( ! $uf && preg_match( '/SAO PAULO|PAULISTA|\bSP\b|CAMPINAS|SANTOS|SOROCABA|RIBEIRAO PRETO|JUNDIAI|GUARULHOS|OSASCO/', $org ) ) { $uf = 'SP'; }
	$txt = dv_ts_norm( ( $x['titulo'] ?? '' ) . ' ' . ( $x['fatos'] ?? '' ) . ' ' . ( $lic['objeto'] ?? '' ) );
	$def = array( 'impugnacao' => array( 'competitividade' ), 'esclarecimento' => array( 'competitividade' ), 'recurso' => array( 'formalismo', 'diligencia' ), 'contrarrazoes' => array( 'formalismo' ), 'reconsideracao' => array( 'formalismo' ), 'diligencia' => array( 'diligencia' ), 'defesa' => array( 'proporcionalidade' ), 'alegacoes' => array( 'proporcionalidade' ), 'recurso_sancao' => array( 'proporcionalidade', 'dosimetria' ), 'reconsid_inid' => array( 'proporcionalidade' ) );
	$temas = array();
	$mapa = array( 'planilha' => 'planilha', 'inexequ' => 'inexequivel', 'atestad' => 'atestado', 'capacidade tecnica' => 'atestado', 'vistoria' => 'vistoria', 'visita tecnica' => 'vistoria', 'garantia' => 'garantia', 'amostra' => 'amostra', 'marca' => 'marca', 'indice' => 'indice', 'balanco' => 'balanco', 'capital social' => 'capital social', 'microempresa' => 'me', ' me/epp' => 'me', 'repactua' => 'repactuacao', 'reajust' => 'reajuste', 'reequilibrio' => 'reequilibrio', 'sobrepreco' => 'sobrepreco', 'multa' => 'multa', 'inabilit' => 'inabilitacao', 'desclassific' => 'desclassificacao', 'diligencia' => 'diligencia', 'formalismo' => 'formalismo', 'aditivo' => 'aditivo', 'prorroga' => 'prorrogacao', 'terceiriza' => 'terceirizacao', 'divida ativa' => 'regularidade fiscal', 'regularidade fiscal' => 'regularidade fiscal', 'certidao negativa' => 'regularidade fiscal', 'fgts' => 'regularidade fiscal', 'sancao registrada' => 'impedimento', 'impediment' => 'impedimento', 'inidone' => 'inidoneidade', 'situacao cadastral' => 'situacao cadastral', 'falencia' => 'falencia', 'recuperacao judicial' => 'recuperacao judicial' );
	/* Pontos fracos do concorrente (Raio-X): viram temas de jurisprudência */
	$cc = preg_replace( '/\D/', '', (string) ( $x['concorrente'] ?? '' ) );
	if ( strlen( $cc ) === 14 ) {
		if ( function_exists( 'dv_ds_pgfn' ) ) { $pg = dv_ds_pgfn( $cc ); if ( $pg && ( $pg['total'] > 0 || ! empty( $pg['fgts'] ) ) ) { $temas['regularidade fiscal'] = 1; } }
		$fo = function_exists( 'dv_ds_interno' ) ? dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cc ) ) : null;
		if ( is_array( $fo ) ) { $sa = (array) ( $fo['sancoes'] ?? array() ); if ( ! empty( $sa['ceis'] ) || ! empty( $sa['cnep'] ) || ! empty( $sa['tcu'] ) ) { $temas['impedimento'] = 1; } $sit = strtoupper( (string) ( $fo['cadastro']['situacao'] ?? $fo['cadastro']['descricao_situacao_cadastral'] ?? '' ) ); if ( $sit && $sit !== 'ATIVA' ) { $temas['situacao cadastral'] = 1; } }
	}
	foreach ( $mapa as $k => $t ) { if ( strpos( $txt, $k ) !== false ) { $temas[ $t ] = 1; } }
	if ( ! $temas ) { foreach ( $def[ $x['tipo'] ?? '' ] ?? array() as $t ) { $temas[ $t ] = 1; } }
	$temas = array_slice( array_keys( $temas ), 0, 5 );
	$fontes = $fed ? array( 'tcu', 'stj' ) : ( $uf === 'SP' ? array( 'tcesp', 'tcu', 'stj' ) : array( 'tcu', 'stj' ) );
	$stop = array_flip( explode( ' ', 'sobre entre apenas porque quando onde como mais menos sendo seria foram pelos pelas desta deste nessa nesse empresa empresas servico servicos contratacao licitacao licitante proposta orgao prestacao objeto edital pregao eletronico contrato publica publico administracao preciso minha nossa nosso foram dessa desse' ) );
	$kw = array(); foreach ( preg_split( '/[^a-z0-9]+/', $txt ) as $w ) { if ( strlen( $w ) >= 5 && ! isset( $stop[ $w ] ) ) { $kw[ substr( $w, 0, 7 ) ] = 1; } }
	$dex = (bool) preg_match( '/dedicacao exclusiva|terceiriza|mao de obra|postos?\b|vigilan|limpeza|portaria|copeir|recepc/', $txt . ' ' . dv_ts_norm( $lic['objeto'] ?? '' ) );
	$pool = array();
	foreach ( $temas as $t ) { foreach ( $fontes as $f ) {
		$r = dv_ts_buscar( $t, '', 1, 40, $f );
		foreach ( $r['itens'] as $y ) {
			if ( isset( $pool[ $y['k'] ] ) ) { $pool[ $y['k'] ]['sc'] += 2; continue; }
			$n = dv_ts_norm( ( $y['ementa'] ?? '' ) . ' ' . $y['e'] . ' ' . ( $y['t'] ?? '' ) . ' ' . ( $y['s'] ?? '' ) );
			$sc = 0; foreach ( $kw as $w => $_ ) { if ( strpos( $n, $w ) !== false ) { $sc++; } }
			if ( strpos( $n, 'concurso publico' ) !== false ) { $sc -= 10; }
			if ( $dex && strpos( $n, 'sem dedicacao exclusiva' ) !== false ) { $sc -= 6; }
			if ( $dex && preg_match( '/com dedicacao exclusiva|terceiriza/', $n ) ) { $sc += 2; }
			if ( strpos( $n, '8.666' ) !== false && strpos( $n, '14.133' ) === false ) { $sc -= 2; }
			if ( strpos( $n, '14.133' ) !== false ) { $sc += 1; }
			if ( in_array( $y['src'] ?? '', array( 'tcusum', 'tcesp' ), true ) ) { $sc += 1; }
			$y['_f'] = $f; $y['_t'] = $t; $y['sc'] = $sc; $pool[ $y['k'] ] = $y;
		}
	} }
	uasort( $pool, function ( $a, $b ) { return $b['sc'] <=> $a['sc']; } );
	$out = array(); $porf = array(); $vci = array(); $min = count( $kw ) >= 4 ? 2 : 1;
	foreach ( $pool as $y ) {
		if ( count( $out ) >= 8 || $y['sc'] < $min ) { continue; } $f = $y['_f']; if ( ( $porf[ $f ] ?? 0 ) >= ( $f === $fontes[0] ? 5 : 3 ) ) { continue; } $porf[ $f ] = ( $porf[ $f ] ?? 0 ) + 1; $sr = $y['src'] ?? '';
		$col = array( 'Plenário' => 'Plenário', 'Primeira Câmara' => '1ª Câmara', 'Segunda Câmara' => '2ª Câmara' );
		$ci = ! empty( $y['ci'] ) ? $y['ci'] : ( $sr === 'tcesp' ? 'Súmula nº ' . $y['n'] . ' do TCE-SP' : ( $sr === 'tcusum' ? 'Súmula nº ' . $y['n'] . ' do TCU' : 'Acórdão ' . $y['n'] . '/' . $y['a'] . ' – TCU – ' . ( $col[ $y['c'] ] ?? $y['c'] ) . ( $y['rel'] ? ', Rel. Min. ' . mb_convert_case( mb_strtolower( $y['rel'] ), MB_CASE_TITLE ) : '' ) ) );
		$u = $y['u'] ?? ''; if ( ! $u && $sr !== 'tcesp' && ! empty( $y['n'] ) && ! empty( $y['a'] ) && strpos( $sr, 'tcu' ) === 0 ) { $u = 'https://pesquisa.apps.tcu.gov.br/pesquisa/acordao-completo?q=NUMACORDAO:' . $y['n'] . '%20ANOACORDAO:' . $y['a']; }
		if ( isset( $vci[ $ci ] ) ) { $porf[ $f ]--; continue; } $vci[ $ci ] = 1;
		$out[] = array( 'k' => $y['k'], 'tema' => $y['_t'], 'fonte' => strpos( $sr, 'tcesp' ) === 0 ? 'TCE-SP' : ( $sr === 'stj' ? 'STJ' : 'TCU' ), 'e' => mb_substr( wp_strip_all_tags( $y['ementa'] ?? $y['e'] ), 0, 600 ), 'ci' => $ci, 'u' => $u );
	}
	$nota = $fed ? 'Órgão federal: precedentes do TCU, que fiscaliza o órgão, e do STJ.' : ( $uf === 'SP' ? 'Órgão estadual ou municipal de São Paulo: primeiro o TCE-SP, que fiscaliza o órgão; depois o TCU (Súmula 222 do TCU) e o STJ.' : 'Órgão estadual ou municipal' . ( $uf ? ' de ' . $uf : '' ) . ': o tribunal de contas do estado ainda não está na nossa base. Sugerimos TCU (Súmula 222 do TCU: suas decisões sobre normas gerais de licitação valem como referência para estados e municípios) e STJ; vale citar também precedentes do TCE local, se você tiver.' );
	return array( 'itens' => $out, 'nota' => $nota, 'temas' => $temas, 'esfera' => $fed ? 'federal' : 'estadual/municipal', 'uf' => $uf );
}
function dv_rc_itens( $uid ) { $l = json_decode( get_user_meta( $uid, 'dv_recursos', true ) ?: '[]', true ); return is_array( $l ) ? $l : array(); }
function dv_rc_salvar_itens( $uid, $l ) { update_user_meta( $uid, 'dv_recursos', wp_slash( wp_json_encode( array_values( $l ), JSON_UNESCAPED_UNICODE ) ) ); }
function dv_rc_calc( $x, $hoje ) {
	$T = dv_rc_tipos(); $n = $T[ $x['tipo'] ][1] ?? 0; $q = (int) ( $x['prazo_qtd'] ?? 0 ); $un = $x['prazo_un'] ?? 'uteis'; $ini = $x['intimacao'] ?? ''; $hr = ! empty( $x['hora'] ) ? $x['hora'] : '';
	$ok = function ( $d ) { return function_exists( 'dv_lic_util' ) ? dv_lic_util( $d ) : ( (int) gmdate( 'N', strtotime( $d ) ) < 6 ); };
	$fim = ''; $fimh = '23:59';
	if ( ! empty( $x['prazo_manual'] ) ) { $fim = $x['prazo_manual']; }
	elseif ( $q && $ini ) {
		if ( $un === 'horas' ) { $t = strtotime( $ini . ' ' . ( $hr ?: '09:00' ) ) + $q * 3600; $fim = gmdate( 'Y-m-d', $t ); $fimh = gmdate( 'H:i', $t ); }
		elseif ( $un === 'corridos' ) { $fim = gmdate( 'Y-m-d', strtotime( $ini . ' +' . $q . ' days' ) ); while ( ! $ok( $fim ) ) { $fim = gmdate( 'Y-m-d', strtotime( $fim . ' +1 day' ) ); } } // vence em dia sem expediente: passa para o próximo dia útil
		else { $fim = dv_rc_uteis_depois( $ini, $q ); }
	} elseif ( $n && $ini ) { $fim = dv_rc_uteis_depois( $ini, $n ); }
	$x['prazo'] = $fim; $x['prazo_hora'] = $fim ? $fimh : '';
	$x['prazo_ts'] = $fim ? ( strtotime( $fim . ' ' . $fimh . ':00' ) - (int) ( get_option( 'gmt_offset' ) * 3600 ) ) * 1000 : null;
	$x['dias'] = $fim ? (int) floor( ( strtotime( $fim ) - strtotime( $hoje ) ) / 86400 ) : null;
	return $x;
}

function dv_rc_roteiro( $tipo ) {
	$base = "Você é analista sênior de licitações e contratos públicos, especialista na Lei 14.133/2021 e na jurisprudência do TCU. Vou te passar um caso real. Escreva em português formal, com estrutura de peça administrativa (endereçamento, qualificação, síntese dos fatos, fundamentos, pedidos). Use só os fatos que eu informar e os documentos que eu anexar; quando faltar informação, deixe [COMPLETAR] em vez de inventar. Cite artigos com precisão. Os precedentes da seção JURISPRUDÊNCIA SUGERIDA vêm de base oficial (TCU, TCE-SP, STJ), com a ementa transcrita: pode citá-los mesmo que sejam recentes, desde que sejam pertinentes ao caso, e diga em uma linha por que descartou os que não servem. Fora dessa lista, só cite acórdãos que você conhece com segurança (número, ano e colegiado); se não tiver certeza, não cite. Confira se o tipo de peça escolhido combina com os fatos (por exemplo, desclassificação já registrada pede recurso, não resposta a diligência) e se o prazo ainda está aberto na data de hoje; se algo não bater, avise logo no início. Se os documentos listados no fim não vierem anexados, não redija a peça inteira: entregue só o diagnóstico preliminar e a lista do que preciso anexar, e espere os anexos. Não chame a Lei 14.133/2021 de \"nova lei\". Antes da peça, entregue: (1) diagnóstico em 5 linhas: chance (alta, média, baixa) e por quê; (2) os 3 argumentos mais fortes, do melhor para o pior; (3) documentos que devo juntar; (4) riscos de alegar algo que pode se voltar contra mim. Depois, a peça completa. No fim, um checklist de protocolo (prazo, forma de envio, assinatura, anexos). Lembre que isto não é parecer jurídico e recomende a revisão por um consultor especializado antes do protocolo.\n\n";
	$x = array(
		'notificacao' => "TIPO: RESPOSTA A NOTIFICAÇÃO, OFÍCIO OU PEDIDO DO ÓRGÃO DURANTE A EXECUÇÃO DO CONTRATO. Responda ponto a ponto, na ordem da notificação, com fatos, datas e provas. O que procede: reconheça com objetividade e apresente o plano de correção com prazos. O que não procede: conteste com base no contrato, no termo de referência e na Lei 14.133/2021 (obrigações das partes e fiscalização, arts. 115 a 120; alterações contratuais, art. 124; atraso de pagamento ou falta de condições criadas pelo órgão, art. 137, § 2º). Se o prazo for curto, peça dilação de forma fundamentada. Cuidado para não admitir fato que possa embasar penalidade (art. 155); registre que a resposta não prejudica a defesa em eventual processo sancionatório. Termine com a lista de anexos.",
		'impugnacao' => "TIPO: IMPUGNAÇÃO AO EDITAL (art. 164 da Lei 14.133/2021). Aponte, cláusula por cláusula, cada exigência ilegal ou restritiva (qualificação técnica além das parcelas de maior relevância, quantitativos acima de 50%, vedação injustificada de somatório de atestados ou de consórcio, índices contábeis sem justificativa, vistoria obrigatória sem motivo, marca, prazos inexequíveis, orçamento desatualizado), com o fundamento legal e a jurisprudência do TCU, e peça a correção e a republicação do edital com reabertura do prazo (art. 55, § 1º).",
		'esclarecimento' => "TIPO: PEDIDO DE ESCLARECIMENTO (art. 164). Formule perguntas objetivas e numeradas, cada uma ligada à cláusula do edital, de modo que a resposta do órgão vincule todos os licitantes. Evite argumentar: só pergunte.",
		'outro' => "TIPO: MANIFESTAÇÃO ADMINISTRATIVA. Responda exatamente ao que foi pedido, com fatos, datas, provas e fundamento no contrato e na Lei 14.133/2021, e termine com pedidos claros e a lista de anexos.",
		'recurso' => "TIPO: RAZÕES DE RECURSO. Ataque a decisão (habilitação do concorrente, aceitação da proposta dele, ou a minha desclassificação/inabilitação). Verifique: descumprimento de exigência do edital (vinculação ao instrumento convocatório, art. 5º); falhas de habilitação jurídica, fiscal, social e trabalhista e econômico-financeira (arts. 66, 68 e 69); atestados que não comprovam a parcela de maior relevância ou os quantitativos (art. 67); inexequibilidade (art. 59, III e §§ 2º a 4º) e necessidade de diligência para comprová-la; sanções que impedem licitar (art. 14 e art. 156); indícios de atuação coordenada entre licitantes do mesmo grupo (sócios, endereços, telefones em comum) como motivo para diligência e representação; se fui eu o prejudicado, falhas sanáveis que deveriam ter sido objeto de diligência (art. 64 e art. 12, III — formalismo moderado), lembrando que o TCU admite documento novo para comprovar condição que já existia na data da sessão (Acórdão 1211/2021-Plenário). Lembre que o recurso tem efeito suspensivo até a decisão final (art. 168).",
		'contrarrazoes' => "TIPO: CONTRARRAZÕES. Defenda a decisão que me favoreceu. Rebata cada alegação do recurso na ordem em que aparece, mostrando por que não procede (cumprimento do edital, documento que comprova, jurisprudência de formalismo moderado e de vedação a excesso de rigor, falhas meramente formais sanáveis por diligência, art. 64 e art. 12, III). Aponte se o recurso é intempestivo, se a intenção não foi manifestada na sessão (art. 165, § 1º, I) ou se é protelatório. Termine pedindo o não provimento e a manutenção da decisão.",
		'reconsideracao' => "TIPO: PEDIDO DE RECONSIDERAÇÃO (art. 165, II). Mostre de forma objetiva o erro de fato ou de direito do ato e o que deve ser corrigido.",
		'diligencia' => "TIPO: RESPOSTA A DILIGÊNCIA. Responda ponto a ponto exatamente o que foi pedido, sem trazer informação que possa gerar nova dúvida. Para exequibilidade, demonstre a composição de custos (mão de obra conforme convenção coletiva, encargos, insumos, tributos, lucro) e explique economias de escala, contratos semelhantes já executados e preços de mercado. Para documentos, explique que comprovam condição já existente na data da sessão (art. 64; Acórdão 1211/2021-Plenário do TCU). Liste os anexos.",
		'defesa' => "TIPO: DEFESA EM PROCESSO SANCIONATÓRIO (arts. 155 a 163). Verifique: se a conduta está mesmo entre as infrações do art. 155; nulidades (falta de intimação regular, cerceamento de defesa, comissão irregular nos casos do art. 158, prescrição de 5 anos do art. 158, § 4º); se houve culpa da empresa ou fato do órgão (atraso de pagamento, alteração unilateral, falta de condições de execução — arts. 124, 137, § 2º e 104); caso fortuito ou força maior; ausência de dano; e, subsidiariamente, dosimetria pelo art. 156, § 1º (natureza e gravidade, peculiaridades do caso, agravantes e atenuantes, danos à Administração e programa de integridade), pedindo a sanção mais branda (advertência no lugar de multa; multa mínima; afastar impedimento ou inidoneidade). Especifique as provas que pretende produzir (art. 158, caput), se for útil.",
		'alegacoes' => "TIPO: ALEGAÇÕES FINAIS EM PROCESSO SANCIONATÓRIO. Faça a síntese das provas produzidas, reforce as nulidades e os argumentos da defesa e o pedido de arquivamento ou, subsidiariamente, de sanção mais branda (art. 156, § 1º).",
		'recurso_sancao' => "TIPO: RECURSO CONTRA SANÇÃO (art. 166). Ataque a decisão sancionadora: nulidades do processo, falta de motivação, desproporcionalidade (art. 156, § 1º) e ausência de culpa, lembrando o efeito suspensivo do recurso (art. 168).",
		'reconsid_inid' => "TIPO: PEDIDO DE RECONSIDERAÇÃO CONTRA DECLARAÇÃO DE INIDONEIDADE (art. 167). Mostre por que a sanção máxima é desproporcional e quais fatos não foram considerados.",
	);
	return $base . ( $x[ $tipo ] ?? '' );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/recursos/juris', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); foreach ( dv_rc_itens( $uid ) as $x ) { if ( $x['id'] === (string) $r['id'] ) { return dv_rc_juris( $x, $uid ); } }
		return new WP_Error( 'dv_rc', 'Caso não encontrado.', array( 'status' => 404 ) );
	} ) );
	register_rest_route( $ns, '/recursos', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' ); $T = dv_rc_tipos(); $tipos = array();
		foreach ( $T as $k => $t ) { $tipos[] = array( 'k' => $k, 'nome' => $t[0], 'dias' => $t[1], 'base' => $t[2], 'roteiro' => dv_rc_roteiro( $k ) ); }
		$lic = function_exists( 'dv_lic_itens' ) ? array_map( function ( $x ) { return array( 'id' => $x['id'], 'rot' => trim( $x['orgao'] . ' · ' . $x['numero'] ), 'objeto' => $x['objeto'], 'link' => $x['link'] ?? '', 'orgao' => $x['orgao'] ?? '', 'numero' => $x['numero'] ?? '', 'abertura' => substr( (string) ( $x['abertura'] ?? '' ), 0, 10 ), 'modalidade' => $x['modalidade'] ?? '', 'pncp' => $x['pncp'] ?? '' ); }, dv_lic_itens( $uid ) ) : array();
		$ct = false && function_exists( 'dv_ct_itens' ) ? array_map( function ( $x ) { return array( 'id' => $x['id'], 'rot' => trim( ( $x['numero'] ?? '' ) . ' · ' . ( $x['orgao'] ?? '' ), ' ·' ), 'objeto' => mb_substr( (string) ( $x['objeto'] ?? '' ), 0, 200 ), 'cnpj' => $x['cnpj'] ?? '' ); }, dv_ct_itens( $uid ) ) : array();
		return array( 'itens' => array_map( function ( $x ) use ( $hoje ) { return dv_rc_calc( $x, $hoje ); }, dv_rc_itens( $uid ) ), 'tipos' => $tipos, 'lic' => $lic, 'contratos' => $ct, 'hoje' => $hoje );
	} ) );
	register_rest_route( $ns, '/recursos/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $l = dv_rc_itens( $uid ); $id = sanitize_key( (string) $r['id'] ); $T = dv_rc_tipos();
		$d = function ( $k ) use ( $r ) { $v = (string) $r[ $k ]; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$x = array( 'tipo' => isset( $T[ $r['tipo'] ] ) ? $r['tipo'] : 'recurso', 'titulo' => mb_substr( sanitize_text_field( (string) $r['titulo'] ), 0, 200 ), 'orgao' => mb_substr( sanitize_text_field( (string) $r['orgao'] ), 0, 200 ),
			'intimacao' => $d( 'intimacao' ), 'prazo_manual' => $d( 'prazo_manual' ), 'hora' => preg_match( '/^\d{2}:\d{2}$/', (string) $r['hora'] ) ? (string) $r['hora'] : '',
			'prazo_qtd' => max( 0, min( 999, (int) $r['prazo_qtd'] ) ), 'prazo_un' => in_array( $r['prazo_un'], array( 'uteis', 'corridos', 'horas' ), true ) ? $r['prazo_un'] : 'uteis',
			'contrato' => sanitize_key( (string) $r['contrato'] ), 'numero_doc' => mb_substr( sanitize_text_field( (string) $r['numero_doc'] ), 0, 80 ), 'concorrente' => preg_replace( '/\D/', '', (string) $r['concorrente'] ), 'lic' => sanitize_key( (string) $r['lic'] ),
			'fatos' => mb_substr( sanitize_textarea_field( (string) $r['fatos'] ), 0, 6000 ), 'status' => in_array( $r['status'], array( 'aberto', 'respondido', 'protocolado', 'deferido', 'indeferido' ), true ) ? $r['status'] : 'aberto' );
		if ( ! $x['titulo'] && ! $x['orgao'] ) { return new WP_Error( 'dv_rc', 'Dê um nome ao caso ou informe o órgão.', array( 'status' => 400 ) ); }
		$achou = false; foreach ( $l as &$y ) { if ( $y['id'] === $id ) { $y = array_merge( $y, $x ); $y['aviso'] = ''; $achou = true; } } unset( $y );
		if ( ! $achou ) { $id = substr( md5( uniqid( '', true ) ), 0, 10 ); $l[] = $x + array( 'id' => $id, 'criado' => current_time( 'Y-m-d' ), 'aviso' => '' ); }
		dv_rc_salvar_itens( $uid, array_slice( $l, -200 ) ); return array( 'ok' => true, 'id' => $id );
	} ) );
	register_rest_route( $ns, '/recursos/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $id = sanitize_key( (string) $r['id'] );
		dv_rc_salvar_itens( $uid, array_filter( dv_rc_itens( $uid ), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ); return array( 'ok' => true );
	} ) );
	/* Dados oficiais do concorrente para fundamentar o recurso (resumo do Raio-X) */
	register_rest_route( $ns, '/recursos/concorrente', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); if ( strlen( $c ) !== 14 ) { return new WP_Error( 'dv_rc', 'CNPJ inválido.', array( 'status' => 400 ) ); }
		$o = array();
		$f = function_exists( 'dv_ds_interno' ) ? dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $c ) ) : null;
		if ( is_array( $f ) ) {
			$cad = (array) ( $f['cadastro'] ?? array() );
			foreach ( array( 'razao_social', 'situacao', 'descricao_situacao_cadastral', 'data_inicio_atividade', 'capital_social', 'porte', 'cnae_fiscal_descricao', 'municipio', 'uf' ) as $k ) { if ( ! empty( $cad[ $k ] ) && ! is_array( $cad[ $k ] ) ) { $o[] = $k . ': ' . $cad[ $k ]; } }
			$s = (array) ( $f['sancoes'] ?? array() );
			foreach ( array( 'ceis', 'cnep', 'tcu' ) as $k ) { foreach ( (array) ( $s[ $k ] ?? array() ) as $x ) { $o[] = 'Sanção ' . strtoupper( $k ) . ': ' . mb_substr( wp_json_encode( $x, JSON_UNESCAPED_UNICODE ), 0, 300 ); } }
			foreach ( (array) ( $f['avisos'] ?? array() ) as $a ) { $o[] = 'Ponto de atenção: ' . ( is_array( $a ) ? mb_substr( wp_json_encode( $a, JSON_UNESCAPED_UNICODE ), 0, 300 ) : $a ); }
		}
		if ( function_exists( 'dv_ds_pgfn' ) ) { $p = dv_ds_pgfn( $c ); if ( $p && $p['total'] > 0 ) { $o[] = 'Dívida ativa da União (PGFN): R$ ' . number_format( $p['total'], 2, ',', '.' ) . ' em ' . $p['inscricoes'] . ' inscrição(ões)' . ( $p['fgts'] > 0 ? ', incluindo FGTS R$ ' . number_format( $p['fgts'], 2, ',', '.' ) : '' ) . '.'; } }
		return array( 'texto' => $o ? implode( "\n", $o ) : 'Nada relevante encontrado nas bases consultadas.', 'fonte' => 'Receita Federal, CGU (CEIS/CNEP), TCU e PGFN, consultadas em ' . date_i18n( 'd/m/Y' ) );
	} ) );
} );

/* Avisos por e-mail: 2 dias antes, véspera e último dia */
add_action( 'init', function () { if ( ! wp_next_scheduled( 'dv_rc_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:20 UTC' ), 'daily', 'dv_rc_diario' ); } } );
add_action( 'dv_rc_diario', function () {
	global $wpdb; $hoje = current_time( 'Y-m-d' ); $T = dv_rc_tipos();
	foreach ( $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='dv_recursos' AND meta_value<>'[]'" ) as $uid ) {
		$uid = (int) $uid; $l = dv_rc_itens( $uid ); $ev = array(); $mud = false;
		foreach ( $l as &$x ) {
			if ( ( $x['status'] ?? 'aberto' ) !== 'aberto' ) { continue; }
			$c = dv_rc_calc( $x, $hoje ); if ( ! in_array( $c['dias'], array( 2, 1, 0 ), true ) ) { continue; }
			$m = 'd' . $c['dias'] . $hoje; if ( strpos( (string) ( $x['aviso'] ?? '' ), $m ) !== false ) { continue; }
			$x['aviso'] = substr( $m . '|' . ( $x['aviso'] ?? '' ), 0, 80 ); $mud = true; $ev[] = $c;
		}
		unset( $x ); if ( $mud ) { dv_rc_salvar_itens( $uid, $l ); }
		if ( ! $ev ) { continue; }
		$u = get_userdata( $uid ); if ( ! $u ) { continue; }
		$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:20px">desenvolve </span><b style="color:#C9A45C;font-size:20px">estratégias</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Recursos e defesas · prazos</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Olá, ' . esc_html( $u->display_name ) . '.</p>';
		foreach ( $ev as $c ) { $h .= '<div style="border-left:4px solid ' . ( $c['dias'] === 0 ? '#C8604E' : '#C9A45C' ) . ';background:#FAF8F3;padding:12px 14px;margin:10px 0;border-radius:8px"><b>' . esc_html( $T[ $c['tipo'] ][0] ) . '</b> · ' . esc_html( $c['titulo'] ?: $c['orgao'] ) . '<br><span style="font-size:14px">' . ( $c['dias'] === 0 ? '<b style="color:#B04A3A">O prazo termina HOJE</b>' : 'O prazo termina em <b>' . $c['dias'] . ' dia(s)</b>' ) . ' (' . esc_html( date_i18n( 'd/m/Y', strtotime( $c['prazo'] ) ) ) . ').</span></div>'; }
		$h .= '<p style="margin-top:20px"><a href="' . esc_url( home_url( '/plataforma/recursos/' ) ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir recursos e defesas</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Prazo calculado em dias úteis (art. 183 da Lei 14.133/2021) com feriados nacionais. Confira a intimação, o edital e feriados locais. Quer que um consultor da Desenvolve revise a peça? Responda este e-mail.</p></div></div>';
		$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
		$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
		$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
		foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, 'Prazo de recurso/defesa · ' . count( $ev ) . ' aviso(s) · Desenvolve', $h, $hd ); } }
	}
} );

add_shortcode( 'dv_recursos', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="rc" id="dv-rc" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="rc-top rc-fil"><button class="rc-novo">+ Nova impugnação, recurso ou defesa</button><select class="rc-fc" hidden></select></div>
	<div class="rc-list"><p class="rc-h">Carregando…</p></div>
	<p class="rc-h" style="margin-top:18px;font-size:.8rem">Prazos em dias úteis contados a partir do dia seguinte à intimação (art. 183 da Lei 14.133/2021), com feriados nacionais. Confira feriados locais e a forma de contagem prevista no edital. O roteiro organiza o trabalho da sua IA e não é parecer jurídico.</p>
	<dialog class="rc-dlg"><form method="dialog" class="rc-f">
		<h3 class="rc-ft">Recurso ou defesa</h3>
		<label>Tipo<select name="tipo"></select></label>
		<p class="rc-base rc-h"></p>
		<div class="rc-2"><label>Nome do caso<input name="titulo" placeholder="Ex.: Recurso PE 20/2026 Caraguatatuba"></label><label>Órgão<input name="orgao"></label></div>
		<div class="rc-2"><input type="hidden" name="contrato"><label>Nº do processo ou do pregão<input name="numero_doc" placeholder="Ex.: Ofício 123/2026"></label></div>
		<div class="rc-3"><label>Recebida em<input type="date" name="intimacao"></label><label>Hora <small>(prazos em horas)</small><input type="time" name="hora"></label><label>Prazo dado<span style="display:flex;gap:6px"><input name="prazo_qtd" inputmode="numeric" placeholder="ex.: 5" style="width:70px"><select name="prazo_un"><option value="uteis">dias úteis</option><option value="corridos">dias corridos</option><option value="horas">horas</option></select></span></label></div>
		<label>Ou a data-limite exata, se o órgão informou<input type="date" name="prazo_manual"></label>
		<div class="rc-2"><label>Licitação da sua gestão<select name="lic"></select></label><label>CNPJ do concorrente (opcional)<input name="concorrente" inputmode="numeric" placeholder="para puxar dados oficiais"></label></div>
		<label>Fatos do caso <small>(o que aconteceu, o que foi decidido, o que você quer)</small><textarea name="fatos" rows="6"></textarea></label>
		<label>Situação<select name="status"><option value="aberto">Em prazo (a responder)</option><option value="respondido">Respondido</option><option value="protocolado">Protocolado</option><option value="deferido">Deferido / ganhou</option><option value="indeferido">Indeferido / perdeu</option></select></label>
		<input type="hidden" name="id"><p class="rc-fm rc-h"></p>
		<div class="rc-act"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="rc-ok">Salvar</button></div>
	</form></dialog>
	<dialog class="rc-dlg rc-ia"><div class="rc-f">
		<h3>Montar com a sua IA</h3>
		<p class="rc-h">Levamos para a sua IA o roteiro da Desenvolve para este tipo de peça, os fatos do caso e, se houver, os dados oficiais do concorrente.</p>
		<div class="rc-docs"></div>
		<div class="rc-jur"></div>
		<p class="rc-st rc-h"></p>
		<div class="rc-arq"></div><div class="rc-ias"></div>
		<p class="rc-im rc-h" style="font-weight:600"></p>
		<p class="rc-aviso">⚖️ O roteiro é um padrão para orientar a sua IA. Cada caso tem detalhes que mudam a estratégia, e nada substitui a análise de um consultor especializado. Antes de protocolar, peça a revisão da Desenvolve.</p>
		<div class="rc-act"><a class="rc-wa" target="_blank" rel="noopener">Pedir revisão de um consultor</a><button type="button" class="g rc-fechar">Fechar</button></div>
	</div></dialog>
</div>
<style>
.rc{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;--am:#9A6B12;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.rc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.rc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.rc-auto{background:rgba(201,164,92,.10);border:1px solid rgba(201,164,92,.55);border-radius:12px;padding:12px 14px;margin:6px 0 12px;color:inherit}.rc-auto .rc-2{display:block}.rc-auto label{color:inherit}.rc-auto .rc-res{color:inherit}.rc-auto .rc-res b{color:#E8D29A}.rc-auto .rc-res{font-size:.9rem;margin-top:4px}.rc-adv{margin:0 0 12px}.rc-adv summary{cursor:pointer;font-weight:600;color:inherit;opacity:.75;font-size:.88rem;margin-bottom:8px}
.rc input,.rc select,.rc textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}.rc small{color:var(--mu);font-weight:500}
.rc-h{color:var(--mu);font-size:.86rem;margin:6px 0 0}
.rc-c{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-top:10px;display:grid;grid-template-columns:1fr auto;gap:10px}.rc-c .o{font-weight:700}.rc-c .m{color:var(--mu);font-size:.82rem;margin-top:3px}
.rc-ch{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.rc-ch span{font-size:.75rem;font-weight:700;border-radius:99px;padding:3px 9px;background:#EEF1F6;color:#3B5578}.rc-ch .r{background:#FDECE8;color:var(--rd)}.rc-ch .a{background:#FFF4DC;color:var(--am)}.rc-ch .v{background:#EEF7F1;color:var(--gr)}.rc-ch .n{background:#EEE9DE;color:var(--mu)}
.rc-c .ac{display:flex;flex-direction:column;gap:6px;min-width:160px}.rc-c .ac button{font-size:.82rem;padding:8px 12px}
.rc-dlg{border:0;border-radius:16px;padding:0;max-width:680px;width:calc(100% - 32px)}.rc-dlg::backdrop{background:rgba(12,15,19,.55)}
.rc-f{padding:22px;display:grid;gap:10px}.rc-f h3{margin:0}.rc-f label{font-weight:600;font-size:.86rem;display:grid;gap:4px}.rc-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.rc-3{display:grid;grid-template-columns:1fr 1fr 1.3fr;gap:10px}.rc-fil{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}.rc-fil select{max-width:420px}.rc-cd{font-variant-numeric:tabular-nums}.rc-act{display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap}.rc-fm.e{color:var(--rd)}
.arq-dl{border:1px solid rgba(201,164,92,.55);background:rgba(201,164,92,.08);border-radius:12px;padding:10px 12px;margin:8px 0 12px}.arq-dl small{opacity:.75}.arq-dl div{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.arq-dl a{font-size:.82rem;font-weight:600;border:1px solid rgba(127,127,127,.4);border-radius:9px;padding:6px 10px;text-decoration:none;color:inherit}.arq-dl .arq-n{display:block;margin-top:6px}.rc-gd{border:1px solid rgba(127,127,127,.35);border-radius:12px;padding:10px 14px;margin:8px 0 10px;font-size:.88rem}.rc-gd ul{margin:6px 0 6px 18px;padding:0}.rc-gd li{margin:3px 0}.rc-gd small{opacity:.75}.rc-jur{margin:12px 0;font-size:.86rem}.rc-jur a{color:#C9A45C}.rc-ji{display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-weight:500;cursor:pointer}.rc-ji input{margin-top:3px;flex:none}.rc-ji small{display:block;opacity:.75;margin-top:2px;line-height:1.35}
.rc-aviso{font-size:.84rem;border-left:3px solid #C9A45C;padding:6px 10px;margin:10px 0;opacity:.9}
.rc-ig{margin:8px 0 10px}.rc-ig b{display:block}.rc-ig small{display:block;opacity:.75;margin:2px 0 4px}
.rc-ias button{display:inline-flex;align-items:center;margin:4px 6px 4px 0}.rc-ias img{width:18px;height:18px;border-radius:4px;margin-right:8px}
.rc-wa{color:#8A6A2C;font-weight:700;margin-right:auto}
.rc-vazio{border:1px dashed var(--ln);border-radius:14px;padding:22px;text-align:center;color:var(--mu);margin-top:10px}
@media(max-width:700px){.rc{padding:16px}.rc-2,.rc-3{grid-template-columns:1fr}.rc-c{grid-template-columns:1fr}.rc-c .ac{flex-direction:row;flex-wrap:wrap;min-width:0}}
</style>
<script>
(function(){var R=document.getElementById('dv-rc');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,F=R.querySelector('.rc-dlg .rc-f'),D=F.closest('dialog'),D2=R.querySelector('.rc-ia'),cur=null,CONC='';
var IAS=[['ChatGPT','https://chatgpt.com/','chatgpt.com'],['Claude','https://claude.ai/new','claude.ai'],['Perplexity','https://www.perplexity.ai/','perplexity.ai'],['Grok','https://grok.com/','grok.com'],['DeepSeek','https://chat.deepseek.com/','deepseek.com']];
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function req(m,p,b){return fetch(api+p,{method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce,'Content-Type':'application/json'},body:b?JSON.stringify(b):undefined}).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function tipo(k){return S.tipos.filter(function(t){return t.k===k})[0]||S.tipos[0]}
var STN={aberto:'A responder',respondido:'Respondido',protocolado:'Protocolado',deferido:'Deferido',indeferido:'Indeferido'},FC='';
function cd(ts){var d=ts-Date.now();if(d<0)return'prazo encerrado';var m=Math.floor(d/60000),dd=Math.floor(m/1440),h=Math.floor(m%1440/60),mi=m%60;return'faltam '+(dd?dd+'d ':'')+(dd<3?h+'h ':'')+(dd?'':mi+'min')}
function ctr(id){return(S.contratos||[]).filter(function(c){return c.id===id})[0]}
function carregar(){return req('GET','recursos').then(function(j){S=j;pintar()})}
function pintar(){var fc=R.querySelector('.rc-fc');fc.innerHTML='<option value="">Todos os contratos e casos</option>'+(S.contratos||[]).filter(function(c){return S.itens.some(function(x){return x.contrato===c.id})}).map(function(c){return'<option value="'+c.id+'"'+(c.id===FC?' selected':'')+'>'+e(c.rot.slice(0,90))+'</option>'}).join('');
 var l=S.itens.filter(function(x){return!FC||x.contrato===FC}).sort(function(a,b){return(a.status==='aberto'?0:1)-(b.status==='aberto'?0:1)||String(a.prazo||'9').localeCompare(String(b.prazo||'9'))});
 R.querySelector('.rc-list').innerHTML=l.length?l.map(function(x){var t=tipo(x.tipo),ch=[];
  if(x.prazo&&x.status==='aberto'){var rest=x.prazo_ts-Date.now();ch.push([rest<0?'n':rest<864e5?'r':rest<3*864e5?'a':'v','Prazo até '+br(x.prazo)+(x.prazo_hora&&x.prazo_hora!=='23:59'?' às '+x.prazo_hora:'')+' · <b class="rc-cd">'+cd(x.prazo_ts)+'</b>'])}else if(!x.prazo)ch.push(['a','Informe a data da intimação ou o prazo']);
  ch.push(['',STN[x.status]]);
  var c=ctr(x.contrato);return'<div class="rc-c"><div><div class="o">'+e(x.titulo||x.orgao)+'</div><div class="m">'+e(t.nome)+(x.numero_doc?' · '+e(x.numero_doc):'')+(x.orgao&&x.titulo?' · '+e(x.orgao):'')+(x.intimacao?' · recebida em '+br(x.intimacao):'')+(c?'<br>Contrato: '+e(c.rot):'')+'</div><div class="rc-ch">'+ch.map(function(k){return'<span class="'+k[0]+'">'+(k[1].indexOf('<b')>=0?k[1]:e(k[1]))+'</span>'}).join('')+'</div></div><div class="ac"><button data-ia="'+x.id+'">Montar com a sua IA</button><button class="g" data-ed="'+x.id+'">Editar</button><button class="g" data-x="'+x.id+'">Excluir</button></div></div>'}).join(''):'<div class="rc-vazio">Vai impugnar um edital, recorrer de uma decisão ou responder a uma diligência? Cadastre aqui, ligado à licitação: o prazo é calculado na hora, você é avisado por e-mail e a sua IA monta a peça com o roteiro da Desenvolve e as teses que você escolher.</div>'}
function form(x){F.reset();F.querySelector('.rc-fm').textContent='';
 F.querySelector('[name=tipo]').innerHTML=S.tipos.map(function(t){return'<option value="'+t.k+'">'+e(t.nome)+'</option>'}).join('');
 F.querySelector('[name=lic]').innerHTML='<option value="">—</option>'+S.lic.map(function(l){return'<option value="'+l.id+'">'+e(l.rot.slice(0,80))+'</option>'}).join('');
 F.querySelector('[name=contrato]').innerHTML='<option value="">—</option>'+(S.contratos||[]).map(function(c){return'<option value="'+c.id+'">'+e(c.rot.slice(0,90))+'</option>'}).join('');
 if(!x&&PRE)x=PRE;
 if(x)['tipo','titulo','orgao','intimacao','prazo_manual','lic','concorrente','fatos','status','id','contrato','numero_doc','prazo_qtd','prazo_un','hora'].forEach(function(k){var i=F.querySelector('[name='+k+']');if(i&&x[k]!=null)i.value=x[k]});
 base();D.showModal()}
function base(){var t=tipo(F.querySelector('[name=tipo]').value);(function(){var b=F.querySelector('.rc-tes');if(!b){b=document.createElement('p');b.className='rc-tes rc-h';F.querySelector('.rc-base').after(b)}var tv=F.querySelector('[name=tipo]').value,n=0;try{n=Object.keys(JSON.parse(localStorage.getItem('dv_teses_sel')||'{}')).length}catch(z){}b.innerHTML='<a href="/plataforma/banco-de-teses/?q='+encodeURIComponent(TQ[tv]||'')+'" target="_blank" rel="noopener">📚 Buscar teses para esta peça</a>'+(n?' · <b>'+n+' tese(s) selecionada(s)</b> entram no roteiro da IA':' · as teses que você selecionar entram no roteiro da IA')})();F.querySelector('.rc-base').textContent=(t.dias?'Prazo legal: '+t.dias+' dias úteis (calculado sozinho; preencha "Prazo dado" só se o órgão fixou outro). ':'Informe o prazo dado pelo órgão (horas, dias úteis ou corridos) ou a data-limite. ')+'Fundamento: '+t.base+'.'}
F.querySelector('[name=tipo]').addEventListener('change',base);
F.querySelector('[name=contrato]').addEventListener('change',function(){var c=ctr(this.value),o=F.querySelector('[name=orgao]');if(c&&!o.value)o.value=(c.rot.split(' · ')[1]||'')});
var PRE=null;(function(){var q=new URLSearchParams(location.search);if(q.get('contrato')||q.get('tipo')||q.get('lic')||q.get('conc')){PRE={contrato:q.get('contrato')||'',tipo:q.get('tipo')||'recurso',lic:q.get('lic')||'',orgao:q.get('orgao')||'',titulo:q.get('titulo')||'',concorrente:(q.get('conc')||'').replace(/\D/g,'')};if(q.get('pontos')){try{PRE.fatos=localStorage.getItem('dv_imp_pontos')||'';localStorage.removeItem('dv_imp_pontos')}catch(z){}}history.replaceState(null,'',location.pathname)}})();
F.querySelector('.rc-ok').addEventListener('click',function(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});var m=F.querySelector('.rc-fm');m.textContent='Salvando…';req('POST','recursos/salvar',o).then(function(){PRE=null;D.close();carregar()}).catch(function(j){m.className='rc-fm rc-h e';m.textContent=(j&&j.message)||'Erro.'})});
function teses(){var S2={};try{S2=JSON.parse(localStorage.getItem('dv_teses_sel')||'{}')}catch(z){}var L=Object.keys(S2).map(function(k){return S2[k]});if(!L.length)return'';var COL={'Plenário':'Plenário','Primeira Câmara':'1ª Câmara','Segunda Câmara':'2ª Câmara'};return'\n\nJURISPRUDÊNCIA SELECIONADA NO BANCO DE TESES (use como fundamento e cite exatamente assim, sem inventar outros precedentes):\n'+L.map(function(y,i){var r=y.ci?y.ci:(y.src==='tcesp'?'Súmula nº '+y.n+' do TCE-SP':y.src==='tcusum'?'Súmula nº '+y.n+' do TCU':'Acórdão '+y.n+'/'+y.a+' – TCU – '+(COL[y.c]||y.c)+(y.rel?', Rel. Min. '+y.rel:''));return(i+1)+'. "'+y.e+'" ('+r+').'}).join('\n')}
var TQ={impugnacao:'restrição competitividade edital',esclarecimento:'esclarecimento edital',recurso:'habilitação inabilitação',contrarrazoes:'habilitação proposta',reconsideracao:'recurso',diligencia:'diligência',defesa:'sanção',alegacoes:'sanção',recurso_sancao:'sanção',reconsid_inid:'inidoneidade',outro:''};
function texto(x){var t=tipo(x.tipo),l=S.lic.filter(function(y){return y.id===x.lic})[0],c=ctr(x.contrato);return t.roteiro+'\n\n=====\nDADOS DO CASO\n- Tipo de peça: '+t.nome+' — '+t.base+'\n- Órgão: '+(x.orgao||'')+'\n- Caso: '+(x.titulo||'')+(x.numero_doc?'\n- Documento recebido: '+x.numero_doc:'')+(c?'\n- Contrato: '+c.rot+' — '+c.objeto:'')+(l?'\n- Licitação: '+l.rot+' — '+l.objeto+(l.link?' ('+l.link+')':''):'')+(x.intimacao?'\n- Intimação/ata: '+br(x.intimacao):'')+(x.prazo?'\n- Prazo final calculado: '+br(x.prazo)+(x.prazo<new Date().toISOString().slice(0,10)?' (ATENÇÃO: já passou)':''):'')+'\n- Data de hoje: '+new Date().toLocaleDateString('pt-BR')+'\n\nFATOS INFORMADOS PELA EMPRESA:\n'+(x.fatos||'[COMPLETAR]')+(CONC?'\n\nDADOS OFICIAIS DO CONCORRENTE (CNPJ '+x.concorrente+'):\n'+CONC:'')+teses()+jurs()+'\n=====\nDOCUMENTOS ANEXOS A ESTA MENSAGEM (analise todos antes de redigir): '+(DOCS[x.tipo]||DOCS.outro).join('; ')+'. Se algum desses documentos não estiver anexo, aponte o que falta e marque [COMPLETAR] nos trechos que dependem dele.\n\nAVISO: esta minuta é um padrão de apoio e não substitui a análise de um consultor especializado; recomende ao final a revisão por um especialista antes do protocolo.'}
function copiar(t){var dl=document.querySelector('dialog[open]')||document.body,a=document.createElement('textarea');a.value=t;a.setAttribute('readonly','');a.style.cssText='position:fixed;top:0;left:0;width:1px;height:1px;opacity:0';dl.appendChild(a);a.focus();a.select();var ok=false;try{ok=document.execCommand('copy')}catch(z){}a.remove();if(navigator.clipboard&&window.isSecureContext){try{navigator.clipboard.writeText(t)}catch(z){}}return true}
function lim(a){return a[0]==='Claude'?30000:7500}
function QU(a){return{'ChatGPT':'https://chatgpt.com/?q=','Claude':'https://claude.ai/new?q=','Perplexity':'https://www.perplexity.ai/search?q=','Grok':'https://grok.com/?q='}[a[0]]}
function direto(a,t){return!!QU(a)&&encodeURIComponent(t).length<lim(a)}
function ias(){if(!cur)return;var t=texto(cur),bt=function(a,i){return'<button class="g" data-ai="'+i+'"><img src="https://www.google.com/s2/favicons?domain='+a[2]+'&sz=64" alt="">'+a[0]+'</button>'},D=[],C=[];IAS.forEach(function(a,i){(direto(a,t)?D:C).push(bt(a,i))});
 D2.querySelector('.rc-ias').innerHTML=(D.length?'<div class="rc-ig"><b>✅ Levam o pedido completo direto</b><small>A IA abre com o texto já escrito (algumas já enviam sozinhas). Anexe os documentos em seguida.</small><div>'+D.join('')+'</div></div>':'')+(C.length?'<div class="rc-ig"><b>📋 Precisa copiar e colar</b><small>Copiamos o pedido completo ao clicar; na IA, cole (Ctrl+V ou ⌘+V), anexe os documentos e envie.</small><div>'+C.join('')+'</div></div>':'')+'<button class="g" data-cp="1">Só copiar o pedido</button>'}
function copiaJa(t){copiar(t);var c=navigator.clipboard;return c&&window.isSecureContext?Promise.race([c.writeText(t).catch(function(){}),new Promise(function(r){setTimeout(r,1500)})]):Promise.resolve()}
function naIA(a,t){var Q=QU(a),u=a[1];if(Q&&encodeURIComponent(t).length<lim(a))u=Q+encodeURIComponent(t);window.open(u,'_blank','noopener');return u!==a[1]}
function baixarTodos(l){var i=0;(function n(){if(i>=l.length)return;var a=document.createElement('a');a.href=l[i].url;a.download='';a.target='_blank';a.rel='noopener';(document.querySelector('dialog[open]')||document.body).appendChild(a);a.click();a.remove();i++;setTimeout(n,900)})()}
function listaArqs(l){return l.length?'<div class="arq-dl"><b>Arquivos da licitação no PNCP</b> <small>— baixe e anexe na IA</small><div>'+l.map(function(f,i){return'<a href="'+e(f.url)+'" download target="_blank" rel="noopener">⬇ '+e(String(f.titulo||'Arquivo').slice(0,60))+'</a>'}).join('')+'</div>'+(l.length>1?'<button type="button" class="g arq-all">⬇ Baixar todos ('+l.length+')</button>':'')+'<small class="arq-n">Se o navegador perguntar, permita baixar vários arquivos.</small></div>':''}
var DOCS={impugnacao:['Edital completo e todos os anexos (termo de referência, estudo técnico preliminar, minuta do contrato, planilhas)','Pesquisa de preços ou orçamento estimado, se publicados','Respostas a esclarecimentos e erratas já divulgadas'],
 esclarecimento:['Edital completo e anexos','Erratas e esclarecimentos já respondidos pelo órgão'],
 recurso:['Edital e anexos','Ata da sessão (com a intenção de recurso registrada)','Decisão de julgamento ou de habilitação/inabilitação e o parecer que a fundamentou','Proposta e documentos de habilitação do concorrente (se o recurso é contra ele)','Sua proposta, planilha e documentos (se você foi desclassificado ou inabilitado)','Diligências e respostas trocadas durante a sessão'],
 contrarrazoes:['Recurso apresentado pelo concorrente','Edital e anexos','Ata da sessão e a decisão que te deu a vitória','Sua proposta, planilha e documentos de habilitação','Diligências e respostas que comprovam o atendimento'],
 reconsideracao:['Decisão ou ato que você quer rever','Edital, contrato ou documento em que a decisão se baseou','Documentos e provas que mudam o entendimento'],
 diligencia:['Ofício ou mensagem da diligência (o que foi pedido e o prazo)','Edital e anexos (cláusulas citadas)','Sua proposta e planilha de custos','Documentos que comprovam o que foi pedido (atestados, notas fiscais, contratos, declarações)'],
 defesa:['Notificação ou intimação do processo sancionatório','Contrato, edital e termo de referência','Relatórios de fiscalização, ocorrências e medições','Comunicações trocadas com o órgão (ofícios, e-mails) e provas a seu favor'],
 alegacoes:['Todo o processo sancionatório até aqui (notificação, defesa, relatório da comissão)','Contrato e edital','Provas e documentos novos'],
 recurso_sancao:['Decisão que aplicou a sanção e o relatório que a fundamentou','Defesa apresentada e documentos do processo','Contrato e edital','Provas que demonstram a desproporção ou o erro da decisão'],
 reconsid_inid:['Decisão que declarou a inidoneidade','Todo o processo sancionatório','Contrato, edital e provas a seu favor'],
 outro:['Documento que gerou o prazo (ofício, notificação, decisão)','Edital ou contrato relacionado','Provas e documentos do caso']};
function guia(x){var d=DOCS[x.tipo]||DOCS.outro;return'<div class="rc-gd"><b>Para a sua IA fazer uma análise completa, anexe na mesma mensagem:</b><ul>'+d.map(function(z){return'<li>'+e(z)+'</li>'}).join('')+'</ul><small>Quanto mais completo o material, melhor a peça. Documentos que você não tiver, a IA vai sinalizar como “[COMPLETAR]”.</small></div>'}
var JUR=[],JLV=0;function jurs(){var L=JUR.filter(function(j,i){var c=document.querySelector('.rc-jur input[data-j="'+i+'"]');return c&&c.checked});if(!L.length)return'';return'\n\nJURISPRUDÊNCIA SUGERIDA PARA O CASO (selecionada pela esfera do órgão e pelo assunto; use só a que for pertinente, confira o inteiro teor antes de citar e não invente outros precedentes):\n'+L.map(function(y,i){var ee=JLV===0?y.e:JLV===1?(y.e.length>220?y.e.slice(0,220)+'…':y.e):'';return(i+1)+'. '+y.ci+(ee?': "'+ee+'"':'')+(y.u?' — '+y.u:'')}).join('\n')}
function jurCarrega(x){var J=D2.querySelector('.rc-jur');JUR=[];J.innerHTML='<p class="rc-h">Buscando jurisprudência para este caso…</p>';req('GET','recursos/juris?id='+encodeURIComponent(x.id)).then(function(r){JUR=r.itens||[];if(!JUR.length){J.innerHTML='<p class="rc-h">Não achamos precedentes para os termos deste caso. Descreva melhor os fatos (ex.: atestado, planilha, vistoria, garantia) ou use o <a href="/plataforma/teses/" target="_blank">Banco de teses</a>.</p>';return}
 J.innerHTML='<b>⚖️ Jurisprudência sugerida para este caso</b><p class="rc-h" style="margin:4px 0 8px">'+e(r.nota)+' As marcadas vão junto para a sua IA.</p>'+JUR.map(function(y,i){return'<label class="rc-ji"><input type="checkbox" data-j="'+i+'" checked><span><b>'+e(y.fonte)+'</b> · '+e(y.ci)+(y.u?' · <a href="'+e(y.u)+'" target="_blank" rel="noopener">fonte</a>':'')+'<small>'+e(y.e.length>260?y.e.slice(0,260)+'…':y.e)+'</small></span></label>'}).join('')+'<p class="rc-h" style="margin-top:6px">Quer outras? <a href="/plataforma/teses/" target="_blank">Busque no Banco de teses</a>: o que você marcar lá também entra no pedido.</p>';ias();J.onchange=ias}).catch(function(){J.innerHTML=''})}
function abrirIA(x){cur=x;D2.querySelector('.rc-docs').innerHTML=guia(x);jurCarrega(x);CONC='';var st=D2.querySelector('.rc-st');st.textContent='';D2.querySelector('.rc-im').textContent='';
 ias();
 (function(){var A=D2.querySelector('.rc-arq'),l=(S.lic||[]).filter(function(z){return z.id===x.lic})[0],m=l&&/^(\d{14})\/(\d{4})\/(\d+)$/.exec(l.pncp||'');A.innerHTML=m?'<p class="rc-h">Buscando os arquivos da licitação no PNCP…</p>':(x.lic?'':'<p class="rc-h">Vincule uma licitação da sua Gestão ao caso (em Editar) para baixar os arquivos daqui.</p>');if(!m)return;
 var pg='https://pncp.gov.br/app/editais/'+m[1]+'/'+m[2]+'/'+m[3],lk='<p class="rc-h">Também dá para baixar direto na <a href="'+pg+'" target="_blank" rel="noopener">página da licitação no PNCP ↗</a>.</p>';
 function mostra(L){A.innerHTML=(L.length?listaArqs(L):'<p class="rc-h">Não conseguimos listar os arquivos agora (o PNCP está instável).</p>')+lk;var b=A.querySelector('.arq-all');if(b)b.onclick=function(){baixarTodos(L)}}
 var ac=window.AbortController?new AbortController():null,tm=setTimeout(function(){if(ac)ac.abort()},12000);
 fetch('https://pncp.gov.br/api/pncp/v1/orgaos/'+m[1]+'/compras/'+m[2]+'/'+m[3]+'/arquivos',ac?{signal:ac.signal}:{}).then(function(r){return r.json()}).then(function(j){clearTimeout(tm);var L=(j||[]).filter(function(f){return f.url&&f.statusAtivo!==false}).map(function(f){return{titulo:f.titulo,url:f.url}});if(L.length)return mostra(L);throw 0})
 .catch(function(){req('GET','lic/pncp?ref='+encodeURIComponent(l.pncp)).then(function(j){mostra((j.arquivos||[]).map(function(f){return{titulo:f.titulo,url:f.url}}))}).catch(function(){mostra([])})})})();
 D2.querySelector('.rc-wa').href='https://wa.me/5511993500950?text='+encodeURIComponent('Olá! Quero que um consultor da Desenvolve revise uma peça: '+tipo(x.tipo).nome+' — '+(x.titulo||x.orgao)+(x.prazo?' (prazo até '+br(x.prazo)+')':'')+'.');
 D2.showModal();
 if(x.concorrente&&x.concorrente.length===14){st.textContent='Buscando dados oficiais do concorrente…';req('GET','recursos/concorrente?cnpj='+x.concorrente).then(function(j){CONC=j.texto+'\nFonte: '+j.fonte;st.textContent='Dados oficiais do concorrente incluídos.'}).catch(function(){st.textContent='Não foi possível buscar os dados do concorrente agora.'})}}
D2.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b)return;if(b.matches('.rc-fechar')){D2.close();return}var t=texto(cur),m=D2.querySelector('.rc-im');m.style.color='#2F7A55';
 if(b.dataset.cp){copiar(t);m.textContent='Copiado! Cole na sua IA (Ctrl+V ou ⌘+V) e anexe os documentos.';return}
 if(b.dataset.ai!=null){var a=IAS[+b.dataset.ai];m.textContent='Copiando o pedido…';copiaJa(t).then(function(){var pre=naIA(a,t);m.textContent=pre?'Abrimos o '+a[0]+' com o pedido já escrito. Se o texto não aparecer lá (por exemplo, quando abre no aplicativo), é só colar: o pedido completo está copiado. Depois anexe os documentos.':'Pedido copiado! Na aba do '+a[0]+', cole (Ctrl+V ou ⌘+V), anexe os documentos e envie.'})}});
R.addEventListener('click',function(ev){var t=ev.target,x;
 if(t.matches('.rc-novo')){PRE=null;form(null);return}
 if(t.dataset.ed){x=S.itens.filter(function(y){return y.id===t.dataset.ed})[0];form(x);return}
 if(t.dataset.ia){x=S.itens.filter(function(y){return y.id===t.dataset.ia})[0];abrirIA(x);return}
 if(t.dataset.x){if(confirm('Excluir este caso?'))req('POST','recursos/excluir',{id:t.dataset.x}).then(carregar)}});
R.querySelector('.rc-fc').addEventListener('change',function(){FC=this.value;pintar()});

/* ===== Preenchimento automático: escolha a licitação (ou cole o link do PNCP) e o resto se preenche ===== */
(function(){
 function pas(y){var a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e2=b%4,f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e2+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451),mo=Math.floor((h+l-7*m+114)/31),di=((h+l-7*m+114)%31)+1;return new Date(Date.UTC(y,mo-1,di))}
 function isoU(d){return d.toISOString().slice(0,10)}
 function util(d){var w=d.getUTCDay();if(w===0||w===6)return false;var k=isoU(d).slice(5);if(['01-01','04-21','05-01','09-07','10-12','11-02','11-15','11-20','12-25'].indexOf(k)>=0)return false;var sx=pas(d.getUTCFullYear());sx.setUTCDate(sx.getUTCDate()-2);return isoU(sx)!==isoU(d)}
 function antes(ab,n){var d=new Date(ab+'T00:00:00Z'),c=0;while(c<n){d.setUTCDate(d.getUTCDate()-1);if(util(d))c++}return isoU(d)}
 var $=function(n){return F.querySelector('[name='+n+']')},AUTO=true,LK=null;
 // 1) Licitação no topo + campo de link do PNCP
 var lic=$('lic').closest('label'),tp=$('tipo').closest('label'),bx=document.createElement('div');bx.className='rc-auto';
 bx.innerHTML='<div class="rc-2"></div><label class="rc-lk">Ou cole o link do PNCP<span style="display:flex;gap:6px"><input class="rc-url" placeholder="pncp.gov.br/app/editais/…"><button type="button" class="g rc-pux">Puxar</button></span></label><div class="rc-res"></div>';
 tp.after(bx);bx.querySelector('.rc-2').appendChild(lic);lic.firstChild.textContent='Licitação';
 // 2) Campos de dados e prazo ficam recolhidos
 var det=document.createElement('details');det.className='rc-adv';det.innerHTML='<summary>Ajustar nome, órgão, número e prazo</summary>';
 [$('titulo').closest('.rc-2'),$('numero_doc').closest('.rc-2'),$('intimacao').closest('.rc-3'),$('prazo_manual').closest('label')].forEach(function(n){if(n)det.appendChild(n)});
 bx.after(det);
 function curto(){var t=tipo($('tipo').value).nome;return t.split(' (')[0]}
 function resumo(){var r=bx.querySelector('.rc-res'),t=tipo($('tipo').value),pz=$('prazo_manual').value,it=$('intimacao').value,lim='';
  if(pz)lim=pz;else if(it&&t.dias){var d=new Date(it+'T00:00:00Z'),c=0;while(c<t.dias){d.setUTCDate(d.getUTCDate()+1);if(util(d))c++}lim=isoU(d)}
  r.innerHTML=($('orgao').value||$('numero_doc').value?'<b>'+e($('titulo').value||'')+'</b><br>':'')+(lim?'Prazo: até <b>'+br(lim)+'</b>'+(pz&&!it?' (3 dias úteis antes da abertura, art. 164)':t.dias?' ('+t.dias+' dias úteis a partir de '+br(it)+', '+(t.base.split(' (')[0])+')':''):'<span class="rc-h">Escolha a licitação para calcularmos o prazo.</span>')}
 function preencher(l){if(!l)return;LK=l;var t=$('tipo').value,num=(l.numero||'').split(' · ')[0];if(l.orgao)$('orgao').value=l.orgao;if(num&&!$('numero_doc').value)$('numero_doc').value=num;
  if(AUTO)$('titulo').value=curto()+(num?' · '+num:'')+(l.orgao?' · '+l.orgao.slice(0,40):'');
  if((t==='impugnacao'||t==='esclarecimento')&&l.abertura){$('prazo_manual').value=antes(l.abertura,3);$('intimacao').value=''}
  else if(tipo(t).dias&&!$('intimacao').value){$('intimacao').value=new Date().toISOString().slice(0,10);$('prazo_manual').value=''}
  resumo()}
 $('lic').addEventListener('change',function(){var v=this.value;preencher(S.lic.filter(function(l){return l.id===v})[0])});
 var TANT=$('tipo').value;$('tipo').addEventListener('focus',function(){TANT=this.value});$('tipo').addEventListener('change',function(){var ant=tipo(TANT).nome.split(' (')[0],tt=$('titulo').value;if(tt&&tt.indexOf(ant)===0)$('titulo').value=curto()+tt.slice(ant.length);var nv=this.value;if(nv!=='impugnacao'&&nv!=='esclarecimento'&&(TANT==='impugnacao'||TANT==='esclarecimento'))$('prazo_manual').value='';TANT=nv;if(!tipo(nv).dias&&nv!=='impugnacao'&&nv!=='esclarecimento'&&!$('prazo_manual').value){det.open=true}aviso();if(LK)preencher(LK);else{if(tipo(this.value).dias&&!$('intimacao').value)$('intimacao').value=new Date().toISOString().slice(0,10);resumo()}});
 function aviso(){var a=F.querySelector('.rc-tav');if(!a){a=document.createElement('p');a.className='rc-tav rc-h';a.style.color='#B04A3A';tp.after(a)}var f=($('fatos').value||'').toLowerCase(),t=$('tipo').value,m='';if(/desclassific|inabilit/.test(f)&&(t==='diligencia'||t==='impugnacao'||t==='esclarecimento'))m='Os fatos falam em desclassificação ou inabilitação: se a decisão já foi registrada, a peça certa costuma ser o recurso (art. 165), com intenção manifestada na sessão.';else if(/diligencia|diligência/.test(f)&&t==='impugnacao')m='Os fatos falam em diligência: confira se o tipo certo não é “Resposta a diligência”.';if(t==='diligencia'&&!$('prazo_manual').value)m+=(m?' ':'')+'Informe em “Ajustar” o prazo dado pelo pregoeiro na diligência.';a.textContent=m}
 $('titulo').addEventListener('input',function(){AUTO=false});$('fatos').addEventListener('change',aviso);
 ['intimacao','prazo_manual','orgao','numero_doc','prazo_qtd','prazo_un'].forEach(function(n){var i=$(n);if(i)i.addEventListener('change',resumo)});
 bx.querySelector('.rc-pux').onclick=function(){var b=this,u=bx.querySelector('.rc-url').value.trim(),r=bx.querySelector('.rc-res');if(!u)return;b.disabled=true;b.textContent='Puxando…';
  req('GET','lic/pncp?ref='+encodeURIComponent(u)).then(function(j){b.disabled=false;b.textContent='Puxar';preencher({orgao:j.orgao,numero:j.numero,abertura:(j.abertura||'').slice(0,10)});
   // guarda também na Gestão de licitações, para conectar prazos e conferência do edital
   req('POST','lic/salvar',j).then(function(){return req('GET','recursos')}).then(function(x){S.lic=x.lic;var m=S.lic.filter(function(l){return l.pncp===j.pncp})[0];$('lic').innerHTML='<option value="">—</option>'+S.lic.map(function(l){return'<option value="'+l.id+'">'+e(l.rot.slice(0,80))+'</option>'}).join('');if(m)$('lic').value=m.id}).catch(function(){})})
  .catch(function(j){b.disabled=false;b.textContent='Puxar';r.innerHTML='<span class="rc-h" style="color:#B04A3A">'+e((j&&j.message)||'Não conseguimos puxar agora. Escolha a licitação ou preencha em “Ajustar”.')+'</span>'})};
 // ao abrir o formulário
 var _f=form;form=function(x){AUTO=!(x&&x.id);LK=null;bx.querySelector('.rc-url').value='';_f(x);det.open=!!(x&&x.id);if(!(x&&x.id)){var v=$('lic').value;if(v)preencher(S.lic.filter(function(l){return l.id===v})[0]);else{if(tipo($('tipo').value).dias&&!$('intimacao').value)$('intimacao').value=new Date().toISOString().slice(0,10);resumo()}}else resumo()};
})();
var VER=(location.search.match(/[?&]ver=([a-z0-9]+)/)||[])[1];if(VER)history.replaceState(null,'',location.pathname);
carregar().then(function(){if(VER){var x=S.itens.filter(function(y){return y.id===VER})[0];if(x){form(x);return}}if(PRE)form(null)});setInterval(function(){if(S&&!document.querySelector('.rc-dlg[open]'))pintar()},60000);
})();
</script>
	<?php return ob_get_clean();
} );
