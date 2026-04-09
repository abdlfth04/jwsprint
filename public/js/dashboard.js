document.addEventListener('DOMContentLoaded', function() {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const dashboardCharts = pageState.dashboardCharts || {};

    function formatDashboardChartValue(value, mode) {
        const numberFormatter = new Intl.NumberFormat('id-ID');
        const currencyFormatter = new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        });

        if (mode === 'currency') {
            return currencyFormatter.format(Number(value) || 0);
        }

        return numberFormatter.format(Number(value) || 0);
    }

    function renderDashboardCharts() {
        if (typeof window.Chart !== 'function') {
            return;
        }

        const primaryChart = dashboardCharts.primary || {};
        const breakdownChart = dashboardCharts.breakdown || {};
        const ctxOmzet = document.getElementById('omzetChart');
        const ctxStatus = document.getElementById('statusChart');

        if (ctxOmzet) {
            new Chart(ctxOmzet, {
                type: 'line',
                data: {
                    labels: primaryChart.labels || [],
                    datasets: [{
                        label: primaryChart.label || 'Aktivitas',
                        data: primaryChart.data || [],
                        borderColor: primaryChart.color || '#0f766e',
                        backgroundColor: primaryChart.fill || 'rgba(15, 118, 110, 0.12)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: primaryChart.color || '#0f766e',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatDashboardChartValue(context.parsed.y, primaryChart.format || 'number');
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            border: { dash: [4, 4] },
                            grid: { color: '#e2e8f0' },
                            ticks: {
                                callback: function(value) {
                                    return formatDashboardChartValue(value, primaryChart.format || 'number');
                                }
                            }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        if (ctxStatus) {
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: breakdownChart.labels || [],
                    datasets: [{
                        data: breakdownChart.data || [],
                        backgroundColor: breakdownChart.colors || ['#0f766e', '#3b82f6', '#f59e0b', '#64748b', '#10b981', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + formatDashboardChartValue(context.parsed, 'number');
                                }
                            }
                        }
                    },
                    cutout: '75%'
                }
            });
        }
    }

    renderDashboardCharts();

    function showDashboardToast(message, type) {
        if (typeof window.showAlert === 'function') {
            window.showAlert(message, type);
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'alert alert-' + type;
        toast.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;min-width:220px;max-width:320px';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.remove();
        }, 3000);
    }

    document.addEventListener('click', function(event) {
        var button = event.target.closest('.btn-dashboard-checklist');
        if (!button) {
            return;
        }

        event.preventDefault();

        var tahapanId = button.dataset.tahapanId;
        if (!tahapanId) {
            showDashboardToast('Tahapan tidak valid.', 'danger');
            return;
        }

        var originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan';

        var fd = new FormData();
        fd.append('action', 'checklist');
        fd.append('tahapan_id', tahapanId);

        fetch('produksi_tahapan.php', {
            method: 'POST',
            body: fd
        })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                if (!result.success) {
                    showDashboardToast(result.message || 'Gagal menyimpan progres tugas.', 'danger');
                    return;
                }

                var taskRow = button.closest('[data-dashboard-task-row]');
                if (taskRow) {
                    taskRow.style.opacity = '0.52';
                    taskRow.style.pointerEvents = 'none';
                }

                showDashboardToast(result.message || 'Tugas berhasil diperbarui.', 'success');
                setTimeout(function() {
                    window.location.reload();
                }, result.all_done ? 900 : 600);
            })
            .catch(function() {
                showDashboardToast('Terjadi kesalahan saat memperbarui tugas.', 'danger');
            })
            .finally(function() {
                button.disabled = false;
                button.innerHTML = originalHtml;
            });
    });
});
