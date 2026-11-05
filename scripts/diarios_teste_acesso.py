import urllib.request, time, ssl
U = {
 'dosp_api': 'https://dosp.com.br/api/index.php/dioe.js/4861?callback=d',
 'sigpub': 'https://www.diariomunicipal.com.br/amm-mg/',
 'domsc': 'https://diariomunicipal.sc.gov.br/',
 'famem': 'https://diariooficial.famem.org.br/dom/dom/todasEdicoes',
 'doem_ba': 'https://www.doem.org.br/',
 'diariooficialba': 'https://www.diariooficialba.com.br/',
 'pi_dom': 'https://www.diarioficialdosmunicipios.org/',
 'ioes': 'https://ioes.dio.es.gov.br/',
 'qd_api': 'https://api.queridodiario.ok.org.br/gazettes?querystring=licitacao&size=1',
 'pncp': 'https://pncp.gov.br/api/search/?q=limpeza&tipos_documento=edital&tam_pagina=1',
}
H = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'Accept-Language': 'pt-BR,pt;q=0.9'}
for k, u in U.items():
    t = time.time()
    try:
        r = urllib.request.urlopen(urllib.request.Request(u, headers=H), timeout=30)
        b = r.read()
        print(k, r.status, len(b), int((time.time()-t)*1000), 'ms', b[:80].decode('utf-8', 'replace').replace('\n', ' '))
    except Exception as e:
        print(k, 'ERRO', str(e)[:120])
