document.querySelectorAll('[data-movement-article-picker]').forEach((picker) => {
    const input = picker.querySelector('[name="q"]');
    const selectedId = picker.querySelector('[name="articulo_id"]');
    const clear = picker.querySelector('[data-movement-article-clear]');
    const popup = picker.querySelector('.movement-article-popup');
    const list = picker.querySelector('[role="listbox"]');
    const status = picker.querySelector('[role="status"]');
    let timer;
    let request;
    let results = [];
    let active = -1;

    function close() {
        clearTimeout(timer);
        timer = null;
        request?.abort();
        request = null;
        results = [];
        active = -1;
        popup.hidden = true;
        list.hidden = true;
        list.replaceChildren();
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function choose(article) {
        input.value = article.nombre;
        selectedId.value = article.id;
        selectedId.disabled = false;
        close();
        input.focus();
    }

    function show(message) {
        popup.hidden = false;
        status.textContent = message;
        input.setAttribute('aria-expanded', String(!list.hidden));
    }

    function suggest() {
        close();
        const term = input.value.trim();
        if (term.length < 2) return;

        timer = setTimeout(async () => {
            timer = null;
            const pending = new AbortController();
            request = pending;
            show('Buscando artículos…');
            try {
                const url = new URL(picker.dataset.movementArticlePicker, window.location.href);
                url.searchParams.set('q', term);
                const response = await fetch(url, { signal: pending.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('lookup');
                const { data } = await response.json();
                if (request !== pending) return;
                results = data;
                results.forEach((article, index) => {
                    const option = document.createElement('div');
                    option.id = list.id + '-' + index;
                    option.className = 'picker-result';
                    option.dataset.index = index;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    const text = document.createElement('span');
                    const name = document.createElement('strong');
                    const sku = document.createElement('small');
                    name.textContent = article.nombre;
                    sku.textContent = 'SKU ' + article.sku;
                    text.append(name, sku);
                    option.append(text);
                    list.append(option);
                });
                list.hidden = results.length === 0;
                show(results.length ? results.length + (results.length === 1 ? ' sugerencia.' : ' sugerencias.') : 'No se encontraron artículos.');
            } catch {
                if (request === pending) show('No se pudieron cargar las sugerencias. Puedes usar Filtrar.');
            }
        }, 250);
    }

    input.addEventListener('input', () => {
        selectedId.value = '';
        selectedId.disabled = true;
        suggest();
    });
    input.addEventListener('focus', () => { if (!selectedId.value) suggest(); });
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            const article = results[active] ?? (results.length === 1 ? results[0] : null);
            if (article && !event.isComposing) choose(article);
        } else if (event.key === 'Escape' && (!popup.hidden || timer)) {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (popup.hidden && !selectedId.value) suggest();
            if (!results.length) return;
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const next = active === -1 ? (step > 0 ? 0 : results.length - 1)
                : Math.min(results.length - 1, Math.max(0, active + step));
            list.children[active]?.setAttribute('aria-selected', 'false');
            active = next;
            const option = list.children[active];
            option.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        }
    });
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const index = event.target.closest('[role="option"]')?.dataset.index;
        if (results[index]) choose(results[index]);
    });
    clear.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input'));
        input.focus();
    });

    clear.hidden = false;
    selectedId.disabled = !selectedId.value;
});
