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
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    public static function tentativas()
    {
        $t = json_decode((string)@file_get_contents(self::arqTentativas()), true);
        if (!is_array($t) || time() - (int)($t['ultima'] ?? 0) > self::LOGIN_BLOQUEIO) {
            return ['erros' => 0, 'ultima' => 0];
        }
        return $t;
    }

    public static function loginBloqueado()
    {
        $t = self::tentativas();
        if ($t['erros'] >= self::LOGIN_LIMITE) {
            $min = (int)ceil((self::LOGIN_BLOQUEIO - (time() - $t['ultima'])) / 60);
            self::erro("Muitas tentativas erradas. Aguarde $min minuto(s) e tente de novo.", 429);
        }
    }

    public static function loginFalhou()
    {
        $t = self::tentativas();
        $t['erros']++;
        $t['ultima'] = time();
        @file_put_contents(self::arqTentativas(), json_encode($t), LOCK_EX);
        sleep(min(5, max(1, $t['erros'] - 2))); // cada erro seguido demora mais
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

    public static function tarefas($historico = true)
    {
        $ativas = self::q("SELECT t.taskID id, h.hostName maquina, tt.ttName tipo, t.taskStateID estado, t.taskCreateTime criada,
                t.taskPercentText pct, t.taskDataCopied copiado, t.taskDataTotal total, t.taskTimeElapsed decorrido,
                t.taskTimeRemaining restante, t.taskBPM velocidade, i.imageName imagem
            FROM tasks t JOIN hosts h ON h.hostID = t.taskHostID JOIN taskTypes tt ON tt.ttID = t.taskTypeID
            LEFT JOIN images i ON i.imageID = t.taskImageID
            WHERE t.taskStateID IN (1,2,3) ORDER BY t.taskID");
        foreach ($ativas as &$t) {
            $t['maquina'] = self::txt($t['maquina']);
            $t['imagem'] = self::txt($t['imagem']);
            $t['tipo'] = self::tipoTarefa($t['tipo']);
            $t['estado_texto'] = self::estadoTexto($t['estado']);
            $t['criada'] = self::data($t['criada']);
            $t['pct'] = (int)$t['pct'];
        }
        unset($t);
        $sessoes = self::q("SELECT m.msID id, m.msName nome, m.msState estado, m.msSessClients esperadas, m.msClients conectadas,
                m.msPercent pct, m.msStartDateTime inicio, m.msCompleteDateTime fim, i.imageName imagem
            FROM multicastSessions m LEFT JOIN images i ON i.imageID = m.msImage ORDER BY m.msID DESC LIMIT 15");
        $sessAtivas = [];
        $sessHist = [];
        foreach ($sessoes as $s) {
            $s['nome'] = self::txt($s['nome']);
            $s['imagem'] = self::txt($s['imagem']);
            $s['estado_texto'] = self::estadoTexto($s['estado']);
            $s['inicio'] = self::data($s['inicio']);
            $s['fim'] = self::data($s['fim']);
            if (in_array((int)$s['estado'], self::ESTADOS_ATIVOS, true)) {
                $sessAtivas[] = $s;
            } else {
                $sessHist[] = $s;
            }
        }
        if (!$historico) {
            return ['ativas' => $ativas, 'multicast_ativas' => $sessAtivas];
        }
        $hist = self::q("SELECT t.taskID id, h.hostName maquina, tt.ttName tipo, t.taskStateID estado, t.taskCreateTime criada,
                i.imageName imagem, t.taskCreateBy por
            FROM tasks t LEFT JOIN hosts h ON h.hostID = t.taskHostID JOIN taskTypes tt ON tt.ttID = t.taskTypeID
            LEFT JOIN images i ON i.imageID = t.taskImageID
            WHERE t.taskStateID NOT IN (1,2,3) ORDER BY t.taskID DESC LIMIT 30");
        foreach ($hist as &$t) {
            $t['maquina'] = self::txt($t['maquina']) ?: '(máquina excluída)';
            $t['imagem'] = self::txt($t['imagem']);
            $t['tipo'] = self::tipoTarefa($t['tipo']);
            $t['estado_texto'] = self::estadoTexto($t['estado']);
            $t['criada'] = self::data($t['criada']);
        }
        return ['ativas' => $ativas, 'multicast_ativas' => $sessAtivas, 'historico' => $hist, 'multicast_historico' => $sessHist];
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
                $msg .= ' Os arquivos não puderam ser apagados.';
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
        if (!$interno && (int)$U->get('id') !== (int)$currentUser->get('id')) {
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
                !empty($d['wol'])
            );
        } catch (Exception $e) {
            return $e->getMessage();
        }
        return null;
    }

    public static function capturar($d)
    {
        $Host = self::host($d['maquina_id'] ?? 0);
        $Image = self::imagem($d['imagem_id'] ?? 0);
        if ($Image->get('protected')) {
            self::erro('Esta imagem está protegida contra sobrescrita. Desmarque a proteção na tela Imagens para capturar de novo.');
        }
        if ((int)$Host->get('imageID') !== (int)$Image->get('id')) {
            $Host->set('imageID', $Image->get('id'))->save();
        }
        $falha = self::criarTarefa($Host, 2, $d);
        if ($falha) {
            self::erro('Não foi possível agendar a captura: ' . $falha);
        }
        return ['ok' => true, 'msg' => 'Captura agendada para "' . self::txt($Host->get('name')) . '".'];
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
        foreach ($ids as $id) {
            $Host = new Host($id);
            if (!$Host->isValid()) {
                continue;
            }
            $nome = self::txt($Host->get('name'));
            if ((int)$Host->get('imageID') !== (int)$Image->get('id')) {
                $Host->set('imageID', $Image->get('id'))->save();
            }
            $falha = self::criarTarefa($Host, 1, $d);
            if ($falha) {
                $falhas[] = "$nome: $falha";
            } else {
                $ok[] = $nome;
            }
        }
        if (!$ok) {
            self::erro("Nenhuma restauração foi agendada.\n" . implode("\n", $falhas));
        }
        return ['ok' => true, 'agendadas' => $ok, 'falhas' => $falhas,
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
        if (FOGCore::getClass('MulticastSessionManager')->exists($nome)) {
            self::erro("Já existe uma sessão chamada \"$nome\". Escolha outro nome.");
        }
        if (FOGCore::getClass('HostManager')->exists($nome)) {
            self::erro('O nome da sessão não pode ser igual ao nome de uma máquina.');
        }
        $espera = (int)($d['espera_min'] ?? 0);
        if ($espera > 0) {
            FOGCore::setSetting('FOG_UDPCAST_MAXWAIT', min($espera, 60));
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
        return ['ok' => true, 'msg' => "Sessão \"$nome\" criada.", 'nome' => $nome];
    }

    public static function cancelarTarefa($d)
    {
        $id = (int)($d['id'] ?? 0);
        if ($id < 1) {
            self::erro('Tarefa inválida.');
        }
        FOGCore::getClass('TaskManager')->cancel([$id]);
        return ['ok' => true, 'msg' => 'Tarefa cancelada.'];
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
        $u = Painel::usuario();
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
        Painel::loginBloqueado();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // confere a senha SEM usar a sessão atual (o FOG aceitaria qualquer senha se já houvesse alguém logado nela)
        if (!preg_match('/(?=^.{3,40}$)^[\w][\w0-9]*[._-]?[\w0-9]*[.]?[\w0-9]+$/i', $usuario)
            || !FOGCore::getClass('User')->passwordValidate($usuario, $senha)) {
            Painel::loginFalhou();
            error_log(sprintf("[%s] - %s - painel - Login failed - username: %s\n", date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'] ?? '',
                preg_replace('/[^\w.@-]/', '?', substr($usuario, 0, 40))), 3, BASEPATH . 'fog_login_failed.log');
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
        case 'cancelar_multicast':
            Painel::responder(Painel::cancelarMulticast($d));
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
