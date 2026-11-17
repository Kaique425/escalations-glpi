<?php
/**
 * Teste funcional do Escalations pela linha de comando: hierarquia com níveis e regras, abertura, deduplicação,
 * subida de nível por tempo, ACK, SLO (alerta e estouro), logs encadeados por hash (e detecção de adulteração),
 * pop-up pendente, evento por webhook (abre, agrupa, recupera) e filtro de regras.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php plugins/escalations/tests/smoke.php
 *
 * Cria registros "[Teste ESC]" e os remove no fim. Desliga e-mail, Teams e WhatsApp durante o teste (só o pop-up
 * registra entrega) e restaura a configuração depois.
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
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');

use GlpiPlugin\Escalations\{Actions, Outbox, Api, Dispatcher, Engine, Escalation, EventIntake, Gaps, ItemWatcher, Log, Message, Perms, Policy, Settings, Slo};

$fails = 0;
$ok = function ($label, $v) use (&$fails) {
    echo ($v ? 'OK   ' : 'FALHA') . " $label\n";
    $fails += $v ? 0 : 1;
    return $v;
};
$uid = (int) $user->getID();

// só o pop-up envia durante o teste
$saved = Config::getConfigurationValues(Settings::CONTEXT);
Settings::set(['mail_enabled' => '0', 'teams_enabled' => '0', 'wa_provider' => 'off', 'popup_enabled' => '1', 'owner_channels' => 'popup', 'fallback_channel' => 'none']);

$pid = Policy::save(['name' => '[Teste ESC] NOC', 'area' => 'Teste', 'is_active' => 1, 'users_id_owner' => $uid, 'ack_slo_min' => 10, 'resolve_slo_min' => 60,
    'slo_target' => 90, 'slo_window_days' => 30, 'slo_warn_pct' => 50, 'repeat_min' => 5, 'max_repeats' => 1]);
Policy::saveLevel($pid, ['name' => 'N1', 'wait_min' => 5, 'channels' => ['popup'], 'users' => [$uid]]);
Policy::saveLevel($pid, ['name' => 'N2', 'wait_min' => 5, 'channels' => ['popup'], 'users' => [$uid]]);
Policy::saveRule($pid, ['name' => 'Eventos críticos', 'source' => 'event', 'severity_min' => 4, 'match_text' => 'ESCTESTE', 'is_active' => 1]);
$ok('hierarquia com 2 níveis e 1 regra', count(Policy::levels($pid)) === 2 && count(Policy::rules($pid)) === 1);
$ok('regra casa evento crítico com o texto', (int) (Policy::match('event', 5, 0, 0, 'Disco ESCTESTE cheio')['policies_id'] ?? 0) === $pid);
$ok('regra não casa severidade baixa', Policy::match('event', 2, 0, 0, 'Disco ESCTESTE cheio') === null);

// origens: por padrão só o que nasce no GLPI; webhook e plugins ficam desligados até serem ligados nas configurações
Settings::set(['sources' => 'ticket,problem,change,project,projecttask']);
$ok('origens padrão não incluem evento nem plugin', Settings::sourceOn('ticket') && Settings::sourceOn('manual') && !Settings::sourceOn('event') && !Settings::sourceOn('plugin'));
$ok('webhook desligado devolve 403', EventIntake::handle(['title' => 'ESCTESTE desligado', 'severity' => 5])[0] === 403);
Settings::set(['sources' => 'ticket,problem,change,project,projecttask,event,plugin']);

// abertura, deduplicação, nível 1
[$id, $created] = Escalation::open(['policies_id' => $pid, 'source' => 'manual', 'source_ref' => 'teste', 'dedup_key' => 'esc-teste-1', 'title' => '[Teste ESC] Queda do link', 'severity' => 5, 'actor' => 'system']);
$e = Escalation::get($id);
$ok('abre no nível 1 aguardando ACK', $created && $e['state'] === 'open' && (int) $e['level'] === 1 && $e['next_action_at'] !== null);
[$id2, $created2] = Escalation::open(['policies_id' => $pid, 'source' => 'manual', 'dedup_key' => 'esc-teste-1', 'title' => 'repetido', 'severity' => 5]);
$ok('repetição é agrupada', !$created2 && $id2 === $id && (int) Escalation::get($id)['occurrences'] === 2);
$ok('pop-up pendente para o alvo', count(Dispatcher::pending($uid)) >= 1);

// sobe para o nível 2 quando o prazo acaba
$DB->update(Escalation::TABLE, ['next_action_at' => date('Y-m-d H:i:s', time() - 5)], ['id' => $id]);
Engine::tick();
$ok('sem ACK sobe para o nível 2', (int) Escalation::get($id)['level'] === 2);

// SLO: alerta a 50% e estouro
$DB->update(Escalation::TABLE, ['opened_at' => date('Y-m-d H:i:s', time() - 330), 'ack_due_at' => date('Y-m-d H:i:s', time() + 270)], ['id' => $id]); // 55% do prazo de ACK
Engine::tick();
$ok('alerta de SLO de ACK a 50% do prazo', (int) Escalation::get($id)['ack_warned'] === 1 && (int) Escalation::get($id)['ack_breached'] === 0);
$DB->update(Escalation::TABLE, ['ack_due_at' => date('Y-m-d H:i:s', time() - 30)], ['id' => $id]);
Engine::tick();
$ok('SLO de ACK estourado', (int) Escalation::get($id)['ack_breached'] === 1);

// ACK
Escalation::ack($id, $uid, 'glpi');
$e = Escalation::get($id);
$ok('ACK interrompe a cadeia', $e['state'] === 'acked' && $e['next_action_at'] === null && (int) $e['users_id_ack'] === $uid);
$threw = false;
try {
    Escalation::ack($id, $uid, 'glpi');
} catch (RuntimeException $x) {
    $threw = true;
}
$ok('segundo ACK é recusado', $threw);
$ok('pop-up some depois do ACK', !array_filter(Dispatcher::pending($uid), fn ($p) => $p['esc_id'] === $id && $p['kind'] === 'level'));
Escalation::resolve($id, 'manual', $uid);
$ok('resolvido', Escalation::get($id)['state'] === 'resolved');

// logs rastreáveis
$trace = Escalation::get($id)['trace_id'];
[$chain, $n] = Log::verify($trace);
$ok('cadeia de logs íntegra (' . $n . ' linhas)', $chain && $n >= 8);
$events = array_column(Log::forEscalation($id), 'event');
$ok('logs cobrem abertura, subida, SLO, ACK e resolução', !array_diff(['opened', 'level_start', 'escalate', 'slo_warn', 'slo_breach', 'ack', 'resolved'], $events));
$row = null;
foreach ($DB->request(['FROM' => Log::TABLE, 'WHERE' => ['trace_id' => $trace, 'event' => 'ack']]) as $r) {
    $row = $r;
}
$DB->update(Log::TABLE, ['message' => 'adulterado'], ['id' => $row['id']]);
$ok('adulteração do log é detectada', Log::verify($trace)[0] === false && Log::verify($trace)[2] === (int) $row['seq']);

// SLO agregado
$s = Slo::stats(Policy::get($pid));
$ok('estatísticas de SLO contam o estouro', $s['total'] === 1 && $s['ack_breaches'] === 1 && $s['mtta'] !== null);

// evento por webhook: abre, agrupa, recupera
[$c1, $b1] = EventIntake::handle(['title' => 'ESCTESTE trigger', 'host' => 'srv-teste', 'severity' => 5, 'status' => 'PROBLEM', 'trigger_id' => '999', 'source' => 'zabbix']);
$ok('webhook abre escalonamento (201)', $c1 === 201 && ($b1['action'] ?? '') === 'opened');
[$c2, $b2] = EventIntake::handle(['title' => 'ESCTESTE trigger', 'host' => 'srv-teste', 'severity' => 5, 'status' => 'PROBLEM', 'trigger_id' => '999', 'source' => 'zabbix']);
$ok('webhook agrupa o repetido', ($b2['action'] ?? '') === 'deduplicated');
[$c3, $b3] = EventIntake::handle(['title' => 'ESCTESTE trigger', 'host' => 'srv-teste', 'status' => 'OK', 'trigger_id' => '999', 'source' => 'zabbix']);
$ok('webhook de recuperação resolve', ($b3['action'] ?? '') === 'resolved');
[$c4] = EventIntake::handle(['title' => 'ESCTESTE baixo', 'severity' => 1]);
$ok('evento sem regra não abre (202)', $c4 === 202);
[$c5] = EventIntake::handle(['severity' => 5]);
$ok('evento sem título é recusado (422)', $c5 === 422);

// API para outros plugins: escalar agora, agrupar, resolver, sem regra, acompanhar prazo
Policy::saveRule($pid, ['name' => 'Contratos', 'source' => 'plugin', 'plugin_type' => 'esctest|contrato_vencendo', 'severity_min' => 3, 'sla_pct' => 50, 'is_active' => 1]);
Settings::set(['sources' => 'ticket']);
$ok('Api desligada devolve null mesmo com regra', Api::escalate(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 'off', 'title' => '[Teste ESC] off', 'severity' => 4]) === null && !Api::track(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 'off', 'title' => '[Teste ESC] off', 'due_at' => time() + 60]));
Settings::set(['sources' => 'ticket,problem,change,project,projecttask,event,plugin']);
$r1 = Api::escalate(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 'c1', 'title' => '[Teste ESC] Contrato 1 vence', 'severity' => 4, 'facts' => ['Conta' => 'ACME'], 'url' => '/plugins/esctest/x']);
$ok('Api::escalate abre escalonamento de plugin', $r1 !== null && $r1['created'] && Escalation::get($r1['id'])['source'] === 'plugin');
$r2 = Api::escalate(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 'c1', 'title' => '[Teste ESC] Contrato 1 vence', 'severity' => 4]);
$ok('Api::escalate agrupa a chamada repetida', $r2 !== null && !$r2['created'] && $r2['id'] === $r1['id']);
$ok('tipo do plugin ficou registrado', countElementsInTable(Api::SOURCES, ['plugin_key' => 'esctest', 'type_key' => 'contrato_vencendo']) === 1);
$ok('Api::resolve encerra o escalonamento', Api::resolve('esctest', 'contrato_vencendo', 'c1') && Escalation::get($r1['id'])['state'] === 'resolved');
$ok('sem regra devolve null', Api::escalate(['plugin' => 'esctest', 'type' => 'outro', 'key' => 'x', 'title' => '[Teste ESC] sem regra', 'severity' => 3]) === null);
$ok('Api recusa chamada sem título', Api::escalate(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 'z']) === null);
$ok('track aceita item com prazo', Api::track(['plugin' => 'esctest', 'type' => 'contrato_vencendo', 'key' => 't1', 'title' => '[Teste ESC] Contrato 2', 'severity' => 4, 'start_at' => time() - 100, 'due_at' => time() + 60]));
ItemWatcher::scan();
$tr = null;
foreach ($DB->request(['FROM' => Api::TRACKED, 'WHERE' => ['plugin_key' => 'esctest', 'ref_key' => 't1']]) as $x) {
    $tr = $x;
}
$ok('motor abre o item acompanhado ao consumir 50% do prazo', $tr && $tr['status'] === 'opened' && Escalation::get((int) $tr['escalations_id'])['state'] === 'open');
$ok('Api::resolve fecha o item acompanhado', Api::resolve('esctest', 'contrato_vencendo', 't1') && Escalation::get((int) $tr['escalations_id'])['state'] === 'resolved');

// análise de gaps
$g = Gaps::load(30, $pid);
$ok('análise de gaps calcula o período', $g['total'] >= 3 && isset($g['reached'][1]) && $g['ack_breaches'] >= 1);
$ok('cobertura devolve a lista de buracos', is_array(Gaps::coverage()));

// permissões: perfil, exceção do usuário, somente notificado, escopo e papel no nível (usuário 4 = "tech")
$tech = 4;
$ppid = Policy::save(['name' => '[Teste ESC] perms', 'area' => 'Teste', 'is_active' => 1, 'users_id_owner' => $uid, 'ack_slo_min' => 10, 'resolve_slo_min' => 60, 'slo_target' => 90]);
Policy::saveLevel($ppid, ['name' => 'N1', 'wait_min' => 30, 'channels' => ['popup', 'email'], 'users' => [$uid], 'users_notify' => [$tech]]);
$lv = Policy::level($ppid, 1);
$roles = Engine::recipientRoles(['id' => 0, 'source' => 'manual', 'items_id' => 0], $lv);
$ok('nível guarda o papel: admin escalado, tech só notificado', ($roles[$uid] ?? '') === 'act' && ($roles[$tech] ?? '') === 'notify');
Perms::deleteUser($tech);
[$pe] = Escalation::open(['policies_id' => $ppid, 'source' => 'manual', 'source_ref' => 'perm', 'title' => '[Teste ESC] permissões', 'severity' => 4, 'actor' => 'system']);
$pesc = Escalation::get($pe);
$ok('tech notificado não pode reconhecer (papel notify)', !Perms::can('ack', $pesc, $tech));
$ok('tech notificado não vê o pop-up de ACK, só o informativo', (function () use ($tech, $pe) {
    foreach (Dispatcher::pending($tech) as $p) {
        if ($p['esc_id'] === $pe) {
            return $p['can_ack'] === false && $p['notify_only'] === true;
        }
    }
    return false;
})());
$mail = null;
foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['escalations_id' => $pe, 'users_id' => $tech, 'channel' => 'email']]) as $x) {
    $mail = $x;
}
$ctxN = Message::context($pesc, $lv, 'level');
$ctxN['role'] = 'notify';
$ok('e-mail de quem só é notificado não tem o botão Assumir', $mail !== null && !str_contains(Message::email($ctxN)[1], 'Assumir (ACK)') && str_contains(Message::email($ctxN)[0], '[Informativo]'));
$ctxA = Message::context($pesc, $lv, 'level');
$ok('e-mail do escalado tem o botão Assumir', str_contains(Message::email($ctxA)[1], 'Assumir (ACK)'));
$threw = null;
try {
    Actions::run('ack', $pe, $tech);
} catch (RuntimeException $x) {
    $threw = $x->getCode();
}
$ok('Actions::run recusa o ACK de quem só é notificado (403)', $threw === 403);
Perms::saveUser($tech, ['cap' => ['ack' => 'grant']]);
$ok('permitir ACK por exceção do usuário vale', Perms::can('ack', $pesc, $tech) && !Perms::can('resolve', $pesc, $tech));
Perms::saveUser($tech, ['cap' => ['ack' => 'grant'], 'scope' => [999999]]);
$ok('escopo de hierarquias bloqueia fora do escopo', !Perms::can('ack', $pesc, $tech));
Perms::saveUser($tech, ['cap' => ['ack' => 'grant'], 'scope' => [$ppid]]);
$ok('dentro do escopo volta a valer', Perms::can('ack', $pesc, $tech));
Perms::saveUser($tech, ['cap' => ['ack' => 'deny'], 'scope' => []]);
$ok('negar vence', !Perms::can('ack', $pesc, $tech));
Perms::saveUser($tech, ['cap' => ['ack' => 'grant'], 'notify_only' => true]);
$ok('somente notificado vence a permissão dada', !Perms::can('ack', $pesc, $tech) && Perms::notifyOnly($tech));
$ok('somente notificado aparece como notify ao ser acionado', (Engine::recipientRoles(['id' => 0, 'source' => 'manual', 'items_id' => 0], Policy::level($ppid, 1))[$tech] ?? '') === 'notify');
Perms::deleteUser($tech);
$ok('exceção removida volta ao perfil', !Perms::can('ack', $pesc, $tech) && !Perms::notifyOnly($tech) && Perms::userCfg($tech) === null);
$ok('administrar não pode ser concedido por exceção', (function () use ($tech) {
    Perms::saveUser($tech, ['cap' => ['manage' => 'grant', 'create' => 'grant']]);
    $r = !Perms::can('manage', null, $tech) && Perms::userCfg($tech) === null;
    Perms::deleteUser($tech);
    return $r;
})());
$ok('admin (perfil) administra e reconhece', Perms::can('manage', null, $uid) && Perms::can('ack', $pesc, $uid));
$ok('perfil guarda somente notificado e escopo', (function () use ($tech) {
    $prof = Perms::profileIds($tech)[0] ?? 0;
    if (!$prof) {
        return true; // usuário sem perfil no laboratório
    }
    Perms::saveProfile($prof, ['notify_only' => true, 'scope' => []]);
    $r = Perms::notifyOnly($tech);
    Perms::saveProfile($prof, ['notify_only' => false, 'scope' => []]);
    return $r && !Perms::notifyOnly($tech) && Perms::profileCfg($prof) === null;
})());

// direitos de perfil editados pelo plugin (perfil do usuário tech)
$techProfile = Perms::profileIds($tech)[0] ?? 0;
if ($techProfile) {
    $before = Perms::profileBits($techProfile);
    $ok('perfil ainda não reconhece (base)', !Perms::can('ack', null, $tech) || ($before & (Escalation::ACK | UPDATE)));
    $keys = array_keys(array_filter(Perms::rightBits(), fn ($m) => $before & $m[2]));
    Perms::saveProfileRights($techProfile, array_merge($keys, ['ack', 'resolve']));
    $ok('salvar direitos do perfil pelo plugin vale na hora', Perms::can('ack', null, $tech) && Perms::can('resolve', null, $tech) && !Perms::can('cancel', null, $tech));
    $ok('salvar sem mudança não altera nem registra', Perms::saveProfileRights($techProfile, array_merge($keys, ['ack', 'resolve'])) === false);
    Perms::saveProfileRights($techProfile, array_diff($keys, ['ack', 'resolve']));
    $ok('desmarcar volta aos direitos originais', Perms::profileBits($techProfile) === $before);
    // restaura exatamente o valor original
    $DB->update('glpi_profilerights', ['rights' => $before], ['profiles_id' => $techProfile, 'name' => Escalation::$rightname]);
}
$adminProfile = 0;
foreach ($DB->request(['SELECT' => 'profiles_id', 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Escalation::$rightname]]) as $r) {
    $bitsR = Perms::profileBits((int) $r['profiles_id']);
    if ($bitsR & Escalation::MANAGE) {
        $adminProfile = (int) $r['profiles_id'];
        $adminCount = ($adminCount ?? 0) + 1;
    }
}
if ($adminProfile && ($adminCount ?? 0) === 1) {
    $threw = false;
    try {
        Perms::saveProfileRights($adminProfile, ['view']);
    } catch (RuntimeException $x) {
        $threw = true;
    }
    $ok('não deixa tirar a administração do único perfil administrador', $threw && (Perms::profileBits($adminProfile) & Escalation::MANAGE) > 0);
}
$DB->delete(Log::TABLE, ['message' => ['LIKE', 'Direitos do perfil%']]);

// limpeza
foreach ($DB->request(['SELECT' => ['id', 'trace_id'], 'FROM' => Escalation::TABLE, 'WHERE' => ['policies_id' => $ppid]]) as $r) {
    $DB->delete(Log::TABLE, ['trace_id' => $r['trace_id']]);
    $DB->delete(Escalation::DELIVERIES, ['escalations_id' => $r['id']]);
    $DB->delete(Outbox::TABLE, ['escalations_id' => $r['id']]);
    $DB->delete(Escalation::TABLE, ['id' => $r['id']]);
}
Policy::delete($ppid);
$DB->delete(Log::TABLE, ['message' => ['LIKE', '%Permissões do usuário%']]);
$DB->delete(Log::TABLE, ['message' => ['LIKE', '%Configuração do perfil%']]);
foreach ($DB->request(['SELECT' => ['id', 'trace_id'], 'FROM' => Escalation::TABLE, 'WHERE' => ['policies_id' => $pid]]) as $r) {
    $DB->delete(Log::TABLE, ['trace_id' => $r['trace_id']]);
    $DB->delete(Escalation::DELIVERIES, ['escalations_id' => $r['id']]);
    $DB->delete(Escalation::TABLE, ['id' => $r['id']]);
}
$DB->delete(Log::TABLE, ['message' => ['LIKE', '%ESCTESTE%']]);
$DB->delete(Log::TABLE, ['message' => ['LIKE', '%esctest%']]);
$DB->delete(Api::SOURCES, ['plugin_key' => 'esctest']);
$DB->delete(Api::TRACKED, ['plugin_key' => 'esctest']);
$DB->delete(Slo::SNAPSHOTS, ['policies_id' => $pid]);
Policy::delete($pid);
$restore = [];
foreach (Settings::DEFAULTS as $k => $default) { // chaves que não existiam voltam ao padrão
    if (!in_array($k, Settings::SECRETS, true)) { // segredos já estão criptografados: não mexe
        $restore[$k] = $saved[$k] ?? $default;
    }
}
Config::setConfigurationValues(Settings::CONTEXT, $restore);

echo $fails ? "\n$fails FALHA(S)\n" : "\nTudo certo.\n";
exit($fails ? 1 : 0);
