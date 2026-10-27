# LabFree ImageDeploy — guia para quem continuar (IA ou pessoa). LEIA INTEIRO ANTES DE MEXER.

> Atualizado em 27/10/2026 (versão 0.8.0). Versão no arquivo `VERSION`; histórico em `CHANGELOG.md`.
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
| `web/` | painel (index.html, app.css, app.js, api.php, fontes/Inter) |
| `lib/LabFreeImageDeploy-root` | ajudante root em Python (ações na seção 4) |
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
22. Nunca escreva um heredoc de bash contendo outro `EOF` dentro (o bash fecha no primeiro e executa o resto): scripts de
    edição vão em arquivo (Write) e rodam com `python3 -I arquivo`.

## 6. Como testar
- `sudo LabFreeImageDeploy verificar` → deve terminar com "Tudo certo."
- **Painel sem tocar na produção** (recomendado): `python3 -I testes/painel_simulado.py 8099` serve o `web/` com a API
  simulada (cenários pelo cookie `cenario`: cheio, vazio, problema, senha, deslogado); `python3 -I testes/plano_fotos.py`
  gera `plano.json` (todas as telas, janelas e assistentes) e `python3 -I testes/fotos_painel.py SAIDA plano.json`
  fotografa em celular/tablet/computador, claro/escuro, e lista erros de JS, rolagem horizontal, elementos que vazam,
  textos cortados e alvos de toque pequenos. Ao mudar o formato de uma resposta da API, atualize o simulado.
- **Envio em grupo real sem root**: `testes/teste_grupo.sh` (ponte + 2 receptores em namespaces; confere que não começa
  sozinho, começa com o pedido, partições seguintes, arquivos iguais e cancelamento limpo) — deve terminar "Tudo certo."
- **Painel** (Chrome headless): `testes/cdp_teste.py SID "rota1;;rota2|js@@js"`. Sessão de teste: crie como www-data em
  `/opt/fog/sessions` com `$_SESSION['FOG_USER']=1; $_SESSION['PAINEL_TOKEN']='tokteste'` e apague no fim.
  Variáveis `TELAS`, `TEMAS`, `MOCKS` (interceptar chamadas que dependem do sudo).
- **Boot PXE real em VM** (QEMU/KVM instalados): `RAIZ_TFTP=/tftpboot testes/boot_vm.sh bios|uefi PASTA "segundos"`.
  FOS direto: `qemu-system-x86_64 -kernel bzImage -initrd init.xz -append "... mode=sysinfo|manreg"`; teclas via monitor `sendkey`.
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
- 0.8.0 publicada no repositório; **para chegar à produção falta rodar o instalador de novo** (porteiro,
  `config.class.php`, tmpfiles, menu de boot sem tempo e o painel novo).

## 9. Próximos passos sugeridos
1. Depois que o usuário rodar o instalador em produção: conferir `LabFreeImageDeploy verificar`, testar o painel, um
   cadastro real e **uma sessão em grupo pequena (2–3 máquinas)**: lista de conectadas, aviso de sala completa,
   Iniciar, progresso, e o resumo em `/var/log/LabFreeImageDeploy/grupo.log`.
1b. **Desempenho (pedido do usuário, opção D)**: escolher o nível de compressão no painel (capturar rápido) e o servidor
   recomprimir a imagem mais forte depois, quando ocioso (zstd; trocar os arquivos só com a imagem livre). Medir antes
   e depois com o `grupo.log`.
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
