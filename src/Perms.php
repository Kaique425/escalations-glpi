<?php

namespace GlpiPlugin\Escalations;

use Session;

/**
 * Permissões do plugin, em camadas (da mais geral para a mais específica):
 *
 *  1. PERFIL (Administração → Perfis → aba Escalations): os direitos de leitura, atualização, criação, administração, "ver todos"
 *     e os de ação: reconhecer (ACK), resolver, silenciar, cancelar, ver logs e ver SLO/gaps. "Atualizar" vale como pacote de
 *     todas as ações (compatível com a versão anterior).
 *  2. PERFIL no plugin (Permissões): "somente notificado" (o perfil recebe avisos, mas não age) e o ESCOPO de hierarquias em que
 *     os direitos do perfil valem.
 *  3. USUÁRIO no plugin (Permissões): exceções por pessoa: permitir ou negar cada capacidade, "somente notificado" e escopo.
 *  4. PAPEL NO NÍVEL (Hierarquias): quem está num nível como "escalado" pode agir no escalonamento em que foi acionado; quem está
 *     como "somente notificado" recebe os avisos, mas não ganha ação por isso.
 *
 * Ordem de decisão para uma ação: negar do usuário vence tudo; "somente notificado" (usuário ou perfil) bloqueia as ações;
 * senão vale o direito do perfil (ou a permissão do usuário) dentro do escopo; senão vale ter sido acionado como escalado
 * naquele escalonamento (exceto cancelar). "Administrar" e "Criar" vêm só do perfil e nunca podem ser concedidos por exceção.
 */
class Perms
{
    public const USERS = 'glpi_plugin_escalations_user_perms';
    public const PROFILES = 'glpi_plugin_escalations_profile_perms';

    /** Ações sobre um escalonamento. */
    public const ACTIONS = ['ack', 'resolve', 'silence', 'cancel'];

    /** Capacidades que o usuário pode ter concedidas ou negadas por exceção. */
    public const OVERRIDABLE = ['view', 'ack', 'resolve', 'silence', 'cancel', 'logs', 'slo', 'all'];

    /** capacidade => [rótulo, bit no direito do plugin]. */
    public static function caps(): array
    {
        return [
            'view'    => ['Ver o plugin (painel e escalonamentos)', READ],
            'ack'     => ['Reconhecer (ACK)', Escalation::ACK],
            'resolve' => ['Resolver', Escalation::RESOLVE],
            'silence' => ['Silenciar e reenviar avisos', Escalation::SILENCE],
            'cancel'  => ['Cancelar escalonamento', Escalation::CANCEL],
            'logs'    => ['Ver logs rastreáveis e mensagens enviadas', Escalation::LOGS],
            'slo'     => ['Ver SLO, SLA e análise de gaps', Escalation::SLO],
            'all'     => ['Ver todos os escalonamentos', Escalation::ALL],
        ];
    }

    // ------------------------------------------------------------------ decisão

    /**
     * O usuário (padrão: o da sessão) pode? $e = escalonamento (para ação, vale ter sido acionado como escalado nele).
     * Capacidades: view, ack, resolve, silence (e resend), cancel, logs, slo, all, create, manage.
     */
    public static function can(string $cap, ?array $e = null, ?int $uid = null): bool
    {
        $uid ??= (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return false;
        }
        [$bits, $notifyOnly, $scope, $cfg] = self::context($uid);
        return self::evaluate($cap, $e, $uid, $bits, $notifyOnly, $scope, $cfg);
    }

    /** Qualquer ação sobre o escalonamento (para mostrar a barra de ações). */
    public static function canAnyAction(array $e, ?int $uid = null): bool
    {
        foreach (self::ACTIONS as $a) {
            if (self::can($a, $e, $uid)) {
                return true;
            }
        }
        return false;
    }

    private static function evaluate(string $cap, ?array $e, int $uid, int $bits, bool $notifyOnly, array $scope, ?array $cfg): bool
    {
        if ($cap === 'resend') {
            $cap = 'silence';
        }
        if ($cap === 'manage') {
            return (bool) ($bits & Escalation::MANAGE);
        }
        if ($cap === 'create') {
            return (bool) ($bits & CREATE);
        }
        $caps = self::caps();
        if (!isset($caps[$cap])) {
            return false;
        }
        $bit = $caps[$cap][1];
        $isAction = in_array($cap, self::ACTIONS, true);
        if ($cfg && ((int) $cfg['deny'] & $bit)) {
            return false; // negar vence tudo
        }
        if ($isAction && $notifyOnly) {
            return false;
        }
        $allowed = self::coreHas($bits, $cap) || ($cfg && ((int) $cfg['grant'] & $bit));
        if ($allowed && $e && $isAction && $scope && !in_array((int) $e['policies_id'], $scope, true)) {
            $allowed = false; // fora do escopo de hierarquias deste perfil/usuário
        }
        if (!$allowed && $e && $isAction && $cap !== 'cancel' && self::actsOn($e, $uid)) {
            $allowed = true; // foi acionado como escalado neste escalonamento
        }
        return $allowed;
    }

    /** O direito do perfil cobre a capacidade? ("Atualizar" cobre todas as ações.) */
    private static function coreHas(int $bits, string $cap): bool
    {
        $bit = self::caps()[$cap][1];
        if (in_array($cap, self::ACTIONS, true) && ($bits & UPDATE)) {
            return true;
        }
        return (bool) ($bits & $bit);
    }

    /** O usuário foi acionado como escalado (papel "agir") neste escalonamento? */
    public static function actsOn(array $e, int $uid): bool
    {
        return (bool) countElementsInTable(Escalation::DELIVERIES, ['escalations_id' => (int) $e['id'], 'users_id' => $uid, 'kind' => 'level', 'role' => 'act']);
    }

    // ------------------------------------------------------------------ contexto do usuário (direitos do perfil, escopo, somente notificado)

    /**
     * [bits do perfil, somente notificado?, escopo de hierarquias (ids; vazio = todas), exceção do usuário].
     * Na sessão do próprio usuário vale o perfil ativo; fora dela (cron, WhatsApp) vale a união dos perfis dele.
     * $union = true força a união (tela de permissões efetivas).
     */
    public static function context(int $uid, bool $union = false): array
    {
        $cfg = self::userCfg($uid);
        $session = !$union && $uid === (int) Session::getLoginUserID() && !empty($_SESSION['glpiactiveprofile']['id']);
        if ($session) {
            $pid = (int) $_SESSION['glpiactiveprofile']['id'];
            $bits = (int) ($_SESSION['glpiactiveprofile'][Escalation::$rightname] ?? 0);
            $pcfg = self::profileCfg($pid);
            $profNotify = (bool) ($pcfg['notify_only'] ?? false);
            $profScope = $pcfg['scope'] ?? [];
        } else {
            $profiles = self::profileIds($uid);
            $bits = 0;
            if ($profiles) {
                global $DB;
                foreach ($DB->request(['SELECT' => 'rights', 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $profiles, 'name' => Escalation::$rightname]]) as $r) {
                    $bits |= (int) $r['rights'];
                }
            }
            $profNotify = (bool) $profiles;
            $profScope = [];
            $unscoped = false;
            foreach ($profiles as $pid) {
                $pcfg = self::profileCfg($pid);
                $profNotify = $profNotify && (bool) ($pcfg['notify_only'] ?? false);
                if (empty($pcfg['scope'])) {
                    $unscoped = true;
                } else {
                    $profScope = array_merge($profScope, $pcfg['scope']);
                }
            }
            $profScope = $unscoped ? [] : array_values(array_unique($profScope));
        }
        $notifyOnly = (bool) ($cfg['notify_only'] ?? false) || $profNotify;
        if ($cfg && !empty($cfg['scope'])) {
            $profScope = $cfg['scope']; // o escopo do usuário substitui o do perfil
        }
        return [$bits, $notifyOnly, $profScope, $cfg];
    }

    /** Escopo de hierarquias do usuário (vazio = todas). */
    public static function scope(?int $uid = null): array
    {
        $uid ??= (int) Session::getLoginUserID();
        return $uid > 0 ? self::context($uid)[2] : [];
    }

    /** O usuário só recebe avisos (não pode agir), por exceção dele ou porque todos os perfis dele são "somente notificado"? */
    public static function notifyOnly(int $uid): bool
    {
        return self::context($uid)[1];
    }

    /** Perfis do usuário (ids). */
    public static function profileIds(int $uid): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => 'profiles_id', 'DISTINCT' => true, 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $uid]]) as $r) {
            $ids[] = (int) $r['profiles_id'];
        }
        return $ids;
    }

    /** Resultado completo para a tela de permissões efetivas: capacidades, somente notificado, escopo e de onde vem. */
    public static function effective(int $uid): array
    {
        [$bits, $notifyOnly, $scope, $cfg] = self::context($uid, true);
        $out = ['caps' => [], 'notify_only' => $notifyOnly, 'scope' => $scope, 'cfg' => $cfg, 'manage' => (bool) ($bits & Escalation::MANAGE), 'create' => (bool) ($bits & CREATE)];
        foreach (array_keys(self::caps()) as $cap) {
            $out['caps'][$cap] = self::evaluate($cap, null, $uid, $bits, $notifyOnly, $scope, $cfg);
        }
        return $out;
    }

    // ------------------------------------------------------------------ configuração por usuário

    public static function userCfg(int $uid): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::USERS, 'WHERE' => ['users_id' => $uid]]) as $r) {
            return ['grant' => (int) $r['grant_bits'], 'deny' => (int) $r['deny_bits'], 'notify_only' => (bool) $r['notify_only'], 'scope' => self::ids((string) $r['scope']), 'note' => (string) $r['note']];
        }
        return null;
    }

    /** Todas as exceções por usuário (users_id => configuração). */
    public static function allUserCfg(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::USERS, 'ORDER' => ['date_mod DESC']]) as $r) {
            $out[(int) $r['users_id']] = ['grant' => (int) $r['grant_bits'], 'deny' => (int) $r['deny_bits'], 'notify_only' => (bool) $r['notify_only'], 'scope' => self::ids((string) $r['scope']), 'note' => (string) $r['note']];
        }
        return $out;
    }

    /**
     * Grava as exceções de um usuário. $in: grant[] e deny[] (capacidades), notify_only, scope[] (ids de hierarquias), note.
     * Sem nenhuma exceção, o registro é apagado.
     */
    public static function saveUser(int $uid, array $in, int $by = 0): void
    {
        global $DB;
        $caps = self::caps();
        $grant = $deny = 0;
        $grantNames = $denyNames = [];
        foreach (self::OVERRIDABLE as $cap) {
            $state = (string) ($in['cap'][$cap] ?? '');
            if ($state === 'grant') {
                $grant |= $caps[$cap][1];
                $grantNames[] = $cap;
            } elseif ($state === 'deny') {
                $deny |= $caps[$cap][1];
                $denyNames[] = $cap;
            }
        }
        $scope = implode(',', array_filter(array_map('intval', (array) ($in['scope'] ?? []))));
        $notify = !empty($in['notify_only']) ? 1 : 0;
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
        $before = self::userCfg($uid);
        if (!$grant && !$deny && !$notify && $scope === '') {
            $DB->delete(self::USERS, ['users_id' => $uid]);
            $row = null;
        } else {
            $row = ['grant_bits' => $grant, 'deny_bits' => $deny, 'notify_only' => $notify, 'scope' => $scope, 'note' => $note, 'users_id_set' => $by, 'date_mod' => date('Y-m-d H:i:s')];
            if ($before) {
                $DB->update(self::USERS, $row, ['users_id' => $uid]);
            } else {
                $DB->insert(self::USERS, $row + ['users_id' => $uid]);
            }
        }
        Log::write(null, 'system', ['status' => 'info', 'users_id' => $by, 'actor' => 'user', 'target_type' => 'user', 'target_id' => $uid,
            'message' => 'Permissões do usuário ' . Ui::userName($uid) . ' ' . ($row ? 'alteradas' : 'removidas (volta a valer o perfil)') . '.',
            'detail' => ['permitir' => $grantNames, 'negar' => $denyNames, 'somente_notificado' => (bool) $notify, 'escopo' => $scope, 'antes' => $before]]);
    }

    public static function deleteUser(int $uid, int $by = 0): void
    {
        self::saveUser($uid, [], $by);
    }

    // ------------------------------------------------------------------ configuração por perfil

    public static function profileCfg(int $pid): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::PROFILES, 'WHERE' => ['profiles_id' => $pid]]) as $r) {
            return ['notify_only' => (bool) $r['notify_only'], 'scope' => self::ids((string) $r['scope']), 'note' => (string) $r['note']];
        }
        return null;
    }

    public static function saveProfile(int $pid, array $in, int $by = 0): void
    {
        global $DB;
        $scope = implode(',', array_filter(array_map('intval', (array) ($in['scope'] ?? []))));
        $notify = !empty($in['notify_only']) ? 1 : 0;
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
        $before = self::profileCfg($pid);
        if ($before === null && !$notify && $scope === '') {
            return; // nada a gravar
        }
        if ($before !== null && (int) $before['notify_only'] === $notify && implode(',', $before['scope']) === $scope && $before['note'] === $note) {
            return; // não mudou: não grava nem registra no log
        }
        if (!$notify && $scope === '') {
            $DB->delete(self::PROFILES, ['profiles_id' => $pid]);
        } elseif ($before) {
            $DB->update(self::PROFILES, ['notify_only' => $notify, 'scope' => $scope, 'note' => $note, 'date_mod' => date('Y-m-d H:i:s')], ['profiles_id' => $pid]);
        } else {
            $DB->insert(self::PROFILES, ['profiles_id' => $pid, 'notify_only' => $notify, 'scope' => $scope, 'note' => $note, 'date_mod' => date('Y-m-d H:i:s')]);
        }
        Log::write(null, 'system', ['status' => 'info', 'users_id' => $by, 'actor' => 'user', 'target_type' => 'profile', 'target_id' => $pid,
            'message' => 'Configuração do perfil #' . $pid . ' alterada (somente notificado: ' . ($notify ? 'sim' : 'não') . ', escopo: ' . ($scope !== '' ? $scope : 'todas as hierarquias') . ').',
            'detail' => ['antes' => $before]]);
    }

    /** Perfis (id => nome): por padrão só os da interface central; $all = true traz todos (para mostrar o nome de qualquer perfil). */
    public static function profiles(bool $all = false): array
    {
        global $DB;
        $out = [];
        $crit = ['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name'];
        if (!$all) {
            $crit['WHERE'] = ['interface' => 'central'];
        }
        foreach ($DB->request($crit) as $p) {
            $out[(int) $p['id']] = (string) $p['name'];
        }
        return $out;
    }

    /** Direitos que a tela do plugin edita: chave => [rótulo curto, rótulo longo, bit]. A ordem é a das colunas. */
    public static function rightBits(): array
    {
        return [
            'view'    => ['Ver', 'Ver o plugin (painel e escalonamentos em que foi acionado)', READ],
            'ack'     => ['ACK', 'Reconhecer (ACK)', Escalation::ACK],
            'resolve' => ['Resolver', 'Resolver', Escalation::RESOLVE],
            'silence' => ['Silenciar', 'Silenciar e reenviar avisos', Escalation::SILENCE],
            'cancel'  => ['Cancelar', 'Cancelar escalonamento', Escalation::CANCEL],
            'update'  => ['Todas as ações', 'Atualizar: vale como todas as ações juntas', UPDATE],
            'logs'    => ['Logs', 'Ver logs rastreáveis e mensagens enviadas', Escalation::LOGS],
            'slo'     => ['SLO/gaps', 'Ver SLO, SLA e análise de gaps', Escalation::SLO],
            'all'     => ['Ver todos', 'Ver todos os escalonamentos (sem isso, só os que acionaram o usuário)', Escalation::ALL],
            'create'  => ['Criar', 'Abrir escalonamento manual', CREATE],
            'manage'  => ['Administrar', 'Administrar hierarquias, canais, permissões e configuração', Escalation::MANAGE],
        ];
    }

    /** Modelos de perfil (chaves de rightBits marcadas + somente notificado), para preencher a matriz com um clique. */
    public static function presets(): array
    {
        return [
            'notify'  => ['Somente notificado', [], true],
            'viewer'  => ['Observador (só vê)', ['view', 'slo'], false],
            'operator' => ['Operador (age no que foi acionado)', ['view', 'ack', 'resolve'], false],
            'manager' => ['Gestor (age, silencia, cancela e vê tudo)', ['view', 'ack', 'resolve', 'silence', 'cancel', 'logs', 'slo', 'all'], false],
            'auditor' => ['Auditor (vê tudo, não age)', ['view', 'logs', 'slo', 'all'], false],
            'admin'   => ['Administrador (tudo)', ['view', 'ack', 'resolve', 'silence', 'cancel', 'update', 'logs', 'slo', 'all', 'create', 'manage'], false],
        ];
    }

    /**
     * Grava os direitos do plugin de um perfil (os mesmos da aba Escalations em Perfis) a partir da tela do plugin.
     * $keys = chaves de rightBits() marcadas. Os demais bits do direito ficam como estão. Recusa tirar a administração do
     * próprio perfil ativo ou do último perfil que administra o plugin (para ninguém se trancar para fora).
     * Devolve true se mudou.
     */
    public static function saveProfileRights(int $pid, array $keys, int $by = 0): bool
    {
        global $DB;
        $map = self::rightBits();
        $known = 0;
        foreach ($map as $m) {
            $known |= $m[2];
        }
        $old = self::profileBits($pid);
        $new = $old & ~$known;
        foreach ($keys as $k) {
            if (isset($map[$k])) {
                $new |= $map[$k][2];
            }
        }
        if ($new === $old) {
            return false;
        }
        if (($old & Escalation::MANAGE) && !($new & Escalation::MANAGE)) {
            if ($pid === (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0)) {
                throw new \RuntimeException('Você não pode tirar a administração do perfil que está usando agora: perderia o acesso a esta tela.', 422);
            }
            $others = 0;
            foreach ($DB->request(['SELECT' => 'rights', 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Escalation::$rightname, ['NOT' => ['profiles_id' => $pid]]]]) as $r) {
                $others += ((int) $r['rights'] & Escalation::MANAGE) ? 1 : 0;
            }
            if (!$others) {
                throw new \RuntimeException('Este é o único perfil que administra o plugin: mantenha a administração em ao menos um perfil.', 422);
            }
        }
        if (countElementsInTable('glpi_profilerights', ['profiles_id' => $pid, 'name' => Escalation::$rightname])) {
            $DB->update('glpi_profilerights', ['rights' => $new], ['profiles_id' => $pid, 'name' => Escalation::$rightname]);
        } else {
            $DB->insert('glpi_profilerights', ['profiles_id' => $pid, 'name' => Escalation::$rightname, 'rights' => $new]);
        }
        if ($pid === (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0)) {
            $_SESSION['glpiactiveprofile'][Escalation::$rightname] = $new; // vale já na sessão aberta
        }
        unset($_SESSION['glpimenu']);
        $added = $removed = [];
        foreach ($map as $k => $m) {
            if (($new & $m[2]) && !($old & $m[2])) {
                $added[] = $m[0];
            } elseif (!($new & $m[2]) && ($old & $m[2])) {
                $removed[] = $m[0];
            }
        }
        $names = self::profiles(true);
        Log::write(null, 'system', ['status' => 'info', 'users_id' => $by, 'actor' => 'user', 'target_type' => 'profile', 'target_id' => $pid,
            'message' => 'Direitos do perfil "' . ($names[$pid] ?? ('#' . $pid)) . '" alterados: ' . ($added ? '+' . implode(', +', $added) : '') . ($added && $removed ? ' ' : '') . ($removed ? '−' . implode(', −', $removed) : '') . '.',
            'detail' => ['antes' => $old, 'depois' => $new, 'adicionados' => $added, 'removidos' => $removed]]);
        return true;
    }

    /** Direitos do plugin de um perfil (bits). */
    public static function profileBits(int $pid): int
    {
        global $DB;
        foreach ($DB->request(['SELECT' => 'rights', 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => Escalation::$rightname]]) as $r) {
            return (int) $r['rights'];
        }
        return 0;
    }

    /** O perfil cobre a capacidade (só pelos direitos dele)? Usado na matriz da tela de permissões. */
    public static function profileHas(int $bits, string $cap): bool
    {
        return match ($cap) {
            'manage' => (bool) ($bits & Escalation::MANAGE),
            'create' => (bool) ($bits & CREATE),
            default  => isset(self::caps()[$cap]) && self::coreHas($bits, $cap),
        };
    }

    private static function ids(string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }
}
