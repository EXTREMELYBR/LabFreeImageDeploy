#!/bin/bash
# LabFree ImageDeploy - troca o nome do Linux depois de restaurar a imagem (sistema de clonagem, FOS).
#
# O FOG so troca o nome do Windows (changeHostname, no registro). Este arquivo, carregado no fim do funcs.sh
# (pelo labfree-tela.sh), refaz o completeTasking para, logo depois de gravar a imagem e antes de avisar o
# servidor que terminou, procurar o Linux no disco restaurado e:
#   - gravar o nome cadastrado em /etc/hostname e trocar o nome antigo no /etc/hosts;
#   - dar um /etc/machine-id novo (com o mesmo id, o DHCP pode dar o MESMO IP para varias maquinas);
#   - criar chaves de SSH novas (as que ja existiam; cada maquina com as suas).
# Vale com a mesma opcao do Windows (painel: "Renomear com o nome cadastrado" = hostearly=1).
# Maquina NAO cadastrada (restaurar rapido, sessao de grupo: o FOG manda capone=1 e para antes de renomear): com a
# opcao ligada, o servidor do LabFree manda hostearly=1 tambem, e ela ganha o nome PC-<MAC da placa de boot>
# (Windows e Linux). A MAC e unica, entao o nome nunca repete; e a mesma maquina recebe sempre o mesmo nome.
#
# Disco criptografado (LUKS): o sistema de clonagem nao tem a senha, entao nada e trocado e a tarefa avisa
# "disco criptografado" (no registro da maquina e no painel). LVM e btrfs (subvolumes @, root...) sao abertos.
# Nunca interrompe a clonagem: qualquer problema vira aviso e a tarefa termina normalmente.

LABFREE_NOME_MNT=/tmp/labfree-linux

# Nome valido para o Linux (o painel ja limita a 15 caracteres: letras, numeros e hifen)
labfree_nome_valido() {
    [[ $1 =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]]
}

# Raiz de um Linux: pasta etc de verdade (nao link) com fstab e os-release (o os-release do Ubuntu e um link
# relativo; -e seguiria o link fora do disco, por isso -L/-f).
labfree_eh_raiz() {
    local r="$1"
    [[ -d $r/etc && ! -L $r/etc && -f $r/etc/fstab && ! -L $r/etc/fstab ]] || return 1
    [[ -L $r/etc/os-release || -f $r/etc/os-release || -f $r/usr/lib/os-release ]]
}

# Grava um arquivo pequeno de forma atomica, mantendo dono e permissao do original (se existir)
labfree_gravar() {
    local alvo="$1" conteudo="$2" modo="${3:-644}" dono="0:0" tmp
    if [[ -f $alvo && ! -L $alvo ]]; then
        modo=$(stat -c %a "$alvo" 2>/dev/null || echo "$modo")
        dono=$(stat -c %u:%g "$alvo" 2>/dev/null || echo "$dono")
    fi
    tmp="${alvo%/*}/.labfree-tmp.$$"
    printf '%s' "$conteudo" 2>/dev/null > "$tmp" || { rm -f "$tmp"; return 1; }
    chown "$dono" "$tmp" 2>/dev/null
    chmod "$modo" "$tmp" 2>/dev/null
    [[ -L $alvo ]] && rm -f "$alvo"
    mv -f "$tmp" "$alvo" 2>/dev/null || { rm -f "$tmp"; return 1; }
}

# Troca o nome dentro de uma raiz de Linux ja montada. Devolve 0 se trocou.
labfree_renomear_raiz() {
    local r="$1" novo="$2" velho="" velho2="" hosts="" debian=0 id f tipo modo dono erro=0
    [[ -f $r/etc/hostname && ! -L $r/etc/hostname ]] && velho=$(head -n 1 "$r/etc/hostname" | tr -d ' \t\r')
    # 1) /etc/hostname
    labfree_gravar "$r/etc/hostname" "$novo"$'\n' 644 || erro=1
    # 2) /etc/hosts: a linha 127.0.1.1 (Debian/Ubuntu/Mint) passa a ter o nome novo; o nome antigo nas linhas
    #    de localhost (127.x e ::1) tambem e trocado (inclusive nome.dominio).
    [[ -f $r/etc/debian_version ]] && debian=1
    if [[ -f $r/etc/hosts && ! -L $r/etc/hosts ]]; then
        # sem linha 127.0.1.1 num Debian/Ubuntu/Mint: ela entra logo depois da linha 127.0.0.1 (ou no fim)
        awk '$1 == "127.0.1.1" { achou = 1 } END { exit !achou }' "$r/etc/hosts" && debian=0
        # nomes antigos: o do /etc/hostname e o da linha 127.0.1.1 (podem ser diferentes)
        velho2=$(awk '$1 == "127.0.1.1" { print $2; exit }' "$r/etc/hosts")
        hosts=$(awk -v novo="$novo" -v velho="$velho" -v velho2="$velho2" -v debian="$debian" '
            function antigo(c,   v, n) {
                for (v = 1; v <= 2; v++) {
                    n = (v == 1 ? velho : velho2)
                    if (n == "" || n ~ /^#/) continue
                    if (tolower(c) == tolower(n)) return novo
                    if (index(tolower(c), tolower(n) ".") == 1) return novo substr(c, length(n) + 1)
                }
                return ""
            }
            BEGIN { achou = 0 }
            $1 == "127.0.1.1" { if (!achou) { print "127.0.1.1\t" novo; achou = 1 } ; next }
            (velho != "" || velho2 != "") && ($1 ~ /^127\./ || $1 == "::1") {
                linha = $1; mudou = 0
                for (i = 2; i <= NF; i++) {
                    c = $i
                    if (c ~ /^#/) { for (j = i; j <= NF; j++) linha = linha " " $j; break }
                    t = antigo(c)
                    if (t != "") { c = t; mudou = 1 }
                    linha = linha (i == 2 ? "\t" : " ") c
                }
                if (mudou) $0 = linha
            }
            { print }
            $1 == "127.0.0.1" && debian == 1 && !achou { print "127.0.1.1\t" novo; achou = 1 }
            END { if (!achou && debian == 1) print "127.0.1.1\t" novo }
        ' "$r/etc/hosts") && labfree_gravar "$r/etc/hosts" "$hosts"$'\n' 644 || erro=1
    elif [[ ! -e $r/etc/hosts && ! -L $r/etc/hosts && $debian -eq 1 ]]; then
        labfree_gravar "$r/etc/hosts" "127.0.0.1"$'\t'"localhost"$'\n'"127.0.1.1"$'\t'"$novo"$'\n' 644 || erro=1
    fi
    # 3) machine-id novo (32 hexadecimais), gravado ja preenchido: arquivo vazio faria o systemd tratar como
    #    "primeiro boot" (alguns sistemas perguntam idioma/fuso). O do D-Bus acompanha quando nao e link.
    id=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
    if [[ ${#id} -eq 32 ]]; then
        if [[ -f $r/etc/machine-id && ! -L $r/etc/machine-id ]]; then
            labfree_gravar "$r/etc/machine-id" "$id"$'\n' 444 || erro=1
        fi
        if [[ -f $r/var/lib/dbus/machine-id && ! -L $r/var/lib/dbus/machine-id ]]; then
            labfree_gravar "$r/var/lib/dbus/machine-id" "$id"$'\n' 444 || erro=1
        fi
    else
        erro=1
    fi
    # 4) chaves de SSH novas (so os tipos que ja existiam; mantem dono e permissao, ex.: grupo ssh_keys)
    if [[ -d $r/etc/ssh && ! -L $r/etc/ssh ]] && command -v ssh-keygen >/dev/null 2>&1; then
        for f in "$r"/etc/ssh/ssh_host_*_key; do
            [[ -f $f && ! -L $f ]] || continue
            tipo=${f##*/ssh_host_}; tipo=${tipo%_key}
            case $tipo in rsa|ecdsa|ed25519|dsa) ;; *) continue ;; esac
            modo=$(stat -c %a "$f"); dono=$(stat -c %u:%g "$f")
            rm -f "$r/etc/ssh/.labfree-chave" "$r/etc/ssh/.labfree-chave.pub"
            if ssh-keygen -q -t "$tipo" -N '' -C "root@$novo" -f "$r/etc/ssh/.labfree-chave" </dev/null >/dev/null 2>&1; then
                chown "$dono" "$r/etc/ssh/.labfree-chave"; chmod "$modo" "$r/etc/ssh/.labfree-chave"
                if [[ -f $f.pub && ! -L $f.pub ]]; then
                    chown "$(stat -c %u:%g "$f.pub")" "$r/etc/ssh/.labfree-chave.pub"
                    chmod "$(stat -c %a "$f.pub")" "$r/etc/ssh/.labfree-chave.pub"
                fi
                mv -f "$r/etc/ssh/.labfree-chave" "$f" && mv -f "$r/etc/ssh/.labfree-chave.pub" "$f.pub" || erro=1
            else
                rm -f "$r/etc/ssh/.labfree-chave" "$r/etc/ssh/.labfree-chave.pub"
                erro=1
            fi
        done
    fi
    sync
    LABFREE_NOME_ANTIGO="$velho"
    return $erro
}

# Procura o Linux em todos os discos restaurados e troca o nome (e avisa sobre partes criptografadas, LUKS ou
# BitLocker, que nao podem ser abertas). Variaveis do FOG usadas: hostname, hostearly,
# osid, disks, hd.
labfreeRenomearLinux() {
    [[ -z $hostname || $hostearly -ne 1 ]] && return 0
    local disco parte tipo vg dev lvs_ativos raiz sub achou=0 trocou=0 falhou=0 cripto=0 bitlocker=0
    local lista=() vgs_ligados=() nao_abriu=() candidatas=()
    local alvos="${disks:-$hd}"
    [[ -z $alvos ]] && return 0
    if ! labfree_nome_valido "$hostname"; then
        handleWarning "Nome cadastrado invalido para o Linux ($hostname): o nome do Linux nao foi trocado"
        return 0
    fi
    # 1) o que ha em cada parte do disco
    for disco in $alvos; do
        getPartitions "$disco"
        [[ -z $parts ]] && parts="$disco"
        for parte in $parts; do
            tipo=$(blkid -po udev "$parte" 2>/dev/null | awk -F= '/^ID_FS_TYPE=/{print $2}')
            case $tipo in
                crypto_LUKS) cripto=1 ;;
                BitLocker) bitlocker=1 ;;
                ext2|ext3|ext4|xfs|btrfs) lista+=("$parte") ;;
                LVM2_member)
                    vg=$(lvm pvs --noheadings -o vg_name "$parte" 2>/dev/null | tr -d ' ')
                    [[ -z $vg ]] && continue
                    lvs_ativos=$(lvm lvs --noheadings -o lv_active "$vg" 2>/dev/null | grep -c active)
                    if [[ $lvs_ativos -eq 0 ]]; then
                        lvm vgchange -ay "$vg" >/dev/null 2>&1 && vgs_ligados+=("$vg")
                    fi
                    for dev in $(lvm lvs --noheadings -o lv_path "$vg" 2>/dev/null); do
                        tipo=$(blkid -po udev "$dev" 2>/dev/null | awk -F= '/^ID_FS_TYPE=/{print $2}')
                        case $tipo in
                            crypto_LUKS) cripto=1 ;;
                            ext2|ext3|ext4|xfs|btrfs) lista+=("$dev") ;;
                        esac
                    done
                    ;;
            esac
        done
    done
    # 2) em cada sistema de arquivos, procura a raiz do Linux (no btrfs, tambem nos subvolumes do primeiro nivel)
    mkdir -p "$LABFREE_NOME_MNT"
    for dev in "${lista[@]}"; do
        umount "$LABFREE_NOME_MNT" >/dev/null 2>&1
        if ! mount -o rw "$dev" "$LABFREE_NOME_MNT" >/dev/null 2>&1; then
            nao_abriu+=("$dev")
            continue
        fi
        candidatas=("$LABFREE_NOME_MNT")
        if [[ $(blkid -po udev "$dev" 2>/dev/null) == *ID_FS_TYPE=btrfs* ]]; then
            for raiz in "$LABFREE_NOME_MNT"/*; do
                [[ -d $raiz && ! -L $raiz ]] && candidatas+=("$raiz")
            done
        fi
        for raiz in "${candidatas[@]}"; do
            labfree_eh_raiz "$raiz" || continue
            achou=1
            sub=""; [[ $raiz != "$LABFREE_NOME_MNT" ]] && sub=", subvolume ${raiz##*/}"
            echo " * Linux encontrado em $dev$sub"
            dots "Trocando o nome da maquina no Linux"
            if labfree_renomear_raiz "$raiz" "$hostname"; then
                echo "Done"
                trocou=1
                [[ -n $LABFREE_NOME_ANTIGO && $LABFREE_NOME_ANTIGO != "$hostname" ]] && echo " * Nome do Linux: $LABFREE_NOME_ANTIGO -> $hostname" || echo " * Nome do Linux: $hostname"
            else
                echo "Failed"
                falhou=1
            fi
            debugPause
        done
        umount "$LABFREE_NOME_MNT" >/dev/null 2>&1 || umount -l "$LABFREE_NOME_MNT" >/dev/null 2>&1
    done
    rmdir "$LABFREE_NOME_MNT" >/dev/null 2>&1
    for vg in "${vgs_ligados[@]}"; do
        lvm vgchange -an "$vg" >/dev/null 2>&1
    done
    # 3) avisos: nada some em silencio
    if [[ $bitlocker -eq 1 ]]; then
        handleWarning "Windows criptografado (BitLocker): o sistema de clonagem nao abre a particao, entao o nome do Windows nao foi trocado"
    fi
    if [[ $falhou -eq 1 ]]; then
        handleWarning "Nao consegui trocar todo o nome do Linux para $hostname (confira o /etc/hostname da maquina)"
    elif [[ $achou -eq 0 && $cripto -eq 1 ]]; then
        handleWarning "Disco criptografado (LUKS): o sistema de clonagem nao abre discos criptografados, entao o nome do Linux nao foi trocado. Troque o nome nessa maquina depois do primeiro boot."
    elif [[ $achou -eq 0 && ${#nao_abriu[@]} -gt 0 && $osid == +(50|51) ]]; then
        handleWarning "Nao consegui abrir ${nao_abriu[*]} para trocar o nome do Linux (sistema de arquivos nao suportado ou com erro)"
    elif [[ $achou -eq 0 && $osid == +(50|51) ]]; then
        handleWarning "Nenhum Linux encontrado no disco restaurado: o nome do Linux nao foi trocado"
    elif [[ $trocou -eq 1 && $cripto -eq 1 ]]; then
        echo " * Ha tambem uma parte criptografada no disco: ela nao foi aberta"
    fi
    return 0
}

# Nome para maquina nao cadastrada: PC- + os 12 hexadecimais da MAC de boot = 15 caracteres (limite do Windows).
# Sem MAC valida (nao deveria acontecer): 12 hexadecimais aleatorios.
labfree_nome_sem_cadastro() {
    local m
    m=$(printf '%s' "${mac%%|*}" | tr -d ':-' | tr 'a-f' 'A-F')
    [[ $m =~ ^[0-9A-F]{12}$ ]] || m=$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n' | tr 'a-f' 'A-F')
    printf 'PC-%s' "$m"
}

# Nome do Windows: o changeHostname do FOG (registro) em cada NTFS dos discos restaurados
labfreeRenomearWindows() {
    [[ $osid == +([1-2]|4|[5-7]|9|10|11) ]] || return 0
    for disk in $disks; do
        getPartitions "$disk"
        for part in $parts; do
            fsTypeSetting "$part"
            [[ $fstype == ntfs ]] && changeHostname "$part"
        done
    done
}

# Igual ao completeTasking do FOG 1.5.10, com o nome do Linux trocado antes de avisar o servidor que terminou.
completeTasking() {
    case $type in
        up)
            chmod -R 775 "$imagePath" >/dev/null 2>&1
            killStatusReporter
            . /bin/fog.imgcomplete
            ;;
        down)
            killStatusReporter
            if [[ -f /images/postdownloadscripts/fog.postdownload ]]; then
                postdownpath="/images/postdownloadscripts/"
                . ${postdownpath}fog.postdownload
            fi
            if [[ $capone -eq 1 ]]; then
                # maquina nao cadastrada: o FOG termina aqui; antes, o nome PC-<MAC> (se a opcao estiver ligada)
                if [[ $hostearly -eq 1 && -z $hostname ]]; then
                    hostname=$(labfree_nome_sem_cadastro)
                    echo " * Maquina nao cadastrada: nome $hostname"
                    labfreeRenomearWindows
                    [[ $osid != 8 ]] && labfreeRenomearLinux
                fi
                exit 0
            fi
            labfreeRenomearWindows
            [[ $osid != 8 ]] && labfreeRenomearLinux
            . /bin/fog.imgcomplete
            ;;
    esac
}
