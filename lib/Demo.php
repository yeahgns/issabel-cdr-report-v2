<?php
/**
 * Modo demonstração: gera linhas CRUAS de CDR no formato do Issabel
 * (canais Local ;1/;2, URA, fila ringall com rodadas, transferências, caixa postal)
 * e as entrega ao mesmo agrupador da produção. Serve para apresentar e para testar.
 */
class DemoData
{
    const TRUNK = 'Bradial_(00)0000-0000';

    private static $queues = array(
        '600' => array('Recepção', array('232', '293', '240')),
        '601' => array('Comercial', array('210', '211', '212')),
        '602' => array('Suporte técnico', array('220', '221')),
    );
    private static $users = array(
        '201' => 'Paulo Andrade', '210' => 'Bruno Lima', '211' => 'Fernanda Costa', '212' => 'Lucas Prado',
        '220' => 'Rafael Souza', '221' => 'Juliana Reis', '232' => 'Maria Oliveira', '240' => 'Carla Mendes',
        '293' => 'João Batista',
    );

    private $rows = array();
    private $seq = 0;

    public static function directory($labels)
    {
        $d = new PbxDirectory($labels);
        foreach (self::$queues as $n => $q) {
            $d->queues[$n] = $q[0];
            foreach ($q[1] as $m) {
                $d->members[$m][] = (string) $n;
            }
        }
        $d->users = self::$users;
        $d->ivrs = array('1' => 'Menu principal');
        $d->dids = array('1933334444' => 'Linha principal', '1933335555' => 'Comercial direto');
        $d->trunks = array(self::TRUNK => 'Bradial');
        return $d;
    }

    private static function hex($n = 8)
    {
        return str_pad(dechex(mt_rand(0, 0x7fffffff)), $n, '0', STR_PAD_LEFT);
    }

    private function chance($pct)
    {
        return mt_rand(1, 1000) <= $pct * 10;
    }

    private function add($ts, $o)
    {
        $this->seq++;
        $this->rows[] = array_merge(array(
            'calldate' => date('Y-m-d H:i:s', $ts), 'clid' => '', 'src' => '', 'dst' => '', 'dcontext' => '',
            'channel' => '', 'dstchannel' => '', 'lastapp' => '', 'lastdata' => '', 'duration' => 0, 'billsec' => 0,
            'disposition' => 'NO ANSWER', 'uniqueid' => '', 'linkedid' => '', 'sequence' => $this->seq,
            'recordingfile' => '', 'did' => '', 'cnum' => '', 'cnam' => '',
        ), $o);
    }

    private static function pool()
    {
        static $p = null;
        if ($p === null) {
            mt_srand(4242);
            $p = array();
            $ddd = array('19', '19', '19', '11', '11', '21', '16');
            for ($i = 0; $i < 140; $i++) {
                $mobile = mt_rand(0, 100) < 70;
                $p[] = $ddd[mt_rand(0, count($ddd) - 1)] . ($mobile ? '9' . mt_rand(81000000, 99999999) : mt_rand(30000000, 39999999));
            }
            $p[0] = '19993672179';
        }
        return $p;
    }

    public function rows($from, $to)
    {
        $pool = self::pool();
        $now = time();
        $end = min(strtotime($to), $now);
        for ($day = strtotime(substr($from, 0, 10) . ' 00:00:00'); $day <= $end; $day = strtotime('+1 day', $day)) {
            mt_srand((int) date('Ymd', $day));
            $dow = (int) date('w', $day);
            $n = $dow === 0 ? 3 : ($dow === 6 ? 14 : mt_rand(70, 105));
            $callbacks = array();
            for ($i = 0; $i < $n; $i++) {
                $ts = $this->businessTime($day, $dow);
                if ($ts > $now - 120) {
                    continue;
                }
                $number = $pool[mt_rand(0, (int) (count($pool) * 0.6))];
                $res = $this->inbound($ts, $number);
                if ($res && $this->chance(62)) {
                    $callbacks[] = array($ts + mt_rand(4, 110) * 60, $number, $res);
                }
            }
            foreach ($callbacks as $cb) {
                if ($cb[0] < $now - 60) {
                    $ext = self::$queues[$cb[2]][1][mt_rand(0, count(self::$queues[$cb[2]][1]) - 1)];
                    $this->outbound($cb[0], $ext, $cb[1], $this->chance(80) ? 'ANSWERED' : 'NO ANSWER');
                }
            }
            $m = $dow === 0 ? 0 : ($dow === 6 ? 4 : mt_rand(22, 36));
            for ($i = 0; $i < $m; $i++) {
                $ts = $this->businessTime($day, $dow);
                if ($ts > $now - 120) {
                    continue;
                }
                $exts = array_keys(self::$users);
                $r = mt_rand(1, 100);
                $disp = $r <= 66 ? 'ANSWERED' : ($r <= 90 ? 'NO ANSWER' : ($r <= 98 ? 'BUSY' : 'FAILED'));
                $this->outbound($ts, $exts[mt_rand(0, count($exts) - 1)], $pool[mt_rand(0, count($pool) - 1)], $disp);
            }
            $k = $dow === 0 || $dow === 6 ? 0 : mt_rand(4, 9);
            for ($i = 0; $i < $k; $i++) {
                $ts = $this->businessTime($day, $dow);
                if ($ts < $now - 120) {
                    $this->internal($ts);
                }
            }
        }
        usort($this->rows, array('CdrGrouper', 'byRowOrder'));
        return $this->rows;
    }

    private function businessTime($day, $dow)
    {
        // picos às 10h e às 14h30
        $peaks = array(array(10.0, 1.3), array(14.5, 1.5), array(16.5, 1.0));
        $p = $peaks[mt_rand(0, 2)];
        $u1 = mt_rand(1, 10000) / 10001;
        $u2 = mt_rand(1, 10000) / 10001;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        $h = max(8.0, min(18.2, $p[0] + $z * $p[1]));
        if ($dow === 6) {
            $h = 8 + mt_rand(0, 400) / 100;
        }
        return $day + (int) ($h * 3600) + mt_rand(0, 59);
    }

    /** Retorna a fila quando a ligação foi perdida (para simular retorno). */
    private function inbound($ts, $number)
    {
        $lid = $ts . '.' . mt_rand(1000, 99999);
        $trunk = 'SIP/' . self::TRUNK . '-' . self::hex();
        $direct = $this->chance(18);
        $did = $direct ? '1933335555' : '1933334444';
        $base = array('src' => $number, 'clid' => '"' . $number . '" <' . $number . '>', 'cnum' => $number,
                      'channel' => $trunk, 'uniqueid' => $lid, 'linkedid' => $lid, 'did' => $did);
        $t = $ts;

        if ($direct) {
            $queue = '601';
        } else {
            $ivr = mt_rand(6, 18);
            if ($this->chance(7)) {
                // desligou no menu
                $this->add($t, $base + array('dst' => 's', 'dcontext' => 'ivr-1', 'lastapp' => 'BackGround',
                    'lastdata' => 'custom/menu-principal', 'duration' => $ivr, 'billsec' => $ivr, 'disposition' => 'ANSWERED'));
                return null;
            }
            $this->add($t, $base + array('dst' => 's', 'dcontext' => 'ivr-1', 'lastapp' => 'BackGround',
                'lastdata' => 'custom/menu-principal', 'duration' => $ivr, 'billsec' => $ivr, 'disposition' => 'ANSWERED'));
            $t += $ivr;
            $r = mt_rand(1, 100);
            $queue = $r <= 52 ? '600' : ($r <= 78 ? '601' : '602');
        }

        $members = self::$queues[$queue][1];
        $willAnswer = $this->chance($queue === '602' ? 76 : 86);
        $patience = mt_rand(12, 140);
        $r = mt_rand(1, 100);
        $answerAfter = $willAnswer ? ($r <= 68 ? mt_rand(3, 18) : ($r <= 94 ? mt_rand(19, 45) : mt_rand(46, 95))) : null;
        $answerer = $willAnswer ? $members[mt_rand(0, count($members) - 1)] : null;
        $talk = mt_rand(35, 420);
        $busyNow = array();
        foreach ($members as $m) {
            if ($m !== $answerer && $this->chance(12)) {
                $busyNow[$m] = true;
            }
        }
        $rec = 'q-' . $queue . '-' . $number . '-' . date('Ymd-His', $ts) . '-' . $lid . '.wav';
        $qbase = $base + array('dst' => $queue, 'dcontext' => 'ext-queues', 'lastapp' => 'Queue',
                                'lastdata' => $queue . ',t,,,300', 'cnam' => '');

        if (!$willAnswer && $this->chance(14)) {
            // ninguém disponível / desistiu antes de tocar
            $w = mt_rand(8, 60);
            $this->add($t, $qbase + array('duration' => $w, 'billsec' => $w, 'disposition' => 'ANSWERED'));
            return $queue;
        }

        $timeout = 15;
        $retry = 4;
        $cycleStart = $t + 1;
        $limit = $willAnswer ? $t + $answerAfter : $t + $patience;
        while ($cycleStart < $limit) {
            $cycleEnd = min($cycleStart + $timeout, $limit);
            $lastCycle = $cycleEnd + $retry >= $limit;
            foreach ($members as $m) {
                $local = 'Local/' . $m . '@from-queue-' . self::hex();
                $isAnswer = $willAnswer && $m === $answerer && $lastCycle;
                if (isset($busyNow[$m])) {
                    $this->add($cycleStart, $qbase + array('dstchannel' => $local . ';1', 'duration' => 0, 'billsec' => 0, 'disposition' => 'BUSY'));
                    continue;
                }
                if ($isAnswer) {
                    $dur = ($cycleEnd - $cycleStart) + $talk;
                    $this->add($cycleStart, $qbase + array('dstchannel' => $local . ';1', 'duration' => $dur, 'billsec' => $talk,
                        'disposition' => 'ANSWERED', 'recordingfile' => $rec));
                    $this->add($cycleStart, array('src' => $number, 'cnum' => $number, 'dst' => $m, 'dcontext' => 'from-queue',
                        'channel' => $local . ';2', 'dstchannel' => 'SIP/' . $m . '-' . self::hex(), 'lastapp' => 'Dial',
                        'lastdata' => 'SIP/' . $m . ',,trM(auto-blkvm)', 'duration' => $dur, 'billsec' => $talk,
                        'disposition' => 'ANSWERED', 'uniqueid' => ($ts + 1) . '.' . mt_rand(100, 9999), 'linkedid' => $lid));
                } else {
                    $this->add($cycleStart, $qbase + array('dstchannel' => $local . ';1', 'duration' => $cycleEnd - $cycleStart,
                        'billsec' => 0, 'disposition' => 'NO ANSWER'));
                }
            }
            if ($lastCycle) {
                $limit = $cycleEnd;
                break;
            }
            $cycleStart = $cycleEnd + $retry;
        }
        $answerAfter = $limit - $t;

        if ($willAnswer) {
            // transferência ocasional
            if ($this->chance(7)) {
                $others = array_values(array_diff(array_keys(self::$users), array($answerer)));
                $to = $others[mt_rand(0, count($others) - 1)];
                $at = $t + $answerAfter + $talk;
                $ring = mt_rand(3, 12);
                $talk2 = mt_rand(40, 300);
                $this->add($at, array('src' => $number, 'cnum' => $number, 'dst' => $to, 'dcontext' => 'from-internal-xfer',
                    'channel' => $trunk, 'dstchannel' => 'SIP/' . $to . '-' . self::hex(), 'lastapp' => 'Dial',
                    'lastdata' => 'SIP/' . $to . ',,tr', 'duration' => $ring + $talk2, 'billsec' => $talk2,
                    'disposition' => 'ANSWERED', 'uniqueid' => $lid, 'linkedid' => $lid, 'recordingfile' => $rec));
            }
            return null;
        }
        if ($queue === '602' && $this->chance(35)) {
            $vm = mt_rand(12, 40);
            $this->add($limit + 1, $base + array('dst' => 'vmu220', 'dcontext' => 'ext-local', 'lastapp' => 'VoiceMail',
                'lastdata' => '220@default,u', 'duration' => $vm, 'billsec' => $vm, 'disposition' => 'ANSWERED'));
        }
        return $queue;
    }

    private function outbound($ts, $ext, $number, $disp)
    {
        $lid = $ts . '.' . mt_rand(1000, 99999);
        $dial = (substr($number, 0, 2) === '19' ? '' : '0') . $number;
        if (substr($number, 0, 2) === '19') {
            $dial = substr($number, 2);
        }
        $ring = mt_rand(4, 28);
        $talk = $disp === 'ANSWERED' ? mt_rand(25, 380) : 0;
        if ($disp === 'BUSY' || $disp === 'FAILED') {
            $ring = mt_rand(1, 4);
        }
        $this->add($ts, array('src' => $ext, 'cnum' => $ext, 'cnam' => isset(self::$users[$ext]) ? self::$users[$ext] : '',
            'dst' => $dial, 'dcontext' => 'from-internal', 'channel' => 'SIP/' . $ext . '-' . self::hex(),
            'dstchannel' => 'SIP/' . self::TRUNK . '-' . self::hex(), 'lastapp' => 'Dial',
            'lastdata' => 'SIP/' . self::TRUNK . '/' . $dial . ',300,T', 'duration' => $ring + $talk, 'billsec' => $talk,
            'disposition' => $disp, 'uniqueid' => $lid, 'linkedid' => $lid,
            'recordingfile' => $talk ? 'out-' . $dial . '-' . $ext . '-' . date('Ymd-His', $ts) . '-' . $lid . '.wav' : ''));
    }

    private function internal($ts)
    {
        $exts = array_keys(self::$users);
        $a = $exts[mt_rand(0, count($exts) - 1)];
        $b = $exts[mt_rand(0, count($exts) - 1)];
        $lid = $ts . '.' . mt_rand(1000, 99999);
        if ($this->chance(15)) {
            $this->add($ts, array('src' => $a, 'dst' => '*97', 'dcontext' => 'app-vmmain', 'channel' => 'SIP/' . $a . '-' . self::hex(),
                'lastapp' => 'VoiceMailMain', 'duration' => 20, 'billsec' => 20, 'disposition' => 'ANSWERED',
                'uniqueid' => $lid, 'linkedid' => $lid));
            return;
        }
        if ($a === $b) {
            return;
        }
        $answered = $this->chance(80);
        $ring = mt_rand(3, 15);
        $talk = $answered ? mt_rand(15, 200) : 0;
        $this->add($ts, array('src' => $a, 'cnum' => $a, 'dst' => $b, 'dcontext' => 'from-internal',
            'channel' => 'SIP/' . $a . '-' . self::hex(), 'dstchannel' => 'SIP/' . $b . '-' . self::hex(),
            'lastapp' => 'Dial', 'lastdata' => 'SIP/' . $b . ',20,tr', 'duration' => $ring + $talk, 'billsec' => $talk,
            'disposition' => $answered ? 'ANSWERED' : 'NO ANSWER', 'uniqueid' => $lid, 'linkedid' => $lid));
    }

    /** WAV de demonstração (tons suaves), para o player funcionar sem gravações reais. */
    public static function wav($seconds)
    {
        $rate = 8000;
        $n = $rate * $seconds;
        $data = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $i / $rate;
            $env = (fmod($t, 1.2) < 0.35) ? 0.25 : 0.0;
            $v = (int) (32767 * $env * sin(2 * M_PI * (440 + 110 * (((int) floor($t / 1.2)) % 3)) * $t));
            $data .= pack('v', $v & 0xffff);
        }
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
             . 'data' . pack('V', strlen($data)) . $data;
    }
}
