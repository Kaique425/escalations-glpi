<?php

namespace GlpiPlugin\Escalations;

/** Peças de tela reaproveitadas (tabela principal de escalonamentos, aviso do motor, gavetas). */
class Views
{
    public const COLS = [['Severidade', '120px'], ['Nível', '170px'], ['Prazo de ACK', '160px'], ['Consumo do prazo', '150px'], ['Aberto há', '100px'], ['Assumiu', '80px'], ['Situação', '150px']];

    /** Aviso quando o motor (cron) parece parado: é o que mais prejudica o SLO sem ninguém ver. */
    public static function engineBanner(): string
    {
        $h = Cron::health();
        if ($h['ok']) {
            return '';
        }
        $msg = $h['last'] === ''
            ? 'O motor de escalonamento ainda não rodou. Confira se o cron do GLPI executa a cada minuto (php front/cron.php) e se a ação automática "escalationsrun" está ativa.'
            : 'O motor de escalonamento não roda há ' . Ui::dur((int) $h['age']) . ' (último ciclo em ' . Ui::dt($h['last']) . '). Sem ele nada sobe de nível, e o SLO fica sem monitoramento. Confira o cron do GLPI.';
        return "<div class='alert alert-danger'><div><i class='ti ti-alert-triangle me-1'></i><b>Motor parado.</b> " . Ui::e($msg) . '</div></div>';
    }

    private static function policyName(int $id): string
    {
        static $names = null;
        $names ??= array_column(Policy::all(), 'name', 'id');
        return (string) ($names[$id] ?? ('#' . $id));
    }

    /** Linha da tabela principal e a gaveta do escalonamento. */
    public static function row(array $e): array
    {
        static $levels = [];
        $pid = (int) $e['policies_id'];
        $levels[$pid] ??= Policy::levels($pid);
        $t = Escalation::timing($e);
        $sev = Escalation::severity((int) $e['severity']);
        $st = Escalation::STATES[$e['state']] ?? [$e['state'], '#94a3b8'];
        $active = in_array($e['state'], ['open', 'acked'], true);
        $breached = (bool) ($e['ack_breached'] || $e['resolve_breached']);
        $lvl = (int) $e['level'] ? Ui::levelLabel((int) $e['level'], (string) ($levels[$pid][(int) $e['level']]['name'] ?? '')) : '—';

        if ($e['acked_at']) {
            $ackTxt = 'ACK em ' . Ui::dur((int) $t['time_to_ack']);
        } elseif (!$active) {
            $ackTxt = '—';
        } else {
            $ackTxt = $t['ack_left'] < 0 ? 'estourado há ' . Ui::dur(-$t['ack_left']) : 'restam ' . Ui::dur($t['ack_left']);
        }
        $ackLate = !$e['acked_at'] && $active && $t['ack_left'] !== null && $t['ack_left'] < 0 || (bool) $e['ack_breached'];
        $pct = $e['state'] === 'open' ? $t['ack_pct'] : $t['resolve_pct'];
        $key = 'e' . (int) $e['id'];
        $slo = ($e['ack_breached'] ? 'SLO de ACK estourado. ' : '') . ($e['resolve_breached'] ? 'SLO de resolução estourado. ' : '');

        Drawer::add($key, $e['title'], 'ti-urgent', $sev[1],
            Drawer::lead((string) ($e['summary'] !== '' ? mb_substr((string) $e['summary'], 0, 300) : 'Rastreio ' . $e['trace_id']))
            . Drawer::item('Situação', $st[0] . ' · ' . $sev[0], '', '', $st[1])
            . Drawer::item('Hierarquia', self::policyName($pid), '', '', '#6366f1')
            . Drawer::item('Nível atual', $lvl, (int) $e['repeats'] ? 'repetido ' . (int) $e['repeats'] . '×' : '', '', '#f59e0b')
            . Drawer::item('Aberto', Ui::dt($e['opened_at']) . ' · há ' . Ui::dur($t['age']), '', '', '#64748b')
            . Drawer::item('Prazo de ACK', $ackTxt, $ackLate ? Ui::badge('SLO', '#ef4444') : '', '', $ackLate ? '#ef4444' : '#22c55e')
            . Drawer::item('Prazo de resolução', Ui::dt($e['resolve_due_at']), $e['resolve_breached'] ? Ui::badge('SLO', '#ef4444') : '', '', $e['resolve_breached'] ? '#ef4444' : '#22c55e')
            . ($slo !== '' ? Drawer::lead($slo) : '')
            . Drawer::actions([['Abrir ficha', Escalation::url((int) $e['id']), 'ti-external-link', 'btn-primary'],
                ['Ver logs', Menu::url('/front/logs.php?trace=' . urlencode($e['trace_id'])), 'ti-file-search', 'btn-outline-secondary']]));

        return [
            'name' => $e['title'], 'sub' => $e['trace_id'] . ' · ' . self::policyName($pid) . ((int) $e['occurrences'] > 1 ? ' · ' . (int) $e['occurrences'] . ' ocorrências' : ''),
            'url' => Escalation::url((int) $e['id']), 'late' => $breached && $active, 'drawer' => $key,
            'cells' => [
                [Ui::stCell($sev[0], $sev[1]), 'esc-cell-st'],
                [Ui::e($lvl), 'esc-cell-val'],
                [Ui::e($ackTxt), 'esc-cell-dl' . ($ackLate ? ' late' : '')],
                [Ui::timeline($pct), 'esc-cell-tl'],
                [Ui::e(Ui::dur($t['age'])), 'esc-cell-val'],
                [(int) $e['users_id_ack'] ? Ui::avatar(Ui::userName((int) $e['users_id_ack'])) : Ui::avatar(''), ''],
                [Ui::stCell($st[0], $st[1]), 'esc-cell-st'],
            ],
        ];
    }

    /** Grupos por situação (Aguardando ACK, Reconhecidos, Resolvidos, Cancelados). */
    public static function groups(array $rows): array
    {
        $by = [];
        foreach ($rows as $e) {
            $by[$e['state']][] = self::row($e);
        }
        $groups = [];
        foreach (Escalation::STATES as $k => [$label, $color]) {
            if (!empty($by[$k])) {
                $groups[] = ['title' => $label, 'color' => $color, 'rows' => $by[$k]];
            }
        }
        return $groups;
    }
}
