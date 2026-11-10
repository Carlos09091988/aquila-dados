#!/usr/bin/env python3
"""
Robô dos diários municipais (Desenvolve).
Todo dia: baixa as edições recentes das plataformas de diários municipais, extrai o texto,
separa os avisos de licitação e procura os termos (nomes e CNPJs) monitorados pelos clientes.
Envia SÓ os resultados para a plataforma (endpoints protegidos). Nada de cliente é gravado no repositório.

Variáveis: DV_ROBO_TOKEN (segredo), DV_SITE (https://desenvolveestrategias.com.br), DIAS (padrão 2), LIMITE_SEG.
"""
import concurrent.futures as cf, datetime as dt, gzip, hashlib, html, json, os, re, subprocess, sys, tempfile, time, unicodedata, urllib.parse, urllib.request

SITE = os.environ.get('DV_SITE', 'https://desenvolveestrategias.com.br').rstrip('/')
TOKEN = os.environ.get('DV_ROBO_TOKEN', '')
DIAS = int(os.environ.get('DIAS', '2'))
LIMITE = int(os.environ.get('LIMITE_SEG', '3000'))
T0 = time.time()
DADOS = os.path.join(os.path.dirname(__file__), '..', 'data', 'diarios')
os.makedirs(DADOS, exist_ok=True)
UA = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept-Language': 'pt-BR,pt;q=0.9', 'Accept-Encoding': 'gzip'}
HOJE = dt.date.today()
DATAS = {(HOJE - dt.timedelta(days=i)).isoformat() for i in range(DIAS)}
LOG = []


def log(*a):
    s = ' '.join(str(x) for x in a); print(s, flush=True); LOG.append(s)


def get(u, timeout=40, binario=False, tent=2):
    for i in range(tent):
        try:
            r = urllib.request.urlopen(urllib.request.Request(u, headers=UA), timeout=timeout)
            b = r.read()
            if r.headers.get('Content-Encoding') == 'gzip':
                b = gzip.decompress(b)
            return b if binario else b.decode('utf-8', 'replace')
        except Exception as e:
            if i == tent - 1:
                return None
            time.sleep(2)


def tempo_ok():
    return time.time() - T0 < LIMITE


def norm(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().upper()
    return re.sub(r'[^A-Z0-9]+', ' ', s).strip()


def pdf_texto(b, limite=180):
    with tempfile.NamedTemporaryFile(suffix='.pdf') as f:
        f.write(b); f.flush()
        try:
            return subprocess.run(['pdftotext', '-enc', 'UTF-8', f.name, '-'], capture_output=True, timeout=limite).stdout.decode('utf-8', 'replace')
        except Exception:
            return ''


# ------------------------------------------------------------------ fontes
SIGPUB = {'amm-mg': 'MG', 'famurs': 'RS', 'amp': 'PR', 'amupe': 'PE', 'femurn': 'RN', 'amm-mt': 'MT', 'famup': 'PB', 'agm': 'GO', 'fgm': 'GO', 'aprece': 'CE',
          'famep': 'PA', 'ama': 'AL', 'aam': 'AM', 'ms': 'MS', 'arom': 'RO', 'appm': 'PI', 'amr': 'RR', 'amurc': 'BA', 'sergipe': 'SE', 'aemerj': 'RJ', 'apm': 'SP', 'bahia': 'BA'}


def docs_sigpub():
    """Edições do dia de cada associação (um PDF com todas as prefeituras). Avisos já vêm pela busca ao vivo; aqui interessa o CNPJ."""
    out = []
    for ent, uf in SIGPUB.items():
        h = get('https://www.diariomunicipal.com.br/%s/' % ent)
        if not h:
            log('sigpub falhou', ent); continue
        for u in sorted(set(re.findall(r'https://www-storage\.voxtecnologia\.com\.br/\?[^"\' <>]+?\.pdf(?=["\'])', h))):
            u = html.unescape(u)
            m = re.search(r'publicado_\d+_(\d{4}-\d{2}-\d{2})_', u)
            if m and m.group(1) in DATAS:
                out.append({'fonte': 'Diário Oficial dos Municípios (%s)' % ent.upper(), 'plat': 'sigpub', 'ent': ent, 'uf': uf, 'municipio': '', 'data': m.group(1), 'url': u, 'link': 'https://www.diariomunicipal.com.br/%s/' % ent})
    return out


def dosp_entidades():
    """Lista de cidades da plataforma DiOE/DOSP (descoberta semanal; guardada em data/diarios/dosp.json)."""
    arq = os.path.join(DADOS, 'dosp2.json')
    try:
        c = json.load(open(arq))
        if time.time() - c.get('quando', 0) < 7 * 86400 and c.get('ent'):
            return c['ent']
    except Exception:
        c = {}
    ent = {}

    def sonda(i):
        t = get('https://dosp.com.br/api/index.php/dioe.js/%d?callback=d' % i, timeout=30, tent=1)
        if not t or '"data"' not in t:
            return None
        m = re.search(r'dosp\.com\.br\\?/diario\\?/([a-z]{2})\\?/([a-z0-9\-]+)\\?/', t)
        ult = re.search(r'"data":\s*"(\d{4}-\d{2}-\d{2})"', t)
        return (i, m.group(1).upper(), m.group(2), ult.group(1) if ult else '') if m else None
    with cf.ThreadPoolExecutor(24) as ex:
        for r in ex.map(sonda, range(int(os.environ.get('DOSP_DE', '1')), int(os.environ.get('DOSP_ATE', '9000')))):
            if r and r[3] >= (HOJE - dt.timedelta(days=120)).isoformat():
                ent[str(r[0])] = {'uf': r[1], 'slug': r[2]}
    if ent:
        json.dump({'quando': time.time(), 'ent': ent}, open(arq, 'w'), ensure_ascii=False, indent=0)
    log('dosp entidades', len(ent))
    return ent or c.get('ent', {})


def docs_dosp():
    out = []; ent = dosp_entidades()

    def ed(item):
        i, e = item
        t = get('https://dosp.com.br/api/index.php/dioe.js/%s?callback=d' % i, timeout=40)
        if not t:
            return []
        r = []
        for m in re.finditer(r'"data":\s*"(\d{4}-\d{2}-\d{2})".{0,600}?"link":\s*"([^"]+\.pdf)"', t, re.S):
            if m.group(1) in DATAS:
                u = m.group(2).replace('\\/', '/')
                mun = e['slug'].replace('-', ' ').title()
                r.append({'fonte': 'Diário Oficial de %s (DiOE)' % mun, 'plat': 'dosp', 'uf': e['uf'], 'municipio': mun, 'data': m.group(1), 'url': u, 'link': u})
        return r
    with cf.ThreadPoolExecutor(12) as ex:
        for r in ex.map(ed, list(ent.items())):
            out += r
    return out


def docs_famem():
    out = []
    t = get('https://diariooficial.famem.org.br/dom/dom/pesquisaEdicoes/')
    try:
        j = json.loads(t or '{}')
    except Exception:
        j = {}
    for x in j.get('data', []):
        d = str(x.get('TDO_DT_GERACAO', ''))[:10]; arq = x.get('TDO_ASSINADO_ARQUIVO') or ''
        if d in DATAS and arq.endswith('.pdf'):
            u = 'https://painel.siganet.net.br/upload/0000000002/cms/publicacoes/diario/' + arq
            out.append({'fonte': 'Diário Oficial dos Municípios do Maranhão (FAMEM)', 'plat': 'famem', 'uf': 'MA', 'municipio': '', 'data': d, 'url': u, 'link': u})
    return out


def docs_dompi():
    """Diário Oficial dos Municípios do Piauí: edições numeradas em PDF (uma por dia útil, ~100 prefeituras por edição)."""
    arq = os.path.join(DADOS, 'dompi.json')
    try:
        st = json.load(open(arq))
    except Exception:
        st = {'ult': 5685}
    out = []; n = st['ult']; falhas = 0
    while falhas < 4 and len(out) < 6:
        n += 1
        u = 'https://www.diarioficialdosmunicipios.org/intranet/_lib/file/doc/pdfs/novo2/%d/DM_%d.pdf' % (n, n)
        try:
            r = urllib.request.urlopen(urllib.request.Request(u, headers=UA, method='HEAD'), timeout=40)
            ok = r.status == 200 and 'pdf' in (r.headers.get('Content-Type') or '')
        except Exception:
            ok = False
        if ok:
            out.append({'fonte': 'Diário Oficial dos Municípios do Piauí (ed. %d)' % n, 'plat': 'dompi', 'uf': 'PI', 'municipio': '', 'data': HOJE.isoformat(), 'url': u, 'link': u, 'ed': n})
            st['ult'] = n; falhas = 0
        else:
            falhas += 1
    json.dump(st, open(arq, 'w'))
    return out


# ---- Plataformas de diário por cidade (DOEM e Atende.net) -------------------------------
# Muitas prefeituras não publicam no diário da associação: contratam uma empresa de diário
# eletrônico, e cada empresa publica todas as suas cidades no mesmo formato. Descobrimos uma
# vez por semana quais municípios do IBGE existem em cada plataforma (data/diarios/doem.json e
# atende.json) e, todo dia, baixamos só as edições novas dessas cidades.
# Formatos de página conferidos nos coletores abertos do Querido Diário (MIT, okfn-brasil).
DOEM_UFS = ['BA', 'SE', 'PE', 'AL', 'PI', 'MA', 'CE', 'PB', 'RN', 'PR', 'MG', 'TO', 'GO', 'RJ', 'SP', 'ES', 'MT', 'MS', 'PA']
ATENDE_UFS = ['PR', 'RS', 'SC', 'SP', 'MG', 'GO', 'MS']
MESES = {'JANEIRO': 1, 'FEVEREIRO': 2, 'MARCO': 3, 'ABRIL': 4, 'MAIO': 5, 'JUNHO': 6, 'JULHO': 7, 'AGOSTO': 8, 'SETEMBRO': 9, 'OUTUBRO': 10, 'NOVEMBRO': 11, 'DEZEMBRO': 12}


def slug_cidade(nome):
    return re.sub(r'[^a-z0-9]', '', unicodedata.normalize('NFKD', nome).encode('ascii', 'ignore').decode().lower())


def data_texto(t):
    """'05/11/2026', '2026-11-05' ou '5 de novembro de 2026' -> '2026-11-05'"""
    t = t or ''
    m = re.search(r'(\d{1,2})/(\d{1,2})/(\d{4})', t)
    if m:
        return '%s-%02d-%02d' % (m.group(3), int(m.group(2)), int(m.group(1)))
    m = re.search(r'(\d{4})-(\d{2})-(\d{2})', t)
    if m:
        return m.group(0)
    m = re.search(r'(\d{1,2})\s+de\s+([A-Za-zçÇ]+)\s+de\s+(\d{4})', t)
    if m and norm(m.group(2)) in MESES:
        return '%s-%02d-%02d' % (m.group(3), MESES[norm(m.group(2))], int(m.group(1)))
    return ''


def ibge_municipios(ufs):
    """Municípios do IBGE (lista guardada em data/diarios/municipios_ibge.csv, do Querido Diário; API do IBGE como reserva)."""
    out = []
    try:
        import csv
        with open(os.path.join(DADOS, 'municipios_ibge.csv'), encoding='utf-8') as f:
            out = [(r['state_code'], r['name']) for r in csv.DictReader(f) if r['state_code'] in ufs]
    except Exception as e:
        log('lista de municípios erro', str(e)[:120])
    if out:
        return out
    for uf in ufs:
        t = get('https://servicodados.ibge.gov.br/api/v1/localidades/estados/%s/municipios' % uf, timeout=60)
        try:
            out += [(uf, x['nome']) for x in json.loads(t or '[]')]
        except Exception:
            log('ibge erro', uf)
    if not out:
        log('nenhum município carregado')
    return out


def doem_url(uf, slug, d):
    return 'https://doem.org.br/%s/%s/diarios/%d/%02d' % (uf.lower(), slug, d.year, d.month)


def doem_edicoes(h):
    """Pares (data, link do PDF) de uma página mensal do DOEM."""
    r = []
    for bloco in re.split(r'class="[^"]*box-diario', h or '')[1:]:
        bloco = bloco[:4000]
        a = re.search(r'<a[^>]+title="Baixar Publica[^"]*"[^>]*href="([^"]+)"', bloco) or re.search(r'<a[^>]+href="([^"]+)"[^>]*title="Baixar Publica', bloco)
        dt_ = re.search(r'class="[^"]*data-diario[^"]*"[^>]*>([^<]+)<', bloco)
        if a and dt_:
            d = data_texto(html.unescape(dt_.group(1)))
            if d:
                r.append((d, urllib.parse.urljoin('https://doem.org.br/', html.unescape(a.group(1)).replace('previsualizar', 'baixar'))))
    return r


ATENDE_PARAMS = {'rot': '54015', 'aca': '101', 'ajax': 't', 'processo': 'loadPluginDiarioOficial', 'parametro': '{"codigoPlugin":1,"filtroPlugin":{"pagina":"1"}}'}


def atende_url(sub):
    return 'https://%s.atende.net/diariooficial/edicao/pagina/atende.php?%s' % (sub, urllib.parse.urlencode(ATENDE_PARAMS))


def atende_edicoes(h, sub):
    r = []
    for bloco in re.split(r'class="[^"]*\blinha\b', h or '')[1:]:
        bloco = bloco[:5000]
        dt_ = re.search(r'class="[^"]*\bdata\b[^"]*"[^>]*>([^<]+)<', bloco)
        links = re.findall(r'data-link="([^"]+)"', bloco)
        if dt_ and links:
            d = data_texto(html.unescape(dt_.group(1)))
            if d:
                r.append((d, urllib.parse.urljoin('https://%s.atende.net/' % sub, html.unescape(links[-1]))))
    return r


def descobrir(arq, plat, ufs, testa):
    """Lista semanal das cidades que publicam em uma plataforma (guardada em data/diarios)."""
    caminho = os.path.join(DADOS, arq)
    try:
        c = json.load(open(caminho))
        if time.time() - c.get('quando', 0) < 7 * 86400 and c.get('cidades'):
            return c['cidades']
    except Exception:
        c = {}
    muns = ibge_municipios(ufs)
    if not muns:
        return c.get('cidades', [])
    achadas = []
    with cf.ThreadPoolExecutor(24) as ex:
        for uf, nome, ok in ex.map(lambda x: (x[0], x[1], testa(x[0], slug_cidade(x[1]))), muns):
            if ok:
                achadas.append([uf, slug_cidade(nome), nome])
    log(plat, 'descoberta:', len(achadas), 'cidades de', len(muns), 'municípios testados')
    if achadas:
        json.dump({'quando': time.time(), 'cidades': sorted(achadas)}, open(caminho, 'w'), ensure_ascii=False, indent=0)
    return achadas or c.get('cidades', [])


def doem_testa(uf, slug):
    for d in (HOJE, HOJE.replace(day=1) - dt.timedelta(days=1), HOJE.replace(day=1) - dt.timedelta(days=40)):
        h = get(doem_url(uf, slug, d), timeout=25, tent=1)
        if h is None:
            return False
        if doem_edicoes(h):
            return True
    return False


def atende_testa(uf, slug):
    h = get(atende_url(slug), timeout=20, tent=1)
    return bool(h) and bool(atende_edicoes(h, slug))


def docs_cidades(plat, arq, ufs, testa, edicoes_de):
    amostra = {'doem': ('BA', 'catu'), 'atende': ('PR', 'castro'), 'instar': ('MG', 'campobelo'), 'adiarios': ('MA', 'anajatuba'), 'diof': ('BA', 'adustina'), 'dobr': ('TO', 'camposlindos'), 'munonline': ('SE', 'aquidaba'), 'ptio': ('RJ', 'areal')}.get(plat)
    if amostra:
        log(plat, 'teste com', amostra, '->', testa(*amostra))
    cidades = descobrir(arq, plat, ufs, testa)
    meses = {(d.year, d.month) for d in (dt.date.fromisoformat(x) for x in DATAS)}
    out = []

    def ed(c):
        uf, slug, nome = c; r = []
        for ano, mes in sorted(meses):
            for d, link in edicoes_de(uf, slug, dt.date(ano, mes, 1)):
                if d in DATAS:
                    r.append({'fonte': 'Diário Oficial de %s' % nome, 'plat': plat, 'uf': uf, 'municipio': nome, 'data': d, 'url': link, 'link': link})
        return r
    with cf.ThreadPoolExecutor(16) as ex:
        for r in ex.map(ed, cidades):
            out += r
    log(plat, 'cidades', len(cidades), 'edições no período', len(out))
    return out


def docs_doem():
    return docs_cidades('doem', 'doem.json', DOEM_UFS, doem_testa, lambda uf, slug, d: doem_edicoes(get(doem_url(uf, slug, d), timeout=30)))


def docs_atende():
    # a página 1 traz as edições mais recentes; basta para o período do robô (2 dias)
    return docs_cidades('atende', 'atende.json', ATENDE_UFS, atende_testa, lambda uf, slug, d: atende_edicoes(get(atende_url(slug), timeout=30), slug) if d.month == HOJE.month else [])


# ---- Mais plataformas de diário por cidade (09/11/2026) ------------------------------------
# Instar, ADiários v1, DIOF/SAI, DiárioOficialBR, Município Online e Portal da Transparência (PTIO).
# Mesma ideia do DOEM/Atende: descoberta semanal em todos os municípios do IBGE e, todo dia,
# só as edições novas. Formatos conferidos nos coletores abertos do Querido Diário (MIT).
TODAS_UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO']


def post_json(u, dados, headers=None, timeout=40):
    try:
        h = dict(UA); h.update({'Content-Type': 'application/json', 'Accept': 'application/json'}); h.update(headers or {})
        r = urllib.request.urlopen(urllib.request.Request(u, data=json.dumps(dados).encode(), headers=h), timeout=timeout)
        b = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            b = gzip.decompress(b)
        return json.loads(b.decode('utf-8', 'replace'))
    except Exception:
        return None


def br(d):
    return d.strftime('%d/%m/%Y')


def fim_mes(d):
    return (d.replace(day=28) + dt.timedelta(days=4)).replace(day=1) - dt.timedelta(days=1)


# Instar: https://www.{cidade}.{uf}.gov.br/portal/diario-oficial
def instar_base(uf, slug):
    return 'https://www.%s.%s.gov.br/portal/diario-oficial' % (slug, uf.lower())


def instar_testa(uf, slug):
    h = get(instar_base(uf, slug), timeout=15, tent=1)
    return bool(h) and ('dof_publicacao_diario' in h or 'sw_qtde_resultados' in h)


def instar_edicoes(uf, slug, d):
    b = instar_base(uf, slug); ini = max(d, min(dt.date.fromisoformat(x) for x in DATAS)); fim = min(fim_mes(d), HOJE)
    h = get('%s/1/%s/%s/0/0/' % (b, ini.strftime('%d-%m-%Y'), fim.strftime('%d-%m-%Y')), timeout=30)
    r = []
    for bl in re.split(r'class="dof_publicacao_diario', h or '')[1:]:
        dd = data_texto(re.sub(r'<[^>]+>', ' ', bl[:9000])); a = re.search(r'href="([^"]+)"', bl)
        if not dd or not a:
            continue
        e = get(urllib.parse.urljoin(b + '/', html.unescape(a.group(1))), timeout=30)
        m = re.search(r'["\']((?:https?://[^"\']+)?/uploads/[^"\']+\.pdf)["\']', e or '') or re.search(r'class="d_titulo_edicao[^"]*"[^>]*>.*?href="([^"]+)"', e or '', re.S)
        if m:
            r.append((dd, urllib.parse.urljoin(b + '/', html.unescape(m.group(1)))))
    return r


# ADiários v1: https://www.{cidade}.{uf}.gov.br/diariooficial.php
def adiarios_base(uf, slug):
    return 'https://www.%s.%s.gov.br' % (slug, uf.lower())


def adiarios_testa(uf, slug):
    h = get(adiarios_base(uf, slug) + '/diariooficial.php', timeout=15, tent=1)
    return bool(h) and ('diario_lista' in h or 'calendarioIcon' in h)


def adiarios_edicoes(uf, slug, d):
    b = adiarios_base(uf, slug); fim = min(fim_mes(d), HOJE)
    h = get('%s/diariooficial.php?%s' % (b, urllib.parse.urlencode({'dtini': br(d), 'dtfim': br(fim)})), timeout=30)
    r = []
    for bl in re.split(r'id="diario_lista"', h or '')[1:]:
        dd = data_texto(bl[:800]); m = re.search(r'id=(\d+)', bl)
        if dd and m:
            r.append((dd, '%s/arquivos_download.php?id=%s&pg=diariooficial' % (b, m.group(1))))
    return r


# DIOF/SAI: https://diario.{cidade}.{uf}.gov.br (API diof.io.org.br) ou sai.io.org.br/{uf}/{cidade}
DIOF_API = 'https://diof.io.org.br/api'
_diof_cli = {}


def diof_cliente(uf, slug):
    k = (uf, slug)
    if k in _diof_cli:
        return _diof_cli[k]
    c = None
    site = 'https://diario.%s.%s.gov.br' % (slug, uf.lower())
    try:
        h = dict(UA); h.update({'Accept': 'application/json, text/plain, */*', 'Origin': site, 'Referer': site + '/'})
        r = urllib.request.urlopen(urllib.request.Request(DIOF_API + '/dados-cliente/info/', headers=h), timeout=15)
        b = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            b = gzip.decompress(b)
        c = json.loads(b.decode()).get('cod_cliente')
    except Exception:
        c = None
    if not c:
        t = get('https://sai.io.org.br/%s/%s/site/diariooficial' % (uf.lower(), slug), timeout=15, tent=1)
        m = re.search(r'<iframe[^>]+src="[^"]*?(\d{2,})', t or '')
        c = m.group(1) if m else None
    _diof_cli[k] = c
    return c


def diof_testa(uf, slug):
    return bool(diof_cliente(uf, slug))


def diof_edicoes(uf, slug, d):
    c = diof_cliente(uf, slug)
    if not c:
        return []
    site = 'https://diario.%s.%s.gov.br' % (slug, uf.lower())
    j = post_json(DIOF_API + '/diario-oficial/edicoes-anteriores-group', headers={'Origin': site, 'Referer': site + '/'}, dados={'cod_cliente': str(c), 'dat_envio_ini': d.isoformat(), 'dat_envio_fim': min(fim_mes(d), HOJE).isoformat(), 'des_observacao': '', 'edicao': None, 'page': 1}) or {}
    r = []
    for g in j.get('data', []) or []:
        for e in g.get('elements', []) or []:
            if e.get('des_arquivoa4') and e.get('dat_envio'):
                r.append((str(e['dat_envio'])[:10], '%s/diario-oficial/download/%s.pdf' % (DIOF_API, e['des_arquivoa4'])))
    return r


# DiárioOficialBR: https://{cidade}.diariooficialbr.com.br
def dobr_testa(uf, slug):
    h = get('https://%s.diariooficialbr.com.br/' % slug, timeout=15, tent=1)
    return bool(h) and ('card-downloads' in h or '/pesquisa' in h) and uf.lower() in (h or '').lower()


def dobr_edicoes(uf, slug, d):
    b = 'https://%s.diariooficialbr.com.br' % slug
    h = get('%s/pesquisa/search?initDate=%s&endDate=%s' % (b, d.isoformat(), min(fim_mes(d), HOJE).isoformat()), timeout=30)
    r = []
    for bl in re.split(r'card-downloads', h or '')[1:]:
        dd = data_texto(re.sub(r'<[^>]+>', ' ', bl[:3000])); a = re.search(r'href="([^"]*/download[^"]*)"', bl)
        if dd and a:
            r.append((dd, urllib.parse.urljoin(b + '/', html.unescape(a.group(1)))))
    return r


# Município Online: https://www.municipioonline.com.br/{uf}/prefeitura/{cidade}/cidadao/diariooficial
def mo_url(uf, slug):
    return 'https://www.municipioonline.com.br/%s/prefeitura/%s/cidadao/diariooficial' % (uf.lower(), slug)


def mo_testa(uf, slug):
    h = get(mo_url(uf, slug), timeout=15, tent=1)
    return bool(h) and 'panel-title' in h


def mo_edicoes(uf, slug, d):
    if d.month != HOJE.month:
        return []
    u = mo_url(uf, slug); h = get(u, timeout=30)  # a página traz as edições mais recentes
    r = []
    for bl in re.split(r'class="panel[ "]', h or '')[1:]:
        dd = data_texto(re.sub(r'<[^>]+>', ' ', bl[:1500])); m = re.search(r"l=([^'\"]+)'", bl)
        if dd and m:
            r.append((dd, u + '/diario?n=diario.pdf&l=' + m.group(1)))
    return r


# Portal da Transparência (PTIO): http://{uf}.portaldatransparencia.com.br/prefeitura/{cidade}/
def ptio_url(uf, slug):
    return 'http://%s.portaldatransparencia.com.br/prefeitura/%s/' % (uf.lower(), slug)


def ptio_testa(uf, slug):
    h = get(ptio_url(uf, slug), timeout=15, tent=1)
    if not h or not re.search(r"class=['\"]edicoes['\"]", h):
        return False
    return slug in re.sub(r'[^a-z0-9]', '', norm(re.sub(r'<[^>]+>', ' ', h[:20000])).lower()) and bool(ptio_edicoes_html(ptio_url(uf, slug), h))


def ptio_edicoes(uf, slug, d):
    if d.month != HOJE.month:
        return []
    u = ptio_url(uf, slug)
    return ptio_edicoes_html(u, get(u, timeout=30))


_ptio_log = [0]


def ptio_edicoes_html(u, h):
    r = []
    if _ptio_log[0] < 3 and h:
        _ptio_log[0] += 1
        bl = (re.split(r"class=['\"]edicoes['\"]", h) + ['', ''])[1]
        log('ptio amostra', u, re.sub(r'\s+', ' ', bl[:600]))
    for bl in re.split(r"class=['\"]edicoes['\"]", h or '')[1:]:
        dd = data_texto(re.sub(r'<[^>]+>', ' ', bl[:1500])); m = re.search(r"href=['\"]([^'\"]*\?[^'\"]+)['\"]", bl)
        if dd and m:
            sub = html.unescape(m.group(1)); r.append((dd, urllib.parse.urljoin(u, sub[sub.index('?'):])))
    return r


# DIOF/IO por código de cliente (diario.io.org.br/{cod}): descobre os clientes ativos testando os códigos
# direto na consulta pública de edições; o município e a UF saem do texto da edição.
UF_NOME = {'ACRE': 'AC', 'ALAGOAS': 'AL', 'AMAZONAS': 'AM', 'AMAPA': 'AP', 'BAHIA': 'BA', 'CEARA': 'CE', 'ESPIRITO SANTO': 'ES', 'GOIAS': 'GO', 'MARANHAO': 'MA', 'MINAS GERAIS': 'MG', 'MATO GROSSO DO SUL': 'MS', 'MATO GROSSO': 'MT', 'PARA': 'PA', 'PARAIBA': 'PB', 'PERNAMBUCO': 'PE', 'PIAUI': 'PI', 'PARANA': 'PR', 'RIO DE JANEIRO': 'RJ', 'RIO GRANDE DO NORTE': 'RN', 'RONDONIA': 'RO', 'RORAIMA': 'RR', 'RIO GRANDE DO SUL': 'RS', 'SANTA CATARINA': 'SC', 'SERGIPE': 'SE', 'SAO PAULO': 'SP', 'TOCANTINS': 'TO'}


def uf_do_texto(t):
    m = re.search(r'ESTADO D[AOE] (MATO GROSSO DO SUL|RIO GRANDE DO NORTE|RIO GRANDE DO SUL|RIO DE JANEIRO|MINAS GERAIS|SANTA CATARINA|ESPIRITO SANTO|SAO PAULO|MATO GROSSO|[A-Z]+)', norm(t[:6000]))
    return UF_NOME.get(m.group(1)) if m else None


def io_eds(cod, ini, fim):
    j = post_json(DIOF_API + '/diario-oficial/edicoes-anteriores-group', {'cod_cliente': str(cod), 'dat_envio_ini': ini.isoformat(), 'dat_envio_fim': fim.isoformat(), 'des_observacao': '', 'edicao': None, 'page': 1}, timeout=25) or {}
    r = []
    for g in j.get('data', []) or []:
        for e in g.get('elements', []) or []:
            if e.get('des_arquivoa4') and e.get('dat_envio'):
                r.append((str(e['dat_envio'])[:10], '%s/diario-oficial/download/%s.pdf' % (DIOF_API, e['des_arquivoa4'])))
    return r


def docs_io():
    arq = os.path.join(DADOS, 'io.json')
    try:
        st = json.load(open(arq))
    except Exception:
        st = {'quando': 0, 'clientes': []}
    if time.time() - st.get('quando', 0) > 7 * 86400 and tempo_ok():
        ini = HOJE - dt.timedelta(days=45)
        ja = {}
        with cf.ThreadPoolExecutor(8) as ex:
            for c, eds in zip(range(1, 1601), ex.map(lambda c: io_eds(c, ini, HOJE), range(1, 1601))):
                if eds:
                    ja[c] = eds
        ativos = sorted(ja)
        st = {'quando': time.time(), 'clientes': ativos, '_eds': ja}
        json.dump({'quando': st['quando'], 'clientes': ativos}, open(arq, 'w'))
        log('io descoberta: clientes ativos', len(ativos))
    ini = min(dt.date.fromisoformat(x) for x in DATAS)
    out = []
    def eds_de(c):
        if c in st.get('_eds', {}):
            return st['_eds'][c]
        for _ in range(3):
            r = io_eds(c, ini, HOJE)
            if r:
                return r
            time.sleep(1)
        return []
    with cf.ThreadPoolExecutor(4) as ex:
        for cod, eds in zip(st['clientes'], ex.map(eds_de, st['clientes'])):
            for d, u in eds:
                if d in DATAS:
                    out.append({'fonte': 'Diário Oficial (IO, cliente %s)' % cod, 'plat': 'io', 'uf': '', 'municipio': '', 'data': d, 'url': u, 'link': u})
    log('io clientes', len(st['clientes']), 'edições no período', len(out))
    return out


PLATAFORMAS = {
    'instar': (instar_testa, instar_edicoes),
    # 'adiarios': o download das edições exige verificação anti-robô (captcha); não contornamos.
    'diof': (diof_testa, diof_edicoes),
    'dobr': (dobr_testa, dobr_edicoes),
    'munonline': (mo_testa, mo_edicoes),
    'ptio': (ptio_testa, ptio_edicoes),
}


def docs_plataforma(plat):
    testa, eds = PLATAFORMAS[plat]
    return docs_cidades(plat, plat + '.json', TODAS_UFS, testa, eds)


# ------------------------------------------------------------------ texto -> atos
RX_MUN = re.compile(r'(?:PREFEITURA\s+MUNICIPAL\s+D[EOA]S?|MUNIC[IÍ]PIO\s+D[EOA]S?|C[AÂ]MARA\s+MUNICIPAL\s+D[EOA]S?)\s+([A-ZÀ-Ü][A-ZÀ-Ü\'\- ]{2,40}?)(?:\s*[-–/]\s*[A-Z]{2}\b|\s*\n|\s{2,}|,)', re.I)
RX_QUER = re.compile(r'AVISO D[EO] (LICITACAO|PREGAO|ABERTURA|DISPENSA|CHAMAMENTO|EDITAL|REABERTURA|CONCORRENCIA)|AVISOS DE LICITACAO|PREGAO (ELETRONICO|PRESENCIAL)|CONCORRENCIA (ELETRONICA|PUBLICA)|TOMADA DE PRECOS|DISPENSA ELETRONICA|CHAMAMENTO PUBLICO|EDITAL DE (LICITACAO|PREGAO)|CREDENCIAMENTO')
RX_NAO = re.compile(r'HOMOLOG|ADJUDICA|EXTRATO|RATIFICA|RESULTADO|REVOGA|ANULA|ADITIVO|APOSTILA|RESCIS|ATA DE REGISTRO|PORTARIA|DECRETO|LEI N|JULGAMENTO|DESERT|FRACASS|SUSPENS|CONVOCACAO|NOTIFICA|CONTRATO N')


def atos(texto, doc):
    """Divide a edição em atos. SIGPub/Siganet terminam cada ato com 'Código Identificador'."""
    partes = re.split(r'C[oó]digo\s+Identificador\s*:?\s*([A-Z0-9]{6,12})', texto)
    r = []
    if doc['plat'] == 'dompi':
        pos = [m.start() for m in re.finditer(r'(?m)^[ \t]*(?:PREFEITURA|C[AÂ]MARA)\s+MUNICIPAL\s+D[EOA]', texto)]
        pos = [0] + pos + [len(texto)]
        r = [(texto[pos[i]:pos[i + 1]], 'a%d' % i) for i in range(len(pos) - 1)]
    elif len(partes) > 2:
        for i in range(0, len(partes) - 1, 2):
            r.append((partes[i], partes[i + 1]))
        if partes[-1].strip():
            r.append((partes[-1], ''))
    else:
        for i, pg in enumerate(texto.split('\f')):
            r.append((pg, 'p%d' % (i + 1)))
    out = []; mun_atual = doc.get('municipio', '')
    for t, cod in r:
        t = t.strip()
        if len(t) < 40:
            continue
        m = RX_MUN.search(t[:600])
        mun = m.group(1).strip().title() if m and not doc.get('municipio') else mun_atual
        if m and not doc.get('municipio'):
            mun_atual = mun
        link = doc['link']
        if doc['plat'] == 'sigpub' and cod and not cod.startswith('p'):
            link = 'https://www.diariomunicipal.com.br/%s/materia/%s' % (doc['ent'], cod)
        out.append({'texto': t, 'municipio': mun, 'link': link, 'cod': cod})
    return out


RX_TIPO_ATO = (
    ('Penalidade', re.compile(r'PENALIDADE|APLICACAO DE (A )?MULTA|APLICA(R|CAO DA?) (SANCAO|ADVERTENCIA|PENALIDADE)|IMPEDIMENTO DE LICITAR|DECLARACAO DE INIDONEIDADE|SUSPENSAO TEMPORARIA|PROCESSO (ADMINISTRATIVO )?SANCIONATORIO|NOTIFICACAO (PARA DEFESA|EXTRAJUDICIAL)')),
    ('Rescisão', re.compile(r'RESCISAO (UNILATERAL|CONTRATUAL|DO CONTRATO|AMIGAVEL)|DISTRATO')),
    ('Aditivo', re.compile(r'TERMO ADITIVO|ADITIVO (N|AO CONTRATO|DE PRAZO|DE VALOR)|APOSTILAMENTO|REAJUSTE|REPACTUACAO|PRORROGACAO D[OE] (CONTRATO|PRAZO)')),
    ('Extrato de contrato', re.compile(r'EXTRATO D[OE] (CONTRATO|TERMO|ATA)|EXTRATO CONTRAT|CONTRATO N[O ]?\s*\d|CONTRATO ADMINISTRATIVO N')),
    ('Ata de registro de preços', re.compile(r'ATA DE REGISTRO DE PRECOS?')),
    ('Homologação', re.compile(r'HOMOLOGACAO|HOMOLOGO|ADJUDICACAO|ADJUDICO|RESULTADO (DA|DE|DO) (LICITACAO|JULGAMENTO|PREGAO)|TERMO DE HOMOLOGACAO')),
    ('Dispensa / inexigibilidade', re.compile(r'RATIFICACAO|RATIFICO|EXTRATO DE DISPENSA|DISPENSA DE LICITACAO|INEXIGIBILIDADE')),
)


def tipo_contratacao(t):
    n = norm(t[:400])
    for nome, rx in RX_TIPO_ATO:
        if rx.search(n):
            return nome
    return None


RX_ORGAO = re.compile(r'(PREFEITURA|MUNICIPIO D|CAMARA MUNICIPAL|CAMARA DE VEREADORES|FUNDO MUNICIPAL|SECRETARIA MUNICIPAL|INSTITUTO DE PREVIDENCIA|SERVICO AUTONOMO|AUTARQUIA|CONSORCIO INTERMUNICIPAL|FUNDACAO MUNICIPAL|PODER EXECUTIVO|PODER LEGISLATIVO)')
RX_EMPRESA = re.compile(r'\b(LTDA|EIRELI|S A|SA|ME|EPP|MEI|EMPRESA|CONTRATAD[AO]|FORNECEDOR|DETENTOR[AE]|VENCEDOR[AE]|ADJUDICATARI[AO]|COMERCIO|SERVICOS|CONSTRUTORA|INDUSTRIA)\b')
RX_CNPJ = re.compile(r'\b\d{2}\.\d{3}\.\d{3}\s*/\s*\d{4}\s*-\s*\d{2}\b')


def trecho(t, i, j):
    return re.sub(r'\s+', ' ', t[max(0, i - 300):j + 300]).strip()


# ------------------------------------------------------------------ principal
def post(rota, itens):
    if not TOKEN or not itens:
        return 0
    n = 0
    for k in range(0, len(itens), 400):
        b = json.dumps({'itens': itens[k:k + 400]}, ensure_ascii=False).encode()
        req = urllib.request.Request(SITE + '/wp-json/desenvolve/v1/robo/' + rota, data=b, headers={'Content-Type': 'application/json', 'X-DV-Robo': TOKEN, 'User-Agent': 'DesenvolveRobo/1.0'})
        try:
            n += json.loads(urllib.request.urlopen(req, timeout=120).read()).get('gravados', 0)
        except Exception as e:
            log('post', rota, 'erro', str(e)[:150])
    return n


def docs_qd(db, base):
    """Edições baixadas pelos coletores abertos do Querido Diário (rodados no próprio GitHub)."""
    import sqlite3
    out = []
    try:
        c = sqlite3.connect(db)
        rows = c.execute("select g.date, g.file_path, g.file_url, t.name, t.state_code from gazettes g join territories t on t.id = g.territory_id").fetchall()
    except Exception as e:
        log('qd db erro', str(e)[:150]); return out
    for d, fp, fu, nome, uf in rows:
        d = str(d)[:10]
        if d not in DATAS or not fp:
            continue
        out.append({'fonte': 'Diário Oficial de %s' % nome, 'plat': 'qd', 'uf': uf, 'municipio': nome, 'data': d, 'url': fu or '', 'link': fu or '', 'arquivo': os.path.join(base, fp)})
    return out


def main():
    termos = []
    if TOKEN:
        try:
            req = urllib.request.Request(SITE + '/wp-json/desenvolve/v1/robo/termos', headers={'X-DV-Robo': TOKEN, 'User-Agent': 'DesenvolveRobo/1.0'})
            termos = json.loads(urllib.request.urlopen(req, timeout=60).read()).get('termos', [])
        except Exception as e:
            log('termos erro', str(e)[:150])
    # variantes normalizadas: nomes -> texto normalizado; CNPJ -> só dígitos
    busca = []
    for t in termos:
        for v in t.get('variantes', []):
            d = re.sub(r'\D', '', v)
            if len(d) == 14:
                busca.append((t['chave'], 'cnpj', d))
            elif len(norm(v)) >= 6:
                busca.append((t['chave'], 'nome', ' ' + norm(v) + ' '))
    log('termos', len(termos), 'variantes', len(busca), 'datas', sorted(DATAS))

    docs = []
    fontes = (('sigpub', docs_sigpub), ('famem', docs_famem), ('dosp', docs_dosp), ('dompi', docs_dompi), ('doem', docs_doem), ('atende', docs_atende)) + tuple((p, (lambda p=p: docs_plataforma(p))) for p in PLATAFORMAS) + (('io', docs_io),)
    if os.environ.get('SO_FONTES'):
        fontes = tuple(f for f in fontes if f[0] in os.environ['SO_FONTES'].split(','))
    if os.environ.get('QD_DB'):
        fontes = (('qd', lambda: docs_qd(os.environ['QD_DB'], os.environ.get('QD_FILES', '.'))),)
    for nome, f in fontes:
        try:
            d = f(); docs += d; log(nome, 'edições', len(d))
        except Exception as e:
            log(nome, 'erro', str(e)[:200])

    achados, avisos, vistos = [], [], set()
    cnpjs, vistos_c, orgaos_ed = [], set(), {}
    atos_c, vistos_a = [], set()

    def processa(doc):
        if doc.get('arquivo'):
            try:
                b = open(doc['arquivo'], 'rb').read()
            except Exception:
                return doc, None
            if b[:4] == b'%PDF':
                return doc, pdf_texto(b)
            t = b.decode('utf-8', 'replace')
            if '<' in t[:2000]:
                t = html.unescape(re.sub(r'<[^>]+>', ' ', re.sub(r'(?is)<(script|style).*?</\1>', ' ', t)))
            return doc, t
        b = get(doc['url'], timeout=120, binario=True)
        if not b or b[:4] != b'%PDF':
            return doc, None
        return doc, pdf_texto(b, 900 if doc['plat'] == 'dompi' else 180)

    feitos = 0; lidos = {}
    with cf.ThreadPoolExecutor(6) as ex:
        for doc, txt in ex.map(processa, docs):
            if not tempo_ok():
                log('limite de tempo'); break
            if not txt:
                continue
            feitos += 1
            if not doc.get('uf'):
                u0 = uf_do_texto(txt)
                if not u0:
                    continue
                doc = dict(doc, uf=u0)
            if doc['plat'] in ('dosp', 'io') and not doc.get('municipio'):
                m = RX_MUN.search(txt[:4000])
                if m:
                    nm = re.sub(r'\s+', ' ', m.group(1)).strip().title()
                    nm = re.sub(r'\b(De|Da|Do|Das|Dos|E)\b', lambda x: x.group(1).lower(), nm)
                    doc = dict(doc, municipio=nm, fonte=('Diário Oficial de %s (DiOE)' if doc['plat'] == 'dosp' else 'Diário Oficial de %s') % nm)
            for a in atos(txt, doc):
                mlido = (a['municipio'] or doc.get('municipio') or '').strip()
                if mlido and len(mlido) < 60:
                    k = doc['uf'] + '|' + mlido
                    if lidos.get(k, '') < doc['data']:
                        lidos[k] = doc['data']
                t = a['texto']; n = ' ' + norm(t) + ' '; dg = None
                for ch, tp, v in busca:
                    if tp == 'cnpj':
                        dg = dg if dg is not None else re.sub(r'\D', '', t)
                        p = dg.find(v)
                        if p < 0:
                            continue
                        mm = re.search(r'\d{2}\.?\d{3}\.?\d{3}\s*/?\s*\d{4}\s*-?\s*\d{2}', t)
                        i = t.find(v[:2] + '.' + v[2:5]) if v[:2] + '.' + v[2:5] in t else (mm.start() if mm else 0)
                    else:
                        p = n.find(v)
                        if p < 0:
                            continue
                        i = max(0, int(p * len(t) / max(1, len(n))))
                    k = (ch, a['link'], a['cod'])
                    if k in vistos:
                        continue
                    vistos.add(k)
                    achados.append({'chave': ch, 'data': doc['data'], 'municipio': a['municipio'], 'uf': doc['uf'], 'link': a['link'], 'trecho': trecho(t, i, i + len(v)), 'fonte': doc['fonte']})
                # atos de contratação (extratos, contratos, aditivos, homologações, penalidades...): guardados 90 dias com busca por texto
                tipo_ato = tipo_contratacao(t)
                if tipo_ato:
                    ka = (a['link'], a['cod'], hashlib.md5(t[:200].encode('utf-8', 'ignore')).hexdigest())
                    if ka not in vistos_a:
                        vistos_a.add(ka)
                        atos_c.append({'id': hashlib.sha1(('|'.join(ka)).encode()).hexdigest()[:32], 'data': doc['data'], 'uf': doc['uf'], 'municipio': a['municipio'] or doc.get('municipio', ''), 'fonte': doc['fonte'], 'link': a['link'], 'tipo': tipo_ato, 'titulo': re.sub(r'\s+', ' ', t[:200]).strip()[:300], 'texto': re.sub(r'\s+', ' ', t)[:3000]})
                # índice de CNPJs citados (Due Diligence): só CNPJ escrito com pontuação, para não pegar outros números
                for mc in RX_CNPJ.finditer(t):
                    c = re.sub(r'\D', '', mc.group(0))
                    if len(c) != 14 or c == c[0] * 14 or c in orgaos_ed.get(doc['link'], ()):
                        continue
                    # CNPJ do próprio órgão (cabeçalho "Prefeitura Municipal de X, CNPJ ...") não interessa ao Due Diligence
                    seg = norm(re.split(r'[.;:\n]', t[max(0, mc.start() - 160):mc.start()])[-1])
                    if RX_ORGAO.search(seg) and not RX_EMPRESA.search(seg):
                        orgaos_ed.setdefault(doc['link'], set()).add(c)
                        continue
                    kc = (c, a['link'], a['cod'])
                    if kc in vistos_c:
                        continue
                    vistos_c.add(kc)
                    cnpjs.append({'cnpj': c, 'data': doc['data'], 'uf': doc['uf'], 'municipio': a['municipio'] or doc.get('municipio', ''), 'fonte': doc['fonte'], 'link': a['link'], 'cod': a['cod'], 'trecho': trecho(t, mc.start(), mc.end())[:700]})
                # avisos de licitação (SIGPub já entra pela busca ao vivo)
                if doc['plat'] != 'sigpub':
                    cab = norm(t[:500])
                    if RX_QUER.search(cab) and not RX_NAO.search(norm(t[:160])):
                        tit = re.sub(r'\s+', ' ', t[:200]).strip()[:200]
                        avisos.append({'id': hashlib.sha1((a['link'] + '|' + a['cod'] + '|' + t[:80]).encode()).hexdigest()[:32], 'data': doc['data'], 'uf': doc['uf'], 'municipio': a['municipio'] or doc.get('municipio', ''), 'fonte': doc['fonte'], 'link': a['link'], 'titulo': tit, 'texto': re.sub(r'\s+', ' ', t)[:6000]})
    # Diário Oficial do Estado de SP: busca direta de cada termo monitorado (API pública do DOE-SP)
    if not os.environ.get('QD_DB') and (not os.environ.get('SO_FONTES') or 'doesp' in os.environ['SO_FONTES']):
        ini = (HOJE - dt.timedelta(days=max(DIAS, 2))).isoformat(); nd = 0
        for t in termos:
            for v in t.get('variantes', []):
                if not tempo_ok():
                    break
                d = re.sub(r'\D', '', v)
                q = v if len(d) != 14 else '%s.%s.%s/%s-%s' % (d[:2], d[2:5], d[5:8], d[8:12], d[12:])
                if len(d) != 14 and len(norm(q)) < 6:
                    continue
                u = 'https://do-api-web-search.doe.sp.gov.br/v2/advanced-search/publications?' + urllib.parse.urlencode({'FromDate': ini, 'ToDate': HOJE.isoformat(), 'Terms': '"%s"' % q if len(d) != 14 else q, 'PageNumber': 1, 'PageSize': 50})
                try:
                    j = json.loads(get(u, timeout=40) or '{}')
                except Exception:
                    j = {}
                for it in j.get('items', []) or []:
                    link = 'https://doe.sp.gov.br/' + str(it.get('slug', '')).lstrip('/')
                    k = (t['chave'], link, '')
                    if k in vistos or not it.get('slug'):
                        continue
                    vistos.add(k); nd += 1
                    org = str(it.get('slug', '')).split('/')[1].replace('-', ' ').capitalize() if '/' in str(it.get('slug', '')) else ''
                    achados.append({'chave': t['chave'], 'data': str(it.get('date', ''))[:10], 'municipio': 'Governo do Estado de SP' + (' · ' + org if org else ''), 'uf': 'SP', 'link': link, 'trecho': re.sub(r'\s+', ' ', (it.get('title') or '') + ' — ' + (it.get('excerpt') or ''))[:700], 'fonte': 'Diário Oficial do Estado de São Paulo'})
        log('doesp citações', nd)
    log('edições lidas', feitos, 'de', len(docs), '| avisos', len(avisos), '| citações', len(achados))
    log('gravados avisos', post('avisos', avisos), '| gravados citações', post('achados', achados))
    log('cnpjs citados', len(cnpjs), '| gravados', post('cnpjs', cnpjs))
    log('atos de contratação', len(atos_c), '| gravados', post('atos', atos_c))
    # municípios cujo diário foi lido (qualquer ato), guardados por 90 dias: entram na conta da cobertura
    if not os.environ.get('QD_DB'):
        arq = os.path.join(DADOS, 'lidos.json')
        try:
            ant = json.load(open(arq)).get('munis', {})
        except Exception:
            ant = {}
        lim = (HOJE - dt.timedelta(days=90)).isoformat()
        for k, d in lidos.items():
            if ant.get(k, '') < d:
                ant[k] = d
        ant = {k: d for k, d in ant.items() if d >= lim}
        json.dump({'quando': time.time(), 'munis': ant}, open(arq, 'w'), ensure_ascii=False, indent=0)
        log('municípios lidos (90 dias)', len(ant))
    json.dump({'quando': dt.datetime.now().isoformat(), 'edicoes': len(docs), 'lidas': feitos, 'avisos': len(avisos), 'citacoes': len(achados), 'por_fonte': {p: sum(1 for d in docs if d['plat'] == p) for p in ('sigpub', 'famem', 'dosp', 'dompi', 'doem', 'atende', 'qd')}}, open(os.path.join(DADOS, 'ultimo%s.json' % ('-qd-' + os.environ.get('GRUPO', '') if os.environ.get('QD_DB') else '')), 'w'), indent=1)


if __name__ == '__main__':
    main()
