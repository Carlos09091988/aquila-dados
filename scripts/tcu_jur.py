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
import re
def limpa(t): return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', t or '')).strip()
# Sumulas do TCU (vigentes, area Licitacao)
sumL = []
for r in sm if isinstance(sm, list) else []:
    if r.get('AREA') != 'Licitação' or str(r.get('VIGENTE', '')).lower() != 'true': continue
    e = limpa(r.get('ENUNCIADO')); e = re.sub(r'^S[ÚU]MULA TCU \d+\s*(\([^)]*\))?\s*:\s*', '', e, flags=re.I)
    sumL.append({'k': r['KEY'], 'n': r['NUMERO'], 'e': e, 't': r.get('TEMA', ''), 's': r.get('SUBTEMA', ''),
                 'ac': (r.get('NUMAPROVACAO', '') + '/' + r.get('ANOAPROVACAO', '')).strip('/'), 'd': r.get('DATASESSAOFORMATADA', '')})
# Respostas a consultas (area Licitacao)
cons = []
try:
    for r in ler(baixar('resposta-consulta/resposta-consulta.csv')):
        if r.get('AREA') != 'Licitação': continue
        cons.append({'k': r['KEY'], 'n': r['NUMACORDAO'], 'a': r['ANOACORDAO'], 'c': r['COLEGIADO'], 't': r['TEMA'], 's': r['SUBTEMA'],
                     'e': limpa(r['ENUNCIADO']), 'd': r['DATASESSAOFORMATADA'], 'rel': r['AUTORTESE']})
except Exception as ex: print('consulta', ex)
# Informativo de Licitacoes e Contratos (so o que e de licitacao)
info = []
CONTRATO = re.compile(r'aditivo|reajust|repactua|reequil[ií]brio|execu[cç][aã]o (do|de) contrato|execu[cç][aã]o contratual|superfaturamento|medi[cç][aã]o|fiscaliza[cç][aã]o do contrato|prorroga[cç][aã]o (do|de) contrato|rescis', re.I)
LICIT = re.compile(r'licita|edital|proposta|habilita|preg[aã]o|certame|licitante|julgamento|contrata[cç][aã]o direta|dispensa|inexigibilidade|registro de pre[cç]os', re.I)
try:
    for r in ler(baixar('boletim-informativo-lc/informativo-lc.csv')):
        e = limpa(r.get('ENUNCIADO'))
        if not e or not LICIT.search(e) or (CONTRATO.search(e) and not re.search(r'licita|edital|certame', e, re.I)): continue
        ta = limpa(r.get('TEXTOACORDAO')); m = re.search(r'Ac[óo]rd[ãa]o\s+(\d+)/(\d{4})', ta); rl = re.search(r'Relator[a]?\s+(?:Ministr[oa](?:-Substitut[oa])?\s+)?(.+)$', ta)
        ni = re.search(r'(\d+)/(\d{4})', r.get('TITULO', ''))
        info.append({'k': r['KEY'], 'n': m.group(1) if m else '', 'a': m.group(2) if m else (ni.group(2) if ni else ''), 'c': r.get('COLEGIADO', ''),
                     'e': e, 'rel': rl.group(1).strip() if rl else '', 'inf': ni.group(1) if ni else '', 'infa': ni.group(2) if ni else '', 'tipo': ta.split(',')[1].strip() if ta.count(',') >= 1 else ''})
except Exception as ex: print('informativo', ex)
json.dump({'sumulas': sumL, 'consultas': cons, 'informativo': info}, open('aquila/jur/tcu-extras.json', 'w'), ensure_ascii=False, separators=(',', ':'))
print(len(sumL), 'sumulas lic;', len(cons), 'consultas;', len(info), 'informativo')
json.dump({'selecionada': out}, open('aquila/jur/tcu-licitacoes.json', 'w'), ensure_ascii=False, separators=(',', ':'))
json.dump({'colunas': cols, 'sumulas': sm}, open('aquila/jur/tcu-sumulas.json', 'w'), ensure_ascii=False, separators=(',', ':'))
print(len(out), 'enunciados;', len(sm), 'sumulas')
