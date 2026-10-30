#!/usr/bin/env python3
"""Robô de dados abertos do Raio-X do fornecedor (Aquila Advocacia).

Baixa as bases públicas da PGFN (dívida ativa da União e do FGTS) e da Receita
Federal (quadro de sócios e empresas) e gera um índice enxuto, dividido em
arquivos pequenos (gzip), publicado no GitHub Pages e consultado pelo site.

Saída (pasta site/):
  pgfn/<3 primeiros dígitos do CNPJ>.json.gz  -> {cnpj: [total, prev, naoprev, fgts, em_cobranca, qtd, ajuizadas, tipos]}
  socios/<3 primeiros hex do sha256(chave)>.json.gz -> {chave: [[cnpj_basico, razao, qualificacao, data_entrada], ...]}
  meta.json -> datas de referência
"""
import datetime as dt
import glob
import gzip
import hashlib
import json
import os
import re
import shutil
import sys
import unicodedata
import zipfile
import xml.etree.ElementTree as ET

import duckdb
import requests

UA = {'User-Agent': 'Mozilla/5.0 (compatible; AquilaRaioX/1.0; +https://aquilaadvocacia.com.br)'}
WORK = os.environ.get('WORK', 'work')
OUT = os.environ.get('OUT', 'site')
LIMITE = int(os.environ.get('LIMITE_ARQUIVOS', '0'))  # 0 = todos (usado só em teste)
RF_SHARE = 'gn672Ad4CF8N6TK'
RF_DAV = 'https://arquivos.receitafederal.gov.br/public.php/webdav/Dados/Cadastros/CNPJ/'


def log(*a):
    print(dt.datetime.now().strftime('%H:%M:%S'), *a, flush=True)


def norm(s):
    s = unicodedata.normalize('NFKD', str(s or '')).encode('ascii', 'ignore').decode()
    return re.sub(r'\s+', ' ', s.upper()).strip()


def chave(nome, doc):
    return norm(nome) + '|' + re.sub(r'\D', '', str(doc or ''))


def baixar(url, destino, auth=None):
    if os.path.exists(destino) and os.path.getsize(destino) > 0:
        return destino
    for t in range(4):
        try:
            with requests.get(url, stream=True, timeout=120, headers=UA, auth=auth) as r:
                r.raise_for_status()
                with open(destino + '.part', 'wb') as f:
                    for ch in r.iter_content(1 << 20):
                        f.write(ch)
            os.replace(destino + '.part', destino)
            log('baixado', os.path.basename(destino), os.path.getsize(destino) // (1 << 20), 'MB')
            return destino
        except Exception as e:
            log('falha', url, e, 'tentativa', t + 1)
    raise SystemExit('Não foi possível baixar ' + url)


def extrair(zips, pasta):
    os.makedirs(pasta, exist_ok=True)
    for z in zips:
        with zipfile.ZipFile(z) as zf:
            zf.extractall(pasta)
    return [p for p in glob.glob(os.path.join(pasta, '**', '*'), recursive=True) if os.path.isfile(p)]


def gravar_shards(rows, pasta, prefixo):
    """rows: iterável de (chave_do_shard, chave, valor) já ordenado pela chave do shard."""
    os.makedirs(pasta, exist_ok=True)
    atual, buf, n = None, {}, 0

    def flush():
        if atual is not None:
            with gzip.open(os.path.join(pasta, atual + '.json.gz'), 'wt', encoding='utf-8') as f:
                json.dump(buf, f, ensure_ascii=False, separators=(',', ':'))

    for sh, k, v in rows:
        if sh != atual:
            flush(); atual, buf = sh, {}
        buf[k] = v; n += 1
    flush()
    log(prefixo, n, 'registros gravados')
    return n


# ---------------- PGFN ----------------
PGFN_ARQS = {'prev': 'Dados_abertos_Previdenciario.zip', 'naoprev': 'Dados_abertos_Nao_Previdenciario.zip', 'fgts': 'Dados_abertos_FGTS.zip'}


def pgfn_trimestre():
    hoje = dt.date.today()
    for ano in range(hoje.year, hoje.year - 3, -1):
        for tri in (4, 3, 2, 1):
            base = f'https://dadosabertos.pgfn.gov.br/{ano}_trimestre_{tri:02d}/'
            try:
                r = requests.head(base + PGFN_ARQS['naoprev'], timeout=30, headers=UA, allow_redirects=True)
                if r.status_code == 200:
                    return base, f'{tri}º trimestre de {ano}'
            except Exception:
                pass
    raise SystemExit('Trimestre da PGFN não encontrado')


def pgfn(con):
    base, ref = pgfn_trimestre()
    log('PGFN', base)
    primeiro = True
    for nat, arq in PGFN_ARQS.items():
        z = baixar(base + arq, os.path.join(WORK, 'pgfn_' + arq))
        pasta = os.path.join(WORK, 'pgfn_' + nat)
        fs = [f for f in extrair([z], pasta) if f.lower().endswith('.csv')]
        os.remove(z)
        if LIMITE: fs = fs[:LIMITE]
        log(nat, len(fs), 'arquivos CSV')
        lista = '[' + ','.join("'" + f.replace("'", "''") + "'" for f in fs) + ']'
        q = f"""SELECT '{nat}' AS nat, CPF_CNPJ, TIPO_PESSOA, TIPO_DEVEDOR, TIPO_SITUACAO_INSCRICAO, INDICADOR_AJUIZADO, VALOR_CONSOLIDADO, NUMERO_INSCRICAO, NOME_DEVEDOR
          FROM read_csv({lista}, delim=';', header=true, all_varchar=true, encoding='latin-1', union_by_name=true, ignore_errors=true, quote='"')"""
        con.execute(('CREATE TABLE pg AS ' if primeiro else 'INSERT INTO pg ') + q)
        primeiro = False
        shutil.rmtree(pasta, ignore_errors=True)
    con.execute(r"""CREATE TABLE pga AS
      SELECT regexp_replace(CPF_CNPJ, '\D', '', 'g') AS cnpj,
        sum(v) AS total, sum(v) FILTER (WHERE nat='prev') AS prev, sum(v) FILTER (WHERE nat='naoprev') AS naoprev,
        sum(v) FILTER (WHERE nat='fgts') AS fgts,
        sum(v) FILTER (WHERE upper(TIPO_SITUACAO_INSCRICAO) LIKE '%COBRAN%') AS cobranca,
        count(*) AS qtd, count(*) FILTER (WHERE upper(INDICADOR_AJUIZADO) LIKE 'S%') AS ajuiz,
        string_agg(DISTINCT upper(TIPO_DEVEDOR), ',') AS tipos
      FROM (SELECT *, coalesce(try_cast(CASE WHEN VALOR_CONSOLIDADO LIKE '%,%' THEN replace(replace(VALOR_CONSOLIDADO, '.', ''), ',', '.') ELSE VALOR_CONSOLIDADO END AS DOUBLE), 0) AS v FROM pg
            WHERE upper(TIPO_PESSOA) LIKE '%JUR%')
      GROUP BY 1 HAVING length(cnpj) = 14""")
    # devedores que dividem as mesmas inscrições (corresponsáveis e solidários): grupo econômico reconhecido pela PGFN
    con.execute(r"""CREATE TABLE ins AS SELECT nat, NUMERO_INSCRICAO AS ni, regexp_replace(CPF_CNPJ, '\D', '', 'g') AS doc, CPF_CNPJ AS docm,
      upper(trim(NOME_DEVEDOR)) AS nome, upper(TIPO_DEVEDOR) AS td,
      coalesce(try_cast(CASE WHEN VALOR_CONSOLIDADO LIKE '%,%' THEN replace(replace(VALOR_CONSOLIDADO, '.', ''), ',', '.') ELSE VALOR_CONSOLIDADO END AS DOUBLE), 0) AS v
      FROM pg WHERE NUMERO_INSCRICAO IS NOT NULL AND NUMERO_INSCRICAO <> ''""")
    con.execute('DROP TABLE pg')
    con.execute('CREATE TABLE mul AS SELECT nat, ni FROM ins GROUP BY nat, ni HAVING count(DISTINCT doc) BETWEEN 2 AND 60')
    con.execute('CREATE TABLE insm AS SELECT ins.* FROM ins JOIN mul USING (nat, ni)')
    con.execute('DROP TABLE ins'); con.execute('DROP TABLE mul')
    con.execute("""CREATE TABLE lk0 AS SELECT a.doc AS cnpj, b.docm AS outro, max(b.nome) AS nome, string_agg(DISTINCT b.td, ',') AS td, round(sum(a.v), 2) AS valor, count(DISTINCT a.ni) AS n
      FROM insm a JOIN insm b USING (nat, ni) WHERE a.doc <> b.doc AND length(a.doc) = 14 GROUP BY 1, 2""")
    con.execute('DROP TABLE insm')
    con.execute("""CREATE TABLE lk AS SELECT cnpj, list([outro, nome, td, CAST(valor AS VARCHAR), CAST(n AS VARCHAR)] ORDER BY valor DESC)[1:50] AS l FROM lk0 GROUP BY cnpj""")
    con.execute('DROP TABLE lk0')
    log('PGFN corresponsáveis', con.execute('SELECT count(*) FROM lk').fetchone()[0])
    cur = con.execute('SELECT substr(cnpj,1,3), cnpj, round(total,2), round(coalesce(prev,0),2), round(coalesce(naoprev,0),2), round(coalesce(fgts,0),2), round(coalesce(cobranca,0),2), qtd, ajuiz, tipos, lk.l FROM pga LEFT JOIN lk USING (cnpj) ORDER BY cnpj')

    def it():
        while True:
            lote = cur.fetchmany(50000)
            if not lote: break
            for r in lote:
                v = [r[2], r[3], r[4], r[5], r[6], r[7], r[8], r[9] or '']
                if r[10]: v.append([[x[0], x[1], x[2], float(x[3]), int(x[4])] for x in r[10]])
                yield r[0], r[1], v
    n = gravar_shards(it(), os.path.join(OUT, 'pgfn'), 'PGFN')
    return {'referencia': ref, 'fonte': base, 'empresas': n}


# ---------------- Receita: sócios ----------------
def rf_mes():
    body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>'
    r = requests.request('PROPFIND', RF_DAV, data=body, headers={**UA, 'Depth': '1'}, auth=(RF_SHARE, ''), timeout=60)
    r.raise_for_status()
    meses = sorted(set(re.findall(r'/CNPJ/(\d{4}-\d{2})/', r.text)))
    if not meses: raise SystemExit('Pastas da Receita não encontradas')
    for m in reversed(meses):
        rr = requests.request('PROPFIND', RF_DAV + m + '/', data=body, headers={**UA, 'Depth': '1'}, auth=(RF_SHARE, ''), timeout=60)
        if 'Socios9.zip' in rr.text and 'Empresas9.zip' in rr.text:
            return m
    raise SystemExit('Mês completo da Receita não encontrado')


def socios(con):
    mes = rf_mes(); log('Receita', mes)
    auth = (RF_SHARE, '')
    def b(nome): return baixar(RF_DAV + mes + '/' + nome, os.path.join(WORK, 'rf_' + nome), auth)
    n = 10 if not LIMITE else 1
    zs = [b(f'Socios{i}.zip') for i in range(n)]
    soc = extrair(zs, os.path.join(WORK, 'rf_soc'))
    for z in zs: os.remove(z)
    qual = extrair([b('Qualificacoes.zip')], os.path.join(WORK, 'rf_qual'))
    con.execute(f"""CREATE TABLE q AS SELECT column0 AS cod, column1 AS nome FROM read_csv({qual!r}, delim=';', header=false, all_varchar=true, encoding='latin-1', quote='"')""")
    con.execute(f"""CREATE TABLE s AS SELECT column00 AS basico, column01 AS ident, column02 AS nome, column03 AS doc, column04 AS qual, column05 AS entrada
       FROM read_csv({soc!r}, delim=';', header=false, all_varchar=true, encoding='latin-1', quote='"', ignore_errors=true, columns={{
       'column00':'VARCHAR','column01':'VARCHAR','column02':'VARCHAR','column03':'VARCHAR','column04':'VARCHAR','column05':'VARCHAR','column06':'VARCHAR','column07':'VARCHAR','column08':'VARCHAR','column09':'VARCHAR','column10':'VARCHAR'}})""")
    shutil.rmtree(os.path.join(WORK, 'rf_soc'), ignore_errors=True)
    # sobrenomes raros (últimos dois sobrenomes): possível parentesco entre sócios
    con.execute(r"""CREATE TABLE sn0 AS SELECT t[len(t)-1] || ' ' || t[len(t)] AS sob, nome, doc, basico, qual FROM (
       SELECT list_filter(string_split(trim(regexp_replace(upper(strip_accents(nome)), '[^A-Z ]', ' ', 'g')), ' '),
         x -> length(x) > 1 AND x NOT IN ('DE','DA','DO','DOS','DAS','DI','DEL','FILHO','FILHA','JUNIOR','NETO','NETA','SOBRINHO','SEGUNDO','TERCEIRO')) AS t,
         trim(regexp_replace(upper(strip_accents(nome)), '\s+', ' ', 'g')) AS nome, regexp_replace(coalesce(doc,''), '\D', '', 'g') AS doc, basico, qual
       FROM s WHERE ident = '2' AND nome IS NOT NULL) WHERE len(t) >= 3""")
    con.execute("""CREATE TABLE sn AS SELECT sn0.* FROM sn0 JOIN (SELECT sob FROM sn0 GROUP BY sob HAVING count(DISTINCT nome || doc) BETWEEN 2 AND 25) USING (sob)""")
    con.execute('DROP TABLE sn0')
    log('sobrenomes', con.execute('SELECT count(*) FROM sn').fetchone()[0])
    con.execute(r"""CREATE TABLE sk AS SELECT CASE WHEN ident = '1' THEN 'PJ|' || regexp_replace(coalesce(doc,''), '\D', '', 'g') ELSE trim(regexp_replace(upper(strip_accents(nome)), '\s+', ' ', 'g')) || '|' || regexp_replace(coalesce(doc,''), '\D', '', 'g') END AS k,
       basico, ident, qual, entrada FROM s WHERE nome IS NOT NULL""")
    con.execute('DROP TABLE s')
    # chaves de pessoas com 2 a 300 empresas, e todas as participações de pessoas jurídicas (holdings)
    con.execute("""CREATE TABLE kk AS SELECT k FROM sk GROUP BY k HAVING (count(DISTINCT basico) BETWEEN 2 AND 300) OR max(ident) = '1'""")
    con.execute('CREATE TABLE sel AS SELECT sk.* FROM sk JOIN kk USING (k)')
    con.execute('DROP TABLE sk')
    log('vínculos selecionados', con.execute('SELECT count(*) FROM sel').fetchone()[0])
    # contatos em comum (telefone, e-mail, endereço) entre estabelecimentos ativos
    con.execute('CREATE TABLE ct0 (k VARCHAR, basico VARCHAR)')
    for i in range(n):
        z = b(f'Estabelecimentos{i}.zip'); est = extrair([z], os.path.join(WORK, 'rf_est')); os.remove(z)
        con.execute(f"""CREATE OR REPLACE TABLE es AS SELECT column00 AS basico, regexp_replace(coalesce(column21,'') || coalesce(column22,''), '\\D', '', 'g') AS t1,
           regexp_replace(coalesce(column23,'') || coalesce(column24,''), '\\D', '', 'g') AS t2, lower(trim(coalesce(column27,''))) AS em,
           regexp_replace(coalesce(column18,''), '\\D', '', 'g') AS cep, regexp_replace(upper(coalesce(column15,'')), '[^A-Z0-9]', '', 'g') AS num,
           regexp_replace(upper(strip_accents(coalesce(column16,''))), '[^A-Z0-9]', '', 'g') AS comp
           FROM read_csv({est!r}, delim=';', header=false, all_varchar=true, encoding='latin-1', quote='"', ignore_errors=true, null_padding=true)
           WHERE column05 = '02'""")
        con.execute("""INSERT INTO ct0 SELECT 'T|' || t1, basico FROM es WHERE length(t1) BETWEEN 10 AND 11
           UNION ALL SELECT 'T|' || t2, basico FROM es WHERE length(t2) BETWEEN 10 AND 11
           UNION ALL SELECT 'E|' || em, basico FROM es WHERE em LIKE '%_@_%._%'
           UNION ALL SELECT 'A|' || cep || '|' || num || '|' || comp, basico FROM es WHERE length(cep) = 8 AND num <> '' AND num NOT IN ('SN','0','00','000','SNO')""")
        con.execute('DROP TABLE es')
        shutil.rmtree(os.path.join(WORK, 'rf_est'), ignore_errors=True)
        log('estabelecimentos', i, 'ok')
    con.execute("""CREATE TABLE ct AS SELECT DISTINCT ct0.k, ct0.basico FROM ct0 JOIN (SELECT k FROM ct0 GROUP BY k HAVING count(DISTINCT basico) BETWEEN 2 AND 30) USING (k)""")
    con.execute('DROP TABLE ct0')
    log('contatos em comum', con.execute('SELECT count(*) FROM ct').fetchone()[0])
    con.execute('CREATE TABLE b AS SELECT DISTINCT basico FROM (SELECT basico FROM sel UNION ALL SELECT basico FROM ct UNION ALL SELECT basico FROM sn)')
    con.execute('CREATE TABLE e (basico VARCHAR, razao VARCHAR)')
    for i in range(n):
        z = b(f'Empresas{i}.zip'); emp = extrair([z], os.path.join(WORK, 'rf_emp')); os.remove(z)
        con.execute(f"""INSERT INTO e SELECT column0, column1 FROM read_csv({emp!r}, delim=';', header=false, all_varchar=true, encoding='latin-1', quote='"', ignore_errors=true)
           WHERE column0 IN (SELECT basico FROM b)""")
        shutil.rmtree(os.path.join(WORK, 'rf_emp'), ignore_errors=True)
        log('empresas', i, 'ok')
    cur = con.execute("""SELECT substr(sha256(sel.k), 1, 3) AS shard, sel.k, sel.basico, left(coalesce(e.razao,''), 80), coalesce(q.nome, sel.qual), sel.entrada
       FROM sel LEFT JOIN e USING (basico) LEFT JOIN q ON q.cod = sel.qual ORDER BY shard, sel.k""")

    def it():
        k0, sh0, lst = None, None, []
        while True:
            lote = cur.fetchmany(50000)
            if not lote: break
            for shard, k, basico, razao, qn, ent in lote:
                if k != k0:
                    if k0 is not None: yield sh0, k0, lst
                    k0, sh0, lst = k, shard, []
                if len(lst) < 300: lst.append([basico, razao, qn, ent])
        if k0 is not None: yield sh0, k0, lst
    total = gravar_shards(it(), os.path.join(OUT, 'socios'), 'Sócios')
    # índice de sinais: contatos em comum e sobrenomes raros
    cur = con.execute("""SELECT substr(sha256(k), 1, 3) AS shard, k, basico, left(coalesce(razao,''), 80), nome, qn FROM (
         SELECT ct.k, ct.basico, e.razao, NULL AS nome, NULL AS qn FROM ct LEFT JOIN e USING (basico)
         UNION ALL SELECT 'S|' || sn.sob, sn.basico, e.razao, sn.nome || '|' || sn.doc, coalesce(q.nome, sn.qual) FROM sn LEFT JOIN e USING (basico) LEFT JOIN q ON q.cod = sn.qual)
       ORDER BY shard, k""")

    def it2():
        k0, sh0, lst = None, None, []
        while True:
            lote = cur.fetchmany(50000)
            if not lote: break
            for shard, k, basico, razao, nome, qn in lote:
                if k != k0:
                    if k0 is not None: yield sh0, k0, lst
                    k0, sh0, lst = k, shard, []
                if len(lst) < 80: lst.append([basico, razao] if nome is None else [basico, razao, nome, qn])
        if k0 is not None: yield sh0, k0, lst
    sinais = gravar_shards(it2(), os.path.join(OUT, 'vinc'), 'Sinais')
    for p in glob.glob(os.path.join(WORK, 'rf_*.zip')):
        os.remove(p)
    return {'referencia': mes, 'chaves': total, 'sinais': sinais}


def main():
    os.makedirs(WORK, exist_ok=True); os.makedirs(OUT, exist_ok=True)
    con = duckdb.connect(os.path.join(WORK, 'db.duckdb'))
    con.execute("SET preserve_insertion_order=false"); con.execute(f"SET temp_directory='{WORK}/tmp'")
    meta = {'gerado_em': dt.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ')}
    etapas = sys.argv[1:] or ['pgfn', 'socios']
    if 'pgfn' in etapas: meta['pgfn'] = pgfn(con)
    if 'socios' in etapas: meta['socios'] = socios(con)
    antigo = {}
    if os.path.exists(os.path.join(OUT, 'meta.json')):
        antigo = json.load(open(os.path.join(OUT, 'meta.json')))
    antigo.update(meta)
    json.dump(antigo, open(os.path.join(OUT, 'meta.json'), 'w'), ensure_ascii=False, indent=1)
    open(os.path.join(OUT, '.nojekyll'), 'w').close()
    open(os.path.join(OUT, 'index.html'), 'w').write('<!doctype html><meta charset="utf-8"><title>Dados do Raio-X</title><p>Índice de dados abertos (PGFN e Receita Federal) usado pelo Raio-X do fornecedor da <a href="https://aquilaadvocacia.com.br/raio-x-do-fornecedor/">Aquila Advocacia</a>.</p>')
    log('pronto', meta)


if __name__ == '__main__':
    main()
