const APP_BASE_URL = (window.APP_BASE_URL || '').replace(/\/$/, '');
const APP_CSRF_TOKEN = window.APP_CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const NOTIFICATION_ENDPOINT = window.NOTIFICATION_ENDPOINT || '';
const NOTIFICATION_STREAM_ENDPOINT = window.NOTIFICATION_STREAM_ENDPOINT || '';
const NOTIFICATION_REFRESH_MS = Number(window.NOTIFICATION_REFRESH_MS || 45000);
const BROWSER_NOTIFICATION_STATE_KEY = 'jws_browser_notification_state';
const WEB_PUSH_ENDPOINT = window.WEB_PUSH_ENDPOINT || '';
let webPushPublicKey = String(window.WEB_PUSH_PUBLIC_KEY || '').trim();
const WEB_PUSH_STATE_KEY = 'jws_web_push_state';
const MOBILE_PWA_PANEL_DISMISS_KEY = 'jws_mobile_pwa_panel_dismiss_state';
const MOBILE_SHELL_BREAKPOINT = 768;
let deferredInstallPrompt = null;
let notificationFeedPromise = null;
let notificationFeedTimer = null;
let notificationStreamSource = null;
let notificationStreamReconnectTimer = null;
let notificationStreamFailures = 0;
let notificationMarkAllPromise = null;
let lastNotificationFetchAt = 0;
let topbarClockTimer = null;
let webPushSyncPromise = null;
let webPushActionPromise = null;
const webPushListeners = [];
const webPushState = {
    supported: false,
    configured: Boolean(window.WEB_PUSH_CONFIGURED) && Boolean(webPushPublicKey),
    permission: 'default',
    browserSubscribed: false,
    serverSubscribed: false,
    active: false,
    subscriptionCount: 0,
    syncing: false,
    busyAction: '',
    message: ''
};

function buildPageUrl(path = '') {
    const normalizedPath = String(path || '').replace(/^\/+/, '');
    const basePath = `${APP_BASE_URL}/pages`;
    return normalizedPath ? `${basePath}/${normalizedPath}` : basePath;
}

function getJwsPageState(key, fallback = null) {
    const state = window.JWS_PAGE_STATE && typeof window.JWS_PAGE_STATE === 'object'
        ? window.JWS_PAGE_STATE
        : {};

    if (typeof key === 'undefined' || key === null || key === '') {
        return state;
    }

    return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback;
}

function extractUploadAjaxErrorMessage(xhr, fallbackMessage = 'Terjadi kesalahan saat upload.', options = {}) {
    const payload = xhr && xhr.responseJSON ? xhr.responseJSON : null;
    const errorJoiner = typeof options.errorJoiner === 'string' ? options.errorJoiner : '<br>';
    const tooLargeMessage = typeof options.tooLargeMessage === 'string' && options.tooLargeMessage.trim() !== ''
        ? options.tooLargeMessage
        : 'Ukuran file melebihi batas upload server/PHP.';

    if (payload) {
        if (payload.message) {
            return payload.message;
        }
        if (Array.isArray(payload.errors) && payload.errors.length) {
            return payload.errors.join(errorJoiner);
        }
        if (payload.msg) {
            return payload.msg;
        }
    }

    const status = Number(xhr && xhr.status ? xhr.status : 0);
    if (status === 413) {
        return tooLargeMessage;
    }

    const rawText = String((xhr && xhr.responseText) || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (rawText) {
        return rawText.slice(0, 240);
    }

    return fallbackMessage;
}

function getCsrfToken() {
    return APP_CSRF_TOKEN;
}

function isStateChangingMethod(method) {
    return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(String(method || 'GET').toUpperCase());
}

function isSameOriginRequest(url) {
    if (!url) return true;

    try {
        const resolvedUrl = new URL(url, window.location.href);
        return resolvedUrl.origin === window.location.origin;
    } catch (error) {
        return true;
    }
}

function ensureFormCsrfInput(form) {
    if (!form || String(form.method || '').toLowerCase() !== 'post' || !getCsrfToken()) {
        return;
    }

    let input = form.querySelector('input[name="csrf_token"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        form.prepend(input);
    }

    input.value = getCsrfToken();
}

function injectCsrfIntoForms(root = document) {
    root.querySelectorAll('form[method="POST"], form[method="post"]').forEach(ensureFormCsrfInput);
}

function setupAjaxCsrf() {
    if (!window.jQuery || !getCsrfToken()) return;

    $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
        const method = options.type || originalOptions.type || 'GET';
        const url = options.url || originalOptions.url || window.location.href;

        if (!isStateChangingMethod(method) || !isSameOriginRequest(url)) {
            return;
        }

        jqXHR.setRequestHeader('X-CSRF-Token', getCsrfToken());
        jqXHR.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    });
}

function setupFetchCsrf() {
    if (!window.fetch || !getCsrfToken() || window.__JWS_FETCH_CSRF_PATCHED__) return;

    const originalFetch = window.fetch.bind(window);
    window.__JWS_FETCH_CSRF_PATCHED__ = true;

    window.fetch = function(input, init) {
        const request = input instanceof Request ? input : null;
        const options = init ? { ...init } : {};
        const method = (options.method || request?.method || 'GET').toUpperCase();
        const url = typeof input === 'string' ? input : (request?.url || window.location.href);

        if (isStateChangingMethod(method) && isSameOriginRequest(url)) {
            const headers = new Headers(options.headers || request?.headers || {});
            headers.set('X-CSRF-Token', getCsrfToken());
            headers.set('X-Requested-With', 'XMLHttpRequest');
            options.headers = headers;
        }

        return originalFetch(input, options);
    };
}

function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark', isDark);

    document.querySelectorAll('[data-theme-icon]').forEach(icon => {
        icon.className = isDark ? 'fas fa-moon' : 'fas fa-sun';
        icon.setAttribute('data-theme-icon', 'true');
    });
}

function toggleTheme() {
    const nextTheme = document.body.classList.contains('dark') ? 'light' : 'dark';
    localStorage.setItem('theme', nextTheme);
    applyTheme(nextTheme);
}

function updateTopbarDateTime() {
    const timeElement = document.querySelector('[data-topbar-time]');
    const dateElement = document.querySelector('[data-topbar-date]');

    if (!timeElement || !dateElement) {
        return;
    }

    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const dateLabel = new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric'
    }).format(now);

    timeElement.textContent = `${hours}:${minutes}:${seconds}`;
    dateElement.textContent = dateLabel;
}

function startTopbarClock() {
    updateTopbarDateTime();

    if (topbarClockTimer) {
        window.clearInterval(topbarClockTimer);
    }

    topbarClockTimer = window.setInterval(updateTopbarDateTime, 1000);
}

function getSidebarElements() {
    return {
        sidebar: document.getElementById('sidebar'),
        overlay: document.getElementById('sidebarOverlay')
    };
}

function isStandaloneDisplayMode() {
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        return true;
    }

    return window.navigator.standalone === true;
}

function isIosDevice() {
    const userAgent = String(window.navigator.userAgent || '');
    const platform = String(window.navigator.platform || '');
    return /iphone|ipad|ipod/i.test(userAgent)
        || (/mac/i.test(platform) && Number(window.navigator.maxTouchPoints || 0) > 1);
}

function isLikelyMobileDevice() {
    const userAgent = String(window.navigator.userAgent || '');
    const coarsePointer = window.matchMedia ? window.matchMedia('(pointer: coarse)').matches : false;
    return /android|iphone|ipad|ipod|mobile/i.test(userAgent)
        || (coarsePointer && window.innerWidth <= 1024);
}

function getMobilePwaElements() {
    return {
        panel: document.getElementById('mobilePwaPanel'),
        status: document.getElementById('mobilePwaPanelStatus'),
        title: document.getElementById('mobilePwaPanelTitle'),
        text: document.getElementById('mobilePwaPanelText'),
        installAction: document.getElementById('mobilePwaInstallAction'),
        pushAction: document.getElementById('mobilePwaPushAction'),
        dismissAction: document.getElementById('mobilePwaDismissAction')
    };
}

function getMobilePwaPanelStateSignature({ isStandalone, canPromptInstall, webPush }) {
    return [
        isStandalone ? 'standalone' : 'browser',
        canPromptInstall ? 'install-ready' : 'install-idle',
        webPush.permission || 'default',
        webPush.active ? 'push-active' : 'push-inactive'
    ].join('|');
}

function getMobilePwaDismissedSignature() {
    try {
        return window.localStorage.getItem(MOBILE_PWA_PANEL_DISMISS_KEY) || '';
    } catch (error) {
        return '';
    }
}

function setMobilePwaDismissedSignature(signature) {
    try {
        if (signature) {
            window.localStorage.setItem(MOBILE_PWA_PANEL_DISMISS_KEY, signature);
            return;
        }

        window.localStorage.removeItem(MOBILE_PWA_PANEL_DISMISS_KEY);
    } catch (error) {
    }
}

function dismissMobilePwaPanel(event) {
    if (event) event.preventDefault();

    const isStandalone = isStandaloneDisplayMode();
    const signature = getMobilePwaPanelStateSignature({
        isStandalone,
        canPromptInstall: Boolean(deferredInstallPrompt) && !isStandalone,
        webPush: getWebPushState()
    });

    setMobilePwaDismissedSignature(signature);

    const { panel } = getMobilePwaElements();
    if (panel) {
        panel.hidden = true;
    }
}

function syncShellModeClasses() {
    document.body.classList.toggle('is-mobile-device', isLikelyMobileDevice());
    document.body.classList.toggle('is-standalone-pwa', isStandaloneDisplayMode());
}

function updateMobilePwaPanel() {
    const { panel, status, title, text, installAction, pushAction, dismissAction } = getMobilePwaElements();
    if (!panel || !status || !title || !text || !installAction || !pushAction || !dismissAction) {
        return;
    }

    syncShellModeClasses();

    const isMobile = isLikelyMobileDevice() && window.innerWidth <= MOBILE_SHELL_BREAKPOINT;
    const isStandalone = isStandaloneDisplayMode();
    const webPush = getWebPushState();
    const pushReady = Boolean(webPush.supported) && Boolean(webPush.configured);
    const canPromptInstall = Boolean(deferredInstallPrompt) && !isStandalone;
    const panelStateSignature = getMobilePwaPanelStateSignature({
        isStandalone,
        canPromptInstall,
        webPush
    });
    const isDismissed = getMobilePwaDismissedSignature() === panelStateSignature;
    const shouldHidePanel = !isMobile || (isStandalone && webPush.active) || isDismissed;

    panel.hidden = shouldHidePanel;
    if (shouldHidePanel) {
        return;
    }

    let statusText = 'Mode Mobile';
    let titleText = 'Pasang aplikasi untuk akses mobile yang lebih nyaman';
    let descriptionText = 'Install PWA lalu aktifkan notifikasi agar chat dan pekerjaan penting tetap masuk saat aplikasi berjalan di background.';

    if (webPush.permission === 'denied') {
        statusText = 'Izin Ditolak';
        titleText = isStandalone
            ? 'Aktifkan izin notifikasi agar PWA bisa memberi update background'
            : 'Install aplikasi sudah siap, tinggal izinkan notifikasi browser';
        descriptionText = 'Buka ikon gembok atau pengaturan situs di browser, ubah Notifications menjadi Allow, lalu muat ulang aplikasi.';
    } else if (isStandalone && !webPush.active) {
        statusText = 'Background';
        titleText = 'PWA sudah terpasang, aktifkan notifikasi background';
        descriptionText = 'Sekali izin saja cukup. Setelah aktif, update penting bisa muncul walau aplikasi sedang ditutup atau berjalan di background.';
    } else if (!isStandalone && webPush.active) {
        statusText = 'Notifikasi Aktif';
        titleText = 'Notifikasi perangkat ini sudah aktif';
        descriptionText = canPromptInstall
            ? 'Pasang PWA supaya pengalaman mobile lebih stabil dan akses aplikasi lebih cepat dari home screen.'
            : 'Notifikasi background sudah siap. Jika ingin akses lebih cepat, pasang aplikasi dari menu browser atau tambahkan ke layar utama.';
    } else if (!canPromptInstall && !isStandalone && isIosDevice()) {
        statusText = 'Tambah ke Home';
        titleText = 'Install PWA dari menu Share di iPhone atau iPad';
        descriptionText = 'Buka menu Share Safari lalu pilih Add to Home Screen. Setelah terpasang, aktifkan notifikasi agar update tetap masuk di background.';
    } else if (!canPromptInstall && !isStandalone) {
        statusText = 'Mode Mobile';
        titleText = 'Pasang aplikasi dari menu browser jika tombol install belum muncul';
        descriptionText = 'Gunakan menu browser seperti Install App atau Tambahkan ke layar utama. Setelah itu aktifkan notifikasi background untuk update real-time.';
    }

    if (!pushReady) {
        descriptionText = 'Server Web Push belum siap, jadi notifikasi background belum bisa diaktifkan dari perangkat ini.';
    } else if (webPush.message) {
        descriptionText = `${descriptionText} ${String(webPush.message).trim()}`.trim();
    }

    status.textContent = statusText;
    title.textContent = titleText;
    text.textContent = descriptionText;
    dismissAction.hidden = false;

    installAction.hidden = !canPromptInstall;

    pushAction.hidden = !pushReady || webPush.active;
    pushAction.disabled = Boolean(webPush.busyAction);

    if (!pushAction.hidden) {
        if (webPush.busyAction === 'subscribe') {
            pushAction.textContent = 'Mengaktifkan...';
        } else if (webPush.permission === 'denied') {
            pushAction.textContent = 'Cara Aktifkan';
        } else if (webPush.permission === 'granted') {
            pushAction.textContent = 'Sinkronkan Notifikasi';
        } else {
            pushAction.textContent = 'Aktifkan Notifikasi';
        }
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalizeSearchText(value) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

function formatIdNumber(value, options = {}) {
    const number = Number(value || 0);
    const minimumFractionDigits = typeof options.minimumFractionDigits === 'number' ? options.minimumFractionDigits : 0;
    const maximumFractionDigits = typeof options.maximumFractionDigits === 'number' ? options.maximumFractionDigits : 0;

    return number.toLocaleString('id-ID', {
        minimumFractionDigits,
        maximumFractionDigits
    });
}

function formatIdCurrency(value, options = {}) {
    const amount = Number(value || 0);
    const minimumFractionDigits = typeof options.minimumFractionDigits === 'number' ? options.minimumFractionDigits : 0;
    const maximumFractionDigits = typeof options.maximumFractionDigits === 'number' ? options.maximumFractionDigits : 0;

    if (options.signed) {
        return `${amount < 0 ? '-Rp ' : 'Rp '}${Math.abs(amount).toLocaleString('id-ID', {
            minimumFractionDigits,
            maximumFractionDigits
        })}`;
    }

    return `Rp ${amount.toLocaleString('id-ID', {
        minimumFractionDigits,
        maximumFractionDigits
    })}`;
}

function formatIdQuantity(value, options = {}) {
    const number = Number(value || 0);

    if (options.preserveIntegers && Number.isInteger(number)) {
        return number.toLocaleString('id-ID');
    }

    return number.toLocaleString('id-ID', {
        minimumFractionDigits: typeof options.minimumFractionDigits === 'number' ? options.minimumFractionDigits : 0,
        maximumFractionDigits: typeof options.maximumFractionDigits === 'number' ? options.maximumFractionDigits : 3
    });
}

function formatIdDateTime(value, options = {}) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return options.emptyLabel || '-';
    }

    const parsed = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
    return Number.isNaN(parsed.getTime())
        ? raw
        : parsed.toLocaleString('id-ID', options.localeOptions);
}

function getNotificationElements() {
    return {
        dropdown: document.getElementById('notificationDropdown'),
        toggle: document.querySelector('.notification-toggle'),
        badge: document.getElementById('notificationCountBadge'),
        summary: document.getElementById('notificationDropdownSummary'),
        body: document.getElementById('notificationDropdownBody'),
        sync: document.getElementById('notificationDropdownSync'),
        browserState: document.getElementById('browserNotificationState'),
        browserAction: document.getElementById('browserNotificationAction'),
        markAllButton: document.getElementById('notificationDropdownMarkAllButton')
    };
}

function getNotificationBadgeClass(tone) {
    return ({
        danger: 'badge-danger',
        warning: 'badge-warning',
        info: 'badge-info',
        success: 'badge-success'
    })[tone] || 'badge-secondary';
}

function buildNotificationPreviewMarkup(item) {
    return `
        <a href="${escapeHtml(item.href || buildPageUrl('notifikasi.php'))}" class="notification-preview">
            <span class="notification-preview-icon tone-${escapeHtml(item.tone || 'info')}">
                <i class="fas ${escapeHtml(item.icon || 'fa-bell')}"></i>
            </span>
            <span class="notification-preview-copy">
                <span class="notification-preview-title">${escapeHtml(item.title || '')}</span>
                <span class="notification-preview-text">${escapeHtml(item.message || '')}</span>
            </span>
            <span class="badge ${getNotificationBadgeClass(item.tone)}">${Number(item.count || 0).toLocaleString('id-ID')}</span>
        </a>`;
}

function getNotificationActionEndpoint() {
    return buildPageUrl('notifikasi.php');
}

function renderNotificationDropdown(payload) {
    const { badge, summary, body, sync, markAllButton } = getNotificationElements();
    if (!summary || !body || !badge || !sync) return;

    const totalCount = Number(payload.count || 0);
    const badgeText = totalCount > 99 ? '99+' : String(totalCount);
    badge.textContent = badgeText;
    badge.hidden = totalCount <= 0;

    summary.textContent = totalCount > 0
        ? `${totalCount.toLocaleString('id-ID')} antrian aktif`
        : 'Tidak ada antrian aktif';

    sync.textContent = payload.generated_label
        ? `Sinkron ${payload.generated_label}`
        : 'Sinkron saat halaman dibuka';

    if (markAllButton) {
        markAllButton.hidden = totalCount <= 0;
        markAllButton.disabled = totalCount <= 0 || Boolean(notificationMarkAllPromise);
    }

    if (Array.isArray(payload.items) && payload.items.length > 0) {
        body.innerHTML = payload.items.map(buildNotificationPreviewMarkup).join('');
        return;
    }

    body.innerHTML = `
        <div class="notification-empty">
            <i class="fas fa-check-circle"></i>
            <div>Semua antrian utama sedang aman.</div>
        </div>`;
}

function dispatchNotificationFeed(payload) {
    document.dispatchEvent(new CustomEvent('jws:notification-feed', {
        detail: payload || {}
    }));
}

function applyNotificationFeedPayload(payload, options = {}) {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    renderNotificationDropdown(payload);
    maybeDispatchBrowserNotification(payload);
    lastNotificationFetchAt = Date.now();

    if (!options.skipEventDispatch) {
        dispatchNotificationFeed(payload);
    }

    return payload;
}

async function markAllNotificationsAsRead() {
    const endpoint = getNotificationActionEndpoint();
    const { sync, markAllButton } = getNotificationElements();
    if (!endpoint || notificationMarkAllPromise) {
        return null;
    }

    const formData = new FormData();
    formData.append('action', 'mark_all_read');

    notificationMarkAllPromise = fetch(endpoint, {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    }).then(async response => {
        if (!response.ok) {
            throw new Error('Status notifikasi tidak bisa diperbarui');
        }

        const payload = await response.json();
        if (!payload || !payload.success || !payload.payload) {
            throw new Error(payload && payload.msg ? payload.msg : 'Status notifikasi belum tersimpan.');
        }

        applyNotificationFeedPayload(payload.payload);
        if (sync) {
            sync.textContent = payload.msg || 'Semua notifikasi ditandai sudah dibaca.';
        }

        return payload;
    }).catch(error => {
        if (sync) {
            sync.textContent = error.message || 'Gagal menandai notifikasi.';
        }
        return null;
    }).finally(() => {
        notificationMarkAllPromise = null;
        if (markAllButton) {
            markAllButton.disabled = markAllButton.hidden;
        }
    });

    if (sync) {
        sync.textContent = 'Menandai semua notifikasi...';
    }
    if (markAllButton) {
        markAllButton.disabled = true;
    }

    return notificationMarkAllPromise;
}

function isBrowserNotificationSupported() {
    return 'Notification' in window && 'serviceWorker' in navigator;
}

function loadBrowserNotificationState() {
    try {
        return JSON.parse(localStorage.getItem(BROWSER_NOTIFICATION_STATE_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function saveBrowserNotificationState(nextState) {
    const current = loadBrowserNotificationState();
    localStorage.setItem(BROWSER_NOTIFICATION_STATE_KEY, JSON.stringify({
        ...current,
        ...nextState
    }));
}

function loadWebPushLocalState() {
    try {
        return JSON.parse(localStorage.getItem(WEB_PUSH_STATE_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function saveWebPushLocalState(nextState) {
    const current = loadWebPushLocalState();
    localStorage.setItem(WEB_PUSH_STATE_KEY, JSON.stringify({
        ...current,
        ...nextState
    }));
}

function getWebPushPublicKey() {
    return String(webPushPublicKey || '').trim();
}

function setWebPushRuntimeConfig(config = {}) {
    const nextPublicKey = String(config.public_key || config.publicKey || '').trim();
    if (nextPublicKey) {
        webPushPublicKey = nextPublicKey;
        window.WEB_PUSH_PUBLIC_KEY = nextPublicKey;
    }

    if (Object.prototype.hasOwnProperty.call(config, 'configured')) {
        window.WEB_PUSH_CONFIGURED = Boolean(config.configured);
    }
}

function emitWebPushState(nextState = {}) {
    Object.assign(webPushState, nextState);
    updateBrowserNotificationUi();
    updateMobilePwaPanel();
    webPushListeners.forEach(listener => {
        try {
            listener({ ...webPushState });
        } catch (error) {
        }
    });
}

function getWebPushState() {
    return { ...webPushState };
}

function onWebPushChange(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }

    webPushListeners.push(listener);
    listener(getWebPushState());

    return () => {
        const index = webPushListeners.indexOf(listener);
        if (index >= 0) {
            webPushListeners.splice(index, 1);
        }
    };
}

function isWebPushSupported() {
    return Boolean(WEB_PUSH_ENDPOINT) && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
}

function base64UrlToUint8Array(value) {
    const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    const padding = normalized.length % 4 ? '='.repeat(4 - (normalized.length % 4)) : '';
    const binary = window.atob(normalized + padding);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

function uint8ArrayToBase64Url(value) {
    let binary = '';
    value.forEach(byte => {
        binary += String.fromCharCode(byte);
    });

    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

async function getServiceWorkerRegistration() {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    const scope = `${APP_BASE_URL || ''}/`;
    const existing = await navigator.serviceWorker.getRegistration(scope);
    if (existing) {
        return existing;
    }

    return navigator.serviceWorker.ready;
}

async function getCurrentPushSubscription() {
    const registration = await getServiceWorkerRegistration();
    if (!registration || !registration.pushManager) {
        return null;
    }

    return registration.pushManager.getSubscription();
}

async function callWebPushApi(action, payload = {}, method = 'POST') {
    if (!WEB_PUSH_ENDPOINT) {
        throw new Error('Endpoint Web Push belum tersedia.');
    }

    if (String(method).toUpperCase() === 'GET') {
        const url = new URL(WEB_PUSH_ENDPOINT, window.location.origin);
        url.searchParams.set('action', action);

        const response = await fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.success === false) {
            throw new Error(result.message || 'Permintaan Web Push gagal.');
        }

        return result;
    }

    const body = new FormData();
    body.append('action', action);
    Object.entries(payload || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        if (typeof value === 'object') {
            body.append(key, JSON.stringify(value));
            return;
        }

        body.append(key, String(value));
    });

    const response = await fetch(WEB_PUSH_ENDPOINT, {
        method: 'POST',
        body
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.success === false) {
        throw new Error(result.message || 'Permintaan Web Push gagal.');
    }

    return result;
}

async function upsertCurrentSubscription(options = {}) {
    const subscription = options.subscription || await getCurrentPushSubscription();
    if (!subscription) {
        return null;
    }

    return callWebPushApi('subscribe', {
        subscription_json: subscription.toJSON(),
        device_label: `${navigator.platform || 'browser'} / ${navigator.language || 'id'}`
    });
}

async function refreshWebPushState(options = {}) {
    if (webPushSyncPromise) {
        return webPushSyncPromise;
    }

    webPushSyncPromise = (async () => {
        const supported = isWebPushSupported();
        const permission = supported ? Notification.permission : 'unsupported';
        let browserSubscribed = false;
        let subscription = null;

        emitWebPushState({
            supported,
            permission,
            syncing: true,
            message: options.message || webPushState.message || ''
        });

        if (supported && permission === 'granted') {
            try {
                subscription = await getCurrentPushSubscription();
                browserSubscribed = Boolean(subscription);
                if (browserSubscribed && options.syncBrowser !== false && getWebPushPublicKey()) {
                    await upsertCurrentSubscription({ subscription });
                }
            } catch (error) {
                browserSubscribed = false;
            }
        }

        let serverState = null;
        try {
            serverState = await callWebPushApi('status', {}, 'GET');
        } catch (error) {
            emitWebPushState({
                syncing: false,
                browserSubscribed,
                serverSubscribed: false,
                active: false,
                message: error.message || 'Gagal memuat status Web Push.'
            });
            return getWebPushState();
        }

        setWebPushRuntimeConfig({
            public_key: serverState.public_key || '',
            configured: Boolean(serverState.configured)
        });

        const active = Boolean(serverState.has_subscription) && browserSubscribed && permission === 'granted' && Boolean(serverState.configured);
        saveWebPushLocalState({
            active,
            endpoint: subscription?.endpoint || ''
        });

        emitWebPushState({
            supported,
            configured: Boolean(serverState.configured) && Boolean(getWebPushPublicKey()),
            permission,
            browserSubscribed,
            serverSubscribed: Boolean(serverState.has_subscription),
            active,
            subscriptionCount: Number(serverState.subscription_count || 0),
            syncing: false,
            message: options.message || serverState.message || ''
        });

        return getWebPushState();
    })().finally(() => {
        webPushSyncPromise = null;
    });

    return webPushSyncPromise;
}

async function enableWebPush() {
    if (webPushActionPromise) {
        return webPushActionPromise;
    }

    webPushActionPromise = (async () => {
        if (!isWebPushSupported()) {
            throw new Error('Browser ini belum mendukung Web Push.');
        }

        if (!getWebPushPublicKey()) {
            await refreshWebPushState({
                syncBrowser: false,
                message: 'Memuat konfigurasi Web Push dari server...'
            });
        }

        const publicKey = getWebPushPublicKey();
        if (!publicKey) {
            throw new Error('VAPID public key belum tersedia.');
        }

        emitWebPushState({ busyAction: 'subscribe', message: 'Menyiapkan Web Push...' });

        let permission = Notification.permission;
        if (permission === 'default') {
            permission = await Notification.requestPermission();
        }

        if (permission !== 'granted') {
            await refreshWebPushState({ syncBrowser: false, message: permission === 'denied' ? 'Izin notifikasi ditolak oleh browser.' : 'Izin notifikasi belum diberikan.' });
            return getWebPushState();
        }

        const registration = await getServiceWorkerRegistration();
        if (!registration || !registration.pushManager) {
            throw new Error('Service worker belum siap.');
        }

        let subscription = await registration.pushManager.getSubscription();
        if (subscription?.options?.applicationServerKey) {
            const currentKey = uint8ArrayToBase64Url(new Uint8Array(subscription.options.applicationServerKey));
            if (currentKey && currentKey !== publicKey) {
                await subscription.unsubscribe();
                subscription = null;
            }
        }

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(publicKey)
            });
        }

        await upsertCurrentSubscription({ subscription });
        return refreshWebPushState({ syncBrowser: false, message: 'Web Push aktif di perangkat ini.' });
    })().finally(() => {
        emitWebPushState({ busyAction: '' });
        webPushActionPromise = null;
    });

    return webPushActionPromise;
}

async function disableWebPush() {
    if (webPushActionPromise) {
        return webPushActionPromise;
    }

    webPushActionPromise = (async () => {
        emitWebPushState({ busyAction: 'unsubscribe', message: 'Mematikan Web Push...' });

        const subscription = await getCurrentPushSubscription();
        const localState = loadWebPushLocalState();
        const endpoint = subscription?.endpoint || localState.endpoint || '';

        if (endpoint) {
            try {
                await callWebPushApi('unsubscribe', {
                    endpoint,
                    subscription_json: subscription ? subscription.toJSON() : ''
                });
            } catch (error) {
            }
        }

        if (subscription) {
            await subscription.unsubscribe().catch(() => {});
        }

        saveWebPushLocalState({ active: false, endpoint: '' });
        return refreshWebPushState({ syncBrowser: false, message: 'Web Push dimatikan untuk perangkat ini.' });
    })().finally(() => {
        emitWebPushState({ busyAction: '' });
        webPushActionPromise = null;
    });

    return webPushActionPromise;
}

async function sendWebPushTest() {
    if (webPushActionPromise) {
        return webPushActionPromise;
    }

    webPushActionPromise = (async () => {
        emitWebPushState({ busyAction: 'test', message: 'Mengirim test push...' });
        const subscription = await getCurrentPushSubscription();
        const response = await callWebPushApi('test', {
            subscription_json: subscription ? subscription.toJSON() : ''
        });
        await refreshWebPushState({ syncBrowser: false, message: response.message || 'Test push sudah dikirim.' });
        return response;
    })().finally(() => {
        emitWebPushState({ busyAction: '' });
        webPushActionPromise = null;
    });

    return webPushActionPromise;
}

function updateBrowserNotificationUi() {
    const { browserState, browserAction } = getNotificationElements();
    if (!browserState || !browserAction) return;

    const state = getWebPushState();
    if (!state.supported) {
        browserState.hidden = false;
        browserState.textContent = 'Web Push tidak didukung';
        browserAction.hidden = true;
        return;
    }

    browserState.hidden = false;
    browserAction.hidden = false;
    browserAction.disabled = Boolean(state.busyAction);

    if (!state.configured) {
        browserState.textContent = 'Web Push belum siap di server';
        browserAction.hidden = true;
        return;
    }

    if (state.busyAction === 'subscribe') {
        browserState.textContent = 'Mengaktifkan Web Push...';
        browserAction.textContent = 'Mengaktifkan...';
        return;
    }

    if (state.busyAction === 'unsubscribe') {
        browserState.textContent = 'Mematikan Web Push...';
        browserAction.textContent = 'Mematikan...';
        return;
    }

    if (state.busyAction === 'test') {
        browserState.textContent = 'Mengirim test push...';
        browserAction.textContent = 'Tunggu...';
        return;
    }

    if (state.permission === 'denied') {
        browserState.textContent = 'Izin notifikasi browser ditolak';
        browserAction.textContent = 'Cara aktifkan';
        return;
    }

    if (state.active) {
        browserState.textContent = 'Web Push aktif di perangkat ini';
        browserAction.textContent = 'Matikan';
        return;
    }

    if (state.permission === 'granted' && state.browserSubscribed && !state.serverSubscribed) {
        browserState.textContent = 'Web Push perlu disinkronkan ulang';
        browserAction.textContent = 'Sinkronkan';
        return;
    }

    browserState.textContent = 'Web Push belum aktif';
    browserAction.textContent = 'Aktifkan';
}

async function showBrowserNotification(title, options = {}) {
    if (!isBrowserNotificationSupported() || Notification.permission !== 'granted') {
        return false;
    }

    const payload = {
        body: options.body || '',
        icon: options.icon || `${APP_BASE_URL}/public/img/pwa-icon-192.png`,
        badge: options.badge || `${APP_BASE_URL}/public/img/pwa-icon-192.png`,
        tag: options.tag || `jws-${Date.now()}`,
        data: {
            url: options.url || window.location.href
        }
    };

    try {
        const registration = await navigator.serviceWorker.ready;
        await registration.showNotification(title, payload);
        return true;
    } catch (error) {
        try {
            new Notification(title, payload);
            return true;
        } catch (notificationError) {
            return false;
        }
    }
}

function maybeDispatchBrowserNotification(payload) {
    if (!payload || !Array.isArray(payload.items) || !isBrowserNotificationSupported()) {
        return;
    }

    const localWebPush = loadWebPushLocalState();
    if (webPushState.active || localWebPush.active) {
        return;
    }

    const chatItem = payload.items.find(item => item.kind === 'chat' || /chat\.php/i.test(String(item.href || '')));
    if (!chatItem) {
        return;
    }

    const latestUnreadId = Number(chatItem.meta?.latest_unread_id || 0);
    const currentState = loadBrowserNotificationState();
    const previousUnreadId = Number(currentState.chat_latest_unread_id || 0);

    saveBrowserNotificationState({
        chat_latest_unread_id: Math.max(previousUnreadId, latestUnreadId)
    });

    if (Notification.permission !== 'granted' || latestUnreadId <= previousUnreadId) {
        return;
    }

    if (!document.hidden && document.hasFocus()) {
        return;
    }

    const sender = String(chatItem.meta?.latest_sender || '').trim();
    const body = sender
        ? `${sender}: ${String(chatItem.meta?.latest_message || chatItem.message || '').trim()}`
        : String(chatItem.message || 'Ada pesan chat baru').trim();

    showBrowserNotification(chatItem.title || 'Pesan chat baru', {
        body,
        tag: `jws-chat-${latestUnreadId}`,
        url: buildPageUrl('chat.php')
    });
}

async function requestBrowserNotificationPermission() {
    if (!isWebPushSupported()) {
        emitWebPushState({ message: 'Browser ini belum mendukung Web Push.' });
        return;
    }

    if (Notification.permission === 'denied') {
        alert('Izin notifikasi sedang ditolak. Buka ikon gembok atau site settings di browser Anda, ubah Notifications menjadi Allow, lalu refresh halaman.');
        return;
    }

    try {
        const currentState = getWebPushState();
        if (currentState.active) {
            await disableWebPush();
        } else {
            await enableWebPush();
        }
        ensureNotificationFeedFresh(true);
    } catch (error) {
        emitWebPushState({ message: error.message || 'Web Push gagal diproses.' });
    }
}

async function fetchNotificationFeed(options = {}) {
    if (!NOTIFICATION_ENDPOINT) return null;
    if (notificationFeedPromise) return notificationFeedPromise;

    notificationFeedPromise = fetch(NOTIFICATION_ENDPOINT, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
    }).then(async response => {
        if (!response.ok) {
            throw new Error('Gagal memuat notifikasi');
        }

        const payload = await response.json();
        return applyNotificationFeedPayload(payload);
    }).catch(() => {
        if (!options.silent) {
            const { sync } = getNotificationElements();
            if (sync) {
                sync.textContent = 'Gagal menyinkronkan notifikasi';
            }
        }
        return null;
    }).finally(() => {
        notificationFeedPromise = null;
    });

    return notificationFeedPromise;
}

function ensureNotificationFeedFresh(force = false) {
    const isStale = Date.now() - lastNotificationFetchAt > Math.max(15000, Math.floor(NOTIFICATION_REFRESH_MS / 2));
    if (force || isStale) {
        fetchNotificationFeed({ silent: !force });
    }
}

function stopNotificationFeedRealtime() {
    if (notificationStreamReconnectTimer) {
        window.clearTimeout(notificationStreamReconnectTimer);
        notificationStreamReconnectTimer = null;
    }

    if (notificationStreamSource) {
        notificationStreamSource.close();
        notificationStreamSource = null;
    }
}

function startNotificationFeedRealtime() {
    if (!NOTIFICATION_STREAM_ENDPOINT || typeof window.EventSource !== 'function' || notificationStreamSource) {
        return false;
    }

    const source = new EventSource(NOTIFICATION_STREAM_ENDPOINT);
    notificationStreamSource = source;

    source.addEventListener('open', () => {
        notificationStreamFailures = 0;
        lastNotificationFetchAt = Date.now();
        const { sync } = getNotificationElements();
        if (sync) {
            sync.textContent = 'Realtime aktif';
        }
    });

    source.addEventListener('notifications', event => {
        try {
            const payload = JSON.parse(event.data || '{}');
            applyNotificationFeedPayload(payload);
            const { sync } = getNotificationElements();
            if (sync) {
                sync.textContent = payload.generated_label
                    ? `Realtime ${payload.generated_label}`
                    : 'Realtime aktif';
            }
        } catch (error) {
        }
    });

    source.addEventListener('ping', event => {
        lastNotificationFetchAt = Date.now();
        try {
            const payload = JSON.parse(event.data || '{}');
            const { sync } = getNotificationElements();
            if (sync && payload.generated_label) {
                sync.textContent = `Realtime ${payload.generated_label}`;
            }
        } catch (error) {
        }
    });

    source.addEventListener('error', () => {
        notificationStreamFailures += 1;
        stopNotificationFeedRealtime();

        if (notificationStreamFailures >= 3) {
            ensureNotificationFeedFresh(false);
            return;
        }

        notificationStreamReconnectTimer = window.setTimeout(() => {
            startNotificationFeedRealtime();
        }, Math.min(5000, 1500 * notificationStreamFailures));
    });

    return true;
}

function startNotificationFeedPolling() {
    if (!NOTIFICATION_ENDPOINT || notificationFeedTimer) return;

    notificationFeedTimer = window.setInterval(() => {
        if (!document.hidden) {
            ensureNotificationFeedFresh(false);
        }
    }, NOTIFICATION_REFRESH_MS);
}

function setSidebarState(isOpen) {
    const { sidebar, overlay } = getSidebarElements();
    if (!sidebar || !overlay) return;

    ['active', 'show'].forEach(cls => {
        sidebar.classList.toggle(cls, isOpen);
        overlay.classList.toggle(cls, isOpen);
    });

    document.body.classList.toggle('sidebar-open', Boolean(isOpen) && window.innerWidth <= 1024);

    document.querySelectorAll('.btn-toggle-sidebar').forEach(button => {
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}

function toggleSidebar(event) {
    if (event) event.preventDefault();
    if (window.innerWidth > 1024) return;

    const { sidebar } = getSidebarElements();
    if (!sidebar) return;

    const isOpen = sidebar.classList.contains('active') || sidebar.classList.contains('show');
    setSidebarState(!isOpen);
}

function closeSidebar(event) {
    if (event) event.preventDefault();
    setSidebarState(false);
}

function closeNotificationMenu() {
    const dropdown = document.getElementById('notificationDropdown');
    const toggle = document.querySelector('.notification-toggle');
    if (!dropdown || !toggle) return;

    dropdown.classList.remove('show');
    toggle.setAttribute('aria-expanded', 'false');
}

function toggleNotificationMenu(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const dropdown = document.getElementById('notificationDropdown');
    const toggle = document.querySelector('.notification-toggle');
    if (!dropdown || !toggle) return;

    const willShow = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willShow);
    toggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');

    if (willShow) {
        ensureNotificationFeedFresh(true);
    }
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('show');
        injectCsrfIntoForms(el);
    }
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

function confirmDelete(form, message) {
    if (!form) return false;

    const confirmed = confirm(message || 'Yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan.');
    if (confirmed) form.submit();
    return false;
}

function updateInstallButtons() {
    const shouldShowInstallPrompt = Boolean(deferredInstallPrompt) && !isStandaloneDisplayMode();
    document.querySelectorAll('[data-pwa-install]').forEach(button => {
        button.hidden = !shouldShowInstallPrompt;
    });
    updateMobilePwaPanel();
}

async function handleInstallClick(event) {
    if (event) event.preventDefault();
    if (!deferredInstallPrompt) return;

    deferredInstallPrompt.prompt();
    try {
        await deferredInstallPrompt.userChoice;
    } finally {
        deferredInstallPrompt = null;
        updateInstallButtons();
    }
}

function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    const swQuery = new URLSearchParams({ v: '20260408-webpush-session' });
    if (getWebPushPublicKey()) {
        swQuery.set('vapid', getWebPushPublicKey());
    }

    const swUrl = `${APP_BASE_URL}/sw.js?${swQuery.toString()}`;
    navigator.serviceWorker.register(swUrl, { scope: `${APP_BASE_URL || ''}/` }).then(registration => {
        registration.update().catch(() => {
        });
    }).catch(() => {
    });

    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data?.type === 'web-push-received') {
            ensureNotificationFeedFresh(true);
            return;
        }

        if (event.data?.type === 'web-push-subscription-updated') {
            refreshWebPushState({
                syncBrowser: true,
                message: event.data?.message || 'Langganan notifikasi background diperbarui otomatis.'
            }).catch(() => {});
            return;
        }

        if (event.data?.type === 'web-push-subscription-refresh-required') {
            refreshWebPushState({
                syncBrowser: true,
                message: event.data?.message || 'Langganan notifikasi perlu diperbarui ulang.'
            }).catch(() => {});
        }
    });
}

document.addEventListener('click', event => {
    if (window.innerWidth <= 1024) {
        const { sidebar } = getSidebarElements();
        if (sidebar && !sidebar.contains(event.target) && !event.target.closest('.btn-toggle-sidebar')) {
            closeSidebar();
        }
    }

    if (!event.target.closest('.notification-center')) {
        closeNotificationMenu();
    }

    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('show');
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeSidebar();
        closeNotificationMenu();
        document.querySelectorAll('.modal-overlay.show').forEach(modal => modal.classList.remove('show'));
    }
});

document.addEventListener('submit', event => {
    if (event.target instanceof HTMLFormElement) {
        ensureFormCsrfInput(event.target);
    }

    if (event.target.matches('form.confirm-delete')) {
        event.preventDefault();
        confirmDelete(event.target);
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth > 1024) {
        closeSidebar();
    }

    syncShellModeClasses();
    updateInstallButtons();
});

window.addEventListener('pageshow', () => {
    if (window.innerWidth <= 1024) {
        closeSidebar();
    }

    syncShellModeClasses();
    updateInstallButtons();
    updateTopbarDateTime();
    ensureNotificationFeedFresh(false);
});

window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredInstallPrompt = event;
    updateInstallButtons();
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    updateInstallButtons();
    updateMobilePwaPanel();
});

window.addEventListener('focus', () => {
    syncShellModeClasses();
    updateInstallButtons();
    updateTopbarDateTime();
    ensureNotificationFeedFresh(false);
});

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopNotificationFeedRealtime();
        return;
    }

    syncShellModeClasses();
    updateTopbarDateTime();
    startNotificationFeedRealtime();
    ensureNotificationFeedFresh(false);
});

window.addEventListener('beforeunload', () => {
    stopNotificationFeedRealtime();
});

document.addEventListener('DOMContentLoaded', () => {
    injectCsrfIntoForms();
    setupAjaxCsrf();
    setupFetchCsrf();

    const savedTheme = localStorage.getItem('theme') || 'light';
    applyTheme(savedTheme);
    startTopbarClock();

    if (window.innerWidth <= 1024) {
        closeSidebar();
    }

    syncShellModeClasses();

    document.querySelectorAll('.alert[data-dismiss]').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity .3s';
            setTimeout(() => alert.remove(), 300);
        }, 4000);
    });

    document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth <= 1024) closeSidebar();
        });
    });

    document.querySelectorAll('[data-pwa-install]').forEach(button => {
        button.addEventListener('click', handleInstallClick);
    });

    const { browserAction } = getNotificationElements();
    if (browserAction) {
        browserAction.addEventListener('click', requestBrowserNotificationPermission);
    }

    const { markAllButton } = getNotificationElements();
    if (markAllButton) {
        markAllButton.addEventListener('click', markAllNotificationsAsRead);
    }

    const { pushAction } = getMobilePwaElements();
    if (pushAction) {
        pushAction.addEventListener('click', requestBrowserNotificationPermission);
    }

    const { dismissAction } = getMobilePwaElements();
    if (dismissAction) {
        dismissAction.addEventListener('click', dismissMobilePwaPanel);
    }

    if (window.JWS_INITIAL_NOTIFICATION_PAYLOAD && typeof window.JWS_INITIAL_NOTIFICATION_PAYLOAD === 'object') {
        applyNotificationFeedPayload(window.JWS_INITIAL_NOTIFICATION_PAYLOAD, {
            skipEventDispatch: true
        });
    }

    updateInstallButtons();
    refreshWebPushState({ syncBrowser: true });
    startNotificationFeedPolling();
    startNotificationFeedRealtime();
    ensureNotificationFeedFresh(false);
    updateMobilePwaPanel();
});

injectCsrfIntoForms();
setupAjaxCsrf();
registerServiceWorker();
setupFetchCsrf();
syncShellModeClasses();

if (window.matchMedia) {
    const standaloneModeQuery = window.matchMedia('(display-mode: standalone)');
    if (typeof standaloneModeQuery.addEventListener === 'function') {
        standaloneModeQuery.addEventListener('change', () => {
            syncShellModeClasses();
            updateInstallButtons();
        });
    } else if (typeof standaloneModeQuery.addListener === 'function') {
        standaloneModeQuery.addListener(() => {
            syncShellModeClasses();
            updateInstallButtons();
        });
    }
}

window.applyTheme = applyTheme;
window.toggleTheme = toggleTheme;
window.toggleSidebar = toggleSidebar;
window.closeSidebar = closeSidebar;
window.toggleNotificationMenu = toggleNotificationMenu;
window.closeNotificationMenu = closeNotificationMenu;
window.openModal = openModal;
window.closeModal = closeModal;
window.confirmDelete = confirmDelete;
window.dismissMobilePwaPanel = dismissMobilePwaPanel;
window.getCsrfToken = getCsrfToken;
window.getJwsPageState = getJwsPageState;
window.jwsPageUrl = buildPageUrl;
window.jwsEscapeHtml = escapeHtml;
window.jwsNormalizeSearchText = normalizeSearchText;
window.jwsFormatNumber = formatIdNumber;
window.jwsFormatCurrency = formatIdCurrency;
window.jwsFormatQuantity = formatIdQuantity;
window.jwsFormatDateTime = formatIdDateTime;
window.jwsExtractUploadErrorMessage = extractUploadAjaxErrorMessage;
window.ensureNotificationFeedFresh = ensureNotificationFeedFresh;
window.applyNotificationFeedPayload = applyNotificationFeedPayload;
window.requestBrowserNotificationPermission = requestBrowserNotificationPermission;
window.jwsWebPush = {
    refresh: refreshWebPushState,
    subscribe: enableWebPush,
    unsubscribe: disableWebPush,
    sendTest: sendWebPushTest,
    onChange: onWebPushChange,
    getState: getWebPushState
};
