# LabFree ImageDeploy - identidade visual no console da maquina (sistema de clonagem).
# O console (texto VGA 80x25 ou framebuffer) aceita trocar as 16 cores (ESC ] P n RRGGBB) e desenha molduras e
# blocos (fonte cp437). Acentos: a maioria aparece (a e i o u c); "a~" e "o~" viram "a" e "o".
# Cores da LabFree (labfree.com.br): marinho #0d1b3e, azul #1a73e8, azul escuro #0d47a1, ciano da nuvem #0ca8e8.

labfree_paleta() {
    local E=$'\e' c
    for c in 0:0d1b3e 1:e5484d 2:22c55e 3:f5a524 4:1a73e8 5:16294f 6:0ca8e8 7:c9d4e8 \
             8:6b7ea8 9:ff6b6b a:4ade80 b:ffc94d c:5b9cf5 d:223a6b e:5fd0ff f:ffffff; do
        printf '%s]P%s' "$E" "${c/:/}"
    done
}

# cores do whiptail (cadastro): os nomes viram as cores da paleta acima
# black=marinho  magenta=cartao  blue=azul LabFree  cyan=ciano  lightgray=texto  white=branco
export NEWT_COLORS='root=lightgray,black
roottext=brightcyan,black
helpline=lightgray,black
window=white,magenta
shadow=black,black
border=cyan,magenta
title=brightcyan,magenta
textbox=white,magenta
label=white,magenta
button=white,brightmagenta
actbutton=white,blue
compactbutton=white,magenta
entry=white,black
disentry=gray,magenta
listbox=white,magenta
actlistbox=white,blue
sellistbox=brightcyan,magenta
actsellistbox=white,blue
checkbox=white,magenta
actcheckbox=white,blue'
