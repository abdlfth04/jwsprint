document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const kpiPeriod = pageState.kpiPeriod || {};
    const kpiCalcUrl = pageState.kpiCalcUrl || '';

    window.filterKpiView = function () {
        const searchInput = document.getElementById('srchKpi');
        const keyword = ((searchInput && searchInput.value) || '').toLowerCase();

        document.querySelectorAll('#tblKpi tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });

        document.querySelectorAll('#mobileKpiList .mobile-data-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };

    window.editTargetKpi = function (data) {
        document.getElementById('targetKaryawanId').value = data.karyawan_id || '';
        document.getElementById('targetKaryawanName').textContent = data.nama || '-';
        document.getElementById('targetPeriodeMulai').value = kpiPeriod.mulai || '';
        document.getElementById('targetPeriodeSelesai').value = kpiPeriod.selesai || '';
        document.getElementById('targetCustom').value = data.target_custom || 0;
        document.getElementById('pencapaianCustom').value = data.pencapaian_custom || 0;

        const pekerjaanField = document.getElementById('targetPekerjaan');
        if (pekerjaanField) {
            pekerjaanField.value = data.target_pekerjaan || 10;
        }

        openModal('modalTarget');
    };

    window.hitungKpi = function () {
        const button = document.getElementById('btnHitungKpi');
        const status = document.getElementById('kpiCalcStatus');
        const payload = {
            periode_mulai: kpiPeriod.mulai || '',
            periode_selesai: kpiPeriod.selesai || ''
        };

        if (!payload.periode_mulai || !payload.periode_selesai || !kpiCalcUrl) {
            return;
        }

        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghitung...';
        }
        if (status) {
            status.textContent = 'Menghitung ulang KPI untuk periode yang dipilih...';
        }

        $.ajax({
            url: kpiCalcUrl,
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function (response) {
                if (response && response.success) {
                    if (status) status.textContent = 'Perhitungan KPI berhasil diperbarui.';
                    window.location.reload();
                    return;
                }

                if (status) {
                    status.textContent = (response && response.message) || 'Perhitungan KPI gagal.';
                }
            },
            error: function () {
                if (status) status.textContent = 'Terjadi kesalahan saat menghubungi server KPI.';
            },
            complete: function () {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-bolt"></i> Hitung Ulang KPI';
                }
            }
        });
    };
});
