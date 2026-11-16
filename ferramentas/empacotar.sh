#!/bin/bash
# LabFree ImageDeploy — gera o pacote de instalação (.tar.gz + .sha256).
# Uso: ferramentas/empacotar.sh [PASTA_DE_SAIDA]   (padrão: ./dist)
# Tudo vem do próprio repositório: a base (base-fog/, FOG 1.5.10.2518 travado) e os componentes que ela baixaria da
# internet (terceiros/: sistema de clonagem, agente para Windows, iPXE). Nenhum link externo é necessário.
set -euo pipefail
RAIZ=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
SAIDA="${1:-$RAIZ/dist}"
VERSAO=$(cat "$RAIZ/VERSION")
NOME="LabFreeImageDeploy-$VERSAO"
[ -x "$RAIZ/base-fog/bin/installfog.sh" ] || { echo "Falta base-fog/ no repositório"; exit 1; }
# O .gitignore da própria base FOG ignora *Image*, *.gz, *.exe, languages/ e packages/tftp/: esses arquivos foram
# acrescentados ao git à força (git add -f). Um clone sem eles geraria um pacote que não instala o envio em grupo.
for f in packages/udpcast-20250223.tar.gz packages/systemd/FOGImageSize.service packages/systemd/FOGImageReplicator.service \
         packages/service/FOGImageSize/FOGImageSize packages/service/FOGImageReplicator/FOGImageReplicator \
         packages/web/service/ipxe/grub.exe packages/web/management/languages/pt_BR.UTF-8/LC_MESSAGES/messages.po; do
    [ -f "$RAIZ/base-fog/$f" ] || { echo "Falta base-fog/$f (ignorado pelo .gitignore da base: use git add -f)"; exit 1; }
done
for h in "$RAIZ"/terceiros/fos/*.sha256 "$RAIZ"/terceiros/fog-client/*.sha256 "$RAIZ"/terceiros/fog-ipxe/*.sha256; do
    (cd "$(dirname "$h")" && sha256sum --quiet -c "$(basename "$h")") || { echo "terceiros/: $(basename "$h" .sha256) corrompido"; exit 1; }
done
# código-fonte do FOS (GPL): precisa ir junto com os binários; o kernel está em partes (limite do GitHub)
F="$RAIZ/terceiros/fos-fonte"
for h in "$F"/fos-*-fonte.tar.gz.sha256 "$F"/buildroot-*.tar.xz.sha256; do
    [ -f "$h" ] && (cd "$F" && sha256sum --quiet -c "$(basename "$h")") || { echo "terceiros/fos-fonte: $(basename "$h" .sha256) ausente ou corrompido"; exit 1; }
done
for h in "$F"/linux-*.tar.xz.sha256; do
    [ -f "$h" ] || { echo "terceiros/fos-fonte: falta o código do kernel"; exit 1; }
    k=$(basename "$h" .sha256)
    [ "$(cat "$F/$k".parte-* | sha256sum | cut -d' ' -f1)" = "$(cut -d' ' -f1 "$h")" ] || { echo "terceiros/fos-fonte: $k corrompido"; exit 1; }
done
# pacotes do Buildroot, código do compilador da Bootlin e firmware embutido no kernel (conteudo.sha256; > 45 MB em partes)
[ -f "$F/conteudo.sha256" ] || { echo "terceiros/fos-fonte: falta conteudo.sha256"; exit 1; }
while read -r soma arq; do
    if [ -f "$F/$arq" ]; then s=$(sha256sum < "$F/$arq"); else s=$(cat "$F/$arq".parte-* 2>/dev/null | sha256sum); fi
    [ "${s%% *}" = "$soma" ] || { echo "terceiros/fos-fonte: $arq ausente ou corrompido"; exit 1; }
done < "$F/conteudo.sha256"

tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
D="$tmp/$NOME"
mkdir -p "$D"
rsync -a --exclude '.git' --exclude 'dist' --exclude 'testes' --exclude 'base-fog/tmp' --exclude 'base-fog/bin/error_logs' --exclude '__pycache__' --exclude 'CLAUDE.md' --exclude 'docs/CUSTOMIZACOES-MAQUINA-ATUAL.md' "$RAIZ/" "$D/"
cp "$RAIZ/base-fog/LICENSE" "$D/LICENSE"
# confere que nenhum segredo desta máquina entrou no pacote
if [ -r /opt/fog/.fogsettings ]; then
    # shellcheck disable=SC1091
    segredos=$( . /opt/fog/.fogsettings; printf '%s\n' "${password:-}" "${snmysqlpass:-}" | grep -v '^$' || true)
    while read -r s; do
        [ -n "$s" ] && grep -rqF -- "$s" "$D" && { echo "ERRO: uma senha desta instalação apareceu no pacote; abortando."; exit 1; }
    done <<< "$segredos"
fi
mkdir -p "$SAIDA"
tar -C "$tmp" -czf "$SAIDA/$NOME.tar.gz" "$NOME"
(cd "$SAIDA" && sha256sum "$NOME.tar.gz" > "$NOME.tar.gz.sha256")
echo "Pacote: $SAIDA/$NOME.tar.gz ($(du -h "$SAIDA/$NOME.tar.gz" | cut -f1))"
echo "SHA-256: $(cut -d' ' -f1 "$SAIDA/$NOME.tar.gz.sha256")"
