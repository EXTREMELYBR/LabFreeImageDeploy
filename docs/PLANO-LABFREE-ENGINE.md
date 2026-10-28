# Ideia: LabFree Engine — sistema próprio, sem o FOG

> **SOMENTE IDEIA, ARQUIVADA (decisão do usuário em 27/10/2026).** Não é um plano em andamento nem tem previsão.
> Não trabalhar nisto, não sugerir começar e não usar como guia do desenvolvimento atual, a menos que o usuário
> peça explicitamente. Fica registrado só para consulta.
>
> Resumo da ideia, para quando (e se) for retomada: Servidor em **Go**, licença **fechada (proprietária)** da LabFree, com **todas as funções da versão atual**
> e porte para **Windows**. Enquanto isso, a versão atual (camada sobre o FOG 1.5.10) segue em produção, autônoma
> (base e componentes embutidos no repositório, versão 0.12.0).

## 1. Objetivo
Um produto da LabFree, sem código do FOG, que faz tudo o que o LabFree ImageDeploy faz hoje, instalável em Linux e
Windows com um único instalador, funcionando sem internet e sem depender de nenhum projeto externo para existir.

## 2. O que o FOG faz hoje e o que substitui cada parte
| Função | Hoje (FOG + LabFree) | LabFree Engine |
|---|---|---|
| Painel e API | `web/` (HTML/JS próprio) + `api.php` usando classes do FOG, MariaDB | Mesmo `web/` (reaproveitado) servido por um binário Go; API nova em JSON; **SQLite** embutido |
| Tarefas, agendamento, histórico | Tabelas e serviços do FOG (PHP, `FOGScheduler`) | Fila de tarefas no próprio servidor (goroutines), mesma semântica de estados |
| Transporte de imagem (1 máquina) | NFS | **HTTP(S)** com retomada (Range), SHA-256 por bloco e limite de banda — funciona em Windows |
| Envio em grupo | udp-sender do FOG + porteiro do LabFree | udpcast (GPL, programa separado, com versão para Windows) controlado pelo Engine; início pelo painel (mesma lógica do porteiro) |
| DHCP | Kea (completo) | Embutido em Go: **modo proxy** (convive com o DHCP da escola) **ou** completo; reservas |
| TFTP / boot | tftpd-hpa + iPXE da FOG | TFTP e HTTP embutidos; iPXE compilado do fonte (já está em `terceiros/`) com o nome e a CA |
| Sistema nas máquinas | FOS (Buildroot da FOG) + tela LabFree | **LabFree OS**: Buildroot próprio, construído no repositório; scripts escritos do zero; telas já desenhadas (`labfree-tela`) viram nativas |
| Agente Windows (nome, domínio, licença) | fog-client (GPL) | Nome: offline no registro (como hoje); domínio e licença: **agente próprio** pequeno (Go, serviço Windows) ou script de primeiro boot |
| Otimizar e conferir imagens | `LabFreeImageDeploy-otimizar` | Mesmo algoritmo, em Go (zstd 19 + janela longa, SHA-256) |
| Cópias HD/rede | `LabFreeImageDeploy-copias` | Mesmo, em Go (no Windows: pastas e compartilhamentos nativos) |
| Rede (placa, IP) | ajudante root + NetworkManager | Linux: NetworkManager/netplan; Windows: netsh/PowerShell; mesma tela |
| NAT opcional | (planejado) | Linux: nftables masquerade; Windows: ICS/NetNat |

## 3. Arquitetura
```
labfree-engine (um executável por sistema)
├── api/        HTTP(S): painel, API JSON, sessões, CSRF, limite de login
├── loja/       SQLite (máquinas, imagens, tarefas, sessões em grupo, usuários, configurações, histórico)
├── tarefas/    fila e estados (agendada, aguardando, em andamento, concluída, falha, cancelada)
├── boot/       DHCP (proxy/completo), TFTP, HTTP de boot, menu iPXE gerado
├── imagens/    servidor de partes (HTTP Range + SHA-256), otimizador, cópias, manifesto
├── grupo/      controle do udpcast (porteiro), andamento por máquina
├── rede/       placa, IP, NAT (por sistema operacional)
└── web/        painel (o atual, adaptado à API nova)
labfree-os (Buildroot): kernel + initramfs; cliente em Go estático (fala com a API) + partclone, zstd, udpcast, sgdisk
```
- Formato das imagens **compatível com o atual** (partclone + zstd, `d1p*.img`, `d1.partitions`, `d1.mbr`): imagens
  já capturadas continuam servindo.
- Cliente da máquina em Go (binário estático) no lugar dos scripts bash do FOS: mais fácil de testar e de manter.

## 4. Licenças (modelo fechado)
- Código do Engine, do cliente e do agente: **da LabFree**, escrito do zero, sem copiar código do FOG.
- Programas GPL usados como **programas separados** (kernel, Buildroot, partclone, udpcast, iPXE, zstd é BSD):
  distribuídos sem modificação proprietária, com o código-fonte junto (pasta `terceiros/` e oferta escrita).
- Ninguém da equipe deve copiar trechos do FOG para o Engine; os textos e telas atuais do LabFree são nossos.
- Registrar a marca "LabFree ImageDeploy" (INPI).

## 5. Etapas (cada uma termina testada em VM e depois no laboratório)
1. **Fundação**: binário Go, SQLite, login/usuários, painel atual servido, instaladores (Linux `.deb`/script e
   Windows `.msi`), atualização automática opcional.
2. **Boot**: DHCP proxy + completo, TFTP/HTTP, iPXE com marca, menu (sem tempo limite, como hoje).
3. **LabFree OS**: Buildroot próprio com o kernel e drivers equivalentes ao FOS atual; tela LabFree nativa; cadastro
   em 2 passos.
4. **Capturar e restaurar** uma máquina por HTTP (retomada, conferência), trilha das 5 etapas, registro, sem
   reiniciar sozinha.
5. **Restaurar em grupo**: udpcast controlado, lista de conectadas, sala completa, Iniciar agora.
6. **Imagens**: compressão por imagem, otimizador, conferência periódica, cópias HD/rede e importação.
7. **Windows depois de restaurar**: nome offline, agente próprio (domínio e licença).
8. **Configurações**: rede/placa, DHCP, NAT opcional, cadastro pelo boot, usuários.
9. **Migração**: importar máquinas, imagens, usuários e histórico de uma instalação LabFree/FOG.
10. **Servidor no Windows**: mesmas funções; testes em Windows 10/11 e Server.

Critério de pronto de cada etapa: testes automáticos (incluindo VM sem internet), fotos do painel sem achados,
documentação e CHANGELOG — as mesmas regras de hoje.

## 6. Riscos e como reduzir
- **Drivers no LabFree OS** (máquinas variadas): começar pela mesma configuração de kernel do FOS atual e manter uma
  sala de teste com os modelos reais.
- **DHCP em modo proxy** em redes de escola: testar com roteadores comuns; manter o modo completo.
- **Windows como servidor**: multicast e firewall do Windows; instalar regras no instalador.
- **Tempo**: várias semanas; a versão atual continua sendo vendida e mantida até a troca.

## 7. O que já existe e é reaproveitado
Painel completo (`web/`), textos e assistentes, porteiro do grupo (lógica), otimizador e cópias (algoritmos e
testes), telas da máquina (`labfree-tela`, cores LabFree), identidade visual, testes de VM e de fotos, e os
componentes de terceiros já embutidos em `terceiros/`.
