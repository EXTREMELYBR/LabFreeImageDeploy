#!/bin/bash
# Teste real do porteiro do envio em grupo (lib/LabFreeImageDeploy-udp-sender), sem root e sem tocar na rede:
# cria uma rede virtual (namespaces do usuário: ponte + 2 "máquinas" com udp-receiver), roda uma sessão como a base
# FOG roda (um `sh -c` com um udp-sender por partição; a 3ª em pedaços via `cat |`) e confere:
#   1. o envio NÃO começa sozinho (nem com --max-wait vencido);  2. começa com o pedido do painel (.iniciar);
#   3. as partições seguintes seguem sem clique;  4. os arquivos chegam iguais;  5. cancelar (como a base) não deixa
#   envio sobrando.
# Uso: testes/teste_grupo.sh [PASTA_DO_UDPCAST]   (padrão: o udpcast compilado pelo instalador da base)
set -u
U=${1:-/opt/fogproject-stable/tmp/udpcast-20250223}
W=$(cd "$(dirname "$0")/.." && pwd)/lib/LabFreeImageDeploy-udp-sender
[ -x "$U/udp-sender" ] && [ -x "$U/udp-receiver" ] || { echo "Falta udp-sender/udp-receiver em $U"; exit 1; }
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
mkdir -p "$T"/img "$T"/run "$T"/logs "$T"/r1 "$T"/r2
head -c 5000000 /dev/urandom > "$T"/img/d1p1.img; head -c 767 /dev/urandom > "$T"/img/d1p2.img
head -c 8000000 /dev/urandom > "$T"/img/d1p3.img.000; head -c 6000000 /dev/urandom > "$T"/img/d1p3.img.001

cat > "$T"/rede.sh <<EOF
set -u
ip link set lo up; ip link add br0 type bridge mcast_snooping 0; ip link set br0 up multicast on
ip addr add 10.9.9.1/24 brd + dev br0; ip route add 224.0.0.0/4 dev br0; ip route add default dev br0
maquina() { # \$1=nº \$2=partições a receber. Cada máquina no seu namespace (duas na mesma disputariam a porta).
  unshare -n bash -c "while ! ip link show c\$1 >/dev/null 2>&1; do sleep 0.1; done
    ip link set lo up; ip link set c\$1 up multicast on; ip addr add 10.9.9.1\$1/24 brd + dev c\$1
    ip route add 224.0.0.0/4 dev c\$1; ip route add default dev c\$1; sleep 1
    for p in \\\$(seq 1 \$2); do $U/udp-receiver --interface c\$1 --portbase 9100 --nokbd --file $T/r\$1/p\\\$p >> $T/r\$1.log 2>&1; done" &
  local pid=\$!; sleep 0.3
  ip link add v\$1 type veth peer name c\$1; ip link set c\$1 netns \$pid; ip link set v\$1 master br0 up
}
export LABFREE_UDP_SENDER=$U/udp-sender LABFREE_GRUPO=$T/run LABFREE_GRUPO_LOGS=$T/logs LABFREE_GRUPO_RESUMO=$T/resumo.log
A="--interface br0 --min-receivers 3 --max-wait 4 --portbase 9100 --full-duplex --ttl 32 --nokbd --nopointopoint"
EOF

# 1-4: sessão completa com 2 máquinas
cat > "$T"/sessao.sh <<EOF
. $T/rede.sh
sh -c "$W \$A --file '$T/img/d1p1.img';$W \$A --file '$T/img/d1p2.img';cat '$T/img/d1p3.img.000' '$T/img/d1p3.img.001' | $W \$A;" > $T/udpcast.log 2>&1 &
maquina 1 3; maquina 2 3
sleep 8
python3 -c "import json;d=json.load(open('$T/run/9100.json'));print(d['fase'], len(d['conectadas']))" > $T/antes.txt
touch $T/run/9100.iniciar
wait
EOF
timeout 120 unshare -rn bash "$T"/sessao.sh
ok=1
[ "$(cat "$T"/antes.txt)" = "aguardando 2" ] && echo "OK  não começou sozinho (2 máquinas esperando)" || { echo "ERRO antes do pedido: $(cat "$T"/antes.txt)"; ok=0; }
for r in r1 r2; do
    if cmp -s "$T"/img/d1p1.img "$T"/$r/p1 && cmp -s "$T"/img/d1p2.img "$T"/$r/p2 && cat "$T"/img/d1p3.img.00* | cmp -s - "$T"/$r/p3; then
        echo "OK  $r recebeu as 3 partições iguais"; else echo "ERRO $r: arquivos diferentes ou faltando"; ok=0; fi
done
grep -q '"fase": "concluido"' "$T"/run/9100.json && echo "OK  andamento: concluído" || { echo "ERRO andamento: $(cat "$T"/run/9100.json)"; ok=0; }

# 5: cancelamento como a base faz (SIGTERM de baixo para cima: udp-sender, porteiro, sh)
rm -rf "${T:?}"/run/* "${T:?}"/r1/*
cat > "$T"/cancelar.sh <<EOF
. $T/rede.sh
sh -c "$W \$A --file '$T/img/d1p1.img';$W \$A --file '$T/img/d1p2.img';" > $T/udpcast.log 2>&1 &
SH=\$!
maquina 1 1
sleep 4
matar() { for f in \$(ps -o pid= --ppid \$1); do matar \$f; done; kill -TERM \$1 2>/dev/null; }; matar \$SH; sleep 3
ps -eo args | grep -c "^$U/udp-sender" > $T/sobrou.txt
pkill -f "$U/udp-receiver"; exit 0
EOF
timeout 40 unshare -rn bash "$T"/cancelar.sh
[ "$(cat "$T"/sobrou.txt)" = 0 ] && echo "OK  cancelar não deixa envio sobrando" || { echo "ERRO sobrou udp-sender depois de cancelar"; ok=0; }
[ $ok = 1 ] && echo "Tudo certo." || exit 1
