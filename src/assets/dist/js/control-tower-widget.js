/**
 * Control Tower — Widget auto-refresh
 */
(function () {
    'use strict';

    const ControlTowerWidget = {
        timer: null,

        init: function () {
            document.querySelectorAll('.ct-widget').forEach(widget => {
                const interval = parseInt(widget.dataset.refreshInterval || '30', 10) * 1000;

                setInterval(() => {
                    this.refreshWidget(widget);
                }, interval);
            });
        },

        refreshWidget: function (widget) {
            const url = Craft.getActionUrl('control-tower/api/widget');

            fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': Craft.csrfTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(data => {
                    this.updateWidget(widget, data);
                })
                .catch(err => {
                    console.warn('Control Tower widget refresh failed:', err);
                });
        },

        updateWidget: function (widget, data) {
            this.setStat(widget, 'ct-w-visitors', data.visitors);
            this.setStat(widget, 'ct-w-editors', data.editors);
            this.setStat(widget, 'ct-w-queue-waiting', data.queueSummary?.waiting);
            this.setStat(widget, 'ct-w-queue-failed', data.queueSummary?.failed);
            this.setStat(widget, 'ct-w-alerts', data.alertCount);

            // Update health badges
            const queueBadge = widget.querySelector('#ct-w-queue-health');
            if (queueBadge && data.queueHealth) {
                queueBadge.className = 'ct-badge ct-badge--' + data.queueHealth;
            }

            const serverBadge = widget.querySelector('#ct-w-server-health');
            if (serverBadge && data.serverHealth) {
                serverBadge.className = 'ct-badge ct-badge--' + data.serverHealth;
            }
        },

        setStat: function (widget, id, value) {
            const el = widget.querySelector('#' + id);
            if (el && value !== undefined && value !== null) {
                el.textContent = typeof value === 'number' ? value.toLocaleString() : value;
            }
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ControlTowerWidget.init());
    } else {
        ControlTowerWidget.init();
    }
})();
