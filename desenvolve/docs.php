/**
 * Desenvolve — Documentos de habilitação (controle de certidões e vencimentos)
 * - Só cadastro para alerta: a plataforma NÃO guarda arquivos. O PDF pode ser lido no navegador para preencher os dados, sem envio.
 * - Lê o PDF no navegador do assinante: identifica a certidão, a validade, o CNPJ e se é negativa/positiva.
 * - Checklist de habilitação da Lei 14.133/2021 (arts. 66 a 69) por empresa, com % de prontidão.
 * - Radar: avisa antes de emitir quando há dívida ativa na PGFN, sanção (CEIS/CNEP/TCU) ou CNPJ irregular.
 * - Alertas por e-mail 30, 15, 7, 3 e 1 dia antes e no vencimento. Pasta de habilitação em ZIP.
 * Incluso na assinatura (não consome créditos). Depende do núcleo (dv_tools_perm, dv_cfg) e do dossiê (dv_ds_pgfn).
 */

define( 'DV_DOCS_MAX_EMP', 20 );
define( 'DV_DOCS_MAX_MB', 15 );

function dv_docs_tipos() {
	// chave => [rótulo, grupo, validade padrão em dias (0 = não vence), link de emissão, obrigatória no checklist]
	return array(
		'contrato_social' => array( 'Contrato social / última alteração consolidada', 'Habilitação jurídica (art. 66)', 0, '', 1 ),
		'cartao_cnpj'     => array( 'Comprovante de inscrição no CNPJ', 'Habilitação jurídica (art. 66)', 0, 'https://solucoes.receita.fazenda.gov.br/servicos/cnpjreva/cnpjreva_solicitacao.asp', 1 ),
		'docs_socios'     => array( 'Documentos dos sócios / administradores', 'Habilitação jurídica (art. 66)', 0, '', 1 ),
		'procuracao'      => array( 'Procuração do representante', 'Habilitação jurídica (art. 66)', 365, '', 0 ),
		'cnd_federal'     => array( 'CND federal (tributos federais e dívida ativa da União)', 'Regularidade fiscal, social e trabalhista (art. 68)', 180, 'https://servicos.receitafederal.gov.br/servico/certidoes/#/home', 1 ),
		'crf_fgts'        => array( 'Certificado de Regularidade do FGTS (CRF)', 'Regularidade fiscal, social e trabalhista (art. 68)', 30, 'https://consulta-crf.caixa.gov.br/consultacrf/pages/consultaEmpregador.jsf', 1 ),
		'cndt'            => array( 'Certidão Negativa de Débitos Trabalhistas (CNDT)', 'Regularidade fiscal, social e trabalhista (art. 68)', 180, 'https://cndt-certidao.tst.jus.br/inicio.faces', 1 ),
		'cnd_estadual'    => array( 'Certidão de débitos estaduais', 'Regularidade fiscal, social e trabalhista (art. 68)', 90, '', 1 ),
		'cnd_municipal'   => array( 'Certidão de débitos municipais', 'Regularidade fiscal, social e trabalhista (art. 68)', 90, '', 1 ),
		'inscricao_mun'   => array( 'Inscrição municipal / estadual (cadastro de contribuinte)', 'Regularidade fiscal, social e trabalhista (art. 68)', 0, '', 0 ),
		'balanco'         => array( 'Balanço patrimonial e DRE (2 últimos exercícios)', 'Qualificação econômico-financeira (art. 69)', 0, '', 1 ),
		'falencia'        => array( 'Certidão de falência e recuperação judicial', 'Qualificação econômico-financeira (art. 69)', 90, '', 1 ),
		'indices'         => array( 'Demonstração dos índices contábeis (LG, SG, LC)', 'Qualificação econômico-financeira (art. 69)', 0, '', 0 ),
		'atestado'        => array( 'Atestado de capacidade técnica', 'Qualificação técnica (art. 67)', 0, '', 1 ),
		'registro_cons'   => array( 'Registro no conselho profissional (CREA, CRA, CRQ...)', 'Qualificação técnica (art. 67)', 365, '', 0 ),
		'cat'             => array( 'Certidão de Acervo Técnico (CAT)', 'Qualificação técnica (art. 67)', 0, '', 0 ),
		'sicaf'           => array( 'SICAF (comprovante de situação)', 'Cadastros e outros', 0, 'https://www3.comprasnet.gov.br/sicaf-web/index.jsf', 0 ),
		'crc'             => array( 'Certificado de registro cadastral (CAUFESP, CRC estadual/municipal)', 'Cadastros e outros', 365, '', 0 ),
		'alvara'          => array( 'Alvará de funcionamento', 'Cadastros e outros', 365, '', 0 ),
		'licenca'         => array( 'Licença sanitária / ambiental / AVCB', 'Cadastros e outros', 365, '', 0 ),
		'certificado_dig' => array( 'Certificado digital (e-CNPJ)', 'Cadastros e outros', 365, '', 0 ),
		'garantia'        => array( 'Apólice de seguro garantia', 'Cadastros e outros', 0, '', 0 ),
		'declaracoes'     => array( 'Declarações (art. 63, menor aprendiz, ME/EPP etc.)', 'Cadastros e outros', 0, '', 0 ),
		'outro'           => array( 'Outro documento (você dá o nome)', 'Outros documentos que você controla', 0, '', 0 ),
	);
}

add_action( 'init', function () {
	if ( get_option( 'dv_docs_semarq' ) !== '1' ) { // a plataforma deixou de guardar arquivos: apaga a pasta antiga, se existir
		$up = wp_upload_dir(); $base = trailingslashit( $up['basedir'] ) . 'dv-docs';
		if ( is_dir( $base ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); } @rmdir( $base ); }
		global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}dv_docs SET arquivo='', nome_arq='', tamanho=0" );
		update_option( 'dv_docs_semarq', '1' );
	}
	if ( get_option( 'dv_docs_db' ) !== '1' ) {
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}dv_docs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			cnpj CHAR(14) NOT NULL,
			tipo VARCHAR(30) NOT NULL,
			titulo VARCHAR(190) NOT NULL DEFAULT '',
			emissao DATE NULL,
			validade DATE NULL,
			resultado VARCHAR(30) NOT NULL DEFAULT '',
			arquivo VARCHAR(190) NOT NULL DEFAULT '',
			nome_arq VARCHAR(190) NOT NULL DEFAULT '',
			tamanho INT UNSIGNED NOT NULL DEFAULT 0,
			obs VARCHAR(500) NOT NULL DEFAULT '',
			avisado VARCHAR(40) NOT NULL DEFAULT '',
			criado DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY uc (user_id,cnpj),
			KEY v (validade)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'dv_docs_db', '1' );
	}
	if ( ! wp_next_scheduled( 'dv_docs_diario' ) ) { wp_schedule_event( strtotime( 'tomorrow 10:00 UTC' ), 'daily', 'dv_docs_diario' ); }
} );

/** Pasta protegida: nada aqui é servido diretamente; o download passa pela API com login. */
function dv_docs_dir( $uid = 0 ) {
	$up = wp_upload_dir(); $base = trailingslashit( $up['basedir'] ) . 'dv-docs';
	if ( ! is_dir( $base ) ) { wp_mkdir_p( $base ); }
	if ( ! file_exists( $base . '/.htaccess' ) ) { file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n" ); }
	if ( ! file_exists( $base . '/index.php' ) ) { file_put_contents( $base . '/index.php', '<?php // silêncio' ); }
	if ( ! $uid ) { return $base; }
	$d = $base . '/' . (int) $uid . '-' . substr( wp_hash( 'dvdocs' . $uid ), 0, 12 );
	if ( ! is_dir( $d ) ) { wp_mkdir_p( $d ); file_put_contents( $d . '/index.php', '<?php // silêncio' ); }
	return $d;
}

function dv_docs_emps( $uid ) { $e = json_decode( get_user_meta( $uid, 'dv_docs_emp', true ) ?: '[]', true ); return is_array( $e ) ? $e : array(); }
function dv_docs_emps_salvar( $uid, $e ) { update_user_meta( $uid, 'dv_docs_emp', wp_slash( wp_json_encode( array_values( $e ), JSON_UNESCAPED_UNICODE ) ) ); }

function dv_docs_situacao( $d, $hoje ) {
	if ( empty( $d['validade'] ) ) { return array( 'sem_validade', null ); }
	$dias = (int) floor( ( strtotime( $d['validade'] ) - strtotime( $hoje ) ) / 86400 );
	if ( $dias < 0 ) { return array( 'vencido', $dias ); }
	if ( $dias <= 30 ) { return array( 'vencendo', $dias ); }
	return array( 'ok', $dias );
}

/** Radar: o que pode dar errado na próxima emissão ou na habilitação, com dados oficiais. Cache de 12 h. */
function dv_docs_radar( $cnpj ) {
	$ck = 'dv_dr1_' . $cnpj; $c = get_transient( $ck ); if ( is_array( $c ) ) { return $c; }
	$al = array();
	if ( function_exists( 'dv_ds_pgfn' ) ) {
		$p = dv_ds_pgfn( $cnpj );
		if ( $p && $p['total'] > 0 ) {
			$al[] = array( 'nivel' => 'alto', 'tipo' => 'cnd_federal', 'txt' => 'Há ' . $p['inscricoes'] . ' inscrição(ões) em dívida ativa da União (R$ ' . number_format( $p['total'], 2, ',', '.' ) . '). A CND federal pode sair positiva: confira se há parcelamento ou garantia que permita a certidão positiva com efeitos de negativa.' );
			if ( $p['fgts'] > 0 ) { $al[] = array( 'nivel' => 'alto', 'tipo' => 'crf_fgts', 'txt' => 'Há débito de FGTS inscrito em dívida ativa (R$ ' . number_format( $p['fgts'], 2, ',', '.' ) . '). O CRF pode ser negado.' ); }
		}
	}
	if ( function_exists( 'dv_ds_interno' ) ) {
		$f = dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cnpj ) );
		if ( is_array( $f ) ) {
			$s = (array) ( $f['sancoes'] ?? array() );
			$n = count( (array) ( $s['ceis'] ?? array() ) ) + count( (array) ( $s['cnep'] ?? array() ) ) + count( (array) ( $s['tcu'] ?? array() ) );
			if ( $n ) { $al[] = array( 'nivel' => 'alto', 'tipo' => '', 'txt' => 'Constam ' . $n . ' registro(s) em cadastros de sanções (CEIS, CNEP ou TCU). Verifique se algum impede licitar (art. 14 e art. 156 da Lei 14.133/2021).' ); }
			$cad = (array) ( $f['cadastro'] ?? array() );
			$sit = strtoupper( (string) ( $cad['situacao'] ?? $cad['descricao_situacao_cadastral'] ?? '' ) );
			if ( $sit && strpos( $sit, 'ATIVA' ) === false ) { $al[] = array( 'nivel' => 'alto', 'tipo' => 'cartao_cnpj', 'txt' => 'A situação cadastral do CNPJ na Receita é ' . $sit . '. Isso impede a habilitação.' ); }
		}
	}
	$r = array( 'alertas' => $al, 'em' => current_time( 'mysql' ) );
	set_transient( $ck, $r, 12 * HOUR_IN_SECONDS );
	return $r;
}

function dv_docs_lista( $uid, $cnpj = '' ) {
	global $wpdb; $w = $cnpj ? $wpdb->prepare( ' AND cnpj=%s', $cnpj ) : '';
	return $wpdb->get_results( $wpdb->prepare( "SELECT id,cnpj,tipo,titulo,emissao,validade,resultado,nome_arq,tamanho,obs,criado,(arquivo<>'') AS tem_arq FROM {$wpdb->prefix}dv_docs WHERE user_id=%d$w ORDER BY validade IS NULL, validade ASC, id DESC", $uid ), ARRAY_A );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';

	register_rest_route( $ns, '/docs', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
		$uid = get_current_user_id(); $hoje = current_time( 'Y-m-d' ); $T = dv_docs_tipos();
		$docs = array_map( function ( $d ) use ( $hoje ) { list( $s, $dias ) = dv_docs_situacao( $d, $hoje ); $d['situacao'] = $s; $d['dias'] = $dias; $d['tem_arq'] = (int) $d['tem_arq']; return $d; }, dv_docs_lista( $uid ) );
		$tipos = array(); foreach ( $T as $k => $t ) { $tipos[] = array( 'k' => $k, 'nome' => $t[0], 'grupo' => $t[1], 'dias' => $t[2], 'link' => $t[3], 'obrig' => $t[4] ); }
		return array( 'empresas' => dv_docs_emps( $uid ), 'docs' => $docs, 'tipos' => $tipos, 'pref' => function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array(), 'hoje' => $hoje, 'zip' => class_exists( 'ZipArchive' ) );
	} ) );

	register_rest_route( $ns, '/docs/empresa', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$uid = get_current_user_id(); $c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); $e = dv_docs_emps( $uid );
		if ( $r['remover'] ) {
			global $wpdb; $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dv_docs WHERE user_id=%d AND cnpj=%s", $uid, $c ) );
			if ( $n ) { return new WP_Error( 'dv_docs', 'Essa empresa ainda tem ' . $n . ' documento(s). Exclua os documentos antes.', array( 'status' => 400 ) ); }
			dv_docs_emps_salvar( $uid, array_filter( $e, function ( $x ) use ( $c ) { return $x['cnpj'] !== $c; } ) ); return array( 'ok' => true );
		}
		if ( strlen( $c ) !== 14 ) { return new WP_Error( 'dv_docs', 'Informe um CNPJ com 14 números.', array( 'status' => 400 ) ); }
		foreach ( $e as $x ) { if ( $x['cnpj'] === $c ) { return new WP_Error( 'dv_docs', 'Essa empresa já está cadastrada.', array( 'status' => 409 ) ); } }
		if ( count( $e ) >= DV_DOCS_MAX_EMP ) { return new WP_Error( 'dv_docs', 'Limite de ' . DV_DOCS_MAX_EMP . ' empresas.', array( 'status' => 400 ) ); }
		$nome = sanitize_text_field( (string) $r['nome'] );
		if ( ! $nome ) { $b = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $c, array( 'timeout' => 15 ) ); $j = is_wp_error( $b ) ? null : json_decode( wp_remote_retrieve_body( $b ), true ); $nome = $j['razao_social'] ?? ''; }
		$e[] = array( 'cnpj' => $c, 'nome' => mb_substr( $nome ?: 'Empresa ' . $c, 0, 150 ) ); dv_docs_emps_salvar( $uid, $e );
		return array( 'ok' => true, 'empresas' => $e );
	} ) );

	register_rest_route( $ns, '/docs/radar', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); $ok = false;
		foreach ( dv_docs_emps( get_current_user_id() ) as $x ) { if ( $x['cnpj'] === $c ) { $ok = true; } }
		return $ok ? dv_docs_radar( $c ) : new WP_Error( 'dv_docs', 'Empresa não cadastrada.', array( 'status' => 404 ) );
	} ) );

	/* Envio (multipart) ou edição (JSON com id) */
	register_rest_route( $ns, '/docs/salvar', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $uid = get_current_user_id(); $T = dv_docs_tipos(); $tb = $wpdb->prefix . 'dv_docs';
		$id = (int) $r['id']; $c = preg_replace( '/\D/', '', (string) $r['cnpj'] ); $tipo = sanitize_key( (string) $r['tipo'] );
		if ( ! isset( $T[ $tipo ] ) ) { $tipo = 'outro'; }
		$okemp = false; foreach ( dv_docs_emps( $uid ) as $x ) { if ( $x['cnpj'] === $c ) { $okemp = true; } }
		if ( ! $okemp ) { return new WP_Error( 'dv_docs', 'Cadastre a empresa antes de enviar documentos.', array( 'status' => 400 ) ); }
		$dt = function ( $v ) { $v = (string) $v; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null; };
		$row = array( 'cnpj' => $c, 'tipo' => $tipo, 'titulo' => mb_substr( sanitize_text_field( (string) $r['titulo'] ) ?: $T[ $tipo ][0], 0, 190 ),
			'emissao' => $dt( $r['emissao'] ), 'validade' => $dt( $r['validade'] ), 'resultado' => sanitize_key( (string) $r['resultado'] ), 'obs' => mb_substr( sanitize_text_field( (string) $r['obs'] ), 0, 500 ) );
		if ( ! $row['validade'] && $T[ $tipo ][2] && $row['emissao'] ) { $row['validade'] = gmdate( 'Y-m-d', strtotime( $row['emissao'] . ' +' . $T[ $tipo ][2] . ' days' ) ); }
		if ( $id ) {
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tb WHERE id=%d AND user_id=%d", $id, $uid ) );
			if ( ! $old ) { return new WP_Error( 'dv_docs', 'Documento não encontrado.', array( 'status' => 404 ) ); }
			$row['avisado'] = ''; $wpdb->update( $tb, $row, array( 'id' => $id, 'user_id' => $uid ) );
		} else {
			$row += array( 'user_id' => $uid, 'criado' => current_time( 'mysql' ) ); $wpdb->insert( $tb, $row ); $id = (int) $wpdb->insert_id;
		}
		return array( 'ok' => true, 'id' => $id );
	} ) );

	register_rest_route( $ns, '/docs/excluir', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		global $wpdb; $uid = get_current_user_id(); $tb = $wpdb->prefix . 'dv_docs';
		$d = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tb WHERE id=%d AND user_id=%d", (int) $r['id'], $uid ) );
		if ( ! $d ) { return new WP_Error( 'dv_docs', 'Documento não encontrado.', array( 'status' => 404 ) ); }
		$wpdb->delete( $tb, array( 'id' => (int) $r['id'], 'user_id' => $uid ) );
		return array( 'ok' => true );
	} ) );

} );

/* Alertas diários por e-mail: 30, 15, 7, 3 e 1 dia antes, no dia e no dia seguinte ao vencimento */
add_action( 'dv_docs_diario', function () {
	global $wpdb; $tb = $wpdb->prefix . 'dv_docs'; $hoje = current_time( 'Y-m-d' );
	$ate = gmdate( 'Y-m-d', strtotime( $hoje . ' +30 days' ) ); $de = gmdate( 'Y-m-d', strtotime( $hoje . ' -1 day' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tb WHERE validade BETWEEN %s AND %s ORDER BY user_id, validade", $de, $ate ), ARRAY_A );
	$por = array();
	foreach ( $rows as $d ) {
		$dias = (int) floor( ( strtotime( $d['validade'] ) - strtotime( $hoje ) ) / 86400 );
		if ( ! in_array( $dias, array( 30, 15, 7, 3, 1, 0, -1 ), true ) ) { continue; }
		$marca = $d['validade'] . ':' . $dias; if ( strpos( $d['avisado'], $marca ) !== false ) { continue; }
		$d['dias'] = $dias; $por[ $d['user_id'] ][] = $d;
		$wpdb->update( $tb, array( 'avisado' => mb_substr( $marca . '|' . $d['avisado'], 0, 40 ) ), array( 'id' => $d['id'] ) );
	}
	foreach ( $por as $uid => $ds ) { if ( function_exists( 'dv_tools_perm' ) ) { dv_docs_email( (int) $uid, $ds ); } }
} );

function dv_docs_email( $uid, $ds ) {
	$u = get_userdata( $uid ); if ( ! $u ) { return; }
	$emps = array(); foreach ( dv_docs_emps( $uid ) as $x ) { $emps[ $x['cnpj'] ] = $x['nome']; }
	$T = dv_docs_tipos(); $link = home_url( '/plataforma/documentos/' );
	$h = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#16191E"><div style="background:#0C0F13;padding:18px 24px;border-radius:12px 12px 0 0"><span style="color:#F4F2EC;font-size:20px">desenvolve </span><b style="color:#C9A45C;font-size:20px">estratégias</b><div style="color:#B9BDB8;font-size:13px;margin-top:4px">Documentos de habilitação · vencimentos</div></div><div style="border:1px solid #E3DED2;border-top:0;padding:22px 24px;border-radius:0 0 12px 12px"><p>Olá, ' . esc_html( $u->display_name ) . '.</p><p>Estes documentos precisam de atenção:</p>';
	foreach ( $ds as $d ) {
		$q = $d['dias'] < 0 ? '<b style="color:#B04A3A">venceu ontem</b>' : ( $d['dias'] === 0 ? '<b style="color:#B04A3A">vence hoje</b>' : 'vence em <b>' . $d['dias'] . ' dia(s)</b>' );
		$em = isset( $T[ $d['tipo'] ][3] ) && $T[ $d['tipo'] ][3] ? ' · <a href="' . esc_url( $T[ $d['tipo'] ][3] ) . '" style="color:#8A6A2C;font-weight:bold">emitir nova</a>' : '';
		$h .= '<div style="border-left:4px solid ' . ( $d['dias'] <= 3 ? '#C8604E' : '#C9A45C' ) . ';background:#FAF8F3;padding:12px 14px;margin:10px 0;border-radius:8px"><b>' . esc_html( $d['titulo'] ) . '</b><br><span style="font-size:13px;color:#6E6A60">' . esc_html( $emps[ $d['cnpj'] ] ?? $d['cnpj'] ) . ' · válido até ' . esc_html( date_i18n( 'd/m/Y', strtotime( $d['validade'] ) ) ) . ' · ' . $q . $em . '</span></div>';
	}
	$h .= '<p style="margin-top:20px"><a href="' . esc_url( $link ) . '" style="background:#C9A45C;color:#241A06;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Abrir meus documentos</a></p><p style="font-size:12px;color:#6E6A60;margin-top:22px">Depois de emitir a nova certidão, envie o PDF na plataforma: a validade é lida automaticamente e os avisos recomeçam do zero. Os e-mails de aviso são os mesmos do Radar do Diário Oficial.</p></div></div>';
	$rem = function_exists( 'dv_cfg' ) && dv_cfg( 'email_remetente' ) ? dv_cfg( 'email_remetente' ) : 'contato@desenvolveestrategias.com.br';
	$hd = array( 'Content-Type: text/html; charset=UTF-8', 'From: Desenvolve Estratégias <' . $rem . '>', 'Reply-To: ' . $rem );
	$p = function_exists( 'dv_capt_pref' ) ? dv_capt_pref( $uid ) : array( 'emails' => array( $u->user_email ) );
	$urg = min( array_column( $ds, 'dias' ) );
	$ass = ( $urg <= 0 ? 'URGENTE: ' : '' ) . count( $ds ) . ' documento(s) vencendo · Desenvolve';
	foreach ( array_slice( (array) $p['emails'], 0, 5 ) as $em ) { if ( is_email( $em ) ) { wp_mail( $em, $ass, $h, $hd ); } }
}

add_shortcode( 'dv_documentos', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="dc" id="dv-docs" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<div class="dc-emps"></div>
	<div class="dc-body"><p class="dc-h">Carregando…</p></div>
	<dialog class="dc-dlg"><form method="dialog" class="dc-f">
		<h3 class="dc-ft">Cadastrar documento</h3>
		<label class="dc-drop">Opcional: arraste o PDF da certidão ou <u>escolha o arquivo</u> para preencher os dados sozinho<input type="file" accept=".pdf"><span class="dc-fn"></span><small style="display:block;color:#6E6A60;margin-top:6px">O arquivo não é enviado nem guardado: a leitura acontece só no seu computador. Guarde a certidão na rede da sua empresa.</small></label>
		<p class="dc-lido dc-h"></p>
		<label>Tipo de documento<select name="tipo"></select></label>
		<label><span class="dc-tl">Descrição</span><input name="titulo" maxlength="190"></label>
		<div class="dc-2"><label>Emissão<input type="date" name="emissao"></label><label>Válido até<input type="date" name="validade"></label></div>
		<label>Resultado<select name="resultado"><option value="">—</option><option value="negativa">Negativa / regular</option><option value="pcen">Positiva com efeitos de negativa</option><option value="positiva">Positiva (irregular)</option></select></label>
		<label>Observação<input name="obs" maxlength="500" placeholder="Ex.: emitida para o pregão 12/2026"></label>
		<p class="dc-fm dc-h"></p>
		<div class="dc-act"><button value="cancel" class="g" formnovalidate>Cancelar</button><button type="button" class="dc-ok">Salvar</button></div>
	</form></dialog>
</div>
<style>
.dc{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;--rd:#B04A3A;--gr:#2F7A55;--am:#9A6B12;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.dc button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.dc button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:8px 14px}
.dc input,.dc select{border:1px solid #CFC8B8;border-radius:10px;padding:10px 12px;font:inherit;background:#fff;width:100%;box-sizing:border-box}
.dc-h{color:var(--mu);font-size:.88rem;margin:6px 0 0}
.dc-emps{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:18px}.dc-emps .e{border:1px solid var(--ln);border-radius:999px;padding:8px 14px;background:#fff;cursor:pointer;font-weight:600;font-size:.9rem}.dc-emps .e.on{background:#0C0F13;color:#F4F2EC;border-color:#0C0F13}
.dc-add{display:flex;gap:8px}.dc-add input{width:230px}
.dc-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:6px 0 18px}.dc-kpi div{border:1px solid var(--ln);border-radius:14px;padding:14px;background:#fff}.dc-kpi b{display:block;font-size:1.6rem}.dc-kpi span{color:var(--mu);font-size:.82rem;font-weight:600}
.dc-bar{height:10px;border-radius:99px;background:#EEE9DE;overflow:hidden;margin-top:8px}.dc-bar i{display:block;height:100%;background:linear-gradient(90deg,#C9A45C,#2F7A55)}
.dc-rad{border:1px solid #E7B3A8;background:#FDECE8;border-radius:14px;padding:12px 16px;margin-bottom:16px}.dc-rad b{color:var(--rd)}.dc-rad li{margin:6px 0;font-size:.92rem}
.dc-rad.ok{border-color:#BFDCCB;background:#EEF7F1}.dc-rad.ok b{color:var(--gr)}
.dc-top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:8px 0 10px}
.dc-g{margin:16px 0 6px;font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--gd);font-weight:700}
.dc-r{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;border:1px solid var(--ln);border-radius:12px;padding:10px 14px;background:#fff;margin-bottom:6px}
.dc-r.falta{background:#FBFAF6;border-style:dashed}.dc-r .t{font-weight:600;font-size:.94rem}.dc-r .m{color:var(--mu);font-size:.8rem;margin-top:2px}
.dc-r .a{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.dc-r .a a,.dc-r .a button{font-size:.8rem;padding:6px 10px;border-radius:8px;text-decoration:none;font-weight:700}
.dc-r .a a{color:#8A6A2C;border:1px solid var(--ln);background:#fff}
.st{display:inline-block;font-size:.72rem;font-weight:700;border-radius:99px;padding:2px 8px;margin-left:6px;vertical-align:middle}
.st.ok{background:#EEF7F1;color:var(--gr)}.st.vencendo{background:#FFF4DC;color:var(--am)}.st.vencido,.st.positiva{background:#FDECE8;color:var(--rd)}.st.falta{background:#EEE9DE;color:var(--mu)}.st.sem_validade{background:#EEF1F6;color:#3B5578}.st.pcen{background:#FFF4DC;color:var(--am)}
.dc-dlg{border:0;border-radius:16px;padding:0;max-width:520px;width:calc(100% - 32px)}.dc-dlg::backdrop{background:rgba(12,15,19,.55)}
.dc-f{padding:22px;display:grid;gap:10px}.dc-f h3{margin:0}.dc-f label{font-weight:600;font-size:.88rem;display:grid;gap:4px}.dc-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.dc-drop{border:2px dashed #CFC8B8;border-radius:12px;padding:18px;text-align:center;cursor:pointer;font-weight:500!important}.dc-drop input{display:none}.dc-drop.on{border-color:#C9A45C;background:#FAF8F3}.dc-fn{display:block;color:#16191E;font-weight:700;margin-top:6px}
.dc-lido{background:#EEF7F1;border-radius:8px;padding:8px 10px;color:var(--gr)!important;display:none}.dc-lido.on{display:block}.dc-lido.w{background:#FFF4DC;color:var(--am)!important}
.dc-act{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}.dc-fm.e{color:var(--rd)}
.dc-src{color:var(--mu);font-size:.8rem;margin-top:22px}
@media(max-width:700px){.dc{padding:16px}.dc-r{grid-template-columns:1fr}.dc-r .a{grid-column:1/-1;justify-content:flex-start}.dc-add input{width:100%}.dc-add{width:100%}}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function(){var R=document.getElementById('dv-docs');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,S=null,emp='',radar={},sel={},dlg=R.querySelector('.dc-dlg'),F=R.querySelector('.dc-f'),edit=0;
if(window.pdfjsLib)pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function br(d){return d?d.slice(0,10).split('-').reverse().join('/'):''}
function fc(c){return c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,'$1.$2.$3/$4-$5')}
function req(m,p,b,form){var o={method:m,credentials:'same-origin',headers:{'X-WP-Nonce':nonce}};if(form)o.body=b;else if(b){o.headers['Content-Type']='application/json';o.body=JSON.stringify(b)}return fetch(api+p,o).then(function(r){return r.json().then(function(j){if(r.status>=400)throw j;return j})})}
function tipo(k){for(var i=0;i<S.tipos.length;i++)if(S.tipos[i].k===k)return S.tipos[i];return S.tipos[S.tipos.length-1]}
var SN={ok:'Em dia',vencendo:'Vence logo',vencido:'Vencido',sem_validade:'Sem validade',falta:'Falta'};
function carregar(){return req('GET','docs').then(function(j){S=j;if(!emp&&j.empresas.length)emp=j.empresas[0].cnpj;if(emp&&!j.empresas.some(function(x){return x.cnpj===emp}))emp=j.empresas.length?j.empresas[0].cnpj:'';pintar();if(emp&&!radar[emp])buscarRadar(emp)})}
function buscarRadar(c){radar[c]={carregando:1};req('GET','docs/radar?cnpj='+c).then(function(j){radar[c]=j;if(emp===c)pintar()}).catch(function(){radar[c]={erro:1};if(emp===c)pintar()})}
function pintar(){
 var E=R.querySelector('.dc-emps');E.innerHTML=S.empresas.map(function(x){return'<span class="e'+(x.cnpj===emp?' on':'')+'" data-c="'+x.cnpj+'">'+e(x.nome.split(' ').slice(0,3).join(' ').replace(/[\s.,-]+$/,''))+'</span>'}).join('')+'<form class="dc-add"><input placeholder="CNPJ de outra empresa" inputmode="numeric"><button>Adicionar</button></form>';
 var B=R.querySelector('.dc-body');
 if(!S.empresas.length){B.innerHTML='<div class="dc-rad ok"><b>Comece pela empresa.</b><p class="dc-h">Informe o CNPJ acima. Montamos o checklist de habilitação da Lei 14.133/2021, e você cadastra cada certidão com a validade (ou arrasta o PDF e os dados são lidos sozinhos, sem o arquivo sair do seu computador). Avisamos por e-mail antes de cada vencimento.</p></div>';return}
 var ds=S.docs.filter(function(d){return d.cnpj===emp}),por={};ds.forEach(function(d){(por[d.tipo]=por[d.tipo]||[]).push(d)});
 var obrig=S.tipos.filter(function(t){return t.obrig}),okc=0,venc=0,vencendo=0,falta=0;
 obrig.forEach(function(t){var l=por[t.k]||[];var bom=l.some(function(d){return d.situacao==='ok'||d.situacao==='sem_validade'||d.situacao==='vencendo'});if(bom)okc++;else if(l.length)venc++;else falta++});
 ds.forEach(function(d){if(d.situacao==='vencendo')vencendo++});
 var pct=Math.round(okc*100/obrig.length),H='';
 H+='<div class="dc-kpi"><div><b>'+pct+'%</b><span>Pronta para habilitar</span><div class="dc-bar"><i style="width:'+pct+'%"></i></div></div><div><b style="color:var(--rd)">'+venc+'</b><span>Obrigatórios vencidos</span></div><div><b style="color:var(--am)">'+vencendo+'</b><span>Vencem em até 30 dias</span></div><div><b style="color:var(--mu)">'+falta+'</b><span>Obrigatórios faltando</span></div><div><b>'+ds.length+'</b><span>Documentos cadastrados</span></div></div>';
 var rd=radar[emp]||{};
 if(rd.carregando)H+='<div class="dc-rad ok"><b>Radar de habilitação</b><p class="dc-h">Verificando dívida ativa, sanções e situação do CNPJ…</p></div>';
 else if(rd.alertas&&rd.alertas.length)H+='<div class="dc-rad"><b>Radar de habilitação: '+rd.alertas.length+' ponto(s) de atenção antes de emitir as certidões</b><ul>'+rd.alertas.map(function(a){return'<li>'+e(a.txt)+'</li>'}).join('')+'</ul><p class="dc-h">Fontes: PGFN (dívida ativa), CGU (CEIS e CNEP), TCU (inidôneos) e Receita Federal. Precisa regularizar? Acione um consultor da Desenvolve.</p></div>';
 else if(rd.alertas)H+='<div class="dc-rad ok"><b>Radar de habilitação: nada impeditivo encontrado</b><p class="dc-h">Sem dívida ativa da União, sem sanções no CEIS, CNEP e TCU e CNPJ ativo na Receita. Fontes oficiais, atualizadas periodicamente.</p></div>';
 H+='<div class="dc-top"><button class="dc-novo">+ Cadastrar documento</button><button class="g dc-outro">+ Outro documento que quero controlar</button></div><p class="dc-h" style="margin:-4px 0 8px">Alvará, licença, AVCB, registro em conselho, certificado digital, apólice, atestado de vistoria, contrato de aluguel, o que quiser: dê o nome e a validade e receba o aviso antes de vencer.</p>';
 var grupos=[];S.tipos.forEach(function(t){if(grupos.indexOf(t.grupo)<0)grupos.push(t.grupo)});
 grupos.forEach(function(g){var linhas='';
  S.tipos.filter(function(t){return t.grupo===g}).forEach(function(t){var l=por[t.k]||[];
   l.forEach(function(d){var st=d.situacao,q=d.dias===null?'':d.dias<0?'venceu há '+(-d.dias)+' dia(s)':d.dias===0?'vence hoje':'faltam '+d.dias+' dia(s)';
    linhas+='<div class="dc-r"><div><div class="t">'+e(d.titulo)+'<span class="st '+st+'">'+SN[st]+'</span>'+(d.resultado==='positiva'?'<span class="st positiva">Positiva</span>':d.resultado==='pcen'?'<span class="st pcen">Positiva c/ efeito de negativa</span>':'')+'</div><div class="m">'+(d.emissao?'Emitido em '+br(d.emissao)+' · ':'')+(d.validade?'válido até '+br(d.validade)+' · '+q:'sem data de validade')+(d.obs?' · '+e(d.obs):'')+'</div></div><div class="a">'+(t.link&&(st==='vencido'||st==='vencendo')?'<a href="'+e(t.link)+'" target="_blank" rel="noopener">Emitir nova</a>':'')+'<button class="g" data-ed="'+d.id+'">Editar</button><button class="g" data-x="'+d.id+'">Excluir</button></div></div>'});
   if(!l.length&&t.obrig)linhas+='<div class="dc-r falta"><div><div class="t">'+e(t.nome)+'<span class="st falta">Falta</span></div><div class="m">'+(t.dias?'Validade usual: '+t.dias+' dias. ':'')+'Obrigatório na maioria dos editais.</div></div><div class="a">'+(t.link?'<a href="'+e(t.link)+'" target="_blank" rel="noopener">Emitir</a>':'')+'<button class="g" data-n="'+t.k+'">Cadastrar</button></div></div>'});
  if(linhas)H+='<div class="dc-g">'+e(g)+'</div>'+linhas});
 H+='<p class="dc-src">A Desenvolve não guarda os seus documentos: aqui ficam só os dados para o alerta (tipo, emissão e validade). Guarde as certidões na rede da sua empresa. Confira sempre o que o edital exige: prazos de validade podem variar por órgão (art. 70 da Lei 14.133/2021 permite substituir por registro cadastral). Avisos por e-mail 30, 15, 7, 3 e 1 dia antes do vencimento, para os mesmos e-mails do Radar do Diário Oficial. <a href="#" class="dc-rmv" style="color:var(--rd)">Remover esta empresa</a></p>';
 B.innerHTML=H}
function rotulo(){var o=F.querySelector('[name=tipo]').value==='outro';F.querySelector('.dc-tl').textContent=o?'Nome do documento (obrigatório)':'Descrição'}
function abrir(d,tk){edit=d?d.id:0;F.reset();F.querySelector('.dc-fn').textContent='';var L=F.querySelector('.dc-lido');L.className='dc-lido dc-h';F.querySelector('.dc-fm').textContent='';
 F.querySelector('.dc-ft').textContent=d?'Editar documento':'Cadastrar documento';
 var sl=F.querySelector('[name=tipo]'),g='',o='';S.tipos.forEach(function(t){if(t.grupo!==g){if(g)o+='</optgroup>';g=t.grupo;o+='<optgroup label="'+e(g)+'">'}o+='<option value="'+t.k+'">'+e(t.nome)+'</option>'});sl.innerHTML=o+'</optgroup>';
 if(d){['tipo','titulo','emissao','validade','resultado','obs'].forEach(function(k){F.querySelector('[name='+k+']').value=d[k]||''})}else if(tk){sl.value=tk;F.querySelector('[name=titulo]').value=tipo(tk).nome}
 rotulo();dlg.showModal()}
F.querySelector('[name=tipo]').addEventListener('change',rotulo);
/* Leitura automática do PDF */
var REG=[[/negativa de d[eé]bitos trabalhistas|CNDT/i,'cndt'],[/regularidade do FGTS|CRF\b|Certificado de Regularidade/i,'crf_fgts'],[/tributos federais|d[ií]vida ativa da uni[aã]o|Receita Federal do Brasil/i,'cnd_federal'],[/fal[eê]ncia|recupera[cç][aã]o judicial|concordata/i,'falencia'],[/fazenda (do estado|estadual)|d[eé]bitos (tribut[aá]rios )?(estaduais|inscritos.*estado)|ICMS/i,'cnd_estadual'],[/(prefeitura|munic[ií]pio).*(certid[aã]o|d[eé]bitos)|tributos mobili[aá]rios|ISS\b/i,'cnd_municipal'],[/comprovante de inscri[cç][aã]o e de situa[cç][aã]o cadastral/i,'cartao_cnpj'],[/SICAF|Sistema de Cadastramento Unificado/i,'sicaf'],[/acervo t[eé]cnico/i,'cat'],[/atestado de capacidade t[eé]cnica|atestamos/i,'atestado'],[/balan[cç]o patrimonial/i,'balanco'],[/alvar[aá]/i,'alvara'],[/ap[oó]lice|seguro garantia/i,'garantia'],[/contrato social|altera[cç][aã]o contratual/i,'contrato_social']];
function dataBR(s){var m=s.match(/(\d{2})\/(\d{2})\/(\d{4})/);return m?m[3]+'-'+m[2]+'-'+m[1]:''}
function ler(file){var L=F.querySelector('.dc-lido');F.querySelector('.dc-fn').textContent=file.name;
 if(!/\.pdf$/i.test(file.name)||!window.pdfjsLib){L.className='dc-lido dc-h';return}
 L.className='dc-lido dc-h on';L.textContent='Lendo o documento…';
 file.arrayBuffer().then(function(buf){return pdfjsLib.getDocument({data:buf}).promise}).then(function(pdf){var ps=[];for(var i=1;i<=Math.min(pdf.numPages,3);i++)ps.push(pdf.getPage(i).then(function(p){return p.getTextContent()}).then(function(c){return c.items.map(function(x){return x.str}).join(' ')}));return Promise.all(ps)}).then(function(tx){
  var t=tx.join(' ').replace(/\s+/g,' '),achou=[],tk='';
  for(var i=0;i<REG.length;i++)if(REG[i][0].test(t)){tk=REG[i][1];break}
  if(tk){F.querySelector('[name=tipo]').value=tk;if(!F.querySelector('[name=titulo]').value)F.querySelector('[name=titulo]').value=tipo(tk).nome;achou.push(tipo(tk).nome)}
  var v=t.match(/(?:v[aá]lid[ao]\s*(?:at[eé]|por)?|validade\s*(?::|at[eé])?|vencimento\s*:?)\s*(?:de\s*\d{2}\/\d{2}\/\d{4}\s*a\s*)?(\d{2}\/\d{2}\/\d{4})/i)||t.match(/Validade:?\s*\d{2}\/\d{2}\/\d{4}\s*a\s*(\d{2}\/\d{2}\/\d{4})/i);
  if(v){F.querySelector('[name=validade]').value=dataBR(v[1]);achou.push('validade '+v[1])}
  var em=t.match(/(?:emitid[ao]|expedid[ao]|expedi[cç][aã]o|emiss[aã]o|gerad[ao]|data da consulta)[^0-9]{0,40}(\d{2}\/\d{2}\/\d{4})/i);
  if(em){F.querySelector('[name=emissao]').value=dataBR(em[1]);achou.push('emissão '+em[1])}
  if(!v&&em&&tk&&tipo(tk).dias){var d=new Date(dataBR(em[1])+'T12:00:00');d.setDate(d.getDate()+tipo(tk).dias);F.querySelector('[name=validade]').value=d.toISOString().slice(0,10);achou.push('validade estimada em '+tipo(tk).dias+' dias')}
  var res='';if(/positiva com efeitos? de negativa/i.test(t))res='pcen';else if(/certid[aã]o positiva/i.test(t))res='positiva';else if(/negativa|regular|nada consta|n[aã]o constam?/i.test(t))res='negativa';
  if(res){F.querySelector('[name=resultado]').value=res;achou.push(res==='pcen'?'positiva com efeitos de negativa':res)}
  var cn=(t.match(/\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}/g)||[]).map(function(x){return x.replace(/\D/g,'')}),aviso='';
  if(cn.length&&cn.indexOf(emp)<0){var raiz=cn.some(function(x){return x.slice(0,8)===emp.slice(0,8)});aviso=raiz?' Atenção: o CNPJ do documento é de uma filial ('+fc(cn[0])+'), não da empresa selecionada.':' Atenção: o CNPJ do documento ('+fc(cn[0])+') é diferente da empresa selecionada.'}
  L.className='dc-lido dc-h on'+(aviso||res==='positiva'?' w':'');L.textContent=(achou.length?'Lido automaticamente: '+achou.join(' · ')+'. Confira antes de salvar.':'Não conseguimos ler os dados deste PDF (pode ser uma imagem). Preencha as datas.')+(res==='positiva'?' A certidão é POSITIVA: ela não comprova regularidade.':'')+aviso
 }).catch(function(){L.textContent='Não conseguimos ler este PDF. Preencha as datas.'})}
var inp=F.querySelector('[type=file]'),drop=F.querySelector('.dc-drop');
inp.addEventListener('change',function(){if(inp.files[0])ler(inp.files[0])});
['dragover','dragenter'].forEach(function(ev){drop.addEventListener(ev,function(x){x.preventDefault();drop.classList.add('on')})});
['dragleave','drop'].forEach(function(ev){drop.addEventListener(ev,function(x){x.preventDefault();drop.classList.remove('on')})});
drop.addEventListener('drop',function(x){if(x.dataTransfer.files[0]){inp.files=x.dataTransfer.files;ler(x.dataTransfer.files[0])}});
F.querySelector('.dc-ok').addEventListener('click',function(){var o={},m=F.querySelector('.dc-fm');['tipo','titulo','emissao','validade','resultado','obs'].forEach(function(k){o[k]=F.querySelector('[name='+k+']').value});o.cnpj=emp;if(edit)o.id=edit;if(o.tipo==='outro'&&!o.titulo.trim()){m.className='dc-fm dc-h e';m.textContent='Dê um nome ao documento.';return}if(o.tipo==='outro'&&!o.validade){m.className='dc-fm dc-h e';m.textContent='Informe a validade para receber o aviso.';return}if(!o.validade&&!o.emissao&&tipo(o.tipo).dias){m.className='dc-fm dc-h e';m.textContent='Informe a emissão ou a validade para receber o alerta.';return}
 m.className='dc-fm dc-h';m.textContent='Salvando…';req('POST','docs/salvar',o).then(function(){dlg.close();carregar()}).catch(function(j){m.className='dc-fm dc-h e';m.textContent=(j&&j.message)||'Não foi possível salvar.'})});
R.addEventListener('click',function(x){var t=x.target,d;
 if(t.matches('.e')){emp=t.dataset.c;sel={};pintar();if(!radar[emp])buscarRadar(emp);return}
 if(t.matches('.dc-novo')){abrir();return}
 if(t.matches('.dc-outro')){abrir(null,'outro');F.querySelector('[name=titulo]').value='';F.querySelector('[name=titulo]').placeholder='Ex.: Licença da vigilância sanitária — filial Campinas';F.querySelector('[name=titulo]').focus();return}
 if(t.dataset.n){abrir(null,t.dataset.n);return}
 if(t.dataset.ed){d=S.docs.filter(function(y){return y.id==t.dataset.ed})[0];abrir(d);return}
 if(t.dataset.x){if(confirm('Excluir este cadastro?'))req('POST','docs/excluir',{id:+t.dataset.x}).then(carregar);return}
 if(t.matches('.dc-rmv')){x.preventDefault();if(confirm('Remover esta empresa da lista?'))req('POST','docs/empresa',{cnpj:emp,remover:1}).then(function(){emp='';carregar()}).catch(function(j){alert(j.message)});return}});
R.addEventListener('submit',function(x){if(!x.target.matches('.dc-add'))return;x.preventDefault();var i=x.target.querySelector('input'),c=i.value.replace(/\D/g,'');var b=x.target.querySelector('button');b.disabled=true;
 req('POST','docs/empresa',{cnpj:c}).then(function(){emp=c;return carregar()}).catch(function(j){alert((j&&j.message)||'Erro')}).then(function(){b.disabled=false})});
carregar();
})();
</script>
	<?php return ob_get_clean();
} );
