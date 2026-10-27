# Histórico de versões — LabFree ImageDeploy

Toda alteração entra aqui **antes** de ser publicada (`ferramentas/publicar.sh` recusa publicar sem isso).
Formato: a versão mais nova no topo; cada item diz o que mudou para quem usa e, quando importa, para quem mantém.
Numeração: `MAIOR.MENOR.CORREÇÃO` — CORREÇÃO para correções, MENOR para funções novas, MAIOR para mudanças que exigem
ação de quem já tem o sistema instalado.

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
