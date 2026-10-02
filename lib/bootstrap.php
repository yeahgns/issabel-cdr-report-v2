<?php
/**
 * Bradial - Relatório de ligações
 * Bootstrap: configuração, autenticação e conexão com o banco.
 * Compatível com PHP 5.4+ (Issabel 4 / CentOS 7).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ROOT', dirname(dirname(__FILE__)));

require_once APP_ROOT . '/lib/Directory.php';
require_once APP_ROOT . '/lib/CampaignDirectory.php';
require_once APP_ROOT . '/lib/CdrRepository.php';
require_once APP_ROOT . '/lib/CdrGrouper.php';
require_once APP_ROOT . '/lib/CallAnalyzer.php';
require_once APP_ROOT . '/lib/Demo.php';

function app_defaults()
{
    return array(
        'timezone'   => 'America/Sao_Paulo',
        'company'    => '',            // nome do cliente exibido no topo (opcional)
        'demo'       => false,         // true = dados fictícios, sem banco

        // Banco. Se user/pass ficarem vazios, lemos /etc/amportal.conf (AMPDBUSER/AMPDBPASS)
        // e, na falta dele, /etc/issabel.conf (mysqlrootpwd).
        'db' => array(
            'host'   => 'localhost',
            'user'   => '',
            'pass'   => '',
            'cdr_db' => 'asteriskcdrdb',
            'pbx_db' => 'asterisk',
        ),

        // Acesso por usuário/senha (HTTP Basic). Vazio = sem senha.
        // Ex.: array('recepcao' => 'umaSenhaForte')
        'auth_users' => array(),

        'recordings_dir'        => '/var/spool/asterisk/monitor',
        'allow_recordings'      => true,
        'allow_tech_view'       => true,   // botão "Visão técnica" (registros crus do CDR)
        'max_range_days'        => 62,
        'callback_window_hours' => 48,     // janela para considerar uma perdida como "retornada"
        'service_level_seconds' => 20,     // nível de serviço: % atendidas em até X segundos
        'hide_feature_codes'    => true,   // esconde *97, *65 etc.
        'hide_trunk_names'      => true,   // na visão do cliente, tronco aparece como "Linha"
        'count_ivr_as_missed'   => false,  // quem desligou na URA conta como perdida?

        // Categoria "Campanha": detectada sozinha em quem tem o módulo Callcenter
        // do Issabel instalado (banco call_center com a tabela campaign). Quem
        // não tem o módulo não é afetado: a conexão falha e a categoria some.
        // 'db.campaigns_db' => nome do banco, se for diferente de 'call_center'.
        'campaigns_enabled'     => true,

        // Nomes manuais. Sobrescrevem o que vem do Issabel.
        // Chaves: queue:600, group:601, ext:232, ivr:1, did:1933334444, trunk:NomeDoTronco
        'labels' => array(),
    );
}

function app_config()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $user = array();
    $file = APP_ROOT . '/config.php';
    if (is_file($file)) {
        $loaded = include $file;
        if (is_array($loaded)) {
            $user = $loaded;
        }
    }
    $cfg = array_replace_recursive(app_defaults(), $user);
    // listas substituem por completo (não mesclam)
    if (isset($user['labels'])) {
        $cfg['labels'] = $user['labels'];
    }
    if (isset($user['auth_users'])) {
        $cfg['auth_users'] = $user['auth_users'];
    }
    date_default_timezone_set($cfg['timezone']);
    return $cfg;
}

function app_equals($a, $b)
{
    $a = (string) $a;
    $b = (string) $b;
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $r = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $r |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $r === 0;
}

function app_require_auth()
{
    $cfg = app_config();
    if (empty($cfg['auth_users'])) {
        return;
    }
    $u = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
    $p = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
    if ($u !== '' && isset($cfg['auth_users'][$u]) && app_equals($cfg['auth_users'][$u], $p)) {
        return;
    }
    header('WWW-Authenticate: Basic realm="Relatorio de ligacoes"');
    http_response_code(401);
    echo 'Acesso restrito.';
    exit;
}

function app_read_kv_file($path)
{
    $out = array();
    if (!@is_readable($path)) {
        return $out;
    }
    foreach (file($path) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $out[trim(substr($line, 0, $pos))] = trim(trim(substr($line, $pos + 1)), "\"'");
    }
    return $out;
}

function app_db_credentials()
{
    $cfg = app_config();
    $db = $cfg['db'];
    if ($db['user'] !== '') {
        return $db;
    }
    $amp = app_read_kv_file('/etc/amportal.conf');
    if (!empty($amp['AMPDBUSER'])) {
        $db['user'] = $amp['AMPDBUSER'];
        $db['pass'] = isset($amp['AMPDBPASS']) ? $amp['AMPDBPASS'] : '';
        if (!empty($amp['AMPDBHOST'])) {
            $db['host'] = $amp['AMPDBHOST'];
        }
        return $db;
    }
    $iss = app_read_kv_file('/etc/issabel.conf');
    if (!empty($iss['mysqlrootpwd'])) {
        $db['user'] = 'root';
        $db['pass'] = $iss['mysqlrootpwd'];
    }
    return $db;
}

function app_pdo($which)
{
    static $pool = array();
    if (isset($pool[$which])) {
        return $pool[$which];
    }
    $db = app_db_credentials();
    if ($which === 'campaigns') {
        // Banco do módulo Callcenter (opcional: só existe em quem tem o addon instalado).
        $name = isset($db['campaigns_db']) ? $db['campaigns_db'] : 'call_center';
    } else {
        $name = $which === 'pbx' ? $db['pbx_db'] : $db['cdr_db'];
    }
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $name . ';charset=utf8',
        $db['user'],
        $db['pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $pool[$which] = $pdo;
    return $pdo;
}
