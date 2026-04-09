        <footer class="footer-shell">
            <div class="footer-brand">
                <img src="<?= companyLogoUrl() ?>" alt="Logo perusahaan JWS Printing & Apparel" width="128" height="50">
                <div class="footer-meta">
                    <strong>JWS Printing & Apparel</strong>
                    <span>Integrated Management System</span>
                </div>
            </div>
        </footer>
    </main>
</div>

<script>
window.APP_BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
window.NOTIFICATION_ENDPOINT = <?= json_encode(pageUrl('notifikasi.php?format=json'), JSON_UNESCAPED_SLASHES) ?>;
window.NOTIFICATION_STREAM_ENDPOINT = <?= json_encode(pageUrl('notifikasi.php?format=stream'), JSON_UNESCAPED_SLASHES) ?>;
window.NOTIFICATION_REFRESH_MS = 15000;
window.WEB_PUSH_ENDPOINT = <?= json_encode(pageUrl('web_push.php'), JSON_UNESCAPED_SLASHES) ?>;
window.WEB_PUSH_PUBLIC_KEY = <?= json_encode((string) (webPushGetVapidConfig()['public_key'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
window.WEB_PUSH_CONFIGURED = <?= json_encode(!empty(webPushGetVapidConfig()['configured'])) ?>;
window.JWS_INITIAL_NOTIFICATION_PAYLOAD = <?= json_encode([
    'count' => (int) ($notificationCount ?? 0),
    'items' => array_values(is_array($notificationItems ?? null) ? $notificationItems : []),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="<?= assetUrl('js/main.js') ?>"></script>
<?php if (isset($pageState) && is_array($pageState)): ?>
<script>
window.JWS_PAGE_STATE = <?= json_encode($pageState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php endif; ?>
<?php if (isset($pageScriptUrls) && is_array($pageScriptUrls)): ?>
<?php foreach ($pageScriptUrls as $pageScriptUrl): ?>
<script src="<?= htmlspecialchars((string) $pageScriptUrl, ENT_QUOTES) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
<?php if (isset($pageJs)): ?>
<script src="<?= assetUrl('js/' . $pageJs) ?>"></script>
<?php endif; ?>
</body>
</html>
