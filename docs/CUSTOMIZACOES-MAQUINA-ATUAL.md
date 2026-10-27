# Customizações locais do servidor FOG (26/10/2026)

Rede FOG: adaptador USB Realtek RTL8153, interface `enx00e04c690b37`
(MAC 00:E0:4C:69:0B:37), IP 192.168.31.1/24, sem gateway e sem DNS.
A internet sai pelo Wi-Fi `wlp2s0` e não foi alterada.

## Arquivos criados/alterados

| Arquivo | O que faz | O installfog.sh sobrescreve? |
|---|---|---|
| `/etc/kea/kea-dhcp4.conf` | adicionadas as opções `service-sockets-*` (retry a cada 5 s quando a placa existe mas ainda não tem IP) | **SIM**, regrava do zero. Depois de rodar o instalador, rode `sudo fog-reaplicar-kea` |
| `/tftpboot/default.ipxe` | `ntp 192.168.31.1` antes do `chain https://...` (cliente com relógio de BIOS errado falhava com "Permission denied 0216eb8f") | **SIM**, regenerado. Rode `sudo fog-reaplicar-customizacoes` |
| `/etc/chrony/conf.d/fog.conf` | chrony (substituiu o systemd-timesyncd) serve hora para 192.168.31.0/24, também sem internet | não |
| `/usr/local/sbin/fog-reaplicar-customizacoes` (link: `fog-reaplicar-kea`) | reinsere as customizações do Kea e do default.ipxe, valida e reinicia o Kea | não |
| `/etc/systemd/system/kea-dhcp4-server.service.d/10-fog-usb-nic.conf` | Kea só sobe se o adaptador existir; `Restart=on-failure` | não |
| `/etc/NetworkManager/dispatcher.d/90-kea-fog` | reinicia o Kea sempre que a placa sobe (boot, USB plugado, cabo, `nmcli con up`) | não |
| `/etc/udev/rules.d/90-fog-usb-nic-nopm.rules` | sem autosuspend USB no adaptador | não |
| Perfil NM "Ligação com fios 1" (fica em `/etc/netplan/90-NM-fa1be90c-...yaml`) | preso ao nome da interface e ao MAC, autoconnect, tentativas infinitas, prioridade 10 | não |
| `/var/www/html/painel/` (index.html, app.css, app.js, api.php) | **Painel simplificado** em `https://192.168.31.1/` — usa o login e as classes do próprio FOG | não (o instalador só recria `/var/www/html/fog`) |
| `/var/www/html/index.php` | raiz do site abre `/painel/` (backup do original e da página padrão do Apache em `/root/www-index.*`) | não (só cria se não existir) |
| `/etc/apt/apt.conf.d/99-fog-sem-atualizacao-automatica` + timers `apt-daily*`, `fwupd-refresh`, `mintupdate-automation-*` desativados + Gerenciador de Atualizações sem verificação automática | nenhuma atualização automática; atualize manualmente | não |
| `/etc/systemd/logind.conf.d/90-fog-servidor.conf` | ignora tampa e teclas de suspender/hibernar | não |
| `/etc/systemd/sleep.conf.d/90-fog-servidor.conf` | proíbe suspender/hibernar | não |
| `sleep/suspend/hibernate/hybrid-sleep/suspend-then-hibernate.target` | mascarados (`systemctl unmask ...` desfaz) | não |
| gsettings do Cinnamon (usuário labfree) | tampa/botões = nada, bateria crítica = desligar | não |

Backups: `*.bak-20261026` ao lado de cada arquivo; o netplan, o perfil NM e os gsettings antigos estão em `/root/*.bak-20261026*`.
O backup antigo do dispatcher ficou em `/etc/NetworkManager/90-kea-fog.bak-20261026` (fora de `dispatcher.d` para não ser executado).

## Se mudar a interface (outro adaptador USB)
O nome `enx...` vem do MAC. Com outro adaptador, atualize: `interface=` em `/opt/fog/.fogsettings`,
`/etc/kea/kea-dhcp4.conf`, o drop-in do Kea, o dispatcher, `fog-reaplicar-kea`, a regra udev
(se for outro chipset) e o perfil NM (`802-3-ethernet.mac-address` e `connection.interface-name`).

## Reinstalar/atualizar o FOG
1. Plugue o adaptador USB (o instalador lê o IP da interface).
2. `cd /opt/fogproject-stable/bin && sudo ./installfog.sh -y`
3. `sudo fog-reaplicar-customizacoes`

## Painel simplificado
- Endereço: `https://192.168.31.1/` (ou `/painel/`). Usuário e senha do FOG; a sessão é a mesma do painel original.
- Painel original: `https://192.168.31.1/fog/management/`.
- Depois de atualizar o FOG para outra versão, abra o painel e teste Início, Máquinas e Imagens:
  o backend (`api.php`) usa classes internas do FOG, que podem mudar entre versões.
- Backup do painel: `/root/painel-fog-v2-20261026.tar.gz` (versão atual) e `/root/painel-fog-20261026.tar.gz` (primeira versão).
- Fonte Inter servida localmente em `/var/www/html/painel/fontes/` (licença OFL); o painel não depende de internet.

## Proteção contra atualizações (apt update / upgrade)
O FOG e o painel **não são pacotes do apt** (foram instalados à mão), então o apt não os apaga nem atualiza.
Para cobrir o que o apt poderia afetar indiretamente:

| Proteção | Onde |
|---|---|
| PHP travado na versão 8.4 (o PPA ondrej/php não troca para 8.5 sozinho; o 8.4 continua recebendo correções) | `apt-mark showhold` — para destravar: `sudo apt-mark unhold php php-cli php-fpm libapache2-mod-php php-bcmath php-curl php-gd php-intl php-json php-ldap php-mbstring php-mysql` |
| apt nunca substitui configurações alteradas (kea-dhcp4.conf, vsftpd.conf…) | `/etc/apt/apt.conf.d/99-fog-protecao` |
| Backup automático antes de cada apt install/upgrade (no máx. 1 a cada 6 h) e toda segunda às 12h | `/usr/local/sbin/fog-backup`, `fog-backup.timer`, guardados em `/root/backups-fog/` (15 mais recentes) |
| Verificação automática depois de cada apt: restaura o painel do backup se algo sumir e avisa problemas | `/usr/local/sbin/fog-verificar`, log em `/var/log/fog-verificar.log` |

Comandos úteis:
- `sudo fog-verificar` — confere tudo agora.
- `sudo fog-backup` — faz um backup agora.
- `sudo fog-restaurar-backup` — lista backups; `sudo fog-restaurar-backup ultimo painel|configuracoes|banco` restaura.

O que **ainda** pode desfazer customizações: rodar `installfog.sh` (atualizar o FOG). Depois dele, rode `sudo fog-reaplicar-customizacoes` e `sudo fog-verificar`.
