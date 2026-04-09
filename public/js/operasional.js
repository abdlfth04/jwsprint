document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const presetKat = pageState.opsPresetKat || {};

    window.updateKatPreset = function (divisi, selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;

        const options = presetKat[divisi] || presetKat.umum || [];
        const currentValue = select.value;
        select.innerHTML = options.map(function (item) {
            return '<option value="' + item + '"' + (item === currentValue ? ' selected' : '') + '>' + item + '</option>';
        }).join('');
    };

    window.editOps = function (data) {
        document.getElementById('eId').value = data.id;
        document.getElementById('eTgl').value = data.tanggal;
        document.getElementById('eJml').value = data.jumlah;
        document.getElementById('eKet').value = data.keterangan;

        const divisi = data.divisi || 'umum';
        const editDivisi = document.getElementById('eDivisi');
        if (editDivisi) {
            editDivisi.value = divisi;
            window.updateKatPreset(divisi, 'eKat');
        }

        setTimeout(function () {
            const kategoriSelect = document.getElementById('eKat');
            if (!kategoriSelect) return;

            const found = Array.from(kategoriSelect.options).some(function (option) {
                return option.value === data.kategori;
            });

            if (!found && data.kategori) {
                kategoriSelect.innerHTML += '<option value="' + data.kategori + '">' + data.kategori + '</option>';
            }
            kategoriSelect.value = data.kategori;
        }, 10);

        openModal('modalEdit');
    };

    window.filterOperasionalView = function () {
        const keyword = (document.getElementById('srchOperasional')?.value || '').toLowerCase();

        document.querySelectorAll('#tblOperasional tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });

        document.querySelectorAll('#mobileOperasionalList .mobile-data-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };
});
