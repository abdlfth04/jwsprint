document.addEventListener('DOMContentLoaded', function() {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const settingState = pageState.setting || {};
    const backupEndpoint = settingState.backupEndpoint || 'backup.php';
    const maxRestoreSizeMb = Number(settingState.maxRestoreSizeMb || 50);
    const backupRestoreEnabled = Boolean(settingState.backupRestoreEnabled);
    const extractUploadErrorMessage = window.jwsExtractUploadErrorMessage
        || function(xhr, fallbackMessage) { return fallbackMessage || 'Terjadi kesalahan saat upload.'; };

    window.editUser = function(u) {
        document.getElementById("euId").value = u.id;
        document.getElementById("euNama").value = u.nama;
        document.getElementById("euUname").value = u.username;
        document.getElementById("euRole").value = u.role;
        document.getElementById("euStatus").value = u.status;
        openModal("modalEditUser");
    };

    // Toggle pajak badge
    const pajakChk = document.getElementById("pajakAktifCheck");
    if (pajakChk) {
        pajakChk.addEventListener("change", function() {
            const badge = document.getElementById("pajakStatusBadge");
            badge.textContent = this.checked ? "Aktif" : "Nonaktif";
            badge.className = "badge " + (this.checked ? "badge-success" : "badge-secondary");
        });
    }

    // Function for restore process (from setting.php)
    window.prosesRestore = function() {
        if (!backupRestoreEnabled) {
            alert("Restore database via web dinonaktifkan di environment ini.");
            return;
        }
        var file = document.getElementById("sqlRestoreFile").files[0];
        if (!file) { alert("Pilih file .sql terlebih dahulu"); return; }
        if (file.size > maxRestoreSizeMb * 1024 * 1024) { alert("Ukuran file melebihi batas " + maxRestoreSizeMb + "MB."); return; }
        var confirmPhrase = (document.getElementById("restoreConfirmPhrase")?.value || "").trim().toUpperCase();
        if (confirmPhrase !== "RESTORE") { alert("Ketik RESTORE untuk mengonfirmasi proses ini."); return; }
        if (!confirm("PERINGATAN: Restore akan menimpa data yang ada. Lanjutkan?")) return;
        var status = document.getElementById("restoreStatus");
        status.innerHTML = "<span style=\"color:var(--text-muted)\"><i class=\"fas fa-spinner fa-spin\"></i> Memproses...</span>";
        var fd = new FormData();
        fd.append("action", "restore");
        fd.append("sqlfile", file);
        fd.append("confirm_restore", confirmPhrase);
        $.ajax({
            url: backupEndpoint,
            type: "POST",
            data: fd,
            processData: false,
            contentType: false,
            dataType: "json",
            success: function(res) {
                if (res.success) {
                    status.innerHTML = "<span style=\"color:var(--success)\"><i class=\"fas fa-check\"></i> " + res.msg + "</span>";
                    var phraseInput = document.getElementById("restoreConfirmPhrase");
                    if (phraseInput) phraseInput.value = "";
                } else {
                    status.innerHTML = "<span style=\"color:var(--danger)\"><i class=\"fas fa-times\"></i> " + res.msg + "</span>";
                }
            },
            error: function(xhr) {
                status.innerHTML = "<span style=\"color:var(--danger)\">"
                    + extractUploadErrorMessage(xhr, "Terjadi kesalahan", {
                        tooLargeMessage: "Ukuran file melebihi batas upload server/PHP."
                    })
                    + "</span>";
            }
        });
    };
});
