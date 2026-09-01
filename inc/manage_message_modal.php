<div class="modal-backdrop" id="manage-message-modal" hidden aria-hidden="true">
    <div class="modal-card modal-card--message" role="dialog" aria-modal="true" aria-labelledby="manage-message-title" aria-describedby="manage-message-text">
        <div class="modal-head">
            <h2 class="modal-title" id="manage-message-title">Сообщение</h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Закрыть"></button>
        </div>
        <div class="modal-body">
            <p class="modal-message" id="manage-message-text"></p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-primary" data-modal-close>Закрыть</button>
        </div>
    </div>
</div>
<script>
(() => {
    const modal = document.getElementById('manage-message-modal');
    if (!modal) {
        return;
    }

    const title = document.getElementById('manage-message-title');
    const text = document.getElementById('manage-message-text');
    let previousFocus = null;

    const close = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    };

    const show = (message, type = 'info', heading = '') => {
        const value = String(message || '').trim();
        if (value === '') {
            return;
        }

        previousFocus = document.activeElement;
        modal.classList.toggle('modal-backdrop--error', type === 'error');
        modal.classList.toggle('modal-backdrop--success', type === 'success');
        title.textContent = heading || (type === 'error' ? 'Ошибка' : (type === 'success' ? 'Готово' : 'Сообщение'));
        text.textContent = value;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        const closeButton = modal.querySelector('[data-modal-close]');
        if (closeButton) {
            closeButton.focus();
        }
    };

    modal.addEventListener('click', (event) => {
        if (event.target === modal || event.target.closest('[data-modal-close]')) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });

    window.ScheduleModal = {show, close};

    const notice = document.querySelector('.notice--error:not([data-inline-notice]), .notice--success:not([data-inline-notice])');
    if (notice) {
        const type = notice.classList.contains('notice--error') ? 'error' : 'success';
        const message = notice.textContent || '';
        notice.remove();
        show(message, type);
    }
})();
</script>
