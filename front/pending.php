<?php

/**
 * Consulta dos pop-ups: devolve em JSON os escalonamentos que estão pedindo ACK ao usuário (e os avisos de SLO).
 * Chamada pelo public/js/escalations.js. Sem sessão: 401; sem direito ou pop-up desligado: {"disabled": true}.
 */

use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Settings;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uid = (int) Session::getLoginUserID();
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['disabled' => true]);
    return;
}
if (!Escalation::canView() || !Settings::bool('popup_enabled')) {
    echo json_encode(['disabled' => true]);
    return;
}
echo json_encode([
    'interval' => Settings::pollSeconds(),
    'sound'    => Settings::bool('popup_sound'),
    'items'    => Dispatcher::pending($uid, 5),
], JSON_UNESCAPED_UNICODE);
