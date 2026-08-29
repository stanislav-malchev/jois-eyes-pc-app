import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const raw = document.getElementById('chart-data');
const canvas = document.getElementById('stepsChart');

if (raw && canvas) {
    const days = JSON.parse(raw.textContent || '[]');

    if (days.length > 0) {
        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: days.map((d) => d.label),
                datasets: [{
                    label: 'Steps',
                    data: days.map((d) => d.steps),
                    backgroundColor: '#0d6efd',
                    borderRadius: 4,
                    maxBarThickness: 48,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.parsed.y.toLocaleString()} steps`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        });
    }
}
