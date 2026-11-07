"""Baixa a CAPAG (capacidade de pagamento, Tesouro Nacional) de municípios e estados e grava data/capag/capag.json.
Fonte: Tesouro Transparente (CKAN). Saída: {"m": {ibge7: nota}, "e": {UF: nota}, "ref": {...}}"""
import io, json, os, re, sys, unicodedata, urllib.request
import pandas as pd

CK = 'https://www.tesourotransparente.gov.br/ckan/api/3/action/'
UA = {'User-Agent': 'Mozilla/5.0 (compatible; DesenvolveBot/1.0)'}


def get(u, raw=False):
    with urllib.request.urlopen(urllib.request.Request(u, headers=UA), timeout=120) as r:
        b = r.read()
    return b if raw else json.loads(b)


def nm(s):
    s = unicodedata.normalize('NFKD', str(s)).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'\s+', ' ', s).strip()


def nota(v):
    v = str(v or '').strip().upper().replace(' ', '')
    m = re.match(r'^(A\+|A|B\+|B|C|D)$', v)
    return m.group(1) if m else ('n.d.' if v in ('N.D.', 'ND', 'N.D', 'NÃOAPLICÁVEL', 'NAOAPLICAVEL') else '')


def tabelas(conteudo, fmt):
    if fmt in ('csv', 'txt'):
        for enc in ('utf-8-sig', 'latin-1'):
            for sep in (';', ','):
                try:
                    df = pd.read_csv(io.BytesIO(conteudo), sep=sep, encoding=enc, dtype=str)
                    if df.shape[1] > 3:
                        yield 'csv', df; return
                except Exception:  # noqa: BLE001
                    pass
        return
    xl = pd.ExcelFile(io.BytesIO(conteudo))
    for sh in xl.sheet_names:
        raw = xl.parse(sh, header=None, dtype=str)
        # acha a linha de cabeçalho (a que tem "capag" e "uf")
        for i in range(min(15, len(raw))):
            row = [nm(x) for x in raw.iloc[i].tolist()]
            if any('capag' in x or 'classificacao' in x for x in row) and any(x == 'uf' for x in row):
                df = raw.iloc[i + 1:].copy(); df.columns = [str(x) for x in raw.iloc[i].tolist()]
                yield sh, df; break


def extrai(df):
    cols = {nm(c): c for c in df.columns}
    cod = next((cols[c] for c in cols if ('codigo' in c and 'munic' in c) or 'cod. ibge' in c or c in ('cod_ibge', 'codigo ibge', 'ibge', 'cod ibge')), None)
    cap = next((cols[c] for c in cols if c == 'capag'), None) or next((cols[c] for c in cols if 'classificacao' in c and 'capag' in c), None) or next((cols[c] for c in cols if c.startswith('capag') and 'antes' not in c), None)
    uf = next((cols[c] for c in cols if c == 'uf'), None)
    return cod, cap, uf


def main():
    out = {'m': {}, 'e': {}, 'ref': {}}
    pk = get(CK + 'package_search?q=capag&rows=50')['result']['results']
    print('pacotes:', [p['name'] for p in pk], flush=True)
    for p in pk:
        for r in sorted(p.get('resources', []), key=lambda r: r.get('last_modified') or r.get('created') or '', reverse=True):
            fmt = (r.get('format') or '').lower()
            if fmt not in ('xlsx', 'xls', 'csv'):
                continue
            nome = nm(r.get('name') or '') + ' ' + nm(p.get('name') or '')
            try:
                b = get(r['url'], raw=True)
            except Exception as e:  # noqa: BLE001
                print('falhou', r.get('name'), e, flush=True); continue
            for sh, df in tabelas(b, fmt):
                cod, cap, uf = extrai(df)
                print('recurso:', r.get('name'), '| aba:', sh, '| cod:', cod, '| capag:', cap, '| uf:', uf, '| linhas:', len(df), flush=True)
                if not cap or not uf:
                    continue
                estadual = ('estado' in nome or 'uf' in nome.split()) and not cod
                n = 0
                for _, row in df.iterrows():
                    v = nota(row.get(cap))
                    if not v:
                        continue
                    u = str(row.get(uf) or '').strip().upper()[:2]
                    if cod and re.match(r'^\d{6,7}', str(row.get(cod) or '')):
                        k = re.match(r'^\d{6,7}', str(row.get(cod))).group(0)
                        if k not in out['m']:
                            out['m'][k] = v; n += 1
                    elif estadual and re.match(r'^[A-Z]{2}$', u) and u not in out['e']:
                        out['e'][u] = v; n += 1
                if n:
                    out['ref'][r.get('name') or r['url']] = {'aba': sh, 'novos': n, 'modificado': r.get('last_modified') or r.get('created')}
                    print('  +', n, flush=True)
    print('municípios:', len(out['m']), 'estados:', len(out['e']), flush=True)
    if len(out['m']) < 1000:
        print('poucos municípios, não grava', flush=True); sys.exit(1)
    os.makedirs('data/capag', exist_ok=True)
    json.dump(out, open('data/capag/capag.json', 'w'), ensure_ascii=False, separators=(',', ':'))


if __name__ == '__main__':
    main()
