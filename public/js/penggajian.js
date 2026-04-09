document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const scheduleDefaults = pageState.payrollScheduleDefaults || {};
    const scheduleRules = pageState.payrollScheduleRules || {};
    const duplicateFlag = Boolean(pageState.payrollDuplicateFlag);
    const employeeSelect = document.getElementById('payrollKaryawanId');
    const methodField = document.getElementById('payrollMethodLabel');
    const scheduleField = document.getElementById('payrollScheduleDate');
    const periodStartField = document.getElementById('payrollPeriodStart');
    const periodEndField = document.getElementById('payrollPeriodEnd');
    const scheduleHelp = document.getElementById('payrollScheduleHelp');

    function normalizeMethod(method) {
        const allowed = ['bulanan', 'mingguan', 'borongan', 'bagi_hasil'];
        return allowed.includes(method) ? method : 'bulanan';
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function parseIsoDate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }

        const parts = value.split('-').map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatIsoDate(date) {
        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-');
    }

    function resolveScheduledPayDate(method, periodEnd) {
        const activeMethod = normalizeMethod(method);
        const endDate = parseIsoDate(periodEnd);
        if (!endDate) {
            return (scheduleDefaults[activeMethod] || {}).jadwal_bayar || '';
        }

        if (activeMethod === 'bulanan') {
            return formatIsoDate(new Date(endDate.getFullYear(), endDate.getMonth(), 28));
        }

        if (activeMethod === 'mingguan' || activeMethod === 'borongan') {
            const target = new Date(endDate);
            const delta = (6 - target.getDay() + 7) % 7;
            target.setDate(target.getDate() + delta);
            return formatIsoDate(target);
        }

        return formatIsoDate(new Date(endDate.getFullYear(), endDate.getMonth() + 1, 5));
    }

    function getSelectedMethod() {
        if (!employeeSelect) {
            return 'bulanan';
        }

        const option = employeeSelect.options[employeeSelect.selectedIndex];
        return normalizeMethod(option ? option.dataset.metode || '' : '');
    }

    function updateSchedulePreview() {
        const method = getSelectedMethod();
        const defaults = scheduleDefaults[method] || scheduleDefaults.bulanan || {};
        const methodLabel = defaults.metode_label || 'Bulanan';
        const ruleLabel = defaults.jadwal_label || scheduleRules[method] || '';
        const scheduleDate = resolveScheduledPayDate(method, periodEndField ? periodEndField.value : '');

        if (methodField) {
            methodField.value = methodLabel;
        }
        if (scheduleField) {
            scheduleField.value = scheduleDate;
        }
        if (scheduleHelp) {
            scheduleHelp.textContent = ruleLabel + '. Periode dan jadwal akan menyesuaikan otomatis sesuai metode gaji karyawan.';
        }
    }

    function applyDefaultsForEmployee() {
        const method = getSelectedMethod();
        const defaults = scheduleDefaults[method] || scheduleDefaults.bulanan || {};

        if (periodStartField && defaults.periode_mulai) {
            periodStartField.value = defaults.periode_mulai;
        }
        if (periodEndField && defaults.periode_selesai) {
            periodEndField.value = defaults.periode_selesai;
        }

        updateSchedulePreview();
    }

    if (duplicateFlag) {
        openModal('modalDuplikat');
    }

    if (employeeSelect) {
        employeeSelect.addEventListener('change', applyDefaultsForEmployee);
    }

    if (periodEndField) {
        periodEndField.addEventListener('change', updateSchedulePreview);
        periodEndField.addEventListener('input', updateSchedulePreview);
    }

    updateSchedulePreview();

    window.filterPenggajianView = function () {
        const searchInput = document.getElementById('srchPenggajian');
        const keyword = ((searchInput && searchInput.value) || '').toLowerCase();

        document.querySelectorAll('#tblSlip tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });

        document.querySelectorAll('#mobileSlipList .mobile-data-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };
});
