const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/recent-sales.js'), 'utf8');
const success = (html, collected = { cash: '$800', digital: '$2.000' }) => ({ ok: true, json: async () => ({ html, collected }) });

function mount(fetch, available = true) {
    let html = '<li>Venta 1</li>';
    const list = {
        updates: 0,
        get innerHTML() { return html; },
        set innerHTML(value) { this.updates++; html = value; },
        replaceChildren() { html = ''; },
    };
    const status = { textContent: '' };
    const cash = { textContent: '$0' };
    const digital = { textContent: '$0' };
    const totals = {
        hidden: false,
        querySelector: (selector) => selector === '[data-pos-collected-cash]' ? cash : digital,
    };
    const widget = {
        dataset: { posRecentUrl: '/ventas/recientes/1' },
        querySelector: (selector) => ({
            '[data-pos-recent-list]': list,
            '[data-pos-recent-status]': status,
            '[data-pos-collected]': totals,
        })[selector],
    };
    const listeners = {};
    const document = {
        hidden: false,
        querySelector: () => available ? widget : null,
        addEventListener: (event, handler) => { listeners[event] = handler; },
    };
    const timer = {};
    runInNewContext(source, {
        document,
        fetch,
        AbortSignal,
        setInterval: (fn, ms) => { Object.assign(timer, { fn, ms }); return 1; },
        clearInterval: () => { timer.cleared = true; },
    });
    return { document, list, status, cash, digital, totals, timer, listeners };
}

test('refreshes every ten seconds without overlapping or replacing an unchanged list', async () => {
    let release;
    let calls = 0;
    const ui = mount((url, options) => {
        assert.equal(url, '/ventas/recientes/1');
        assert.equal(options.cache, 'no-store');
        assert.equal(options.headers.Accept, 'application/json');
        calls++;
        return new Promise((resolveFetch) => { release = resolveFetch; });
    });
    assert.equal(ui.timer.ms, 10000);
    const pending = ui.timer.fn();
    await ui.timer.fn();
    assert.equal(calls, 1);
    release(success('<li>Venta 2</li><li>Venta 1</li>'));
    await pending;
    assert.equal(ui.list.innerHTML, '<li>Venta 2</li><li>Venta 1</li>');
    assert.equal(ui.cash.textContent, '$800');
    assert.equal(ui.digital.textContent, '$2.000');

    const unchanged = ui.timer.fn();
    release(success(ui.list.innerHTML, { cash: '$800', digital: '$2.500' }));
    await unchanged;
    assert.equal(ui.list.updates, 1);
    assert.equal(ui.digital.textContent, '$2.500');
});

test('skips hidden pages and retries connection errors without clearing existing rows', async () => {
    let calls = 0;
    const ui = mount(async () => {
        if (++calls === 1) throw new Error('offline');
        return success('<li>Venta 2</li>');
    });
    ui.document.hidden = true;
    await ui.timer.fn();
    assert.equal(calls, 0);
    ui.document.hidden = false;
    ui.listeners.visibilitychange();
    await new Promise(setImmediate);
    assert.match(ui.status.textContent, /Se volverá a intentar/);
    assert.equal(ui.list.innerHTML, '<li>Venta 1</li>');
    assert.equal(ui.cash.textContent, '$0');
    await ui.timer.fn();
    assert.equal(ui.status.textContent, '');
    assert.equal(ui.list.innerHTML, '<li>Venta 2</li>');
    assert.equal(ui.cash.textContent, '$800');
});

test('ignores a refresh without a complete collection summary', async () => {
    const ui = mount(async () => success('<li>Venta 2</li>', { cash: '$800' }));
    await ui.timer.fn();
    assert.equal(ui.list.innerHTML, '<li>Venta 1</li>');
    assert.equal(ui.cash.textContent, '$0');
    assert.equal(ui.digital.textContent, '$0');
    assert.match(ui.status.textContent, /Se volverá a intentar/);
});

for (const [response, expected] of [
    [{ status: 401 }, /Tu sesión terminó/],
    [{ status: 403 }, /Ya no tienes permiso/],
    [{ status: 419 }, /Tu sesión terminó/],
    [{ type: 'opaqueredirect' }, /Tu sesión terminó/],
    [{ status: 409, json: async () => ({ message: 'Caja principal ya no está vigente.' }) }, /Caja principal ya no está vigente/],
]) {
    test(`stops and removes sales and totals after ${response.status ?? response.type}`, async () => {
        let calls = 0;
        const ui = mount(async () => { calls++; return response; });
        await ui.timer.fn();
        await ui.timer.fn();
        ui.listeners.visibilitychange();
        assert.equal(ui.timer.cleared, true);
        assert.equal(ui.list.innerHTML, '');
        assert.equal(ui.list.hidden, true);
        assert.equal(ui.totals.hidden, true);
        assert.equal(ui.cash.textContent, '');
        assert.equal(ui.digital.textContent, '');
        assert.match(ui.status.textContent, expected);
        assert.equal(calls, 1);
    });
}

test('does not start polling without a current principal cash session', () => {
    const ui = mount(() => assert.fail('Unexpected request'), false);
    assert.equal(ui.timer.fn, undefined);
});
