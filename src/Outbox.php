<?php

namespace GlpiPlugin\Escalations;

/**
 * Caixa de saída: guarda a cópia exata de cada mensagem enviada (e-mail em HTML, cartão do Teams, texto do WhatsApp),
 * para conferir o que o destinatário recebeu e para auditoria. No modo simulação (Configurações) nada sai de verdade:
 * a mensagem só é gravada aqui, o que serve para testar em ambiente sem SMTP.
 */
class Outbox
{
    public const TABLE = 'glpi_plugin_escalations_outbox';

    /** Cópia da mensagem do canal: [assunto, html, texto]. */
    public static function copyOf(string $channel, array $c): array
    {
        return match ($channel) {
            'email'    => Message::email($c),
            'teams'    => ['Cartão do Teams', '', Message::plain($c)],
            'whatsapp' => ['', '', Message::whatsapp($c)],
            default    => ['', '', ''],
        };
    }

    public static function record(array $e, array $d, array $copy, string $status, string $error = ''): void
    {
        global $DB;
        if (!Settings::bool('outbox') || $copy === []) {
            return;
        }
        try {
            $DB->insert(self::TABLE, [
                'escalations_id' => (int) $e['id'], 'deliveries_id' => (int) $d['id'], 'trace_id' => (string) $e['trace_id'], 'level' => (int) $d['level'], 'channel' => (string) $d['channel'],
                'users_id' => (int) $d['users_id'], 'recipient' => mb_substr((string) $d['recipient'], 0, 255), 'subject' => mb_substr((string) $copy[0], 0, 255),
                'body_html' => mb_substr((string) $copy[1], 0, 200000), 'body_text' => mb_substr((string) $copy[2], 0, 20000), 'status' => $status, 'error' => $error !== '' ? mb_substr($error, 0, 500) : null,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Log::file('Outbox::record: ' . $t->getMessage());
        }
    }

    /** Lista com filtros. Devolve [linhas, total]. Respeita a visibilidade (quem não vê tudo só vê os seus). */
    public static function search(array $f, int $page, int $per): array
    {
        global $DB;
        $where = [];
        if (!Escalation::canAll()) {
            $where['users_id'] = (int) \Session::getLoginUserID();
        }
        foreach (['channel', 'status'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $where[$k] = $f[$k];
            }
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = ['OR' => ['trace_id' => ['LIKE', '%' . $f['q'] . '%'], 'subject' => ['LIKE', '%' . $f['q'] . '%'], 'recipient' => ['LIKE', '%' . $f['q'] . '%']]];
        }
        $total = countElementsInTable(self::TABLE, $where);
        $crit = ['FROM' => self::TABLE, 'ORDER' => ['id DESC'], 'START' => max(0, ($page - 1) * $per), 'LIMIT' => $per];
        if ($where) {
            $crit['WHERE'] = $where;
        }
        return [iterator_to_array($DB->request($crit), false), $total];
    }
}
