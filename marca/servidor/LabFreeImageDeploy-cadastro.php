<?php
/**
 * LabFree ImageDeploy — apoio ao cadastro guiado pelo boot da rede.
 * Instalado em /var/www/html/fog/service/ pelo LabFreeImageDeploy-root (marca-aplicar).
 *
 *   acao=sugestao  ->  "NOME|ID_DA_IMAGEM_PADRAO"  (próximo nome livre pelo padrão do painel)
 *   acao=imagens   ->  uma linha por imagem: "ID|Nome|Tamanho" e por fim "#!fim" (resposta nunca vazia)
 *                     (só ASCII: o console do boot não tem acentos)
 *
 * Mesmo nível de acesso de service/imagelisting.php do FOG (lista pública de imagens para o boot).
 */
require '../commons/base.inc.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/plain; charset=utf-8');

function labfree_ascii($texto)
{
    $t = html_entity_decode((string)$texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    return trim(preg_replace('/[^\x20-\x7E]|\|/', '', (string)$t));
}

$acao = (string)($_REQUEST['acao'] ?? '');
if ($acao === 'sugestao') {
    $padrao = strtoupper(trim((string)FOGCore::getSetting('FOG_QUICKREG_SYS_NAME')));
    if ($padrao === '' || $padrao === 'MAC' || !preg_match('/^[A-Z0-9-]*\*+[A-Z0-9-]*$/', $padrao)) {
        $padrao = 'LAB-***';
    }
    $digitos = substr_count($padrao, '*');
    $numero = max(1, (int)FOGCore::getSetting('FOG_QUICKREG_SYS_NUMBER'));
    $nome = '';
    // uma consulta só (antes era uma por número testado)
    $usados = array_flip(array_map('strtoupper', (array)FOGCore::getSubObjectIDs('Host', [], 'name')));
    for ($i = 0; $i < 100000; $i++, $numero++) {
        $tentativa = preg_replace('/\*+/', str_pad((string)$numero, $digitos, '0', STR_PAD_LEFT), $padrao, 1);
        if (strlen($tentativa) <= 15 && !isset($usados[$tentativa])) {
            $nome = $tentativa;
            break;
        }
    }
    echo $nome . '|' . (int)FOGCore::getSetting('FOG_QUICKREG_IMG_ID');
    exit;
}
if ($acao === 'imagens') {
    $raiz = '/images';
    foreach ((array)FOGCore::getClass('StorageNodeManager')->find(['isMaster' => 1, 'isEnabled' => 1]) as $No) {
        $raiz = rtrim((string)$No->get('path'), '/') ?: '/images';
        break;
    }
    foreach ((array)FOGCore::getClass('ImageManager')->find(['isEnabled' => 1], '', 'name') as $Image) {
        $tamanho = '';
        $dir = $raiz . '/' . basename((string)$Image->get('path'));
        if (is_dir($dir)) {
            $bytes = 0;
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    $bytes += filesize($f);
                }
            }
            $tamanho = $bytes > 0 ? sprintf('%.1f GB', $bytes / 1e9) : 'vazia';
        }
        printf("%d|%s|%s\n", $Image->get('id'), labfree_ascii($Image->get('name')), $tamanho);
    }
    echo "#!fim\n";
    exit;
}
http_response_code(400);
echo 'acao invalida';
