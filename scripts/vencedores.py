"""Robô dos vencedores (Vince · alerta de vencedores).

Todo dia baixa do PNCP os contratos publicados nos últimos dias e guarda quem venceu:
empresa (CNPJ e nome), órgão, cidade, objeto, valor e vigência. Alimenta o alerta de
vencedores da plataforma e a prospecção de seguro garantia da Desenvolve.

Fica de fora: pessoa física, empenhos, contratos de receita e de alienação de bens,
contratos abaixo de R$ 10 mil e os assinados há mais de 60 dias (publicação atrasada).

Saída: data/vencedores/dias/AAAA-MM-DD-N.json (por dia de publicação, em partes de até
2.000 contratos) e data/vencedores/indice.json. Guarda 30 dias.
Cada item é uma lista curta para o arquivo ficar leve:
  [id, publicado, assinatura, uf, municipio, orgao_cnpj, orgao, cnpj, empresa, objeto, valor, fim_vigencia, categoria]
Dados públicos (Lei 14.133/2021, art. 174).
"""
import json, os, re, sys, time, datetime as dt
import urllib.request, urllib.parse, urllib.error
from concurrent.futures import ThreadPoolExecutor

BASE = 'https://pncp.gov.br/api/consulta/v1'
OUT = os.path.join(os.path.dirname(__file__), '..', 'data', 'vencedores')
HOJE = dt.date.today()
DIAS = int(os.environ.get('DIAS', 3))          # janela de publicação relida a cada rodada
GUARDA = 30                                    # dias mantidos no repositório
PARTE = 2000
VALOR_MIN = 10000
LIMITE_SEG = int(os.environ.get('LIMITE_SEG', 2400))
T0 = time.time()
UA = {'Accept': 'application/json', 'User-Agent': 'Mozilla/5.0 (compatible; DesenvolveRobo/1.0; +https://desenvolveestrategias.com.br)'}
CAT = {'Compras': 'C', 'Serviços': 'S', 'Obras': 'O', 'Serviços de Engenharia': 'E', 'Informática (TIC)': 'T', 'Locação Imóveis': 'L'}


def get(url, tent=4):
    for i in range(tent):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
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


def data(s):
    try:
        return dt.date.fromisoformat(str(s)[:10])
    except Exception:
        return None


def item(c):
    if c.get('tipoPessoa') != 'PJ' or c.get('receita'):
        return None
    if (c.get('tipoContrato') or {}).get('nome') == 'Empenho':
        return None
    cat = (c.get('categoriaProcesso') or {}).get('nome') or ''
    if 'Aliena' in cat:
        return None
    cnpj = re.sub(r'\D', '', str(c.get('niFornecedor') or ''))
    if len(cnpj) != 14:
        return None
    v = float(c.get('valorGlobal') or c.get('valorInicial') or 0)
    if v < VALOR_MIN:
        return None
    pub, ass = data(c.get('dataPublicacaoPncp')), data(c.get('dataAssinatura'))
    if not pub or (ass and (pub - ass).days > 60):
        return None
    org, uni = c.get('orgaoEntidade') or {}, c.get('unidadeOrgao') or {}
    uf = (uni.get('ufSigla') or '').upper()
    if len(uf) != 2:
        return None
    return [
        c.get('numeroControlePNCP') or '', pub.isoformat(), ass.isoformat() if ass else '', uf,
        (uni.get('municipioNome') or '')[:60], re.sub(r'\D', '', str(org.get('cnpj') or '')),
        (org.get('razaoSocial') or '')[:100], cnpj, (c.get('nomeRazaoSocialFornecedor') or '')[:100],
        re.sub(r'\s+', ' ', str(c.get('objetoContrato') or '')).strip()[:240], round(v, 2),
        str(c.get('dataVigenciaFim') or '')[:10], CAT.get(cat, ''),
    ]


def dia(d):
    """Todos os contratos publicados no dia d. None se o PNCP não responder."""
    def url(p):
        q = urllib.parse.urlencode({'dataInicial': d.strftime('%Y%m%d'), 'dataFinal': d.strftime('%Y%m%d'), 'pagina': p, 'tamanhoPagina': 500})
        return f'{BASE}/contratos?{q}'
    j = get(url(1))
    if j is None:
        return None
    pags, total, falta = [j], j.get('totalPaginas') or 0, []
    if total > 1:
        falta = list(range(2, total + 1))
        for rodada in range(3):   # o PNCP falha em páginas soltas: tenta de novo só as que faltaram
            with ThreadPoolExecutor(max_workers=4 if rodada == 0 else 2) as ex:
                res = list(ex.map(lambda p: (p, get(url(p))), falta))
            pags += [r for _, r in res if r]
            falta = [p for p, r in res if r is None]
            if not falta:
                break
            time.sleep(20 * (rodada + 1))
    o = {}
    for pg in pags:
        for c in pg.get('data') or []:
            x = item(c)
            if x and x[0]:
                o[x[0]] = x
    return sorted(o.values(), key=lambda x: -x[10]), len(falta)


def main():
    pasta = os.path.join(OUT, 'dias')
    os.makedirs(pasta, exist_ok=True)
    try:
        idx = json.load(open(os.path.join(OUT, 'indice.json'), encoding='utf-8'))
    except Exception:
        idx = {}
    dias = idx.get('dias', {})
    incompletos = sorted((k for k, v in dias.items() if v.get('faltam_paginas')), reverse=True)
    n = DIAS if len(dias) >= 20 else max(DIAS, GUARDA)   # sem a base completa: recarrega os últimos 30 dias
    fila = [HOJE - dt.timedelta(days=k) for k in range(n)]
    fila += [dt.date.fromisoformat(k) for k in incompletos if dt.date.fromisoformat(k) not in fila]
    for d in fila:
        if time.time() - T0 > LIMITE_SEG:
            break
        r = dia(d)
        if r is None:
            print(d, 'PNCP não respondeu; fica para a próxima rodada', flush=True)
            continue
        lst, falta = r
        ds = d.isoformat()
        if falta:   # dia incompleto: soma ao que já estava salvo em vez de apagar
            ant = {}
            for f in sorted(os.listdir(pasta)):
                if f.startswith(ds + '-'):
                    for x in json.load(open(os.path.join(pasta, f), encoding='utf-8')):
                        ant[x[0]] = x
            ant.update({x[0]: x for x in lst})
            lst = sorted(ant.values(), key=lambda x: -x[10])
        for f in os.listdir(pasta):
            if f.startswith(ds + '-'):
                os.remove(os.path.join(pasta, f))
        partes = max(1, -(-len(lst) // PARTE))
        for p in range(partes):
            with open(os.path.join(pasta, f'{ds}-{p + 1}.json'), 'w', encoding='utf-8') as fh:
                json.dump(lst[p * PARTE:(p + 1) * PARTE], fh, ensure_ascii=False, separators=(',', ':'))
        dias[ds] = {'n': len(lst), 'partes': partes, 'valor': round(sum(x[10] for x in lst), 2), 'lido': dt.datetime.now().isoformat(timespec='minutes'), 'faltam_paginas': falta}
        print(ds, len(lst), 'contratos', ('(faltaram %d páginas)' % falta) if falta else '', flush=True)
    corte = (HOJE - dt.timedelta(days=GUARDA)).isoformat()
    for f in os.listdir(pasta):
        if f[:10] < corte:
            os.remove(os.path.join(pasta, f))
    dias = {k: v for k, v in dias.items() if k >= corte}
    with open(os.path.join(OUT, 'indice.json'), 'w', encoding='utf-8') as fh:
        json.dump({'gerado': dt.datetime.now().isoformat(timespec='minutes'), 'campos': ['id', 'publicado', 'assinatura', 'uf', 'municipio', 'orgao_cnpj', 'orgao', 'cnpj', 'empresa', 'objeto', 'valor', 'fim', 'categoria'], 'dias': dict(sorted(dias.items()))}, fh, ensure_ascii=False, indent=1)


if __name__ == '__main__':
    main()
