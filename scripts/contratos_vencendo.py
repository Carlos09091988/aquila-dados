"""Radar de contratos vencendo (Desenvolve).

Baixa do PNCP os contratos de serviços contínuos de "facilities" (limpeza, vigilância,
portaria, manutenção, alimentação, apoio etc.) e guarda, por UF, os que vencem nos
próximos 18 meses. Dados públicos (Lei 14.133/2021, art. 174).

Modos:
  carga      -> percorre as publicações desde CARGA_DESDE em janelas, retomando de onde parou
  semanal    -> pega o que foi publicado/atualizado nos últimos 10 dias e limpa os vencidos
Saída: data/contratos/uf/<UF>.json, data/contratos/indice.json, data/contratos/estado.json
"""
import json, os, re, sys, time, unicodedata, datetime as dt
import urllib.request, urllib.parse, urllib.error

BASE = 'https://pncp.gov.br/api/consulta/v1'
OUT = os.path.join(os.path.dirname(__file__), '..', 'data', 'contratos')
CARGA_DESDE = dt.date(2021, 4, 1)          # início da Lei 14.133 / PNCP
JANELA = 3                                 # dias por consulta
LIMITE_SEG = int(os.environ.get('LIMITE_SEG', 5 * 3600))
HOJE = dt.date.today()
MIN_FIM = HOJE - dt.timedelta(days=15)
MAX_FIM = HOJE + dt.timedelta(days=548)

RAMOS = {
    'limpeza': r'limpeza|conservacao|higienizacao|asseio|zeladoria',
    'vigilancia': r'vigilancia|seguranca patrimonial|vigilante|monitoramento eletronico|seguranca desarmada|seguranca armada',
    'portaria': r'portaria|recepcao|recepcionista|controlador de acesso|controle de acesso',
    'manutencao': r'manutencao predial|manutencao preventiva e corretiva|ar condicionado|climatizacao|elevador|instalacoes eletricas|instalacoes hidraulicas|facilities',
    'alimentacao': r'alimentacao|refeicoes|refeicao|merenda|nutricao|cozinha|restaurante|marmitex|lanches',
    'apoio': r'apoio administrativo|mao de obra|mao-de-obra|copeiragem|copeira|terceirizacao|telefonista|auxiliar de servicos|servicos gerais',
    'jardinagem': r'jardinagem|rocada|rocagem|paisagismo|poda de arvores',
    'transporte': r'transporte escolar|veiculos com motorista|fretamento|motorista',
    'lavanderia': r'lavanderia|enxoval|rouparia',
    'residuos': r'coleta de lixo|residuos solidos|residuos de saude|limpeza urbana|varricao',
}
RAMOS = {k: re.compile(v) for k, v in RAMOS.items()}
EXCLUI = re.compile(r'aquisicao de|compra de|fornecimento de materia|material de limpeza|produtos de limpeza|generos alimenticios|locacao de imovel')


def norm(t):
    t = unicodedata.normalize('NFD', str(t or '').lower())
    return ''.join(c for c in t if unicodedata.category(c) != 'Mn')


def ramo(objeto):
    o = norm(objeto)
    if EXCLUI.search(o):
        return None
    for k, rx in RAMOS.items():
        if rx.search(o):
            return k
    return None


def get(url, tent=4):
    for i in range(tent):
        try:
            req = urllib.request.Request(url, headers={'Accept': 'application/json'})
            with urllib.request.urlopen(req, timeout=60) as r:
                if r.status == 204:
                    return {'data': [], 'totalPaginas': 0}
                return json.loads(r.read().decode('utf-8') or '{}')
        except urllib.error.HTTPError as e:
            if e.code == 204:
                return {'data': [], 'totalPaginas': 0}
            if e.code in (400, 404, 422):
                return {'data': [], 'totalPaginas': 0, 'erro': e.code}
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
    fim = data(c.get('dataVigenciaFim'))
    if not fim or fim < MIN_FIM or fim > MAX_FIM:
        return None
    r = ramo(c.get('objetoContrato'))
    if not r:
        return None
    org = c.get('orgaoEntidade') or {}
    uni = c.get('unidadeOrgao') or {}
    uf = (uni.get('ufSigla') or '').upper()
    if len(uf) != 2:
        return None
    ctrl = c.get('numeroControlePNCP') or ''
    return uf, ctrl, {
        'id': ctrl,
        'oc': re.sub(r'\D', '', str(org.get('cnpj') or '')),
        'on': (org.get('razaoSocial') or '')[:120],
        'un': (uni.get('nomeUnidade') or '')[:100],
        'mu': uni.get('municipioNome') or '',
        'fc': re.sub(r'\D', '', str(c.get('niFornecedor') or '')),
        'fn': (c.get('nomeRazaoSocialFornecedor') or '')[:120],
        'ob': re.sub(r'\s+', ' ', str(c.get('objetoContrato') or ''))[:220],
        'v': round(float(c.get('valorGlobal') or c.get('valorInicial') or 0), 2),
        'ini': str(c.get('dataVigenciaInicio') or '')[:10],
        'fim': fim.isoformat(),
        'ra': r,
        'at': str(c.get('dataAtualizacao') or c.get('dataPublicacaoPncp') or '')[:10],
    }


def carregar():
    base = {}
    d = os.path.join(OUT, 'uf')
    if os.path.isdir(d):
        for f in os.listdir(d):
            if f.endswith('.json'):
                with open(os.path.join(d, f), encoding='utf-8') as fh:
                    for x in json.load(fh).get('itens', []):
                        base[x['id']] = (f[:2], x)
    return base


def salvar(base, estado):
    d = os.path.join(OUT, 'uf')
    os.makedirs(d, exist_ok=True)
    por = {}
    for k, (uf, x) in base.items():
        f = data(x['fim'])
        if f and MIN_FIM <= f <= MAX_FIM:
            por.setdefault(uf, []).append(x)
    idx = {'gerado': HOJE.isoformat(), 'ufs': {}, 'ramos': list(RAMOS.keys())}
    for uf, l in por.items():
        l.sort(key=lambda x: x['fim'])
        with open(os.path.join(d, uf + '.json'), 'w', encoding='utf-8') as fh:
            json.dump({'uf': uf, 'gerado': HOJE.isoformat(), 'itens': l}, fh, ensure_ascii=False, separators=(',', ':'))
        idx['ufs'][uf] = len(l)
    with open(os.path.join(OUT, 'indice.json'), 'w', encoding='utf-8') as fh:
        json.dump(idx, fh, ensure_ascii=False)
    with open(os.path.join(OUT, 'estado.json'), 'w', encoding='utf-8') as fh:
        json.dump(estado, fh, ensure_ascii=False, indent=1)


def janela(base, ini, fim, rota='contratos'):
    from concurrent.futures import ThreadPoolExecutor
    def url(p):
        q = urllib.parse.urlencode({'dataInicial': ini.strftime('%Y%m%d'), 'dataFinal': fim.strftime('%Y%m%d'), 'pagina': p, 'tamanhoPagina': 500})
        return f'{BASE}/{rota}?{q}'
    j = get(url(1))
    if j is None:
        return None  # PNCP fora: tenta de novo na proxima rodada
    pags = [j]
    total = j.get('totalPaginas') or 0
    if total > 1:
        with ThreadPoolExecutor(max_workers=4) as ex:
            res = list(ex.map(lambda p: get(url(p)), range(2, total + 1)))
        if any(r is None for r in res):
            return None
        pags += res
    novos = 0
    for pg in pags:
        for c in pg.get('data') or []:
            r = item(c)
            if r:
                uf, ctrl, x = r
                base[ctrl] = (uf, x)
                novos += 1
    return novos


def main():
    modo = sys.argv[1] if len(sys.argv) > 1 else 'semanal'
    os.makedirs(OUT, exist_ok=True)
    try:
        estado = json.load(open(os.path.join(OUT, 'estado.json'), encoding='utf-8'))
    except Exception:
        estado = {}
    base = carregar()
    t0 = time.time()
    if modo == 'carga':
        ini = data(estado.get('carga_ate')) or CARGA_DESDE
        while ini <= HOJE and time.time() - t0 < LIMITE_SEG:
            fim = min(ini + dt.timedelta(days=JANELA - 1), HOJE)
            n = janela(base, ini, fim)
            if n is None:
                print('PNCP não respondeu em', ini, '- parando para retomar depois')
                break
            print(ini, fim, 'contratos no radar:', n, flush=True)
            estado['carga_ate'] = (fim + dt.timedelta(days=1)).isoformat()
            ini = fim + dt.timedelta(days=1)
            if ini > HOJE:
                estado['carga_concluida'] = HOJE.isoformat()
    else:
        ini = HOJE - dt.timedelta(days=10)
        n1 = janela(base, ini, HOJE)
        n2 = janela(base, ini, HOJE, 'contratos/atualizacao')  # aditivos e retificações que mudam a vigência
        print('semanal: publicados', n1, 'atualizados', n2)
        estado['semanal'] = HOJE.isoformat()
    salvar(base, estado)
    print('total no radar:', sum(1 for _ in base))


if __name__ == '__main__':
    main()
