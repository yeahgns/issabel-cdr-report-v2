<?php
/**
 * Tudo que acontece depois do agrupamento: retornos de perdidas,
 * clientes que ligaram várias vezes, filtros e indicadores.
 */
class CallAnalyzer
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** Chave de comparação de números: últimos 8 dígitos (ignora 0, operadora, +55, DDD). */
    public static function numberKey($n)
    {
        $d = preg_replace('/\D/', '', (string) $n);
        if (strlen($d) < 8) {
            return '';
        }
        return substr($d, -8);
    }

    public function isLost($c)
    {
        if ($c['direction'] !== 'in') {
            return false;
        }
        if ($c['status'] === 'missed' || $c['status'] === 'voicemail') {
            return true;
        }
        return $c['status'] === 'ivr' && !empty($this->cfg['count_ivr_as_missed']);
    }

    /**
     * Marca, para cada perdida, se houve retorno: ligação feita para o número e atendida,
     * ou o cliente ligou de novo e foi atendido, dentro da janela configurada.
     * $calls pode conter ligações além do período exibido (a janela de retorno).
     */
    public function annotate(array &$calls)
    {
        $window = (int) $this->cfg['callback_window_hours'] * 3600;
        $byKey = array();
        foreach ($calls as $i => $c) {
            $k = self::numberKey($c['party']['number']);
            if ($k !== '' && $c['direction'] !== 'int') {
                $byKey[$k][] = $i;
            }
        }
        foreach ($byKey as $k => $idx) {
            usort($idx, function ($a, $b) use ($calls) {
                return $calls[$a]['ts'] - $calls[$b]['ts'];
            });
            $inCount = 0;
            foreach ($idx as $i) {
                if ($calls[$i]['direction'] === 'in') {
                    $inCount++;
                }
            }
            $n = count($idx);
            foreach ($idx as $pos => $i) {
                if ($calls[$i]['direction'] === 'in') {
                    $calls[$i]['repeat'] = $inCount;
                }
                if (!$this->isLost($calls[$i])) {
                    continue;
                }
                $cb = array('state' => 'open', 'at' => null, 'by' => null, 'how' => null, 'tries' => 0, 'after' => null);
                for ($j = $pos + 1; $j < $n; $j++) {
                    $next = $calls[$idx[$j]];
                    if ($next['ts'] - $calls[$i]['ts'] > $window) {
                        break;
                    }
                    if ($next['direction'] === 'out') {
                        $cb['tries']++;
                    }
                    if ($next['status'] === 'answered') {
                        $cb['state'] = 'done';
                        $cb['at'] = $next['date'];
                        $cb['how'] = $next['direction'] === 'out' ? 'returned' : 'called_again';
                        if ($next['direction'] === 'out') {
                            $cb['by'] = $next['from'];
                        } elseif ($next['answeredBy']) {
                            $cb['by'] = $next['answeredBy'][0];
                        }
                        $cb['after'] = $next['ts'] - $calls[$i]['ts'];
                        break;
                    }
                }
                $calls[$i]['callback'] = $cb;
            }
        }
    }

    /* ------------------------------------------------------------ filtros */

    public function matches($c, $f, $ignoreStatus)
    {
        if ($f['dir'] !== '' && $c['direction'] !== $f['dir']) {
            return false;
        }
        if (!$ignoreStatus && $f['status'] !== '') {
            if ($f['status'] === 'lost') {
                if (!$this->isLost($c)) {
                    return false;
                }
            } elseif ($f['status'] === 'open') {
                if (!$this->isLost($c) || !$c['callback'] || $c['callback']['state'] !== 'open') {
                    return false;
                }
            } elseif ($f['status'] === 'unanswered') {
                if ($c['status'] === 'answered') {
                    return false;
                }
            } elseif ($c['status'] !== $f['status']) {
                return false;
            }
        }
        if ($f['dept'] !== '' && $c['department'] !== $f['dept']) {
            return false;
        }
        if ($f['agent'] !== '' && !$this->involves($c, $f['agent'])) {
            return false;
        }
        if ($f['rec'] && !$c['recording']) {
            return false;
        }
        if ($f['q'] !== '' && !$this->search($c, $f['q'])) {
            return false;
        }
        return true;
    }

    private function involves($c, $ext)
    {
        if ($c['from']['ext'] === $ext) {
            return true;
        }
        foreach ($c['steps'] as $s) {
            foreach ($s['members'] as $m) {
                if (!$m['external'] && $m['ext'] === $ext) {
                    return true;
                }
            }
        }
        return false;
    }

    private function search($c, $q)
    {
        $q = self::lower(trim($q));
        $digits = preg_replace('/\D/', '', $q);
        $hay = array($c['party']['number'], $c['party']['name'], $c['party']['lineName'], $c['department'], $c['from']['ext'], $c['from']['name']);
        foreach ($c['steps'] as $s) {
            $hay[] = $s['label'];
            foreach ($s['members'] as $m) {
                $hay[] = $m['ext'];
                $hay[] = $m['name'];
            }
        }
        foreach ($hay as $h) {
            $h = (string) $h;
            if ($h === '') {
                continue;
            }
            if (strpos(self::lower($h), $q) !== false) {
                return true;
            }
            if (strlen($digits) >= 3 && strpos(preg_replace('/\D/', '', $h), $digits) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function lower($s)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /* ------------------------------------------------------- indicadores */

    private static function emptyPoint()
    {
        return array('answered' => 0, 'lost' => 0, 'other' => 0, 'out' => 0);
    }

    public function stats(array $calls, $from, $to)
    {
        $sl = (int) $this->cfg['service_level_seconds'];
        $in = array('total' => 0, 'answered' => 0, 'lost' => 0, 'open' => 0, 'ivr' => 0, 'voicemail' => 0,
                    'waitSum' => 0, 'waitMax' => 0, 'talkSum' => 0, 'withinSl' => 0, 'lostWaitSum' => 0);
        $out = array('total' => 0, 'answered' => 0, 'talkSum' => 0);
        $internal = 0;
        $multiDay = substr($from, 0, 10) !== substr($to, 0, 10);
        $series = array();
        $heat = array();
        $depts = array();
        $agents = array();

        foreach ($calls as $c) {
            $dir = $c['direction'];
            $bucket = $multiDay ? date('Y-m-d', $c['ts']) : date('G', $c['ts']);
            if (!isset($series[$bucket])) {
                $series[$bucket] = self::emptyPoint();
            }
            if ($dir === 'in') {
                $in['total']++;
                $w = (int) date('w', $c['ts']);
                $h = (int) date('G', $c['ts']);
                $heat[$w][$h] = isset($heat[$w][$h]) ? $heat[$w][$h] + 1 : 1;

                $dk = $c['department'] !== '' ? $c['department'] : 'Sem departamento';
                if (!isset($depts[$dk])) {
                    $depts[$dk] = array('name' => $dk, 'total' => 0, 'answered' => 0, 'lost' => 0, 'open' => 0,
                                        'waitSum' => 0, 'talkSum' => 0, 'withinSl' => 0, 'maxWait' => 0);
                }
                $depts[$dk]['total']++;

                if ($c['status'] === 'answered') {
                    $in['answered']++;
                    $in['waitSum'] += $c['wait'];
                    $in['talkSum'] += $c['talk'];
                    $in['waitMax'] = max($in['waitMax'], $c['wait']);
                    if ($c['wait'] <= $sl) {
                        $in['withinSl']++;
                        $depts[$dk]['withinSl']++;
                    }
                    $depts[$dk]['answered']++;
                    $depts[$dk]['waitSum'] += $c['wait'];
                    $depts[$dk]['talkSum'] += $c['talk'];
                    $depts[$dk]['maxWait'] = max($depts[$dk]['maxWait'], $c['wait']);
                    $series[$bucket]['answered']++;
                } elseif ($this->isLost($c)) {
                    $in['lost']++;
                    $in['lostWaitSum'] += (int) $c['wait'];
                    if ($c['status'] === 'voicemail') {
                        $in['voicemail']++;
                    }
                    $depts[$dk]['lost']++;
                    if ($c['callback'] && $c['callback']['state'] === 'open') {
                        $in['open']++;
                        $depts[$dk]['open']++;
                    }
                    $series[$bucket]['lost']++;
                } else {
                    $in['ivr']++;
                    $series[$bucket]['other']++;
                }
            } elseif ($dir === 'out') {
                $out['total']++;
                $series[$bucket]['out']++;
                if ($c['status'] === 'answered') {
                    $out['answered']++;
                    $out['talkSum'] += $c['talk'];
                }
            } else {
                $internal++;
            }

            // Atendentes
            if ($dir === 'out' && $c['from']['ext'] !== '') {
                $a =& $this->agent($agents, $c['from']['ext'], $c['from']['name']);
                $a['made']++;
                if ($c['status'] === 'answered') {
                    $a['madeAnswered']++;
                    $a['talkOut'] += $c['talk'];
                }
                unset($a);
            }
            if ($dir !== 'out') {
                $seen = array();
                foreach ($c['steps'] as $st) {
                    foreach ($st['members'] as $m) {
                        if ($m['external'] || isset($seen[$m['ext']])) {
                            continue;
                        }
                        $seen[$m['ext']] = true;
                        $a =& $this->agent($agents, $m['ext'], $m['name']);
                        $a['offered']++;
                        if ($m['result'] === 'answered') {
                            $a['answered']++;
                            $a['talkIn'] += $m['talk'];
                            $a['ringSum'] += max(0, $m['answeredAt'] - $m['firstAt']);
                        } else {
                            $a['notAnswered']++;
                            if ($m['result'] === 'busy') {
                                $a['busy']++;
                            }
                            if ($c['status'] !== 'answered') {
                                $a['rangLost']++; // tocou para ele e ninguém atendeu
                            }
                        }
                        unset($a);
                    }
                }
            }
        }

        // série completa (sem buracos)
        $full = array();
        if ($multiDay) {
            $t = strtotime(substr($from, 0, 10) . ' 12:00:00');
            $last = strtotime(substr($to, 0, 10) . ' 12:00:00');
            for (; $t <= $last; $t += 86400) {
                $k = date('Y-m-d', $t);
                $full[] = array('label' => $k) + (isset($series[$k]) ? $series[$k] : self::emptyPoint());
            }
        } else {
            for ($h = 0; $h < 24; $h++) {
                $k = (string) $h;
                $full[] = array('label' => $k) + (isset($series[$k]) ? $series[$k] : self::emptyPoint());
            }
        }

        $base = $in['answered'] + $in['lost'];
        $summary = array(
            'received' => $in['total'],
            'answered' => $in['answered'],
            'lost' => $in['lost'],
            'open' => $in['open'],
            'ivr' => $in['ivr'],
            'voicemail' => $in['voicemail'],
            'answerRate' => $base ? (int) round(100 * $in['answered'] / $base) : null,
            'serviceLevel' => $base ? (int) round(100 * $in['withinSl'] / $base) : null,
            'avgWait' => $in['answered'] ? (int) round($in['waitSum'] / $in['answered']) : null,
            'maxWait' => $in['waitMax'],
            'avgTalk' => $in['answered'] ? (int) round($in['talkSum'] / $in['answered']) : null,
            'avgLostWait' => $in['lost'] ? (int) round($in['lostWaitSum'] / $in['lost']) : null,
            'made' => $out['total'],
            'madeAnswered' => $out['answered'],
            'avgTalkOut' => $out['answered'] ? (int) round($out['talkSum'] / $out['answered']) : null,
            'internal' => $internal,
        );

        foreach ($depts as &$d) {
            $b = $d['answered'] + $d['lost'];
            $d['answerRate'] = $b ? (int) round(100 * $d['answered'] / $b) : null;
            $d['serviceLevel'] = $b ? (int) round(100 * $d['withinSl'] / $b) : null;
            $d['avgWait'] = $d['answered'] ? (int) round($d['waitSum'] / $d['answered']) : null;
            $d['avgTalk'] = $d['answered'] ? (int) round($d['talkSum'] / $d['answered']) : null;
        }
        unset($d);
        usort($depts, function ($a, $b) {
            return $b['total'] - $a['total'];
        });
        foreach ($agents as &$a) {
            $a['avgTalk'] = $a['answered'] ? (int) round($a['talkIn'] / $a['answered']) : null;
            $a['avgRing'] = $a['answered'] ? (int) round($a['ringSum'] / $a['answered']) : null;
            $a['answerRate'] = $a['offered'] ? (int) round(100 * $a['answered'] / $a['offered']) : null;
        }
        unset($a);
        usort($agents, function ($a, $b) {
            $x = $b['answered'] + $b['made'];
            $y = $a['answered'] + $a['made'];
            return $x === $y ? strcmp($a['ext'], $b['ext']) : $x - $y;
        });

        return array(
            'summary' => $summary,
            'serviceLevelSeconds' => $sl,
            'series' => array('granularity' => $multiDay ? 'day' : 'hour', 'points' => $full),
            'heat' => $heat,
            'departments' => array_values($depts),
            'agents' => array_values($agents),
        );
    }

    private function &agent(&$agents, $ext, $name)
    {
        if (!isset($agents[$ext])) {
            $agents[$ext] = array(
                'ext' => (string) $ext, 'name' => $name, 'offered' => 0, 'answered' => 0, 'notAnswered' => 0,
                'busy' => 0, 'rangLost' => 0, 'talkIn' => 0, 'ringSum' => 0, 'made' => 0, 'madeAnswered' => 0, 'talkOut' => 0,
            );
        }
        if ($agents[$ext]['name'] === '' && $name !== '') {
            $agents[$ext]['name'] = $name;
        }
        return $agents[$ext];
    }

    /** Opções dos filtros (departamentos e atendentes presentes no período). */
    public function facets(array $calls)
    {
        $d = array();
        $a = array();
        foreach ($calls as $c) {
            if ($c['department'] !== '') {
                $d[$c['department']] = true;
            }
            if ($c['from']['ext'] !== '') {
                $a[$c['from']['ext']] = $c['from']['name'];
            }
            foreach ($c['steps'] as $s) {
                foreach ($s['members'] as $m) {
                    if (!$m['external'] && (!isset($a[$m['ext']]) || $a[$m['ext']] === '')) {
                        $a[$m['ext']] = $m['name'];
                    }
                }
            }
        }
        $depts = array_keys($d);
        sort($depts);
        ksort($a);
        $agents = array();
        foreach ($a as $ext => $name) {
            $agents[] = array('ext' => (string) $ext, 'name' => $name);
        }
        return array('departments' => $depts, 'agents' => $agents);
    }
}
