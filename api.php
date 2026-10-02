<?php
/**
 * API do relatório.
 *   ?action=calls   JSON com ligações (paginadas), indicadores e opções de filtro
 *   ?action=export  CSV (mode=calls: uma linha por ligação | mode=legs: uma linha por ramal que tocou)
 *   ?action=audio   gravação da ligação (id = linkedid), com suporte a Range para avançar/voltar
 */
require dirname(__FILE__) . '/lib/bootstrap.php';

$cfg = app_config();
app_require_auth();

function param($k, $default = '')
{
    return isset($_GET[$k]) ? trim((string) $_GET[$k]) : $default;
}

function fail($code, $msg)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('error' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

function valid_date($d)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

$action = param('action', 'calls');
$demo = !empty($cfg['demo']);

try {
    /* ---------------------------------------------------------- áudio */
    if ($action === 'audio') {
        if (empty($cfg['allow_recordings'])) {
            fail(403, 'Gravações desativadas.');
        }
        $id = param('id');
        if (!preg_match('/^[0-9A-Za-z._-]{1,64}$/', $id)) {
            fail(400, 'Identificador inválido.');
        }
        if ($demo) {
            $wav = DemoData::wav(12);
            header('Content-Type: audio/wav');
            header('Content-Length: ' . strlen($wav));
            header('Accept-Ranges: none');
            echo $wav;
            exit;
        }
        $repo = new CdrRepository(app_pdo('cdr'));
        $rec = $repo->recordingFor($id);
        if (!$rec) {
            fail(404, 'Esta ligação não tem gravação.');
        }
        $base = rtrim($cfg['recordings_dir'], '/');
        $file = $rec['recordingfile'];
        $ts = strtotime($rec['calldate']);
        $candidates = array();
        if ($file[0] === '/') {
            $candidates[] = $file;
        } else {
            $candidates[] = $base . '/' . date('Y/m/d', $ts) . '/' . $file;
            $candidates[] = $base . '/' . $file;
        }
        $path = null;
        $realBase = realpath($base);
        foreach ($candidates as $c) {
            $rp = realpath($c);
            if ($rp && $realBase && strpos($rp, $realBase . '/') === 0 && is_file($rp)) {
                $path = $rp;
                break;
            }
        }
        if ($path === null) {
            fail(404, 'O arquivo da gravação não foi encontrado no servidor.');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = array('wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'gsm' => 'audio/x-gsm', 'wav49' => 'audio/wav');
        $size = filesize($path);
        $startB = 0;
        $endB = $size - 1;
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
        if (param('download') === '1') {
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        }
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] !== '') {
                $startB = (int) $m[1];
                $endB = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            } elseif ($m[2] !== '') {
                $startB = max(0, $size - (int) $m[2]);
            }
            if ($startB > $endB) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $startB . '-' . $endB . '/' . $size);
        }
        header('Content-Length: ' . ($endB - $startB + 1));
        $fh = fopen($path, 'rb');
        fseek($fh, $startB);
        $left = $endB - $startB + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    /* ------------------------------------------------- período e filtros */
    $today = date('Y-m-d');
    $fromD = param('from', $today);
    $toD = param('to', $fromD);
    if (!valid_date($fromD) || !valid_date($toD)) {
        fail(400, 'Datas inválidas. Use o formato AAAA-MM-DD.');
    }
    if ($toD < $fromD) {
        $tmp = $fromD;
        $fromD = $toD;
        $toD = $tmp;
    }
    $days = (strtotime($toD) - strtotime($fromD)) / 86400 + 1;
    if ($days > (int) $cfg['max_range_days']) {
        fail(400, 'O período máximo é de ' . (int) $cfg['max_range_days'] . ' dias. Escolha um intervalo menor.');
    }
    $from = $fromD . ' 00:00:00';
    $to = $toD . ' 23:59:59';
    $ahead = date('Y-m-d H:i:s', min(time() + 60, strtotime($to) + (int) $cfg['callback_window_hours'] * 3600));

    $filters = array(
        'q' => param('q'),
        'dir' => in_array(param('dir'), array('in', 'out', 'int'), true) ? param('dir') : '',
        'status' => param('status'),
        'dept' => param('dept'),
        'agent' => param('agent'),
        'rec' => param('rec') === '1',
    );
    $tech = !empty($cfg['allow_tech_view']) && param('tech') === '1';

    /* -------------------------------------------------- dados */
    if ($demo) {
        $dir = DemoData::directory($cfg['labels']);
        $gen = new DemoData();
        $rows = $gen->rows($from, $ahead);
    } else {
        try {
            $dir = PbxDirectory::fromPdo(app_pdo('pbx'), $cfg['labels']);
        } catch (Exception $e) {
            error_log('cdr-report: sem acesso ao banco asterisk: ' . $e->getMessage());
            $dir = new PbxDirectory($cfg['labels']);
        }
        $repo = new CdrRepository(app_pdo('cdr'));
        $rows = $repo->fetch($from, $ahead);
    }

    // Em modo demo não existe banco call_center de verdade: não tenta conectar.
    $campaigns = $demo ? new CampaignDirectory() : CampaignDirectory::detect($cfg);
    $grouper = new CdrGrouper($dir, $cfg, $campaigns);
    $analyzer = new CallAnalyzer($cfg);
    $all = $grouper->group($rows);
    $analyzer->annotate($all);

    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    $inRange = array();
    foreach ($all as $c) {
        if ($c['ts'] >= $fromTs && $c['ts'] <= $toTs) {
            $inRange[] = $c;
        }
    }
    $facets = $analyzer->facets($inRange);

    $forStats = array();
    $list = array();
    foreach ($inRange as $c) {
        if ($analyzer->matches($c, $filters, true)) {
            $forStats[] = $c;
            if ($analyzer->matches($c, $filters, false)) {
                $list[] = $c;
            }
        }
    }

    $sort = param('sort', 'date');
    $order = param('order', 'desc') === 'asc' ? 1 : -1;
    $sortKeys = array('date' => 'ts', 'wait' => 'wait', 'talk' => 'talk', 'total' => 'total');
    if (isset($sortKeys[$sort]) && !($sort === 'date' && $order === -1)) {
        $k = $sortKeys[$sort];
        usort($list, function ($a, $b) use ($k, $order) {
            $x = (int) $a[$k];
            $y = (int) $b[$k];
            if ($x === $y) {
                return $b['ts'] - $a['ts'];
            }
            return $x < $y ? -$order : $order;
        });
    }

    /* --------------------------------------------------- exportação */
    if ($action === 'export') {
        $mode = param('mode') === 'legs' ? 'legs' : 'calls';
        $name = 'ligacoes_' . $fromD . ($toD !== $fromD ? '_a_' . $toD : '') . ($mode === 'legs' ? '_detalhado' : '') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
        $dirs = array('in' => 'Recebida', 'out' => 'Feita', 'int' => 'Interna');
        $statusNames = array('answered' => 'Atendida', 'missed' => 'Perdida', 'ivr' => 'Encerrada na URA',
            'voicemail' => 'Caixa postal', 'noanswer' => 'Não atendeu', 'busy' => 'Ocupado', 'failed' => 'Falhou');
        $results = array('answered' => 'Atendeu', 'noanswer' => 'Não atendeu', 'busy' => 'Ocupado', 'failed' => 'Indisponível');
        $who = function ($p) {
            return trim($p['ext'] . ($p['name'] !== '' ? ' - ' . $p['name'] : ''));
        };
        if ($mode === 'calls') {
            fputcsv($out, array('Data', 'Hora', 'Tipo', 'Número', 'Nome', 'Linha', 'Departamento', 'Situação',
                'Atendida por', 'Também tocou em', 'Ramal que ligou', 'Espera (s)', 'Conversa (s)', 'Total (s)', 'Retorno', 'Gravação'), ';');
            foreach ($list as $c) {
                $by = array();
                foreach ($c['answeredBy'] as $a) {
                    $by[] = $who($a);
                }
                $rang = array();
                foreach ($c['rang'] as $a) {
                    $rang[] = $who($a);
                }
                $cb = '';
                if ($c['callback']) {
                    $cb = $c['callback']['state'] === 'done'
                        ? ($c['callback']['how'] === 'returned' ? 'Retornada ' : 'Cliente ligou de novo ') . date('d/m H:i', strtotime($c['callback']['at']))
                        : 'Sem retorno';
                }
                fputcsv($out, array(
                    date('d/m/Y', $c['ts']), date('H:i:s', $c['ts']), $dirs[$c['direction']],
                    $c['party']['number'], $c['party']['name'],
                    $c['party']['lineName'] !== '' ? $c['party']['lineName'] : $c['party']['line'],
                    $c['department'], $statusNames[$c['status']], implode(' > ', $by), implode(', ', $rang),
                    $c['direction'] !== 'in' ? $who($c['from']) : '',
                    $c['wait'] === null ? '' : $c['wait'], $c['talk'], $c['total'], $cb, $c['recording'] ? 'Sim' : 'Não',
                ), ';');
            }
        } else {
            fputcsv($out, array('Data', 'Hora', 'Tipo', 'Número', 'Passo', 'Ramal', 'Nome', 'Resultado',
                'Tentativas', 'Tocou (s)', 'Conversa (s)', 'Situação da ligação'), ';');
            foreach ($list as $c) {
                foreach ($c['steps'] as $s) {
                    foreach ($s['members'] as $m) {
                        fputcsv($out, array(
                            date('d/m/Y', $c['ts']), date('H:i:s', $c['ts'] + $m['firstAt']), $dirs[$c['direction']],
                            $c['party']['number'], $s['label'], $m['external'] ? '' : $m['ext'],
                            $m['external'] ? $m['ext'] : $m['name'], $results[$m['result']],
                            $m['attempts'], $m['answeredAt'] !== null ? $m['answeredAt'] - $m['firstAt'] : $m['ring'],
                            $m['talk'], $statusNames[$c['status']],
                        ), ';');
                    }
                }
            }
        }
        fclose($out);
        exit;
    }

    /* ------------------------------------------------------ JSON */
    $per = max(10, min(200, (int) param('per', '50')));
    $total = count($list);
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($pages, (int) param('page', '1')));
    $slice = array_slice($list, ($page - 1) * $per, $per);
    foreach ($slice as &$c) {
        unset($c['recordingFile']);
        if (!$tech) {
            unset($c['raw']);
            if (!empty($cfg['hide_trunk_names'])) {
                $c['trunk'] = '';
            }
        }
        if (empty($cfg['allow_recordings'])) {
            $c['recording'] = false;
        }
    }
    unset($c);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(array(
        'range' => array('from' => $fromD, 'to' => $toD),
        'generatedAt' => date('Y-m-d H:i:s'),
        'demo' => $demo,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'calls' => $slice,
        'stats' => $analyzer->stats($forStats, $from, $to),
        'facets' => $facets,
        'features' => array(
            'tech' => !empty($cfg['allow_tech_view']),
            'recordings' => !empty($cfg['allow_recordings']),
            'serviceLevelSeconds' => (int) $cfg['service_level_seconds'],
            'callbackHours' => (int) $cfg['callback_window_hours'],
        ),
    ), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
} catch (Exception $e) {
    error_log('cdr-report: ' . $e->getMessage());
    fail(500, 'Não foi possível ler as ligações do banco. Verifique o acesso ao MySQL no config.php.');
}
