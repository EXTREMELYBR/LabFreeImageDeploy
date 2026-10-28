# Arquitetura do LabFree ImageDeploy

## Visão geral

```
                    navegador (painel)                         máquinas clientes (boot pela rede)
                           │ HTTPS                                   │ DHCP → TFTP → HTTPS → NFS/multicast
┌──────────────────────────┼──────────────────────────────────────────┼─────────────────────────────┐
│ servidor                 ▼                                          ▼                             │
│  /var/www/html/LabFreeImageDeploy/  (painel: HTML+JS+api.php)   Kea DHCP  ── tftpd ── Apache        │
│        │ classes PHP do FOG (Host, Image, Task, MulticastSession…)   │          │   /fog/service/*  │
│        │ sudo (só um arquivo) ──► /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root        │
│        │                              (rede, DHCP, marca, serviços — valida, aplica, desfaz)        │
│  base FOG 1.5.10 (/opt/fog, /var/www/html/fog, banco "fog", /images, /tftpboot)                    │
│  serviços LabFreeImageDeploy-* (multicast, agendador, replicação, ping…) = serviços do FOG renomeados│
└────────────────────────────────────────────────────────────────────────────────────────────────────┘
```

O LabFree **não é um fork** do código do FOG: a base FOG 1.5.10 é instalada pelo instalador oficial dela
(embutido em `base-fog/`, versão travada) e o LabFree é uma camada em volta:

| Peça | Arquivo(s) no pacote | Papel |
|---|---|---|
| Instalador | `instalar.sh` | instalação nova (rede → base FOG com respostas prontas → camada) e atualização/migração |
| Painel | `web/` | página única em JS puro (sem framework/CDN, fonte Inter local), `api.php` em JSON |
| Porteiro do envio em grupo | `lib/LabFreeImageDeploy-udp-sender` | fica no lugar do udp-sender da base: início só pelo painel, andamento para o painel, registro de cada sessão |
| Ajudante root | `lib/LabFreeImageDeploy-root` (Python, só biblioteca padrão) | ações privilegiadas: `dhcp-*`, `rede-*`, `reaplicar`, `marca-aplicar`, `servicos-aplicar`, `imagem-apagar` |
| Comandos | `sbin/LabFreeImageDeploy*` | `LabFreeImageDeploy verificar|backup|restaurar|reaplicar|senha|recompilar-ipxe` |
| Sistema | `sistema/` | sudoers, dispatcher do NetworkManager, timer de backup, regras do apt, energia |
| Marca | `marca/` | fundo do menu de boot, cadastro guiado (`fos/bin/fog.man.reg`), endpoint do cadastro (`servidor/`) |
| Ferramentas | `ferramentas/` | `empacotar.sh` (gera o pacote), `LabFreeImageDeploy-recompilar-ipxe` |

## Painel (`web/`)
- `index.html` carrega `app.css` e `app.js` com `?v=VERSÃO` (o `publicar.sh` atualiza; o navegador nunca mistura
  arquivo velho com API nova). Fonte Inter local, sem CDN.
- `app.js`: roteamento por `#/tela`; cada tela atualiza sozinha (`repetir()`) sem redesenhar quando nada mudou e sem
  redesenhar no meio de um clique. Assistentes (Capturar, Restaurar, Restaurar em grupo) em passos com revisão.
- `app.css`: cores em variáveis com tema escuro automático; os cartões usam *container queries* (o layout se ajusta à
  largura do cartão, não só da tela), com `@media` de reserva para navegadores antigos.

## Painel ↔ servidor
- `api.php` carrega `/var/www/html/fog/commons/base.inc.php` e usa as classes do FOG para **escrever**
  (mesmas regras do FOG); usa SQL direto só para **ler**. Login = sessão do FOG (cookie `path=/`).
- POST exige o cabeçalho `X-Painel-Token` (token da sessão) contra CSRF.
- Ações de root: `api.php` → `sudo -n /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root AÇÃO` com JSON
  no stdin (`proc_open` com array, nada passa por shell). O sudoers permite **só** esse arquivo e **só** as 7 ações
  do painel (`status`, `dhcp-ler`, `dhcp-aplicar`, `rede-listar`, `rede-simular`, `rede-aplicar`, `imagem-apagar`);
  o ajudante repete essa lista quando chamado pelo www-data, valida tudo (IPs, MACs, nomes de interface) e nunca
  monta comandos com texto livre.
- `rede-aplicar` grava um diário (`/var/lib/LabFreeImageDeploy/rede-pendente.json` + `manifesto.json` da cópia): se o
  processo morrer no meio (queda de energia), `LabFreeImageDeploy-recuperar.service` no boot — ou a próxima
  alteração — desfaz tudo (`recuperar`).
- Toda mudança do ajudante: trava exclusiva (`/run/LabFreeImageDeploy.lock`, uma alteração por vez) → valida → guarda
  cópia (`/var/lib/LabFreeImageDeploy/historico/`, últimas 60) → aplica → testa → **desfaz sozinho** se falhar
  (o banco, os arquivos e todos os campos da conexão do NetworkManager). Gravações preservam dono e permissões,
  usam arquivo temporário exclusivo e **nunca seguem link simbólico** (pastas do FOG pertencem ao www-data).
- `rede-aplicar` roda numa unidade própria do systemd (`systemd-run`): o painel roda no PHP-FPM, e o ajudante
  filho dele morreria junto com qualquer reinício do PHP/Apache. O Apache só recebe `reload`; o PHP-FPM não é reiniciado.
- Sessão do painel: login confere a senha sem usar a sessão atual e regenera o id; 10 erros por IP bloqueiam 15 min
  (arquivo em `/opt/fog/sessions`); 4 h sem uso pede login; trocar a senha invalida as outras sessões (impressão
  digital do hash na sessão); senha de fábrica (`admin`) bloqueia tudo com HTTP 428 até `primeira_senha`.

## Tela da máquina e acompanhamento ao vivo
- O sistema de clonagem (FOS, `init.xz`) é reconstruído pelo ajudante (`marca_fos`) a partir do original do FOG,
  guardado em `/var/lib/LabFreeImageDeploy/fos-original/` (só root). Entram os arquivos de `marca/fos/`:
  - `etc/init.d/S99fog`: captura/restauração (`type=up|down`) rodam por `/bin/labfree-tela`; o resto como no FOG.
  - `bin/labfree-tela`: roda `/bin/fog` no terminal 2 dentro de `script` (tudo gravado em `/tmp/labfree.bruto`),
    desenha no terminal 1 as etapas (deduzidas das mensagens do FOS), a barra (de `/tmp/status.fog`, do partclone)
    e o registro traduzido; envia a etapa a `service/LabFreeImageDeploy-etapa.php`; no fim não reinicia
    (tela de resultado com Reiniciar/Desligar/Registro/Técnico) e envia o resultado e o registro.
  - `usr/share/fog/lib/labfree-tela.sh`: carregado no fim do `funcs.sh`; com `LABFREE_TELA=1`, `handleError` grava o
    motivo em `/tmp/labfree.resultado` e sai (sem a espera de 1 min e o reboot), `handleWarning` espera 10 s.
- `service/LabFreeImageDeploy-etapa.php` grava na tabela `labfreeEtapas` (uma linha por tarefa: etapa, texto, parte,
  velocidade com unidade, resultado, mensagem, registro). O painel junta isso às tarefas (`api.php` `etapas()`,
  `fase()`), mostra o motivo das falhas (`motivo()`, também de `taskLog`) e o registro (`?a=registro&id=`).
- `api.php` também guarda, por tarefa, quando o andamento mudou pela última vez (arquivo em `/opt/fog/sessions`) para
  avisar "sem notícia há X min".

## Envio em grupo (multicast)
- A base (serviço `LabFreeImageDeploy-multicast`) roda, por sessão, `sh -c "udp-sender … --file d1p1.img; udp-sender …
  --file d1p2.img; …"` (uma partição por vez). `UDPSENDERPATH` aponta para o porteiro
  (`/usr/local/lib/LabFreeImageDeploy/udp-sender`), que chama o udp-sender de verdade.
- 1ª partição: o porteiro tira o início automático (`--min-receivers`, `--max-wait`, `--nokbd`) e liga um pipe como
  "teclado". O painel (`iniciar_multicast`) cria `/run/LabFreeImageDeploy/grupo/<porta>.iniciar`; com pelo menos uma
  máquina conectada, o porteiro "aperta a tecla". O painel também põe `msSenderStart` no passado: a base deixa de
  aceitar máquinas novas na sessão.
- Partições seguintes: `--min-receivers` = máquinas que receberam a anterior, `--max-wait 600`.
- O porteiro lê a saída do udp-sender (conexões, bytes, retiradas) e grava `<porta>.json`; o painel junta com as
  concessões do Kea (IP → MAC) e o cadastro (MAC → nome). Resumo por partição em `/var/log/LabFreeImageDeploy/grupo.log`.
- `FOG_UDPCAST_MAXWAIT` = 1440: janela para entrar e espera das máquinas (`--start-timeout` do udp-receiver no FOS).

## Otimização e conferência das imagens
- `LabFreeImageDeploy-otimizar.timer` (20 min) roda `lib/LabFreeImageDeploy-otimizar` com prioridade mínima. Só age
  com o servidor parado. Recomprime cada partição zstd com nível 19 + `--long=27` (o zstd do FOS lê sem opção
  extra), confere o SHA-256 do conteúdo descomprimido (novo = original) e só então troca o arquivo. Sem otimização:
  `zstd -t`. Reconfere a cada 30 dias. Andamento na tabela `labfreeImagens` (painel).
- Na restauração o FOS usa `partclone.restore --ignore_crc`; a soma de verificação do zstd é o que detecta dado
  errado (a máquina mostra erro em vez de gravar lixo).

## Ajustes do Windows e cópias
- Windows: nome pelo FOS (`FOG_CHANGE_HOSTNAME_EARLY`); domínio e licença pelo agente da base (`FOGService.msi`, módulo
  hostnamechanger) com os dados que `Painel::aplicarWindows()` grava em cada máquina.
- Cópias: `lib/LabFreeImageDeploy-copias` (root, chamado pela ação `copias` do ajudante). Trabalho em unidade própria
  do systemd; SHA-256 de cada arquivo no manifesto `labfree-imagem.json`; conferência lendo de volta do destino
  (cache descartado com `posix_fadvise`). O painel cadastra as imagens importadas com as classes da base.

## Internet para a rede de clonagem (NAT)
- Ajudante `nat-aplicar`: tabela própria do nftables `ip labfreeimagedeploy_nat` (repasse só da rede de clonagem +
  masquerade para as outras placas), `ip_forward`, regra do ufw quando ele está ativo, DHCP com o servidor como
  gateway e o DNS da placa de internet; o estado anterior fica em `/etc/LabFreeImageDeploy/nat.json` e volta ao
  desligar. `LabFreeImageDeploy-nat.service` recria no boot; trocar placa/IP e o `reaplicar` também recriam.

## Visual nas máquinas
- Menu de boot: `LabFreeImageDeploy-fundo.png` (gerado de `marca/fundo-boot.html`) + `CORES_IPXE`.
- Sistema de clonagem: `labfree-cores.sh` troca a paleta do console para as cores da LabFree e define as cores do
  whiptail; `labfree-tela` desenha com molduras e blocos da fonte do console.

## Rede de clonagem
- Configuração central: `/etc/LabFreeImageDeploy/LabFreeImageDeploy.conf` (`FOG_IFACE`, `FOG_MAC`, `FOG_IP`,
  `FOG_PREFIXO`, `FOG_NM_UUID`). Todos os scripts leem daqui.
- Conexão do NetworkManager presa à placa (nome **e** MAC), IP fixo, sem gateway/DNS, `never-default`.
- `ignore-carrier=yes` para essa placa (`/etc/NetworkManager/conf.d/90-LabFreeImageDeploy-ignorar-carrier.conf`):
  sem isso, quando um cliente reinicia a placa o link cai, o NM tira o IP do servidor e o envio multicast morre.
- Kea 2.4 não sobe se a interface não existir → drop-in com `ConditionPathExists`, `Restart=on-failure`, retry de
  socket no Kea, e o dispatcher `90-LabFreeImageDeploy-kea` reinicia o Kea quando a placa sobe.
- Trocar IP/placa (`rede-aplicar`) atualiza NM, Kea, `default.ipxe`, Apache, `config.class.php`, `.fogsettings`,
  banco (FOG_TFTP_HOST, FOG_WEB_HOST, FOG_NFS_ETH_MONITOR, FOG_UDPCAST_INTERFACE, nfsGroupMembers) e reemite o
  certificado HTTPS com a CA do servidor (o iPXE confia na raiz; não precisa recompilar).
- DHCP completo salvo em `/etc/LabFreeImageDeploy/dhcp.json` (o instalador do FOG só conhece faixa/gateway/1 DNS).

## Boot pela rede (o que o cliente vê)
1. Firmware → DHCP (Kea) → TFTP `undionly.kkpxe` (BIOS) ou `snponly.efi` (UEFI): iPXE recompilado com
   `PRODUCT_NAME "LabFree ImageDeploy"` e com a CA do servidor embutida.
2. `default.ipxe` → `ntp <IP do servidor>` (acerta o relógio; sem isso o HTTPS falha em BIOS com relógio errado)
   → `chain https://<IP>/fog/service/ipxe/boot.php`.
3. Menu: fundo `LabFreeImageDeploy-fundo.png`, cores e textos vindos do banco (`FOG_IPXE_*`, tabela `pxeMenu`)
   e de pequenas trocas em `bootmenu.class.php` (só ASCII: a fonte do iPXE não tem acentos).
4. Sistema de clonagem (FOS, `init.xz`): reconstruído com o banner LabFree e o cadastro guiado (`whiptail`).
   O original fica em `init.xz.fog-original`; um carimbo em `/var/lib/LabFreeImageDeploy/marca-*.sha256` evita
   reconstruir sem necessidade.
5. Cadastro guiado: `/bin/fog.man.reg` do LabFree → `service/LabFreeImageDeploy-cadastro.php` (nome sugerido
   pelo padrão `FOG_QUICKREG_SYS_NAME`, lista de imagens) → `service/auto.register.php` do FOG (campos em base64).

## Autonomia (sem atualizações do FOG)
- Base FOG embutida e travada; a interface original `/fog/management` e a API `/fog/api` ficam bloqueadas
  (`/etc/apache2/conf-available/LabFreeImageDeploy.conf`); `/fog/service` e `/fog/status` continuam (clientes).
- `fogproject.org` bloqueado em `/etc/hosts`; `sendreports='N'`; `FOG_CLIENT_AUTOUPDATE=0`.
- PHP travado (`apt-mark hold` nos metapacotes); o apt nunca substitui configurações alteradas; backup antes de cada
  `apt` e verificação depois (`/etc/apt/apt.conf.d/99-LabFreeImageDeploy-protecao`).

## Testes
`testes/` (fora do pacote): `teste_grupo.sh` (envio em grupo real numa rede virtual, sem root), `painel_simulado.py` + `plano_fotos.py` + `fotos_painel.py` (painel com API simulada,
fotos de todas as telas e verificação automática de layout), `cdp_teste.py` (painel no Chrome headless, com MOCKS), `boot_vm.sh` (boot PXE real numa
VM QEMU/KVM via slirp, BIOS e UEFI), testes de desfazer do DHCP e da rede, reemissão de certificado em cópia.
Veja `AGENTS.md` para os comandos.
