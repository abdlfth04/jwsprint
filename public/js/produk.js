document.addEventListener('DOMContentLoaded', function () {
    function escapeHtml(value) {
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
    }

    function getInventorySearchType(type) {
        if (type === 'produk' || type === 'bahan') {
            return type;
        }

        const input = document.querySelector('[data-inventory-search-input]');
        return input ? String(input.dataset.inventoryType || 'produk') : 'produk';
    }

    function getInventorySearchState(type) {
        const currentType = getInventorySearchType(type);
        const isProduk = currentType === 'produk';

        return {
            type: currentType,
            input: document.querySelector('[data-inventory-search-input]') || document.getElementById(isProduk ? 'srchProd' : 'srchBahan'),
            clearButtons: Array.from(document.querySelectorAll('[data-inventory-search-clear]')),
            tableRows: Array.from(document.querySelectorAll('#' + (isProduk ? 'tblProd' : 'tblBahan') + ' tbody tr')),
            mobileCards: Array.from(document.querySelectorAll('#' + (isProduk ? 'mobileProdList' : 'mobileBahanList') + ' .mobile-data-card')),
            summary: document.getElementById('inventorySearchSummary'),
            emptyState: document.getElementById('inventorySearchEmpty')
        };
    }

    function setInventorySearchSummary(summaryNode, visibleCount, totalCount, keyword, itemLabel) {
        if (!summaryNode) {
            return;
        }

        const visibleLabel = Number(visibleCount || 0).toLocaleString('id-ID');
        const totalLabel = Number(totalCount || 0).toLocaleString('id-ID');
        const normalizedKeyword = String(keyword || '').trim();
        const label = String(itemLabel || 'data');

        if (normalizedKeyword !== '') {
            summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> dari ' + totalLabel
                + ' ' + label + ' cocok untuk &quot;' + escapeHtml(normalizedKeyword) + '&quot;';
            return;
        }

        summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> ' + label + ' pada tab ini';
    }

    function toggleInventorySearchClearButtons(clearButtons, shouldShow) {
        clearButtons.forEach(function (button) {
            button.hidden = !shouldShow;
        });
    }

    window.addGrosirTier = function (modalType, qty = '', harga = '') {
        const prefix = modalType === 'edit' ? 'Edit' : 'Tambah';
        const container = document.getElementById('grosirTiersContainer' + prefix);
        if (!container) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'form-row align-center gap-2 mb-2';
        wrapper.innerHTML =
            '<div class="form-group" style="flex:1;margin:0">' +
                '<label class="form-label small d-block">Min. Qty</label>' +
                '<input type="number" name="grosir_qty[]" class="form-control" value="' + qty + '" placeholder="Contoh: 50">' +
            '</div>' +
            '<div class="form-group" style="flex:1.5;margin:0">' +
                '<label class="form-label small d-block">Harga per Satuan</label>' +
                '<input type="number" name="grosir_harga[]" class="form-control" value="' + harga + '" placeholder="Contoh: 8000">' +
            '</div>' +
            '<button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()" style="margin-top:18px"><i class="fas fa-trash"></i></button>';

        container.appendChild(wrapper);
    };

    window.editProduk = function (data, satuanList) {
        document.getElementById('eId').value = data.id;
        document.getElementById('eKode').value = data.kode;
        document.getElementById('eNama').value = data.nama;
        document.getElementById('eKat').value = data.kategori_id;
        document.getElementById('eHb').value = data.harga_beli;
        document.getElementById('eHj').value = data.harga_jual;
        document.getElementById('eStok').value = data.stok;
        document.getElementById('eDesk').value = data.deskripsi;

        const satuanSelect = document.getElementById('eSatuan');
        if (satuanSelect) {
            satuanSelect.innerHTML = (satuanList || []).map(function (item) {
                return '<option value="' + item + '"' + (item === data.satuan ? ' selected' : '') + '>' + item + '</option>';
            }).join('');
        }

        const container = document.getElementById('grosirTiersContainerEdit');
        if (container) {
            container.innerHTML = '';
            if (Array.isArray(data.grosir_tiers)) {
                data.grosir_tiers.forEach(function (tier) {
                    window.addGrosirTier('edit', tier.min_qty, parseFloat(tier.harga));
                });
            }
        }

        openModal('modalEdit');
    };

    window.editBahan = function (data) {
        document.getElementById('ebId').value = data.id;
        document.getElementById('ebKode').value = data.kode;
        document.getElementById('ebNama').value = data.nama;
        document.getElementById('ebSatuan').value = data.satuan;
        document.getElementById('ebHb').value = data.harga_beli;
        document.getElementById('ebStok').value = data.stok;
        document.getElementById('ebMin').value = data.stok_minimum;
        document.getElementById('ebKet').value = data.keterangan;
        openModal('modalEditBahan');
    };

    window.filterInventoryView = function (type, keywordOverride) {
        const state = getInventorySearchState(type);
        const rawKeyword = typeof keywordOverride === 'string'
            ? keywordOverride
            : (state.input ? state.input.value : '');
        const keyword = String(rawKeyword || '').trim().toLowerCase();
        const hasKeyword = keyword !== '';
        const itemLabel = state.input ? String(state.input.dataset.inventoryLabel || (state.type === 'produk' ? 'produk' : 'bahan')) : 'data';
        const totalCount = state.tableRows.length > 0 ? state.tableRows.length : state.mobileCards.length;

        let visibleRowCount = 0;
        state.tableRows.forEach(function (row) {
            const haystack = String(row.dataset.search || row.textContent || '').toLowerCase();
            const match = !hasKeyword || haystack.includes(keyword);
            row.hidden = !match;
            if (match) {
                visibleRowCount += 1;
            }
        });

        let visibleCardCount = 0;
        state.mobileCards.forEach(function (card) {
            const haystack = String(card.dataset.search || card.textContent || '').toLowerCase();
            const match = !hasKeyword || haystack.includes(keyword);
            card.hidden = !match;
            if (match) {
                visibleCardCount += 1;
            }
        });

        const visibleCount = state.tableRows.length > 0 ? visibleRowCount : visibleCardCount;
        setInventorySearchSummary(state.summary, visibleCount, totalCount, rawKeyword, itemLabel);
        toggleInventorySearchClearButtons(state.clearButtons, hasKeyword);

        if (state.emptyState) {
            state.emptyState.hidden = visibleCount > 0;
        }

        return visibleCount;
    };

    function initialiseInventorySearch() {
        const state = getInventorySearchState();
        if (!state.input) {
            return;
        }

        state.input.addEventListener('input', function () {
            window.filterInventoryView(state.type, state.input.value);
        });

        state.input.addEventListener('search', function () {
            window.filterInventoryView(state.type, state.input.value);
        });

        state.clearButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (state.input) {
                    state.input.value = '';
                    state.input.focus();
                }
                window.filterInventoryView(state.type, '');
            });
        });

        window.filterInventoryView(state.type, state.input.value);
    }

    initialiseInventorySearch();
});
