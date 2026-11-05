"""Teste de acesso/estrutura de diários a partir do GitHub (sem dados de clientes)."""
import urllib.request, subprocess, re, collections, os
H = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept-Language': 'pt-BR,pt;q=0.9'}
def baixa(u, f):
    b = urllib.request.urlopen(urllib.request.Request(u, headers=H), timeout=120).read(); open(f, 'wb').write(b); return len(b)
# Piauí - Diário Oficial dos Municípios (edições numeradas)
for n in (5687, 5686):
    u = 'https://www.diarioficialdosmunicipios.org/intranet/_lib/file/doc/pdfs/novo2/%d/DM_%d.pdf' % (n, n)
    try:
        print('PI', n, baixa(u, 'pi.pdf'), 'bytes')
        t = subprocess.run(['pdftotext', '-layout', 'pi.pdf', '-'], capture_output=True, text=True).stdout
        print('  chars', len(t), 'paginas', t.count('\f'))
        print('  primeiras linhas:', re.sub(r'\s+', ' ', t[:1500]))
        mun = collections.Counter(m.strip().upper() for m in re.findall(r'(?:PREFEITURA|C[ÂA]MARA) MUNICIPAL DE ([A-ZÀ-Ú][A-Za-zÀ-ú \-]{2,40}?)\s*(?:[-–/]|\n|PI\b)', t))
        print('  municipios distintos:', len(mun), list(mun.most_common(60)))
        print('  marcadores:', {k: len(re.findall(k, t)) for k in ('Código Identificador', 'Id:', 'Publicado por', 'AVISO DE LICITA', 'EXTRATO')})
    except Exception as e:
        print('PI', n, 'ERRO', str(e)[:150])
