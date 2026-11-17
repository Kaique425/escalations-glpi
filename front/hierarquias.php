<?php

use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;

Menu::guard(false, true);
Menu::header('Hierarquias', 'hierarquias');
global $DB;

$groups = [];
foreach (Policy::all() as $p) {
    $levels = Policy::levels((int) $p['id']);
    $rules = count(Policy::rules((int) $p['id']));
    $open = countElementsInTable(Escalation::TABLE, ['policies_id' => $p['id'], 'state' => ['open', 'acked']]);
    $names = array_map(fn ($l) => 'N' . $l['position'] . ' ' . $l['name'], $levels);
    $groups[$p['area'] !== '' ? $p['area'] : 'Sem área'][] = [
        'name' => $p['name'], 'sub' => $levels ? implode(' → ', $names) : 'Nenhum nível cadastrado', 'url' => Menu::url('/front/hierarquia.php?id=' . (int) $p['id']),
        'cells' => [
            [Ui::e((string) count($levels)), 'esc-cell-val'],
            [Ui::e((int) $p['ack_slo_min'] . ' min'), 'esc-cell-val'],
            [Ui::e((int) $p['resolve_slo_min'] . ' min'), 'esc-cell-val'],
            [Ui::e(number_format((float) $p['slo_target'], 0) . '%'), 'esc-cell-val'],
            [Ui::e($rules . ' regra(s)'), 'esc-cell-val'],
            [(int) $p['users_id_owner'] ? Ui::avatar(Ui::userName((int) $p['users_id_owner'])) : Ui::avatar(''), ''],
            [Ui::stCell($open ? $open . ' aberto(s)' : ((int) $p['is_active'] ? 'Ativa' : 'Inativa'), $open ? '#ef4444' : ((int) $p['is_active'] ? '#22c55e' : '#94a3b8')), 'esc-cell-st'],
        ],
    ];
}
$tableGroups = [];
$palette = ['#6366f1', '#14b8a6', '#f97316', '#ec4899', '#0ea5e9', '#84cc16'];
$i = 0;
foreach ($groups as $area => $rows) {
    $tableGroups[] = ['title' => $area, 'color' => $palette[$i++ % count($palette)], 'rows' => $rows];
}

echo "<div class='esc-dash'>";
echo Ui::title('Hierarquias', 'Cada hierarquia define os níveis (quem é acionado e em quanto tempo sobe), as regras de quando vale e os SLOs de ACK e resolução.',
    Ui::button('Nova hierarquia', Menu::url('/front/hierarquia.php'), 'ti-plus'));
echo Ui::mainTable([['Níveis', '90px'], ['SLO de ACK', '110px'], ['SLO resolução', '130px'], ['Meta', '90px'], ['Regras', '110px'], ['Dono', '80px'], ['Situação', '130px']],
    $tableGroups, 'Nenhuma hierarquia ainda. Crie a primeira para começar a escalar.');
echo '</div>';
Menu::footer();
