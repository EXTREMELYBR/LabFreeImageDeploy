#!/bin/bash
# Teste do nome do Linux depois de restaurar (marca/fos/usr/share/fog/lib/labfree-nome.sh).
#
# Roda o completeTasking de verdade DENTRO do sistema de clonagem (o init.xz aberto num chroot isolado: mesmos
# bash, awk, blkid, lvm, ssh-keygen das maquinas), contra discos de teste em arquivos (loop): Ubuntu/Mint comum,
# LVM, btrfs (@ e estilo openSUSE), xfs (Fedora), LUKS, LVM + LUKS, Windows + Linux, BitLocker, dois discos,
# disco sem particoes, sem Linux, links apontando para fora, nome invalido, opcao desligada, repeticao, maquina nao
# cadastrada (capone=1: nome PC-<MAC>).
#
# Uso (root):  sudo bash testes/teste_nome_linux.sh [init.xz]
#   init.xz padrao: o do servidor (/var/www/html/fog/service/ipxe/init.xz).
#   SOBREPOR=1 copia por cima os arquivos de marca/fos deste repositorio (testar antes de reconstruir o init.xz).
# Nao toca em discos reais: so arquivos em uma pasta temporaria (apagada no fim). Precisa: sgdisk, mkfs.ext4,
# mkfs.xfs, mkfs.btrfs, mkfs.ntfs, mkfs.vfat, cryptsetup, lvm, ssh-keygen (do servidor, para montar os discos).
set -u
[[ $EUID -eq 0 ]] || { echo "Rode como root (sudo)."; exit 2; }
REPO=$(cd "$(dirname "$0")/.." && pwd)
INIT=${1:-/var/www/html/fog/service/ipxe/init.xz}
[[ -r $INIT ]] || { echo "init.xz nao encontrado: $INIT"; exit 2; }
for c in sgdisk mkfs.ext4 mkfs.xfs mkfs.btrfs mkfs.ntfs mkfs.vfat cryptsetup lvm ssh-keygen unshare chroot; do
    command -v $c >/dev/null || { echo "Falta o programa $c"; exit 2; }
done
W=$(mktemp -d /var/tmp/labfree-teste-nome.XXXXXX)
OK=0; FALHAS=0; LOOPS=(); VGS=(vglfteste1 vglfteste2)

limpar() {
    local m
    for m in $(awk -v w="$W" 'index($2, w) == 1 {print $2}' /proc/mounts | sort -r); do umount -l "$m" 2>/dev/null; done
    for m in "${VGS[@]}"; do vgchange -qq -an "$m" >/dev/null 2>&1; done
    cryptsetup close lfteste-luks 2>/dev/null
    for m in "${LOOPS[@]}"; do losetup -d "$m" 2>/dev/null; done
    rm -rf "$W"
}
trap limpar EXIT
ok() { OK=$((OK + 1)); printf '   ok    %s\n' "$1"; }
falha() { FALHAS=$((FALHAS + 1)); printf '   FALHA %s\n' "$1"; }
confere() { local d="$1"; shift; if "$@"; then ok "$d"; else falha "$d"; fi; }

# ---------------------------------------------------------------- sistema de clonagem aberto
echo "== Abrindo o sistema de clonagem ($INIT)"
mkdir -p "$W/raiz" "$W/m" "$W/t"
xz -dc "$INIT" > "$W/init.img" && mount -o loop,ro "$W/init.img" "$W/m" && cp -a "$W/m/." "$W/raiz/" && umount "$W/m"
rm -f "$W/init.img"
if [[ ${SOBREPOR:-0} == 1 ]]; then
    cp "$REPO"/marca/fos/usr/share/fog/lib/*.sh "$W/raiz/usr/share/fog/lib/"
    cp "$REPO"/marca/fos/bin/* "$W/raiz/bin/"
    echo "   (arquivos de marca/fos do repositorio por cima)"
fi
grep -q 'labfree-tela.sh' "$W/raiz/usr/share/fog/lib/funcs.sh" || { echo "Este init.xz nao tem a marca LabFree."; exit 1; }
[[ -r $W/raiz/usr/share/fog/lib/labfree-nome.sh ]] || { echo "Este init.xz nao tem o labfree-nome.sh (use SOBREPOR=1 ou reconstrua)."; exit 1; }
printf '#!/bin/bash\necho " * FIM-DA-TAREFA"\n' > "$W/raiz/bin/fog.imgcomplete"
md5sum "$W/raiz/etc/passwd" "$W/raiz/etc/hosts" "$W/raiz/etc/hostname" 2>/dev/null > "$W/fos.md5"
mkdir -p "$W/raiz/teste"
cat > "$W/raiz/teste/caso.sh" <<'EOF'
#!/bin/bash
. /usr/share/fog/lib/funcs.sh
[[ -n $TELA ]] && export LABFREE_TELA=1 && . /usr/share/fog/lib/funcs.sh
# loops (major 7) ficam fora do getPartitions do FOG (filtra por tipo de disco real); o resto e o original
getPartitions() { local disk="$1"; [[ -z $disk ]] && disk="$hd"; parts=$(lsblk -lpno KNAME,TYPE $disk | awk '{if ($2 ~ /part/ || $2 ~ /md/) print $1}' | sort -V | uniq); }
if [[ -z $TELA ]]; then handleWarning() { echo " * AVISO-TESTE: $1"; }; else usleep() { :; }; fi
killStatusReporter() { :; }; debugPause() { :; }
type=down; imgType=n; capone="$CAPONE"; mac="$MAC"; web=""
hostname="$NOME"; hostearly="$HOSTEARLY"; osid="$OSID"; disks="$DISCOS"; hd="${DISCOS%% *}"
completeTasking
EOF
rodar() { # NOME HOSTEARLY OSID "DISCOS" [TELA] -> saida em $SAIDA (variaveis CAPONE e MAC: maquina nao cadastrada)
    SAIDA=$(unshare -m --propagation private bash -c '
        R="$1"; mount -t proc proc "$R/proc"; mount --rbind /sys "$R/sys"; mount --rbind /dev "$R/dev"
        mount -t tmpfs tmpfs "$R/run"; mount -t tmpfs tmpfs "$R/tmp"
        NOME="$2" HOSTEARLY="$3" OSID="$4" DISCOS="$5" TELA="$6" CAPONE="$7" MAC="$8" chroot "$R" /bin/bash /teste/caso.sh 2>&1
    ' - "$W/raiz" "$1" "$2" "$3" "$4" "${5:-}" "${CAPONE:-}" "${MAC:-}")
    printf '%s\n' "$SAIDA" | sed 's/^/      | /'
}
abre() { mount -o ro${3:+,$3} "$1" "$W/t" && R="$W/t/${2:-}"; }   # DEV [SUBPASTA] [opcoes]
fecha() { umount "$W/t"; }

# ---------------------------------------------------------------- discos de teste
popular() { # PASTA ESTILO NOME_ANTIGO
    local r="$1" e="$2" n="$3" t
    mkdir -p "$r"/etc/ssh "$r"/usr/lib "$r"/var/lib/dbus
    printf 'UUID=x / ext4 defaults 0 1\n' > "$r/etc/fstab"
    printf '%s\n' "$n" > "$r/etc/hostname"
    printf '0123456789abcdef0123456789abcdef\n' > "$r/etc/machine-id"; chmod 444 "$r/etc/machine-id"
    case $e in
        debian)
            printf 'NAME="Linux Mint"\n' > "$r/usr/lib/os-release"; ln -s ../usr/lib/os-release "$r/etc/os-release"
            echo trixie/sid > "$r/etc/debian_version"
            printf '127.0.0.1\tlocalhost\n127.0.1.1\t%s\n\n# IPv6\n::1     ip6-localhost ip6-loopback\n' "$n" > "$r/etc/hosts"
            ln -s /etc/machine-id "$r/var/lib/dbus/machine-id" ;;
        fedora)
            printf 'NAME="Fedora Linux"\n' > "$r/etc/os-release"
            printf '127.0.0.1   localhost localhost.localdomain\n::1         localhost localhost.localdomain\n' > "$r/etc/hosts"
            cp "$r/etc/machine-id" "$r/var/lib/dbus/machine-id"; rm -f "$r/etc/hostname" ;;
        suse)
            printf 'NAME="openSUSE Leap"\n' > "$r/usr/lib/os-release"; ln -s ../usr/lib/os-release "$r/etc/os-release"
            printf '127.0.0.1\tlocalhost %s %s.escola.local # maquina modelo\n::1\tlocalhost ipv6-localhost %s\n' "$n" "$n" "$n" > "$r/etc/hosts" ;;
    esac
    for t in rsa ecdsa ed25519; do ssh-keygen -q -t $t -N '' -C "root@$n" -f "$r/etc/ssh/ssh_host_${t}_key"; done
    [[ $e == fedora ]] && { chgrp 998 "$r"/etc/ssh/ssh_host_*_key; chmod 640 "$r"/etc/ssh/ssh_host_*_key; }
    return 0
}
disco() { # NOME TAMANHO_MB [particoes sgdisk...] -> L (loop)
    local n="$1" tam="$2"; shift 2
    truncate -s "${tam}M" "$W/$n.img"; L=$(losetup -f --show -P "$W/$n.img"); LOOPS+=("$L")
    if [[ $# -gt 0 ]]; then sgdisk -o "$@" "$L" >/dev/null && blockdev --rereadpt "$L"; udevadm settle; fi
}
echo "== Montando os discos de teste"
{
disco ubuntu 400 -n1:0:+64M -t1:ef00 -n2:0:-40M -t2:8300 -n3:0:0 -t3:8200; D_UBUNTU=$L
mkfs.vfat "${L}p1"; mkfs.ext4 -q -F "${L}p2"; mkswap "${L}p3"; mount "${L}p2" "$W/t"; popular "$W/t" debian modelo-lab; umount "$W/t"

disco lvm 500 -n1:0:+100M -t1:8300 -n2:0:0 -t2:8e00; D_LVM=$L
mkfs.ext4 -q -F "${L}p1"; pvcreate -qq "${L}p2"; vgcreate -qq vglfteste1 "${L}p2"
lvcreate -qq -y -n swap -L 20M vglfteste1; lvcreate -qq -y -n root -l 100%FREE vglfteste1
mkfs.ext4 -q -F /dev/vglfteste1/root; mount /dev/vglfteste1/root "$W/t"; popular "$W/t" debian modelo-lvm; umount "$W/t"
vgchange -qq -an vglfteste1

disco btrfs 400 -n1:0:+64M -t1:ef00 -n2:0:0 -t2:8300; D_BTRFS=$L
mkfs.vfat "${L}p1"; mkfs.btrfs -q -f "${L}p2"; mount "${L}p2" "$W/t"
btrfs -q subvolume create "$W/t/@"; btrfs -q subvolume create "$W/t/@home"; popular "$W/t/@" debian modelo-btrfs; umount "$W/t"

disco xfs 400 -n1:0:+64M -t1:ef00 -n2:0:0 -t2:8300; D_XFS=$L
mkfs.vfat "${L}p1"; mkfs.xfs -q -f "${L}p2"; mount "${L}p2" "$W/t"; popular "$W/t" fedora modelo-fedora; umount "$W/t"

printf 'senha-teste' > "$W/chave"
disco luks 400 -n1:0:+64M -t1:ef00 -n2:0:+100M -t2:8300 -n3:0:0 -t3:8309; D_LUKS=$L
mkfs.vfat "${L}p1"; mkfs.ext4 -q -F "${L}p2"
cryptsetup -q luksFormat --type luks2 --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "${L}p3" "$W/chave"
cryptsetup open --key-file "$W/chave" "${L}p3" lfteste-luks; mkfs.ext4 -q -F /dev/mapper/lfteste-luks
mount /dev/mapper/lfteste-luks "$W/t"; popular "$W/t" debian modelo-luks; umount "$W/t"; cryptsetup close lfteste-luks

disco dual 400 -n1:0:+150M -t1:0700 -n2:0:0 -t2:8300; D_DUAL=$L
mkfs.ntfs -Q -F "${L}p1"; mkfs.ext4 -q -F "${L}p2"; mount "${L}p2" "$W/t"; popular "$W/t" debian modelo-dual; umount "$W/t"

disco semlinux 200 -n1:0:+64M -t1:ef00 -n2:0:0 -t2:0700; D_SEM=$L
mkfs.vfat "${L}p1"; mkfs.vfat "${L}p2"

disco suse 400 -n1:0:0 -t1:8300; D_SUSE=$L
mkfs.btrfs -q -f "${L}p1"; mount "${L}p1" "$W/t"; btrfs -q subvolume create "$W/t/@"; mkdir -p "$W/t/@/.snapshots/1"
btrfs -q subvolume create "$W/t/@/.snapshots/1/snapshot"; popular "$W/t/@/.snapshots/1/snapshot" suse modelo-suse
btrfs -q subvolume set-default "$W/t/@/.snapshots/1/snapshot"; umount "$W/t"

disco multi1 200 -n1:0:0 -t1:ef00; D_M1=$L; mkfs.vfat "${L}p1"
disco multi2 300 -n1:0:0 -t1:8300; D_M2=$L
mkfs.ext4 -q -F "${L}p1"; mount "${L}p1" "$W/t"; popular "$W/t" debian modelo-multi; umount "$W/t"

disco inteiro 200; D_INT=$L
mkfs.ext4 -q -F "$L"; mount "$L" "$W/t"; popular "$W/t" debian modelo-inteiro; umount "$W/t"

disco windows 200 -n1:0:0 -t1:0700; D_WIN=$L; mkfs.ntfs -Q -F "${L}p1"

disco bitlocker 200 -n1:0:0 -t1:0700; D_BL=$L; mkfs.ntfs -Q -F "${L}p1"
printf -- '-FVE-FS-' | dd of="${L}p1" bs=1 seek=3 conv=notrunc status=none

disco lvmluks 500 -n1:0:+150M -t1:8309 -n2:0:0 -t2:8e00; D_LL=$L
cryptsetup -q luksFormat --type luks2 --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "${L}p1" "$W/chave"
pvcreate -qq "${L}p2"; vgcreate -qq vglfteste2 "${L}p2"; lvcreate -qq -y -n raiz -l 100%FREE vglfteste2
mkfs.ext4 -q -F /dev/vglfteste2/raiz; mount /dev/vglfteste2/raiz "$W/t"; popular "$W/t" debian modelo-lvmluks; umount "$W/t"
vgchange -qq -an vglfteste2

disco links 300 -n1:0:0 -t1:8300; D_LINKS=$L
mkfs.ext4 -q -F "${L}p1"; mount "${L}p1" "$W/t"; popular "$W/t" debian modelo-links
( cd "$W/t/etc" && rm hostname && ln -s /etc/passwd hostname && rm hosts && ln -s /etc/hosts hosts \
  && rm machine-id && ln -s /etc/hostname machine-id && mv ssh ssh.real && ln -s /etc/ssh ssh ); umount "$W/t"
udevadm settle
} > "$W/montagem.log" 2>&1 || true
for v in D_UBUNTU D_LVM D_BTRFS D_XFS D_LUKS D_DUAL D_SEM D_SUSE D_M1 D_M2 D_INT D_WIN D_BL D_LL D_LINKS; do
    [[ -n ${!v:-} ]] || { echo "Falha ao montar os discos de teste:"; tail -20 "$W/montagem.log"; exit 1; }
done

# ---------------------------------------------------------------- conferencias comuns
IDS=()
renomeado() { # DESCRICAO NOME DEV [SUBPASTA] [opcoes] - Linux com o nome novo e tudo no lugar
    local d="$1" n="$2" id
    abre "$3" "${4:-}" "${5:-}" || { falha "$d: nao abriu"; return; }
    confere "$d: /etc/hostname = $n" [ "$(cat "$R/etc/hostname")" == "$n" ]
    confere "$d: hosts sem o nome antigo" bash -c "! grep -q modelo- '$R/etc/hosts'"
    id=$(cat "$R/etc/machine-id")
    confere "$d: machine-id novo (32 hex)" bash -c "[[ $id =~ ^[0-9a-f]{32}$ && $id != 0123456789abcdef0123456789abcdef ]]"
    IDS+=("$id")
    if [[ -L $R/var/lib/dbus/machine-id ]]; then
        confere "$d: link do D-Bus mantido" [ "$(readlink "$R/var/lib/dbus/machine-id")" == /etc/machine-id ]
    elif [[ -f $R/var/lib/dbus/machine-id ]]; then
        confere "$d: machine-id do D-Bus igual" [ "$(cat "$R/var/lib/dbus/machine-id")" == "$id" ]
    fi
    local f bons=0 total=0
    for f in "$R"/etc/ssh/ssh_host_*_key; do
        total=$((total + 1))
        [[ $(ssh-keygen -lf "$f.pub" | awk '{print $3}') == "root@$n" ]] && cmp -s <(ssh-keygen -yf "$f" 2>/dev/null | awk '{print $2}') <(awk '{print $2}' "$f.pub") && bons=$((bons + 1))
        [[ $(stat -c %a "$f") == 640 ]] && [[ $(ssh-keygen -lf "$f.pub" | awk '{print $3}') == "root@$n" ]] && bons=$((bons + 1))
    done
    confere "$d: 3 chaves de SSH novas e validas" [ "$bons" -ge 3 -a "$total" -eq 3 ]
    confere "$d: nenhum arquivo temporario sobrando" [ -z "$(find "$R/etc" -maxdepth 2 -name '.labfree*' -print -quit)" ]
    fecha
}
saida_tem() { confere "$1" bash -c 'grep -qF -- "$1" <<< "$2"' - "$2" "$SAIDA"; }
saida_sem() { confere "$1" bash -c '! grep -qF -- "$1" <<< "$2"' - "$2" "$SAIDA"; }

# ---------------------------------------------------------------- casos
echo; echo "== 1. Ubuntu/Mint comum (EFI + ext4 + swap)"
rodar LAB-001 1 50 "$D_UBUNTU"
saida_tem "tela mostra a troca" "Trocando o nome da maquina no Linux"
saida_tem "tarefa termina" "FIM-DA-TAREFA"
renomeado "ubuntu" LAB-001 "${D_UBUNTU}p2"
abre "${D_UBUNTU}p2"; confere "ubuntu: linha 127.0.1.1 unica com o nome novo" [ "$(grep -c "^127.0.1.1	LAB-001$" "$R/etc/hosts")" -eq 1 ]
confere "ubuntu: chaves com 600 e .pub 644" bash -c "[[ \$(stat -c %a '$R/etc/ssh/ssh_host_rsa_key') == 600 && \$(stat -c %a '$R/etc/ssh/ssh_host_rsa_key.pub') == 644 ]]"
confere "ubuntu: machine-id com permissao 444" [ "$(stat -c %a "$R/etc/machine-id")" == 444 ]; fecha

echo; echo "== 2. LVM (/boot + volume com o Linux)"
rodar LAB-002 1 50 "$D_LVM"
confere "lvm: volumes desligados de novo depois" bash -c "! lvs --noheadings -o lv_active vglfteste1 | grep -q active"
vgchange -qq -ay vglfteste1; renomeado "lvm" LAB-002 /dev/vglfteste1/root; vgchange -qq -an vglfteste1

echo; echo "== 3. btrfs estilo Ubuntu (subvolume @)"
rodar LAB-003 1 50 "$D_BTRFS"; saida_tem "btrfs: achou no subvolume @" "subvolume @"
renomeado "btrfs" LAB-003 "${D_BTRFS}p2" @

echo; echo "== 4. xfs estilo Fedora (sem /etc/hostname, chaves 640 grupo 998)"
rodar LAB-004 1 50 "$D_XFS"
renomeado "xfs" LAB-004 "${D_XFS}p2"
abre "${D_XFS}p2"; confere "xfs: hosts do Fedora nao ganhou 127.0.1.1" bash -c "! grep -q 127.0.1.1 '$R/etc/hosts'"
confere "xfs: chaves mantiveram 640 e grupo 998" [ "$(stat -c '%a %g' "$R/etc/ssh/ssh_host_ed25519_key")" == "640 998" ]; fecha

echo; echo "== 5. Disco criptografado (LUKS)"
antes=$(blkid -o value -s UUID "${D_LUKS}p3"); md5a=$(dd if="${D_LUKS}p3" bs=1M count=8 status=none | md5sum)
rodar LAB-005 1 50 "$D_LUKS"
saida_tem "luks: avisa disco criptografado" "Disco criptografado (LUKS)"
saida_sem "luks: nao diz que trocou" "Trocando o nome"
saida_tem "luks: tarefa termina mesmo assim" "FIM-DA-TAREFA"
confere "luks: parte criptografada intacta" [ "$md5a" == "$(dd if="${D_LUKS}p3" bs=1M count=8 status=none | md5sum)" ]
confere "luks: nada aberto em /dev/mapper" bash -c "! ls /dev/mapper | grep -q luks"
echo "   (de novo pela tela do LabFree)"
rodar LAB-005 1 50 "$D_LUKS" 1
saida_tem "luks na tela: aviso no registro (AVISO:)" "AVISO: Disco criptografado"

echo; echo "== 6. Windows + Linux no mesmo disco (imagem do tipo Windows)"
rodar LAB-006 1 9 "$D_DUAL"; renomeado "dual" LAB-006 "${D_DUAL}p2"

echo; echo "== 7. Sem Linux no disco"
rodar LAB-007 1 50 "$D_SEM"; saida_tem "imagem Linux sem Linux: avisa" "Nenhum Linux encontrado"
rodar LAB-007 1 9 "$D_SEM"; saida_sem "imagem Windows sem Linux: nao avisa nada" "AVISO"

echo; echo "== 8. btrfs estilo openSUSE (subvolume padrao = snapshot; nome antigo em 127.0.0.1 e ::1)"
rodar LAB-008 1 50 "$D_SUSE"; renomeado "suse" LAB-008 "${D_SUSE}p1"
abre "${D_SUSE}p1"; confere "suse: nome.dominio e comentario mantidos" grep -q "^127.0.0.1	localhost LAB-008 LAB-008.escola.local # maquina modelo$" "$R/etc/hosts"
confere "suse: ::1 trocado" grep -q "^::1	localhost ipv6-localhost LAB-008$" "$R/etc/hosts"; fecha

echo; echo "== 9. Dois discos (Linux no segundo)"
rodar LAB-009 1 50 "$D_M1 $D_M2"; renomeado "multi" LAB-009 "${D_M2}p1"

echo; echo "== 10. ext4 no disco inteiro (sem tabela de particoes)"
rodar LAB-010 1 50 "$D_INT"; renomeado "inteiro" LAB-010 "$D_INT"

echo; echo "== 11. So Windows"
rodar LAB-011 1 9 "$D_WIN"; saida_sem "so Windows: nenhum aviso" "AVISO"; saida_sem "so Windows: nada de Linux" "Linux"

echo; echo "== 12. BitLocker"
rodar LAB-012 1 9 "$D_BL"; saida_tem "bitlocker: avisa" "Windows criptografado (BitLocker)"

echo; echo "== 13. LVM com o Linux + parte LUKS no mesmo disco"
rodar LAB-013 1 50 "$D_LL"; saida_tem "lvm+luks: informa a parte criptografada" "parte criptografada"
saida_sem "lvm+luks: sem aviso de falha" "AVISO"
vgchange -qq -ay vglfteste2; renomeado "lvm+luks" LAB-013 /dev/vglfteste2/raiz; vgchange -qq -an vglfteste2

echo; echo "== 14. Links apontando para fora do disco (nunca mexe no sistema de clonagem)"
rodar LAB-014 1 50 "$D_LINKS"
confere "links: arquivos do sistema de clonagem intactos" md5sum --quiet -c "$W/fos.md5"
abre "${D_LINKS}p1"; confere "links: /etc/hostname virou arquivo com o nome" bash -c "[[ ! -L '$R/etc/hostname' && \$(cat '$R/etc/hostname') == LAB-014 ]]"
confere "links: hosts (link) nao foi seguido" [ -L "$R/etc/hosts" ]
confere "links: machine-id (link) nao foi seguido" [ -L "$R/etc/machine-id" ]
confere "links: pasta ssh (link) nao foi seguida" [ -L "$R/etc/ssh" ]; fecha

echo; echo "== 15. Opcao desligada, maquina sem nome e nome invalido: nada muda"
for args in "LAB-099||50" "LAB-099|0|50" "|1|50" "LAB_01|1|50" "-LAB|1|50"; do
    IFS='|' read -r a b c <<< "$args"; rodar "$a" "$b" "$c" "$D_UBUNTU"
done
abre "${D_UBUNTU}p2"; confere "nada mudou (nome continua LAB-001)" [ "$(cat "$R/etc/hostname")" == LAB-001 ]; fecha
rodar "LAB_01" 1 50 "$D_UBUNTU"; saida_tem "nome invalido: avisa" "Nome cadastrado invalido"

echo; echo "== 16. Restaurar de novo com outro nome (repeticao)"
rodar LAB-101 1 50 "$D_UBUNTU"; rodar LAB-102 1 50 "$D_UBUNTU"
saida_tem "mostra o nome antigo e o novo" "LAB-101 -> LAB-102"
abre "${D_UBUNTU}p2"; confere "uma unica linha 127.0.1.1" [ "$(grep -c '^127.0.1.1' "$R/etc/hosts")" -eq 1 ]
confere "hosts com o nome mais novo" grep -q "^127.0.1.1	LAB-102$" "$R/etc/hosts"; fecha

echo; echo "== 17. Maquina nao cadastrada (restaurar rapido / sessao de grupo: capone=1)"
CAPONE=1 MAC=00:e0:4c:88:03:09 rodar "" 1 50 "$D_UBUNTU"
saida_tem "nao cadastrada: informa o nome" "Maquina nao cadastrada: nome PC-00E04C880309"
saida_sem "nao cadastrada: termina como o FOG (sem fog.imgcomplete)" "FIM-DA-TAREFA"
renomeado "nao cadastrada" PC-00E04C880309 "${D_UBUNTU}p2"
CAPONE=1 MAC=00:e0:4c:88:03:09 rodar "" "" 50 "$D_BTRFS"
abre "${D_BTRFS}p2" @; confere "nao cadastrada com a opcao desligada: nome nao muda" [ "$(cat "$R/etc/hostname")" == LAB-003 ]; fecha
CAPONE=1 MAC="a4:bb:6d:01:02:0f|00:11:22:33:44:55" rodar "" 1 9 "$D_DUAL"
saida_tem "nao cadastrada, Windows + Linux: usa a primeira MAC" "nome PC-A4BB6D01020F"
renomeado "nao cadastrada dual" PC-A4BB6D01020F "${D_DUAL}p2"
CAPONE=1 MAC="" rodar "" 1 50 "$D_M1 $D_M2"
abre "${D_M2}p1"; confere "nao cadastrada sem MAC: nome aleatorio PC-<12 hex>" bash -c "[[ \$(cat '$R/etc/hostname') =~ ^PC-[0-9A-F]{12}$ ]]"; fecha

echo; echo "== 18. Cada maquina com um machine-id diferente"
confere "${#IDS[@]} machine-ids, todos diferentes" [ "$(printf '%s\n' "${IDS[@]}" | sort -u | wc -l)" -eq "${#IDS[@]}" ]

echo; echo "== Resultado: $OK ok, $FALHAS falha(s)"
[[ $FALHAS -eq 0 ]]
