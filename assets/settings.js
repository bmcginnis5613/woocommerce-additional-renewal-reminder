(function () {
    'use strict';

    function combineTimingFields() {
        var number = document.getElementById('ceg_second_renewal_reminder_number');
        var unit = document.getElementById('ceg_second_renewal_reminder_unit');
        if (!number || !unit || document.getElementById('ceg-second-reminder-timing')) {
            return;
        }
        var numberRow = number.closest('tr');
        var unitRow = unit.closest('tr');
        if (!numberRow || !unitRow || numberRow === unitRow) {
            return;
        }

        var pair = document.createElement('div');
        pair.id = 'ceg-second-reminder-timing';
        pair.className = 'ceg-second-reminder-timing';
        var numberColumn = document.createElement('div');
        var unitColumn = document.createElement('div');
        numberColumn.className = unitColumn.className = 'ceg-second-reminder-timing__field';
        number.parentNode.insertBefore(pair, number);
        pair.appendChild(numberColumn);
        pair.appendChild(unitColumn);
        numberColumn.appendChild(number);
        unitColumn.appendChild(unit);
        unit.setAttribute('aria-label', 'Second reminder unit');
        // Keep original names and values so WooCommerce saves the same settings.
        unitRow.style.setProperty('display', 'none', 'important');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', combineTimingFields);
    } else {
        combineTimingFields();
    }
}());
