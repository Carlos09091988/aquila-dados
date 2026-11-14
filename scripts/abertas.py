"""Robô das licitações abertas (Vince · consulta grátis e busca rápida).

Baixa da API oficial de consultas do PNCP todas as contratações com proposta aberta
(endpoint /contratacoes/proposta), estado por estado, com várias conexões ao mesmo tempo.
O site da Desenvolve importa estes arquivos e faz as buscas na própria base, sem esperar o PNCP.

Saída: data/abertas/UF.json (um arquivo por estado) e data/abertas/indice.json.
Cada item é uma lista curta:
  [id_pncp, uf, ibge, municipio, orgao_cnpj, orgao, unidade, modalidade_id, modalidade, objeto, valor, publicado, encerramento, link]
Se um estado vier incompleto (páginas que falharam), o arquivo anterior desse estado é mantido.
Dados públicos (Lei 14.133/2021, art. 174).
"""
import json, os, sys, time, hashlib, datetime as dt
import urllib.request, urllib.error
from concurrent.futures import ThreadPoolExecutor

BASE = 'https://pncp.gov.br/api/consulta/v1/contratacoes/proposta'
OUT = os.path.join(os.path.dirname(__file__), '..', 'data', 'abertas')
UFS = 'AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split()
FIM = (dt.date.today() + dt.timedelta(days=1500)).strftime('%Y%m%d')
LIMITE_SEG = int(os.environ.get('LIMITE_SEG', 2700))
THREADS = int(os.environ.get('THREADS', 8))
T0 = time.time()
UA = {'Accept': 'application/json', 'User-Agent': 'Mozilla/5.0 (compatible; DesenvolveRobo/1.0; +https://desenvolveestrategias.com.br)'}


def get(uf, pag, tent=5):
    url = f'{BASE}?dataFinal={FIM}&uf={uf}&pagina={pag}&tamanhoPagina=50'
    for i in range(tent):
        if time.time() - T0 > LIMITE_SEG:
            return None
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=90) as r:
                if r.status == 204:
                    return {'data': [], 'totalPaginas': 0}
                return json.loads(r.read().decode('utf-8') or '{}')
        except urllib.error.HTTPError as e:
            if e.code in (204, 400, 404, 422):
                return {'data': [], 'totalPaginas': 0}
        except Exception:
            pass
        time.sleep(3 * (i + 1))
    return None


def dtm(s):
    s = str(s or '')
    return s[:19].replace('T', ' ') if len(s) >= 10 else ''


def item(x):
    u = x.get('unidadeOrgao') or {}
    o = x.get('orgaoEntidade') or {}
    cn = ''.join(c for c in str(o.get('cnpj') or '') if c.isdigit())
    ano, seq = x.get('anoCompra'), x.get('sequencialCompra')
    link = f'https://pncp.gov.br/app/editais/{cn}/{ano}/{seq}' if cn and ano and seq else (x.get('linkSistemaOrigem') or '')
    ob = ' '.join(str(x.get('objetoCompra') or '').split())[:600]
    try:
        ibge = int(u.get('codigoIbge') or 0)
    except Exception:
        ibge = 0
    return [x.get('numeroControlePNCP') or '', (u.get('ufSigla') or '')[:2], ibge, u.get('municipioNome') or '', cn,
            (o.get('razaoSocial') or '')[:220], (u.get('nomeUnidade') or '')[:220], int(x.get('modalidadeId') or 0),
            x.get('modalidadeNome') or '', ob, float(x.get('valorTotalEstimado') or 0), dtm(x.get('dataPublicacaoPncp')),
            dtm(x.get('dataEncerramentoProposta')), link[:255]]


def main():
    os.makedirs(OUT, exist_ok=True)
    # 1) primeira página de cada estado: total de páginas
    with ThreadPoolExecutor(THREADS) as ex:
        primeiras = dict(zip(UFS, ex.map(lambda uf: get(uf, 1), UFS)))
    tarefas, pags = [], {}
    for uf, j in primeiras.items():
        if j is None:
            pags[uf] = None
            continue
        pags[uf] = int(j.get('totalPaginas') or 0)
        tarefas += [(uf, p) for p in range(2, pags[uf] + 1)]
    print('paginas por estado:', pags, 'total', sum(v or 0 for v in pags.values()), flush=True)
    # 2) demais páginas, maiores estados misturados para usar bem as conexões
    with ThreadPoolExecutor(THREADS) as ex:
        res = list(ex.map(lambda t: (t, get(*t)), tarefas))
    dados = {uf: ([] if primeiras[uf] is None else list(primeiras[uf].get('data') or [])) for uf in UFS}
    falhas = {uf: (1 if primeiras[uf] is None else 0) for uf in UFS}
    for (uf, p), j in res:
        if j is None:
            falhas[uf] += 1
        else:
            dados[uf] += j.get('data') or []
    ind_path = os.path.join(OUT, 'indice.json')
    try:
        ind = json.load(open(ind_path))
    except Exception:
        ind = {'ufs': {}}
    agora = dt.datetime.utcnow() - dt.timedelta(hours=3)
    corte = agora.strftime('%Y-%m-%d %H:%M:%S')
    for uf in UFS:
        if falhas[uf] or pags[uf] is None:
            print(f'{uf}: {falhas[uf]} páginas falharam, mantém o arquivo anterior', flush=True)
            continue
        vistos, linhas = set(), []
        for x in dados[uf]:
            it = item(x)
            if not it[0] or it[0] in vistos or (it[12] and it[12] < corte):
                continue
            vistos.add(it[0])
            linhas.append(it)
        linhas.sort(key=lambda r: r[11], reverse=True)
        txt = json.dumps(linhas, ensure_ascii=False, separators=(',', ':'))
        open(os.path.join(OUT, f'{uf}.json'), 'w').write(txt)
        ind['ufs'][uf] = {'n': len(linhas), 'sha': hashlib.sha1(txt.encode()).hexdigest()[:12], 'quando': agora.strftime('%Y-%m-%d %H:%M')}
    ind['quando'] = agora.strftime('%Y-%m-%d %H:%M')
    ind['total'] = sum(v['n'] for v in ind['ufs'].values())
    ind['falhas'] = {k: v for k, v in falhas.items() if v}
    json.dump(ind, open(ind_path, 'w'), ensure_ascii=False, indent=1)
    print('total', ind['total'], 'falhas', ind['falhas'], 'tempo', int(time.time() - T0), 's', flush=True)


if __name__ == '__main__':
    main()
