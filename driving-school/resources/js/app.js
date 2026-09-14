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


/**
 * The live training board.
 *
 * The countdown is rendered from the server's expected_end_at, so the clock a
 * teacher sees is the database's clock: refreshing the page, or opening a
 * second screen, shows the same number. A short poll keeps the rest of the
 * board current without anyone pressing refresh, and the same payload is what
 * a websocket would deliver if broadcasting is switched on.
 */
window.trainingBoard = function (config) {
    return {
        board: config.initial || {},
        endpoint: config.endpoint,
        pollMs: config.pollMs || 4000,
        remaining: 0,
        // Ticks every second so every teacher panel recomputes its own clock.
        now: Date.now(),
        toasts: [],
        warned: {},
        lastSignature: '',

        init() {
            this.syncFromBoard();
            setInterval(() => this.tick(), 1000);
            setInterval(() => this.refresh(), this.pollMs);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.refresh();
            });
        },

        /** Seconds left, recomputed from the server timestamp every second. */
        tick() {
            this.now = Date.now();
            const current = this.board.current;
            if (!current || !current.expected_end_at) { this.remaining = 0; return; }
            if (current.status === 'paused') { this.remaining = current.remaining_seconds; return; }
            if (current.status !== 'in_progress') { this.remaining = 0; return; }

            const left = Math.max(0, Math.round((new Date(current.expected_end_at) - Date.now()) / 1000));
            this.remaining = left;

            if (left <= 300 && left > 0 && !this.warned[current.id + ':5m']) {
                this.warned[current.id + ':5m'] = true;
                this.notify('warning', `⚠️ ${current.student}'s training ends in 5 minutes.`);
            }
            // The server ends it too; refreshing promptly keeps the UI honest.
            if (left === 0 && !this.warned[current.id + ':end']) {
                this.warned[current.id + ':end'] = true;
                this.notify('info', `🔔 ${current.student}'s training has ended. Attendance is required.`);
                this.refresh();
            }
        },

        async refresh() {
            try {
                const response = await fetch(this.endpoint, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const fresh = await response.json();
                this.announce(fresh);
                this.board = fresh;
                this.syncFromBoard();
            } catch (error) {
                // A dropped poll is not worth interrupting the teacher for.
            }
        },

        /** Tells the user what changed since the last poll. */
        announce(fresh) {
            const before = this.board.current;
            const after = fresh.current;

            if (after && (!before || before.id !== after.id)) {
                this.notify('success', `🟢 ${after.student} — training in progress.`);
            }
            if (before && !after) {
                this.notify('info', '✅ Training completed.');
            }
            if (after && before && before.status !== after.status && after.status === 'attendance_pending') {
                this.notify('info', `🔵 ${after.student} — attendance pending.`);
            }

            const signature = (fresh.queue || []).map((q) => q.id).join(',');
            if (this.lastSignature && signature !== this.lastSignature) {
                this.notify('info', '🟡 Queue updated.');
            }
            this.lastSignature = signature;
        },

        syncFromBoard() {
            this.remaining = this.board.current ? this.board.current.remaining_seconds : 0;
            this.lastSignature = (this.board.queue || []).map((q) => q.id).join(',');
        },

        notify(tone, message) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, tone, message });
            setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, 6000);
        },

        /**
         * mm:ss for any session, from its server-side expected_end_at. Used by
         * the admin board, where several teachers each have their own clock.
         * A student who is only waiting has no session and therefore no clock.
         */
        clockFor(session) {
            if (!session || !session.expected_end_at) return '00:00';

            const left = session.status === 'in_progress'
                ? Math.max(0, Math.round((new Date(session.expected_end_at) - this.now) / 1000))
                : session.remaining_seconds;

            return `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
        },

        get clock() {
            const minutes = Math.floor(this.remaining / 60);
            const seconds = this.remaining % 60;
            return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        },

        /** Fraction of the session elapsed, for the progress ring. */
        get elapsedPercent() {
            const total = (this.board.current?.total_minutes || 0) * 60;
            if (!total) return 0;
            return Math.min(100, Math.max(0, ((total - this.remaining) / total) * 100));
        },

        get isUrgent() {
            return this.remaining > 0 && this.remaining <= 300;
        },
    };
};

Alpine.start();
