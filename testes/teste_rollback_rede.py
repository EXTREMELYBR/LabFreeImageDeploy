import importlib.machinery, importlib.util, subprocess
spec = importlib.util.spec_from_loader('ff', importlib.machinery.SourceFileLoader('ff', '/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root'))
ff = importlib.util.module_from_spec(spec); spec.loader.exec_module(ff)
def falha(ip, inst):
    r = subprocess.run(['ip', '-4', '-br', 'addr', 'show', 'dev', 'enx00e04c690b37'], capture_output=True, text=True).stdout.strip()
    banco = ff.mysql("select settingValue from globalSettings where settingKey='FOG_WEB_HOST'").strip()
    print('no meio da troca -> placa:', r, '| banco FOG_WEB_HOST:', banco, '| default.ipxe tem 10.10.10.1:', '10.10.10.1' in open(ff.DEFAULT_IPXE).read())
    raise ff.Erro('falha simulada na reemissão do certificado')
ff.reemitir_certificado = falha
try:
    ff.rede_aplicar({'interface': 'enx00e04c690b37', 'ip': '10.10.10.1', 'prefixo': 24})
    print('ERRO: deveria ter falhado')
except ff.Erro as e:
    print('resultado:', e)
