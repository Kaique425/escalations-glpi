<?php

namespace GlpiPlugin\Escalations;

/**
 * Análise de gaps: onde a escalada perde tempo, quem não atende, que canal não entrega, que evento só faz ruído, por que o
 * SLO estoura e que buracos de cobertura existem na configuração (nível sem usuário, usuário sem e-mail/celular, regra que
 * nunca dispara, chamado crítico sem regra...). Tudo calculado dos dados que o plugin já grava.
 */
class Gaps
{
    public const WEEKDAYS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    /** Dados do período. $policy = 0 para todas as hierarquias. */
    public static function load(int $days, int $policy = 0): array
    {
        global $DB;
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $where = ['opened_at' => ['>=', $since], ['NOT' => ['state' => 'cancelled']]];
        if ($policy) {
            $where['policies_id'] = $policy;
        }
        $d = ['days' => $days, 'total' => 0, 'reached' => [], 'acked_at' => [], 'ack_sum' => [], 'ack_n' => [], 'exhausted' => 0, 'no_ack_resolved' => 0, 'heat' => [], 'people' => [],
            'channels' => [], 'noise' => [], 'ignored' => [], 'causes' => [], 'escalate_wait' => [], 'ack_breaches' => 0, 'rows' => []];
        $ids = [];
        $byPolicy = [];
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => $where, 'ORDER' => ['opened_at'], 'LIMIT' => 20000]) as $e) {
            $d['total']++;
            $ids[] = (int) $e['id'];
            $d['rows'][(int) $e['id']] = $e;
            $byPolicy[(int) $e['policies_id']] = true;
            $level = max(1, (int) $e['level']);
            for ($k = 1; $k <= $level; $k++) {
                $d['reached'][$k] = ($d['reached'][$k] ?? 0) + 1;
            }
            if ($e['acked_at']) {
                $d['acked_at'][$level] = ($d['acked_at'][$level] ?? 0) + 1;
                $d['ack_sum'][$level] = ($d['ack_sum'][$level] ?? 0) + strtotime($e['acked_at']) - strtotime($e['opened_at']);
                $d['ack_n'][$level] = ($d['ack_n'][$level] ?? 0) + 1;
            } elseif ($e['state'] === 'resolved') {
                $d['no_ack_resolved']++;
                $key = preg_replace('/\d+/', '#', (string) $e['title']);
                $d['ignored'][$key] = ($d['ignored'][$key] ?? 0) + 1;
            }
            $t = strtotime($e['opened_at']);
            $cell = &$d['heat'][(int) date('N', $t)][(int) date('G', $t)];
            $cell = $cell ?? [0, 0];
            $cell[0]++;
            $cell[1] += (int) $e['ack_breached'];
            unset($cell);
            $d['ack_breaches'] += (int) $e['ack_breached'];
            if ((int) $e['occurrences'] > 1) {
                $d['noise'][(int) $e['id']] = (int) $e['occurrences'];
            }
        }
        arsort($d['noise']);
        arsort($d['ignored']);
        $d['noise'] = array_slice($d['noise'], 0, 8, true);
        $d['ignored'] = array_slice($d['ignored'], 0, 8, true);
        ksort($d['reached']);

        // logs: cadeia esgotada, tempo de espera antes de subir, adiamentos, falhas, sem destinatário
        $noRecipient = $failed = [];
        $snoozes = [];
        $fallbacks = 0;
        foreach (array_chunk($ids, 800) as $chunk) {
            foreach ($DB->request(['SELECT' => ['escalations_id', 'event', 'status', 'users_id', 'detail'], 'FROM' => Log::TABLE,
                'WHERE' => ['escalations_id' => $chunk, 'event' => ['exhausted', 'escalate', 'snooze', 'delivery', 'retry', 'fallback', 'no_recipient']]]) as $l) {
                switch ($l['event']) {
                    case 'exhausted':
                        $d['exhausted']++;
                        break;
                    case 'escalate':
                        $det = json_decode((string) $l['detail'], true) ?: [];
                        if (isset($det['from'], $det['waited_s'])) {
                            $d['escalate_wait'][(int) $det['from']][] = (int) $det['waited_s'];
                        }
                        break;
                    case 'snooze':
                        $snoozes[(int) $l['users_id']] = ($snoozes[(int) $l['users_id']] ?? 0) + 1;
                        break;
                    case 'no_recipient':
                        $noRecipient[(int) $l['escalations_id']] = true;
                        break;
                    case 'fallback':
                        $fallbacks++;
                        break;
                    default:
                        if ($l['status'] === 'fail') {
                            $failed[(int) $l['escalations_id']] = true;
                        }
                }
            }
            // pessoas e canais
            foreach ($DB->request(['SELECT' => ['escalations_id', 'users_id', 'channel', 'status', 'error', 'shown_at', 'date_creation', 'kind'], 'FROM' => Escalation::DELIVERIES, 'WHERE' => ['escalations_id' => $chunk]]) as $x) {
                $c = &$d['channels'][$x['channel']];
                $c = $c ?? ['total' => 0, 'failed' => 0, 'lag_sum' => 0, 'lag_n' => 0, 'errors' => []];
                $c['total']++;
                if ($x['status'] === 'failed') {
                    $c['failed']++;
                    $err = mb_substr((string) $x['error'], 0, 90);
                    $c['errors'][$err] = ($c['errors'][$err] ?? 0) + 1;
                }
                if ($x['channel'] === 'popup' && $x['shown_at']) {
                    $c['lag_sum'] += strtotime($x['shown_at']) - strtotime($x['date_creation']);
                    $c['lag_n']++;
                }
                unset($c);
                if ($x['kind'] === 'level' && (int) $x['users_id']) {
                    $p = &$d['people'][(int) $x['users_id']];
                    $p = $p ?? ['notified' => [], 'acked' => 0];
                    $p['notified'][(int) $x['escalations_id']] = true;
                    unset($p);
                }
            }
        }
        $d['fallbacks'] = $fallbacks;
        foreach ($d['people'] as $uid => &$p) {
            $p['n'] = count($p['notified']);
            $p['acked'] = 0;
            foreach ($p['notified'] as $eid => $_) {
                if ((int) ($d['rows'][$eid]['users_id_ack'] ?? 0) === $uid) {
                    $p['acked']++;
                }
            }
            $p['snoozes'] = $snoozes[$uid] ?? 0;
            $p['rate'] = $p['n'] ? $p['acked'] / $p['n'] * 100 : 0;
            unset($p['notified']);
        }
        unset($p);

        // causas dos estouros de SLO de ACK
        $waits = [];
        foreach (array_keys($byPolicy) as $pid) {
            $first = Policy::level($pid, 1);
            $waits[$pid] = [(int) ($first['wait_min'] ?? 0), (int) (Policy::get($pid)['ack_slo_min'] ?? 0)];
        }
        foreach ($d['rows'] as $id => $e) {
            if (!(int) $e['ack_breached']) {
                continue;
            }
            [$wait, $slo] = $waits[(int) $e['policies_id']] ?? [0, 0];
            $cause = isset($noRecipient[$id]) ? 'Sem destinatário no nível'
                : (isset($failed[$id]) ? 'Canal de envio falhou'
                : ($wait > 0 && $slo > 0 && $wait >= $slo ? 'Espera do N1 maior que o SLO de ACK (configuração)' : 'Ninguém assumiu a tempo'));
            $d['causes'][$cause] = ($d['causes'][$cause] ?? 0) + 1;
        }
        arsort($d['causes']);
        return $d;
    }

    /** Buracos de cobertura da configuração, hoje. Cada item: [gravidade (danger|warning|info), texto, link]. */
    public static function coverage(int $days = 30): array
    {
        global $DB;
        $out = [];
        $dummy = ['id' => 0, 'source' => 'manual', 'items_id' => 0];
        $link = fn (int $id) => Menu::url('/front/hierarquia.php?id=' . $id);
        foreach (Policy::all(true) as $p) {
            $pid = (int) $p['id'];
            $n = $p['name'];
            $levels = Policy::levels($pid);
            if (!$levels) {
                $out[] = ['danger', "“{$n}” não tem nenhum nível: nada é acionado.", $link($pid)];
                continue;
            }
            if (!(int) $p['users_id_owner']) {
                $out[] = ['warning', "“{$n}” não tem dono: os avisos de SLO e de cadeia esgotada não chegam a ninguém.", $link($pid)];
            }
            if (!Policy::rules($pid)) {
                $out[] = ['warning', "“{$n}” não tem regra: só o escalonamento manual a usa.", $link($pid)];
            }
            $first = $levels[array_key_first($levels)];
            if ((int) $first['wait_min'] >= (int) $p['ack_slo_min']) {
                $out[] = ['warning', "“{$n}”: o N1 espera {$first['wait_min']} min para subir, mas o SLO de ACK é {$p['ack_slo_min']} min. O SLO estoura antes de o N2 ser avisado.", $link($pid)];
            }
            foreach ($levels as $pos => $l) {
                $roles = Engine::recipientRoles($dummy, $l);
                $users = array_keys($roles);
                if ($roles && !in_array('act', $roles, true)) {
                    $out[] = ['warning', "“{$n}” · N{$pos} {$l['name']}: só tem pessoas “somente notificadas” (ou sem permissão de agir). Ninguém deste nível pode assumir o escalonamento.", $link($pid)];
                }
                $hasSupervisorOnly = !array_diff(array_column($l['targets'], 'type'), ['supervisor']);
                if (!$users && !$hasSupervisorOnly) {
                    $out[] = ['danger', "“{$n}” · N{$pos} {$l['name']}: nenhum usuário ativo nos alvos. O nível é pulado sem avisar ninguém.", $link($pid)];
                }
                foreach ($l['channel_list'] as $ch) {
                    if (!Channels::enabled($ch)) {
                        $out[] = ['warning', "“{$n}” · N{$pos}: usa " . Policy::CHANNELS[$ch][0] . ', mas o canal está desligado ou sem configuração.', Menu::url('/front/config.php')];
                    }
                }
                $noMail = $noPhone = [];
                foreach ($users as $uid) {
                    if (in_array('email', $l['channel_list'], true) && Channels::userEmail($uid) === '') {
                        $noMail[] = Ui::userName($uid);
                    }
                    if (in_array('whatsapp', $l['channel_list'], true) && Channels::phone($uid) === '') {
                        $noPhone[] = Ui::userName($uid);
                    }
                }
                if ($noMail) {
                    $out[] = ['warning', "“{$n}” · N{$pos}: sem e-mail cadastrado: " . implode(', ', array_slice($noMail, 0, 6)) . (count($noMail) > 6 ? '…' : '') . '.', $link($pid)];
                }
                if ($noPhone) {
                    $out[] = ['warning', "“{$n}” · N{$pos}: sem celular cadastrado (WhatsApp): " . implode(', ', array_slice($noPhone, 0, 6)) . (count($noPhone) > 6 ? '…' : '') . '.', $link($pid)];
                }
            }
            // regras que nunca dispararam no período
            foreach (Policy::rules($pid) as $r) {
                if ((int) $r['is_active'] && !countElementsInTable(Escalation::TABLE, ['rules_id' => $r['id'], 'opened_at' => ['>=', date('Y-m-d H:i:s', time() - $days * 86400)]])) {
                    $out[] = ['info', "Regra “{$r['name']}” de “{$n}” não disparou nos últimos {$days} dias (confira se o critério não está restrito demais).", $link($pid)];
                }
            }
        }
        // chamados críticos abertos sem nenhuma regra que os cubra
        $uncovered = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'priority', 'entities_id', 'itilcategories_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0, 'status' => [1, 2, 3, 4], 'priority' => ['>=', 4]], 'ORDER' => ['priority DESC'], 'LIMIT' => 300]) as $t) {
            if (!Policy::match('ticket', TicketWatcher::severityOf((int) $t['priority']), (int) $t['entities_id'], (int) $t['itilcategories_id'], (string) $t['name'])) {
                $uncovered[] = '#' . $t['id'];
            }
        }
        if ($uncovered) {
            $out[] = ['danger', count($uncovered) . ' chamado(s) aberto(s) de prioridade alta ou crítica não casam com nenhuma regra: ninguém seria escalado (' . implode(', ', array_slice($uncovered, 0, 8)) . (count($uncovered) > 8 ? '…' : '') . ').', Menu::url('/front/hierarquias.php')];
        }
        // itens de plugins acompanhados sem regra
        $orphans = 0;
        foreach ($DB->request(['FROM' => Api::TRACKED, 'WHERE' => ['status' => 'active'], 'LIMIT' => 500]) as $t) {
            if (!Policy::match('plugin', (int) $t['severity'], (int) $t['entities_id'], 0, (string) $t['title'], $t['plugin_key'], $t['type_key'])) {
                $orphans++;
            }
        }
        if ($orphans) {
            $out[] = ['warning', $orphans . ' item(ns) de plugins acompanhado(s) sem regra para escalar.', Menu::url('/front/hierarquias.php')];
        }
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $order[$a[0]] <=> $order[$b[0]]);
        return $out;
    }
}
