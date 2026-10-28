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

## Pendente: código dos pacotes do Buildroot
Para o pacote ficar completo também nesse ponto, falta guardar aqui os códigos dos pacotes que o Buildroot usa
(pasta `pacotes-buildroot/`). Eles são obtidos, numa máquina de desenvolvimento com internet, com:
```
./build.sh --fs-download-only -a x64 && ./build.sh --fs-download-only -a x86   # grava em ~/.buildroot-dl
```
(é o script do projeto FOS; rodar só com a autorização do responsável pelo sistema.)

## Atualizar para uma versão nova do FOS
Trocar os binários em `../fos/` e, **junto**, os arquivos desta pasta pela mesma versão (tag do FOS, Buildroot e kernel
indicados no `build.sh` dela: `KERNEL_VERSION` e `BUILDROOT_VERSION`), com os `.sha256`. Registrar em
`../LEIAME.md` e no `CHANGELOG.md`.
