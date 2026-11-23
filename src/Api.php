<?php

namespace GlpiPlugin\Escalations;

use Plugin;
use Throwable;

/**
 * Porta de entrada para OUTROS PLUGINS (e scripts) escalarem qualquer coisa: contrato vencendo, demanda atrasada,
 * oportunidade parada, tarefa de projeto, mini projeto, plano de ação... Qualquer item que tenha prazo e responsável.
 * Estável de propósito: nunca lança exceção para quem chama (devolve null/false e grava no log).
 *
 * Uso típico no plugin de origem:
 *
 *   if (class_exists(\GlpiPlugin\Escalations\Api::class) && \GlpiPlugin\Escalations\Api::available()) {
 *       // 1) já está crítico: escala agora (agrupa se já houver um aberto com a mesma chave)
 *       \GlpiPlugin\Escalations\Api::escalate([
 *           'plugin' => 'customer360', 'type' => 'contrato_vencendo', 'key' => 'contrato:' . $id,
 *           'title' => 'Contrato 123 vence em 10 dias', 'severity' => 4, 'entities_id' => 0,
 *           'summary' => '...', 'facts' => ['Conta' => 'ACME'], 'url' => '/plugins/customer360/front/contrato.php?id=' . $id,
 *       ]);
 *       // 2) ou deixa o motor acompanhar o prazo e escalar ao consumir X% (percentual definido na regra)
 *       \GlpiPlugin\Escalations\Api::track([... mesmos campos ..., 'start_at' => '2026-01-01', 'due_at' => '2026-12-31']);
 *       // 3) quando o problema deixa de existir (renovou, entregou, ganhou, fechou)
 *       \GlpiPlugin\Escalations\Api::resolve('customer360', 'contrato_vencendo', 'contrato:' . $id);
 *   }
 *
 * Quem decide se vira escalonamento, para qual hierarquia e com quais níveis é o administrador, em Escalations →
 * Hierarquias → Regras (origem "Item de plugin"). Os tipos usados aparecem lá sozinhos.
 */
class Api
{
    public const SOURCES = 'glpi_plugin_escalations_sources';
    public const TRACKED = 'glpi_plugin_escalations_tracked';

    public static function available(): bool
    {
        return Plugin::isPluginActive('escalations');
    }

    /** Declara (ou atualiza) um tipo para ele aparecer na tela de regras. Opcional: escalate/track registram sozinhos. */
    public static function registerType(string $plugin, string $type, string $label = '', int $severity = 3, string $description = ''): void
    {
        global $DB;
        try {
            $plugin = self::slug($plugin);
            $type = self::slug($type);
            if ($plugin === '' || $type === '') {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $row = [];
            foreach ($DB->request(['FROM' => self::SOURCES, 'WHERE' => ['plugin_key' => $plugin, 'type_key' => $type]]) as $r) {
                $row = $r;
            }
            if ($row) {
                $upd = ['last_used' => $now];
                if ($label !== '') {
                    $upd += ['label' => mb_substr($label, 0, 150), 'description' => mb_substr($description, 0, 500), 'default_severity' => max(1, min(5, $severity))];
                }
                $DB->update(self::SOURCES, $upd, ['id' => $row['id']]);
                return;
            }
            $DB->insert(self::SOURCES, ['plugin_key' => $plugin, 'type_key' => $type, 'label' => mb_substr($label !== '' ? $label : $type, 0, 150), 'description' => mb_substr($description, 0, 500),
                'default_severity' => max(1, min(5, $severity)), 'date_creation' => $now, 'last_used' => $now]);
        } catch (Throwable $e) {
            Log::file('Api::registerType: ' . $e->getMessage());
        }
    }

    /**
     * Escala agora. Campos: plugin, type, key, title (obrigatórios); severity (1-5), summary, facts[], entities_id, url, label.
     * @return array{id: int, trace_id: string, created: bool}|null null = sem regra, sem hierarquia ou erro
     */
    public static function escalate(array $a): ?array
    {
        try {
            if (!Settings::sourceOn('plugin')) {
                return null; // origem desligada nas configurações
            }
            $n = self::normalize($a);
            if (!$n) {
                return null;
            }
            self::registerType($n['plugin'], $n['type'], (string) ($a['label'] ?? ''), $n['severity']);
            $rule = Policy::match('plugin', $n['severity'], $n['entities_id'], 0, $n['title'] . ' ' . $n['summary'], $n['plugin'], $n['type']);
            if (!$rule) {
                self::noRule($n);
                return null;
            }
            [$id, $created] = Escalation::open([
                'policies_id' => (int) $rule['policies_id'], 'rules_id' => (int) $rule['id'], 'source' => 'plugin', 'source_ref' => mb_substr($n['plugin'] . ':' . $n['type'] . ':' . $n['key'], 0, 100),
                'dedup_key' => $n['dedup'], 'title' => $n['title'], 'summary' => $n['summary'], 'severity' => $n['severity'], 'entities_id' => $n['entities_id'], 'facts' => $n['facts'], 'actor' => 'plugin',
            ]);
            $e = Escalation::get($id);
            if ($created) {
                Dispatcher::flush($id);
            }
            return ['id' => $id, 'trace_id' => (string) ($e['trace_id'] ?? ''), 'created' => $created];
        } catch (Throwable $e) {
            Log::file('Api::escalate: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pede ao motor para acompanhar o prazo do item e escalar quando consumir X% (X vem da regra; sem X, ao vencer).
     * Campos: os de escalate() + due_at (obrigatório), start_at (padrão: agora). Chamar de novo atualiza prazo e dados.
     */
    public static function track(array $a): bool
    {
        global $DB;
        try {
            if (!Settings::sourceOn('plugin')) {
                return false;
            }
            $n = self::normalize($a);
            $due = self::time($a['due_at'] ?? null);
            if (!$n || !$due) {
                return false;
            }
            $start = self::time($a['start_at'] ?? null) ?: time();
            self::registerType($n['plugin'], $n['type'], (string) ($a['label'] ?? ''), $n['severity']);
            $now = date('Y-m-d H:i:s');
            $row = ['title' => $n['title'], 'summary' => $n['summary'], 'facts' => json_encode($n['facts'], JSON_UNESCAPED_UNICODE), 'severity' => $n['severity'], 'entities_id' => $n['entities_id'],
                'url' => (string) ($a['url'] ?? ''), 'start_at' => date('Y-m-d H:i:s', $start), 'due_at' => date('Y-m-d H:i:s', $due), 'date_mod' => $now];
            $cur = null;
            foreach ($DB->request(['FROM' => self::TRACKED, 'WHERE' => ['plugin_key' => $n['plugin'], 'type_key' => $n['type'], 'ref_key' => $n['key']]]) as $r) {
                $cur = $r;
            }
            if ($cur) {
                if ($cur['status'] === 'done') {
                    $row += ['status' => 'active', 'escalations_id' => 0];
                }
                $DB->update(self::TRACKED, $row, ['id' => $cur['id']]);
            } else {
                $DB->insert(self::TRACKED, $row + ['plugin_key' => $n['plugin'], 'type_key' => $n['type'], 'ref_key' => $n['key'], 'status' => 'active', 'date_creation' => $now]);
            }
            return true;
        } catch (Throwable $e) {
            Log::file('Api::track: ' . $e->getMessage());
            return false;
        }
    }

    /** O problema deixou de existir: encerra o acompanhamento e resolve o escalonamento aberto (se houver). */
    public static function resolve(string $plugin, string $type, string $key, string $note = ''): bool
    {
        global $DB;
        try {
            $plugin = self::slug($plugin);
            $type = self::slug($type);
            $key = trim($key);
            if ($plugin === '' || $type === '' || $key === '') {
                return false;
            }
            $DB->update(self::TRACKED, ['status' => 'done', 'date_mod' => date('Y-m-d H:i:s')], ['plugin_key' => $plugin, 'type_key' => $type, 'ref_key' => $key]);
            $hit = false;
            foreach ($DB->request(['FROM' => Escalation::TABLE, 'WHERE' => ['dedup_key' => self::dedup($plugin, $type, $key), 'state' => ['open', 'acked']]]) as $e) {
                Escalation::resolve((int) $e['id'], 'plugin ' . $plugin, 0, $note);
                Dispatcher::flush((int) $e['id']);
                $hit = true;
            }
            return $hit;
        } catch (Throwable $e) {
            Log::file('Api::resolve: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ internos

    public static function dedup(string $plugin, string $type, string $key): string
    {
        return mb_substr('plugin:' . $plugin . ':' . $type . ':' . $key, 0, 190);
    }

    public static function slug(string $v): string
    {
        return mb_substr(preg_replace('/[^a-z0-9_\-:.]/', '', mb_strtolower(trim($v))), 0, 60);
    }

    /** @return int|null timestamp */
    private static function time($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = is_numeric($v) ? (int) $v : strtotime((string) $v);
        return $t ?: null;
    }

    private static function normalize(array $a): ?array
    {
        $plugin = self::slug((string) ($a['plugin'] ?? ''));
        $type = self::slug((string) ($a['type'] ?? ''));
        $key = trim((string) ($a['key'] ?? ''));
        $title = trim((string) ($a['title'] ?? ''));
        if ($plugin === '' || $type === '' || $key === '' || $title === '') {
            Log::file('Api: chamada sem plugin, type, key ou title.');
            return null;
        }
        $facts = is_array($a['facts'] ?? null) ? $a['facts'] : [];
        $url = trim((string) ($a['url'] ?? ''));
        if ($url !== '') {
            $facts['Abrir'] = preg_match('#^https?://#', $url) ? $url : Ui::absUrl($url);
        }
        return [
            'plugin' => $plugin, 'type' => $type, 'key' => mb_substr($key, 0, 120), 'title' => mb_substr($title, 0, 255), 'summary' => (string) ($a['summary'] ?? ''),
            'severity' => max(1, min(5, (int) ($a['severity'] ?? 3))), 'entities_id' => (int) ($a['entities_id'] ?? 0), 'facts' => $facts, 'dedup' => self::dedup($plugin, $type, mb_substr($key, 0, 120)),
        ];
    }

    /** Sem regra: registra uma vez por dia e por título, para não encher o log. */
    private static function noRule(array $n): void
    {
        $msg = 'Nenhuma regra de hierarquia casa com ' . $n['plugin'] . ' · ' . $n['type'] . ' (severidade ' . $n['severity'] . '): ' . $n['title'];
        $msg = mb_substr($msg, 0, 500);
        if (!countElementsInTable(Log::TABLE, ['trace_id' => 'SYS-' . date('Ymd'), 'message' => $msg])) {
            Log::write(null, 'system', ['status' => 'warn', 'actor' => 'plugin', 'message' => $msg]);
        }
    }
}
