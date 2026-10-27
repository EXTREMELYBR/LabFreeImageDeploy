<?php
/**
 * LabFree ImageDeploy simplificado - backend JSON.
 *
 * Customização local (não faz parte do FOG). Usa as próprias classes do FOG
 * para tudo que altera dados (tarefas, multicast, cadastros), então valem as
 * mesmas regras e validações do painel original. Leituras usam SQL direto,
 * só para montar as telas.
 *
 * Login: usuários da base (tabela users). Senha de fábrica (admin) exige troca no primeiro acesso.
 */
define('FOG_WANTS_SESSION', true);
require '/var/www/html/fog/commons/base.inc.php';

// base.inc.php minifica a saída HTML; aqui só sai JSON.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set(trim((string)@file_get_contents('/etc/timezone')) ?: 'America/Sao_Paulo');

/** Erro com mensagem para a tela (as funções lançam; as rotas respondem). */
final class PainelErro extends Exception
{
}

final class Painel
{
    const SERVICOS = [
        'kea-dhcp4-server'    => 'DHCP (entrega IP às máquinas)',
        'tftpd-hpa'           => 'TFTP (boot pela rede)',
        'apache2'             => 'Servidor web',
        'mariadb'             => 'Banco de dados',
        'nfs-server'          => 'NFS (envio/recebimento das imagens)',
        'vsftpd'              => 'FTP (finaliza capturas)',
        'LabFreeImageDeploy-multicast'          => 'Envio em grupo (multicast)',
        'LabFreeImageDeploy-agendador'          => 'Agendador de tarefas',
        'LabFreeImageDeploy-replicacao-imagens' => 'Replicação de imagens',
        'chrony'              => 'Hora da rede (NTP)',
    ];
    const ESTADOS_ATIVOS = [0, 1, 2, 3];

    /** @var PDO */
    private static $db;

    public static function db()
    {
        if (!self::$db) {
            self::$db = new PDO(
                'mysql:host=' . DATABASE_HOST . ';dbname=' . DATABASE_NAME . ';charset=utf8mb4',
                DATABASE_USERNAME,
                DATABASE_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return self::$db;
    }

    public static function q($sql, $args = [])
    {
        $st = self::db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function responder($dados, $codigo = 200)
    {
        http_response_code($codigo);
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function erro($msg, $codigo = 400)
    {
        self::responder(['erro' => $msg], $codigo);
    }

    /** Texto vindo do banco do FOG (que guarda com entidades HTML) em texto puro. */
    public static function txt($v)
    {
        return html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Datas do FOG ficam no fuso FOG_TZ_INFO; o painel mostra no fuso do servidor. */
    public static function data($v)
    {
        if (!$v || strpos((string)$v, '0000-00-00') === 0) {
            return null;
        }
        static $tzFog = null, $tzLocal = null;
        if (!$tzFog) {
            $tzFog = new DateTimeZone(FOGCore::getSetting('FOG_TZ_INFO') ?: 'UTC');
            $local = trim((string)@file_get_contents('/etc/timezone')) ?: 'America/Sao_Paulo';
            $tzLocal = new DateTimeZone($local);
        }
        try {
            $d = new DateTime((string)$v, $tzFog);
        } catch (Exception $e) {
            return null;
        }
        return $d->setTimezone($tzLocal)->format(DATE_ATOM);
    }

    public static function corpo()
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
            self::erro('Pedido grande demais.', 413);
        }
        $j = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);
        return is_array($j) ? $j : [];
    }

    public static function usuario()
    {
        global $currentUser;
        return ($currentUser instanceof User && $currentUser->isValid()) ? $currentUser : null;
    }

    const INATIVIDADE = 4 * 3600; // sessão sem nenhuma ação do usuário por 4 h pede login de novo

    /** Impressão digital da senha atual: trocar ou redefinir a senha derruba as outras sessões desse usuário. */
    public static function marcaSenha($id)
    {
        $hash = (string)(self::q('SELECT uPass FROM users WHERE uId=?', [(int)$id])[0]['uPass'] ?? '');
        return substr(hash('sha256', $hash), 0, 16);
    }

    /** A sessão ainda vale? (mesmas regras de exigirLogin, sem responder) */
    public static function sessaoValida()
    {
        $u = self::usuario();
        if (!$u) {
            return false;
        }
        if (isset($_SESSION['PAINEL_SENHA']) && !hash_equals((string)$_SESSION['PAINEL_SENHA'], self::marcaSenha($u->get('id')))) {
            return false;
        }
        return !(isset($_SESSION['PAINEL_ATIVIDADE']) && time() - (int)$_SESSION['PAINEL_ATIVIDADE'] > self::INATIVIDADE);
    }

    public static function exigirLogin($acao = '')
    {
        $u = self::usuario();
        $ok = (bool)$u;
        if ($ok && isset($_SESSION['PAINEL_SENHA']) && !hash_equals((string)$_SESSION['PAINEL_SENHA'], self::marcaSenha($u->get('id')))) {
            $ok = false; // a senha mudou depois deste login
        }
        if ($ok && isset($_SESSION['PAINEL_ATIVIDADE']) && time() - (int)$_SESSION['PAINEL_ATIVIDADE'] > self::INATIVIDADE) {
            $ok = false;
        }
        if (!$ok) {
            if ($u) {
                $u->logout();
            }
            self::erro('Sua sessão expirou. Entre novamente.', 401);
        }
        // a atualização automática da tela (resumo/tarefas) não conta como uso
        if (!in_array($acao, ['resumo', 'tarefas', 'sessao'], true) || !isset($_SESSION['PAINEL_ATIVIDADE'])) {
            $_SESSION['PAINEL_ATIVIDADE'] = time();
        }
        if (!isset($_SESSION['PAINEL_SENHA'])) {
            $_SESSION['PAINEL_SENHA'] = self::marcaSenha($u->get('id'));
        }
    }

    // ------------------------------------------------------------ tentativas de login

    const LOGIN_LIMITE = 10;      // erros seguidos por IP antes de bloquear
    const LOGIN_BLOQUEIO = 900;   // segundos de bloqueio

    private static function arqTentativas()
    {
        $dir = session_save_path() ?: sys_get_temp_dir();
        $dir = preg_replace('/^.*;/', '', $dir);
        return rtrim($dir, '/') . '/LabFreeImageDeploy-login-' . md5((string)($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
    }

    /** Conta uma tentativa ANTES de conferir a senha, sob trava (pedidos em paralelo não escapam do limite). */
    public static function loginContar()
    {
        $arq = self::arqTentativas();
        $fh = @fopen($arq, 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $t = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($t) || time() - (int)($t['ultima'] ?? 0) > self::LOGIN_BLOQUEIO) {
            $t = ['erros' => 0, 'ultima' => 0];
        }
        if ($t['erros'] >= self::LOGIN_LIMITE) {
            flock($fh, LOCK_UN);
            fclose($fh);
            $min = (int)ceil((self::LOGIN_BLOQUEIO - (time() - $t['ultima'])) / 60);
            self::erro("Muitas tentativas erradas. Aguarde $min minuto(s) e tente de novo.", 429);
        }
        $t['erros']++;
        $t['ultima'] = time();
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($t));
        flock($fh, LOCK_UN);
        fclose($fh);
        // de vez em quando apaga contadores velhos de outros endereços
        if (mt_rand(1, 50) === 1) {
            foreach (glob(dirname($arq) . '/LabFreeImageDeploy-login-*.json') ?: [] as $f) {
                if (filemtime($f) < time() - 86400) {
                    @unlink($f);
                }
            }
        }
    }

    public static function loginOk()
    {
        @unlink(self::arqTentativas());
    }

    /** Pedidos que alteram algo precisam do token da sessão no cabeçalho. */
    public static function exigirToken()
    {
        $enviado = (string)($_SERVER['HTTP_X_PAINEL_TOKEN'] ?? '');
        $esperado = (string)($_SESSION['PAINEL_TOKEN'] ?? '');
        if ($esperado === '' || !hash_equals($esperado, $enviado)) {
            self::erro('Pedido recusado por segurança. Recarregue a página.', 403);
        }
    }

    public static function token()
    {
        if (empty($_SESSION['PAINEL_TOKEN'])) {
            $_SESSION['PAINEL_TOKEN'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['PAINEL_TOKEN'];
    }

    // ------------------------------------------------------------------ dados

    public static function noArmazenamento()
    {
        $r = self::q("SELECT ngmHostname ip, ngmInterface iface, ngmRootPath raiz FROM nfsGroupMembers WHERE ngmIsMasterNode='1' ORDER BY ngmID LIMIT 1");
        return $r ? $r[0] : ['ip' => '192.168.31.1', 'iface' => '', 'raiz' => '/images'];
    }

    /** Último lease do Kea por MAC: quem deu boot na rede de clonagem e quando. */
    public static function leases()
    {
        // o Kea limpa o arquivo de hora em hora (LFC): as concessões podem estar em .csv, .csv.1, .csv.2 ou .completed
        $arqs = glob('/var/lib/kea/kea-leases4.csv*') ?: [];
        usort($arqs, fn($a, $b) => filemtime($a) <=> filemtime($b));
        $out = [];
        foreach ($arqs as $arq) {
        if (!is_readable($arq) || !($fh = fopen($arq, 'r'))) {
            continue;
        }
        $cab = fgetcsv($fh, 0, ',', '"', '');
        while (($l = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (!$cab || count($l) < count($cab)) {
                continue;
            }
            $r = array_combine($cab, array_slice($l, 0, count($cab)));
            $mac = strtolower($r['hwaddr'] ?? '');
            if (!$mac || (isset($r['state']) && $r['state'] !== '0')) {
                continue;
            }
            $visto = (int)$r['expire'] - (int)$r['valid_lifetime'];
            if (!isset($out[$mac]) || $visto >= $out[$mac]['visto_ts']) {
                $out[$mac] = [
                    'mac' => $mac,
                    'ip' => $r['address'],
                    'nome_rede' => rtrim((string)$r['hostname'], '.'),
                    'visto_ts' => $visto,
                    'visto' => date(DATE_ATOM, $visto),
                ];
            }
        }
        fclose($fh);
        }
        return $out;
    }

    /** Lê o layout de disco salvo na imagem para explicar onde ela cabe. */
    public static function infoDisco($caminho, $tipo)
    {
        $dir = rtrim(self::noArmazenamento()['raiz'], '/') . '/' . $caminho;
        $info = ['existe' => is_dir($dir), 'bytes' => 0, 'capturada' => null, 'disco_origem' => null, 'minimo_bytes' => null];
        if (!$info['existe']) {
            return $info;
        }
        $ultima = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $info['bytes'] += filesize($f);
                $ultima = max($ultima, filemtime($f));
            }
        }
        $info['capturada'] = $ultima ? date(DATE_ATOM, $ultima) : null;
        // Redimensionável: o mínimo é o layout encolhido; fixo: o layout original.
        $arqs = $tipo == 1 ? ['d1.minimum.partitions', 'd1.partitions'] : ['d1.partitions'];
        foreach ($arqs as $nome) {
            $p = "$dir/$nome";
            if (!is_readable($p)) {
                continue;
            }
            $setor = 512;
            $fim = 0;
            foreach (file($p) as $linha) {
                if (preg_match('/^sector-size:\s*(\d+)/', $linha, $m)) {
                    $setor = (int)$m[1];
                } elseif (preg_match('/^device:\s*(\S+)/', $linha, $m)) {
                    $info['disco_origem'] = basename($m[1]);
                } elseif (preg_match('/start=\s*(\d+),\s*size=\s*(\d+)/', $linha, $m)) {
                    $fim = max($fim, (int)$m[1] + (int)$m[2]);
                }
            }
            if ($fim) {
                // +1 MiB para a tabela GPT de backup no fim do disco
                $info['minimo_bytes'] = $fim * $setor + 1048576;
                break;
            }
        }
        return $info;
    }

    public static function servicos()
    {
        $servicos = self::SERVICOS;
        // o PHP-FPM atende o boot das máquinas (/fog/service); a versão depende do sistema
        foreach (glob('/etc/systemd/system/multi-user.target.wants/php*-fpm.service') ?: [] as $u) {
            $servicos[basename($u, '.service')] = 'PHP (boot das máquinas)';
        }
        $nomes = array_keys($servicos);
        $saida = (string)shell_exec('systemctl is-active ' . implode(' ', array_map('escapeshellarg', $nomes)) . ' 2>/dev/null');
        $estados = preg_split('/\s+/', trim($saida));
        $lista = [];
        foreach ($nomes as $i => $n) {
            $lista[] = ['servico' => $n, 'descricao' => $servicos[$n], 'ativo' => ($estados[$i] ?? '') === 'active'];
        }
        return $lista;
    }

    public static function rede()
    {
        $no = self::noArmazenamento();
        $iface = $no['iface'];
        $base = "/sys/class/net/$iface";
        $existe = $iface !== '' && is_dir($base);
        $cabo = $existe && trim((string)@file_get_contents("$base/carrier")) === '1';
        $ips = $existe ? (string)shell_exec('ip -4 -o addr show dev ' . escapeshellarg($iface) . ' 2>/dev/null') : '';
        $temIp = strpos($ips, ' ' . $no['ip'] . '/') !== false;
        // Kea com socket aberto na porta 67 do IP do FOG? (/proc/net/udp: IP little-endian em hex)
        $hexIp = strtoupper(implode('', array_reverse(array_map(function ($o) {
            return sprintf('%02x', (int)$o);
        }, explode('.', $no['ip'])))));
        $dhcp = false;
        foreach (@file('/proc/net/udp') ?: [] as $l) {
            if (strpos($l, " $hexIp:0043 ") !== false) {
                $dhcp = true;
                break;
            }
        }
        return ['interface' => $iface, 'ip' => $no['ip'], 'adaptador' => $existe, 'cabo' => $cabo, 'ip_ok' => $temIp, 'dhcp_escutando' => $dhcp];
    }

    public static function imagens()
    {
        $rows = self::q("SELECT i.imageID id, i.imageName nome, i.imageDesc descricao, i.imagePath caminho, i.imageTypeID tipo,
                it.imageTypeName tipo_nome, i.imageOSID os_id, o.osName os, i.imageDateTime criada, i.imageLastDeploy ultimo_deploy,
                i.imageEnabled ativa, i.imageProtect protegida,
                (SELECT COUNT(*) FROM hosts h WHERE h.hostImage = i.imageID) maquinas
            FROM images i LEFT JOIN imageTypes it ON it.imageTypeID = i.imageTypeID LEFT JOIN os o ON o.osID = i.imageOSID
            ORDER BY i.imageName");
        foreach ($rows as &$r) {
            $r['nome'] = self::txt($r['nome']);
            $r['descricao'] = self::txt($r['descricao']);
            $r['os'] = self::txt($r['os']);
            $r['criada'] = self::data($r['criada']);
            $r['ultimo_deploy'] = self::data($r['ultimo_deploy']);
            $r['redimensionavel'] = (int)$r['tipo'] === 1;
            $r['disco'] = self::infoDisco($r['caminho'], (int)$r['tipo']);
        }
        return $rows;
    }

    public static function maquinas()
    {
        $rows = self::q("SELECT h.hostID id, h.hostName nome, h.hostDesc descricao, h.hostImage imagem_id, i.imageName imagem,
                h.hostPingCode ping, h.hostCreateDate criada,
                (SELECT LOWER(m.hmMAC) FROM hostMAC m WHERE m.hmHostID = h.hostID AND m.hmPrimary = '1' LIMIT 1) mac
            FROM hosts h LEFT JOIN images i ON i.imageID = h.hostImage ORDER BY h.hostName");
        $ativas = [];
        foreach (self::q("SELECT t.taskHostID hid, tt.ttName tipo, t.taskStateID estado, t.taskPercentText pct
                FROM tasks t JOIN taskTypes tt ON tt.ttID = t.taskTypeID WHERE t.taskStateID IN (1,2,3)") as $t) {
            $ativas[$t['hid']] = $t;
        }
        $ultimas = [];
        foreach (self::q("SELECT ilHostID hid, ilType tipo, ilFinishTime fim, ilImageName img FROM imagingLog
                WHERE ilID IN (SELECT MAX(ilID) FROM imagingLog GROUP BY ilHostID)") as $l) {
            $ultimas[$l['hid']] = $l;
        }
        $leases = self::leases();
        $cadastrados = [];
        foreach ($rows as &$r) {
            $r['nome'] = self::txt($r['nome']);
            $r['descricao'] = preg_match('/^Created by FOG Reg/i', self::txt($r['descricao'])) ? 'Cadastrada pelo menu de boot da rede' : self::txt($r['descricao']);
            $r['imagem'] = self::txt($r['imagem']);
            $r['criada'] = self::data($r['criada']);
            $r['tarefa'] = isset($ativas[$r['id']]) ? [
                'tipo' => self::tipoTarefa($ativas[$r['id']]['tipo']),
                'estado' => (int)$ativas[$r['id']]['estado'],
                'pct' => (int)$ativas[$r['id']]['pct'],
            ] : null;
            $u = $ultimas[$r['id']] ?? null;
            $r['ultima'] = $u ? ['tipo' => $u['tipo'] === 'up' ? 'Captura' : 'Restauração', 'quando' => self::data($u['fim']), 'imagem' => self::txt($u['img'])] : null;
            $r['rede'] = $leases[$r['mac']] ?? null;
            $cadastrados[$r['mac']] = true;
        }
        $outrasMacs = self::q("SELECT LOWER(hmMAC) mac FROM hostMAC");
        foreach ($outrasMacs as $m) {
            $cadastrados[$m['mac']] = true;
        }
        $novas = array_values(array_filter($leases, function ($l) use ($cadastrados) {
            return !isset($cadastrados[$l['mac']]);
        }));
        usort($novas, function ($a, $b) {
            return $b['visto_ts'] <=> $a['visto_ts'];
        });
        return ['maquinas' => $rows, 'nao_cadastradas' => $novas];
    }

    public static function tipoTarefa($nome)
    {
        $mapa = ['Deploy' => 'Restauração', 'Capture' => 'Captura', 'Multi-Cast' => 'Restauração em grupo (multicast)',
            'Deploy - Debug' => 'Restauração (depuração)', 'Capture - Debug' => 'Captura (depuração)',
            'Deploy - No Snapins' => 'Restauração', 'Hardware Inventory' => 'Inventário de hardware',
            'Memtest86+' => 'Teste de memória', 'Disk Surface Test' => 'Teste de disco', 'Wipe' => 'Apagar disco'];
        return $mapa[$nome] ?? $nome;
    }

    public static function estadoTexto($id)
    {
        return [0 => 'Iniciando', 1 => 'Aguardando a máquina ligar', 2 => 'Máquina conectada, na fila', 3 => 'Em andamento',
            4 => 'Concluída', 5 => 'Cancelada', 6 => 'Falhou'][(int)$id] ?? 'Desconhecido';
    }

    /**
     * Acompanhamento ao vivo: a máquina manda o andamento (progress.php do FOG) a cada poucos segundos, mas o banco
     * não guarda QUANDO chegou a última notícia nem em que parte do disco ela está. Este arquivo guarda, por tarefa,
     * a última mudança vista e a sequência de partes (cada parte do disco recomeça em 0%).
     */
    private static function progressoMemoria($ativas)
    {
        $arq = rtrim(preg_replace('/^.*;/', '', session_save_path() ?: sys_get_temp_dir()), '/') . '/LabFreeImageDeploy-progresso.json';
        $fh = @fopen($arq, 'c+');
        $mem = [];
        if ($fh && flock($fh, LOCK_EX)) {
            $mem = json_decode((string)stream_get_contents($fh), true) ?: [];
        }
        $agora = time();
        $nova = [];
        foreach ($ativas as $t) {
            $id = (string)$t['id'];
            $assinatura = $t['estado'] . '|' . $t['pct'] . '|' . $t['copiado'] . '|' . $t['total'];
            $m = $mem[$id] ?? ['assinatura' => '', 'mudou' => $agora, 'partes' => [], 'desde_inicio' => false];
            if ($m['assinatura'] !== $assinatura) {
                $m['assinatura'] = $assinatura;
                $m['mudou'] = $agora;
            }
            if ((int)$t['estado'] === 3 && $t['total'] !== '' && $t['total'] !== null) {
                if (!$m['partes']) {
                    // só dá para numerar as partes se acompanhamos desde o começo da cópia
                    $m['desde_inicio'] = (int)$t['pct'] <= 5;
                }
                if (end($m['partes']) !== $t['total']) {
                    $m['partes'][] = $t['total'];
                }
            }
            $nova[$id] = $m;
        }
        if ($fh) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($nova));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return $nova;
    }

    /** Etapas informadas pelas máquinas (tela do LabFree no FOS), por tarefa. Tabela criada pelo ajudante. */
    public static function etapas($ids)
    {
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) {
            return [];
        }
        try {
            $r = self::q('SELECT taskID, etapa, etapas, texto, parte, velocidade, resultado, mensagem, (registro IS NOT NULL AND registro <> \'\') tem_registro,
                    UNIX_TIMESTAMP(atualizada) quando FROM labfreeEtapas WHERE taskID IN (' . implode(',', $ids) . ')');
        } catch (Throwable $e) {
            return []; // tabela ainda não criada (versão antiga do sistema de clonagem)
        }
        return array_column($r, null, 'taskID');
    }

    /** Fase legível de uma tarefa ativa, para a tela. */
    private static function fase(&$t, $mem, $maq = null)
    {
        $estado = (int)$t['estado'];
        $captura = strpos($t['tipo'], 'Captura') === 0;
        $temDados = $t['total'] !== '' && $t['total'] !== null;
        $t['sem_noticia'] = max(0, time() - (int)($mem['mudou'] ?? time()));
        $t['parte'] = ($mem['desde_inicio'] ?? false) ? count($mem['partes'] ?? []) : null;
        if ($estado === 1) {
            $t['fase'] = 'aguardando';
            $t['fase_texto'] = 'Aguardando a máquina ligar pela rede';
        } elseif ($estado === 2) {
            $t['fase'] = 'fila';
            $t['fase_texto'] = 'Máquina conectada, aguardando vaga para começar';
        } elseif (!$temDados) {
            $t['fase'] = 'preparando';
            $t['fase_texto'] = $captura ? 'Preparando: lendo as partições do disco' : 'Preparando o disco (partições)';
        } elseif ($t['pct'] >= 100) {
            $t['fase'] = 'finalizando';
            $t['fase_texto'] = $captura ? 'Finalizando: conferindo e guardando a imagem' : 'Finalizando: ajustando o disco para iniciar';
        } else {
            $t['fase'] = 'copiando';
            $parte = $t['parte'] ? ' (parte ' . $t['parte'] . ' do disco)' : '';
            $t['fase_texto'] = ($captura ? 'Copiando o disco para o servidor' : 'Gravando no disco') . $parte;
        }
        // a própria máquina disse em que etapa está (tela do LabFree): é a informação mais exata
        if ($maq && (int)$t['estado'] === 3 && $maq['texto'] !== '') {
            $t['etapa'] = (int)$maq['etapa'];
            $t['etapas'] = (int)$maq['etapas'];
            $t['fase_texto'] = $maq['texto'];
            if (($maq['velocidade'] ?? '') !== '') {
                $t['velocidade'] = $maq['velocidade']; // com a unidade que a máquina mediu (MB/min ou GB/min)
            }
            if ((int)$maq['parte'] > 0 && $t['fase'] === 'copiando') {
                $t['parte'] = (int)$maq['parte'];
            }
            // notícia da máquina também conta como sinal de vida
            $t['sem_noticia'] = min($t['sem_noticia'], max(0, time() - (int)$maq['quando']));
        }
        // sem notícia há muito tempo, enquanto deveria estar trabalhando: pode ter travado ou desligado
        $limite = $t['fase'] === 'copiando' ? 180 : ($t['fase'] === 'preparando' || $t['fase'] === 'finalizando' ? 900 : 0);
        $t['parada'] = $limite > 0 && $t['sem_noticia'] > $limite;
    }

    /** Mensagens de erro do FOS em português (as mais comuns); o texto original segue junto. */
    public static function motivo($texto)
    {
        $texto = trim(preg_replace('/\s+/', ' ', (string)$texto));
        if ($texto === '') {
            return '';
        }
        $mapa = [
            '/not (?:large|big) enough|too small|smaller than|minimum size/i' => 'O disco desta máquina é menor que o necessário para a imagem.',
            '/could not find any.*(disk|drive)|no (hard )?dis[kc]s? (found|detected)|Unable to locate.*disk/i' => 'Nenhum disco foi encontrado na máquina (confira na BIOS se o disco aparece e se o modo SATA está em AHCI).',
            '/hibernat|unclean|dirty/i' => 'O Windows não foi desligado por completo (hibernação/inicialização rápida). Desligue segurando Shift e tente de novo.',
            '/BitLocker/i' => 'O disco está protegido pelo BitLocker. Desative o BitLocker no Windows e tente de novo.',
            '/Unable to locate image store|Could not locate file|not exist.*image/i' => 'Os arquivos da imagem não foram encontrados no servidor.',
            '/GPT partition tables|partition table|sgdisk|sfdisk/i' => 'Não foi possível recriar as partições: o disco desta máquina provavelmente é menor que o disco de onde a imagem veio (ou está com defeito).',
            '/space|No space left/i' => 'Falta espaço no servidor para guardar a imagem.',
            '/mount|nfs/i' => 'A máquina não conseguiu acessar a pasta de imagens do servidor (rede ou serviço NFS).',
            '/partclone|exit code|failed to restore|restore.*failed/i' => 'A gravação dos dados falhou (problema no disco da máquina ou na rede durante a cópia).',
        ];
        foreach ($mapa as $re => $pt) {
            if (preg_match($re, $texto)) {
                return $pt; // o texto técnico completo fica em "Ver registro"
            }
        }
        return mb_substr($texto, 0, 300);
    }

    /** Registro completo enviado pela máquina no fim de uma tarefa (tela do LabFree). */
    public static function registro($id)
    {
        $id = (int)$id;
        $r = [];
        try {
            $r = self::q('SELECT e.resultado, e.mensagem, e.registro, e.atualizada, h.hostName maquina FROM labfreeEtapas e
                LEFT JOIN hosts h ON h.hostID = e.hostID WHERE e.taskID = ?', [$id]);
        } catch (Throwable $e) {
        }
        $erros = self::q("SELECT logText, createTime FROM taskLog WHERE taskID = ? AND logType IN ('error','warning') ORDER BY id", [$id]);
        if (!$r && !$erros) {
            self::erro('Esta tarefa não tem registro (ela foi feita antes da versão que guarda o registro, ou a máquina não chegou a enviar).', 404);
        }
        $x = $r[0] ?? [];
        return ['maquina' => self::txt($x['maquina'] ?? ''), 'resultado' => $x['resultado'] ?? '', 'mensagem' => (string)($x['mensagem'] ?? ''),
            'registro' => (string)($x['registro'] ?? ''), 'quando' => self::data($x['atualizada'] ?? null),
            'erros' => array_map(function ($l) {
                return ['texto' => $l['logText'], 'motivo' => self::motivo($l['logText']), 'quando' => self::data($l['createTime'])];
            }, $erros)];
    }

    /** Estado atual das tarefas indicadas (acompanhamento logo depois de agendar; sem limite de 30). */
    public static function acompanhar($ids)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$ids))));
        if (!$ids || count($ids) > 500) {
            return ['tarefas' => []];
        }
        $ativas = array_column(self::tarefas(false)['ativas_todas'], null, 'id');
        $etapas = self::etapas($ids);
        $saida = [];
        foreach (self::q('SELECT t.taskID id, h.hostName maquina, t.taskStateID estado FROM tasks t LEFT JOIN hosts h ON h.hostID = t.taskHostID
                WHERE t.taskID IN (' . implode(',', $ids) . ')') as $t) {
            if (isset($ativas[$t['id']])) {
                $saida[] = $ativas[$t['id']] + ['ativa' => true];
                continue;
            }
            $m = $etapas[$t['id']] ?? null;
            $saida[] = ['id' => (int)$t['id'], 'maquina' => self::txt($t['maquina']), 'ativa' => false, 'estado' => (int)$t['estado'],
                'estado_texto' => self::estadoTexto($t['estado']), 'motivo' => self::motivo($m['mensagem'] ?? ''),
                'tem_registro' => $m ? (bool)$m['tem_registro'] : false];
        }
        return ['tarefas' => $saida];
    }

    public static function tarefas($historico = true)
    {
        $ativas = self::q("SELECT t.taskID id, h.hostName maquina, tt.ttName tipo, t.taskStateID estado, t.taskCreateTime criada,
                t.taskPercentText pct, t.taskDataCopied copiado, t.taskDataTotal total, t.taskTimeElapsed decorrido,
                t.taskTimeRemaining restante, t.taskBPM velocidade, i.imageName imagem,
                (SELECT MAX(a.msID) FROM multicastSessionsAssoc a WHERE a.tID = t.taskID) sessao
            FROM tasks t JOIN hosts h ON h.hostID = t.taskHostID JOIN taskTypes tt ON tt.ttID = t.taskTypeID
            LEFT JOIN images i ON i.imageID = t.taskImageID
            WHERE t.taskStateID IN (1,2,3) ORDER BY t.taskID");
        $memoria = self::progressoMemoria($ativas);
        $daMaquina = self::etapas(array_column($ativas, 'id'));
        foreach ($ativas as &$t) {
            $t['maquina'] = self::txt($t['maquina']);
            $t['imagem'] = self::txt($t['imagem']);
            $t['tipo'] = self::tipoTarefa($t['tipo']);
            $t['estado_texto'] = self::estadoTexto($t['estado']);
            $t['criada'] = self::data($t['criada']);
            $t['pct'] = (int)$t['pct'];
            $t['velocidade'] = ''; // o FOG guarda só o número (sem MB/GB); a tela do LabFree manda o valor com a unidade
            $t['sessao'] = $t['sessao'] ? (int)$t['sessao'] : null;
            self::fase($t, $memoria[(string)$t['id']] ?? [], $daMaquina[$t['id']] ?? null);
        }
        unset($t);
        // ativas sempre todas (uma sessão antiga presa precisa aparecer para ser cancelada); histórico só as 15 últimas
        $sessoes = self::q("(SELECT m.msID id, m.msName nome, m.msState estado, m.msSessClients esperadas, m.msClients conectadas,
                m.msPercent pct, m.msStartDateTime inicio, m.msCompleteDateTime fim, i.imageName imagem, m.msBasePort porta
            FROM multicastSessions m LEFT JOIN images i ON i.imageID = m.msImage WHERE m.msState IN (0,1,2,3))
            UNION ALL
            (SELECT m.msID, m.msName, m.msState, m.msSessClients, m.msClients, m.msPercent, m.msStartDateTime, m.msCompleteDateTime, i.imageName,
                m.msBasePort
            FROM multicastSessions m LEFT JOIN images i ON i.imageID = m.msImage WHERE m.msState NOT IN (0,1,2,3) ORDER BY m.msID DESC LIMIT 15)
            ORDER BY id DESC");
        $sessAtivas = [];
        $sessHist = [];
        foreach ($sessoes as $s) {
            $s['nome'] = self::txt($s['nome']);
            $s['imagem'] = self::txt($s['imagem']);
            $s['estado_texto'] = self::estadoTexto($s['estado']);
            $s['inicio'] = self::data($s['inicio']);
            $s['fim'] = self::data($s['fim']);
            if (in_array((int)$s['estado'], self::ESTADOS_ATIVOS, true)) {
                // as máquinas desta sessão, cada uma com o seu andamento
                $s['maquinas'] = array_values(array_filter($ativas, function ($t) use ($s) {
                    return $t['sessao'] === (int)$s['id'];
                }));
                // máquinas conectadas e andamento do envio, lidos do porteiro (vale também para máquinas sem cadastro)
                $s['grupo'] = self::grupoAndamento($s['porta']);
                $s['conectadas'] = $s['grupo'] ? count($s['grupo']['conectadas'])
                    : max((int)$s['conectadas'], count($s['maquinas']));
                unset($s['porta']);
                $sessAtivas[] = $s;
            } else {
                unset($s['porta']);
                $sessHist[] = $s;
            }
        }
        $todas = $ativas;
        // tarefas de sessões em grupo aparecem dentro da sessão, não soltas
        $ids = array_column($sessAtivas, 'id');
        $ativas = array_values(array_filter($ativas, function ($t) use ($ids) {
            return !$t['sessao'] || !in_array($t['sessao'], $ids);
        }));
        // agendadas há mais de 1 dia: um boot pela rede esquecido apagaria o disco sem aviso
        $esquecidas = count(array_filter($todas, function ($t) {
            return (int)$t['estado'] === 1 && $t['criada'] && strtotime($t['criada']) < time() - 86400;
        }));
        if (!$historico) {
            return ['ativas' => $ativas, 'multicast_ativas' => $sessAtivas, 'ativas_todas' => $todas, 'esquecidas' => $esquecidas];
        }
        $hist = self::q("SELECT t.taskID id, h.hostName maquina, tt.ttName tipo, t.taskStateID estado, t.taskCreateTime criada,
                i.imageName imagem, t.taskCreateBy por
            FROM tasks t LEFT JOIN hosts h ON h.hostID = t.taskHostID JOIN taskTypes tt ON tt.ttID = t.taskTypeID
            LEFT JOIN images i ON i.imageID = t.taskImageID
            WHERE t.taskStateID NOT IN (1,2,3) ORDER BY t.taskID DESC LIMIT 30");
        $daMaquina = self::etapas(array_column($hist, 'id'));
        $motivos = [];
        if ($hist) {
            // motivo da falha que o FOS mandou (taskerror.php grava em taskLog)
            foreach (self::q("SELECT taskID, logText FROM taskLog WHERE logType = 'error' AND taskID IN ("
                . implode(',', array_map('intval', array_column($hist, 'id'))) . ") ORDER BY id") as $l) {
                $motivos[$l['taskID']] = $l['logText'];
            }
        }
        foreach ($hist as &$t) {
            $m = $daMaquina[$t['id']] ?? null;
            $t['tem_registro'] = $m ? (bool)$m['tem_registro'] : false;
            $t['motivo'] = self::motivo(($motivos[$t['id']] ?? '') ?: ($m['mensagem'] ?? ''));
            $t['maquina'] = self::txt($t['maquina']) ?: '(máquina excluída)';
            $t['imagem'] = self::txt($t['imagem']);
            $t['tipo'] = self::tipoTarefa($t['tipo']);
            $t['estado_texto'] = self::estadoTexto($t['estado']);
            $t['criada'] = self::data($t['criada']);
        }
        return ['ativas' => $ativas, 'multicast_ativas' => $sessAtivas, 'historico' => $hist, 'multicast_historico' => $sessHist];
    }

    const GRUPO = '/run/LabFreeImageDeploy/grupo';

    /** Início (campo 22 de /proc/PID/stat) de um processo, para saber se o PID ainda é o mesmo processo. */
    private static function inicioProcesso($pid)
    {
        $st = @file_get_contents('/proc/' . (int)$pid . '/stat');
        if (!$st || ($p = strrpos($st, ')')) === false) {
            return -1;
        }
        return (int)(preg_split('/\s+/', trim(substr($st, $p + 1)))[19] ?? -1);
    }

    /**
     * Andamento de uma sessão em grupo, gravado pelo porteiro do envio (lib/LabFreeImageDeploy-udp-sender) em
     * GRUPO/<porta>.json: máquinas conectadas (por IP, com nome/MAC quando dá), fase e quanto já foi enviado.
     * null quando o porteiro não está cuidando desta sessão (servidor sem a versão nova ou envio ainda não aberto).
     */
    public static function grupoAndamento($porta)
    {
        $arq = self::GRUPO . '/' . (int)$porta . '.json';
        if (!is_file($arq) || is_link($arq)) {
            return null;
        }
        $d = json_decode((string)@file_get_contents($arq), true);
        if (!is_array($d) || empty($d['sh']) || self::inicioProcesso($d['sh']) !== (int)($d['sh_inicio'] ?? -2)) {
            return null; // o envio daquela sessão já acabou (ou é de uma sessão antiga na mesma porta)
        }
        $porIp = [];
        foreach (self::leases() as $l) {
            $porIp[$l['ip']] = $l['mac'];
        }
        $nomes = array_column(self::q('SELECT LOWER(m.hmMAC) mac, h.hostName nome FROM hostMAC m JOIN hosts h ON h.hostID = m.hmHostID'), 'nome', 'mac');
        $lista = [];
        foreach ((array)($d['conectadas'] ?? []) as $ip) {
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            $mac = $porIp[$ip] ?? '';
            $lista[] = ['ip' => $ip, 'mac' => $mac, 'nome' => isset($nomes[$mac]) ? self::txt($nomes[$mac]) : ''];
        }
        usort($lista, fn($a, $b) => strnatcasecmp($a['nome'] ?: 'zz' . $a['ip'], $b['nome'] ?: 'zz' . $b['ip']));
        $total = max(1, (int)($d['total'] ?? 0));
        $enviado = min($total, (int)($d['enviado_antes'] ?? 0) + (int)($d['bytes'] ?? 0));
        $fase = (string)($d['fase'] ?? '');
        $vel = 0;
        if ($fase === 'enviando' && !empty($d['inicio_envio']) && ($seg = (int)$d['atualizado'] - (int)$d['inicio_envio']) >= 5) {
            $vel = (int)($d['bytes'] ?? 0) / $seg;
        }
        return [
            'fase' => in_array($fase, ['aguardando', 'iniciando', 'enviando', 'terminando', 'proxima', 'parte_concluida', 'concluido', 'erro', 'cancelado'], true) ? $fase : 'aguardando',
            'conectadas' => $lista,
            'iniciadas' => (int)($d['iniciadas'] ?? 0),
            'parte' => (int)($d['parte'] ?? 0) + 1,
            'partes' => max(1, (int)($d['partes'] ?? 1)),
            'enviado' => $enviado,
            'total' => $total,
            'pct' => (int)floor($enviado * 100 / $total),
            'velocidade' => (int)$vel,                                    // bytes por segundo
            'restante' => $vel > 0 ? (int)(($total - $enviado) / $vel) : 0, // segundos
            'retiradas' => count((array)($d['retiradas'] ?? [])),
            'espera_desde' => isset($d['criado']) ? date(DATE_ATOM, (int)$d['criado']) : null,
        ];
    }

    /** Botão "Iniciar agora": pede ao porteiro para começar a transmissão com as máquinas que já entraram. */
    public static function iniciarMulticast($d)
    {
        $id = (int)($d['id'] ?? 0);
        $s = self::q('SELECT msID id, msBasePort porta, msState estado, msName nome FROM multicastSessions WHERE msID = ?', [$id])[0] ?? null;
        if (!$s || !in_array((int)$s['estado'], self::ESTADOS_ATIVOS, true)) {
            self::erro('Sessão não encontrada ou já encerrada.');
        }
        $g = self::grupoAndamento($s['porta']);
        if (!$g) {
            self::erro('O servidor ainda está abrindo esta sessão. Aguarde alguns segundos e tente de novo.');
        }
        if ($g['fase'] !== 'aguardando') {
            self::erro('A transmissão desta sessão já começou.');
        }
        if (!$g['conectadas']) {
            self::erro('Nenhuma máquina entrou na sessão ainda.');
        }
        $pedido = self::GRUPO . '/' . (int)$s['porta'] . '.iniciar';
        if (is_link($pedido)) {
            @unlink($pedido);
        }
        if (@file_put_contents($pedido, (string)time()) === false) {
            self::erro('Não foi possível avisar o servidor de envio. Tente de novo.');
        }
        // depois do início, máquinas novas não podem mais entrar (pegariam a imagem pela metade): a base só deixa
        // entrar até msSenderStart + FOG_UDPCAST_MAXWAIT
        self::db()->prepare("UPDATE multicastSessions SET msSenderStart = '2000-01-01 00:00:00' WHERE msID = ?")->execute([$id]);
        $n = count($g['conectadas']);
        return ['ok' => true, 'msg' => 'Transmissão iniciada para ' . ($n === 1 ? '1 máquina.' : "$n máquinas.")];
    }

    /** Quantas máquinas deram boot na rede de clonagem e ainda não estão cadastradas. */
    public static function contarNovas()
    {
        $cad = array_flip(array_column(self::q("SELECT LOWER(hmMAC) mac FROM hostMAC"), 'mac'));
        return count(array_diff_key(self::leases(), $cad));
    }

    public static function resumo()
    {
        $no = self::noArmazenamento();
        $livre = @disk_free_space($no['raiz']) ?: 0;
        $total = @disk_total_space($no['raiz']) ?: 0;
        $tar = self::tarefas(false);
        return [
            'servicos' => self::servicos(),
            'rede' => self::rede(),
            'disco' => ['livre' => $livre, 'total' => $total, 'caminho' => $no['raiz']],
            'contagem' => [
                'maquinas' => (int)self::q("SELECT COUNT(*) n FROM hosts")[0]['n'],
                'imagens' => (int)self::q("SELECT COUNT(*) n FROM images")[0]['n'],
                'novas' => self::contarNovas(),
            ],
            'tarefas' => $tar['ativas'],
            'multicast' => $tar['multicast_ativas'],
            'esquecidas' => $tar['esquecidas'],
            'versao_fog' => defined('FOG_VERSION') ? FOG_VERSION : '',
        ];
    }

    // ---------------------------------------------------------------- ações

    public static function validarNomeMaquina($nome)
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,14}$/', $nome)) {
            self::erro('Nome inválido. Use até 15 caracteres: letras, números e hífen (ex.: LAB01-PC05).');
        }
    }

    public static function host($id)
    {
        $Host = new Host((int)$id);
        if (!$Host->isValid()) {
            self::erro('Máquina não encontrada.', 404);
        }
        return $Host;
    }

    public static function imagem($id)
    {
        $Image = new Image((int)$id);
        if (!$Image->isValid()) {
            self::erro('Imagem não encontrada.', 404);
        }
        return $Image;
    }

    public static function cadastrarMaquina($d)
    {
        $nome = trim((string)($d['nome'] ?? ''));
        self::validarNomeMaquina($nome);
        $MAC = new MACAddress(trim((string)($d['mac'] ?? '')));
        if (!$MAC->isValid()) {
            self::erro('Endereço MAC inválido. Formato: 00:11:22:33:44:55');
        }
        if (FOGCore::getClass('HostManager')->exists($nome)) {
            self::erro("Já existe uma máquina chamada \"$nome\".");
        }
        $existe = self::q("SELECT h.hostName n FROM hostMAC m JOIN hosts h ON h.hostID = m.hmHostID WHERE LOWER(m.hmMAC) = ?", [strtolower($MAC->__toString())]);
        if ($existe) {
            self::erro('Este MAC já está cadastrado na máquina "' . self::txt($existe[0]['n']) . '".');
        }
        $imagemId = (int)($d['imagem_id'] ?? 0);
        if ($imagemId) {
            self::imagem($imagemId);
        }
        $Host = FOGCore::getClass('Host')
            ->set('name', $nome)
            ->set('description', trim((string)($d['descricao'] ?? '')))
            ->set('imageID', $imagemId)
            ->set('modules', FOGCore::getSubObjectIDs('Module', ['isDefault' => 1]))
            ->addPriMAC($MAC);
        if (!$Host->save()) {
            self::erro('Não foi possível cadastrar a máquina.');
        }
        return ['ok' => true, 'id' => (int)$Host->get('id'), 'msg' => "Máquina \"$nome\" cadastrada."];
    }

    public static function editarMaquina($d)
    {
        $Host = self::host($d['id'] ?? 0);
        if (isset($d['nome'])) {
            $nome = trim((string)$d['nome']);
            self::validarNomeMaquina($nome);
            if ($nome !== $Host->get('name') && FOGCore::getClass('HostManager')->exists($nome, $Host->get('id'))) {
                self::erro("Já existe uma máquina chamada \"$nome\".");
            }
            $Host->set('name', $nome);
        }
        if (isset($d['descricao'])) {
            $Host->set('description', trim((string)$d['descricao']));
        }
        if (isset($d['imagem_id'])) {
            $img = (int)$d['imagem_id'];
            if ($img) {
                self::imagem($img);
            }
            $Host->set('imageID', $img);
        }
        if (!$Host->save()) {
            self::erro('Não foi possível salvar.');
        }
        return ['ok' => true, 'msg' => 'Alterações salvas.'];
    }

    public static function excluirMaquina($d)
    {
        $Host = self::host($d['id'] ?? 0);
        if ($Host->get('task')->isValid()) {
            self::erro('Esta máquina tem uma tarefa em andamento. Cancele a tarefa antes de excluir.');
        }
        $nome = self::txt($Host->get('name'));
        if (!$Host->destroy()) {
            self::erro('Não foi possível excluir.');
        }
        return ['ok' => true, 'msg' => "Máquina \"$nome\" excluída. As imagens não foram afetadas."];
    }

    public static function criarImagem($d)
    {
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome) > 40) {
            self::erro('Informe um nome para a imagem (até 40 caracteres).');
        }
        $caminho = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
        if ($caminho === '' || in_array(strtolower($caminho), ['dev', 'postdownloadscripts', 'postinitscripts'], true)) {
            self::erro('Escolha outro nome para a imagem.');
        }
        if (FOGCore::getClass('ImageManager')->exists($nome)) {
            self::erro("Já existe uma imagem chamada \"$nome\".");
        }
        if (FOGCore::getClass('ImageManager')->exists($caminho, '', 'path')) {
            self::erro("Já existe uma imagem usando a pasta \"$caminho\". Escolha outro nome.");
        }
        $tipo = (int)($d['tipo'] ?? 1);
        if (!in_array($tipo, [1, 2, 3, 4], true)) {
            $tipo = 1;
        }
        $os = (int)($d['os_id'] ?? 9);
        $grupo = (int)(self::q("SELECT ngID FROM nfsGroups ORDER BY ngID LIMIT 1")[0]['ngID'] ?? 1);
        $Image = FOGCore::getClass('Image')
            ->set('name', $nome)
            ->set('description', trim((string)($d['descricao'] ?? '')))
            ->set('osID', $os)
            ->set('path', $caminho)
            ->set('imageTypeID', $tipo)
            ->set('imagePartitionTypeID', 1)
            ->set('compress', (int)FOGCore::getSetting('FOG_PIGZ_COMP') ?: 6)
            ->set('isEnabled', 1)
            ->set('format', (int)FOGCore::getSetting('FOG_IMAGE_COMPRESSION_FORMAT_DEFAULT'))
            ->set('toReplicate', 1)
            ->addGroup($grupo);
        if (!$Image->save()) {
            self::erro('Não foi possível criar a imagem.');
        }
        $Image->setPrimaryGroup($grupo);
        return ['ok' => true, 'id' => (int)$Image->get('id'), 'msg' => "Imagem \"$nome\" criada. Agora capture uma máquina para ela."];
    }

    public static function editarImagem($d)
    {
        $Image = self::imagem($d['id'] ?? 0);
        if (isset($d['nome'])) {
            $nome = trim((string)$d['nome']);
            if ($nome === '' || mb_strlen($nome) > 40) {
                self::erro('Informe um nome para a imagem (até 40 caracteres).');
            }
            if ($nome !== self::txt($Image->get('name')) && FOGCore::getClass('ImageManager')->exists($nome, $Image->get('id'))) {
                self::erro("Já existe uma imagem chamada \"$nome\".");
            }
            $Image->set('name', $nome);
        }
        if (isset($d['tipo']) && (int)$d['tipo'] !== (int)$Image->get('imageTypeID')) {
            $info = self::infoDisco($Image->get('path'), (int)$Image->get('imageTypeID'));
            if ($info['bytes'] > 0) {
                self::erro('O tipo só pode ser mudado enquanto a imagem está vazia. Crie uma imagem nova com o tipo desejado e capture de novo.');
            }
            if (!in_array((int)$d['tipo'], [1, 2, 3, 4], true)) {
                self::erro('Tipo de imagem inválido.');
            }
            $Image->set('imageTypeID', (int)$d['tipo']);
        }
        if (isset($d['os_id'])) {
            $Image->set('osID', (int)$d['os_id']);
        }
        if (isset($d['descricao'])) {
            $Image->set('description', trim((string)$d['descricao']));
        }
        if (isset($d['protegida'])) {
            $Image->set('protected', $d['protegida'] ? 1 : 0);
        }
        if (!$Image->save()) {
            self::erro('Não foi possível salvar.');
        }
        return ['ok' => true, 'msg' => 'Imagem atualizada.'];
    }

    public static function excluirImagem($d)
    {
        $Image = self::imagem($d['id'] ?? 0);
        $id = (int)$Image->get('id');
        if ($Image->get('protected')) {
            self::erro('Esta imagem está protegida. Desmarque a proteção antes de excluir.');
        }
        $ocupada = self::q("SELECT (SELECT COUNT(*) FROM tasks WHERE taskImageID=? AND taskStateID IN (1,2,3))
            + (SELECT COUNT(*) FROM multicastSessions WHERE msImage=? AND msState IN (0,1,2,3)) n", [$id, $id]);
        if ((int)$ocupada[0]['n'] > 0) {
            self::erro('Há uma captura ou restauração usando esta imagem agora. Espere terminar ou cancele em Tarefas.');
        }
        $nome = self::txt($Image->get('name'));
        $caminho = $Image->get('path');
        if (!$Image->destroy()) {
            self::erro('Não foi possível excluir a imagem.');
        }
        $msg = "Imagem \"$nome\" excluída.";
        if (!empty($d['apagar_arquivos'])) {
            try {
                $r = self::root('imagem-apagar', ['caminho' => $caminho]);
                $msg .= sprintf(' %.1f GB liberados no servidor.', ($r['bytes'] ?? 0) / 1e9);
            } catch (Throwable $e) {
                $msg .= ' Os arquivos não puderam ser apagados (' . $e->getMessage() . "). A pasta continua em /images/$caminho.";
            }
        } else {
            $msg .= " Os arquivos continuam em /images/$caminho.";
        }
        return ['ok' => true, 'msg' => $msg];
    }

    // ------------------------------------------------------------ usuários

    public static function usuarios()
    {
        global $currentUser;
        $rows = self::q("SELECT uId id, uName nome, uCreateDate criado, uCreateBy criado_por FROM users ORDER BY uName");
        foreach ($rows as &$r) {
            $r['nome'] = self::txt($r['nome']);
            $r['criado'] = self::data($r['criado']);
            $r['eu'] = (int)$r['id'] === (int)$currentUser->get('id');
        }
        return ['usuarios' => $rows];
    }

    /** Senhas de fábrica: "admin" (LabFree ImageDeploy) e "password" (base FOG). Exigem troca no primeiro acesso. */
    const SENHAS_FABRICA = ['admin', 'password'];

    /** A conta ainda usa uma senha de fábrica? */
    public static function senhaPadrao($u)
    {
        $hash = (string)(self::q('SELECT uPass FROM users WHERE uId=?', [(int)$u->get('id')])[0]['uPass'] ?? '');
        if ($hash === '') {
            return false;
        }
        foreach (self::SENHAS_FABRICA as $s) {
            if (password_verify($s, $hash) || hash_equals(md5($s), $hash)) {
                return true;
            }
        }
        return false;
    }

    /** Troca de senha obrigatória pendente nesta sessão? (verificado no servidor, não só na tela) */
    public static function trocaPendente()
    {
        $u = self::usuario();
        if (!$u) {
            return false;
        }
        if (!isset($_SESSION['PAINEL_TROCAR_SENHA'])) {
            $_SESSION['PAINEL_TROCAR_SENHA'] = self::senhaPadrao($u) ? 1 : 0;
        }
        return (bool)$_SESSION['PAINEL_TROCAR_SENHA'];
    }

    /** Primeiro acesso: define a senha nova sem pedir a atual (só enquanto a troca estiver pendente). */
    public static function primeiraSenha($d)
    {
        if (!self::trocaPendente()) {
            self::erro('A senha já foi definida. Para trocar, use "Trocar minha senha".');
        }
        $u = self::usuario();
        $nova = (string)($d['nova'] ?? '');
        if (strcasecmp($nova, self::txt($u->get('name'))) === 0) {
            self::erro('A senha não pode ser igual ao nome de usuário.');
        }
        self::senhaUsuario(['id' => $u->get('id'), 'senha' => $nova], true);
        $_SESSION['PAINEL_TROCAR_SENHA'] = 0;
        $_SESSION['PAINEL_SENHA'] = self::marcaSenha($u->get('id'));
        return ['ok' => true, 'msg' => 'Senha definida. Bem-vindo ao LabFree ImageDeploy!'];
    }

    public static function validarSenha($senha)
    {
        if (mb_strlen((string)$senha) < 8) {
            self::erro('A senha precisa ter pelo menos 8 caracteres.');
        }
        $fracas = array_merge(self::SENHAS_FABRICA, ['senha123', '12345678', '123456789', 'labfree1', 'labfree123', 'imagedeploy', 'mudar123']);
        if (in_array(strtolower((string)$senha), $fracas, true)) {
            self::erro('Essa senha é fácil demais de adivinhar. Escolha outra.');
        }
    }

    public static function criarUsuario($d)
    {
        global $currentUser;
        $nome = trim((string)($d['nome'] ?? ''));
        // a mesma regra do login da base FOG (senão o usuário é criado e nunca consegue entrar)
        if (!preg_match('/^[A-Za-z]/', $nome) || !preg_match('/(?=^.{3,40}$)^[\w][\w0-9]*[._-]?[\w0-9]*[.]?[\w0-9]+$/i', $nome)) {
            self::erro('Nome de usuário: 3 a 40 caracteres, começando com letra; letras e números, com no máximo um ponto, hífen ou sublinhado no meio (ex.: joao.silva).');
        }
        if (FOGCore::getClass('UserManager')->exists($nome)) {
            self::erro("Já existe o usuário \"$nome\".");
        }
        self::confirmarMinhaSenha($d);
        self::validarSenha($d['senha'] ?? '');
        $U = FOGCore::getClass('User')
            ->set('name', $nome)
            ->set('password', (string)$d['senha'])
            ->set('type', 0)
            ->set('createdBy', $currentUser->get('name'))
            ->set('createdTime', FOGCore::formatTime('now', 'Y-m-d H:i:s'));
        if (!$U->save()) {
            self::erro('Não foi possível criar o usuário.');
        }
        return ['ok' => true, 'msg' => "Usuário \"$nome\" criado."];
    }

    /** Ações sobre outros usuários pedem a senha de quem está usando o painel (sessão esquecida aberta não basta). */
    public static function confirmarMinhaSenha($d)
    {
        global $currentUser;
        $Eu = new User((int)$currentUser->get('id'));
        if (!$Eu->passwordValidate($Eu->get('name'), (string)($d['minha_senha'] ?? ''))) {
            self::erro('Para confirmar, digite a SUA senha atual corretamente.', 403);
        }
    }

    public static function senhaUsuario($d, $interno = false)
    {
        global $currentUser;
        $U = new User((int)($d['id'] ?? 0));
        if (!$U->isValid()) {
            self::erro('Usuário não encontrado.', 404);
        }
        if (!$interno) {
            if ((int)$U->get('id') === (int)$currentUser->get('id')) {
                self::erro('Para trocar a sua própria senha, use "Trocar minha senha" (pede a senha atual).');
            }
            self::confirmarMinhaSenha($d);
        }
        self::validarSenha($d['senha'] ?? '');
        $U->set('password', (string)$d['senha']);
        if (!$U->save()) {
            self::erro('Não foi possível trocar a senha.');
        }
        return ['ok' => true, 'msg' => 'Senha de "' . self::txt($U->get('name')) . '" alterada.'];
    }

    public static function minhaSenha($d)
    {
        global $currentUser;
        $U = new User((int)$currentUser->get('id'));
        if (!$U->passwordValidate($U->get('name'), (string)($d['atual'] ?? ''))) {
            self::erro('A senha atual não confere.');
        }
        $r = self::senhaUsuario(['id' => $U->get('id'), 'senha' => $d['nova'] ?? ''], true);
        $_SESSION['PAINEL_SENHA'] = self::marcaSenha($U->get('id')); // esta sessão continua; as outras saem
        return $r;
    }

    public static function excluirUsuario($d)
    {
        global $currentUser;
        $U = new User((int)($d['id'] ?? 0));
        if (!$U->isValid()) {
            self::erro('Usuário não encontrado.', 404);
        }
        if ((int)$U->get('id') === (int)$currentUser->get('id')) {
            self::erro('Você não pode excluir o próprio usuário.');
        }
        self::confirmarMinhaSenha($d);
        if ((int)self::q('SELECT COUNT(*) n FROM users')[0]['n'] <= 1) {
            self::erro('Precisa existir pelo menos um usuário.');
        }
        $nome = self::txt($U->get('name'));
        if (!$U->destroy()) {
            self::erro('Não foi possível excluir o usuário.');
        }
        return ['ok' => true, 'msg' => "Usuário \"$nome\" excluído."];
    }

    /** Erros conhecidos do FOG ao criar tarefa, em português. */
    public static function traduzirErroFog($msg)
    {
        $mapa = [
            '/already a member of an active task|already has a task|in a tasking/i' => 'a máquina já tem uma tarefa em andamento ou agendada (veja em Tarefas)',
            '/not enabled|disabled/i' => 'a imagem está desativada',
            '/no image|image.*not.*(found|valid)|invalid image/i' => 'imagem não encontrada',
            '/storage|node/i' => 'o armazenamento de imagens do servidor não está disponível',
            '/protected/i' => 'a imagem está protegida',
        ];
        foreach ($mapa as $re => $pt) {
            if (preg_match($re, $msg)) {
                return $pt;
            }
        }
        return $msg;
    }

    /** Wake-on-LAN direto pela rede de clonagem (o do FOG passa por /fog/management, que fica bloqueado). */
    public static function acordar($Host)
    {
        if (!function_exists('socket_create')) {
            return;
        }
        $macs = array_column(self::q('SELECT hmMAC FROM hostMAC WHERE hmHostID = ?', [(int)$Host->get('id')]), 'hmMAC');
        $destinos = ['255.255.255.255'];
        $conf = @parse_ini_file('/etc/LabFreeImageDeploy/LabFreeImageDeploy.conf');
        if ($conf && !empty($conf['FOG_IP'])) {
            $prefixo = (int)($conf['FOG_PREFIXO'] ?? 24);
            $ip = ip2long($conf['FOG_IP']);
            $destinos[] = long2ip($ip | ((1 << (32 - $prefixo)) - 1));
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            return;
        }
        socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        foreach ($macs as $mac) {
            $hex = preg_replace('/[^0-9a-f]/i', '', $mac);
            if (strlen($hex) !== 12) {
                continue;
            }
            $pacote = str_repeat(chr(0xff), 6) . str_repeat(hex2bin($hex), 16);
            foreach ($destinos as $dst) {
                foreach ([9, 7] as $porta) {
                    @socket_sendto($sock, $pacote, strlen($pacote), 0, $dst, $porta);
                }
            }
        }
        socket_close($sock);
    }

    public static function criarTarefa($Host, $tipo, $d)
    {
        global $currentUser;
        try {
            $Host->createImagePackage(
                $tipo,
                $tipo == 2 ? 'Captura' : 'Restauração',
                !empty($d['desligar']),
                false,
                false,
                false,
                $currentUser->get('name'),
                '',
                false,
                false
            );
        } catch (Exception $e) {
            return self::traduzirErroFog($e->getMessage());
        }
        if (!empty($d['wol'])) {
            self::acordar($Host);
        }
        return null;
    }

    /** Id da tarefa ativa de uma máquina (para acompanhar depois de agendar). */
    public static function tarefaAtiva($hostId)
    {
        $r = self::q('SELECT taskID FROM tasks WHERE taskHostID = ? AND taskStateID IN (1,2,3) ORDER BY taskID DESC LIMIT 1', [(int)$hostId]);
        return $r ? (int)$r[0]['taskID'] : null;
    }

    public static function capturar($d)
    {
        $Host = self::host($d['maquina_id'] ?? 0);
        $Image = self::imagem($d['imagem_id'] ?? 0);
        if ($Image->get('protected')) {
            self::erro('Esta imagem está protegida contra sobrescrita. Desmarque a proteção na tela Imagens para capturar de novo.');
        }
        $no = self::noArmazenamento();
        $livre = (float)@disk_free_space($no['raiz']);
        $tamanho = (float)(self::infoDisco($Image->get('path'), (int)$Image->get('imageTypeID'))['bytes'] ?? 0);
        $precisa = max(20e9, $tamanho * 1.2);
        if ($livre > 0 && $livre < $precisa) {
            self::erro(sprintf('Pouco espaço no servidor para uma captura: %.1f GB livres (o recomendado é pelo menos %.0f GB). '
                . 'Exclua imagens que não usa mais em Imagens.', $livre / 1e9, $precisa / 1e9));
        }
        $antiga = (int)$Host->get('imageID');
        if ($antiga !== (int)$Image->get('id')) {
            $Host->set('imageID', $Image->get('id'))->save();
        }
        $falha = self::criarTarefa($Host, 2, $d);
        if ($falha) {
            if ($antiga !== (int)$Image->get('id')) {
                $Host->set('imageID', $antiga)->save(); // não troca a imagem padrão se nada foi agendado
            }
            self::erro('Não foi possível agendar a captura: ' . $falha);
        }
        return ['ok' => true, 'msg' => 'Captura agendada para "' . self::txt($Host->get('name')) . '".',
            'tarefas' => array_filter([self::tarefaAtiva($Host->get('id'))])];
    }

    public static function restaurar($d)
    {
        $Image = self::imagem($d['imagem_id'] ?? 0);
        $ids = array_values(array_unique(array_map('intval', (array)($d['maquinas'] ?? []))));
        if (!$ids) {
            self::erro('Escolha pelo menos uma máquina.');
        }
        $ok = [];
        $falhas = [];
        $tarefas = [];
        @set_time_limit(600); // muitas máquinas de uma vez
        foreach ($ids as $id) {
            $Host = new Host($id);
            if (!$Host->isValid()) {
                continue;
            }
            $nome = self::txt($Host->get('name'));
            $antiga = (int)$Host->get('imageID');
            if ($antiga !== (int)$Image->get('id')) {
                $Host->set('imageID', $Image->get('id'))->save();
            }
            $falha = self::criarTarefa($Host, 1, $d);
            if ($falha) {
                if ($antiga !== (int)$Image->get('id')) {
                    $Host->set('imageID', $antiga)->save();
                }
                $falhas[] = "$nome: $falha";
            } else {
                $ok[] = $nome;
                $tarefas[] = self::tarefaAtiva($id);
            }
        }
        if (!$ok) {
            self::erro("Nenhuma restauração foi agendada.\n" . implode("\n", $falhas));
        }
        return ['ok' => true, 'agendadas' => $ok, 'falhas' => $falhas, 'tarefas' => array_values(array_filter($tarefas)),
            'msg' => count($ok) . ' restauração(ões) agendada(s)' . ($falhas ? ', ' . count($falhas) . ' com problema' : '') . '.'];
    }

    public static function multicast($d)
    {
        $Image = self::imagem($d['imagem_id'] ?? 0);
        $nome = trim((string)($d['nome'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9-]{2,20}$/', $nome)) {
            self::erro('Nome da sessão: de 2 a 20 letras, números ou hífen, sem espaços (ex.: sala3).');
        }
        $qtd = (int)($d['quantidade'] ?? 0);
        if ($qtd < 1 || $qtd > 200) {
            self::erro('Informe quantas máquinas vão receber a imagem (1 a 200).');
        }
        if (self::q('SELECT msID FROM multicastSessions WHERE msName = ? AND msState IN (0,1,2,3) LIMIT 1', [$nome])) {
            self::erro("Já existe uma sessão em andamento chamada \"$nome\". Escolha outro nome.");
        }
        if (FOGCore::getClass('HostManager')->exists($nome)) {
            self::erro('O nome da sessão não pode ser igual ao nome de uma máquina.');
        }
        try {
            $StorageGroup = $Image->getStorageGroup();
            $StorageNode = $StorageGroup->getMasterStorageNode();
            MulticastSession::assertCapacity();
            $Sessao = FOGCore::getClass('MulticastSession')
                ->set('name', $nome)
                ->set('port', MulticastSession::allocatePort())
                ->set('image', $Image->get('id'))
                ->set('stateID', 0)
                ->set('sessclients', $qtd)
                ->set('isDD', $Image->get('imageTypeID'))
                ->set('starttime', FOGCore::formatTime('now', 'Y-m-d H:i:s'))
                ->set('interface', $StorageNode->get('interface'))
                ->set('logpath', $Image->get('path'))
                ->set('storagegroupID', $StorageGroup->get('id'))
                ->set('clients', -2);
            if (!$Sessao->save()) {
                self::erro('Não foi possível criar a sessão multicast.');
            }
        } catch (Exception $e) {
            self::erro('Não foi possível criar a sessão multicast: ' . $e->getMessage());
        }
        return ['ok' => true, 'msg' => "Sessão \"$nome\" criada.", 'nome' => $nome, 'id' => (int)$Sessao->get('id')];
    }

    public static function cancelarTarefa($d)
    {
        $id = (int)($d['id'] ?? 0);
        if ($id < 1) {
            self::erro('Tarefa inválida.');
        }
        FOGCore::getClass('TaskManager')->cancel([$id]);
        return ['ok' => true, 'msg' => 'Tarefa cancelada. Se a máquina já estava copiando, reinicie-a (ela não para sozinha).'];
    }

    // ------------------------------------------------------------ limpeza do histórico

    /** Quantos registros cada opção da limpeza apagaria (a tela mostra antes de confirmar). */
    public static function limpezaContagem()
    {
        $n = function ($sql) {
            return (int)self::q($sql)[0]['n'];
        };
        return [
            'historico' => $n("SELECT COUNT(*) n FROM tasks WHERE taskStateID NOT IN (1,2,3)")
                + $n("SELECT COUNT(*) n FROM multicastSessions WHERE msState NOT IN (0,1,2,3)"),
            'fila' => $n("SELECT COUNT(*) n FROM tasks WHERE taskStateID = 1")
                + $n("SELECT COUNT(*) n FROM multicastSessions WHERE msState IN (0,1) AND msPercent = 0 AND msClients <= 0"),
            'em_andamento' => $n("SELECT COUNT(*) n FROM tasks WHERE taskStateID IN (2,3)")
                + $n("SELECT COUNT(*) n FROM multicastSessions WHERE msState IN (2,3) OR (msState IN (0,1) AND (msPercent > 0 OR msClients > 0))"),
            'clonagens' => $n("SELECT COUNT(*) n FROM imagingLog"),
            'registros' => $n("SELECT COUNT(*) n FROM history") + $n("SELECT COUNT(*) n FROM userTracking"),
        ];
    }

    /**
     * Apaga histórico de tarefas, fila de espera, registro de clonagens e registros internos (opções independentes).
     * Nunca mexe no que está em andamento. Pede a senha de quem está usando o painel. Tudo numa transação.
     */
    public static function limparHistorico($d)
    {
        global $currentUser;
        self::confirmarMinhaSenha($d);
        $op = [
            'fila' => !empty($d['fila']),
            'historico' => !empty($d['historico']),
            'clonagens' => !empty($d['clonagens']),
            'registros' => !empty($d['registros']),
        ];
        if (!in_array(true, $op, true)) {
            self::erro('Escolha pelo menos uma opção.');
        }
        $antes = self::limpezaContagem();
        // 1) fila: cancela pelas classes do FOG (libera o boot das máquinas e encerra sessões que não começaram)
        if ($op['fila']) {
            $ids = array_column(self::q("SELECT taskID id FROM tasks WHERE taskStateID = 1"), 'id');
            if ($ids) {
                FOGCore::getClass('TaskManager')->cancel(array_map('intval', $ids));
            }
            $ms = array_column(self::q("SELECT msID id FROM multicastSessions WHERE msState IN (0,1) AND msPercent = 0 AND msClients <= 0"), 'id');
            if ($ms) {
                FOGCore::getClass('MulticastSessionManager')->cancel(array_map('intval', $ms));
            }
        }
        // 2) apagar (tudo ou nada)
        $db = self::db();
        $db->beginTransaction();
        try {
            // as tarefas canceladas pela fila viram histórico: só somem se "histórico" também foi escolhido
            if ($op['historico']) {
                $db->exec("DELETE l FROM taskLog l JOIN tasks t ON t.taskID = l.taskID WHERE t.taskStateID NOT IN (1,2,3)");
                // sessões que terminaram há menos de 1 h ficam: o FOG ainda encerra as máquinas por elas
                $velhas = "m.msState NOT IN (0,1,2,3) AND (m.msCompleteDateTime IS NULL OR m.msCompleteDateTime < NOW() - INTERVAL 1 HOUR)";
                $db->exec("DELETE a FROM multicastSessionsAssoc a LEFT JOIN multicastSessions m ON m.msID = a.msID WHERE m.msID IS NULL OR ($velhas)");
                $db->exec("DELETE m FROM multicastSessions m WHERE $velhas");
                $db->exec("DELETE FROM tasks WHERE taskStateID NOT IN (1,2,3)");
                $db->exec("DELETE FROM snapinTasks WHERE stState NOT IN (1,2,3)");
                $db->exec("DELETE j FROM snapinJobs j LEFT JOIN snapinTasks t ON t.stJobID = j.sjID WHERE t.stID IS NULL AND j.sjStateID NOT IN (1,2,3)");
                if (self::q("SHOW TABLES LIKE 'labfreeEtapas'")) {
                    $db->exec("DELETE e FROM labfreeEtapas e LEFT JOIN tasks t ON t.taskID = e.taskID WHERE t.taskID IS NULL");
                }
            }
            if ($op['clonagens']) {
                $db->exec("DELETE FROM imagingLog");
            }
            if ($op['registros']) {
                $db->exec("DELETE FROM history");
                $db->exec("DELETE FROM userTracking");
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[painel] limpeza desfeita: ' . $e->getMessage());
            self::erro('Não foi possível limpar; nada foi apagado. Tente de novo.', 500);
        }
        $depois = self::limpezaContagem();
        $feito = [];
        if ($op['fila']) {
            $feito[] = 'fila cancelada (' . max(0, $antes['fila'] - $depois['fila']) . ')';
        }
        if ($op['historico']) {
            $feito[] = 'histórico apagado';
        }
        if ($op['clonagens']) {
            $feito[] = 'registro de clonagens apagado';
        }
        if ($op['registros']) {
            $feito[] = 'registros internos apagados';
        }
        error_log(sprintf('[painel] limpeza por %s: %s', self::txt($currentUser->get('name')), implode('; ', $feito)));
        return ['ok' => true, 'msg' => 'Limpeza concluída: ' . implode(', ', $feito) . '.', 'contagem' => $depois];
    }

    public static function cancelarMulticast($d)
    {
        $id = (int)($d['id'] ?? 0);
        if ($id < 1) {
            self::erro('Sessão inválida.');
        }
        FOGCore::getClass('MulticastSessionManager')->cancel([$id]);
        return ['ok' => true, 'msg' => 'Sessão multicast cancelada.'];
    }

    /**
     * Chama o ajudante de root do LabFree ImageDeploy (via sudo; o sudoers só permite este arquivo).
     * Entrada e saída em JSON. Nunca passa texto livre para o shell (proc_open com array).
     */
    public static function root($acao, $dados = [])
    {
        $ajudante = '/usr/local/lib/LabFreeImageDeploy/LabFreeImageDeploy-root';
        if (!is_executable($ajudante)) {
            throw new PainelErro('O componente de configuração do LabFree ImageDeploy não está instalado neste servidor.', 503);
        }
        // stderr vai para um arquivo: ler dois canais em sequência pode travar se o ajudante escrever muito no outro
        $arqErro = tempnam(sys_get_temp_dir(), 'lfid');
        $proc = proc_open(['sudo', '-n', $ajudante, $acao], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $arqErro, 'w']], $pipes);
        if (!is_resource($proc)) {
            @unlink($arqErro);
            throw new PainelErro('Não foi possível iniciar o componente de configuração.', 500);
        }
        // sempre um objeto JSON: json_encode([]) gera "[]" (lista), que o ajudante recusa
        fwrite($pipes[0], json_encode((object)$dados, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        $saida = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $codigo = proc_close($proc);
        $erros = (string)@file_get_contents($arqErro, false, null, 0, 65536);
        @unlink($arqErro);
        $json = json_decode((string)$saida, true);
        if (!is_array($json)) {
            if (stripos($erros, 'password') !== false || stripos($erros, 'sudo') !== false) {
                throw new PainelErro("Configurar rede e DHCP pelo painel ainda não foi habilitado neste servidor. "
                    . "Peça ao administrador para rodar: sudo /opt/LabFreeImageDeploy/instalar.sh", 503);
            }
            error_log('[painel] ajudante ' . $acao . ' respondeu: ' . substr((string)$saida . ' ' . $erros, 0, 500));
            throw new PainelErro('Resposta inválida do componente de configuração.', 500);
        }
        if ($codigo !== 0 || isset($json['erro'])) {
            throw new PainelErro($json['erro'] ?? 'Falha no componente de configuração.', 400);
        }
        return $json;
    }

    // ------------------------------------------------------------ cadastro pelo boot

    public static function cadastroConfig()
    {
        $padrao = strtoupper(trim((string)FOGCore::getSetting('FOG_QUICKREG_SYS_NAME')));
        if (!preg_match('/^[A-Z0-9-]*\*+[A-Z0-9-]*$/', $padrao)) {
            $padrao = 'LAB-***';
        }
        $digitos = substr_count($padrao, '*');
        $numero = max(1, (int)FOGCore::getSetting('FOG_QUICKREG_SYS_NUMBER'));
        $sugestao = '';
        $usados = array_flip(array_map('strtoupper', array_column(self::q('SELECT hostName FROM hosts'), 'hostName')));
        for ($i = 0, $n = $numero; $i < 100000; $i++, $n++) {
            $t = preg_replace('/\*+/', str_pad((string)$n, $digitos, '0', STR_PAD_LEFT), $padrao, 1);
            if (strlen($t) <= 15 && !isset($usados[$t])) {
                $sugestao = $t;
                break;
            }
        }
        return [
            'prefixo' => preg_replace('/\*+.*$/', '', $padrao),
            'sufixo' => preg_replace('/^[^*]*\*+/', '', $padrao),
            'digitos' => $digitos,
            'numero' => $numero,
            'imagem_id' => (int)FOGCore::getSetting('FOG_QUICKREG_IMG_ID'),
            'restaurar' => (bool)(int)FOGCore::getSetting('FOG_QUICKREG_IMG_WHEN_REG'),
            'sugestao' => $sugestao,
            // menu de boot: 0 = espera alguém escolher; N = depois de N segundos inicia pelo disco
            'menu_espera' => max(0, (int)FOGCore::getSetting('FOG_PXE_MENU_TIMEOUT')),
        ];
    }

    public static function cadastroSalvar($d)
    {
        $prefixo = strtoupper(trim((string)($d['prefixo'] ?? '')));
        $sufixo = strtoupper(trim((string)($d['sufixo'] ?? '')));
        $digitos = (int)($d['digitos'] ?? 3);
        $numero = (int)($d['numero'] ?? 1);
        if (!preg_match('/^[A-Z0-9-]{0,12}$/', $prefixo) || !preg_match('/^[A-Z0-9-]{0,8}$/', $sufixo)) {
            self::erro('Use só letras, números e hífen no começo e no fim do nome.');
        }
        if ($digitos < 1 || $digitos > 5) {
            self::erro('A numeração precisa ter de 1 a 5 dígitos.');
        }
        if (strlen($prefixo) + $digitos + strlen($sufixo) > 15) {
            self::erro('O nome completo fica com mais de 15 caracteres. Encurte o começo ou o fim.');
        }
        if ($numero < 1 || $numero > 99999) {
            self::erro('Próximo número inválido.');
        }
        $img = (int)($d['imagem_id'] ?? 0);
        if ($img) {
            self::imagem($img);
        }
        FOGCore::setSetting('FOG_QUICKREG_SYS_NAME', $prefixo . str_repeat('*', $digitos) . $sufixo);
        FOGCore::setSetting('FOG_QUICKREG_SYS_NUMBER', $numero);
        FOGCore::setSetting('FOG_QUICKREG_IMG_ID', $img ?: -1);
        FOGCore::setSetting('FOG_QUICKREG_IMG_WHEN_REG', (!empty($d['restaurar']) && $img) ? 1 : 0);
        FOGCore::setSetting('FOG_QUICKREG_AUTOPOP', 1);
        if (isset($d['menu_espera'])) {
            $espera = (int)$d['menu_espera'];
            if ($espera < 0 || $espera > 600) {
                self::erro('Tempo do menu de boot: de 0 (esperar a escolha) a 600 segundos.');
            }
            FOGCore::setSetting('FOG_PXE_MENU_TIMEOUT', $espera);
        }
        return ['ok' => true, 'msg' => 'Cadastro pelo boot configurado.', 'cadastro' => self::cadastroConfig()];
    }

    public static function sistemas()
    {
        return self::q("SELECT osID id, osName nome FROM os ORDER BY osName");
    }
}

// ------------------------------------------------------------------- rotas

$acao = (string)($_GET['a'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($acao === 'sessao') {
        $u = Painel::sessaoValida() ? Painel::usuario() : null;
        $versao = trim((string)@file_get_contents('/etc/LabFreeImageDeploy/versao')) ?: 'dev';
        Painel::responder(['logado' => (bool)$u, 'usuario' => $u ? Painel::txt($u->get('name')) : null, 'token' => $u ? Painel::token() : null,
            'versao' => $versao, 'trocar_senha' => $u ? Painel::trocaPendente() : false]);
    }
    if ($acao === 'entrar' && $metodo === 'POST') {
        $d = Painel::corpo();
        $usuario = trim((string)($d['usuario'] ?? ''));
        $senha = (string)($d['senha'] ?? '');
        if ($usuario === '' || $senha === '') {
            Painel::erro('Informe usuário e senha.');
        }
        Painel::loginContar();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // confere a senha SEM usar a sessão atual (o FOG aceitaria qualquer senha se já houvesse alguém logado nela)
        if (!preg_match('/(?=^.{3,40}$)^[\w][\w0-9]*[._-]?[\w0-9]*[.]?[\w0-9]+$/i', $usuario)
            || !FOGCore::getClass('User')->passwordValidate($usuario, $senha)) {
            error_log(sprintf("[%s] - %s - painel - Login failed - username: %s\n", date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'] ?? '',
                preg_replace('/[^\w.@-]/', '?', substr($usuario, 0, 40))), 3, BASEPATH . 'fog_login_failed.log');
            @chmod(BASEPATH . 'fog_login_failed.log', 0200); // o Apache não serve esse arquivo
            Painel::erro('Usuário ou senha incorretos.', 401);
        }
        if ($u = Painel::usuario()) {
            $u->logout();
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $User = FOGCore::attemptLogin($usuario, $senha);
        if (!$User || !$User->isValid() || strcasecmp(Painel::txt($User->get('name')), $usuario) !== 0) {
            Painel::erro('Usuário ou senha incorretos.', 401);
        }
        Painel::loginOk();
        $_SESSION['FOG_LANG'] = 'pt_BR';
        unset($_SESSION['PAINEL_TOKEN']);
        $_SESSION['PAINEL_TROCAR_SENHA'] = Painel::senhaPadrao($User) ? 1 : 0;
        $_SESSION['PAINEL_SENHA'] = Painel::marcaSenha($User->get('id'));
        $_SESSION['PAINEL_ATIVIDADE'] = time();
        Painel::responder(['ok' => true, 'usuario' => Painel::txt($User->get('name')), 'token' => Painel::token(),
            'versao' => trim((string)@file_get_contents('/etc/LabFreeImageDeploy/versao')) ?: 'dev', 'trocar_senha' => (bool)$_SESSION['PAINEL_TROCAR_SENHA']]);
    }

    Painel::exigirLogin($acao);
    // Senha de fábrica: nada funciona até definir a senha nova (exceto sair).
    if (Painel::trocaPendente() && !in_array($acao, ['primeira_senha', 'sair'], true)) {
        Painel::responder(['erro' => 'Defina uma senha nova para continuar.', 'trocar_senha' => true], 428);
    }
    // libera a sessão: pedidos demorados (rede, DHCP) não seguram os outros pedidos do mesmo navegador
    if (!in_array($acao, ['primeira_senha', 'sair', 'minha_senha'], true)) {
        Painel::token();
        session_write_close();
    }

    if ($metodo === 'GET') {
        switch ($acao) {
            case 'resumo':
                Painel::responder(Painel::resumo());
            case 'maquinas':
                Painel::responder(Painel::maquinas());
            case 'imagens':
                Painel::responder(['imagens' => Painel::imagens(), 'sistemas' => Painel::sistemas()]);
            case 'tarefas':
                Painel::responder(Painel::tarefas());
            case 'dhcp':
                Painel::responder(Painel::root('dhcp-ler'));
            case 'rede':
                Painel::responder(Painel::root('rede-listar'));
            case 'usuarios':
                Painel::responder(Painel::usuarios());
            case 'acompanhar':
                Painel::responder(Painel::acompanhar($_GET['ids'] ?? ''));
            case 'registro':
                Painel::responder(Painel::registro($_GET['id'] ?? 0));
            case 'limpeza':
                Painel::responder(Painel::limpezaContagem());
            case 'cadastro':
                Painel::responder(Painel::cadastroConfig());
        }
        Painel::erro('Ação desconhecida.', 404);
    }

    if ($metodo !== 'POST') {
        Painel::erro('Método não permitido.', 405);
    }
    Painel::exigirToken();
    $d = Painel::corpo();
    switch ($acao) {
        case 'primeira_senha':
            Painel::responder(Painel::primeiraSenha($d));
        case 'sair':
            global $currentUser;
            $currentUser->logout();
            Painel::responder(['ok' => true]);
        case 'cadastrar_maquina':
            Painel::responder(Painel::cadastrarMaquina($d));
        case 'editar_maquina':
            Painel::responder(Painel::editarMaquina($d));
        case 'excluir_maquina':
            Painel::responder(Painel::excluirMaquina($d));
        case 'criar_imagem':
            Painel::responder(Painel::criarImagem($d));
        case 'editar_imagem':
            Painel::responder(Painel::editarImagem($d));
        case 'capturar':
            Painel::responder(Painel::capturar($d));
        case 'restaurar':
            Painel::responder(Painel::restaurar($d));
        case 'multicast':
            Painel::responder(Painel::multicast($d));
        case 'cancelar_tarefa':
            Painel::responder(Painel::cancelarTarefa($d));
        case 'limpar_historico':
            Painel::responder(Painel::limparHistorico($d));
        case 'cancelar_multicast':
            Painel::responder(Painel::cancelarMulticast($d));
        case 'iniciar_multicast':
            Painel::responder(Painel::iniciarMulticast($d));
        case 'cadastro_salvar':
            Painel::responder(Painel::cadastroSalvar($d));
        case 'excluir_imagem':
            Painel::responder(Painel::excluirImagem($d));
        case 'criar_usuario':
            Painel::responder(Painel::criarUsuario($d));
        case 'senha_usuario':
            Painel::responder(Painel::senhaUsuario($d));
        case 'minha_senha':
            Painel::responder(Painel::minhaSenha($d));
        case 'excluir_usuario':
            Painel::responder(Painel::excluirUsuario($d));
        case 'dhcp_salvar':
            Painel::responder(Painel::root('dhcp-aplicar', $d));
        case 'rede_simular':
            Painel::responder(Painel::root('rede-simular', ['cliente_ip' => $_SERVER['REMOTE_ADDR'] ?? ''] + $d));
        case 'rede_aplicar':
            Painel::responder(Painel::root('rede-aplicar', ['cliente_ip' => $_SERVER['REMOTE_ADDR'] ?? ''] + $d));
    }
    Painel::erro('Ação desconhecida.', 404);
} catch (PainelErro $e) {
    Painel::erro($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[painel] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    Painel::erro('Erro interno do servidor. O detalhe ficou no registro do Apache (error.log).', 500);
}
