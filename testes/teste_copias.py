"""Teste das cópias de imagens (lib/LabFreeImageDeploy-copias) sem root: pastas temporárias fazem o papel do HD
externo e de /images. Confere: exportar (arquivos iguais + manifesto com SHA-256), importar (arquivos iguais,
pasta nova quando o nome já existe), HD com arquivo estragado depois da cópia (recusado), cópia incompleta (recusada),
cancelamento no meio.   Uso: python3 -I testes/teste_copias.py"""
import hashlib
import importlib.machinery
import importlib.util
import json
import os
import shutil
import sys
import tempfile

AQUI = os.path.dirname(os.path.abspath(__file__))
carregador = importlib.machinery.SourceFileLoader('copias', os.path.join(AQUI, '..', 'lib', 'LabFreeImageDeploy-copias'))
spec = importlib.util.spec_from_loader('copias', carregador)
c = importlib.util.module_from_spec(spec)
carregador.exec_module(c)

T = tempfile.mkdtemp()
c.IMAGENS, c.ESTADO, c.BLOCO, c.PENDENTES = f'{T}/images', f'{T}/estado.json', 64 * 1024, f'{T}/pendentes.json'
HD = f'{T}/hd'
os.makedirs(f'{T}/images/Lab1'); os.makedirs(HD)
arquivos = {'d1.mbr': os.urandom(512), 'd1.partitions': b'label: gpt\n', 'd1p1.img': os.urandom(300_000), 'd1p2.img': os.urandom(1_200_000)}
for n, b in arquivos.items():
    open(f'{T}/images/Lab1/{n}', 'wb').write(b)
falhas = []


def confere(cond, texto):
    print(('OK   ' if cond else 'ERRO ') + texto)
    if not cond:
        falhas.append(texto)


def est():
    return c.Andamento({'fase': 'copiando', 'total': 0, 'feito': 0})


img = {'caminho': 'Lab1', 'nome': 'Laboratório 1', 'descricao': 'teste', 'os': 'Windows 10', 'os_id': 9, 'tipo': 1,
       'tipo_particao': 1, 'formato': 5, 'compressao': 3}
c.exportar(HD, [img], est())
dest = f'{HD}/{c.PASTA_DESTINO}/Lab1'
confere(all(open(f'{dest}/{n}', 'rb').read() == b for n, b in arquivos.items()), 'exportar: arquivos iguais no HD')
man = json.load(open(f'{dest}/{c.MANIFESTO}'))
confere(man['nome'] == 'Laboratório 1' and all(man['arquivos'][n]['sha256'] == hashlib.sha256(b).hexdigest() for n, b in arquivos.items()),
        'exportar: manifesto com nome e SHA-256 de cada arquivo')
confere(not any(n.startswith('.') for n in os.listdir(f'{HD}/{c.PASTA_DESTINO}')), 'exportar: sem pasta parcial sobrando')
confere([i['pasta'] for i in c.listar_destino(HD)] == ['Lab1'], 'listar: imagem aparece no destino')

e = est()
c.importar(HD, ['Lab1'], e)
confere(os.path.isdir(f'{T}/images/Lab1-2') and all(open(f'{T}/images/Lab1-2/{n}', 'rb').read() == b for n, b in arquivos.items()),
        'importar: nome já existente vira Lab1-2, arquivos iguais')
confere(e.d['importadas'][0]['caminho'] == 'Lab1-2' and e.d['importadas'][0]['nome'] == 'Laboratório 1', 'importar: dados para o painel cadastrar')

# HD estragou depois da cópia: mesmo tamanho, conteúdo diferente
b = bytearray(open(f'{dest}/d1p2.img', 'rb').read()); b[1000] ^= 0xFF
open(f'{dest}/d1p2.img', 'wb').write(bytes(b))
try:
    c.importar(HD, ['Lab1'], est())
    confere(False, 'importar com arquivo estragado: devia recusar')
except c.Erro as ex:
    confere('diferente' in str(ex), f'importar com arquivo estragado: recusado ({ex})')

# cópia incompleta (falta um arquivo)
os.remove(f'{dest}/d1p1.img')
try:
    c.importar(HD, ['Lab1'], est())
    confere(False, 'importar cópia incompleta: devia recusar')
except c.Erro as ex:
    confere('falta' in str(ex), f'importar cópia incompleta: recusado ({ex})')

# cancelar no meio
e = est(); e.d['cancelar'] = True
try:
    c.exportar(HD, [img], e)
    confere(False, 'cancelar: devia parar')
except c.Cancelado:
    confere(True, 'cancelar: para no meio')

shutil.rmtree(T)
print('Tudo certo.' if not falhas else f'{len(falhas)} falha(s).')
sys.exit(1 if falhas else 0)
