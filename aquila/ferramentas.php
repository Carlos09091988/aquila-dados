/* Aquila — ferramentas gratuitas para quem está com um problema na mão (nov/2026).
 * Calculadora de prazos, "Fui inabilitado, cabe recurso?", reequilíbrio/repactuação e consulta de penalidades por CNPJ.
 * Tudo roda no navegador; a consulta de penalidades lê o índice público do CEIS/CNEP publicado no GitHub. */

function aq_fr_paginas() {
	return array(
		'ferramentas'                => array( 'Ferramentas gratuitas', '[aq_ferramentas]' ),
		'calculadora-de-prazos'      => array( 'Calculadora de prazos em licitações', '[aq_prazos]' ),
		'analise-de-inabilitacao'    => array( 'Fui inabilitado. Cabe recurso?', '[aq_inabilitacao]' ),
		'calculadora-de-reequilibrio' => array( 'Reequilíbrio e repactuação do contrato', '[aq_reequilibrio]' ),
		'consulta-de-penalidades'    => array( 'Consulta de penalidades por CNPJ', '[aq_penalidades]' ),
	);
}
add_action( 'init', function () {
	if ( get_option( 'aq_fr_ver' ) === '1' ) { return; }
	foreach ( aq_fr_paginas() as $slug => $p ) {
		$e = get_page_by_path( $slug );
		if ( $e ) { if ( strpos( $e->post_content, '[aq_' ) === false ) { wp_update_post( array( 'ID' => $e->ID, 'post_content' => $p[1], 'post_status' => 'publish', 'post_title' => $p[0] ) ); } continue; }
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $p[0], 'post_content' => $p[1] ) );
	}
	update_option( 'aq_fr_ver', '1', false );
}, 20 );
/* As páginas usam o mesmo cabeçalho, rodapé e estilo das páginas do site (lidos da página de atuação),
   no modelo Elementor Canvas, para não aparecer o cabeçalho do tema. */
function aq_fr_hero() {
	return array(
		'ferramentas'                 => array( 'Ferramentas gratuitas', 'Ferramentas gratuitas para empresas que contratam com o Poder Público. Elas dão uma primeira orientação; cada caso merece análise própria.' ),
		'calculadora-de-prazos'       => array( 'Calculadora de prazos em licitações', 'Prazos da Lei 14.133/2021 contados em dias úteis, excluindo o dia do começo e incluindo o do vencimento (art. 183). Já considera os feriados nacionais, o Carnaval, a Sexta-feira Santa e Corpus Christi.' ),
		'analise-de-inabilitacao'     => array( 'Fui inabilitado. Cabe recurso?', 'Responda em um minuto e veja os caminhos para contestar a decisão, os fundamentos mais usados e o prazo para recorrer.' ),
		'calculadora-de-reequilibrio' => array( 'Reequilíbrio e repactuação do contrato', 'Estime quanto o seu contrato pode estar deixando de receber com o dissídio da categoria e a inflação desde a data-base.' ),
		'consulta-de-penalidades'     => array( 'Consulta de penalidades por CNPJ', 'Confira se a empresa tem penalidade registrada nos cadastros oficiais de empresas punidas (CEIS e CNEP, da CGU) e busque o CNPJ nos diários oficiais, onde aparecem as notificações e os processos de penalidade.' ),
	);
}
add_action( 'init', function () {
	if ( get_option( 'aq_fr_tpl' ) === '1' ) { return; }
	foreach ( array_keys( aq_fr_paginas() ) as $slug ) { $e = get_page_by_path( $slug ); if ( $e ) { update_post_meta( $e->ID, '_wp_page_template', 'elementor_canvas' ); } }
	update_option( 'aq_fr_tpl', '1', false );
}, 21 );
function aq_fr_moldura() {
	static $m = null; if ( $m !== null ) { return $m; }
	$m = false;
	$fonte = get_page_by_path( 'atuacao/ppps-e-concessoes' );
	$raw = $fonte ? $fonte->post_content : get_post_field( 'post_content', 1809 );
	if ( ! $raw ) { return $m; }
	$raw = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $raw );
	$i = strpos( $raw, '<section class="phero' ); $j = strpos( $raw, '<section class="blk contato' );
	if ( $i === false || $j === false ) { return $m; }
	$m = array( str_replace( ' aria-current="page"', '', substr( $raw, 0, $i ) ), substr( $raw, $j ) );
	return $m;
}
add_filter( 'the_content', function ( $c ) {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) { return $c; }
	$slug = get_post_field( 'post_name', get_the_ID() ); $H = aq_fr_hero();
	if ( ! isset( $H[ $slug ] ) || strpos( $c, 'class="aqf' ) === false ) { return $c; }
	$m = aq_fr_moldura(); if ( ! $m ) { return $c; }
	$cr = '<a href="/">Início</a>' . ( $slug === 'ferramentas' ? '' : ' / <a href="/ferramentas/">Ferramentas</a>' );
	$hero = '<section class="phero on-ink"><div class="wrap"><nav class="crumbs" aria-label="Você está em">' . $cr . '</nav><p class="kicker">' . ( $slug === 'ferramentas' ? 'Ferramentas' : 'Ferramenta gratuita' ) . '</p><h1>' . esc_html( $H[ $slug ][0] ) . '</h1><p class="lead">' . esc_html( $H[ $slug ][1] ) . '</p></div></section>';
	return $m[0] . $hero . '<section class="blk aqf-blk"><div class="wrap">' . $c . '</div></section>' . $m[1];
}, 99 );

function aq_fr_wa( $t ) { return 'https://wa.me/5511993500950?text=' . rawurlencode( $t ); }
function aq_fr_css() {
	static $ja = false; if ( $ja ) { return ''; } $ja = true;
	return '<style>
.aqf-blk{padding:56px 0 80px!important}
.aqf{--n:#0F1B2D;--a:#0F1B2D;--g:#B8924F;--c:#F8F7F3;--l:#D9D5CC;--m:#4A5668;font-family:"Public Sans",system-ui,sans-serif;color:var(--n);max-width:880px;line-height:1.6;font-size:17px}
.aqf p{margin:0 0 10px}.aqf h2,.aqf h3{font-family:"EB Garamond",Georgia,serif;font-weight:500;color:var(--n);margin:0 0 10px;font-size:28px;line-height:1.2}
.aqf .bx{background:#fff;border:1px solid var(--l);border-radius:4px;padding:26px 28px;margin:0 0 20px}
.aqf label{display:block;font-weight:600;font-size:15px;margin:14px 0 6px}.aqf label:first-child{margin-top:0}.aqf small{color:var(--m);font-size:13px}
.aqf input,.aqf select,.aqf textarea{width:100%;box-sizing:border-box;border:1px solid #CFCAC0;border-radius:2px;padding:12px 14px;font:inherit;font-size:16px;background:#fff;color:var(--n)}
.aqf input:focus,.aqf select:focus,.aqf textarea:focus{outline:2px solid var(--g);outline-offset:1px;border-color:var(--g)}
.aqf textarea{min-height:110px}.aqf .g2{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:640px){.aqf .g2{grid-template-columns:1fr}.aqf .bx{padding:20px}}
.aqf button{background:var(--n);color:var(--c);border:0;border-radius:2px;min-height:48px;padding:0 26px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;margin-top:18px}.aqf button:hover{background:#1B2A40}
.aqf .r{display:none}.aqf .r.on{display:block}.aqf .big{font-family:"EB Garamond",Georgia,serif;font-size:40px;font-weight:500;line-height:1.1;color:var(--n)}
.aqf .tag{display:inline-block;border-radius:2px;padding:4px 10px;font-size:14px;font-weight:600;margin:2px 6px 2px 0}.aqf .ok{background:#E8F4EC;color:#13612e}.aqf .at{background:#FBF1DC;color:#7A5410}.aqf .rv{background:#FBEAE7;color:#a90707}
.aqf .cta{background:var(--n);color:#C9D0D9;border-radius:4px;padding:26px 28px;margin-top:20px}.aqf .cta b{color:#F8F7F3;font-family:"EB Garamond",Georgia,serif;font-weight:500;font-size:22px}.aqf .cta a{display:inline-flex;align-items:center;min-height:46px;margin-top:14px;background:var(--g);color:var(--n);border-radius:2px;padding:0 22px;font-weight:600;font-size:15px;text-decoration:none}.aqf .cta a:hover{background:#D4B77E}
.aqf ul{padding-left:20px;margin:6px 0 12px}.aqf li{margin:6px 0}.aqf .nt{font-size:13px;color:var(--m);margin-top:14px}.aqf .bx a{color:var(--n);text-decoration:underline;text-decoration-color:var(--g);text-underline-offset:3px}
.aqf .cards{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:640px){.aqf .cards{grid-template-columns:1fr}}.aqf .cards a{display:block;background:#fff;border:1px solid var(--l);border-top:3px solid var(--g);border-radius:4px;padding:24px 26px;text-decoration:none;color:var(--n);transition:border-color .2s,transform .2s}.aqf .cards a:hover{border-color:var(--g);transform:translateY(-2px)}.aqf .cards a b{display:block;font-family:"EB Garamond",Georgia,serif;font-weight:500;font-size:24px;line-height:1.2;margin-bottom:8px}.aqf .cards a span{color:var(--m);font-size:15px}.aqf .cards a i{display:block;font-style:normal;color:var(--g);font-weight:600;font-size:14px;margin-top:14px}
</style>';
}
function aq_fr_cta( $txt, $wa ) {
	return '<div class="cta"><b>' . esc_html( $txt ) . '</b><br><span>Cada caso tem detalhes que mudam o resultado. Para uma análise jurídica aprofundada, fale com o escritório.</span><br><a href="' . esc_url( aq_fr_wa( $wa ) ) . '" target="_blank" rel="noopener">Falar com a Aquila pelo WhatsApp</a></div>';
}
/* Feriados nacionais + datas móveis, e contagem em dias úteis (art. 183 da Lei 14.133) */
function aq_fr_js_base() {
	static $ja = false; if ( $ja ) { return ''; } $ja = true;
	return '<script>window.AQ=window.AQ||{};(function(A){function pas(y){var a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e=b%4,f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3),h=(19*a+b-d-g+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451),mo=Math.floor((h+l-7*m+114)/31),da=((h+l-7*m+114)%31)+1;return new Date(y,mo-1,da)}
function iso(d){return d.getFullYear()+"-"+("0"+(d.getMonth()+1)).slice(-2)+"-"+("0"+d.getDate()).slice(-2)}
function fer(y){var f=["01-01","04-21","05-01","09-07","10-12","11-02","11-15","11-20","12-25"].map(function(x){return y+"-"+x}),p=pas(y),ad=function(n){var d=new Date(p);d.setDate(d.getDate()+n);return iso(d)};f.push(ad(-48),ad(-47),ad(-2),ad(60));return f}
var C={};A.feriado=function(d){var y=d.getFullYear();C[y]=C[y]||fer(y);return C[y].indexOf(iso(d))>=0};
A.util=function(d){var w=d.getDay();return w!==0&&w!==6&&!A.feriado(d)};
A.soma=function(d,n){d=new Date(d);var k=0;while(k<n){d.setDate(d.getDate()+1);if(A.util(d))k++}return d};
A.antes=function(d,n){d=new Date(d);var k=0;while(k<n){d.setDate(d.getDate()-1);if(A.util(d))k++}return d};
A.dt=function(s){var m=String(s).match(/(\\d{4})-(\\d{2})-(\\d{2})/);return m?new Date(+m[1],m[2]-1,+m[3]):null};
A.br=function(d){var dias=["domingo","segunda-feira","terça-feira","quarta-feira","quinta-feira","sexta-feira","sábado"];return("0"+d.getDate()).slice(-2)+"/"+("0"+(d.getMonth()+1)).slice(-2)+"/"+d.getFullYear()+" ("+dias[d.getDay()]+")"};
A.faltam=function(d){var h=new Date();h.setHours(0,0,0,0);return Math.round((d-h)/864e5)};
A.e=function(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]})};
A.rs=function(v){return Number(v||0).toLocaleString("pt-BR",{style:"currency",currency:"BRL"})};})(window.AQ);</script>';
}

/* ---------- Hub ---------- */
add_shortcode( 'aq_ferramentas', function () {
	$c = array( array( 'calculadora-de-prazos', 'Calculadora de prazos', 'Impugnação, esclarecimento, recurso, contrarrazões e defesa em processo de penalidade, em dias úteis, com feriados.' ),
		array( 'analise-de-inabilitacao', 'Fui inabilitado. Cabe recurso?', 'Conte o motivo da inabilitação e veja os caminhos, os fundamentos e o prazo para recorrer.' ),
		array( 'calculadora-de-reequilibrio', 'Reequilíbrio e repactuação', 'Veja quanto o seu contrato pode estar deixando de receber com o dissídio e a inflação do período.' ),
		array( 'consulta-de-penalidades', 'Consulta de penalidades por CNPJ', 'Confira se a empresa tem sanção registrada no CEIS ou no CNEP e busque o CNPJ nos diários oficiais.' ) );
	$h = aq_fr_css() . '<div class="aqf"><div class="cards">';
	foreach ( $c as $x ) { $h .= '<a href="/' . $x[0] . '/"><b>' . esc_html( $x[1] ) . '</b><span>' . esc_html( $x[2] ) . '</span><i>Abrir ferramenta →</i></a>'; }
	return $h . '</div></div>';
} );

/* ---------- Calculadora de prazos ---------- */
add_shortcode( 'aq_prazos', function () {
	ob_start(); echo aq_fr_css() . aq_fr_js_base(); ?>
<div class="aqf" id="aq-pz">
<div class="bx"><label for="pz-t">O que você precisa fazer?</label><select id="pz-t">
<option value="imp">Impugnar o edital ou pedir esclarecimento</option><option value="rec">Apresentar as razões do recurso</option><option value="con">Apresentar contrarrazões</option>
<option value="def">Defesa em processo de penalidade</option><option value="rsa">Recurso contra penalidade (advertência, multa ou impedimento)</option><option value="rei">Pedido de reconsideração contra declaração de inidoneidade</option></select>
<label for="pz-d" id="pz-l">Data de abertura da licitação</label><input type="date" id="pz-d">
<label for="pz-x">Feriados locais do órgão (opcional)</label><input id="pz-x" placeholder="Ex.: 25/01, 09/07"><small>Datas em que o órgão não tem expediente, separadas por vírgula.</small>
<button type="button" id="pz-b">Calcular prazo</button></div><div class="r" id="pz-r"></div></div>
<script>(function(){var A=window.AQ,R={imp:["Data de abertura da licitação",3,-1,"até 3 dias úteis antes da abertura (art. 164)","Prazo para impugnar ou pedir esclarecimento"],rec:["Data da intimação ou da lavratura da ata",3,1,"3 dias úteis da intimação ou da lavratura da ata (art. 165, I e § 1º). A intenção de recorrer deve ser manifestada na própria sessão.","Último dia para as razões do recurso"],con:["Data da intimação do recurso",3,1,"3 dias úteis da intimação do recurso (art. 165, § 4º)","Último dia para as contrarrazões"],def:["Data da intimação para a defesa",15,1,"15 dias úteis da intimação (arts. 157 e 158)","Último dia para a defesa"],rsa:["Data da intimação da decisão",15,1,"15 dias úteis da intimação (art. 166)","Último dia para o recurso"],rei:["Data da intimação da decisão",15,1,"15 dias úteis da intimação (art. 167)","Último dia para o pedido de reconsideração"]};
var t=document.getElementById("pz-t"),d=document.getElementById("pz-d"),x=document.getElementById("pz-x"),r=document.getElementById("pz-r");t.onchange=function(){document.getElementById("pz-l").textContent=R[t.value][0]};
document.getElementById("pz-b").onclick=function(){var b=A.dt(d.value);if(!b){r.className="r on";r.innerHTML="<div class=bx>Informe a data.</div>";return}var y=b.getFullYear(),ex=(x.value.match(/\d{1,2}\/\d{1,2}/g)||[]);var of=A.feriado;A.feriado=function(z){var k=("0"+z.getDate()).slice(-2)+"/"+("0"+(z.getMonth()+1)).slice(-2);return of(z)||ex.some(function(q){var p=q.split("/");return("0"+p[0]).slice(-2)+"/"+("0"+p[1]).slice(-2)===k})};
var c=R[t.value],f=c[2]<0?A.antes(b,c[1]):A.soma(b,c[1]);A.feriado=of;var n=A.faltam(f),st=n<0?"<span class=\"tag rv\">Prazo encerrado</span>":n===0?"<span class=\"tag rv\">Vence hoje</span>":n<=2?"<span class=\"tag at\">Faltam "+n+" dia(s)</span>":"<span class=\"tag ok\">Faltam "+n+" dias</span>";
r.className="r on";r.innerHTML="<div class=bx><p>"+c[4]+"</p><div class=big>"+A.br(f)+"</div><p>"+st+"</p><p><b>Regra:</b> "+c[3]+".</p><p class=nt>Confira no edital e no sistema do órgão o horário limite e a forma de envio. Se o órgão não tiver expediente no início ou no fim do prazo, ele é prorrogado para o dia útil seguinte (art. 183, § 2º).</p></div>"+<?php echo wp_json_encode( aq_fr_cta( 'Prazo correndo? O escritório prepara a sua peça.', 'Olá! Usei a calculadora de prazos do site e preciso de apoio com uma peça.' ) ); ?>};})();</script>
	<?php return ob_get_clean();
} );

/* ---------- Fui inabilitado. Cabe recurso? ---------- */
add_shortcode( 'aq_inabilitacao', function () {
	ob_start(); echo aq_fr_css() . aq_fr_js_base(); ?>
<div class="aqf" id="aq-in">
<div class="bx"><label for="in-m">Qual foi o motivo da inabilitação ou desclassificação?</label><select id="in-m">
<option value="cert">Certidão vencida, faltando ou com pendência</option><option value="atest">Atestado de capacidade técnica insuficiente</option><option value="bal">Balanço ou índices contábeis</option>
<option value="doc">Documento faltando ou com erro formal</option><option value="inex">Proposta considerada inexequível</option><option value="plan">Erro na planilha de custos</option><option value="outro">Outro motivo</option></select>
<div class="g2"><div><label for="in-me">A empresa é ME ou EPP?</label><select id="in-me"><option value="n">Não</option><option value="s">Sim</option></select></div>
<div><label for="in-dl">Houve diligência antes da decisão?</label><select id="in-dl"><option value="n">Não</option><option value="s">Sim</option><option value="x">Não sei</option></select></div></div>
<label for="in-d">Data da decisão (ata ou intimação)</label><input type="date" id="in-d">
<button type="button" id="in-b">Ver os caminhos</button></div><div class="r" id="in-r"></div></div>
<script>(function(){var A=window.AQ,M={
cert:["Certidão vencida, faltando ou com pendência",["Se a certidão já existia e estava válida na data da sessão, ela pode ser juntada depois para provar uma condição que a empresa já tinha (TCU, Acórdão 1.211/2021-Plenário).","O agente de contratação deve sanear falhas que não alterem a substância dos documentos (art. 64, § 1º)."],"Pedir a reconsideração e juntar a certidão que comprova a regularidade na data da sessão."],
atest:["Atestado de capacidade técnica insuficiente",["A exigência de quantitativo mínimo não pode passar de 50% do objeto (art. 67, § 2º).","Atestados podem ser somados quando o edital não proibir de forma justificada, e a Administração pode fazer diligência para esclarecer o conteúdo (art. 64)."],"Conferir se o edital respeitou o limite de 50% e se os atestados somados atendem; pedir diligência."],
bal:["Balanço ou índices contábeis",["Os índices exigidos devem estar justificados no processo e ser usuais para o tipo de contratação (art. 69, § 5º).","O edital pode exigir capital ou patrimônio líquido mínimo de até 10% do valor estimado (art. 69, § 4º); confira se as exigências respeitam esse limite."],"Verificar se os índices foram justificados no processo e se as exigências respeitam os limites da lei."],
doc:["Documento faltando ou com erro formal",["Falhas formais que não alterem a substância podem ser saneadas por despacho fundamentado (art. 64, § 1º).","O TCU condena o formalismo excessivo quando a dúvida pode ser resolvida por diligência."],"Apontar que o erro é formal e pedir o saneamento ou a diligência."],
inex:["Proposta considerada inexequível",["A presunção de inexequibilidade é relativa: a empresa deve ter a chance de demonstrar que consegue executar o contrato (TCU, Súmula 262).","A Administração pode fazer diligência para aferir a exequibilidade (art. 59, § 2º)."],"Demonstrar a composição de custos com a convenção coletiva, encargos, insumos e tributos."],
plan:["Erro na planilha de custos",["Erros na planilha que não alteram o valor global podem ser corrigidos em diligência.","Desclassificar por erro sanável, sem dar a chance de corrigir, contraria o formalismo moderado aceito pelo TCU."],"Mostrar que o erro é sanável e que o preço global se mantém."],
outro:["Outro motivo",["Toda decisão de inabilitação precisa ser motivada, com indicação do item do edital e da razão.","Falhas sanáveis devem ser corrigidas por diligência antes da inabilitação (art. 64)."],"Pedir acesso à decisão completa e aos documentos para avaliar o melhor caminho."]};
document.getElementById("in-b").onclick=function(){var m=M[document.getElementById("in-m").value],me=document.getElementById("in-me").value==="s",dl=document.getElementById("in-dl").value,d=A.dt(document.getElementById("in-d").value),r=document.getElementById("in-r");
var h="<div class=bx><h3>"+m[0]+"</h3>";h+=dl==="n"?"<p><span class=\"tag ok\">Bom sinal</span> Não houve diligência antes da decisão. Isso costuma fortalecer o recurso, porque a Administração deveria ter esclarecido o ponto antes de inabilitar.</p>":dl==="s"?"<p><span class=\"tag at\">Atenção</span> Houve diligência. O recurso precisa mostrar por que a resposta foi suficiente ou por que a análise errou.</p>":"";
h+="<p><b>Fundamentos que costumam ser usados:</b></p><ul>"+m[1].map(function(x){return"<li>"+x+"</li>"}).join("")+(me&&document.getElementById("in-m").value==="cert"?"<li>ME e EPP têm 5 dias úteis, prorrogáveis por igual período, para regularizar a documentação fiscal depois de declaradas vencedoras (Lei Complementar 123/2006, art. 43, § 1º).</li>":"")+"</ul><p><b>Primeiro passo:</b> "+m[2]+"</p>";
if(d){var f=A.soma(d,3),n=A.faltam(f);h+="<p><b>Prazo para as razões do recurso:</b> "+A.br(f)+" "+(n<0?"<span class=\"tag rv\">encerrado</span>":"<span class=\"tag "+(n<=1?"at":"ok")+"\">faltam "+n+" dia(s)</span>")+"<br><small>3 dias úteis da intimação ou da ata (art. 165, I). A intenção de recorrer precisa ter sido registrada na sessão.</small></p>"}
h+="<p class=nt>Orientação geral, baseada na Lei 14.133/2021 e na jurisprudência do TCU. Não substitui a análise do edital, da ata e dos documentos do caso.</p></div>";
r.className="r on";r.innerHTML=h+<?php echo wp_json_encode( aq_fr_cta( 'Quer contestar a decisão? O escritório analisa a ata e prepara o recurso.', 'Olá! Fui inabilitado em uma licitação e quero avaliar o recurso.' ) ); ?>};})();</script>
	<?php return ob_get_clean();
} );

/* ---------- Reequilíbrio e repactuação ---------- */
add_shortcode( 'aq_reequilibrio', function () {
	ob_start(); echo aq_fr_css() . aq_fr_js_base(); ?>
<div class="aqf" id="aq-rq">
<div class="bx"><div class="g2"><div><label for="rq-v">Valor mensal do contrato (R$)</label><input id="rq-v" inputmode="decimal" placeholder="Ex.: 150.000,00"></div>
<div><label for="rq-p">Parte do custo que é mão de obra (%)</label><input id="rq-p" inputmode="decimal" value="70"><small>Em contratos de limpeza e vigilância costuma ficar entre 60% e 80%.</small></div>
<div><label for="rq-b">Data-base (proposta ou último reajuste)</label><input type="date" id="rq-b"></div>
<div><label for="rq-d">Reajuste da convenção coletiva (%)</label><input id="rq-d" inputmode="decimal" placeholder="Ex.: 5,5"></div></div>
<label for="rq-i">Inflação dos demais custos no período (%)</label><input id="rq-i" inputmode="decimal" placeholder="Calculamos o IPCA automaticamente"><small>Deixe em branco para usar o IPCA acumulado desde a data-base (Banco Central).</small>
<button type="button" id="rq-go">Calcular</button></div><div class="r" id="rq-r"></div></div>
<script>(function(){var A=window.AQ,n=function(s){s=String(s||"").replace(/[^\d,.-]/g,"");if(s.indexOf(",")>=0)s=s.replace(/\./g,"").replace(",",".");return parseFloat(s)||0};
function ipca(b){var h=new Date(),f=function(d){return("0"+d.getDate()).slice(-2)+"/"+("0"+(d.getMonth()+1)).slice(-2)+"/"+d.getFullYear()};return fetch("https://api.bcb.gov.br/dados/serie/bcdata.sgs.433/dados?formato=json&dataInicial="+f(b)+"&dataFinal="+f(h)).then(function(r){return r.json()}).then(function(L){var x=1;L.forEach(function(o){x*=1+parseFloat(o.valor)/100});return{v:(x-1)*100,m:L.length}})}
document.getElementById("rq-go").onclick=function(){var v=n(document.getElementById("rq-v").value),p=n(document.getElementById("rq-p").value)/100,b=A.dt(document.getElementById("rq-b").value),dc=n(document.getElementById("rq-d").value),ii=document.getElementById("rq-i").value.trim(),r=document.getElementById("rq-r");r.className="r on";
if(!v||!b){r.innerHTML="<div class=bx>Informe o valor mensal e a data-base.</div>";return}r.innerHTML="<div class=bx>Calculando…</div>";
(ii?Promise.resolve({v:n(ii),m:null}):ipca(b).catch(function(){return null})).then(function(I){if(!I){r.innerHTML="<div class=bx>Não conseguimos buscar o IPCA agora. Informe a inflação do período no campo acima.</div>";return}
var mo=v*p*dc/100,ou=v*(1-p)*I.v/100,dif=mo+ou,h=new Date(),ano=new Date(b);ano.setFullYear(ano.getFullYear()+1);var meses=Math.max(0,(h.getFullYear()-ano.getFullYear())*12+h.getMonth()-ano.getMonth()+(h.getDate()>=ano.getDate()?1:0));
var x="<div class=bx><p>Diferença estimada por mês</p><div class=big>"+A.rs(dif)+"</div><p>Novo valor mensal estimado: <b>"+A.rs(v+dif)+"</b> ("+((dif/v)*100).toFixed(2).replace(".",",")+"%)</p><ul><li>Mão de obra: "+A.rs(mo)+" ("+String(dc).replace(".",",")+"% da convenção sobre "+Math.round(p*100)+"% do contrato)</li><li>Demais custos: "+A.rs(ou)+" ("+I.v.toFixed(2).replace(".",",")+"% "+(ii?"informado":"de IPCA em "+I.m+" meses")+")</li></ul>";
x+=h<ano?"<p><span class=\"tag at\">Ainda não completou 1 ano</span> O direito ao reajuste ou à repactuação nasce depois de 1 ano da data-base, em "+A.br(ano)+".</p>":"<p><span class=\"tag ok\">Já completou 1 ano</span> desde "+A.br(ano)+". Se nada foi pedido, são cerca de <b>"+meses+" mês(es)</b>, ou <b>"+A.rs(dif*meses)+"</b> em valores retroativos estimados.</p>";
x+="<p><b>Atenção:</b> a repactuação da mão de obra conta da data da convenção coletiva (art. 135) e deve ser pedida antes de assinar a prorrogação do contrato, sob risco de perder o direito. O reajuste por índice segue o art. 25, § 7º, e o art. 92, V, da Lei 14.133.</p><p class=nt>Estimativa simplificada: o cálculo oficial usa a planilha de custos do contrato, item por item.</p></div>";
r.innerHTML=x+<?php echo wp_json_encode( aq_fr_cta( 'Quer receber o que é seu? O escritório monta o pedido com a planilha.', 'Olá! Usei a calculadora de reequilíbrio do site e quero avaliar o pedido do meu contrato.' ) ); ?>})};})();</script>
	<?php return ob_get_clean();
} );

/* ---------- Consulta de penalidades por CNPJ ---------- */
add_shortcode( 'aq_penalidades', function () {
	ob_start(); echo aq_fr_css() . aq_fr_js_base(); ?>
<div class="aqf" id="aq-pn">
<div class="bx"><label for="pn-c">CNPJ da empresa</label><input id="pn-c" inputmode="numeric" placeholder="00.000.000/0000-00"><button type="button" id="pn-b">Consultar</button></div><div class="r" id="pn-r"></div></div>
<script>(function(){var A=window.AQ,U="https://raw.githubusercontent.com/Carlos09091988/aquila-dados/main/data/sancoes/";
function fc(c){return c.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/,"$1.$2.$3/$4-$5")}function vd(c){if(!/^\d{14}$/.test(c)||/^(\d)\1+$/.test(c))return false;var s=function(n){var t=0,p=n-7;for(var i=0;i<n;i++){t+=c[i]*p--;if(p<2)p=9}var r=t%11<2?0:11-t%11;return r==c[n]};return s(12)&&s(13)}
function d8(s){var m=String(s).match(/(\d{2})\/(\d{2})\/(\d{4})/);return m?new Date(+m[3],m[2]-1,+m[1]):null}
document.getElementById("pn-b").onclick=function(){var c=document.getElementById("pn-c").value.replace(/\D/g,""),r=document.getElementById("pn-r");r.className="r on";if(!vd(c)){r.innerHTML="<div class=bx>CNPJ inválido.</div>";return}r.innerHTML="<div class=bx>Consultando…</div>";
Promise.all([fetch(U+c.slice(0,2)+".json").then(function(x){return x.ok?x.json():{}}).catch(function(){return null}),fetch(U+"meta.json").then(function(x){return x.json()}).catch(function(){return{}}),fetch("https://brasilapi.com.br/api/cnpj/v1/"+c).then(function(x){return x.ok?x.json():{}}).catch(function(){return{}})]).then(function(R){
if(R[0]===null){r.innerHTML="<div class=bx>Não foi possível consultar agora. Tente de novo em alguns minutos.</div>";return}var L=(R[0][c.slice(0,8)]||[]),hoje=new Date(),at=[],en=[];L.forEach(function(s){var f=d8(s.fim);(f&&f<hoje?en:at).push(s)});
var nm=R[2].razao_social?"<p><b>"+A.e(R[2].razao_social)+"</b> · CNPJ "+fc(c)+(R[2].descricao_situacao_cadastral?" · "+A.e(R[2].descricao_situacao_cadastral):"")+"</p>":"<p>CNPJ "+fc(c)+"</p>";
var li=function(s){return"<li><b>"+A.e(s.tipo||"Sanção")+"</b> ("+s.cad+") · "+A.e(s.orgao||"")+(s.uf?"/"+A.e(s.uf):"")+"<br><small>Início "+A.e(s.inicio||"?")+(s.fim?" · fim "+A.e(s.fim):" · sem data final")+(s.cnpj!==c?" · registrada no CNPJ "+fc(s.cnpj)+" (mesma empresa)":"")+(s.lei?" · "+A.e(s.lei):"")+"</small></li>"};
var h="<div class=bx>"+nm+(at.length?"<p><span class=\"tag rv\">"+at.length+" penalidade(s) em vigor</span></p><ul>"+at.map(li).join("")+"</ul>":"<p><span class=\"tag ok\">Nenhuma penalidade em vigor no CEIS ou no CNEP</span></p>")+(en.length?"<p><span class=\"tag at\">"+en.length+" penalidade(s) já encerrada(s)</span></p><ul>"+en.map(li).join("")+"</ul>":"");
var q=encodeURIComponent("\""+fc(c)+"\"");h+="<p><b>Notificações e processos nos diários oficiais:</b></p><p><a href=\"https://www.in.gov.br/consulta/-/buscar/dou?q="+q+"\" target=\"_blank\" rel=\"noopener\">Buscar no Diário Oficial da União</a> · <a href=\"https://queridodiario.ok.org.br/pesquisa?termo="+q+"\" target=\"_blank\" rel=\"noopener\">Buscar nos diários municipais</a></p>";
h+="<p class=nt>Fontes: CEIS e CNEP (Portal da Transparência/CGU), atualizados toda semana"+(R[1].atualizado?" (última atualização em "+R[1].atualizado.split("-").reverse().join("/")+")":"")+", e cadastro da Receita Federal. Processos em andamento que ainda não viraram penalidade não aparecem nesses cadastros.</p></div>";
r.innerHTML=h+<?php echo wp_json_encode( aq_fr_cta( 'Recebeu notificação ou tem penalidade em vigor? O escritório prepara a defesa.', 'Olá! Consultei um CNPJ no site e preciso de apoio com um processo de penalidade.' ) ); ?>})};})();</script>
	<?php return ob_get_clean();
} );
