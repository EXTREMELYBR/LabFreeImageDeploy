"""Teste da limpeza de sobras de capturas (ajudante, ação dev-orfas) sem root e sem tocar no /images real:
pastas falsas numa pasta temporária e o banco simulado. Uso: python3 -I testes/teste_orfas.py  → "Tudo certo." """
import importlib.machinery
import importlib.util
import os
import pathlib
import sys
import tempfile
import time

RAIZ = pathlib.Path(__file__).resolve().parent.parent
carregador = importlib.machinery.SourceFileLoader('ajudante', str(RAIZ / 'lib' / 'LabFreeImageDeploy-root'))
spec = importlib.util.spec_from_loader('ajudante', carregador)
aj = importlib.util.module_from_spec(spec)
carregador.exec_module(aj)

falhas = []


def confere(cond, texto):
    print(('  ok   ' if cond else '  FALHA ') + texto)
    if not cond:
        falhas.append(texto)


def criar(raiz, nome, mb=1, horas=2):
    d = raiz / nome
    d.mkdir()
    (d / 'd1p1.img').write_bytes(b'x' * mb * 1024 * 1024)
    antigo = time.time() - horas * 3600
    for x in (d / 'd1p1.img', d):
        os.utime(x, (antigo, antigo))
    return d


with tempfile.TemporaryDirectory() as tmp:
    raiz = pathlib.Path(tmp) / 'dev'
    raiz.mkdir()
    (raiz / 'postinitscripts').mkdir()
    (raiz / '.mntcheck').touch()
    criar(raiz, 'aabbccddee01')              # sobra de máquina cadastrada, sem tarefa
    criar(raiz, 'aabbccddee02')              # máquina com captura em andamento
    criar(raiz, 'aabbccddee03', horas=0)     # gravada agora (captura começando)
    criar(raiz, 'aabbccddee04', mb=2)        # sobra de máquina sem cadastro
    criar(raiz, 'AABBCCDDEE05')              # nome fora do padrão (maiúsculas)
    alvo_link = pathlib.Path(tmp) / 'importante'
    alvo_link.mkdir()
    (alvo_link / 'nao-apagar').touch()
    os.symlink(alvo_link, raiz / 'aabbccddee06')  # link simbólico: nunca seguir

    def mysql_falso(sql):
        if 'JOIN tasks' in sql:
            return 'aa:bb:cc:dd:ee:02\n'
        return 'aa:bb:cc:dd:ee:01\tLAB-001\naa:bb:cc:dd:ee:02\tLAB-002\n'
    aj.mysql = mysql_falso
    aj.DEV_IMAGENS = str(raiz)
    aj.log = lambda *a: None

    lista = aj.dev_orfas({'acao': 'listar'})['orfas']
    pastas = [o['pasta'] for o in lista]
    confere(pastas == ['aabbccddee01', 'aabbccddee04'], f'lista só as sobras paradas e livres: {pastas}')
    confere(lista[0]['maquina'] == 'LAB-001' and lista[1]['maquina'] == '', 'nome da máquina quando cadastrada')
    confere(lista[0]['mac'] == 'aa:bb:cc:dd:ee:01', 'MAC formatado')
    confere(lista[1]['bytes'] == 2 * 1024 * 1024, 'tamanho somado')

    for pedido, motivo in [(['aabbccddee02'], 'em uso por uma tarefa'), (['aabbccddee03'], 'gravada há pouco'),
                           (['aabbccddee06'], 'link simbólico'), (['../dev'], 'nome inválido'), ([], 'lista vazia'),
                           (['aabbccddee01', 'aabbccddee02'], 'uma das pedidas está em uso (nenhuma é apagada)')]:
        try:
            aj.dev_orfas({'acao': 'apagar', 'pastas': pedido})
            confere(False, f'recusa: {motivo}')
        except aj.Erro:
            confere(True, f'recusa: {motivo}')
    confere((raiz / 'aabbccddee01').is_dir(), 'pedido recusado não apaga nada')

    r = aj.dev_orfas({'acao': 'apagar', 'pastas': ['aabbccddee01', 'aabbccddee04']})
    confere(r['apagadas'] == 2 and r['bytes'] == 3 * 1024 * 1024, f'apaga as sobras pedidas: {r}')
    sobrou = sorted(os.listdir(raiz))
    confere(sobrou == ['.mntcheck', 'AABBCCDDEE05', 'aabbccddee02', 'aabbccddee03', 'aabbccddee06', 'postinitscripts'],
            f'o resto fica: {sobrou}')
    confere((alvo_link / 'nao-apagar').exists(), 'destino do link intacto')

print('Tudo certo.' if not falhas else f'{len(falhas)} falha(s).')
sys.exit(1 if falhas else 0)
