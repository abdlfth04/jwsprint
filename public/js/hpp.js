(function() {
    const state = {
        current: null
    };

    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const hppState = pageState.hppState || {};
    const materialCatalog = hppState.materialsByDepartment || {
        printing: [],
        apparel: []
    };
    const formatMoney = function(value, signed = false) {
        return window.jwsFormatCurrency(value, { signed: signed });
    };
    const escapeHtml = window.jwsEscapeHtml;

    function parseNumber(id) {
        return Number(document.getElementById(id)?.value || 0);
    }

    function filterTableRows(keyword) {
        document.querySelectorAll('#tblHpp tbody tr.hpp-row').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    }

    function filterMobileCards(keyword) {
        document.querySelectorAll('.hpp-mobile-card').forEach(card => {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    }

    function updatePreviewFields(totalHpp, laba) {
        const totalEl = document.getElementById('hppTotalPreview');
        const profitEl = document.getElementById('hppProfitPreview');
        if (totalEl) {
            totalEl.textContent = formatMoney(totalHpp);
        }
        if (profitEl) {
            profitEl.value = formatMoney(laba, true);
            profitEl.style.color = laba >= 0 ? 'var(--success)' : 'var(--danger)';
            profitEl.style.fontWeight = '700';
        }
    }

    function getDepartmentMaterials(departemen) {
        return Array.isArray(materialCatalog?.[departemen]) ? materialCatalog[departemen] : [];
    }

    function buildMaterialOptionLabel(item) {
        const stokText = Number(item?.stok || 0).toLocaleString('id-ID', {
            maximumFractionDigits: 3
        });
        const satuan = String(item?.satuan || '').trim();
        const baseLabel = String(item?.label || item?.nama || '');
        return satuan ? `${baseLabel} (${stokText} ${satuan})` : baseLabel;
    }

    function populateMaterialSelect(select, departemen, selectedId, rowData = {}) {
        if (!select) {
            return;
        }

        const materials = getDepartmentMaterials(departemen);
        const selectedValue = Number(selectedId || 0);
        const options = ['<option value="">Pilih bahan departemen</option>'];
        let hasSelected = false;

        materials.forEach(item => {
            const itemId = Number(item?.id || 0);
            const isSelected = itemId === selectedValue;
            if (isSelected) {
                hasSelected = true;
            }

            options.push(
                `<option value="${itemId}" data-name="${escapeHtml(item?.nama || '')}" data-satuan="${escapeHtml(item?.satuan || '')}" data-price="${Number(item?.harga_beli || 0)}"${isSelected ? ' selected' : ''}>${escapeHtml(buildMaterialOptionLabel(item))}</option>`
            );
        });

        if (!hasSelected && selectedValue > 0 && rowData?.nama_bahan) {
            options.splice(1, 0,
                `<option value="${selectedValue}" data-name="${escapeHtml(rowData.nama_bahan)}" data-satuan="${escapeHtml(rowData.satuan || '')}" data-price="${Number(rowData.unit_cost || 0)}" selected>${escapeHtml(rowData.nama_bahan)} (arsip)</option>`
            );
        }

        select.innerHTML = options.join('');
    }

    function materialRowTemplate() {
        return `
            <td>
                <select class="form-control hpp-material-select"></select>
            </td>
            <td>
                <input type="number" step="0.001" min="0" class="form-control hpp-material-qty" placeholder="0">
            </td>
            <td>
                <input type="text" class="form-control hpp-material-satuan" readonly style="background:var(--bg)">
            </td>
            <td>
                <input type="number" step="0.01" min="0" class="form-control hpp-material-unit-cost" placeholder="0">
            </td>
            <td>
                <input type="text" class="form-control hpp-material-total" readonly style="background:var(--bg)">
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm hpp-material-remove"><i class="fas fa-trash"></i></button>
            </td>
        `;
    }

    function applyMaterialDefaults(rowEl, forcePrice = false) {
        const select = rowEl.querySelector('.hpp-material-select');
        const satuanInput = rowEl.querySelector('.hpp-material-satuan');
        const unitCostInput = rowEl.querySelector('.hpp-material-unit-cost');
        const option = select?.selectedOptions?.[0];
        const defaultPrice = Number(option?.dataset?.price || 0);
        const currentPrice = Number(unitCostInput?.value || 0);

        satuanInput.value = option?.dataset?.satuan || '';
        if (forcePrice || currentPrice === 0 || currentPrice === Number(rowEl.dataset.defaultPrice || 0)) {
            unitCostInput.value = defaultPrice || '';
        }

        rowEl.dataset.defaultPrice = String(defaultPrice || 0);
        rowEl.dataset.materialName = option?.dataset?.name || '';
    }

    function buildMaterialPayload(rowEl) {
        const select = rowEl.querySelector('.hpp-material-select');
        const qtyInput = rowEl.querySelector('.hpp-material-qty');
        const satuanInput = rowEl.querySelector('.hpp-material-satuan');
        const unitCostInput = rowEl.querySelector('.hpp-material-unit-cost');
        const totalInput = rowEl.querySelector('.hpp-material-total');

        const stokBahanId = Number(select?.value || 0);
        const option = select?.selectedOptions?.[0];
        const namaBahan = option?.dataset?.name || rowEl.dataset.materialName || '';
        const qty = Number(qtyInput?.value || 0);
        const unitCost = Number(unitCostInput?.value || 0);
        const totalCost = Math.max(0, qty) * Math.max(0, unitCost);

        if (totalInput) {
            totalInput.value = formatMoney(totalCost);
        }

        return {
            stok_bahan_id: stokBahanId,
            nama_bahan: namaBahan,
            satuan: satuanInput?.value || '',
            qty: qty > 0 ? qty : 0,
            unit_cost: unitCost > 0 ? unitCost : 0,
            total_cost: totalCost,
            isValid: namaBahan !== '' && qty > 0
        };
    }

    function syncMaterialSection() {
        const tbody = document.getElementById('hppMaterialRows');
        const rows = Array.from(tbody?.querySelectorAll('.hpp-material-row') || []);
        const validRows = [];

        rows.forEach(rowEl => {
            const payload = buildMaterialPayload(rowEl);
            if (payload.isValid) {
                validRows.push({
                    stok_bahan_id: payload.stok_bahan_id || null,
                    nama_bahan: payload.nama_bahan,
                    satuan: payload.satuan,
                    qty: payload.qty,
                    unit_cost: payload.unit_cost,
                    total_cost: payload.total_cost
                });
            }
        });

        const total = validRows.reduce((sum, row) => sum + Number(row.total_cost || 0), 0);
        const bahanInput = document.getElementById('hppBahanCost');
        const hiddenInput = document.getElementById('hppMaterialUsageJson');
        const countEl = document.getElementById('hppMaterialCount');
        const totalEl = document.getElementById('hppMaterialTotal');
        const emptyEl = document.getElementById('hppMaterialEmpty');
        const modeNote = document.getElementById('hppBahanModeNote');

        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(validRows);
        }
        if (countEl) {
            countEl.textContent = `${validRows.length} bahan`;
        }
        if (totalEl) {
            totalEl.textContent = formatMoney(total);
        }
        if (emptyEl) {
            emptyEl.style.display = rows.length > 0 ? 'none' : '';
        }

        if (bahanInput) {
            if (validRows.length > 0) {
                bahanInput.dataset.manualFallback = bahanInput.dataset.manualFallback || bahanInput.value || String(state.current?.bahan_baku_cost || 0);
                bahanInput.value = total.toFixed(2);
                bahanInput.readOnly = true;
                bahanInput.style.background = 'var(--bg)';
                if (modeNote) {
                    modeNote.textContent = 'Biaya bahan otomatis mengikuti total rincian bahan aktual di bawah ini.';
                }
            } else {
                const fallback = bahanInput.dataset.manualFallback ?? state.current?.bahan_baku_cost ?? 0;
                bahanInput.readOnly = false;
                bahanInput.style.background = '';
                bahanInput.value = fallback;
                if (modeNote) {
                    modeNote.textContent = 'Kosongkan rincian bahan jika ingin isi biaya bahan secara manual.';
                }
            }
        }

        updateHppPreview();
    }

    function attachMaterialRowHandlers(rowEl) {
        const select = rowEl.querySelector('.hpp-material-select');
        const qtyInput = rowEl.querySelector('.hpp-material-qty');
        const unitCostInput = rowEl.querySelector('.hpp-material-unit-cost');
        const removeButton = rowEl.querySelector('.hpp-material-remove');

        select?.addEventListener('change', function() {
            applyMaterialDefaults(rowEl, true);
            syncMaterialSection();
        });
        qtyInput?.addEventListener('input', syncMaterialSection);
        unitCostInput?.addEventListener('input', syncMaterialSection);
        removeButton?.addEventListener('click', function() {
            rowEl.remove();
            syncMaterialSection();
        });
    }

    function createMaterialRow(rowData = {}) {
        const tbody = document.getElementById('hppMaterialRows');
        if (!tbody) {
            return;
        }

        const departemen = String(state.current?.departemen || 'printing');
        const rowEl = document.createElement('tr');
        rowEl.className = 'hpp-material-row';
        rowEl.innerHTML = materialRowTemplate();

        const select = rowEl.querySelector('.hpp-material-select');
        const qtyInput = rowEl.querySelector('.hpp-material-qty');
        const satuanInput = rowEl.querySelector('.hpp-material-satuan');
        const unitCostInput = rowEl.querySelector('.hpp-material-unit-cost');

        populateMaterialSelect(select, departemen, rowData.stok_bahan_id, rowData);
        qtyInput.value = Number(rowData.qty || 0) > 0 ? Number(rowData.qty || 0) : '';
        satuanInput.value = rowData.satuan || '';
        unitCostInput.value = Number(rowData.unit_cost || 0) > 0 ? Number(rowData.unit_cost || 0) : '';

        tbody.appendChild(rowEl);
        attachMaterialRowHandlers(rowEl);
        applyMaterialDefaults(rowEl, Number(rowData.unit_cost || 0) <= 0);
        if (Number(rowData.unit_cost || 0) > 0) {
            rowEl.dataset.defaultPrice = String(Number(rowData.unit_cost || 0));
        }
        syncMaterialSection();
    }

    function renderMaterialRows(rows) {
        const tbody = document.getElementById('hppMaterialRows');
        if (!tbody) {
            return;
        }

        tbody.innerHTML = '';
        const list = Array.isArray(rows) ? rows : [];
        list.forEach(row => createMaterialRow(row));
        syncMaterialSection();
    }

    function fillModal(data) {
        state.current = data || null;

        document.getElementById('hppDetailId').value = data.detail_id || '';
        document.getElementById('hppDepartemen').value = data.departemen === 'apparel' ? 'Apparel' : 'Printing';
        document.getElementById('hppInvoiceJob').value = `${data.invoice || '-'} / ${data.job || '-'}`;
        document.getElementById('hppItemName').value = `${data.item || ''} (${Number(data.qty || 0).toLocaleString('id-ID')} ${data.satuan || ''})`;
        document.getElementById('hppOmzetPreview').textContent = formatMoney(data.omzet || 0);
        document.getElementById('hppSuggestedBahan').textContent = formatMoney(data.suggested_bahan || 0);
        document.getElementById('hppSuggestedFinishing').textContent = formatMoney(data.suggested_finishing || 0);

        document.getElementById('hppBahanCost').value = Number(data.bahan_baku_cost || 0);
        document.getElementById('hppBahanCost').dataset.manualFallback = String(Number(data.bahan_baku_cost || 0));
        document.getElementById('hppFinishingCost').value = Number(data.finishing_cost || 0);
        document.getElementById('hppTenagaKerjaCost').value = Number(data.tenaga_kerja_cost || 0);
        document.getElementById('hppOverheadCost').value = Number(data.overhead_cost || 0);
        document.getElementById('hppSubkonCost').value = Number(data.subkon_cost || 0);
        document.getElementById('hppPengirimanCost').value = Number(data.pengiriman_cost || 0);
        document.getElementById('hppLainLainCost').value = Number(data.lain_lain_cost || 0);
        document.getElementById('hppCatatan').value = data.catatan || '';
        document.getElementById('hppMaterialUsageJson').value = '[]';

        renderMaterialRows(data.material_rows || []);
        updateHppPreview();
        openModal('modalHppCosting');
    }

    window.filterHppView = function() {
        const keyword = String(document.getElementById('srchHpp')?.value || '').trim().toLowerCase();
        filterTableRows(keyword);
        filterMobileCards(keyword);
    };

    window.openHppModal = function(data) {
        fillModal(data || {});
    };

    window.updateHppPreview = function() {
        const omzet = Number(state.current?.omzet || 0);
        const totalHpp = [
            'hppBahanCost',
            'hppFinishingCost',
            'hppTenagaKerjaCost',
            'hppOverheadCost',
            'hppSubkonCost',
            'hppPengirimanCost',
            'hppLainLainCost'
        ].reduce((sum, id) => sum + parseNumber(id), 0);

        updatePreviewFields(totalHpp, omzet - totalHpp);
    };

    window.addHppMaterialRow = function(rowData) {
        createMaterialRow(rowData || {});
    };

    window.resetHppCosting = function(form, itemName) {
        return confirm(`Reset biaya aktual untuk ${itemName || 'item ini'} ke estimasi otomatis?`);
    };

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('hppCostingForm');
        const bahanInput = document.getElementById('hppBahanCost');
        form?.addEventListener('submit', syncMaterialSection);
        bahanInput?.addEventListener('input', function() {
            if (!bahanInput.readOnly) {
                bahanInput.dataset.manualFallback = bahanInput.value;
            }
        });
        filterHppView();
    });
})();
