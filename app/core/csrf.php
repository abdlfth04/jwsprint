<?php

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfToken(): string
{
    return ensureCsrfToken();
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfMetaTag(): string
{
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requestExpectsJson(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false
        || strpos($accept, 'text/json') !== false
        || $requestedWith === 'xmlhttprequest';
}

function getIncomingCsrfToken(): string
{
    $token = $_POST['csrf_token'] ?? '';
    if (is_string($token) && $token !== '') {
        return $token;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($headerToken) ? trim($headerToken) : '';
}

function isValidCsrfToken(?string $token = null): bool
{
    $token = $token ?? getIncomingCsrfToken();
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals(csrfToken(), $token);
}

function isLoginSubmissionRequest(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return false;
    }

    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return basename($requestPath) === 'login.php';
}

function rejectInvalidCsrfRequest(?bool $asJson = null): void
{
    $asJson = $asJson ?? requestExpectsJson();

    http_response_code(403);

    if ($asJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.',
        ]);
        exit;
    }

    if (isLoginSubmissionRequest() && function_exists('loginUrl')) {
        $loginUrl = loginUrl();
        $separator = strpos($loginUrl, '?') === false ? '?' : '&';
        header('Location: ' . $loginUrl . $separator . 'security=expired', true, 303);
        exit;
    }

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Sesi Tidak Valid</title></head><body style="font-family:Arial,sans-serif;padding:24px;background:#f8fafc;color:#0f172a"><h1 style="margin-top:0">Sesi keamanan tidak valid</h1><p>Muat ulang halaman lalu coba lagi.</p></body></html>';
    exit;
}

function enforceCsrfProtection(?bool $asJson = null): void
{
    if (!isValidCsrfToken()) {
        rejectInvalidCsrfRequest($asJson);
    }
}

function requestMatchesCurrentOrigin(string $candidateUrl): bool
{
    $candidateUrl = trim($candidateUrl);
    if ($candidateUrl === '') {
        return false;
    }

    $candidateHost = strtolower((string) parse_url($candidateUrl, PHP_URL_HOST));
    if ($candidateHost === '') {
        return false;
    }

    $currentHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $currentHost = preg_replace('/:\d+$/', '', $currentHost) ?? $currentHost;
    if ($currentHost === '' || $candidateHost !== $currentHost) {
        return false;
    }

    $candidateScheme = strtolower((string) parse_url($candidateUrl, PHP_URL_SCHEME));
    if ($candidateScheme === '') {
        return true;
    }

    return $candidateScheme === (isHttpsRequest() ? 'https' : 'http');
}

function isSameOriginBrowserRequest(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        return requestMatchesCurrentOrigin($origin);
    }

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        return requestMatchesCurrentOrigin($referer);
    }

    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

    return in_array($fetchSite, ['same-origin', 'none'], true);
}

function isTrustedServiceWorkerSubscriptionRequest(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return false;
    }

    if (trim((string) ($_SERVER['HTTP_X_JWS_SERVICE_WORKER'] ?? '')) !== '1') {
        return false;
    }

    if ((int) ($_SESSION['user_id'] ?? 0) <= 0) {
        return false;
    }

    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (basename($requestPath) !== 'web_push.php') {
        return false;
    }

    if (strtolower((string) ($_POST['action'] ?? '')) !== 'subscribe') {
        return false;
    }

    return isSameOriginBrowserRequest();
}

function enforceCsrfProtectionForStateChangingRequests(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        ensureCsrfToken();
        return;
    }

    if (isTrustedServiceWorkerSubscriptionRequest()) {
        return;
    }

    enforceCsrfProtection();
}
