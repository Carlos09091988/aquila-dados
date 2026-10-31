"""Baixa a Jurisprudencia Selecionada e as Sumulas do TCU (dados abertos) e
gera aquila/jur/tcu-licitacoes.json so com Licitacao e Contrato Administrativo."""
import csv, io, json, sys, urllib.request, gzip
csv.field_size_limit(10**9)
BASE = 'https://sites.tcu.gov.br/dados-abertos/jurisprudencia/arquivos/'
def baixar(p):
    req = urllib.request.Request(BASE + p, headers={'User-Agent': 'aquila-dados (GitHub Actions)'})
    return urllib.request.urlopen(req, timeout=300).read().decode('utf-8-sig')
AREAS = {'Licitação', 'Contrato Administrativo'}
def ler(txt):
    return csv.DictReader(io.StringIO(txt), delimiter='|', quotechar='"')
import os; os.makedirs('aquila/jur', exist_ok=True)
out = []
for r in ler(baixar('jurisprudencia-selecionada/jurisprudencia-selecionada.csv')):
    if r.get('AREA') not in AREAS: continue
    out.append({'k': r['KEY'], 'n': r['NUMACORDAO'], 'a': r['ANOACORDAO'], 'c': r['COLEGIADO'],
                'ar': r['AREA'], 't': r['TEMA'], 's': r['SUBTEMA'], 'e': (r['ENUNCIADO'] or '').strip(),
                'd': r['DATASESSAOFORMATADA'], 'rel': r['AUTORTESE'], 'p': r.get('PARADIGMATICO', ''),
                'sum': r.get('NUMSUMULA', ''), 'idx': r.get('INDEXADORESCONSOLIDADOS', '')})
sm = []
try:
    t = baixar('sumula/sumula.csv'); rd = ler(t); cols = rd.fieldnames
    for r in rd: sm.append(r)
except Exception as e:
    cols = str(e)
json.dump({'selecionada': out}, open('aquila/jur/tcu-licitacoes.json', 'w'), ensure_ascii=False, separators=(',', ':'))
json.dump({'colunas': cols, 'sumulas': sm}, open('aquila/jur/tcu-sumulas.json', 'w'), ensure_ascii=False, separators=(',', ':'))
print(len(out), 'enunciados;', len(sm), 'sumulas')
