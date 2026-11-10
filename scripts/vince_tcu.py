"""Base do Vince: acórdãos do TCU sobre licitações e contratos (2021 em diante) e Boletim de Jurisprudência.
Fonte: dados abertos do TCU (sites.tcu.gov.br/dados-abertos/jurisprudencia).
Gera aquila/jur/vince-tcu-acordaos-AAAA.json (um por ano) e aquila/jur/vince-tcu-boletim.json.
Uso: python3 scripts/vince_tcu.py [ANO_INICIAL]   (padrão: 2021; o mês corrente sempre refaz o ano atual)."""
import csv, io, json, os, re, sys, time, urllib.request
csv.field_size_limit(10**9)
B = 'https://sites.tcu.gov.br/dados-abertos/jurisprudencia/arquivos/'
UA = {'User-Agent': 'aquila-dados (GitHub Actions; base do Vince)'}
os.makedirs('aquila/jur', exist_ok=True)

def abrir(p):
    r = urllib.request.urlopen(urllib.request.Request(B + p, headers=UA), timeout=600)
    return csv.DictReader(io.TextIOWrapper(r, encoding='utf-8-sig', newline=''), delimiter='|', quotechar='"')

def limpa(t):
    t = re.sub(r'<[^>]+>', ' ', t or '')
    return re.sub(r'\s+', ' ', t.replace('&nbsp;', ' ').replace('&quot;', '"')).strip()

LIC = re.compile(r'licita|edital|preg[aã]o|concorr[eê]ncia|habilita|proposta|certame|licitante|contrata[cç][aã]o direta|dispensa|inexigibilidade|registro de pre[cç]os|ata de registro|contrato administrativo|aditivo|reajust|repactua|reequil[ií]brio|sobrepre[cç]o|superfaturamento|inidone|impedimento de licitar|san[cç][aã]o administrativa|credenciamento|termo de refer[eê]ncia|estudo t[eé]cnico preliminar|agente de contrata[cç][aã]o|pregoeiro', re.I)
FORA = re.compile(r'APOSENTADORIA|PENS[AÃ]O|ADMISS[AÃ]O|REFORMA|ATO DE PESSOAL|\(APOS\)|\(PENS\)|\(ADM\)|\(REFO\)', re.I)

# Resumos gerados pela IA do próprio TCU (chave -> texto)
ia = {}
try:
    for r in abrir('acordao-completo/acordao-completo-resumo.csv'):
        ia[r['KEY']] = limpa(r.get('VISAOGERAL'))[:1600]
    print('resumos IA:', len(ia))
except Exception as e:
    print('resumos IA: falhou', e)

ini = int(sys.argv[1]) if len(sys.argv) > 1 else 2021
# Chaves de TODOS os acórdãos lidos (qualquer assunto), para o Vince conferir se uma citação existe: "numero/ano/P|1|2"
CH = 'aquila/jur/vince-tcu-chaves.json'
chaves = set(json.load(open(CH))['chaves']) if os.path.exists(CH) else set()
def col(c): c = (c or '').lower(); return 'P' if c.startswith('plen') else ('1' if c.startswith('primeira') else ('2' if c.startswith('segunda') else '?'))
ano_atual = int(time.strftime('%Y'))
for ano in range(ini, ano_atual + 1):
    arq = f'aquila/jur/vince-tcu-acordaos-{ano}.json'
    if ano < ano_atual - 1 and os.path.exists(arq) and len(sys.argv) <= 1 and any(c.split('/')[1] == str(ano) for c in list(chaves)[:200000]):
        print(ano, 'já existe; mantido'); continue
    out = []
    try:
        for r in abrir(f'acordao-completo/acordao-completo-{ano}.csv'):
            chaves.add(f"{r.get('NUMACORDAO','')}/{r.get('ANOACORDAO','')}/{col(r.get('COLEGIADO'))}")
            tp = r.get('TIPOPROCESSO', '')
            base = (r.get('ASSUNTO', '') + ' ' + r.get('SUMARIO', ''))
            if FORA.search(tp) or not LIC.search(base): continue
            dec = limpa(r.get('ACORDAO'))
            dec = re.sub(r'^.*?ACÓRDÃO N[ºo°]\s*\d+/\d{4}\s*-\s*TCU\s*-\s*[^ ]+( Câmara)?\s*', '', dec)[:1600]
            out.append({'k': r['KEY'], 'n': r.get('NUMACORDAO', ''), 'a': r.get('ANOACORDAO', ''), 'c': r.get('COLEGIADO', ''),
                        'd': r.get('DATASESSAO', ''), 'rel': (r.get('RELATOR') or '').title(), 'tp': limpa(tp)[:80],
                        'ent': limpa(r.get('ENTIDADE'))[:160], 'su': limpa(r.get('SUMARIO'))[:1000] or limpa(r.get('ASSUNTO'))[:1000],
                        'ia': ia.get(r['KEY'], ''), 'dec': dec, 'sit': r.get('SITUACAO', '')})
    except Exception as e:
        print(ano, 'falhou', e)
        if not out: continue
    json.dump({'ano': ano, 'acordaos': out}, open(arq, 'w'), ensure_ascii=False, separators=(',', ':'))
    print(ano, len(out), 'acórdãos de licitação/contratos', round(os.path.getsize(arq) / 1e6, 1), 'MB')

json.dump({'chaves': sorted(chaves)}, open(CH, 'w'), separators=(',', ':'))
print('chaves de acórdãos:', len(chaves))

# Boletim de Jurisprudência (geral) — só os enunciados de licitações e contratos
bol = []
try:
    for r in abrir('boletim-jurisprudencia/boletim-jurisprudencia.csv'):
        e = limpa(r.get('ENUNCIADO'))
        if not e or not LIC.search(e): continue
        ta = limpa(r.get('TEXTOACORDAO')); m = re.search(r'Ac[óo]rd[ãa]o\s+(\d+)/(\d{4})\s+(Plen[áa]rio|Primeira C[âa]mara|Segunda C[âa]mara)?', ta)
        rl = re.search(r'Relator[a]?\s+(?:Ministr[oa](?:-Substitut[oa])?\s+)?([^)]+)', ta)
        bol.append({'k': r['KEY'], 'n': m.group(1) if m else '', 'a': m.group(2) if m else '', 'c': (m.group(3) or '') if m else '',
                    'e': e, 'bol': limpa(r.get('TITULO')), 'rel': rl.group(1).strip() if rl else ''})
    json.dump({'boletim': bol}, open('aquila/jur/vince-tcu-boletim.json', 'w'), ensure_ascii=False, separators=(',', ':'))
    print('boletim:', len(bol))
except Exception as e:
    print('boletim falhou', e)
