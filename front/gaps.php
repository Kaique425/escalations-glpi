<?php

use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Gaps;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard(false, false, 'slo');
Menu::header('Análise de gaps', 'gaps');

$days = (int) ($_GET['dias'] ?? 30);
$days = in_array($days, [7, 30, 90, 180, 365], true) ? $days : 30;
$policy = (int) ($_GET['policy'] ?? 0);
$d = Gaps::load($days, $policy);
$cov = Escalation::canManage() || Escalation::canAll() ? Gaps::coverage($days) : [];
$total = max(1, $d['total']);
$pct = fn (float $v) => number_format($v, 0, ',', '') . '%';
$sevColor = ['danger' => '#ef4444', 'warning' => '#f59e0b', 'info' => '#3b82f6'];
$sevIcon = ['danger' => 'ti-circle-x', 'warning' => 'ti-alert-triangle', 'info' => 'ti-info-circle'];
$bar = fn (float $p, string $c, string $label) => "<div class='d-flex align-items-center gap-2 mb-1'><div style='flex:1'>" . Ui::bar($p, $c) . "</div><div class='small' style='min-width:210px'>" . $label . '</div></div>';

$reachedN2 = $d['reached'][2] ?? 0;
$dangers = count(array_filter($cov, fn ($c) => $c[0] === 'danger'));

echo "<div class='esc-dash'>";
echo Ui::title('Análise de gaps', 'Onde a escalada perde tempo, quem não atende, que canal não entrega, o que só faz ruído e que buracos existem na configuração.',
    Ui::button('SLO e SLA', Menu::url('/front/slo.php'), 'ti-gauge', 'btn-outline-secondary'));
echo Views::engineBanner();

echo "<form method='get' class='card card-body mb-3'><div class='esc-filters'>"
    . Ui::filter('Período', Ui::select('dias', [7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 180 => '180 dias', 365 => '1 ano'], $days))
    . Ui::filter('Hierarquia', Ui::select('policy', array_column(Policy::all(), 'name', 'id'), $policy ?: '', '', 'Todas'))
    . "<div><button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></div></div></form>";

echo "<div class='esc-kpis'>"
    . Ui::kpi('Escalonamentos', (string) $d['total'], 'no período', '#6366f1')
    . Ui::kpi('Passaram do N1', $pct($reachedN2 / $total * 100), $reachedN2 . ' subiram ao menos um nível', $reachedN2 / $total > 0.4 ? '#f59e0b' : '#22c55e')
    . Ui::kpi('Cadeia esgotada', (string) $d['exhausted'], 'ninguém assumiu em nenhum nível', $d['exhausted'] ? '#ef4444' : '#22c55e')
    . Ui::kpi('Resolvidos sem ACK', (string) $d['no_ack_resolved'], 'ninguém assumiu e resolveu sozinho', $d['no_ack_resolved'] ? '#f59e0b' : '#22c55e')
    . Ui::kpi('SLO de ACK estourado', (string) $d['ack_breaches'], $pct($d['ack_breaches'] / $total * 100) . ' do total', $d['ack_breaches'] ? '#ef4444' : '#22c55e')
    . Ui::kpi('Buracos de cobertura', (string) count($cov), $dangers . ' crítico(s)', $dangers ? '#ef4444' : (count($cov) ? '#f59e0b' : '#22c55e'))
    . '</div>';

// cobertura
if (Escalation::canManage() || Escalation::canAll()) {
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Buracos de cobertura (configuração hoje)</h3></div><div class='card-body'>";
    if (!$cov) {
        echo "<div class='text-secondary'><i class='ti ti-circle-check text-success me-1'></i>Nenhum buraco encontrado: todo nível tem alguém ativo, os canais estão prontos e os chamados críticos têm regra.</div>";
    }
    foreach ($cov as [$sev, $text, $url]) {
        echo "<a class='esc-gap' style='--c:" . $sevColor[$sev] . "' href='" . Ui::e($url) . "'><i class='ti " . $sevIcon[$sev] . "'></i><span>" . Ui::e($text) . '</span></a>';
    }
    echo '</div></div>';
}

// funil por nível
echo "<div class='row g-3 mb-3'><div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Onde a escalada para</h3></div><div class='card-body'>";
if (!$d['total']) {
    echo "<div class='text-secondary'>Sem escalonamentos no período.</div>";
}
foreach ($d['reached'] as $k => $n) {
    $acked = $d['acked_at'][$k] ?? 0;
    echo $bar($n / $total * 100, '#6366f1', '<b>Chegou ao N' . (int) $k . '</b> · ' . (int) $n . ' (' . $pct($n / $total * 100) . ') · ACK aqui: <b>' . (int) $acked . '</b>');
}
if ($d['exhausted']) {
    echo $bar($d['exhausted'] / $total * 100, '#ef4444', '<b>Esgotou a cadeia</b> · ' . (int) $d['exhausted']);
}
echo "<div class='small text-secondary mt-2'>Se quase todo ACK acontece só no N2 ou depois, o N1 está só atrasando a escalada: revise quem está nele ou encurte a espera.</div></div></div></div>";

// tempo por nível
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Tempo por nível</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'>"
    . "<thead><tr><th>Nível</th><th>ACKs</th><th>Tempo médio até o ACK</th><th>Espera média antes de subir</th><th>Configurado</th></tr></thead><tbody>";
$cfg = $policy ? Policy::levels($policy) : [];
$maxLevel = max([0] + array_keys($d['reached']));
for ($k = 1; $k <= $maxLevel; $k++) {
    $w = $d['escalate_wait'][$k] ?? [];
    echo '<tr><td><b>N' . $k . '</b></td><td>' . (int) ($d['acked_at'][$k] ?? 0) . '</td><td>' . Ui::e(Ui::dur(!empty($d['ack_n'][$k]) ? (int) round($d['ack_sum'][$k] / $d['ack_n'][$k]) : null)) . '</td>'
        . '<td>' . Ui::e(Ui::dur($w ? (int) round(array_sum($w) / count($w)) : null)) . '</td><td>' . (isset($cfg[$k]) ? (int) $cfg[$k]['wait_min'] . ' min' : '—') . '</td></tr>';
}
echo $maxLevel ? '' : "<tr><td colspan='5' class='text-center text-secondary'>Sem dados.</td></tr>";
echo '</tbody></table></div></div></div></div>';

// mapa de calor
$maxCell = 1;
foreach ($d['heat'] as $row) {
    foreach ($row as $cell) {
        $maxCell = max($maxCell, $cell[0]);
    }
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Quando abre e quando estoura (dia da semana × hora)</h3></div><div class='card-body'><div class='table-responsive'><table class='esc-heat'><thead><tr><th></th>";
for ($h = 0; $h < 24; $h++) {
    echo '<th>' . $h . '</th>';
}
echo '</tr></thead><tbody>';
foreach (Gaps::WEEKDAYS as $dow => $label) {
    echo '<tr><th>' . $label . '</th>';
    for ($h = 0; $h < 24; $h++) {
        $c = $d['heat'][$dow][$h] ?? [0, 0];
        if (!$c[0]) {
            echo '<td></td>';
            continue;
        }
        $color = $c[1] > 0 ? '#ef4444' : '#3b82f6';
        $alpha = (int) round(15 + 75 * ($c[0] / $maxCell));
        echo "<td style='background:color-mix(in srgb, $color {$alpha}%, transparent)' title='" . Ui::e($label . ' ' . $h . 'h: ' . $c[0] . ' aberto(s), ' . $c[1] . ' com SLO de ACK estourado') . "'>" . (int) $c[0] . '</td>';
    }
    echo '</tr>';
}
echo "</tbody></table></div><div class='small text-secondary'>Azul: escalonamentos abertos naquela hora. Vermelho: pelo menos um estourou o SLO de ACK. Madrugada ou fim de semana vermelhos indicam falta de cobertura de plantão.</div></div></div>";

// pessoas
$people = $d['people'];
uasort($people, fn ($a, $b) => [$a['rate'], -$a['n']] <=> [$b['rate'], -$b['n']]);
echo "<div class='row g-3 mb-3'><div class='col-lg-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Quem foi acionado e não assumiu</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'>"
    . "<thead><tr><th>Pessoa</th><th>Acionado</th><th>Assumiu</th><th style='min-width:140px'>Taxa de ACK</th><th>Adiou</th></tr></thead><tbody>";
$shown = 0;
foreach ($people as $uid => $p) {
    if ($p['n'] < 2 || $shown++ >= 10) {
        continue;
    }
    echo '<tr><td>' . Ui::avatar(Ui::userName($uid)) . ' ' . Ui::e(Ui::userName($uid)) . '</td><td>' . (int) $p['n'] . '</td><td>' . (int) $p['acked'] . '</td><td>' . Ui::bar($p['rate'], $p['rate'] < 30 ? '#ef4444' : ($p['rate'] < 60 ? '#f59e0b' : '#22c55e'))
        . '<span class="small text-secondary">' . $pct($p['rate']) . '</span></td><td>' . (int) $p['snoozes'] . '</td></tr>';
}
echo $shown ? '' : "<tr><td colspan='5' class='text-center text-secondary'>Dados insuficientes (precisa de ao menos 2 acionamentos por pessoa).</td></tr>";
echo '</tbody></table></div><div class="card-footer small text-secondary">Taxa baixa não é culpa: pode ser alguém posto no nível errado, sem celular cadastrado ou de férias.</div></div></div>';

// causas dos estouros
echo "<div class='col-lg-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Por que o SLO de ACK estourou</h3></div><div class='card-body'>";
foreach ($d['causes'] as $cause => $n) {
    echo $bar($n / max(1, $d['ack_breaches']) * 100, $cause === 'Ninguém assumiu a tempo' ? '#f59e0b' : '#ef4444', '<b>' . (int) $n . '</b> · ' . Ui::e($cause));
}
echo $d['causes'] ? '' : "<div class='text-secondary'>Nenhum estouro de SLO de ACK no período.</div>";
echo '</div></div></div></div>';

// canais
echo "<div class='row g-3 mb-3'><div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Os canais entregam?</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'>"
    . "<thead><tr><th>Canal</th><th>Envios</th><th>Falhas</th><th>Atraso até exibir</th></tr></thead><tbody>";
foreach ($d['channels'] as $ch => $c) {
    $rate = $c['total'] ? $c['failed'] / $c['total'] * 100 : 0;
    arsort($c['errors']);
    $top = array_key_first($c['errors']);
    echo '<tr><td><i class="ti ' . Ui::e(Policy::CHANNELS[$ch][1] ?? 'ti-send') . ' me-1"></i>' . Ui::e(Policy::CHANNELS[$ch][0] ?? $ch) . '</td><td>' . (int) $c['total'] . '</td>'
        . '<td>' . ($c['failed'] ? Ui::badge($c['failed'] . ' (' . $pct($rate) . ')', $rate > 10 ? '#ef4444' : '#f59e0b') . ($top ? '<div class="small text-secondary">' . Ui::e($top) . '</div>' : '') : '<span class="text-secondary">0</span>') . '</td>'
        . '<td>' . ($c['lag_n'] ? Ui::e(Ui::dur((int) round($c['lag_sum'] / $c['lag_n']))) : '—') . '</td></tr>';
}
echo $d['channels'] ? '' : "<tr><td colspan='4' class='text-center text-secondary'>Sem envios no período.</td></tr>";
echo '</tbody></table></div><div class="card-footer small text-secondary">Canal reserva acionado ' . (int) ($d['fallbacks'] ?? 0) . ' vez(es). O atraso do pop-up é o tempo entre o envio e o usuário ver na tela.</div></div></div>';

// ruído
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>O que só faz ruído</h3></div><div class='card-body'>"
    . "<div class='esc-sec mb-2'>Mais repetidos (agrupados)</div>";
foreach ($d['noise'] as $eid => $n) {
    echo "<div class='d-flex gap-2 small mb-1'><span class='badge bg-secondary-lt'>" . (int) $n . '×</span><a href="' . Ui::e(Escalation::url((int) $eid)) . '">' . Ui::e($d['rows'][$eid]['title']) . '</a></div>';
}
echo $d['noise'] ? '' : "<div class='text-secondary small'>Nenhuma repetição agrupada.</div>";
echo "<div class='esc-sec mt-3 mb-2'>Resolvidos sem ninguém assumir</div>";
foreach ($d['ignored'] as $title => $n) {
    echo "<div class='d-flex gap-2 small mb-1'><span class='badge bg-warning-lt'>" . (int) $n . '×</span><span>' . Ui::e($title) . '</span></div>';
}
echo $d['ignored'] ? "<div class='small text-secondary mt-2'>Se resolve sozinho e ninguém assume, talvez não precise escalar: aumente a severidade mínima da regra.</div>" : "<div class='text-secondary small'>Nenhum.</div>";
echo '</div></div></div></div>';
echo '</div>';
Menu::footer();
