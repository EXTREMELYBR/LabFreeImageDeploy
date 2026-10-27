"""Servidor de teste do painel: serve /opt/LabFreeImageDeploy/web e responde api.php com dados simulados.
Uso: python3 -I testes/painel_simulado.py 8099   (cenário por cookie 'cenario': cheio | vazio | problema | senha | deslogado)"""
import json
import os
import sys
import time
from datetime import datetime, timedelta, timezone
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

WEB = os.environ.get('WEB') or os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'web')  # o web/ deste repositório
TZ = timezone(timedelta(hours=-3))


def iso(min_atras):
    return (datetime.now(TZ) - timedelta(minutes=min_atras)).isoformat(timespec='seconds')


SERVICOS = [('LabFreeImageDeploy-kea', 'DHCP (entrega IPs)'), ('LabFreeImageDeploy-tftp', 'TFTP (arquivos de boot)'),
            ('nfs-kernel-server', 'NFS (pasta das imagens)'), ('apache2', 'Servidor web (painel)'), ('mariadb', 'Banco de dados'),
            ('LabFreeImageDeploy-multicast', 'Envio em grupo (multicast)'), ('LabFreeImageDeploy-agendador', 'Agendador de tarefas'),
            ('php8.3-fpm', 'PHP (boot das máquinas)')]


def disco(bytes_, min_atras=None, origem='sda', minimo=None):
    return {'existe': True, 'bytes': bytes_, 'capturada': iso(min_atras) if min_atras is not None else None,
            'disco_origem': origem if bytes_ else None, 'minimo_bytes': minimo}


def imagens(c):
    if c == 'vazio':
        return []
    return [
        {'id': 1, 'nome': 'Laboratorio Fontes', 'descricao': 'Windows 10 + Office + Scratch, sala de informática', 'caminho': 'LaboratorioFontes', 'tipo': 2,
         'os_id': 9, 'os': 'Windows 10', 'protegida': 1, 'maquinas': 18, 'redimensionavel': False, 'disco': disco(41.2e9, 60 * 24 * 12, 'sda', 128.04e9)},
        {'id': 2, 'nome': 'Windows 11 Administrativo', 'descricao': '', 'caminho': 'Win11Adm', 'tipo': 1,
         'os_id': 9, 'os': 'Windows 11', 'protegida': 0, 'maquinas': 4, 'redimensionavel': True, 'disco': disco(23.7e9, 60 * 5, 'nvme0n1', 64.4e9)},
        {'id': 3, 'nome': 'Ubuntu 24.04 Robótica com um nome bem comprido para testar quebra', 'descricao': 'ROS 2, Arduino IDE', 'caminho': 'UbuntuRobotica', 'tipo': 1,
         'os_id': 50, 'os': 'Linux', 'protegida': 0, 'maquinas': 0, 'redimensionavel': True, 'disco': disco(0)},
    ]


def maquinas(c):
    if c == 'vazio':
        return []
    l = []
    nomes = ['LAB-001', 'LAB-002', 'LAB-003', 'LAB-004', 'LAB-005', 'LAB-006', 'LAB-007', 'LAB-008', 'SECRETARIA-01', 'DIRECAO', 'PROFESSOR-SALA2', 'LAB-012']
    for i, n in enumerate(nomes, 1):
        m = {'id': i, 'nome': n, 'descricao': 'Cadastrada pelo menu de boot da rede' if i % 3 else 'Sala 2, patrimônio 004512', 'imagem_id': 1 if i < 9 else 2,
             'imagem': 'Laboratorio Fontes' if i < 9 else 'Windows 11 Administrativo', 'mac': f'00:1a:2b:3c:4d:{i:02x}', 'criada': iso(60 * 24 * 30),
             'tarefa': None, 'ultima': None, 'rede': None}
        if i == 1:
            m['tarefa'] = {'tipo': 'Restauração', 'estado': 3, 'pct': 64}
        if i == 2:
            m['tarefa'] = {'tipo': 'Captura', 'estado': 1, 'pct': 0}
        if i in (3, 4, 5):
            m['ultima'] = {'tipo': 'Restauração', 'quando': iso(60 * (i - 2)), 'imagem': 'Laboratorio Fontes'}
            m['rede'] = {'mac': m['mac'], 'ip': f'192.168.31.{100 + i}', 'visto': iso(i * 7)}
        if i == 12:
            m['imagem_id'] = 0
            m['imagem'] = ''
        l.append(m)
    return l


def novas(c):
    if c == 'vazio':
        return []
    return [{'mac': 'f4:8e:38:aa:01:9c', 'ip': '192.168.31.140', 'nome_rede': 'DESKTOP-7GH2KQ', 'visto': iso(3), 'visto_ts': time.time()},
            {'mac': '3c:52:82:11:fe:02', 'ip': '192.168.31.141', 'nome_rede': '', 'visto': iso(40), 'visto_ts': time.time() - 2400}]


def ativas(c):
    if c == 'vazio':
        return []
    base = {'decorrido': '00:12:31', 'sessao': None}
    return [
        dict(base, id=101, maquina='LAB-001', tipo='Restauração', estado=3, estado_texto='Em andamento', criada=iso(20), pct=64, copiado='14,2 GB', total='22,1 GB',
             restante='00:06:40', velocidade='1,9 GB/min', imagem='Laboratorio Fontes', fase='copiando', fase_texto='Gravando a parte 2 de 3 do disco', etapa=4, etapas=5, parte=2, sem_noticia=4, parada=False),
        dict(base, id=102, maquina='LAB-002', tipo='Captura', estado=1, estado_texto='Aguardando a máquina ligar', criada=iso(60 * 30), pct=0, copiado='', total='',
             restante='', velocidade='', imagem='Windows 11 Administrativo', fase='aguardando', fase_texto='Aguardando a máquina ligar pela rede', sem_noticia=0, parada=False),
        dict(base, id=103, maquina='PROFESSOR-SALA2', tipo='Restauração', estado=3, estado_texto='Em andamento', criada=iso(15), pct=0, copiado='', total='',
             restante='', velocidade='', imagem='Windows 11 Administrativo', fase='preparando', fase_texto='Verificando o disco da máquina', etapa=2, etapas=5, sem_noticia=6, parada=False),
        dict(base, id=104, maquina='LAB-008', tipo='Restauração', estado=3, estado_texto='Em andamento', criada=iso(50), pct=37, copiado='8,1 GB', total='22,1 GB',
             restante='00:09:10', velocidade='0 MB/min', imagem='Laboratorio Fontes', fase='copiando', fase_texto='Gravando no disco (parte 1 do disco)', sem_noticia=320, parada=True),
    ]


def sessoes(c):
    if c == 'vazio':
        return []
    mq = [
        {'id': 201, 'maquina': 'sala3-pc01', 'fase': 'copiando', 'pct': 41, 'fase_texto': 'Gravando no disco', 'sem_noticia': 3, 'parada': False},
        {'id': 202, 'maquina': 'sala3-pc02', 'fase': 'copiando', 'pct': 41, 'fase_texto': 'Gravando no disco', 'sem_noticia': 2, 'parada': False},
        {'id': 203, 'maquina': 'MAQUINA-COM-NOME-LONGO', 'fase': 'preparando', 'pct': 0, 'fase_texto': 'Preparando o disco (partições)', 'sem_noticia': 400, 'parada': True},
    ]
    # 'grupo' = andamento lido do porteiro do envio (máquinas conectadas, cadastradas ou não)
    conect = [{'ip': '192.168.31.101', 'mac': '00:1a:2b:3c:4d:01', 'nome': 'sala3-pc01'}, {'ip': '192.168.31.102', 'mac': '00:1a:2b:3c:4d:02', 'nome': 'sala3-pc02'},
              {'ip': '192.168.31.143', 'mac': '6c:4b:90:aa:bb:cc', 'nome': ''}]
    g1 = {'fase': 'enviando', 'conectadas': conect, 'iniciadas': 3, 'parte': 3, 'partes': 5, 'enviado': 12_700_000_000, 'total': 31_000_000_000, 'pct': 41,
          'velocidade': 38_400_000, 'restante': 480, 'retiradas': 1 if c == 'problema' else 0, 'espera_desde': iso(20)}
    n = 5 if c == 'problema' else 12
    sala = [{'ip': f'192.168.31.{110 + i}', 'mac': f'00:1a:2b:3c:5e:{i:02x}', 'nome': f'LAB-{i + 1:03d}' if i % 4 else ''} for i in range(n)]
    g2 = {'fase': 'aguardando', 'conectadas': sala, 'iniciadas': 0, 'parte': 1, 'partes': 5, 'enviado': 0, 'total': 28_000_000_000, 'pct': 0,
          'velocidade': 0, 'restante': 0, 'retiradas': 0, 'espera_desde': iso(9)}
    return [{'id': 7, 'nome': 'sala3', 'estado': 3, 'estado_texto': 'Em andamento', 'esperadas': 3, 'conectadas': 3, 'pct': 41, 'inicio': iso(12), 'fim': None,
             'imagem': 'Laboratorio Fontes', 'maquinas': mq, 'grupo': g1},
            {'id': 8, 'nome': 'lab-b', 'estado': 1, 'estado_texto': 'Aguardando', 'esperadas': 12, 'conectadas': n, 'pct': 0, 'inicio': iso(9), 'fim': None,
             'imagem': 'Windows 11 Administrativo', 'maquinas': [], 'grupo': g2},
            {'id': 9, 'nome': 'antiga', 'estado': 1, 'estado_texto': 'Aguardando', 'esperadas': 4, 'conectadas': 0, 'pct': 0, 'inicio': iso(1), 'fim': None,
             'imagem': 'Windows 11 Administrativo', 'maquinas': [], 'grupo': None}]


def historico(c):
    if c == 'vazio':
        return []
    h = [
        {'id': 90, 'maquina': 'LAB-003', 'tipo': 'Restauração', 'estado': 4, 'estado_texto': 'Concluída', 'criada': iso(65), 'imagem': 'Laboratorio Fontes', 'por': 'admin', 'tem_registro': True, 'motivo': ''},
        {'id': 89, 'maquina': 'LAB-007', 'tipo': 'Restauração', 'estado': 6, 'estado_texto': 'Falhou', 'criada': iso(80), 'imagem': 'Laboratorio Fontes', 'por': 'tecnico1', 'tem_registro': True,
         'motivo': 'Não foi possível recriar as partições: o disco desta máquina provavelmente é menor que o disco de onde a imagem veio (ou está com defeito).'},
        {'id': 88, 'maquina': 'LAB-004', 'tipo': 'Captura', 'estado': 5, 'estado_texto': 'Cancelada', 'criada': iso(60 * 26), 'imagem': 'Windows 11 Administrativo', 'por': 'admin', 'tem_registro': False, 'motivo': ''},
        {'id': 87, 'maquina': '(máquina excluída)', 'tipo': 'Restauração', 'estado': 6, 'estado_texto': 'Falhou', 'criada': iso(60 * 50), 'imagem': '', 'por': '', 'tem_registro': False,
         'motivo': 'O Windows não foi desligado por completo (hibernação/inicialização rápida). Desligue segurando Shift e tente de novo.'},
    ]
    return h + [{'id': 80 - i, 'maquina': f'LAB-0{10 + i}', 'tipo': 'Restauração', 'estado': 4, 'estado_texto': 'Concluída', 'criada': iso(60 * 24 * (3 + i)),
                 'imagem': 'Laboratorio Fontes', 'por': 'admin', 'tem_registro': i % 2 == 0, 'motivo': ''} for i in range(8)]


def resposta(acao, c, q, corpo):
    if acao == 'sessao':
        if c == 'deslogado':
            return 200, {'logado': False, 'versao': '0.6.1'}
        return 200, {'logado': True, 'usuario': 'admin', 'token': 'tok', 'versao': '0.6.1', 'trocar_senha': c == 'senha'}
    if acao == 'entrar':
        if corpo.get('senha') == 'errada':
            return 401, {'erro': 'Usuário ou senha incorretos.'}
        return 200, {'ok': True, 'usuario': corpo.get('usuario') or 'admin', 'token': 'tok', 'versao': '0.6.1', 'trocar_senha': False}
    if acao == 'resumo':
        prob = c == 'problema'
        return 200, {'servicos': [{'servico': s, 'descricao': d, 'ativo': not (prob and i in (0, 5))} for i, (s, d) in enumerate(SERVICOS)],
                     'rede': {'interface': 'enx00e04c690b37', 'ip': '192.168.31.1', 'adaptador': True, 'cabo': not prob, 'ip_ok': True, 'dhcp_escutando': not prob},
                     'disco': {'livre': 31e9 if prob else 312.4e9, 'total': 468.8e9, 'caminho': '/images'},
                     'contagem': {'maquinas': len(maquinas(c)), 'imagens': len(imagens(c)), 'novas': len(novas(c))},
                     'tarefas': ativas(c), 'multicast': sessoes(c), 'esquecidas': 0 if c == 'vazio' else 1, 'versao_fog': '1.5.10.2518'}
    if acao == 'maquinas':
        return 200, {'maquinas': maquinas(c), 'nao_cadastradas': novas(c)}
    if acao == 'imagens':
        return 200, {'imagens': imagens(c), 'sistemas': [{'id': 9, 'nome': 'Windows 10/11'}, {'id': 50, 'nome': 'Linux'}, {'id': 1, 'nome': 'Windows XP'}]}
    if acao == 'tarefas':
        return 200, {'ativas': ativas(c), 'multicast_ativas': sessoes(c), 'historico': historico(c),
                     'multicast_historico': [] if c == 'vazio' else [{'id': 5, 'nome': 'sala1', 'estado': 4, 'estado_texto': 'Concluída', 'esperadas': 20, 'conectadas': 20, 'pct': 100,
                                                                        'inicio': iso(60 * 24 * 2), 'fim': iso(60 * 24 * 2 - 30), 'imagem': 'Laboratorio Fontes'}]}
    if acao == 'acompanhar':
        a = ativas(c)
        return 200, {'tarefas': [dict(a[0], ativa=True), dict(a[1], ativa=True),
                                 {'id': 89, 'maquina': 'LAB-007', 'ativa': False, 'estado': 6, 'estado_texto': 'Falhou', 'motivo': historico('cheio')[1]['motivo'], 'tem_registro': True},
                                 {'id': 90, 'maquina': 'LAB-003', 'ativa': False, 'estado': 4, 'estado_texto': 'Concluída', 'motivo': '', 'tem_registro': True}]}
    if acao == 'registro':
        falha = q.get('id') == '89'
        reg = '\n'.join([' * Conectando ao servidor .......... OK', ' * Verificando o disco /dev/sda (120 GB) ..... OK',
                         ' * Preparando as partições', ' * ERRO: Failed to restore GPT partition tables (sgdisk exit 2)' if falha else ' * Gravando parte 1 de 3 ..... OK',
                         *([f'   partclone: {i * 10}% concluído, 1.9GB/min' for i in range(1, 11)] if not falha else []), ' * Fim'])
        return 200, {'maquina': 'LAB-007', 'resultado': 'erro' if falha else 'sucesso',
                     'mensagem': 'Não foi possível recriar as partições (disco menor que o da imagem).' if falha else '', 'registro': reg, 'quando': iso(70),
                     'erros': [{'texto': 'sgdisk exit 2', 'motivo': 'Não foi possível recriar as partições: o disco desta máquina provavelmente é menor que o disco de onde a imagem veio (ou está com defeito).', 'quando': iso(70)}] if falha else []}
    if acao == 'usuarios':
        return 200, {'usuarios': [{'id': 3, 'nome': 'admin', 'criado': iso(60 * 24 * 40), 'criado_por': '', 'eu': True},
                                  {'id': 4, 'nome': 'tecnico1', 'criado': iso(60 * 24 * 3), 'criado_por': 'admin', 'eu': False},
                                  {'id': 5, 'nome': 'joao.silva', 'criado': iso(60 * 5), 'criado_por': 'admin', 'eu': False}]}
    if acao == 'limpeza':
        return 200, {'historico': 13, 'fila': 2, 'em_andamento': 3, 'clonagens': 154, 'registros': 0}
    if acao == 'cadastro':
        return 200, {'prefixo': 'LAB-', 'sufixo': '', 'digitos': 3, 'numero': 1, 'imagem_id': 1, 'restaurar': False, 'sugestao': 'LAB-009', 'menu_espera': 0}
    if acao == 'dhcp':
        return 200, {'ativo': True, 'escutando': c != 'problema', 'interface': 'enx00e04c690b37', 'inicio': '192.168.31.100', 'fim': '192.168.31.250', 'gateway': '',
                     'dns': [], 'dominio': '', 'concessao_horas': 6, 'rede': '192.168.31.0/24', 'mascara': '255.255.255.0', 'servidor_ip': '192.168.31.1',
                     'leases_ativos': 7, 'tftp': '192.168.31.1',
                     'reservas': [{'mac': '00:1a:2b:3c:4d:09', 'ip': '192.168.31.20', 'nome': 'secretaria-01'}],
                     'arquivos_boot': [{'classe': 'FOG-UEFI-x64', 'arquivo': 'snponly.efi'}, {'classe': 'FOG-Legacy-BIOS', 'arquivo': 'undionly.kkpxe'},
                                       {'classe': 'FOG-UEFI-ARM64', 'arquivo': 'arm64-efi/snponly.efi'}]}
    if acao == 'rede':
        return 200, {'atual': {'interface': 'enx00e04c690b37', 'ip': '192.168.31.1', 'prefixo': 24},
                     'placas': [{'nome': 'enx00e04c690b37', 'usb': True, 'tipo': 'ethernet', 'modelo': 'Realtek RTL8153 Gigabit', 'mac': '00:e0:4c:69:0b:37', 'cabo': True,
                                 'velocidade': 1000, 'ips': ['192.168.31.1/24'], 'internet': False, 'fog': True, 'pode_usar': True},
                                {'nome': 'wlp2s0', 'usb': False, 'tipo': 'wifi', 'modelo': 'Intel Wi-Fi 6 AX201', 'mac': 'a4:c3:f0:11:22:33', 'cabo': True, 'velocidade': None,
                                 'ips': ['192.168.0.15/24'], 'internet': True, 'fog': False, 'pode_usar': False},
                                {'nome': 'enp1s0', 'usb': False, 'tipo': 'ethernet', 'modelo': 'Realtek RTL8111', 'mac': 'b0:7b:25:00:11:22', 'cabo': False, 'velocidade': None,
                                 'ips': [], 'internet': False, 'fog': False, 'pode_usar': True}]}
    if acao == 'rede_simular':
        return 200, {'mudancas': ['IP do servidor: 192.168.31.1 → 10.10.10.1', 'Faixa do DHCP: 10.10.10.100 a 10.10.10.250', 'Endereço do painel muda para https://10.10.10.1/'],
                     'avisos': ['As máquinas que já têm IP precisam reiniciar para pegar o novo.']}
    # ações que alteram: sucesso fingido
    if acao in ('capturar', 'restaurar'):
        return 200, {'ok': True, 'msg': 'Agendado.', 'tarefas': [101, 102, 89, 90], 'agendadas': ['LAB-003', 'LAB-004'], 'falhas': []}
    return 200, {'ok': True, 'msg': 'Feito (simulação).', 'id': 99}


class H(SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=WEB, **k)

    def log_message(self, *a):
        pass

    def translate_path(self, path):
        p = urlparse(path).path
        if p.startswith('/LabFreeImageDeploy/'):
            p = p[len('/LabFreeImageDeploy'):]
        return WEB + (p if p != '/' else '/index.html')

    def cenario(self):
        for parte in (self.headers.get('Cookie') or '').split(';'):
            k, _, v = parte.strip().partition('=')
            if k == 'cenario':
                return v
        return 'cheio'

    def api(self, corpo):
        q = {k: v[0] for k, v in parse_qs(urlparse(self.path).query).items()}
        cod, dados = resposta(q.get('a', ''), self.cenario(), q, corpo)
        b = json.dumps(dados, ensure_ascii=False).encode()
        self.send_response(cod)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def do_GET(self):
        if urlparse(self.path).path.endswith('/api.php'):
            return self.api({})
        if urlparse(self.path).path == '/fog/favicon.ico':
            self.send_response(204)
            self.end_headers()
            return
        return super().do_GET()

    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0)
        try:
            corpo = json.loads(self.rfile.read(n) or b'{}')
        except ValueError:
            corpo = {}
        return self.api(corpo)


ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
