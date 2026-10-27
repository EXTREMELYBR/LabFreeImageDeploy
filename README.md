# LabFree ImageDeploy

**Clonagem de máquinas pela rede, sem complicação.** Capture o disco de uma máquina modelo e grave
essa imagem em uma, dez ou cem máquinas pelo cabo de rede, a partir de um painel web simples em português.

Baseado no [FOG Project](https://fogproject.org) (licença GNU GPL v3). O LabFree ImageDeploy traz a base
FOG 1.5.10 embutida e travada nessa versão (não recebe atualizações do FOG) e acrescenta:

- **Painel novo**: assistentes passo a passo para capturar, restaurar e restaurar em grupo (multicast),
  acompanhamento ao vivo, avisos claros (por exemplo, "esta imagem só cabe em discos de 128 GB ou mais").
- **Tela própria na máquina durante a clonagem**: etapas, porcentagem, velocidade, tempo restante e mensagens em
  português; no fim mostra o resultado e não reinicia sozinha.
- **Acompanhamento e histórico**: trilha das 5 etapas de cada máquina, aviso de máquina sem notícia, histórico por
  dia com filtros, motivo das falhas e o registro completo de cada tarefa.
- **Restaurar em grupo sob controle**: o painel mostra cada máquina que entrou (cadastrada ou não), avisa quando a
  sala está completa e só começa quando alguém clica em Iniciar. Nada começa sozinho na máquina.
- **Imagens menores e conferidas**: com o servidor parado, as imagens são recomprimidas e conferidas bit a bit
  (restaurações mais rápidas, arquivo corrompido detectado antes de estragar uma sala).
- **Cadastro de máquinas em 2 passos** direto na tela da máquina, pelo boot da rede: nome sugerido e imagem.
- **Rede e DHCP configuráveis pelo painel**, com teste e desfazer automático se algo der errado.
- **Funciona com adaptador USB-Ethernet** e não mexe na internet do servidor.
- **Proteções**: backups automáticos, nada é estragado por `apt upgrade`, notebook não suspende.

## Instalação (3 passos)

Você precisa de: um computador com **Ubuntu 22.04/24.04, Linux Mint 21/22 ou Debian 12** (64 bits, com
NetworkManager), **internet** durante a instalação e **uma placa de rede com cabo só para a rede de clonagem**
(pode ser um adaptador USB-Ethernet, ligado ao switch das máquinas a clonar).

```bash
tar xzf LabFreeImageDeploy-*.tar.gz
cd LabFreeImageDeploy-*/
sudo ./instalar.sh
```

O instalador mostra as placas de rede e pergunta qual atende a rede de clonagem.
Leva de 20 a 40 minutos. No final ele mostra o endereço do painel (por padrão `https://192.168.31.1/`).

Instalação sem perguntas: `sudo ./instalar.sh --sim --placa enx00e04c690b37`
(todas as opções: `sudo ./instalar.sh --ajuda`).

## Primeiro uso

1. Abra o painel e entre com usuário **admin** e senha **admin**. No primeiro acesso o painel pede uma senha nova
   (obrigatório: até trocar, nada mais funciona).
2. **Cadastre** a máquina modelo: dê boot pela rede nela (F12 na maioria) e escolha *Cadastrar esta maquina*.
3. **Capture**: Painel → Capturar imagem.
4. **Restaure**: Painel → Restaurar imagem (algumas máquinas) ou Restaurar em grupo (sala inteira).

## Administração no servidor

```bash
sudo LabFreeImageDeploy verificar        # confere se está tudo funcionando
sudo LabFreeImageDeploy backup           # backup do painel, configurações e banco
sudo LabFreeImageDeploy senha admin      # define a senha de um usuário
sudo LabFreeImageDeploy senha admin --redefinir   # volta para admin/admin (troca obrigatória no próximo acesso)
sudo LabFreeImageDeploy                  # lista todas as ações
```

## Documentação

- [docs/INSTALACAO.md](docs/INSTALACAO.md) — instalação detalhada, opções, atualização e problemas
- [docs/USO.md](docs/USO.md) — uso do dia a dia e problemas comuns
- [docs/ARQUITETURA.md](docs/ARQUITETURA.md) — como funciona por dentro (para quem for manter)
- [CHANGELOG.md](CHANGELOG.md) — o que mudou em cada versão
- [AGENTS.md](AGENTS.md) — estado do projeto e guia para continuar o desenvolvimento (inclui a regra de publicação)
- [docs/PROMPT-CONTINUAR.md](docs/PROMPT-CONTINUAR.md) — texto pronto para passar o projeto a outra IA

## Licença

GNU GPL versão 3 (veja `LICENSE`). Este pacote contém o FOG Project e usa iPXE, Partclone, udpcast,
Kea DHCP e outros softwares livres, cada um sob sua própria licença; os créditos aparecem no painel
(Ajuda → Sobre) e nas telas de boot.
