<?php

require_once __DIR__ . '/file_manager.php';

if (!function_exists('chatAttachmentMaxBytes')) {
    function chatAttachmentMaxBytes(): int
    {
        return 10 * 1024 * 1024;
    }
}

if (!function_exists('chatAttachmentAllowedExtensions')) {
    function chatAttachmentAllowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt'];
    }
}

if (!function_exists('chatAttachmentAcceptAttribute')) {
    function chatAttachmentAcceptAttribute(): string
    {
        return implode(',', array_map(static function (string $extension): string {
            return '.' . $extension;
        }, chatAttachmentAllowedExtensions()));
    }
}

if (!function_exists('chatAttachmentAcceptedLabel')) {
    function chatAttachmentAcceptedLabel(): string
    {
        return 'JPG, PNG, PDF, DOC, DOCX, XLS, XLSX, CSV, PPT, PPTX, TXT';
    }
}

if (!function_exists('chatMessagePayloadMarker')) {
    function chatMessagePayloadMarker(): string
    {
        return '__JWS_CHAT_PAYLOAD__:';
    }
}

if (!function_exists('chatNormalizeAttachmentRelativePath')) {
    function chatNormalizeAttachmentRelativePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || strpos($normalized, '..') !== false) {
            return '';
        }

        return strpos($normalized, 'chat/') === 0 ? $normalized : '';
    }
}

if (!function_exists('chatResolveAttachmentAbsolutePath')) {
    function chatResolveAttachmentAbsolutePath(string $relativePath): ?string
    {
        $normalizedRelative = chatNormalizeAttachmentRelativePath($relativePath);
        if ($normalizedRelative === '') {
            return null;
        }

        $rootPath = str_replace('\\', '/', appPrivateStorageRootPath());
        $absolutePath = str_replace('\\', '/', appPrivateStoragePath($normalizedRelative));
        $normalizedRoot = rtrim($rootPath, '/');

        if (strpos($absolutePath, $normalizedRoot . '/') !== 0 && $absolutePath !== $normalizedRoot) {
            return null;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $absolutePath);
    }
}

if (!function_exists('chatDetectAttachmentKind')) {
    function chatDetectAttachmentKind(string $mimeType = '', string $extension = ''): string
    {
        $mimeType = strtolower(trim($mimeType));
        $extension = strtolower(trim($extension));

        if (strpos($mimeType, 'image/') === 0 || in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return 'image';
        }

        return 'document';
    }
}

if (!function_exists('chatBuildAttachmentPreviewLabel')) {
    function chatBuildAttachmentPreviewLabel(?array $attachment): string
    {
        if (!is_array($attachment) || empty($attachment['name'])) {
            return '';
        }

        $kind = (string) ($attachment['kind'] ?? chatDetectAttachmentKind((string) ($attachment['mime'] ?? ''), (string) ($attachment['ext'] ?? '')));
        $prefix = $kind === 'image' ? '[Gambar]' : '[Dokumen]';

        return trim($prefix . ' ' . (string) ($attachment['name'] ?? 'Lampiran'));
    }
}

if (!function_exists('chatCollapsePreviewText')) {
    function chatCollapsePreviewText(string $text, int $limit = 56): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($clean === '') {
            return 'Belum ada pesan';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($clean, 0, $limit, '...');
        }

        return strlen($clean) > $limit ? substr($clean, 0, max(0, $limit - 3)) . '...' : $clean;
    }
}

if (!function_exists('chatParseStoredMessagePayload')) {
    function chatParseStoredMessagePayload(string $storedMessage): array
    {
        $normalizedMessage = str_replace(["\r\n", "\r"], "\n", (string) $storedMessage);
        $marker = chatMessagePayloadMarker();

        if (strpos($normalizedMessage, $marker) !== 0) {
            return [
                'text' => $normalizedMessage,
                'attachment' => null,
                'raw' => $normalizedMessage,
            ];
        }

        $decoded = json_decode(substr($normalizedMessage, strlen($marker)), true);
        if (!is_array($decoded)) {
            return [
                'text' => $normalizedMessage,
                'attachment' => null,
                'raw' => $normalizedMessage,
            ];
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) ($decoded['text'] ?? ''));
        $attachment = is_array($decoded['attachment'] ?? null) ? $decoded['attachment'] : null;

        if ($attachment !== null) {
            $attachmentPath = chatNormalizeAttachmentRelativePath((string) ($attachment['path'] ?? ''));
            if ($attachmentPath === '') {
                $attachment = null;
            } else {
                $attachment = [
                    'name' => trim((string) ($attachment['name'] ?? 'Lampiran')),
                    'path' => $attachmentPath,
                    'mime' => strtolower(trim((string) ($attachment['mime'] ?? 'application/octet-stream'))),
                    'size' => max(0, (int) ($attachment['size'] ?? 0)),
                    'ext' => strtolower(trim((string) ($attachment['ext'] ?? pathinfo((string) ($attachment['name'] ?? ''), PATHINFO_EXTENSION)))),
                    'kind' => (string) ($attachment['kind'] ?? ''),
                ];
                $attachment['kind'] = chatDetectAttachmentKind((string) $attachment['mime'], (string) $attachment['ext']);
            }
        }

        return [
            'text' => $text,
            'attachment' => $attachment,
            'raw' => $normalizedMessage,
        ];
    }
}

if (!function_exists('chatBuildStoredMessagePayload')) {
    function chatBuildStoredMessagePayload(string $text, ?array $attachment = null): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $marker = chatMessagePayloadMarker();

        if ((!is_array($attachment) || empty($attachment['path']) || empty($attachment['name'])) && strpos($text, $marker) !== 0) {
            return $text;
        }

        $payload = [
            'text' => $text,
            'attachment' => (is_array($attachment) && !empty($attachment['path']) && !empty($attachment['name']))
                ? [
                    'name' => trim((string) ($attachment['name'] ?? 'Lampiran')),
                    'path' => chatNormalizeAttachmentRelativePath((string) ($attachment['path'] ?? '')),
                    'mime' => strtolower(trim((string) ($attachment['mime'] ?? 'application/octet-stream'))),
                    'size' => max(0, (int) ($attachment['size'] ?? 0)),
                    'ext' => strtolower(trim((string) ($attachment['ext'] ?? pathinfo((string) ($attachment['name'] ?? ''), PATHINFO_EXTENSION)))),
                    'kind' => chatDetectAttachmentKind((string) ($attachment['mime'] ?? ''), (string) ($attachment['ext'] ?? '')),
                ]
                : null,
        ];

        return $marker . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('chatPreviewTextFromStoredMessage')) {
    function chatPreviewTextFromStoredMessage(string $storedMessage, int $limit = 56): string
    {
        $payload = chatParseStoredMessagePayload($storedMessage);
        $text = trim((string) ($payload['text'] ?? ''));
        $previewBase = $text !== '' ? $text : chatBuildAttachmentPreviewLabel($payload['attachment'] ?? null);

        return chatCollapsePreviewText($previewBase, $limit);
    }
}

if (!function_exists('chatAttachmentRelativeDirectory')) {
    function chatAttachmentRelativeDirectory(int $roomId): string
    {
        return 'chat/room_' . max(1, $roomId);
    }
}

if (!function_exists('chatEnsureAttachmentDirectory')) {
    function chatEnsureAttachmentDirectory(int $roomId): array
    {
        $relativeDir = chatAttachmentRelativeDirectory($roomId);
        $absoluteDir = appPrivateStoragePath($relativeDir);

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Folder lampiran chat belum tersedia.');
        }

        if (!is_writable($absoluteDir)) {
            throw new RuntimeException('Folder lampiran chat belum bisa ditulisi di server.');
        }

        return [$absoluteDir, str_replace('\\', '/', $relativeDir)];
    }
}

if (!function_exists('chatStoreUploadedAttachment')) {
    function chatStoreUploadedAttachment(array $file, int $roomId): array
    {
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => uploadErrorMessage($uploadError, (string) ($file['name'] ?? 'Lampiran chat'), chatAttachmentMaxBytes()),
            ];
        }

        $originalName = trim((string) ($file['name'] ?? ''));
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($originalName === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return [
                'success' => false,
                'message' => 'Sumber file upload tidak valid.',
            ];
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, chatAttachmentAllowedExtensions(), true)) {
            return [
                'success' => false,
                'message' => 'Format file belum didukung. Gunakan ' . chatAttachmentAcceptedLabel() . '.',
            ];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return [
                'success' => false,
                'message' => 'File yang dipilih kosong atau gagal dibaca.',
            ];
        }

        if ($size > chatAttachmentMaxBytes()) {
            return [
                'success' => false,
                'message' => 'Ukuran file melebihi batas chat (' . formatUploadByteSize(chatAttachmentMaxBytes()) . ').',
            ];
        }

        $mimeType = detectFileMimeType($tmpName);
        if (!isAllowedUploadMimeForExtension($extension, $mimeType)) {
            return [
                'success' => false,
                'message' => $originalName . ': tipe file terdeteksi sebagai ' . $mimeType . ' dan tidak sesuai dengan format .' . $extension . '.',
            ];
        }

        try {
            [$absoluteDir, $relativeDir] = chatEnsureAttachmentDirectory($roomId);
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        try {
            $randomName = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $randomName = sha1($originalName . '|' . microtime(true) . '|' . mt_rand());
        }

        $storedName = $randomName . '.' . $extension;
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        $relativePath = $relativeDir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $absolutePath)) {
            return [
                'success' => false,
                'message' => 'Server gagal menyimpan lampiran chat.',
            ];
        }

        return [
            'success' => true,
            'attachment' => [
                'name' => $originalName,
                'path' => $relativePath,
                'mime' => $mimeType,
                'size' => $size,
                'ext' => $extension,
                'kind' => chatDetectAttachmentKind($mimeType, $extension),
            ],
        ];
    }
}

if (!function_exists('chatBuildAttachmentUrls')) {
    function chatBuildAttachmentUrls(int $messageId, array $attachment): array
    {
        $downloadUrl = pageUrl('chat_file.php?id=' . $messageId);
        $mimeType = (string) ($attachment['mime'] ?? '');
        $canInline = canDisplayTransactionFileInline($mimeType);

        $attachment['url'] = $canInline ? pageUrl('chat_file.php?id=' . $messageId . '&inline=1') : $downloadUrl;
        $attachment['download_url'] = $downloadUrl;
        $attachment['preview_url'] = ($attachment['kind'] ?? '') === 'image'
            ? pageUrl('chat_file.php?id=' . $messageId . '&inline=1')
            : '';
        $attachment['size_text'] = formatUploadByteSize((int) ($attachment['size'] ?? 0));

        return $attachment;
    }
}

if (!function_exists('chatDecorateMessageRow')) {
    function chatDecorateMessageRow(array $row): array
    {
        $payload = chatParseStoredMessagePayload((string) ($row['pesan'] ?? ''));
        $attachment = $payload['attachment'];
        $messageId = (int) ($row['id'] ?? 0);

        if ($attachment !== null && $messageId > 0) {
            $attachment = chatBuildAttachmentUrls($messageId, $attachment);
        }

        $row['message_text'] = (string) ($payload['text'] ?? '');
        $row['has_attachment'] = $attachment !== null;
        $row['attachment'] = $attachment;
        $row['preview_text'] = chatPreviewTextFromStoredMessage((string) ($row['pesan'] ?? ''));

        return $row;
    }
}
