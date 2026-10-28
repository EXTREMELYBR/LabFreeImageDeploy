# Instalar o LabFree ImageDeploy em um computador novo

> Cole este arquivo inteiro em uma conversa nova com o Claude (Claude Code) **no computador onde o sistema vai
> ser instalado** (ele também está no repositório: `docs/PROMPT-INSTALAR.md`). O Claude deve seguir os passos abaixo, um de cada vez, explicando em português simples.

---

## Instruções para o Claude

Você vai me ajudar a instalar o **LabFree ImageDeploy** (a versão mais recente), um sistema de clonagem de discos pela rede (PXE)
para laboratórios de escolas e empresas. Ele é uma camada sobre o FOG Project 1.5.10, que vem **dentro** do pacote.

Fale comigo em português, de forma simples e direta. Siga os passos na ordem e me mostre o resultado de cada um
antes de ir para o próximo.

### Regras (obrigatórias)

1. **Não mexa na conexão de internet** (a placa que leva o computador à internet) **nem na rota padrão**.
2. **Não use Wi-Fi como placa de clonagem.** A rede de clonagem precisa de uma placa com cabo, só para ela
   (um adaptador USB-Ethernet serve).
3. Antes de alterar qualquer arquivo de configuração à mão, faça um backup (`arquivo.bak-AAAAMMDD`). O instalador
   já faz os backups dele; normalmente você não precisa editar nada.
4. Comandos com `sudo` que demoram ou mudam o sistema (o instalador, principalmente): **me passe o comando exato**
   e eu rodo no terminal. Não tente contornar bloqueios de permissão.
5. Não apague nada em `/images` nem dados do banco de dados.
6. As senhas do banco ficam em `/opt/fog/.fogsettings`: são segredo. Nunca mostre, copie nem envie esse arquivo.
7. O repositório no GitHub é **privado**. Não peça nem salve meu token; se eu precisar fazer login no GitHub,
   eu mesmo faço.

### Passo 1 — Conferir o computador

Rode e me explique o resultado:

```bash
cat /etc/os-release | head -4
uname -m
free -h
df -h /
systemctl is-active NetworkManager
nmcli -f DEVICE,TYPE,STATE,CONNECTION device
ip -4 -br addr
ip route | grep default
```

Precisa ter:

| Item | Precisa ser |
|---|---|
| Sistema | Ubuntu 22.04 ou 24.04, Linux Mint 21 ou 22, ou Debian 12, 64 bits (`x86_64`) |
| NetworkManager | `active` |
| Memória | 4 GB ou mais |
| Disco livre | pelo menos **5 GB para a instalação**, mais o espaço das imagens (cada imagem ocupa de 10 a 60 GB, em `/images`) |
| Internet | funcionando (o instalador baixa pacotes do Ubuntu/Debian) |
| Placa de clonagem | uma placa **ethernet com cabo** que **não** seja a da rota padrão (a da internet) |

Diga qual placa é a da internet (a que aparece em `ip route | grep default`) e qual pode ser a de clonagem.
Se só existir uma placa com cabo, pare e me avise: preciso ligar um adaptador USB-Ethernet.

Avise também: **na rede de clonagem (o switch das máquinas a clonar) não pode haver outro servidor DHCP**
(roteador, por exemplo). O LabFree entrega os IPs nessa rede.

### Passo 2 — Baixar o pacote (cerca de 1 GB)

O pacote fica na release mais recente do GitHub:
https://github.com/EXTREMELYBR/LabFreeImageDeploy/releases/latest

Arquivos: `LabFreeImageDeploy-X.Y.Z.tar.gz` e `LabFreeImageDeploy-X.Y.Z.tar.gz.sha256` (X.Y.Z é o número da
versão; nos comandos abaixo, troque pelo número que veio no nome do arquivo).

Como o repositório é privado, me ofereça um destes caminhos:

- **A) Pelo navegador**: eu entro no GitHub com a minha conta, abro o link acima e baixo os dois arquivos para a
  pasta `~/Downloads` deste computador (ou copio de um pendrive).
- **B) Pelo terminal com o `gh`**: se o `gh` não estiver instalado, `sudo apt install gh`. Depois eu rodo
  `gh auth login` (interativo, eu faço) e você roda:

  ```bash
  mkdir -p ~/Downloads && cd ~/Downloads
  gh release download -R EXTREMELYBR/LabFreeImageDeploy   # sem número = a mais recente
  ```

### Passo 3 — Conferir se o arquivo chegou inteiro

```bash
cd ~/Downloads
sha256sum -c LabFreeImageDeploy-X.Y.Z.tar.gz.sha256
```

Tem que aparecer `LabFreeImageDeploy-X.Y.Z.tar.gz: OK`.

Se der diferente, **não instale**: baixe de novo.

### Passo 4 — Extrair

```bash
cd ~/Downloads
tar xzf LabFreeImageDeploy-X.Y.Z.tar.gz
cd LabFreeImageDeploy-X.Y.Z
./instalar.sh --ajuda
```

### Passo 5 — Instalar (eu rodo)

Antes, confirme que o cabo da placa de clonagem está ligado no switch das máquinas.

Monte o comando com a placa escolhida no passo 1 e me passe para eu rodar. Sugira rodar dentro do `screen` para a
instalação não parar se o terminal fechar:

```bash
sudo apt install -y screen
screen -S instalar
cd ~/Downloads/LabFreeImageDeploy-X.Y.Z
sudo ./instalar.sh --placa NOME_DA_PLACA
```

- Sem `--placa`, o instalador mostra as placas e pergunta (a da internet e o Wi-Fi ficam bloqueados).
- Leva de **20 a 40 minutos**. Se o terminal cair: `screen -r instalar`.
- Por padrão o servidor fica com o IP `192.168.31.1` na rede de clonagem. Se a rede da internet já usar
  `192.168.31.x`, use outro IP: `--ip 10.10.10.1`.
- O registro completo fica em `/var/log/LabFreeImageDeploy/instalacao.log`. Se falhar, leia o fim desse arquivo
  (`sudo tail -n 60 /var/log/LabFreeImageDeploy/instalacao.log`), me explique o erro em português e diga o que
  fazer. Rodar o instalador de novo continua de onde parou.

### Passo 6 — Conferir

```bash
sudo LabFreeImageDeploy verificar
```

Tem que terminar com **"Tudo certo."**. Se aparecer "ATENÇÃO", me explique cada aviso e o comando que ele sugere.

### Passo 7 — Primeiro acesso

1. No navegador: `https://192.168.31.1/` (ou o IP escolhido; também responde pelo IP da internet do servidor).
   O aviso de certificado é normal: *Avançado → Continuar*.
2. Usuário **admin**, senha **admin**. O painel obriga a criar uma senha nova.
3. Para testar: ligue uma máquina do laboratório pela rede (boot PXE / "Network boot" na BIOS). Ela deve mostrar a
   tela do LabFree com o cadastro em 2 passos.

Documentação completa dentro do pacote: `README.md`, `docs/INSTALACAO.md` (problemas na instalação) e
`docs/USO.md` (uso do dia a dia).

### Problemas comuns

| Mensagem | O que fazer |
|---|---|
| "Nenhuma placa com cabo disponível" | Ligar um adaptador USB-Ethernet. A placa da internet não pode ser usada |
| "A rede … conflita com a placa …" | Usar outro IP: `--ip 10.10.10.1` |
| "Sem internet" | Conectar o servidor à internet pela outra placa |
| "Sistema não testado" | Só use `--forcar` se eu concordar; o recomendado é um dos sistemas da tabela do passo 1 |
| Máquinas não recebem IP | `sudo LabFreeImageDeploy verificar`; conferir o cabo e se não há outro DHCP (roteador) na rede de clonagem |
