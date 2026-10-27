import importlib.machinery, importlib.util, json, hashlib
cam = '/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root'
spec = importlib.util.spec_from_loader('ff', importlib.machinery.SourceFileLoader('ff', cam))
ff = importlib.util.module_from_spec(spec); spec.loader.exec_module(ff)
h = lambda p: hashlib.md5(open(p, 'rb').read()).hexdigest()
antes = (h(ff.KEA), h(ff.FOGSETTINGS))
real = ff.kea_reiniciar
chamadas = {'n': 0}
def falha(conf):
    chamadas['n'] += 1
    if chamadas['n'] == 1:   # primeira chamada = dentro do dhcp-aplicar
        raise ff.Erro('falha simulada no reinício do Kea')
    return real(conf)        # segunda = reinício depois de desfazer
ff.kea_reiniciar = falha
try:
    ff.dhcp_aplicar({'ativo': True, 'inicio': '192.168.31.100', 'fim': '192.168.31.200', 'gateway': '', 'concessao_horas': 2})
    print('ERRO: deveria ter falhado')
except ff.Erro as e:
    print('falhou como esperado:', e)
depois = (h(ff.KEA), h(ff.FOGSETTINGS))
print('arquivos restaurados ao estado anterior:', antes == depois)
print('Kea escutando de novo:', ff.esperar_dhcp('192.168.31.1'))
