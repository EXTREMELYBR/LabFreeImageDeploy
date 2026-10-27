#!/bin/bash
# LabFree ImageDeploy - ajustes no sistema de clonagem (FOS), carregados no fim do funcs.sh do FOG.
#
# So valem quando a tarefa roda pela tela do LabFree (/bin/labfree-tela exporta LABFREE_TELA=1).
# Fora dela (cadastro, inventario, modo depuracao) o comportamento original do FOG fica intacto.
#
# Mudancas:
#   handleError   - nao espera 1 minuto nem reinicia: guarda o motivo em /tmp/labfree.resultado e
#                   encerra; a tela final mostra o erro e deixa ver o registro.
#   handleWarning - espera 10 s em vez de 60 s (o aviso tambem fica no registro).

# Cada script do FOS carrega o funcs.sh de novo (o que traz de volta as funcoes originais), por isso as trocas
# abaixo sao refeitas a cada carga - nada de "so na primeira vez".
if [[ -n $LABFREE_TELA ]]; then

    handleError() {
        local str="$1"
        local parts="" part=""
        echo
        echo " * ERRO: $(printf '%b' "$str" | head -n 3 | tr '\n' ' ')"
        printf '%b\n' "$str" > /tmp/labfree.resultado
        reportToServer error "$str"
        # mesmo cuidado do FOG: devolve as particoes ao tamanho do disco se a falha foi depois de encolher
        if [[ -n $2 && -n $hd ]]; then
            case $osid in
                [1-2]|4|[5-7]|9|10|11|50|51)
                    getPartitions "$hd"
                    for part in $parts; do
                        expandPartition "$part"
                    done
                    ;;
            esac
        fi
        exit 1
    }

    handleWarning() {
        local str="$1"
        echo " * AVISO: $(printf '%b' "$str" | head -n 3 | tr '\n' ' ')"
        reportToServer warning "$str"
        usleep 10000000
    }
fi
