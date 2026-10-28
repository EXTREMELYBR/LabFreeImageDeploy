<?php
/**
 * LabFree ImageDeploy — a máquina em clonagem conta em que etapa está (e, no fim, o resultado e o registro).
 * Instalado em /var/www/html/fog/service/ pelo LabFreeImageDeploy-root (marca-aplicar).
 * Chamado por /bin/labfree-tela dentro do FOS. Mesmo nível de acesso de service/progress.php do FOG:
 * identifica a máquina pelo MAC e só grava para uma máquina cadastrada.
 *
 * POST: mac, sysuuid, etapa, etapas, texto, parte  [, registro (últimas linhas, durante a clonagem)]
 *       [, resultado (sucesso|erro), mensagem, registro (completo, no fim)]
 * Tabela labfreeEtapas: uma linha por tarefa (taskID), com a última etapa, o registro (ao vivo e, no fim, completo)
 * e o resultado.
 */
require '../commons/base.inc.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/plain; charset=utf-8');

function labfree_texto($v, $max)
{
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$v);
    return mb_substr(strip_tags($t), 0, $max, 'UTF-8');
}

try {
    FOGCore::getHostItem(false);
    $Host = FOGCore::$Host;
    if (!$Host || !$Host->isValid()) {
        throw new Exception('maquina nao cadastrada');
    }
    $hostID = (int)$Host->get('id');
    $Task = $Host->get('task');
    $taskID = ($Task && $Task->isValid()) ? (int)$Task->get('id') : 0;
    $pdo = new PDO('mysql:host=' . DATABASE_HOST . ';dbname=' . DATABASE_NAME . ';charset=utf8mb4', DATABASE_USERNAME, DATABASE_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS labfreeEtapas (
        taskID INT NOT NULL PRIMARY KEY, hostID INT NOT NULL, etapa TINYINT NOT NULL DEFAULT 0, etapas TINYINT NOT NULL DEFAULT 5,
        texto VARCHAR(255) NOT NULL DEFAULT '', parte SMALLINT NOT NULL DEFAULT 0, resultado VARCHAR(10) NOT NULL DEFAULT '',
        mensagem TEXT NULL, registro MEDIUMTEXT NULL, atualizada DATETIME NOT NULL, KEY (hostID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE labfreeEtapas ADD COLUMN IF NOT EXISTS velocidade VARCHAR(30) NOT NULL DEFAULT ''");
    $resultado = in_array($_POST['resultado'] ?? '', ['sucesso', 'erro'], true) ? $_POST['resultado'] : '';
    if (!$taskID) {
        // a tarefa já foi concluída no servidor (o resultado chega depois): vale a última desta máquina
        $r = $pdo->prepare('SELECT taskID FROM labfreeEtapas WHERE hostID = ? ORDER BY atualizada DESC LIMIT 1');
        $r->execute([$hostID]);
        $taskID = (int)$r->fetchColumn();
        if (!$taskID || !$resultado) {
            throw new Exception('sem tarefa ativa');
        }
    }
    $dados = [
        $taskID, $hostID,
        max(0, min(9, (int)($_POST['etapa'] ?? 0))), max(1, min(9, (int)($_POST['etapas'] ?? 5))),
        labfree_texto($_POST['texto'] ?? '', 250), max(0, min(999, (int)($_POST['parte'] ?? 0))),
        labfree_texto($_POST['velocidade'] ?? '', 30),
    ];
    if ($resultado) {
        $st = $pdo->prepare('INSERT INTO labfreeEtapas (taskID, hostID, etapa, etapas, texto, parte, velocidade, resultado, mensagem, registro, atualizada)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE etapa=VALUES(etapa), etapas=VALUES(etapas), texto=VALUES(texto),
            parte=VALUES(parte), resultado=VALUES(resultado), mensagem=VALUES(mensagem), registro=VALUES(registro), atualizada=NOW()');
        $st->execute(array_merge($dados, [$resultado, labfree_texto($_POST['mensagem'] ?? '', 2000), labfree_texto($_POST['registro'] ?? '', 65000)]));
    } elseif (isset($_POST['registro'])) {
        // registro ao vivo: as últimas linhas, para o painel acompanhar (o completo chega com o resultado)
        $st = $pdo->prepare('INSERT INTO labfreeEtapas (taskID, hostID, etapa, etapas, texto, parte, velocidade, registro, atualizada)
            VALUES (?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE etapa=VALUES(etapa), etapas=VALUES(etapas), texto=VALUES(texto),
            parte=VALUES(parte), velocidade=VALUES(velocidade), registro=VALUES(registro), atualizada=NOW()');
        $st->execute(array_merge($dados, [labfree_texto($_POST['registro'], 8000)]));
    } else {
        $st = $pdo->prepare('INSERT INTO labfreeEtapas (taskID, hostID, etapa, etapas, texto, parte, velocidade, atualizada) VALUES (?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE etapa=VALUES(etapa), etapas=VALUES(etapas), texto=VALUES(texto), parte=VALUES(parte),
            velocidade=VALUES(velocidade), atualizada=NOW()');
        $st->execute($dados);
    }
    echo '#!ok';
} catch (Exception $e) {
    echo $e->getMessage();
}
