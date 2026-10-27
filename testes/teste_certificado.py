import importlib.machinery, importlib.util
spec = importlib.util.spec_from_loader('ff', importlib.machinery.SourceFileLoader('ff', '/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root'))
ff = importlib.util.module_from_spec(spec); spec.loader.exec_module(ff)
ff.PKI_WEB = '/tmp/claude-0/-/cb402e8a-de04-45e3-9f04-2548629d3c1c/scratchpad/pki-teste/web'; ff.SSL_DIR = '/tmp/claude-0/-/cb402e8a-de04-45e3-9f04-2548629d3c1c/scratchpad/pki-teste/ssl'; ff.HISTORICO = '/tmp/claude-0/-/cb402e8a-de04-45e3-9f04-2548629d3c1c/scratchpad/pki-teste/hist'
inst = ff.Instantaneo('teste-cert')
ff.reemitir_certificado('10.10.10.1', inst)
print('reemitido; cópias guardadas:', len([c for c in inst.arquivos.values() if c]))
