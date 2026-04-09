document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const historyEndpoint = pageState.pelangganHistoryEndpoint || 'pelanggan.php?ajax=riwayat_transaksi';
    const customerDetails = pageState.pelangganDetails || {};
    const escapeHtml = typeof window.jwsEscapeHtml === 'function'
        ? window.jwsEscapeHtml
        : function (value) {
            return String(value || '').replace(/[&<>"']/g, function (character) {
                switch (character) {
                    case '&':
                        return '&amp;';
                    case '<':
                        return '&lt;';
                    case '>':
                        return '&gt;';
                    case '"':
                        return '&quot;';
                    case '\'':
                        return '&#039;';
                    default:
                        return character;
                }
            });
        };
    const normalizeSearchText = typeof window.jwsNormalizeSearchText === 'function'
        ? window.jwsNormalizeSearchText
        : function (value) {
            return String(value || '').trim().toLowerCase();
        };
    const formatMoney = typeof window.jwsFormatCurrency === 'function'
        ? window.jwsFormatCurrency
        : function (value) {
            return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
        };
    const formatDateTime = typeof window.jwsFormatDateTime === 'function'
        ? window.jwsFormatDateTime
        : function (value) {
            return value ? String(value) : '-';
        };
    let currentCustomerDetailId = 0;

    function getCustomerDetail(customerOrId) {
        if (customerOrId && typeof customerOrId === 'object' && !Array.isArray(customerOrId)) {
            return customerOrId;
        }

        const key = String(parseInt(customerOrId || '0', 10));
        return customerDetails[key] || null;
    }

    function buildHistoryUrl(customerId) {
        const url = new URL(historyEndpoint, window.location.href);
        url.searchParams.set('id', String(parseInt(customerId || '0', 10)));
        return url.toString();
    }

    function getCustomerSearchState() {
        return {
            input: document.querySelector('[data-customer-search-input]') || document.getElementById('customerSearchInput') || document.getElementById('srch'),
            clearButtons: Array.from(document.querySelectorAll('[data-customer-search-clear]')),
            rows: Array.from(document.querySelectorAll('#tblPelanggan tbody tr')),
            cards: Array.from(document.querySelectorAll('#mobilePelangganList .mobile-data-card')),
            summary: document.getElementById('customerSearchSummary'),
            emptyState: document.getElementById('customerSearchEmpty')
        };
    }

    function setCustomerSearchSummary(summaryNode, visibleCount, totalCount, keyword) {
        if (!summaryNode) {
            return;
        }

        const visibleLabel = Number(visibleCount || 0).toLocaleString('id-ID');
        const totalLabel = Number(totalCount || 0).toLocaleString('id-ID');
        const normalizedKeyword = String(keyword || '').trim();

        if (normalizedKeyword !== '') {
            summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> dari ' + totalLabel
                + ' pelanggan cocok untuk &quot;' + escapeHtml(normalizedKeyword) + '&quot;';
            return;
        }

        summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> pelanggan pada halaman ini';
    }

    function toggleCustomerSearchClearButtons(clearButtons, shouldShow) {
        clearButtons.forEach(function (button) {
            button.hidden = !shouldShow;
        });
    }

    function renderHistorySummary(summary) {
        return `
            <div class="metric-strip" style="margin-bottom:16px">
                <div class="metric-card">
                    <span class="metric-label">Total Transaksi</span>
                    <span class="metric-value">${Number(summary.total_transaksi || 0).toLocaleString('id-ID')}</span>
                    <span class="metric-note">Jumlah invoice yang pernah dibuat untuk pelanggan ini.</span>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Nilai Transaksi</span>
                    <span class="metric-value">${escapeHtml(formatMoney(summary.nilai_transaksi || 0))}</span>
                    <span class="metric-note">Akumulasi nilai transaksi non-batal.</span>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Sisa Tagihan</span>
                    <span class="metric-value">${escapeHtml(formatMoney(summary.outstanding_total || 0))}</span>
                    <span class="metric-note">Total outstanding invoice yang belum lunas.</span>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Proses Aktif</span>
                    <span class="metric-value">${Number(summary.active_processes || 0).toLocaleString('id-ID')}</span>
                    <span class="metric-note">Invoice yang masih butuh tindak lanjut workflow atau pembayaran.</span>
                </div>
            </div>
        `;
    }

    function renderHistoryRows(transactions) {
        if (!Array.isArray(transactions) || transactions.length === 0) {
            return `
                <div class="empty-state" style="margin:0">
                    <i class="fas fa-inbox"></i>
                    <div>Belum ada transaksi untuk pelanggan ini.</div>
                </div>
            `;
        }

        const tableRows = transactions.map((trx, index) => `
            <tr>
                <td>${index + 1}</td>
                <td><strong>${escapeHtml(trx.no_transaksi || '-')}</strong></td>
                <td>${escapeHtml(formatDateTime(trx.created_at))}</td>
                <td>${escapeHtml(trx.workflow_label || '-')}</td>
                <td>${escapeHtml(trx.status_label || '-')}</td>
                <td class="rp">${Number(trx.total || 0).toLocaleString('id-ID')}</td>
                <td class="rp">${Number(trx.remaining_amount || 0).toLocaleString('id-ID')}</td>
                <td>${escapeHtml(trx.catatan_invoice || '-')}</td>
                <td>
                    <div class="btn-group">
                        <a href="${escapeHtml(trx.invoice_url || '#')}" target="_blank" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print"></i>
                        </a>
                        <a href="${escapeHtml(trx.append_url || '#')}" class="btn btn-primary btn-sm">
                            <i class="fas fa-layer-group"></i>
                        </a>
                    </div>
                </td>
            </tr>
        `).join('');

        const cards = transactions.map((trx) => `
            <div class="mobile-data-card" style="margin-bottom:12px">
                <div class="mobile-data-top">
                    <div>
                        <div class="mobile-data-title">${escapeHtml(trx.no_transaksi || '-')}</div>
                        <div class="mobile-data-subtitle">${escapeHtml(formatDateTime(trx.created_at))}</div>
                    </div>
                    <span class="badge badge-secondary">${escapeHtml(trx.status_label || '-')}</span>
                </div>
                <div class="mobile-data-grid">
                    <div class="mobile-data-field">
                        <span class="mobile-data-label">Tahap</span>
                        <span class="mobile-data-value">${escapeHtml(trx.workflow_label || '-')}</span>
                    </div>
                    <div class="mobile-data-field">
                        <span class="mobile-data-label">Total</span>
                        <span class="mobile-data-value">${escapeHtml(formatMoney(trx.total || 0))}</span>
                    </div>
                    <div class="mobile-data-field">
                        <span class="mobile-data-label">Sisa</span>
                        <span class="mobile-data-value">${escapeHtml(formatMoney(trx.remaining_amount || 0))}</span>
                    </div>
                    <div class="mobile-data-field" style="grid-column:1 / -1">
                        <span class="mobile-data-label">Catatan Invoice</span>
                        <span class="mobile-data-value">${escapeHtml(trx.catatan_invoice || '-')}</span>
                    </div>
                </div>
                <div class="mobile-data-actions">
                    <a href="${escapeHtml(trx.invoice_url || '#')}" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Invoice</a>
                    <a href="${escapeHtml(trx.append_url || '#')}" class="btn btn-primary btn-sm"><i class="fas fa-layer-group"></i> Tambah Item</a>
                </div>
            </div>
        `).join('');

        return `
            <div class="table-responsive table-desktop">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>No. Transaksi</th>
                            <th>Tanggal</th>
                            <th>Tahap</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Sisa</th>
                            <th>Catatan Invoice</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>${tableRows}</tbody>
                </table>
            </div>
            <div class="mobile-data-list">${cards}</div>
        `;
    }

    function renderHistoryBody(payload) {
        return renderHistorySummary(payload.summary || {}) + renderHistoryRows(payload.transactions || []);
    }

    function renderCustomerDetailBody(customer) {
        if (!customer) {
            return '<div class="alert alert-danger">Detail pelanggan tidak ditemukan.</div>';
        }

        const isMitra = Number(customer.is_mitra || 0) === 1;
        const contactMeta = [];
        contactMeta.push(`<span><i class="fas fa-phone"></i> ${escapeHtml(customer.telepon || '-')}</span>`);
        contactMeta.push(`<span><i class="fas fa-envelope"></i> ${escapeHtml(customer.email || '-')}</span>`);
        contactMeta.push(`<span><i class="fas fa-clock"></i> ${escapeHtml(customer.last_transaction_at ? formatDateTime(customer.last_transaction_at) : 'Belum ada transaksi')}</span>`);

        return `
            <div class="customer-detail-shell">
                <div class="customer-detail-highlight">
                    <div class="customer-detail-top">
                        <div>
                            <div class="mobile-data-title">${escapeHtml(customer.nama || '-')}</div>
                            <div class="customer-detail-subtitle">
                                ${isMitra
                                    ? 'Pelanggan mitra. Cocok untuk alur tempo dan follow-up invoice yang membutuhkan kontak aktif.'
                                    : 'Pelanggan reguler untuk transaksi umum. Detail lengkap dan aksi utama tersedia dari popup ini.'}
                            </div>
                        </div>
                        <span class="badge ${isMitra ? 'badge-mitra' : 'badge-reguler'}">${isMitra ? 'Mitra' : 'Reguler'}</span>
                    </div>
                    <div class="customer-inline-meta">${contactMeta.join('')}</div>
                </div>

                <div class="metric-strip">
                    <div class="metric-card">
                        <span class="metric-label">Total Transaksi</span>
                        <span class="metric-value">${Number(customer.transaksi_total || 0).toLocaleString('id-ID')}</span>
                        <span class="metric-note">Jumlah invoice yang pernah dibuat untuk pelanggan ini.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Nilai Transaksi</span>
                        <span class="metric-value">${escapeHtml(formatMoney(customer.nilai_transaksi || 0))}</span>
                        <span class="metric-note">Akumulasi transaksi non-batal pelanggan ini.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Sisa Tagihan</span>
                        <span class="metric-value">${escapeHtml(formatMoney(customer.outstanding_total || 0))}</span>
                        <span class="metric-note">Nominal invoice yang masih perlu ditindaklanjuti.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Kontak Siap Pakai</span>
                        <span class="metric-value">${customer.telepon || customer.email ? 'Ya' : 'Belum'}</span>
                        <span class="metric-note">Telepon atau email akan memudahkan follow-up pesanan dan pembayaran.</span>
                    </div>
                </div>

                <div class="customer-detail-grid">
                    <div class="customer-detail-card">
                        <div class="customer-detail-label"><i class="fas fa-phone"></i> Telepon</div>
                        <div class="customer-detail-value">${escapeHtml(customer.telepon || '-')}</div>
                    </div>
                    <div class="customer-detail-card">
                        <div class="customer-detail-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="customer-detail-value">${escapeHtml(customer.email || '-')}</div>
                    </div>
                    <div class="customer-detail-card">
                        <div class="customer-detail-label"><i class="fas fa-receipt"></i> Invoice Tercatat</div>
                        <div class="customer-detail-value">${Number(customer.transaksi_total || 0).toLocaleString('id-ID')} transaksi</div>
                    </div>
                    <div class="customer-detail-card">
                        <div class="customer-detail-label"><i class="fas fa-hourglass-half"></i> Aktivitas Terakhir</div>
                        <div class="customer-detail-value">${escapeHtml(customer.last_transaction_at ? formatDateTime(customer.last_transaction_at) : 'Belum ada transaksi')}</div>
                    </div>
                    <div class="customer-detail-card full">
                        <div class="customer-detail-label"><i class="fas fa-location-dot"></i> Alamat</div>
                        <div class="customer-detail-value">${escapeHtml(customer.alamat || '-')}</div>
                    </div>
                </div>

                <div class="customer-detail-note">
                    Riwayat transaksi, edit data, dan hapus pelanggan bisa diproses langsung dari popup ini supaya tim tidak perlu mengejar tombol aksi di sisi kanan tabel.
                </div>
            </div>
        `;
    }

    function syncCustomerDetailActions(customer) {
        const historyButton = document.getElementById('detailPelangganHistoryBtn');
        const editButton = document.getElementById('detailPelangganEditBtn');
        const deleteIdInput = document.getElementById('detailPelangganDeleteId');
        const deleteForm = document.getElementById('detailPelangganDeleteForm');

        const hasCustomer = !!customer;
        if (historyButton) {
            historyButton.disabled = !hasCustomer;
        }
        if (editButton) {
            editButton.disabled = !hasCustomer;
        }
        if (deleteIdInput) {
            deleteIdInput.value = hasCustomer ? String(customer.id || 0) : '0';
        }
        if (deleteForm) {
            deleteForm.dataset.customerName = hasCustomer ? String(customer.nama || '') : '';
        }
    }

    window.editPelanggan = function (d) {
        const customer = getCustomerDetail(d) || d;
        if (!customer) {
            return;
        }

        document.getElementById('editId').value = customer.id;
        document.getElementById('editNama').value = customer.nama || '';
        document.getElementById('editTelepon').value = customer.telepon || '';
        document.getElementById('editEmail').value = customer.email || '';
        document.getElementById('editAlamat').value = customer.alamat || '';
        document.getElementById('editMitra').checked = Number(customer.is_mitra || 0) === 1;
        openModal('modalEdit');
    };

    window.openCustomerDetailModal = function (customerId) {
        const customer = getCustomerDetail(customerId);
        const body = document.getElementById('detailPelangganBody');
        const title = document.getElementById('modalDetailPelangganTitle');

        if (!customer || !body || !title) {
            return;
        }

        currentCustomerDetailId = Number(customer.id || 0);
        title.textContent = 'Detail Pelanggan - ' + String(customer.nama || 'Pelanggan');
        body.innerHTML = renderCustomerDetailBody(customer);
        syncCustomerDetailActions(customer);
        openModal('modalDetailPelanggan');
    };

    window.filterPelangganView = function (keywordOverride) {
        const state = getCustomerSearchState();
        const rawKeyword = typeof keywordOverride === 'string'
            ? keywordOverride
            : (state.input ? state.input.value : '');
        const keyword = normalizeSearchText(rawKeyword);
        const hasKeyword = keyword !== '';
        const totalCount = state.rows.length > 0 ? state.rows.length : state.cards.length;
        let visibleRowCount = 0;

        state.rows.forEach(function (row) {
            const haystack = normalizeSearchText(row.dataset.search || row.textContent || '');
            const match = !hasKeyword || haystack.includes(keyword);
            row.hidden = !match;
            if (match) {
                visibleRowCount += 1;
            }
        });

        let visibleCardCount = 0;
        state.cards.forEach(function (card) {
            const haystack = normalizeSearchText(card.dataset.search || card.textContent || '');
            const match = !hasKeyword || haystack.includes(keyword);
            card.hidden = !match;
            if (match) {
                visibleCardCount += 1;
            }
        });

        const visibleCount = state.rows.length > 0 ? visibleRowCount : visibleCardCount;
        setCustomerSearchSummary(state.summary, visibleCount, totalCount, rawKeyword);
        toggleCustomerSearchClearButtons(state.clearButtons, hasKeyword);

        if (state.emptyState) {
            state.emptyState.hidden = visibleCount > 0;
        }

        return visibleCount;
    };

    function initialiseCustomerSearch() {
        const state = getCustomerSearchState();
        if (!state.input) {
            return;
        }

        state.input.addEventListener('input', function () {
            window.filterPelangganView(state.input.value);
        });

        state.input.addEventListener('search', function () {
            window.filterPelangganView(state.input.value);
        });

        state.clearButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (state.input) {
                    state.input.value = '';
                    state.input.focus();
                }
                window.filterPelangganView('');
            });
        });

        window.filterPelangganView(state.input.value);
    }

    window.openCustomerTransactionsModal = async function (customerId, customerName) {
        const body = document.getElementById('riwayatPelangganBody');
        const title = document.getElementById('riwayatPelangganTitle');
        const customer = getCustomerDetail(customerId) || {
            id: parseInt(customerId || '0', 10),
            nama: String(customerName || 'Pelanggan')
        };

        if (!body || !title || !customer.id) {
            return;
        }

        currentCustomerDetailId = Number(customer.id || 0);
        title.textContent = 'Riwayat Transaksi - ' + String(customer.nama || customerName || 'Pelanggan');
        body.innerHTML = '<div class="text-center text-muted" style="padding:32px 0"><i class="fas fa-spinner fa-spin"></i><br>Memuat riwayat transaksi...</div>';
        openModal('modalRiwayatPelanggan');

        try {
            const response = await fetch(buildHistoryUrl(customer.id), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const raw = await response.text();
            let payload = {};

            try {
                payload = JSON.parse(raw);
            } catch (error) {
                throw new Error('Response riwayat transaksi tidak valid.');
            }

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Riwayat transaksi tidak dapat dimuat.');
            }

            body.innerHTML = renderHistoryBody(payload);
        } catch (error) {
            body.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message || 'Terjadi kesalahan saat memuat riwayat transaksi.')}</div>`;
        }
    };

    initialiseCustomerSearch();

    const historyButton = document.getElementById('detailPelangganHistoryBtn');
    if (historyButton) {
        historyButton.addEventListener('click', function () {
            const customer = getCustomerDetail(currentCustomerDetailId);
            if (!customer) {
                return;
            }

            closeModal('modalDetailPelanggan');
            window.openCustomerTransactionsModal(customer.id, customer.nama);
        });
    }

    const editButton = document.getElementById('detailPelangganEditBtn');
    if (editButton) {
        editButton.addEventListener('click', function () {
            const customer = getCustomerDetail(currentCustomerDetailId);
            if (!customer) {
                return;
            }

            closeModal('modalDetailPelanggan');
            window.editPelanggan(customer);
        });
    }

    const deleteForm = document.getElementById('detailPelangganDeleteForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const customerName = String(deleteForm.dataset.customerName || '').trim();
            confirmDelete(deleteForm, customerName !== '' ? `Yakin ingin menghapus pelanggan ${customerName}?` : 'Yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan.');
        });
    }
});
