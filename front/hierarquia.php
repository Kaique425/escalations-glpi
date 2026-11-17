<?php

use GlpiPlugin\Escalations\Api;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Ui;

Menu::guard(false, true);
global $DB;
$self = Menu::url('/front/hierarquia.php');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$policy = $id ? Policy::get($id) : null;
if ($id && !$policy) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

// ------------------------------------------------------------------ gravação (o redirecionamento fica fora do try/catch)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $go = $id ? $self . '?id=' . $id : $self;
    $msg = '';
    $err = '';
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'policy_save':
                $id = Policy::save($_POST, $id);
                $go = $self . '?id=' . $id;
                $msg = 'Hierarquia salva.' . (Policy::levels($id) ? '' : ' Agora cadastre os níveis.');
                break;
            case 'policy_delete':
                Policy::delete($id);
                $go = Menu::url('/front/hierarquias.php');
                $msg = 'Hierarquia excluída.';
                break;
            case 'level_save':
                Policy::saveLevel($id, $_POST, (int) ($_POST['level_id'] ?? 0));
                $msg = 'Nível salvo.';
                break;
            case 'level_delete':
                Policy::deleteLevel((int) ($_POST['level_id'] ?? 0));
                $msg = 'Nível excluído.';
                break;
            case 'rule_save':
                Policy::saveRule($id, $_POST, (int) ($_POST['rule_id'] ?? 0));
                $msg = 'Regra salva.';
                break;
            case 'rule_delete':
                Policy::deleteRule((int) ($_POST['rule_id'] ?? 0));
                $msg = 'Regra excluída.';
                break;
            default:
                throw new RuntimeException('Ação desconhecida.', 422);
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    Session::addMessageAfterRedirect($err !== '' ? $err : $msg, false, $err !== '' ? ERROR : INFO);
    Html::redirect($go);
}

// ------------------------------------------------------------------ tela
Menu::header($policy ? $policy['name'] : 'Nova hierarquia', 'hierarquias');
$p = $policy ?: ['name' => '', 'area' => '', 'description' => '', 'is_active' => 1, 'users_id_owner' => 0, 'ack_slo_min' => 15, 'resolve_slo_min' => 240, 'slo_target' => 95,
    'slo_window_days' => 30, 'slo_warn_pct' => 80, 'repeat_min' => 15, 'max_repeats' => 3];
$users = Ui::users();
$grp = Ui::groups();

echo "<div class='esc-dash'>";
echo Ui::title($policy ? $policy['name'] : 'Nova hierarquia', 'Quem é acionado, em que ordem e em quanto tempo sobe, por quais canais, e os SLOs de ACK e resolução.',
    Ui::button('Voltar', Menu::url('/front/hierarquias.php'), 'ti-arrow-left', 'btn-outline-secondary'));

// diagrama em degraus
if ($policy) {
    $levels = Policy::levels($id);
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Cadeia de escalonamento</h3></div><div class='card-body'>";
    if (!$levels) {
        echo "<div class='esc-todo'>Esta hierarquia ainda não tem níveis. Cadastre o primeiro nível abaixo: sem nível, nada é acionado.</div>";
    }
    echo "<div class='esc-steps'>";
    foreach ($levels as $pos => $l) {
        $icons = '';
        foreach ($l['channel_list'] as $ch) {
            $icons .= "<i class='ti " . Ui::e(Policy::CHANNELS[$ch][1]) . "' title='" . Ui::e(Policy::CHANNELS[$ch][0]) . "'></i> ";
        }
        echo "<div class='esc-step'><div class='n'>N" . (int) $pos . '</div><div class="t">' . Ui::e($l['name']) . '</div><div class="m">' . Ui::e(implode(', ', Policy::targetNames($l)) ?: '—') . '</div>'
            . '<div class="m">' . $icons . '· sobe em ' . (int) $l['wait_min'] . ' min</div></div>';
    }
    if ($levels) {
        echo "<div class='esc-step end'><div class='n'><i class='ti ti-repeat'></i></div><div class='t'>Último nível</div><div class='m'>repete a cada " . (int) $p['repeat_min'] . ' min, até ' . (int) $p['max_repeats'] . 'x; depois avisa o dono</div></div>';
    }
    echo '</div></div></div>';
}

// dados da hierarquia e SLO
echo Ui::formOpen($self . ($id ? '?id=' . $id : ''), 'card mb-3') . "<input type='hidden' name='action' value='policy_save'><input type='hidden' name='id' value='" . $id . "'>"
    . "<div class='card-header'><h3 class='card-title'>Dados e SLO</h3></div><div class='card-body'><div class='row g-3'>"
    . Ui::field('Nome', Ui::input('name', $p['name'], 'text', "required maxlength='255'"), 'col-md-5')
    . Ui::field('Área', Ui::input('area', $p['area'], 'text', "maxlength='100' placeholder='ex.: NOC, Infra, Segurança'"), 'col-md-3')
    . Ui::field('Dono da hierarquia (recebe os avisos de SLO)', Ui::select('users_id_owner', $users, $p['users_id_owner'], '', '— ninguém —'), 'col-md-4')
    . Ui::field('Descrição', "<textarea class='form-control' name='description' rows='2'>" . Ui::e($p['description']) . '</textarea>', 'col-12')
    . Ui::field('SLO de ACK (minutos)', Ui::input('ack_slo_min', $p['ack_slo_min'], 'number', "min='1'"), 'col-md-3', 'Prazo para alguém assumir.')
    . Ui::field('SLO de resolução (minutos)', Ui::input('resolve_slo_min', $p['resolve_slo_min'], 'number', "min='1'"), 'col-md-3', 'Prazo para resolver.')
    . Ui::field('Meta de cumprimento (%)', Ui::input('slo_target', $p['slo_target'], 'number', "min='50' max='100' step='0.1'"), 'col-md-2', 'Ex.: 95 = 95% dentro do prazo.')
    . Ui::field('Janela (dias)', Ui::input('slo_window_days', $p['slo_window_days'], 'number', "min='1' max='365'"), 'col-md-2')
    . Ui::field('Alertar com (% do prazo)', Ui::input('slo_warn_pct', $p['slo_warn_pct'], 'number', "min='10' max='99'"), 'col-md-2', 'Aviso antes de estourar.')
    . Ui::field('Repetir o último nível a cada (min)', Ui::input('repeat_min', $p['repeat_min'], 'number', "min='1'"), 'col-md-3')
    . Ui::field('Repetições máximas', Ui::input('max_repeats', $p['max_repeats'], 'number', "min='0' max='50'"), 'col-md-3')
    . "<div class='col-md-3'><label class='form-label'>&nbsp;</label>" . Ui::check('is_active', (bool) $p['is_active'], 'Hierarquia ativa') . '</div>'
    . "</div></div><div class='card-footer d-flex justify-content-between'><div>";
if ($policy) {
    echo Ui::submit('Excluir hierarquia', 'action', 'policy_delete', 'ti-trash', 'btn-outline-danger', "data-confirm='Excluir esta hierarquia, seus níveis e regras?'");
}
echo '</div>' . Ui::submit('Salvar', 'action', 'policy_save', 'ti-device-floppy') . '</div>';
Ui::closeForm();

if (!$policy) {
    echo '</div>';
    Menu::footer();
    return;
}

// ------------------------------------------------------------------ níveis
$channelsBox = function (array $selected): string {
    $h = '';
    foreach (Policy::CHANNELS as $k => [$label, $icon]) {
        $h .= Ui::check('channels[]', in_array($k, $selected, true), $label, $k);
    }
    return $h;
};
$levelForm = function (?array $l) use ($self, $id, $users, $grp, $channelsBox): void {
    $t = $l['targets'] ?? [];
    $of = fn (string $type, string $role = 'act') => array_map(fn ($x) => (int) $x['items_id'], array_filter($t, fn ($x) => $x['type'] === $type && ($x['role'] ?? 'act') === $role));
    $hasSuper = fn (string $role) => (bool) array_filter($t, fn ($x) => $x['type'] === 'supervisor' && ($x['role'] ?? 'act') === $role);
    echo Ui::formOpen($self . '?id=' . $id) . "<input type='hidden' name='id' value='" . $id . "'><input type='hidden' name='level_id' value='" . (int) ($l['id'] ?? 0) . "'><input type='hidden' name='action' value='level_save'>"
        . "<div class='row g-3'>"
        . Ui::field('Nome do nível', Ui::input('name', $l['name'] ?? '', 'text', "required maxlength='100' placeholder='ex.: N2 Infra'"), 'col-md-4')
        . Ui::field('Posição', Ui::input('position', $l['position'] ?? '', 'number', "min='1' placeholder='auto'"), 'col-md-2')
        . Ui::field('Sobe em (min sem ACK)', Ui::input('wait_min', $l['wait_min'] ?? 15, 'number', "min='1'"), 'col-md-3')
        . "<div class='col-md-3'><label class='form-label'>Canais</label>" . $channelsBox($l['channel_list'] ?? ['email', 'popup']) . '</div>'
        . "<div class='col-12'><div class='esc-sec'>Escalados: recebem os avisos e <b>podem agir</b> (assumir, resolver, silenciar)</div></div>"
        . Ui::field('Usuários', Ui::multi('users', $users, $of('user'), 7), 'col-md-4', 'Segure Ctrl/Cmd para vários.')
        . Ui::field('Grupos (todos os membros)', Ui::multi('groups', $grp, $of('group'), 7), 'col-md-4')
        . Ui::field('Gestores dos grupos', Ui::multi('groupmgrs', $grp, $of('groupmgr'), 7), 'col-md-4', 'Membros marcados como gestor no grupo.')
        . "<div class='col-12'>" . Ui::check('supervisor', $hasSuper('act'), 'Incluir o gestor (supervisor) de quem já foi acionado nos níveis anteriores e do responsável do chamado') . '</div>'
        . "<div class='col-12'><details " . ($of('user', 'notify') || $of('group', 'notify') || $of('groupmgr', 'notify') || $hasSuper('notify') ? 'open' : '') . "><summary class='esc-sec' style='cursor:pointer'>Somente notificados: recebem os avisos, mas <b>não agem</b> (informativo) — clique para abrir</summary><div class='row g-3 mt-1'>"
        . Ui::field('Usuários (só notificar)', Ui::multi('users_notify', $users, $of('user', 'notify'), 6), 'col-md-4', 'Direção, clientes internos, quem só precisa saber. Quem estiver nas duas listas fica como escalado.')
        . Ui::field('Grupos (só notificar)', Ui::multi('groups_notify', $grp, $of('group', 'notify'), 6), 'col-md-4')
        . Ui::field('Gestores dos grupos (só notificar)', Ui::multi('groupmgrs_notify', $grp, $of('groupmgr', 'notify'), 6), 'col-md-4')
        . "<div class='col-12'>" . Ui::check('supervisor_notify', $hasSuper('notify'), 'Notificar também o gestor (supervisor), sem poder de agir') . '</div>'
        . '</div></details></div>'
        . '</div><div class="mt-3 d-flex gap-2">' . Ui::submit('Salvar nível', 'action', 'level_save', 'ti-device-floppy');
    if ($l) {
        echo Ui::submit('Excluir nível', 'action', 'level_delete', 'ti-trash', 'btn-outline-danger', "data-confirm='Excluir este nível?'");
    }
    echo '</div>';
    Ui::closeForm();
};
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Níveis</h3></div><div class='card-body'>";
foreach (Policy::levels($id) as $pos => $l) {
    echo "<details class='esc-lvl mb-2'><summary><b>N" . (int) $pos . '</b> · ' . Ui::e($l['name']) . " <span class='text-secondary small'>— " . Ui::e(implode(', ', Policy::targetNames($l))) . ' · sobe em ' . (int) $l['wait_min'] . ' min</span></summary><div class="p-3">';
    $levelForm($l);
    echo '</div></details>';
}
echo "<details class='esc-lvl' " . (Policy::levels($id) ? '' : 'open') . "><summary><i class='ti ti-plus me-1'></i><b>Adicionar nível</b></summary><div class='p-3'>";
$levelForm(null);
echo '</div></details></div></div>';

// ------------------------------------------------------------------ regras
$cats = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'ORDER' => 'completename', 'LIMIT' => 1000]) as $c) {
    $cats[(int) $c['id']] = $c['completename'];
}
$types = [];
foreach ($DB->request(['FROM' => Api::SOURCES, 'ORDER' => ['plugin_key', 'type_key']]) as $t) {
    $types[$t['plugin_key'] . '|' . $t['type_key']] = $t['plugin_key'] . ' · ' . ($t['label'] ?: $t['type_key']);
}
$ruleForm = function (?array $r) use ($self, $id, $cats, $types): void {
    $r ??= ['id' => 0, 'name' => '', 'source' => 'any', 'plugin_key' => '', 'type_key' => '', 'severity_min' => 1, 'entities_id' => -1, 'itilcategories_id' => 0, 'match_text' => '', 'sla_pct' => 80, 'unassigned_min' => 0, 'is_active' => 1];
    echo Ui::formOpen($self . '?id=' . $id) . "<input type='hidden' name='id' value='" . $id . "'><input type='hidden' name='rule_id' value='" . (int) $r['id'] . "'><input type='hidden' name='action' value='rule_save'>"
        . "<div class='row g-3'>"
        . Ui::field('Nome da regra', Ui::input('name', $r['name'], 'text', "maxlength='150'"), 'col-md-4')
        . Ui::field('Origem', Ui::select('source', array_filter(Policy::SOURCES, fn ($label, $k) => $k === 'any' || $k === $r['source'] || Settings::sourceOn($k), ARRAY_FILTER_USE_BOTH), $r['source']), 'col-md-2', 'Só aparecem as origens ativas nas Configurações.')
        . Ui::field('Severidade mínima', Ui::select('severity_min', array_map(fn ($s) => $s[0], Escalation::SEVERITIES), $r['severity_min']), 'col-md-3', 'Chamado: prioridade. Evento: severidade.')
        . Ui::field('Entidade (e filhas)', Ui::select('entities_id', Ui::entities(), (int) $r['entities_id'] >= 0 ? $r['entities_id'] : '', '', 'Todas as entidades'), 'col-md-3')
        . Ui::field('Categoria do chamado', Ui::select('itilcategories_id', $cats, $r['itilcategories_id'] ?: '', '', 'Todas'), 'col-md-4')
        . Ui::field('Título contém', Ui::input('match_text', $r['match_text'], 'text', "maxlength='255' placeholder='opcional'"), 'col-md-4')
        . "<div class='col-md-4'><label class='form-label'>&nbsp;</label>" . Ui::check('is_active', (bool) $r['is_active'], 'Regra ativa') . '</div>'
        . Ui::field('Tipo do plugin (só para origem "Item de plugin")', Ui::select('plugin_type', $types, $r['plugin_key'] !== '' ? $r['plugin_key'] . '|' . $r['type_key'] : '', '', 'Qualquer plugin e tipo'), 'col-md-12',
            $types ? 'Os tipos aparecem aqui quando o plugin os registra ou os usa pela primeira vez.' : 'Nenhum plugin registrou tipos ainda.')
        . Ui::field('Escalar ao consumir X% do prazo (chamado, problema, mudança, projeto, tarefa, item de plugin)', Ui::input('sla_pct', $r['sla_pct'], 'number', "min='0' max='100'"), 'col-md-6', '0 = não usar. 100 = só ao estourar. Em item de plugin sem percentual, abre ao vencer.')
        . Ui::field('Chamado: escalar se ficar sem responsável por X min', Ui::input('unassigned_min', $r['unassigned_min'], 'number', "min='0'"), 'col-md-6', '0 = não usar. Vale só para chamados.')
        . '</div><div class="mt-3 d-flex gap-2">' . Ui::submit('Salvar regra', 'action', 'rule_save', 'ti-device-floppy');
    if ($r['id']) {
        echo Ui::submit('Excluir regra', 'action', 'rule_delete', 'ti-trash', 'btn-outline-danger', "data-confirm='Excluir esta regra?'");
    }
    echo '</div>';
    Ui::closeForm();
};
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Regras: quando esta hierarquia vale</h3></div><div class='card-body'>"
    . "<div class='small text-secondary mb-2'>Eventos (webhook) usam origem, severidade, entidade e título. Chamados, problemas e mudanças também usam categoria; todos os itens com prazo usam o percentual do prazo; só chamados usam “sem responsável”. Se várias regras casarem, vale a mais específica.</div>";
foreach (Policy::rules($id) as $r) {
    echo "<details class='esc-lvl mb-2'><summary><b>" . Ui::e($r['name']) . '</b> <span class="text-secondary small">— ' . Ui::e(Policy::SOURCES[$r['source']] ?? $r['source']) . ($r['type_key'] !== '' ? ' ' . Ui::e($r['plugin_key'] . ' · ' . $r['type_key']) : '') . ', severidade ≥ ' . Ui::e(Escalation::SEVERITIES[(int) $r['severity_min']][0])
        . ((int) $r['sla_pct'] ? ', SLA ' . (int) $r['sla_pct'] . '%' : '') . ((int) $r['unassigned_min'] ? ', sem responsável ' . (int) $r['unassigned_min'] . ' min' : '') . ((int) $r['is_active'] ? '' : ' (inativa)') . '</span></summary><div class="p-3">';
    $ruleForm($r);
    echo '</div></details>';
}
echo "<details class='esc-lvl'><summary><i class='ti ti-plus me-1'></i><b>Adicionar regra</b></summary><div class='p-3'>";
$ruleForm(null);
echo '</div></details></div></div>';
echo '</div>';
Menu::footer();
