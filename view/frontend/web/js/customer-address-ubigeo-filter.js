define([], function () {
    'use strict';

    /**
     * Filters the Distrito <select> options down to the ones matching the chosen Provincia,
     * for the customer account "Address Book" form (view/frontend/templates/address/edit.phtml).
     * Plain vanilla JS on purpose: this page has no knockout/UI-component form (unlike checkout),
     * just classic server-rendered <select> elements.
     *
     * @param {Object} config
     * @param {String} config.distritoSelector
     * @param {HTMLSelectElement} provinciaSelect
     */
    return function (config, provinciaSelect) {
        var distritoSelect = document.querySelector(config.distritoSelector);
        if (!distritoSelect) {
            return;
        }

        var distritoOptions = Array.prototype.slice.call(distritoSelect.options);

        function applyFilter() {
            var selectedProvincia = provinciaSelect.value;
            var currentValue = distritoSelect.value;
            var currentStillVisible = false;

            distritoOptions.forEach(function (option) {
                if (option.value === '') {
                    option.hidden = false;
                    return;
                }

                var matches = !selectedProvincia || option.getAttribute('data-provincia') === selectedProvincia;
                option.hidden = !matches;

                if (matches && option.value === currentValue) {
                    currentStillVisible = true;
                }
            });

            if (!currentStillVisible) {
                distritoSelect.value = '';
            }
        }

        applyFilter();

        provinciaSelect.addEventListener('change', applyFilter);
    };
});
