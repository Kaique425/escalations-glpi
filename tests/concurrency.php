<?php

/**
 * Runs with the GLPI core bootstrapped against a disposable, empty esc_race_* database.
 * Use an isolated network with no external egress. The Escalations plugin stays uninstalled.
 * GLPI_ROOT=/var/www/glpi ESCALATIONS_TEST_DB=esc_race_lab php tests/concurrency.php
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Escalations\Cron;
use GlpiPlugin\Escalations\Engine;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\EventIntake;
use GlpiPlugin\Escalations\Lock;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Outbox;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$database = getenv('ESCALATIONS_TEST_DB');
if (!$database || !preg_match('/^esc_race_[a-z0-9_]+$/D', $database)) {
    throw new RuntimeException('Use a disposable database named esc_race_*.');
}

$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Escalations\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php';
    }
});
$cli = dirname(__DIR__) . '/tools/tick.php';
if ($_SERVER['SCRIPT_FILENAME'] === $cli) {
    return; // auto_prepend_file: only provide the uninstalled plugin's autoloader.
}
putenv('GLPI_ROOT=' . $root);
chdir($root);
require $root . '/vendor/autoload.php';
(new Kernel('production'))->boot();
if ($DB->dbdefault !== $database || Plugin::isPluginActive('escalations')) {
    throw new RuntimeException('The active GLPI database must be the disposable database with Escalations uninstalled.');
}

function openInput(int $policy, string $key): array
{
    return ['policies_id' => $policy, 'source' => 'manual', 'dedup_key' => $key, 'title' => 'Concurrency'];
}

if (($argv[1] ?? '') === 'worker') {
    [$action, $id, $argument] = [$argv[2], (int) $argv[3], $argv[4]];
    echo "READY\n";
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(1);
    }
    try {
        $result = match ($action) {
            'open' => Escalation::open(openInput($id, $argument)),
            'ack' => Escalation::ack($id, (int) $argument, 'popup'),
            'resolve' => Escalation::resolve($id, 'manual', (int) $argument),
            'cancel' => Escalation::cancel($id, 'test', (int) $argument),
            'recover' => EventIntake::handle(['title' => 'Concurrency', 'dedup_key' => $argument, 'status' => 'resolved']),
            'tick' => Engine::tick(),
            'cron' => Cron::cronEscalationsrun(new CronTask()),
            'cli' => cliCycle($cli),
        };
        echo json_encode(['code' => 200, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['code' => $e->getCode(), 'error' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    }
    exit;
}

function cliCycle(string $script): int
{
    $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __FILE__, $script, '-v'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
    ], $pipes);
    check(is_resource($process), 'CLI launched');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    check(proc_close($process) === 0 && preg_match('/ciclo concluído: (\d+) ação/', $output, $matches) === 1, 'CLI returned the action count');
    return (int) $matches[1];
}

function startWorker(string $action, int $id = 0, string $argument = ''): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $action, (string) $id, $argument], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR,
    ], $pipes);
    check(is_resource($process), 'worker launched');
    stream_set_timeout($pipes[1], 45);
    if (fgets($pipes[1]) !== "READY\n") {
        proc_terminate($process);
        throw new RuntimeException("Worker did not start: $action");
    }
    return [$process, $pipes];
}

function releaseWorker(array $worker): void
{
    fwrite($worker[1][0], "GO\n");
    fclose($worker[1][0]);
}

function finishWorker(array $worker): array
{
    [$process, $pipes] = $worker;
    $json = fgets($pipes[1]);
    if ($json === false) {
        proc_terminate($process);
        throw new RuntimeException('Worker exited without a result or exceeded the timeout.');
    }
    fclose($pipes[1]);
    check(proc_close($process) === 0, 'worker completed');
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function parallel(array ...$operations): array
{
    $workers = array_map(static fn (array $operation): array => startWorker(...$operation), $operations);
    foreach ($workers as $worker) {
        releaseWorker($worker);
    }
    return array_map(finishWorker(...), $workers);
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Failed: $label");
    }
}

function countEvents(int $id, array|string $events): int
{
    return (int) countElementsInTable(Log::TABLE, ['escalations_id' => $id, 'event' => $events]);
}

function forceDue(int $id): void
{
    global $DB;
    $DB->update(Escalation::TABLE, ['next_action_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $id]);
}

require dirname(__DIR__) . '/hook.php';
$tables = plugin_escalations_table_definitions(DBConnection::getDefaultPrimaryKeySignOption());
foreach (array_keys($tables) as $table) {
    check(!$DB->tableExists($table), 'Escalations tables must not exist before the suite');
}
$charset = DBConnection::getDefaultCharset();
$collation = DBConnection::getDefaultCollation();
foreach ($tables as $table => $definition) {
    $DB->doQuery("CREATE TABLE `$table` ($definition) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
}

$settings = ['sources' => 'manual', 'mail_enabled' => '0', 'teams_enabled' => '0', 'wa_provider' => 'off',
    'avisos_bridge' => '0', 'sink' => 'teams', 'outbox' => '1', 'owner_channels' => 'popup', 'fallback_channel' => 'none'];
Settings::set($settings);
[$user, $other] = array_map(
    static fn (array $row): int => (int) $row['id'],
    iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'id', 'LIMIT' => 2]), false)
);

$policy = Policy::save(['name' => 'Concurrency', 'users_id_owner' => $user, 'is_active' => 1, 'max_repeats' => 1]);
foreach ([1, 2] as $position) {
    Policy::saveLevel($policy, ['name' => "N$position", 'channels' => ['popup'], 'users' => [$user]]);
}

$opened = parallel(
    ['open', $policy, 'Ráce-Key'],
    ['open', $policy, 'race-key'],
    ['open', $policy, 'RACE-KEY'],
    ['open', $policy, 'race-key ']
);
check(array_column($opened, 'code') === [200, 200, 200, 200], 'all openings succeeded');
$id = $opened[0]['result'][0];
check(count(array_filter($opened, static fn (array $r): bool => $r['result'][1])) === 1, 'only one opening won');
check(array_unique(array_map(static fn (array $r): int => $r['result'][0], $opened)) === [$id], 'all callers received the same id');
check((int) Escalation::get($id)['occurrences'] === 4, 'no lost occurrence increments');
check(countEvents($id, 'opened') === 1 && countEvents($id, 'dedup') === 3 && countEvents($id, 'level_start') === 1, 'one opening and one N1 with three dedup logs');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $id, 'channel' => 'popup']) === 1, 'one initial delivery');
echo "OK concurrent dedup, including collation-equivalent keys\n";

forceDue($id);
Lock::run('tick', static function () use ($id): void {
    $cycles = parallel(['tick'], ['cron'], ['cli']);
    check($cycles === array_fill(0, 3, ['code' => 200, 'result' => 0]), 'all entry points lost the same lock');
    check(Settings::get('last_tick') === '' && (int) Escalation::get($id)['level'] === 1 && countEvents($id, 'escalate') === 0, 'losing cycles made no changes');
});
$cycles = parallel(['tick'], ['cron'], ['cli']);
check(array_column($cycles, 'code') === [200, 200, 200] && array_sum(array_column($cycles, 'result')) === 1
    && (int) Escalation::get($id)['level'] === 2, 'native cron, engine and CLI advanced once');
check(countEvents($id, 'escalate') === 1 && countEvents($id, 'level_start') === 2, 'only one advance had effects');
echo "OK common cycle lock and single N1 to N2 transition\n";

forceDue($id);
Escalation::withLock($id, static function () use ($id, $user): void {
    $result = parallel(['tick']);
    check($result === [['code' => 200, 'result' => 0]], 'tick did not advance a locked escalation');
    Escalation::ack($id, $user, 'glpi');
});
check(Engine::tick() === 0 && Escalation::get($id)['next_action_at'] === null && countEvents($id, 'repeat') === 0, 'ACK stopped a due repeat');
$DB->update(Escalation::TABLE, ['resolve_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $id]);
Engine::tick();
Engine::tick();
check((int) Escalation::get($id)['resolve_breached'] === 1 && countEvents($id, 'slo_breach') === 1, 'resolution SLO continued after ACK, with one breach');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $id, 'kind' => 'slo_breach:resolve']) === 1, 'one owner warning');
echo "OK ACK stops advancement and resolution SLO continues\n";

$closed = parallel(['resolve', $id, (string) $user], ['cancel', $id, (string) $other]);
$codes = array_column($closed, 'code');
sort($codes);
check($codes === [200, 409], 'resolution and cancellation had exactly one winner');
check(countEvents($id, ['resolved', 'cancelled']) === 1, 'one terminal audit event');
$state = Escalation::get($id)['state'];
check($state === ($closed[0]['code'] === 200 ? 'resolved' : 'cancelled'), 'terminal state matches the winning action');
check(countElementsInTable(Outbox::TABLE, ['escalations_id' => $id, 'status' => 'sim']) === ($state === 'resolved' ? 2 : 1), 'only a winning resolution announces');
[$next, $created] = Escalation::open(openInput($policy, 'race-key'));
check($created && $next !== $id, 'a new occurrence can open after a terminal state');
echo "OK resolution versus cancellation, with legitimate reopening\n";

$acks = parallel(['ack', $next, (string) $user], ['ack', $next, (string) $other]);
$codes = array_column($acks, 'code');
sort($codes);
check($codes === [200, 409], 'simultaneous ACK returns one conflict');
check((int) Escalation::get($next)['users_id_ack'] === ($acks[0]['code'] === 200 ? $user : $other), 'ACK author matches the winner');
check(countEvents($next, 'ack') === 1, 'one ACK audit event');
check(countElementsInTable(Outbox::TABLE, ['escalations_id' => $next, 'status' => 'sim']) === 1, 'one ACK announcement');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $next, 'channel' => 'popup', 'status' => 'acked']) === 1, 'one pending popup cleared');
check(Log::verify(Escalation::get($id)['trace_id'])[0] && Log::verify(Escalation::get($next)['trace_id'])[0], 'both audit chains verified');
echo "OK simultaneous ACK, single set of effects and intact trace hashes\n";

[$last] = Escalation::open(openInput($policy, 'last-level'));
foreach (['escalate', 'repeat', 'exhausted'] as $event) {
    forceDue($last);
    $cycles = parallel(['cron'], ['tick']);
    check(array_column($cycles, 'code') === [200, 200] && array_sum(array_column($cycles, 'result')) === 1, "one winning cycle for $event");
    check(countEvents($last, $event) === 1, "one audit entry for $event");
}
check(Escalation::get($last)['state'] === 'open' && Escalation::get($last)['next_action_at'] === null, 'exhaustion leaves the escalation open without advancing');
check(countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $last, 'kind' => 'exhausted']) === 1, 'one exhaustion notice to the owner');
echo "OK one repeat and one exhaustion notice\n";

[$previous] = Escalation::open(openInput($policy, 'closing-key'));
$race = parallel(['cancel', $previous, (string) $user], ['open', $policy, 'closing-key']);
check(array_column($race, 'code') === [200, 200], 'opening and cancellation both completed');
[$openedId, $wasCreated] = $race[1]['result'];
check(Escalation::get($previous)['state'] === 'cancelled' && countEvents($previous, 'cancelled') === 1, 'the original occurrence closed once');
if ($wasCreated) {
    check($openedId !== $previous && Escalation::get($openedId)['state'] === 'open', 'opening after cancellation created a new occurrence');
} else {
    check($openedId === $previous && (int) Escalation::get($previous)['occurrences'] === 2, 'opening before cancellation grouped into the previous occurrence');
}
check(countElementsInTable(Escalation::TABLE, ['dedup_key' => 'closing-key', 'state' => ['open', 'acked']]) === (int) $wasCreated, 'at most one occurrence stayed active');
$manual = parallel(['open', $policy, ''], ['open', $policy, '']);
check(array_column($manual, 'code') === [200, 200] && $manual[0]['result'][1] && $manual[1]['result'][1]
    && $manual[0]['result'][0] !== $manual[1]['result'][0], 'manual openings without a deduplication key remain independent');
echo "OK cancellation racing with a new occurrence and independent unkeyed openings\n";

Settings::set(['sources' => 'event']);
[$code, $event] = EventIntake::handle(['title' => 'Concurrency', 'dedup_key' => 'recover-key', 'policy_id' => $policy]);
check($code === 201, 'webhook handler opened the event');
$recoveries = parallel(['recover', 0, 'recover-key'], ['recover', 0, 'recover-key']);
check(array_column($recoveries, 'code') === [200, 200], 'both recovery workers completed');
$actions = [];
foreach ($recoveries as $response) {
    [$status, $body] = $response['result'];
    check($status === 200, 'the duplicate recovery is accepted');
    $actions[] = $body['action'];
}
sort($actions);
check($actions === ['ignored', 'resolved'] && countEvents($event['id'], 'resolved') === 1
    && countEvents($event['id'], 'webhook') === 2, 'only the winning recovery has success effects on the trace');
echo "OK concurrent recovery events are idempotent\n";
