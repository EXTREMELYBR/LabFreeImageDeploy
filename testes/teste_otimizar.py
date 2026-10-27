"""Teste do otimizador de imagens (lib/LabFreeImageDeploy-otimizar) sem root e sem banco: imagens falsas numa pasta
temporária, banco simulado. Confere: fica menor, conteúdo idêntico bit a bit, pedaços refeitos, imagem só conferida,
imagem corrompida detectada (e não mexida), pausa quando uma tarefa começa (sem sobrar temporário) e passada repetida
sem trabalho.   Uso: python3 -I testes/teste_otimizar.py"""
import hashlib
import importlib.machinery
import importlib.util
import os
import subprocess
import sys
import tempfile

AQUI = os.path.dirname(os.path.abspath(__file__))
carregador = importlib.machinery.SourceFileLoader('otimizar', os.path.join(AQUI, '..', 'lib', 'LabFreeImageDeploy-otimizar'))
spec = importlib.util.spec_from_loader('otimizar', carregador)
m = importlib.util.module_from_spec(spec)
carregador.exec_module(m)

T = tempfile.mkdtemp()
m.ESTADO, m.LOG, m.IDADE_MIN, m.PEDACO, m.CHECAR_A_CADA = f'{T}/estado.json', f'{T}/otimizar.log', 0, 4 * 1024 * 1024, 0
RAIZ = f'{T}/images'
IMAGENS = [('1', 'Unica', 'Única', '1'), ('2', 'Pedacos', 'Em pedaços', '1'), ('3', 'SoConferir', 'Só conferir', '0'),
           ('4', 'Estragada', 'Estragada', '1')]
reg = {}
ocupado_depois = [None]  # None = sempre parado; N = fica ocupado depois de N consultas
consultas = [0]


def falso_mysql(sql):
    if sql.startswith('SELECT ngmRootPath'):
        return RAIZ + '\n'
    if sql.startswith('SELECT i.imageID'):
        return ''.join('\t'.join(i) + '\n' for i in IMAGENS)
    return ''


def falso_parado():
    consultas[0] += 1
    return ocupado_depois[0] is None or consultas[0] <= ocupado_depois[0]


m.mysql = falso_mysql
m.parado = falso_parado
m.registrar = lambda img, **c: reg.setdefault(img, {}).update(c)


PROGRAMAS = sorted(os.path.join(d, n) for d in ('/usr/lib/x86_64-linux-gnu', '/usr/bin') if os.path.isdir(d)
                   for n in os.listdir(d) if os.path.isfile(os.path.join(d, n)) and not os.path.islink(os.path.join(d, n)))


def dados(mb, inicio=0):
    """Conteúdo parecido com uma partição de verdade: programas e bibliotecas do sistema, em sequência."""
    out = bytearray()
    for p in PROGRAMAS[inicio:]:
        if len(out) >= mb * 1024 * 1024:
            break
        try:
            out += open(p, 'rb').read()
        except OSError:
            pass
    return bytes(out[:mb * 1024 * 1024])


def zst(b, nivel='-3'):
    return subprocess.run(['zstd', '-q', nivel, '-c'], input=b, capture_output=True, check=True).stdout


def sha(b):
    return hashlib.sha256(b).hexdigest()


def conteudo(pasta, base):
    pecas = sorted(os.path.join(pasta, n) for n in os.listdir(pasta) if n.startswith(base))
    junto = b''.join(open(p, 'rb').read() for p in pecas)
    r = subprocess.run(['zstd', '-dcq'], input=junto, capture_output=True)
    return r.stdout if r.returncode == 0 else None


falhas = []


def confere(cond, texto):
    print(('OK   ' if cond else 'ERRO ') + texto)
    if not cond:
        falhas.append(texto)


originais = {}
for img_id, caminho, _n, _o in IMAGENS:
    os.makedirs(f'{RAIZ}/{caminho}')
    open(f'{RAIZ}/{caminho}/d1.mbr', 'wb').write(os.urandom(512))
base = dados(24)
originais['Unica'] = base
open(f'{RAIZ}/Unica/d1p1.img', 'wb').write(zst(base))
originais['Pedacos'] = dados(20, 400)
z = zst(originais['Pedacos'])
for i in range(0, len(z), 3 * 1024 * 1024):  # pedaços de 3 MB (o otimizador refaz em 4 MB)
    open(f'{RAIZ}/Pedacos/d1p2.img.{i // (3 * 1024 * 1024):03d}', 'wb').write(z[i:i + 3 * 1024 * 1024])
originais['SoConferir'] = dados(6, 900)
open(f'{RAIZ}/SoConferir/d1p1.img', 'wb').write(zst(originais['SoConferir']))
estragado = bytearray(zst(dados(6, 1200)))
estragado[len(estragado) // 2] ^= 0xFF
open(f'{RAIZ}/Estragada/d1p1.img', 'wb').write(bytes(estragado))
tam = {c: sum(os.path.getsize(f'{RAIZ}/{c}/{n}') for n in os.listdir(f'{RAIZ}/{c}') if n.startswith('d1p'))
       for _i, c, _n, _o in IMAGENS}
sha_estragada = sha(open(f'{RAIZ}/Estragada/d1p1.img', 'rb').read())
sha_soconferir = sha(open(f'{RAIZ}/SoConferir/d1p1.img', 'rb').read())

# 1) tarefa começa no meio: pausa, nada muda, nenhum temporário sobra
ocupado_depois[0] = 4  # a 1ª imagem começa e, no meio, aparece uma tarefa
m.passada()
confere(reg.get('1', {}).get('estado') == 'pausada', 'tarefa no meio: imagem marcada como pausada')
confere(conteudo(f'{RAIZ}/Unica', 'd1p1') == originais['Unica'] and
        os.path.getsize(f'{RAIZ}/Unica/d1p1.img') == tam['Unica'], 'tarefa no meio: original intacto')
confere(not any(n.startswith('.labfree') for c in os.listdir(RAIZ) for n in os.listdir(f'{RAIZ}/{c}')), 'tarefa no meio: nenhum temporário sobrou')

# 2) passada completa
ocupado_depois[0] = None
m.passada()
for c in ('Unica', 'Pedacos'):
    novo = sum(os.path.getsize(f'{RAIZ}/{c}/{n}') for n in os.listdir(f'{RAIZ}/{c}') if n.startswith('d1p'))
    confere(novo < tam[c], f'{c}: ficou menor ({tam[c] / 1e6:.1f} -> {novo / 1e6:.1f} MB)')
    confere(conteudo(f'{RAIZ}/{c}', 'd1p') == originais[c], f'{c}: conteúdo idêntico bit a bit depois de otimizar')
pecas = sorted(n for n in os.listdir(f'{RAIZ}/Pedacos') if n.startswith('d1p2'))
confere(pecas[0] == 'd1p2.img.000' and all(os.path.getsize(f'{RAIZ}/Pedacos/{p}') == m.PEDACO for p in pecas[:-1]),
        f'Pedacos: refeito em pedaços de 4 MB ({len(pecas)} pedaços, nomes .000…)')
confere(reg['1'].get('estado') == 'ok' and reg['1'].get('depois', 0) < reg['1'].get('antes', 0), 'Unica: registrada como ok, antes > depois')
confere(sha(open(f'{RAIZ}/SoConferir/d1p1.img', 'rb').read()) == sha_soconferir and reg['3'].get('estado') == 'ok',
        'SoConferir (otimizar desligado): só conferida, arquivo não mexido')
confere(reg['4'].get('estado') == 'problema' and sha(open(f'{RAIZ}/Estragada/d1p1.img', 'rb').read()) == sha_estragada,
        f"Estragada: problema detectado ({reg['4'].get('detalhe')}) e arquivo não mexido")
confere(open(f'{RAIZ}/Unica/d1.mbr', 'rb').read() and os.path.exists(f'{RAIZ}/Pedacos/d1.mbr'), 'MBR e demais arquivos ficam como estão')

# 3) passada repetida: nada a fazer
reg.clear()
m.passada()
confere('1' not in reg and '2' not in reg and '3' not in reg, 'passada repetida: nenhuma imagem mexida de novo')

subprocess.run(['rm', '-rf', T])
print('Tudo certo.' if not falhas else f'{len(falhas)} falha(s).')
sys.exit(1 if falhas else 0)
