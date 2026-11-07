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


import re, unicodedata

RAMOS = [  # do mais específico para o mais geral; a primeira regra que casar define o ramo
    ('Merenda e alimentação', r'merenda|alimentacao escolar|refeic|generos alimenticios|hortifruti|cozinha|lanche|nutricao|alimentacao'),
    ('Vigilância e segurança', r'vigilancia|seguranca patrimonial|vigilante|monitoramento eletronico|cftv'),
    ('Limpeza e conservação', r'limpeza|conservacao|higienizacao|asseio|zeladoria|material de limpeza'),
    ('Portaria e recepção', r'portaria|recepcao|recepcionista|controle de acesso'),
    ('Mão de obra e apoio', r'apoio administrativo|mao de obra|mao-de-obra|copeira|terceirizacao|servicos gerais|cessao de mao'),
    ('Resíduos e limpeza urbana', r'coleta de lixo|residuos|limpeza urbana|varricao|aterro'),
    ('Jardinagem e roçada', r'jardinagem|rocada|rocagem|paisagismo|poda'),
    ('Medicamentos e material hospitalar', r'medicament|farmac|material hospitalar|medico-hospitalar|insumos hospitalares|ortese|protese|odontolog|laboratori'),
    ('Saúde (serviços)', r'saude|hospital|enfermag|ambulancia|exames|clinica|plantao medico'),
    ('Obras e engenharia', r'obra|engenharia|reforma|construcao|pavimenta|edificacao|drenagem|recapeamento|asfalt'),
    ('Manutenção predial e equipamentos', r'manutencao|ar condicionado|climatizacao|elevador|instalacoes eletricas'),
    ('Tecnologia', r'software|sistema de gestao|informatica|tecnologia da informacao|licenca de uso|computador|notebook|impressora|rede de dados|internet|telefonia'),
    ('Transporte e veículos', r'transporte|veiculo|motorista|fretamento|onibus|caminhao|locacao de veic|pneu'),
    ('Combustíveis', r'combustiv|gasolina|diesel|etanol|lubrificante'),
    ('Uniformes e confecção', r'uniforme|fardamento|vestuario|confeccao|calcado|enxoval'),
    ('Material escolar e de escritório', r'material escolar|kit escolar|livro|didatic|material de escritorio|material de expediente|papel'),
    ('Mobiliário e equipamentos', r'mobiliario|moveis|cadeira|mesa|equipamento|eletrodomest'),
    ('Eventos, comunicação e publicidade', r'evento|show|publicidade|comunicacao|grafica|impressao'),
    ('Consultoria e treinamento', r'consultoria|assessoria|treinamento|capacitacao|curso|auditoria'),
    ('Materiais de construção', r'material de construcao|materiais de construcao|cimento|areia|brita|tubos|eletrico|hidraulic'),
]
_RX = [(n, re.compile(r)) for n, r in RAMOS]


def ramo(texto):
    t = unicodedata.normalize('NFD', str(texto or '').lower())
    t = ''.join(c for c in t if unicodedata.category(c) != 'Mn')
    for n, rx in _RX:
        if rx.search(t):
            return n
    return 'Outros'


ERROS = {}


def get(url, tent=6):
    for t in range(tent):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=90) as r:
                if r.status == 204:
                    return {}
                b = r.read()
                return json.loads(b) if b.strip() else {}
        except Exception as e:  # noqa: BLE001
            c = getattr(e, 'code', 0) or type(e).__name__
            if c == 204:
                return {}
            ERROS[c] = ERROS.get(c, 0) + 1
            time.sleep(min(60, 3 * (2 ** t)))
    return None


def paginas(base, pags):
    """Baixa as páginas 2..pags com poucas conexões; as que falharem são tentadas de novo uma a uma."""
    res = {}
    with ThreadPoolExecutor(3) as ex:
        for p, j in zip(range(2, pags + 1), ex.map(lambda p: get(base + str(p)), range(2, pags + 1))):
            res[p] = j
    for p in [p for p, j in res.items() if j is None]:
        time.sleep(2); res[p] = get(base + str(p), tent=8)
    falhas = sum(1 for j in res.values() if j is None)
    return [j for j in res.values() if j], falhas


def soma_vazia():
    return {'n': 0, 'valor': 0.0, 'sem_valor': 0, 'fora_teto': 0}


def acumula(tot, uf, rapida, valor, rm='Outros'):
    ok = 0 < (valor or 0) <= TETO
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
        r = g.setdefault('ramos', {}).setdefault(rm, {'n': 0, 'valor': 0.0})
        r['n'] += 1
        if ok:
            r['valor'] += valor


# ---------- abertas agora (busca do PNCP: mesma fonte do contador do site) ----------
def abertas():
    base = ('https://pncp.gov.br/api/search/?tipos_documento=edital&status=recebendo_proposta'
            '&ordenacao=-data&tam_pagina=100&pagina=')
    p1 = get(base + '1')
    if not p1 or 'total' not in p1:
        print('abertas: busca do PNCP não respondeu', ERROS, flush=True); return None
    total = int(p1.get('total') or 0); pags = min(800, (total + 99) // 100)
    print('abertas: total', total, 'páginas', pags, flush=True)
    res = {1: p1}
    with ThreadPoolExecutor(6) as ex:
        for p, j in zip(range(2, pags + 1), ex.map(lambda p: get(base + str(p)), range(2, pags + 1))):
            res[p] = j
    for p in [p for p, j in res.items() if j is None]:
        time.sleep(2); res[p] = get(base + str(p), tent=8)
    falhas = sum(1 for j in res.values() if j is None)
    if falhas > max(2, pags * 0.03):
        print('abertas: %d de %d páginas falharam' % (falhas, pags), ERROS, flush=True); return None
    tot, vistos = {}, set()
    for j in res.values():
        for i in (j or {}).get('items') or []:
            k = i.get('numero_controle_pncp') or i.get('id')
            if k in vistos:
                continue
            vistos.add(k)
            try:
                mod = int(i.get('modalidade_licitacao_id') or 0)
            except (TypeError, ValueError):
                mod = 0
            acumula(tot, i.get('uf'), mod in RAPIDAS, float(i.get('valor_global') or 0), ramo((i.get('description') or '') + ' ' + (i.get('title') or '')))
    print('abertas: somadas', len(vistos), 'BR', tot.get('BR'), flush=True)
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
        pags = int(p1.get('totalPaginas') or 0)
        mais, falhas = paginas(base, pags); res = [p1] + mais
        if falhas > max(1, pags * 0.02):
            print('dia %s: %d de %d páginas falharam (modalidade %d)' % (ds, falhas, pags, mod), ERROS, file=sys.stderr); return None
        for j in res:
            for i in (j or {}).get('data') or []:
                k = i.get('numeroControlePNCP')
                if k in vistos:
                    continue
                vistos.add(k)
                acumula(tot, (i.get('unidadeOrgao') or {}).get('ufSigla'), mod in RAPIDAS, float(i.get('valorTotalEstimado') or 0), ramo(i.get('objetoCompra')))
    return tot


def soma(a, b):
    for uf, g in b.items():
        G = a.setdefault(uf, {'todas': soma_vazia(), 'rapidas': soma_vazia()})
        for tipo in ('todas', 'rapidas'):
            for k in ('n', 'valor', 'sem_valor', 'fora_teto'):
                G[tipo][k] += g[tipo][k]
        for rm, v in (g.get('ramos') or {}).items():
            R = G.setdefault('ramos', {}).setdefault(rm, {'n': 0, 'valor': 0.0})
            R['n'] += v['n']; R['valor'] += v['valor']


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
            try:
                if 'ramos' in (json.load(open(arq)).get('BR') or {}):
                    continue
            except Exception:  # noqa: BLE001
                pass
        if time.time() - T0 > LIMITE:
            break
        t = dia(d)
        if t is None:
            print('dia falhou', d, file=sys.stderr); continue
        json.dump(t, open(arq, 'w'), separators=(',', ':')); feitos += 1
        print('dia', d, t.get('BR', {}).get('todas'), flush=True)

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
    print('dias novos', feitos, 'cobertos', cobertos, 'de 365', 'erros', ERROS)


if __name__ == '__main__':
    main()
