# Código-fonte do FOS (sistema de clonagem que roda nas máquinas)

O FOS é software livre (GPL). Esta pasta traz o código-fonte **correspondente aos binários de `../fos/`**
(`bzImage`, `bzImage32`, `init.xz`, `init_32.xz`), para cumprir a licença e para que o sistema possa ser
reconstruído sem depender de nenhum site. As personalizações do LabFree aplicadas por cima do FOS ficam em
`../../marca/fos/` (também GPL) e são aplicadas pelo instalador (`LabFreeImageDeploy-root`, função `marca_fos`).

| Arquivo | O que é | Origem |
|---|---|---|
| `fos-EXP_20261021-160109-fonte.tar.gz` | Scripts de construção, configurações (Buildroot e kernel), patches e scripts do FOS | github.com/FOGProject/fos, tag `EXP_20261021-160109` (commit `01e8743a4cdd17804f5bbf7322c9361bee5c3974`) — a mesma versão dos binários (SHA-256 conferido com a release) |
| `buildroot-2026.02.1.tar.xz` | Buildroot, que monta o `init.xz` | buildroot.org (SHA-256 conferido com o `.sign` oficial) |
| `linux-6.18.38.tar.xz.parte-00` … `-03` | Kernel Linux 6.18.38 (o `bzImage`), dividido em partes de 45 MB por causa do limite de 100 MB por arquivo do GitHub | cdn.kernel.org (SHA-256 conferido com o `sha256sums.asc` oficial) |

Cada arquivo tem o `.sha256` ao lado; o do kernel vale para as partes juntadas.

## Juntar o kernel e conferir
```
cat linux-6.18.38.tar.xz.parte-* > linux-6.18.38.tar.xz
sha256sum -c linux-6.18.38.tar.xz.sha256 buildroot-2026.02.1.tar.xz.sha256 fos-EXP_20261021-160109-fonte.tar.gz.sha256
```

## Reconstruir o FOS (em uma máquina de desenvolvimento, não no servidor do laboratório)
```
mkdir fos && tar -xzf fos-EXP_20261021-160109-fonte.tar.gz -C fos --strip-components=1
cp buildroot-2026.02.1.tar.xz linux-6.18.38.tar.xz fos/
cd fos && ./build.sh -n -a x64        # e -a x86 para os arquivos de 32 bits
```
O `build.sh` usa os arquivos já colocados ao lado dele em vez de baixá-los. Os pacotes que o Buildroot compila
dentro do `init.xz` (busybox, partclone, util-linux etc.) são baixados por ele na primeira construção; veja abaixo.

## Código dos pacotes, do compilador e firmware (desde a 0.13.6)
| Pasta | O que é | Origem |
|---|---|---|
| `pacotes-buildroot/` | Código de todos os pacotes que o Buildroot compila dentro do `init.xz` e do `init_32.xz` (busybox, partclone, e2fsprogs, ntfs-3g, util-linux, zstd, gcc/glibc do sistema de 32 bits…) e o compilador pronto da Bootlin usado no de 64 bits: 126 pacotes, a pasta de downloads do Buildroot (`BR2_DL_DIR`) | `build.sh --fs-download-only -a x64` e `-a x86` em 28/10/2026; cada arquivo conferido pelo Buildroot com o `.hash` do próprio pacote |
| `toolchain-bootlin/` | Código do gcc 15.1.0 (com os patches e a `series`) e da glibc 2.41-70 do compilador da Bootlin `x86-64--glibc--bleeding-edge-2025.08-1`: as bibliotecas de execução (glibc, libgcc, libstdc++) do sistema de 64 bits vêm dele | toolchains.bootlin.com/downloads/releases/sources/ (gcc conferido com o `sha512.sum` do gcc.gnu.org) |
| `firmware/` | Os arquivos de firmware embutidos nos kernels (`CONFIG_EXTRA_FIRMWARE`: placas de rede Broadcom bnx2/bnx2x/tg3 e Realtek r8169/r8152), o `WHENCE` e as licenças deles (`LICENSES/`). Firmware não é código-fonte: vem como foi distribuído, com licença "Redistributable" | linux-firmware, tag `20261019` (a última antes do FOS de 21/10/2026). Conferido byte a byte dentro dos kernels: 39 de 39 (`bzImage`) e 31 de 31 (`bzImage32`) |

`conteudo.sha256` tem o SHA-256 de cada arquivo inteiro dessas três pastas. Arquivos maiores que 45 MB estão em
partes (`.parte-00`, `-01`…), como o kernel. O `ferramentas/empacotar.sh` confere tudo antes de gerar o pacote.
Para conferir à mão e juntar as partes:
```
while read -r soma arq; do
  if [ -f "$arq" ]; then s=$(sha256sum < "$arq"); else s=$(cat "$arq".parte-* | sha256sum); fi
  [ "${s%% *}" = "$soma" ] || echo "ERRO: $arq"
done < conteudo.sha256
for p in $(find . -name '*.parte-00'); do a=${p%.parte-00}; cat "$a".parte-* > "$a"; done
```

Para reconstruir sem internet: junte as partes, copie `pacotes-buildroot/*` para `~/.buildroot-dl/` (a pasta de
downloads do Buildroot) e o `linux-6.18.38.tar.xz` juntado também para `~/.buildroot-dl/linux/` (o sistema de 32 bits
compila os cabeçalhos do kernel; o arquivo não foi repetido aqui). O firmware vai na pasta `linux-firmware/` ao lado
do `build.sh` (o script tenta clonar o repositório inteiro se ela não existir).

`pacotes-buildroot/fog/fog_1.tar.gz` (desde a 0.13.7) é um arquivo vazio: o programa desse pacote (`fogmbrfix`) está
no código do FOS, em `Buildroot/package/fog/src`, mas o Buildroot exige o arquivo, com o SHA-512 de
`Buildroot/package/fog/fog.hash` (conferido). Ele só existe em www.fogproject.org, que o LabFree bloqueia no servidor;
foi baixado à parte com `curl --resolve` para `www.fogproject.org` e `fogproject.org` (o site redireciona), sem
mexer no `/etc/hosts`.

Como foi obtido (o script do FOS só aceita Debian/Ubuntu e exige as ferramentas de compilação até para baixar; numa
cópia temporária, as duas conferências foram puladas, sem instalar nada):
```
sed -i -e 's/^checkDependencies$/:/' -e 's/^installDependencies "\$installDep"/:/' build.sh
HOME=$PWD ./build.sh --fs-download-only -a x64; HOME=$PWD ./build.sh --fs-download-only -a x86
(cd fssourcex64 && make -k source); (cd fssourcex86 && make -k source)   # segue apesar do fog_1.tar.gz
```

## Atualizar para uma versão nova do FOS
Trocar os binários em `../fos/` e, **junto**, os arquivos desta pasta pela mesma versão (tag do FOS, Buildroot e kernel
indicados no `build.sh` dela: `KERNEL_VERSION` e `BUILDROOT_VERSION`), com os `.sha256`. Registrar em
`../LEIAME.md` e no `CHANGELOG.md`.
