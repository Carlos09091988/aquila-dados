#!/usr/bin/env python3
"""Índice de busca do catálogo de materiais (CATMAT) e serviços (CATSER) do Compras.gov.br.

Gera (pasta cat/):
  w/<2 primeiras letras>.json.gz -> {palavra: ["M150486", "S23477", ...]}
  d/<código % 400>.json.gz       -> {"M150486": ["descrição", "PDM ou classe"]}
  meta.json
"""
import datetime as dt, gzip, json, os, re, time, unicodedata
from collections import defaultdict
import requests

API = 'https://dadosabertos.compras.gov.br'
OUT = os.environ.get('OUT', 'cat')
UA = {'User-Agent': 'Mozilla/5.0 (compatible; AquilaRaioX/1.0; +https://aquilaadvocacia.com.br)'}
PARE = set('DE DA DO DAS DOS E EM PARA COM SEM POR A O AS OS UM UMA TIPO MATERIAL COR NA NO NAS NOS AO'.split())


def log(*a): print(dt.datetime.now().strftime('%H:%M:%S'), *a, flush=True)


def norm(s):
    s = unicodedata.normalize('NFKD', str(s or '')).encode('ascii', 'ignore').decode().upper()
    return re.sub(r'\s+', ' ', re.sub(r'[^A-Z0-9]+', ' ', s)).strip()


def palavras(s):
    out = set()
    for w in norm(s).split():
        if len(w) < 2 or w in PARE: continue
        out.add(w)
        if len(w) > 4 and w.endswith('S'): out.add(w[:-1])
    return out


def paginas(path, params):
    p = 1
    while True:
        for t in range(5):
            try:
                r = requests.get(API + path, params={**params, 'pagina': p, 'tamanhoPagina': 500}, headers=UA, timeout=120)
                if r.status_code == 200: break
            except Exception as e:
                log('erro', e)
            time.sleep(5 * (t + 1))
        else:
            raise SystemExit(f'Falha em {path} página {p}')
        d = r.json(); res = d.get('resultado') or []
        yield from res
        if p >= (d.get('totalPaginas') or 0) or not res: break
        p += 1
        if p % 50 == 0: log(path, p, '/', d.get('totalPaginas'))


def main():
    itens = {}
    for x in paginas('/modulo-material/4_consultarItemMaterial', {'statusItem': 'true'}):
        itens['M%d' % x['codigoItem']] = [re.sub(r'\s+', ' ', (x.get('descricaoItem') or '')).strip()[:220], x.get('nomePdm') or '']
    log('materiais', len(itens))
    for x in paginas('/modulo-servico/6_consultarItemServico', {'statusServico': 'true'}):
        itens['S%d' % x['codigoServico']] = [re.sub(r'\s+', ' ', (x.get('nomeServico') or '')).strip()[:220], x.get('nomeClasse') or '']
    log('total', len(itens))
    idx = defaultdict(list)
    for k, (desc, grupo) in itens.items():
        for w in palavras(desc + ' ' + grupo): idx[w].append(k)
    shards = defaultdict(dict)
    for w, l in idx.items(): shards[w[:2]][w] = l
    os.makedirs(os.path.join(OUT, 'w'), exist_ok=True); os.makedirs(os.path.join(OUT, 'd'), exist_ok=True)
    for sh, d in shards.items():
        with gzip.open(os.path.join(OUT, 'w', sh + '.json.gz'), 'wt', encoding='utf-8') as f: json.dump(d, f, separators=(',', ':'))
    ds = defaultdict(dict)
    for k, v in itens.items(): ds[int(k[1:]) % 400][k] = v
    for sh, d in ds.items():
        with gzip.open(os.path.join(OUT, 'd', '%d.json.gz' % sh), 'wt', encoding='utf-8') as f: json.dump(d, f, ensure_ascii=False, separators=(',', ':'))
    json.dump({'gerado_em': dt.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'), 'itens': len(itens), 'palavras': len(idx)}, open(os.path.join(OUT, 'meta.json'), 'w'))
    log('pronto', len(itens), 'itens', len(idx), 'palavras', len(shards), 'arquivos')


if __name__ == '__main__': main()
