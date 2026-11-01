/**
 * Desenvolve — Banco de teses (jurisprudência do TCU e súmulas do TCE-SP sobre licitações)
 * Base: Jurisprudência Selecionada do TCU (dados abertos), filtrada e publicada no GitHub
 * (aquila-dados/aquila/jur) por uma tarefa mensal. O site guarda uma cópia em uploads e
 * renova a cada 7 dias. Incluso na assinatura (sem custo de crédito).
 */

define( 'DV_TS_URL', 'https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/aquila/jur/' );

function dv_ts_arquivo( $nome ) {
	$up = wp_upload_dir(); $dir = trailingslashit( $up['basedir'] ) . 'dv-teses-v3';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); @file_put_contents( $dir . '/index.html', '' ); }
	$f = $dir . '/' . $nome;
	if ( ! file_exists( $f ) || filemtime( $f ) < time() - 7 * DAY_IN_SECONDS ) {
		$g = wp_remote_get( DV_TS_URL . $nome, array( 'timeout' => 60 ) );
		if ( ! is_wp_error( $g ) && wp_remote_retrieve_response_code( $g ) === 200 && strlen( wp_remote_retrieve_body( $g ) ) > 1000 ) {
			file_put_contents( $f, wp_remote_retrieve_body( $g ) );
		} elseif ( file_exists( $f ) ) { touch( $f, time() - 6 * DAY_IN_SECONDS ); }
	}
	return file_exists( $f ) ? $f : '';
}
function dv_ts_base() {
	static $b = null; if ( $b !== null ) { return $b; }
	$f = dv_ts_arquivo( 'tcu-licitacoes.json' ); $j = $f ? json_decode( file_get_contents( $f ), true ) : array();
	$b = array();
	foreach ( (array) ( $j['selecionada'] ?? array() ) as $x ) {
		if ( ( $x['ar'] ?? '' ) !== 'Licitação' ) { continue; }
		$x['e'] = trim( wp_strip_all_tags( $x['e'] ) );
		if ( $x['e'] === '' ) { continue; }
		$x['_n'] = dv_ts_norm( $x['e'] . ' ' . $x['t'] . ' ' . $x['s'] . ' ' . ( $x['idx'] ?? '' ) );
		$b[] = $x;
	}
	$f2 = dv_ts_arquivo( 'tcesp-sumulas.json' ); $sp = $f2 ? json_decode( file_get_contents( $f2 ), true ) : array();
	foreach ( (array) ( $sp['sumulas'] ?? array() ) as $x ) {
		$b[] = array( 'k' => 'TCESP-S' . $x['n'], 'n' => (string) $x['n'], 'a' => '', 'c' => '', 'ar' => 'Licitação', 't' => 'Súmulas do TCE-SP', 's' => 'Súmula ' . $x['n'],
			'e' => $x['e'], 'nota' => $x['nota'], 'src' => 'tcesp', 'd' => '', 'rel' => '', '_n' => dv_ts_norm( $x['e'] . ' sumula tce-sp tcesp sao paulo' ) );
	}
	$ks = array(); foreach ( $b as $x ) { $ks[ $x['k'] ] = 1; }
	$f3 = dv_ts_arquivo( 'tcu-extras.json' ); $ex = $f3 ? json_decode( file_get_contents( $f3 ), true ) : array();
	foreach ( (array) ( $ex['sumulas'] ?? array() ) as $x ) {
		$b[] = array( 'k' => $x['k'], 'n' => (string) $x['n'], 'a' => '', 'c' => '', 'ar' => 'Licitação', 't' => 'Súmulas do TCU', 's' => $x['t'] . ( $x['s'] ? ' › ' . $x['s'] : '' ), 'e' => $x['e'], 'src' => 'tcusum', 'prio' => 1, 'd' => $x['d'], 'rel' => '', 'apr' => $x['ac'], '_n' => dv_ts_norm( $x['e'] . ' ' . $x['t'] . ' ' . $x['s'] . ' sumula tcu' ) );
	}
	foreach ( (array) ( $ex['consultas'] ?? array() ) as $x ) {
		if ( isset( $ks[ $x['k'] ] ) ) { continue; } $ks[ $x['k'] ] = 1;
		$b[] = array( 'k' => $x['k'], 'n' => $x['n'], 'a' => $x['a'], 'c' => $x['c'], 'ar' => 'Licitação', 't' => $x['t'], 's' => $x['s'], 'e' => $x['e'], 'src' => 'tcucons', 'd' => $x['d'], 'rel' => $x['rel'], '_n' => dv_ts_norm( $x['e'] . ' ' . $x['t'] . ' ' . $x['s'] . ' consulta' ) );
	}
	foreach ( (array) ( $ex['informativo'] ?? array() ) as $x ) {
		if ( isset( $ks[ $x['k'] ] ) ) { continue; } $ks[ $x['k'] ] = 1;
		$b[] = array( 'k' => $x['k'], 'n' => $x['n'], 'a' => $x['a'], 'c' => $x['c'], 'ar' => 'Licitação', 't' => 'Informativo de Licitações e Contratos', 's' => 'Informativo ' . $x['inf'] . '/' . $x['infa'], 'e' => $x['e'], 'src' => 'tcuinfo', 'd' => '', 'rel' => $x['rel'], 'inf' => $x['inf'], '_n' => dv_ts_norm( $x['e'] . ' informativo' ) );
	}
	/* outras fontes já normalizadas (CJF, boletins do TCE-SP, STJ...): rótulo, link e citação prontos */
	$f4 = dv_ts_arquivo( 'outras-fontes.json' ); $of = $f4 ? json_decode( file_get_contents( $f4 ), true ) : array();
	foreach ( (array) ( $of['itens'] ?? array() ) as $x ) {
		if ( empty( $x['e'] ) || isset( $ks[ $x['k'] ] ) ) { continue; } $ks[ $x['k'] ] = 1;
		$x += array( 'n' => '', 'a' => '', 'c' => '', 'd' => '', 'rel' => '', 't' => '', 's' => '' ); $x['ar'] = 'Licitação';
		$x['_n'] = dv_ts_norm( $x['e'] . ' ' . $x['t'] . ' ' . $x['s'] . ' ' . ( $x['lab'] ?? '' ) ); $b[] = $x;
	}
	foreach ( $b as &$x ) { if ( ( $x['src'] ?? '' ) === 'tcesp' ) { $x['prio'] = 1; } } unset( $x );
	usort( $b, function ( $a, $c ) {
		return array( $c['prio'] ?? 0, ! empty( $c['prio'] ) ? -(int) $c['n'] : (int) $c['a'], ! empty( $c['prio'] ) ? 0 : (int) ( $c['inf'] ?? 0 ), (int) $c['n'] )
			<=> array( $a['prio'] ?? 0, ! empty( $a['prio'] ) ? -(int) $a['n'] : (int) $a['a'], ! empty( $a['prio'] ) ? 0 : (int) ( $a['inf'] ?? 0 ), (int) $a['n'] );
	} );
	return $b;
}
function dv_ts_norm( $s ) { return strtolower( remove_accents( (string) $s ) ); }
function dv_ts_sin() {
	return array(
		'planilha' => array( 'planilha', 'composicao de custos', 'formacao de precos' ),
		'inexequivel' => array( 'inexequ' ), 'inexequibilidade' => array( 'inexequ' ),
		'atestado' => array( 'atestad', 'capacidade tecnica', 'qualificacao tecnica' ),
		'repactuacao' => array( 'repactua' ), 'reajuste' => array( 'reajust' ),
		'reequilibrio' => array( 'reequilibrio', 'equilibrio economico' ),
		'multa' => array( 'multa', 'sancao', 'penalidade' ), 'sancao' => array( 'sancao', 'penalidade', 'multa', 'impedimento', 'inidone' ),
		'vistoria' => array( 'vistoria', 'visita tecnica' ), 'me' => array( 'microempresa', 'pequena empresa', 'pequeno porte' ),
		'epp' => array( 'microempresa', 'pequena empresa', 'pequeno porte' ), 'impugnacao' => array( 'impugna' ),
		'recurso' => array( 'recurso', 'recorrer' ), 'garantia' => array( 'garantia' ), 'terceirizacao' => array( 'terceiriza', 'dedicacao exclusiva' ),
		'diligencia' => array( 'diligencia' ), 'aditivo' => array( 'aditivo', 'acrescimo', 'supressao' ), 'prorrogacao' => array( 'prorroga' ),
	);
}
function dv_ts_buscar( $q, $tema = '', $pag = 1, $por = 25, $fonte = '' ) {
	$sin = dv_ts_sin(); $grupos = array();
	foreach ( preg_split( '/\s+/', dv_ts_norm( $q ) ) as $w ) {
		$w = trim( $w, ".,;:!?\"'()" ); if ( strlen( $w ) < 2 || in_array( $w, array( 'de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'em', 'na', 'no', 'por', 'para', 'com', 'que', 'meu', 'minha' ), true ) ) { continue; }
		$base = rtrim( $w, 's' ); $grupos[] = $sin[ $w ] ?? $sin[ $base ] ?? array( strlen( $base ) > 3 ? $base : $w );
	}
	$tema = dv_ts_norm( $tema ); $res = array(); $temas = array();
	foreach ( dv_ts_base() as $x ) {
		if ( $tema !== '' && dv_ts_norm( $x['ar'] . ' > ' . $x['t'] ) !== $tema ) { continue; }
		$sr = $x['src'] ?? '';
		if ( $fonte === 'tcesp' && ! in_array( $sr, array( 'tcesp', 'tcespb' ), true ) ) { continue; } if ( $fonte === 'tcu' && $sr !== '' && strpos( $sr, 'tcu' ) !== 0 ) { continue; }
		if ( $fonte === 'sumulas' && ! in_array( $sr, array( 'tcesp', 'tcusum' ), true ) ) { continue; } if ( $fonte === 'info' && $sr !== 'tcuinfo' ) { continue; }
		if ( in_array( $fonte, array( 'stj', 'cjf', 'agu' ), true ) && $sr !== $fonte ) { continue; } if ( $fonte === 'tcesp' && $sr === 'tcespb' ) { $sr = 'tcesp'; }
		$ok = true; foreach ( $grupos as $g ) { $um = false; foreach ( $g as $t ) { if ( strpos( $x['_n'], $t ) !== false ) { $um = true; break; } } if ( ! $um ) { $ok = false; break; } }
		if ( ! $ok ) { continue; }
		$res[] = $x; $k = $x['ar'] . ' > ' . $x['t']; $temas[ $k ] = ( $temas[ $k ] ?? 0 ) + 1;
	}
	arsort( $temas );
	$itens = array_map( function ( $x ) { unset( $x['_n'], $x['idx'] ); return $x; }, array_slice( $res, ( $pag - 1 ) * $por, $por ) );
	return array( 'total' => count( $res ), 'pag' => $pag, 'por' => $por, 'itens' => $itens, 'temas' => array_slice( $temas, 0, 12, true ) );
}

add_action( 'rest_api_init', function () {
	$perm = function () { return function_exists( 'dv_tools_perm' ) && dv_tools_perm(); };
	$ns = 'desenvolve/v1';
	register_rest_route( $ns, '/teses', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
		$q = sanitize_text_field( (string) $r['q'] ); $tema = sanitize_text_field( (string) $r['tema'] );
		if ( $q === '' && $tema === '' && empty( $r['fonte'] ) ) {
			$f = dv_ts_arquivo( 'situacoes.json' ); $sit = $f ? json_decode( file_get_contents( $f ), true ) : array();
			$sit = array_values( array_filter( (array) $sit, function ( $x ) { return ! in_array( $x['slug'] ?? '', array( 'repactuacao-negada', 'multa-ou-penalidade' ), true ); } ) );
			$temas = array(); foreach ( dv_ts_base() as $x ) { $k = $x['ar'] . ' > ' . $x['t']; $temas[ $k ] = ( $temas[ $k ] ?? 0 ) + 1; } arsort( $temas );
			return array( 'situacoes' => $sit, 'temas' => array_slice( $temas, 0, 40, true ), 'total_base' => count( dv_ts_base() ) );
		}
		return dv_ts_buscar( $q, $tema, max( 1, (int) $r['pag'] ), 25, sanitize_key( (string) $r['fonte'] ) );
	} ) );
} );

add_shortcode( 'dv_teses', function () {
	if ( ! is_user_logged_in() ) { return ''; }
	ob_start(); ?>
<div class="ts" id="dv-ts" data-api="<?php echo esc_attr( rest_url( 'desenvolve/v1/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<form class="ts-b" autocomplete="off"><input name="q" type="search" placeholder="Digite o tema da sua tese. Ex.: erro na planilha, atestado somatório, inexequível, vistoria, índice contábil"><button>Buscar</button></form>
	<div class="ts-sel" hidden></div>
	<div class="ts-r"><p class="ts-h">Carregando…</p></div>
	<p class="ts-h ts-av">Fontes: Jurisprudência Selecionada do Tribunal de Contas da União (dados abertos), atualizada mensalmente, e repertório de súmulas do Tribunal de Contas do Estado de São Paulo (Resolução nº 05/2019). Os enunciados retratam o entendimento de cada decisão e não substituem a leitura do acórdão. Conteúdo informativo, que não constitui parecer jurídico.</p>
</div>
<style>
.ts{--ln:#E3DED2;--mu:#6E6A60;--gd:#A8843F;color:#16191E;font-family:"Hanken Grotesk",-apple-system,sans-serif;padding:28px}
.ts button{background:#C9A45C;color:#241A06;border:0;border-radius:10px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.ts button.g{background:#fff;border:1px solid var(--ln);color:#16191E;font-weight:600;padding:7px 12px;font-size:.85rem}
.ts-b{display:flex;gap:8px}.ts-b input{flex:1;border:1px solid #CFC8B8;border-radius:10px;padding:12px 14px;font:inherit;background:#fff}
.ts h3{font-size:1.05rem;margin:24px 0 10px}.ts-h{color:var(--mu);font-size:.86rem}.ts-av{margin-top:28px}
.ts-sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}.ts-s{border:1px solid var(--ln);border-radius:14px;padding:14px 16px;background:#fff;cursor:pointer;text-align:left}.ts-s:hover{border-color:var(--gd)}.ts-s span{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--gd);font-weight:700}.ts-s b{display:block;margin-top:4px;line-height:1.35}
.ts-tm{display:flex;flex-wrap:wrap;gap:6px}.ts-tm span{border:1px solid var(--ln);background:#fff;border-radius:99px;padding:5px 11px;font-size:.8rem;cursor:pointer}.ts-tm span:hover,.ts-tm span.on{border-color:var(--gd);color:#7A5A1C}.ts-tm small{color:var(--mu)}
.ts-i{border:1px solid var(--ln);border-radius:12px;padding:14px 16px;background:#fff;margin-bottom:10px}.ts-i .m{font-size:.8rem;color:var(--mu)}.ts-i .m a{color:#16191E;font-weight:700}.ts-i p{margin:8px 0;line-height:1.55;font-size:.95rem}.ts-i .k{display:flex;gap:6px;flex-wrap:wrap}.ts-i.sel{border-color:var(--gd);background:#FFFBF1}
.ts-cx{background:#F6F3EC;border-radius:14px;padding:18px 20px;margin-top:14px}.ts-cx h4{margin:0 0 6px}.ts-cx ul{margin:6px 0 10px;padding-left:20px}.ts-cx li{margin:4px 0;line-height:1.5}
.ts-sel{position:sticky;top:10px;z-index:5;background:#16191E;color:#F4F2EC;border-radius:12px;padding:10px 14px;margin:14px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.ts-sel[hidden]{display:none}.ts-sel b{margin-right:auto}
.ts-ia{border:1px solid var(--ln);border-radius:14px;background:#fff;padding:16px;margin:10px 0}.ts-ia textarea{width:100%;box-sizing:border-box;min-height:80px;border:1px solid #CFC8B8;border-radius:10px;padding:10px;font:inherit}.ts-ia .ias{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.ts-ia img{width:16px;height:16px;vertical-align:-3px;margin-right:5px}
.ts-tag{display:inline-block;background:#FFF4DC;color:#9A6B12;border-radius:99px;padding:0 7px;font-size:.72rem;font-weight:700}
.ts-pg{display:flex;gap:8px;align-items:center;margin-top:10px}
#dv-ts button.ts-s{background:#fff!important;color:#16191E!important;border:1px solid var(--ln)!important;border-radius:14px!important;padding:14px 16px!important;font:inherit!important;text-transform:none!important;letter-spacing:0!important;box-shadow:none!important;width:100%!important;display:block!important;text-align:left!important;line-height:1.35!important}
#dv-ts button.ts-s:hover{border-color:var(--gd)!important}#dv-ts button.ts-s span{font-size:.72rem!important}#dv-ts button.ts-s b{font-weight:700!important;font-size:1rem!important}
#dv-ts button.g{background:#fff!important;color:#16191E!important;border:1px solid var(--ln)!important;text-transform:none!important;letter-spacing:0!important;box-shadow:none!important}
@media(max-width:700px){.ts{padding:16px}.ts-b{flex-direction:column}}
</style>
<script>
(function(){var R=document.getElementById('dv-ts');if(!R)return;var api=R.dataset.api,nonce=R.dataset.nonce,O=R.querySelector('.ts-r'),SEL={},Q='',TEMA='',PAG=1,BASE=null,FONTE='';
var IAS=[['ChatGPT','https://chatgpt.com/','chatgpt.com'],['Claude','https://claude.ai/new','claude.ai'],['Perplexity','https://www.perplexity.ai/','perplexity.ai'],['Grok','https://grok.com/','grok.com'],['DeepSeek','https://chat.deepseek.com/','deepseek.com']];
var COL={'Plenário':'Plenário','Primeira Câmara':'1ª Câmara','Segunda Câmara':'2ª Câmara'};
try{SEL=JSON.parse(localStorage.getItem('dv_teses_sel')||'{}')}catch(x){}
function e(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function g(p){return fetch(api+p,{credentials:'same-origin',headers:{'X-WP-Nonce':nonce}}).then(function(r){return r.json()})}
function tit(s){return String(s||'').toLowerCase().replace(/(^|\s)\S/g,function(c){return c.toUpperCase()})}
function ac(x){if(x.lab)return x.lab;if(x.src==='tcesp')return'Súmula nº '+x.n+' do TCE-SP';if(x.src==='tcusum')return'Súmula nº '+x.n+' do TCU';return'Acórdão '+x.n+'/'+x.a+' – TCU – '+(COL[x.c]||x.c)}
function url(x){if(x.u)return x.u;if(x.src==='tcesp')return'https://www.tce.sp.gov.br/sumulas';if(x.src==='tcusum')return'https://pesquisa.apps.tcu.gov.br/documento/sumula/*/KEY%253A'+x.k+'/DTRELEVANCIA%2520desc/0';if(x.src==='tcuinfo')return'https://pesquisa.apps.tcu.gov.br/documento/informativo-lc/*/KEY%253A'+x.k+'/DTRELEVANCIA%2520desc/0';return'https://pesquisa.apps.tcu.gov.br/documento/jurisprudencia-selecionada/*/KEY%253A'+x.k+'/score%2520desc/0'}
function cit(x){if(x.ci)return'"'+x.e+'" ('+x.ci+').';if(x.src==='tcesp'||x.src==='tcusum')return'"'+x.e+'" ('+ac(x)+').';if(x.src==='tcuinfo')return'"'+x.e+'" ('+ac(x)+(x.rel?', Rel. Min. '+tit(x.rel):'')+'; Informativo de Licitações e Contratos do TCU nº '+String(x.s||'').replace('Informativo ','')+').';return'"'+x.e+'" ('+ac(x)+', Rel. Min. '+tit(x.rel)+', sessão de '+x.d+').'}
function guarda(){try{localStorage.setItem('dv_teses_sel',JSON.stringify(SEL))}catch(x){}barra()}
function copiar(t){if(navigator.clipboard)navigator.clipboard.writeText(t);else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');a.remove()}}
function barra(){var n=Object.keys(SEL).length,B=R.querySelector('.ts-sel');B.hidden=!n;B.innerHTML='<b>'+n+' tese(s) selecionada(s)</b><button type="button" class="g" data-cpall="1">Copiar citações</button><button type="button" data-ia="1">Montar argumento com a sua IA</button><button type="button" class="g" data-lp="1">Limpar</button>'}
function item(x){var s=SEL[x.k];return'<div class="ts-i'+(s?' sel':'')+'" data-k="'+e(x.k)+'"><div class="m"><a href="'+url(x)+'" target="_blank" rel="noopener">'+e(ac(x))+'</a>'+(x.lab?(x.meta?' · '+e(x.meta):''):x.src==='tcesp'?' · Tribunal de Contas do Estado de São Paulo':x.src==='tcusum'?' · '+e(x.s)+(x.apr?' · aprovada pelo Acórdão '+e(x.apr):''):x.src==='tcuinfo'?(x.rel?' · Rel. '+e(tit(x.rel)):'')+' · <span class="ts-tag">'+e(x.s)+'</span>':' · '+e(x.d)+' · Rel. '+e(tit(x.rel))+' · '+e(x.t)+' › '+e(x.s)+(x.src==='tcucons'?' · <span class="ts-tag">Resposta a consulta</span>':''))+'</div><p>'+e(x.e)+'</p>'+(x.nota?'<p class="ts-h"><i>'+e(x.nota)+'</i></p>':'')+'<div class="k"><button type="button" class="g" data-cp="'+e(x.k)+'">Copiar citação</button><button type="button" class="g" data-sl="'+e(x.k)+'">'+(s?'✓ Selecionada':'+ Selecionar')+'</button></div></div>'}
var ATUAIS={};
function inicio(){O.innerHTML='<p class="ts-h">Carregando…</p>';g('teses').then(function(j){BASE=j;var h='<h3>Teses prontas para as situações mais comuns</h3><div class="ts-sg">'+(j.situacoes||[]).map(function(s,i){return'<button type="button" class="ts-s" data-sit="'+i+'"><span>'+e(s.tema)+'</span><b>'+e(s.titulo)+'</b></button>'}).join('')+'</div>';
 h+='<h3>Atalhos</h3><div class="ts-tm"><span data-fonte="sumulas">Súmulas (TCU e TCE-SP)</span><span data-fonte="info">Últimos informativos do TCU</span><span data-fonte="tcesp">Súmulas do TCE-SP</span></div>';
 h+='<h3>Ou navegue pelos temas <small class="ts-h">('+(j.total_base||0).toLocaleString('pt-BR')+' enunciados e súmulas sobre licitações)</small></h3><div class="ts-tm">'+Object.keys(j.temas||{}).map(function(k){return'<span data-tema="'+e(k)+'">'+e(k.split(' > ')[1])+' <small>'+j.temas[k]+'</small></span>'}).join('')+'</div>';O.innerHTML=h}).catch(function(){O.innerHTML='<p class="ts-h">Não foi possível carregar o banco agora. Tente de novo em instantes.</p>'})}
function situacao(i){var s=BASE.situacoes[i];var h='<p><a href="#" data-home="1">← Voltar</a></p><div class="ts-cx"><span class="ts-h">'+e(s.tema)+'</span><h4>'+e(s.titulo)+'</h4><p>'+e(s.resumo)+'</p><b>Na prática</b><ul>'+s.pratica.map(function(p){return'<li>'+e(p)+'</li>'}).join('')+'</ul><b>Prazo:</b> '+e(s.prazo)+(s.nota?'<p class="ts-h"><i>'+e(s.nota)+'</i></p>':'')+'</div><h3>Decisões do TCU</h3>';
 h+=s.dec.map(function(d){var m=d.url.match(/KEY%253A([^/]+)/),k=m?m[1]:d.ac,p=d.ac.match(/Acórdão (\d+)\/(\d+) – (.+)/)||[];var x={k:k,n:p[1],a:p[2],c:p[3],d:d.data,rel:d.rel,e:d.enunciado,t:s.tema,s:''};ATUAIS[k]=x;return item(x).replace('<p>','<p><b>'+e(d.txt)+'</b></p><p class="ts-h">')}).join('');
 h+='<p><a href="#" data-q="'+e(s.titulo)+'">Ver mais decisões sobre este assunto →</a></p>';O.innerHTML=h;window.scrollTo({top:R.getBoundingClientRect().top+scrollY-20})}
function buscar(){O.innerHTML='<p class="ts-h">Buscando…</p>';g('teses?q='+encodeURIComponent(Q)+'&tema='+encodeURIComponent(TEMA)+'&pag='+PAG+'&fonte='+FONTE).then(function(j){j.itens.forEach(function(x){ATUAIS[x.k]=x});var h='<p><a href="#" data-home="1">← Início</a></p><div class="ts-tm" style="margin-bottom:6px">'+[['','Tudo'],['sumulas','Súmulas'],['info','Informativo do TCU'],['tcu','TCU'],['tcesp','TCE-SP'],['stj','STJ'],['cjf','Enunciados CJF']].map(function(f){return'<span data-fonte="'+f[0]+'" class="'+(FONTE===f[0]?'on':'')+'">'+f[1]+'</span>'}).join('')+'</div><h3>'+j.total+' resultado(s)'+(Q?' para "'+e(Q)+'"':'')+(TEMA?' em '+e(TEMA.split(' > ')[1]):'')+(!Q&&!TEMA&&FONTE?' — '+({sumulas:'Súmulas',info:'Informativo de Licitações e Contratos do TCU',tcu:'TCU',tcesp:'TCE-SP',stj:'STJ',cjf:'Enunciados do CJF'}[FONTE]||''):'')+'</h3>';
 if(Q&&!TEMA&&Object.keys(j.temas).length>1)h+='<div class="ts-tm" style="margin-bottom:12px">'+Object.keys(j.temas).map(function(k){return'<span data-tema="'+e(k)+'">'+e(k.split(' > ')[1])+' <small>'+j.temas[k]+'</small></span>'}).join('')+'</div>';
 h+=j.itens.length?j.itens.map(item).join(''):'<p class="ts-h">Nada encontrado. Tente palavras mais gerais (ex.: "planilha" em vez de "planilha de custos e formação de preços").</p>';
 var np=Math.ceil(j.total/j.por);if(np>1)h+='<div class="ts-pg">'+(PAG>1?'<button type="button" class="g" data-pg="'+(PAG-1)+'">← Anteriores</button>':'')+'<span class="ts-h">Página '+PAG+' de '+np+'</span>'+(PAG<np?'<button type="button" class="g" data-pg="'+(PAG+1)+'">Próximos →</button>':'')+'</div>';
 O.innerHTML=h})}
function ia(){var L=Object.keys(SEL).map(function(k){return SEL[k]});var box=document.createElement('div');box.className='ts-ia';box.innerHTML='<b>Descreva o seu caso</b> <span class="ts-h">(o que aconteceu, o órgão, a peça que você precisa: impugnação, recurso, contrarrazões, resposta a diligência)</span><textarea placeholder="Ex.: Fomos desclassificados no pregão 12/2026 da Prefeitura X porque a planilha não trazia o adicional noturno, sem diligência prévia. Preciso de um recurso."></textarea><div class="ias">'+IAS.map(function(a,i){return'<button type="button" class="g" data-ai="'+i+'"><img src="https://www.google.com/s2/favicons?domain='+a[2]+'&sz=64" alt="">'+a[0]+'</button>'}).join('')+'<button type="button" class="g" data-ai="c">Só copiar</button></div><p class="ts-h ts-iam"></p>';
 var old=R.querySelector('.ts-ia');if(old)old.remove();R.querySelector('.ts-sel').after(box);box.querySelector('textarea').focus();box._L=L}
function prompt(box){var L=box._L,c=box.querySelector('textarea').value.trim();return'Você é um especialista em licitações públicas (Lei 14.133/2021). Com base no caso abaixo e usando como fundamento as teses do TCU listadas, monte a argumentação da peça indicada: (1) resumo dos fatos; (2) argumentos, um por tese, explicando por que ela se aplica ao caso; (3) pedido final. Cite as decisões exatamente como estão abaixo, sem inventar outros acórdãos ou números. Se alguma tese não se aplicar ao caso, diga isso.\n\nCASO:\n'+(c||'[descreva aqui]')+'\n\nTESES DO TCU:\n'+L.map(function(x,i){return(i+1)+'. '+cit(x)}).join('\n')+'\n\nAo final, lembre que o texto deve ser revisado antes do protocolo.'}
R.addEventListener('submit',function(ev){ev.preventDefault();Q=R.querySelector('[name=q]').value.trim();TEMA='';PAG=1;if(Q)buscar();else inicio()});
R.addEventListener('click',function(ev){var t=ev.target.closest('button,a,span[data-tema],span[data-fonte]');if(!t||!R.contains(t))return;var D=t.dataset,x;
 if(D.home){ev.preventDefault();Q='';TEMA='';FONTE='';inicio();return}
 if(D.sit!=null){situacao(+D.sit);return}
 if(D.q){ev.preventDefault();Q=D.q.replace(/^(Fui|Disseram que o meu|Pediram um)\s*/i,'').replace(/\(.*\)/,'').trim();R.querySelector('[name=q]').value=Q;TEMA='';PAG=1;buscar();return}
 if(D.fonte!=null){FONTE=D.fonte;PAG=1;buscar();return}
 if(D.tema){TEMA=D.tema;PAG=1;buscar();return}
 if(D.pg){PAG=+D.pg;buscar();window.scrollTo({top:R.getBoundingClientRect().top+scrollY-20});return}
 if(D.cp){x=ATUAIS[D.cp];if(x){copiar(cit(x));t.textContent='Copiado ✓'}return}
 if(D.sl){x=ATUAIS[D.sl];if(!x)return;if(SEL[x.k])delete SEL[x.k];else SEL[x.k]=x;guarda();t.textContent=SEL[x.k]?'✓ Selecionada':'+ Selecionar';t.closest('.ts-i').classList.toggle('sel',!!SEL[x.k]);return}
 if(D.cpall){copiar(Object.keys(SEL).map(function(k){return cit(SEL[k])}).join('\n\n'));t.textContent='Copiado ✓';return}
 if(D.lp){SEL={};guarda();R.querySelectorAll('.ts-i.sel').forEach(function(i){i.classList.remove('sel')});R.querySelectorAll('[data-sl]').forEach(function(b){b.textContent='+ Selecionar'});var o=R.querySelector('.ts-ia');if(o)o.remove();return}
 if(D.ia){ia();return}
 if(D.ai!=null){var box=t.closest('.ts-ia');copiar(prompt(box));var m=box.querySelector('.ts-iam');if(D.ai==='c'){m.textContent='Copiado! Cole na IA que você preferir.';return}var a=IAS[+D.ai];window.open(a[1],'_blank','noopener');m.textContent='Copiado! Na aba do '+a[0]+', cole (Ctrl+V ou ⌘+V) e envie.';return}
});
barra();inicio();
})();
</script>
	<?php return ob_get_clean();
} );
