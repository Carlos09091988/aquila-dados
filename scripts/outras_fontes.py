"""Junta CJF (enunciados), boletins de jurisprudencia do TCE-SP e STJ em aquila/jur/outras-fontes.json."""
import glob, json, os, re
R = 'aquila/jur/raw/'; itens = []
LIC = re.compile(r'licita|preg[aã]o|certame|edital|concorr[eê]ncia|contrata[cç][aã]o direta|dispensa|inexigib|registro de pre[cç]os|habilita|proposta|projeto b[aá]sico|termo de refer[eê]ncia', re.I)
def frase(t):
    t = re.sub(r'\s+', ' ', t).strip()
    return t
# ---- CJF I Jornada de Direito Administrativo
p = R + 'cjf-i-jda.txt'
if os.path.exists(p):
    tx = open(p, encoding='utf-8', errors='ignore').read()
    i = tx.rfind('ENUNCIADOS APROVADOS'); j = tx.find('LISTA DE PARTICIPANTES', i); tx = tx[i:j if j > 0 else None]
    tx = re.sub(r'\n\s*\d{1,3}\s*\n', '\n', tx)
    for m in re.finditer(r'Enunciado\s+(\d+)\s*\.\s*(.+?)(?=\|\s*Enunciado\s+\d+\s*\.|\Z)', tx, re.S):
        n, e = m.group(1), frase(m.group(2)).strip(' |')
        if n not in ('1', '21', '26', '29', '36'): continue  # enunciados sobre licitação
        e = re.sub(r'\s+\d{1,3}\s*$', '', e)
        itens.append({'k': 'CJF-IJDA-' + n, 'src': 'cjf', 'n': n, 'e': e, 't': 'Enunciados do CJF', 's': 'I Jornada de Direito Administrativo',
                      'lab': 'Enunciado ' + n + ' da I Jornada de Direito Administrativo (CJF)', 'meta': 'Conselho da Justiça Federal, 2020',
                      'u': 'https://cjf.jus.br/cjf/corregedoria-da-justica-federal/centro-de-estudos-judiciarios-1/publicacoes-1/enunciados-aprovados-i-jda-vf.pdf',
                      'ci': 'Enunciado ' + n + ' da I Jornada de Direito Administrativo do CJF', 'prio': 1})
# ---- Boletins de Jurisprudencia do TCE-SP
MES = {'Janeiro': 1, 'Fevereiro': 2, 'Marco': 3, 'Março': 3, 'Abril': 4, 'Maio': 5, 'Junho': 6, 'Julho': 7, 'Agosto': 8, 'Setembro': 9, 'Outubro': 10, 'Novembro': 11, 'Dezembro': 12}
PROC = re.compile(r'^\s*(\d{5,6}[./]\d{3}[./]\d{2}-\d|\d{3,6}/\d{3}/\d{2})(\s+e\s+outr[oa]s?)?\s*$', re.M)
for f in sorted(glob.glob(R + 'tcesp-boletim-*.txt')):
    ano, mesn = re.search(r'tcesp-boletim-(\d{4})-(.+)\.txt', f).groups(); mes = MES.get(mesn.split('_')[0], 0)
    tx = open(f, encoding='utf-8', errors='ignore').read()
    k0 = tx.find('EMENTA:'); 
    if k0 < 0: continue
    corpo = tx[max(0, tx.rfind('\n', 0, tx.rfind('(Sess', 0, k0) - 60)):]
    secao = ''
    ms = list(PROC.finditer(corpo))
    for idx, m in enumerate(ms):
        bloco = corpo[m.end(): ms[idx + 1].start() if idx + 1 < len(ms) else len(corpo)]
        cab = re.search(r'\((Sess[ãa]o[^)]*?(\d{2}/\d{2}/\d{4})[^)]*?Relatoria:\s*([^)]+))\)', bloco, re.S)
        em = re.search(r'EMENTA:\s*(.+?)(?=\n\s*1\.\s|\nNota CPAJ|\Z)', bloco, re.S)
        if not cab or not em: continue
        antes = corpo[:m.start()]
        for sec in ['CAUTELARES EM PROCEDIMENTO', 'TRIBUNAL PLENO', 'PRIMEIRA CÂMARA', 'SEGUNDA CÂMARA', 'DELIBERAÇÃO EM DESTAQUE']:
            pass
        ementa = frase(em.group(1))
        teses = re.findall(r'\n\s*(\d{1,2})\.\s+(.+?)(?=\n\s*\d{1,2}\.\s|\nNota CPAJ|\Z)', bloco[em.end():] if em.end() < len(bloco) else '', re.S)
        texto = ' '.join(frase(t[1]) for t in teses) if teses else ''
        if not texto:
            texto = ementa.capitalize()
        if not (LIC.search(ementa) or 'CAUTELAR' in ementa[:60].upper()): continue
        proc = m.group(1); rel = frase(cab.group(3)).rstrip('.'); data = cab.group(2)
        col = 'Tribunal Pleno' if re.search(r'Plen', cab.group(1)) else ('Primeira Câmara' if 'Primeira' in cab.group(1) else ('Segunda Câmara' if 'Segunda' in cab.group(1) else ''))
        itens.append({'k': 'TCESPB-' + proc + '-' + data, 'src': 'tcespb', 'n': proc, 'a': ano, 'e': texto[:2500], 'ementa': ementa[:1200],
                      't': 'Boletim de Jurisprudência do TCE-SP', 's': f'{mes:02d}/{ano}', 'd': data, 'rel': rel,
                      'lab': 'TCE-SP – Processo ' + proc, 'meta': (col + ' · ' if col else '') + 'sessão de ' + data + ' · ' + rel + ' · Boletim de Jurisprudência ' + f'{mes:02d}/{ano}',
                      'u': 'https://www.tce.sp.gov.br/jurisdicionado/jurisprudencia',
                      'ci': 'TCE-SP, Processo ' + proc + (', ' + col if col else '') + ', ' + rel + ', sessão de ' + data})
# ---- STJ
p = 'aquila/jur/stj-licitacoes.json'
if os.path.exists(p):
    for a in json.load(open(p)):
        d = str(a.get('d') or ''); dd = (d[6:8] + '/' + d[4:6] + '/' + d[:4]) if re.match(r'^\d{8}$', d) else d
        ano = d[:4] if re.match(r'^\d{8}', d) else ''
        rel = (a.get('rel') or '').title()
        lab = 'STJ – ' + (a.get('classe') or '') + ' ' + str(a.get('proc') or '')
        itens.append({'k': 'STJ-' + str(a['id']), 'src': 'stj', 'n': str(a.get('proc') or ''), 'a': ano, 'e': a['e'], 't': 'STJ', 's': a.get('orgao') or '', 'd': dd, 'rel': rel,
                      'lab': lab.strip(), 'meta': (a.get('orgao') or '').title() + ' · Rel. Min. ' + rel + ' · julgado em ' + dd,
                      'u': 'https://scon.stj.jus.br/SCON/pesquisar.jsp?livre=' + str(a.get('proc') or '').replace(' ', '+'),
                      'ci': 'STJ, ' + (a.get('classe') or '') + ' ' + str(a.get('proc') or '') + ', ' + (a.get('orgao') or '').title() + ', Rel. Min. ' + rel + ', julgado em ' + dd})
json.dump({'itens': itens}, open('aquila/jur/outras-fontes.json', 'w'), ensure_ascii=False, separators=(',', ':'))
from collections import Counter
print(len(itens), Counter(i['src'] for i in itens))
