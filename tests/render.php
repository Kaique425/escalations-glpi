<?php
/**
 * Renderiza as telas do plugin pela linha de comando (sessão simulada do usuário glpi, perfil Super-Admin) e confere se
 * alguma devolve erro/aviso PHP. O Html::header só imprime o layout uma vez por processo: uma tela por execução.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/escalations/tests/render.php setup
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/escalations/tests/render.php page painel [--save=/tmp/esc-snaps]
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/escalations/tests/render.php teardown
 *
 * `setup` cria uma hierarquia "[Render ESC]" com nível, regra e um escalonamento (só pop-up envia); `teardown` remove tudo.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB, $CFG_GLPI;
$user = new User();
$user->getFromDBbyName('glpi');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::initEntityProfiles((int) $user->getID());
foreach ($_SESSION['glpiprofiles'] ?? [] as $pid => $p) {
    if (($p['name'] ?? '') === 'Super-Admin') {
        Session::changeProfile($pid);
        break;
    }
}
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
$_SESSION['glpiname'] = $user->fields['name'];
$_SESSION['valid_id'] = session_id();
$_SESSION['login_session_uid'] = 'esc-render-' . bin2hex(random_bytes(4));
if ($DB->tableExists('glpi_users_sessions')) {
    $DB->insert('glpi_users_sessions', ['users_id' => (int) $user->getID(), 'login_session_uid' => $_SESSION['login_session_uid'], 'session_file' => 'esc-render',
        'ip_address' => '127.0.0.1', 'user_agent' => 'esc-render', 'auth_type' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    register_shutdown_function(fn () => $DB->delete('glpi_users_sessions', ['login_session_uid' => $_SESSION['login_session_uid']]));
}
use GlpiPlugin\Escalations\{Escalation, Log, Policy, Settings};

$_SESSION['glpiactiveprofile'][Escalation::$rightname] = READ | CREATE | UPDATE | PURGE | Escalation::MANAGE | Escalation::ALL | Escalation::ACK | Escalation::RESOLVE | Escalation::SILENCE | Escalation::CANCEL | Escalation::LOGS | Escalation::SLO;
$uid = (int) $user->getID();
$state = sys_get_temp_dir() . '/esc-render.json';
$mode = $argv[1] ?? 'page';

if ($mode === 'setup') {
    Settings::set(['mail_enabled' => '0', 'teams_enabled' => '0', 'wa_provider' => 'off']);
    $pid = Policy::save(['name' => '[Render ESC] NOC', 'area' => 'Render', 'is_active' => 1, 'users_id_owner' => $uid, 'ack_slo_min' => 10, 'resolve_slo_min' => 60, 'slo_target' => 95]);
    Policy::saveLevel($pid, ['name' => 'N1 Operação', 'wait_min' => 5, 'channels' => ['popup', 'email'], 'users' => [$uid]]);
    Policy::saveLevel($pid, ['name' => 'N2 Gerência', 'wait_min' => 10, 'channels' => ['popup'], 'users' => [$uid], 'supervisor' => 1]);
    Policy::saveRule($pid, ['name' => 'Render', 'source' => 'any', 'severity_min' => 1, 'sla_pct' => 80, 'is_active' => 1]);
    [$eid] = Escalation::open(['policies_id' => $pid, 'source' => 'manual', 'source_ref' => 'render', 'title' => '[Render ESC] Link principal fora', 'summary' => "Primeira linha\nSegunda linha",
        'severity' => 5, 'facts' => ['Cliente' => 'ACME', 'Abrir' => 'https://example.com/x'], 'actor' => 'system']);
    file_put_contents($state, json_encode(['pid' => $pid, 'eid' => $eid]));
    echo "fixtures criadas: política $pid, escalonamento $eid\n";
    exit(0);
}
if ($mode === 'teardown') {
    $s = json_decode((string) @file_get_contents($state), true) ?: [];
    foreach ($DB->request(['SELECT' => ['id', 'trace_id'], 'FROM' => Escalation::TABLE, 'WHERE' => ['policies_id' => (int) ($s['pid'] ?? 0)]]) as $r) {
        $DB->delete(Log::TABLE, ['trace_id' => $r['trace_id']]);
        $DB->delete(Escalation::DELIVERIES, ['escalations_id' => $r['id']]);
        $DB->delete(Escalation::TABLE, ['id' => $r['id']]);
    }
    if (!empty($s['pid'])) {
        Policy::delete((int) $s['pid']);
    }
    $DB->delete(Log::TABLE, ['message' => ['LIKE', '%Render ESC%']]);
    @unlink($state);
    echo "fixtures removidas\n";
    exit(0);
}

$s = json_decode((string) @file_get_contents($state), true) ?: ['pid' => 0, 'eid' => 0];
foreach ($argv as $a) { // --eid=N e --pid=N apontam para registros existentes (ex.: a demonstração)
    if (str_starts_with($a, '--eid=')) {
        $s['eid'] = (int) substr($a, 6);
    }
    if (str_starts_with($a, '--pid=')) {
        $s['pid'] = (int) substr($a, 6);
    }
}
$pages = [
    'painel' => ['/front/painel.php', []], 'escalonamentos' => ['/front/escalonamentos.php', []], 'escalonamento' => ['/front/escalonamento.php', ['id' => $s['eid']]],
    'novo' => ['/front/escalonamento.php', ['novo' => 1]], 'slo' => ['/front/slo.php', []], 'gaps' => ['/front/gaps.php', ['policy' => $s['pid']]], 'logs' => ['/front/logs.php', []], 'outbox' => ['/front/outbox.php', []], 'permissoes' => ['/front/permissoes.php', []],
    'hierarquias' => ['/front/hierarquias.php', []], 'hierarquia' => ['/front/hierarquia.php', ['id' => $s['pid']]], 'hierarquia-nova' => ['/front/hierarquia.php', []], 'config' => ['/front/config.php', []],
];
$pageKey = $argv[2] ?? '';
if (!isset($pages[$pageKey])) {
    fwrite(STDERR, 'Telas: ' . implode(', ', array_keys($pages)) . "\n");
    exit(2);
}
$save = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--save=')) {
        $save = substr($a, 7);
        @mkdir($save, 0775, true);
    }
}
[$path, $get] = $pages[$pageKey];
$_GET = $_REQUEST = $get;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/escalations' . $path;
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    if (str_contains($str, 'Since twig/twig')) { // depreciação do layout do próprio GLPI (links "adicionar/buscar" do menu), não é do plugin
        return true;
    }
    $warnings[] = "$str @ " . basename($file) . ":$line";
    return true;
});
ob_start();
try {
    include $root . '/plugins/escalations' . $path;
} catch (Throwable $e) {
    echo '<!--EXC ' . $e->getMessage() . '-->';
    $warnings[] = 'EXCEÇÃO: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
}
$html = ob_get_clean();
restore_error_handler();
$ok = !$warnings && strlen($html) > 2000 && !preg_match('/Fatal error|Stack trace/', $html);
echo ($ok ? 'OK   ' : 'FALHA') . " $pageKey (" . strlen($html) . " bytes)\n";
foreach (array_slice($warnings, 0, 6) as $w) {
    echo "       $w\n";
}
if ($save) {
    file_put_contents("$save/$pageKey.html", $html);
}
exit($ok ? 0 : 1);
