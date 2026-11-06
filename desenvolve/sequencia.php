/**
 * Desenvolve — Sequência de e-mails para quem entrou na lista (consulta grátis ou lista de espera)
 * Dia 2: três licitações abertas do ramo (ou do Brasil) · Dia 5: como funciona o plano Busca · Dia 12: lembrete do lançamento (ou "já está no ar").
 * Roda uma vez por dia (WP-Cron, por volta das 9h de Brasília). Cada e-mail sai uma única vez por contato (marca 'seq' na lista).
 * Quem vira assinante (e-mail com conta ativa) não recebe mais a sequência.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'dv_seq_diaria' ) ) {
		$h = strtotime( 'today 09:10' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); if ( $h < time() ) { $h += DAY_IN_SECONDS; }
		wp_schedule_event( $h, 'daily', 'dv_seq_diaria' );
	}
} );

function dv_seq_ramo_q( $ramo ) {
	$m = array( 'limpeza' => 'limpeza', 'vigil' => 'vigilância', 'aliment' => 'alimentação', 'saúde' => 'saúde', 'saude' => 'saúde', 'obra' => 'obra', 'tecnolog' => 'tecnologia', 'fornec' => 'fornecimento', 'consult' => 'consultoria', 'advoc' => 'consultoria' );
	$r = mb_strtolower( (string) $ramo );
	foreach ( $m as $k => $q ) { if ( $k && strpos( $r, $k ) !== false ) { return $q; } }
	return '';
}
function dv_seq_envia( $em, $assunto, $corpo ) {
	$from = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	return wp_mail( $em, $assunto, $corpo . "\n\nSe não quiser mais receber estes e-mails, responda com \"sair\".\n\nEquipe Desenvolve\nhttps://desenvolveestrategias.com.br", array( 'From: Desenvolve <' . $from . '>' ) );
}
add_action( 'dv_seq_diaria', function () {
	if ( ! function_exists( 'dv_lista' ) ) { return; }
	$l = dv_lista(); if ( ! $l ) { return; }
	$agora = current_time( 'timestamp' ); $lanc = function_exists( 'dv_pre_lancamento' ) ? dv_pre_lancamento() : true; $mudou = false; $enviados = 0;
	foreach ( $l as $em => $c ) {
		if ( $enviados >= 40 ) { break; } // no máximo 40 e-mails por rodada
		if ( empty( $c['data'] ) || empty( $c['email'] ) ) { continue; }
		$u = get_user_by( 'email', $c['email'] ); if ( $u && function_exists( 'dv_status' ) && dv_status( $u->ID ) === 'ativo' ) { continue; }
		$dias = (int) floor( ( $agora - strtotime( $c['data'] ) ) / DAY_IN_SECONDS ); $seq = (int) ( $c['seq'] ?? 0 );
		$nome = trim( (string) ( $c['nome'] ?? '' ) ); $ola = 'Olá' . ( $nome ? ', ' . explode( ' ', $nome )[0] : '' ) . '!';
		$ok = false;
		if ( $dias >= 2 && $seq < 2 ) {
			$q = dv_seq_ramo_q( $c['ramo'] ?? '' ); $lista = '';
			if ( function_exists( 'dv_am_editais' ) ) {
				$r = dv_am_editais( $q, '', 0, 0 );
				if ( is_array( $r ) ) { $it = array_merge( (array) ( $r['editais'] ?? array() ), (array) ( $r['bloqueado']['editais'] ?? array() ) ); $n = 0;
					foreach ( $it as $x ) { if ( empty( $x['objeto'] ) ) { continue; } $lista .= "\n• " . mb_substr( $x['objeto'], 0, 140 ) . "\n  " . $x['orgao'] . ( $x['local'] ? ' · ' . $x['local'] : '' ) . ( $x['fim'] ? ' · propostas até ' . date_i18n( 'd/m', strtotime( $x['fim'] ) ) : '' ) . ( ! empty( $x['link'] ) ? "\n  " . $x['link'] : '' ) . "\n"; if ( ++$n >= 3 ) { break; } }
				}
			}
			$ok = dv_seq_envia( $c['email'], $q ? 'Três licitações abertas de ' . $q . ' hoje' : 'Três licitações abertas hoje', $ola . "\n\nComo prometido, aqui vão três licitações que estão abertas agora" . ( $q ? ' na área de ' . $q : '' ) . ", vindas do PNCP:\n" . ( $lista ? $lista : "\n(O PNCP não respondeu agora; faça a busca pelo site.)\n" ) . "\nPara ver todas, por cidade, estado ou raio de atendimento, use a busca grátis: https://desenvolveestrategias.com.br/consulta-gratis/\n\nCom o plano Busca (R$ 19,90 por mês), as novas do seu ramo chegam todo dia por e-mail, com os prazos na agenda." );
			if ( $ok ) { $l[ $em ]['seq'] = 2; }
		} elseif ( $dias >= 5 && $seq < 5 ) {
			$ok = dv_seq_envia( $c['email'], 'Como funciona o plano Busca, em 1 minuto', $ola . "\n\nO plano Busca da Desenvolve custa R$ 19,90 por mês e faz quatro coisas:\n\n1. Todo dia de manhã, manda por e-mail as licitações novas do que você vende, na sua região.\n2. Busca de licitações abertas por palavra, cidade, estado e raio de atendimento em km, com a opção de ver só contratação rápida (dispensa e emergencial).\n3. Gestão das licitações que você escolheu disputar, com a agenda de prazos e lembretes.\n4. Documentos e certidões com aviso antes de vencer.\n\nÉ o plano de entrada, pensado para MEI, microempresa e empresa de pequeno porte. Quem disputa todo mês e quer o Raio-X do concorrente e do comprador usa o plano Completo, por R$ 79,90.\n\nQuem está na lista assina pelo preço de lançamento, garantido enquanto mantiver a assinatura, e ganha 100 créditos de bônus no primeiro mês.\n\nPlanos: https://desenvolveestrategias.com.br/#lista" );
			if ( $ok ) { $l[ $em ]['seq'] = 5; }
		} elseif ( $dias >= 12 && $seq < 12 ) {
			$ok = dv_seq_envia( $c['email'], $lanc ? 'Lançamento da Desenvolve em 17 de novembro' : 'A plataforma da Desenvolve já está no ar', $ola . "\n\n" . ( $lanc ? "A plataforma da Desenvolve abre no dia 17 de novembro. Quem está na lista recebe o acesso primeiro, por e-mail, com o preço de lançamento garantido e 100 créditos de bônus no primeiro mês." : "A plataforma da Desenvolve está no ar. O seu preço de lançamento e os 100 créditos de bônus continuam valendo: é só assinar com este mesmo e-mail." ) . "\n\nEnquanto isso, a busca grátis continua aberta: https://desenvolveestrategias.com.br/consulta-gratis/\n\nPlanos: https://desenvolveestrategias.com.br/#lista" );
			if ( $ok ) { $l[ $em ]['seq'] = 12; }
		}
		if ( $ok ) { $mudou = true; $enviados++; }
	}
	if ( $mudou ) { update_option( 'dv_lista', $l, false ); }
	update_option( 'dv_seq_ultima', current_time( 'mysql' ) . ' · ' . $enviados . ' e-mail(s)', false );
} );
