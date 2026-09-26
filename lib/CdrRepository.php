<?php
/**
 * Lê as linhas cruas da tabela cdr. Nenhum filtro "esconde-linha" aqui:
 * tudo é lido e o agrupamento decide o que é ligação, perna ou ruído.
 */
class CdrRepository
{
    private $pdo;
    private $cols = null;

    private static $wanted = array(
        'calldate', 'clid', 'src', 'dst', 'dcontext', 'channel', 'dstchannel',
        'lastapp', 'lastdata', 'duration', 'billsec', 'disposition',
        'uniqueid', 'linkedid', 'sequence', 'recordingfile', 'did', 'cnum', 'cnam',
    );

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function columns()
    {
        if ($this->cols === null) {
            $this->cols = array();
            foreach ($this->pdo->query('SHOW COLUMNS FROM cdr')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $this->cols[$c['Field']] = true;
            }
        }
        return $this->cols;
    }

    private function selectList()
    {
        $have = $this->columns();
        $parts = array();
        foreach (self::$wanted as $c) {
            $parts[] = isset($have[$c]) ? "`$c`" : "'' AS `$c`";
        }
        return implode(', ', $parts);
    }

    /** Todas as linhas entre $from e $to (Y-m-d H:i:s). */
    public function fetch($from, $to)
    {
        $cols = $this->columns();
        $order = isset($cols['sequence']) ? 'calldate, sequence' : 'calldate';
        $sql = 'SELECT ' . $this->selectList() . ' FROM cdr WHERE calldate BETWEEN ? AND ? ORDER BY ' . $order;
        $st = $this->pdo->prepare($sql);
        $st->execute(array($from, $to));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Arquivo de gravação de uma ligação (o nome nunca vem do navegador). */
    public function recordingFor($callId)
    {
        $cols = $this->columns();
        $where = isset($cols['linkedid']) ? '(linkedid = ? OR uniqueid = ?)' : 'uniqueid = ?';
        $params = isset($cols['linkedid']) ? array($callId, $callId) : array($callId);
        $sql = "SELECT calldate, recordingfile, disposition FROM cdr WHERE $where AND recordingfile <> ''"
             . " ORDER BY (disposition = 'ANSWERED') DESC, calldate LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }
}
