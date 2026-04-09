document.addEventListener('DOMContentLoaded', function () {
    window.openLightbox = function (src) {
        const overlay = document.getElementById('lightboxOverlay');
        const image = document.getElementById('lightboxImg');
        if (!overlay || !image) return;

        image.src = src;
        overlay.style.display = 'flex';
    };

    window.closeLightbox = function () {
        const overlay = document.getElementById('lightboxOverlay');
        const image = document.getElementById('lightboxImg');
        if (!overlay || !image) return;

        overlay.style.display = 'none';
        image.src = '';
    };

    window.filterAbsensiView = function () {
        const searchInput = document.getElementById('srchAbsensi');
        const keyword = ((searchInput && searchInput.value) || '').toLowerCase();

        document.querySelectorAll('#tblAbsensi tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });

        document.querySelectorAll('#mobileAbsensiList .mobile-data-card').forEach(function (card) {
            card.style.display = card.textContent.toLowerCase().includes(keyword) ? '' : 'none';
        });
    };

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            window.closeLightbox();
        }
    });
});
