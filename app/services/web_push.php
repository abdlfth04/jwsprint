<?php

require_once __DIR__ . '/chat_support.php';

function webPushTableExists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $cache[$table] = false;
    }

    return $cache[$table] = schemaTableExists($conn, $table);
}

function webPushIsSupportedEnvironment(): bool
{
    return extension_loaded('curl')
        && extension_loaded('openssl')
        && function_exists('openssl_pkey_derive');
}

function webPushBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function webPushBase64UrlDecode(string $value): string
{
    $normalized = strtr(trim($value), '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    if ($decoded === false) {
        throw new RuntimeException('Format base64url Web Push tidak valid.');
    }

    return $decoded;
}

function webPushOpenSslConfigPath(): ?string
{
    static $resolved = false;
    static $path = null;

    if ($resolved) {
        return $path;
    }
    $resolved = true;

    $phpBinaryDir = str_replace('/', DIRECTORY_SEPARATOR, dirname((string) PHP_BINARY));
    $phpParentDir = dirname($phpBinaryDir);

    $candidates = array_filter([
        (string) envValue('OPENSSL_CONF', ''),
        (string) getenv('OPENSSL_CONF'),
        $phpBinaryDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpBinaryDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpBinaryDir . DIRECTORY_SEPARATOR . 'windowsXamppPhp' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpParentDir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        $phpParentDir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        'C:\\xampp\\php\\extras\\openssl\\openssl.cnf',
        'C:\\xampp\\php\\windowsXamppPhp\\extras\\ssl\\openssl.cnf',
        'E:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        'E:\\xampp\\php\\extras\\openssl\\openssl.cnf',
        'E:\\xampp\\php\\windowsXamppPhp\\extras\\ssl\\openssl.cnf',
    ]);

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }

    return $path;
}

function webPushEcKeyOptions(): array
{
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];

    $configPath = webPushOpenSslConfigPath();
    if ($configPath !== null) {
        $options['config'] = $configPath;
    }

    return $options;
}

function webPushGenerateEcKeyPair(): array
{
    $key = openssl_pkey_new(webPushEcKeyOptions());
    if ($key === false) {
        $errors = [];
        while ($message = openssl_error_string()) {
            $errors[] = $message;
        }

        throw new RuntimeException('Gagal membuat key pair Web Push: ' . implode(' | ', $errors));
    }

    $details = openssl_pkey_get_details($key);
    $ec = is_array($details['ec'] ?? null) ? $details['ec'] : null;
    $x = (string) ($ec['x'] ?? '');
    $y = (string) ($ec['y'] ?? '');
    $d = (string) ($ec['d'] ?? '');
    if (strlen($x) !== 32 || strlen($y) !== 32 || strlen($d) !== 32) {
        throw new RuntimeException('Key pair EC Web Push tidak valid.');
    }

    return [
        'private' => $d,
        'public' => "\x04" . $x . $y,
    ];
}

function webPushGenerateVapidKeys(): array
{
    $pair = webPushGenerateEcKeyPair();

    return [
        'public_key' => webPushBase64UrlEncode($pair['public']),
        'private_key' => webPushBase64UrlEncode($pair['private']),
    ];
}

function webPushStoredVapidConfigPath(): string
{
    if (function_exists('appPrivateStoragePath')) {
        return appPrivateStoragePath('web_push' . DIRECTORY_SEPARATOR . 'web_push_vapid.json');
    }

    return PROJECT_ROOT
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'cache'
        . DIRECTORY_SEPARATOR . 'web_push_vapid.json';
}

function webPushLegacyStoredVapidConfigPath(): string
{
    return PROJECT_ROOT
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'cache'
        . DIRECTORY_SEPARATOR . 'web_push_vapid.json';
}

function webPushReadStoredVapidConfig(): array
{
    $path = webPushStoredVapidConfigPath();
    if (!is_file($path)) {
        $legacyPath = webPushLegacyStoredVapidConfigPath();
        if (is_file($legacyPath)) {
            $path = $legacyPath;
        }
    }

    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function webPushPersistVapidConfig(array $config): bool
{
    $path = webPushStoredVapidConfigPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = json_encode([
        'public_key' => trim((string) ($config['public_key'] ?? '')),
        'private_key' => trim((string) ($config['private_key'] ?? '')),
        'subject' => trim((string) ($config['subject'] ?? '')),
        'generated_at' => trim((string) ($config['generated_at'] ?? date(DATE_ATOM))),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return false;
    }

    return @file_put_contents($path, $payload, LOCK_EX) !== false;
}

function webPushPemFromRawPublicKey(string $publicKey): string
{
    if (strlen($publicKey) !== 65 || ($publicKey[0] ?? '') !== "\x04") {
        throw new RuntimeException('Public key Web Push tidak valid.');
    }

    $der = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200') . $publicKey;

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function webPushPemFromRawPrivateKey(string $privateKey, string $publicKey): string
{
    if (strlen($privateKey) !== 32) {
        throw new RuntimeException('Private key Web Push tidak valid.');
    }

    $der = hex2bin('30770201010420')
        . $privateKey
        . hex2bin('A00A06082A8648CE3D030107A144034200')
        . $publicKey;

    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

function webPushCurrentOrigin(): string
{
    if (PHP_SAPI === 'cli') {
        return 'https://localhost';
    }

    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

function webPushDefaultSubject(): string
{
    $origin = webPushCurrentOrigin();
    $path = rtrim((string) BASE_URL, '/');

    return $origin . ($path !== '' ? $path : '');
}

function webPushGetVapidConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $enabled = envBool('WEB_PUSH_ENABLED', true);
    $publicKey = trim((string) envValue('WEB_PUSH_PUBLIC_KEY', ''));
    $privateKey = trim((string) envValue('WEB_PUSH_PRIVATE_KEY', ''));
    $subject = trim((string) envValue('WEB_PUSH_SUBJECT', ''));
    if ($subject === '') {
        $subject = webPushDefaultSubject();
    }

    $validateKeys = static function (string $candidatePublicKey, string $candidatePrivateKey): bool {
        try {
            return strlen(webPushBase64UrlDecode($candidatePublicKey)) === 65
                && strlen(webPushBase64UrlDecode($candidatePrivateKey)) === 32;
        } catch (Throwable $exception) {
            return false;
        }
    };

    $source = 'env';
    if ((!$validateKeys($publicKey, $privateKey) || $publicKey === '' || $privateKey === '') && $enabled) {
        $storedConfig = webPushReadStoredVapidConfig();
        $storedPublicKey = trim((string) ($storedConfig['public_key'] ?? ''));
        $storedPrivateKey = trim((string) ($storedConfig['private_key'] ?? ''));
        $storedSubject = trim((string) ($storedConfig['subject'] ?? ''));

        if ($validateKeys($storedPublicKey, $storedPrivateKey)) {
            $publicKey = $storedPublicKey;
            $privateKey = $storedPrivateKey;
            if ($subject === '' && $storedSubject !== '') {
                $subject = $storedSubject;
            }
            $source = 'cache';
        }
    }

    if ((!$validateKeys($publicKey, $privateKey) || $publicKey === '' || $privateKey === '') && $enabled && webPushIsSupportedEnvironment()) {
        try {
            $generatedKeys = webPushGenerateVapidKeys();
            if ($validateKeys((string) ($generatedKeys['public_key'] ?? ''), (string) ($generatedKeys['private_key'] ?? ''))) {
                $publicKey = (string) $generatedKeys['public_key'];
                $privateKey = (string) $generatedKeys['private_key'];
                webPushPersistVapidConfig([
                    'public_key' => $publicKey,
                    'private_key' => $privateKey,
                    'subject' => $subject,
                ]);
                $source = 'generated';
            }
        } catch (Throwable $exception) {
        }
    }

    $configured = $enabled && $validateKeys($publicKey, $privateKey);

    $config = [
        'enabled' => $enabled,
        'configured' => $configured,
        'public_key' => $publicKey,
        'private_key' => $privateKey,
        'subject' => $subject,
        'source' => $source,
    ];

    return $config;
}

function webPushEnsureSubscriptionTable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $ready = false;
    }

    if (webPushTableExists('push_subscriptions')) {
        return $ready = true;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        endpoint TEXT NOT NULL,
        public_key VARCHAR(255) NOT NULL,
        auth_token VARCHAR(255) NOT NULL,
        content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
        user_agent VARCHAR(255) NULL,
        device_label VARCHAR(120) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_success_at DATETIME NULL,
        last_failure_at DATETIME NULL,
        last_http_status SMALLINT UNSIGNED NULL,
        failure_reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_push_subscription_hash (endpoint_hash),
        KEY idx_push_subscription_user (user_id),
        KEY idx_push_subscription_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $created = $conn->query($sql);

    return $ready = (bool) $created;
}

function webPushEndpointHash(string $endpoint): string
{
    return hash('sha256', trim($endpoint));
}

function webPushNormalizeSubscription(array $subscription): ?array
{
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $publicKey = trim((string) ($keys['p256dh'] ?? $subscription['public_key'] ?? ''));
    $authToken = trim((string) ($keys['auth'] ?? $subscription['auth_token'] ?? ''));
    $contentEncoding = trim((string) ($subscription['contentEncoding'] ?? $subscription['content_encoding'] ?? 'aes128gcm'));

    if ($endpoint === '' || $publicKey === '' || $authToken === '') {
        return null;
    }

    if ($contentEncoding === '') {
        $contentEncoding = 'aes128gcm';
    }

    try {
        $publicKeyBytes = webPushBase64UrlDecode($publicKey);
        $authTokenBytes = webPushBase64UrlDecode($authToken);
    } catch (Throwable $exception) {
        return null;
    }

    if (strlen($publicKeyBytes) !== 65 || ($publicKeyBytes[0] ?? '') !== "\x04") {
        return null;
    }

    if (strlen($authTokenBytes) !== 16) {
        return null;
    }

    return [
        'endpoint' => $endpoint,
        'endpoint_hash' => webPushEndpointHash($endpoint),
        'public_key' => $publicKey,
        'auth_token' => $authToken,
        'content_encoding' => $contentEncoding,
    ];
}

function webPushGetRequestPayload(): array
{
    static $payload = null;
    if (is_array($payload)) {
        return $payload;
    }

    $payload = [];
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') === false) {
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $payload;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }

    return $payload;
}

function webPushGetSubscriptionFromRequest(): ?array
{
    $requestPayload = webPushGetRequestPayload();
    $candidate = $requestPayload['subscription'] ?? $_POST['subscription'] ?? null;

    if (is_string($_POST['subscription_json'] ?? null)) {
        $decoded = json_decode((string) $_POST['subscription_json'], true);
        if (is_array($decoded)) {
            $candidate = $decoded;
        }
    }

    if (is_string($candidate)) {
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            $candidate = $decoded;
        }
    }

    if (!is_array($candidate)) {
        return null;
    }

    return webPushNormalizeSubscription($candidate);
}

function webPushUpsertSubscription(int $userId, array $subscription, array $context = []): bool
{
    if ($userId <= 0 || !webPushEnsureSubscriptionTable()) {
        return false;
    }

    $normalized = webPushNormalizeSubscription($subscription);
    if ($normalized === null) {
        return false;
    }

    global $conn;
    $userAgent = trim((string) ($context['user_agent'] ?? ''));
    $deviceLabel = trim((string) ($context['device_label'] ?? ''));

    $stmt = $conn->prepare(
        "INSERT INTO push_subscriptions (
            user_id,
            endpoint_hash,
            endpoint,
            public_key,
            auth_token,
            content_encoding,
            user_agent,
            device_label,
            is_active,
            last_success_at,
            last_failure_at,
            last_http_status,
            failure_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            endpoint = VALUES(endpoint),
            public_key = VALUES(public_key),
            auth_token = VALUES(auth_token),
            content_encoding = VALUES(content_encoding),
            user_agent = VALUES(user_agent),
            device_label = VALUES(device_label),
            is_active = 1,
            last_failure_at = NULL,
            last_http_status = NULL,
            failure_reason = NULL"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'isssssss',
        $userId,
        $normalized['endpoint_hash'],
        $normalized['endpoint'],
        $normalized['public_key'],
        $normalized['auth_token'],
        $normalized['content_encoding'],
        $userAgent,
        $deviceLabel
    );
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function webPushDeactivateSubscriptionByEndpoint(int $userId, string $endpoint, ?string $reason = null, ?int $statusCode = null): bool
{
    if ($userId <= 0 || trim($endpoint) === '' || !webPushEnsureSubscriptionTable()) {
        return false;
    }

    global $conn;
    $endpointHash = webPushEndpointHash($endpoint);
    $failureReason = trim((string) ($reason ?? ''));
    $httpStatus = $statusCode !== null ? max(0, (int) $statusCode) : null;

    $stmt = $conn->prepare(
        "UPDATE push_subscriptions
         SET is_active = 0,
             last_failure_at = NOW(),
             last_http_status = ?,
             failure_reason = ?
         WHERE user_id = ? AND endpoint_hash = ?"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isis', $httpStatus, $failureReason, $userId, $endpointHash);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected >= 0;
}

function webPushUpdateDeliveryStatus(int $subscriptionId, bool $success, int $statusCode = 0, string $reason = ''): void
{
    if ($subscriptionId <= 0 || !webPushEnsureSubscriptionTable()) {
        return;
    }

    global $conn;
    $reason = trim($reason);
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 252) . '...';
    }

    if ($success) {
        $stmt = $conn->prepare(
            "UPDATE push_subscriptions
             SET is_active = 1,
                 last_success_at = NOW(),
                 last_http_status = ?,
                 last_failure_at = NULL,
                 failure_reason = NULL
             WHERE id = ?"
        );
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('ii', $statusCode, $subscriptionId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE push_subscriptions
         SET is_active = CASE WHEN ? IN (404, 410) THEN 0 ELSE is_active END,
             last_failure_at = NOW(),
             last_http_status = ?,
             failure_reason = ?
         WHERE id = ?"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iisi', $statusCode, $statusCode, $reason, $subscriptionId);
    $stmt->execute();
    $stmt->close();
}

function webPushGetCurrentUserSubscriptionCount(): int
{
    if (!isLoggedIn() || !webPushEnsureSubscriptionTable()) {
        return 0;
    }

    global $conn;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM push_subscriptions WHERE user_id = ? AND is_active = 1");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function webPushGetActiveSubscriptionsForUserIds(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (empty($userIds) || !webPushEnsureSubscriptionTable()) {
        return [];
    }

    global $conn;
    $idList = implode(',', $userIds);
    if ($idList === '') {
        return [];
    }

    $result = $conn->query("SELECT * FROM push_subscriptions WHERE is_active = 1 AND user_id IN ($idList) ORDER BY id ASC");

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function webPushReadDerLength(string $value, int &$offset): int
{
    if (!isset($value[$offset])) {
        throw new RuntimeException('Panjang DER tidak valid.');
    }

    $length = ord($value[$offset]);
    $offset++;
    if (($length & 0x80) === 0) {
        return $length;
    }

    $byteCount = $length & 0x7F;
    if ($byteCount < 1 || $byteCount > 4) {
        throw new RuntimeException('Panjang DER terlalu besar.');
    }

    $length = 0;
    for ($index = 0; $index < $byteCount; $index++) {
        if (!isset($value[$offset])) {
            throw new RuntimeException('Panjang DER terpotong.');
        }
        $length = ($length << 8) | ord($value[$offset]);
        $offset++;
    }

    return $length;
}

function webPushDerIntegerToFixed(string $value, int $size): string
{
    while (strlen($value) > 1 && ($value[0] ?? '') === "\x00") {
        $value = substr($value, 1);
    }

    if (strlen($value) > $size) {
        throw new RuntimeException('Ukuran integer DER tidak valid.');
    }

    return str_pad($value, $size, "\x00", STR_PAD_LEFT);
}

function webPushDerSignatureToJose(string $signature, int $size = 32): string
{
    $offset = 0;
    if (($signature[$offset] ?? '') !== "\x30") {
        throw new RuntimeException('Format signature DER tidak valid.');
    }
    $offset++;
    webPushReadDerLength($signature, $offset);

    if (($signature[$offset] ?? '') !== "\x02") {
        throw new RuntimeException('Komponen signature DER tidak valid.');
    }
    $offset++;
    $rLength = webPushReadDerLength($signature, $offset);
    $r = substr($signature, $offset, $rLength);
    $offset += $rLength;

    if (($signature[$offset] ?? '') !== "\x02") {
        throw new RuntimeException('Komponen signature DER tidak lengkap.');
    }
    $offset++;
    $sLength = webPushReadDerLength($signature, $offset);
    $s = substr($signature, $offset, $sLength);

    return webPushDerIntegerToFixed($r, $size) . webPushDerIntegerToFixed($s, $size);
}

function webPushAudienceFromEndpoint(string $endpoint): string
{
    $parts = parse_url($endpoint);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : null;

    if ($scheme === '' || $host === '') {
        throw new RuntimeException('Endpoint Web Push tidak valid.');
    }

    $audience = $scheme . '://' . $host;
    if ($port !== null) {
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        if (!$isDefaultPort) {
            $audience .= ':' . $port;
        }
    }

    return $audience;
}

function webPushCreateVapidJwt(string $audience): string
{
    $config = webPushGetVapidConfig();
    if (empty($config['configured'])) {
        throw new RuntimeException('VAPID Web Push belum dikonfigurasi.');
    }

    $publicKey = webPushBase64UrlDecode((string) $config['public_key']);
    $privateKey = webPushBase64UrlDecode((string) $config['private_key']);
    $privatePem = webPushPemFromRawPrivateKey($privateKey, $publicKey);

    $jwtHeader = webPushBase64UrlEncode(json_encode([
        'typ' => 'JWT',
        'alg' => 'ES256',
    ], JSON_UNESCAPED_SLASHES));

    $claims = [
        'aud' => $audience,
        'exp' => time() + 12 * 60 * 60,
    ];
    if ((string) $config['subject'] !== '') {
        $claims['sub'] = (string) $config['subject'];
    }

    $jwtPayload = webPushBase64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $input = $jwtHeader . '.' . $jwtPayload;

    $resource = openssl_pkey_get_private($privatePem);
    if ($resource === false) {
        throw new RuntimeException('Private key VAPID tidak dapat dipakai untuk sign.');
    }

    $signature = '';
    $signed = openssl_sign($input, $signature, $resource, OPENSSL_ALGO_SHA256);
    if (!$signed) {
        throw new RuntimeException('Gagal menandatangani JWT VAPID.');
    }

    return $input . '.' . webPushBase64UrlEncode(webPushDerSignatureToJose($signature, 32));
}

function webPushEncryptPayload(string $payload, string $userPublicKey, string $authToken, array $options = []): array
{
    $uaPublic = webPushBase64UrlDecode($userPublicKey);
    $authSecret = webPushBase64UrlDecode($authToken);
    if (strlen($uaPublic) !== 65 || ($uaPublic[0] ?? '') !== "\x04") {
        throw new RuntimeException('Public key subscriber tidak valid.');
    }
    if (strlen($authSecret) !== 16) {
        throw new RuntimeException('Authentication secret subscriber tidak valid.');
    }

    $senderKeys = is_array($options['sender_keys'] ?? null) ? $options['sender_keys'] : webPushGenerateEcKeyPair();
    $senderPublic = (string) ($senderKeys['public'] ?? '');
    $senderPrivate = (string) ($senderKeys['private'] ?? '');
    $salt = (string) ($options['salt'] ?? random_bytes(16));

    if (strlen($senderPublic) !== 65 || strlen($senderPrivate) !== 32 || strlen($salt) !== 16) {
        throw new RuntimeException('Material enkripsi Web Push tidak valid.');
    }

    $peerKey = openssl_pkey_get_public(webPushPemFromRawPublicKey($uaPublic));
    $privateKey = openssl_pkey_get_private(webPushPemFromRawPrivateKey($senderPrivate, $senderPublic));
    if ($peerKey === false || $privateKey === false) {
        throw new RuntimeException('Key pair enkripsi Web Push tidak dapat dipakai.');
    }

    $sharedSecret = openssl_pkey_derive($peerKey, $privateKey, 32);
    if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
        throw new RuntimeException('Gagal membuat shared secret Web Push.');
    }

    $keyInfo = 'WebPush: info' . "\x00" . $uaPublic . $senderPublic;
    $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
    $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

    $plaintext = $payload . "\x02";
    $recordSize = max(4096, strlen($plaintext) + 17);
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false || !is_string($tag)) {
        throw new RuntimeException('Gagal mengenkripsi payload Web Push.');
    }

    $header = $salt . pack('N', $recordSize) . chr(strlen($senderPublic)) . $senderPublic;
    $body = $header . $ciphertext . $tag;
    if (strlen($body) > 4096) {
        throw new RuntimeException('Payload Web Push melebihi batas push service.');
    }

    return [
        'body' => $body,
        'salt' => $salt,
        'sender_public' => $senderPublic,
    ];
}

function webPushSummarizeResponseBody(string $body): string
{
    $summary = trim((string) preg_replace('/\s+/', ' ', $body));
    if ($summary === '') {
        return '';
    }

    return strlen($summary) > 255 ? substr($summary, 0, 252) . '...' : $summary;
}

function webPushDefaultIconUrl(): string
{
    return pwaIconUrl();
}

function webPushBuildMessage(array $message): array
{
    $title = trim((string) ($message['title'] ?? 'JWS Notification'));
    $body = trim((string) ($message['body'] ?? 'Ada pembaruan baru di aplikasi JWS.'));
    $url = (string) ($message['url'] ?? pageUrl('notifikasi.php'));
    $data = is_array($message['data'] ?? null) ? $message['data'] : [];

    return [
        'title' => $title !== '' ? $title : 'JWS Notification',
        'body' => $body,
        'icon' => (string) ($message['icon'] ?? webPushDefaultIconUrl()),
        'badge' => (string) ($message['badge'] ?? webPushDefaultIconUrl()),
        'url' => $url,
        'tag' => trim((string) ($message['tag'] ?? 'jws-' . substr(hash('sha256', $url . '|' . $title), 0, 20))),
        'ttl' => max(30, min(2419200, (int) ($message['ttl'] ?? 120))),
        'urgency' => in_array((string) ($message['urgency'] ?? ''), ['very-low', 'low', 'normal', 'high'], true)
            ? (string) $message['urgency']
            : 'normal',
        'kind' => (string) ($message['kind'] ?? 'generic'),
        'timestamp' => (int) ($message['timestamp'] ?? round(microtime(true) * 1000)),
        'data' => array_merge($data, [
            'url' => $data['url'] ?? $url,
            'kind' => $data['kind'] ?? (string) ($message['kind'] ?? 'generic'),
        ]),
        'forceDisplay' => !empty($message['forceDisplay']),
    ];
}

function webPushDispatchToSubscription(array $subscription, array $message): array
{
    $preparedMessage = webPushBuildMessage($message);
    $payload = json_encode($preparedMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Payload notifikasi Web Push tidak dapat diserialisasi.');
    }

    $encrypted = webPushEncryptPayload(
        $payload,
        (string) ($subscription['public_key'] ?? ''),
        (string) ($subscription['auth_token'] ?? '')
    );

    $audience = webPushAudienceFromEndpoint((string) ($subscription['endpoint'] ?? ''));
    $jwt = webPushCreateVapidJwt($audience);
    $vapidConfig = webPushGetVapidConfig();

    $headers = [
        'TTL: ' . (int) $preparedMessage['ttl'],
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'Authorization: vapid t=' . $jwt . ', k=' . (string) $vapidConfig['public_key'],
        'Urgency: ' . (string) $preparedMessage['urgency'],
    ];

    $handle = curl_init((string) $subscription['endpoint']);
    if ($handle === false) {
        throw new RuntimeException('cURL tidak dapat diinisialisasi untuk Web Push.');
    }

    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $encrypted['body'],
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    }

    curl_setopt_array($handle, $options);
    $responseBody = curl_exec($handle);
    $curlError = curl_error($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($responseBody === false) {
        $reason = $curlError !== '' ? $curlError : 'Web Push request gagal di level transport.';
        webPushUpdateDeliveryStatus((int) ($subscription['id'] ?? 0), false, $statusCode, $reason);

        return [
            'success' => false,
            'status_code' => $statusCode,
            'reason' => $reason,
        ];
    }

    $success = $statusCode >= 200 && $statusCode < 300;
    $reason = $success ? '' : webPushSummarizeResponseBody((string) $responseBody);
    webPushUpdateDeliveryStatus((int) ($subscription['id'] ?? 0), $success, $statusCode, $reason);

    return [
        'success' => $success,
        'status_code' => $statusCode,
        'reason' => $reason,
    ];
}

function webPushSendToUserIds(array $userIds, array $message): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $config = webPushGetVapidConfig();

    if (
        empty($config['enabled'])
        || empty($config['configured'])
        || !webPushIsSupportedEnvironment()
        || empty($userIds)
    ) {
        return [
            'users' => count($userIds),
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];
    }

    $subscriptions = webPushGetActiveSubscriptionsForUserIds($userIds);
    $results = [];
    $sent = 0;
    $failed = 0;

    foreach ($subscriptions as $subscription) {
        try {
            $result = webPushDispatchToSubscription($subscription, $message);
        } catch (Throwable $exception) {
            $result = [
                'success' => false,
                'status_code' => 0,
                'reason' => $exception->getMessage(),
            ];
            webPushUpdateDeliveryStatus((int) ($subscription['id'] ?? 0), false, 0, $exception->getMessage());
        }

        if (!empty($result['success'])) {
            $sent++;
        } else {
            $failed++;
        }

        $results[] = [
            'subscription_id' => (int) ($subscription['id'] ?? 0),
            'user_id' => (int) ($subscription['user_id'] ?? 0),
            'success' => !empty($result['success']),
            'status_code' => (int) ($result['status_code'] ?? 0),
            'reason' => (string) ($result['reason'] ?? ''),
        ];
    }

    return [
        'users' => count($userIds),
        'subscriptions' => count($subscriptions),
        'sent' => $sent,
        'failed' => $failed,
        'results' => $results,
    ];
}

function webPushCollapseText(string $value, int $limit = 120): string
{
    $value = trim((string) preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $limit, '...');
    }

    return strlen($value) > $limit ? substr($value, 0, max(0, $limit - 3)) . '...' : $value;
}

function webPushResolveChatRecipientUserIds(array $room, int $senderId): array
{
    if ($senderId <= 0 || !webPushTableExists('users')) {
        return [];
    }

    $roomType = (string) ($room['tipe'] ?? 'group');
    if ($roomType === 'personal') {
        $parts = explode('_', (string) ($room['nama'] ?? ''));
        $userIds = [];
        if (count($parts) >= 3) {
            $userIds[] = (int) ($parts[1] ?? 0);
            $userIds[] = (int) ($parts[2] ?? 0);
        }

        return array_values(array_diff(array_unique(array_filter($userIds)), [$senderId]));
    }

    global $conn;
    $roomDivisi = trim((string) ($room['divisi'] ?? ''));
    if ($roomDivisi !== '' && webPushTableExists('karyawan')) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN karyawan k ON k.user_id = u.id
             WHERE u.status = 'aktif'
               AND u.id <> ?
               AND (u.role IN ('superadmin', 'admin') OR k.divisi = ?)"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('is', $senderId, $roomDivisi);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_values(array_filter(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $rows)));
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE status = 'aktif' AND id <> ?");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $senderId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows)));
}

function webPushGetUserIdsByRoles(array $roles, int $excludeUserId = 0): array
{
    if (!webPushTableExists('users')) {
        return [];
    }

    $allowedRoles = ['superadmin', 'admin', 'service', 'kasir', 'user'];
    $roles = array_values(array_unique(array_filter(array_map(static function ($role) use ($allowedRoles): string {
        $normalized = trim((string) $role);
        return in_array($normalized, $allowedRoles, true) ? $normalized : '';
    }, $roles))));

    if (empty($roles)) {
        return [];
    }

    global $conn;
    $quotedRoles = array_map(static function (string $role) use ($conn): string {
        return "'" . $conn->real_escape_string($role) . "'";
    }, $roles);

    $sql = "SELECT id FROM users WHERE status = 'aktif' AND role IN (" . implode(',', $quotedRoles) . ")";
    if ($excludeUserId > 0) {
        $sql .= " AND id <> " . (int) $excludeUserId;
    }

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    return array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $result->fetch_all(MYSQLI_ASSOC))));
}

function webPushFetchTransactionContext(int $transaksiId, ?int $detailId = null): array
{
    $context = [
        'transaksi_id' => $transaksiId,
        'detail_transaksi_id' => max(0, (int) ($detailId ?? 0)),
        'no_transaksi' => $transaksiId > 0 ? ('#' . $transaksiId) : 'Transaksi',
        'pelanggan' => '',
        'produk' => '',
    ];

    if ($transaksiId <= 0) {
        return $context;
    }

    global $conn;
    if (!isset($conn) || !$conn || !schemaTableExists($conn, 'transaksi')) {
        return $context;
    }

    $hasPelangganJoin = schemaTableExists($conn, 'pelanggan') && schemaColumnExists($conn, 'transaksi', 'pelanggan_id');
    $hasDetailTable = schemaTableExists($conn, 'detail_transaksi');
    $detailColumnExists = $hasDetailTable && schemaColumnExists($conn, 'detail_transaksi', 'id');
    $detailNameExists = $hasDetailTable && schemaColumnExists($conn, 'detail_transaksi', 'nama_produk');

    $sql = "SELECT t.no_transaksi";
    if ($hasPelangganJoin) {
        $sql .= ", p.nama AS nama_pelanggan";
    } else {
        $sql .= ", NULL AS nama_pelanggan";
    }
    if ($detailColumnExists && $detailNameExists) {
        $sql .= ", dt.nama_produk";
    } else {
        $sql .= ", NULL AS nama_produk";
    }
    $sql .= " FROM transaksi t";
    if ($hasPelangganJoin) {
        $sql .= " LEFT JOIN pelanggan p ON p.id = t.pelanggan_id";
    }
    if ($detailColumnExists && $detailNameExists) {
        $sql .= " LEFT JOIN detail_transaksi dt ON dt.id = ?";
    }
    $sql .= " WHERE t.id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $context;
    }

    $detailIdValue = max(0, (int) ($detailId ?? 0));
    if ($detailColumnExists && $detailNameExists) {
        $stmt->bind_param('ii', $detailIdValue, $transaksiId);
    } else {
        $stmt->bind_param('i', $transaksiId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (!empty($row['no_transaksi'])) {
        $context['no_transaksi'] = (string) $row['no_transaksi'];
    }
    if (!empty($row['nama_pelanggan'])) {
        $context['pelanggan'] = (string) $row['nama_pelanggan'];
    }
    if (!empty($row['nama_produk'])) {
        $context['produk'] = (string) $row['nama_produk'];
    }

    return $context;
}

function webPushFormatTransactionContextLabel(array $context): string
{
    $parts = [];
    $noTransaksi = trim((string) ($context['no_transaksi'] ?? ''));
    $pelanggan = trim((string) ($context['pelanggan'] ?? ''));
    $produk = trim((string) ($context['produk'] ?? ''));

    if ($noTransaksi !== '') {
        $parts[] = $noTransaksi;
    }
    if ($pelanggan !== '') {
        $parts[] = $pelanggan;
    }
    if ($produk !== '') {
        $parts[] = $produk;
    }

    return implode(' / ', $parts);
}

function webPushSendChatMessagePush(array $room, array $message, int $senderId): array
{
    $recipientIds = webPushResolveChatRecipientUserIds($room, $senderId);
    if (empty($recipientIds)) {
        return [
            'users' => 0,
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];
    }

    $roomId = (int) ($room['id'] ?? 0);
    $roomName = trim((string) ($room['nama'] ?? 'Room Chat'));
    $senderName = trim((string) ($message['nama'] ?? 'Tim JWS'));
    $messagePreview = webPushCollapseText(chatPreviewTextFromStoredMessage((string) ($message['pesan'] ?? ''), 110), 110);
    $isPersonal = (string) ($room['tipe'] ?? 'group') === 'personal';
    $title = $isPersonal
        ? ($senderName !== '' ? $senderName . ' mengirim pesan baru' : 'Pesan chat baru')
        : 'Pesan baru di ' . ($roomName !== '' ? $roomName : 'Room Chat');
    $body = $isPersonal
        ? ($messagePreview !== '' ? $messagePreview : 'Buka chat untuk membaca pesan baru.')
        : trim(($senderName !== '' ? $senderName . ': ' : '') . ($messagePreview !== '' ? $messagePreview : 'Ada pesan baru.'));
    $url = pageUrl('chat.php') . ($roomId > 0 ? '?room=' . $roomId : '');

    return webPushSendToUserIds($recipientIds, [
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'kind' => 'chat',
        'tag' => 'jws-chat-room-' . max(0, $roomId),
        'ttl' => 120,
        'urgency' => 'high',
        'data' => [
            'url' => $url,
            'room_id' => $roomId,
            'kind' => 'chat',
        ],
    ]);
}

function webPushSendReadyPrintUploadedPush(int $transaksiId, ?int $detailId, int $senderId, array $meta = []): array
{
    $recipientIds = webPushGetUserIdsByRoles(['superadmin', 'admin', 'service'], $senderId);
    if (empty($recipientIds)) {
        return [
            'users' => 0,
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];
    }

    $context = webPushFetchTransactionContext($transaksiId, $detailId);
    $label = webPushFormatTransactionContextLabel($context);
    $versionNo = max(0, (int) ($meta['version_no'] ?? 0));
    $uploaderName = trim((string) ($meta['uploader_name'] ?? ''));
    $bodyParts = [];
    if ($label !== '') {
        $bodyParts[] = $label;
    }
    if ($versionNo > 0) {
        $bodyParts[] = 'V' . $versionNo;
    }
    $body = implode(' - ', $bodyParts);
    if ($uploaderName !== '') {
        $body .= ($body !== '' ? ' oleh ' : '') . $uploaderName;
    }
    if ($body === '') {
        $body = 'Ada file siap cetak baru yang menunggu tindak lanjut.';
    } else {
        $body .= ' siap ditindaklanjuti.';
    }

    $url = pageUrl('siap_cetak.php');

    return webPushSendToUserIds($recipientIds, [
        'title' => 'File siap cetak baru',
        'body' => webPushCollapseText($body, 120),
        'url' => $url,
        'kind' => 'ready_print',
        'tag' => 'jws-ready-print-' . $transaksiId . '-' . max(0, (int) ($detailId ?? 0)),
        'ttl' => 180,
        'urgency' => 'high',
        'data' => [
            'url' => $url,
            'kind' => 'ready_print',
            'transaksi_id' => $transaksiId,
            'detail_transaksi_id' => max(0, (int) ($detailId ?? 0)),
        ],
    ]);
}

function webPushSendCashierQueuePush(int $transaksiId, int $senderId = 0): array
{
    $recipientIds = webPushGetUserIdsByRoles(['superadmin', 'admin', 'kasir'], $senderId);
    if (empty($recipientIds)) {
        return [
            'users' => 0,
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];
    }

    $context = webPushFetchTransactionContext($transaksiId, null);
    $label = webPushFormatTransactionContextLabel($context);
    $body = $label !== '' ? $label . ' siap diproses kasir.' : ('Transaksi #' . $transaksiId . ' siap diproses kasir.');
    $url = pageUrl('transaksi.php');

    return webPushSendToUserIds($recipientIds, [
        'title' => 'Order masuk antrian kasir',
        'body' => webPushCollapseText($body, 120),
        'url' => $url,
        'kind' => 'cashier_queue',
        'tag' => 'jws-cashier-' . $transaksiId,
        'ttl' => 180,
        'urgency' => 'high',
        'data' => [
            'url' => $url,
            'kind' => 'cashier_queue',
            'transaksi_id' => $transaksiId,
        ],
    ]);
}

function webPushSendTestNotificationToCurrentUser(): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'users' => 0,
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'results' => [],
        ];
    }

    return webPushSendToUserIds([$userId], [
        'title' => 'Web Push JWS aktif',
        'body' => 'Perangkat ini sudah siap menerima notifikasi real-time dari aplikasi JWS.',
        'url' => pageUrl('notifikasi.php'),
        'kind' => 'system',
        'tag' => 'jws-web-push-test',
        'ttl' => 60,
        'urgency' => 'high',
        'forceDisplay' => true,
        'data' => [
            'url' => pageUrl('notifikasi.php'),
            'kind' => 'system',
        ],
    ]);
}
