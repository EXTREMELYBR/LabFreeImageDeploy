# Plano de migração: LabFree Engine — servidor próprio, escrito do zero, com o FOS personalizado nas máquinas

> **Situação (28/10/2026):** plano completo, feito a pedido do usuário a partir de uma varredura de todo o sistema
> atual (versão 0.13.x). **O desenvolvimento só começa quando o usuário mandar.** Até lá a versão atual (camada sobre
> o FOG 1.5.10) continua sendo a única em produção e recebendo correções.
>
> **Decisões do usuário:**
> - (28/10) O sistema das máquinas continua sendo o **FOS personalizado** — **de forma permanente**, sem plano de
>   trocá-lo. Ele precisa poder ser **atualizado quando sair versão nova, sem quebrar o sistema** (seção 6).
> - Pendente antes de começar: a linguagem do servidor (seção 4).

## 1. Objetivo e regras
- Um produto da LabFree que faz **tudo** o que o LabFree ImageDeploy faz hoje (matriz de paridade, seção 3), com a
  mesma aparência e os mesmos textos.
- Duas partes, com regras diferentes:
  - **Servidor (Engine), painel e agente Windows**: escritos **do zero**, código **fechado (proprietário)** da LabFree,
    **sem nenhum código do FOG**. Quem escreve não copia nem traduz código do FOG; o comportamento vem desta
    especificação e de testes de caixa-preta ("sala limpa").
  - **Sistema das máquinas (FOS personalizado)**: é o FOS do projeto FOG (GPL), usado como **programa separado**, com
    as personalizações do LabFree por cima. As mudanças nele são GPL e o código delas é publicado junto do pacote.
    O Engine só conversa com ele pela rede (HTTP, NFS, multicast) e por parâmetros de boot — por isso o servidor
    continua fechado.
- **Autônomo**: instala e funciona sem internet e sem depender de nenhum projeto externo existir.
- Servidor em **Linux** primeiro e depois **Windows**, com o mesmo código.
- **Compatível com as imagens já capturadas** (é o mesmo FOS que grava e lê).
- Continuam valendo as regras de hoje: nada começa sozinho na máquina (só tarefas agendadas no painel), conferência
  de dados, documentação e CHANGELOG em toda mudança, release a cada versão, teste em VM antes do laboratório.

## 2. Como o sistema atual está montado (resultado da varredura)
| Camada | Hoje | Linhas próprias |
|---|---|---|
| Painel (SPA) | `web/index.html`, `app.js`, `app.css` | ~2.800 |
| API do painel | `web/api.php` (≈40 rotas) usando classes do FOG e o banco `fog` | ~2.200 |
| Ajudante root | `lib/LabFreeImageDeploy-root` (Python, 15 ações via sudo) | ~1.840 |
| Envio em grupo | FOGMulticastManager + `udp-sender` + porteiro (`lib/LabFreeImageDeploy-udp-sender`) | ~430 |
| Otimizar / cópias | `lib/LabFreeImageDeploy-otimizar`, `lib/LabFreeImageDeploy-copias` | ~1.090 |
| Comandos | `sbin/` (verificar, backup, restaurar, reaplicar, senha), recompilar iPXE | ~560 |
| Instalador | `instalar.sh` + `installfog.sh` da base | ~550 (+ base) |
| Máquinas | FOS (Buildroot da FOG) + `labfree-tela`, `fog.man.reg`, cores, banner | ~700 (+ FOS) |
| Do FOG, usado em tempo de execução | BootMenu/`boot.php`, ~20 endpoints `service/*.php` chamados pelo FOS, serviços FOG* (multicast, agendador…), Kea, tftpd-hpa, NFS, vsftpd, Apache/PHP, MariaDB, agente Windows `fog-client` | — |

**O que o FOG faz por nós e o Engine passa a fazer** (o FOS fica):
1. Boot: DHCP, TFTP, menu iPXE gerado, login e escolha de sessão no boot, **parâmetros de boot que o FOS espera**.
2. Banco e regras de tarefas (estados, fila, agendador, histórico, registro de clonagens).
3. **Os endpoints que o FOS chama** (check-in, progresso, conclusão, cadastro, erros…) — seção 6.2.
4. Armazenamento das imagens (NFS para o FOS, conclusão da captura).
5. Agente Windows (nome, domínio, licença).

## 3. Matriz de paridade (tudo que precisa existir no Engine)
Cada linha vira um item de teste na etapa correspondente (seção 8). "Fora" = não existe no produto atual e não entra.

### 3.1 Painel e acesso
- Login usuário/senha; bloqueio por IP (10 erros → 15 min); log de falhas; sessão com token anti-CSRF em todo POST.
- Inatividade de 4 h (atualizações automáticas não contam); trocar a senha derruba as outras sessões do usuário.
- `admin/admin` (ou senha fraca de fábrica) **obriga a trocar** antes de qualquer coisa (hoje HTTP 428).
- Regras de senha: ≥ 8 caracteres, lista de senhas fracas, diferente do usuário.
- Reconfirmação com a senha de quem está no painel: criar/excluir usuário, senha de outro, limpar histórico,
  Windows, NAT, pasta de rede.
- Usuários: criar, trocar senha (própria e de outro), excluir (nunca a si mesmo nem o último). Sem papéis.
- Comando de terminal para redefinir a senha (`LabFreeImageDeploy senha admin --redefinir`).
- Telas: Início (estado do servidor e problemas, tarefas esquecidas, máquinas novas, atalhos, números, atividade),
  Capturar, Restaurar, Grupo, Máquinas, Imagens, Tarefas, Configurações (DHCP, Placa de rede + NAT, Cadastro pelo
  boot, Windows, Cópias), Usuários, Ajuda — **o `web/` atual é reaproveitado** (seção 5.1).
- Tema claro/escuro, celular, identidade LabFree, avisos "sala pronta" e "sem comunicação".

### 3.2 Máquinas
- Cadastrar (nome ≤ 15, MAC único), editar (MAC fixo), excluir (não com tarefa ativa); imagem padrão; descrição;
  chave do Windows própria.
- "Detectadas na rede" (só últimos 30 min), com cadastrar já preenchido; busca e filtros; última clonagem.
- **Cadastro pelo boot** em 2 passos (nome sugerido pelo padrão `LAB-***` + próximo número; imagem; restaurar na
  hora com login) e **cadastro automático** sem perguntas (com opção de já restaurar).

### 3.3 Imagens
- Criar (nome ≤ 40, pasta ASCII única, sufixo `-2` se a pasta existir), editar, proteger, excluir (opção de apagar os
  arquivos; recusa se protegida ou em uso).
- 4 tipos: **Redimensionável** (1 disco), **Partições de tamanho fixo**, **Todos os discos**, **Setor a setor**.
- Compressão por imagem: rápida 3 / normal 6 / menor 12 (zstd).
- Informações: tamanho, data, disco de origem (NVMe/SATA/eMMC), **tamanho mínimo de disco**, máquinas usando.
- **Otimizar no servidor** (zstd 19 + janela longa, SHA-256 do conteúdo igual antes de trocar, ganho ≥ 1 %, só com o
  servidor parado, pausa sozinho) e **conferência periódica** (30 dias) com aviso de imagem corrompida.

### 3.4 Capturar e restaurar (uma máquina ou várias)
- Assistentes do painel; Wake-on-LAN (portas 9 e 7); "desligar ao terminar"; reserva de espaço
  (≥ max(20 GB, 1,2 × imagem)); recusa se a imagem estiver ocupada/protegida.
- Na máquina (FOS personalizado): trilha de 5 etapas, porcentagem, velocidade, tempo restante, parte N de M,
  registro ao vivo, mensagens de erro em português; **fim sem reiniciar sozinho** (R/D/L/T); com "desligar ao
  terminar", desliga em 60 s.
- No painel: cartão ao vivo por tarefa, "sem notícia há X", tarefa esquecida (> 24 h), cancelar, motivo da falha,
  "Ver registro" (mensagem da máquina + erros do servidor + log completo).
- Renomear o Windows offline (FOS, com `hostearly=1`).

### 3.5 Restaurar em grupo (multicast)
- Sessão com nome (2–20, único, ≠ nome de máquina) e quantidade (1–200).
- Máquinas entram pelo menu de boot (login do painel + nome da sessão), **inclusive sem cadastro**.
- Painel lista as conectadas (nome ou "sem cadastro", IP, MAC), avisa **sala completa** e **espera um humano**:
  "Iniciar transmissão"/"Iniciar agora" (as que faltam não entram mais).
- Andamento: GB enviados, MB/s, tempo restante, parte X de Y, máquinas retiradas; partes seguintes esperam só as que
  receberam a anterior (máx. 10 min); cancelar sem lançar a parte seguinte.
- Registro resumido por parte e detalhado por sessão (podado: 120 dias / 200 arquivos).

### 3.6 Tarefas e histórico
- Estados: fila, aguardando a máquina, em andamento, concluída, falha, cancelada.
- **Tarefa agendada começa sozinha** quando a máquina liga (decisão do usuário); fora isso nada começa sozinho e o
  menu de boot **espera sem limite** (tempo configurável 0/10/30/60 s).
- Histórico de tarefas e sessões juntos, por dia, filtros; limpeza seletiva (fila, histórico, clonagens, registros)
  com senha.

### 3.7 Rede e boot
- Escolha da placa de clonagem (bloqueia Wi-Fi e a placa da internet), IP e máscara (/16 a /29), "ver o que vai
  mudar", aplicar com **desfazer automático** se algo falhar e recuperação após queda de energia.
- Nunca mexer na conexão de internet nem na rota padrão do servidor.
- **DHCP**: faixa, gateway, DNS, domínio, concessão, reservas; liga/desliga; instruções para usar outro DHCP
  (opções 66/67); **modo proxy** (novo, para conviver com o DHCP da escola).
- Arquivos de boot por classe (BIOS `undionly.kkpxe`, UEFI `snponly.efi`, 32 bits).
- Relógio para o iPXE (hoje chrony + `ntp` antes do HTTPS).
- Adaptador USB: sem economia de energia; aguentar a placa sem cabo/queda de link (hoje `ignore-carrier`).
- **NAT opcional** (internet para a rede de clonagem), desligado por padrão, com DNS, compatível com firewall,
  restaurado no boot, desfaz exatamente.
- Menu de boot: fundo LabFree, cores, textos em português ASCII, itens: disco local, memtest, cadastrar, cadastro
  automático, restaurar uma imagem (com login), entrar em sessão de grupo, informações do sistema, depuração, menu
  avançado. Cabeçalho âmbar (sem cadastro) / verde (cadastrada).
- iPXE com o nome do produto e a CA do servidor embutida; textos do login em português.

### 3.8 Windows depois de restaurar
- Renomear (offline, pelo FOS, antes do primeiro boot do Windows).
- Entrar no domínio (domínio, OU, usuário, senha guardada) e ativar a licença (chave por máquina ou padrão, 25
  caracteres) — **agente próprio** instalado uma vez na máquina de referência; nunca reinicia/desliga/desconecta o
  usuário por conta própria.
- Instalador do agente servido pelo próprio servidor.

### 3.9 Cópias, backup e manutenção
- Cópias de imagens para HD USB (exFAT, NTFS, ext*, xfs, btrfs, FAT32 com aviso de 4 GB) ou pasta de rede SMB;
  manifesto com SHA-256 por arquivo; cópia conferida lendo de volta; troca atômica; importação conferida, com nome
  novo se já existir; cancelar; montagens seguras (somente leitura, sem executar).
- Backup semanal e antes de atualizar (banco + configurações, 15 guardados), restauração por partes
  (painel/configurações/banco).
- `verificar` (autoteste e autocorreção silenciosa), `reaplicar`, `versao`, `otimizar`.
- Notebook como servidor: não suspender com a tampa fechada.
- Atualização do sistema operacional não quebra o produto (hoje: backup antes do apt + verificar depois).

### 3.10 Fora do escopo (o FOG tem, o produto atual não usa)
Snapins, replicação entre servidores, impressoras, rastreamento de usuários, GreenFOG, localizações, grupos do FOG,
antivírus, wipe/teste de disco/fotorec, redefinir senha do Windows, ARM, Secure Boot (melhoria futura, seção 11).
Os modos do FOS que não aparecem no menu continuam existindo no FOS, mas o Engine não os oferece.

## 4. Decisão de linguagem do servidor (pendente — o usuário decide)
Com o FOS mantido, a linguagem vale só para o **servidor** e o **agente Windows**.

| | **C# (.NET 10 LTS)** | **Go** |
|---|---|---|
| Servidor no Windows (serviço, MSI, firewall, AD) | melhor | bom, mais manual |
| Servidor no Linux | bom | bom |
| Painel/API | ASP.NET Core (Kestrel) | net/http |
| DHCP/TFTP/NFS embutidos | bom | um pouco mais simples |
| Agente Windows | melhor | bom |
| Proteção do código fechado | exige NativeAOT/ofuscação | naturalmente mais difícil de descompilar |
| Contratar programadores no Brasil | mais fácil | mais difícil |

**Recomendação: C#** (servidor e agente na mesma linguagem, melhor no Windows).

## 5. Arquitetura
```
labfree-engine  (um serviço; Linux: systemd; Windows: serviço do Windows)        ← código fechado, do zero
├── web/          painel atual (HTML/JS/CSS) servido pelo Engine, HTTPS com certificado próprio
├── api/          mesma API JSON de hoje (rotas, campos e códigos), sessões, CSRF, bloqueio de login
├── loja/         SQLite (WAL): máquinas, imagens, tarefas, sessões de grupo, usuários, configurações, histórico
├── tarefas/      fila, estados, agendador, "sem notícia", esquecidas
├── boot/         DHCP (completo e proxy), TFTP, HTTP de boot, menu iPXE, login/sessão no boot, parâmetros do FOS
├── fos/          "contrato do FOS": os endpoints que ele chama (seção 6.2) e a conclusão de captura/restauração
├── armazenamento/ /images exportado ao FOS (Linux: NFS do sistema; Windows: NFS embutido — seção 7)
├── imagens/      otimizador, conferência, informações de disco
├── grupo/        controle do udp-sender (porteiro), andamento por máquina
├── copias/       exportar/importar HD e SMB
├── rede/         placa, IP, NAT, desfazer (implementação por sistema operacional)
└── manutencao/   backup/restauração, verificar, atualização, versões do FOS
FOS personalizado (GPL, programa separado)                                         ← mantido, atualizável
  kernel + init do projeto FOG  +  camada LabFree (tela, cadastro, cores, banner, ajustes de erro)
labfree-agente (Windows)                                                            ← código fechado, do zero
```

### 5.1 Decisões de arquitetura
- **Painel reaproveitado e API compatível**: o Engine responde `api?a=<rota>` com os mesmos campos de hoje. O
  `app.js` muda pouco. `testes/fotos_painel.py` e `testes/api_cli.py` provam a paridade nos dois sistemas.
- **Sem Apache, PHP, MariaDB, Kea, tftpd-hpa e vsftpd**: tudo dentro do Engine. Fica só o servidor NFS do sistema
  (Linux), porque é assim que o FOS lê e grava as imagens.
- **Envio em grupo**: udp-sender (udpcast, GPL, programa separado; tem versão Windows) controlado pelo Engine com a
  lógica do porteiro. O FOS já traz o udp-receiver.
- **Ajudante root deixa de existir**: o Engine roda como serviço com as permissões necessárias; as ações perigosas
  (rede, NAT, montagens) ficam isoladas num módulo pequeno, com validação e desfazer — mesmas regras de hoje.
- **Formato de imagem**: o do FOS (não muda). O Engine grava também um `labfree-imagem.json` com SHA-256 (o mesmo
  manifesto das cópias) depois de cada captura.
- **Banco**: SQLite com migrações versionadas; backup = cópia consistente do banco + configurações.

## 6. FOS personalizado e atualizável (sem quebrar o sistema)
### 6.1 Como as personalizações ficam separadas
O FOS que vem do projeto FOG **nunca é editado no lugar**. Ele fica guardado intacto e a camada LabFree é aplicada
por cima na instalação (como o `marca_fos` já faz hoje):

| Regra | Como |
|---|---|
| Original intacto | `terceiros/fos/<versão>/` com `bzImage`, `bzImage32`, `init.xz`, `init_32.xz` e `.sha256`, e o código-fonte em `terceiros/fos-fonte/<versão>/` |
| Camada LabFree em **arquivos próprios** | `labfree-tela`, `fog.man.reg`, `labfree-cores.sh`, `labfree-tela.sh`, `S99fog`, banner, `/etc/issue` |
| Mudar comportamento **sem editar** os scripts do FOS | `labfree-tela.sh` é carregado no fim do `funcs.sh` e **redefine** as funções (`handleError`, `handleWarning`, `displayBanner`…). Uma única linha é acrescentada ao `funcs.sh` |
| Cada ponto de encaixe tem uma **impressão digital** | Lista do que a camada espera do FOS: funções que redefine, arquivos que substitui, mensagens que a tela traduz, formato do `/tmp/status.fog`, parâmetros de boot, endpoints chamados. Conferida automaticamente contra cada FOS novo |
| Reconstrução repetível | Mesmo processo para qualquer versão: abrir `init.xz`, aplicar a camada, conferir, fechar (`xz --check=crc32`) |

### 6.2 O contrato do FOS (o que o Engine precisa responder)
O Engine implementa, do zero e a partir do comportamento observado, o que o FOS usa hoje:

| Uso | Endpoints / mecanismos |
|---|---|
| Parâmetros de boot | `mac, ftp, storage, storageip, osid, web, img, imgType (n/mps/mpa/dd), imgPartitionType, imgid, imgFormat, PIGZ_COMP, hostname`; opcionais `hostearly, shutdown, mc/port/mcastMaxWait/mcastrdv, pct, ignorepg, isdebug, fdrive, keymap` |
| Versão e capacidades | `getversion.php` (incl. `?caps=1`) |
| Início da tarefa | `Pre_Stage1.php` (uma máquina), `mc_checkin.php` (grupo) |
| Andamento e erros | `progress.php`, `taskerror.php`, `blame.php` |
| Conclusão | `Post_Stage2.php` (captura: o Engine move `dev/<mac>` para a pasta da imagem localmente — sem FTP), `Post_Stage3.php` (restauração) |
| Cadastro | `man.hostexists.php`, `hostnameloop.php`, `checkcredentials.php`, `auto.register.php` (campos em base64), `inventory.php` |
| Outros chamados pelo FOS | `locationcheck.php`, `oucheck.php`, `secureboot.report.php`, `hostinfo.php` |
| Da camada LabFree | `LabFreeImageDeploy-cadastro.php` (sugestão de nome, lista de imagens), `LabFreeImageDeploy-etapa.php` (etapas, resultado, registro) |
| Armazenamento | NFS `/images` (leitura) e `/images/dev` (gravação da captura) |
| Grupo | udp-sender ↔ udp-receiver na porta da sessão |

O Engine responde **nos mesmos caminhos** (`/fog/service/...`) para o FOS funcionar sem alteração; o painel e o
resto do produto não mostram esses nomes.

### 6.3 Como atualizar o FOS quando sair versão nova
1. Baixar a versão nova do FOS (kernel + init) e **o código-fonte da mesma versão** (repositório do FOS na tag,
   Buildroot, kernel e pacotes do Buildroot — como em `terceiros/fos-fonte/`), com SHA-256. A versão em uso continua
   no pacote.
2. `labfree fos conferir <versão>`: aplica a camada LabFree numa cópia e confere a impressão digital (6.1). Se algo
   que a camada usa mudou, mostra exatamente o quê e **não deixa usar** até a camada ser ajustada.
3. **Kit de compatibilidade** (automático, em VMs como os testes de hoje): com a versão nova, contra o Engine —
   cadastro em 2 passos e automático, captura e restauração dos 4 tipos de imagem (Windows e Linux, MBR e GPT,
   disco maior e menor), restaurar uma imagem capturada com a versão anterior, grupo com 2 máquinas (com e sem
   cadastro), erro de disco menor, tela final, renomear Windows. Tudo precisa passar.
4. Publicar uma versão do LabFree que traz o FOS novo como **padrão** (CHANGELOG + release), mantendo o anterior no
   pacote.
5. No servidor: o instalador/atualizador usa o padrão novo; o Engine guarda as duas versões e o painel/terminal tem
   **"voltar para a versão anterior do FOS"** (troca imediata, sem reinstalar). Nenhuma clonagem em andamento é
   afetada: a troca só vale para os próximos boots e é recusada com clonagem em andamento.
6. O Engine sabe qual FOS cada máquina usou (registrado na tarefa) para diagnosticar problemas novos.

Também vale para o **kernel**: o FOS traz o kernel com os drivers; atualizar o FOS é o caminho para suportar máquinas
novas (placas de rede, NVMe, Wi-Fi não), sem mexer no Engine.

## 7. Mapa de substituição (componente por componente)
| Hoje | Engine | Observações |
|---|---|---|
| Kea DHCP | DHCP embutido (completo + proxy), reservas, concessões, opção 66/67 por classe | Detectar outro DHCP na rede e avisar |
| tftpd-hpa | TFTP embutido (só leitura, blksize/tsize) | |
| `default.ipxe` + `boot.php` + `BootMenu` | Script iPXE gerado pelo Engine (menu, login, sessão, tarefa da máquina, parâmetros do FOS) | Textos ASCII, cores, fundo |
| chrony (relógio do iPXE) | NTP/SNTP mínimo embutido **ou** 1º salto do iPXE por HTTP | Decidir na etapa 2 |
| iPXE da FOG | iPXE compilado do fonte (`terceiros/ipxe/`) com nosso nome e a CA do servidor | Patches de português continuam |
| Apache + PHP + `api.php` | Servidor HTTPS do Engine + API | |
| MariaDB `fog` | SQLite | Migração na etapa 9 |
| `service/*.php` | Contrato do FOS (6.2) | Mesmos caminhos |
| NFS + vsftpd | Linux: NFS do sistema (sem vsftpd; conclusão local). Windows: servidor NFSv3 embutido no Engine | Windows 10/11 não tem servidor NFS |
| FOGMulticastManager + porteiro | Módulo `grupo/` + udp-sender | |
| FOGScheduler | Agendador interno | |
| FOGImageReplicator, SnapinReplicator, PingHosts, ImageSize, SnapinHash | Não existem (fora do escopo) ou viram parte do Engine (tamanho das imagens) | |
| FOS + camada LabFree | **Mantido** (seção 6) | Atualizável |
| fog-client (`FOGService.msi`) | `labfree-agente` | Renomear/domínio/licença com comandos do Windows |
| `LabFreeImageDeploy-root` | Módulos `rede/` e `copias/` | |
| `LabFreeImageDeploy-otimizar`, `-copias` | Módulos do Engine (mesmo algoritmo) | Testes atuais viram especificação |
| systemd units, sudoers, apt hooks, tmpfiles | 1 serviço + timers internos | |
| `instalar.sh` + `installfog.sh` | Instalador próprio (.deb / script offline; .msi no Windows) | |

## 8. Etapas
Cada etapa termina com: testes automáticos, teste em VM **sem internet**, fotos do painel sem achados, documentação,
CHANGELOG e release. Estimativas para 1 desenvolvedor dedicado; somam **~4 a 6 meses** (o FOS mantido elimina a
parte mais arriscada).

| # | Etapa | Entrega | Semanas |
|---|---|---|---|
| 0 | **Preparação** | Linguagem decidida; repositório novo (`labfree-engine`); CI; especificação da API do painel e do **contrato do FOS** (gravando as chamadas reais do FOS ao servidor atual numa VM); código dos pacotes do Buildroot no pacote | 1–2 |
| 1 | **Fundação** | Serviço, SQLite, usuários e todas as regras de acesso (3.1), painel atual servido, `verificar`, logs, backup | 3–4 |
| 2 | **Boot** | DHCP completo e proxy, TFTP, iPXE compilado, menu gerado com fundo/cores/textos, relógio, login e sessão no boot | 3–4 |
| 3 | **FOS personalizado no Engine** | Camada LabFree separada (6.1), impressão digital, `fos conferir`, versões lado a lado e voltar atrás; cadastro em 2 passos e automático | 2–3 |
| 4 | **Capturar e restaurar** | Contrato do FOS (6.2), NFS, conclusão da captura, tarefas, etapas e registro, tela final, renomear Windows; kit de compatibilidade (6.3) | 3–4 |
| 5 | **Grupo** | udp-sender controlado, porteiro, lista de conectadas, sala completa, iniciar, partes seguintes, cancelar, registros | 2–3 |
| 6 | **Imagens** | Compressão, otimizador, conferência, informações de disco, cópias HD/SMB e importação | 2 |
| 7 | **Windows** | `labfree-agente` (domínio, licença), instalador servido pelo Engine | 1–2 |
| 8 | **Configurações e rede** | Placa/IP com desfazer e recuperação, DHCP no painel, NAT, cadastro pelo boot, Wake-on-LAN | 2–3 |
| 9 | **Migração** | Importar de uma instalação atual (seção 9) | 1–2 |
| 10 | **Instalador e atualização** | Instalador offline (.deb + script), atualização com backup antes e volta atrás | 1–2 |
| 11 | **Piloto** | Laboratório real em paralelo com o sistema atual | 2–4 |
| 12 | **Servidor no Windows** | NFS embutido, rede/NAT/DHCP no Windows (netsh/PowerShell/NetNat), firewall, serviço, .msi; testes em Windows 10/11 e Server | 4–6 |

## 9. Migração das instalações atuais
1. **Importador** (`labfree-engine importar`): lê o banco `fog` e `/images` de um servidor LabFree atual e traz
   máquinas (nome, MAC, imagem padrão, descrição, chave), imagens (nome, pasta, tipo, sistema, compressão,
   proteção), usuários (o hash bcrypt é reaproveitado), configurações (cadastro pelo boot, Windows, DHCP, reservas,
   NAT, placa/IP) e histórico. As pastas de imagem **não são copiadas nem alteradas**.
2. **Ensaio**: importar uma cópia (backup do servidor) numa VM e comparar o painel dos dois sistemas.
3. **Troca**: com o laboratório parado, backup completo, desligar os serviços do sistema atual (sem desinstalar),
   ligar o Engine com a mesma placa e o mesmo IP. **Volta atrás** = desligar o Engine e religar os serviços antigos.
4. Desinstalar a versão antiga só depois de um período de piloto sem problemas.

## 10. Licenças e componentes de terceiros
- **Fechado (LabFree)**: Engine, painel, agente Windows. Sem código do FOG.
- **GPL, programas separados, código-fonte junto do pacote** (`terceiros/`, com oferta escrita):
  - FOS (kernel Linux + Buildroot do projeto FOG) e **a camada LabFree aplicada nele** (`labfree-tela`,
    `fog.man.reg`, `labfree-*.sh`, `S99fog` — esses arquivos passam a ser publicados como GPL);
  - iPXE (GPL v2/UBDL) com os patches de português; udpcast (udp-sender no servidor).
- **Código-fonte do FOS no pacote** (feito na 0.13.5, `terceiros/fos-fonte/`): scripts e configurações do FOS na
  mesma versão dos binários, Buildroot e kernel. **Falta** guardar também o código dos pacotes que o Buildroot
  compila dentro do `init.xz` (busybox, partclone, util-linux…) — obtido com `build.sh --fs-download-only` numa
  máquina de desenvolvimento (`terceiros/fos-fonte/LEIAME.md`). Toda atualização do FOS (seção 6.3) troca binários e
  código-fonte juntos.
- Os avisos de licença e autoria do FOS continuam nos arquivos dele e na tela "Sobre"; as telas do produto são
  LabFree.
- Revisão com advogado antes de vender (fronteira "programa separado", avisos e oferta de código-fonte).
- Marca "LabFree ImageDeploy" registrada (INPI).

## 11. Melhorias que o Engine permite (opcionais, depois da paridade)
- DHCP em modo proxy (convive com o DHCP da escola sem configurar nada).
- Endpoints do FOS autenticados por tarefa (hoje a máquina se identifica só pelo MAC).
- Secure Boot (shim assinado + kernel assinado).
- Atualização do servidor e do FOS pelo painel; licenciamento/ativação do produto.

## 12. Riscos e como reduzir
| Risco | Como reduzir |
|---|---|
| Contrato do FOS reproduzido errado (resposta diferente do que o FOS espera) | Gravar as chamadas reais do FOS ao servidor atual (etapa 0) e repetir no Engine; kit de compatibilidade em VM |
| FOS novo muda algo que a camada LabFree usa | Impressão digital + `fos conferir` + kit de compatibilidade antes de virar padrão; versão anterior sempre disponível |
| Projeto FOG parar de lançar FOS | O FOS atual e seu código-fonte ficam no pacote; o Buildroot pode ser recompilado por nós (kernel novo, drivers) |
| DHCP proxy em redes de escola | Testar com roteadores comuns; manter o modo completo |
| Servidor Windows (NFS, multicast, firewall) | Etapa separada (12), depois da paridade no Linux |
| Dois sistemas para manter | Versão atual só recebe correções durante o desenvolvimento |

## 13. O que já existe e é reaproveitado
Painel completo (`web/`), textos e assistentes, lógica do porteiro, algoritmos e testes do otimizador e das cópias,
**camada LabFree do FOS inteira** (`marca/fos/`, banner, cores), identidade visual (`marca/`), patches de português
do iPXE, testes de VM, de fotos e de API (`testes/`), e os componentes de terceiros em `terceiros/`.
