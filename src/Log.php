<?php

namespace GlpiPlugin\Escalations;

use Throwable;
use Toolbox;

/**
 * Logs rastreáveis. Cada escalonamento tem um código de rastreio (trace_id, ex.: ESC-261117-A3F9C) que aparece
 * no e-mail, no Teams, no WhatsApp e no pop-up. Toda ação (abertura, envio, falha, ACK, subida, SLO, resolução)
 * vira uma linha numerada (seq) por rastreio. As linhas são encadeadas por hash (prev_hash → hash): se alguém
 * apagar ou editar uma linha no banco, Log::verify() acusa em qual ponto a cadeia quebrou.
 * Segredos nunca entram no log (Log::clean).
 */
class Log
{
    public const TABLE = 'glpi_plugin_escalations_logs';

    /** Eventos conhecidos e rótulos. */
    public const EVENTS = [
        'opened'       => 'Aberto',
        'dedup'        => 'Repetição agrupada',
        'level_start'  => 'Nível iniciado',
        'delivery'     => 'Envio',
        'popup_shown'  => 'Pop-up exibido',
        'retry'        => 'Nova tentativa',
        'fallback'     => 'Canal reserva',
        'escalate'     => 'Subiu de nível',
        'repeat'       => 'Repetiu o nível',
        'exhausted'    => 'Cadeia esgotada',
        'ack'          => 'ACK',
        'snooze'       => 'Adiado',
        'silence'      => 'Silenciado',
        'resolved'     => 'Resolvido',
        'cancelled'    => 'Cancelado',
        'slo_warn'     => 'SLO em risco',
        'slo_breach'   => 'SLO estourado',
        'no_recipient' => 'Sem destinatário',
        'inbound'      => 'Resposta recebida',
        'webhook'      => 'Evento recebido',
        'test'         => 'Teste de canal',
        'system'       => 'Sistema',
    ];

    public const STATUS = ['ok' => ['OK', '#22c55e'], 'fail' => ['Falha', '#ef4444'], 'warn' => ['Atenção', '#f59e0b'], 'info' => ['Info', '#64748b']];

    /**
     * Grava uma linha. $esc = linha do escalonamento (ou null para eventos do sistema).
     * Opções: level, channel, status (ok|fail|warn|info), message, detail (array), users_id, actor, target_type, target_id, duration_ms.
     */
    public static function write(?array $esc, string $event, array $o = []): int
    {
        global $DB;
        $trace = (string) ($esc['trace_id'] ?? 'SYS-' . date('Ymd'));
        $eid = (int) ($esc['id'] ?? 0);
        $detail = isset($o['detail']) ? json_encode(self::clean($o['detail']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
        $uid = (int) ($o['users_id'] ?? 0);
        if (!$uid && isset($_SESSION['glpiID'])) {
            $uid = (int) $_SESSION['glpiID'];
        }
        $row = [
            'trace_id'         => $trace,
            'escalations_id'   => $eid,
            'event'            => $event,
            'level'            => (int) ($o['level'] ?? 0),
            'channel'          => (string) ($o['channel'] ?? ''),
            'target_type'      => (string) ($o['target_type'] ?? ''),
            'target_id'        => (int) ($o['target_id'] ?? 0),
            'status'           => (string) ($o['status'] ?? 'info'),
            'message'          => mb_substr((string) ($o['message'] ?? ''), 0, 500),
            'detail'           => $detail,
            'users_id'         => $uid,
            'actor'            => (string) ($o['actor'] ?? ($uid ? 'user' : 'system')),
            'ip'               => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'duration_ms'      => (int) ($o['duration_ms'] ?? 0),
        ];
        // duas tentativas: a chave única (trace_id, seq) protege a cadeia contra gravações simultâneas
        for ($try = 0; $try < 2; $try++) {
            try {
                $prev = '';
                $seq = 1;
                foreach ($DB->request(['SELECT' => ['seq', 'hash'], 'FROM' => self::TABLE, 'WHERE' => ['trace_id' => $trace], 'ORDER' => 'seq DESC', 'LIMIT' => 1]) as $last) {
                    $prev = (string) $last['hash'];
                    $seq = (int) $last['seq'] + 1;
                }
                $now = microtime(true);
                $row['date_creation'] = date('Y-m-d H:i:s', (int) $now) . '.' . str_pad((string) (int) (($now - floor($now)) * 1000), 3, '0', STR_PAD_LEFT);
                $row['seq'] = $seq;
                $row['prev_hash'] = $prev;
                $row['hash'] = self::hash($row);
                $DB->insert(self::TABLE, $row);
                return (int) $DB->insertId();
            } catch (Throwable $e) {
                if ($try === 1) {
                    Toolbox::logInFile('escalations', 'Log não gravado (' . $event . ' ' . $trace . '): ' . $e->getMessage() . "\n");
                }
            }
        }
        return 0;
    }

    /** Hash da linha: encadeia a linha anterior e todos os campos que importam para a auditoria. */
    public static function hash(array $r): string
    {
        return hash('sha256', implode('|', [
            $r['prev_hash'] ?? '', $r['trace_id'], $r['seq'], $r['event'], $r['level'], $r['channel'], $r['target_type'], $r['target_id'],
            $r['status'], $r['message'], $r['detail'] ?? '', $r['users_id'], $r['actor'], $r['date_creation'],
        ]));
    }

    /** Confere a cadeia de um rastreio. Devolve [ok, linhas, seq da primeira linha adulterada ou null]. */
    public static function verify(string $trace): array
    {
        global $DB;
        $prev = '';
        $n = 0;
        $expected = 1;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['trace_id' => $trace], 'ORDER' => 'seq']) as $r) {
            $n++;
            if ((int) $r['seq'] !== $expected || (string) $r['prev_hash'] !== $prev || self::hash($r) !== (string) $r['hash']) {
                return [false, $n, (int) $r['seq']];
            }
            $prev = (string) $r['hash'];
            $expected++;
        }
        return [true, $n, null];
    }

    /** Recalcula a cadeia de hashes de um rastreio (só para ferramentas de demonstração que reescrevem datas). */
    public static function rechain(string $trace): void
    {
        global $DB;
        $prev = '';
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['trace_id' => $trace], 'ORDER' => 'seq']) as $r) {
            $r['prev_hash'] = $prev;
            $hash = self::hash($r);
            $DB->update(self::TABLE, ['prev_hash' => $prev, 'hash' => $hash], ['id' => $r['id']]);
            $prev = $hash;
        }
    }

    /** Linhas de um escalonamento, da mais antiga para a mais nova. */
    public static function forEscalation(int $id, int $limit = 500): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['escalations_id' => $id], 'ORDER' => 'seq', 'LIMIT' => $limit]), false);
    }

    /** Busca com filtros (tela de logs). Devolve [linhas, total]. */
    public static function search(array $f, int $page, int $per): array
    {
        global $DB;
        $where = self::where($f);
        $total = countElementsInTable(self::TABLE, $where);
        $crit = ['FROM' => self::TABLE, 'ORDER' => ['id DESC'], 'START' => max(0, ($page - 1) * $per), 'LIMIT' => $per];
        if ($where) {
            $crit['WHERE'] = $where;
        }
        $rows = iterator_to_array($DB->request($crit), false);
        return [$rows, $total];
    }

    public static function where(array $f): array
    {
        $w = [];
        if (($f['trace'] ?? '') !== '') {
            $w['trace_id'] = ['LIKE', '%' . trim($f['trace']) . '%'];
        }
        if (!empty($f['traces'])) { // restrição de visibilidade: só estes rastreios
            $w[] = ['trace_id' => array_values((array) $f['traces'])];
        }
        foreach (['event', 'channel', 'status'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $w[$k] = $f[$k];
            }
        }
        if ((int) ($f['user'] ?? 0)) {
            $w['users_id'] = (int) $f['user'];
        }
        if (($f['from'] ?? '') !== '') {
            $w[] = ['date_creation' => ['>=', $f['from'] . ' 00:00:00']];
        }
        if (($f['to'] ?? '') !== '') {
            $w[] = ['date_creation' => ['<=', $f['to'] . ' 23:59:59.999']];
        }
        if (($f['q'] ?? '') !== '') {
            $w[] = ['OR' => ['message' => ['LIKE', '%' . $f['q'] . '%'], 'detail' => ['LIKE', '%' . $f['q'] . '%']]];
        }
        return $w;
    }

    /** Tira segredos e limita o tamanho dos valores antes de gravar. */
    public static function clean($v, int $depth = 0)
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = is_string($k) && preg_match('/token|secret|pass|key|authorization|signature|apikey|webhook_url|teams_url/i', $k)
                    ? '***' : ($depth > 4 ? '…' : self::clean($x, $depth + 1));
            }
            return $out;
        }
        if (is_string($v)) {
            // URLs com segredo na query: guarda só endereço e caminho
            $v = preg_replace('#(https?://[^\s?"\']+)\?[^\s"\']*#', '$1?…', $v);
            return mb_strlen($v) > 2000 ? mb_substr($v, 0, 2000) . '…' : $v;
        }
        return $v;
    }

    /** Linha de texto para o arquivo de log do GLPI (files/_log/escalations.log). */
    public static function file(string $text): void
    {
        Toolbox::logInFile('escalations', $text . "\n");
    }
}
