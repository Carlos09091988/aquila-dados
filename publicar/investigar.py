import gzip, json, glob, sys, re
termos = [t.upper() for t in sys.argv[1:]]
achados = {}
for f in glob.glob('pages/socios/*.json.gz'):
    d = json.load(gzip.open(f))
    for k, l in d.items():
        for e in l:
            r = (e[1] or '').upper()
            if any(t in r for t in termos) or any(t in k for t in termos):
                achados.setdefault(k, l); break
out = []
for k, l in sorted(achados.items()):
    out.append(k + ' => ' + '; '.join(f'{e[0]} {e[1]} ({e[2]})' for e in l[:12]))
txt = ' || '.join(out)[:9000]
for i in range(0, len(txt), 900): print('::error::' + txt[i:i+900])
