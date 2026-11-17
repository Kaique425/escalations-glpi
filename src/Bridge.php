<?php

namespace GlpiPlugin\Escalations;

use Plugin;
use Throwable;

/**
 * Ponte opcional com o plugin "Avisos" (sino do GLPI): cada acionamento também vira um aviso no sino do usuário.
 * Desligada por padrão (Configurações → Motor): o pop-up com ACK do Escalations continua sendo o canal principal, e o
 * Avisos serve de histórico no sino. O Teams do Avisos fica de fora (o Escalations tem o próprio).
 */
class Bridge
{
    public static function available(): bool
    {
        return Settings::bool('avisos_bridge') && Plugin::isPluginActive('avisos') && class_exists(\GlpiPlugin\Avisos\Aviso::class);
    }

    public static function notify(array $e, array $users, string $title, string $message, string $level = 'warning'): void
    {
        if (!self::available()) {
            return;
        }
        $users = array_values(array_unique(array_filter(array_map('intval', $users))));
        if (!$users) {
            return;
        }
        try {
            \GlpiPlugin\Avisos\Aviso::send([
                'source' => 'escalations', 'event' => 'escalonamento', 'users' => $users, 'title' => $title, 'message' => $message, 'level' => $level,
                'url' => Escalation::url((int) $e['id']), 'facts' => ['Rastreio' => (string) $e['trace_id'], 'Severidade' => Escalation::severity((int) $e['severity'])[0]],
                'item' => [Escalation::class, (int) $e['id']], 'include_self' => true, 'teams' => false,
            ]);
        } catch (Throwable $t) {
            Log::file('Bridge::notify: ' . $t->getMessage());
        }
    }

    public static function levelLevel(int $severity): string
    {
        return $severity >= 5 ? 'danger' : ($severity >= 3 ? 'warning' : 'info');
    }
}
