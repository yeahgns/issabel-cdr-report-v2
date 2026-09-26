<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ROOT', dirname(dirname(__FILE__)));

require_once APP_ROOT . '/lib/Directory.php';
require_once APP_ROOT . '/lib/CdrRepository.php';
require_once APP_ROOT . '/lib/CdrGrouper.php';
require_once APP_ROOT . '/lib/CallAnalyzer.php';
require_once APP_ROOT . '/lib/Demo.php';

function app_defaults()
{
    return array(
        'timezone'   => 'America/Sao_Paulo',
        'company'    => '',
        'demo'       => false,
        
        'db' => array(
            'host'   => 'localhost',
            'user'   => '',
            'pass'   => '',
            'cdr_db' => 'asteriskcdrdb',
            'pbx_db' => 'asterisk',
        ),

        'auth_users' => array(),

        'recordings_dir'        => '/var/spool/asterisk/monitor',
        'allow_recordings'      => true,
        'allow_tech_view'       => true,
        'max_range_days'        => 62,
        'callback_window_hours' => 48,
        'service_level_seconds' => 20,
        'hide_feature_codes'    => true,
        'hide_trunk_names'      => true,
        'count_ivr_as_missed'   => false,

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
    $name = $which === 'pbx' ? $db['pbx_db'] : $db['cdr_db'];
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $name . ';charset=utf8',
        $db['user'],
        $db['pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $pool[$which] = $pdo;
    return $pdo;
}
