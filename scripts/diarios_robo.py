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


def pdf_texto(b):
    with tempfile.NamedTemporaryFile(suffix='.pdf') as f:
        f.write(b); f.flush()
        try:
            return subprocess.run(['pdftotext', '-enc', 'UTF-8', f.name, '-'], capture_output=True, timeout=180).stdout.decode('utf-8', 'replace')
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
    arq = os.path.join(DADOS, 'dosp.json')
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
        for r in ex.map(sonda, range(int(os.environ.get('DOSP_DE', '3000')), int(os.environ.get('DOSP_ATE', '7000')))):
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
    h = get('https://diariooficial.famem.org.br/dom/dom/todasEdicoes')
    if not h:
        return out
    for m in re.finditer(r'(\d{2})/(\d{2})/(\d{4}).{0,1500}?(https://painel\.siganet\.net\.br/upload/[^"\']+\.pdf)', h, re.S):
        d = '%s-%s-%s' % (m.group(3), m.group(2), m.group(1))
        if d in DATAS:
            out.append({'fonte': 'Diário Oficial dos Municípios do Maranhão (FAMEM)', 'plat': 'famem', 'uf': 'MA', 'municipio': '', 'data': d, 'url': m.group(4), 'link': m.group(4)})
    return list({x['url']: x for x in out}.values())


# ------------------------------------------------------------------ texto -> atos
RX_MUN = re.compile(r'(?:PREFEITURA\s+MUNICIPAL\s+D[EOA]S?|MUNIC[IÍ]PIO\s+D[EOA]S?|C[AÂ]MARA\s+MUNICIPAL\s+D[EOA]S?)\s+([A-ZÀ-Ü][A-ZÀ-Ü\'\- ]{2,40}?)(?:\s*[-–/]\s*[A-Z]{2}\b|\s*\n|\s{2,}|,)', re.I)
RX_QUER = re.compile(r'AVISO D[EO] (LICITACAO|PREGAO|ABERTURA|DISPENSA|CHAMAMENTO|EDITAL|REABERTURA|CONCORRENCIA)|AVISOS DE LICITACAO|PREGAO (ELETRONICO|PRESENCIAL)|CONCORRENCIA (ELETRONICA|PUBLICA)|TOMADA DE PRECOS|DISPENSA ELETRONICA|CHAMAMENTO PUBLICO|EDITAL DE (LICITACAO|PREGAO)|CREDENCIAMENTO')
RX_NAO = re.compile(r'HOMOLOG|ADJUDICA|EXTRATO|RATIFICA|RESULTADO|REVOGA|ANULA|ADITIVO|APOSTILA|RESCIS|ATA DE REGISTRO|PORTARIA|DECRETO|LEI N|JULGAMENTO|DESERT|FRACASS|SUSPENS|CONVOCACAO|NOTIFICA|CONTRATO N')


def atos(texto, doc):
    """Divide a edição em atos. SIGPub/Siganet terminam cada ato com 'Código Identificador'."""
    partes = re.split(r'C[oó]digo\s+Identificador\s*:?\s*([A-Z0-9]{6,12})', texto)
    r = []
    if len(partes) > 2:
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
    for nome, f in (('sigpub', docs_sigpub), ('famem', docs_famem), ('dosp', docs_dosp)):
        try:
            d = f(); docs += d; log(nome, 'edições', len(d))
        except Exception as e:
            log(nome, 'erro', str(e)[:200])

    achados, avisos, vistos = [], [], set()

    def processa(doc):
        b = get(doc['url'], timeout=120, binario=True)
        if not b or b[:4] != b'%PDF':
            return doc, None
        return doc, pdf_texto(b)

    feitos = 0
    with cf.ThreadPoolExecutor(6) as ex:
        for doc, txt in ex.map(processa, docs):
            if not tempo_ok():
                log('limite de tempo'); break
            if not txt:
                continue
            feitos += 1
            for a in atos(txt, doc):
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
                # avisos de licitação (SIGPub já entra pela busca ao vivo)
                if doc['plat'] != 'sigpub':
                    cab = norm(t[:500])
                    if RX_QUER.search(cab) and not RX_NAO.search(norm(t[:160])):
                        tit = re.sub(r'\s+', ' ', t[:200]).strip()
                        avisos.append({'id': hashlib.sha1((a['link'] + '|' + a['cod'] + '|' + t[:80]).encode()).hexdigest()[:32], 'data': doc['data'], 'uf': doc['uf'], 'municipio': a['municipio'] or doc.get('municipio', ''), 'fonte': doc['fonte'], 'link': a['link'], 'titulo': tit, 'texto': re.sub(r'[ \t]+', ' ', t)[:6000]})
    log('edições lidas', feitos, 'de', len(docs), '| avisos', len(avisos), '| citações', len(achados))
    log('gravados avisos', post('avisos', avisos), '| gravados citações', post('achados', achados))
    json.dump({'quando': dt.datetime.now().isoformat(), 'edicoes': len(docs), 'lidas': feitos, 'avisos': len(avisos), 'citacoes': len(achados), 'por_fonte': {p: sum(1 for d in docs if d['plat'] == p) for p in ('sigpub', 'famem', 'dosp')}}, open(os.path.join(DADOS, 'ultimo.json'), 'w'), indent=1)


if __name__ == '__main__':
    main()
