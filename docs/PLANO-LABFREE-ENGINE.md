# Plano de migração: LabFree Engine — sistema próprio, escrito do zero, sem o FOG

> **Situação (28/10/2026):** plano completo, feito a pedido do usuário a partir de uma varredura de todo o sistema
> atual (versão 0.13.1). **O desenvolvimento só começa quando o usuário mandar.** Até lá a versão atual (camada sobre
> o FOG 1.5.10) continua sendo a única em produção e recebendo correções.
>
> **Decisão pendente do usuário antes de começar:** a linguagem (seção 4).

## 1. Objetivo e regras
- Um produto da LabFree, **escrito do zero, sem nenhum código do FOG**, que faz **tudo** o que o LabFree ImageDeploy
  faz hoje (matriz de paridade, seção 3), com a mesma aparência e os mesmos textos.
- Licença **fechada (proprietária)** da LabFree. Programas livres de terceiros só como **programas separados**
  (seção 9).
- **Autônomo**: instala e funciona sem internet e sem depender de nenhum projeto externo existir.
- Servidor em **Linux** primeiro e depois **Windows**, com o mesmo código.
- **Compatível com as imagens já capturadas** (mesmo formato de arquivos), para a troca não exigir capturar tudo de
  novo.
- **Regra de "sala limpa"**: quem escreve o Engine não copia nem traduz código do FOG/FOS. O comportamento é
  reproduzido a partir desta especificação, da documentação pública dos formatos e de testes de caixa-preta. Os
  textos, telas, painel e scripts do LabFree são nossos e podem ser reaproveitados.
- Continuam valendo as regras de hoje: nada começa sozinho na máquina (só tarefas agendadas no painel), conferência
  de dados por SHA-256, documentação e CHANGELOG em toda mudança, teste em VM antes do laboratório.

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
| Do FOG, usado em tempo de execução | BootMenu/`boot.php`, ~20 endpoints `service/*.php`, scripts do FOS (`fog.upload`, `fog.download`, `funcs.sh`), serviços FOG* (multicast, agendador…), Kea, tftpd-hpa, NFS, vsftpd, Apache/PHP, MariaDB, agente Windows `fog-client` | — |

**O que o FOG faz por nós e terá de ser escrito do zero** (é o grosso do trabalho):
1. Boot: DHCP, TFTP, menu iPXE gerado, login e escolha de sessão no boot.
2. Banco e regras de tarefas (estados, fila, agendador, histórico, registro de clonagens).
3. **Sistema das máquinas (FOS)**: detecção de disco, captura e restauração por tipo de imagem, **encolher e
   esticar partições** (NTFS, ext, btrfs, xfs, f2fs, LVM), MBR/GPT, renomear Windows offline, cadastro.
4. Transporte das imagens (hoje NFS + FTP) e check-in/conclusão das tarefas.
5. Agente Windows (nome, domínio, licença).

## 3. Matriz de paridade (tudo que precisa existir no Engine)
Cada linha vira um item de teste na etapa correspondente (seção 7). "Fora" = não existe no produto atual e não entra.

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
- Leitura das imagens antigas: zstd (formatos 5/6, em pedaços de 200 MB), gzip e sem compressão.

### 3.4 Capturar e restaurar (uma máquina ou várias)
- Assistentes do painel; Wake-on-LAN (portas 9 e 7); "desligar ao terminar"; reserva de espaço
  (≥ max(20 GB, 1,2 × imagem)); recusa se a imagem estiver ocupada/protegida.
- Na máquina: trilha de 5 etapas, porcentagem, velocidade, tempo restante, parte N de M, registro ao vivo, mensagens
  de erro em português (disco menor, sem disco/AHCI, e2fsck, hibernação, BitLocker, checksum, espaço, rede…).
- **Fim sem reiniciar sozinho**: R reinicia, D desliga, L registro, T detalhes; teclas apertadas durante a clonagem
  descartadas; com "desligar ao terminar", desliga em 60 s (qualquer tecla cancela).
- No painel: cartão ao vivo por tarefa, "sem notícia há X", tarefa esquecida (> 24 h), cancelar, motivo da falha,
  "Ver registro" (mensagem da máquina + erros do servidor + log completo).
- Restauração **estica as partições** para o disco de destino (redimensionável) e recusa disco menor que o mínimo.
- Windows: renomear offline com o nome cadastrado.

### 3.5 Restaurar em grupo (multicast)
- Sessão com nome (2–20, único, ≠ nome de máquina) e quantidade (1–200).
- Máquinas entram pelo menu de boot (login do painel + nome da sessão), **inclusive sem cadastro**.
- Painel lista as conectadas (nome ou "sem cadastro", IP, MAC), avisa **sala completa** e **espera um humano**:
  "Iniciar transmissão"/"Iniciar agora" (as que faltam não entram mais).
- Andamento: GB enviados, MB/s, tempo restante, parte X de Y, máquinas retiradas; partes seguintes esperam só as que
  receberam a anterior (máx. 10 min); cancelar sem lançar a parte seguinte.
- Registro resumido por parte e detalhado por sessão (podado: 120 dias / 200 arquivos).

### 3.6 Tarefas e histórico
- Estados: agendada/fila, aguardando a máquina, em andamento, concluída, falha, cancelada.
- **Tarefa agendada começa sozinha** quando a máquina liga (decisão do usuário); fora isso nada começa sozinho e o
  menu de boot **espera sem limite** (tempo configurável 0/10/30/60 s).
- Histórico de tarefas e sessões juntos, por dia, filtros; limpeza seletiva (fila, histórico, clonagens, registros)
  com senha.

### 3.7 Rede e boot
- Escolha da placa de clonagem (bloqueia Wi-Fi e a placa da internet), IP e máscara (/16 a /29), "ver o que vai
  mudar", aplicar com **desfazer automático** se algo falhar e recuperação após queda de energia.
- Nunca mexer na conexão de internet nem na rota padrão do servidor.
- **DHCP próprio**: faixa, gateway, DNS, domínio, concessão, reservas; liga/desliga; instruções para usar outro DHCP
  (opções 66/67); **modo proxy** (novo, recomendado para conviver com o DHCP da escola).
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
- Renomear (offline, na máquina, antes do primeiro boot do Windows).
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
antivírus, wipe/teste de disco/fotorec, redefinir senha do Windows, ARM, Secure Boot (hoje desligado — fica como
melhoria futura, seção 10), inventário de hardware detalhado (só o que o cadastro usa).

## 4. Decisão de linguagem (pendente — o usuário decide)
| | **C# (.NET 10 LTS)** | **Go** |
|---|---|---|
| Servidor no Windows (serviço, MSI, firewall, AD) | melhor | bom, mais manual |
| Servidor no Linux | bom | bom |
| Painel/API | ASP.NET Core (Kestrel) | net/http |
| DHCP/TFTP/multicast | bom | um pouco mais simples |
| Cliente nas máquinas (Linux mínimo) | NativeAOT (~15–25 MB) | binário estático (~8–12 MB), mais simples |
| Agente Windows | melhor | bom |
| Proteção do código fechado | exige NativeAOT/ofuscação | naturalmente mais difícil de descompilar |
| Contratar programadores no Brasil | mais fácil | mais difícil |

**Recomendação:** **C# em todo o servidor e no agente Windows**, com o cliente das máquinas em **C# NativeAOT**; se o
NativeAOT der problema no Linux mínimo (tamanho, musl), o cliente passa para **Go** sem afetar o resto (ele só fala
HTTP com o servidor). O resto do plano vale para as duas linguagens.

## 5. Arquitetura do Engine
```
labfree-engine  (um serviço; Linux: systemd; Windows: serviço do Windows)
├── web/          painel atual (HTML/JS/CSS) servido pelo próprio Engine, HTTPS com certificado próprio
├── api/          mesma API JSON de hoje (rotas, campos e códigos), sessões, CSRF, bloqueio de login
├── loja/         SQLite (WAL): máquinas, imagens, tarefas, sessões de grupo, usuários, configurações, histórico
├── tarefas/      fila, estados, agendador, "sem notícia", esquecidas
├── boot/         DHCP (completo e proxy), TFTP, HTTP de boot, gerador do menu iPXE, login/sessão no boot
├── imagens/      transferência por HTTP (envio e recebimento), otimizador, conferência, informações de disco
├── grupo/        controle do udpcast (porteiro), andamento por máquina
├── copias/       exportar/importar HD e SMB
├── rede/         placa, IP, NAT, desfazer (implementação por sistema operacional)
└── manutencao/   backup/restauração, verificar, atualização
labfree-os  (Buildroot próprio): kernel + initramfs + labfree-cliente + partclone, zstd, udp-receiver, sfdisk/sgdisk,
            ntfs-3g/ntfsresize, e2fsprogs, btrfs-progs, xfsprogs, f2fs-tools, lvm2, hivex (registro do Windows)
labfree-agente (Windows): serviço pequeno — domínio, licença, confirma o nome
```

### 5.1 Decisões de arquitetura
- **Painel reaproveitado e API compatível**: o Engine responde `api?a=<rota>` com os mesmos campos de hoje. O
  `app.js` muda pouco (remover menções ao FOG, rotas novas). Os testes de fotos (`testes/fotos_painel.py`) e o
  `testes/api_cli.py` servem para provar paridade nos dois sistemas.
- **Sem Apache, PHP, MariaDB, Kea, tftpd-hpa, NFS, vsftpd**: tudo dentro do Engine. Menos peças para quebrar e
  instalador muito mais simples.
- **Transporte por HTTP(S)** no lugar de NFS+FTP: captura envia cada partição por streaming (PUT em pedaços com
  SHA-256 por pedaço e retomada), restauração baixa com `Range` (retoma se cair). A pasta da captura só vira a
  imagem depois de tudo conferido (troca atômica, como o otimizador faz hoje).
- **Envio em grupo**: udpcast (programa separado, GPL, com versão Windows) controlado pelo Engine com a lógica do
  porteiro (início manual, sala completa, partes seguintes só com quem recebeu). Protocolo multicast próprio fica
  como melhoria futura.
- **Ajudante root deixa de existir**: o Engine roda como serviço com as permissões necessárias; as ações perigosas
  (rede, NAT, montagens) ficam isoladas num módulo pequeno, com validação e desfazer — mesmas regras de hoje
  (gravação atômica, nunca seguir links, instantâneo antes de mudar).
- **Cliente nas máquinas** (`labfree-cliente`): um programa só no lugar dos scripts bash do FOS + `labfree-tela`.
  Desenha as mesmas telas (paleta cp437/VGA, 80×25), fala com a API (check-in, etapas, conclusão, registro) e chama
  partclone/zstd/udp-receiver/sfdisk como programas externos.
- **Formato de imagem igual ao de hoje** (`d1.mbr`, `d1.partitions` em formato sfdisk, `d1.minimum.partitions`,
  `d1.fixed_size_partitions`, `d1.original.fstypes`, `d1.original.swapuuids`, `dNpM.img[.NNN]` partclone+zstd,
  `dN.size`, `.ebr`, LVM). O Engine grava também um `labfree-imagem.json` (o mesmo manifesto das cópias) com
  SHA-256, para conferir tudo por padrão.
- **Banco**: SQLite com migrações versionadas; backup = cópia consistente do banco + configurações.

## 6. Mapa de substituição (componente por componente)
| Hoje | Engine | Observações |
|---|---|---|
| Kea DHCP | DHCP embutido (completo + proxy), reservas, concessões, opção 66/67 por classe (BIOS/UEFI/32) | Detectar outro DHCP na rede e avisar |
| tftpd-hpa | TFTP embutido (só leitura, blksize/tsize) | |
| `default.ipxe` + `boot.php` + `BootMenu` | Script iPXE gerado pelo Engine (menu, login, sessão, tarefa da máquina) | Textos ASCII, cores, fundo |
| chrony (relógio do iPXE) | NTP/SNTP mínimo embutido **ou** iPXE com HTTP sem conferir data no 1º salto | Decidir na etapa 2 |
| iPXE da FOG | iPXE compilado do fonte (`terceiros/ipxe/`) com nossa configuração e nosso nome | Patches de português continuam |
| Apache + PHP + `api.php` | Servidor HTTPS do Engine + API | |
| MariaDB `fog` | SQLite | Migração na etapa 9 |
| `service/*.php` (check-in, progresso, conclusão, registro, erro, inventário, cadastro, credenciais) | API do cliente (`/cliente/...`) autenticada por token da tarefa | Hoje confia só no MAC: melhorar |
| NFS + vsftpd | HTTP(S) com streaming e retomada | |
| FOGMulticastManager + porteiro | Módulo `grupo/` + udpcast | |
| FOGScheduler | Agendador interno | |
| FOGImageReplicator, SnapinReplicator, PingHosts, ImageSize, SnapinHash | Não existem (fora do escopo) ou viram parte do Engine (tamanho das imagens) | |
| FOS + `fog.upload`/`fog.download`/`funcs.sh` | LabFree OS + `labfree-cliente` | Maior risco (seção 11) |
| `fog.man.reg` + `labfree-tela` | Telas no `labfree-cliente` | Mesmo visual |
| fog-client (`FOGService.msi`) | `labfree-agente` | |
| `LabFreeImageDeploy-root` | Módulo `rede/` + `copias/` | |
| `LabFreeImageDeploy-otimizar`, `-copias` | Módulos do Engine (mesmo algoritmo) | Testes atuais viram especificação |
| systemd units, sudoers, apt hooks, tmpfiles | 1 serviço + timers internos | |
| `instalar.sh` + `installfog.sh` | Instalador próprio (.deb / script offline; .msi no Windows) | |

## 7. Etapas
Cada etapa termina com: testes automáticos, teste em VM **sem internet**, fotos do painel sem achados, documentação
e CHANGELOG. Estimativas para 1 desenvolvedor dedicado; somam ~6 a 9 meses.

| # | Etapa | Entrega | Semanas |
|---|---|---|---|
| 0 | **Preparação** | Decisão da linguagem; repositório novo (`labfree-engine`); CI; especificação da API extraída do painel atual; banco de testes com imagens reais de cada tipo | 1–2 |
| 1 | **Fundação** | Serviço, SQLite, usuários e todas as regras de acesso (3.1), painel atual servido, `verificar`, logs, backup | 3–4 |
| 2 | **Boot** | DHCP completo e proxy, TFTP, iPXE compilado, menu gerado com fundo/cores/textos, relógio, login e sessão no boot | 3–4 |
| 3 | **LabFree OS** | Buildroot próprio (kernel com drivers equivalentes ao FOS atual: NVMe, SATA/AHCI, RAID Intel, eMMC, placas de rede), `labfree-cliente` com as telas, cadastro em 2 passos e automático | 4–6 |
| 4 | **Capturar e restaurar** | Tipos fixo, todos os discos e setor a setor primeiro; depois **redimensionável** (encolher/esticar NTFS, ext, btrfs, xfs, f2fs, LVM; MBR e GPT); renomear Windows offline; mensagens de erro; tela final; transporte HTTP com retomada | 6–8 |
| 5 | **Grupo** | udpcast controlado, porteiro, lista de conectadas, sala completa, iniciar, partes seguintes, cancelar, registros | 2–3 |
| 6 | **Imagens** | Compressão, otimizador, conferência, informações de disco, cópias HD/SMB e importação | 2–3 |
| 7 | **Windows** | `labfree-agente` (domínio, licença), instalador servido pelo Engine | 2–3 |
| 8 | **Configurações e rede** | Placa/IP com desfazer e recuperação, DHCP no painel, NAT, cadastro pelo boot, Wake-on-LAN | 2–3 |
| 9 | **Migração** | Importar de uma instalação atual (seção 8) | 1–2 |
| 10 | **Instalador e atualização** | Instalador offline (.deb + script), atualização com backup antes e volta atrás | 1–2 |
| 11 | **Piloto** | Laboratório real em paralelo com o sistema atual (seção 8) | 2–4 |
| 12 | **Servidor no Windows** | Rede/NAT/DHCP no Windows (netsh/PowerShell/NetNat), firewall, serviço, .msi; testes em Windows 10/11 e Server | 4–6 |

## 8. Migração das instalações atuais
1. **Importador** (`labfree-engine importar`): lê o banco `fog` e `/images` de um servidor LabFree atual e traz
   máquinas (nome, MAC, imagem padrão, descrição, chave), imagens (nome, pasta, tipo, sistema, compressão,
   proteção), usuários (o hash bcrypt é reaproveitado), configurações (cadastro pelo boot, Windows, DHCP, reservas,
   NAT, placa/IP) e histórico. As pastas de imagem **não são copiadas nem alteradas**; o Engine passa a usá-las e
   gera os manifestos com SHA-256 na primeira conferência.
2. **Ensaio**: importar uma cópia (backup do servidor) numa VM e comparar o painel dos dois sistemas.
3. **Troca**: com o laboratório parado, backup completo, desligar os serviços do sistema atual (sem desinstalar),
   ligar o Engine com a mesma placa e o mesmo IP. **Volta atrás** = desligar o Engine e religar os serviços antigos.
4. Desinstalar a versão antiga só depois de um período de piloto sem problemas.

## 9. Licenças e componentes de terceiros
- Código do Engine, do cliente e do agente: da LabFree, sem código do FOG (regra de sala limpa, seção 1).
- Usados como **programas separados**, sem modificação proprietária, com código-fonte e oferta escrita junto
  (pasta `terceiros/`): kernel Linux, Buildroot e pacotes (GPL), partclone (GPL), udpcast (GPL), iPXE (GPL v2/UBDL),
  ntfs-3g (GPL), e2fsprogs/btrfs-progs/xfsprogs/f2fs-tools/lvm2 (GPL/LGPL), hivex (LGPL). zstd (BSD) pode ser
  embutido.
- Revisão com advogado antes de vender (fronteira "programa separado" e oferta de código-fonte).
- Marca "LabFree ImageDeploy" registrada (INPI).

## 10. Melhorias que o Engine permite (opcionais, depois da paridade)
- DHCP em modo proxy (convive com o DHCP da escola sem configurar nada).
- Retomar uma restauração interrompida no meio; limite de banda por tarefa.
- Cliente autenticado por token da tarefa (hoje o FOS se identifica só pelo MAC).
- Secure Boot (shim assinado + kernel assinado).
- Protocolo multicast próprio com correção de erros.
- Atualização do servidor pelo painel; licenciamento/ativação do produto.

## 11. Riscos e como reduzir
| Risco | Como reduzir |
|---|---|
| **Encolher/esticar partições** (o ponto mais difícil e de onde vem a maioria dos erros de clonagem) | Especificar caso a caso com testes em VM (Windows 10/11 MBR e GPT, Ubuntu ext4, LVM, btrfs, disco maior e menor); começar pelos tipos fixos |
| Drivers no LabFree OS (máquinas variadas) | Partir da mesma configuração de kernel do FOS atual (configuração é dado público); sala de teste com os modelos reais |
| DHCP proxy em redes de escola | Testar com roteadores comuns; manter o modo completo |
| Compatibilidade com imagens antigas | Biblioteca de imagens reais de cada tipo, restauradas pelos dois sistemas e comparadas byte a byte |
| Servidor Windows (multicast, firewall, placa) | Etapa separada (12), depois da paridade no Linux |
| Tempo / dois sistemas para manter | Versão atual só recebe correções durante o desenvolvimento |

## 12. O que já existe e é reaproveitado
Painel completo (`web/`), textos e assistentes, lógica do porteiro, algoritmos e testes do otimizador e das cópias,
telas da máquina (`labfree-tela`, cores), identidade visual (`marca/`), patches de português do iPXE, testes de VM,
de fotos e de API (`testes/`), e os componentes de terceiros em `terceiros/` (fonte do iPXE).
