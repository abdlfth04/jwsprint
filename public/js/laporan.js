document.addEventListener('DOMContentLoaded', function () {
    const pageState = window.getJwsPageState ? window.getJwsPageState() : (window.JWS_PAGE_STATE || {});
    const reportCharts = pageState.reportCharts || {};

    if (typeof window.Chart !== 'function') {
        return;
    }

    const numberFormatter = new Intl.NumberFormat('id-ID');
    const currencyFormatter = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    });

    function formatValue(value, mode) {
        const numericValue = Number(value) || 0;
        if (mode === 'currency') {
            return currencyFormatter.format(numericValue);
        }
        if (mode === 'percentage') {
            return numericValue.toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
        }
        return numberFormatter.format(numericValue);
    }

    function buildYAxis(format, beginAtZero) {
        return {
            beginAtZero: beginAtZero !== false,
            grid: {
                color: '#e2e8f0'
            },
            ticks: {
                callback: function (value) {
                    return formatValue(value, format);
                }
            }
        };
    }

    function createChart(canvas, chart) {
        const type = chart.type || 'bar';
        const format = chart.format || 'number';
        const options = chart.options || {};
        const datasets = (chart.datasets || []).map(function (dataset) {
            return Object.assign({}, dataset);
        });

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: options.legend !== false,
                    position: type === 'doughnut' ? 'bottom' : 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 16
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            const label = context.dataset.label || context.label || 'Nilai';
                            const value = type === 'doughnut'
                                ? context.parsed
                                : (options.indexAxis === 'y' ? context.parsed.x : context.parsed.y);
                            const activeFormat = context.dataset.yAxisID === 'yCurrency' ? 'currency' : format;
                            return label + ': ' + formatValue(value, activeFormat);
                        }
                    }
                }
            }
        };

        if (type === 'doughnut') {
            chartOptions.cutout = '68%';
        } else {
            chartOptions.scales = {
                x: {
                    grid: {
                        display: options.indexAxis === 'y'
                    }
                },
                y: buildYAxis(format, options.beginAtZero)
            };

            if (options.indexAxis === 'y') {
                chartOptions.indexAxis = 'y';
                chartOptions.scales = {
                    x: buildYAxis(format, options.beginAtZero),
                    y: {
                        grid: {
                            display: false
                        }
                    }
                };
            }

            if (options.secondaryAxis === 'currency') {
                chartOptions.scales.yCurrency = buildYAxis('currency', true);
                chartOptions.scales.yCurrency.position = 'right';
                chartOptions.scales.yCurrency.grid = {
                    drawOnChartArea: false
                };
            }
        }

        return new Chart(canvas, {
            type: type,
            data: {
                labels: chart.labels || [],
                datasets: datasets
            },
            options: chartOptions
        });
    }

    document.querySelectorAll('.report-chart-canvas[data-chart-id]').forEach(function (canvas) {
        const chartId = canvas.getAttribute('data-chart-id') || '';
        if (!chartId || !reportCharts[chartId]) {
            return;
        }

        createChart(canvas, reportCharts[chartId]);
    });
});
