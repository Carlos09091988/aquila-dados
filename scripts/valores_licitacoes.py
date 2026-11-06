#!/usr/bin/env python3
"""Valores das licitações (PNCP) para o contador do site da Desenvolve.

Gera data/valores/valores.json com:
  - abertas: licitações recebendo propostas agora (quantidade e valor estimado), Brasil e por UF,
    separando contratação rápida (dispensa e inexigibilidade);
  - ano: licitações publicadas nos últimos 365 dias (quantidade e valor estimado), idem.

O "ano" é montado dia a dia (data/valores/dias/AAAA-MM-DD.json): cada execução completa os dias
que faltam, até o limite de tempo, e o total só é publicado quando a janela de 365 dias está completa
(antes disso o arquivo traz 'dias_cobertos' para o site não mostrar um número parcial como anual).
"""
import json, os, sys, time, datetime as dt, urllib.request, urllib.parse
from concurrent.futures import ThreadPoolExecutor

RAIZ = os.path.join(os.path.dirname(__file__), '..', 'data', 'valores')
DIAS = os.path.join(RAIZ, 'dias')
LIMITE = int(os.environ.get('LIMITE_SEG', '2700'))
T0 = time.time()
TETO = 5e9          # valor estimado acima disso é quase sempre erro de digitação no PNCP: fica fora da soma
RAPIDAS = {8, 9}    # dispensa, inexigibilidade
MODALIDADES = range(1, 14)
UA = {'User-Agent': 'desenvolve-valores/1.0', 'Accept': 'application/json'}


def get(url, tent=4):
    for t in range(tent):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
                b = r.read()
                return json.loads(b) if b.strip() else {}
        except Exception as e:  # noqa: BLE001
            if getattr(e, 'code', 0) == 204:
                return {}
            time.sleep(2 + 3 * t)
    return None


def soma_vazia():
    return {'n': 0, 'valor': 0.0, 'sem_valor': 0, 'fora_teto': 0}


def acumula(tot, uf, rapida, valor):
    for chave in ('BR', uf or '??'):
        g = tot.setdefault(chave, {'todas': soma_vazia(), 'rapidas': soma_vazia()})
        for tipo in ('todas',) + (('rapidas',) if rapida else ()):
            s = g[tipo]; s['n'] += 1
            if not valor or valor <= 0:
                s['sem_valor'] += 1
            elif valor > TETO:
                s['fora_teto'] += 1
            else:
                s['valor'] += valor


# ---------- abertas agora (PNCP, recebendo proposta) ----------
def abertas():
    hoje = dt.date.today()
    fim = (hoje + dt.timedelta(days=400)).strftime('%Y%m%d')
    tot, vistos = {}, set()
    for mod in MODALIDADES:
        base = ('https://pncp.gov.br/api/consulta/v1/contratacoes/proposta?dataFinal=' + fim +
                '&codigoModalidadeContratacao=%d&tamanhoPagina=50&pagina=' % mod)
        p1 = get(base + '1')
        if p1 is None:
            print('abertas: falhou modalidade', mod, file=sys.stderr); return None
        pags = int(p1.get('totalPaginas') or 0)
        res = [p1]
        with ThreadPoolExecutor(8) as ex:
            res += list(ex.map(lambda p: get(base + str(p)), range(2, pags + 1)))
        if any(r is None for r in res):
            print('abertas: páginas falharam na modalidade', mod, file=sys.stderr); return None
        for j in res:
            for i in (j or {}).get('data') or []:
                k = i.get('numeroControlePNCP')
                if k in vistos:
                    continue
                vistos.add(k)
                acumula(tot, (i.get('unidadeOrgao') or {}).get('ufSigla'), mod in RAPIDAS, float(i.get('valorTotalEstimado') or 0))
        print('abertas modalidade', mod, 'páginas', pags, 'acumulado', tot.get('BR', {}).get('todas', {}).get('n'))
    return tot


# ---------- publicadas por dia (para a janela de 12 meses) ----------
def dia(d):
    ds = d.strftime('%Y%m%d'); tot, vistos = {}, set()
    for mod in MODALIDADES:
        base = ('https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao?dataInicial=%s&dataFinal=%s'
                '&codigoModalidadeContratacao=%d&tamanhoPagina=50&pagina=' % (ds, ds, mod))
        p1 = get(base + '1')
        if p1 is None:
            return None
        pags = int(p1.get('totalPaginas') or 0); res = [p1]
        with ThreadPoolExecutor(8) as ex:
            res += list(ex.map(lambda p: get(base + str(p)), range(2, pags + 1)))
        if any(r is None for r in res):
            return None
        for j in res:
            for i in (j or {}).get('data') or []:
                k = i.get('numeroControlePNCP')
                if k in vistos:
                    continue
                vistos.add(k)
                acumula(tot, (i.get('unidadeOrgao') or {}).get('ufSigla'), mod in RAPIDAS, float(i.get('valorTotalEstimado') or 0))
    return tot


def soma(a, b):
    for uf, g in b.items():
        G = a.setdefault(uf, {'todas': soma_vazia(), 'rapidas': soma_vazia()})
        for tipo in ('todas', 'rapidas'):
            for k in ('n', 'valor', 'sem_valor', 'fora_teto'):
                G[tipo][k] += g[tipo][k]


def main():
    os.makedirs(DIAS, exist_ok=True)
    saida = os.path.join(RAIZ, 'valores.json')
    atual = json.load(open(saida)) if os.path.exists(saida) else {}

    ab = abertas()
    if ab:
        atual['abertas'] = ab; atual['abertas_em'] = dt.datetime.now().strftime('%Y-%m-%d %H:%M')

    # dias de ontem para trás (o dia de hoje ainda está sendo publicado)
    ontem = dt.date.today() - dt.timedelta(days=1)
    janela = [ontem - dt.timedelta(days=i) for i in range(365)]
    feitos = 0
    for d in janela:
        arq = os.path.join(DIAS, d.isoformat() + '.json')
        recente = d >= ontem - dt.timedelta(days=3)   # os últimos dias ainda recebem publicações atrasadas: refaz
        if os.path.exists(arq) and not recente:
            continue
        if time.time() - T0 > LIMITE:
            break
        t = dia(d)
        if t is None:
            print('dia falhou', d, file=sys.stderr); continue
        json.dump(t, open(arq, 'w'), separators=(',', ':')); feitos += 1
        print('dia', d, t.get('BR', {}).get('todas'))

    # apaga dias fora da janela e soma os 365
    validos = {d.isoformat() for d in janela}
    ano, cobertos = {}, 0
    for f in sorted(os.listdir(DIAS)):
        nome = f[:-5]
        if nome not in validos:
            os.remove(os.path.join(DIAS, f)); continue
        soma(ano, json.load(open(os.path.join(DIAS, f)))); cobertos += 1
    atual['ano'] = ano; atual['dias_cobertos'] = cobertos
    atual['ano_de'] = janela[-1].isoformat(); atual['ano_ate'] = janela[0].isoformat()
    atual['teto_por_licitacao'] = TETO
    json.dump(atual, open(saida, 'w'), ensure_ascii=False, separators=(',', ':'))
    print('dias novos', feitos, 'cobertos', cobertos, 'de 365')


if __name__ == '__main__':
    main()
