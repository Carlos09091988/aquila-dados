"""STJ (dados abertos, espelhos de acordaos): guarda so os acordaos sobre licitacao. Incremental."""
import json, os, re, urllib.request
BASE = 'https://dadosabertos.web.stj.jus.br'
DS = ['espelhos-de-acordaos-corte-especial', 'espelhos-de-acordaos-primeira-secao', 'espelhos-de-acordaos-primeira-turma', 'espelhos-de-acordaos-segunda-turma']
OUT = 'aquila/jur/stj-licitacoes.json'; EST = 'aquila/jur/stj-estado.json'
def get(u, t=300):
    return urllib.request.urlopen(urllib.request.Request(u, headers={'User-Agent': 'aquila-dados (GitHub Actions)'}), timeout=t).read()
dados = json.load(open(OUT)) if os.path.exists(OUT) else []
feitos = set(json.load(open(EST))) if os.path.exists(EST) else set()
vistos = {d['id'] for d in dados}
LEI = re.compile(r'LEI:0*(8666|14133|10520|12462|13303)\b|licita[cç][aã]o|licitat[oó]ri|preg[aã]o eletr|certame', re.I)
for ds in DS:
    try: res = json.loads(get(f'{BASE}/api/3/action/package_show?id={ds}'))['result']['resources']
    except Exception as e: print('pacote', ds, e); continue
    js = sorted([r for r in res if r['url'].lower().endswith('.json')], key=lambda r: r['url'])[-40:]
    for r in js:
        if r['id'] in feitos: continue
        try: lista = json.loads(get(r['url']))
        except Exception as e: print('arquivo', r['url'], e); continue
        n = 0
        for a in lista if isinstance(lista, list) else []:
            em = a.get('ementa') or ''; ref = a.get('referenciasLegislativas') or ''
            ref = ' '.join(ref) if isinstance(ref, list) else str(ref)
            txt = em + ' ' + ref + ' ' + str(a.get('termosAuxiliares') or '')
            if not LEI.search(txt) or not re.search(r'licita|preg[aã]o|certame|contrata[cç][aã]o direta|dispensa de licita|inexigibilidade', em, re.I): continue
            if a.get('id') in vistos: continue
            vistos.add(a.get('id')); n += 1
            dados.append({'id': a.get('id'), 'proc': a.get('numeroProcesso'), 'classe': a.get('siglaClasse'), 'orgao': a.get('nomeOrgaoJulgador'),
                          'rel': a.get('ministroRelator'), 'e': re.sub(r'\s+', ' ', em).strip()[:2500], 'tese': a.get('teseJuridica') or '',
                          'd': a.get('dataDecisao'), 'pub': a.get('dataPublicacao')})
        feitos.add(r['id']); print(ds, r['url'].rsplit('/', 1)[-1], n)
json.dump(dados, open(OUT, 'w'), ensure_ascii=False, separators=(',', ':'))
json.dump(sorted(feitos), open(EST, 'w'))
print('total', len(dados))
