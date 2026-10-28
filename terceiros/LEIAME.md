# Componentes de terceiros embutidos

O LabFree ImageDeploy é **autônomo**: o instalador não baixa nada do projeto FOG nem do GitHub. Tudo o que a base
(FOG 1.5.10.2518, em `../base-fog/`) baixaria durante a instalação está aqui, conferido por SHA-256
(`instalar.sh` → `preparar_terceiros`; `ferramentas/empacotar.sh` também confere antes de gerar o pacote).
Os pacotes do próprio sistema operacional (Apache, PHP, MariaDB, Kea…) vêm dos repositórios do Ubuntu/Debian.

| Pasta | O que é | Versão | Origem (quando foi obtido) | Licença |
|---|---|---|---|---|
| `fos/` | Sistema de clonagem que roda nas máquinas (kernel `bzImage`/`bzImage32` e `init.xz`/`init_32.xz`). O LabFree acrescenta a tela, o cadastro e a marca por cima (ajudante root, `marca_fos`) | kernel 6.18.38, init 20261021 | github.com/FOGProject/fos releases (26/10/2026) | GPL v2 (kernel, Buildroot) e licenças de cada programa |
| `fos-fonte/` | **Código-fonte** correspondente ao `fos/` (GPL): scripts e configurações do FOS, Buildroot e kernel (em partes de 45 MB). Como reconstruir e o que falta em `fos-fonte/LEIAME.md` | FOS tag EXP_20261021-160109, Buildroot 2026.02.1, Linux 6.18.38 | github.com/FOGProject/fos, buildroot.org, kernel.org (28/10/2026) | GPL v2 |
| `fog-client/` | Agente para Windows (`FOGService.msi`, `SmartInstaller.exe`): nome, domínio e licença depois de restaurar | 0.13.0 | github.com/FOGProject/fog-client releases (26/10/2026) | GPL v3 |
| `fog-ipxe/*.tar.gz` | Binários de boot pela rede (iPXE da FOG) e o conjunto Secure Boot | v2.0.0-fog.7.1 | github.com/FOGProject/fog-ipxe releases (27/10/2026) | GPL v2 |
| `fog-ipxe/fonte/` | Scripts e ajustes da FOG para compilar o iPXE (`buildipxe.sh`, patches, configs) | v2.0.0-fog.7.1 | github.com/FOGProject/fog-ipxe | GPL v2 |
| `ipxe/ipxe-v2.0.0-fonte.tar.gz` | Código-fonte do iPXE (para compilar o boot com o nome do produto e a CA do servidor) | v2.0.0 (commit 12798ec2) | github.com/ipxe/ipxe (`git archive v2.0.0`) | GPL v2 (e UBDL) |

Fora do pacote de propósito: os arquivos ARM (`arm_Image`, `arm_init.cpio.gz`, ~85 MB), que laboratórios não usam;
o instalador da base foi ajustado para seguir sem eles.

## Atualizar um componente
Troque o arquivo e o `.sha256` correspondente (no caso do FOS, troque também o código em `fos-fonte/` pela mesma versão), registre a nova versão/origem nesta tabela e no `CHANGELOG.md`, teste
uma instalação do zero numa VM **sem acesso ao GitHub** (AGENTS.md, seção 6) e publique uma versão nova.
