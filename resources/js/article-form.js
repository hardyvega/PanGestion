document.querySelectorAll('form[data-article-form]').forEach((form) => {
    let submitting = false;

    form.querySelector('[name="codigo_barras"]')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
    });
});
