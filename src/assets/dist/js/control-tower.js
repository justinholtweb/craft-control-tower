/**
 * Control Tower — CP section live polling
 *
 * All dynamic HTML uses escapeHtml() which delegates to textContent-based encoding.
 * Data comes from authenticated CP API endpoints only (not user input).
 */
(function () {
    'use strict';

    const CT = {
        refreshInterval: null,
        timer: null,
        currentTab: null,

        init: function () {
            const container = document.getElementById('ct-dashboard');
            if (!container) return;

            this.refreshInterval = parseInt(container.dataset.refreshInterval || '30', 10) * 1000;
            this.currentTab = container.dataset.tab || 'overview';

            // Period selector buttons
            document.querySelectorAll('.ct-period-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const group = e.target.closest('.ct-toolbar');
                    group.querySelectorAll('.ct-period-btn').forEach(b => b.classList.remove('active'));
                    e.target.classList.add('active');
                    this.refresh(e.target.dataset.period);
                });
            });

            // Start auto-refresh
            this.startPolling();
        },

        startPolling: function () {
            if (this.timer) clearInterval(this.timer);
            this.timer = setInterval(() => this.refresh(), this.refreshInterval);
        },

        refresh: function (period) {
            const indicator = document.querySelector('.ct-refreshing');
            if (indicator) indicator.classList.add('active');

            let url = Craft.getActionUrl('control-tower/api/' + this.currentTab);
            if (period) {
                url += '?period=' + encodeURIComponent(period);
            }

            fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(response => response.json())
                .then(data => {
                    this.updateDashboard(data);
                })
                .catch(err => {
                    console.warn('Control Tower refresh failed:', err);
                })
                .finally(() => {
                    if (indicator) indicator.classList.remove('active');
                });
        },

        updateDashboard: function (data) {
            // Update stat card values via textContent (safe)
            this.updateStat('ct-visitors-count', data.visitors ?? data.count);
            this.updateStat('ct-editors-count', data.editors ?? data.count);
            this.updateStat('ct-queue-waiting', data.queueSummary?.waiting);
            this.updateStat('ct-queue-failed', data.queueSummary?.failed);
            this.updateStat('ct-alert-count', data.alertCount ?? data.count);
            this.updateStat('ct-rpm', data.requestsPerMinute);

            // Update health badges
            this.updateBadge('ct-queue-health', data.queueSummary?.healthy !== false ? (data.queueHealth || 'healthy') : 'critical');
            this.updateBadge('ct-server-health', data.serverHealth ?? data.health);

            // Update editors table (DOM-based, safe)
            if (data.editors || data.editors_list) {
                this.updateEditorsTable(data.editors || data.editors_list);
            }

            // Update alerts list (DOM-based, safe)
            if (data.activeAlerts || data.active) {
                this.updateAlertsList(data.activeAlerts || data.active);
            }
        },

        updateStat: function (id, value) {
            const el = document.getElementById(id);
            if (el && value !== undefined && value !== null) {
                el.textContent = typeof value === 'number' ? value.toLocaleString() : value;
            }
        },

        updateBadge: function (id, health) {
            const el = document.getElementById(id);
            if (!el || !health) return;

            el.className = 'ct-badge ct-badge--' + health;
            const label = el.querySelector('.ct-badge__label');
            if (label) {
                label.textContent = health.charAt(0).toUpperCase() + health.slice(1);
            }
        },

        updateEditorsTable: function (editors) {
            const tbody = document.getElementById('ct-editors-tbody');
            if (!tbody) return;

            // Clear existing rows
            while (tbody.firstChild) {
                tbody.removeChild(tbody.firstChild);
            }

            if (!editors.length) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.setAttribute('colspan', '4');
                td.className = 'ct-empty';
                td.textContent = 'No active editors';
                tr.appendChild(td);
                tbody.appendChild(tr);
                return;
            }

            editors.forEach(editor => {
                const tr = document.createElement('tr');

                // Name cell with avatar
                const tdName = document.createElement('td');
                const editorRow = document.createElement('div');
                editorRow.className = 'ct-editor-row';

                const initial = (editor.userName || '?')[0].toUpperCase();
                if (editor.photoUrl) {
                    const img = document.createElement('img');
                    img.className = 'ct-avatar';
                    img.src = editor.photoUrl;
                    img.alt = '';
                    editorRow.appendChild(img);
                } else {
                    const span = document.createElement('span');
                    span.className = 'ct-avatar ct-avatar--placeholder';
                    span.textContent = initial;
                    editorRow.appendChild(span);
                }

                const info = document.createElement('div');
                info.className = 'ct-editor-info';
                const nameSpan = document.createElement('span');
                nameSpan.className = 'ct-editor-name';
                nameSpan.textContent = editor.userName || 'Unknown';
                const actionSpan = document.createElement('span');
                actionSpan.className = 'ct-editor-action';
                actionSpan.textContent = editor.action || 'browsing';
                info.appendChild(nameSpan);
                info.appendChild(actionSpan);
                editorRow.appendChild(info);
                tdName.appendChild(editorRow);

                // Section cell
                const tdSection = document.createElement('td');
                tdSection.textContent = editor.sectionHandle || '\u2014';

                // Element cell
                const tdElement = document.createElement('td');
                tdElement.textContent = editor.elementId ? '#' + editor.elementId : '\u2014';

                // Time cell
                const tdTime = document.createElement('td');
                tdTime.textContent = this.timeAgo(editor.lastSeenAt);

                tr.appendChild(tdName);
                tr.appendChild(tdSection);
                tr.appendChild(tdElement);
                tr.appendChild(tdTime);
                tbody.appendChild(tr);
            });
        },

        updateAlertsList: function (alerts) {
            const container = document.getElementById('ct-alerts-list');
            if (!container) return;

            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }

            if (!alerts.length) {
                const empty = document.createElement('div');
                empty.className = 'ct-empty';
                empty.textContent = 'No active alerts';
                container.appendChild(empty);
                return;
            }

            alerts.forEach(alert => {
                const item = document.createElement('div');
                item.className = 'ct-alert-item';

                const icon = document.createElement('span');
                icon.className = 'ct-alert-icon ct-alert-icon--' + (alert.severity || 'info');
                icon.textContent = (alert.severity === 'critical' || alert.severity === 'warning') ? '!' : 'i';

                const content = document.createElement('div');
                const msg = document.createElement('div');
                msg.className = 'ct-alert-message';
                msg.textContent = alert.message;
                const time = document.createElement('div');
                time.className = 'ct-alert-time';
                time.textContent = this.timeAgo(alert.createdAt);

                content.appendChild(msg);
                content.appendChild(time);
                item.appendChild(icon);
                item.appendChild(content);
                container.appendChild(item);
            });
        },

        timeAgo: function (dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

            if (seconds < 60) return 'just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
            return Math.floor(seconds / 86400) + 'd ago';
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => CT.init());
    } else {
        CT.init();
    }
})();
