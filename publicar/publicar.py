#!/usr/bin/env python3
"""Publica no site da Aquila (WordPress) as atualizações das ferramentas.

Usa a senha de aplicativo do WordPress guardada nos segredos do GitHub
(WP_USER e WP_APP_PASSWORD). Nada é publicado fora do site aquilaadvocacia.com.br.
"""
import base64, json, os, re, sys
import requests

SITE = 'https://aquilaadvocacia.com.br'
AUTH = (os.environ['WP_USER'], os.environ['WP_APP_PASSWORD'])
H = {'User-Agent': 'robo-aquila/1.0'}
DIR = os.path.dirname(os.path.abspath(__file__))


def get(pid):
    r = requests.get(f'{SITE}/wp-json/wp/v2/pages/{pid}?context=edit', auth=AUTH, headers=H, timeout=60)
    r.raise_for_status(); return r.json()


def post(path, body):
    r = requests.post(f'{SITE}/wp-json/wp/v2/{path}', auth=AUTH, headers=H, json=body, timeout=90)
    if r.status_code >= 300: raise SystemExit(f'Erro {r.status_code} em {path}: {r.text[:300]}')
    return r.json()


def b64js(raw):
    m = re.search(r'[A-Za-z0-9+/=]{3000,}', raw)
    if not m: raise SystemExit('Script da página não encontrado')
    return m.group(0), base64.b64decode(m.group(0)).decode('utf-8')


def enc(s): return base64.b64encode(s.encode('utf-8')).decode()


def ler(nome): return open(os.path.join(DIR, nome), encoding='utf-8').read()


def raiox():
    p = get(2127); raw = p['content']['raw']; b, s = b64js(raw)
    i = s.find("var NONCE='';"); j = s.find('function busca(')
    if 'var DADOS=' in s:
        i = s.find('var DADOS=');
    if i < 0 or j < 0 or j < i: raise SystemExit('Âncoras do Raio-X não encontradas')
    s = s[:i] + ler('raiox-dados.js') + s[j:]
    raw = raw.replace(b, enc(s))
    post('pages/2127', {'content': raw}); print('Raio-X atualizado')


def cut(raw, a, z, rep):
    i = raw.find(a); j = raw.find(z, i)
    if i < 0 or j < 0: raise SystemExit('Trecho não encontrado: ' + a[:40])
    return raw[:i] + rep + raw[j:]


def banco():
    ja = requests.get(f'{SITE}/wp-json/wp/v2/pages?slug=banco-de-precos&context=edit', auth=AUTH, headers=H, timeout=60).json()
    p = get(2166); raw = p['content']['raw']; b, _ = b64js(raw)
    raw = raw.replace(b, enc(ler('banco-de-precos.js'))).replace('id="aq-comp"', 'id="aq-bp"')
    raw = cut(raw, '<h1>Comparador de licitantes</h1>', '</div>', '<h1>Banco de preços</h1>\n<p class="lead">Quanto o governo está pagando? Pesquise qualquer material ou serviço e veja os preços praticados nas compras públicas: mediana, média, menor e maior valor, com órgão, fornecedor e data de cada compra. Dados oficiais do Compras.gov.br.</p>\n')
    fi = raw.find('<form class="panel sp" id="cp-form">'); fj = raw.find('</form>', fi) + 7
    if fi < 0: raise SystemExit('Formulário do modelo não encontrado')
    raw = raw[:fi] + '<div class="panel sp" id="bp-app"></div>' + raw[fj:]
    raw = cut(raw, 'Fontes: Receita Federal (via BrasilAPI), para o cadastro', '</p>', 'Fonte: Compras.gov.br, preços praticados e catálogo de materiais e serviços (CATMAT e CATSER), em dados abertos. A base reúne compras da União e de estados e municípios que usam o Compras.gov.br; compras feitas em outros portais podem não aparecer. Os valores são informativos e não substituem a pesquisa de preços formal do processo. Ferramenta de caráter informativo.')
    hi = raw.find('<section class="blk how">'); hj = raw.find('</section>', hi) + 10
    raw = raw[:hi] + '<section class="blk how">\n<div class="wrap split">\n<div>\n<p class="kicker">Para que serve</p>\n<h2>Preço de referência em segundos</h2>\n</div>\n<div>\n<ul class="forma">\n<li><b>Para montar a proposta.</b> Veja quanto o mercado público está pagando antes de dar o lance e evite preço acima do aceitável ou abaixo do exequível.</li>\n<li><b>Para contestar a desclassificação.</b> Proposta considerada inexequível? Compare com os preços praticados em outros órgãos e fundamente o recurso.</li>\n<li><b>Para impugnar o edital.</b> Preço de referência fora da realidade é motivo de impugnação. A mediana é o parâmetro da Lei 14.133 (art. 23, § 1º, I).</li>\n<li><b>Para o órgão público.</b> Pesquisa de preços com contratações similares do último ano (art. 23, § 1º, II).</li>\n</ul>\n</div>\n</div>\n</section>' + raw[hj:]
    raw = raw.replace('Encontrou indícios de ligação entre licitantes?', 'Proposta desclassificada por preço ou edital com preço fora da realidade?')
    body = {'title': 'Banco de preços: preços praticados em compras públicas', 'slug': 'banco-de-precos', 'status': 'publish', 'template': 'elementor_canvas', 'featured_media': 2206, 'content': raw,
            'excerpt': 'Pesquise materiais e serviços e veja os preços praticados nas compras públicas: mediana, média, menor e maior valor, com órgão, fornecedor e data.'}
    pg = post('pages/%d' % ja[0]['id'] if ja else 'pages', body); print('Banco de preços:', pg.get('link'))
    f = get(2057); fr = f['content']['raw']
    if '/banco-de-precos/' not in fr:
        i = fr.find('<a class="tool" href="/comparador-de-licitantes/">'); j = fr.find('</a>', i) + 4
        if i < 0: raise SystemExit('Cartão do comparador não encontrado')
        fr = fr[:j] + '<a class="tool" href="/banco-de-precos/"><span class="tag">Compras.gov.br · preços praticados</span><h2>Banco de preços</h2><p>Quanto o governo está pagando por um material ou serviço: mediana, média, menor e maior valor, com órgão, fornecedor e data de cada compra.</p><span class="go">Pesquisar preços →</span></a>' + fr[j:]
        post('pages/2057', {'content': fr}); print('Página de ferramentas atualizada')
    hm = get(1784); ed = json.loads(hm['meta']['_elementor_data'])
    def acha(a):
        for e in a:
            if e.get('widgetType') == 'html': return e
            r = acha(e.get('elements', []))
            if r: return r
    hw = acha(ed); hs = hw['settings']['html']
    if '/banco-de-precos/' not in hs:
        hs = hs.replace('<a href="/comparador-de-licitantes/">Comparar licitantes</a><a href="/buscador-de-licitacoes/">Buscar licitações abertas</a>', '<a href="/comparador-de-licitantes/">Comparar licitantes</a><a href="/banco-de-precos/">Pesquisar preços</a><a href="/buscador-de-licitacoes/">Buscar licitações abertas</a>')
        hs = re.sub(r'(<a[^>]*href="/repactuacao-e-reequilibrio/"[^>]*>Repactuação e reequilíbrio</a>)', r'\1<a href="/banco-de-precos/">Pesquisar preços</a>', hs, count=1)
        hw['settings']['html'] = hs
        post('pages/1784', {'meta': {'_elementor_data': json.dumps(ed, ensure_ascii=False)}}); print('Home atualizada')


def teste():
    r = requests.get(f'{SITE}/wp-json/wp/v2/users/me', auth=AUTH, headers=H, timeout=30)
    print('Acesso ao WordPress:', r.status_code, r.json().get('name') if r.ok else r.text[:200])
    if not r.ok: raise SystemExit(1)


if __name__ == '__main__':
    etapas = sys.argv[1:] or ['teste', 'raiox', 'banco']
    for e in etapas: globals()[e]()
