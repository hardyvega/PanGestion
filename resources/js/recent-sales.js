const recentSales = document.querySelector('[data-pos-recent-url]');

if (recentSales) {
    const list = recentSales.querySelector('[data-pos-recent-list]');
    const totals = recentSales.querySelector('[data-pos-collected]');
    const cash = totals.querySelector('[data-pos-collected-cash]');
    const digital = totals.querySelector('[data-pos-collected-digital]');
    const status = recentSales.querySelector('[data-pos-recent-status]');
    let lastHtml = list.innerHTML.trim();
    let pending = false;
    let stopped = false;

    const stop = (message) => {
        stopped = true;
        clearInterval(timer);
        list.replaceChildren();
        list.hidden = true;
        cash.textContent = '';
        digital.textContent = '';
        totals.hidden = true;
        status.textContent = message;
    };

    const refresh = async () => {
        if (pending || stopped || document.hidden) return;
        pending = true;
        try {
            const response = await fetch(recentSales.dataset.posRecentUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                redirect: 'manual',
                signal: AbortSignal.timeout(8000),
            });
            if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 419) {
                stop('Tu sesión terminó. Vuelve a iniciar sesión para consultar las ventas.');
                return;
            }
            if (response.status === 403) {
                stop('Ya no tienes permiso para consultar las ventas de esta jornada.');
                return;
            }
            if (response.status === 409) {
                const { message } = await response.json().catch(() => ({}));
                stop(message || 'Caja principal ya no tiene la misma sesión vigente.');
                return;
            }
            if (!response.ok) throw new Error('recent sales');
            const { html, collected } = await response.json();
            if (typeof html !== 'string' || typeof collected?.cash !== 'string' || typeof collected?.digital !== 'string') {
                throw new Error('recent sales');
            }
            const nextHtml = html.trim();
            if (lastHtml !== nextHtml) {
                list.innerHTML = nextHtml;
                lastHtml = nextHtml;
            }
            if (cash.textContent !== collected.cash) cash.textContent = collected.cash;
            if (digital.textContent !== collected.digital) digital.textContent = collected.digital;
            status.textContent = '';
        } catch {
            status.textContent = 'No se pudieron actualizar las ventas. Se volverá a intentar.';
        } finally {
            pending = false;
        }
    };

    const timer = setInterval(refresh, 10000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) void refresh();
    });
}
