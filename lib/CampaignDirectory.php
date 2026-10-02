<?php
/**
 * Bradial - Relatório de ligações
 * Ponte opcional com o módulo Callcenter do Issabel (banco call_center).
 *
 * Não depende do nome da fila (ex.: "DISPAROS"): uma fila é de campanha quando
 * ela está cadastrada como `queue` em alguma linha de call_center.campaign.
 * Isso é o próprio vínculo que o discador usa para entregar a ligação ao
 * agente, então continua certo mesmo se o cliente renomear ou criar outra
 * fila com nome parecido.
 *
 * Quem não tem o módulo Callcenter instalado não tem o banco `call_center`
 * (ou não tem a tabela `campaign`): a conexão falha, isso é tratado aqui
 * dentro, e o relatório segue normalmente sem a categoria de campanha.
 */
if (!defined('APP_ROOT')) exit;

class CampaignDirectory
{
    /** @var array queueExtension(string) => nome da campanha(string) */
    private $byQueue = array();
    private $available = false;

    public static function detect(array $cfg)
    {
        $dir = new self();
        if (empty($cfg['campaigns_enabled'])) {
            return $dir; // desligado explicitamente no config
        }
        try {
            $pdo = app_pdo('campaigns');
            $check = $pdo->query("SHOW TABLES LIKE 'campaign'");
            if (!$check || !$check->fetchColumn()) {
                return $dir; // banco existe, mas sem o módulo Callcenter nativo
            }
            $rows = $pdo->query("SELECT queue, name FROM campaign WHERE queue IS NOT NULL AND queue <> ''");
            foreach ($rows as $r) {
                $dir->byQueue[(string) $r['queue']] = $r['name'];
            }
            $dir->available = true;
        } catch (Exception $e) {
            // Sem banco call_center, sem permissão, ou sem o módulo: desliga de vez.
            error_log('cdr-report: campanhas indisponíveis (' . $e->getMessage() . ')');
        }
        return $dir;
    }

    /** true quando o módulo Callcenter foi detectado neste servidor. */
    public function isAvailable()
    {
        return $this->available;
    }

    /** Nome da campanha dessa fila, ou null se a fila não for de campanha. */
    public function campaignForQueue($queueExtension)
    {
        $q = (string) $queueExtension;
        return isset($this->byQueue[$q]) ? $this->byQueue[$q] : null;
    }
}
