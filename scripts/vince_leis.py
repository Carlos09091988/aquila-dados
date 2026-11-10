"""Base do Vince: legislação de licitações e direito administrativo, artigo por artigo, no texto vigente.
Fonte: normas.leg.br (Senado/Congresso, licença CC BY 4.0). Gera aquila/jur/vince-leis.json.
Cada artigo: {'l': 'Lei 14.133/2021', 'a': '75', 't': texto completo do artigo (caput, incisos, parágrafos), 'r': 1 se revogado, 'u': urn}.
Para cada dispositivo usa a versão mais recente (workExample com a maior data)."""
import json, re, sys, time, urllib.request, urllib.parse

API = 'https://normas.leg.br/api/public/normas?tipo_documento=maior-detalhe&urn='
LEIS = [
    ('Lei 14.133/2021', 'urn:lex:br:federal:lei:2021-04-01;14133', None),
    ('Lei 8.666/1993', 'urn:lex:br:federal:lei:1993-06-21;8666', None),          # revogada, mas rege contratos assinados sob ela
    ('Lei 10.520/2002', 'urn:lex:br:federal:lei:2002-07-17;10520', None),
    ('LC 123/2006', 'urn:lex:br:federal:lei.complementar:2006-12-14;123', None),
    ('Lei 13.303/2016', 'urn:lex:br:federal:lei:2016-06-30;13303', None),
    ('Lei 8.987/1995', 'urn:lex:br:federal:lei:1995-02-13;8987', None),
    ('Lei 11.079/2004', 'urn:lex:br:federal:lei:2004-12-30;11079', None),
    ('Lei 12.846/2013', 'urn:lex:br:federal:lei:2013-08-01;12846', None),
    ('Lei 9.784/1999', 'urn:lex:br:federal:lei:1999-01-29;9784', None),
    ('Lei 8.429/1992', 'urn:lex:br:federal:lei:1992-06-02;8429', None),
    ('Lei 12.527/2011', 'urn:lex:br:federal:lei:2011-11-18;12527', None),
    ('Lei 13.709/2018', 'urn:lex:br:federal:lei:2018-08-14;13709', None),
    ('LINDB (Decreto-Lei 4.657/1942)', 'urn:lex:br:federal:decreto.lei:1942-09-04;4657', None),
    ('Lei 12.016/2009', 'urn:lex:br:federal:lei:2009-08-07;12016', None),     # mandado de segurança
    ('Decreto 11.462/2023', 'urn:lex:br:federal:decreto:2023-03-31;11462', None),  # registro de preços
    ('Decreto 11.246/2022', 'urn:lex:br:federal:decreto:2022-10-27;11246', None),  # agente de contratação
    ('Decreto 10.947/2022', 'urn:lex:br:federal:decreto:2022-01-25;10947', None),  # plano de contratações anual
    ('Decreto 8.538/2015', 'urn:lex:br:federal:decreto:2015-10-06;8538', None),    # ME/EPP nas compras públicas
    ('Constituição Federal', 'urn:lex:br:federal:constituicao:1988-10-05;1988', {'5', '22', '37', '70', '71', '73', '75', '173', '175'}),
]
UA = {'User-Agent': 'aquila-dados (GitHub Actions; base do Vince)'}

def baixar(urn):
    for i in range(4):
        try:
            r = urllib.request.urlopen(urllib.request.Request(API + urllib.parse.quote(urn, safe=':;.-'), headers=UA), timeout=180)
            return json.loads(r.read().decode('utf-8'))
        except Exception as e:
            print('  tentativa', i + 1, urn, e); time.sleep(5 * (i + 1))
    return None

def versao(n):
    """Versão mais recente do dispositivo (workExample pode ser dict ou lista)."""
    w = n.get('workExample')
    ws = w if isinstance(w, list) else ([w] if isinstance(w, dict) else [])
    ws = [x for x in ws if isinstance(x, dict)]
    if not ws: return {}
    return max(ws, key=lambda x: x.get('legislationDate') or '')

def filhos(n):
    h = n.get('hasPart')
    return h if isinstance(h, list) else ([h] if isinstance(h, dict) else [])

def limpa(t):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', t or '')).strip()

def texto(n, prof=0):
    """Texto do dispositivo e de tudo o que está abaixo dele, na ordem."""
    v = versao(n); nome = limpa(v.get('name', '')); tx = limpa(v.get('text', ''))
    tipo = (v.get('legislationType') or '') if isinstance(v.get('legislationType'), str) else ''
    linha = (nome + ' ' + tx).strip()
    if re.search(r'revog', tipo, re.I) and not tx: linha = (nome + ' (Revogado)').strip()
    out = [linha] if linha else []
    for f in filhos(n): out += texto(f, prof + 1)
    return out

def artigos(raiz):
    """Percorre a árvore e devolve os nós que são artigos (identificador terminando em !artN ou _artN)."""
    achados = []
    def anda(n):
        if not isinstance(n, dict): return
        lid = n.get('legislationIdentifier', '')
        m = re.search(r'!art(\d+[a-z]?(?:-\d+)?)$', lid)
        if m and '@' not in lid:
            achados.append((m.group(1), n)); return
        for f in filhos(n): anda(f)
    anda(raiz)
    return achados

saida = []
for rot, urn, so in LEIS:
    print(rot)
    j = baixar(urn)
    if not j: print('  FALHOU'); continue
    n = 0
    for num, no in artigos(j.get('hasPart', {})):
        if so and num.split('-')[0] not in so: continue
        linhas = texto(no)
        if not linhas: continue
        t = '\n'.join(linhas)
        rev = 1 if re.match(r'^Art\.?\s*[\dº°A-Z-]+\.?\s*\(Revogad', t) or (len(t) < 40 and 'Revogad' in t) else 0
        saida.append({'l': rot, 'a': num.replace('-', '-'), 't': t[:12000], 'r': rev, 'u': urn + '!art' + num})
        n += 1
    print('  artigos:', n)
    time.sleep(2)

if len(saida) < 500:
    print('Poucos artigos (', len(saida), '); mantendo o arquivo anterior.'); sys.exit(1)
json.dump({'fonte': 'normas.leg.br (CC BY 4.0)', 'gerado': time.strftime('%Y-%m-%d'), 'artigos': saida},
          open('aquila/jur/vince-leis.json', 'w'), ensure_ascii=False, separators=(',', ':'))
print('total', len(saida), 'artigos')
