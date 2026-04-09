<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

function webPushApiResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function webPushApiStatusPayload(?string $message = null): array
{
    $config = webPushGetVapidConfig();
    $count = webPushGetCurrentUserSubscriptionCount();

    return [
        'success' => true,
        'supported' => webPushIsSupportedEnvironment(),
        'enabled' => !empty($config['enabled']),
        'configured' => !empty($config['configured']),
        'public_key' => !empty($config['configured']) ? (string) $config['public_key'] : '',
        'subject' => (string) ($config['subject'] ?? ''),
        'subscription_count' => $count,
        'has_subscription' => $count > 0,
        'message' => $message,
        'generated_at' => date(DATE_ATOM),
    ];
}

function webPushApiRequestValue(string $key, $default = null)
{
    $payload = webPushGetRequestPayload();
    if (array_key_exists($key, $payload)) {
        return $payload[$key];
    }

    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

$action = strtolower((string) webPushApiRequestValue('action', 'status'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET' && $action === 'status') {
    webPushApiResponse(webPushApiStatusPayload());
}

requireRole('superadmin', 'admin', 'service', 'kasir', 'user');

if ($method !== 'POST') {
    webPushApiResponse([
        'success' => false,
        'message' => 'Method tidak didukung.',
    ], 405);
}

$config = webPushGetVapidConfig();
if (!webPushIsSupportedEnvironment()) {
    webPushApiResponse([
        'success' => false,
        'message' => 'Environment PHP belum mendukung Web Push.',
    ], 503);
}

if ($action === 'subscribe') {
    if (empty($config['configured'])) {
        webPushApiResponse([
            'success' => false,
            'message' => 'VAPID Web Push belum dikonfigurasi.',
        ], 503);
    }

    $subscription = webPushGetSubscriptionFromRequest();
    if ($subscription === null) {
        webPushApiResponse([
            'success' => false,
            'message' => 'Payload subscription tidak valid.',
        ], 422);
    }

    $saved = webPushUpsertSubscription(
        (int) ($_SESSION['user_id'] ?? 0),
        $subscription,
        [
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'device_label' => trim((string) webPushApiRequestValue('device_label', '')),
        ]
    );

    if (!$saved) {
        webPushApiResponse([
            'success' => false,
            'message' => 'Subscription Web Push gagal disimpan.',
        ], 500);
    }

    webPushApiResponse(webPushApiStatusPayload('Web Push aktif di perangkat ini.'));
}

if ($action === 'unsubscribe') {
    $subscription = webPushGetSubscriptionFromRequest();
    $endpoint = trim((string) ($subscription['endpoint'] ?? webPushApiRequestValue('endpoint', '')));
    if ($endpoint === '') {
        webPushApiResponse([
            'success' => false,
            'message' => 'Endpoint subscription tidak ditemukan.',
        ], 422);
    }

    webPushDeactivateSubscriptionByEndpoint(
        (int) ($_SESSION['user_id'] ?? 0),
        $endpoint,
        'Dinonaktifkan dari browser.',
        0
    );

    webPushApiResponse(webPushApiStatusPayload('Web Push dimatikan untuk perangkat ini.'));
}

if ($action === 'test') {
    if (empty($config['configured'])) {
        webPushApiResponse([
            'success' => false,
            'message' => 'VAPID Web Push belum dikonfigurasi.',
        ], 503);
    }

    $subscription = webPushGetSubscriptionFromRequest();
    if ($subscription !== null) {
        webPushUpsertSubscription(
            (int) ($_SESSION['user_id'] ?? 0),
            $subscription,
            [
                'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'device_label' => trim((string) webPushApiRequestValue('device_label', '')),
            ]
        );
    }

    $delivery = webPushSendTestNotificationToCurrentUser();
    if (($delivery['subscriptions'] ?? 0) === 0) {
        webPushApiResponse(array_merge(
            webPushApiStatusPayload('Belum ada subscription aktif di akun ini.'),
            [
                'success' => false,
                'delivery' => $delivery,
            ]
        ), 409);
    }

    $sent = (int) ($delivery['sent'] ?? 0);
    $message = $sent > 0
        ? 'Test push dikirim ke ' . number_format($sent) . ' subscription.'
        : 'Push test diproses, tetapi tidak ada subscription yang berhasil menerima.';

    webPushApiResponse(array_merge(
        webPushApiStatusPayload($message),
        [
            'delivery' => $delivery,
        ]
    ));
}

webPushApiResponse([
    'success' => false,
    'message' => 'Aksi Web Push tidak dikenal.',
], 400);
