# Histórico de versões — LabFree ImageDeploy

Toda alteração entra aqui **antes** de ser publicada (`ferramentas/publicar.sh` recusa publicar sem isso).
Formato: a versão mais nova no topo; cada item diz o que mudou para quem usa e, quando importa, para quem mantém.
Numeração: `MAIOR.MENOR.CORREÇÃO` — CORREÇÃO para correções, MENOR para funções novas, MAIOR para mudanças que exigem
ação de quem já tem o sistema instalado.

## [0.12.0] - 2026-10-27

### Novo
- **Sistema 100% autônomo**: o repositório e o pacote trazem a base (FOG 1.5.10.2518, em `base-fog/`) e tudo o que
  ela baixaria da internet durante a instalação (`terceiros/`: sistema de clonagem com kernel e init, agente para
  Windows, binários do iPXE e o código-fonte do iPXE), cada arquivo conferido por SHA-256. A instalação **não acessa
  o GitHub nem o site do FOG**: se esses links deixarem de existir, o LabFree ImageDeploy continua instalável.
  Testado com uma instalação do zero numa VM Ubuntu 24.04 com o GitHub e o fogproject.org bloqueados.
- `docs/PLANO-LABFREE-ENGINE.md`: plano do sistema próprio (sem o FOG), em Go, licença fechada, com todas as funções
  atuais e porte para Windows (guardado para quando for a hora).

### Para quem mantém
- `instalar.sh` → `preparar_terceiros`: copia `terceiros/` para `base-fog/tmp` (onde a base procura), confere os
  SHA-256 e prepara `/opt/fog/ipxe` (scripts da FOG + fonte do iPXE v2.0.0 como repositório com a marca v2.0.0).
- `base-fog/lib/common/functions.sh` (`downloadfiles`): usa o arquivo local quando o SHA-256 confere; ARM opcional
  (não vem no pacote). Ajustes marcados com "LabFree ImageDeploy".
- `ferramentas/empacotar.sh` não usa mais `/opt/fogproject-stable`: tudo vem do repositório (e confere os SHA-256).
- `terceiros/LEIAME.md`: origem, versão e licença de cada componente e como atualizar.

## [0.11.0] - 2026-10-27

### Novo
- **Painel com a identidade visual da LabFree** (labfree.com.br): cores da marca (azul #1a73e8, marinho #0d1b3e;
  o tema escuro é o marinho da LabFree), fonte Jost (arquivos locais, funciona sem internet), logomarca da nuvem
  no menu, no topo do celular e no login (logo completo), nome no estilo do logo (LABFREE em itálico + ImageDeploy),
  **favicon** e ícone para celular (tela inicial) com a nuvem, e a página Ajuda → Sobre com a LabFree.

### Para quem mantém
- `web/fontes/jost-*.woff2`, `web/marca/` (logo, nuvem, favicon-64, icone-180), `web/favicon.ico`.
  `logoMarca()`/`nomeMarca()` no `app.js`. Fotos do painel: 117 situações sem achados.

## [0.10.0] - 2026-10-27

Precisa do instalador para chegar ao servidor (`sudo ./instalar.sh`, sem clonagem em andamento; reconstrói o
sistema de clonagem, ~10 min).

### Novo
- **Ajustes do Windows depois de restaurar** (Configurações → Windows):
  - **Renomear o Windows com o nome cadastrado**: feito pelo próprio sistema de clonagem logo depois de gravar
    o disco, sem instalar nada na máquina.
  - **Entrar no domínio** (Active Directory: domínio, OU, usuário e senha) e **ativar a licença** (chave própria
    da máquina em Máquinas → Editar, ou uma chave padrão/volume): feitos pelo agente para Windows no primeiro boot.
    O agente é instalado uma vez na máquina modelo (o painel mostra o link e o passo a passo) e só faz isso: não
    reinicia, não desliga e não desloga ninguém sozinho.
  - Salvar pede a senha de quem está no painel; a senha do domínio nunca volta a aparecer.
- **Cópias das imagens** (Configurações → Cópias): copiar para um **HD externo (USB)** ou uma **pasta de rede**
  (NAS, Windows ou outro servidor, SMB) e **importar** de volta (inclusive em outro servidor LabFree). Cada arquivo
  é **conferido bit a bit** (lido de volta do destino); na importação, também contra o que foi copiado na época
  (HD que estragou na gaveta é detectado). Discos internos nunca aparecem; FAT32 é avisado (arquivos > 4 GB).
  A cópia roda em segundo plano, com andamento ao vivo e botão Cancelar (nada pela metade fica no destino).
- **Visual novo nas máquinas, com a identidade da LabFree** (labfree.com.br): menu de boot em marinho com a nuvem
  e o logo LABFREE (fonte Jost) e opção selecionada no azul LabFree; tela da clonagem com as cores da LabFree,
  nuvem, trilha das etapas (√ ► ○), barra de progresso, cartões e teclas destacadas; cadastro pelo boot e banner do
  sistema de clonagem com as mesmas cores. Acentos na tela da máquina onde a fonte do console permite.

### Para quem mantém
- Novos: `lib/LabFreeImageDeploy-copias` (+ ação `copias` do ajudante, liberada ao painel no sudoers),
  `marca/fos/usr/share/fog/lib/labfree-cores.sh` (paleta e cores do whiptail), `marca/fontes/Jost.ttf` (OFL),
  `marca/labfree-logo.png`. O instalador instala `cifs-utils exfatprogs ntfs-3g zstd`.
- `labfree-tela`: modo demonstração `labfreedemo=progresso|preparando|grupo|captura|sucesso|erro` na linha do kernel
  (para fotografar a tela numa VM sem servidor). Cores do menu de boot em `CORES_IPXE` (ajudante).
- API: `windows`, `windows_salvar`, `chave_windows` em cadastrar/editar máquina, `copias`, `copias_listar`,
  `copias_rede`, `copias_exportar`, `copias_importar`, `copias_cancelar`. Configurações do LabFree em
  `globalSettings` (categoria LabFree: `LABFREE_WIN_*`, `LABFREE_CHAVE_<id>`).
- O otimizador de imagens pausa durante uma cópia (e a cópia espera ele pausar).
- Testes: `testes/teste_copias.py`.

## [0.9.0] - 2026-10-27

Precisa do instalador para chegar ao servidor (`sudo ./instalar.sh`, sem clonagem em andamento).

### Novo
- **Imagens menores e conferidas, sozinhas**: com o servidor parado, cada imagem é recomprimida (zstd nível 19 com
  janela longa: na imagem do laboratório, 14% menor que a compressão padrão do FOG) e **conferida bit a bit** antes
  de substituir o original. Menos dados para enviar = restaurações (individuais e em grupo) mais rápidas. Para na
  hora se alguém começar uma captura, restauração ou sessão em grupo, e continua depois.
- **Integridade garantida**: toda imagem é conferida depois da captura e de novo a cada 30 dias (soma de verificação
  de cada bloco). Arquivo corrompido aparece em Imagens ("Problema nos arquivos desta imagem") e no
  `LabFreeImageDeploy verificar`, e nunca é "otimizado" por cima.
- **Compressão escolhida por imagem** (Imagens → Nova imagem / Editar): Rápida (padrão, nível 3: 3,5× menos trabalho
  para o processador da máquina, a captura termina antes; o servidor deixa menor depois), Normal (6) ou Menor (12).
  Imagens novas sempre no formato zstd. Cada imagem mostra "Otimizada: X% menor" e "Conferida há …".
- `sudo LabFreeImageDeploy otimizar`: roda a otimização/conferência agora (se o servidor estiver parado).

### Correções
- **Máquinas → Detectadas na rede** virava uma lista infinita: agora mostra só quem deu boot pela rede nos últimos
  30 minutos (o número no menu também); depois disso a máquina sai da lista sozinha.

### Para quem mantém
- `lib/LabFreeImageDeploy-otimizar` + `LabFreeImageDeploy-otimizar.timer` (a cada 20 min, `Nice=19`, E/S ociosa);
  tabela `labfreeImagens` (datas em UTC); estado por arquivo em `/var/lib/LabFreeImageDeploy/otimizar.json`; registro
  em `/var/log/LabFreeImageDeploy/otimizar.log`. Teste sem root: `python3 -I testes/teste_otimizar.py`.
- API: `imagens` traz `compressao`, `zstd` e `otimizacao`; `criar_imagem`/`editar_imagem` aceitam `compressao` e
  `otimizar`; `Painel::recentes()` (detecção de 30 min).
- `publicar.sh` confere também a sintaxe do porteiro e do otimizador.

## [0.8.1] - 2026-10-27

### Documentação
- AGENTS.md e PROMPT-CONTINUAR: 0.8.0 instalada na produção e o que foi conferido; pendências (teste real em grupo,
  adaptador USB em porta USB 3).

## [0.8.0] - 2026-10-27

Precisa do instalador para chegar ao servidor (`sudo ./instalar.sh`, sem clonagem em andamento).

### Novo
- **Restaurar em grupo: nada começa sozinho.** A transmissão só começa quando alguém clica em **Iniciar
  transmissão** no painel. Quando o número de máquinas combinado entra, o painel avisa ("Sala completa", aviso na
  tela e "(Sala pronta)" no título da aba). **Iniciar agora** começa antes, só com as que já entraram (com
  confirmação dizendo quantas faltam). Depois do início, máquinas novas não entram mais na sessão (pegariam a imagem
  pela metade).
- **O painel mostra todas as máquinas que entraram na sessão em grupo**, inclusive as **não cadastradas** (antes
  elas recebiam a imagem sem aparecer em lugar nenhum): nome (ou "Máquina sem cadastro"), IP e MAC. Durante a
  transmissão: porcentagem, parte N de M, quanto já foi enviado, velocidade, tempo restante e aviso se uma máquina
  foi retirada por parar de responder.
- **Menu de boot da máquina não escolhe nada sozinho**: antes iniciava pelo disco depois de 3 segundos; agora espera
  alguém escolher. Configurável em Configurações → Cadastro pelo boot → Menu de boot (esperar, 10 s, 30 s ou 1 min).
- **Capturar**: guia para deixar a imagem menor antes de capturar (hibernação, limpeza do Windows Update, pontos de
  restauração, BitLocker, desligamento completo).
- **Medição do envio em grupo**: resumo de cada partição (espera, tempo de envio, MB/s, retransmissões, máquinas
  retiradas) em `/var/log/LabFreeImageDeploy/grupo.log` e o registro completo de cada sessão em
  `/var/log/LabFreeImageDeploy/grupo/`.

### Mudou
- Assistente Restaurar em grupo: sai o "prazo para entrar" (quem decide o início é a pessoa no painel).
- `LabFreeImageDeploy verificar` confere se o envio em grupo está sob o controle do painel.

### Para quem mantém
- Novo `lib/LabFreeImageDeploy-udp-sender` ("porteiro"), instalado em `/usr/local/lib/LabFreeImageDeploy/udp-sender`
  e posto no lugar do udp-sender da base (`UDPSENDERPATH` no `config.class.php`, por `marca-aplicar`). Andamento em
  `/run/LabFreeImageDeploy/grupo/<porta>.json`; o painel pede o início criando `<porta>.iniciar`.
- `FOG_UDPCAST_MAXWAIT` passa a 1440 min (janela para entrar e espera das máquinas pelo início); as partições 2..N
  esperam no máximo 10 min por uma máquina que sumiu (o porteiro troca o valor).
- `FOG_PXE_MENU_TIMEOUT` = 0 aplicado uma vez pelo `marca-aplicar` (marcador em `/var/lib/LabFreeImageDeploy`).
- API: `iniciar_multicast`; `tarefas`/`resumo` trazem `grupo` em cada sessão ativa; `cadastro` traz `menu_espera`.
- Testes: `testes/teste_grupo.sh` (envio em grupo real numa rede virtual, sem root); `testes/painel_simulado.py`
  serve o `web/` do próprio repositório e simula sessões em grupo com e sem o porteiro.

## [0.7.1] - 2026-10-27

### Documentação
- AGENTS.md: pedidos 11 a 13 do usuário, estado da produção depois da 0.7.0 (só os arquivos visuais do painel
  aplicados), estrutura do `app.js`, próximos passos (registro ao vivo no painel e pendências da 0.6.0).
- README: tela da máquina e acompanhamento/histórico na lista de funções.
- ARQUITETURA: seção do painel (`web/`) e os testes com API simulada.
- Novo `docs/PROMPT-CONTINUAR.md`: texto pronto para passar o projeto a outra IA (o que é o sistema, regras e pendências).

### Para quem mantém
- `ferramentas/publicar.sh`: `PUBLICAR_RODAPE` acrescenta linhas no fim da mensagem do commit.

## [0.7.0] - 2026-10-27

Varredura do painel tela a tela (todas as telas, janelas e assistentes, em celular, tablet e computador, tema claro
e escuro), com dados simulados que incluem tarefas ao vivo, máquinas sem notícia, falhas e sessões em grupo.

### Novo
- **Trilha das 5 etapas** em cada máquina em andamento (Conectar → Verificar o disco → Partições → Gravar/Copiar →
  Finalizar), igual à tela da máquina. Sem a tela nova na máquina, a etapa é estimada pela fase.
- **Tarefas**: quatro números no topo (clonando agora, esperando, sem notícia, falhas hoje); histórico agrupado por
  dia (Hoje, Ontem, …) com filtros Todas / Concluídas / Com falha / Canceladas; sessões em grupo do histórico com o nome.
- **Máquinas**: filtros Em tarefa agora / Nunca clonadas / Sem imagem padrão; máquina ocupada mostra "Acompanhar"
  em vez de "Restaurar"; a pílula diz "Última: restauração há 1 h".
- Máquina sem notícia: barra e pílula em laranja ("Sem notícia"); tarefa agendada há mais de 1 dia em laranja com o
  lembrete de cancelar. Sessão em grupo esperando máquinas mostra na barra quantas já entraram.

### Correções de layout
- Histórico no Início (meia coluna) e no tablet: nome cortado com "…", motivo espremido e textos vazando do cartão.
  Os cartões agora se adaptam à própria largura (container queries), não só à largura da tela.
- Pílulas de estado do histórico desalinhadas quando a linha não tinha "Ver registro".
- Celular: avisos com botão espremiam o texto; abas de Configurações cortadas ("Cadastr…"); MAC cortado nas reservas
  do DHCP; links "Ver tudo"/"Histórico" e o indicador de rede pequenos demais para o dedo.
- Nomes longos de imagem/máquina cortados nos cartões de escolha dos assistentes (agora quebram linha).
- Ponto "ao vivo" desalinhado em textos de duas linhas.
- "Restaurar em grupo": logo depois de criar, a tela dizia "A sessão terminou" antes de a sessão aparecer.
- Capturar: "Agora, em cada máquina" virou "Agora, na máquina"; Restaurar: "0 selecionada(s)" virou "Nenhuma
  selecionada" / "3 selecionadas".

### Para quem mantém
- `testes/painel_simulado.py` (API simulada), `testes/plano_fotos.py` e `testes/fotos_painel.py` (fotos e
  verificação automática de layout) — ver AGENTS.md seção 6.

## [0.6.1] - 2026-10-27

### Documentação
- AGENTS.md: estado da máquina de produção depois da 0.6.0 (painel e ajudante aplicados; tela da máquina depende do instalador).

## [0.6.0] - 2026-10-27

### Novo
- **Tela nova na máquina durante a captura/restauração**: etapas (conectando, verificando o disco, preparando as
  partições, gravando/copiando — com "parte N de M" —, finalizando), barra de porcentagem, velocidade, tempo restante
  e as últimas mensagens em português. Alt+F2 mostra os detalhes técnicos.
- **A máquina não reinicia sozinha no fim**: mostra "Concluído com sucesso" ou "O processo terminou com erro" com o
  motivo, e deixa escolher Reiniciar, Desligar, Ver o registro completo ou Detalhes técnicos. Com "Desligar quando
  terminar" marcado no painel, desliga sozinha depois de 60 s (qualquer tecla cancela).
- **Acompanhamento ao vivo no painel**: cada máquina mostra a fase e a etapa que ela mesma informa ("Etapa 4 de 5"),
  porcentagem, quanto já foi copiado, velocidade com a unidade certa e tempo restante; aviso quando a máquina fica
  mais de 3 min sem dar notícia (travou, desligou, cabo); sessões em grupo mostram máquina por máquina.
- **Registro de cada tarefa no painel**: o histórico mostra o motivo das falhas em português e o botão "Ver registro"
  (o registro completo que a máquina enviou e os erros que o servidor recebeu).
- **Limpar histórico** (Tarefas): histórico de tarefas, fila de espera, registro de clonagens e registros internos,
  com contagem antes, confirmação por senha e sem tocar no que está em andamento.
- Aviso no Início de tarefas agendadas há mais de 1 dia (um boot pela rede esquecido apagaria o disco).

### Correções
- Wake-on-LAN ("tentar ligar pela rede") nunca funcionava: agora o painel envia o pacote direto na rede de clonagem.
- Captura recusada quando falta espaço no servidor (antes podia lotar o disco e derrubar o painel).
- Agendamento que falha não troca mais a imagem padrão da máquina; erros do FOG traduzidos.
- Acompanhamento depois de agendar funciona com qualquer quantidade de máquinas (antes errava acima de 30).
- Sessões em grupo antigas presas apareciam fora da lista e travavam a exclusão da imagem.
- Botões "Cancelar" não somem mais no meio do clique durante a atualização ao vivo.
- Cadastro automático do FOG: nome virava o MAC depois da primeira colisão; mensagem "falhou" quando a tarefa era criada.
- Cadastro pelo boot: nome tomado por outra máquina ao mesmo tempo volta ao passo do nome (antes repetia para sempre).
- Item "Secure Boot" escondido do menu de boot (não funciona com o iPXE do produto).
- Troca de rede interrompida (queda de energia) é desfeita sozinha no próximo boot ou na próxima alteração (diário).
- Desfazer do DHCP desligado para de verdade o serviço; troca de placa mantendo a mesma rede permitida.
- Ajudante root: certificado gerado com configurações numa pasta só do root; apagar imagem não segue links;
  gravações com fsync; o original do sistema de clonagem guardado numa pasta só do root.
- Backup inclui as chaves da CA (`/opt/fog/pki`); backup sem banco sai com erro; restauração recusa backup de outra
  rede, não troca a senha do banco de uma instalação nova e não grava através de links.
- Instalador cria o admin numa instalação que parou depois da base; publicar.sh só marca a versão depois do envio e
  atualiza a versão dos arquivos do painel (cache do navegador).

### Segurança
- Limite de tentativas de login sem brechas em pedidos paralelos e sem prender processos do PHP.
- Criar usuário pede a senha de quem está no painel; a própria senha só por "Trocar minha senha".
- Troca de usuário em outra aba recarrega o painel (não age como outra pessoa).
- Registro de logins com erro deixa de ser legível pelo site.

## [0.5.0] - 2026-10-26

Varredura completa de todos os módulos (painel, API, ajudante root, instalador, scripts, cadastro pelo boot).

### Segurança
- O ajudante root nunca grava seguindo link simbólico (impedia que o usuário do site virasse root).
- Regra de sudo restrita às 7 ações que o painel usa; o ajudante também recusa as demais vindas do painel.
- Uma alteração por vez (trava exclusiva) entre painel, instalador e comandos.
- Login: corrigido desvio da troca obrigatória da senha de fábrica; limite de 10 erros por IP (bloqueio de 15 min);
  sessão regenerada no login; sessão expira após 4 h sem uso; trocar a senha derruba as outras sessões da conta.
- Trocar a senha ou excluir **outro** usuário pede a senha de quem está no painel.
- Pedidos maiores que 1 MB recusados; erros internos não mostram detalhes técnicos na tela.
- Valores do banco escapados no desfazer da troca de rede.

### Correções
- Máquinas: aparecia o texto "null" na tela quando não havia máquinas detectadas na rede.
- Máquinas detectadas na rede: lia só um dos arquivos de concessões do DHCP (a lista ficava vazia).
- Trocar IP/placa pelo painel morria no meio sem desfazer (o ajudante era derrubado junto com o PHP); agora roda em
  unidade própria do systemd e o Apache só é recarregado.
- Desfazer da troca de rede agora devolve **toda** a conexão do NetworkManager e reativa as conexões derrubadas.
- Faixa de DHCP sugerida podia incluir o IP do servidor (servidor no fim da rede).
- Duplo clique em "Continuar" nos assistentes pulava a tela de confirmação.
- Link com imagem inexistente travava o assistente de Restaurar.
- Cadastro pelo boot mostrava "Pronto!" mesmo quando o servidor recusava; "Voltar" não voltava; servidor fora do ar
  aparecia como "máquina já cadastrada".
- Instalador: retomar uma instalação que falhou no meio não terminava a base; não apaga mais uma pasta de
  desenvolvimento (com `.git`); funciona em pasta com espaço no nome; valida a faixa `--faixa`; erros do ajudante
  sempre param a instalação com a mensagem certa; sem terminal e sem `--sim` dá mensagem clara.
- Backup: disco cheio não gera mais backup corrompido "válido"; inclui certificado/CA e configurações do site.
- Restaurar backup: não apaga o backup escolhido; não volta programas antigos; não roda com clonagem em andamento.
- Verificação automática do painel apontava para a pasta antiga.
- DHCP: salvar sem mudanças não reinicia mais o serviço (antes derrubava o DHCP por alguns segundos).

### Melhorias
- Painel: telas não se sobrepõem ao navegar rápido; atualização automática sem pedidos sobrepostos e sem redesenhar
  quando nada mudou; aviso "Sem comunicação" quando o servidor cai; prazo em todos os pedidos; seleção visível em
  navegadores antigos; busca mais leve; janela de diálogo não fecha durante uma ação.
- API: resumo mais leve (não monta o histórico), consultas únicas no lugar de uma por máquina.
- Histórico de alterações limitado às 60 mais recentes; registros com rotação mensal (logrotate).

## [0.4.0-dev] - 2026-10-26

Primeira versão com o nome LabFree ImageDeploy: painel novo, assistentes, DHCP e placa de rede pelo painel,
instalador simplificado, marca nas telas de boot, cadastro pelo boot em 2 passos, admin/admin com troca obrigatória.
Detalhes em `AGENTS.md` (seções 1 e 7) e `docs/CUSTOMIZACOES-MAQUINA-ATUAL.md` (histórico do dia 1).
