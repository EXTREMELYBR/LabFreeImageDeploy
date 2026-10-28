# Instalação do LabFree ImageDeploy

## Antes de começar

| Item | Requisito |
|---|---|
| Sistema | Ubuntu 22.04 ou 24.04, Linux Mint 21 ou 22, Debian 12 — 64 bits (x86_64) |
| Rede do servidor | **NetworkManager** ativo (padrão em Ubuntu/Mint desktop) |
| Placa de clonagem | Uma placa **com cabo**, só para a rede de clonagem (adaptador USB-Ethernet serve). Não pode ser a placa que leva o servidor à internet, nem Wi-Fi |
| Internet | Necessária durante a instalação só para os pacotes do sistema (Ubuntu/Debian). Todo o resto vem no pacote: o LabFree ImageDeploy não baixa nada do projeto FOG nem do GitHub |
| Memória | 4 GB ou mais |
| Disco | Espaço para as imagens: cada uma ocupa de 10 a 60 GB (ficam em `/images`) |
| Rede de clonagem | Um switch onde ficam a placa de clonagem do servidor e as máquinas a clonar. **Nenhum outro servidor DHCP** nessa rede (o LabFree entrega os IPs) |

## Instalação

```bash
tar xzf LabFreeImageDeploy-<versão>.tar.gz
cd LabFreeImageDeploy-<versão>/
sudo ./instalar.sh
```

O instalador:
1. confere o sistema, a memória, o disco e a internet;
2. lista as placas de rede e pergunta qual atende a rede de clonagem (a placa da internet e o Wi-Fi ficam bloqueados);
3. configura essa placa com IP fixo (padrão `192.168.31.1/24`, sem gateway e sem DNS — a internet do servidor continua pela outra placa);
4. instala a base (FOG 1.5.10, embutida no pacote) sem fazer perguntas;
5. aplica o LabFree ImageDeploy: painel, marca nas telas de boot, cadastro guiado, DHCP, proteções e backups;
6. recompila o firmware de boot (iPXE) com o nome do produto (10 a 20 minutos);
7. cria o usuário **admin** com senha **admin** e mostra o endereço do painel. No primeiro acesso o painel exige uma senha nova.

### Opções

| Opção | Para que serve |
|---|---|
| `--placa NOME` | placa da rede de clonagem (ex.: `enp3s0`, `enx00e04c690b37`) |
| `--ip IP` / `--mascara N` | IP fixo do servidor e máscara em bits (padrão `192.168.31.1` e `24`) |
| `--faixa INI-FIM` | faixa de IPs entregues às máquinas (padrão: `.10` até o último) |
| `--senha SENHA` | senha inicial do `admin` (padrão: `admin`, com troca obrigatória no primeiro acesso) |
| `--notebook` / `--nao-notebook` | impedir (ou não) suspensão e hibernação; notebooks são detectados sozinhos |
| `--sem-ipxe-marca` | não recompilar o iPXE com o nome do produto (economiza ~15 minutos) |
| `--sim` | não perguntar nada |
| `--forcar` | instalar em sistema não testado |

Registro completo da instalação: `/var/log/LabFreeImageDeploy/instalacao.log`.

## Depois de instalar

1. Ligue o cabo da placa de clonagem no switch das máquinas.
2. Abra `https://<IP do servidor>/` (o navegador avisa sobre o certificado: é o certificado próprio do servidor — clique em *Avançado → Continuar*).
   O painel também responde pelos outros IPs do servidor (por exemplo, o da rede da internet).
3. Entre com **admin** / **admin**, crie sua senha (obrigatório) e siga o *Primeiro uso* do README.
4. Rode `sudo LabFreeImageDeploy verificar`: deve terminar com "Tudo certo."

## Atualizar para uma versão nova

Extraia o pacote novo e rode o mesmo comando: `sudo ./instalar.sh`. Em máquina que já tem o sistema, ele
**atualiza** (painel, scripts, marca) sem reinstalar a base e sem apagar imagens, máquinas ou configurações.
Versões de desenvolvimento antigas (nomes "FOG Fácil", `fogfacil`, `labfree`) são migradas automaticamente;
os arquivos antigos ficam guardados em `/var/lib/LabFreeImageDeploy/migracao/`.

## Onde fica cada coisa

| Caminho | Conteúdo |
|---|---|
| `/opt/LabFreeImageDeploy/` | cópia do pacote instalado (inclui a base FOG em `base-fog/`) |
| `/etc/LabFreeImageDeploy/` | configuração: `LabFreeImageDeploy.conf` (placa e IP), `dhcp.json` (DHCP), `versao` |
| `/var/www/html/LabFreeImageDeploy/` | painel web |
| `/usr/local/lib/LabFreeImageDeploy/` | ajudante que aplica rede e DHCP com segurança |
| `/usr/local/sbin/LabFreeImageDeploy*` | comandos de administração |
| `/var/lib/LabFreeImageDeploy/` | cópias de segurança de cada mudança, compilação do iPXE |
| `/var/log/LabFreeImageDeploy/` | registros (`sistema.log`, `verificar.log`, `instalacao.log`) |
| `/var/backups/LabFreeImageDeploy/` | backups automáticos (toda segunda às 12h e antes de cada `apt`) |
| `/images` | as imagens capturadas |
| serviços `LabFreeImageDeploy-*` | multicast, agendador, replicação, ping, backup |

Caminhos herdados da base FOG que **não mudam** (as máquinas clientes dependem deles durante o boot):
`/opt/fog`, `/var/www/html/fog/service`, `/tftpboot`, `/images`.

## Problemas na instalação

| Mensagem | O que fazer |
|---|---|
| "Nenhuma placa com cabo disponível" | Conecte um adaptador USB-Ethernet (ou outra placa). A placa da internet não pode ser usada |
| "A rede … conflita com a placa …" | O IP escolhido está na mesma faixa de outra rede do servidor: use `--ip 10.10.10.1` |
| "Sem internet" | A instalação precisa baixar pacotes; conecte o servidor à internet pela outra placa |
| "A instalação da base falhou" | Veja o fim de `/var/log/LabFreeImageDeploy/instalacao.log`. Rodar `sudo ./instalar.sh` de novo continua de onde parou |
| Máquinas não recebem IP | `sudo LabFreeImageDeploy verificar`; confira o cabo e se não há outro servidor DHCP na rede de clonagem |
