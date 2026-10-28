#!/bin/bash
# =============================================================================
#  LabFree ImageDeploy — publica uma versão nova no GitHub (repositório privado).
#
#  Uso:   ferramentas/publicar.sh               confere, faz o commit, marca a versão (tag vX.Y.Z), envia e
#                                               SEMPRE lança uma release no GitHub com as notas do CHANGELOG e o
#                                               pacote de instalação (.tar.gz + .sha256)
#         ferramentas/publicar.sh --sem-pacote  o mesmo, mas a release sai só com as notas (sem o pacote)
#  Se a versão já foi enviada mas a release falhou (internet caiu, por exemplo), rodar de novo só lança a release.
#  Precisa do gh com acesso ao repositório (variável GH_TOKEN ou gh auth login).
#
#  PUBLICAR_RODAPE="linhas"   acrescenta essas linhas no fim da mensagem do commit (ex.: coautoria).
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
PACOTE=1
case "${1:-}" in --sem-pacote) PACOTE=0 ;; --pacote|"") ;; *) echo "opção desconhecida: $1" >&2; exit 1 ;; esac
erro() { echo "ERRO: $*" >&2; exit 1; }

VERSAO=$(tr -d '[:space:]' < VERSION)
[[ "$VERSAO" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]] || erro "VERSION inválida: '$VERSAO' (use X.Y.Z)"
TAG="v$VERSAO"
grep -qF "## [$VERSAO]" CHANGELOG.md || erro "CHANGELOG.md não tem a seção '## [$VERSAO]' descrevendo esta versão."
command -v gh >/dev/null || erro "falta o programa gh (GitHub CLI) para lançar a release."
NOTAS=$(awk -v v="## [$VERSAO]" 'index($0, v) == 1 {ok = 1; next} ok && /^## \[/ {exit} ok' CHANGELOG.md)

lancar_release() {
    echo "== Release $TAG no GitHub"
    local arquivos=()
    if [ $PACOTE = 1 ]; then
        ferramentas/empacotar.sh
        arquivos=("dist/LabFreeImageDeploy-$VERSAO.tar.gz" "dist/LabFreeImageDeploy-$VERSAO.tar.gz.sha256")
    fi
    gh release create "$TAG" "${arquivos[@]}" --verify-tag --title "LabFree ImageDeploy $VERSAO" --notes "$NOTAS" \
        || erro "a versão foi enviada, mas a release não. Rode ferramentas/publicar.sh de novo para lançá-la."
    echo "  release: $(gh release view "$TAG" --json url -q .url)"
}

if git rev-parse --verify -q "refs/tags/$TAG" >/dev/null; then
    gh release view "$TAG" >/dev/null 2>&1 && erro "a versão $VERSAO já foi publicada (com release). Aumente o número em VERSION e descreva a mudança no CHANGELOG.md."
    echo "A versão $TAG já foi enviada, mas falta a release: lançando só a release."
    lancar_release
    echo "Pronto: versão $VERSAO publicada."
    exit 0
fi

echo "== Conferindo sintaxe"
for f in instalar.sh sbin/* ferramentas/*.sh ferramentas/LabFreeImageDeploy-recompilar-ipxe sistema/networkmanager/* marca/fos/bin/*; do
    bash -n "$f" || erro "erro de sintaxe em $f"
done
python3 -m py_compile lib/LabFreeImageDeploy-root lib/LabFreeImageDeploy-udp-sender lib/LabFreeImageDeploy-otimizar lib/LabFreeImageDeploy-copias lib/*.py testes/*.py || erro "erro de sintaxe em Python"
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
git add -A
if git diff --cached --quiet; then
    echo "  (nada novo para commit; só marcando a versão)"
else
    git commit -q -F - <<MSG
LabFree ImageDeploy $VERSAO
$NOTAS
${PUBLICAR_RODAPE:+
$PUBLICAR_RODAPE}
MSG
fi
git push -q origin HEAD
git tag -a "$TAG" -m "LabFree ImageDeploy $VERSAO"   # só marca depois que o código subiu
git push -q origin "$TAG" || { git tag -d "$TAG" >/dev/null; erro "não consegui enviar a marca da versão; rode de novo."; }
echo "  enviado: $(git remote get-url origin) ($TAG)"

lancar_release
echo "Pronto: versão $VERSAO publicada."
