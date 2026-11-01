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
	$base = "Você é analista sênior de licitações e contratos públicos da Desenvolve Estratégias, especialista na Lei 14.133/2021 e na jurisprudência do TCU. Vou te passar um caso real. Escreva em português formal, com estrutura de peça administrativa (endereçamento, qualificação, síntese dos fatos, fundamentos, pedidos). Use só os fatos que eu informar e os documentos que eu anexar; quando faltar informação, deixe [COMPLETAR] em vez de inventar. Cite artigos com precisão e só cite acórdãos que você conhece com segurança (número, ano e colegiado); se não tiver certeza, não cite. Não chame a Lei 14.133/2021 de \"nova lei\". Antes da peça, entregue: (1) diagnóstico em 5 linhas: chance (alta, média, baixa) e por quê; (2) os 3 argumentos mais fortes, do melhor para o pior; (3) documentos que devo juntar; (4) riscos de alegar algo que pode se voltar contra mim. Depois, a peça completa. No fim, um checklist de protocolo (prazo, forma de envio, assinatura, anexos). Lembre que isto não é parecer jurídico e que um consultor da Desenvolve pode revisar antes do protocolo.\n\n";
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
	register_rest_route( $ns, '/recursos', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' ); $T = dv_rc_tipos(); $tipos = array();
		foreach ( $T as $k => $t ) { $tipos[] = array( 'k' => $k, 'nome' => $t[0], 'dias' => $t[1], 'base' => $t[2], 'roteiro' => dv_rc_roteiro( $k ) ); }
		$lic = function_exists( 'dv_lic_itens' ) ? array_map( function ( $x ) { return array( 'id' => $x['id'], 'rot' => trim( $x['orgao'] . ' · ' . $x['numero'] ), 'objeto' => $x['objeto'], 'link' => $x['link'] ?? '' ); }, dv_lic_itens( $uid ) ) : array();
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
		return array( 'texto' => $o ? implode( "\n", $o ) : 'Nada relevante encontrado nas bases consultadas.', 'fonte' => 'Receita Federal, CGU (CEIS/CNEP), TCU e PGFN, pela plataforma Desenvolve em ' . date_i18n( 'd/m/Y' ) );
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
		<p class="rc-h">Levamos para a sua IA o roteiro da Desenvolve para este tipo de peça, os fatos do caso e, se houver, os dados oficiais do concorrente. Anexe na mesma mensagem os documentos (edital, ata, decisão, recurso do adversário, notificação).</p>
		<p class="rc-st rc-h"></p>
		<div class="rc-ias"></div>
		<p class="rc-im rc-h" style="font-weight:600"></p>
		<div class="rc-act"><a class="rc-wa" target="_blank" rel="noopener">Pedir revisão de um consultor</a><button type="button" class="g rc-fechar">Fechar</button></div>
	</div></dialog>
</div>
<style>
.rc{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;--am:#9A6B12;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.rc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.rc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.rc input,.rc select,.rc textarea{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}.rc small{color:var(--mu);font-weight:500}
.rc-h{color:var(--mu);font-size:.86rem;margin:6px 0 0}
.rc-c{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:14px 16px;margin-top:10px;display:grid;grid-template-columns:1fr auto;gap:10px}.rc-c .o{font-weight:700}.rc-c .m{color:var(--mu);font-size:.82rem;margin-top:3px}
.rc-ch{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.rc-ch span{font-size:.75rem;font-weight:700;border-radius:99px;padding:3px 9px;background:#EEF1F6;color:#3B5578}.rc-ch .r{background:#FDECE8;color:var(--rd)}.rc-ch .a{background:#FFF4DC;color:var(--am)}.rc-ch .v{background:#EEF7F1;color:var(--gr)}.rc-ch .n{background:#EEE9DE;color:var(--mu)}
.rc-c .ac{display:flex;flex-direction:column;gap:6px;min-width:160px}.rc-c .ac button{font-size:.82rem;padding:8px 12px}
.rc-dlg{border:0;border-radius:16px;padding:0;max-width:680px;width:calc(100% - 32px)}.rc-dlg::backdrop{background:rgba(12,15,19,.55)}
.rc-f{padding:22px;display:grid;gap:10px}.rc-f h3{margin:0}.rc-f label{font-weight:600;font-size:.86rem;display:grid;gap:4px}.rc-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.rc-3{display:grid;grid-template-columns:1fr 1fr 1.3fr;gap:10px}.rc-fil{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}.rc-fil select{max-width:420px}.rc-cd{font-variant-numeric:tabular-nums}.rc-act{display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap}.rc-fm.e{color:var(--rd)}
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
  var c=ctr(x.contrato);return'<div class="rc-c"><div><div class="o">'+e(x.titulo||x.orgao)+'</div><div class="m">'+e(t.nome)+(x.numero_doc?' · '+e(x.numero_doc):'')+(x.orgao&&x.titulo?' · '+e(x.orgao):'')+(x.intimacao?' · recebida em '+br(x.intimacao):'')+(c?'<br>Contrato: '+e(c.rot):'')+'</div><div class="rc-ch">'+ch.map(function(k){return'<span class="'+k[0]+'">'+(k[1].indexOf('<b')>=0?k[1]:e(k[1]))+'</span>'}).join('')+'</div></div><div class="ac"><button data-ia="'+x.id+'">Montar com a sua IA</button><button class="g" data-ed="'+x.id+'">Editar</button><button class="g" data-x="'+x.id+'">Excluir</button></div></div>'}).join(''):'<div class="rc-vazio">Recebeu uma notificação, um ofício, uma diligência, uma decisão ou um processo de penalidade? Cadastre aqui, ligado ao contrato: o prazo é calculado na hora, você é avisado por e-mail e a sua IA monta a peça com o roteiro da Desenvolve.</div>'}
function form(x){F.reset();F.querySelector('.rc-fm').textContent='';
 F.querySelector('[name=tipo]').innerHTML=S.tipos.map(function(t){return'<option value="'+t.k+'">'+e(t.nome)+'</option>'}).join('');
 F.querySelector('[name=lic]').innerHTML='<option value="">—</option>'+S.lic.map(function(l){return'<option value="'+l.id+'">'+e(l.rot.slice(0,80))+'</option>'}).join('');
 F.querySelector('[name=contrato]').innerHTML='<option value="">—</option>'+(S.contratos||[]).map(function(c){return'<option value="'+c.id+'">'+e(c.rot.slice(0,90))+'</option>'}).join('');
 if(!x&&PRE)x=PRE;
 if(x)['tipo','titulo','orgao','intimacao','prazo_manual','lic','concorrente','fatos','status','id','contrato','numero_doc','prazo_qtd','prazo_un','hora'].forEach(function(k){var i=F.querySelector('[name='+k+']');if(i&&x[k]!=null)i.value=x[k]});
 base();D.showModal()}
function base(){var t=tipo(F.querySelector('[name=tipo]').value);F.querySelector('.rc-base').textContent=(t.dias?'Prazo legal: '+t.dias+' dias úteis (calculado sozinho; preencha "Prazo dado" só se o órgão fixou outro). ':'Informe o prazo dado pelo órgão (horas, dias úteis ou corridos) ou a data-limite. ')+'Fundamento: '+t.base+'.'}
F.querySelector('[name=tipo]').addEventListener('change',base);
F.querySelector('[name=contrato]').addEventListener('change',function(){var c=ctr(this.value),o=F.querySelector('[name=orgao]');if(c&&!o.value)o.value=(c.rot.split(' · ')[1]||'')});
var PRE=null;(function(){var q=new URLSearchParams(location.search);if(q.get('contrato')||q.get('tipo')||q.get('lic')){PRE={contrato:q.get('contrato')||'',tipo:q.get('tipo')||'recurso',lic:q.get('lic')||'',orgao:q.get('orgao')||'',titulo:q.get('titulo')||''};history.replaceState(null,'',location.pathname)}})();
F.querySelector('.rc-ok').addEventListener('click',function(){var o={};new FormData(F).forEach(function(v,k){o[k]=v});var m=F.querySelector('.rc-fm');m.textContent='Salvando…';req('POST','recursos/salvar',o).then(function(){PRE=null;D.close();carregar()}).catch(function(j){m.className='rc-fm rc-h e';m.textContent=(j&&j.message)||'Erro.'})});
function texto(x){var t=tipo(x.tipo),l=S.lic.filter(function(y){return y.id===x.lic})[0],c=ctr(x.contrato);return t.roteiro+'\n\n=====\nDADOS DO CASO (plataforma Desenvolve)\n- Tipo de peça: '+t.nome+' — '+t.base+'\n- Órgão: '+(x.orgao||'')+'\n- Caso: '+(x.titulo||'')+(x.numero_doc?'\n- Documento recebido: '+x.numero_doc:'')+(c?'\n- Contrato: '+c.rot+' — '+c.objeto:'')+(l?'\n- Licitação: '+l.rot+' — '+l.objeto+(l.link?' ('+l.link+')':''):'')+(x.intimacao?'\n- Intimação/ata: '+br(x.intimacao):'')+(x.prazo?'\n- Prazo final calculado: '+br(x.prazo):'')+'\n\nFATOS INFORMADOS PELA EMPRESA:\n'+(x.fatos||'[COMPLETAR]')+(CONC?'\n\nDADOS OFICIAIS DO CONCORRENTE (CNPJ '+x.concorrente+'):\n'+CONC:'')+'\n=====\nOs documentos do caso estão anexos a esta mensagem.'}
function copiar(t){var a=document.createElement('textarea');a.value=t;a.style.cssText='position:fixed;top:0;opacity:0';document.body.appendChild(a);a.select();var ok=false;try{ok=document.execCommand('copy')}catch(z){}a.remove();if(!ok&&navigator.clipboard)navigator.clipboard.writeText(t)}
function abrirIA(x){cur=x;CONC='';var st=D2.querySelector('.rc-st');st.textContent='';D2.querySelector('.rc-im').textContent='';
 D2.querySelector('.rc-ias').innerHTML=IAS.map(function(a,i){return'<button class="g" data-ai="'+i+'"><img src="https://www.google.com/s2/favicons?domain='+a[2]+'&sz=64" alt="">'+a[0]+'</button>'}).join('')+'<button class="g" data-cp="1">Só copiar</button>';
 D2.querySelector('.rc-wa').href='https://wa.me/5511993500950?text='+encodeURIComponent('Olá! Quero que um consultor da Desenvolve revise uma peça: '+tipo(x.tipo).nome+' — '+(x.titulo||x.orgao)+(x.prazo?' (prazo até '+br(x.prazo)+')':'')+'.');
 D2.showModal();
 if(x.concorrente&&x.concorrente.length===14){st.textContent='Buscando dados oficiais do concorrente…';req('GET','recursos/concorrente?cnpj='+x.concorrente).then(function(j){CONC=j.texto+'\nFonte: '+j.fonte;st.textContent='Dados oficiais do concorrente incluídos.'}).catch(function(){st.textContent='Não foi possível buscar os dados do concorrente agora.'})}}
D2.addEventListener('click',function(ev){var b=ev.target.closest('button');if(!b)return;if(b.matches('.rc-fechar')){D2.close();return}var t=texto(cur),m=D2.querySelector('.rc-im');m.style.color='#2F7A55';
 if(b.dataset.cp){copiar(t);m.textContent='Copiado! Cole na sua IA (Ctrl+V ou ⌘+V) e anexe os documentos.';return}
 if(b.dataset.ai!=null){var a=IAS[+b.dataset.ai];copiar(t);window.open(a[1],'_blank','noopener');m.textContent='Copiado! Na aba do '+a[0]+', cole (Ctrl+V ou ⌘+V), anexe os documentos e envie.'}});
R.addEventListener('click',function(ev){var t=ev.target,x;
 if(t.matches('.rc-novo')){PRE=null;form(null);return}
 if(t.dataset.ed){x=S.itens.filter(function(y){return y.id===t.dataset.ed})[0];form(x);return}
 if(t.dataset.ia){x=S.itens.filter(function(y){return y.id===t.dataset.ia})[0];abrirIA(x);return}
 if(t.dataset.x){if(confirm('Excluir este caso?'))req('POST','recursos/excluir',{id:t.dataset.x}).then(carregar)}});
R.querySelector('.rc-fc').addEventListener('change',function(){FC=this.value;pintar()});
var VER=(location.search.match(/[?&]ver=([a-z0-9]+)/)||[])[1];if(VER)history.replaceState(null,'',location.pathname);
carregar().then(function(){if(VER){var x=S.itens.filter(function(y){return y.id===VER})[0];if(x){form(x);return}}if(PRE)form(null)});setInterval(function(){if(S&&!document.querySelector('.rc-dlg[open]'))pintar()},60000);
})();
</script>
	<?php return ob_get_clean();
} );
