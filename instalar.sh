#!/bin/bash
# =============================================================================
#  LabFree ImageDeploy — instalador
#  Clonagem de máquinas pela rede (baseado no FOG Project, GPL v3).
#
#  Uso:   sudo ./instalar.sh                 (pergunta o que precisar)
#         sudo ./instalar.sh --ajuda         (todas as opções)
#
#  Instalação nova: configura a placa da rede de clonagem, instala a base
#  (FOG 1.5.10, embutida neste pacote) sem perguntas e aplica o LabFree.
#  Máquina que já tem o sistema: atualiza para esta versão sem reinstalar a base.
# =============================================================================
set -Eeuo pipefail

ORIGEM=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
DESTINO=/opt/LabFreeImageDeploy
VERSAO=$(cat "$ORIGEM/VERSION" 2>/dev/null || echo dev)
LOG=/var/log/LabFreeImageDeploy/instalacao.log
AJUDANTE=/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root
BASE_OK=/var/lib/LabFreeImageDeploy/base-instalada   # só existe depois que a base FOG terminou de instalar
if [ "$(id -u)" -ne 0 ]; then echo "Rode como root: sudo ./instalar.sh"; exit 1; fi
mkdir -p /var/log/LabFreeImageDeploy

PLACA=""; IP=""; PREFIXO=""; FAIXA=""; SIM=0; SEM_IPXE=0; NOTEBOOK=auto; SENHA=""; FORCAR=0; MODO=""

# ------------------------------------------------------------------ aparência

if [ -t 1 ]; then N=$'\e[1m'; V=$'\e[32m'; A=$'\e[33m'; R=$'\e[31m'; C=$'\e[36m'; Z=$'\e[0m'; else N=; V=; A=; R=; C=; Z=; fi
titulo() { echo; echo "${N}${C}== $*${Z}"; echo "== $*" >>"$LOG"; }
ok()     { echo "  ${V}✔${Z} $*"; echo "  ok: $*" >>"$LOG"; }
aviso()  { echo "  ${A}!${Z} $*"; echo "  aviso: $*" >>"$LOG"; }
falha()  { echo; echo "  ${R}✘ $*${Z}"; echo "  ERRO: $*" >>"$LOG"; echo "  Detalhes em $LOG"; exit 1; }
passo()  { printf "  … %s" "$*"; echo "  … $*" >>"$LOG"; }
feito()  { printf "\r  ${V}✔${Z} %s\n" "$*"; }
rodar()  { "$@" >>"$LOG" 2>&1; }
trap 'falha "Erro inesperado na linha $LINENO (comando: $BASH_COMMAND)."' ERR

ajuda() {
    cat <<EOF
LabFree ImageDeploy $VERSAO — instalador

  sudo ./instalar.sh [opções]

Opções:
  --placa NOME        placa de rede (com cabo) que vai atender a rede de clonagem (ex.: enp3s0, enx00e0…)
  --ip IP             IP fixo do servidor nessa rede (padrão: 192.168.31.1)
  --mascara N         máscara em bits (padrão: 24 = 255.255.255.0)
  --faixa INI-FIM     faixa de IPs entregues às máquinas (padrão: .10 até o último)
  --senha SENHA       senha inicial do usuário "admin" (padrão: admin, com troca obrigatória no primeiro acesso)
  --notebook          impede suspender/hibernar e ignora a tampa (detectado sozinho em notebooks)
  --nao-notebook      não mexe nas opções de energia
  --sem-ipxe-marca    não recompila o iPXE com o nome do produto (economiza ~15 min)
  --sim               não pergunta nada (usa os padrões e as opções acima)
  --forcar            instala mesmo em sistema não testado
  --ajuda             mostra esta ajuda

Sistemas suportados: Ubuntu 22.04/24.04, Linux Mint 21/22, Debian 12 (x86_64), com NetworkManager.
Requisitos: uma placa de rede com cabo SÓ para a rede de clonagem (pode ser adaptador USB),
            internet durante a instalação, 4 GB de RAM e espaço em disco para as imagens.
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --placa) PLACA="${2:-}"; shift 2 ;;
        --ip) IP="${2:-}"; shift 2 ;;
        --mascara) PREFIXO="${2:-}"; shift 2 ;;
        --faixa) FAIXA="${2:-}"; shift 2 ;;
        --senha) SENHA="${2:-}"; shift 2 ;;
        --notebook) NOTEBOOK=sim; shift ;;
        --nao-notebook) NOTEBOOK=nao; shift ;;
        --sem-ipxe-marca) SEM_IPXE=1; shift ;;
        --sim|-y) SIM=1; shift ;;
        --forcar) FORCAR=1; shift ;;
        --ajuda|-h|--help) ajuda; exit 0 ;;
        *) echo "Opção desconhecida: $1"; echo; ajuda; exit 1 ;;
    esac
done

perguntar() { # perguntar "texto" padrão -> resposta em $RESP
    RESP="$2"
    [ "$SIM" = 1 ] && return 0
    read -r -p "  $1 [$2]: " RESP </dev/tty || RESP=""
    RESP="${RESP:-$2}"
}
confirmar() { # confirmar "texto" -> 0 sim / 1 não
    [ "$SIM" = 1 ] && return 0
    local r=""; read -r -p "  $1 [S/n]: " r </dev/tty || r=""
    [[ -z "$r" || "$r" =~ ^[SsYy] ]]
}
json() { python3 -I -c "import json,sys; d=json.load(sys.stdin); print($1)"; }
# chama o ajudante: falha com a mensagem dele se der erro (código de saída, "erro" ou saída que não é JSON)
ajudante() { # ajudante origem|instalado AÇÃO [json-de-entrada] -> saída JSON em $SAIDA
    local prog="$1" acao="$2" entrada="${3:-}" rc=0
    if [ "$prog" = origem ]; then
        SAIDA=$(printf '%s' "$entrada" | python3 -I "$ORIGEM/lib/LabFreeImageDeploy-root" "$acao" 2>>"$LOG") || rc=$?
    else
        SAIDA=$(printf '%s' "$entrada" | "$AJUDANTE" "$acao" 2>>"$LOG") || rc=$?
    fi
    echo "$acao: $SAIDA" >>"$LOG"
    local erro; erro=$(printf '%s' "$SAIDA" | python3 -I -c 'import json,sys
try:
    d = json.load(sys.stdin)
    print(d.get("erro", "") if isinstance(d, dict) else "resposta inesperada")
except Exception:
    print("resposta inválida do ajudante")' 2>/dev/null) || erro="resposta inválida do ajudante"
    [ -z "$erro" ] && [ "$rc" = 0 ] && return 0
    falha "${erro:-o ajudante terminou com erro ($rc) na ação $acao}"
}

json_rede() { python3 -I -c 'import json,sys; print(json.dumps({"interface": sys.argv[1], "ip": sys.argv[2], "prefixo": int(sys.argv[3])}))' "$PLACA" "$IP" "$PREFIXO"; }

# ------------------------------------------------------------------ 1. checagens

checagens() {
    { echo; echo "==================== instalação iniciada em $(date) ===================="; } >>"$LOG"
    titulo "LabFree ImageDeploy $VERSAO — instalação"
    if [ "$SIM" != 1 ] && ! { : </dev/tty; } 2>/dev/null; then
        falha "Sem terminal para responder às perguntas. Rode num terminal (ssh -t) ou use --sim com as opções (veja --ajuda)."
    fi
    [ "$(uname -m)" = "x86_64" ] || falha "Só computadores x86_64 (64 bits) são suportados."
    . /etc/os-release
    local suportado=0
    case "${ID}:${VERSION_ID:-}" in
        ubuntu:22.04|ubuntu:24.04|linuxmint:21*|linuxmint:22*|debian:12) suportado=1 ;;
    esac
    if [ $suportado = 1 ]; then ok "Sistema: ${PRETTY_NAME}"
    elif [ $FORCAR = 1 ]; then aviso "Sistema não testado (${PRETTY_NAME}); continuando por causa de --forcar"
    else falha "Sistema não testado: ${PRETTY_NAME}. Suportados: Ubuntu 22.04/24.04, Mint 21/22, Debian 12 (ou use --forcar)."; fi
    systemctl is-active --quiet NetworkManager || falha "O NetworkManager precisa estar ativo (ele gerencia a placa da rede de clonagem)."
    ok "NetworkManager ativo"
    local mem; mem=$(awk '/MemTotal/ {print int($2/1024/1024+0.5)}' /proc/meminfo)
    [ "$mem" -ge 3 ] || aviso "Só ${mem} GB de RAM; recomendado 4 GB ou mais."
    local livre; livre=$(df -BG --output=avail / | tail -1 | tr -dc 0-9)
    [ "$livre" -ge 40 ] && ok "Espaço livre: ${livre} GB" || aviso "Só ${livre} GB livres em /. Cada imagem ocupa de 10 a 60 GB."
    # base pronta = marcador desta versão, ou (instalações antigas) site + banco do FOG já criados.
    # Uma instalação da base que falhou no meio não conta: rodar de novo continua a instalação nova.
    if [ ! -e "$BASE_OK" ] && [ -f /var/www/html/fog/lib/fog/config.class.php ] \
       && mysql -N fog -e 'SELECT 1 FROM users LIMIT 1' >/dev/null 2>&1 && mysql -N fog -e 'SELECT 1 FROM globalSettings LIMIT 1' >/dev/null 2>&1; then
        mkdir -p "$(dirname "$BASE_OK")"; date > "$BASE_OK"
    fi
    if [ -e "$BASE_OK" ]; then
        MODO=atualizar
        ok "Sistema já instalado nesta máquina: será ATUALIZADO para a versão $VERSAO (imagens, máquinas e configurações são mantidas)"
    else
        MODO=nova
        [ -x "$ORIGEM/base-fog/bin/installfog.sh" ] || falha "Pacote incompleto: falta a pasta base-fog. Baixe o pacote completo."
        [ -d "$ORIGEM/terceiros/fos" ] || falha "Pacote incompleto: falta a pasta terceiros. Baixe o pacote completo."
        [ -f /opt/fog/.fogsettings ] && aviso "Uma instalação anterior da base não terminou: ela será retomada."
        curl -fsS --max-time 15 -o /dev/null http://archive.ubuntu.com/ 2>/dev/null || curl -fsS --max-time 15 -o /dev/null http://deb.debian.org/ 2>/dev/null \
            || falha "Sem internet. A instalação precisa baixar pacotes do sistema."
        ok "Internet disponível"
    fi
}

# ------------------------------------------------------------------ 2. rede de clonagem

escolher_rede() {
    titulo "Rede de clonagem"
    ajudante origem rede-listar; local lista="$SAIDA"
    local candidatas; candidatas=$(echo "$lista" | json "'\n'.join(p['nome'] for p in d['placas'] if p['pode_usar'])")
    echo "  Placas de rede encontradas:"
    echo "$lista" | python3 -I -c '
import json,sys
for p in json.load(sys.stdin)["placas"]:
    tipo = "Wi-Fi" if p["tipo"] == "wifi" else ("USB" if p["usb"] else "cabo")
    obs = "leva a INTERNET (não pode)" if p["internet"] else ("Wi-Fi não serve" if p["tipo"] == "wifi" else ("cabo conectado" if p["cabo"] else "sem cabo"))
    print("    - %-18s %-6s %s  %-20s %s" % (p["nome"], tipo, p["mac"], ", ".join(p["ips"]) or "sem IP", obs))'
    [ -n "$candidatas" ] || falha "Nenhuma placa com cabo disponível. A rede de clonagem precisa de uma placa só para ela (pode ser um adaptador USB-Ethernet). A placa que leva a internet não pode ser usada."
    if [ -z "$PLACA" ]; then
        local primeira; primeira=$(echo "$candidatas" | head -1)
        if [ "$(echo "$candidatas" | wc -l)" -gt 1 ]; then
            perguntar "Qual placa vai atender a rede de clonagem? ($(echo $candidatas | tr ' ' '/'))" "$primeira"
            PLACA="$RESP"
        else
            PLACA="$primeira"
        fi
    fi
    grep -qxF -- "$PLACA" <<<"$candidatas" || falha "A placa \"$PLACA\" não pode ser usada (não existe, é Wi-Fi ou leva a internet)."
    ok "Placa: $PLACA"
    [ -n "$IP" ] || { perguntar "IP fixo do servidor nessa rede" "192.168.31.1"; IP="$RESP"; }
    [ -n "$PREFIXO" ] || PREFIXO=24
    [[ "$PREFIXO" =~ ^[0-9]{1,2}$ ]] || falha "Máscara inválida: $PREFIXO (use um número, ex.: 24)."
    ajudante origem rede-simular "$(json_rede)"
    ok "Endereço do servidor: $IP/$PREFIXO"
    # faixa: a mesma regra do ajudante (nunca inclui o IP do servidor); a informada em --faixa é conferida
    FAIXA=$(python3 -I "$ORIGEM/lib/faixa.py" "$ORIGEM/lib/LabFreeImageDeploy-root" "$IP/$PREFIXO" "$FAIXA")
    [[ "$FAIXA" == ERRO:* ]] && falha "Faixa de IPs: ${FAIXA#ERRO: }"
    ok "Faixa de IPs para as máquinas: $FAIXA"
}

# ------------------------------------------------------------------ 3. confirmação

confirmar_tudo() {
    titulo "Resumo"
    if [ "$MODO" = nova ]; then
        echo "  Instalação NOVA do LabFree ImageDeploy $VERSAO"
        echo "  - Placa da rede de clonagem: $PLACA (IP fixo $IP/$PREFIXO, sem gateway, sem DNS)"
        echo "  - DHCP para as máquinas: $FAIXA"
        echo "  - A internet desta máquina (outra placa) não é alterada"
        echo "  - Leva de 20 a 40 minutos"
    else
        echo "  ATUALIZAÇÃO para o LabFree ImageDeploy $VERSAO (nada é apagado)"
    fi
    echo
    confirmar "Continuar?" || { echo "  Cancelado."; exit 0; }
}

# ------------------------------------------------------------------ 4. arquivos do LabFree

instalar_arquivos() {
    titulo "Instalando o LabFree ImageDeploy"
    passo "pacotes necessários"
    rodar apt-get update || aviso "o apt-get update reclamou de algum repositório (veja $LOG); continuando com o que já está disponível"
    rodar env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        python3 curl rsync chrony whiptail git xz-utils sbsigntool sudo cifs-utils exfatprogs ntfs-3g zstd
    feito "pacotes necessários"
    if [ "$ORIGEM" != "$DESTINO" ]; then
        if [ -e "$DESTINO/.git" ]; then
            # pasta de desenvolvimento (repositório): nunca sobrescrever nem apagar
            aviso "$DESTINO é um repositório de desenvolvimento; a cópia do pacote não foi gravada lá"
        else
            mkdir -p "$DESTINO"
            rsync -a --delete "$ORIGEM/" "$DESTINO/"
        fi
    fi
    install -d -m 755 /usr/local/lib/LabFreeImageDeploy /usr/local/share/LabFreeImageDeploy /etc/LabFreeImageDeploy /var/log/LabFreeImageDeploy
    install -d -m 700 /var/lib/LabFreeImageDeploy /var/lib/LabFreeImageDeploy/historico /var/backups/LabFreeImageDeploy
    install -m 755 "$ORIGEM/lib/LabFreeImageDeploy-root" "$AJUDANTE"
    install -m 755 "$ORIGEM/lib/LabFreeImageDeploy-udp-sender" /usr/local/lib/LabFreeImageDeploy/udp-sender  # porteiro do envio em grupo
    install -m 755 "$ORIGEM/lib/LabFreeImageDeploy-otimizar" /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-otimizar
    install -m 755 "$ORIGEM/lib/LabFreeImageDeploy-copias" /usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-copias
    rsync -a --delete "$ORIGEM/marca/" /usr/local/share/LabFreeImageDeploy/
    echo "$VERSAO" > /etc/LabFreeImageDeploy/versao
    for f in "$ORIGEM"/sbin/LabFreeImageDeploy*; do install -m 750 "$f" /usr/local/sbin/; done
    install -m 750 "$ORIGEM/ferramentas/LabFreeImageDeploy-recompilar-ipxe" /usr/local/sbin/LabFreeImageDeploy-recompilar-ipxe
    ok "programas instalados (comando: sudo LabFreeImageDeploy)"
}

arquivar() { # guarda um arquivo/pasta antigo em /var/lib/LabFreeImageDeploy/migracao/<caminho original>
    local d
    d="/var/lib/LabFreeImageDeploy/migracao$(dirname "$1")"
    mkdir -p "$d"; rm -rf -- "${d:?}/$(basename "$1")"; mv -f "$1" "$d/"
}

migrar_nomes_antigos() {
    # Versões de desenvolvimento usaram os nomes "FOG Fácil" (fogfacil, fog-*) e "labfree".
    local houve=0 velho conf
    for conf in /etc/labfree/labfree.conf /etc/fogfacil/fogfacil.conf; do
        if [ -s "$conf" ] && ! grep -q '^FOG_IFACE=.\+' /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf 2>/dev/null; then
            cp -a "$conf" /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf; houve=1
        fi
    done
    for conf in /etc/labfree/dhcp.json /etc/fogfacil/dhcp.json; do
        [ -s "$conf" ] && [ ! -s /etc/LabFreeImageDeploy/dhcp.json ] && cp -a "$conf" /etc/LabFreeImageDeploy/dhcp.json && houve=1
    done
    # estado (histórico de mudanças, carimbos da marca, backups do boot, compilação do iPXE)
    if [ -d /var/lib/labfree ]; then
        rsync -a /var/lib/labfree/ /var/lib/LabFreeImageDeploy/ && rm -rf /var/lib/labfree; houve=1
    fi
    for velho in /etc/sudoers.d/fogfacil /etc/sudoers.d/labfree \
                 /etc/NetworkManager/dispatcher.d/90-kea-fog /etc/NetworkManager/dispatcher.d/90-labfree-kea \
                 /etc/NetworkManager/conf.d/90-fogfacil-ignorar-carrier.conf /etc/NetworkManager/conf.d/90-labfree-ignorar-carrier.conf \
                 /etc/systemd/system/kea-dhcp4-server.service.d/10-fog-usb-nic.conf /etc/systemd/system/kea-dhcp4-server.service.d/10-labfree.conf \
                 /etc/udev/rules.d/90-fog-usb-nic-nopm.rules /etc/udev/rules.d/90-labfree-usb-nic-nopm.rules \
                 /etc/chrony/conf.d/fog.conf /etc/chrony/conf.d/labfree.conf \
                 /etc/apt/apt.conf.d/99-fog-protecao /etc/apt/apt.conf.d/99-fog-sem-atualizacao-automatica \
                 /etc/apt/apt.conf.d/99-labfree-protecao /etc/apt/apt.conf.d/99-labfree-sem-atualizacao-automatica \
                 /etc/systemd/logind.conf.d/90-fog-servidor.conf /etc/systemd/sleep.conf.d/90-fog-servidor.conf \
                 /etc/systemd/logind.conf.d/90-labfree.conf /etc/systemd/sleep.conf.d/90-labfree.conf \
                 /usr/local/lib/fogfacil /usr/local/lib/labfree /usr/local/share/labfree /etc/fogfacil /etc/labfree \
                 /var/www/html/painel /var/www/html/fog/service/ipxe/labfree-bg.png; do
        if [ -e "$velho" ]; then arquivar "$velho"; houve=1; fi
    done
    for unidade in fog-backup labfree-backup; do
        if [ -e "/etc/systemd/system/$unidade.timer" ]; then
            rodar systemctl disable --now "$unidade.timer" || true
            arquivar "/etc/systemd/system/$unidade.timer"
            [ -e "/etc/systemd/system/$unidade.service" ] && arquivar "/etc/systemd/system/$unidade.service"
            houve=1
        fi
    done
    if [ -e /etc/apache2/conf-available/labfree-imagedeploy.conf ]; then
        rodar a2disconf -q labfree-imagedeploy || true
        arquivar /etc/apache2/conf-available/labfree-imagedeploy.conf; houve=1
    fi
    sed -i '/^# labfree-inicio/,/^# labfree-fim/d' /etc/hosts
    for dir in /root/backups-fog /root/backups-labfree; do
        [ -d "$dir" ] || continue
        for b in "$dir"/*.tar.gz; do [ -e "$b" ] && mv -n "$b" "/var/backups/LabFreeImageDeploy/LabFreeImageDeploy-$(basename "$b" | sed -E 's/^(fog|labfree)-//')"; done
        rmdir "$dir" 2>/dev/null || true; houve=1
    done
    for log in /var/log/fogfacil.log /var/log/fog-verificar.log /var/log/labfree.log /var/log/labfree-verificar.log /var/log/labfree-instalacao.log; do
        [ -e "$log" ] && mkdir -p /var/log/LabFreeImageDeploy/antigos && mv -f "$log" /var/log/LabFreeImageDeploy/antigos/ && houve=1
    done
    # comandos antigos viram atalhos para os novos
    local par v n
    for par in fog-verificar:LabFreeImageDeploy-verificar fog-backup:LabFreeImageDeploy-backup fog-restaurar-backup:LabFreeImageDeploy-restaurar-backup \
               fog-reaplicar-customizacoes:LabFreeImageDeploy-reaplicar fog-reaplicar-kea:LabFreeImageDeploy-reaplicar \
               labfree-verificar:LabFreeImageDeploy-verificar labfree-backup:LabFreeImageDeploy-backup labfree-restaurar-backup:LabFreeImageDeploy-restaurar-backup \
               labfree-reaplicar:LabFreeImageDeploy-reaplicar labfree-senha:LabFreeImageDeploy-senha labfree-recompilar-ipxe:LabFreeImageDeploy-recompilar-ipxe; do
        v="${par%%:*}"; n="${par##*:}"
        if { [ -e "/usr/local/sbin/$v" ] || [ -L "/usr/local/sbin/$v" ]; } && [ "$(readlink "/usr/local/sbin/$v")" != "$n" ]; then
            ln -sfn "$n" "/usr/local/sbin/$v"; houve=1
        fi
    done
    [ -L /opt/fogfacil ] && rm -f /opt/fogfacil
    if [ $houve = 1 ]; then ok "nomes antigos (FOG Fácil / labfree) migrados para LabFreeImageDeploy (cópias em /var/lib/LabFreeImageDeploy/migracao)"; fi
    return 0
}

# ------------------------------------------------------------------ 5. rede + base

configurar_rede() {
    if [ "$MODO" = atualizar ] && [ -s /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf ] && grep -q '^FOG_IFACE=.\+' /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf; then
        ok "rede de clonagem já configurada ($(. /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf; echo "$FOG_IFACE, $FOG_IP"))"
        return
    fi
    passo "configurando a placa $PLACA com $IP/$PREFIXO"
    ajudante instalado rede-aplicar "$(json_rede)"
    feito "placa $PLACA configurada com $IP/$PREFIXO"
    python3 -I -c 'import json,sys; ini,fim=sys.argv[1].split("-"); print(json.dumps({"ativo": True, "inicio": ini, "fim": fim, "gateway": "", "dns": [], "dominio": "", "concessao_horas": 6, "reservas": []}))' \
        "$FAIXA" > /etc/LabFreeImageDeploy/dhcp.json
}

# Tudo o que a base baixaria da internet (sistema de clonagem, agente para Windows, iPXE) vem no pacote, na pasta
# terceiros/, conferido pelo SHA-256: a instalação não depende de nenhum link do projeto FOG (sistema autônomo).
preparar_terceiros() { # $1 = pasta base-fog que será usada
    local b="$1" t="$ORIGEM/terceiros" d tmpd
    [ -d "$t/fos" ] || falha "Pacote incompleto: falta a pasta terceiros. Baixe o pacote completo."
    mkdir -p "$b/tmp"
    cp -f "$t"/fos/* "$t"/fog-client/* "$t"/fog-ipxe/*.tar.gz* "$b/tmp/"
    ( cd "$b/tmp" && for h in "$t"/fos/*.sha256 "$t"/fog-client/*.sha256 "$t"/fog-ipxe/*.sha256; do sha256sum --quiet -c "$(basename "$h")" || exit 1; done ) >>"$LOG" 2>&1 \
        || falha "Arquivos do pacote estão corrompidos (SHA-256 não confere). Baixe o pacote de novo."
    # fontes do iPXE: a base compila o boot (HTTPS) e o LabFree recompila com o nome do produto, sem baixar nada
    if [ ! -x /opt/fog/ipxe/buildipxe.sh ]; then
        mkdir -p /opt/fog/ipxe && cp -a "$t/fog-ipxe/fonte/." /opt/fog/ipxe/
    fi
    for d in ipxe ipxe-efi; do
        [ -d "/opt/fog/ipxe/build/$d/.git" ] && continue
        tmpd=$(mktemp -d)
        tar -xzf "$t/ipxe/ipxe-v2.0.0-fonte.tar.gz" -C "$tmpd" || falha "Não foi possível abrir o código do iPXE do pacote."
        mkdir -p /opt/fog/ipxe/build && rm -rf "/opt/fog/ipxe/build/$d" && mv "$tmpd/ipxe" "/opt/fog/ipxe/build/$d" && rmdir "$tmpd"
        # o script de compilação da base espera um repositório git com a versão marcada (v2.0.0)
        ( cd "/opt/fog/ipxe/build/$d" && git init -q && git add -A && git -c user.name=LabFree -c user.email=labfree@localhost commit -qm "iPXE v2.0.0 (pacote LabFree ImageDeploy)" && git tag v2.0.0 ) >>"$LOG" 2>&1 \
            || falha "Não foi possível preparar o código do iPXE."
    done
    ok "componentes da base vindos do pacote (nada é baixado do projeto FOG)"
}

instalar_base() {
    [ "$MODO" = nova ] || return 0
    titulo "Instalando a base (FOG 1.5.10) — demora de 15 a 30 minutos"
    local mascara ini="${FAIXA%-*}" fim="${FAIXA#*-}"
    mascara=$(python3 -I -c "import ipaddress; print(ipaddress.ip_network('$IP/$PREFIXO', strict=False).netmask)")
    mkdir -p /opt/fog
    # Respostas prontas: o instalador do FOG não pergunta nada que já esteja aqui. Se uma instalação anterior
    # parou no meio, o arquivo dela é mantido (tem a senha do banco que o FOG já criou) e só estas chaves mudam.
    local respostas; respostas=$(mktemp)
    cat > "$respostas" <<EOF
## LabFree ImageDeploy: respostas pré-preenchidas para a instalação da base
ipaddress='$IP'
ipaddresses='$IP'
interface='$PLACA'
submask='$mascara'
hostname='$(hostname)'
routeraddress='#   No router address added'
plainrouter=''
dnsaddress='#   No dns added'
username='fogproject'
dodhcp='y'
bldhcp='1'
dhcpengine='kea'
blexports='1'
installtype='N'
installlang='0'
storageLocation='/images'
docroot='/var/www/html/'
webroot='/fog/'
dohttps='yes'
shttpproto='https'
startrange='$ini'
endrange='$fim'
sendreports='N'
EOF
    if [ -s /opt/fog/.fogsettings ]; then
        cp -a /opt/fog/.fogsettings "/opt/fog/.fogsettings.bak-$(date +%Y%m%d-%H%M%S)"
        python3 -I "$ORIGEM/lib/mesclar-fogsettings.py" "$respostas" /opt/fog/.fogsettings
        rm -f "$respostas"
    else
        mv -f "$respostas" /opt/fog/.fogsettings
    fi
    chmod 600 /opt/fog/.fogsettings
    echo "  (o andamento detalhado fica em $LOG)"
    # o instalador do FOG não aceita espaços no caminho (ex.: "Área de Trabalho"): usa a cópia em /opt
    local base="$ORIGEM/base-fog/bin"
    if [[ "$base" == *[[:space:]]* ]]; then
        base="$DESTINO/base-fog/bin"
        [ -x "$base/installfog.sh" ] || falha "Extraia o pacote numa pasta sem espaços no nome (ex.: /root/LabFreeImageDeploy) e rode de novo."
    fi
    preparar_terceiros "$(dirname "$base")"
    ( cd "$base" && ./installfog.sh -y ) >>"$LOG" 2>&1 || falha "A instalação da base falhou. Veja o final de $LOG. Rodar sudo ./instalar.sh de novo continua de onde parou."
    [ -d /var/www/html/fog/lib/fog ] || falha "A base não foi instalada corretamente (falta /var/www/html/fog)."
    date > "$BASE_OK"
    ok "base instalada"
}

# ------------------------------------------------------------------ 6. camada LabFree

aplicar_camada() {
    titulo "Aplicando o LabFree ImageDeploy"
    mkdir -p /var/www/html/LabFreeImageDeploy
    rsync -a --delete "$ORIGEM/web/" /var/www/html/LabFreeImageDeploy/
    chown -R root:root /var/www/html/LabFreeImageDeploy; chmod -R u=rwX,go=rX /var/www/html/LabFreeImageDeploy
    install -m 644 -o www-data -g www-data "$ORIGEM/sistema/raiz-index.php" /var/www/html/index.php
    [ -f /var/www/html/index.html ] && mv -f /var/www/html/index.html /var/lib/LabFreeImageDeploy/index.html.apache-padrao || true
    ok "painel em https://$(. /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf; echo "$FOG_IP")/"
    # sudoers: o painel (www-data) só pode executar o ajudante, que valida tudo
    local tmp; tmp=$(mktemp)
    cp "$ORIGEM/sistema/sudoers/LabFreeImageDeploy" "$tmp"
    visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; falha "Regra de sudo inválida (não instalada)."; }
    install -m 440 -o root -g root "$tmp" /etc/sudoers.d/LabFreeImageDeploy; rm -f "$tmp"
    visudo -c >/dev/null || falha "A configuração do sudo ficou inválida; verifique /etc/sudoers.d/LabFreeImageDeploy."
    ok "permissão do painel para configurar rede e DHCP"
    install -m 755 "$ORIGEM/sistema/networkmanager/90-LabFreeImageDeploy-kea" /etc/NetworkManager/dispatcher.d/90-LabFreeImageDeploy-kea
    install -m 644 "$ORIGEM/sistema/systemd/LabFreeImageDeploy-backup.service" "$ORIGEM/sistema/systemd/LabFreeImageDeploy-backup.timer" /etc/systemd/system/
    install -m 644 "$ORIGEM/sistema/apt/99-LabFreeImageDeploy-protecao" "$ORIGEM/sistema/apt/99-LabFreeImageDeploy-sem-atualizacao-automatica" /etc/apt/apt.conf.d/
    install -m 644 "$ORIGEM/sistema/logrotate/LabFreeImageDeploy" /etc/logrotate.d/LabFreeImageDeploy
    rodar systemctl daemon-reload
    rodar systemctl enable --now LabFreeImageDeploy-backup.timer
    install -m 644 "$ORIGEM/sistema/systemd/LabFreeImageDeploy-recuperar.service" /etc/systemd/system/
    install -m 644 "$ORIGEM/sistema/systemd/LabFreeImageDeploy-otimizar.service" "$ORIGEM/sistema/systemd/LabFreeImageDeploy-otimizar.timer" /etc/systemd/system/
    rodar systemctl daemon-reload
    rodar systemctl enable --now LabFreeImageDeploy-otimizar.timer   # imagens menores e conferidas, com o servidor parado
    rodar systemctl enable LabFreeImageDeploy-recuperar.service   # desfaz no boot uma troca de rede interrompida
    ok "backup semanal, proteção contra o apt e atualizações automáticas desligadas"
    # PHP travado na versão em uso (o PPA ondrej trocaria de versão sozinho)
    local metas; metas=$(dpkg-query -W -f='${Package} ${Version}\n' php php-cli php-fpm libapache2-mod-php php-bcmath php-curl php-gd php-intl php-json php-ldap php-mbstring php-mysql 2>/dev/null | awk '$2 ~ /^2:/ {print $1}' || true)
    if [ -n "$metas" ]; then rodar apt-mark hold $metas && ok "PHP travado na versão atual"; fi
    # energia (notebook)
    if [ "$NOTEBOOK" = auto ]; then
        case "$(cat /sys/class/dmi/id/chassis_type 2>/dev/null)" in 8|9|10|14|31|32) NOTEBOOK=sim ;; *) NOTEBOOK=nao ;; esac
    fi
    if [ "$NOTEBOOK" = sim ]; then
        install -d /etc/systemd/logind.conf.d /etc/systemd/sleep.conf.d
        install -m 644 "$ORIGEM/sistema/energia/logind-90-LabFreeImageDeploy.conf" /etc/systemd/logind.conf.d/90-LabFreeImageDeploy.conf
        install -m 644 "$ORIGEM/sistema/energia/sleep-90-LabFreeImageDeploy.conf" /etc/systemd/sleep.conf.d/90-LabFreeImageDeploy.conf
        rodar systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target suspend-then-hibernate.target
        rodar systemctl reload systemd-logind || true
        ok "notebook: não suspende nem hiberna (nem ao fechar a tampa)"
    fi
    passo "DHCP, boot pela rede, marca e proteções (inclui reconstruir o sistema de clonagem: até 10 min)"
    ajudante instalado reaplicar
    feito "DHCP, boot pela rede, marca e proteções aplicados"
}

marca_ipxe() {
    [ "$SEM_IPXE" = 1 ] && { aviso "iPXE mantido sem o nome do produto (--sem-ipxe-marca)"; return; }
    if grep -aq "LabFree ImageDeploy" /tftpboot/snponly.efi 2>/dev/null; then ok "boot pela rede já mostra o nome LabFree ImageDeploy"; return; fi
    passo "recompilando o iPXE com o nome do produto (10 a 20 min)"
    if /usr/local/sbin/LabFreeImageDeploy-recompilar-ipxe compilar >>"$LOG" 2>&1 && /usr/local/sbin/LabFreeImageDeploy-recompilar-ipxe instalar >>"$LOG" 2>&1; then
        feito "iPXE com o nome LabFree ImageDeploy"
    else
        echo; aviso "não foi possível recompilar o iPXE; o boot continua funcionando com o iPXE padrão (detalhes em $LOG)"
    fi
}

usuario_admin() {
    # Instalação nova: usuário "admin" com senha "admin" (ou --senha); o painel exige trocar no primeiro acesso.
    # Em atualização só age se o admin nunca foi criado (instalação anterior parou depois da base).
    if [ "$MODO" != nova ]; then
        [ "$(mysql -N fog -e "select count(*) from users where uName='admin'" 2>/dev/null)" = 0 ] \
            && [ "$(mysql -N fog -e "select count(*) from users where uName='fog'" 2>/dev/null)" = 1 ] || return 0
    fi
    titulo "Usuário do painel"
    local senha="${SENHA:-admin}" hash
    hash=$(SENHA="$senha" php -r 'echo password_hash(getenv("SENHA"), PASSWORD_BCRYPT, ["cost" => 11]);')
    if [ "$(mysql -N fog -e "select count(*) from users where uName='admin'")" = 0 ]; then
        mysql fog -e "update users set uName='admin', uPass='$hash' where uName='fog'"
    else
        mysql fog -e "update users set uPass='$hash' where uName='admin'"
    fi
    [ "$(mysql -N fog -e "select count(*) from users where uName='admin'")" = 1 ] \
        || falha "Não encontrei o usuário inicial da base para criar o admin. Crie com: sudo LabFreeImageDeploy senha admin"
    if [ -z "$SENHA" ]; then ok "usuário admin, senha admin (o painel pede para trocar no primeiro acesso)"; else ok "usuário admin com a senha informada"; fi
}

final() {
    titulo "Conferência final"
    /usr/local/sbin/LabFreeImageDeploy-verificar | tail -3
    . /etc/LabFreeImageDeploy/LabFreeImageDeploy.conf
    local outros; outros=$(ip -4 -o addr show scope global | awk -v f="$FOG_IFACE" '$2 != f {sub(/\/.*/, "", $4); print "https://" $4 "/"}' | tr '\n' ' ')
    echo
    echo "${N}${V}  LabFree ImageDeploy $VERSAO pronto!${Z}"
    echo
    echo "  Painel:    https://$FOG_IP/   (na rede de clonagem)"
    [ -n "$outros" ] && echo "             $outros (de outras redes deste servidor)"
    if [ "$MODO" = nova ]; then
        if [ -z "$SENHA" ]; then
            echo "  Usuário:   admin      Senha: admin   (será pedida uma senha nova no primeiro acesso)"
        else
            echo "  Usuário:   admin      Senha: a informada em --senha"
        fi
        echo
        echo "  O navegador vai avisar que o certificado não é reconhecido: isso é esperado"
        echo "  (o certificado é deste servidor). Clique em Avançado → Continuar."
    fi
    echo
    echo "  Ligue o cabo da placa $FOG_IFACE no switch da rede de clonagem e dê boot pela rede nas máquinas."
    echo "  Administração no servidor: sudo LabFreeImageDeploy  (verificar, backup, senha, ...)"
    echo "  Registro desta instalação: $LOG"
}

# ------------------------------------------------------------------ execução

checagens
if [ "$MODO" = nova ]; then escolher_rede; fi
confirmar_tudo
instalar_arquivos
migrar_nomes_antigos
configurar_rede
instalar_base
aplicar_camada
marca_ipxe
usuario_admin
final
