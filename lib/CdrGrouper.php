<?php
/**
 * Transforma as linhas do CDR (uma por perna) em LIGAÇÕES.
 *
 * Regras principais
 * - Agrupa por linkedid (todas as pernas de uma ligação, inclusive transferências).
 *   Sem linkedid (Asterisk muito antigo), usa uniqueid.
 * - Cada linha vira um "passo" do caminho (URA, fila, grupo, ramal, número externo,
 *   caixa postal) e, quando for o caso, um "membro" que tocou dentro daquele passo.
 * - As duas metades de um canal Local (;1 e ;2) são a MESMA tentativa e são fundidas.
 * - Atendida = alguma pessoa (ramal ou número externo) atendeu com billsec > 0.
 *   URA e mensagens atendem o canal, mas não contam como atendimento.
 */
class CdrGrouper
{
    private $dir;
    private $cfg;
    private $campaigns;

    private static $rank = array(
        'ANSWERED' => 4, 'BUSY' => 3, 'NO ANSWER' => 2, 'NOANSWER' => 2,
        'CONGESTION' => 1, 'FAILED' => 1, '' => 0,
    );

    private static $ivrApps = array('background', 'read', 'waitexten', 'playback', 'ivr', 'answer', 'wait');

    public function __construct(PbxDirectory $dir, array $cfg, CampaignDirectory $campaigns = null)
    {
        $this->dir = $dir;
        $this->cfg = $cfg;
        // Opcional: sem o módulo Callcenter, fica um diretório vazio e a
        // categoria de campanha simplesmente nunca é marcada.
        $this->campaigns = $campaigns !== null ? $campaigns : new CampaignDirectory();
    }

    public function group(array $rows)
    {
        $buckets = array();
        foreach ($rows as $r) {
            $key = !empty($r['linkedid']) ? $r['linkedid'] : $r['uniqueid'];
            $buckets[$key][] = $r;
        }
        $calls = array();
        foreach ($buckets as $key => $list) {
            if ($this->isSystemCall($list)) {
                continue;
            }
            $call = $this->buildCall((string) $key, $list);
            if ($call !== null) {
                $calls[] = $call;
            }
        }
        usort($calls, array('CdrGrouper', 'byStartDesc'));
        return $calls;
    }

    /**
     * Ligação gerada pelo próprio PABX (Originate/AMI, call file, script de
     * monitoramento): nenhuma linha envolve tronco ou ramal e ninguém com
     * número de verdade participou. Ex.: src "s", Local/s@default-...;1.
     */
    private function isSystemCall(array $rows)
    {
        foreach ($rows as $r) {
            foreach (array('channel', 'dstchannel') as $f) {
                $c = $this->parseChannel($r[$f]);
                if ($c['kind'] === 'trunk' || $c['kind'] === 'ext') {
                    return false;
                }
            }
            if (preg_match('/\d{3,}/', (string) $r['src'])) {
                return false;
            }
        }
        return true;
    }

    public static function byStartDesc($a, $b)
    {
        if ($a['ts'] === $b['ts']) {
            return strcmp($b['id'], $a['id']);
        }
        return $a['ts'] < $b['ts'] ? 1 : -1;
    }

    public static function byRowOrder($a, $b)
    {
        $c = strcmp($a['calldate'], $b['calldate']);
        if ($c !== 0) {
            return $c;
        }
        $sa = isset($a['sequence']) ? (int) $a['sequence'] : 0;
        $sb = isset($b['sequence']) ? (int) $b['sequence'] : 0;
        return $sa - $sb;
    }

    /* ------------------------------------------------------------ canais */

    /**
     * SIP/Bradial_(00)0000-0000-000003c0  -> trunk  "Bradial_(00)0000-0000"
     * SIP/232-0000a1b2                    -> ext    "232"
     * Local/232@from-queue-0000133b;1     -> local  exten 232, base "Local/232@from-queue-0000133b"
     */
    public function parseChannel($ch)
    {
        $ch = trim((string) $ch);
        $out = array('kind' => '', 'id' => '', 'base' => '', 'ctx' => '', 'raw' => $ch);
        if ($ch === '') {
            return $out;
        }
        if (preg_match('~^Local/([^@]+)@(.+?)-([0-9a-f]+)(?:;(\d))?$~i', $ch, $m)) {
            $exten = preg_match('/^\d+/', $m[1], $d) ? $d[0] : $m[1];
            $out['kind'] = 'local';
            $out['id'] = $exten;
            $out['ctx'] = $m[2];
            $out['base'] = 'Local/' . $m[1] . '@' . $m[2] . '-' . $m[3];
            return $out;
        }
        if (!preg_match('~^([A-Za-z0-9]+)/(.+)$~', $ch, $m)) {
            $out['kind'] = 'other';
            return $out;
        }
        $tech = strtoupper($m[1]);
        $peer = preg_replace('/-[0-9a-f]+$/i', '', $m[2]);
        $out['id'] = $peer;
        $out['base'] = $ch;
        if ($tech === 'DAHDI' || $tech === 'ZAP' || $tech === 'KHOMP') {
            $out['kind'] = 'trunk';
        } elseif ($this->dir->isTrunk($peer)) {
            $out['kind'] = 'trunk';
        } elseif ($this->dir->isExtension($peer)) {
            $out['kind'] = 'ext';
        } else {
            $out['kind'] = 'trunk';
        }
        return $out;
    }

    /* ------------------------------------------------------ classificação */

    /** Em qual passo do caminho esta linha acontece. */
    private function classify($r, $a, $b, $localMap)
    {
        $app = strtolower(trim($r['lastapp']));
        $ctx = strtolower(trim($r['dcontext']));
        $dst = trim($r['dst']);

        // Segunda metade de um canal Local: pertence ao passo que a criou.
        if ($a['kind'] === 'local') {
            if (isset($localMap[$a['base']])) {
                return array('step' => $localMap[$a['base']], 'member' => $a['id'], 'attempt' => $a['base']);
            }
            if (strpos($a['ctx'], 'from-queue') === 0) {
                return array('step' => 'ext:' . $a['id'], 'member' => $a['id'], 'attempt' => $a['base']);
            }
        }

        $memberOf = function ($b) {
            if ($b['kind'] === 'local' || $b['kind'] === 'ext') {
                return array($b['id'], $b['base']);
            }
            return array(null, null);
        };

        if ($app === 'queue' || ($this->dir->isQueue($dst) && ($b['kind'] !== 'trunk'))) {
            list($m, $att) = $memberOf($b);
            return array('step' => 'queue:' . $dst, 'member' => $m, 'attempt' => $att);
        }
        if ($this->dir->isGroup($dst) || $ctx === 'ext-group') {
            list($m, $att) = $memberOf($b);
            return array('step' => 'group:' . $dst, 'member' => $m, 'attempt' => $att);
        }
        if ($app === 'voicemail' || preg_match('/^vm[ubis]?\d+/i', $dst)) {
            $box = preg_match('/(\d+)/', $r['lastdata'] !== '' ? $r['lastdata'] : $dst, $mm) ? $mm[1] : $dst;
            return array('step' => 'voicemail:' . $box, 'member' => null, 'attempt' => null);
        }
        if ($b['kind'] === 'trunk') {
            return array('step' => 'external:' . $dst, 'member' => '#' . $dst, 'attempt' => $b['base']);
        }
        if ($b['kind'] === 'ext' || $b['kind'] === 'local') {
            return array('step' => 'ext:' . $b['id'], 'member' => $b['id'], 'attempt' => $b['base']);
        }
        if (preg_match('/^ivr-(\d+)/', $ctx, $mm)) {
            return array('step' => 'ivr:' . $mm[1], 'member' => null, 'attempt' => null);
        }
        if (in_array($app, self::$ivrApps, true) || $dst === 's' || strpos($ctx, 'app-announcement') === 0) {
            return array('step' => 'ivr:', 'member' => null, 'attempt' => null);
        }
        return array('step' => 'other:' . $dst, 'member' => null, 'attempt' => null);
    }

    private function stepLabel($type, $target)
    {
        switch ($type) {
            case 'queue':     return $this->dir->queueName($target);
            case 'group':     return $this->dir->groupName($target);
            case 'ivr':       return $target === '' ? 'Mensagem / menu' : $this->dir->ivrName($target);
            case 'voicemail': return 'Caixa postal';
            case 'ext':
                $n = $this->dir->extName($target);
                return $n !== '' ? $n : 'Ramal ' . $target;
            case 'external':  return $target;
        }
        return $target === 'hangup' ? 'Encerrada' : ($target === '' ? 'Outro' : $target);
    }

    private function best($d1, $d2)
    {
        $r1 = isset(self::$rank[$d1]) ? self::$rank[$d1] : 0;
        $r2 = isset(self::$rank[$d2]) ? self::$rank[$d2] : 0;
        return $r2 > $r1 ? $d2 : $d1;
    }

    /* ------------------------------------------------------- montagem */

    private function buildCall($id, array $rows)
    {
        usort($rows, array('CdrGrouper', 'byRowOrder'));

        // 1a passada: de qual passo nasceu cada canal Local
        $localMap = array();
        $parsed = array();
        foreach ($rows as $i => $r) {
            $a = $this->parseChannel($r['channel']);
            $b = $this->parseChannel($r['dstchannel']);
            $parsed[$i] = array($a, $b);
            if ($b['kind'] === 'local' && $a['kind'] !== 'local') {
                $c = $this->classify($r, $a, $b, array());
                $localMap[$b['base']] = $c['step'];
            }
        }

        $start = null;
        $end = null;
        $steps = array();
        $origin = null;
        $recording = '';
        $recordingAnswered = false;
        $did = '';
        $raw = array();

        foreach ($rows as $i => $r) {
            list($a, $b) = $parsed[$i];
            $t0 = strtotime($r['calldate']);
            $dur = max(0, (int) $r['duration']);
            $bill = max(0, min($dur, (int) $r['billsec']));
            $disp = strtoupper(trim($r['disposition']));
            $t1 = $t0 + $dur;
            $ans = ($disp === 'ANSWERED' && $bill > 0) ? $t1 - $bill : null;

            $start = $start === null ? $t0 : min($start, $t0);
            $end = $end === null ? $t1 : max($end, $t1);
            if ($origin === null && $a['kind'] !== 'local' && $a['kind'] !== '') {
                $origin = array('row' => $r, 'ch' => $a);
            }
            if ($did === '' && !empty($r['did'])) {
                $did = $r['did'];
            }
            if ($r['recordingfile'] !== '' && (!$recordingAnswered || $recording === '')) {
                if ($recording === '' || $ans !== null) {
                    $recording = $r['recordingfile'];
                    $recordingAnswered = $ans !== null;
                }
            }

            $c = $this->classify($r, $a, $b, $localMap);
            $raw[] = array(
                'calldate' => $r['calldate'], 'src' => $r['src'], 'dst' => $r['dst'],
                'dcontext' => $r['dcontext'], 'channel' => $r['channel'], 'dstchannel' => $r['dstchannel'],
                'lastapp' => $r['lastapp'], 'lastdata' => $r['lastdata'], 'disposition' => $disp,
                'duration' => $dur, 'billsec' => $bill, 'uniqueid' => $r['uniqueid'],
                'recordingfile' => $r['recordingfile'], 'step' => $c['step'],
            );

            $key = $c['step'];
            if (!isset($steps[$key])) {
                $p = strpos($key, ':');
                $type = substr($key, 0, $p);
                $target = (string) substr($key, $p + 1);
                $steps[$key] = array(
                    'key' => $key, 'type' => $type, 'target' => $target,
                    'label' => $this->stepLabel($type, $target),
                    'start' => $t0, 'end' => $t1, 'disp' => '', 'answered' => $ans,
                    'talk' => 0, 'members' => array(),
                );
            }
            $s =& $steps[$key];
            $s['start'] = min($s['start'], $t0);
            $s['end'] = max($s['end'], $t1);
            $s['disp'] = $this->best($s['disp'], $disp);
            if ($ans !== null) {
                $s['answered'] = $s['answered'] === null ? $ans : min($s['answered'], $ans);
            }
            if ($c['member'] === null) {
                $s['talk'] = max($s['talk'], $bill);
            } else {
                $mk = $c['member'];
                if (!isset($s['members'][$mk])) {
                    $isExternal = $mk[0] === '#';
                    $num = $isExternal ? substr($mk, 1) : $mk;
                    $s['members'][$mk] = array(
                        'ext' => $num, 'external' => $isExternal,
                        'name' => $isExternal ? '' : $this->dir->extName($num),
                        'attempts' => array(),
                    );
                }
                $ak = $c['attempt'] !== null ? $c['attempt'] : 'row' . $i;
                $att =& $s['members'][$mk]['attempts'];
                if (!isset($att[$ak])) {
                    $att[$ak] = array('start' => $t0, 'end' => $t1, 'ans' => $ans, 'talk' => $bill, 'disp' => $disp);
                } else {
                    $x =& $att[$ak];
                    $x['start'] = min($x['start'], $t0);
                    $x['end'] = max($x['end'], $t1);
                    $x['talk'] = max($x['talk'], $bill);
                    $x['disp'] = $this->best($x['disp'], $disp);
                    if ($ans !== null) {
                        $x['ans'] = $x['ans'] === null ? $ans : min($x['ans'], $ans);
                    }
                    unset($x);
                }
                unset($att);
            }
            unset($s);
        }

        if ($start === null) {
            return null;
        }

        return $this->finish($id, $start, $end, $steps, $origin, $recording, $did, $raw);
    }

    private function finish($id, $start, $end, $steps, $origin, $recording, $did, $raw)
    {
        uasort($steps, function ($x, $y) {
            return $x['start'] - $y['start'];
        });

        // Direção
        $first = $origin !== null ? $origin['row'] : $raw[0];
        $firstCh = $origin !== null ? $origin['ch'] : array('kind' => '', 'id' => '');
        $hasExternal = false;
        foreach ($steps as $s) {
            if ($s['type'] === 'external') {
                $hasExternal = true;
            }
        }
        $ctx0 = strtolower($first['dcontext']);
        if ($firstCh['kind'] === 'trunk' || preg_match('/^from-(trunk|pstn|did)/', $ctx0)) {
            $direction = 'in';
        } elseif ($hasExternal) {
            $direction = 'out';
        } else {
            $direction = 'int';
        }

        if ($direction === 'int' && !empty($this->cfg['hide_feature_codes']) && substr(trim($first['dst']), 0, 1) === '*') {
            return null;
        }

        // Finaliza membros e coleta atendimentos
        $answers = array();
        $outSteps = array();
        $rangNoAnswer = array();
        foreach ($steps as $s) {
            $members = array();
            foreach ($s['members'] as $m) {
                $ring = 0;
                $talk = 0;
                $disp = '';
                $ans = null;
                $tries = array();
                $firstTry = null;
                foreach ($m['attempts'] as $t) {
                    $ringT = ($t['ans'] !== null ? $t['ans'] : $t['end']) - $t['start'];
                    $ring += max(0, $ringT);
                    $talk += $t['talk'];
                    $disp = $this->best($disp, $t['disp']);
                    if ($t['ans'] !== null && $t['talk'] > 0) {
                        $ans = $ans === null ? $t['ans'] : min($ans, $t['ans']);
                    }
                    $firstTry = $firstTry === null ? $t['start'] : min($firstTry, $t['start']);
                    $tries[] = array(
                        's' => $t['start'] - $start,
                        'r' => max(0, $ringT),
                        't' => $t['talk'],
                        'd' => $t['disp'],
                    );
                }
                usort($tries, function ($x, $y) {
                    return $x['s'] - $y['s'];
                });
                $answered = $ans !== null;
                $member = array(
                    'ext' => $m['ext'],
                    'name' => $m['name'],
                    'external' => $m['external'],
                    'result' => $answered ? 'answered' : $this->resultKey($disp),
                    'attempts' => count($tries),
                    'ring' => $ring,
                    'talk' => $talk,
                    'answeredAt' => $answered ? $ans - $start : null,
                    'firstAt' => $firstTry - $start,
                    'tries' => $tries,
                );
                $members[] = $member;
                if ($answered) {
                    $answers[] = array('at' => $ans, 'member' => $member, 'step' => $s);
                } elseif (!$m['external']) {
                    $rangNoAnswer[$m['ext']] = $m['name'];
                }
            }
            usort($members, array('CdrGrouper', 'byMemberOrder'));

            $step = array(
                'type' => $s['type'],
                'target' => $s['target'],
                'label' => $s['label'],
                'at' => $s['start'] - $start,
                'dur' => $s['end'] - $s['start'],
                'result' => $s['answered'] !== null ? 'answered' : $this->resultKey($s['disp']),
                'members' => $members,
            );
            if ($s['type'] === 'queue') {
                $campaignName = $this->campaigns->campaignForQueue($s['target']);
                if ($campaignName !== null) {
                    $step['campaign'] = $campaignName;
                }
            }
            if ($s['type'] === 'ivr' || $s['type'] === 'voicemail' || $s['type'] === 'other') {
                $step['dur'] = $s['talk'] > 0 ? $s['talk'] : $step['dur'];
            }
            $outSteps[] = $step;
        }
        // A linha da URA costuma continuar aberta enquanto o cliente espera na fila:
        // limitamos o tempo da URA ao início do passo seguinte.
        for ($i = 0; $i < count($outSteps) - 1; $i++) {
            if (in_array($outSteps[$i]['type'], array('ivr', 'other'), true)) {
                $gap = $outSteps[$i + 1]['at'] - $outSteps[$i]['at'];
                if ($gap >= 0) {
                    $outSteps[$i]['dur'] = min($outSteps[$i]['dur'], $gap);
                }
            }
        }

        usort($answers, function ($x, $y) {
            return $x['at'] - $y['at'];
        });
        foreach ($answers as $a) {
            unset($rangNoAnswer[$a['member']['ext']]);
        }

        // Partes da ligação
        $party = array('number' => '', 'name' => '', 'line' => '', 'lineName' => '');
        $fromExt = '';
        if ($direction === 'in') {
            $party['number'] = $first['src'] !== '' ? $first['src'] : $first['cnum'];
            $name = trim($first['cnam']);
            if ($name !== '' && $name !== $party['number'] && !preg_match('/^[\d\s+()-]+$/', $name)) {
                $party['name'] = $name;
            }
            $party['line'] = $did;
            $party['lineName'] = $did !== '' ? $this->dir->didName($did) : '';
        } else {
            $fromExt = $firstCh['kind'] === 'ext' ? $firstCh['id'] : $first['src'];
            if ($direction === 'out') {
                foreach ($outSteps as $st) {
                    if ($st['type'] === 'external') {
                        $party['number'] = $st['target'];
                        break;
                    }
                }
            } else {
                $party['number'] = $first['dst'];
                foreach ($outSteps as $st) {
                    if ($st['type'] === 'ext') {
                        $party['number'] = $st['target'];
                        $party['name'] = $st['label'] !== 'Ramal ' . $st['target'] ? $st['label'] : '';
                        break;
                    }
                }
            }
        }

        // Status
        $has = array();
        foreach ($outSteps as $st) {
            $has[$st['type']] = true;
        }
        $firstAnswer = $answers ? $answers[0] : null;
        $lastDisp = '';
        foreach ($outSteps as $st) {
            if ($st['type'] === 'external' || $st['type'] === 'ext') {
                $lastDisp = $this->best($lastDisp, $st['result'] === 'answered' ? 'ANSWERED' : strtoupper(str_replace('_', ' ', $st['result'])));
            }
        }
        if ($firstAnswer) {
            $status = 'answered';
        } elseif (isset($has['voicemail'])) {
            $status = 'voicemail';
        } elseif ($direction === 'out') {
            $status = $lastDisp === 'BUSY' ? 'busy' : ($lastDisp === 'FAILED' || $lastDisp === 'CONGESTION' ? 'failed' : 'noanswer');
        } elseif (isset($has['queue']) || isset($has['group']) || isset($has['ext']) || isset($has['external'])) {
            $status = 'missed';
        } elseif (isset($has['ivr'])) {
            $status = 'ivr';
        } else {
            $status = 'missed';
        }

        // Motivo, para perdidas
        $reason = '';
        if ($status === 'missed') {
            $rang = 0;
            $busy = 0;
            foreach ($outSteps as $st) {
                foreach ($st['members'] as $m) {
                    $rang++;
                    if ($m['result'] === 'busy') {
                        $busy++;
                    }
                }
            }
            if ($rang === 0) {
                $reason = 'nobody';      // desligou antes de tocar em algum ramal
            } elseif ($busy === $rang) {
                $reason = 'busy';        // todos ocupados
            } else {
                $reason = 'noanswer';    // tocou e ninguém atendeu
            }
        }

        // Departamento
        $department = '';
        $deptStep = null;
        if ($firstAnswer && in_array($firstAnswer['step']['type'], array('queue', 'group'), true)) {
            $deptStep = $firstAnswer['step'];
        } else {
            foreach ($outSteps as $st) {
                if ($st['type'] === 'queue' || $st['type'] === 'group') {
                    $deptStep = $st;
                }
            }
        }
        if ($deptStep !== null) {
            $department = $deptStep['label'];
        } elseif ($direction !== 'in' && $fromExt !== '') {
            $d = $this->dir->departmentsOf($fromExt);
            $department = $d ? $d[0] : '';
        } elseif ($firstAnswer && !$firstAnswer['member']['external']) {
            $d = $this->dir->departmentsOf($firstAnswer['member']['ext']);
            $department = $d ? $d[0] : '';
        }

        $talk = 0;
        $answeredBy = array();
        foreach ($answers as $a) {
            $talk += $a['member']['talk'];
            $answeredBy[] = array('ext' => $a['member']['ext'], 'name' => $a['member']['name'], 'external' => $a['member']['external']);
        }
        // Espera = do momento em que a ligação começou a chamar alguém (fila, grupo, ramal)
        // até ser atendida. O tempo na URA fica separado em $ivrTime.
        $ivrTime = 0;
        $waitFrom = null;
        foreach ($outSteps as $st) {
            if ($st['type'] === 'ivr' || $st['type'] === 'other') {
                if ($waitFrom === null) {
                    $ivrTime += $st['dur'];
                }
            } elseif ($waitFrom === null && $st['type'] !== 'voicemail') {
                $waitFrom = $start + $st['at'];
            }
        }
        if ($waitFrom === null) {
            $waitFrom = $start + $ivrTime;
        }
        if ($firstAnswer) {
            $wait = max(0, $firstAnswer['at'] - $waitFrom);
        } elseif ($direction === 'in' && $status !== 'ivr') {
            $vmAt = null;
            foreach ($outSteps as $st) {
                if ($st['type'] === 'voicemail') {
                    $vmAt = $start + $st['at'];
                }
            }
            $wait = max(0, ($vmAt !== null ? $vmAt : $end) - $waitFrom);
        } else {
            $wait = null;
        }

        $rang = array();
        foreach ($rangNoAnswer as $ext => $name) {
            $rang[] = array('ext' => (string) $ext, 'name' => $name);
        }

        $campaign = '';
        foreach ($outSteps as $st) {
            if (!empty($st['campaign'])) {
                $campaign = $st['campaign'];
                break;
            }
        }

        $from = array('ext' => $fromExt, 'name' => $fromExt !== '' ? $this->dir->extName($fromExt) : '');
        $trunk = '';
        if ($direction === 'in' && $firstCh['kind'] === 'trunk') {
            $trunk = $this->dir->trunkName($firstCh['id']);
        }

        return array(
            'id' => $id,
            'ts' => $start,
            'date' => date('Y-m-d H:i:s', $start),
            'direction' => $direction,
            'status' => $status,
            'reason' => $reason,
            'party' => $party,
            'from' => $from,
            'trunk' => $trunk,
            'department' => $department,
            'campaign' => $campaign,
            'answeredBy' => $answeredBy,
            'rang' => $rang,
            'transferred' => count($answers) > 1,
            'wait' => $wait,
            'ivrTime' => $ivrTime,
            'talk' => $talk,
            'total' => $end - $start,
            'recording' => $recording !== '',
            'recordingFile' => $recording,
            'steps' => $outSteps,
            'raw' => $raw,
            'callback' => null,
            'repeat' => 0,
        );
    }

    public static function byMemberOrder($x, $y)
    {
        $wx = $x['result'] === 'answered' ? 0 : 1;
        $wy = $y['result'] === 'answered' ? 0 : 1;
        if ($wx !== $wy) {
            return $wx - $wy;
        }
        return $x['firstAt'] - $y['firstAt'];
    }

    private function resultKey($disp)
    {
        switch ($disp) {
            case 'ANSWERED':   return 'answered';
            case 'BUSY':       return 'busy';
            case 'FAILED':
            case 'CONGESTION': return 'failed';
        }
        return 'noanswer';
    }
}
