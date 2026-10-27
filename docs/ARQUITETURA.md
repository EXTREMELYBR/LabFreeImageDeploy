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
| Ajudante root | `lib/LabFreeImageDeploy-root` (Python, só biblioteca padrão) | ações privilegiadas: `dhcp-*`, `rede-*`, `reaplicar`, `marca-aplicar`, `servicos-aplicar`, `imagem-apagar` |
| Comandos | `sbin/LabFreeImageDeploy*` | `LabFreeImageDeploy verificar|backup|restaurar|reaplicar|senha|recompilar-ipxe` |
| Sistema | `sistema/` | sudoers, dispatcher do NetworkManager, timer de backup, regras do apt, energia |
| Marca | `marca/` | fundo do menu de boot, cadastro guiado (`fos/bin/fog.man.reg`), endpoint do cadastro (`servidor/`) |
| Ferramentas | `ferramentas/` | `empacotar.sh` (gera o pacote), `LabFreeImageDeploy-recompilar-ipxe` |

## Painel ↔ servidor
- `api.php` carrega `/var/www/html/fog/commons/base.inc.php` e usa as classes do FOG para **escrever**
  (mesmas regras do FOG); usa SQL direto só para **ler**. Login = sessão do FOG (cookie `path=/`).
- POST exige o cabeçalho `X-Painel-Token` (token da sessão) contra CSRF.
- Ações de root: `api.php` → `sudo -n /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root AÇÃO` com JSON
  no stdin (`proc_open` com array, nada passa por shell). O sudoers permite **só** esse arquivo e **só** as 7 ações
  do painel (`status`, `dhcp-ler`, `dhcp-aplicar`, `rede-listar`, `rede-simular`, `rede-aplicar`, `imagem-apagar`);
  o ajudante repete essa lista quando chamado pelo www-data, valida tudo (IPs, MACs, nomes de interface) e nunca
  monta comandos com texto livre.
- Toda mudança do ajudante: trava exclusiva (`/run/LabFreeImageDeploy.lock`, uma alteração por vez) → valida → guarda
  cópia (`/var/lib/LabFreeImageDeploy/historico/`, últimas 60) → aplica → testa → **desfaz sozinho** se falhar
  (o banco, os arquivos e todos os campos da conexão do NetworkManager). Gravações preservam dono e permissões,
  usam arquivo temporário exclusivo e **nunca seguem link simbólico** (pastas do FOG pertencem ao www-data).
- `rede-aplicar` roda numa unidade própria do systemd (`systemd-run`): o painel roda no PHP-FPM, e o ajudante
  filho dele morreria junto com qualquer reinício do PHP/Apache. O Apache só recebe `reload`; o PHP-FPM não é reiniciado.
- Sessão do painel: login confere a senha sem usar a sessão atual e regenera o id; 10 erros por IP bloqueiam 15 min
  (arquivo em `/opt/fog/sessions`); 4 h sem uso pede login; trocar a senha invalida as outras sessões (impressão
  digital do hash na sessão); senha de fábrica (`admin`) bloqueia tudo com HTTP 428 até `primeira_senha`.

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
`testes/` (fora do pacote): `cdp_teste.py` (painel no Chrome headless, com MOCKS), `boot_vm.sh` (boot PXE real numa
VM QEMU/KVM via slirp, BIOS e UEFI), testes de desfazer do DHCP e da rede, reemissão de certificado em cópia.
Veja `AGENTS.md` para os comandos.
