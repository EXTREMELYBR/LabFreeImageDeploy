# LabFree ImageDeploy — guia para quem continuar (IA ou pessoa). LEIA INTEIRO ANTES DE MEXER.

> Atualizado em 28/10/2026 (versão 0.13.2). Versão no arquivo `VERSION`; histórico em `CHANGELOG.md`.
> Código no GitHub, repositório **privado** (remoto `origin`; veja `git remote -v`). `CLAUDE.md` é um link para este arquivo.
>
> **REGRA DO USUÁRIO (26/10): toda alteração precisa ser documentada e enviada ao GitHub como versão nova.**
> Ao terminar qualquer mudança: (1) atualize a documentação afetada (README, docs/, este arquivo);
> (2) aumente `VERSION` (X.Y.Z: correção → Z, função nova → Y); (3) descreva a mudança em `CHANGELOG.md` na seção
> `## [X.Y.Z] - AAAA-MM-DD`; (4) rode `ferramentas/publicar.sh` (confere sintaxe e senhas, faz commit, tag `vX.Y.Z`
> e envia; `--pacote` também anexa o pacote de instalação a uma release privada).

## 1. O que é e o que o usuário pediu
Produto **LabFree ImageDeploy**: clonagem de máquinas pela rede, **baseado no FOG Project 1.5.10** (GPL v3).
Não é fork: a base FOG é instalada pelo instalador oficial dela (embutida em `base-fog/` no pacote, travada) e o
LabFree é uma camada em volta. Pedidos do usuário (em ordem cronológica, todos atendidos salvo indicação):
1. Servidor FOG confiável numa rede isolada por adaptador USB (Kea, NM, dispatcher, energia) — feito.
2. Painel simplificado, bonito, moderno, responsivo, fácil (assistentes) — feito (`web/`).
3. Desligar atualizações automáticas do Mint; proteger contra `apt upgrade` — feito.
4. Configurar DHCP completo e a placa de rede pelo painel — feito (aba Configurações + `lib/LabFreeImageDeploy-root`).
5. Instalador simples + pacote + documentação; "documente tudo para outra IA continuar" — feito (testes em andamento, seção 7).
6. Renomear para **LabFree ImageDeploy**, remover o painel avançado `/fog`, sistema **autônomo** (sem atualizações do FOG),
   nome trocado **até nas telas de boot PXE** — feito.
7. Cadastro de máquinas pelo boot **simples, rápido, poucos passos, bonito** — feito (whiptail, 2 passos).
8. Pastas, diretórios, serviços "e cia" com o nome **LabFreeImageDeploy** — feito no repositório e no instalador;
   na produção, migrado quando o usuário rodou o instalador em 26/10 (seção 8).
9. Usuário/senha padrão **admin/admin** com **troca obrigatória no primeiro acesso** — feito (exigida no servidor: HTTP 428 em
   qualquer ação até `primeira_senha`; `LabFreeImageDeploy senha USUARIO --redefinir` volta para admin/admin).
10. Objetivo comercial: "software que dê para vender" para escolas e empresas (ver seção 10).
11. Varreduras completas de segurança e funcionamento (0.5.0) e publicação no GitHub privado com documentação de toda
    mudança (regra no topo deste arquivo) — feito.
12. Porcentagem, etapas e mensagens na tela da máquina e no painel; a máquina **não reinicia sozinha** no fim (mostra o
    resultado e o registro); limpar o histórico pelo painel — feito na 0.6.0 (tela da máquina depende do instalador, seção 8).
13. Varredura do painel tela a tela: mais bonito, moderno, fácil de entender, sem falhas de layout — feito na 0.7.0.
14. (27/10, noite) Multicast: mostrar **todas** as máquinas conectadas (o painel não mostrava as não cadastradas nem o
    progresso); **avisar quando a sala completar e esperar o humano iniciar**; poder iniciar com menos máquinas;
    **a máquina não inicia nada sozinha** (o menu de boot ia para o disco em 3 s); opções para gastar menos banda e
    tempo — escolhidas: compressão no painel + recomprimir no servidor (pendente, seção 9) e guia de limpeza +
    medição (feitos). Tarefa agendada continua começando sozinha no boot (decisão do usuário). Feito na 0.8.0.
15. (27/10, noite) Fazer a opção D (compressão + recomprimir no servidor); captura e restauração (única e em grupo)
    mais rápidas e com menos banda; **garantir a fidelidade dos dados**; sugerir funções novas; "Detectadas na rede"
    não pode ser lista infinita. Feito na 0.9.0 (sugestões na resposta ao usuário e na seção 9).
16. (27/10, noite) Ajustes do Windows depois de restaurar (renomear, domínio, licença); cópia das imagens para HD
    externo ou outro servidor com conferência bit a bit e opção de importar; visual das estações bonito, moderno e
    com a identidade da LabFree (empresa do usuário, labfree.com.br: marinho #0d1b3e, azul #1a73e8, ciano da nuvem
    #0ca8e8, fonte Jost, logo nuvem + LABFREE em itálico). Feito na 0.10.0.
17. (27/10, noite) Painel também com a identidade da LabFree, com logomarca e favicon — feito na 0.11.0.
18. (27/10, noite) **REGRA: o sistema tem de ser 100% autônomo** — o instalador já traz a base FOG e tudo de que
    ela precisa, sem baixar nada do projeto FOG (se os links do FOG sumirem, ninguém mais instalaria). Pedido também
    um NAT opcional (rede de clonagem com internet pelo Wi-Fi do servidor): avaliado como viável (aguardando a vez).
    Autonomia feita na 0.12.0 (`base-fog/` + `terceiros/` no repositório). A ideia de um **sistema próprio sem o FOG**
    (licença fechada, porte para Windows) ficou como ideia arquivada em `docs/PLANO-LABFREE-ENGINE.md`.
19. (28/10) NAT opcional feito; "teste intenso de todo o sistema, procurar bugs, falhas e otimizações" — feito na
    0.13.0 (seção 7i).
20. (28/10) "Varredura completa e plano de migração do sistema completo, com todas as funções atuais, escrito do zero"
    — feito na 0.13.2: `docs/PLANO-LABFREE-ENGINE.md` (inventário, matriz de paridade, arquitetura, etapas,
    migração, licenças, riscos). **Desenvolvimento só começa quando o usuário mandar**; linguagem (C# ou Go)
    ainda é decisão dele (recomendado C#).

## 2. A máquina do usuário (desenvolvimento = PRODUÇÃO; há um laboratório usando)
- Notebook Dell Inspiron 15 3520, Linux Mint 22.3 (base Ubuntu 24.04). Você roda como root.
- Rede de clonagem: adaptador USB RTL8153 `enx00e04c690b37` (MAC 00:e0:4c:69:0b:37), **192.168.31.1/24**, sem gateway/DNS.
  Conexão NM "Ligação com fios 1" (uuid fa1be90c-…; arquivo gerado pelo netplan). Internet pelo Wi-Fi `wlp2s0` — **nunca mexa nela**.
- Base FOG 1.5.10.2518 instalada de `/opt/fogproject-stable` (git do FOG; o pacote copia daqui sem `.git`).
- Antes de reiniciar Kea/rede/Apache/serviços, confira se há clonagem em andamento:
  `mysql fog -e "select taskID,taskStateID from tasks where taskStateID in (1,2,3); select msID,msState from multicastSessions where msState in (0,1,2,3)"`
- O sistema de permissões do Claude Code **bloqueia** (e só o usuário pode fazer/autorizar): gravar regras de sudo,
  trocar o IP real do servidor, cancelar sessões do laboratório, apagar com `rm` em variáveis sem `${VAR:?}`.
  Não contorne; peça ao usuário.

## 3. Repositório `/opt/LabFreeImageDeploy` (links antigos: `/opt/labfree-imagedeploy`)
| Pasta/arquivo | Conteúdo |
|---|---|
| `instalar.sh` | instalador (nova instalação e atualização/migração); `--ajuda` lista opções |
| `base-fog/` | a base FOG 1.5.10.2518 embutida (sem histórico/testes); único ajuste: `downloadfiles` usa os arquivos locais |
| `terceiros/` | o que a base baixaria da internet (FOS, agente Windows, iPXE binário e fonte) + `LEIAME.md` (origem/licenças) |
| `web/` | painel (index.html, app.css, app.js, api.php, fontes/Inter) |
| `lib/LabFreeImageDeploy-root` | ajudante root em Python (ações na seção 4) |
| `lib/LabFreeImageDeploy-copias` | cópia/importação de imagens para HD externo ou pasta de rede, conferida bit a bit (seção 7g) |
| `lib/LabFreeImageDeploy-otimizar` | recomprime (zstd 19 + janela longa) e confere as imagens com o servidor parado (seção 7f) |
| `lib/LabFreeImageDeploy-udp-sender` | "porteiro" do envio em grupo, no lugar do udp-sender da base (seção 7e) |
| `sbin/` | `LabFreeImageDeploy` (comando único) + `LabFreeImageDeploy-{verificar,backup,restaurar-backup,reaplicar,senha}` |
| `sistema/` | sudoers, dispatcher NM, timer de backup, regras apt, energia, `raiz-index.php` |
| `marca/` | `LabFreeImageDeploy-fundo.png` (+ `fundo-boot.html` que o gera); `fos/` (arquivos que entram no FOS: `bin/fog.man.reg` cadastro guiado, `bin/labfree-tela` tela da clonagem, `etc/init.d/S99fog`, `usr/share/fog/lib/labfree-tela.sh`); `servidor/` (`LabFreeImageDeploy-cadastro.php`, `LabFreeImageDeploy-etapa.php`) |
| `ferramentas/` | `empacotar.sh` (gera `dist/LabFreeImageDeploy-<versão>.tar.gz`), `publicar.sh` (envia versão ao GitHub), `LabFreeImageDeploy-recompilar-ipxe` |
| `CHANGELOG.md` | o que mudou em cada versão (obrigatório antes de publicar) |
| `testes/` | `painel_simulado.py`, `plano_fotos.py`, `fotos_painel.py` (painel com API simulada e fotos), `cdp_teste.py`, `boot_vm.sh`, testes de rollback e de certificado (não vão no pacote) |
| `docs/` | `INSTALACAO.md`, `USO.md`, `ARQUITETURA.md` (leia este para entender o funcionamento), `PROMPT-CONTINUAR.md` (texto para passar o projeto a outra IA), `CUSTOMIZACOES-MAQUINA-ATUAL.md` (histórico do dia 1, nomes antigos) |

## 4. Ajudante root (`LabFreeImageDeploy-root`) — ações
`status`, `dhcp-ler`, `dhcp-aplicar`, `rede-listar`, `rede-simular`, `rede-aplicar`, `reaplicar`, `imagem-apagar`,
`marca-aplicar`, `servicos-aplicar`. Uso: `echo '{json}' | sudo /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root AÇÃO`.
Padrão de toda ação que altera: validar → `Instantaneo` (cópia com dono/permissões) → aplicar → testar → desfazer se falhar.
`reaplicar` = tudo idempotente (NM ignore-carrier, drop-in Kea, udev, chrony, NTP no default.ipxe, DHCP de `dhcp.json`,
marca, serviços renomeados). Use depois de qualquer mexida da base FOG.

## 5. Lições aprendidas (não repita)
1. Kea 2.4 **não sobe** se a interface listada não existir → drop-in `ConditionPathExists` + dispatcher + retry de socket.
2. iPXE valida HTTPS pelo relógio da BIOS → `ntp <IP>` antes do `chain https://` + chrony servindo hora (`0216eb8f`).
3. **NetworkManager tira o IP quando o link cai ~6 s** (cliente PXE reiniciando a placa) → `udp-sender` morre
   ("Set multicast send interface: Cannot assign requested address"), cliente trava em "Starting to restore image (-)".
   Correção: `ignore-carrier=yes` para a placa de clonagem. Confirmado em produção.
4. `installfog.sh` regrava Kea e `default.ipxe` → rode `LabFreeImageDeploy reaplicar` depois.
5. AppArmor do Kea só lê `/etc/kea/**`: teste com `runuser -u _kea -- kea-dhcp4 -t /etc/kea/arquivo`.
6. Imagem "Laboratorio Fontes" é **tamanho fixo** de disco SATA 128 GB → não restaura em NVMe menor.
7. Ao reescrever arquivos de outros pacotes preserve dono/permissões (`config.class.php` = `www-data:www-data 0640`; errar derruba o site).
8. Mint 22 usa netplan + NM: `nmcli con mod` persiste no YAML do netplan.
9. PHP: `json_encode([])` = `[]`; mande `(object)$dados`. Teste sempre pelo caminho real (painel → sudo).
10. Telas de boot (iPXE e console do FOS) **não têm acentos**: textos de boot sempre em ASCII.
11. `stripAndDecode` do FOG decodifica base64 em **todos** os campos do cadastro → envie também o `imageid` em base64.
12. `getHardDisk` do FOS reinicia a máquina se não achar disco (via `handleError`) → o cadastro detecta o disco com `lsblk`.
13. Migração por `mv` para uma pasta só colide nomes iguais (`fogfacil` arquivo × pasta) → `arquivar()` guarda pelo caminho completo.
14. Instalador interrompido no meio pode deixar arquivos fora do lugar: sempre teste em VM antes da produção (seção 6).
16. O usuário viu "Painel FOG"/"painel avançado" porque a produção ainda roda o painel ANTIGO (`/var/www/html/painel`):
    o código novo só chega lá rodando o instalador. Antes de investigar bug de tela, confira qual versão está publicada.
15. Python 3.12 não aceita `\"` dentro de expressões de f-string; no bash prefira `%`-format.
17. **O painel roda no PHP-FPM**: o ajudante chamado por ele fica no cgroup do FPM. Reiniciar `php*-fpm` (ou o Apache) mata o
    ajudante no meio → `rede-aplicar` se re-executa via `systemd-run --wait --pipe` (unidade própria) e não reinicia o FPM;
    o Apache só recebe `reload` (graceful). Confirmado na VM: antes, a troca de IP pelo painel morria sem desfazer.
18. Pastas do FOG pertencem ao www-data (`/var/www/html/fog/service`, `lib/fog`): o root **nunca** grava seguindo link
    simbólico (`gravar_atomico` com `mkstemp`+`fchown`, `copiar_seguro`, `recusar_link`). Senão www-data → root.
19. O Kea limpa o arquivo de concessões de hora em hora (LFC): leia `kea-leases4.csv*` (todos), não só o `.csv`.
20. `Element.replaceChildren/append` escrevem `null` como texto; o `app.js` filtra null/false nesses métodos (topo do arquivo).
21. `validatePw`/`authenticate` do FOG aceitam qualquer senha se já houver usuário na sessão: o login do painel confere com
    `passwordValidate` e regenera a sessão antes de `attemptLogin`.
23. FOS: o partclone usa ncurses (`-N`): não redirecione a saída do `/bin/fog` para arquivo. A tela do LabFree roda o
    `/bin/fog` no terminal 2 dentro de `script` (pty) e desenha no terminal 1. O console é 80x25: desligue a quebra de
    linha (`ESC[?7l`) e corte os textos, senão uma linha longa rola a tela inteira.
24. O FOG guarda a velocidade (`taskBPM`) só como número; o partclone manda "674.08MB" ou "3.9GB" (por minuto). A
    unidade certa vem da tela da máquina (`labfreeEtapas.velocidade`).
25. Reconstruir o `init.xz` leva ~7 min (xz -9 de um só processo, 2 arquivos): faça todas as mudanças do FOS antes.
26. **Multicast da base**: máquina NÃO cadastrada que entra pelo menu "sessão de grupo" não gera tarefa (`falseTasking`):
    o FOG não sabe que ela existe. Quem sabe é o udp-sender (saída "New connection from IP"); o porteiro lê isso.
27. `FOG_UDPCAST_MAXWAIT` vale para 3 coisas: espera do 1º udp-sender, janela para entrar (`isJoinable`: até
    `msSenderStart` + maxwait) e `--start-timeout` do udp-receiver na máquina (`mcStartTimeout` no FOS). Com início
    manual precisa ser grande (1440); o porteiro limita as partições seguintes a 600 s.
28. A base cancela matando de baixo para cima (udp-sender → porteiro → `sh`): no meio-tempo o `sh` lançaria a partição
    seguinte. O porteiro encerra o `sh` da sessão quando o udp-sender morre por sinal.
29. Teste de udpcast em namespace: o udp-receiver manda o pedido de conexão para o **broadcast** da placa; sem
    `brd +` no `ip addr add` ele manda para 0.0.0.0 e nunca conecta. Duas máquinas no mesmo namespace disputam a porta.
30. A captura do FOS faz `zstdmt --ultra -$PIGZ_COMP` (nível = `imageCompress` da imagem) e a restauração
    `zstdmt -dc | partclone.restore --ignore_crc`: quem garante a integridade na restauração é a soma de verificação
    do zstd (erro = falha visível). O zstd do FOS é 1.5.7 e lê janela de até 128 MB (`--long=27`) sem opção extra.
31. Medido (1 GB da imagem real, i7-1255U 12 threads): nível 3 = 438 MB em 1,2 s; 6 = 425 MB/4,3 s; 12 = 413 MB/18 s;
    19 = 384 MB/66 s; 19 + `--long=27` = 366 MB/73 s. Descomprimir: < 1 s/GB em qualquer nível.
32. **Console do FOS** (corrige a lição 10 para o FOS; o iPXE continua só ASCII): é texto VGA 80x25 com a fonte cp437
    e `vt.default_utf8=1`. Funcionam: troca de paleta (`ESC ] P n RRGGBB`), molduras, blocos (█ ░ ▄ ▀), √ ► • ○ ✕ e os
    acentos á é í ó ú ç â ê ô à. NÃO existem ã õ Ú (viram a o U). Não há locale: o bash conta bytes, então textos
    variáveis passam por `ascii()` antes de cortar; textos fixos medem a largura com `larg()`.
33. Fotografar o FOS numa VM sem root: copiar o init descompactado, trocar arquivos com `debugfs -w` (write/rm/
    set_inode_field mode), recompactar com `xz -C crc32` (o kernel recusa outro check), QEMU com `-nic user`
    (o FOS para sem placa de rede) e `web=http://10.0.2.2:PORTA/fog/` respondendo HEAD/POST (o FOS confere o
    servidor antes de seguir); `screendump` pelo monitor. O menu do iPXE: `RAIZ_TFTP=pasta testes/boot_vm.sh` com um
    `default.ipxe` que encadeia um menu de teste por HTTP.
34. Comando grande que mistura editar arquivo + matar processo + baixar pode ser barrado pelo modo automático de
    permissões; em passos separados passa. `pkill -f`/`pgrep -f` com um padrão que aparece na própria linha de
    comando mata o próprio shell (saída 144): filtre com `ps -eo pid,args | grep "[x]yz"`.
35. Kernel "virtual" do Ubuntu Server não tem exFAT (nem sempre NTFS): `mount -t exfat` falha com "unknown filesystem
    type" → o copias tenta `exfat-fuse`/`ntfs-3g` (pacotes instalados pelo instalador).
36. Captura de Linux que não foi desligado direito falha no `e2fsck` do FOS (journal pendente): é o "hibernação do
    Windows" do Linux — mensagem traduzida no painel e na tela.
37. O verificador de segurança do Claude Code bloqueia comandos com `rm` em variável ou dentro de `bash -c`, e até a
    palavra RM numa coluna do `lsblk`: escreva o comando sem isso (ou em passos).
38. A base apaga `msName` quando a sessão em grupo termina (para o nome poder ser reutilizado): o histórico mostra "nº ID".
39. O `buildipxe.sh` da base faz `git fetch` do GitHub sem prazo antes de compilar, mesmo com o código já presente:
    o LabFree tira essa linha (instalação nova e recompilação).
40. Ao publicar, rode a edição da documentação ANTES do `publicar.sh` e confira que ela terminou (na 0.13.0 a edição
    parou no meio e o publicar seguiu).
22. Nunca escreva um heredoc de bash contendo outro `EOF` dentro (o bash fecha no primeiro e executa o resto): scripts de
    edição vão em arquivo (Write) e rodam com `python3 -I arquivo`.

## 6. Como testar
- `sudo LabFreeImageDeploy verificar` → deve terminar com "Tudo certo."
- **Painel sem tocar na produção** (recomendado): `python3 -I testes/painel_simulado.py 8099` serve o `web/` com a API
  simulada (cenários pelo cookie `cenario`: cheio, vazio, problema, senha, deslogado); `python3 -I testes/plano_fotos.py`
  gera `plano.json` (todas as telas, janelas e assistentes) e `python3 -I testes/fotos_painel.py SAIDA plano.json`
  fotografa em celular/tablet/computador, claro/escuro, e lista erros de JS, rolagem horizontal, elementos que vazam,
  textos cortados e alvos de toque pequenos. Ao mudar o formato de uma resposta da API, atualize o simulado.
- **Cópias de imagens sem root**: `python3 -I testes/teste_copias.py` (exportar, importar, HD estragado, cópia
  incompleta, cancelar) — deve terminar "Tudo certo."
- **Tela da máquina sem servidor**: `labfreedemo=…` na linha do kernel (lição 33).
- **Otimizador de imagens sem root**: `python3 -I testes/teste_otimizar.py` (imagens falsas + banco simulado: menor,
  bit a bit igual, pedaços, só conferir, corrompida, pausa no meio) — deve terminar "Tudo certo."
- **Envio em grupo real sem root**: `testes/teste_grupo.sh` (ponte + 2 receptores em namespaces; confere que não começa
  sozinho, começa com o pedido, partições seguintes, arquivos iguais e cancelamento limpo) — deve terminar "Tudo certo."
- **Painel** (Chrome headless): `testes/cdp_teste.py SID "rota1;;rota2|js@@js"`. Sessão de teste: crie como www-data em
  `/opt/fog/sessions` com `$_SESSION['FOG_USER']=1; $_SESSION['PAINEL_TOKEN']='tokteste'` e apague no fim.
  Variáveis `TELAS`, `TEMAS`, `MOCKS` (interceptar chamadas que dependem do sudo).
- **Boot PXE real em VM** (QEMU/KVM instalados): `RAIZ_TFTP=/tftpboot testes/boot_vm.sh bios|uefi PASTA "segundos"`.
  FOS direto: `qemu-system-x86_64 -kernel bzImage -initrd init.xz -append "... mode=sysinfo|manreg"`; teclas via monitor `sendkey`.
- **Instalação do zero SEM o GitHub/FOG (prova de autonomia)**: VM própria (overlay da imagem cloud do Ubuntu 24.04,
  cloud-init instala o NetworkManager e bloqueia github.com/fogproject.org em /etc/hosts), repositório copiado por
  `tar | ssh`, `sudo ./instalar.sh --sim --placa ens4`. Passos usados na 0.12.0 estão no CHANGELOG/seção 7h.
- **Instalação do zero em VM**: `/var/tmp/LabFreeImageDeploy-teste/` (imagem Ubuntu 24.04 cloud, `servidor.qcow2`,
  chave `id_teste`, SSH `-p 2222 teste@127.0.0.1`, painel em `https://127.0.0.1:8443/`). Placa de clonagem da VM `ens4`
  numa rede `-netdev socket,mcast=230.0.0.1:12345` — uma segunda VM cliente nessa rede faz PXE contra o servidor da VM.

## 7. Resultados de testes (26/10)
- DHCP pelo painel (mudanças reais, ligar/desligar, desfazer forçado) e rede (idempotente, falha forçada no meio de uma
  troca real de IP → tudo restaurado) — OK. Troca de IP real **até o fim** em produção: **não testada** (bloqueada).
- Marca: menu BIOS e UEFI, banner do FOS e iPXE com nome — testados em VM contra o servidor de produção — OK.
- Cadastro guiado em VM contra produção: 3 teclas, máquina cadastrada com a imagem certa — OK (máquina de teste apagada).
- **Instalação do zero numa VM Ubuntu 24.04 limpa** (pacote `dist/`, `--sim --placa ens4`): achou e corrigiu 6 bugs
  (permissão do ajudante, f-string, linha de install, consulta do PHP, senha do admin em instalação retomada, `strings`
  ausente). Resultado final: base FOG instalada sem perguntas, 7 serviços `LabFreeImageDeploy-*` rodando, login real
  `admin` OK, aba Configurações via sudo OK, `/fog/management` redireciona, "Tudo certo." — e **uma segunda VM cliente
  na rede isolada fez PXE contra o servidor da VM** (DHCP do Kea, iPXE com nome, menu LabFree). OK.
- **Migração em VM** reproduzindo o estado legado da produção (fogfacil + labfree + sudoers já movido) → tudo migrado,
  atalhos `fog-*`/`labfree-*` criados, backups renomeados, `/painel/` redireciona, "Tudo certo." OK.

## 7b. Varredura completa de 26/10 (noite) — o que mudou
Quatro revisões (API, frontend, ajudante root, instalador/scripts) + medições; cada achado foi conferido no código antes de corrigir.
- **Segurança**: escrita sem seguir symlink; trava `flock` (/run/LabFreeImageDeploy.lock) para ações que alteram; sudoers
  restrito às 7 ações do painel + o ajudante recusa as demais vindas do www-data; SQL do rollback escapado; login sem o
  desvio da troca obrigatória, com limite (10 erros/IP → 15 min), sessão regenerada, inatividade 4 h, trocar a senha derruba
  as outras sessões; senha/exclusão de OUTRO usuário pedem a senha de quem está logado; corpo > 1 MB recusado; erro interno sem detalhes.
- **Robustez**: rollback do NetworkManager completo (snapshot de todos os campos, apaga conexão criada, reativa as derrubadas);
  `dhcp-aplicar` desfaz habilitado/desabilitado e só reinicia o Kea se algo mudou; faixa DHCP padrão nunca inclui o servidor
  (`faixa_padrao`), `rede-simular` valida igual ao aplicar; histórico podado (60); logrotate; `marca_fos` não perde o original
  e não apaga dentro de imagem montada; backup atômico e conferido (`gzip -t`), inclui CA/certificado; restauração extrai
  antes do backup, não volta programas antigos, confere tarefas ativas; instalador retoma base que falhou (marcador
  `/var/lib/LabFreeImageDeploy/base-instalada`), mescla `.fogsettings`, não apaga repositório com `.git`, aceita pasta com espaço,
  falha certo com erro do ajudante; cadastro pelo boot confere a resposta do servidor (antes dizia "Pronto" mesmo falhando),
  "Voltar" volta ao nome, endpoint nunca responde vazio.
- **Painel**: duplo clique não pula a revisão; tela atrasada não sobrepõe a nova (recipiente por navegação + `geracao`);
  repetição sem pedidos sobrepostos; timeout em todo `fetch`; status "Sem comunicação"; sem redesenho quando nada mudou;
  modal com foco preso e sem fechar durante ação; seleção visível sem `:has()`; busca com debounce; "null" corrigido.
- **Medido na VM**: todas as chamadas do painel 13–20 ms (dhcp/rede ~60 ms, passam pelo sudo); `resumo` não monta mais o histórico.
- **Testado**: API (23 verificações), troca de IP real pelo painel ida/volta (~2 s), falha forçada no meio (NM, arquivos e
  banco idênticos depois), trava, allowlist, ataque de symlink (/etc/passwd intacto), Chrome em todas as telas (desktop e
  celular, sem erros, sem rolagem horizontal), cadastro pelo boot em VM cliente (nome, Voltar, já cadastrada, servidor fora).

## 7c. Versão 0.6.0 (27/10) — testes
- Captura e restauração reais em VM (cliente com BIOS, disco GPT com EFI + 600 MB de dados): tela nova com etapas,
  barra, registro e resultado; a máquina NÃO reiniciou; dados conferidos depois da restauração (arquivo íntegro,
  partição ampliada 2→2,9 GB); painel mostrou "Etapa 4 de 5", a fase e "Ver registro".
- Limpar histórico na VM (tarefas concluídas/falha/cancelada/fila/em andamento): só o pedido foi apagado, em andamento intacto.
- Pendências conhecidas: multicast de máquinas cadastradas sem login em cada uma (hoje a sessão por nome pede usuário e
  senha do painel na máquina); expirar tarefas esquecidas automaticamente (hoje só avisa); limpar `/images/dev`
  órfãos; testar Ubuntu 22.04/Debian 12 (Kea 2.0/2.2); fuso FOG_TZ_INFO (UTC) diferente do sistema.

## 7d. Versão 0.7.0 (27/10) — varredura do painel
- Estrutura do `app.js`: `el()` monta o DOM; cada tela é uma função `tela*` com `repetir()` (atualização ao vivo) e
  `geracao` (tela antiga não desenha sobre a nova); `chaveVivo` evita redesenhar sem mudança e `interagindo()` evita
  redesenhar no meio de um clique; `modal()`, `menuSuspenso`, `escolhas` (cartões de escolha) e `assistente` (passos).
  Novas na 0.7.0: `trilha()`/`etapaDe()` (5 etapas, estimadas pela fase quando a máquina não informa), `filtros()`
  (botões com contagem), `rotuloDia()`.
- 47 situações fotografadas (telas, janelas, assistentes, menus) × celular/tablet/computador × claro/escuro com a API
  simulada; verificação automática de layout sem achados no fim (só reticências esperadas em nomes de placa) e sem erros de JS.
- Os cartões (`.cartao`) são `container-type: inline-size`: regras `@container` ajustam histórico e tarefas pela
  largura do cartão. Navegadores sem container queries (anteriores a 2023) caem no `@media` antigo.

## 7e. Versão 0.8.0 (27/10) — envio em grupo controlado pelo painel
- **Porteiro** (`lib/LabFreeImageDeploy-udp-sender` → `/usr/local/lib/LabFreeImageDeploy/udp-sender`; `UDPSENDERPATH` no
  `config.class.php` aponta para ele; o nome precisa conter "udp-sender" porque a base confere o processo por isso).
  A base roda `sh -c "udp-sender ... --file p1; udp-sender ... --file p2; ..."`. Na 1ª partição o porteiro tira
  `--nokbd/--min-receivers/--max-wait` e dá ao udp-sender um pipe como "teclado"; quando o painel cria
  `/run/LabFreeImageDeploy/grupo/<porta>.iniciar` (e há ≥ 1 conectada) ele "aperta a tecla". Nas partições seguintes
  põe `--min-receivers` = quantas receberam a anterior e `--max-wait 600`. Mesma sessão = mesmo `sh` (PID + início).
  Andamento em `<porta>.json` (root:www-data 0640; pasta 1770 criada pelo tmpfiles). Se o porteiro falhar antes de
  começar, roda o udp-sender original (`LABFREE_PORTEIRO=0` também desliga).
- Painel: `api.php` `grupoAndamento()` (IP → MAC pelas concessões do Kea → nome do cadastro), `iniciarMulticast()`
  (cria o pedido e põe `msSenderStart` no passado para ninguém mais entrar). `app.js` `cartaoSessao()` (com porteiro) e
  `cartaoSessaoBase()` (sem ele), `iniciarSessao()`, `avisarSalaPronta()`.
- Menu de boot: `FOG_PXE_MENU_TIMEOUT` 0 = `choose --timeout 0` = espera sem limite (iPXE: 0 = indefinido).
- Testado: `testes/teste_grupo.sh` (udpcast real em rede virtual) e fotos do painel (Início, Tarefas, assistente,
  Configurações) em celular/tablet/computador, claro/escuro, sem achados. **Não testado ainda com máquinas reais**
  nem em VM com o FOS (próximo passo depois do instalador).
- Medido em 27/10 na produção: imagem "Laboratorio Fontes" 31 GB (já zstd); sessões de 21–24 máquinas levaram ~70 min
  (início automático com espera de até 60 min). **O adaptador USB da rede de clonagem estava numa porta USB 2.0
  (480 Mbit/s, ~35 MB/s)**: mudar para uma porta USB 3 é o maior ganho de velocidade (pedido ao usuário).

## 7f. Versão 0.9.0 (27/10) — imagens menores e conferidas
- `lib/LabFreeImageDeploy-otimizar` (timer a cada 20 min): para cada partição zstd de cada imagem (`d*p*.img[.NNN]`,
  `sys.img`, `rec.img`), se mudou desde a última vez: `cat pedaços | zstd -dc` → SHA-256 + `zstd -19 --long=27 -T0`
  → temporário `.labfree-otimizando-*` na mesma pasta → descomprime o novo e compara o SHA-256 → troca (mantém dono e
  permissões; refaz pedaços de 200 MB se era "em pedaços"). Só troca se ficar ≥ 1% menor. Confere "servidor parado"
  (nenhuma tarefa 1–3, nenhuma sessão 0–3) a cada 20 s e antes de trocar; ocupado → apaga o temporário, estado
  "pausada". Otimização desligada → só `zstd -t`. Reconfere a cada 30 dias. gzip antigo: só fica como está.
- Painel: `controlesCompressao()` (criar/editar), `linhaOtimizacao()` (cartão da imagem). Imagem nova: `compress` 3
  (Rápida) e formato zstd.
- Detectadas na rede: `Painel::recentes()` filtra concessões com `visto_ts` dos últimos 30 min.

## 7g. Versão 0.10.0 (27/10) — Windows, cópias e visual das estações
- Windows: renomear = `FOG_CHANGE_HOSTNAME_EARLY` (o FOS grava o nome no registro com `reged`, `changeHostname` do
  funcs.sh). Domínio/licença = agente FOG (`/fog/client/FOGService.msi`, módulo `hostnamechanger`, que lê
  `hosts.hostUseAD/ADDomain/ADOU/ADUser/ADPass/productKey`). `Painel::aplicarWindows()` grava isso em cada máquina
  (ao salvar a aba, ao cadastrar/editar e antes de restaurar); senha do domínio = `FOG_AD_DEFAULT_PASSWORD` (`#`×32
  no host = "usar a global"). Os outros módulos do agente (`FOG_CLIENT_*_ENABLED`) ficam desligados.
  **Não testado com Windows/AD reais** (o código usa os caminhos da base).
- Cópias: `lib/LabFreeImageDeploy-copias` (root, via ajudante `copias`): `ler`, `rede` (testa e guarda em
  `/etc/LabFreeImageDeploy/copias.json`, 600), `listar`, `exportar`/`importar` (unidade `LabFreeImageDeploy-copia-*`,
  trava `/run/LabFreeImageDeploy-copia.lock`, andamento `/run/LabFreeImageDeploy/copia.json`), `cancelar`. No destino:
  `LabFreeImageDeploy-imagens/<pasta>/` + `labfree-imagem.json` (dados + SHA-256). O painel cadastra a importada
  (`cadastrarImportadas`, uma vez por trabalho: `LABFREE_IMPORTACAO_FEITA`). Montagem: disco já montado pelo desktop
  é usado onde está; senão `/run/LabFreeImageDeploy/destino` (ntfs3 → ntfs-3g). **Não testado com HD/NAS reais**
  (teste com pastas).
- Visual: `marca/fundo-boot.html` → PNG (Chrome headless, comando no próprio arquivo); `CORES_IPXE`; `labfree-tela`
  redesenhada (`topo`, `trilha`, `cartao`, `barra`, `msg`, `tela_final`, `demo`); `fog.man.reg` e `BANNER_FOS` usam
  `labfree-cores.sh`. Fotografados em VM: menu de boot, tela (preparando, gravando, grupo, sucesso, erro) e cadastro.

## 7h. Versão 0.12.0 (27/10) — autonomia comprovada
- Instalação do zero numa VM Ubuntu 24.04 (cloud image + cloud-init com NetworkManager) com github.com,
  *.githubusercontent.com e fogproject.org bloqueados em /etc/hosts: "Testing internet connection… Failed" e mesmo
  assim tudo instalado: kernel/init/agente vindos de `terceiros/` (SHA-256 iguais aos do pacote), iPXE "Skipped
  (pre-placed sources)" e compilado localmente, iPXE com a marca recompilado, `verificar` = "Tudo certo.".
- Única dependência externa que sobra: os pacotes do Ubuntu/Debian (apt). Um "pacote offline" com os .deb é possível.

## 7i. Versão 0.13.0 (28/10) — NAT e teste intenso
- **Três revisões de código** (API/painel, programas root, instalador/scripts/FOS) por agentes; todos os achados
  confirmados foram corrigidos (lista no CHANGELOG). Ficaram como decisão/observação: `--force-confold` do apt vale
  para todos os pacotes (escolha deliberada: o apt nunca troca configuração alterada; revisar se der problema com
  pacotes de segurança); `LabFreeImageDeploy-etapa.php` confia no MAC (mesmo nível do `progress.php` da base);
  troca de imagens "em pedaços" pelo otimizador não é atômica entre pedaços (reconfere antes; imagens atuais são de
  arquivo único).
- **Teste de ponta a ponta em VM** (servidor Ubuntu 24.04 com o sistema instalado + VMs clientes na rede de clonagem
  por socket multicast, boot pela rede de verdade): atualização com backup antes; troca obrigatória da senha (428);
  cadastro; captura (falha legítima por e2fsck e depois sucesso, tela nova, sem reiniciar); otimizador na imagem real
  (−26%, conteúdo idêntico, data mantida); restauração da imagem otimizada num disco vazio → Ubuntu sobe; sessão em
  grupo com 2 máquinas sem cadastro → painel lista as duas, não começa sozinha, Iniciar → 4 partes → as duas sobem;
  cópias para "pendrive" USB exFAT (achou o defeito do módulo exFAT) e importação → cadastrada, arquivos iguais;
  Windows (domínio/chave nas máquinas, senha conferida, desligar limpa); NAT (cliente sem internet → com internet →
  sem de novo; com ufw ativo; persiste após reiniciar); menu de boot sem tempo; login do grupo em português.
- 123 fotos do painel sem achados; `teste_grupo.sh`, `teste_otimizar.py`, `teste_copias.py` = "Tudo certo."
- Como repetir: VM servidor em `scratchpad/vm` (cloud image + cloud-init, `ssh -p 2223`), `testes/api_cli.py` dentro
  dela (`entrar`, `get`, `post`), clientes com `-boot n` na mesma rede `-netdev socket,mcast=230.0.0.1:12346`, teclas
  e fotos pelo monitor do QEMU (`sendkey`, `screendump`).
- **Produção**: 0.13.x ainda não instalada (versão instalada em 28/10: 0.12.0).

## 8. Situação da máquina de produção (IMPORTANTE)
- 26/10 21:43: o usuário rodou o instalador (versão anterior à varredura). Migração concluída: nomes antigos arquivados,
  sudoers `LabFreeImageDeploy`, serviços `LabFreeImageDeploy-*` ativos, painel em `/var/www/html/LabFreeImageDeploy`,
  `LabFreeImageDeploy verificar` = "Tudo certo.". Usuário do painel: `admin` (uId 3).
- 26/10 22:26: depois da varredura, foram copiados para a produção **só** o painel (`web/`) e o ajudante
  (`/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root`), sem reiniciar nada (correção do "null" em Máquinas pedida
  pelo usuário). Backup do estado anterior: `/var/backups/LabFreeImageDeploy/manual-20261026-revisao/`.
- 27/10 00:45: idem para a 0.6.0 (só `web/` e o ajudante; backup em `/var/backups/LabFreeImageDeploy/manual-20261027-v060/`).
  A tela nova na máquina (FOS) e a tabela `labfreeEtapas` só chegam à produção com o instalador (reconstrói o FOS, ~7 min,
  sem clonagem em andamento).
- **Falta (ação do usuário, porque grava o sudoers)**: `cd /opt/LabFreeImageDeploy && sudo ./instalar.sh` para levar o resto da
  varredura: sudoers restrito, cadastro pelo boot novo (reconstrói o FOS), `LabFreeImageDeploy-cadastro.php`, scripts de
  backup/restauração/verificação, logrotate, marcador `base-instalada`. Depois: `sudo LabFreeImageDeploy verificar`.
- 27/10: 0.7.0 aplicada **só** nos arquivos visuais do painel (`app.js`, `app.css`, `index.html` em
  `/var/www/html/LabFreeImageDeploy/`, dono e permissões preservados), sem reiniciar nada e sem clonagem em andamento.
  `api.php` e o ajudante não mudaram. Backup: `/var/backups/LabFreeImageDeploy/manual-20261027-v070/`. Tela de login
  conferida no Chrome sem erros. Enquanto o instalador não rodar, a trilha de etapas do painel é **estimada pela fase**
  (a máquina ainda usa a tela antiga do FOS e não envia `labfreeEtapas`).

- 27/10 18:47: o usuário rodou o instalador com a **0.7.1** (antes de a 0.8.0 entrar no repositório): chegaram à
  produção a regra de sudo restrita, a tela nova na máquina (FOS), a unidade de recuperação e o resto da varredura de
  26/10 (o item "Falta" acima está feito). `/etc/LabFreeImageDeploy/versao` = 0.7.1.
- 27/10 ~18:49: o usuário rodou o instalador com a **0.8.0**. Conferido: `versao` 0.8.0, porteiro em
  `/usr/local/lib/LabFreeImageDeploy/udp-sender`, `/run/LabFreeImageDeploy/grupo` (1770 root:www-data),
  `LabFreeImageDeploy-multicast` reiniciado com ele, `boot.php` com `choose ... --timeout 0`, painel `?v=0.8.0`, FOS com
  a `labfree-tela` atual. **Falta**: teste real de sessão em grupo com 2–3 máquinas (seção 9, item 1) e mudar o
  adaptador USB para uma porta USB 3 (continua em 480 Mbit/s).
- 0.10.0 publicada (inclui a 0.9.0); **falta rodar o instalador** (também regrava o sudoers com a ação `copias`
  e reconstrói o FOS com a tela nova).
- 0.9.0: **falta rodar o instalador** para levar o otimizador, a compressão por imagem e a correção das
  Detectadas. A imagem "Laboratorio Fontes" (31 GB, nível 6) deve ficar ~14% menor na primeira noite parada (~1,5 h).

## 9. Próximos passos sugeridos
1. Depois que o usuário rodar o instalador em produção: conferir `LabFreeImageDeploy verificar`, testar o painel, um
   cadastro real e **uma sessão em grupo pequena (2–3 máquinas)**: lista de conectadas, aviso de sala completa,
   Iniciar, progresso, e o resumo em `/var/log/LabFreeImageDeploy/grupo.log`.
1b. Feito na 0.9.0 (opção D). Próximas ideias de desempenho e funções (sugeridas ao usuário em 27/10): porta USB 3 /
   placa 2,5 GbE; kernel e FOS por HTTP na rede isolada; limitar taxa do multicast se o `grupo.log` mostrar muitas
   retransmissões; restauração unicast em paralelo com limite configurável; converter imagens gzip antigas para zstd;
   pós-restauração (renomear Windows/ingressar no domínio); agendamento por horário; relatório por sala; backup das
   imagens para HD externo; "sala" (grupo de máquinas) no painel; Wake-on-LAN da sala inteira para multicast.
2. Traduzir as telas restantes do FOS (menus de inventário/sysinfo, mensagens do Partclone) — hoje em inglês.
3. Confirmação com tempo na troca de IP (desfaz se o usuário não confirmar em 2 min).
4. CI no GitHub Actions com `bash -n`, `shellcheck`, `php -l`, `py_compile` (o `publicar.sh` já faz essas conferências localmente).
5. Registro ao vivo no painel: hoje o painel mostra fase, etapa, porcentagem e velocidade ao vivo, mas as linhas do
   registro só chegam no fim (`LabFreeImageDeploy-etapa.php` recebe o registro com o resultado). Oferecido ao usuário.
6. Pendências da 0.6.0 (seção 7c): multicast de máquinas cadastradas sem login em cada uma; progresso de cada máquina
   sem cadastro no grupo (hoje o painel mostra o progresso da transmissão; por máquina exige a tela do FOS mandar o
   andamento por MAC); expirar tarefas esquecidas;
   limpar `/images/dev` órfãos; testar Ubuntu 22.04/Debian 12; fuso `FOG_TZ_INFO` diferente do sistema.
7. Para passar o projeto a outra IA, use o texto de `docs/PROMPT-CONTINUAR.md`.

## 10. Objetivo comercial (pedido do usuário: vender para escolas e empresas)
- A base é GPL v3: pode vender, mas quem compra recebe o código-fonte e o direito de redistribuir; não dá para impor
  "licença por máquina" sobre o código. Modelos compatíveis: venda da instalação/configuração, suporte e atualizações
  por assinatura, servidor pronto (appliance), treinamento. A **marca** "LabFree ImageDeploy" pode ser registrada (INPI)
  — é ela, e não o código, que protege o negócio. Manter os créditos do FOG (exigência da licença).
- Ideias de produto: página "Sobre/Suporte" com contato do revendedor configurável, relatório de uso para o cliente,
  instalador em ISO pronta, tradução das telas restantes do FOS.
