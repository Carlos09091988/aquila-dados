"""Robô das atas (Desenvolve).

Todo dia procura no PNCP licitações dos ramos de serviços contínuos cuja sessão já
aconteceu, baixa as atas e relatórios de julgamento publicados e extrai os CNPJs
dos participantes. Alimenta a base de participações da plataforma.

Saída: data/participacoes/novos.json (últimos 30 dias) e data/participacoes/estado.json
Dados públicos (Lei 14.133/2021, art. 174).
"""
import json, os, re, subprocess, sys, tempfile, time, datetime as dt
import urllib.request, urllib.parse, urllib.error

OUT = os.path.join(os.path.dirname(__file__), '..', 'data', 'participacoes')
LIMITE_SEG = int(os.environ.get('LIMITE_SEG', 2400))
HOJE = dt.date.today()
T0 = time.time()
UA = {'User-Agent': 'Mozilla/5.0 (compatible; DesenvolveRobo/1.0; +https://desenvolveestrategias.com.br)'}

TERMOS = ['vigilância', 'limpeza', 'portaria', 'recepção', 'alimentação escolar', 'merenda', 'manutenção predial',
          'apoio administrativo', 'mão de obra', 'jardinagem', 'roçada', 'transporte escolar', 'coleta de lixo',
          'limpeza hospitalar', 'copeiragem', 'controle de acesso', 'lavanderia hospitalar', 'refeições']
DOC_OK = re.compile(r'ata|julgamento|resultado|disputa|classifica|homolog|adjudica|lances|propostas', re.I)
DOC_NAO = re.compile(r'registro de pre|edital|termo de refer|minuta|estudo t|etp|aviso|errata|esclarec|impugna|contrato', re.I)
CNPJ_RE = re.compile(r'(\d{2}\.?\d{3}\.?\d{3}\s?/\s?\d{4}\s?-\s?\d{2})')
VAL_RE = re.compile(r'R\$\s*([\d.]+,\d{2})')


def tempo():
    return time.time() - T0 < LIMITE_SEG


def get(url, binario=False, tent=3):
    for i in range(tent):
        try:
            req = urllib.request.Request(url, headers=UA)
            with urllib.request.urlopen(req, timeout=60) as r:
                b = r.read(16 * 1024 * 1024)
                return b if binario else json.loads(b.decode('utf-8', 'ignore'))
        except Exception:
            time.sleep(2 + i * 3)
    return None


def cnpj_ok(c):
    if not re.fullmatch(r'\d{14}', c) or len(set(c)) == 1:
        return False
    for n in (12, 13):
        s, p = 0, n - 7
        for i in range(n):
            s += int(c[i]) * p
            p -= 1
            if p < 2:
                p = 9
        d = 0 if s % 11 < 2 else 11 - s % 11
        if int(c[n]) != d:
            return False
    return True


def texto_pdf(b):
    with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as f:
        f.write(b)
        nome = f.name
    try:
        r = subprocess.run(['pdftotext', '-layout', '-l', '60', nome, '-'], capture_output=True, timeout=120)
        return r.stdout.decode('utf-8', 'ignore')
    except Exception:
        return ''
    finally:
        os.unlink(nome)


def participantes(t, orgao):
    out, vistos = [], set()
    linhas = t.split('\n')
    for i, ln in enumerate(linhas):
        for m in CNPJ_RE.finditer(ln):
            c = re.sub(r'\D', '', m.group(1))
            if c == orgao or c in vistos or not cnpj_ok(c):
                continue
            vistos.add(c)
            antes = ln[:m.start()].strip(' -–:|;,(')
            antes = re.sub(r'[,;\s]*(CPF/CNPJ|CNPJ/MF|CNPJ|inscrita no|sob o n[ºo°]?)\s*[:n°º.]*\s*$', '', antes, flags=re.I)
            antes = re.sub(r'^.*?(Empresa|Fornecedor|Licitante|Raz[aã]o Social)\s*:\s*', '', antes, flags=re.I).strip(' -–:|;,(')
            if len(antes) < 3 and i > 0:
                antes = linhas[i - 1].strip()
            seg = [x for x in re.split(r'\s{2,}|\t|\|', antes) if re.search(r'[A-Za-zÀ-ú]{3}', x)]
            nome = (seg[-1] if seg else '')[-120:].strip(' -–:|;,(')
            v = VAL_RE.search(ln[m.end():])
            val = float(v.group(1).replace('.', '').replace(',', '.')) if v else 0
            out.append({'c': c, 'n': nome[:120], 'v': val})
    return out


def main():
    os.makedirs(OUT, exist_ok=True)
    pe = os.path.join(OUT, 'estado.json')
    pn = os.path.join(OUT, 'novos.json')
    estado = json.load(open(pe)) if os.path.exists(pe) else {}
    novos = json.load(open(pn)) if os.path.exists(pn) else []
    corte = (HOJE - dt.timedelta(days=30)).isoformat()
    novos = [x for x in novos if x.get('d', '') >= corte]
    RAMOS = re.compile(r'vigil[aâ]ncia|seguran[cç]a patrimonial|limpeza|conserva[cç][aã]o|higieniza|portaria|recep[cç][aã]o|controle de acesso|alimenta[cç][aã]o|merenda|refei[cç]|manuten[cç][aã]o predial|apoio administrativo|m[aã]o de obra|copeir|jardinagem|ro[cç]ada|transporte escolar|coleta de (lixo|res[ií]duos)|lavanderia', re.I)
    EXCL = re.compile(r'aquisi[cç][aã]o de|material de limpeza|produtos de limpeza|g[eê]neros aliment', re.I)
    alvo = {}
    # pendentes de dias anteriores (ata ainda não publicada)
    for ref, e in estado.items():
        if not e.get('ok') and e.get('t', 0) < 6 and e.get('objeto'):
            alvo[ref] = {'objeto': e['objeto'], 'orgao': e.get('orgao', '')}
    # pregões eletrônicos publicados há 21 dias (sessão já deve ter ocorrido)
    dia = (HOJE - dt.timedelta(days=21)).strftime('%Y%m%d')
    for pg in range(1, 200):
        if not tempo():
            break
        q = urllib.parse.urlencode({'dataInicial': dia, 'dataFinal': dia, 'codigoModalidadeContratacao': 6, 'pagina': pg, 'tamanhoPagina': 50})
        j = get('https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao?' + q)
        if not j or not j.get('data'):
            break
        for c in j['data']:
            obj = c.get('objetoCompra') or ''
            if not RAMOS.search(obj) or EXCL.search(obj):
                continue
            enc = (c.get('dataEncerramentoProposta') or '')[:10]
            if enc and enc > (HOJE - dt.timedelta(days=2)).isoformat():
                continue
            oe = c.get('orgaoEntidade') or {}
            ref = f"{oe.get('cnpj', '')}/{c.get('anoCompra')}/{c.get('sequencialCompra')}"
            if not re.fullmatch(r'\d{14}/\d{4}/\d+', ref) or estado.get(ref, {}).get('ok'):
                continue
            alvo[ref] = {'objeto': obj[:250], 'orgao': (oe.get('razaoSocial') or '')[:190]}
        if pg >= (j.get('totalPaginas') or 1):
            break
    print('licitações com sessão encerrada a verificar:', len(alvo))
    feitos = achou = 0
    for ref, info in alvo.items():
        if not tempo():
            break
        cnpj, ano, seq = ref.split('/')
        e = estado.setdefault(ref, {'t': 0})
        e['t'] = e.get('t', 0) + 1
        e['objeto'], e['orgao'] = info['objeto'], info['orgao']
        arqs = get(f'https://pncp.gov.br/api/pncp/v1/orgaos/{cnpj}/compras/{ano}/{seq}/arquivos')
        feitos += 1
        if not isinstance(arqs, list):
            continue
        P = {}
        for a in arqs:
            tit = f"{a.get('titulo', '')} {a.get('tipoDocumentoNome', '')}"
            url = a.get('url') or a.get('uri') or ''
            if not url or not DOC_OK.search(tit) or DOC_NAO.search(a.get('titulo', '') or ''):
                continue
            b = get(url, binario=True)
            if not b or b[:4] != b'%PDF':
                continue
            for p in participantes(texto_pdf(b), cnpj):
                if p['c'] not in P or (p['v'] and not P[p['c']]['v']):
                    P[p['c']] = p
            if len(P) >= 2:
                break
        if len(P) >= 2:
            e['ok'] = True
            achou += 1
            novos.append({'ref': ref, 'd': HOJE.isoformat(), 'objeto': info['objeto'], 'orgao': info['orgao'], 'participantes': list(P.values())[:80]})
        time.sleep(0.3)
    estado = {k: v for k, v in estado.items() if v.get('ok') or v.get('t', 0) < 6}
    json.dump(estado, open(pe, 'w'), ensure_ascii=False)
    json.dump(novos, open(pn, 'w'), ensure_ascii=False)
    print(f'verificadas: {feitos} · com participantes: {achou} · na janela de 30 dias: {len(novos)}')


if __name__ == '__main__':
    main()
