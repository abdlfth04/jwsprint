<?php

function realtimeStreamPrepare(): void
{
    if (!headers_sent()) {
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
    }

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    ignore_user_abort(true);
    @set_time_limit(0);

    echo ':' . str_repeat(' ', 2048) . "\n\n";
    @flush();
}

function realtimeStreamSend(string $event, array $payload = []): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    echo 'event: ' . preg_replace('/[^a-z0-9_\-]/i', '', $event) . "\n";
    echo 'data: ' . $json . "\n\n";
    @flush();
}

function realtimeStreamSignature($payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? sha1($json) : sha1(serialize($payload));
}
