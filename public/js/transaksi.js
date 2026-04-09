(() => {
    const detailEndpoint = window.jwsPageUrl ? window.jwsPageUrl('transaksi_detail.php') : 'transaksi_detail.php';
    const fileUploadEndpoint = window.jwsPageUrl ? window.jwsPageUrl('file_upload.php') : 'file_upload.php';
    const fileDeleteEndpoint = window.jwsPageUrl ? window.jwsPageUrl('file_delete.php') : 'file_delete.php';
    const fileAssignEndpoint = window.jwsPageUrl ? window.jwsPageUrl('file_assign.php') : 'file_assign.php';

    function formatTransactionMoney(value) {
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(character) {
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

    function getTransactionSearchState() {
        return {
            inputs: Array.from(document.querySelectorAll('[data-transaction-search-input]')),
            clearButtons: Array.from(document.querySelectorAll('[data-transaction-search-clear]')),
            tableRows: Array.from(document.querySelectorAll('#tblTrx tbody tr')),
            mobileCards: Array.from(document.querySelectorAll('.mobile-transaction-card')),
            summary: document.getElementById('transactionSearchSummary'),
            emptyState: document.getElementById('transactionSearchEmpty')
        };
    }

    function setTransactionSearchSummary(summaryNode, visibleCount, totalCount, keyword) {
        if (!summaryNode) {
            return;
        }

        const visibleLabel = Number(visibleCount || 0).toLocaleString('id-ID');
        const totalLabel = Number(totalCount || 0).toLocaleString('id-ID');
        const normalizedKeyword = String(keyword || '').trim();

        if (normalizedKeyword !== '') {
            summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> dari ' + totalLabel
                + ' transaksi cocok untuk &quot;' + escapeHtml(normalizedKeyword) + '&quot;';
            return;
        }

        summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> transaksi pada halaman ini';
    }

    function syncTransactionSearchInputs(inputs, source) {
        if (!source) {
            return;
        }

        inputs.forEach(function(input) {
            if (input !== source) {
                input.value = source.value;
            }
        });
    }

    function toggleTransactionSearchClearButtons(clearButtons, shouldShow) {
        clearButtons.forEach(function(button) {
            button.hidden = !shouldShow;
        });
    }

    window.filterTransaksiView = function(keywordOverride) {
        const state = getTransactionSearchState();
        const currentInput = state.inputs[0] || null;
        const rawKeyword = typeof keywordOverride === 'string'
            ? keywordOverride
            : (currentInput ? currentInput.value : '');
        const keyword = String(rawKeyword || '').trim().toLowerCase();
        const hasKeyword = keyword !== '';
        const totalCount = state.tableRows.length > 0 ? state.tableRows.length : state.mobileCards.length;

        let visibleTableCount = 0;
        state.tableRows.forEach(function(row) {
            const match = !hasKeyword || row.textContent.toLowerCase().includes(keyword);
            row.hidden = !match;
            if (match) {
                visibleTableCount += 1;
            }
        });

        let visibleMobileCount = 0;
        state.mobileCards.forEach(function(card) {
            const match = !hasKeyword || card.textContent.toLowerCase().includes(keyword);
            card.hidden = !match;
            if (match) {
                visibleMobileCount += 1;
            }
        });

        const visibleCount = state.tableRows.length > 0 ? visibleTableCount : visibleMobileCount;
        setTransactionSearchSummary(state.summary, visibleCount, totalCount, rawKeyword);
        toggleTransactionSearchClearButtons(state.clearButtons, hasKeyword);

        if (state.emptyState) {
            state.emptyState.hidden = visibleCount > 0;
        }

        return visibleCount;
    };

    function initialiseTransactionSearch() {
        const state = getTransactionSearchState();
        if (!state.inputs.length) {
            return;
        }

        state.inputs.forEach(function(input) {
            input.addEventListener('input', function() {
                syncTransactionSearchInputs(state.inputs, input);
                window.filterTransaksiView(input.value);
            });

            input.addEventListener('search', function() {
                syncTransactionSearchInputs(state.inputs, input);
                window.filterTransaksiView(input.value);
            });
        });

        state.clearButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                state.inputs.forEach(function(input) {
                    input.value = '';
                });
                window.filterTransaksiView('');
                if (state.inputs[0]) {
                    state.inputs[0].focus();
                }
            });
        });

        window.filterTransaksiView(state.inputs[0].value);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiseTransactionSearch);
    } else {
        initialiseTransactionSearch();
    }

    window.lihatDetail = function(id) {
        openModal('modalDetail');
        $('#modalDetailTitle').text('Detail Transaksi');
        $('#detailContent').html('<div class="text-center text-muted" style="padding:40px"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Memuat...</div>');

        $.get(detailEndpoint + '?id=' + id, function(data) {
            $('#detailContent').html(data);
            const loadedTitle = $('#detailContent').find('[data-detail-title]').first().data('detailTitle');
            if (loadedTitle) {
                $('#modalDetailTitle').text(loadedTitle);
            }
            if (typeof window.syncTransactionFileDetailOptions === 'function') {
                window.syncTransactionFileDetailOptions();
            }
        });
    };

    function openPaymentModalFromButton(button) {
        if (!button) {
            return;
        }

        const transactionId = Number(button.dataset.id || 0);
        const transactionNo = button.dataset.no || '';
        const customerName = button.dataset.pelanggan || 'Umum';
        const total = Number(button.dataset.total || 0);
        const paid = Number(button.dataset.paid || 0);
        const remaining = Number(button.dataset.remaining || 0);
        const status = (button.dataset.status || '').toLowerCase();
        const paymentDate = document.getElementById('paymentDate');
        const paymentAmount = document.getElementById('paymentAmount');

        const infoInput = document.getElementById('paymentTransactionInfo');
        const totalLabel = document.getElementById('paymentGrandTotal');
        const paidLabel = document.getElementById('paymentPaidTotal');
        const remainingLabel = document.getElementById('paymentRemaining');

        if (!infoInput || !totalLabel || !paidLabel || !remainingLabel) {
            return;
        }

        document.getElementById('paymentTransaksiId').value = transactionId;
        infoInput.value = transactionNo + ' | ' + customerName;
        totalLabel.textContent = formatTransactionMoney(total);
        paidLabel.textContent = formatTransactionMoney(paid);
        remainingLabel.textContent = formatTransactionMoney(remaining);
        document.getElementById('paymentReference').value = '';
        document.getElementById('paymentMethod').value = 'cash';
        document.getElementById('paymentProofInput').value = '';
        document.getElementById('paymentNote').value = status === 'draft'
            ? 'Pelunasan draft invoice'
            : (status === 'dp'
                ? 'Pelunasan down payment invoice'
                : (status === 'tempo' ? 'Pembayaran pelunasan transaksi tempo' : 'Pembayaran lanjutan transaksi'));

        if (paymentDate) {
            paymentDate.valueAsDate = new Date();
        }
        if (paymentAmount) {
            paymentAmount.value = remaining > 0 ? remaining.toFixed(2) : '';
            paymentAmount.max = remaining > 0 ? remaining.toFixed(2) : '';
        }

        openModal('modalPayment');
    }

    window.transactionFileNeedsItemSelection = function(type) {
        return !['bukti_transfer'].includes(String(type || '').toLowerCase());
    };

    window.syncTransactionFileDetailOptions = function() {
        const tipeFile = document.getElementById('tipeFileUpload');
        const detailSelect = document.getElementById('detailTransaksiUpload');
        const hint = document.getElementById('uploadItemHint');

        if (!tipeFile || !detailSelect) {
            return;
        }

        const type = tipeFile.value;
        const needsItemSelection = window.transactionFileNeedsItemSelection(type);
        const category = ['cetak', 'siap_cetak'].includes(type)
            ? 'printing'
            : (['mockup', 'list_nama'].includes(type) ? 'apparel' : '');

        const eligibleOptions = Array.from(detailSelect.options).filter(option => {
            if (!option.value) {
                return false;
            }

            const match = !needsItemSelection || !category || option.dataset.category === category;
            option.hidden = !match;
            option.disabled = !match;
            return match;
        });

        detailSelect.disabled = !needsItemSelection;
        if (!needsItemSelection) {
            detailSelect.value = '';
        }

        const selectedOption = detailSelect.options[detailSelect.selectedIndex] || null;
        if (selectedOption && selectedOption.disabled) {
            detailSelect.value = '';
        }

        if (!detailSelect.value && eligibleOptions.length === 1) {
            detailSelect.value = eligibleOptions[0].value;
        }

        if (!hint) {
            return;
        }

        if (!needsItemSelection) {
            hint.textContent = 'Bukti transfer melekat ke transaksi dan tidak perlu dipetakan ke item produk tertentu.';
        } else if (!category) {
            hint.textContent = 'Pilih item produk yang sesuai supaya lampiran masuk ke JO/SPK yang tepat.';
        } else if (!eligibleOptions.length) {
            hint.textContent = 'Belum ada item produk yang cocok untuk tipe file ini.';
        } else if (eligibleOptions.length === 1) {
            hint.textContent = 'Item produk terpilih otomatis karena hanya ada satu item yang cocok.';
        } else if (!detailSelect.value) {
            hint.textContent = 'Pilih item produk terlebih dahulu agar file tidak tercampur ke JO/SPK lain.';
        } else {
            hint.textContent = 'File akan ditautkan ke item produk yang dipilih.';
        }
    };

    window.uploadFiles = async function(transaksiId) {
        const fileInput = document.getElementById('fileInput');
        const tipeFile = document.getElementById('tipeFileUpload') ? document.getElementById('tipeFileUpload').value : 'lainnya';
        const detailSelect = document.getElementById('detailTransaksiUpload');
        const statusDiv = document.getElementById('uploadStatus');

        if (!fileInput || !fileInput.files.length) {
            if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--danger)">Pilih file terlebih dahulu.</span>';
            return;
        }

        const eligibleOptions = detailSelect
            ? Array.from(detailSelect.options).filter(option => option.value && !option.disabled)
            : [];
        const detailId = detailSelect ? detailSelect.value : '';
        const needsItemSelection = window.transactionFileNeedsItemSelection(tipeFile);

        if (needsItemSelection && eligibleOptions.length > 1 && !detailId) {
            if (statusDiv) statusDiv.innerHTML = '<span style="color:var(--danger)">Pilih item produk terlebih dahulu.</span>';
            return;
        }

        let successCount = 0;
        let failCount = 0;
        let lastError = '';
        if (statusDiv) {
            statusDiv.innerHTML = '<span style="color:var(--info)"><i class="fas fa-spinner fa-spin"></i> Mengunggah ' + fileInput.files.length + ' file...</span>';
        }

        for (let i = 0; i < fileInput.files.length; i += 1) {
            const fd = new FormData();
            fd.append('transaksi_id', transaksiId);
            fd.append('tipe_file', tipeFile);
            if (needsItemSelection && detailId) {
                fd.append('detail_transaksi_id', detailId);
            }
            fd.append('file', fileInput.files[i]);

            try {
                const res = await $.ajax({
                    url: fileUploadEndpoint,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                });

                if (res.success) {
                    successCount += 1;
                } else {
                    failCount += 1;
                    lastError = res.message;
                }
            } catch (e) {
                failCount += 1;
                lastError = (window.jwsExtractUploadErrorMessage || function(xhr, fallbackMessage) {
                    return fallbackMessage || 'Terjadi kesalahan server atau jaringan saat upload.';
                })(e, 'Terjadi kesalahan server atau jaringan saat upload.', {
                    errorJoiner: ' ',
                    tooLargeMessage: 'Ukuran file melebihi batas upload server/PHP. Coba upload file yang lebih kecil atau naikkan batas upload di cPanel.'
                });
            }
        }

        if (successCount > 0) {
            if (statusDiv) {
                statusDiv.innerHTML = '<span style="color:var(--success)"><i class="fas fa-check"></i> '
                    + successCount + ' file berhasil diunggah' + (failCount > 0 ? ' (' + failCount + ' gagal)' : '') + '.</span>';
            }
            fileInput.value = '';
            setTimeout(() => window.lihatDetail(transaksiId), 1000);
        } else if (statusDiv) {
            statusDiv.innerHTML = '<span style="color:var(--danger)">Gagal: ' + lastError + '</span>';
        }
    };

    window.hapusFile = function(fileId) {
        if (!confirm('Apakah Anda yakin ingin menghapus lampiran ini?')) return;
        $.post(fileDeleteEndpoint, { id: fileId }, function(res) {
            if (res.success) {
                $('#fileRow' + fileId).fadeOut(300, function() { $(this).remove(); });
            } else {
                alert(res.message || 'Gagal menghapus file');
            }
        }, 'json').fail(function() {
            alert('Terjadi kesalahan jaringan.');
        });
    };

    window.ubahItemFile = function(fileId, detailId) {
        $.post(fileAssignEndpoint, { id: fileId, detail_transaksi_id: detailId }, function(res) {
            if (res.success) {
                window.lihatDetail(res.transaksi_id);
            } else {
                alert(res.message || 'Gagal memperbarui item file.');
            }
        }, 'json').fail(function() {
            alert('Terjadi kesalahan jaringan.');
        });
    };

    window.openPaymentModalFromDetailButton = function(button) {
        closeModal('modalDetail');
        openPaymentModalFromButton(button);
    };
})();
