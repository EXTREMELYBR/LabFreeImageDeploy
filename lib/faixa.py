"""Faixa do DHCP para o instalador, com a mesma regra e a mesma validação do ajudante root.

Uso: python3 -I faixa.py CAMINHO_DO_AJUDANTE IP/PREFIXO [INICIO-FIM]
Imprime "INICIO-FIM" ou "ERRO: motivo".
"""
import importlib.machinery
import importlib.util
import ipaddress
import sys

carregador = importlib.machinery.SourceFileLoader('ajudante', sys.argv[1])
m = importlib.util.module_from_spec(importlib.util.spec_from_loader('ajudante', carregador))
carregador.exec_module(m)
try:
    ifc = ipaddress.ip_interface(sys.argv[2])
except ValueError:
    print(f'ERRO: endereço inválido: {sys.argv[2]}')
    sys.exit(0)
pedida = sys.argv[3] if len(sys.argv) > 3 else ''
if pedida:
    ini, _, fim = pedida.partition('-')
else:
    ini, fim = m.faixa_padrao(ifc.network, ifc.ip)
try:
    v = m.dhcp_validar({'inicio': str(ini).strip(), 'fim': str(fim).strip()}, ifc.network, ifc.ip)
except m.Erro as e:
    print(f'ERRO: {e}')
    sys.exit(0)
print(f"{v['inicio']}-{v['fim']}")
