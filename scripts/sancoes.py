"""Baixa o CEIS e o CNEP (Portal da Transparência, dados abertos) e publica um índice por raiz de CNPJ.
Saída: data/sancoes/<2 primeiros dígitos>.json  ->  {raiz8: [ {cad, tipo, orgao, uf, inicio, fim, lei, processo} ]}
"""
import csv, io, json, os, sys, zipfile, datetime, urllib.request

BASE = 'https://portaldatransparencia.gov.br/download-de-dados/%s/%s'
OUT = 'data/sancoes'

def baixar(cad):
    hoje = datetime.date.today()
    for d in range(0, 8):
        dia = (hoje - datetime.timedelta(days=d)).strftime('%Y%m%d')
        try:
            req = urllib.request.Request(BASE % (cad, dia), headers={'Accept': '*/*'})
            with urllib.request.urlopen(req, timeout=120) as r:
                b = r.read()
            if b[:2] == b'PK':
                print(cad, dia, len(b)); return zipfile.ZipFile(io.BytesIO(b))
        except Exception as e:
            print(cad, dia, 'falhou', e)
    return None

def col(row, *nomes):
    up = {(k or '').strip().upper(): v for k, v in row.items()}
    for n in nomes:  # primeiro o nome exato da coluna
        if n in up:
            return (up[n] or '').strip()
    for n in nomes:  # depois o começo do nome
        for k, v in up.items():
            if k.startswith(n):
                return (v or '').strip()
    return ''

def main():
    idx = {}
    for cad in ('ceis', 'cnep'):
        z = baixar(cad)
        if not z:
            print('sem arquivo', cad); continue
        for nome in z.namelist():
            if not nome.lower().endswith('.csv'):
                continue
            txt = z.read(nome).decode('latin-1')
            for row in csv.DictReader(io.StringIO(txt), delimiter=';'):
                doc = ''.join(c for c in col(row, 'CPF OU CNPJ DO SANCIONADO', 'CPF OU CNPJ') if c.isdigit())
                if len(doc) != 14:
                    continue
                r8 = doc[:8]
                idx.setdefault(r8, []).append({
                    'cad': cad.upper(), 'cnpj': doc,
                    'tipo': col(row, 'CATEGORIA DA SANÇÃO', 'CATEGORIA DA SANCAO', 'TIPO DE SANÇÃO'),
                    'orgao': col(row, 'ÓRGÃO SANCIONADOR', 'ORGAO SANCIONADOR'),
                    'esfera': col(row, 'ESFERA ÓRGÃO SANCIONADOR', 'ESFERA ORGAO SANCIONADOR'),
                    'uf': col(row, 'UF ÓRGÃO SANCIONADOR', 'UF ORGAO SANCIONADOR'),
                    'inicio': col(row, 'DATA INÍCIO SANÇÃO', 'DATA INICIO SANCAO', 'DATA INÍCIO'),
                    'fim': col(row, 'DATA FINAL SANÇÃO', 'DATA FINAL SANCAO', 'DATA FINAL'),
                    'lei': col(row, 'FUNDAMENTAÇÃO LEGAL', 'FUNDAMENTACAO LEGAL'),
                    'processo': col(row, 'NÚMERO DO PROCESSO', 'NUMERO DO PROCESSO'),
                })
    if not idx:
        print('nada baixado'); sys.exit(1)
    os.makedirs(OUT, exist_ok=True)
    por = {}
    for r8, l in idx.items():
        por.setdefault(r8[:2], {})[r8] = l[:30]
    for p, d in por.items():
        with open(f'{OUT}/{p}.json', 'w', encoding='utf-8') as f:
            json.dump(d, f, ensure_ascii=False, separators=(',', ':'))
    with open(f'{OUT}/meta.json', 'w') as f:
        json.dump({'atualizado': datetime.date.today().isoformat(), 'empresas': len(idx)}, f)
    print('empresas', len(idx))

if __name__ == '__main__':
    main()
