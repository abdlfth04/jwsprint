let cart = [];
let activeProduk = null;
let tipeFilter = '';
let pelangganCatalog = [];
let invoiceCatalog = [];
let posRequestInFlight = false;
let posCartTopbarObserver = null;
let shouldRevealLatestCartItem = false;
const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
const posState = pageState.posState || {};
const formatCurrency = window.jwsFormatCurrency || function(value) {
    return `Rp ${Number(value || 0).toLocaleString('id-ID')}`;
};
const formatQuantity = function(value) {
    if (typeof window.jwsFormatQuantity !== 'function') {
        return Number(value || 0).toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }

    return window.jwsFormatQuantity(value, { preserveIntegers: true, maximumFractionDigits: 2 });
};
const posEscapeHtml = window.jwsEscapeHtml || function(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};
const posNormalizeSearchText = window.jwsNormalizeSearchText || function(value) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
};
let posCustomerSaveInFlight = false;

function getPosEndpoint() {
    return posState.endpoint || 'pos.php';
}

function getPosCustomerCatalogEndpoint() {
    return posState.customerCatalogEndpoint || `${getPosEndpoint()}?ajax=pelanggan_catalog`;
}

function getPosCsrfToken() {
    return window.getCsrfToken ? window.getCsrfToken() : '';
}

function currentPosUserRole() {
    return String(posState.userRole || '').toLowerCase();
}

function canProcessPosPayment() {
    return ['superadmin', 'admin', 'kasir'].includes(currentPosUserRole());
}

function canSubmitPosIntake() {
    return ['superadmin', 'admin', 'service', 'kasir'].includes(currentPosUserRole());
}

function getInvoiceOptionsPanel() {
    return document.getElementById('invoiceOptionsPanel');
}

function getPosActionButtons() {
    return {
        clearCartButton: document.getElementById('clearCartBtn'),
        submitIntakeButton: document.getElementById('submitIntakeBtn'),
        openCheckoutButton: document.querySelector('.pos-checkout-btn'),
        submitCheckoutButton: document.getElementById('submitCheckoutBtn')
    };
}

function syncPosCartViewport() {
    const cartPanel = document.getElementById('posCart');
    const topbar = document.querySelector('.topbar');

    if (!cartPanel) {
        return;
    }

    if (window.innerWidth <= 980 || !topbar) {
        cartPanel.style.removeProperty('top');
        cartPanel.style.removeProperty('max-height');
        return;
    }

    const topbarStyles = window.getComputedStyle(topbar);
    const stickyTop = parseFloat(topbarStyles.top || '0') || 0;
    const topbarHeight = Math.ceil(topbar.getBoundingClientRect().height || topbar.offsetHeight || 0);
    const topOffset = Math.max(24, Math.ceil(stickyTop + topbarHeight + 18));
    const maxHeight = Math.max(420, Math.floor(window.innerHeight - topOffset - 18));

    cartPanel.style.top = `${topOffset}px`;
    cartPanel.style.maxHeight = `${maxHeight}px`;
}

function revealLatestCartItem(cartItems) {
    if (window.innerWidth <= 980) {
        return;
    }

    const latestItem = cartItems?.querySelector('.cart-item:last-child');
    if (!latestItem) {
        return;
    }

    requestAnimationFrame(() => {
        latestItem.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'nearest'
        });
    });
}

function setButtonLoadingState(button, isLoading, loadingHtml) {
    if (!button) {
        return;
    }

    if (!button.dataset.originalHtml) {
        button.dataset.originalHtml = button.innerHTML;
    }

    button.innerHTML = isLoading && loadingHtml ? loadingHtml : button.dataset.originalHtml;
}

function setPosRequestBusy(isBusy, mode = '') {
    posRequestInFlight = isBusy;

    const {
        submitIntakeButton,
        openCheckoutButton,
        submitCheckoutButton
    } = getPosActionButtons();

    setButtonLoadingState(
        submitIntakeButton,
        isBusy && mode === 'draft',
        '<i class="fas fa-spinner fa-spin"></i> Menyimpan draft...'
    );
    setButtonLoadingState(
        openCheckoutButton,
        isBusy && mode === 'checkout',
        '<i class="fas fa-spinner fa-spin"></i> Memproses...'
    );
    setButtonLoadingState(
        submitCheckoutButton,
        isBusy && mode === 'checkout',
        '<i class="fas fa-spinner fa-spin"></i> Memproses...'
    );

    updateTotal();
}

function setInputValue(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.value = value;
    }
}

function setTextContent(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function openPosModal(id) {
    if (typeof window.openModal === 'function') {
        window.openModal(id);
        return;
    }

    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
    }
}

function sortPelangganCatalog() {
    const generalOptions = pelangganCatalog.filter(item => item.value === '');
    const customerOptions = pelangganCatalog
        .filter(item => item.value !== '')
        .sort((left, right) => left.label.localeCompare(right.label, 'id', { sensitivity: 'base' }));

    pelangganCatalog = [...generalOptions, ...customerOptions];
}

function createPelangganOption(item) {
    const option = document.createElement('option');
    option.value = item.value;
    option.textContent = item.text;
    option.dataset.mitra = item.mitra;
    option.dataset.label = item.label;
    option.dataset.search = item.search;
    option.disabled = !!item.disabled;

    return option;
}

function getDefaultPelangganCatalogEntry() {
    const currentDefault = pelangganCatalog.find(item => item.value === '');
    if (currentDefault) {
        return {
            value: '',
            text: currentDefault.text || '-- Pelanggan Umum --',
            label: currentDefault.label || 'Pelanggan Umum',
            search: posNormalizeSearchText(currentDefault.search || 'pelanggan umum'),
            mitra: '0',
            disabled: false
        };
    }

    return {
        value: '',
        text: '-- Pelanggan Umum --',
        label: 'Pelanggan Umum',
        search: posNormalizeSearchText('pelanggan umum'),
        mitra: '0',
        disabled: false
    };
}

function buildPelangganCatalogEntry(customer) {
    const value = String(customer?.id || customer?.value || '').trim();
    const mitra = String(customer?.is_mitra ?? customer?.mitra ?? '0');
    const baseName = String(customer?.nama || customer?.label || '').trim();

    if (value === '' || baseName === '') {
        return null;
    }

    const label = baseName + (mitra === '1' ? ' *' : '');
    return {
        value,
        text: label,
        label,
        search: posNormalizeSearchText([
            baseName,
            customer?.telepon || '',
            customer?.email || '',
            customer?.alamat || ''
        ].join(' ')),
        mitra,
        disabled: false
    };
}

function replacePelangganCatalog(customers, preferredValue = null) {
    if (!Array.isArray(customers)) {
        return;
    }

    pelangganCatalog = [
        getDefaultPelangganCatalogEntry(),
        ...customers
            .map(buildPelangganCatalogEntry)
            .filter(Boolean)
    ];

    sortPelangganCatalog();
    renderPelangganOptions(document.getElementById('pelangganSearch')?.value || '', preferredValue);
}

async function syncPelangganCatalogFromServer(preferredValue = null) {
    const response = await fetch(getPosCustomerCatalogEndpoint(), {
        headers: {
            'Accept': 'application/json'
        }
    });
    let payload = null;

    try {
        payload = await response.json();
    } catch (error) {
        payload = null;
    }

    if (!response.ok || !payload || payload.success !== true || !Array.isArray(payload.customers)) {
        throw new Error(payload?.message || payload?.msg || 'Daftar pelanggan terbaru tidak bisa dimuat.');
    }

    replacePelangganCatalog(payload.customers, preferredValue);
    return payload.customers;
}

function updatePelangganSearchInfo(query, matchCount) {
    const info = document.getElementById('pelangganSearchInfo');
    if (!info) {
        return;
    }

    const normalized = posNormalizeSearchText(query);
    if (normalized === '') {
        info.textContent = `${matchCount.toLocaleString('id-ID')} pelanggan siap dipilih.`;
        return;
    }

    if (matchCount > 0) {
        info.textContent = `${matchCount.toLocaleString('id-ID')} pelanggan cocok untuk "${query.trim()}".`;
        return;
    }

    info.textContent = 'Tidak ada pelanggan yang cocok.';
}

function renderPelangganOptions(query = '', preferredValue = null) {
    const pelangganSelect = document.getElementById('pelangganSelect');
    if (!pelangganSelect) {
        return;
    }

    const selectedValue = preferredValue !== null && preferredValue !== undefined
        ? String(preferredValue)
        : String(pelangganSelect.value || '');
    const normalized = posNormalizeSearchText(query);
    const generalOption = pelangganCatalog.find(item => item.value === '') || null;
    const customerMatches = pelangganCatalog.filter(item => {
        if (item.value === '') {
            return false;
        }

        return normalized === '' || item.search.includes(normalized);
    });

    pelangganSelect.innerHTML = '';

    if (generalOption && normalized === '') {
        pelangganSelect.appendChild(createPelangganOption(generalOption));
    }

    if (normalized !== '' && customerMatches.length > 1) {
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = '-- Pilih pelanggan hasil pencarian --';
        placeholderOption.disabled = true;
        placeholderOption.selected = true;
        pelangganSelect.appendChild(placeholderOption);
    }

    customerMatches.forEach(item => {
        pelangganSelect.appendChild(createPelangganOption(item));
    });

    if (normalized !== '' && customerMatches.length === 0) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '-- Tidak ada pelanggan yang cocok --';
        emptyOption.disabled = true;
        pelangganSelect.appendChild(emptyOption);
    }

    const canRestoreValue = Array.from(pelangganSelect.options).some(option => option.value === selectedValue && !option.disabled);
    let nextSelectedValue = canRestoreValue ? selectedValue : '';
    if (normalized !== '' && customerMatches.length === 1) {
        nextSelectedValue = customerMatches[0].value;
    }
    pelangganSelect.value = nextSelectedValue;

    if (normalized !== '' && customerMatches.length > 1 && !canRestoreValue) {
        pelangganSelect.selectedIndex = 0;
    }

    updatePelangganSearchInfo(query, customerMatches.length);
}

function buildPelangganCatalog() {
    const pelangganSelect = document.getElementById('pelangganSelect');
    const pelangganSearch = document.getElementById('pelangganSearch');
    if (!pelangganSelect) {
        return;
    }

    const initialCatalog = Array.from(pelangganSelect.options).map(option => ({
        value: String(option.value || ''),
        text: option.textContent || '',
        label: option.dataset.label || option.textContent || '',
        search: posNormalizeSearchText(option.dataset.search || option.textContent || ''),
        mitra: option.dataset.mitra || '0',
        disabled: !!option.disabled
    }));

    const generalOption = initialCatalog.find(item => item.value === '') || getDefaultPelangganCatalogEntry();
    pelangganCatalog = [
        generalOption,
        ...initialCatalog.filter(item => item.value !== '')
    ];

    sortPelangganCatalog();
    renderPelangganOptions(pelangganSearch?.value || '', pelangganSelect.value);
}

function filterPelangganOptions() {
    const pelangganSearch = document.getElementById('pelangganSearch');
    renderPelangganOptions(pelangganSearch?.value || '');
}

function registerPelangganOption(customer) {
    const customerEntry = buildPelangganCatalogEntry(customer);
    if (!customerEntry) {
        return;
    }

    const selectedValue = customerEntry.value;
    const existingIndex = pelangganCatalog.findIndex(item => item.value === selectedValue);
    if (existingIndex >= 0) {
        pelangganCatalog[existingIndex] = customerEntry;
    } else {
        pelangganCatalog.push(customerEntry);
    }

    sortPelangganCatalog();

    const pelangganSearch = document.getElementById('pelangganSearch');
    if (pelangganSearch) {
        pelangganSearch.value = '';
    }

    renderPelangganOptions('', selectedValue);
}

function getPosTransactionMode() {
    return document.getElementById('invoiceModeSelect')?.value === 'append' ? 'append' : 'new';
}

function isAppendMode() {
    return getPosTransactionMode() === 'append';
}

function setPosTransactionMode(mode) {
    const nextMode = mode === 'append' ? 'append' : 'new';
    const modeSelect = document.getElementById('invoiceModeSelect');
    if (!modeSelect) {
        return;
    }

    modeSelect.value = nextMode;
    onPosTransactionModeChange(nextMode);
}

function syncPosModeUi() {
    const appendMode = isAppendMode();
    const selectedInvoice = getSelectedExistingInvoice();
    const invoiceNote = (document.getElementById('invoiceNoteInput')?.value || '').trim();
    const panel = getInvoiceOptionsPanel();
    const modeSummary = document.getElementById('posModeSummary');
    const modeDescription = document.getElementById('posModeDescription');
    const optionsSummary = document.getElementById('invoiceOptionsSummary');

    document.querySelectorAll('.cart-mode-btn').forEach(button => {
        const isActive = button.dataset.mode === (appendMode ? 'append' : 'new');
        button.classList.toggle('btn-primary', isActive);
        button.classList.toggle('btn-outline', !isActive);
        button.classList.toggle('is-active', isActive);
    });

    if (modeSummary) {
        modeSummary.textContent = appendMode ? 'Tambah ke Invoice Lama' : 'Invoice Baru';
    }

    if (modeDescription) {
        if (appendMode && selectedInvoice) {
            modeDescription.textContent = `Item baru akan masuk ke ${selectedInvoice.text}.`;
        } else if (appendMode) {
            modeDescription.textContent = 'Pilih invoice tujuan.';
        } else if (invoiceNote !== '') {
            modeDescription.textContent = 'Catatan invoice akan ikut disimpan.';
        } else {
            modeDescription.textContent = 'Transaksi baru aktif.';
        }
    }

    if (optionsSummary) {
        if (appendMode && selectedInvoice) {
            optionsSummary.textContent = `Mode amend aktif untuk ${selectedInvoice.text}.`;
        } else if (appendMode) {
            optionsSummary.textContent = 'Pilih invoice tujuan.';
        } else if (invoiceNote !== '') {
            optionsSummary.textContent = 'Invoice baru dengan catatan tambahan.';
        } else {
            optionsSummary.textContent = 'Invoice lama dan catatan tambahan.';
        }
    }

    if (panel && appendMode) {
        panel.open = true;
    }
}

function createInvoiceOption(item) {
    const option = document.createElement('option');
    option.value = item.value;
    option.textContent = item.text;
    option.dataset.search = item.search;
    option.dataset.pelangganId = item.pelangganId;
    option.dataset.pelangganLabel = item.pelangganLabel;
    option.dataset.total = String(item.total || 0);
    option.dataset.bayar = String(item.bayar || 0);
    option.dataset.remaining = String(item.remaining || 0);
    option.dataset.status = item.status;
    option.dataset.workflow = item.workflow;
    option.dataset.note = item.note;
    option.disabled = !!item.disabled;

    return option;
}

function updateInvoiceSearchInfo(query, matchCount) {
    const info = document.getElementById('invoiceSearchInfo');
    if (!info) {
        return;
    }

    const normalized = posNormalizeSearchText(query);
    if (normalized === '') {
        info.textContent = `${matchCount.toLocaleString('id-ID')} invoice aktif siap dipilih.`;
        return;
    }

    if (matchCount > 0) {
        info.textContent = `${matchCount.toLocaleString('id-ID')} invoice cocok untuk "${query.trim()}".`;
        return;
    }

    info.textContent = 'Tidak ada invoice yang cocok dengan pencarian saat ini.';
}

function buildInvoiceCatalog() {
    const select = document.getElementById('existingInvoiceSelect');
    const searchInput = document.getElementById('invoiceSearch');
    if (!select) {
        return;
    }

    invoiceCatalog = Array.from(select.options).map(option => ({
        value: String(option.value || ''),
        text: option.textContent || '',
        search: posNormalizeSearchText(option.dataset.search || option.textContent || ''),
        pelangganId: String(option.dataset.pelangganId || '0'),
        pelangganLabel: option.dataset.pelangganLabel || '',
        total: parseFloat(option.dataset.total || 0),
        bayar: parseFloat(option.dataset.bayar || 0),
        remaining: parseFloat(option.dataset.remaining || 0),
        status: option.dataset.status || '',
        workflow: option.dataset.workflow || '',
        note: option.dataset.note || '',
        disabled: !!option.disabled
    }));

    renderInvoiceOptions(searchInput?.value || '', select.value);
}

function renderInvoiceOptions(query = '', preferredValue = null) {
    const select = document.getElementById('existingInvoiceSelect');
    if (!select) {
        return;
    }

    const selectedValue = preferredValue !== null && preferredValue !== undefined
        ? String(preferredValue)
        : String(select.value || '');
    const normalized = posNormalizeSearchText(query);
    const defaultOption = invoiceCatalog.find(item => item.value === '') || null;
    const matches = invoiceCatalog.filter(item => {
        if (item.value === '') {
            return false;
        }

        return normalized === '' || item.search.includes(normalized);
    });

    select.innerHTML = '';
    if (defaultOption) {
        select.appendChild(createInvoiceOption(defaultOption));
    } else {
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih Invoice Aktif --';
        select.appendChild(placeholder);
    }

    matches.forEach(item => {
        select.appendChild(createInvoiceOption(item));
    });

    if (normalized !== '' && matches.length === 0) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '-- Tidak ada invoice yang cocok --';
        emptyOption.disabled = true;
        select.appendChild(emptyOption);
    }

    const canRestoreValue = Array.from(select.options).some(option => option.value === selectedValue && !option.disabled);
    select.value = canRestoreValue ? selectedValue : '';
    updateInvoiceSearchInfo(query, matches.length);
}

function filterInvoiceOptions() {
    const searchInput = document.getElementById('invoiceSearch');
    renderInvoiceOptions(searchInput?.value || '');
    handleExistingInvoiceChange(false);
}

function getSelectedExistingInvoice() {
    const select = document.getElementById('existingInvoiceSelect');
    if (!select || !select.value) {
        return null;
    }

    const option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        return null;
    }

    return {
        id: parseInt(option.value || '0', 10),
        pelangganId: parseInt(option.dataset.pelangganId || '0', 10),
        pelangganLabel: option.dataset.pelangganLabel || 'Pelanggan Umum',
        total: parseFloat(option.dataset.total || '0'),
        bayar: parseFloat(option.dataset.bayar || '0'),
        remaining: parseFloat(option.dataset.remaining || '0'),
        status: option.dataset.status || '',
        workflow: option.dataset.workflow || '',
        note: option.dataset.note || '',
        text: option.textContent || ''
    };
}

function setCustomerPickerLocked(isLocked) {
    const pelangganSearch = document.getElementById('pelangganSearch');
    const pelangganSelect = document.getElementById('pelangganSelect');
    const addButton = document.querySelector('.customer-picker-add-btn');

    if (pelangganSearch) pelangganSearch.disabled = isLocked;
    if (pelangganSelect) pelangganSelect.disabled = isLocked;
    if (addButton) addButton.disabled = isLocked;
}

function updateExistingInvoiceInfo() {
    const info = document.getElementById('existingInvoiceInfo');
    const invoice = getSelectedExistingInvoice();
    const metrics = getCartMetrics();

    if (!info) {
        return;
    }

    if (!isAppendMode()) {
        info.style.display = 'none';
        info.innerHTML = '';
        return;
    }

    if (!invoice) {
        info.style.display = '';
        info.innerHTML = 'Pilih invoice lama terlebih dahulu.';
        return;
    }

    const resultingTotal = invoice.total + metrics.total;
    const payableNow = invoice.remaining + metrics.total;
    info.style.display = '';
    info.innerHTML = [
        `<div><strong>${posEscapeHtml(invoice.text)}</strong></div>`,
        `<div>Status <strong>${posEscapeHtml(invoice.status || '-')}</strong> | Tahap <strong>${posEscapeHtml(invoice.workflow || '-')}</strong></div>`,
        `<div>Total ${posEscapeHtml(formatCurrency(invoice.total))} | Bayar ${posEscapeHtml(formatCurrency(invoice.bayar))} | Sisa ${posEscapeHtml(formatCurrency(invoice.remaining))}</div>`,
        `<div>Tambahan ${posEscapeHtml(formatCurrency(metrics.total))} | Total baru <strong>${posEscapeHtml(formatCurrency(resultingTotal))}</strong> | Tagihan <strong>${posEscapeHtml(formatCurrency(payableNow))}</strong></div>`
    ].join('');
}

function handleExistingInvoiceChange(fillInvoiceNote = true) {
    const invoice = getSelectedExistingInvoice();
    const pelangganSelect = document.getElementById('pelangganSelect');
    const invoiceNoteInput = document.getElementById('invoiceNoteInput');

    if (invoice && invoice.pelangganId > 0 && pelangganSelect) {
        pelangganSelect.value = String(invoice.pelangganId);
        setCustomerPickerLocked(true);
    } else {
        setCustomerPickerLocked(false);
    }

    if (invoiceNoteInput && (fillInvoiceNote || !invoice)) {
        invoiceNoteInput.value = invoice ? (invoice.note || '') : '';
    }

    updateExistingInvoiceInfo();
    syncPosModeUi();
    updateTotal();
}

function updateCheckoutMethodAvailability() {
    const hiddenMethods = isAppendMode() ? ['downpayment', 'tempo'] : [];
    const selectedMethod = document.querySelector('[name="metodeBayar"]:checked')?.value || 'cash';

    document.querySelectorAll('[name="metodeBayar"]').forEach(input => {
        const wrapper = input.closest('label');
        if (wrapper) {
            wrapper.style.display = hiddenMethods.includes(input.value) ? 'none' : '';
        }
    });

    if (hiddenMethods.includes(selectedMethod)) {
        const cashOption = document.querySelector('[name="metodeBayar"][value="cash"]');
        if (cashOption) {
            cashOption.checked = true;
            onMetodeChange('cash');
        }
    }
}

function onPosTransactionModeChange(value) {
    const picker = document.getElementById('existingInvoicePicker');
    const invoiceNoteInput = document.getElementById('invoiceNoteInput');
    const optionsPanel = getInvoiceOptionsPanel();
    const appendMode = value === 'append';

    if (picker) {
        picker.style.display = appendMode ? '' : 'none';
    }

    if (!appendMode) {
        setCustomerPickerLocked(false);
        const invoiceSelect = document.getElementById('existingInvoiceSelect');
        if (invoiceSelect) {
            invoiceSelect.value = '';
        }
        const invoiceSearch = document.getElementById('invoiceSearch');
        if (invoiceSearch) {
            invoiceSearch.value = '';
        }
        if (invoiceNoteInput) {
            invoiceNoteInput.value = '';
        }
        renderInvoiceOptions('', '');
    } else {
        if (optionsPanel) {
            optionsPanel.open = true;
        }
        renderInvoiceOptions(document.getElementById('invoiceSearch')?.value || '', document.getElementById('existingInvoiceSelect')?.value || '');
        handleExistingInvoiceChange(true);
    }

    updateCheckoutMethodAvailability();
    updateExistingInvoiceInfo();
    syncPosModeUi();
    updateTotal();
}

function getCheckoutContext() {
    const metrics = getCartMetrics();
    const invoice = getSelectedExistingInvoice();

    if (isAppendMode() && invoice) {
        return {
            isAppend: true,
            invoice,
            metrics,
            totalToSettle: invoice.remaining + metrics.total,
            resultingInvoiceTotal: invoice.total + metrics.total
        };
    }

    return {
        isAppend: false,
        invoice: null,
        metrics,
        totalToSettle: metrics.total,
        resultingInvoiceTotal: metrics.total
    };
}

function registerExistingInvoiceSummary(summary, customerLabel) {
    if (!summary || !summary.id) {
        return;
    }

    const select = document.getElementById('existingInvoiceSelect');
    if (!select) {
        return;
    }

    const label = summary.no_transaksi + ' - ' + (customerLabel || 'Pelanggan Umum');
    const searchText = posNormalizeSearchText([
        summary.no_transaksi,
        customerLabel || 'Pelanggan Umum',
        summary.status_label || summary.status || '',
        summary.workflow_label || summary.workflow_step || '',
        summary.catatan_invoice || ''
    ].join(' '));

    const existing = Array.from(select.options).find(option => option.value === String(summary.id));
    const target = existing || document.createElement('option');
    target.value = String(summary.id);
    target.textContent = label;
    target.dataset.search = searchText;
    target.dataset.pelangganId = String(summary.pelanggan_id || 0);
    target.dataset.pelangganLabel = customerLabel || 'Pelanggan Umum';
    target.dataset.total = String(summary.total || 0);
    target.dataset.bayar = String(summary.bayar || 0);
    target.dataset.remaining = String(summary.sisa_bayar || 0);
    target.dataset.status = summary.status_label || summary.status || '';
    target.dataset.workflow = summary.workflow_label || summary.workflow_step || '';
    target.dataset.note = summary.catatan_invoice || '';

    if (!existing) {
        select.appendChild(target);
    }

    buildInvoiceCatalog();
    renderInvoiceOptions(document.getElementById('invoiceSearch')?.value || '', String(summary.id));
    syncPosModeUi();
}

function findBestGrosirPrice(qty, tiers) {
    if (!Array.isArray(tiers) || tiers.length === 0) return null;

    let bestPrice = null;
    tiers.forEach(tier => {
        const minQty = parseFloat(tier.min_qty || 0);
        if (qty >= minQty) {
            bestPrice = parseFloat(tier.harga || 0);
        }
    });

    return bestPrice;
}

function getHargaProduk(produk, qty, allowTier = true) {
    if (!produk) return 0;

    if (!allowTier) {
        return parseFloat(produk.harga || 0);
    }

    const tierPrice = findBestGrosirPrice(qty, produk.grosirTiers || []);
    return tierPrice !== null ? tierPrice : parseFloat(produk.harga || 0);
}

function updateFilterButtons() {
    const buttonMap = {
        btnAll: tipeFilter === '',
        btnPrinting: tipeFilter === 'printing',
        btnApparel: tipeFilter === 'apparel',
        btnLainnya: tipeFilter === 'lainnya'
    };

    Object.entries(buttonMap).forEach(([id, isActive]) => {
        const button = document.getElementById(id);
        if (!button) return;

        button.classList.toggle('btn-primary', isActive);
        button.classList.toggle('btn-outline', !isActive);
        button.classList.toggle('is-active', isActive);
    });
}

function updateSectionVisibility() {
    const sections = [
        { id: 'sectionPrinting', type: 'printing' },
        { id: 'sectionApparel', type: 'apparel' },
        { id: 'sectionLainnya', type: 'lainnya' }
    ];
    let visibleSections = 0;

    sections.forEach(sectionInfo => {
        const section = document.getElementById(sectionInfo.id);
        if (!section) return;

        const cards = Array.from(section.querySelectorAll('.product-card'));
        const hasVisibleCard = cards.some(card => card.style.display !== 'none');
        const matchesType = !tipeFilter || tipeFilter === sectionInfo.type;

        section.style.display = matchesType && hasVisibleCard ? '' : 'none';
        if (matchesType && hasVisibleCard) {
            visibleSections += 1;
        }
    });

    const emptyState = document.getElementById('posSearchEmpty');
    if (emptyState) {
        emptyState.hidden = visibleSections !== 0;
    }
}

function setTipeFilter(tipe) {
    tipeFilter = tipe || '';
    updateFilterButtons();
    filterProduk();
}

function filterProduk() {
    const query = (document.getElementById('searchProduk')?.value || '').trim().toLowerCase();

    document.querySelectorAll('.product-card').forEach(card => {
        const name = (card.dataset.search || card.dataset.nama || '').toLowerCase();
        const type = card.dataset.tipe || '';
        const matchQuery = query === '' || name.includes(query);
        const matchType = !tipeFilter || type === tipeFilter;
        card.style.display = matchQuery && matchType ? '' : 'none';
    });

    updateSectionVisibility();
}

function buildProdukFromElement(element, forcedType) {
    let grosirTiers = [];

    try {
        grosirTiers = JSON.parse(element.dataset.grosirTiers || '[]');
        if (!Array.isArray(grosirTiers)) {
            grosirTiers = [];
        }
    } catch (error) {
        grosirTiers = [];
    }

    return {
        id: parseInt(element.dataset.id || 0, 10),
        nama: element.dataset.nama || '',
        harga: parseFloat(element.dataset.harga || 0),
        stok: parseFloat(element.dataset.stok || 0),
        tipe: forcedType || element.dataset.tipe || 'lainnya',
        satuan: element.dataset.satuan || 'pcs',
        grosirTiers
    };
}

function pilihProduk(element) {
    activeProduk = buildProdukFromElement(element);

    if (activeProduk.tipe === 'printing') {
        setTextContent('printingProdukNama', activeProduk.nama);
        setInputValue('printingSatuan', activeProduk.satuan || 'm2');
        resetFinishingSelectGroup('printingFinishingGroup');
        setInputValue('printingCatatan', '');
        setInputValue('printingLebar', 1);
        setInputValue('printingTinggi', 1);
        setInputValue('printingQty', 1);
        toggleDimensi();
        openPosModal('modalPrinting');
        return;
    }

    setTextContent('apparelProdukNama', activeProduk.nama);
    document.querySelectorAll('#modalApparel .size-qty-input').forEach(input => {
        input.value = '';
    });
    setInputValue('apparelBahan', '');
    resetFinishingSelectGroup('apparelFinishingGroup');
    setInputValue('apparelCatatan', '');
    hitungApparelSubtotal();
    openPosModal('modalApparel');
}

function pilihProdukLainnya(element) {
    activeProduk = buildProdukFromElement(element, 'lainnya');
    setTextContent('lainnyaNama', activeProduk.nama);
    setTextContent('lainnyaSatuan', activeProduk.satuan);
    setInputValue('lainnyaQty', 1);
    setInputValue('lainnyaCatatan', '');
    hitungLainnyaSubtotal();
    openPosModal('modalLainnya');
}

function openCustomProductModal() {
    activeProduk = null;
    setInputValue('customNama', '');
    setInputValue('customHarga', 0);
    setInputValue('customSatuan', 'pcs');
    setInputValue('customQty', 1);
    setInputValue('customCatatan', '');
    hitungCustomSubtotal();
    openPosModal('modalCustomProduct');
}

function toggleDimensi() {
    const satuan = document.getElementById('printingSatuan')?.value || 'm2';
    const isCustomSize = satuan === 'm2';

    const dimensiGroup = document.getElementById('dimensiGroup');
    const qtyGroup = document.getElementById('qtyGroup');

    if (dimensiGroup) dimensiGroup.style.display = isCustomSize ? '' : 'none';
    if (qtyGroup) qtyGroup.style.display = isCustomSize ? 'none' : '';

    if (isCustomSize) {
        hitungLuas();
    } else {
        hitungPrintingSubtotal();
    }
}

function resetFinishingSelectGroup(groupId) {
    const group = document.getElementById(groupId);
    if (!group) return;

    group.querySelectorAll('.js-finishing-select').forEach(select => {
        select.value = '';
    });
}

function getSelectedFinishings(groupId) {
    const group = document.getElementById(groupId);
    if (!group) return [];

    const usedIds = new Set();

    return Array.from(group.querySelectorAll('.js-finishing-select'))
        .map(select => {
            const option = select.selectedOptions?.[0];
            const id = parseInt(select.value || 0, 10);
            const nama = option?.dataset.nama || '';
            const biaya = parseFloat(option?.dataset.biaya || 0);

            if (!id || !nama || usedIds.has(id)) {
                return null;
            }

            usedIds.add(id);

            return {
                id,
                nama,
                biaya: Number.isFinite(biaya) ? biaya : 0
            };
        })
        .filter(Boolean);
}

function finishingNamesFromSelection(finishings) {
    return finishings
        .map(item => String(item?.nama || '').trim())
        .filter(Boolean);
}

function finishingCostFromSelection(finishings) {
    return finishings.reduce((sum, item) => sum + parseFloat(item?.biaya || 0), 0);
}

function hitungLuas() {
    const lebar = parseFloat(document.getElementById('printingLebar')?.value || 0);
    const tinggi = parseFloat(document.getElementById('printingTinggi')?.value || 0);
    const luas = lebar * tinggi;

    const output = document.getElementById('printingLuas');
    if (output) {
        output.value = `${formatQuantity(luas)} m2`;
    }

    hitungPrintingSubtotal();
}

function hitungPrintingSubtotal() {
    if (!activeProduk) return;

    const satuan = document.getElementById('printingSatuan')?.value || 'm2';
    const lebar = parseFloat(document.getElementById('printingLebar')?.value || 0);
    const tinggi = parseFloat(document.getElementById('printingTinggi')?.value || 0);
    const qtyInput = parseFloat(document.getElementById('printingQty')?.value || 0);
    const qty = satuan === 'm2' ? (lebar * tinggi) : qtyInput;
    const allowTier = satuan !== 'm2';
    const harga = getHargaProduk(activeProduk, qty, allowTier);
    const selectedFinishings = getSelectedFinishings('printingFinishingGroup');
    const finishingBiayaPerUnit = finishingCostFromSelection(selectedFinishings);
    const finishingBiaya = satuan === 'm2'
        ? finishingBiayaPerUnit
        : finishingBiayaPerUnit * Math.max(qty, 0);
    const subtotal = (harga * qty) + finishingBiaya;
    const finishingLabel = satuan !== 'm2' && finishingBiayaPerUnit > 0 && qty > 0
        ? `${formatCurrency(finishingBiaya)} (${formatQuantity(qty)} x ${formatCurrency(finishingBiayaPerUnit)})`
        : formatCurrency(finishingBiaya);

    document.getElementById('printingHargaSatuan').textContent = `${formatCurrency(harga)} / ${satuan}`;
    document.getElementById('printingFinBiaya').textContent = finishingLabel;
    document.getElementById('printingSubtotal').textContent = formatCurrency(subtotal);
}

function addPrintingToCart() {
    if (!activeProduk) return;

    const satuan = document.getElementById('printingSatuan')?.value || 'm2';
    const lebar = parseFloat(document.getElementById('printingLebar')?.value || 0);
    const tinggi = parseFloat(document.getElementById('printingTinggi')?.value || 0);
    const qtyInput = parseFloat(document.getElementById('printingQty')?.value || 0);

    let qty = qtyInput;
    let luas = 0;
    if (satuan === 'm2') {
        luas = lebar * tinggi;
        qty = luas;
        if (qty <= 0) {
            alert('Masukkan dimensi yang valid.');
            return;
        }
    } else if (qty <= 0) {
        alert('Masukkan jumlah order yang valid.');
        return;
    }

    const selectedFinishings = getSelectedFinishings('printingFinishingGroup');
    const finishingNames = finishingNamesFromSelection(selectedFinishings);
    const finishingBiayaPerUnit = finishingCostFromSelection(selectedFinishings);
    const finishingBiaya = satuan === 'm2'
        ? finishingBiayaPerUnit
        : finishingBiayaPerUnit * qty;
    const harga = getHargaProduk(activeProduk, qty, satuan !== 'm2');
    const subtotal = (harga * qty) + finishingBiaya;

    cart.push({
        id: activeProduk.id,
        nama: activeProduk.nama,
        harga,
        kat_tipe: 'printing',
        satuan,
        qty,
        lebar,
        tinggi,
        luas,
        finishing_id: satuan === 'm2' && selectedFinishings.length === 1 ? selectedFinishings[0].id : null,
        finishing_nama: finishingNames.join(', '),
        finishing_biaya: finishingBiaya,
        finishing_list: finishingNames,
        bahan_id: null,
        bahan_nama: '',
        size_detail: '',
        subtotal,
        catatan: document.getElementById('printingCatatan')?.value || '',
        label: satuan === 'm2'
            ? `${formatQuantity(lebar)} x ${formatQuantity(tinggi)} m2`
            : `${formatQuantity(qty)} ${satuan}`
    });
    shouldRevealLatestCartItem = true;

    closeModal('modalPrinting');
    renderCart();
}

function readApparelSizes() {
    let totalQty = 0;
    const detail = [];

    document.querySelectorAll('#modalApparel .size-qty-input').forEach(input => {
        const qty = parseInt(input.value || 0, 10);
        if (qty > 0) {
            totalQty += qty;
            detail.push(`${input.dataset.size}:${qty}`);
        }
    });

    return { totalQty, detail };
}

function hitungApparelSubtotal() {
    if (!activeProduk) return;

    const { totalQty } = readApparelSizes();
    const selectedFinishings = getSelectedFinishings('apparelFinishingGroup');
    const finishingBiaya = finishingCostFromSelection(selectedFinishings);
    const harga = getHargaProduk(activeProduk, totalQty);
    const subtotal = (harga + finishingBiaya) * totalQty;

    document.getElementById('apparelTotalQty').textContent = `${formatQuantity(totalQty)} pcs`;
    document.getElementById('apparelHargaSatuan').textContent = formatCurrency(harga);
    document.getElementById('apparelFinBiaya').textContent = `${formatCurrency(finishingBiaya)} / pcs`;
    document.getElementById('apparelSubtotal').textContent = formatCurrency(subtotal);
}

function addApparelToCart() {
    if (!activeProduk) return;

    const { totalQty, detail } = readApparelSizes();
    if (totalQty <= 0) {
        alert('Masukkan jumlah minimal 1 pcs.');
        return;
    }

    const selectedFinishings = getSelectedFinishings('apparelFinishingGroup');
    const finishingNames = finishingNamesFromSelection(selectedFinishings);
    const bahanSelect = document.getElementById('apparelBahan');
    const bahanOption = bahanSelect?.selectedOptions?.[0];
    const finishingBiaya = finishingCostFromSelection(selectedFinishings);
    const harga = getHargaProduk(activeProduk, totalQty);

    cart.push({
        id: activeProduk.id,
        nama: activeProduk.nama,
        harga,
        kat_tipe: 'apparel',
        satuan: 'pcs',
        qty: totalQty,
        lebar: 0,
        tinggi: 0,
        luas: 0,
        finishing_id: selectedFinishings.length === 1 ? selectedFinishings[0].id : null,
        finishing_nama: finishingNames.join(', '),
        finishing_biaya: finishingBiaya,
        finishing_list: finishingNames,
        bahan_id: bahanSelect?.value || null,
        bahan_nama: bahanOption?.dataset.nama || '',
        size_detail: detail.join(', '),
        subtotal: (harga + finishingBiaya) * totalQty,
        catatan: document.getElementById('apparelCatatan')?.value || '',
        label: `${formatQuantity(totalQty)} pcs`
    });
    shouldRevealLatestCartItem = true;

    closeModal('modalApparel');
    renderCart();
}

function hitungLainnyaSubtotal() {
    if (!activeProduk) return;

    const qty = parseFloat(document.getElementById('lainnyaQty')?.value || 0);
    const harga = getHargaProduk(activeProduk, qty);
    const subtotal = harga * qty;

    document.getElementById('lainnyaHarga').textContent = `${formatCurrency(harga)} / ${activeProduk.satuan}`;
    document.getElementById('lainnyaSubtotal').textContent = formatCurrency(subtotal);
}

function addLainnyaToCart() {
    if (!activeProduk) return;

    const qty = parseFloat(document.getElementById('lainnyaQty')?.value || 0);
    if (qty <= 0) {
        alert('Masukkan jumlah order yang valid.');
        return;
    }

    const harga = getHargaProduk(activeProduk, qty);

    cart.push({
        id: activeProduk.id,
        nama: activeProduk.nama,
        harga,
        kat_tipe: 'lainnya',
        satuan: activeProduk.satuan,
        qty,
        lebar: 0,
        tinggi: 0,
        luas: 0,
        finishing_id: null,
        finishing_nama: '',
        finishing_biaya: 0,
        bahan_id: null,
        bahan_nama: '',
        size_detail: '',
        subtotal: harga * qty,
        catatan: document.getElementById('lainnyaCatatan')?.value || '',
        label: `${formatQuantity(qty)} ${activeProduk.satuan}`
    });
    shouldRevealLatestCartItem = true;

    closeModal('modalLainnya');
    renderCart();
}

function hitungCustomSubtotal() {
    const harga = Math.max(0, parseFloat(document.getElementById('customHarga')?.value || 0));
    const qty = Math.max(0, parseFloat(document.getElementById('customQty')?.value || 0));
    const satuan = (document.getElementById('customSatuan')?.value || 'pcs').trim() || 'pcs';
    const subtotal = harga * qty;

    document.getElementById('customHargaDisplay').textContent = `${formatCurrency(harga)} / ${satuan}`;
    document.getElementById('customQtyDisplay').textContent = `${formatQuantity(qty)} ${satuan}`;
    document.getElementById('customSubtotal').textContent = formatCurrency(subtotal);
}

function addCustomToCart() {
    const nama = (document.getElementById('customNama')?.value || '').trim();
    const harga = parseFloat(document.getElementById('customHarga')?.value || 0);
    const qty = parseFloat(document.getElementById('customQty')?.value || 0);
    const satuan = (document.getElementById('customSatuan')?.value || 'pcs').trim() || 'pcs';
    const catatan = document.getElementById('customCatatan')?.value || '';

    if (nama === '') {
        alert('Nama produk custom wajib diisi.');
        return;
    }
    if (harga <= 0) {
        alert('Harga produk custom harus lebih besar dari nol.');
        return;
    }
    if (qty <= 0) {
        alert('Jumlah order produk custom tidak valid.');
        return;
    }

    cart.push({
        id: 0,
        nama,
        harga,
        kat_tipe: 'lainnya',
        satuan,
        qty,
        lebar: 0,
        tinggi: 0,
        luas: 0,
        finishing_id: null,
        finishing_nama: '',
        finishing_biaya: 0,
        bahan_id: null,
        bahan_nama: '',
        size_detail: '',
        subtotal: harga * qty,
        catatan,
        label: `${formatQuantity(qty)} ${satuan}`,
        is_custom: true
    });
    shouldRevealLatestCartItem = true;

    closeModal('modalCustomProduct');
    renderCart();
}

function buildCartTags(item) {
    const tags = [];

    if (item.is_custom) tags.push('Produk custom');

    if (item.kat_tipe === 'apparel') {
        if (item.size_detail) tags.push(item.size_detail);
        if (item.bahan_nama) tags.push(item.bahan_nama);
    }

    if (Array.isArray(item.finishing_list) && item.finishing_list.length) {
        item.finishing_list.forEach(name => {
            if (name) tags.push(name);
        });
    } else if (item.finishing_nama) {
        tags.push(item.finishing_nama);
    }
    if (item.catatan) tags.push(item.catatan);

    return tags;
}

function renderCart() {
    const cartItems = document.getElementById('cartItems');
    if (!cartItems) return;

    if (cart.length === 0) {
        cartItems.innerHTML = [
            '<div class="cart-empty-state">',
            '<i class="fas fa-shopping-cart"></i>',
            '<p>Keranjang Anda kosong</p>',
            '<span>Pilih produk untuk mulai transaksi.</span>',
            '</div>'
        ].join('');
        shouldRevealLatestCartItem = false;
        updateTotal();
        return;
    }

    cartItems.innerHTML = cart.map((item, index) => {
        const tags = buildCartTags(item);

        return `
            <div class="cart-item">
                <div class="cart-item-body">
                    <div class="cart-item-header">
                        <div class="cart-item-name">${posEscapeHtml(item.nama)}</div>
                        <div class="cart-item-price">${formatCurrency(item.subtotal)}</div>
                    </div>
                    <div class="cart-item-meta">${posEscapeHtml(item.label || '')}</div>
                    ${tags.length ? `<div class="cart-item-tags">${tags.map(tag => `<span class="cart-tag">${posEscapeHtml(tag)}</span>`).join('')}</div>` : ''}
                </div>
                <button type="button" class="qty-btn cart-remove-btn" onclick="removeItem(${index})" aria-label="Hapus item">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
    }).join('');

    updateTotal();

    if (shouldRevealLatestCartItem) {
        revealLatestCartItem(cartItems);
        shouldRevealLatestCartItem = false;
    }
}

function getCartMetrics() {
    const subtotal = cart.reduce((sum, item) => sum + parseFloat(item.subtotal || 0), 0);
    const diskonInput = parseFloat(document.getElementById('diskonInput')?.value || 0);
    const diskonMode = document.getElementById('diskonTipeSelect')?.value || 'nominal';
    let diskon = diskonMode === 'persen'
        ? subtotal * (Math.min(Math.max(diskonInput, 0), 100) / 100)
        : Math.max(diskonInput, 0);

    diskon = Math.min(diskon, subtotal);

    const subtotalSetelahDiskon = Math.max(0, subtotal - diskon);
    const pajakAktif = !!document.getElementById('pajakToggle')?.checked;
    const pajakPersen = parseFloat(posState.pajakPersen ?? 0);
    const pajak = pajakAktif ? subtotalSetelahDiskon * (pajakPersen / 100) : 0;
    const total = subtotalSetelahDiskon + pajak;

    return {
        subtotal,
        diskon,
        pajak,
        total,
        diskonMode,
        diskonInput,
        itemCount: cart.length
    };
}

function updateTotal() {
    const metrics = getCartMetrics();
    const appendMode = isAppendMode();
    const activeInvoice = getSelectedExistingInvoice();
    const subtotalEl = document.getElementById('subtotalVal');
    const pajakEl = document.getElementById('pajakVal');
    const totalEl = document.getElementById('totalVal');
    const diskonInfo = document.getElementById('diskonInfo');
    const diskonNominalVal = document.getElementById('diskonNominalVal');
    const cartCount = document.getElementById('cartCount');
    const cartSummaryTotal = document.getElementById('cartSummaryTotal');
    const mobileCartBar = document.getElementById('mobileCartBar');
    const mobileCartCount = document.getElementById('mobileCartCount');
    const mobileCartTotal = document.getElementById('mobileCartTotal');
    const {
        clearCartButton,
        submitIntakeButton,
        openCheckoutButton: checkoutButton,
        submitCheckoutButton
    } = getPosActionButtons();
    const appendInvoiceMissing = appendMode && !activeInvoice;
    const disableCommonActions = posRequestInFlight || metrics.itemCount === 0 || appendInvoiceMissing;
    const disableCheckoutActions = disableCommonActions || !canProcessPosPayment();

    if (subtotalEl) subtotalEl.textContent = formatCurrency(metrics.subtotal);
    if (pajakEl) pajakEl.textContent = formatCurrency(metrics.pajak);
    if (totalEl) totalEl.textContent = formatCurrency(metrics.total);

    if (diskonInfo && diskonNominalVal) {
        if (metrics.diskon > 0) {
            diskonInfo.style.display = 'block';
            diskonNominalVal.style.display = 'block';
            diskonInfo.textContent = metrics.diskonMode === 'persen'
                ? `(${formatQuantity(metrics.diskonInput)}%)`
                : '(Nominal)';
            diskonNominalVal.textContent = `- ${formatCurrency(metrics.diskon)}`;
        } else {
            diskonInfo.style.display = 'none';
            diskonNominalVal.style.display = 'none';
            diskonInfo.textContent = '';
            diskonNominalVal.textContent = '- Rp 0';
        }
    }

    if (cartCount) {
        cartCount.textContent = `${metrics.itemCount} item`;
    }
    if (cartSummaryTotal) {
        cartSummaryTotal.textContent = formatCurrency(metrics.total);
    }
    if (mobileCartCount) {
        mobileCartCount.textContent = `${metrics.itemCount} item aktif`;
    }
    if (mobileCartTotal) {
        mobileCartTotal.textContent = formatCurrency(metrics.total);
    }
    if (mobileCartBar) {
        mobileCartBar.classList.toggle('hidden', metrics.itemCount === 0);
    }
    if (clearCartButton) {
        clearCartButton.disabled = disableCommonActions;
    }
    if (submitIntakeButton) {
        submitIntakeButton.disabled = disableCommonActions || !canSubmitPosIntake();
    }
    if (checkoutButton) {
        checkoutButton.disabled = disableCheckoutActions;
    }
    if (submitCheckoutButton) {
        submitCheckoutButton.disabled = disableCheckoutActions;
    }

    updateCheckoutMethodAvailability();
    updateExistingInvoiceInfo();
    syncPosModeUi();
}

function removeItem(index) {
    cart.splice(index, 1);
    renderCart();
}

function clearCart(force = false) {
    if (posRequestInFlight && !force) {
        return;
    }

    cart = [];
    shouldRevealLatestCartItem = false;
    if (!isAppendMode()) {
        const invoiceNoteInput = document.getElementById('invoiceNoteInput');
        if (invoiceNoteInput) {
            invoiceNoteInput.value = '';
        }
    }
    syncPosModeUi();
    renderCart();
}

function focusCart() {
    const cartPanel = document.getElementById('posCart');
    if (cartPanel) {
        cartPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function getTotalAkhir() {
    return getCheckoutContext();
}

function bukaBayar() {
    if (posRequestInFlight) {
        alert('Transaksi masih diproses. Tunggu sampai selesai.');
        return;
    }

    if (!canProcessPosPayment()) {
        alert('Pembayaran POS hanya dapat diproses oleh admin atau kasir.');
        return;
    }

    if (cart.length === 0) {
        alert('Keranjang masih kosong.');
        return;
    }

    const checkoutContext = getTotalAkhir();
    if (checkoutContext.isAppend && !checkoutContext.invoice) {
        alert('Pilih invoice yang ingin ditambahkan terlebih dahulu.');
        return;
    }

    document.getElementById('bayarTotal').value = formatCurrency(checkoutContext.totalToSettle);
    document.getElementById('bayarInput').value = '';
    document.getElementById('kembalianDisplay').value = '';
    document.getElementById('dpInput').value = '';
    document.getElementById('sisaDisplay').value = '';
    document.getElementById('referensiBayarInput').value = '';
    document.getElementById('buktiPembayaranInput').value = '';
    document.getElementById('catatanBayar').value = '';

    const cashOption = document.querySelector('[name="metodeBayar"][value="cash"]');
    if (cashOption) cashOption.checked = true;
    onMetodeChange('cash');
    openModal('modalBayar');
}

function onMetodeChange(value) {
    document.querySelectorAll('.metode-btn').forEach(button => {
        button.classList.toggle('active', button.dataset.val === value);
    });

    const showCash = ['cash', 'transfer', 'qris'].includes(value);
    const paymentProofInput = document.getElementById('buktiPembayaranInput');
    const paymentProofHint = document.getElementById('buktiPembayaranHint');

    document.getElementById('groupCash').style.display = showCash ? '' : 'none';
    document.getElementById('groupDP').style.display = value === 'downpayment' ? '' : 'none';
    document.getElementById('groupTempo').style.display = value === 'tempo' ? '' : 'none';

    if (paymentProofInput) {
        paymentProofInput.disabled = value === 'tempo';
        if (value === 'tempo') {
            paymentProofInput.value = '';
        }
    }
    if (paymentProofHint) {
        paymentProofHint.textContent = value === 'tempo'
            ? 'Upload bukti dinonaktifkan karena transaksi tempo belum menerima pembayaran.'
            : 'Opsional. Format yang didukung: JPG, PNG, atau PDF. File akan tersimpan di detail transaksi sebagai bukti transfer.';
    }

    if (showCash) hitungKembalian();
    if (value === 'downpayment') hitungSisa();
    if (value === 'tempo') updateTempoTgl();
}

function hitungKembalian() {
    const { totalToSettle } = getTotalAkhir();
    const bayar = parseFloat(document.getElementById('bayarInput')?.value || 0);
    document.getElementById('kembalianDisplay').value = formatCurrency(Math.max(0, bayar - totalToSettle));
}

function hitungSisa() {
    const { totalToSettle } = getTotalAkhir();
    const dp = parseFloat(document.getElementById('dpInput')?.value || 0);
    document.getElementById('sisaDisplay').value = formatCurrency(Math.max(0, totalToSettle - dp));
}

function updateTempoTgl() {
    const days = parseInt(document.getElementById('tempoDays')?.value || 30, 10);
    const deadline = new Date();
    deadline.setDate(deadline.getDate() + days);
    document.getElementById('tempoTglDisplay').value = deadline.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'long',
        year: 'numeric'
    });
}

function prosesCheckout() {
    if (posRequestInFlight) {
        return;
    }

    if (!canProcessPosPayment()) {
        alert('Pembayaran POS hanya dapat diproses oleh admin atau kasir.');
        return;
    }

    const metode = document.querySelector('[name="metodeBayar"]:checked')?.value || 'cash';
    const checkoutContext = getTotalAkhir();
    const metrics = checkoutContext.metrics;
    if (checkoutContext.isAppend && !checkoutContext.invoice) {
        alert('Pilih invoice yang ingin ditambahkan terlebih dahulu.');
        return;
    }
    const pelangganSelect = document.getElementById('pelangganSelect');
    const paymentProofInput = document.getElementById('buktiPembayaranInput');
    let bayar = 0;
    let dpAmount = 0;
    let tempoDays = 30;

    if (['cash', 'transfer', 'qris'].includes(metode)) {
        bayar = parseFloat(document.getElementById('bayarInput')?.value || 0);
        if (bayar < checkoutContext.totalToSettle) {
            alert('Uang diterima kurang dari total tagihan.');
            return;
        }
    } else if (!checkoutContext.isAppend && metode === 'downpayment') {
        dpAmount = parseFloat(document.getElementById('dpInput')?.value || 0);
        if (dpAmount <= 0 || dpAmount > metrics.total) {
            alert('Jumlah DP tidak valid.');
            return;
        }
    } else if (!checkoutContext.isAppend && metode === 'tempo') {
        tempoDays = parseInt(document.getElementById('tempoDays')?.value || 30, 10);
        if (!pelangganSelect?.value) {
            alert('Pilih pelanggan terlebih dahulu untuk pembayaran tempo.');
            return;
        }

        if (pelangganSelect.selectedOptions[0]?.dataset.mitra !== '1') {
            alert('Pembayaran tempo hanya untuk pelanggan mitra.');
            return;
        }
    }

    setPosRequestBusy(true, 'checkout');

    const requestData = new FormData();
    requestData.append('csrf_token', getPosCsrfToken());
    requestData.append('action', 'checkout');
    requestData.append('workflow_action', 'payment');
    requestData.append('items', JSON.stringify(cart));
    requestData.append('append_to_transaction_id', checkoutContext.isAppend ? String(checkoutContext.invoice?.id || '') : '');
    requestData.append('pelanggan_id', pelangganSelect?.value || '');
    requestData.append('diskon', String(metrics.diskon));
    requestData.append('pajak', String(metrics.pajak));
    requestData.append('pajak_aktif', document.getElementById('pajakToggle')?.checked ? '1' : '0');
    requestData.append('metode_bayar', metode);
    requestData.append('bayar', String(bayar));
    requestData.append('dp_amount', String(dpAmount));
    requestData.append('tempo_days', String(tempoDays));
    requestData.append('invoice_note', document.getElementById('invoiceNoteInput')?.value || '');
    requestData.append('referensi_bayar', document.getElementById('referensiBayarInput')?.value || '');
    requestData.append('catatan', document.getElementById('catatanBayar')?.value || '');
    if (paymentProofInput?.files?.length) {
        requestData.append('bukti_pembayaran', paymentProofInput.files[0]);
    }

    $.ajax({
        url: getPosEndpoint(),
        type: 'POST',
        data: requestData,
        processData: false,
        contentType: false,
        dataType: 'json'
    }).done(function (response) {
        if (!response || !response.success) {
            alert('Gagal: ' + (response?.msg || 'Checkout tidak berhasil.'));
            return;
        }

        const customerLabel = pelangganSelect?.selectedOptions?.[0]?.textContent || 'Pelanggan Umum';
        registerExistingInvoiceSummary(response.invoice_summary, customerLabel);

        closeModal('modalBayar');
        clearCart(true);
        if (checkoutContext.isAppend) {
            document.getElementById('invoiceNoteInput').value = response?.invoice_summary?.catatan_invoice || '';
            handleExistingInvoiceChange(false);
        }
        document.getElementById('strukHeading').textContent = checkoutContext.isAppend ? 'Invoice Berhasil Diperbarui' : 'Transaksi Berhasil';
        document.getElementById('strukNoTrx').textContent = response.no_transaksi || '-';
        document.getElementById('strukTotal').textContent = checkoutContext.isAppend
            ? `Total invoice kini: ${formatCurrency(response.total || 0)}`
            : `Total: ${formatCurrency(response.total || 0)}`;

        const info = [`Metode: ${String(response.metode || '').toUpperCase()}`];
        if (checkoutContext.isAppend) {
            info.unshift('Item baru masuk ke invoice yang sama');
        }
        if (parseFloat(response.sisa_bayar || 0) > 0) {
            info.push(`Sisa: ${formatCurrency(response.sisa_bayar || 0)}`);
        }
        if (parseFloat(response.kembalian || 0) > 0) {
            info.push(`Kembalian: ${formatCurrency(response.kembalian || 0)}`);
        }
        if (response?.payment_proof?.attempted && response?.payment_proof?.success) {
            info.push('Bukti bayar terlampir');
        }

        document.getElementById('strukInfo').textContent = info.join(' | ');
        openModal('modalStruk');
        if (response?.payment_proof?.attempted && !response?.payment_proof?.success && response?.payment_proof?.message) {
            alert('Pembayaran berhasil disimpan, tetapi upload bukti pembayaran gagal: ' + response.payment_proof.message);
        }
    }).fail(function (xhr) {
        const fallback = xhr.responseText ? xhr.responseText.slice(0, 200) : 'Terjadi kesalahan jaringan.';
        alert('Request gagal: ' + fallback);
    }).always(function () {
        setPosRequestBusy(false);
    });
}

function simpanDraftPos() {
    if (posRequestInFlight) {
        return;
    }

    if (!canSubmitPosIntake()) {
        alert('Simpan draft hanya dapat diproses oleh admin, customer service, atau kasir.');
        return;
    }

    if (cart.length === 0) {
        alert('Keranjang masih kosong.');
        return;
    }

    const checkoutContext = getTotalAkhir();
    const metrics = checkoutContext.metrics;
    if (checkoutContext.isAppend && !checkoutContext.invoice) {
        alert('Pilih invoice yang ingin ditambahkan terlebih dahulu.');
        return;
    }
    const pelangganSelect = document.getElementById('pelangganSelect');

    setPosRequestBusy(true, 'draft');

    $.post(getPosEndpoint(), {
        csrf_token: getPosCsrfToken(),
        action: 'checkout',
        workflow_action: 'draft',
        items: JSON.stringify(cart),
        append_to_transaction_id: checkoutContext.isAppend ? (checkoutContext.invoice?.id || '') : '',
        pelanggan_id: pelangganSelect?.value || '',
        diskon: metrics.diskon,
        pajak: metrics.pajak,
        pajak_aktif: document.getElementById('pajakToggle')?.checked ? 1 : 0,
        invoice_note: document.getElementById('invoiceNoteInput')?.value || '',
        catatan: 'Draft invoice dari POS'
    }, function (response) {
        if (!response || !response.success) {
            alert('Gagal: ' + (response?.msg || 'Draft invoice tidak berhasil disimpan.'));
            return;
        }

        const customerLabel = pelangganSelect?.selectedOptions?.[0]?.textContent || 'Pelanggan Umum';
        registerExistingInvoiceSummary(response.invoice_summary, customerLabel);
        clearCart(true);
        if (checkoutContext.isAppend) {
            document.getElementById('invoiceNoteInput').value = response?.invoice_summary?.catatan_invoice || '';
            handleExistingInvoiceChange(false);
        }
        document.getElementById('strukHeading').textContent = checkoutContext.isAppend
            ? 'Draft Invoice Berhasil Diperbarui'
            : 'Draft Invoice Tersimpan';
        document.getElementById('strukNoTrx').textContent = response.no_transaksi || '-';
        document.getElementById('strukTotal').textContent = checkoutContext.isAppend
            ? `Total invoice kini: ${formatCurrency(response.total || 0)}`
            : `Estimasi total: ${formatCurrency(response.total || 0)}`;
        document.getElementById('strukInfo').textContent = checkoutContext.isAppend
            ? 'Item baru sudah masuk ke draft invoice yang sama. Lanjutkan edit detail atau pelunasan dari menu Transaksi.'
            : 'Draft tersimpan ke menu Transaksi. Invoice bisa diedit lagi atau dilanjutkan ke pelunasan dari sana.';
        openModal('modalStruk');
    }, 'json').fail(function (xhr) {
        const fallback = xhr.responseText ? xhr.responseText.slice(0, 200) : 'Terjadi kesalahan jaringan.';
        alert('Request gagal: ' + fallback);
    }).always(function () {
        setPosRequestBusy(false);
    });
}

function simpanPelangganBaru() {
    const messageBox = document.getElementById('msgTambahPelanggan');
    const nama = document.getElementById('newPelNama')?.value.trim() || '';
    const submitButton = document.getElementById('savePosCustomerBtn');

    if (posCustomerSaveInFlight) {
        return;
    }

    if (nama === '') {
        if (messageBox) {
            messageBox.innerHTML = '<div class="alert alert-danger" style="padding:8px 12px;font-size:.85rem">Nama wajib diisi.</div>';
        }
        return;
    }

    posCustomerSaveInFlight = true;
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.dataset.originalHtml = submitButton.dataset.originalHtml || submitButton.innerHTML;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    }

    $.ajax({
        url: getPosEndpoint(),
        type: 'POST',
        dataType: 'json',
        data: {
            csrf_token: getPosCsrfToken(),
            action: 'tambah_pelanggan',
            nama,
            telepon: document.getElementById('newPelTelepon')?.value.trim() || '',
            email: document.getElementById('newPelEmail')?.value.trim() || '',
            alamat: document.getElementById('newPelAlamat')?.value.trim() || '',
            is_mitra: document.getElementById('newPelMitra')?.checked ? 1 : 0
        }
    }).done(function (response) {
        if (!response || !response.success) {
            if (messageBox) {
                messageBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:.85rem">${posEscapeHtml(response?.msg || 'Gagal menyimpan pelanggan.')}</div>`;
            }
            return;
        }

        const savedCustomer = response.customer || {
            id: response.id,
            nama: response.nama,
            is_mitra: response.is_mitra,
            telepon: document.getElementById('newPelTelepon')?.value.trim() || '',
            email: document.getElementById('newPelEmail')?.value.trim() || '',
            alamat: document.getElementById('newPelAlamat')?.value.trim() || ''
        };
        const preferredCustomerId = String(savedCustomer?.id || response.id || '');
        const pelangganSearch = document.getElementById('pelangganSearch');
        if (pelangganSearch) {
            pelangganSearch.value = '';
        }

        if (Array.isArray(response.customers)) {
            replacePelangganCatalog(response.customers, preferredCustomerId);
        } else {
            syncPelangganCatalogFromServer(preferredCustomerId).catch(function () {
                registerPelangganOption(savedCustomer);
            });
        }

        ['newPelNama', 'newPelTelepon', 'newPelEmail', 'newPelAlamat'].forEach(id => {
            const input = document.getElementById(id);
            if (input) input.value = '';
        });
        const mitraCheckbox = document.getElementById('newPelMitra');
        if (mitraCheckbox) mitraCheckbox.checked = false;
        if (messageBox) messageBox.innerHTML = '';
        closeModal('modalTambahPelanggan');
    }).fail(function (xhr) {
        const fallback = xhr?.responseJSON?.message
            || xhr?.responseJSON?.msg
            || (xhr?.status === 403
                ? 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.'
                : 'Terjadi kesalahan jaringan.');
        if (messageBox) {
            messageBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:.85rem">${posEscapeHtml(fallback)}</div>`;
        }
    }).always(function () {
        posCustomerSaveInFlight = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = submitButton.dataset.originalHtml || '<i class="fas fa-save"></i> Simpan';
        }
    });
}

function preparePosCustomerModal() {
    const messageBox = document.getElementById('msgTambahPelanggan');
    const nameInput = document.getElementById('newPelNama');

    if (messageBox) {
        messageBox.innerHTML = '';
    }

    window.setTimeout(() => {
        nameInput?.focus();
    }, 60);
}

document.addEventListener('DOMContentLoaded', function () {
    updateFilterButtons();
    filterProduk();
    buildPelangganCatalog();
    syncPelangganCatalogFromServer(document.getElementById('pelangganSelect')?.value || null).catch(function () {
        return null;
    });
    buildInvoiceCatalog();
    toggleDimensi();
    updateTempoTgl();
    const initialAppendId = String(posState.appendTransactionId ?? '');
    if (initialAppendId && initialAppendId !== '0') {
        const modeSelect = document.getElementById('invoiceModeSelect');
        const invoiceSelect = document.getElementById('existingInvoiceSelect');
        if (modeSelect) {
            modeSelect.value = 'append';
        }
        if (invoiceSelect) {
            renderInvoiceOptions('', initialAppendId);
        }
        onPosTransactionModeChange('append');
        handleExistingInvoiceChange(true);
    } else {
        onPosTransactionModeChange(getPosTransactionMode());
    }

    const invoiceNoteInput = document.getElementById('invoiceNoteInput');
    if (invoiceNoteInput) {
        invoiceNoteInput.addEventListener('input', syncPosModeUi);
    }

    const customerForm = document.getElementById('tambahPelangganForm');
    if (customerForm) {
        customerForm.addEventListener('submit', function(event) {
            event.preventDefault();
            simpanPelangganBaru();
        });
    }

    syncPosModeUi();
    renderCart();
    syncPosCartViewport();

    if (window.ResizeObserver) {
        const topbar = document.querySelector('.topbar');
        if (topbar) {
            if (posCartTopbarObserver) {
                posCartTopbarObserver.disconnect();
            }

            posCartTopbarObserver = new ResizeObserver(() => {
                syncPosCartViewport();
            });
            posCartTopbarObserver.observe(topbar);
        }
    }
});

window.addEventListener('resize', syncPosCartViewport);
window.addEventListener('pageshow', syncPosCartViewport);

window.setTipeFilter = setTipeFilter;
window.filterProduk = filterProduk;
window.setPosTransactionMode = setPosTransactionMode;
window.pilihProduk = pilihProduk;
window.pilihProdukLainnya = pilihProdukLainnya;
window.openCustomProductModal = openCustomProductModal;
window.toggleDimensi = toggleDimensi;
window.hitungLuas = hitungLuas;
window.hitungPrintingSubtotal = hitungPrintingSubtotal;
window.addPrintingToCart = addPrintingToCart;
window.hitungApparelSubtotal = hitungApparelSubtotal;
window.addApparelToCart = addApparelToCart;
window.hitungLainnyaSubtotal = hitungLainnyaSubtotal;
window.addLainnyaToCart = addLainnyaToCart;
window.hitungCustomSubtotal = hitungCustomSubtotal;
window.addCustomToCart = addCustomToCart;
window.renderCart = renderCart;
window.filterPelangganOptions = filterPelangganOptions;
window.filterInvoiceOptions = filterInvoiceOptions;
window.onPosTransactionModeChange = onPosTransactionModeChange;
window.handleExistingInvoiceChange = handleExistingInvoiceChange;
window.removeItem = removeItem;
window.clearCart = clearCart;
window.updateTotal = updateTotal;
window.focusCart = focusCart;
window.bukaBayar = bukaBayar;
window.onMetodeChange = onMetodeChange;
window.hitungKembalian = hitungKembalian;
window.hitungSisa = hitungSisa;
window.updateTempoTgl = updateTempoTgl;
window.prosesCheckout = prosesCheckout;
window.simpanDraftPos = simpanDraftPos;
window.prosesIntakeOrder = simpanDraftPos;
window.preparePosCustomerModal = preparePosCustomerModal;
window.simpanPelangganBaru = simpanPelangganBaru;
