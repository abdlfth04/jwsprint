let activeRoom = null;
let activeRoomNama = '';
let activeRoomTipe = 'group';
let lastId = 0;
let pollTimer = null;
let summaryTimer = null;
let streamSource = null;
let streamRetryTimer = null;
let streamFailureCount = 0;
let isFetchingMessages = false;
let isFetchingSummary = false;
let isSendingMessage = false;
let activeAttachmentFile = null;

const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
const chatState = pageState.chat || {};
const CURRENT_USER_ID = Number(chatState.myUserId || 0);
const CHAT_ENDPOINT = chatState.endpoint || (window.jwsPageUrl ? window.jwsPageUrl('chat.php') : 'chat.php');
const CHAT_STREAM_ENDPOINT = chatState.streamEndpoint || (window.jwsPageUrl ? window.jwsPageUrl('chat.php?format=stream') : '');
const CHAT_REFRESH_MS = Number(chatState.refreshMs || 2000);
const CHAT_SUMMARY_MS = Number(chatState.summaryMs || 5000);
const CHAT_ATTACHMENT_MAX_BYTES = Number(chatState.attachmentMaxBytes || 10485760);
const CHAT_ATTACHMENT_LABEL = String(chatState.attachmentLabel || 'JPG, PNG, PDF, DOC, DOCX, XLS, XLSX, CSV, PPT, PPTX, TXT');
const CHAT_ACTIVE_ROOM_KEY = 'jws_chat_active_room';
const CHAT_SEEN_KEY = 'jws_chat_seen_map';
const CHAT_DRAFT_KEY = 'jws_chat_draft_map';
const CHAT_FILTER_KEY = 'jws_chat_room_filter';
const CHAT_MOBILE_BREAKPOINT = window.matchMedia('(max-width: 768px)');

let seenMap = loadSeenMap();
let draftMap = loadDraftMap();
let activeRoomFilter = loadRoomFilter();

function loadSeenMap() {
    try {
        return JSON.parse(localStorage.getItem(CHAT_SEEN_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function persistSeenMap() {
    localStorage.setItem(CHAT_SEEN_KEY, JSON.stringify(seenMap));
}

function loadDraftMap() {
    try {
        return JSON.parse(localStorage.getItem(CHAT_DRAFT_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function persistDraftMap() {
    localStorage.setItem(CHAT_DRAFT_KEY, JSON.stringify(draftMap));
}

function loadRoomFilter() {
    const stored = (localStorage.getItem(CHAT_FILTER_KEY) || 'all').trim().toLowerCase();
    return ['all', 'unread', 'group', 'personal'].includes(stored) ? stored : 'all';
}

function persistRoomFilter() {
    localStorage.setItem(CHAT_FILTER_KEY, activeRoomFilter);
}

function saveActiveRoomState() {
    if (!activeRoom) return;

    localStorage.setItem(CHAT_ACTIVE_ROOM_KEY, JSON.stringify({
        id: activeRoom,
        nama: activeRoomNama,
        tipe: activeRoomTipe
    }));
}

function getStoredActiveRoomState() {
    try {
        return JSON.parse(localStorage.getItem(CHAT_ACTIVE_ROOM_KEY) || 'null');
    } catch (error) {
        return null;
    }
}

function setRealtimeState(state, text) {
    const pill = document.getElementById('chatRealtimeState');
    const sync = document.getElementById('chatSyncText');
    if (!pill || !sync) return;

    pill.className = 'chat-live-pill' + (state ? ` ${state}` : '');
    pill.textContent = state === 'live' ? 'Realtime' : state === 'syncing' ? 'Syncing' : state === 'error' ? 'Error' : 'Idle';
    sync.textContent = text || 'Belum sinkron';
}

function setMobileRoomState(isOpen) {
    const layout = document.getElementById('chatLayout');
    if (!layout) return;

    const roomsPanel = layout.querySelector('.chat-rooms');
    const mainPanel = layout.querySelector('.chat-main');
    const isMobile = CHAT_MOBILE_BREAKPOINT.matches;

    layout.classList.toggle('chat-room-open', isMobile && Boolean(isOpen));
    layout.dataset.mobileView = isMobile ? (isOpen ? 'room' : 'list') : 'desktop';

    if (!roomsPanel || !mainPanel) return;

    roomsPanel.hidden = false;
    mainPanel.hidden = false;
    roomsPanel.style.removeProperty('display');
    mainPanel.style.removeProperty('display');

    if (!isMobile) {
        mainPanel.style.display = 'flex';
        return;
    }

    if (isOpen) {
        roomsPanel.hidden = true;
        roomsPanel.style.display = 'none';
        mainPanel.hidden = false;
        mainPanel.style.display = 'flex';
        return;
    }

    roomsPanel.hidden = false;
    roomsPanel.style.display = 'flex';
    mainPanel.hidden = true;
    mainPanel.style.display = 'none';
}

function toggleChatSidebar(showSidebar) {
    setMobileRoomState(!showSidebar);
}

function getChatBox() {
    return document.getElementById('chatMessages');
}

function getChatInput() {
    return document.getElementById('chatInput');
}

function getChatAttachmentInput() {
    return document.getElementById('chatAttachmentInput');
}

function getChatAttachmentStrip() {
    return document.getElementById('chatAttachmentStrip');
}

function getRoomItems() {
    return Array.from(document.querySelectorAll('.chat-room-item[data-room-id]'));
}

function escAttr(str) {
    return escHtml(str).replace(/`/g, '&#096;');
}

function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!size || size <= 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = size;
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
        value /= 1024;
        index += 1;
    }

    const precision = value >= 100 || index === 0 ? 0 : 1;
    return `${value.toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: precision
    })} ${units[index]}`;
}

function attachmentKindFromMeta(file = {}) {
    const mime = String(file.mime || file.type || '').toLowerCase();
    const ext = String(file.ext || '').toLowerCase();
    if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png'].includes(ext)) {
        return 'image';
    }
    return 'document';
}

function attachmentLabel(file = {}) {
    const kind = attachmentKindFromMeta(file);
    return `${kind === 'image' ? 'Gambar' : 'Dokumen'} - ${formatFileSize(file.size)}`;
}

function resetChatAttachmentInput() {
    const input = getChatAttachmentInput();
    if (input) {
        input.value = '';
    }
}

function clearChatAttachment() {
    if (activeAttachmentFile && activeAttachmentFile.local_url) {
        URL.revokeObjectURL(activeAttachmentFile.local_url);
    }
    activeAttachmentFile = null;
    resetChatAttachmentInput();
    updateChatAttachmentPreview();
    syncChatComposerState();
}

function updateChatAttachmentPreview() {
    const strip = getChatAttachmentStrip();
    const nameEl = document.getElementById('chatAttachmentName');
    const metaEl = document.getElementById('chatAttachmentMeta');
    const iconEl = document.getElementById('chatAttachmentIcon');
    if (!strip || !nameEl || !metaEl || !iconEl) return;

    if (!activeAttachmentFile) {
        strip.hidden = true;
        nameEl.textContent = 'Belum ada file';
        metaEl.textContent = 'Pilih lampiran chat';
        iconEl.className = 'fas fa-paperclip';
        return;
    }

    strip.hidden = false;
    nameEl.textContent = activeAttachmentFile.name || 'Lampiran chat';
    metaEl.textContent = attachmentLabel(activeAttachmentFile);
    iconEl.className = `fas ${attachmentIcon(activeAttachmentFile)}`;
}

function validateChatAttachment(file) {
    if (!file) {
        return { valid: false, message: 'File tidak ditemukan.' };
    }

    const name = String(file.name || '');
    const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    const allowed = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt'];
    if (!allowed.includes(ext)) {
        return { valid: false, message: `Format file belum didukung. Gunakan ${CHAT_ATTACHMENT_LABEL}.` };
    }

    if (Number(file.size || 0) > CHAT_ATTACHMENT_MAX_BYTES) {
        return {
            valid: false,
            message: `Ukuran file melebihi batas chat (${formatFileSize(CHAT_ATTACHMENT_MAX_BYTES)}).`
        };
    }

    return { valid: true, ext };
}

function handleChatAttachmentSelection(file) {
    if (!file) {
        clearChatAttachment();
        return;
    }

    const validation = validateChatAttachment(file);
    if (!validation.valid) {
        alert(validation.message || 'Lampiran chat tidak valid.');
        clearChatAttachment();
        return;
    }

    if (activeAttachmentFile && activeAttachmentFile.local_url) {
        URL.revokeObjectURL(activeAttachmentFile.local_url);
    }

    const kind = attachmentKindFromMeta({
        type: file.type,
        ext: validation.ext
    });

    activeAttachmentFile = {
        file,
        name: file.name,
        size: Number(file.size || 0),
        mime: String(file.type || ''),
        ext: validation.ext,
        kind,
        local_url: kind === 'image' ? URL.createObjectURL(file) : ''
    };

    updateChatAttachmentPreview();
    syncChatComposerState();
}

function toUnixTimestamp(value) {
    const timestamp = Date.parse(value || '');
    return Number.isNaN(timestamp) ? 0 : Math.floor(timestamp / 1000);
}

function updateDraftStatus(message) {
    const status = document.getElementById('chatDraftStatus');
    if (!status) return;

    if (message) {
        status.textContent = message;
        return;
    }

    if (!activeRoom) {
        status.textContent = 'Draft tersimpan otomatis per room.';
        return;
    }

    if (activeAttachmentFile) {
        status.textContent = `Lampiran ${activeAttachmentFile.name || 'chat'} siap dikirim.`;
        return;
    }

    const draft = String(draftMap[activeRoom] || '').trim();
    status.textContent = draft !== ''
        ? 'Draft room ini tersimpan otomatis.'
        : 'Belum ada draft di room ini.';
}

function saveDraftForRoom(roomId, value) {
    const key = String(roomId || '').trim();
    if (!key) return;

    if (String(value || '').trim() === '') {
        delete draftMap[key];
    } else {
        draftMap[key] = String(value);
    }

    persistDraftMap();
    updateDraftStatus();
}

function restoreDraftForActiveRoom() {
    const input = getChatInput();
    if (!input) return;

    input.value = activeRoom ? String(draftMap[activeRoom] || '') : '';
    autoGrowChatInput(input);
    updateDraftStatus();
}

function clearDraftForRoom(roomId) {
    const key = String(roomId || '').trim();
    if (!key || !(key in draftMap)) {
        updateDraftStatus();
        return;
    }

    delete draftMap[key];
    persistDraftMap();
    updateDraftStatus();
}

function updateFilterButtons() {
    document.querySelectorAll('.chat-filter-chip[data-chat-filter]').forEach(button => {
        button.classList.toggle('active', (button.dataset.chatFilter || 'all') === activeRoomFilter);
    });
}

function sortRoomList(listId) {
    const list = document.getElementById(listId);
    if (!list) return;

    const items = Array.from(list.querySelectorAll('.chat-room-item[data-room-id]'));
    items.sort((left, right) => {
        const leftUnread = Number(left.dataset.unreadCount || 0);
        const rightUnread = Number(right.dataset.unreadCount || 0);
        if (leftUnread !== rightUnread) {
            return rightUnread - leftUnread;
        }

        const leftDefault = Number(left.dataset.default || 0);
        const rightDefault = Number(right.dataset.default || 0);
        if (leftDefault !== rightDefault) {
            return rightDefault - leftDefault;
        }

        const leftTs = Number(left.dataset.lastTs || 0);
        const rightTs = Number(right.dataset.lastTs || 0);
        if (leftTs !== rightTs) {
            return rightTs - leftTs;
        }

        return String(left.dataset.roomName || '').localeCompare(String(right.dataset.roomName || ''), 'id', { sensitivity: 'base' });
    });

    items.forEach(item => list.appendChild(item));
}

function sortAllRoomLists() {
    sortRoomList('groupRoomList');
    sortRoomList('personalRoomList');
}

function updateChatSidebarOverview() {
    const rooms = getRoomItems();
    const totalRooms = rooms.length;
    const unreadRooms = rooms.filter(item => Number(item.dataset.unreadCount || 0) > 0).length;
    const groupRooms = rooms.filter(item => (item.dataset.roomType || 'group') === 'group').length;
    const personalRooms = rooms.filter(item => (item.dataset.roomType || 'group') === 'personal').length;

    const summaryRooms = document.getElementById('chatSummaryRooms');
    const summaryUnread = document.getElementById('chatSummaryUnread');
    const allCount = document.getElementById('chatFilterCountAll');
    const unreadCount = document.getElementById('chatFilterCountUnread');
    const groupCount = document.getElementById('chatFilterCountGroup');
    const personalCount = document.getElementById('chatFilterCountPersonal');

    if (summaryRooms) {
        summaryRooms.textContent = `${totalRooms} room aktif`;
    }
    if (summaryUnread) {
        summaryUnread.textContent = unreadRooms > 0
            ? `${unreadRooms} room belum dibaca`
            : 'Semua room sudah dibaca';
    }
    if (allCount) allCount.textContent = String(totalRooms);
    if (unreadCount) unreadCount.textContent = String(unreadRooms);
    if (groupCount) groupCount.textContent = String(groupRooms);
    if (personalCount) personalCount.textContent = String(personalRooms);
}

function filterRoomSections() {
    const roomQuery = (document.getElementById('chatRoomSearch')?.value || '').trim().toLowerCase();
    const filter = activeRoomFilter || 'all';

    document.querySelectorAll('.chat-room-item[data-room-id]').forEach(item => {
        const haystack = item.dataset.search || '';
        const unreadCount = Number(item.dataset.unreadCount || 0);
        const roomType = item.dataset.roomType || 'group';
        const isActive = Number(item.dataset.roomId || 0) === Number(activeRoom);
        const matchesQuery = roomQuery === '' || haystack.includes(roomQuery);
        const matchesFilter = filter === 'all'
            || (filter === 'unread' && unreadCount > 0)
            || filter === roomType;
        const visible = matchesQuery && (matchesFilter || isActive);

        item.hidden = !visible;
        item.style.display = visible ? '' : 'none';
    });

    document.querySelectorAll('[data-room-section]').forEach(section => {
        const sectionType = section.dataset.roomSection || '';
        const typeAllowed = filter === 'all' || filter === 'unread' || filter === sectionType;
        const items = Array.from(section.querySelectorAll('.chat-room-item[data-room-id]'));
        const visibleItems = items.filter(item => !item.hidden);
        const emptyNote = section.querySelector('.chat-inline-note');
        const shouldShowEmpty = typeAllowed && filter === sectionType;

        if (!typeAllowed) {
            section.hidden = true;
            if (emptyNote) emptyNote.hidden = true;
            return;
        }

        if (visibleItems.length > 0) {
            section.hidden = false;
            if (emptyNote) emptyNote.hidden = true;
            return;
        }

        section.hidden = !shouldShowEmpty;
        if (emptyNote) {
            emptyNote.hidden = !shouldShowEmpty;
        }
    });
}

function renderEmptyState(message) {
    const box = getChatBox();
    if (!box) return;

    box.innerHTML = `
        <div class="chat-empty-state" id="chatEmptyState">
            <i class="fas fa-comments"></i>
            <div>${escHtml(message || 'Belum ada pesan di room ini.')}</div>
        </div>`;
}

function formatClock(dateString) {
    return new Date(dateString).toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

function formatRoomTime(dateString) {
    if (!dateString) return '';

    const date = new Date(dateString);
    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();

    if (sameDay) {
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    }

    return date.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit' });
}

function compactText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function buildRoomPreview(summary, roomType) {
    if (summary && summary.preview_text) {
        return compactText(summary.preview_text) || 'Belum ada pesan';
    }

    const isMine = Number(summary.user_id || 0) === CURRENT_USER_ID;
    const shouldPrefix = roomType !== 'personal' && summary.sender_name && !isMine;
    const raw = `${shouldPrefix ? `${summary.sender_name}: ` : ''}${summary.last_message || 'Belum ada pesan'}`;
    const cleaned = compactText(raw) || 'Belum ada pesan';

    return cleaned.length > 56 ? `${cleaned.slice(0, 53)}...` : cleaned;
}

function attachmentIcon(meta = {}) {
    const ext = String(meta.ext || '').toLowerCase();
    if (['jpg', 'jpeg', 'png'].includes(ext)) return 'fa-image';
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['doc', 'docx'].includes(ext)) return 'fa-file-word';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'fa-file-excel';
    if (['ppt', 'pptx'].includes(ext)) return 'fa-file-powerpoint';
    if (ext === 'txt') return 'fa-file-lines';
    return 'fa-file';
}

function buildMessageAttachmentMarkup(message) {
    const attachment = message && message.attachment && typeof message.attachment === 'object'
        ? message.attachment
        : null;
    if (!attachment) {
        return '';
    }

    const kind = attachmentKindFromMeta(attachment);
    const name = escHtml(attachment.name || 'Lampiran');
    const meta = escHtml(attachmentLabel(attachment));
    const targetUrl = escAttr(attachment.url || attachment.download_url || attachment.local_url || '#');
    const downloadUrl = escAttr(attachment.download_url || attachment.url || attachment.local_url || '#');
    const hasTargetUrl = targetUrl !== '#';
    const hasDownloadUrl = downloadUrl !== '#';

    if (kind === 'image' && (attachment.preview_url || attachment.local_url)) {
        const previewUrl = escAttr(attachment.preview_url || attachment.local_url || targetUrl);
        const openTag = hasTargetUrl
            ? `<a class="chat-attachment chat-attachment-image" href="${targetUrl}" target="_blank" rel="noopener">`
            : '<div class="chat-attachment chat-attachment-image">';
        const closeTag = hasTargetUrl ? '</a>' : '</div>';
        return `
            ${openTag}
                <img src="${previewUrl}" alt="${name}" loading="lazy">
                <span class="chat-attachment-caption">
                    <strong>${name}</strong>
                    <span>${meta}</span>
                </span>
            ${closeTag}`;
    }

    const openTag = hasDownloadUrl
        ? `<a class="chat-attachment chat-attachment-file" href="${downloadUrl}" target="_blank" rel="noopener">`
        : '<div class="chat-attachment chat-attachment-file">';
    const closeTag = hasDownloadUrl ? '</a>' : '</div>';

    return `
        ${openTag}
            <span class="chat-attachment-icon"><i class="fas ${attachmentIcon(attachment)}"></i></span>
            <span class="chat-attachment-copy">
                <strong>${name}</strong>
                <span>${meta}</span>
            </span>
        ${closeTag}`;
}

function isNearBottom(box) {
    return box.scrollHeight - box.scrollTop - box.clientHeight < 80;
}

function scrollToBottom(force = false) {
    const box = getChatBox();
    if (!box) return;

    if (force || isNearBottom(box)) {
        box.scrollTop = box.scrollHeight;
    }
}

function markRoomSeen(roomId, messageId) {
    if (!roomId || !messageId) return;

    const current = Number(seenMap[roomId] || 0);
    if (messageId > current) {
        seenMap[roomId] = messageId;
        persistSeenMap();
    }
}

function clearActiveState() {
    document.querySelectorAll('.chat-room-item').forEach(item => item.classList.remove('active'));
}

function updateRoomHeader(nama, tipe) {
    const iconEl = document.getElementById('chatRoomTitleIcon');
    const titleEl = document.getElementById('chatRoomTitleText');
    const metaEl = document.getElementById('chatRoomTitleMeta');
    if (!iconEl || !titleEl || !metaEl) return;

    const isPersonal = tipe === 'personal';
    iconEl.className = `fas ${isPersonal ? 'fa-user' : 'fa-hashtag'}`;
    titleEl.textContent = nama;
    metaEl.textContent = isPersonal ? 'Chat personal aktif' : 'Room group aktif';
}

function createMessageNode(message, pending = false) {
    const mine = Number(message.user_id) === CURRENT_USER_ID;
    const div = document.createElement('div');
    div.className = `chat-msg${mine ? ' mine' : ''}${pending ? ' pending' : ''}`;
    if (message && message.id !== undefined && message.id !== null) {
        div.dataset.messageId = String(message.id);
    }

    const sender = mine || !message.nama
        ? ''
        : `<span class="sender">${escHtml(message.nama || '')}</span><span class="sep">&bull;</span>`;
    const time = pending ? 'Mengirim...' : formatClock(message.created_at);
    const messageText = escHtml(message.message_text !== undefined ? message.message_text : (message.pesan || ''));
    const attachmentMarkup = buildMessageAttachmentMarkup(message);

    div.innerHTML = `
        <div class="bubble">
            ${messageText !== '' ? `<div class="chat-message-text">${messageText}</div>` : ''}
            ${attachmentMarkup}
        </div>
        <div class="meta">${sender}${time}</div>`;

    return div;
}

function autoGrowChatInput(textarea) {
    if (!textarea) return;

    textarea.style.height = 'auto';
    textarea.style.height = `${Math.min(textarea.scrollHeight, 120)}px`;
}

function handleChatInputKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        kirimPesan();
    }
}

function appendMessages(messages, options = {}) {
    const box = getChatBox();
    if (!box || !Array.isArray(messages) || messages.length === 0) return;

    const shouldStickBottom = options.forceScroll || isNearBottom(box);
    const emptyState = document.getElementById('chatEmptyState');
    if (emptyState) {
        emptyState.remove();
    }

    messages.forEach(message => {
        const messageId = String(message?.id ?? '');
        const alreadyRendered = messageId
            && Array.from(box.querySelectorAll('.chat-msg')).some(node => node.dataset.messageId === messageId);
        if (alreadyRendered) {
            lastId = Math.max(lastId, Number(message.id || 0));
            return;
        }
        box.appendChild(createMessageNode(message, false));
        lastId = Math.max(lastId, Number(message.id || 0));
    });

    if (activeRoom && lastId > 0) {
        markRoomSeen(activeRoom, lastId);
    }

    if (shouldStickBottom) {
        box.scrollTop = box.scrollHeight;
    }
}

function renderedRoomIds() {
    return Array.from(document.querySelectorAll('.chat-room-item[data-room-id]'))
        .map(item => Number(item.dataset.roomId || 0))
        .filter(Boolean);
}

function updateRoomSummary(summary) {
    const roomId = Number(summary.room_id || 0);
    if (!roomId) return;

    const previewEl = document.getElementById(`room-preview-${roomId}`);
    const timeEl = document.getElementById(`room-time-${roomId}`);
    const unreadEl = document.getElementById(`room-unread-${roomId}`);
    const roomEl = document.getElementById(`room-${roomId}`);
    if (!roomEl) return;

    const roomType = roomEl.dataset.roomType || 'group';
    const preview = buildRoomPreview(summary, roomType);
    const unreadCount = Number(summary.unread_count || 0);
    roomEl.dataset.unreadCount = String(unreadCount);
    roomEl.dataset.lastTs = String(toUnixTimestamp(summary.created_at));

    if (previewEl) previewEl.textContent = preview;
    if (timeEl) timeEl.textContent = formatRoomTime(summary.created_at);
    const hasUnread = roomId !== Number(activeRoom) && unreadCount > 0;

    if (unreadEl) {
        unreadEl.hidden = !hasUnread;
        if (hasUnread) {
            unreadEl.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
        }
    }
}

function fetchRoomSummaries(force = false) {
    if (isFetchingSummary || document.hidden) return;

    const roomIds = renderedRoomIds();
    if (!roomIds.length) return;

    isFetchingSummary = true;
    $.post(CHAT_ENDPOINT, { action: 'ringkasan', room_ids: roomIds }, function(data) {
        if (Array.isArray(data)) {
            data.forEach(updateRoomSummary);
            sortAllRoomLists();
            updateChatSidebarOverview();
            filterRoomSections();
        }
        if (force) {
            setRealtimeState(activeRoom ? 'live' : '', activeRoom ? 'Sinkron diperbarui' : 'Daftar room diperbarui');
        }
    }, 'json').fail(function() {
        if (force) {
            setRealtimeState('error', 'Gagal sinkron room');
        }
    }).always(function() {
        isFetchingSummary = false;
    });
}

function fetchMessages(options = {}) {
    if (!activeRoom || isFetchingMessages || document.hidden) return;

    isFetchingMessages = true;
    setRealtimeState('syncing', 'Memeriksa pesan terbaru...');

    $.post(CHAT_ENDPOINT, {
        action: 'load',
        room_id: activeRoom,
        since: options.reset ? 0 : lastId
    }, function(data) {
        if (options.reset) {
            const box = getChatBox();
            if (box) box.innerHTML = '';
            lastId = 0;
        }

        if (!Array.isArray(data) || data.length === 0) {
            const box = getChatBox();
            if (box && !box.children.length) {
                renderEmptyState('Belum ada pesan di room ini.');
            }
            if (options.reset && typeof window.ensureNotificationFeedFresh === 'function') {
                window.ensureNotificationFeedFresh(true);
            }
            setRealtimeState('live', 'Tidak ada pesan baru');
            return;
        }

        appendMessages(data, { forceScroll: Boolean(options.reset) || Boolean(options.forceScroll) });
        if (options.reset && typeof window.ensureNotificationFeedFresh === 'function') {
            window.ensureNotificationFeedFresh(true);
        }
        setRealtimeState('live', `Sinkron ${new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`);
    }, 'json').fail(function() {
        setRealtimeState('error', 'Gagal memuat pesan');
    }).always(function() {
        isFetchingMessages = false;
    });
}

function restartPolling() {
    stopRealtimeStream();

    if (pollTimer) clearInterval(pollTimer);
    if (summaryTimer) clearInterval(summaryTimer);

    summaryTimer = setInterval(fetchRoomSummaries, CHAT_SUMMARY_MS);

    if (activeRoom) {
        pollTimer = setInterval(fetchMessages, CHAT_REFRESH_MS);
    }
}

function stopRealtimeStream() {
    if (streamRetryTimer) {
        clearTimeout(streamRetryTimer);
        streamRetryTimer = null;
    }

    if (streamSource) {
        streamSource.close();
        streamSource = null;
    }
}

function buildStreamUrl() {
    const url = new URL(CHAT_STREAM_ENDPOINT, window.location.origin);
    if (activeRoom) {
        url.searchParams.set('room_id', String(activeRoom));
    }
    if (lastId > 0) {
        url.searchParams.set('last_id', String(lastId));
    }
    return url.toString();
}

function startRealtimeStream() {
    if (!CHAT_STREAM_ENDPOINT || typeof window.EventSource !== 'function' || document.hidden) {
        return false;
    }

    stopRealtimeStream();

    if (pollTimer) clearInterval(pollTimer);
    if (summaryTimer) clearInterval(summaryTimer);

    const source = new EventSource(buildStreamUrl());
    streamSource = source;

    source.addEventListener('open', function() {
        streamFailureCount = 0;
        setRealtimeState(activeRoom ? 'live' : '', activeRoom ? 'Realtime aktif' : 'Daftar room realtime aktif');
    });

    source.addEventListener('summaries', function(event) {
        try {
            const payload = JSON.parse(event.data || '{}');
            if (Array.isArray(payload.summaries)) {
                payload.summaries.forEach(updateRoomSummary);
                sortAllRoomLists();
                updateChatSidebarOverview();
                filterRoomSections();
            }
            if (typeof window.ensureNotificationFeedFresh === 'function') {
                window.ensureNotificationFeedFresh(false);
            }
        } catch (error) {
        }
    });

    source.addEventListener('messages', function(event) {
        try {
            const payload = JSON.parse(event.data || '{}');
            if (Array.isArray(payload.messages) && payload.messages.length > 0) {
                appendMessages(payload.messages, { forceScroll: true });
                setRealtimeState('live', `Realtime ${new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`);
                if (typeof window.ensureNotificationFeedFresh === 'function') {
                    window.ensureNotificationFeedFresh(true);
                }
            }
        } catch (error) {
        }
    });

    source.addEventListener('ping', function() {
        if (activeRoom) {
            setRealtimeState('live', 'Realtime aktif');
        }
    });

    source.addEventListener('error', function() {
        streamFailureCount += 1;
        stopRealtimeStream();

        if (streamFailureCount >= 3) {
            setRealtimeState('error', 'Realtime terputus, kembali ke sinkron berkala');
            restartPolling();
            return;
        }

        streamRetryTimer = setTimeout(function() {
            if (!document.hidden) {
                startRealtimeStream();
            }
        }, Math.min(5000, 1500 * streamFailureCount));
    });

    return true;
}

function focusChatInput() {
    const input = getChatInput();
    if (!input) return;

    syncChatComposerState();
    autoGrowChatInput(input);

    if (CHAT_MOBILE_BREAKPOINT.matches) {
        return;
    }

    input.focus();
}

function syncChatComposerState() {
    const input = getChatInput();
    const sendButton = document.getElementById('chatSendButton');
    const attachButton = document.querySelector('[data-chat-action="open-attachment-picker"]');
    const clearButton = document.querySelector('[data-chat-action="clear-attachment"]');
    const quickReplyButtons = document.querySelectorAll('.chat-quick-reply[data-chat-action="quick-reply"]');
    if (!input || !sendButton) return;

    if (activeRoom && !isSendingMessage) {
        saveDraftForRoom(activeRoom, input.value);
    } else {
        updateDraftStatus();
    }

    input.readOnly = !activeRoom || isSendingMessage;
    sendButton.disabled = !activeRoom || isSendingMessage || (!input.value.trim() && !activeAttachmentFile);
    if (attachButton) {
        attachButton.disabled = !activeRoom || isSendingMessage;
    }
    if (clearButton) {
        clearButton.disabled = !activeAttachmentFile || isSendingMessage;
    }
    quickReplyButtons.forEach(button => {
        button.disabled = !activeRoom || isSendingMessage;
    });
}

function setRoomFilter(filter) {
    activeRoomFilter = ['all', 'unread', 'group', 'personal'].includes(filter) ? filter : 'all';
    persistRoomFilter();
    updateFilterButtons();
    filterRoomSections();
}

function applyQuickReply(message) {
    const input = getChatInput();
    if (!input || !activeRoom) return;

    const text = String(message || '').trim();
    if (text === '') return;

    input.value = input.value.trim() === '' ? text : `${input.value.trim()}\n${text}`;
    autoGrowChatInput(input);
    syncChatComposerState();
    input.focus();
}

function loadRoom(id, nama, tipe) {
    const currentInput = getChatInput();
    if (currentInput && activeRoom) {
        saveDraftForRoom(activeRoom, currentInput.value);
    }

    activeRoom = Number(id);
    activeRoomNama = nama;
    activeRoomTipe = tipe || 'group';
    lastId = 0;

    clearActiveState();
    const roomEl = document.getElementById(`room-${activeRoom}`);
    if (roomEl) {
        roomEl.classList.add('active');
        roomEl.dataset.unreadCount = '0';
        const unreadEl = document.getElementById(`room-unread-${activeRoom}`);
        if (unreadEl) {
            unreadEl.hidden = true;
            unreadEl.textContent = '0';
        }
    }

    updateRoomHeader(activeRoomNama, activeRoomTipe);
    updateChatSidebarOverview();
    filterRoomSections();

    const inputArea = document.getElementById('chatInputArea');
    if (inputArea) {
        inputArea.hidden = false;
    }

    setMobileRoomState(true);
    saveActiveRoomState();
    const box = getChatBox();
    if (box) {
        box.innerHTML = '';
    }
    renderEmptyState('Menyambungkan room...');
    restoreDraftForActiveRoom();
    clearChatAttachment();
    syncChatComposerState();
    if (!startRealtimeStream()) {
        fetchMessages({ reset: true, forceScroll: true });
        fetchRoomSummaries(true);
        restartPolling();
    }
    focusChatInput();
}

function createOptimisticMessage(text, attachment = null) {
    return {
        id: `pending-${Date.now()}`,
        pesan: text,
        message_text: text,
        attachment,
        created_at: new Date().toISOString(),
        nama: '',
        user_id: CURRENT_USER_ID
    };
}

function kirimPesan() {
    const input = getChatInput();
    const sendButton = document.getElementById('chatSendButton');
    if (!input || !activeRoom || isSendingMessage) return;

    const roomIdAtSend = Number(activeRoom);
    const pesan = input.value.trim();
    const selectedAttachment = activeAttachmentFile;
    if (!pesan && !selectedAttachment) return;
    const pendingDraft = pesan;

    isSendingMessage = true;
    if (sendButton) sendButton.disabled = true;
    saveDraftForRoom(roomIdAtSend, pendingDraft);

    let optimisticAttachment = null;
    if (selectedAttachment) {
        optimisticAttachment = {
            name: selectedAttachment.name,
            size: selectedAttachment.size,
            mime: selectedAttachment.mime,
            ext: selectedAttachment.ext,
            kind: selectedAttachment.kind,
            url: selectedAttachment.local_url || '',
            download_url: selectedAttachment.local_url || '',
            preview_url: selectedAttachment.kind === 'image' ? (selectedAttachment.local_url || '') : ''
        };
    }

    const box = getChatBox();
    const optimisticNode = createMessageNode(createOptimisticMessage(pesan, optimisticAttachment), true);
    const emptyState = document.getElementById('chatEmptyState');
    if (emptyState) emptyState.remove();
    if (box) {
        box.appendChild(optimisticNode);
        box.scrollTop = box.scrollHeight;
    }

    const formData = new FormData();
    formData.append('action', 'kirim');
    formData.append('room_id', String(activeRoom));
    formData.append('pesan', pesan);
    if (selectedAttachment && selectedAttachment.file) {
        formData.append('attachment', selectedAttachment.file);
    }

    input.value = '';
    autoGrowChatInput(input);
    syncChatComposerState();
    updateDraftStatus(selectedAttachment ? 'Mengirim pesan dan lampiran...' : 'Mengirim pesan...');
    setRealtimeState('syncing', selectedAttachment ? 'Mengirim pesan dan lampiran...' : 'Mengirim pesan...');

    $.ajax({
        url: CHAT_ENDPOINT,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json'
    }).done(function(res) {
        optimisticNode.remove();

        if (res && res.success && res.message) {
            const responseRoomId = Number(res.message.room_id || roomIdAtSend || 0);
            const stillOnSameRoom = Number(activeRoom) === responseRoomId;

            clearDraftForRoom(roomIdAtSend);
            if (stillOnSameRoom) {
                appendMessages([res.message], { forceScroll: true });
            }

            if (activeAttachmentFile === selectedAttachment) {
                clearChatAttachment();
            } else if (selectedAttachment && selectedAttachment.local_url && selectedAttachment.local_url.startsWith('blob:')) {
                URL.revokeObjectURL(selectedAttachment.local_url);
            }

            updateRoomSummary({
                room_id: responseRoomId,
                last_id: res.message.id,
                last_message: res.message.pesan,
                preview_text: res.message.preview_text || '',
                created_at: res.message.created_at,
                sender_name: res.message.nama,
                user_id: res.message.user_id
            });
            sortAllRoomLists();
            updateChatSidebarOverview();
            filterRoomSections();
            setRealtimeState('live', selectedAttachment ? 'Pesan dan lampiran terkirim' : 'Pesan terkirim');
        } else {
            if (Number(activeRoom) === roomIdAtSend) {
                input.value = pendingDraft;
                autoGrowChatInput(input);
            }
            alert((res && res.msg) || 'Gagal mengirim pesan');
            setRealtimeState('error', 'Pesan gagal dikirim');
        }
    }).fail(function() {
        optimisticNode.remove();
        if (Number(activeRoom) === roomIdAtSend) {
            input.value = pendingDraft;
            autoGrowChatInput(input);
        }
        alert('Gagal mengirim pesan');
        setRealtimeState('error', 'Pesan gagal dikirim');
    }).always(function() {
        isSendingMessage = false;
        if (sendButton) sendButton.disabled = false;
        syncChatComposerState();
        if (Number(activeRoom) === roomIdAtSend) {
            focusChatInput();
        }
    });
}

function buildRoomButton(roomId, roomName, type) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'chat-room-item';
    button.id = `room-${roomId}`;
    button.dataset.chatAction = 'open-room';
    button.dataset.roomId = String(roomId);
    button.dataset.roomName = roomName;
    button.dataset.roomType = type;
    button.dataset.search = roomName.toLowerCase();
    button.dataset.lastTs = '0';
    button.dataset.unreadCount = '0';
    button.dataset.default = '0';

    const avatarMarkup = type === 'personal'
        ? `<span class="chat-room-avatar">${escHtml(roomName.charAt(0).toUpperCase())}</span>`
        : `<span class="chat-room-icon"><i class="fas fa-hashtag"></i></span>`;

    button.innerHTML = `
        ${avatarMarkup}
        <span class="chat-room-copy">
            <span class="chat-room-name">${escHtml(roomName)}</span>
            <span class="chat-room-preview" id="room-preview-${roomId}">Belum ada pesan</span>
        </span>
        <span class="chat-room-meta">
            <span class="chat-room-time" id="room-time-${roomId}"></span>
            <span class="chat-room-unread" id="room-unread-${roomId}" hidden>Baru</span>
        </span>`;

    return button;
}

function bukaPersonal(targetId, targetNama) {
    $.post(CHAT_ENDPOINT, { action: 'personal', target_id: targetId }, function(res) {
        if (!res || !res.success) {
            alert((res && res.msg) || 'Gagal membuka chat');
            return;
        }

        if (res.is_new) {
            const personalList = document.getElementById('personalRoomList');
            if (personalList && !document.getElementById(`room-${res.room_id}`)) {
                const note = document.getElementById('personalRoomEmptyNote');
                if (note) note.remove();
                personalList.prepend(buildRoomButton(res.room_id, targetNama, 'personal'));
                sortAllRoomLists();
                updateChatSidebarOverview();
                filterRoomSections();
            }
        }

        loadRoom(res.room_id, targetNama, 'personal');
    }, 'json');
}

function buatRoom() {
    const input = document.getElementById('namaRoom');
    if (!input) return;

    const nama = input.value.trim();
    if (!nama) return;

    $.post(CHAT_ENDPOINT, { action: 'buat_room', nama }, function(res) {
        if (!res || !res.success) {
            alert('Gagal membuat room');
            return;
        }

        const list = document.getElementById('groupRoomList');
        if (list && !document.getElementById(`room-${res.id}`)) {
            list.appendChild(buildRoomButton(res.id, res.nama, 'group'));
            sortAllRoomLists();
            updateChatSidebarOverview();
            filterRoomSections();
        }

        closeModal('modalRoom');
        input.value = '';
        loadRoom(res.id, res.nama, 'group');
    }, 'json');
}

function filterChatLists() {
    const contactQuery = (document.getElementById('chatContactSearch')?.value || '').trim().toLowerCase();
    filterRoomSections();

    document.querySelectorAll('.user-list-item').forEach(item => {
        const haystack = item.dataset.search || '';
        item.style.display = haystack.includes(contactQuery) ? '' : 'none';
    });
}

function bindChatInteractions() {
    const layout = document.getElementById('chatLayout');
    if (!layout || layout.dataset.bound === '1') return;

    layout.dataset.bound = '1';
    layout.addEventListener('click', function(event) {
        const trigger = event.target.closest('[data-chat-action]');
        if (!trigger) return;

        const action = trigger.dataset.chatAction || '';
        if (action === 'open-room') {
            event.preventDefault();
            const roomId = Number(trigger.dataset.roomId || 0);
            const roomName = trigger.dataset.roomName || trigger.textContent.trim();
            const roomType = trigger.dataset.roomType || 'group';
            if (roomId) {
                loadRoom(roomId, roomName, roomType);
            }
            return;
        }

        if (action === 'open-personal') {
            event.preventDefault();
            const targetId = Number(trigger.dataset.targetId || 0);
            const targetName = trigger.dataset.targetName || trigger.textContent.trim();
            if (targetId) {
                bukaPersonal(targetId, targetName);
            }
            return;
        }

        if (action === 'show-sidebar') {
            event.preventDefault();
            toggleChatSidebar(true);
            return;
        }

        if (action === 'set-room-filter') {
            event.preventDefault();
            setRoomFilter(trigger.dataset.chatFilter || 'all');
            return;
        }

        if (action === 'quick-reply') {
            event.preventDefault();
            if (isSendingMessage) {
                return;
            }
            applyQuickReply(trigger.dataset.chatQuickReply || '');
            return;
        }

        if (action === 'open-attachment-picker') {
            event.preventDefault();
            if (isSendingMessage) {
                return;
            }
            const attachmentInput = getChatAttachmentInput();
            if (attachmentInput) {
                attachmentInput.click();
            }
            return;
        }

        if (action === 'clear-attachment') {
            event.preventDefault();
            if (!isSendingMessage) {
                clearChatAttachment();
            }
        }
    });
}

function restoreInitialRoom() {
    const requestedRoomId = Number(new URL(window.location.href).searchParams.get('room') || 0);
    const requestedRoom = requestedRoomId > 0 ? document.getElementById(`room-${requestedRoomId}`) : null;
    const stored = getStoredActiveRoomState();
    const roomItem = requestedRoom || (stored && document.getElementById(`room-${stored.id}`)
        ? document.getElementById(`room-${stored.id}`)
        : document.querySelector('.chat-room-item[data-default="1"]') || document.querySelector('.chat-room-item'));

    if (!roomItem || (window.innerWidth <= 768 && !requestedRoom)) {
        setMobileRoomState(false);
        updateChatSidebarOverview();
        filterRoomSections();
        fetchRoomSummaries(true);
        if (!startRealtimeStream()) {
            restartPolling();
        }
        return;
    }

    const roomId = Number(roomItem.dataset.roomId || 0);
    const roomName = roomItem.dataset.roomName || roomItem.textContent.trim();
    const roomType = roomItem.dataset.roomType || 'group';
    if (roomId) {
        loadRoom(roomId, roomName, roomType);
    }
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopRealtimeStream();
        if (pollTimer) clearInterval(pollTimer);
        if (summaryTimer) clearInterval(summaryTimer);
        return;
    }

    fetchRoomSummaries(true);
    if (activeRoom) {
        fetchMessages({ forceScroll: false });
    }
    if (!startRealtimeStream()) {
        restartPolling();
    }
});

function syncChatLayoutOnViewportChange() {
    setMobileRoomState(CHAT_MOBILE_BREAKPOINT.matches && Boolean(activeRoom));
}

window.addEventListener('focus', function() {
    if (document.hidden) {
        return;
    }

    if (!streamSource && !startRealtimeStream()) {
        fetchRoomSummaries(true);
        if (activeRoom) {
            fetchMessages();
        }
    }
});

window.addEventListener('beforeunload', function() {
    stopRealtimeStream();
    if (pollTimer) clearInterval(pollTimer);
    if (summaryTimer) clearInterval(summaryTimer);
});

if (typeof CHAT_MOBILE_BREAKPOINT.addEventListener === 'function') {
    CHAT_MOBILE_BREAKPOINT.addEventListener('change', syncChatLayoutOnViewportChange);
} else if (typeof CHAT_MOBILE_BREAKPOINT.addListener === 'function') {
    CHAT_MOBILE_BREAKPOINT.addListener(syncChatLayoutOnViewportChange);
}

document.addEventListener('DOMContentLoaded', function() {
    bindChatInteractions();
    const attachmentInput = getChatAttachmentInput();
    if (attachmentInput) {
        attachmentInput.addEventListener('change', function(event) {
            const file = event.target && event.target.files ? event.target.files[0] : null;
            handleChatAttachmentSelection(file || null);
        });
    }
    sortAllRoomLists();
    updateFilterButtons();
    updateChatSidebarOverview();
    filterRoomSections();
    updateChatAttachmentPreview();
    syncChatComposerState();
    setMobileRoomState(false);
    restoreInitialRoom();
});

window.loadRoom = loadRoom;
window.kirimPesan = kirimPesan;
window.bukaPersonal = bukaPersonal;
window.buatRoom = buatRoom;
window.filterChatLists = filterChatLists;
window.toggleChatSidebar = toggleChatSidebar;
window.autoGrowChatInput = autoGrowChatInput;
window.handleChatInputKeydown = handleChatInputKeydown;
window.syncChatComposerState = syncChatComposerState;
