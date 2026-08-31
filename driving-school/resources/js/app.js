import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
window.Chart = Chart;

/**
 * Renders the dashboard charts declared with <canvas data-chart="...">.
 * Every data point comes from the server-rendered JSON in data-series.
 */
function renderCharts() {
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        if (canvas.dataset.rendered) return;
        canvas.dataset.rendered = '1';

        const type = canvas.dataset.chart;
        const series = JSON.parse(canvas.dataset.series || '[]');
        const labels = series.map((point) => point.label);
        const brand = '#3366f2';

        const datasets = type === 'income'
            ? [
                { label: canvas.dataset.labelIncome || 'Income', data: series.map((p) => p.income), backgroundColor: '#10b981', borderRadius: 4 },
                { label: canvas.dataset.labelExpenses || 'Expenses', data: series.map((p) => p.expenses), backgroundColor: '#f43f5e', borderRadius: 4 },
            ]
            : [
                {
                    label: canvas.dataset.label || 'Present',
                    data: series.map((p) => p.value),
                    borderColor: brand,
                    backgroundColor: 'rgba(51, 102, 242, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                },
            ];

        new Chart(canvas, {
            type: type === 'income' ? 'bar' : 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: type === 'income' } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.2)' } },
                    x: { grid: { display: false } },
                },
            },
        });
    });
}

document.addEventListener('DOMContentLoaded', renderCharts);

Alpine.start();
