document.addEventListener('DOMContentLoaded', function() {
    let currentProduksiDetailId = 0;

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

    function getProduksiSearchState() {
        return {
            input: document.querySelector('[data-produksi-search-input]') || document.getElementById('produksiSearchInput') || document.getElementById('srch'),
            clearButtons: Array.from(document.querySelectorAll('[data-produksi-search-clear]')),
            rows: Array.from(document.querySelectorAll('#tblProd tbody tr.produksi-row')),
            cards: Array.from(document.querySelectorAll('.mobile-produksi-card')),
            summary: document.getElementById('produksiSearchSummary'),
            emptyState: document.getElementById('produksiSearchEmpty')
        };
    }

    function setProduksiSearchSummary(summaryNode, visibleCount, totalCount, keyword) {
        if (!summaryNode) {
            return;
        }

        const visibleLabel = Number(visibleCount || 0).toLocaleString('id-ID');
        const totalLabel = Number(totalCount || 0).toLocaleString('id-ID');
        const normalizedKeyword = String(keyword || '').trim();

        if (normalizedKeyword !== '') {
            summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> dari ' + totalLabel
                + ' job cocok untuk &quot;' + escapeHtml(normalizedKeyword) + '&quot;';
            return;
        }

        summaryNode.innerHTML = '<strong>' + visibleLabel + '</strong> job pada halaman ini';
    }

    function toggleProduksiSearchClearButtons(clearButtons, shouldShow) {
        clearButtons.forEach(function(button) {
            button.hidden = !shouldShow;
        });
    }

    window.filterProduksiView = function(keywordOverride) {
        const state = getProduksiSearchState();
        const rawKeyword = typeof keywordOverride === 'string'
            ? keywordOverride
            : (state.input ? state.input.value : '');
        const keyword = String(rawKeyword || '').trim().toLowerCase();
        const hasKeyword = keyword !== '';
        const totalCount = state.rows.length > 0 ? state.rows.length : state.cards.length;

        let visibleRowCount = 0;
        state.rows.forEach(function(row) {
            const haystack = String(row.dataset.search || row.textContent || '').toLowerCase();
            const match = !hasKeyword || haystack.includes(keyword);
            row.hidden = !match;
            if (match) {
                visibleRowCount += 1;
            }
        });

        let visibleCardCount = 0;
        state.cards.forEach(function(card) {
            const haystack = String(card.dataset.search || card.textContent || '').toLowerCase();
            const match = !hasKeyword || haystack.includes(keyword);
            card.hidden = !match;
            if (match) {
                visibleCardCount += 1;
            }
        });

        const visibleCount = state.rows.length > 0 ? visibleRowCount : visibleCardCount;
        setProduksiSearchSummary(state.summary, visibleCount, totalCount, rawKeyword);
        toggleProduksiSearchClearButtons(state.clearButtons, hasKeyword);

        if (state.emptyState) {
            state.emptyState.hidden = visibleCount > 0;
        }

        return visibleCount;
    };

    function initialiseProduksiSearch() {
        const state = getProduksiSearchState();
        if (!state.input) {
            return;
        }

        state.input.addEventListener('input', function() {
            window.filterProduksiView(state.input.value);
        });

        state.input.addEventListener('search', function() {
            window.filterProduksiView(state.input.value);
        });

        state.clearButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                if (state.input) {
                    state.input.value = '';
                    state.input.focus();
                }
                window.filterProduksiView('');
            });
        });

        window.filterProduksiView(state.input.value);
    }

    function showTahapanToast(message, type) {
        if (typeof window.showAlert === 'function') {
            window.showAlert(message, type);
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'alert alert-' + type;
        toast.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;min-width:220px';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 3000);
    }

    function updateProduksiRowAsDone(produksiId) {
        var mainRow = document.querySelector('tr[data-produksi-id="' + produksiId + '"]');
        if (mainRow) {
            var progressCell = mainRow.cells[8];
            if (progressCell) {
                var progressBar = progressCell.querySelector('.progress-mini-bar');
                var progressText = progressCell.querySelector('small');
                if (progressBar) {
                    progressBar.style.width = '100%';
                }
                if (progressText) {
                    var counts = progressText.textContent.match(/(\d+)\/(\d+)/);
                    if (counts) {
                        progressText.textContent = counts[2] + '/' + counts[2] + ' (100%)';
                    }
                }
            }

            var statusCell = mainRow.cells[9];
            if (statusCell) {
                statusCell.innerHTML = '<span class="badge badge-success">selesai</span>';
            }
        }

        var mobileCard = document.querySelector('.mobile-produksi-card[data-produksi-id="' + produksiId + '"]');
        if (mobileCard) {
            var mobileStatus = mobileCard.querySelector('.mobile-data-top .badge');
            if (mobileStatus) {
                mobileStatus.className = 'badge badge-success';
                mobileStatus.textContent = 'selesai';
            }

            var mobileProgress = mobileCard.querySelector('.mobile-data-field .mobile-data-value');
            var progressBar = mobileCard.querySelector('.progress-mini-bar');
            var progressLabel = Array.from(mobileCard.querySelectorAll('.mobile-data-field')).find(function(field) {
                var label = field.querySelector('.mobile-data-label');
                return label && label.textContent.trim().toLowerCase() === 'progress';
            });
            if (progressBar) {
                progressBar.style.width = '100%';
            }
            if (progressLabel) {
                var valueEl = progressLabel.querySelector('.mobile-data-value');
                if (valueEl) {
                    var counts = valueEl.textContent.match(/(\d+)\/(\d+)/);
                    if (counts) {
                        valueEl.textContent = counts[2] + '/' + counts[2] + ' (100%)';
                    }
                }
            }
        }
    }

    function loadProduksiDetail(produksiId) {
        var content = document.getElementById('produksiDetailContent');
        var title = document.getElementById('modalProduksiDetailTitle');
        if (!content || !produksiId) {
            return;
        }

        currentProduksiDetailId = Number(produksiId || 0);
        content.innerHTML = '<div class="text-center text-muted" style="padding:40px"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Memuat...</div>';
        if (title) {
            title.textContent = 'Detail Produksi';
        }

        fetch('produksi_tahapan.php?produksi_id=' + produksiId)
            .then(function(response) {
                return response.text();
            })
            .then(function(html) {
                content.innerHTML = html;
                if (title) {
                    var holder = content.querySelector('[data-produksi-detail-title]');
                    if (holder) {
                        title.textContent = holder.getAttribute('data-produksi-detail-title');
                    }
                }
                if (typeof window.initProduksiTahapan === 'function') {
                    window.initProduksiTahapan(content);
                }
            })
            .catch(function() {
                content.innerHTML = '<p class="text-muted">Gagal memuat detail produksi.</p>';
            });
    }

    function refreshProduksiDetail(produksiId) {
        if (!produksiId) {
            return;
        }

        if (Number(produksiId) === currentProduksiDetailId) {
            loadProduksiDetail(produksiId);
        }
    }

    window.initProduksiTahapan = function(panel) {
        if (!panel || panel.dataset.behaviorBound === '1') {
            return;
        }
        panel.dataset.behaviorBound = '1';

        panel.addEventListener('change', function(event) {
            var select = event.target.closest('.select-operator');
            if (!select) {
                return;
            }

            var tahapanId = select.dataset.id;
            var userId = select.value;
            var previousValue = select.dataset.previousValue || '';

            if (!userId) {
                select.value = previousValue;
                showTahapanToast('Pilih operator yang akan ditugaskan.', 'danger');
                return;
            }

            select.disabled = true;

            var fd = new FormData();
            fd.append('action', 'assign');
            fd.append('tahapan_id', tahapanId);
            fd.append('user_id', userId);

            fetch('produksi_tahapan.php', { method: 'POST', body: fd })
                .then(function(response) {
                    return response.json();
                })
                .then(function(res) {
                    if (res.success) {
                        select.dataset.previousValue = userId;
                        showTahapanToast(res.message, 'success');
                        refreshProduksiDetail(select.dataset.produksi);
                        return;
                    }

                    select.value = previousValue;
                    showTahapanToast(res.message || 'Gagal menugaskan operator.', 'danger');
                })
                .catch(function() {
                    select.value = previousValue;
                    showTahapanToast('Terjadi kesalahan', 'danger');
                })
                .finally(function() {
                    select.disabled = false;
                });
        });

        panel.addEventListener('focusin', function(event) {
            var select = event.target.closest('.select-operator');
            if (!select) {
                return;
            }
            select.dataset.previousValue = select.value;
        });

        panel.addEventListener('click', function(event) {
            var button = event.target.closest('.btn-checklist');
            if (!button) {
                return;
            }

            var tahapanId = button.dataset.id;
            var produksiId = button.dataset.produksi;
            button.disabled = true;

            var fd = new FormData();
            fd.append('action', 'checklist');
            fd.append('tahapan_id', tahapanId);

            fetch('produksi_tahapan.php', { method: 'POST', body: fd })
                .then(function(response) {
                    return response.json();
                })
                .then(function(res) {
                    if (!res.success) {
                        showTahapanToast(res.message || 'Gagal menyimpan tahapan.', 'danger');
                        return;
                    }

                    showTahapanToast(res.message, 'success');
                    refreshProduksiDetail(produksiId);

                    if (res.all_done) {
                        updateProduksiRowAsDone(produksiId);
                    }
                })
                .catch(function() {
                    showTahapanToast('Terjadi kesalahan', 'danger');
                })
                .finally(function() {
                    button.disabled = false;
                });
        });
    };

    window.openProduksiDetail = function(produksiId) {
        currentProduksiDetailId = Number(produksiId || 0);
        openModal('modalProduksiDetail');
        loadProduksiDetail(produksiId);
    };

    window.editProduksi = function(d) {
        closeModal('modalProduksiDetail');
        document.getElementById('eId').value = d.id;
        document.getElementById('eNoDok').value = d.no_dokumen;
        document.getElementById('eNama').value = d.nama_pekerjaan;
        document.getElementById('eStatus').value = d.status;
        document.getElementById('eDeadline').value = d.deadline || '';
        document.getElementById('eKaryawan').value = d.karyawan_id || '';
        document.getElementById('eKet').value = d.keterangan || '';
        openModal('modalEdit');
    };

    window.cetakDokumen = function(id, tipe) {
        var page = tipe === 'SPK' ? 'spk_cetak.php' : 'jo_cetak.php';
        window.open(page + '?id=' + id, '_blank');
    };

    window.toggleTahapan = function(id) {
        window.openProduksiDetail(id);
    };

    initialiseProduksiSearch();
});
