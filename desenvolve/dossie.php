/**
 * Desenvolve — Dossiê completo da empresa
 * Junta num relatório só: cadastro, sócios, grupo econômico (sócios em comum, holdings, contatos
 * e endereço em comum, possíveis parentes), dívida ativa da União e solidários, risco das empresas
 * do grupo, sanções, contratos públicos, publicações em diários oficiais e notícias negativas.
 * Rota: POST desenvolve/v1/dossie {cnpj}  — cobra dv_cfg('creditos_dossie') créditos (padrão 3).
 * Depende de: núcleo (dv_usar, dv_cfg), ferramentas (rotas dvtools/v1, dv_tools_perm), notícias (dv_noticias).
 */

define( 'DV_DADOS', 'https://carlos09091988.github.io/aquila-dados/' );

function dv_ds_gz( $rel ) {
	$r = wp_remote_get( DV_DADOS . $rel, array( 'timeout' => 25 ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return array(); }
	$b = wp_remote_retrieve_body( $r );
	$t = substr( $rel, -3 ) === '.gz' ? @gzdecode( $b ) : $b;
	return $t ? (array) json_decode( $t, true ) : array();
}
function dv_ds_shard( $pasta, $k ) { $o = dv_ds_gz( $pasta . '/' . substr( hash( 'sha256', $k ), 0, 3 ) . '.json.gz' ); $v = $o[ $k ] ?? array(); unset( $o ); return $v; }
function dv_ds_nrm( $s ) { return trim( preg_replace( '/\s+/', ' ', strtoupper( remove_accents( (string) $s ) ) ) ); }
function dv_ds_dv( $b ) { // completa o CNPJ da matriz a partir da raiz
	$b = substr( $b, 0, 8 ) . '0001'; $c = array_map( 'intval', str_split( $b ) ); $w = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
	$s = 0; for ( $i = 0; $i < 12; $i++ ) { $s += $c[ $i ] * $w[ $i ]; } $d1 = $s % 11 < 2 ? 0 : 11 - $s % 11; $c[] = $d1; array_unshift( $w, 6 );
	$s = 0; for ( $i = 0; $i < 13; $i++ ) { $s += $c[ $i ] * $w[ $i ]; } $d2 = $s % 11 < 2 ? 0 : 11 - $s % 11;
	return $b . $d1 . $d2;
}
function dv_ds_pgfn( $cnpj ) {
	$o = dv_ds_gz( 'pgfn/' . substr( $cnpj, 0, 3 ) . '.json.gz' ); $r = $o[ $cnpj ] ?? null; unset( $o );
	if ( ! $r ) { return null; }
	return array( 'total' => (float) $r[0], 'previdenciaria' => (float) $r[1], 'nao_previdenciaria' => (float) $r[2], 'fgts' => (float) $r[3],
		'em_cobranca' => (float) $r[4], 'inscricoes' => (int) $r[5], 'ajuizadas' => (int) $r[6], 'tipos' => $r[7] ?? '',
		'solidarios' => array_map( function ( $x ) { return array( 'doc' => $x[0], 'nome' => $x[1], 'tipo' => $x[2], 'valor' => (float) $x[3], 'inscricoes' => (int) $x[4] ); }, (array) ( $r[8] ?? array() ) ) );
}
function dv_ds_interno( $rota, $params ) {
	$q = new WP_REST_Request( 'GET', $rota ); $q->set_query_params( $params );
	$r = rest_do_request( $q );
	return $r->is_error() ? null : $r->get_data();
}

function dv_dossie_dados( $cnpj ) {
	$ck = 'dv_ds_' . $cnpj; $c = get_transient( $ck ); if ( $c ) { return $c; }
	$t0 = microtime( true );
	$r = wp_remote_get( 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, array( 'timeout' => 25 ) );
	$cad = is_wp_error( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $cad['razao_social'] ) ) { return new WP_Error( 'dv_cnpj', 'CNPJ não encontrado na Receita Federal.', array( 'status' => 404 ) ); }
	$raiz = substr( $cnpj, 0, 8 );
	$D = array( 'cnpj' => $cnpj, 'gerado_em' => current_time( 'mysql' ), 'cadastro' => $cad, 'meta' => dv_ds_gz( 'meta.json' ) ?: null );

	/* Grupo econômico */
	$emp = array();
	$add = function ( $basico, $razao, $via, $detalhe = '' ) use ( &$emp, $raiz ) {
		if ( ! $basico || $basico === $raiz ) { return; }
		if ( ! isset( $emp[ $basico ] ) ) { if ( count( $emp ) >= 60 ) { return; } $emp[ $basico ] = array( 'cnpj' => dv_ds_dv( $basico ), 'razao' => $razao ?: 'CNPJ raiz ' . $basico, 'vias' => array() ); }
		$v = $via . ( $detalhe ? ': ' . $detalhe : '' );
		if ( count( $emp[ $basico ]['vias'] ) < 5 && ! in_array( $v, $emp[ $basico ]['vias'], true ) ) { $emp[ $basico ]['vias'][] = $v; }
	};
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) {
		$doc = preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) );
		$k = strlen( $doc ) === 14 ? 'PJ|' . $doc : dv_ds_nrm( $s['nome_socio'] ?? '' ) . '|' . $doc;
		foreach ( dv_ds_shard( 'socios', $k ) as $e ) { $add( $e[0], $e[1], 'Sócio em comum', ( $s['nome_socio'] ?? '' ) . ( $e[2] ? ' (' . $e[2] . ')' : '' ) ); }
		if ( strlen( $doc ) === 14 ) { $add( substr( $doc, 0, 8 ), $s['nome_socio'] ?? '', 'Sócia pessoa jurídica (holding)' ); }
	}
	foreach ( dv_ds_shard( 'socios', 'PJ|' . $cnpj ) as $e ) { $add( $e[0], $e[1], 'Participação da empresa', $e[2] ?? '' ); }
	$tels = array_filter( array( preg_replace( '/\D/', '', (string) ( $cad['ddd_telefone_1'] ?? '' ) ), preg_replace( '/\D/', '', (string) ( $cad['ddd_telefone_2'] ?? '' ) ) ) );
	foreach ( $tels as $t ) { if ( strlen( $t ) >= 10 ) { foreach ( dv_ds_shard( 'vinc', 'T|' . $t ) as $e ) { $add( $e[0], $e[1], 'Mesmo telefone', $t ); } } }
	if ( ! empty( $cad['email'] ) ) { foreach ( dv_ds_shard( 'vinc', 'E|' . strtolower( trim( $cad['email'] ) ) ) as $e ) { $add( $e[0], $e[1], 'Mesmo e-mail' ); } }
	$cep = preg_replace( '/\D/', '', (string) ( $cad['cep'] ?? '' ) ); $num = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $cad['numero'] ?? '' ) ) );
	$comp = preg_replace( '/[^A-Z0-9]/', '', dv_ds_nrm( $cad['complemento'] ?? '' ) );
	if ( strlen( $cep ) === 8 && $num !== '' ) { foreach ( dv_ds_shard( 'vinc', 'A|' . $cep . '|' . $num . '|' . $comp ) as $e ) { $add( $e[0], $e[1], 'Mesmo endereço' ); } }
	$parentes = array(); $PART = array( 'DE', 'DA', 'DO', 'DOS', 'DAS', 'DI', 'DEL', 'FILHO', 'FILHA', 'JUNIOR', 'NETO', 'NETA', 'SOBRINHO', 'SEGUNDO', 'TERCEIRO' );
	foreach ( (array) ( $cad['qsa'] ?? array() ) as $s ) {
		if ( strlen( preg_replace( '/\D/', '', (string) ( $s['cnpj_cpf_do_socio'] ?? '' ) ) ) === 14 ) { continue; }
		$t = array_values( array_filter( explode( ' ', preg_replace( '/[^A-Z ]/', ' ', dv_ds_nrm( $s['nome_socio'] ?? '' ) ) ), function ( $x ) use ( $PART ) { return strlen( $x ) > 1 && ! in_array( $x, $PART, true ); } ) );
		if ( count( $t ) < 3 ) { continue; }
		$sob = $t[ count( $t ) - 2 ] . ' ' . $t[ count( $t ) - 1 ];
		foreach ( dv_ds_shard( 'vinc', 'S|' . $sob ) as $e ) {
			$nome = explode( '|', (string) ( $e[2] ?? '' ) )[0];
			if ( $nome === dv_ds_nrm( $s['nome_socio'] ) ) { continue; }
			$parentes[] = array( 'socio' => $s['nome_socio'], 'sobrenome' => $sob, 'pessoa' => $nome, 'empresa' => $e[1], 'cnpj' => dv_ds_dv( $e[0] ), 'qualificacao' => $e[3] ?? '' );
			if ( count( $parentes ) >= 40 ) { break 2; }
		}
	}
	/* Risco de cada empresa do grupo (dívida ativa da União) — até 25 */
	foreach ( array_slice( array_keys( $emp ), 0, 25 ) as $b ) { $p = dv_ds_pgfn( $emp[ $b ]['cnpj'] ); $emp[ $b ]['pgfn'] = $p ? $p['total'] : 0; }
	uasort( $emp, function ( $a, $b ) { return count( $b['vias'] ) <=> count( $a['vias'] ) ?: ( $b['pgfn'] ?? 0 ) <=> ( $a['pgfn'] ?? 0 ); } );
	$D['grupo'] = array_values( $emp ); $D['parentes'] = $parentes;

	/* Dívida ativa da União e solidários */
	$D['pgfn'] = dv_ds_pgfn( $cnpj );

	/* Ferramentas já existentes (sanções, contratos, certidões, diários, lista suja) */
	$D['raiox'] = dv_ds_interno( '/dvtools/v1/fornecedor', array( 'cnpj' => $cnpj ) );
	$D['federal'] = dv_ds_interno( '/dvtools/v1/fornecedor/federal', array( 'cnpj' => $cnpj ) );
	$D['extras'] = dv_ds_interno( '/dvtools/v1/fornecedor/extras', array( 'cnpj' => $cnpj ) );
	$D['diarios'] = dv_ds_interno( '/dvtools/v1/fornecedor/diarios', array( 'cnpj' => $cnpj ) );

	/* Notícias negativas */
	$D['noticias'] = function_exists( 'dv_noticias' ) ? dv_noticias( $cnpj ) : null;
	if ( is_wp_error( $D['noticias'] ) ) { $D['noticias'] = null; }

	$D['tempo'] = round( microtime( true ) - $t0, 1 );
	set_transient( $ck, $D, 12 * HOUR_IN_SECONDS );
	return $D;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'desenvolve/v1', '/dossie', array( 'methods' => 'POST',
		'permission_callback' => function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); },
		'callback' => function ( WP_REST_Request $r ) {
			$cnpj = preg_replace( '/\D/', '', (string) $r['cnpj'] );
			if ( strlen( $cnpj ) !== 14 ) { return new WP_Error( 'dv_cnpj', 'Informe um CNPJ com 14 números.', array( 'status' => 400 ) ); }
			$uid = get_current_user_id(); $n = max( 1, (int) ( dv_cfg( 'creditos_dossie' ) ?: 3 ) );
			if ( ! dv_is_master( $uid ) && dv_saldo( $uid )['total'] < $n ) {
				global $wpdb; // se já pagou este dossiê nos últimos 30 dias, deixa reabrir
				$ja = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dv_uso WHERE user_id=%d AND chave=%s AND criado > %s", $uid,
					substr( hash( 'sha256', 'dossie|' . strtolower( 'dossie|' . $cnpj . '|1' ) ), 0, 64 ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
				if ( ! $ja ) { return new WP_Error( 'dv_sem_credito', 'O dossiê completo usa ' . $n . ' créditos e seu saldo não é suficiente.', array( 'status' => 402 ) ); }
			}
			set_time_limit( 120 );
			$D = dv_dossie_dados( $cnpj ); if ( is_wp_error( $D ) ) { return $D; }
			for ( $i = 1; $i <= $n; $i++ ) { $u = dv_usar( $uid, 'dossie', 'dossie|' . $cnpj . '|' . $i, $i === 1 ? ( $D['cadastro']['razao_social'] ?? $cnpj ) : '' ); if ( is_wp_error( $u ) ) { return $u; } }
			$D['saldo'] = dv_saldo( $uid );
			return $D;
		} ) );
} );
