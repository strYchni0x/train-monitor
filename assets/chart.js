/* German Regional Train Monitor - weekday picker for the hourly delay chart. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.trainmon-chart-section').forEach(function (section) {
            var picker = section.querySelector('.trainmon-weekday-picker');
            var select = section.querySelector('.trainmon-weekday-select');
            if (!picker || !select) { return; }
            picker.hidden = false;
            select.addEventListener('change', function () {
                section.querySelectorAll('[data-trainmon-weekday]').forEach(function (variant) {
                    variant.hidden = variant.getAttribute('data-trainmon-weekday') !== select.value;
                });
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
