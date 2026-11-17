<?php

use GlpiPlugin\Escalations\Drawer;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Log;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;

Menu::guard(false, false, 'logs');
$self = Menu::url('/front/logs.php');

$f = [];
foreach (['trace', 'event', 'channel', 'status', 'from', 'to', 'q'] as $k) {
    $f[$k] = trim((string) ($_GET[$k] ?? ''));
}
$f['user'] = (int) ($_GET['user'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));

// quem não vê tudo só enxerga os logs dos escalonamentos que pode ver
$restricted = !Escalation::canAll();
if ($restricted) {
    $allowed = [];
    global $DB;
    foreach ($DB->request(['SELECT' => 'trace_id', 'FROM' => Escalation::TABLE, 'WHERE' => Escalation::visibility(), 'LIMIT' => 5000]) as $r) {
        $allowed[] = $r['trace_id'];
    }
    $f['traces'] = $allowed ?: ['-'];
}

// exportação CSV (respeita os filtros)
if (!empty($_GET['export'])) {
    [$all] = Log::search($f, 1, 5000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="escalations-logs-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['quando', 'rastreio', 'linha', 'evento', 'nivel', 'canal', 'situacao', 'mensagem', 'usuario', 'origem', 'ip', 'duracao_ms', 'hash'], ';');
    foreach ($all as $l) {
        fputcsv($out, [$l['date_creation'], $l['trace_id'], $l['seq'], $l['event'], $l['level'], $l['channel'], $l['status'], $l['message'], $l['users_id'] ? Ui::userName((int) $l['users_id']) : '', $l['actor'], $l['ip'], $l['duration_ms'], $l['hash']], ';');
    }
    fclose($out);
    return;
}

Menu::header('Logs rastreáveis', 'logs');
[$rows, $total] = Log::search($f, $page, 50);

echo "<div class='esc-dash'>";
echo Ui::title('Logs rastreáveis', 'Cada ação do plugin fica registrada com o código de rastreio do escalonamento. As linhas são encadeadas por hash: qualquer edição ou exclusão no banco é detectada.',
    Ui::button('Exportar CSV', $self . '?' . http_build_query(array_filter(array_diff_key($f, ['traces' => 1])) + ['export' => 1]), 'ti-download', 'btn-outline-secondary'));

$events = Log::EVENTS;
$chs = array_map(fn ($c) => $c[0], Policy::CHANNELS);
$sts = array_map(fn ($s) => $s[0], Log::STATUS);
echo "<form method='get' class='card card-body mb-3'><div class='esc-filters'>"
    . Ui::filter('Rastreio', Ui::input('trace', $f['trace'], 'text', "placeholder='ESC-261117-…'"), 'min-width:190px')
    . Ui::filter('Texto', Ui::input('q', $f['q'], 'text', "placeholder='mensagem ou detalhe'"), 'min-width:190px')
    . Ui::filter('Evento', Ui::select('event', $events, $f['event'], '', 'Todos'))
    . Ui::filter('Canal', Ui::select('channel', $chs, $f['channel'], '', 'Todos'))
    . Ui::filter('Situação', Ui::select('status', $sts, $f['status'], '', 'Todas'))
    . Ui::filter('Usuário', Ui::select('user', Ui::users(), $f['user'] ?: '', '', 'Todos'))
    . Ui::filter('De', Ui::input('from', $f['from'], 'date'))
    . Ui::filter('Até', Ui::input('to', $f['to'], 'date'))
    . "<div><button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></div></div></form>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . (int) $total . " linha(s)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm table-hover esc-log'>"
    . '<thead><tr><th>Quando</th><th>Rastreio</th><th>#</th><th>Evento</th><th>Canal</th><th>Situação</th><th>Mensagem</th><th>Quem</th></tr></thead><tbody>';
foreach ($rows as $l) {
    [$sl, $sc] = Log::STATUS[$l['status']] ?? [$l['status'], '#64748b'];
    $key = 'l' . (int) $l['id'];
    Drawer::add($key, (Log::EVENTS[$l['event']] ?? $l['event']) . ' · ' . $l['trace_id'] . ' #' . (int) $l['seq'], 'ti-file-search', $sc,
        Drawer::lead((string) $l['message'])
        . Drawer::item('Quando', Ui::dtSec($l['date_creation']), $l['duration_ms'] ? (int) $l['duration_ms'] . ' ms' : '', '', '#64748b')
        . Drawer::item('Quem', $l['users_id'] ? Ui::userName((int) $l['users_id']) : $l['actor'], $l['ip'], '', '#6366f1')
        . Drawer::item('Hash', substr((string) $l['hash'], 0, 40) . '…', '', '', '#64748b')
        . Drawer::item('Hash anterior', $l['prev_hash'] !== '' ? substr((string) $l['prev_hash'], 0, 40) . '…' : '(início da cadeia)', '', '', '#64748b')
        . ($l['detail'] ? '<div class="esc-dr-sec">Detalhe</div><pre class="esc-json">' . Ui::e(json_encode(json_decode((string) $l['detail'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>' : '')
        . Drawer::actions(array_filter([
            [$l['escalations_id'] ? 'Abrir escalonamento' : '', $l['escalations_id'] ? Escalation::url((int) $l['escalations_id']) : '', 'ti-external-link', 'btn-primary'],
            ['Só este rastreio', $self . '?trace=' . urlencode($l['trace_id']), 'ti-filter', 'btn-outline-secondary'],
        ], fn ($b) => $b[0] !== '')));
    echo "<tr class='esc-click' data-drawer='$key' tabindex='0'><td class='text-nowrap small'>" . Ui::e(Ui::dtSec($l['date_creation'])) . '</td>'
        . '<td class="small"><code>' . Ui::e($l['trace_id']) . '</code></td><td>' . (int) $l['seq'] . '</td>'
        . '<td>' . Ui::badge(Log::EVENTS[$l['event']] ?? $l['event'], $sc) . '</td>'
        . '<td>' . ($l['channel'] !== '' ? '<i class="ti ' . Ui::e(Policy::CHANNELS[$l['channel']][1] ?? 'ti-send') . '"></i> ' . Ui::e(Policy::CHANNELS[$l['channel']][0] ?? $l['channel']) : '') . '</td>'
        . '<td>' . Ui::badge($sl, $sc) . '</td><td>' . Ui::e($l['message']) . '</td><td class="small">' . Ui::e($l['users_id'] ? Ui::userName((int) $l['users_id']) : $l['actor']) . '</td></tr>';
}
echo $rows ? '' : "<tr><td colspan='8' class='text-center text-secondary'>Nenhuma linha com estes filtros.</td></tr>";
echo '</tbody></table></div>' . Ui::pager($total, $page, 50, array_diff_key($f, ['traces' => 1])) . '</div></div>';
Menu::footer();
