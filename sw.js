const CACHE_NAME = 'jws-pwa-v5';
const BASE_PATH = self.location.pathname.replace(/\/sw\.js$/, '');
const SERVICE_WORKER_URL = new URL(self.location.href);
const WEB_PUSH_PUBLIC_KEY = SERVICE_WORKER_URL.searchParams.get('vapid') || '';
const WEB_PUSH_SYNC_URL = `${BASE_PATH}/pages/web_push.php`;
const APP_SHELL = [
    `${BASE_PATH}/manifest.webmanifest`,
    `${BASE_PATH}/offline.html`,
    `${BASE_PATH}/public/css/base.css`,
    `${BASE_PATH}/public/css/layout.css`,
    `${BASE_PATH}/public/css/mobile-shell.css`,
    `${BASE_PATH}/public/css/components.css`,
    `${BASE_PATH}/public/css/login.css`,
    `${BASE_PATH}/public/js/main.js`,
    `${BASE_PATH}/public/img/logo.png`,
    `${BASE_PATH}/public/img/pwa-icon-192.png`,
    `${BASE_PATH}/public/img/pwa-icon-512.png`,
    `${BASE_PATH}/public/img/pwa-maskable-512.png`
];

function base64UrlToUint8Array(value) {
    const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    const padding = normalized.length % 4 ? '='.repeat(4 - (normalized.length % 4)) : '';
    const binary = atob(normalized + padding);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

async function broadcastToClients(message) {
    const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    windowClients.forEach(client => {
        client.postMessage(message);
    });
    return windowClients;
}

async function syncPushSubscriptionToServer(subscription, deviceLabel = 'Background PWA') {
    if (!subscription) {
        return false;
    }

    const body = new URLSearchParams();
    body.set('action', 'subscribe');
    body.set('subscription_json', JSON.stringify(subscription.toJSON()));
    body.set('device_label', deviceLabel);

    const response = await fetch(WEB_PUSH_SYNC_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-JWS-Service-Worker': '1',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body
    });

    return response.ok;
}

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const requestUrl = new URL(event.request.url);
    if (requestUrl.origin !== self.location.origin) return;

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' })
                .catch(async () => {
                    return caches.match(`${BASE_PATH}/offline.html`);
                })
        );
        return;
    }

    const isStaticAsset = requestUrl.pathname.startsWith(`${BASE_PATH}/public/`) ||
        requestUrl.pathname.endsWith('/manifest.webmanifest');

    if (!isStaticAsset) return;

    event.respondWith(
        caches.match(event.request).then(cached => {
            const networkFetch = fetch(event.request)
                .then(response => {
                    const cloned = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, cloned));
                    return response;
                })
                .catch(() => cached);

            return cached || networkFetch;
        })
    );
});

self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let payload = {};

        if (event.data) {
            try {
                payload = event.data.json();
            } catch (error) {
                payload = {
                    title: 'JWS Notification',
                    body: event.data.text()
                };
            }
        }

        const title = payload.title || 'JWS Notification';
        const data = {
            ...(payload.data || {}),
            url: payload.url || payload.data?.url || `${BASE_PATH}/pages/notifikasi.php`
        };

        const windowClients = await broadcastToClients({
            type: 'web-push-received',
            payload: { ...payload, data }
        });

        const hasVisibleClient = windowClients.some(client => client.visibilityState === 'visible');
        if (hasVisibleClient && !payload.forceDisplay) {
            return;
        }

        return self.registration.showNotification(title, {
            body: payload.body || 'Ada pembaruan baru di aplikasi JWS.',
            icon: payload.icon || `${BASE_PATH}/public/img/pwa-icon-192.png`,
            badge: payload.badge || `${BASE_PATH}/public/img/pwa-icon-192.png`,
            tag: payload.tag || `jws-push-${Date.now()}`,
            data,
            timestamp: Number(payload.timestamp || Date.now())
        });
    })());
});

self.addEventListener('pushsubscriptionchange', event => {
    event.waitUntil((async () => {
        try {
            let applicationServerKey = event.oldSubscription?.options?.applicationServerKey || null;
            if ((!applicationServerKey || applicationServerKey.byteLength === 0) && WEB_PUSH_PUBLIC_KEY) {
                applicationServerKey = base64UrlToUint8Array(WEB_PUSH_PUBLIC_KEY);
            }

            if (!applicationServerKey) {
                await broadcastToClients({
                    type: 'web-push-subscription-refresh-required',
                    message: 'Subscription notifikasi perlu diperbarui dari aplikasi.'
                });
                return;
            }

            const subscription = await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey
            });

            const synced = await syncPushSubscriptionToServer(subscription, 'Background PWA / auto-resubscribe');
            await broadcastToClients({
                type: synced ? 'web-push-subscription-updated' : 'web-push-subscription-refresh-required',
                message: synced
                    ? 'Subscription notifikasi background disegarkan otomatis.'
                    : 'Subscription notifikasi background belum berhasil disimpan ulang.'
            });
        } catch (error) {
            await broadcastToClients({
                type: 'web-push-subscription-refresh-required',
                message: error?.message || 'Subscription notifikasi background perlu dicek ulang.'
            });
        }
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const targetUrl = new URL(event.notification?.data?.url || `${BASE_PATH}/pages/notifikasi.php`, self.location.origin).href;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async windowClients => {
            for (const client of windowClients) {
                if ('focus' in client && client.url === targetUrl) {
                    return client.focus();
                }
            }

            for (const client of windowClients) {
                const clientUrl = new URL(client.url);
                if (clientUrl.origin === self.location.origin && 'focus' in client) {
                    await client.focus();
                    if ('navigate' in client) {
                        return client.navigate(targetUrl);
                    }
                    return client;
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }

            return undefined;
        })
    );
});
