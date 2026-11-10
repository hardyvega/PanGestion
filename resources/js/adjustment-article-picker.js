const limit = 12;
const normalize = (value) => value.trim().toLowerCase();

document.querySelectorAll('form[data-adjustment-form]').forEach((form) => {
    const select = form.querySelector('[name="articulo_id"]');
    const fallback = form.querySelector('[data-adjustment-fallback]');
    const search = form.querySelector('[data-adjustment-search]');
    const input = search.querySelector('[role="combobox"]');
    const list = search.querySelector('[role="listbox"]');
    const status = search.querySelector('[role="status"]');
    const clear = search.querySelector('[data-adjustment-clear]');
    const articles = Array.from(select.options).filter((option) => option.value).map((option) => ({
        id: option.value,
        ...option.dataset,
        keys: [option.dataset.name, option.dataset.sku, option.dataset.barcode].map(normalize),
    }));

    let results = [];
    let active = -1;
    const missingSelection = 'Selecciona un artículo de las sugerencias antes de registrar el ajuste.';

    const details = (article) => [
        'SKU ' + article.sku,
        article.stockLabel ? 'Stock ' + article.stockLabel : article.unit,
    ].filter(Boolean).join(' · ');

    function close() {
        list.hidden = true;
        list.replaceChildren();
        results = [];
        active = -1;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function selectArticle(article) {
        select.value = article.id;
        // Preserve a scanned barcode as text, even after choosing its match.
        if (!article.barcode || normalize(input.value) !== article.keys[2]) input.value = article.name;
        input.setCustomValidity('');
        input.removeAttribute('aria-invalid');
        close();
        status.textContent = 'Seleccionado: ' + article.name + ' · ' + details(article);
    }

    function suggest() {
        close();
        const term = normalize(input.value);
        if (!term) {
            status.textContent = '';
            return;
        }

        const exact = [];
        const partial = [];
        let count = 0;
        for (const article of articles) {
            if (!article.keys.some((key) => key.includes(term))) continue;
            count++;
            const group = article.keys.includes(term) ? exact : partial;
            if (group.length < limit) group.push(article);
        }
        results = exact.concat(partial).slice(0, limit);
        status.textContent = count === 0 ? 'No se encontraron artículos.'
            : count > limit ? 'Primeras ' + limit + ' coincidencias de ' + count + '. Acota la búsqueda.'
            : count === 1 ? '1 coincidencia.' : count + ' coincidencias.';

        results.forEach((article, index) => {
            const option = document.createElement('div');
            option.id = list.id + '-' + index;
            option.className = 'picker-result';
            option.dataset.index = index;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            const text = document.createElement('span');
            const name = document.createElement('strong');
            const meta = document.createElement('small');
            name.textContent = article.name;
            meta.textContent = details(article);
            text.append(name, meta);
            option.append(text);
            list.append(option);
        });
        list.hidden = results.length === 0;
        input.setAttribute('aria-expanded', String(!list.hidden));
    }

    input.addEventListener('input', () => {
        select.value = '';
        input.setCustomValidity(missingSelection);
        input.removeAttribute('aria-invalid');
        suggest();
    });
    input.addEventListener('focus', () => { if (!select.value) suggest(); });
    input.addEventListener('blur', close);
    input.addEventListener('invalid', () => {
        input.setAttribute('aria-invalid', 'true');
        status.textContent = missingSelection;
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            const match = results[active] ?? (results.length === 1 ? results[0] : null);
            if (match && !event.isComposing) selectArticle(match);
        } else if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (list.hidden && !select.value) suggest();
            if (!results.length) return;
            event.preventDefault();
            const next = active === -1 ? (event.key === 'ArrowDown' ? 0 : results.length - 1)
                : Math.min(results.length - 1, Math.max(0, active + (event.key === 'ArrowDown' ? 1 : -1)));
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
        if (results[index]) {
            selectArticle(results[index]);
            input.focus();
        }
    });
    clear.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input'));
        input.focus();
    });
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('input')) event.preventDefault();
    });
    form.addEventListener('submit', (event) => {
        if (select.value) return;
        event.preventDefault();
        input.reportValidity();
    });

    const previous = articles.find((article) => article.id === select.value);
    if (previous) selectArticle(previous);
    else input.setCustomValidity(missingSelection);
    select.required = false;
    input.disabled = false;
    fallback.hidden = true;
    search.hidden = false;
});
