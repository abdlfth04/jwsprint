document.addEventListener('DOMContentLoaded', function() {
    const video = document.getElementById('cameraStream');
    const canvas = document.getElementById('cameraCanvas');
    const btnAbsen = document.getElementById('btnAbsen');
    const cameraError = document.getElementById('cameraError');
    const actionElem = document.getElementById('absenAction');
    const endpoint = window.jwsPageUrl ? window.jwsPageUrl('absensi_mobile.php') : 'absensi_mobile.php';

    if (!video || !actionElem) {
        return;
    }

    const action = actionElem.value;
    let stream = null;

    function showCameraError() {
        video.style.display = 'none';
        if (cameraError) {
            cameraError.style.display = 'block';
        }
    }

    function stopCameraStream() {
        if (!stream) {
            return;
        }

        stream.getTracks().forEach(function(track) {
            track.stop();
        });
        stream = null;
    }

    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
            .then(function(mediaStream) {
                stream = mediaStream;
                video.srcObject = mediaStream;
                video.play();
                if (btnAbsen) {
                    btnAbsen.disabled = false;
                }
            })
            .catch(function(err) {
                console.error('Camera access denied/failed:', err);
                showCameraError();
            });
    } else {
        showCameraError();
    }

    if (btnAbsen) {
        btnAbsen.addEventListener('click', function() {
            btnAbsen.disabled = true;
            const originalText = btnAbsen.innerHTML;
            btnAbsen.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i> Memproses...';

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');

            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const base64Image = canvas.toDataURL('image/jpeg', 0.8);
            const formData = new FormData();
            formData.append('action', action);
            formData.append('image', base64Image);

            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }

                    alert('Gagal: ' + data.message);
                    btnAbsen.disabled = false;
                    btnAbsen.innerHTML = originalText;
                })
                .catch(function(err) {
                    console.error('AJAX Error:', err);
                    alert('Terjadi kesalahan jaringan.');
                    btnAbsen.disabled = false;
                    btnAbsen.innerHTML = originalText;
                });
        });
    }

    window.addEventListener('beforeunload', stopCameraStream);
});
