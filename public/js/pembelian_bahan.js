(function() {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const state = pageState.purchasingState || {};
    const materialsByDepartment = state.materialsByDepartment || { printing: [], apparel: [] };
    const suppliersByDepartment = state.suppliersByDepartment || { printing: [], apparel: [] };
    const activeDepartment = state.activeDepartment || 'printing';
    const formatMoney = window.jwsFormatCurrency;
    const formatQty = function(value) {
        return window.jwsFormatQuantity(value, { maximumFractionDigits: 3 });
    };
    const escapeHtml = window.jwsEscapeHtml;

    function getMaterials() {
        return Array.isArray(materialsByDepartment?.[activeDepartment]) ? materialsByDepartment[activeDepartment] : [];
    }

    function getSuppliers() {
        return Array.isArray(suppliersByDepartment?.[activeDepartment]) ? suppliersByDepartment[activeDepartment] : [];
    }

    function populateMaterialSelect(select, selectedId = 0) {
        if (!select) {
            return;
        }

        const options = ['<option value="">Pilih bahan</option>'];
        getMaterials().forEach(item => {
            const itemId = Number(item?.id || 0);
            const label = `${item?.kode ? item.kode + ' - ' : ''}${item?.nama || ''} (${formatQty(item?.stok || 0)} ${item?.satuan || ''})`;
            options.push(
                `<option value="${itemId}" data-name="${escapeHtml(item?.nama || '')}" data-satuan="${escapeHtml(item?.satuan || '')}" data-price="${Number(item?.harga_beli || 0)}"${itemId === Number(selectedId || 0) ? ' selected' : ''}>${escapeHtml(label)}</option>`
            );
        });

        select.innerHTML = options.join('');
    }

    function populateSupplierSelect(selectedId = 0) {
        const select = document.getElementById('purchaseSupplierSelect');
        if (!select) {
            return;
        }

        const options = ['<option value="">Supplier umum / manual</option>'];
        getSuppliers().forEach(item => {
            const itemId = Number(item?.id || 0);
            const label = `${item?.nama || ''}${item?.telepon ? ' - ' + item.telepon : ''}`;
            options.push(`<option value="${itemId}" data-name="${escapeHtml(item?.nama || '')}"${itemId === Number(selectedId || 0) ? ' selected' : ''}>${escapeHtml(label)}</option>`);
        });

        select.innerHTML = options.join('');
    }

    function syncSelectedSupplier() {
        const select = document.getElementById('purchaseSupplierSelect');
        const supplierInput = document.getElementById('purchaseSupplier');
        const option = select?.selectedOptions?.[0];

        if (!supplierInput) {
            return;
        }

        if (Number(select?.value || 0) > 0) {
            supplierInput.value = option?.dataset?.name || '';
        } else if (supplierInput.dataset.lockedBySelect === 'true') {
            supplierInput.value = '';
        }
        supplierInput.dataset.lockedBySelect = Number(select?.value || 0) > 0 ? 'true' : 'false';
    }

    function togglePurchasePaymentFields() {
        const methodSelect = document.getElementById('purchasePaymentMethod');
        const initialPaymentInput = document.getElementById('purchaseInitialPayment');
        const dueDateGroup = document.getElementById('purchaseDueDateGroup');
        const dueDateInput = document.getElementById('purchaseDueDate');
        const grandTotal = Number((document.getElementById('purchaseGrandTotalPreview')?.value || '0').replace(/[^\d.-]/g, '')) || 0;
        const isTempo = (methodSelect?.value || 'tunai') === 'tempo';

        if (dueDateGroup) {
            dueDateGroup.style.display = isTempo ? '' : 'none';
        }
        if (dueDateInput) {
            dueDateInput.required = isTempo;
            if (!isTempo) {
                dueDateInput.value = '';
            }
        }
        if (initialPaymentInput && !isTempo) {
            initialPaymentInput.value = grandTotal > 0 ? String(grandTotal) : '0';
        }
    }

    function purchaseRowTemplate() {
        return `
            <td><select class="form-control purchase-item-select"></select></td>
            <td><input type="number" min="0.001" step="0.001" class="form-control purchase-item-qty" placeholder="0"></td>
            <td><input type="text" class="form-control purchase-item-satuan" readonly style="background:var(--bg)"></td>
            <td><input type="number" min="0" step="0.01" class="form-control purchase-item-price" placeholder="0"></td>
            <td><input type="text" class="form-control purchase-item-subtotal" readonly style="background:var(--bg)"></td>
            <td><button type="button" class="btn btn-danger btn-sm purchase-item-remove"><i class="fas fa-trash"></i></button></td>
        `;
    }

    function buildPurchasePayload(rowEl) {
        const select = rowEl.querySelector('.purchase-item-select');
        const qtyInput = rowEl.querySelector('.purchase-item-qty');
        const satuanInput = rowEl.querySelector('.purchase-item-satuan');
        const priceInput = rowEl.querySelector('.purchase-item-price');
        const subtotalInput = rowEl.querySelector('.purchase-item-subtotal');

        const stokBahanId = Number(select?.value || 0);
        const option = select?.selectedOptions?.[0];
        const qty = Number(qtyInput?.value || 0);
        const hargaBeli = Number(priceInput?.value || 0);
        const subtotal = Math.max(0, qty) * Math.max(0, hargaBeli);

        if (subtotalInput) {
            subtotalInput.value = formatMoney(subtotal);
        }

        return {
            stok_bahan_id: stokBahanId,
            qty: qty > 0 ? qty : 0,
            harga_beli: hargaBeli > 0 ? hargaBeli : 0,
            isValid: stokBahanId > 0 && qty > 0
        };
    }

    function syncPurchaseItems() {
        const tbody = document.getElementById('purchaseItemRows');
        const rows = Array.from(tbody?.querySelectorAll('.purchase-item-row') || []);
        const payload = [];

        rows.forEach(rowEl => {
            const row = buildPurchasePayload(rowEl);
            if (row.isValid) {
                payload.push(row);
            }
        });

        const subtotal = payload.reduce((sum, item) => sum + (Number(item.qty || 0) * Number(item.harga_beli || 0)), 0);
        const ongkir = Number(document.getElementById('purchaseOngkir')?.value || 0);
        const diskon = Number(document.getElementById('purchaseDiskon')?.value || 0);
        const grandTotal = Math.max(0, subtotal + ongkir - diskon);
        const hiddenInput = document.getElementById('purchaseItemsJson');
        const countEl = document.getElementById('purchaseItemCount');
        const subtotalEl = document.getElementById('purchaseSubtotal');
        const subtotalPreview = document.getElementById('purchaseSubtotalPreview');
        const grandTotalPreview = document.getElementById('purchaseGrandTotalPreview');
        const emptyEl = document.getElementById('purchaseItemEmpty');
        const initialPaymentInput = document.getElementById('purchaseInitialPayment');

        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(payload);
        }
        if (countEl) {
            countEl.textContent = `${payload.length} item`;
        }
        if (subtotalEl) {
            subtotalEl.textContent = formatMoney(subtotal);
        }
        if (subtotalPreview) {
            subtotalPreview.value = formatMoney(subtotal);
        }
        if (grandTotalPreview) {
            grandTotalPreview.value = formatMoney(grandTotal);
        }
        if (emptyEl) {
            emptyEl.style.display = rows.length > 0 ? 'none' : '';
        }
        if (initialPaymentInput && (document.getElementById('purchasePaymentMethod')?.value || 'tunai') !== 'tempo') {
            initialPaymentInput.value = grandTotal > 0 ? String(grandTotal) : '0';
        }

        togglePurchasePaymentFields();
    }

    function updateRowDefaults(rowEl, forcePrice = false) {
        const select = rowEl.querySelector('.purchase-item-select');
        const satuanInput = rowEl.querySelector('.purchase-item-satuan');
        const priceInput = rowEl.querySelector('.purchase-item-price');
        const option = select?.selectedOptions?.[0];
        const defaultPrice = Number(option?.dataset?.price || 0);

        if (satuanInput) {
            satuanInput.value = option?.dataset?.satuan || '';
        }
        if (forcePrice || Number(priceInput?.value || 0) === 0) {
            priceInput.value = defaultPrice || '';
        }

        syncPurchaseItems();
    }

    function attachPurchaseRowHandlers(rowEl) {
        rowEl.querySelector('.purchase-item-select')?.addEventListener('change', function() {
            updateRowDefaults(rowEl, true);
        });
        rowEl.querySelector('.purchase-item-qty')?.addEventListener('input', syncPurchaseItems);
        rowEl.querySelector('.purchase-item-price')?.addEventListener('input', syncPurchaseItems);
        rowEl.querySelector('.purchase-item-remove')?.addEventListener('click', function() {
            rowEl.remove();
            syncPurchaseItems();
        });
    }

    function addPurchaseRow(rowData = {}) {
        const tbody = document.getElementById('purchaseItemRows');
        if (!tbody) {
            return;
        }

        const rowEl = document.createElement('tr');
        rowEl.className = 'purchase-item-row';
        rowEl.innerHTML = purchaseRowTemplate();
        const select = rowEl.querySelector('.purchase-item-select');
        const qtyInput = rowEl.querySelector('.purchase-item-qty');
        const satuanInput = rowEl.querySelector('.purchase-item-satuan');
        const priceInput = rowEl.querySelector('.purchase-item-price');

        populateMaterialSelect(select, rowData.stok_bahan_id || 0);
        qtyInput.value = Number(rowData.qty || 0) > 0 ? Number(rowData.qty || 0) : '';
        satuanInput.value = rowData.satuan || '';
        priceInput.value = Number(rowData.harga_beli || 0) > 0 ? Number(rowData.harga_beli || 0) : '';

        tbody.appendChild(rowEl);
        attachPurchaseRowHandlers(rowEl);
        updateRowDefaults(rowEl, Number(rowData.harga_beli || 0) <= 0);
    }

    function resetPurchaseForm() {
        document.getElementById('purchaseForm')?.reset();
        const purchaseDate = document.getElementById('purchaseDate');
        if (purchaseDate) {
            purchaseDate.valueAsDate = new Date();
        }
        document.getElementById('purchaseItemRows').innerHTML = '';
        document.getElementById('purchaseItemsJson').value = '[]';
        populateSupplierSelect();
        syncSelectedSupplier();
        addPurchaseRow();
        syncPurchaseItems();
    }

    function populateAdjustmentSelect() {
        const select = document.getElementById('adjustMaterialSelect');
        if (!select) {
            return;
        }

        const options = ['<option value="">Pilih bahan</option>'];
        getMaterials().forEach(item => {
            const label = `${item?.kode ? item.kode + ' - ' : ''}${item?.nama || ''} (${formatQty(item?.stok || 0)} ${item?.satuan || ''})`;
            options.push(`<option value="${Number(item?.id || 0)}">${escapeHtml(label)}</option>`);
        });
        select.innerHTML = options.join('');
    }

    function renderPurchaseDetail(data) {
        document.getElementById('detailPurchaseNumber').value = data?.no_pembelian || '';
        document.getElementById('detailPurchaseDate').value = data?.tanggal || '';
        document.getElementById('detailPurchaseSupplier').value = data?.supplier_nama || 'Supplier umum';
        document.getElementById('detailPurchaseNota').value = data?.referensi_nota || '-';
        document.getElementById('detailPurchaseMethod').value = data?.metode_pembayaran || '-';
        document.getElementById('detailPurchaseDueDate').value = data?.jatuh_tempo || '-';
        document.getElementById('detailPurchaseStatus').value = data?.status_pembayaran || '-';
        document.getElementById('detailPurchaseSubtotal').value = formatMoney(data?.subtotal || 0);
        document.getElementById('detailPurchaseOngkir').value = formatMoney(data?.ongkir || 0);
        document.getElementById('detailPurchaseDiskon').value = formatMoney(data?.diskon || 0);
        document.getElementById('detailPurchaseGrandTotal').value = formatMoney(data?.grand_total || 0);
        document.getElementById('detailPurchasePaidSummary').value = `${formatMoney(data?.dibayar_total || 0)} / ${formatMoney(data?.sisa_tagihan || 0)}`;
        document.getElementById('detailPurchaseCatatan').value = data?.catatan || '-';

        const tbody = document.getElementById('detailPurchaseItems');
        const items = Array.isArray(data?.items) ? data.items : [];
        tbody.innerHTML = items.length > 0
            ? items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item?.nama_bahan || '-')}</strong><div class="text-muted small">${escapeHtml(item?.satuan || '')}</div></td>
                    <td>${formatQty(item?.qty || 0)}</td>
                    <td>${formatMoney(item?.harga_beli || 0)}</td>
                    <td>${formatMoney(item?.subtotal || 0)}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" class="text-center text-muted" style="padding:18px">Belum ada item pembelian.</td></tr>';

        const paymentBody = document.getElementById('detailPurchasePayments');
        const payments = Array.isArray(data?.payments) ? data.payments : [];
        paymentBody.innerHTML = payments.length > 0
            ? payments.map(payment => `
                <tr>
                    <td>${escapeHtml(payment?.tanggal || '-')}</td>
                    <td>${formatMoney(payment?.nominal || 0)}</td>
                    <td>${escapeHtml(payment?.metode || '-')}</td>
                    <td>${escapeHtml(payment?.referensi || '-')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4" class="text-center text-muted" style="padding:18px">Belum ada pembayaran supplier.</td></tr>';
    }

    function openSupplierForm(data) {
        document.getElementById('supplierForm')?.reset();
        document.getElementById('supplierId').value = data?.id || 0;
        document.getElementById('supplierAction').value = data?.id ? 'update_supplier' : 'save_supplier';
        document.getElementById('supplierModalTitle').textContent = data?.id ? 'Edit Supplier Bahan' : 'Tambah Supplier Bahan';
        document.getElementById('supplierName').value = data?.nama || '';
        document.getElementById('supplierStatus').value = data?.status || 'aktif';
        document.getElementById('supplierPhone').value = data?.telepon || '';
        document.getElementById('supplierEmail').value = data?.email || '';
        document.getElementById('supplierAddress').value = data?.alamat || '';
        document.getElementById('supplierNote').value = data?.catatan || '';
        openModal('modalSupplier');
    }

    function openPaymentForm(data) {
        const paymentDate = document.getElementById('paymentDate');
        if (paymentDate) {
            paymentDate.valueAsDate = new Date();
        }
        document.getElementById('purchasePaymentForm')?.reset();
        if (paymentDate) {
            paymentDate.valueAsDate = new Date();
        }
        document.getElementById('paymentPurchaseId').value = data?.id || 0;
        document.getElementById('paymentPurchaseInfo').value = `${data?.no_pembelian || '-'} / ${data?.supplier || 'Supplier umum'}`;
        document.getElementById('paymentGrandTotal').textContent = formatMoney(data?.grand_total || 0);
        document.getElementById('paymentRemaining').textContent = formatMoney(data?.sisa_tagihan || 0);
        document.getElementById('paymentAmount').value = data?.sisa_tagihan || 0;
        openModal('modalPurchasePayment');
    }

    window.addPurchaseItemRow = function(rowData) {
        addPurchaseRow(rowData || {});
    };

    window.syncPurchaseItems = syncPurchaseItems;
    window.syncSelectedSupplier = syncSelectedSupplier;
    window.togglePurchasePaymentFields = togglePurchasePaymentFields;

    window.openPurchaseModal = function() {
        resetPurchaseForm();
        openModal('modalPembelianBahan');
    };

    window.openAdjustmentModal = function() {
        populateAdjustmentSelect();
        openModal('modalAdjustmentStock');
    };

    window.openPurchaseDetail = function(data) {
        renderPurchaseDetail(data || {});
        openModal('modalPurchaseDetail');
    };

    window.openSupplierModal = function(data) {
        openSupplierForm(data || {});
    };

    window.openPaymentModal = function(data) {
        openPaymentForm(data || {});
    };

    window.filterPurchasingView = function() {
        const keyword = String(document.getElementById('srchPurchasing')?.value || '').trim().toLowerCase();
        document.querySelectorAll('#tblPurchasing tbody tr.purchase-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
        document.querySelectorAll('#mobilePurchasingList .purchase-mobile-card').forEach(card => {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };

    window.filterMutationView = function() {
        const keyword = String(document.getElementById('srchMutation')?.value || '').trim().toLowerCase();
        document.querySelectorAll('#tblMutation tbody tr.mutation-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
        document.querySelectorAll('#mobileMutationList .mutation-mobile-card').forEach(card => {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('purchaseForm')?.addEventListener('submit', syncPurchaseItems);
        document.getElementById('purchasePaymentForm')?.addEventListener('submit', function(event) {
            const remainingText = document.getElementById('paymentRemaining')?.textContent || 'Rp 0';
            const remaining = Number(remainingText.replace(/[^\d.-]/g, '')) || 0;
            const amount = Number(document.getElementById('paymentAmount')?.value || 0);
            if (amount > remaining) {
                event.preventDefault();
                alert('Nominal pembayaran melebihi sisa tagihan supplier.');
            }
        });
        populateAdjustmentSelect();
        populateSupplierSelect();
        togglePurchasePaymentFields();
        filterPurchasingView();
        filterMutationView();
    });
})();
