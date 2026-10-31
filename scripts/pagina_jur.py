import json,html
S=json.load(open('aquila/jur/situacoes.json'))
e=html.escape
SIN={'erro-na-planilha':'planilha custo custos formação preços erro omissão diligência desclassificado desclassificação bdi',
 'preco-inexequivel':'inexequível inexequibilidade preço baixo lance 75% 50% exequibilidade',
 'atestado-exigido':'atestado capacidade técnica qualificação experiência postos somatório habilitação inabilitado',
 'repactuacao-negada':'repactuação reajuste convenção coletiva cct dissídio aumento salário reequilíbrio prorrogação',
 'multa-ou-penalidade':'multa penalidade sanção impedimento inidoneidade suspensão notificação defesa processo'}
cards=''.join(f'<a class="jc" href="#{s["slug"]}" data-k="{e(s["titulo"]+" "+s["tema"]+" "+SIN[s["slug"]])}"><span>{e(s["tema"])}</span><b>{e(s["titulo"])}</b><i>{len(s["dec"])} decisões do TCU →</i></a>' for s in S)
secs=''
for s in S:
    decs=''.join(f'<li><p>{e(d["txt"])}</p><small><a href="{e(d["url"])}" target="_blank" rel="noopener">{e(d["ac"])}</a> · {e(d["data"])} · Rel. {e(d["rel"])}</small><details><summary>Ver o enunciado do TCU</summary><blockquote>{e(d["enunciado"])}</blockquote></details></li>' for d in s['dec'])
    nota=f'<p class="jn">{e(s["nota"])}</p>' if s.get('nota') else ''
    secs+=f'''<article class="js" id="{s["slug"]}"><p class="kicker">{e(s["tema"])}</p><h2>{e(s["titulo"])}</h2>
<div class="jr"><p class="jl">Resumo</p><p>{e(s["resumo"])}</p></div>
<div class="jg"><div><p class="jl">Na prática</p><ul>{''.join(f"<li>{e(p)}</li>" for p in s["pratica"])}</ul></div><div class="jp"><p class="jl">Prazo</p><p>{e(s["prazo"])}</p></div></div>
<p class="jl" style="margin-top:28px">O que o TCU decidiu</p>{nota}<ol class="jd">{decs}</ol>
<div class="jcta"><p><b>Está passando por isso?</b> Cada caso tem detalhes que mudam a estratégia. Fale com o escritório para uma análise do seu caso.</p><a class="btn btn-gold" href="/#contato">Falar com o escritório</a></div>
<p class="jtop"><a href="#topo-jur">↑ Voltar às situações</a></p></article>'''
CSS='''.aq .jur{padding:56px 0 96px}.aq .jur .wrap{max-width:980px}
.aq .jbusca{display:flex;gap:10px;margin:0 0 22px}.aq .jbusca input{flex:1;font:inherit;font-size:17px;padding:14px 16px;border:1px solid var(--rule);background:#fff;color:var(--ink)}
.aq .jgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.aq .jc{display:flex;flex-direction:column;gap:8px;padding:20px;border:1px solid var(--rule);background:#fff;text-decoration:none;color:var(--ink);transition:border-color .2s}.aq .jc:hover{border-color:var(--gold)}
.aq .jc span{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--gold)}.aq .jc b{font-family:'Libre Caslon Text',Georgia,serif;font-weight:400;font-size:21px;line-height:1.3}.aq .jc i{font-style:normal;font-size:14px;color:var(--slate);margin-top:auto}
.aq .jvazio{display:none;color:var(--slate);margin-top:14px}
.aq .js{padding-top:64px;margin-top:56px;border-top:1px solid var(--rule)}.aq .js h2{font-size:32px;margin:6px 0 20px}
.aq .jl{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);margin:0 0 8px;font-weight:600}
.aq .jr{background:var(--stone);padding:22px 24px;border-left:3px solid var(--gold)}.aq .jr p+p,.aq .js p{font-size:17px;line-height:1.7;color:#344155}
.aq .jg{display:grid;grid-template-columns:1.6fr 1fr;gap:24px;margin-top:24px}.aq .jg ul{padding-left:20px;margin:0}.aq .jg li{font-size:16px;line-height:1.6;color:#344155}.aq .jg li+li{margin-top:6px}
.aq .jp{border:1px solid var(--rule);padding:16px 18px;background:#fff}.aq .jp p:last-child{font-size:15px}
.aq .jn{font-size:14px!important;color:var(--slate)!important;font-style:italic}
.aq .jd{padding-left:22px;margin:10px 0 0}.aq .jd>li{padding:14px 0;border-bottom:1px solid var(--rule)}.aq .jd>li p{margin:0 0 6px;font-size:16.5px!important}
.aq .jd small{font-size:13.5px;color:var(--slate)}.aq .jd small a{color:var(--ink);font-weight:600}
.aq .jd details{margin-top:6px}.aq .jd summary{cursor:pointer;font-size:13.5px;color:var(--gold)}.aq .jd blockquote{margin:8px 0 0;padding:10px 14px;background:var(--paper);border-left:2px solid var(--mist);font-size:14.5px;line-height:1.65;color:#344155}
.aq .jcta{display:flex;gap:18px;align-items:center;justify-content:space-between;background:var(--ink);color:var(--paper);padding:22px 24px;margin-top:28px}.aq .jcta p{color:#C9D0D9!important;margin:0;font-size:16px!important}.aq .jcta b{color:#fff}
.aq .jtop{margin-top:14px;font-size:14px!important}.aq .jtop a{color:var(--slate)}
.aq .javiso{font-size:13.5px!important;color:var(--slate)!important;margin-top:48px}
@media(max-width:760px){.aq .jg{grid-template-columns:1fr}.aq .jcta{flex-direction:column;align-items:flex-start}.aq .js h2{font-size:26px}}'''
HERO='''<section class="pg-hero"><div class="wrap"><p class="crumbs"><a href="/">Início</a> / Jurisprudência</p><p class="kicker">Jurisprudência de licitações</p><h1>O que o TCU já decidiu sobre o seu problema</h1><p class="lead">Escolha a situação que você está vivendo e veja, em linguagem simples, o que diz o Tribunal de Contas da União, com o link para cada decisão.</p></div></section>'''
BODY=f'''<section class="jur" id="topo-jur"><div class="wrap"><div class="jbusca"><input type="search" id="jq" placeholder="Digite o seu problema. Ex.: planilha, atestado, multa, repactuação" aria-label="Buscar situação"></div>
<div class="jgrid" id="jgrid">{cards}</div><p class="jvazio" id="jvazio">Ainda não temos essa situação. <a href="/#contato">Conte o seu caso para o escritório.</a></p>
{secs}
<p class="javiso">Conteúdo informativo, elaborado a partir da Jurisprudência Selecionada do Tribunal de Contas da União (dados abertos). Os resumos não substituem a leitura das decisões nem a análise do caso concreto e não constituem parecer jurídico.</p></div></section>
<script>(function(){{var q=document.getElementById('jq');if(!q)return;function n(s){{return(s||'').toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g,'')}}q.addEventListener('input',function(){{var t=n(q.value).split(/\\s+/).filter(Boolean),v=0;document.querySelectorAll('#jgrid .jc').forEach(function(c){{var k=n(c.dataset.k),ok=t.every(function(w){{return k.indexOf(w)>-1||k.indexOf(w.replace(/s$/,''))>-1}});c.style.display=ok?'':'none';if(ok)v++}});document.getElementById('jvazio').style.display=v?'none':'block'}})}})();</script>'''
json.dump({'css':CSS,'hero':HERO,'body':BODY},open('aquila/jur/pagina.json','w'),ensure_ascii=False)
print(len(BODY))
