"""Lista os coletores do Querido Diário dos estados pedidos, sem os de plataformas que já cobrimos direto (SIGPub, DiOE/DOSP, DOM/SC)."""
import os, re, sys
base = sys.argv[1]; ufs = sys.argv[2].split(',')
for uf in ufs:
    d = os.path.join(base, 'gazette', 'spiders', uf)
    if not os.path.isdir(d):
        continue
    for f in sorted(os.listdir(d)):
        if not f.endswith('.py'):
            continue
        t = open(os.path.join(d, f), encoding='utf-8').read()
        if re.search(r'sigpub|dosp|diariomunicipal\.sc\.gov', t, re.I):
            continue
        for n in re.findall(r'name\s*=\s*"([a-z]{2}_[a-z0-9_]+)"', t):
            print(n)
