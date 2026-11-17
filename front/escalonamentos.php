<?php

use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard();
Menu::header('Escalonamentos', 'escalonamentos');

$f = [];
foreach (['state', 'source', 'q', 'from', 'to'] as $k) {
    $f[$k] = trim((string) ($_GET[$k] ?? ''));
}
$f['severity'] = (int) ($_GET['severity'] ?? 0);
$f['policy'] = (int) ($_GET['policy'] ?? 0);
$f['slo'] = !empty($_GET['slo']) ? 1 : 0;
$page = max(1, (int) ($_GET['page'] ?? 1));
[$rows, $total] = Escalation::search($f, $page, 50);

echo "<div class='esc-dash'>";
echo Ui::title('Escalonamentos', 'Todos os escalonamentos com situação, nível, prazo de ACK e SLO. Cada um tem código de rastreio e log próprio.',
    Escalation::canCreate() ? Ui::button('Escalar agora', Menu::url('/front/escalonamento.php?novo=1'), 'ti-urgent') : '');

$states = array_map(fn ($s) => $s[0], Escalation::STATES);
$sevs = array_map(fn ($s) => $s[0], Escalation::SEVERITIES);
$pol = array_column(Policy::all(), 'name', 'id');
echo "<form method='get' class='card card-body mb-3'><div class='esc-filters'>"
    . Ui::filter('Busca', Ui::input('q', $f['q'], 'text', "placeholder='título, rastreio ou origem'"), 'min-width:220px')
    . Ui::filter('Situação', Ui::select('state', $states, $f['state'], '', 'Todas'))
    . Ui::filter('Severidade', Ui::select('severity', $sevs, $f['severity'] ?: '', '', 'Todas'))
    . Ui::filter('Origem', Ui::select('source', Escalation::SOURCES, $f['source'], '', 'Todas'))
    . Ui::filter('Hierarquia', Ui::select('policy', $pol, $f['policy'] ?: '', '', 'Todas'))
    . Ui::filter('De', Ui::input('from', $f['from'], 'date'))
    . Ui::filter('Até', Ui::input('to', $f['to'], 'date'))
    . Ui::filter('SLO', Ui::check('slo', (bool) $f['slo'], 'só com SLO em risco/estourado'))
    . "<div><button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></div></div></form>";

echo "<div class='small text-secondary mb-2'>" . (int) $total . ' escalonamento(s)</div>';
echo Ui::mainTable(Views::COLS, Views::groups($rows), 'Nenhum escalonamento com estes filtros.');
echo Ui::pager($total, $page, 50, $f);
echo '</div>';
Menu::footer();
