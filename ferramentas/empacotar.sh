#!/bin/bash
# LabFree ImageDeploy — gera o pacote de instalação (.tar.gz + .sha256).
# Uso: ferramentas/empacotar.sh [PASTA_DA_BASE_FOG] [PASTA_DE_SAIDA]
#   PASTA_DA_BASE_FOG: código do FOG 1.5.10 usado como base (padrão: /opt/fogproject-stable)
#   PASTA_DE_SAIDA:    onde gravar o pacote (padrão: ./dist)
# O pacote leva a base FOG embutida (versão travada, sem o histórico git e sem nada
# desta instalação: logs, senhas e relatórios ficam de fora).
set -euo pipefail
RAIZ=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
BASE="${1:-/opt/fogproject-stable}"
SAIDA="${2:-$RAIZ/dist}"
VERSAO=$(cat "$RAIZ/VERSION")
NOME="LabFreeImageDeploy-$VERSAO"
[ -x "$BASE/bin/installfog.sh" ] || { echo "Base FOG não encontrada em $BASE"; exit 1; }
grep -q "1.5.10" "$BASE/packages/web/lib/fog/system.class.php" || echo "AVISO: a base em $BASE não parece ser o FOG 1.5.10"

tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
D="$tmp/$NOME"
mkdir -p "$D"
rsync -a --exclude '.git' --exclude 'dist' --exclude 'testes' --exclude '__pycache__' --exclude 'CLAUDE.md' --exclude 'docs/CUSTOMIZACOES-MAQUINA-ATUAL.md' "$RAIZ/" "$D/"
rsync -a --exclude '.git' --exclude 'bin/error_logs' --exclude 'rpttmp' --exclude 'tests' --exclude 'CLAUDE.md' \
      --exclude 'tmp/*.sql' --exclude 'tmp/*.sql.*' --exclude 'tmp/ipxe*' "$BASE/" "$D/base-fog/"
cp "$BASE/LICENSE" "$D/LICENSE"
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
