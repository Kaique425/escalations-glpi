<?php

namespace GlpiPlugin\Escalations;

/** Chamada HTTP simples (curl) usada pelos canais Teams e WhatsApp. Devolve sempre um array, nunca lança exceção. */
class Http
{
    /**
     * @return array{ok: bool, code: int, body: string, error: string, ms: int}
     */
    public static function post(string $url, string $body, array $headers = [], int $timeout = 10): array
    {
        $t0 = microtime(true);
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'Endereço inválido (use http:// ou https://).', 'ms' => 0];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'ok'    => $err === '' && $code >= 200 && $code < 300,
            'code'  => $code,
            'body'  => is_string($resp) ? mb_substr($resp, 0, 2000) : '',
            'error' => $err !== '' ? $err : ($code >= 300 || $code === 0 ? 'HTTP ' . $code : ''),
            'ms'    => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /** Host do endereço, para registrar no log sem expor o segredo da URL. */
    public static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '?');
    }
}
