# Uso do dia a dia

## Palavras usadas
- **Imagem**: cópia completa do disco de uma máquina, guardada no servidor.
- **Capturar**: copiar o disco da máquina para o servidor (máquina → servidor).
- **Restaurar**: gravar a imagem no disco da máquina, apagando o que havia (servidor → máquina).
- **Restaurar em grupo (multicast)**: uma única transmissão recebida por várias máquinas ao mesmo tempo.
- **Boot pela rede (PXE)**: ligar a máquina pelo cabo de rede. Tecla do menu de boot: F12 (Dell, Lenovo, Positivo, Acer), F9 (HP), F8 (Asus), F11 (MSI, ASRock).

## Cadastrar uma máquina
**Pelo boot (recomendado)**: ligue a máquina na rede de clonagem, aperte a tecla do menu de boot, escolha a rede
(PXE) e, no menu do LabFree ImageDeploy, *Cadastrar esta maquina (nome e imagem)*:
1. confirme o nome sugerido com Enter (ou digite outro);
2. escolha a imagem e Enter. Pronto: a máquina reinicia e já aparece no painel.

**Muitas máquinas**: use *Cadastro automatico (sem perguntas)* no menu de boot — cada uma recebe o próximo nome
da sequência. A sequência (ex.: `LAB-001`), a imagem padrão e se o cadastro automático já restaura a imagem ficam
em Painel → Configurações → Cadastro pelo boot.

**Pelo painel**: Máquinas → Cadastrar máquina (precisa do MAC), ou clique em *Cadastrar* nas máquinas
"Detectadas na rede" (as que deram boot pela rede e ainda não estão cadastradas).

## Capturar uma imagem
Prepare a máquina modelo. No Windows: desligue pelo menu Iniciar segurando Shift (desligamento completo) e
desative o BitLocker. Painel → Capturar imagem → escolha a máquina → escolha ou crie a imagem → Agendar.
Depois ligue a máquina com boot pela rede. Acompanhe em Tarefas.

**Tipo de imagem**: prefira *Redimensionável* — restaura em discos de tamanhos diferentes, inclusive menores.
Imagens de *tamanho fixo* só restauram em discos iguais ou maiores que o original (o painel mostra o mínimo).

## Restaurar
- **Poucas máquinas**: Painel → Restaurar imagem → imagem → máquinas → Apagar e restaurar. Ligue as máquinas pela rede.
- **Sala inteira**: Painel → Restaurar em grupo → imagem → nome da sessão e quantidade → Criar sessão. Em cada
  máquina: boot pela rede → *Entrar em sessao de grupo (multicast)* → digite o nome da sessão. A transmissão começa
  quando todas entrarem (ou ao fim do tempo de espera). As máquinas não precisam estar cadastradas.

## Configurações (painel)
- **DHCP**: faixa de IPs, gateway, DNS, domínio, tempo de concessão, reservas de IP, ligar/desligar.
- **Placa de rede**: qual placa e qual IP atendem a rede de clonagem. Mostra o que vai mudar antes de aplicar e
  desfaz sozinho se algo falhar. Não aplique com capturas ou restaurações em andamento.
- **Cadastro pelo boot**: padrão de nomes, próximo número, imagem padrão.
- **Usuários**: quem pode entrar no painel; trocar senhas.

## Problemas comuns
| Sintoma | Causa e solução |
|---|---|
| Máquina não pega IP / não aparece o menu | Início do painel deve mostrar "Tudo pronto". Confira cabo e switch; na BIOS ative *Network Stack/PXE* e, se possível, desative o *Secure Boot* |
| `Permission denied (ipxe.org/0216eb8f)` | Relógio da BIOS muito errado. O servidor já acerta a hora pela rede; se persistir, ajuste data/hora na BIOS e troque a bateria da placa-mãe |
| Restauração falha logo no começo | Imagem de tamanho fixo maior que o disco da máquina. Crie uma imagem *Redimensionável* e capture de novo |
| Restauração em grupo parada em "Starting to restore image (-)" | Rede de clonagem caiu durante o envio. O LabFree mantém o IP do servidor mesmo quando o cabo pisca; se acontecer, cancele a sessão em Tarefas, reinicie as máquinas e crie outra sessão |
| Máquina não aparece em "Detectadas na rede" | Ela só aparece depois de dar boot pela rede pelo menos uma vez |
| Esqueci a senha do painel | Outro usuário redefine em Usuários; ou no servidor: `sudo LabFreeImageDeploy senha admin --redefinir` (volta para admin/admin e o painel pede uma senha nova no próximo acesso) |

Em qualquer dúvida, no servidor: `sudo LabFreeImageDeploy verificar`.
