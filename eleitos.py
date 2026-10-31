#!/usr/bin/env python3
"""Eleitos (TSE, dados abertos): prefeito, vice e vereadores de cada município (2024) e governador/vice (2022).
Saída: eleitos/<UF>.json -> {"MUNICIPIO": {"p": [nome, urna, partido], "v": [...], "c": [[urna, partido], ...]}, "_GOV": {"p": [...], "v": [...]}}
"""
import csv, io, json, os, re, unicodedata, zipfile
import requests

OUT = os.environ.get('OUT', 'eleitos')
BASE = 'https://cdn.tse.jus.br/estatistica/sense/dados/consulta_cand/consulta_cand_{}.zip'

def norm(s):
    s = unicodedata.normalize('NFKD', str(s or '')).encode('ascii', 'ignore').decode().upper()
    return re.sub(r'\s+', ' ', re.sub(r'[^A-Z0-9 ]+', ' ', s)).strip()

def linhas(ano):
    path = f'/tmp/cand_{ano}.zip'
    if not os.path.exists(path):
        with requests.get(BASE.format(ano), stream=True, timeout=600) as r:
            r.raise_for_status()
            with open(path, 'wb') as f:
                for ch in r.iter_content(1 << 20): f.write(ch)
    z = zipfile.ZipFile(path)
    nomes = [n for n in z.namelist() if n.lower().endswith('.csv')]
    brasil = [n for n in nomes if 'BRASIL' in n.upper()]
    for n in (brasil or nomes):
        with z.open(n) as f:
            for row in csv.DictReader(io.TextIOWrapper(f, encoding='latin-1'), delimiter=';'):
                yield row

def eleito(r):
    s = norm(r.get('DS_SIT_TOT_TURNO', ''))
    return s.startswith('ELEITO')

def main():
  dados = {}
  for r in linhas(2024):
      if not eleito(r): continue
      uf = r['SG_UF']; mun = norm(r['NM_UE']); cargo = r['CD_CARGO']
      d = dados.setdefault(uf, {}).setdefault(mun, {'p': None, 'v': None, 'c': []})
      pess = [r['NM_CANDIDATO'].strip(), r['NM_URNA_CANDIDATO'].strip(), r['SG_PARTIDO'].strip()]
      if cargo == '11': d['p'] = pess
      elif cargo == '12': d['v'] = pess
      elif cargo == '13': d['c'].append(pess[1:])
  for r in linhas(2022):
      if not eleito(r) or r['CD_CARGO'] not in ('3', '4'): continue
      uf = r['SG_UF']; g = dados.setdefault(uf, {}).setdefault('_GOV', {'p': None, 'v': None})
      pess = [r['NM_CANDIDATO'].strip(), r['NM_URNA_CANDIDATO'].strip(), r['SG_PARTIDO'].strip()]
      if r['CD_CARGO'] == '3': g['p'] = pess
      else: g['v'] = pess
  os.makedirs(OUT, exist_ok=True)
  for uf, m in dados.items():
      for v in m.values():
          if 'c' in v: v['c'].sort()
      with open(os.path.join(OUT, f'{uf}.json'), 'w', encoding='utf-8') as f:
          json.dump(m, f, ensure_ascii=False, separators=(',', ':'))
  print({uf: len(m) for uf, m in sorted(dados.items())})


if __name__ == '__main__':
    import traceback, sys
    try:
        main()
    except Exception:
        t = traceback.format_exc().replace('\n', ' | ')
        print('::error::' + t[-900:])
        sys.exit(1)
