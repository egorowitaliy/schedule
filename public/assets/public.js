(() => {
    const media = window.matchMedia('(max-width: 760px)');
    const selectors = Array.from(document.querySelectorAll('select[data-group-select]'));
    const forms = Array.from(document.querySelectorAll('form[data-schedule-filter]'));

    const syncSelector = (select) => {
        const allOption = select.querySelector('option[data-all-groups]');
        if (!allOption) {
            return;
        }

        if (media.matches) {
            allOption.textContent = 'Выберите группу';
            allOption.value = '';
            allOption.disabled = true;
        } else {
            allOption.textContent = 'Все группы';
            allOption.value = '0';
            allOption.disabled = false;
        }
    };

    const syncAll = () => {
        selectors.forEach(syncSelector);
    };

    syncAll();

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', syncAll);
    } else if (typeof media.addListener === 'function') {
        media.addListener(syncAll);
    }

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!media.matches) {
                return;
            }

            const select = form.querySelector('select[data-group-select]');
            if (!select || select.value !== '') {
                return;
            }

            event.preventDefault();
            select.setCustomValidity('Выберите группу');
            select.reportValidity();
            select.focus();
        });
    });

    selectors.forEach((select) => {
        select.addEventListener('change', () => {
            select.setCustomValidity('');
        });
    });
})();
