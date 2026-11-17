<?php

namespace GlpiPlugin\Escalations;

use CronTask;
use Throwable;

/**
 * Ações automáticas (Configurar → Ações automáticas):
 *  - escalationsrun (a cada minuto): sobe níveis, confere SLO, reenvia falhas, observa chamados;
 *  - escalationsdaily (diária): grava o histórico de SLO e apaga logs de escalonamentos antigos já encerrados.
 * Para o escalonamento ser confiável, o cron do GLPI precisa rodar a cada minuto pelo sistema (cron.php via CLI).
 */
class Cron
{
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'escalationsdaily' => ['description' => 'Escalations: histórico de SLO e limpeza de logs antigos'],
            default            => ['description' => 'Escalations: sobe níveis, confere SLO, reenvia falhas e observa chamados'],
        };
    }

    public static function cronEscalationsrun(CronTask $task): int
    {
        $n = Engine::tick();
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }

    public static function cronEscalationsdaily(CronTask $task): int
    {
        $n = Slo::snapshot();
        $n += self::purge();
        $task->addVolume($n);
        return $n > 0 ? 1 : 0;
    }

    /** Apaga escalonamentos encerrados há mais de N dias (padrão 365), com logs e entregas. */
    public static function purge(): int
    {
        global $DB;
        $days = max(30, Settings::int('log_retention_days') ?: 365);
        $cut = date('Y-m-d H:i:s', time() - $days * 86400);
        $n = 0;
        foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['state' => ['resolved', 'cancelled'], 'resolved_at' => ['<', $cut]], 'LIMIT' => 500]) as $e) {
            try {
                $DB->delete(Log::TABLE, ['trace_id' => $e['trace_id']]);
                $DB->delete(Escalation::DELIVERIES, ['escalations_id' => $e['id']]);
                $DB->delete(Outbox::TABLE, ['escalations_id' => $e['id']]);
                $DB->delete(Escalation::TABLE, ['id' => $e['id']]);
                $n++;
            } catch (Throwable $t) {
                Log::file('purge ' . $e['trace_id'] . ': ' . $t->getMessage());
            }
        }
        // itens acompanhados já concluídos há mais de 30 dias
        $DB->delete(Api::TRACKED, ['status' => 'done', 'date_mod' => ['<', date('Y-m-d H:i:s', time() - 30 * 86400)]]);
        // logs do sistema (testes, eventos sem regra) com mais de 90 dias
        $DB->delete(Log::TABLE, ['escalations_id' => 0, 'date_creation' => ['<', date('Y-m-d H:i:s', time() - 90 * 86400)]]);
        return $n;
    }

    /** Situação do motor para as telas: último ciclo e se parece parado. */
    public static function health(): array
    {
        $last = Settings::get('last_tick');
        $age = $last !== '' ? time() - strtotime($last) : null;
        return ['last' => $last, 'age' => $age, 'ok' => $age !== null && $age <= 180, 'ms' => (int) Settings::get('last_tick_ms')];
    }
}
