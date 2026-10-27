"""Mescla as respostas do LabFree num /opt/fog/.fogsettings que já existe (instalação retomada).

Uso: python3 -I mesclar-fogsettings.py RESPOSTAS DESTINO
Troca só as chaves que aparecem em RESPOSTAS e mantém as demais (por exemplo, a senha do banco
que o instalador do FOG já gravou e aplicou).
"""
import os
import sys

novas = [l for l in open(sys.argv[1]).read().splitlines() if '=' in l and not l.startswith('#')]
chaves = {l.split('=', 1)[0]: l for l in novas}
saida, vistas = [], set()
for linha in open(sys.argv[2]).read().splitlines():
    k = linha.split('=', 1)[0]
    if k in chaves:
        saida.append(chaves[k])
        vistas.add(k)
    else:
        saida.append(linha)
saida += [chaves[k] for k in chaves if k not in vistas]
# temporário + troca: uma queda de energia nunca deixa o arquivo (que tem a senha do banco) pela metade
tmp = sys.argv[2] + '.novo'
with open(tmp, 'w') as f:
    f.write('\n'.join(saida) + '\n')
    f.flush()
    os.fsync(f.fileno())
st = os.stat(sys.argv[2])
os.chmod(tmp, 0o600)
os.chown(tmp, st.st_uid, st.st_gid)  # mantém o dono original
os.replace(tmp, sys.argv[2])
