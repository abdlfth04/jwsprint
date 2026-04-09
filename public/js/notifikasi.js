(function() {
    const listEl = document.getElementById('notificationList');
    if (!listEl) {
        return;
    }

    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const defaultNotificationHref = window.jwsPageUrl ? window.jwsPageUrl('notifikasi.php') : 'notifikasi.php';

    const emptyStateEl = document.getElementById('notificationEmptyState');
    const searchInput = document.getElementById('notificationSearch');
    const refreshButton = document.getElementById('notificationRefreshButton');
    const markAllButton = document.getElementById('notificationMarkAllButton');
    const syncText = document.getElementById('notificationSyncText');
    const filterButtons = Array.from(document.querySelectorAll('.notification-filter[data-filter]'));
    const webPushStatusBadge = document.getElementById('webPushStatusBadge');
    const webPushDescription = document.getElementById('webPushDescription');
    const webPushMeta = document.getElementById('webPushMeta');
    const webPushPrimaryAction = document.getElementById('webPushPrimaryAction');
    const webPushTestAction = document.getElementById('webPushTestAction');
    const endpoint = window.NOTIFICATION_ENDPOINT || buildNotificationEndpoint();
    const actionEndpoint = pageState.notificationActionEndpoint || defaultNotificationHref;
    const streamEndpoint = window.NOTIFICATION_STREAM_ENDPOINT || endpoint.replace('format=json', 'format=stream');
    const refreshMs = Number(pageState.notificationPageRefreshMs || 45000);
    const escapeHtml = window.jwsEscapeHtml;
    const formatNumber = window.jwsFormatNumber;

    const state = {
        items: readItemsFromDom(),
        filter: 'all',
        query: '',
        fetching: false,
        marking: false,
        timer: null,
        stream: null,
        streamRetry: null
    };

    function buildNotificationEndpoint() {
        const url = new URL(window.location.href);
        url.searchParams.set('format', 'json');
        return url.toString();
    }

    function toneBadgeClass(tone) {
        return ({
            danger: 'badge-danger',
            warning: 'badge-warning',
            info: 'badge-info',
            success: 'badge-success'
        })[tone] || 'badge-secondary';
    }

    function readItemsFromDom() {
        return Array.from(document.querySelectorAll('#notificationList .notification-task')).map(node => ({
            href: node.querySelector('.notification-task-main')?.getAttribute('href') || defaultNotificationHref,
            tone: node.dataset.tone || 'info',
            count: Number(node.dataset.count || 0),
            title: node.querySelector('.notification-task-title')?.textContent?.trim() || '',
            message: node.querySelector('.notification-task-text')?.textContent?.trim() || '',
            icon: node.querySelector('.notification-task-icon i')?.className.replace('fas ', '') || 'fa-bell',
            search: node.dataset.search || '',
            read_key: node.dataset.readKey || ''
        }));
    }

    function renderItem(item) {
        return `
            <div
                class="notification-task"
                data-tone="${escapeHtml(item.tone || 'info')}"
                data-search="${escapeHtml(item.search || '')}"
                data-count="${Number(item.count || 0)}"
                data-read-key="${escapeHtml(item.read_key || '')}">
                <a href="${escapeHtml(item.href || defaultNotificationHref)}" class="notification-task-main">
                    <span class="notification-task-icon tone-${escapeHtml(item.tone || 'info')}">
                        <i class="fas ${escapeHtml(item.icon || 'fa-bell')}"></i>
                    </span>
                    <span class="notification-task-copy">
                        <span class="notification-task-top">
                            <span class="notification-task-title">${escapeHtml(item.title || '')}</span>
                            <span class="badge ${toneBadgeClass(item.tone)}">${formatNumber(item.count)}</span>
                        </span>
                        <span class="notification-task-text">${escapeHtml(item.message || '')}</span>
                    </span>
                    <span class="notification-task-arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <div class="notification-task-actions">
                    <button type="button" class="notification-task-read" data-notification-action="mark-read" data-read-key="${escapeHtml(item.read_key || '')}">
                        <i class="fas fa-check"></i> Sudah dibaca
                    </button>
                </div>
            </div>`;
    }

    function setSyncText(text) {
        if (syncText) {
            syncText.textContent = text;
        }
    }

    function updateSummary(payload) {
        const totalEl = document.getElementById('notificationTotalValue');
        const urgentEl = document.getElementById('notificationUrgentValue');
        const categoryEl = document.getElementById('notificationCategoryValue');
        const updatedEl = document.getElementById('notificationUpdatedValue');
        const counts = payload.counts_by_tone || {};
        const categories = payload.category_counts || {};
        const urgent = Number(counts.danger || 0) + Number(counts.warning || 0);

        if (totalEl) totalEl.textContent = formatNumber(payload.count || 0);
        if (urgentEl) urgentEl.textContent = formatNumber(urgent);
        if (categoryEl) categoryEl.textContent = formatNumber(categories.all || 0);
        if (updatedEl) updatedEl.textContent = payload.generated_label || '';

        const chipMap = {
            notificationChipCountAll: categories.all || 0,
            notificationChipCountUrgent: categories.urgent || 0,
            notificationChipCountWarning: categories.warning || 0,
            notificationChipCountInfo: categories.info || 0
        };

        Object.keys(chipMap).forEach(id => {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = formatNumber(chipMap[id]);
            }
        });
    }

    function webPushBadgeClass(state) {
        if (!state.supported || !state.configured) return 'badge-secondary';
        if (state.permission === 'denied') return 'badge-danger';
        if (state.active) return 'badge-success';
        return 'badge-warning';
    }

    function renderWebPushState(state = {}) {
        if (!webPushStatusBadge || !webPushDescription || !webPushMeta || !webPushPrimaryAction || !webPushTestAction) {
            return;
        }

        webPushStatusBadge.className = `badge ${webPushBadgeClass(state)}`;
        webPushPrimaryAction.disabled = Boolean(state.busyAction);

        if (!state.supported) {
            webPushStatusBadge.textContent = 'Tidak Didukung';
            webPushDescription.textContent = 'Browser ini belum mendukung kombinasi Service Worker, Push API, atau Notification API yang dibutuhkan Web Push.';
            webPushMeta.textContent = 'Gunakan browser modern berbasis HTTPS atau localhost untuk mengaktifkan Web Push.';
            webPushPrimaryAction.hidden = true;
            webPushTestAction.hidden = true;
            return;
        }

        if (!state.configured) {
            webPushStatusBadge.textContent = 'Belum Siap';
            webPushDescription.textContent = 'Server aplikasi belum memiliki konfigurasi VAPID yang valid, jadi browser belum bisa berlangganan Web Push.';
            webPushMeta.textContent = state.message || 'Lengkapi konfigurasi Web Push di server terlebih dulu.';
            webPushPrimaryAction.hidden = true;
            webPushTestAction.hidden = true;
            return;
        }

        webPushPrimaryAction.hidden = false;

        if (state.busyAction === 'subscribe') {
            webPushStatusBadge.textContent = 'Sinkron';
            webPushDescription.textContent = 'Browser sedang meminta izin dan menyimpan subscription ke server.';
            webPushMeta.textContent = 'Mohon tunggu hingga proses aktivasi selesai.';
            webPushPrimaryAction.textContent = 'Mengaktifkan...';
            webPushTestAction.hidden = true;
            return;
        }

        if (state.busyAction === 'unsubscribe') {
            webPushStatusBadge.textContent = 'Sinkron';
            webPushDescription.textContent = 'Subscription sedang dihapus dari browser dan server.';
            webPushMeta.textContent = 'Web Push sedang dimatikan untuk perangkat ini.';
            webPushPrimaryAction.textContent = 'Mematikan...';
            webPushTestAction.hidden = true;
            return;
        }

        if (state.busyAction === 'test') {
            webPushStatusBadge.textContent = 'Test';
            webPushDescription.textContent = 'Push test sedang dikirim ke subscription yang aktif di akun ini.';
            webPushMeta.textContent = 'Jika browser mengizinkan, notifikasi akan muncul dalam beberapa detik.';
            webPushPrimaryAction.textContent = state.active ? 'Matikan' : 'Aktifkan';
            webPushTestAction.hidden = false;
            webPushTestAction.disabled = true;
            return;
        }

        webPushTestAction.disabled = false;

        if (state.permission === 'denied') {
            webPushStatusBadge.textContent = 'Diblokir';
            webPushDescription.textContent = 'Izin notifikasi saat ini ditolak oleh browser, jadi subscription Web Push tidak bisa dibuat.';
            webPushMeta.textContent = 'Buka ikon gembok atau site settings browser, ubah Notifications menjadi Allow, lalu refresh halaman.';
            webPushPrimaryAction.textContent = 'Cara aktifkan';
            webPushTestAction.hidden = true;
            return;
        }

        if (state.active) {
            webPushStatusBadge.textContent = 'Aktif';
            webPushDescription.textContent = 'Perangkat ini akan menerima push real-time, terutama saat ada pesan chat baru masuk.';
            webPushMeta.textContent = `${Number(state.subscriptionCount || 0).toLocaleString('id-ID')} subscription aktif di akun ini.${state.message ? ` ${state.message}` : ''}`.trim();
            webPushPrimaryAction.textContent = 'Matikan';
            webPushTestAction.hidden = false;
            return;
        }

        webPushStatusBadge.textContent = 'Siap';
        webPushDescription.textContent = 'Browser sudah mendukung Web Push. Aktifkan sekali saja agar chat baru bisa langsung masuk ke perangkat ini.';
        webPushMeta.textContent = state.message || 'Belum ada subscription aktif untuk perangkat ini.';
        webPushPrimaryAction.textContent = state.permission === 'granted' ? 'Sinkronkan' : 'Aktifkan';
        webPushTestAction.hidden = true;
    }

    function matchesFilter(item) {
        if (state.filter === 'all') {
            return true;
        }

        if (state.filter === 'urgent') {
            return item.tone === 'danger' || item.tone === 'warning';
        }

        return item.tone === state.filter;
    }

    function matchesQuery(item) {
        if (!state.query) {
            return true;
        }

        return String(item.search || '').includes(state.query);
    }

    function applyFilters() {
        const visibleItems = state.items.filter(item => matchesFilter(item) && matchesQuery(item));

        listEl.innerHTML = visibleItems.map(renderItem).join('');
        if (emptyStateEl) {
            emptyStateEl.hidden = visibleItems.length > 0;
        }
        updateBulkActionState();
    }

    function applyPayload(payload, options = {}) {
        if (!payload || !Array.isArray(payload.items)) {
            return;
        }

        state.items = payload.items.map(item => ({
            ...item,
            search: item.search || `${item.title || ''} ${item.message || ''}`.toLowerCase(),
            read_key: item.read_key || ''
        }));

        updateSummary(payload);
        applyFilters();
        setSyncText((options.prefix || 'Sinkron') + (payload.generated_label ? ` ${payload.generated_label}` : ''));
    }

    function updateBulkActionState() {
        if (!markAllButton) {
            return;
        }

        markAllButton.disabled = state.marking || state.items.length <= 0;
    }

    function setMarkingState(nextState) {
        state.marking = Boolean(nextState);
        listEl.querySelectorAll('.notification-task-read').forEach(button => {
            button.disabled = state.marking;
        });
        updateBulkActionState();
    }

    async function sendReadAction(action, readKey = '') {
        if (!actionEndpoint || state.marking) {
            return;
        }

        const formData = new FormData();
        formData.append('action', action);
        if (readKey) {
            formData.append('read_key', readKey);
        }

        setMarkingState(true);
        setSyncText(action === 'mark_all_read' ? 'Menandai semua notifikasi...' : 'Menandai notifikasi...');

        try {
            const response = await fetch(actionEndpoint, {
                method: 'POST',
                body: formData,
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Aksi notifikasi gagal diproses');
            }

            const payload = await response.json();
            if (!payload || !payload.success || !payload.payload) {
                throw new Error(payload && payload.msg ? payload.msg : 'Status notifikasi belum tersimpan.');
            }

            applyPayload(payload.payload, { prefix: 'Sinkron' });
            if (typeof window.applyNotificationFeedPayload === 'function') {
                window.applyNotificationFeedPayload(payload.payload);
            } else if (typeof window.ensureNotificationFeedFresh === 'function') {
                window.ensureNotificationFeedFresh(true);
            }
            setSyncText(payload.msg || 'Status notifikasi diperbarui.');
        } catch (error) {
            setSyncText(error.message || 'Status notifikasi gagal diperbarui');
        } finally {
            setMarkingState(false);
        }
    }

    async function refreshNotificationPage(silent = false) {
        if (!endpoint || state.fetching) {
            return;
        }

        state.fetching = true;
        if (refreshButton) refreshButton.disabled = true;
        if (!silent) setSyncText('Menyinkronkan notifikasi...');

        try {
            const response = await fetch(endpoint, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Gagal memuat notifikasi');
            }

            const payload = await response.json();
            if (!Array.isArray(payload.items)) {
                throw new Error('Payload notifikasi tidak valid');
            }

            applyPayload(payload);
        } catch (error) {
            setSyncText('Gagal menyinkronkan notifikasi');
        } finally {
            state.fetching = false;
            if (refreshButton) refreshButton.disabled = false;
        }
    }

    function stopStream() {
        if (state.streamRetry) {
            clearTimeout(state.streamRetry);
            state.streamRetry = null;
        }

        if (state.stream) {
            state.stream.close();
            state.stream = null;
        }
    }

    function startStream() {
        if (!streamEndpoint || typeof window.EventSource !== 'function' || state.stream) {
            return false;
        }

        const source = new EventSource(streamEndpoint);
        state.stream = source;

        source.addEventListener('notifications', function(event) {
            try {
                applyPayload(JSON.parse(event.data || '{}'), { prefix: 'Realtime' });
            } catch (error) {
            }
        });

        source.addEventListener('ping', function(event) {
            try {
                const payload = JSON.parse(event.data || '{}');
                setSyncText(payload.generated_label ? `Realtime ${payload.generated_label}` : 'Realtime aktif');
            } catch (error) {
                setSyncText('Realtime aktif');
            }
        });

        source.addEventListener('error', function() {
            stopStream();
            state.streamRetry = window.setTimeout(function() {
                startStream();
            }, 2500);
        });

        return true;
    }

    function bindFilters() {
        if (searchInput) {
            searchInput.addEventListener('input', function(event) {
                state.query = String(event.target.value || '').trim().toLowerCase();
                applyFilters();
            });
        }

        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                state.filter = button.dataset.filter || 'all';
                filterButtons.forEach(item => item.classList.toggle('is-active', item === button));
                applyFilters();
            });
        });

        if (refreshButton) {
            refreshButton.addEventListener('click', function() {
                refreshNotificationPage(false);
            });
        }

        if (markAllButton) {
            markAllButton.addEventListener('click', function() {
                sendReadAction('mark_all_read');
            });
        }
    }

    function bindReadActions() {
        listEl.addEventListener('click', function(event) {
            const button = event.target.closest('[data-notification-action="mark-read"]');
            if (!button) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const readKey = button.dataset.readKey || '';
            if (!readKey) {
                return;
            }

            sendReadAction('mark_read', readKey);
        });
    }

    function startPolling() {
        if (state.timer) {
            clearInterval(state.timer);
        }

        state.timer = window.setInterval(function() {
            if (!document.hidden) {
                refreshNotificationPage(true);
            }
        }, refreshMs);
    }

    function bindWebPush() {
        if (!window.jwsWebPush || !webPushPrimaryAction) {
            return;
        }

        window.jwsWebPush.onChange(renderWebPushState);

        webPushPrimaryAction.addEventListener('click', async function() {
            const current = window.jwsWebPush.getState();
            if (current.permission === 'denied') {
                window.requestBrowserNotificationPermission();
                return;
            }

            try {
                if (current.active) {
                    await window.jwsWebPush.unsubscribe();
                } else {
                    await window.jwsWebPush.subscribe();
                }
            } catch (error) {
                renderWebPushState({
                    ...current,
                    message: error.message || 'Aksi Web Push gagal diproses.'
                });
            }
        });

        if (webPushTestAction) {
            webPushTestAction.addEventListener('click', async function() {
                try {
                    await window.jwsWebPush.sendTest();
                } catch (error) {
                    const current = window.jwsWebPush.getState();
                    renderWebPushState({
                        ...current,
                        message: error.message || 'Test Web Push gagal dikirim.'
                    });
                }
            });
        }
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopStream();
            return;
        }

        startStream();
        refreshNotificationPage(true);
    });

    window.addEventListener('beforeunload', function() {
        stopStream();
    });

    document.addEventListener('jws:notification-feed', function(event) {
        applyPayload((event && event.detail) || {}, { prefix: 'Sinkron' });
    });

    bindFilters();
    bindReadActions();
    bindWebPush();
    applyFilters();
    startPolling();
    startStream();
    refreshNotificationPage(true);
})();
