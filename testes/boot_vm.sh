#!/bin/bash
# LabFree ImageDeploy — teste de boot PXE numa máquina virtual (QEMU/KVM), sem usar o laboratório.
# A VM usa a rede interna do QEMU (slirp): o QEMU entrega IP e o arquivo de boot por TFTP a partir
# de /tftpboot; o resto (default.ipxe → menu → kernel/init) vem do servidor real pelo IP da rede de
# clonagem, que a VM alcança via NAT do QEMU. Nada é enviado ao DHCP do laboratório.
# Uso: testes/boot_vm.sh bios|uefi PASTA_SAIDA "segundos para cada captura..."
# Ex.: testes/boot_vm.sh bios /tmp/telas "8 15 25"
set -euo pipefail
RAIZ_TFTP="${RAIZ_TFTP:-/tftpboot}"  # permite testar binários novos sem mexer no /tftpboot
modo="${1:-bios}"; saida="${2:-/tmp/LabFreeImageDeploy-telas}"; capturas="${3:-8 15 25}"
mkdir -p "$saida"
mon="$saida/monitor.sock"; rm -f "$mon"
if [ "$modo" = "uefi" ]; then
    cp /usr/share/OVMF/OVMF_VARS_4M.fd "$saida/vars.fd"
    firmware=(-drive if=pflash,format=raw,readonly=on,file=/usr/share/OVMF/OVMF_CODE_4M.fd -drive if=pflash,format=raw,file="$saida/vars.fd")
    arquivo=snponly.efi
else
    firmware=()
    arquivo=undionly.kkpxe
fi
qemu-system-x86_64 -enable-kvm -m 2048 -smp 2 "${firmware[@]}" -boot n \
    -netdev user,id=n0,tftp=$RAIZ_TFTP,bootfile=$arquivo -device e1000,netdev=n0,mac=52:54:00:4c:46:01 \
    -display none -vga std -monitor unix:"$mon",server,nowait -serial file:"$saida/serial.log" &
pid=$!
trap 'kill $pid 2>/dev/null || true' EXIT
inicio=$(date +%s)
for t in $capturas; do
    while [ $(( $(date +%s) - inicio )) -lt "$t" ]; do sleep 1; done
    echo "screendump $saida/tela-${modo}-${t}s.png -f png" | socat - UNIX-CONNECT:"$mon" >/dev/null 2>&1 || true
    echo "captura: $saida/tela-${modo}-${t}s.png"
done
echo "quit" | socat - UNIX-CONNECT:"$mon" >/dev/null 2>&1 || true
wait $pid 2>/dev/null || true
