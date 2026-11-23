<?php

/**
 * Webhook de eventos (Zabbix, Wazuh, qualquer sistema que poste JSON). Sem sessão do GLPI: a autenticação é o token
 * (cabeçalho X-Escalations-Token, Authorization: Bearer ou ?token=). Veja a tela de Configurações para o exemplo.
 */

use GlpiPlugin\Escalations\Dispatcher;
use GlpiPlugin\Escalations\EventIntake;
use GlpiPlugin\Escalations\Log;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'Use POST.']);
    return;
}
if (!EventIntake::authorized()) {
    usleep(300000); // desacelera tentativas de adivinhar o token
    Log::write(null, 'webhook', ['status' => 'warn', 'actor' => 'webhook', 'message' => 'Webhook recusado: token ausente ou inválido.']);
    $reply(401, ['ok' => false, 'error' => 'Token inválido.']);
    return;
}
$raw = (string) file_get_contents('php://input', false, null, 0, 65537);
if (strlen($raw) > 65536) {
    $reply(413, ['ok' => false, 'error' => 'Corpo grande demais (máximo 64 KB).']);
    return;
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST; // aceita também formulário simples
}
if (!$payload) {
    $reply(400, ['ok' => false, 'error' => 'Envie um JSON com ao menos "title".']);
    return;
}
[$code, $body] = EventIntake::handle($payload);
if ($code === 201) {
    Dispatcher::retries((int) $body['id']);
}
$reply($code, $body);
