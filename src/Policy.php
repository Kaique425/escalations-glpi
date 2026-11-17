<?php

namespace GlpiPlugin\Escalations;

use RuntimeException;

/**
 * Hierarquia (política) de escalonamento: níveis ordenados (N1 → N2 → ... → Diretoria), com alvos, tempo para subir
 * e canais; regras que dizem quando a hierarquia vale; e os SLOs de ACK e de resolução.
 */
class Policy
{
    public const TABLE = 'glpi_plugin_escalations_policies';
    public const LEVELS = 'glpi_plugin_escalations_levels';
    public const TARGETS = 'glpi_plugin_escalations_targets';
    public const RULES = 'glpi_plugin_escalations_rules';

    public const CHANNELS = [
        'email'    => ['E-mail', 'ti-mail'],
        'popup'    => ['Pop-up no GLPI', 'ti-bell-ringing'],
        'teams'    => ['Teams', 'ti-brand-teams'],
        'whatsapp' => ['WhatsApp', 'ti-brand-whatsapp'],
    ];

    public const TARGET_TYPES = [
        'user'       => 'Usuário',
        'group'      => 'Todos do grupo',
        'groupmgr'   => 'Gestores do grupo',
        'supervisor' => 'Gestor de quem já foi acionado / do responsável do chamado',
        'oncall'     => 'Plantão (reservado para a v0.2)',
    ];

    public const SOURCES = [
        'any'         => 'Qualquer origem',
        'ticket'      => 'Chamado',
        'problem'     => 'Problema',
        'change'      => 'Mudança',
        'project'     => 'Projeto',
        'projecttask' => 'Tarefa de projeto',
        'plugin'      => 'Item de plugin (Customer 360, CRM, Marketing...)',
        'event'       => 'Evento (webhook)',
    ];

    // ------------------------------------------------------------------ leitura

    public static function get(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $r) {
            return $r;
        }
        return null;
    }

    /** Hierarquias (id => linha), ordenadas por área e nome. */
    public static function all(bool $onlyActive = false): array
    {
        global $DB;
        $crit = ['FROM' => self::TABLE, 'ORDER' => ['area', 'name']];
        if ($onlyActive) {
            $crit['WHERE'] = ['is_active' => 1];
        }
        $out = [];
        foreach ($DB->request($crit) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Níveis da hierarquia, em ordem, cada um com 'targets' (linhas) e 'channel_list'. */
    public static function levels(int $policyId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::LEVELS, 'WHERE' => ['policies_id' => $policyId], 'ORDER' => ['position', 'id']]) as $l) {
            $l['targets'] = iterator_to_array($DB->request(['FROM' => self::TARGETS, 'WHERE' => ['levels_id' => $l['id']], 'ORDER' => ['type', 'id']]), false);
            $l['channel_list'] = Settings::channels((string) $l['channels']);
            $out[(int) $l['position']] = $l;
        }
        return $out;
    }

    public static function level(int $policyId, int $position): ?array
    {
        return self::levels($policyId)[$position] ?? null;
    }

    public static function levelById(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::LEVELS, 'WHERE' => ['id' => $id]]) as $l) {
            $l['targets'] = iterator_to_array($DB->request(['FROM' => self::TARGETS, 'WHERE' => ['levels_id' => $id]]), false);
            $l['channel_list'] = Settings::channels((string) $l['channels']);
            return $l;
        }
        return null;
    }

    public static function rules(int $policyId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::RULES, 'WHERE' => ['policies_id' => $policyId], 'ORDER' => ['id']]), false);
    }

    /** Nomes legíveis dos alvos de um nível (para o diagrama e as listas). */
    public static function targetNames(array $level): array
    {
        $users = Ui::users();
        $groups = Ui::groups();
        $names = [];
        foreach ($level['targets'] as $t) {
            $id = (int) $t['items_id'];
            $name = match ($t['type']) {
                'user'       => $users[$id] ?? ('usuário #' . $id),
                'group'      => 'Grupo ' . ($groups[$id] ?? ('#' . $id)),
                'groupmgr'   => 'Gestores de ' . ($groups[$id] ?? ('#' . $id)),
                'supervisor' => 'Gestor do responsável',
                default      => 'Plantão',
            };
            $names[] = ($t['role'] ?? 'act') === 'notify' ? $name . ' (só notifica)' : $name;
        }
        return $names;
    }

    // ------------------------------------------------------------------ gravação

    public static function save(array $in, int $id = 0): int
    {
        global $DB;
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Informe o nome da hierarquia.', 422);
        }
        $ack = max(1, (int) ($in['ack_slo_min'] ?? 15));
        $res = max($ack, (int) ($in['resolve_slo_min'] ?? 240));
        $target = (float) str_replace(',', '.', (string) ($in['slo_target'] ?? '95'));
        if ($target < 50 || $target > 100) {
            throw new RuntimeException('A meta de SLO precisa ficar entre 50 e 100%.', 422);
        }
        $row = [
            'name'            => mb_substr($name, 0, 255),
            'area'            => mb_substr(trim((string) ($in['area'] ?? '')), 0, 100),
            'description'     => (string) ($in['description'] ?? ''),
            'is_active'       => !empty($in['is_active']) ? 1 : 0,
            'users_id_owner'  => (int) ($in['users_id_owner'] ?? 0),
            'ack_slo_min'     => $ack,
            'resolve_slo_min' => $res,
            'slo_target'      => round($target, 2),
            'slo_window_days' => max(1, min(365, (int) ($in['slo_window_days'] ?? 30))),
            'slo_warn_pct'    => max(10, min(99, (int) ($in['slo_warn_pct'] ?? 80))),
            'repeat_min'      => max(1, (int) ($in['repeat_min'] ?? 15)),
            'max_repeats'     => max(0, min(50, (int) ($in['max_repeats'] ?? 3))),
            'date_mod'        => date('Y-m-d H:i:s'),
        ];
        if ($id) {
            $DB->update(self::TABLE, $row, ['id' => $id]);
            return $id;
        }
        $row['date_creation'] = $row['date_mod'];
        $DB->insert(self::TABLE, $row);
        return (int) $DB->insertId();
    }

    public static function delete(int $id): void
    {
        global $DB;
        if (countElementsInTable(Escalation::TABLE, ['policies_id' => $id, 'state' => ['open', 'acked']])) {
            throw new RuntimeException('Há escalonamentos abertos nesta hierarquia. Resolva-os ou desative a hierarquia em vez de excluir.', 422);
        }
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => self::LEVELS, 'WHERE' => ['policies_id' => $id]]) as $l) {
            $DB->delete(self::TARGETS, ['levels_id' => $l['id']]);
        }
        $DB->delete(self::LEVELS, ['policies_id' => $id]);
        $DB->delete(self::RULES, ['policies_id' => $id]);
        $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** Cria ou atualiza um nível e seus alvos. $in: name, position, wait_min, channels[], users[], groups[], groupmgrs[], supervisor. */
    public static function saveLevel(int $policyId, array $in, int $levelId = 0): int
    {
        global $DB;
        $channels = array_values(array_intersect(array_keys(self::CHANNELS), (array) ($in['channels'] ?? [])));
        if (!$channels) {
            throw new RuntimeException('Escolha ao menos um canal para o nível.', 422);
        }
        $users = array_filter(array_map('intval', (array) ($in['users'] ?? [])));
        $groups = array_filter(array_map('intval', (array) ($in['groups'] ?? [])));
        $mgrs = array_filter(array_map('intval', (array) ($in['groupmgrs'] ?? [])));
        $super = !empty($in['supervisor']);
        // "somente notificado": recebe os avisos mas não age; quem está nas duas listas fica como escalado
        $nUsers = array_diff(array_filter(array_map('intval', (array) ($in['users_notify'] ?? []))), $users);
        $nGroups = array_diff(array_filter(array_map('intval', (array) ($in['groups_notify'] ?? []))), $groups);
        $nMgrs = array_diff(array_filter(array_map('intval', (array) ($in['groupmgrs_notify'] ?? []))), $mgrs);
        $nSuper = !empty($in['supervisor_notify']) && !$super;
        if (!$users && !$groups && !$mgrs && !$super && !$nUsers && !$nGroups && !$nMgrs && !$nSuper) {
            throw new RuntimeException('Escolha quem será acionado neste nível (usuário, grupo ou gestor).', 422);
        }
        $max = 0;
        foreach ($DB->request(['SELECT' => ['position'], 'FROM' => self::LEVELS, 'WHERE' => ['policies_id' => $policyId]]) as $r) {
            $max = max($max, (int) $r['position']);
        }
        $row = [
            'policies_id' => $policyId,
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Nível', 0, 100),
            'position'    => (int) ($in['position'] ?? 0) ?: ($levelId ? 0 : $max + 1),
            'wait_min'    => max(1, (int) ($in['wait_min'] ?? 15)),
            'channels'    => implode(',', $channels),
        ];
        if ($levelId) {
            if (!$row['position']) {
                unset($row['position']);
            }
            $DB->update(self::LEVELS, $row, ['id' => $levelId]);
        } else {
            $DB->insert(self::LEVELS, $row);
            $levelId = (int) $DB->insertId();
        }
        $DB->delete(self::TARGETS, ['levels_id' => $levelId]);
        foreach ($users as $u) {
            $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => 'user', 'items_id' => $u]);
        }
        foreach ($groups as $g) {
            $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => 'group', 'items_id' => $g]);
        }
        foreach ($mgrs as $g) {
            $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => 'groupmgr', 'items_id' => $g]);
        }
        if ($super) {
            $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => 'supervisor', 'items_id' => 0]);
        }
        foreach ([['user', $nUsers], ['group', $nGroups], ['groupmgr', $nMgrs]] as [$type, $list]) {
            foreach ($list as $id) {
                $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => $type, 'items_id' => $id, 'role' => 'notify']);
            }
        }
        if ($nSuper) {
            $DB->insert(self::TARGETS, ['levels_id' => $levelId, 'type' => 'supervisor', 'items_id' => 0, 'role' => 'notify']);
        }
        self::normalize($policyId);
        return $levelId;
    }

    public static function deleteLevel(int $levelId): void
    {
        global $DB;
        $l = self::levelById($levelId);
        if (!$l) {
            return;
        }
        $DB->delete(self::TARGETS, ['levels_id' => $levelId]);
        $DB->delete(self::LEVELS, ['id' => $levelId]);
        self::normalize((int) $l['policies_id']);
    }

    /** Renumera os níveis 1..n na ordem (position, id). */
    public static function normalize(int $policyId): void
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::LEVELS, 'WHERE' => ['policies_id' => $policyId], 'ORDER' => ['position', 'id']]) as $l) {
            $DB->update(self::LEVELS, ['position' => ++$n], ['id' => $l['id']]);
        }
    }

    public static function saveRule(int $policyId, array $in, int $ruleId = 0): int
    {
        global $DB;
        $source = array_key_exists((string) ($in['source'] ?? ''), self::SOURCES) ? $in['source'] : 'any';
        $row = [
            'policies_id'       => $policyId,
            'name'              => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Regra', 0, 150),
            'source'            => $source,
            'plugin_key'        => '',
            'type_key'          => '',
            'severity_min'      => max(1, min(5, (int) ($in['severity_min'] ?? 1))),
            'entities_id'       => isset($in['entities_id']) && $in['entities_id'] !== '' ? (int) $in['entities_id'] : -1,
            'itilcategories_id' => (int) ($in['itilcategories_id'] ?? 0),
            'match_text'        => mb_substr(trim((string) ($in['match_text'] ?? '')), 0, 255),
            'sla_pct'           => max(0, min(100, (int) ($in['sla_pct'] ?? 0))),
            'unassigned_min'    => max(0, (int) ($in['unassigned_min'] ?? 0)),
            'is_active'         => !empty($in['is_active']) ? 1 : 0,
        ];
        if ($source === 'plugin' && str_contains((string) ($in['plugin_type'] ?? ''), '|')) {
            [$row['plugin_key'], $row['type_key']] = array_map(fn ($x) => mb_substr(trim($x), 0, 60), explode('|', (string) $in['plugin_type'], 2));
        }
        if ($ruleId) {
            $DB->update(self::RULES, $row, ['id' => $ruleId]);
            return $ruleId;
        }
        $DB->insert(self::RULES, $row);
        return (int) $DB->insertId();
    }

    public static function deleteRule(int $ruleId): void
    {
        global $DB;
        $DB->delete(self::RULES, ['id' => $ruleId]);
    }

    // ------------------------------------------------------------------ escolha da hierarquia

    /**
     * Acha a regra (e a hierarquia) que vale para uma origem. A regra mais específica ganha:
     * maior severity_min, depois a que tem texto, categoria e entidade definidos.
     * Devolve a linha da regra (com 'policy') ou null.
     */
    public static function match(string $source, int $severity, int $entity, int $category, string $text, string $plugin = '', string $type = ''): ?array
    {
        global $DB;
        $best = null;
        $bestScore = -1;
        $active = array_keys(self::all(true));
        if (!$active) {
            return null;
        }
        foreach ($DB->request(['FROM' => self::RULES, 'WHERE' => ['is_active' => 1, 'policies_id' => $active]]) as $r) {
            if (!in_array($r['source'], ['any', $source], true) || $severity < (int) $r['severity_min']) {
                continue;
            }
            if ((int) $r['entities_id'] >= 0 && !self::entityMatches((int) $r['entities_id'], $entity)) {
                continue;
            }
            if ($source === 'plugin' && (($r['plugin_key'] !== '' && $r['plugin_key'] !== $plugin) || ($r['type_key'] !== '' && $r['type_key'] !== $type))) {
                continue;
            }
            if ((int) $r['itilcategories_id'] && (int) $r['itilcategories_id'] !== $category) {
                continue;
            }
            if ($r['match_text'] !== '' && stripos($text, $r['match_text']) === false) {
                continue;
            }
            $score = (int) $r['severity_min'] * 10 + ($r['match_text'] !== '' ? 4 : 0) + ((int) $r['itilcategories_id'] ? 3 : 0) + ((int) $r['entities_id'] >= 0 ? 2 : 0) + ($r['source'] !== 'any' ? 1 : 0) + ($r['type_key'] !== '' ? 3 : 0) + ($r['plugin_key'] !== '' ? 1 : 0);
            if ($score > $bestScore) {
                $best = $r;
                $bestScore = $score;
            }
        }
        if ($best) {
            $best['policy'] = self::get((int) $best['policies_id']);
        }
        return $best;
    }

    /** A entidade da regra vale para a do chamado (ela mesma ou uma filha)? */
    private static function entityMatches(int $ruleEntity, int $entity): bool
    {
        if ($ruleEntity === $entity) {
            return true;
        }
        static $sons = [];
        if (!isset($sons[$ruleEntity])) {
            $sons[$ruleEntity] = array_map('intval', array_values(getSonsOf('glpi_entities', $ruleEntity)));
        }
        return in_array($entity, $sons[$ruleEntity], true);
    }
}
