# Histórico de versões — LabFree ImageDeploy

Toda alteração entra aqui **antes** de ser publicada (`ferramentas/publicar.sh` recusa publicar sem isso).
Formato: a versão mais nova no topo; cada item diz o que mudou para quem usa e, quando importa, para quem mantém.
Numeração: `MAIOR.MENOR.CORREÇÃO` — CORREÇÃO para correções, MENOR para funções novas, MAIOR para mudanças que exigem
ação de quem já tem o sistema instalado.

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
