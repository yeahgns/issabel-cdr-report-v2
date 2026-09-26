<?php
/**
 * Traduz números técnicos em nomes que o cliente entende.
 * Lê do banco "asterisk" do Issabel (FreePBX) e aplica os nomes manuais do config.
 */
class PbxDirectory
{
    public $queues  = array(); // '600' => 'Recepção'
    public $groups  = array(); // '601' => 'Comercial'
    public $users   = array(); // '232' => 'Maria'
    public $ivrs    = array(); // '1'   => 'Menu principal'
    public $dids    = array(); // '1933334444' => 'Linha principal'
    public $trunks  = array(); // 'Bradial_(00)0000-0000' => 'Bradial'
    public $members = array(); // '232' => array('600', '602')  (filas em que o ramal está)
    private $labels = array();

    public function __construct($labels = array())
    {
        $this->labels = is_array($labels) ? $labels : array();
    }

    public static function fromPdo($pdo, $labels)
    {
        $d = new PbxDirectory($labels);
        $d->queues = self::pairs($pdo, 'SELECT extension, descr FROM queues_config');
        $d->groups = self::pairs($pdo, 'SELECT grpnum, description FROM ringgroups');
        $d->users  = self::pairs($pdo, 'SELECT extension, name FROM users');
        $d->ivrs   = self::pairs($pdo, 'SELECT id, name FROM ivr_details');
        if (!$d->ivrs) {
            $d->ivrs = self::pairs($pdo, 'SELECT ivr_id, displayname FROM ivr'); // FreePBX antigo
        }
        $d->dids   = self::pairs($pdo, "SELECT extension, description FROM incoming WHERE extension <> ''");
        $d->trunks = self::pairs($pdo, 'SELECT channelid, name FROM trunks');

        $rows = self::rows($pdo, "SELECT id, data FROM queues_details WHERE keyword = 'member'");
        foreach ($rows as $r) {
            // data: "Local/232@from-queue/n,0" ou "SIP/232,0"
            if (preg_match('~^(?:Local|SIP|PJSIP|IAX2)/(\d+)~i', $r[1], $m)) {
                $d->members[$m[1]][] = (string) $r[0];
            }
        }
        return $d;
    }

    private static function rows($pdo, $sql)
    {
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        } catch (Exception $e) {
            return array(); // tabela inexistente nesta versão: segue sem
        }
    }

    private static function pairs($pdo, $sql)
    {
        $out = array();
        foreach (self::rows($pdo, $sql) as $r) {
            $name = trim((string) $r[1]);
            if ((string) $r[0] !== '' && $name !== '') {
                $out[(string) $r[0]] = $name;
            }
        }
        return $out;
    }

    private function label($key, $fallback)
    {
        return isset($this->labels[$key]) && $this->labels[$key] !== '' ? $this->labels[$key] : $fallback;
    }

    public function isQueue($n)  { return isset($this->queues[(string) $n]) || isset($this->labels['queue:' . $n]); }
    public function isGroup($n)  { return isset($this->groups[(string) $n]) || isset($this->labels['group:' . $n]); }

    public function isExtension($peer)
    {
        $peer = (string) $peer;
        if (isset($this->users[$peer]) || isset($this->labels['ext:' . $peer])) {
            return true;
        }
        // Sem lista de ramais disponível: 2 a 6 dígitos é ramal.
        return !$this->users && preg_match('/^\d{2,6}$/', $peer);
    }

    public function isTrunk($peer)
    {
        return isset($this->trunks[(string) $peer]) || isset($this->labels['trunk:' . $peer]);
    }

    public function queueName($n) { return $this->label('queue:' . $n, isset($this->queues[$n]) ? $this->queues[$n] : 'Fila ' . $n); }
    public function groupName($n) { return $this->label('group:' . $n, isset($this->groups[$n]) ? $this->groups[$n] : 'Grupo ' . $n); }
    public function ivrName($n)   { return $this->label('ivr:' . $n, isset($this->ivrs[$n]) ? $this->ivrs[$n] : 'Menu de atendimento'); }
    public function trunkName($n) { return $this->label('trunk:' . $n, isset($this->trunks[$n]) ? $this->trunks[$n] : $n); }

    public function extName($n)
    {
        $n = (string) $n;
        return $this->label('ext:' . $n, isset($this->users[$n]) ? $this->users[$n] : '');
    }

    public function didName($n)
    {
        $n = (string) $n;
        return $this->label('did:' . $n, isset($this->dids[$n]) ? $this->dids[$n] : '');
    }

    /** Filas/grupos a que um ramal pertence (para dar departamento a ligações feitas). */
    public function departmentsOf($ext)
    {
        $out = array();
        if (isset($this->members[$ext])) {
            foreach ($this->members[$ext] as $q) {
                $out[] = $this->queueName($q);
            }
        }
        return $out;
    }
}
