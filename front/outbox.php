<?php

use GlpiPlugin\Escalations\Drawer;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Outbox;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Settings;
use GlpiPlugin\Escalations\Ui;
use GlpiPlugin\Escalations\Views;

Menu::guard(false, false, 'logs');
Menu::header('Mensagens enviadas', 'outbox');

$f = [];
foreach (['q', 'channel', 'status'] as $k) {
    $f[$k] = trim((string) ($_GET[$k] ?? ''));
}
$page = max(1, (int) ($_GET['page'] ?? 1));
[$rows, $total] = Outbox::search($f, $page, 30);
$chs = array_map(fn ($c) => $c[0], Policy::CHANNELS);
unset($chs['popup']);
$status = ['sent' => ['Enviada', '#22c55e'], 'sim' => ['Simulada', '#6366f1'], 'failed' => ['Falhou', '#ef4444']];

echo "<div class='esc-dash'>";
echo Ui::title('Mensagens enviadas', 'A cópia exata de cada e-mail, cartão do Teams e mensagem de WhatsApp enviados pelos escalonamentos. Clique numa linha para ver o conteúdo.');
echo Views::engineBanner();
if (Settings::get('sink') !== '') {
    echo "<div class='alert alert-info'><div><i class='ti ti-flask me-1'></i><b>Modo simulação ativo</b> para: " . Ui::e(implode(', ', array_map(fn ($c) => Policy::CHANNELS[$c][0], Settings::channels(Settings::get('sink')))))
        . '. As mensagens são montadas e guardadas aqui, mas <b>não são enviadas de verdade</b>.</div></div>';
}
if (!Settings::bool('outbox')) {
    echo "<div class='alert alert-warning'><div>A Caixa de saída está desligada em Configurações: novas mensagens não são guardadas.</div></div>";
}
echo "<form method='get' class='card card-body mb-3'><div class='esc-filters'>"
    . Ui::filter('Busca', Ui::input('q', $f['q'], 'text', "placeholder='rastreio, assunto ou destinatário'"), 'min-width:240px')
    . Ui::filter('Canal', Ui::select('channel', $chs, $f['channel'], '', 'Todos'))
    . Ui::filter('Situação', Ui::select('status', array_map(fn ($s) => $s[0], $status), $f['status'], '', 'Todas'))
    . "<div><button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></div></div></form>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . (int) $total . " mensagem(ns)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm table-hover'>"
    . '<thead><tr><th>Quando</th><th>Rastreio</th><th>Nível</th><th>Canal</th><th>Para</th><th>Assunto / conteúdo</th><th>Situação</th></tr></thead><tbody>';
foreach ($rows as $m) {
    [$sl, $sc] = $status[$m['status']] ?? [$m['status'], '#64748b'];
    $key = 'm' . (int) $m['id'];
    $isMail = $m['channel'] === 'email' && $m['body_html'] !== '';
    $body = $isMail
        ? "<iframe class='esc-mail' sandbox='' srcdoc='" . Ui::e($m['body_html']) . "'></iframe>"
        : "<div class='esc-wa'>" . nl2br(Ui::e($m['body_text'])) . '</div>';
    Drawer::add($key, $m['subject'] !== '' ? $m['subject'] : (Policy::CHANNELS[$m['channel']][0] ?? $m['channel']), Policy::CHANNELS[$m['channel']][1] ?? 'ti-send', $sc,
        Drawer::item('Para', $m['recipient'] !== '' ? $m['recipient'] : '—', Ui::badge($sl, $sc), '', $sc)
        . Drawer::item('Quando', Ui::dtSec($m['date_creation']), $m['trace_id'] . ($m['level'] ? ' · N' . (int) $m['level'] : ''), '', '#64748b')
        . ($m['error'] ? Drawer::lead('Erro: ' . $m['error']) : '')
        . $body
        . Drawer::actions(array_filter([[$m['escalations_id'] ? 'Abrir escalonamento' : '', $m['escalations_id'] ? Escalation::url((int) $m['escalations_id']) : '', 'ti-external-link', 'btn-primary']], fn ($b) => $b[0] !== '')));
    $preview = $m['subject'] !== '' ? $m['subject'] : mb_substr(preg_replace('/\s+/', ' ', (string) $m['body_text']), 0, 110);
    echo "<tr class='esc-click' data-drawer='$key' tabindex='0'><td class='text-nowrap small'>" . Ui::e(Ui::dtSec($m['date_creation'])) . '</td><td class="small"><code>' . Ui::e($m['trace_id']) . '</code></td>'
        . '<td>' . ($m['level'] ? 'N' . (int) $m['level'] : '—') . '</td><td><i class="ti ' . Ui::e(Policy::CHANNELS[$m['channel']][1] ?? 'ti-send') . ' me-1"></i>' . Ui::e(Policy::CHANNELS[$m['channel']][0] ?? $m['channel']) . '</td>'
        . '<td class="small">' . Ui::e($m['recipient'] !== '' ? $m['recipient'] : '—') . '</td><td>' . Ui::e($preview) . '</td><td>' . Ui::badge($sl, $sc) . '</td></tr>';
}
echo $rows ? '' : "<tr><td colspan='7' class='text-center text-secondary'>Nenhuma mensagem guardada ainda.</td></tr>";
echo '</tbody></table></div>' . Ui::pager($total, $page, 30, $f) . '</div></div>';
Menu::footer();
