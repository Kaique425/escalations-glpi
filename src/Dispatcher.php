<?php

namespace GlpiPlugin\Escalations;

use Throwable;

/**
 * Entrega as mensagens: cria um registro por destinatário e canal (tabela deliveries), tenta enviar, repete com espera
 * crescente quando falha e, esgotadas as tentativas, aciona o canal reserva. Tudo fica no log rastreável.
 */
class Dispatcher
{
    /** Espera (minutos) antes da 2ª, 3ª... tentativa. */
    private const BACKOFF = [1, 5, 15, 30];

    /**
     * Registra e tenta enviar. $uid = 0 para o canal da equipe (Teams).
     * $kind: level | slo_warn:ack | slo_breach:ack | slo_warn:resolve | slo_breach:resolve | exhausted.
     */
    public static function deliver(array $e, ?array $level, string $channel, int $uid, int $round = 0, string $kind = 'level', string $role = 'act'): int
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $recipient = match ($channel) {
            'email'    => $uid ? Channels::userEmail($uid) : '',
            'whatsapp' => $uid ? Channels::phone($uid) : '',
            'teams'    => 'Canal do Teams',
            default    => Ui::userName($uid),
        };
        $DB->insert(Escalation::DELIVERIES, [
            'escalations_id' => (int) $e['id'], 'trace_id' => $e['trace_id'], 'level' => (int) ($level['position'] ?? 0), 'round' => $round, 'kind' => $kind, 'role' => $role === 'notify' ? 'notify' : 'act',
            'channel' => $channel, 'users_id' => $uid, 'recipient' => mb_substr($recipient, 0, 255), 'status' => 'queued', 'attempts' => 0,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        if ($channel === 'popup') {
            Log::write($e, 'delivery', ['channel' => 'popup', 'status' => 'ok', 'level' => (int) ($level['position'] ?? 0), 'target_type' => 'user', 'target_id' => $uid,
                'actor' => 'system', 'message' => 'Pop-up registrado para ' . Ui::userName($uid) . ' (aparece quando o GLPI estiver aberto no navegador dele).',
                'detail' => ['delivery' => $id, 'kind' => $kind, 'round' => $round]]);
            return $id;
        }
        self::attempt($id);
        return $id;
    }

    /** Uma tentativa de envio de um registro. Atualiza o registro e grava o log. */
    public static function attempt(int $id): void
    {
        global $DB;
        $d = null;
        foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['id' => $id]]) as $r) {
            $d = $r;
        }
        $e = $d ? Escalation::get((int) $d['escalations_id']) : null;
        if (!$d || !$e) {
            return;
        }
        $level = (int) $d['level'] ? Policy::level((int) $e['policies_id'], (int) $d['level']) : null;
        $attempt = (int) $d['attempts'] + 1;
        $max = max(1, Settings::int('max_attempts') ?: 3);

        $noRetry = false;
        $copy = [];
        if (!Channels::enabled($d['channel'])) {
            $res = ['ok' => false, 'provider_id' => '', 'error' => 'Canal desligado ou não configurado.', 'ms' => 0, 'detail' => []];
            $noRetry = true; // não adianta tentar de novo
        } else {
            try {
                $c = Message::context($e, $level, (string) $d['kind']);
                $c['role'] = (string) ($d['role'] ?? 'act');
                $copy = Outbox::copyOf((string) $d['channel'], $c);
                if (Channels::sinked((string) $d['channel']) && ($d['channel'] === 'teams' || (string) $d['recipient'] !== '')) {
                    // simulação: não envia; a cópia fica na Caixa de saída
                    $res = ['ok' => true, 'provider_id' => 'simulado', 'error' => '', 'ms' => 0, 'detail' => ['simulado' => true]];
                } else {
                    $res = match ($d['channel']) {
                        'email'    => Channels::mail($c, ['id' => (int) $d['users_id'], 'email' => (string) $d['recipient'], 'name' => Ui::userName((int) $d['users_id'])]),
                        'teams'    => Channels::teams($c),
                        'whatsapp' => Channels::whatsapp($c, (string) $d['recipient']),
                        default    => ['ok' => false, 'provider_id' => '', 'error' => 'Canal desconhecido.', 'ms' => 0, 'detail' => []],
                    };
                }
            } catch (Throwable $t) {
                $res = ['ok' => false, 'provider_id' => '', 'error' => $t->getMessage(), 'ms' => 0, 'detail' => []];
            }
        }

        $now = date('Y-m-d H:i:s');
        $upd = ['attempts' => $attempt, 'date_mod' => $now, 'provider_id' => mb_substr((string) $res['provider_id'], 0, 190)];
        if ($res['ok']) {
            $upd += ['status' => 'sent', 'sent_at' => $now, 'error' => null, 'next_try' => null];
        } else {
            $retry = !$noRetry && $attempt < $max;
            $upd += ['status' => 'failed', 'error' => mb_substr((string) $res['error'], 0, 500),
                'next_try' => $retry ? date('Y-m-d H:i:s', time() + self::BACKOFF[min($attempt - 1, count(self::BACKOFF) - 1)] * 60) : null];
        }
        $DB->update(Escalation::DELIVERIES, $upd, ['id' => $id]);
        Outbox::record($e, $d, $copy, $res['ok'] ? (Channels::sinked((string) $d['channel']) ? 'sim' : 'sent') : 'failed', $res['ok'] ? '' : (string) $res['error']);

        $label = Policy::CHANNELS[$d['channel']][0] ?? $d['channel'];
        $who = (int) $d['users_id'] ? Ui::userName((int) $d['users_id']) : 'canal da equipe';
        Log::write($e, $attempt > 1 ? 'retry' : 'delivery', [
            'channel' => $d['channel'], 'level' => (int) $d['level'], 'target_type' => (int) $d['users_id'] ? 'user' : 'channel', 'target_id' => (int) $d['users_id'],
            'status' => $res['ok'] ? 'ok' : 'fail', 'actor' => 'system', 'duration_ms' => (int) $res['ms'],
            'message' => $res['ok'] ? $label . ' enviado para ' . $who . ' (tentativa ' . $attempt . ').' : $label . ' falhou para ' . $who . ' (tentativa ' . $attempt . '): ' . $res['error'],
            'detail' => ['delivery' => $id, 'kind' => $d['kind'], 'round' => (int) $d['round'], 'attempt' => $attempt, 'provider_id' => $res['provider_id']] + (array) $res['detail'],
        ]);
        if (!$res['ok'] && $upd['next_try'] === null) {
            self::fallback($e, $d, $level);
        }
    }

    /** Falha definitiva: aciona o canal reserva (uma vez por destinatário e nível). */
    private static function fallback(array $e, array $d, ?array $level): void
    {
        $fb = Settings::get('fallback_channel');
        if ($fb === '' || $fb === 'none' || $fb === $d['channel'] || !(int) $d['users_id'] || !Channels::enabled($fb)) {
            return;
        }
        if (countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => $d['escalations_id'], 'users_id' => $d['users_id'], 'level' => $d['level'], 'round' => $d['round'], 'kind' => $d['kind'], 'channel' => $fb])) {
            return;
        }
        Log::write($e, 'fallback', ['channel' => $fb, 'level' => (int) $d['level'], 'status' => 'warn', 'target_type' => 'user', 'target_id' => (int) $d['users_id'], 'actor' => 'system',
            'message' => (Policy::CHANNELS[$d['channel']][0] ?? $d['channel']) . ' falhou em definitivo para ' . Ui::userName((int) $d['users_id']) . '; acionando o canal reserva (' . (Policy::CHANNELS[$fb][0] ?? $fb) . ').']);
        self::deliver($e, $level, $fb, (int) $d['users_id'], (int) $d['round'], (string) $d['kind'], (string) ($d['role'] ?? 'act'));
    }

    /** Reenvia o que falhou e ainda tem tentativas. */
    public static function retries(): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Escalation::DELIVERIES, 'WHERE' => ['status' => 'failed', ['NOT' => ['next_try' => null]], 'next_try' => ['<=', date('Y-m-d H:i:s')]], 'LIMIT' => 50]) as $r) {
            self::attempt((int) $r['id']);
            $n++;
        }
        return $n;
    }

    /** Avisa a quem cuida da hierarquia (dono): SLO em risco/estourado, cadeia esgotada. */
    public static function notifyOwner(array $e, string $kind): void
    {
        $policy = Policy::get((int) $e['policies_id']);
        $owner = (int) ($policy['users_id_owner'] ?? 0);
        if (!$owner) {
            Log::write($e, 'no_recipient', ['status' => 'warn', 'message' => 'A hierarquia não tem responsável (dono) para receber o aviso de ' . $kind . '. Defina em Hierarquias.']);
            return;
        }
        Bridge::notify($e, [$owner], ($kind === 'exhausted' ? 'Cadeia esgotada: ' : 'SLO: ') . $e['title'], 'Aviso ao responsável pela hierarquia (' . $kind . ').', 'danger');
        foreach (Settings::channels(Settings::get('owner_channels')) as $ch) {
            self::deliver($e, null, $ch, $ch === 'teams' ? 0 : $owner, 0, $kind);
        }
    }

    /** Aviso solto no Teams (ACK, resolvido). Não gera pendência. */
    public static function announce(?array $e, string $text): void
    {
        if (!$e || !Channels::enabled('teams')) {
            return;
        }
        if (Channels::sinked('teams')) { // simulação: guarda o aviso na Caixa de saída
            Outbox::record($e, ['id' => 0, 'level' => 0, 'channel' => 'teams', 'users_id' => 0, 'recipient' => 'Canal do Teams'], ['Aviso no canal do Teams', '', '[' . $e['trace_id'] . '] ' . $e['title'] . ' — ' . $text], 'sim');
            Log::write($e, 'delivery', ['channel' => 'teams', 'status' => 'ok', 'actor' => 'system', 'message' => 'Aviso no Teams (simulado): ' . $text]);
            return;
        }
        $r = Channels::teamsText('[' . $e['trace_id'] . '] ' . $e['title'] . ' — ' . $text);
        Log::write($e, 'delivery', ['channel' => 'teams', 'status' => $r['ok'] ? 'ok' : 'fail', 'duration_ms' => (int) $r['ms'], 'actor' => 'system',
            'message' => $r['ok'] ? 'Aviso no Teams: ' . $text : 'Aviso no Teams falhou: ' . $r['error'], 'detail' => $r['detail']]);
    }

    // ------------------------------------------------------------------ pop-up

    /**
     * Pop-ups pendentes do usuário (o navegador consulta de tempos em tempos). Na primeira vez que aparecem, o log registra
     * "Pop-up exibido" com o atraso desde o envio. Pop-up de ACK some quando alguém assume; os de SLO ficam até "Ciente".
     */
    public static function pending(int $uid, int $limit = 5): array
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($DB->request(['FROM' => Escalation::DELIVERIES, 'WHERE' => ['users_id' => $uid, 'channel' => 'popup', 'status' => ['queued', 'shown']], 'ORDER' => ['id'], 'LIMIT' => 40]) as $d) {
            if ($d['snooze_until'] && $d['snooze_until'] > $now) {
                continue;
            }
            $e = Escalation::get((int) $d['escalations_id']);
            if (!$e) {
                continue;
            }
            $alive = $d['kind'] === 'level' ? $e['state'] === 'open' : in_array($e['state'], ['open', 'acked'], true);
            if (!$alive) {
                $DB->update(Escalation::DELIVERIES, ['status' => 'acked', 'date_mod' => $now], ['id' => $d['id']]);
                continue;
            }
            if ($d['status'] === 'queued') {
                $DB->update(Escalation::DELIVERIES, ['status' => 'shown', 'shown_at' => $now, 'date_mod' => $now], ['id' => $d['id']]);
                $lag = time() - strtotime((string) $d['date_creation']);
                Log::write($e, 'popup_shown', ['channel' => 'popup', 'status' => 'ok', 'level' => (int) $d['level'], 'users_id' => $uid, 'target_type' => 'user', 'target_id' => $uid, 'actor' => 'user',
                    'message' => 'Pop-up exibido para ' . Ui::userName($uid) . ' após ' . Ui::dur($lag) . ' na fila.', 'detail' => ['delivery' => (int) $d['id'], 'lag_s' => $lag]]);
            }
            $out[] = Message::popup($e, $d);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** "Ciente" num pop-up de aviso (SLO, cadeia esgotada): encerra a entrega. */
    public static function dismiss(int $deliveryId, int $uid): void
    {
        global $DB;
        $DB->update(Escalation::DELIVERIES, ['status' => 'acked', 'date_mod' => date('Y-m-d H:i:s')], ['id' => $deliveryId, 'users_id' => $uid, 'channel' => 'popup']);
    }
}
