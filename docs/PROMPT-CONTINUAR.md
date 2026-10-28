# Texto para passar o projeto a outra IA

Cole o bloco abaixo como primeira mensagem numa sessão nova (rodando nesta máquina, em `/opt/LabFreeImageDeploy`).
Atualize a lista de pendências quando ela mudar (a fonte é `AGENTS.md`, seções 8 e 9).

````text
Você vai continuar o desenvolvimento do **LabFree ImageDeploy**, um sistema meu de clonagem de disco pela rede (PXE)
para laboratórios. Ele é uma camada sobre o **FOG Project 1.5.10**, com painel web próprio, assistentes, DHCP e placa
de rede configuráveis pelo painel, instalador simplificado, marca nas telas de boot e tela própria na máquina durante
a clonagem. Eu quero vender o sistema para escolas e empresas, então qualidade, clareza para leigos e segurança importam.
Fale comigo em português, de forma simples e direta.

## Antes de qualquer coisa
1. Leia INTEIRO `/opt/LabFreeImageDeploy/AGENTS.md` (o `CLAUDE.md` é o mesmo arquivo): o que é o sistema, a máquina,
   a estrutura do repositório, o ajudante root, as lições aprendidas, como testar, o estado da produção (seção 8) e
   os próximos passos (seção 9).
2. Leia também `CHANGELOG.md` (versão atual em `VERSION`), `README.md` e `docs/`.
3. Só depois me diga, em poucas linhas, o que entendeu e o que pretende fazer.

## Onde você está (MUITO IMPORTANTE)
- Este notebook (Dell Inspiron 15 3520, Linux Mint 22.3, tudo como root) é ao mesmo tempo a máquina de
  desenvolvimento e o **SERVIDOR DE PRODUÇÃO** de um laboratório escolar real.
- Repositório de trabalho: `/opt/LabFreeImageDeploy`. O painel em produção é servido a partir de cópias instaladas
  pelo instalador (AGENTS.md seções 3 e 8). Mudar o repositório não muda a produção sozinho.
- Repositório GitHub privado: remoto `origin` (o token é do usuário Linux `labfree`).

## Regras que você deve seguir sempre
- Antes de alterar qualquer arquivo de configuração, faça backup (`arquivo.bak-AAAAMMDD`).
- Não mexa na conexão de internet (a outra placa) nem na rota padrão.
- Não apague imagens em `/images` nem dados do banco do FOG.
- Antes de reiniciar serviços ou mexer na produção, confira se há clonagem em andamento:
  `mysql fog -e "select taskID,taskStateID from tasks where taskStateID in (1,2,3); select msID,msState from multicastSessions where msState in (0,1,2,3)"`
- Se precisar de algo que você não consegue ou não deve executar (rodar o instalador em produção, gravar sudoers,
  trocar o IP do servidor, cancelar sessões do laboratório), me passe os comandos exatos e eu rodo em outro
  terminal. Não tente contornar bloqueios de permissão.
- As credenciais do banco em `/opt/fog/.fogsettings` são segredo: nunca copie, mostre nem envie para o git.
- **O sistema tem de ser 100% autônomo**: nada da instalação pode depender de links do projeto FOG ou do GitHub
  (a base fica em `base-fog/` e o que ela baixaria em `terceiros/`, com SHA-256). Teste instalações do zero numa VM
  com o GitHub bloqueado.
- Servidor próprio escrito do zero: há um **plano de migração completo** (`docs/PLANO-LABFREE-ENGINE.md`), mas o
  desenvolvimento só começa quando eu mandar; a linguagem (C# ou Go) ainda é decisão minha. As máquinas continuam
  sempre no **FOS personalizado** (atualizável sem quebrar), nunca um sistema nosso do zero.
- Toda alteração deve ser documentada (CHANGELOG.md, AGENTS.md, docs/ quando afetar o uso, VERSION) e a nova
  versão enviada para o GitHub com `ferramentas/publicar.sh` (ele recusa publicar sem CHANGELOG). Sempre que fizer
  algo, suba para o GitHub e lance uma release nova: o `publicar.sh` já lança a release com as notas e o pacote.
- Para o git/gh, use o token do `labfree` só dentro do próprio comando, sem imprimir nem salvar:
  `GH_TOKEN=$(sudo -u labfree gh auth token) GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=credential.https://github.com.helper GIT_CONFIG_VALUE_0='!f() { echo username=x-access-token; echo "password=$GH_TOKEN"; }; f' <comando git>`
  (`PUBLICAR_RODAPE="..."` acrescenta linhas de coautoria no commit do `publicar.sh`).

## Como testar o painel sem tocar na produção
- `testes/painel_simulado.py` (API falsa com dados realistas; cenários pelo cookie `cenario`: cheio, vazio,
  problema, senha, deslogado), `testes/plano_fotos.py` e `testes/fotos_painel.py` (fotos no Chrome headless em
  celular/tablet/computador, claro/escuro, com verificação automática de layout e de erros de JS). AGENTS.md seção 6.
- Não existe `node` instalado; a sintaxe do JS é validada pelo Chrome.

## O que está pendente (detalhes em AGENTS.md seções 8 e 9)
- Produção: a 0.13.2 está instalada (tudo até o NAT e o teste intenso). Falta o instalador para a 0.14.0
  (tarefas esquecidas canceladas sozinhas, registro ao vivo no painel, sobras de captura em Imagens).
- Testes com máquinas reais: sessão em grupo pequena (2–3 máquinas: lista de conectadas, aviso de sala completa,
  Iniciar, progresso), Windows com domínio, HD externo e NAS. Mudar o adaptador USB da rede de clonagem para uma
  porta USB 3 (é o maior ganho de velocidade).
- Ideias e itens conhecidos: multicast para máquinas cadastradas sem login em cada uma; testar em Ubuntu 22.04 e
  Debian 12; fuso do `FOG_TZ_INFO`.

Comece lendo os arquivos indicados e me apresente um resumo curto. Não altere nada antes disso.
````
