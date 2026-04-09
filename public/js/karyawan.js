document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const employeePhotoUrlBase = String(pageState.employeePhotoUrlBase || '');
    const jabatanRoleMap = {
        CEO: 'superadmin',
        'Head Office': 'superadmin',
        HRD: 'admin',
        Admin: 'admin',
        'Customer Service': 'service',
        Kasir: 'kasir',
        'Operator Printing': 'user',
        'Operator Jahit': 'user',
        'Tim Produksi Printing': 'user',
        'Tim Produksi Apparel': 'user',
        'Office Boy': 'user'
    };

    const hasExtendedColumns = Boolean(pageState.hasNewCols);
    const payrollScheduleMap = pageState.payrollScheduleRules || {};

    function applyRoleBadge(selectEl, badgeWrapId) {
        const wrap = document.getElementById(badgeWrapId);
        const text = document.getElementById(badgeWrapId + 'Text');
        if (!wrap || !text || !selectEl) return;

        const role = jabatanRoleMap[selectEl.value];
        if (role) {
            wrap.classList.add('active');
            text.textContent = role.toUpperCase();
        } else {
            wrap.classList.remove('active');
            text.textContent = '';
        }
    }

    function togglePayrollFields(method, gajiWrapId, tarifWrapId) {
        const gajiWrap = document.getElementById(gajiWrapId);
        const tarifWrap = document.getElementById(tarifWrapId);
        if (!gajiWrap || !tarifWrap) return;

        if (method === 'borongan') {
            gajiWrap.style.display = 'none';
            tarifWrap.style.display = '';
        } else {
            gajiWrap.style.display = '';
            tarifWrap.style.display = 'none';
        }
    }

    function applyPayrollScheduleHint(selectEl, hintWrapId) {
        const wrap = document.getElementById(hintWrapId);
        const text = document.getElementById(hintWrapId + 'Text');
        if (!wrap || !text || !selectEl) return;

        const method = selectEl.value || 'bulanan';
        text.textContent = payrollScheduleMap[method] || payrollScheduleMap.bulanan || '';
    }

    function setSelectValue(selectEl, value) {
        if (!selectEl) return;
        selectEl.value = value ?? '';
    }

    function populateEditModal(data) {
        document.getElementById('eId').value = data.id;
        document.getElementById('eNama').value = data.nama || '';
        document.getElementById('eTlp').value = data.telepon || '';
        document.getElementById('eEmail').value = data.email || '';
        document.getElementById('eAlamat').value = data.alamat || '';
        document.getElementById('eGaji').value = data.gaji || 0;
        document.getElementById('eTgl').value = data.tanggal_masuk || '';
        setSelectValue(document.getElementById('eStatus'), data.status || 'aktif');

        if (hasExtendedColumns) {
            const jabatanSelect = document.getElementById('eJabatan');
            setSelectValue(jabatanSelect, data.jabatan || '');
            applyRoleBadge(jabatanSelect, 'eRoleBadge');

            setSelectValue(document.getElementById('eDivisi'), data.divisi || 'umum');
            document.getElementById('eNik').value = data.nik || '';
            document.getElementById('eTglLhr').value = data.tanggal_lahir || '';

            const metodeSelect = document.getElementById('eMetodeGaji');
            setSelectValue(metodeSelect, data.metode_gaji || 'bulanan');
            togglePayrollFields(metodeSelect ? metodeSelect.value : 'bulanan', 'eGajiPokokWrap', 'eTarifWrap');
            applyPayrollScheduleHint(metodeSelect, 'ePayrollScheduleHint');

            document.getElementById('eGajiPokok').value = data.gaji_pokok || 0;
            document.getElementById('eTarif').value = data.tarif_borongan || 0;

            const userSelect = document.getElementById('eUserId');
            if (userSelect) {
                const selectedUserId = data.user_id || '';
                const exists = Array.from(userSelect.options).some(function (option) {
                    return option.value === String(selectedUserId);
                });

                if (selectedUserId && !exists) {
                    const option = document.createElement('option');
                    option.value = selectedUserId;
                    option.textContent = '(User #' + selectedUserId + ' - terhubung)';
                    userSelect.appendChild(option);
                }

                userSelect.value = selectedUserId;
            }

            const preview = document.getElementById('eFotoPreview');
            if (preview) {
                preview.innerHTML = data.foto
                    ? '<img src="' + employeePhotoUrlBase + encodeURIComponent(data.foto) + '" alt="Foto karyawan" style="height:52px;border-radius:12px;object-fit:cover">'
                    : '';
            }
        } else {
            const jabatanText = document.getElementById('eJabatanText');
            if (jabatanText) jabatanText.value = data.jabatan || '';
        }

        openModal('modalEdit');
    }

    window.editKaryawan = populateEditModal;

    window.filterKaryawanView = function () {
        const searchInput = document.getElementById('srchKaryawan');
        const keyword = ((searchInput && searchInput.value) || '').toLowerCase();

        document.querySelectorAll('#tblKaryawan tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });

        document.querySelectorAll('#mobileKaryawanList .mobile-data-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };

    window.onJabatanChange = function (selectId, badgeWrapId) {
        applyRoleBadge(document.getElementById(selectId), badgeWrapId);
    };

    window.onMetodeGajiChange = function (selectId, gajiWrapId, tarifWrapId) {
        const selectEl = document.getElementById(selectId);
        togglePayrollFields(selectEl ? selectEl.value : 'bulanan', gajiWrapId, tarifWrapId);
        applyPayrollScheduleHint(selectEl, selectId.charAt(0) + 'PayrollScheduleHint');
    };

    document.body.addEventListener('click', function (event) {
        const button = event.target.closest('.btn-edit-karyawan');
        if (!button) return;

        try {
            const payload = JSON.parse(button.dataset.karyawan || '{}');
            populateEditModal(payload);
        } catch (error) {
        }
    });

    ['t', 'e'].forEach(function (prefix) {
        const jabatanSelect = document.getElementById(prefix + 'Jabatan');
        if (jabatanSelect) {
            jabatanSelect.addEventListener('change', function () {
                applyRoleBadge(jabatanSelect, prefix + 'RoleBadge');
            });
            applyRoleBadge(jabatanSelect, prefix + 'RoleBadge');
        }

        const metodeSelect = document.getElementById(prefix + 'MetodeGaji');
        if (metodeSelect) {
            metodeSelect.addEventListener('change', function () {
                togglePayrollFields(metodeSelect.value, prefix + 'GajiPokokWrap', prefix + 'TarifWrap');
                applyPayrollScheduleHint(metodeSelect, prefix + 'PayrollScheduleHint');
            });
            togglePayrollFields(metodeSelect.value, prefix + 'GajiPokokWrap', prefix + 'TarifWrap');
            applyPayrollScheduleHint(metodeSelect, prefix + 'PayrollScheduleHint');
        }
    });
});
