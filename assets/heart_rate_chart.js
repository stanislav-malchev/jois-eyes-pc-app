import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const raw = document.getElementById('chart-data');
const canvas = document.getElementById('heartRateChart');

if (raw && canvas) {
    const points = JSON.parse(raw.textContent || '[]');

    if (points.length > 0) {
        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: points.map((p) => p.label),
                datasets: [{
                    label: 'BPM',
                    data: points.map((p) => p.value),
                    borderColor: '#dc3545',
                    backgroundColor: '#dc3545',
                    pointRadius: points.length > 60 ? 0 : 3,
                    tension: 0.2,
                    spanGaps: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => (ctx.parsed.y === null ? 'No reading' : `${ctx.parsed.y} BPM`),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { ticks: { precision: 0 } },
                },
            },
        });
    }
}
