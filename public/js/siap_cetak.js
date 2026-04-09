document.addEventListener('DOMContentLoaded', function () {
    const uploadEndpoint = window.jwsPageUrl ? window.jwsPageUrl('file_upload.php') : 'file_upload.php';
    const deleteEndpoint = window.jwsPageUrl ? window.jwsPageUrl('file_delete.php') : 'file_delete.php';
    const extractUploadErrorMessage = window.jwsExtractUploadErrorMessage
        || function (xhr, fallbackMessage) { return fallbackMessage || 'Terjadi kesalahan jaringan saat upload.'; };
    const previewModeStorageKey = 'siapCetakReferencePreviewMode';
    const searchStorageKey = 'siapCetakSearchQuery';
    const normalizeSearchText = window.jwsNormalizeSearchText || function (value) {
        return String(value ?? '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    };
    let previewObserver = null;
    let previewMode = 'compact';

    function getStoredPreviewMode() {
        try {
            const storedValue = window.localStorage.getItem(previewModeStorageKey);
            return storedValue === 'thumbnail' ? 'thumbnail' : 'compact';
        } catch (error) {
            return 'compact';
        }
    }

    function getStoredSearchQuery() {
        try {
            return String(window.sessionStorage.getItem(searchStorageKey) || '');
        } catch (error) {
            return '';
        }
    }

    function setStoredSearchQuery(query) {
        try {
            const normalizedValue = String(query ?? '');
            if (normalizedValue === '') {
                window.sessionStorage.removeItem(searchStorageKey);
                return;
            }

            window.sessionStorage.setItem(searchStorageKey, normalizedValue);
        } catch (error) {
            // Abaikan jika storage browser tidak tersedia.
        }
    }

    function syncPreviewToggleUi() {
        document.querySelectorAll('[data-reference-preview-toggle] [data-preview-mode]').forEach(function (button) {
            const isActive = button.getAttribute('data-preview-mode') === previewMode;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function applyPreviewMode(mode) {
        previewMode = mode === 'thumbnail' ? 'thumbnail' : 'compact';
        document.documentElement.setAttribute('data-reference-preview-mode', previewMode);
        syncPreviewToggleUi();

        try {
            window.localStorage.setItem(previewModeStorageKey, previewMode);
        } catch (error) {
            // Abaikan jika storage browser tidak tersedia.
        }

        if (previewMode === 'thumbnail') {
            document.querySelectorAll('[data-reference-collapsible][open]').forEach(function (details) {
                queueDeferredPreviewImages(details);
            });
        }
    }

    function loadDeferredPreviewImage(img) {
        if (!img || img.dataset.previewLoaded === '1') {
            return;
        }

        const previewSrc = img.getAttribute('data-preview-src');
        if (!previewSrc) {
            return;
        }

        img.dataset.previewLoaded = '1';

        const shell = img.closest('.preview-thumb');
        if (shell) {
            shell.classList.add('is-loading');
            shell.classList.remove('is-ready', 'is-failed');
        }

        img.addEventListener('load', function handleLoad() {
            if (shell) {
                shell.classList.remove('is-loading');
                shell.classList.add('is-ready');
            }
        }, { once: true });

        img.addEventListener('error', function handleError() {
            if (shell) {
                shell.classList.remove('is-loading');
                shell.classList.add('is-failed');
            }
        }, { once: true });

        img.src = previewSrc;
    }

    function queueDeferredPreviewImages(scope) {
        if (previewMode !== 'thumbnail') {
            return;
        }

        const root = scope || document;
        const previewImages = root.querySelectorAll('img[data-preview-src]');
        if (!previewImages.length) {
            return;
        }

        if ('IntersectionObserver' in window) {
            if (!previewObserver) {
                previewObserver = new IntersectionObserver(function (entries, observer) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) {
                            return;
                        }

                        loadDeferredPreviewImage(entry.target);
                        observer.unobserve(entry.target);
                    });
                }, {
                    rootMargin: '160px 0px'
                });
            }

            previewImages.forEach(function (img) {
                if (img.dataset.previewObserved === '1') {
                    return;
                }

                img.dataset.previewObserved = '1';
                previewObserver.observe(img);
            });

            return;
        }

        previewImages.forEach(loadDeferredPreviewImage);
    }

    function bindReferenceCollapsibles() {
        document.querySelectorAll('[data-reference-collapsible]').forEach(function (details) {
            if (details.open && previewMode === 'thumbnail') {
                queueDeferredPreviewImages(details);
            }

            details.addEventListener('toggle', function () {
                if (details.open && previewMode === 'thumbnail') {
                    queueDeferredPreviewImages(details);
                }
            });
        });
    }

    function bindReferencePreviewToggle() {
        document.querySelectorAll('[data-reference-preview-toggle] [data-preview-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyPreviewMode(button.getAttribute('data-preview-mode'));
            });
        });
    }

    function updateReadyPrintSearchInfo(matchCount, totalCount, query) {
        const info = document.getElementById('siapCetakSearchInfo');
        if (!info) {
            return;
        }

        const normalizedQuery = normalizeSearchText(query);
        if (normalizedQuery === '') {
            info.textContent = totalCount.toLocaleString('id-ID') + ' job siap ditelusuri.';
            return;
        }

        if (matchCount > 0) {
            info.textContent = matchCount.toLocaleString('id-ID') + ' job cocok untuk "' + String(query || '').trim() + '".';
            return;
        }

        info.textContent = 'Tidak ada job siap cetak yang cocok.';
    }

    function syncReadyPrintSearchClear(query) {
        const clearButton = document.getElementById('siapCetakSearchClear');
        if (!clearButton) {
            return;
        }

        const hasQuery = normalizeSearchText(query) !== '';
        clearButton.hidden = !hasQuery;
        clearButton.disabled = !hasQuery;
    }

    function getReadyPrintSearchInput() {
        return document.getElementById('siapCetakSearch');
    }

    function clearReadyPrintSearch() {
        const searchInput = getReadyPrintSearchInput();
        if (!searchInput) {
            return;
        }

        searchInput.value = '';
        setStoredSearchQuery('');
        filterReadyPrintJobs();
        searchInput.focus();
    }

    function filterReadyPrintJobs() {
        const searchInput = getReadyPrintSearchInput();
        const cards = Array.from(document.querySelectorAll('.sc-card[data-ready-search]'));
        const rawQuery = searchInput ? searchInput.value : '';
        const normalizedQuery = normalizeSearchText(rawQuery);
        let visibleCount = 0;

        cards.forEach(function (card) {
            const haystack = normalizeSearchText(card.getAttribute('data-ready-search') || card.textContent || '');
            const matches = normalizedQuery === '' || haystack.includes(normalizedQuery);
            card.hidden = !matches;
            card.classList.toggle('hidden', !matches);
            card.setAttribute('aria-hidden', matches ? 'false' : 'true');
            if (matches) {
                visibleCount += 1;
            }
        });

        const emptyState = document.getElementById('siapCetakSearchEmpty');
        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
        }

        setStoredSearchQuery(rawQuery);
        syncReadyPrintSearchClear(rawQuery);
        updateReadyPrintSearchInfo(visibleCount, cards.length, rawQuery);
    }

    window.filterSiapCetakView = filterReadyPrintJobs;

    function bindReadyPrintSearchControls() {
        const searchInput = getReadyPrintSearchInput();
        const clearButton = document.getElementById('siapCetakSearchClear');
        if (!searchInput) {
            return;
        }

        const storedQuery = getStoredSearchQuery();
        if (storedQuery !== '' && normalizeSearchText(searchInput.value) === '') {
            searchInput.value = storedQuery;
        }
        syncReadyPrintSearchClear(searchInput.value);

        if (clearButton) {
            clearButton.addEventListener('click', clearReadyPrintSearch);
        }

        document.addEventListener('keydown', function (event) {
            if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            const activeElement = document.activeElement;
            const isTypingContext = activeElement
                && (
                    activeElement.tagName === 'INPUT'
                    || activeElement.tagName === 'TEXTAREA'
                    || activeElement.tagName === 'SELECT'
                    || activeElement.isContentEditable
                );

            if (event.key === '/' && !isTypingContext) {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
                return;
            }

            if (event.key === 'Escape' && activeElement === searchInput && normalizeSearchText(searchInput.value) !== '') {
                event.preventDefault();
                clearReadyPrintSearch();
            }
        });
    }

    function renderStatus(targetId, message, tone) {
        const status = document.getElementById(targetId);
        if (!status) return;

        const colorMap = {
            success: 'var(--success)',
            danger: 'var(--danger)',
            info: 'var(--info)'
        };

        status.innerHTML = '<span style="color:' + (colorMap[tone] || 'var(--text-muted)') + '">' + message + '</span>';
    }

    function normalizeUploadLabel(label) {
        const text = String(label || 'file').trim();
        return text ? text.toLowerCase() : 'file';
    }

    async function uploadFiles(files, options) {
        const jobId = Number(options && options.jobId ? options.jobId : 0);
        const transaksiId = Number(options && options.transaksiId ? options.transaksiId : 0);
        const detailTransaksiId = Number(options && options.detailTransaksiId ? options.detailTransaksiId : 0);
        const tipeFile = String(options && options.tipeFile ? options.tipeFile : 'siap_cetak');
        const statusTargetId = String(options && options.statusTargetId ? options.statusTargetId : ('uploadStatus-' + jobId));
        const emptyLabel = normalizeUploadLabel(options && options.emptyLabel ? options.emptyLabel : 'file');

        if (!files || !files.length) {
            renderStatus(statusTargetId, 'Pilih ' + emptyLabel + ' terlebih dahulu.', 'danger');
            return;
        }

        let successCount = 0;
        let failCount = 0;
        let lastError = '';

        renderStatus(statusTargetId, '<i class="fas fa-spinner fa-spin"></i> Mengunggah ' + files.length + ' file...', 'info');

        for (const file of files) {
            const formData = new FormData();
            formData.append('transaksi_id', transaksiId);
            if (detailTransaksiId) {
                formData.append('detail_transaksi_id', detailTransaksiId);
            }
            formData.append('tipe_file', tipeFile);
            formData.append('file', file);

            try {
                const response = await $.ajax({
                    url: uploadEndpoint,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                });

                if (response.success) {
                    successCount += 1;
                } else {
                    failCount += 1;
                    lastError = Array.isArray(response.errors) && response.errors.length
                        ? response.errors.join('<br>')
                        : (response.message || 'Upload gagal.');
                }
            } catch (xhr) {
                failCount += 1;
                lastError = extractUploadErrorMessage(xhr, 'Terjadi kesalahan jaringan saat upload.', {
                    tooLargeMessage: 'Ukuran file melebihi batas upload server/PHP. Coba upload file lebih kecil atau naikkan batas upload di cPanel.'
                });
            }
        }

        if (successCount > 0) {
            const summary = '<i class="fas fa-check"></i> ' + successCount + ' file berhasil diunggah'
                + (failCount > 0 ? ' (' + failCount + ' gagal)' : '')
                + '. Halaman akan dimuat ulang.';
            renderStatus(statusTargetId, summary, 'success');
            setTimeout(function () {
                window.location.reload();
            }, 1200);
            return;
        }

        renderStatus(statusTargetId, lastError || 'Upload gagal.', 'danger');
    }

    window.uploadSiapCetak = function (input, jobId, transaksiId, detailTransaksiId) {
        uploadFiles(input.files, {
            jobId: jobId,
            transaksiId: transaksiId,
            detailTransaksiId: detailTransaksiId,
            tipeFile: 'siap_cetak',
            statusTargetId: 'uploadStatus-' + jobId,
            emptyLabel: 'file TIF atau PDF'
        });
        input.value = '';
    };

    window.handleDrop = function (event, jobId, transaksiId, detailTransaksiId) {
        event.preventDefault();
        uploadFiles(event.dataTransfer.files, {
            jobId: jobId,
            transaksiId: transaksiId,
            detailTransaksiId: detailTransaksiId,
            tipeFile: 'siap_cetak',
            statusTargetId: 'uploadStatus-' + jobId,
            emptyLabel: 'file TIF atau PDF'
        });
    };

    window.uploadSupportFiles = function (input, jobId, transaksiId, detailTransaksiId, tipeFile, statusTargetId, emptyLabel) {
        uploadFiles(input.files, {
            jobId: jobId,
            transaksiId: transaksiId,
            detailTransaksiId: detailTransaksiId,
            tipeFile: tipeFile,
            statusTargetId: statusTargetId,
            emptyLabel: emptyLabel
        });
        input.value = '';
    };

    window.handleSupportDrop = function (event, jobId, transaksiId, detailTransaksiId, tipeFile, statusTargetId, emptyLabel) {
        event.preventDefault();
        uploadFiles(event.dataTransfer.files, {
            jobId: jobId,
            transaksiId: transaksiId,
            detailTransaksiId: detailTransaksiId,
            tipeFile: tipeFile,
            statusTargetId: statusTargetId,
            emptyLabel: emptyLabel
        });
    };

    window.hapusFileTransaksi = function (fileId, button, fileLabel) {
        const normalizedLabel = normalizeUploadLabel(fileLabel || 'file ini');
        if (!confirm('Apakah Anda yakin ingin menghapus ' + normalizedLabel + '?')) {
            return;
        }

        if (button) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        $.post(deleteEndpoint, { id: fileId }, function (response) {
            if (response.success) {
                window.location.reload();
                return;
            }

            alert(response.message || 'Gagal menghapus file.');
            if (button) {
                button.disabled = false;
                button.innerHTML = button.dataset.originalHtml || '<i class="fas fa-trash"></i>';
            }
        }, 'json').fail(function () {
            alert('Terjadi kesalahan jaringan.');
            if (button) {
                button.disabled = false;
                button.innerHTML = button.dataset.originalHtml || '<i class="fas fa-trash"></i>';
            }
        });
    };

    window.showLightbox = function (src, alt) {
        const img = document.getElementById('lbImg');
        const lightbox = document.getElementById('lightbox');
        if (!img || !lightbox) return;

        img.src = src;
        img.alt = alt || '';
        lightbox.classList.add('show');
    };

    window.closeLightbox = function () {
        const img = document.getElementById('lbImg');
        const lightbox = document.getElementById('lightbox');
        if (!img || !lightbox) return;

        img.src = '';
        img.alt = '';
        lightbox.classList.remove('show');
    };

    applyPreviewMode(getStoredPreviewMode());
    bindReferencePreviewToggle();
    bindReferenceCollapsibles();
    bindReadyPrintSearchControls();
    filterReadyPrintJobs();
});
