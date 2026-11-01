"""Baixa PDFs oficiais (CJF, boletins de jurisprudencia do TCE-SP) e salva o texto em aquila/jur/raw/."""
import os, subprocess, urllib.request
os.makedirs('aquila/jur/raw', exist_ok=True)
def baixa(url, nome):
    dst = 'aquila/jur/raw/' + nome
    if os.path.exists(dst + '.txt'): return True
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'aquila-dados (GitHub Actions)'})
        b = urllib.request.urlopen(req, timeout=120).read()
        if not b.startswith(b'%PDF'): return False
        open('/tmp/x.pdf', 'wb').write(b)
        subprocess.run(['pdftotext', '-layout', '/tmp/x.pdf', dst + '.txt'], check=True)
        print('ok', nome, len(b)); return True
    except Exception as e:
        return False
baixa('https://cjf.jus.br/cjf/corregedoria-da-justica-federal/centro-de-estudos-judiciarios-1/publicacoes-1/enunciados-aprovados-i-jda-vf.pdf', 'cjf-i-jda')
MESES = ['Janeiro', 'Fevereiro', 'Marco', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro']
import itertools
for ano in range(2023, 2027):
    nomes = MESES + ['Janeiro_Fevereiro', 'Novembro_Dezembro', 'Dezembro_Janeiro']
    for m in nomes:
        ok = False
        for host, suf in itertools.product(['https://www.tce.sp.gov.br', 'https://tce.sp.gov.br'], ['', '_0', '_1']):
            if baixa(f'{host}/sites/default/files/publicacoes/Boletim-Jurisprudencia-TCESP-{m}_{ano}{suf}.pdf', f'tcesp-boletim-{ano}-{m}'): ok = True; break
