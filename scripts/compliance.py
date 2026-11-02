"""Bases públicas de compliance para a Due Diligence (gratuitas):
- Cadastro de Empregadores (lista suja do trabalho escravo), Ministério do Trabalho — PDF
- Termos de embargo do Ibama — dados abertos (CSV)
Saída: data/compliance/escravo.json  {cnpj14|cpf: {...}}
       data/compliance/embargos/<2 primeiros dígitos>.json  {raiz8: [ {...} ]}
"""
import csv, io, json, os, re, subprocess, sys, tempfile, zipfile, datetime, urllib.request

OUT = 'data/compliance'
UA = {'User-Agent': 'aquila-dados (dados abertos)', 'Accept': '*/*'}

def get(url, timeout=180):
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=timeout) as r:
        return r.read()

def so_dig(s): return ''.join(c for c in (s or '') if c.isdigit())

# ---------- Lista suja ----------
PAGINAS_MTE = [
    'https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/areas-de-atuacao/combate-ao-trabalho-escravo-e-analogo-ao-de-escravo',
    'https://www.gov.br/trabalho-e-emprego/pt-br/assuntos/inspecao-do-trabalho/areas-de-atuacao/cadastro_de_empregadores',
]
def pdf_mte():
    links = []
    for p in PAGINAS_MTE:
        try:
            h = get(p, 60).decode('utf-8', 'ignore')
            links += re.findall(r'href="([^"]*cadastro[_-]de[_-]empregadores[^"]*\.pdf[^"]*)"', h, re.I)
        except Exception as e:
            print('pagina MTE falhou', p, e)
    links = [l if l.startswith('http') else 'https://www.gov.br' + l for l in links]
    links.append('https://www.gov.br/trabalho-e-emprego/pt-br/cadastro_de_empregadores-14-julho-2025.pdf/@@download/file')
    vistos = []
    for l in links:
        if l in vistos: continue
        vistos.append(l)
        try:
            b = get(l)
            if b[:4] == b'%PDF':
                print('PDF MTE', l, len(b)); return b, l
        except Exception as e:
            print('pdf falhou', l, e)
    return None, None

def escravo():
    b, url = pdf_mte()
    if not b: return None
    with tempfile.NamedTemporaryFile(suffix='.pdf') as f:
        f.write(b); f.flush()
        txt = subprocess.run(['pdftotext', '-layout', f.name, '-'], capture_output=True, text=True).stdout
    out = {}
    for ln in txt.splitlines():
        m = re.search(r'(\d{2}\.\d{3}\.\d{3}/\d{4}-\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2})', ln)
        if not m: continue
        doc = so_dig(m.group(1))
        partes = [p.strip() for p in re.split(r'\s{2,}', ln.strip()) if p.strip()]
        datas = re.findall(r'\d{2}/\d{2}/\d{4}', ln)
        nome = next((p for p in partes if re.search(r'[A-Za-zÀ-ú]{3}', p) and m.group(1) not in p), '')
        uf = next((p for p in partes if re.fullmatch(r'[A-Z]{2}', p)), '')
        out[doc] = {'nome': nome[:120], 'uf': uf, 'trabalhadores': next((p for p in partes if re.fullmatch(r'\d{1,4}', p)), ''),
                    'decisao': datas[0] if datas else '', 'inclusao': datas[-1] if datas else '', 'linha': ln.strip()[:300]}
    print('lista suja', len(out))
    return {'fonte': url, 'atualizado': datetime.date.today().isoformat(), 'itens': out}

# ---------- Embargos Ibama ----------
CKAN = 'https://dadosabertos.ibama.gov.br/api/3/action/'
def recursos_ibama():
    urls = []
    for q in ('package_show?id=fiscalizacao-termo-de-embargo', 'package_search?q=termo%20de%20embargo&rows=10'):
        try:
            j = json.loads(get(CKAN + q, 60))
            res = j['result']
            pkgs = res['results'] if 'results' in res else [res]
            for p in pkgs:
                for r in p.get('resources', []):
                    u = r.get('url', '')
                    if re.search(r'\.(csv|zip)(\?|$)', u, re.I) and re.search(r'embargo', u + r.get('name', ''), re.I):
                        urls.append(u)
        except Exception as e:
            print('ckan falhou', q, e)
    return list(dict.fromkeys(urls))

def linhas_csv(b, nome):
    if b[:2] == b'PK':
        z = zipfile.ZipFile(io.BytesIO(b))
        for n in z.namelist():
            if n.lower().endswith('.csv'):
                yield from linhas_csv(z.read(n), n)
        return
    for enc in ('utf-8-sig', 'latin-1'):
        try:
            txt = b.decode(enc); break
        except UnicodeDecodeError:
            continue
    sep = ';' if txt[:2000].count(';') > txt[:2000].count(',') else ','
    for row in csv.DictReader(io.StringIO(txt), delimiter=sep):
        yield {(k or '').strip().upper(): (v or '').strip() for k, v in row.items()}

def campo(row, *pref):
    for p in pref:
        for k, v in row.items():
            if k.startswith(p) and v: return v
    return ''

def embargos():
    idx = {}
    for u in recursos_ibama():
        try:
            b = get(u, 300); print('ibama', u, len(b))
        except Exception as e:
            print('ibama falhou', u, e); continue
        for row in linhas_csv(b, u):
            doc = so_dig(campo(row, 'CPF_CNPJ_EMBARGADO', 'CPF_CNPJ_INFRATOR', 'CPF_CNPJ', 'NUM_CPF_CNPJ', 'CNPJ'))
            if len(doc) != 14: continue
            sit = campo(row, 'SIT_DESEMBARGO', 'DES_STATUS', 'SITUACAO')
            idx.setdefault(doc[:8], []).append({'cnpj': doc,
                'data': campo(row, 'DAT_EMBARGO', 'DATA_EMBARGO', 'DAT_HORA_AUTO', 'DATA'),
                'municipio': campo(row, 'MUNICIPIO', 'NOM_MUNICIPIO'), 'uf': campo(row, 'UF', 'SIG_UF'),
                'area': campo(row, 'QTD_AREA_EMBARGADA', 'AREA'), 'motivo': campo(row, 'DES_INFRACAO', 'DES_TAD', 'DESCRICAO', 'OBSERVACAO')[:240],
                'situacao': sit, 'termo': campo(row, 'NUM_TAD', 'NUM_TERMO', 'SEQ_TAD')})
        if idx: break
    print('embargos empresas', len(idx))
    return idx

def main():
    os.makedirs(OUT, exist_ok=True); ok = False
    e = escravo()
    if e and e['itens']:
        json.dump(e, open(f'{OUT}/escravo.json', 'w'), ensure_ascii=False, separators=(',', ':')); ok = True
    m = embargos()
    if m:
        os.makedirs(f'{OUT}/embargos', exist_ok=True); por = {}
        for r8, l in m.items(): por.setdefault(r8[:2], {})[r8] = l[:20]
        for p, d in por.items(): json.dump(d, open(f'{OUT}/embargos/{p}.json', 'w'), ensure_ascii=False, separators=(',', ':'))
        ok = True
    json.dump({'atualizado': datetime.date.today().isoformat(), 'escravo': len(e['itens']) if e else 0, 'embargos': len(m)}, open(f'{OUT}/meta.json', 'w'))
    if not ok: sys.exit(1)

if __name__ == '__main__':
    main()
