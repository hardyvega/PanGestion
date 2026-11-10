function normalizePermissionSearch(value) {
    return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es').trim();
}

function permissionMatches(row, query, filter) {
    const checkbox = row.querySelector('input[type="checkbox"]');
    const haystack = normalizePermissionSearch(`${row.dataset.permissionName} ${row.dataset.permissionDescription} ${row.dataset.permissionCode}`);
    return query.split(/\s+/).every((word) => haystack.includes(word))
        && (filter !== 'assigned' || checkbox.checked)
        && (filter !== 'unassigned' || !checkbox.checked);
}

function initializeRolePermissions(root) {
    const form = root.querySelector('[data-role-permission-form]');
    if (!form) return;
    const search = form.querySelector('[data-permission-search]');
    const filters = [...form.querySelectorAll('[data-permission-filter]')];
    const groups = [...form.querySelectorAll('[data-permission-category]')];
    const rows = [...form.querySelectorAll('[data-role-permission]')];
    const saved = new Set(JSON.parse(root.dataset.savedIds).map(String));
    const openBeforeFilter = new Map();
    const feedback = root.querySelector('[data-permission-feedback]');
    const recovery = root.querySelector('[data-permission-recovery]');
    const recheck = root.querySelector('[data-permission-recheck]');
    const button = form.querySelector('[data-save-role-permissions]');
    let needsSessionCheck = false;
    let filtering = false;
    let submitting = false;

    function notify(message, success = false, focus = true) {
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.classList.toggle('is-success', success);
        if (focus) feedback.focus();
    }

    function busy(value) {
        submitting = value;
        form.inert = value;
        button.disabled = value || needsSessionCheck;
        button.setAttribute('aria-busy', String(value));
        button.textContent = value ? 'Guardando…' : 'Guardar permisos';
    }

    function sessionFailure(status) {
        needsSessionCheck = true;
        recovery.hidden = false;
        notify(status === 419
            ? 'El formulario ya no tiene una verificación de seguridad válida (CSRF). Los cambios no se han guardado. Comprueba tu sesión antes de continuar.'
            : 'Tu sesión ha expirado por seguridad. Debes iniciar sesión nuevamente. Los cambios todavía no se han guardado.');
    }

    async function readResponse(response) {
        if (response.redirected) { sessionFailure(401); return null; }
        if (response.status === 401 || response.status === 419) { sessionFailure(response.status); return null; }
        if (response.status === 403) {
            notify('No tienes autorización para guardar permisos. Los cambios no se han guardado.');
            return null;
        }
        if (response.status === 422) {
            const data = await response.json();
            const errors = Object.values(data.errors || {}).flat();
            notify(`No se guardaron los cambios. ${errors.length ? errors.join(' ') : data.message || 'Revisa la selección.'}`);
            return null;
        }
        if (!response.ok) throw new Error('server');
        return response.json();
    }

    function update() {
        const query = normalizePermissionSearch(search.value);
        const filter = filters.find((input) => input.checked)?.value || 'all';
        const hasFilter = query !== '' || filter !== 'all';
        if (hasFilter && !filtering) groups.forEach((group) => openBeforeFilter.set(group, group.open));
        const selected = rows.filter((row) => row.querySelector('input').checked);
        let visibleCount = 0;
        const categories = new Map();

        groups.forEach((group) => {
            const options = [...group.querySelectorAll('[data-role-permission]')];
            let visible = 0;
            let assigned = 0;
            options.forEach((row) => {
                row.hidden = !permissionMatches(row, query, filter);
                if (!row.hidden) visible++;
                if (row.querySelector('input').checked) assigned++;
            });
            visibleCount += visible;
            group.hidden = visible === 0;
            if (hasFilter && visible > 0) group.open = true;
            else if (!hasFilter && filtering) group.open = openBeforeFilter.get(group) || false;
            group.querySelector('[data-category-count]').textContent = `${assigned} seleccionados / ${options.length} disponibles`;
            if (assigned > 0) categories.set(group.dataset.categoryLabel, assigned);
        });
        filtering = hasFilter;
        root.querySelector('[data-permission-result-count]').textContent = `${visibleCount} de ${rows.length} permisos`;
        root.querySelector('[data-permission-no-results]').hidden = visibleCount > 0;
        root.querySelector('[data-selected-total]').textContent = String(selected.length);
        root.querySelector('[data-selected-empty]').hidden = selected.length > 0;
        root.querySelector('[data-selection-preview]').hidden = selected.length === 0;
        const categoryList = root.querySelector('[data-selected-categories]');
        categoryList.replaceChildren();
        categories.forEach((count, label) => {
            const item = document.createElement('li');
            const title = document.createElement('span');
            title.textContent = label;
            const amount = document.createElement('strong');
            amount.textContent = String(count);
            item.append(title, amount);
            categoryList.append(item);
        });
        const selectionList = root.querySelector('[data-selected-permissions]');
        selectionList.replaceChildren();
        selected.forEach((row) => {
            const item = document.createElement('li');
            const name = document.createElement('strong');
            name.textContent = row.dataset.permissionName;
            const description = document.createElement('small');
            description.textContent = row.dataset.permissionDescription;
            item.append(name, description);
            selectionList.append(item);
        });
        const dirty = selected.length !== saved.size || selected.some((row) => !saved.has(row.querySelector('input').value));
        const status = root.querySelector('[data-permission-save-status]');
        status.textContent = dirty ? 'Cambios sin guardar' : 'Sin cambios pendientes';
        status.classList.toggle('has-changes', dirty);
        if (document.activeElement?.closest('[data-role-permission]')?.hidden) search.focus();
    }

    search.addEventListener('input', () => { if (!submitting) update(); });
    form.addEventListener('change', (event) => {
        if (!submitting && event.target.matches('input[type="checkbox"], [data-permission-filter]')) update();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting || needsSessionCheck) return;
        // Capture the whole form, including checked inputs hidden by filters or details.
        const body = new FormData(form);
        const selected = body.getAll('permissions[]').map(String);
        busy(true);
        try {
            const response = await fetch(form.action, {
                method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, redirect: 'error',
            });
            const data = await readResponse(response);
            if (data) {
                if (data.saved !== true) throw new Error('unconfirmed');
                saved.clear();
                selected.forEach((id) => saved.add(id));
                update();
                notify('Permisos del rol actualizados correctamente.', true);
            }
        } catch {
            notify('No se pudo confirmar el guardado. La selección sigue en esta pestaña. Comprueba la conexión y recarga el rol en otra pestaña antes de reintentar.');
        } finally {
            busy(false);
        }
    });

    recheck.addEventListener('click', async () => {
        if (submitting) return;
        busy(true);
        recheck.disabled = true;
        try {
            const response = await fetch(root.dataset.sessionUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, redirect: 'error',
            });
            const data = await readResponse(response);
            if (data) {
                if (String(data.user_id) !== root.dataset.userId || String(data.role_id) !== root.dataset.roleId) {
                    rows.forEach((row) => { row.querySelector('input').checked = false; });
                    update();
                    notify('La cuenta activa cambió. La selección se descartó por seguridad. Cierra esta pestaña y vuelve a abrir el rol con tu cuenta.');
                    return;
                }
                if (typeof data.csrf_token !== 'string' || !data.csrf_token) throw new Error('session');
                document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = data.csrf_token; });
                document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.csrf_token);
                needsSessionCheck = false;
                recovery.hidden = true;
                notify('Sesión verificada. Revisa la selección y pulsa Guardar permisos para confirmar.');
            }
        } catch {
            notify('No se pudo comprobar la sesión. Los cambios no se han reenviado.');
        } finally {
            recheck.disabled = false;
            busy(false);
        }
    });

    // A local warning only: no keep-alive requests and no session extension.
    const lifetimeMs = Math.max(1, Number(root.dataset.sessionMinutes)) * 60000;
    const warningAt = Date.now() + lifetimeMs - Math.min(120000, lifetimeMs / 4);
    let warned = false;
    const warnIfIdle = () => {
        if (!warned && !submitting && !needsSessionCheck && Date.now() >= warningAt) {
            warned = true;
            notify('Llevas tiempo en esta pantalla. Tu sesión puede vencer por seguridad. Guarda los cambios antes de continuar.', false, false);
        }
    };
    window.setTimeout(warnIfIdle, Math.max(0, warningAt - Date.now()));
    document.addEventListener('visibilitychange', warnIfIdle);
    update();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-role-permissions]').forEach(initializeRolePermissions);
});
