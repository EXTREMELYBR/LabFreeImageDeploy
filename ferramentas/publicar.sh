#!/bin/bash
# =============================================================================
#  LabFree ImageDeploy — publica uma versão nova no GitHub (repositório privado).
#
#  Uso:   ferramentas/publicar.sh            confere, faz o commit, marca a versão (tag vX.Y.Z) e envia
#         ferramentas/publicar.sh --pacote   o mesmo e ainda anexa o pacote de instalação (dist/) a uma
#                                            "release" privada no GitHub
#
#  Regras (o script recusa publicar se não forem cumpridas):
#    1. VERSION mudou desde a última versão publicada;
#    2. CHANGELOG.md tem a seção "## [VERSÃO]" com o que mudou;
#    3. scripts sem erro de sintaxe (bash, Python, PHP);
#    4. nenhuma senha desta máquina dentro dos arquivos enviados.
# =============================================================================
set -Eeuo pipefail
RAIZ=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
cd "$RAIZ"
PACOTE=0; [ "${1:-}" = "--pacote" ] && PACOTE=1
erro() { echo "ERRO: $*" >&2; exit 1; }

VERSAO=$(tr -d '[:space:]' < VERSION)
[[ "$VERSAO" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]] || erro "VERSION inválida: '$VERSAO' (use X.Y.Z)"
TAG="v$VERSAO"
git rev-parse --verify -q "refs/tags/$TAG" >/dev/null && erro "a versão $VERSAO já foi publicada. Aumente o número em VERSION e descreva a mudança no CHANGELOG.md."
grep -qF "## [$VERSAO]" CHANGELOG.md || erro "CHANGELOG.md não tem a seção '## [$VERSAO]' descrevendo esta versão."

echo "== Conferindo sintaxe"
for f in instalar.sh sbin/* ferramentas/*.sh ferramentas/LabFreeImageDeploy-recompilar-ipxe sistema/networkmanager/* marca/fos/bin/*; do
    bash -n "$f" || erro "erro de sintaxe em $f"
done
python3 -m py_compile lib/LabFreeImageDeploy-root lib/*.py testes/*.py || erro "erro de sintaxe em Python"
find "$RAIZ" -name __pycache__ -prune -exec rm -rf {} +
if command -v php >/dev/null; then
    for f in web/*.php marca/servidor/*.php sistema/*.php; do php -l "$f" >/dev/null || erro "erro de sintaxe em $f"; done
fi

echo "== Procurando senhas desta máquina nos arquivos"
if [ -r /opt/fog/.fogsettings ]; then
    for chave in snmysqlpass password; do
        valor=$(grep -oP "^$chave='\K[^']+" /opt/fog/.fogsettings || true)
        [ -n "$valor" ] || continue
        if git ls-files -co --exclude-standard -z | xargs -0 /usr/bin/grep -lF -e "$valor" 2>/dev/null; then
            erro "a senha '$chave' do /opt/fog/.fogsettings aparece nos arquivos acima. Remova antes de publicar."
        fi
    done
fi
if git ls-files -co --exclude-standard -z | xargs -0 /usr/bin/grep -lE "BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY" 2>/dev/null; then
    erro "há chave privada nos arquivos acima."
fi

echo "== Commit e versão $TAG"
# o navegador busca app.js/app.css novos a cada versão (sem misturar arquivo velho com API nova)
sed -i -E "s/\?v=[0-9A-Za-z.-]+/?v=$VERSAO/g" web/index.html
NOTAS=$(awk -v v="## [$VERSAO]" 'index($0, v) == 1 {ok = 1; next} ok && /^## \[/ {exit} ok' CHANGELOG.md)
git add -A
if git diff --cached --quiet; then
    echo "  (nada novo para commit; só marcando a versão)"
else
    git commit -q -F - <<MSG
LabFree ImageDeploy $VERSAO
$NOTAS
MSG
fi
git push -q origin HEAD
git tag -a "$TAG" -m "LabFree ImageDeploy $VERSAO"   # só marca depois que o código subiu
git push -q origin "$TAG" || { git tag -d "$TAG" >/dev/null; erro "não consegui enviar a marca da versão; rode de novo."; }
echo "  enviado: $(git remote get-url origin) ($TAG)"

if [ $PACOTE = 1 ]; then
    echo "== Pacote de instalação"
    ferramentas/empacotar.sh
    gh release create "$TAG" "dist/LabFreeImageDeploy-$VERSAO.tar.gz" --title "LabFree ImageDeploy $VERSAO" --notes "$NOTAS"
fi
echo "Pronto: versão $VERSAO publicada."
