const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/app.js'), 'utf8')
    .replace(/^import .*;\r?$/gm, '');
const methods = [
    { id: 1, nombre: 'Efectivo', afecta_efectivo: true },
    { id: 2, nombre: 'Debito', afecta_efectivo: false },
    { id: 3, nombre: 'Credito', afecta_efectivo: false },
];

class Element {
    constructor() {
        this.listeners = new Map();
        this.attributes = new Map();
        this.fields = new Map();
        this.children = [];
        this.dataset = {};
        this.value = '';
        this.textContent = '';
        this.disabled = false;
        this.classes = new Set();
        this.classList = { toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name) };
    }
    addEventListener(name, fn) { this.listeners.set(name, fn); }
    setAttribute(name, value) { this.attributes.set(name, value); }
    querySelector(selector) {
        if (!this.fields.has(selector)) this.fields.set(selector, new Element());
        return this.fields.get(selector);
    }
    querySelectorAll() { return [...this.children]; }
    appendChild(child) { this.children.push(child); child.remove = () => this.children.splice(this.children.indexOf(child), 1); }
    getBoundingClientRect() { return { height: 500 }; }
    focus() { this.focused = true; }
    scrollIntoView() { this.scrolled = true; }
    set innerHTML(value) { this.html = value; this.fields.clear(); }
    get innerHTML() { return this.html || ''; }
}

function pos({ payments = [{ payment_method_id: 1, amount: '5340.00', received_amount: '', payment_uuid: 'old-payment' }], empty = false, session = true, cashSessionNotice, fetch, productData, initialQuantity = '1.000' } = {}) {
    const root = new Element();
    const searchForm = new Element();
    const product = { id: 9, nombre: 'Pan de prueba', sku: 'PAN-9', precio: '5340.00', stock: '5.000', stock_label: '5 un', unidad_simbolo: 'un', ...productData };
    root.dataset = {
        initialCart: JSON.stringify({ 9: product }),
        initialLines: JSON.stringify(empty ? [] : [{ article_id: 9, quantity: initialQuantity }]),
        initialPayments: JSON.stringify(empty ? [] : payments),
        searchUrl: '/ventas/buscar',
        cashSessionNotice,
    };
    root.querySelector('[data-cash-session-input]').value = session ? '1' : '';
    let uuid = 0;
    const document = {
        addEventListener() {},
        querySelector: () => searchForm,
        getElementById: () => root,
        createElement: () => new Element(),
    };
    const window = {
        panGestionPaymentMethods: methods,
        crypto: { randomUUID: () => `test-uuid-${++uuid}` },
        requestAnimationFrame: (fn) => fn(),
        addEventListener() {},
        innerHeight: 768,
    };
    const context = { document, window, fetch };
    runInNewContext(`${source}\ninitializeSalesPoint();`, context);
    const field = (selector) => root.querySelector(selector);
    const paymentLines = field('[data-payment-lines]');
    const paymentRow = (index = 0) => {
        const row = paymentLines.querySelector(`[data-payment-line="${index}"]`);
        row.dataset.paymentLine = String(index);
        return row;
    };
    const paymentInput = (selector, value, index = 0) => {
        const row = paymentRow(index);
        const target = row.querySelector(selector);
        target.value = value;
        target.matches = (match) => match === selector;
        target.closest = () => row;
        paymentLines.listeners.get(selector === '[data-payment-method]' ? 'change' : 'input')({ target });
        return target;
    };
    const submit = () => {
        const event = { prevented: false, preventDefault() { this.prevented = true; } };
        field('[data-checkout-form]').listeners.get('submit')(event);
        return event;
    };
    const quantity = (action) => {
        const row = field('[data-cart-lines]').children[0];
        field('[data-cart-lines]').listeners.get('click')({ target: {
            closest: (selector) => selector === '[data-cart-line]' ? row
                : selector === '[data-quantity-action]' ? { dataset: { quantityAction: action } } : null,
        } });
    };
    const editQuantity = (value) => {
        const cartLines = field('[data-cart-lines]');
        const target = { value, matches: () => true, closest: () => cartLines.children[0] };
        cartLines.listeners.get('change')({ target });
        return target.value;
    };
    return { root, field, paymentRow, paymentInput, submit, quantity, editQuantity, searchForm, context, product };
}

test('cash change updates on input: 5340 / 10000 -> 4660, without rerendering the input', () => {
    const p = pos();
    const input = p.paymentInput('[data-payment-received]', '10000');
    assert.equal(p.paymentRow().querySelector('[data-payment-change]').textContent, 'Vuelto a entregar: $4.660');
    assert.equal(p.paymentRow().querySelector('[data-received-preview]').textContent, 'Recibido: $10.000');
    assert.equal(p.paymentRow().querySelector('[data-payment-received]'), input);
    assert.equal(p.field('[data-checkout-button]').disabled, false);
    assert.match(p.field('[data-payment-lines]').innerHTML, /Total de venta/);
    assert.match(p.field('[data-payment-inputs]').innerHTML, /\[amount\]" value="5340.00"/);
    assert.match(p.field('[data-payment-inputs]').innerHTML, /\[received_amount\]" value="10000.00"/);
});

test('exact cash shows zero change', () => {
    const p = pos();
    p.paymentInput('[data-payment-received]', '5340');
    assert.equal(p.paymentRow().querySelector('[data-payment-change]').textContent, 'Vuelto a entregar: $0');
    assert.equal(p.submit().prevented, false);
});

test('insufficient cash shows the exact shortfall and prevents submit', () => {
    const p = pos();
    p.paymentInput('[data-payment-received]', '5000');
    assert.equal(p.paymentRow().querySelector('[data-payment-change]').textContent, 'Faltan $340');
    assert.equal(p.field('[data-checkout-button]').disabled, true);
    assert.equal(p.submit().prevented, true);
});

for (const received of ['', ' ', '-1', 'NaN', 'Infinity', 'abc', '10000.001', '1e6', '5,000']) {
    test(`invalid or empty received cash blocks checkout: ${JSON.stringify(received)}`, () => {
        const p = pos();
        p.paymentInput('[data-payment-received]', received);
        assert.equal(p.field('[data-checkout-button]').disabled, true);
        assert.equal(p.submit().prevented, true);
    });
}

test('cart quantity changes recalculate the single applied amount and the cash shortfall', () => {
    const p = pos();
    p.paymentInput('[data-payment-received]', '10000');
    p.quantity('increase');
    assert.equal(p.field('[data-cart-total]').textContent, '$10.680');
    assert.equal(p.paymentRow().querySelector('[data-payment-change]').textContent, 'Faltan $680');
    assert.equal(p.submit().prevented, true);
    p.quantity('decrease');
    assert.equal(p.paymentRow().querySelector('[data-payment-change]').textContent, 'Vuelto a entregar: $4.660');
});

test('cart and search display Chilean stock while quantity controls keep canonical payloads', async () => {
    const productData = { stock: '1234.000', stock_label: '1.234 un' };
    const p = pos({ productData, fetch: async () => ({ ok: true, json: async () => ({ exact_match: false, data: [p.product] }) }) });
    const rowHtml = () => p.field('[data-cart-lines]').children[0].innerHTML;
    const lines = () => p.field('[data-line-inputs]').innerHTML;
    assert.match(rowHtml(), /Stock disponible: 1\.234 un/);
    assert.match(rowHtml(), /data-quantity-input value="1"/);
    assert.match(rowHtml(), /class="quantity-unit" aria-hidden="true">un</);
    p.quantity('increase');
    assert.match(rowHtml(), /data-quantity-input value="2"/);
    assert.match(lines(), /\[quantity\]" value="2\.000"/);
    p.quantity('decrease');
    assert.match(lines(), /\[quantity\]" value="1\.000"/);

    p.field('[data-search-input]').value = 'pan';
    p.searchForm.listeners.get('submit')({ preventDefault() {} });
    await new Promise(setImmediate);
    assert.match(p.field('[data-product-results]').innerHTML, /Stock 1\.234 un/);
});

test('editing fractional POS quantities displays significant decimals and submits the original precision', () => {
    const p = pos({
        productData: { stock: '2.500', stock_label: '2,5 L', unidad_simbolo: 'L', precio: '800.00' },
        initialQuantity: '0.035',
    });
    const rowHtml = () => p.field('[data-cart-lines]').children[0].innerHTML;
    const lines = () => p.field('[data-line-inputs]').innerHTML;
    assert.match(rowHtml(), /Stock disponible: 2,5 L/);
    assert.match(rowHtml(), /data-quantity-input value="0,035"/);
    assert.match(lines(), /\[quantity\]" value="0\.035"/);
    assert.equal(p.field('[data-cart-total]').textContent, '$28');

    p.editQuantity('0,125');
    assert.match(rowHtml(), /data-quantity-input value="0,125"/);
    assert.match(lines(), /\[quantity\]" value="0\.125"/);
    assert.equal(p.field('[data-cart-total]').textContent, '$100');
    p.editQuantity('0.250');
    assert.match(rowHtml(), /data-quantity-input value="0,25"/);
    assert.match(lines(), /\[quantity\]" value="0\.250"/);
    assert.equal(p.editQuantity('0,0001'), '0,25');
    assert.equal(p.editQuantity('2,501'), '0,25');
    assert.match(lines(), /\[quantity\]" value="0\.250"/);
});

for (const method of ['2', '3']) {
    test(`noncash method ${method} clears cash fields and requires no banknotes`, () => {
        const p = pos();
        p.paymentInput('[data-payment-received]', '10000');
        p.paymentInput('[data-payment-method]', method);
        assert.match(p.field('[data-payment-lines]').innerHTML, /payment-received-field is-hidden/);
        assert.match(p.field('[data-payment-inputs]').innerHTML, /\[received_amount\]" value=""/);
        assert.equal(p.field('[data-checkout-button]').disabled, false);
        p.paymentInput('[data-payment-method]', '1');
        assert.equal(p.field('[data-checkout-button]').disabled, true);
    });
}

test('mixed payment computes change only against the cash component and keeps distribution exact', () => {
    const p = pos({ payments: [
        { payment_method_id: 2, amount: '3000', received_amount: '' },
        { payment_method_id: 1, amount: '2340', received_amount: '5000' },
    ] });
    assert.equal(p.field('[data-payment-mode]').textContent, 'Mixto');
    assert.equal((p.field('[data-payment-lines]').innerHTML.match(/Monto de este pago/g) ?? []).length, 2);
    assert.doesNotMatch(p.field('[data-payment-lines]').innerHTML, /Total de venta/);
    assert.equal(p.paymentRow(1).querySelector('[data-payment-change]').textContent, 'Vuelto a entregar: $2.660');
    assert.equal(p.field('[data-checkout-button]').disabled, false);
    p.paymentInput('[data-payment-amount]', '2999', 0);
    assert.equal(p.field('[data-payment-balance]').textContent, '$1');
    assert.equal(p.submit().prevented, true);
    p.paymentInput('[data-payment-amount]', '3001', 0);
    assert.equal(p.field('[data-payment-balance]').textContent, '-$1');
    assert.equal(p.submit().prevented, true);
});

test('invalid mixed applied amounts block checkout even if the other row covers the total', () => {
    const p = pos({ payments: [
        { payment_method_id: 2, amount: '5340' },
        { payment_method_id: 3, amount: 'bad' },
    ] });
    assert.equal(p.submit().prevented, true);
});

test('double submission is blocked without changing the successful command identity', () => {
    const p = pos();
    p.paymentInput('[data-payment-received]', '10000');
    const uuid = p.field('[data-command-uuid-input]').value;
    assert.equal(p.submit().prevented, false);
    assert.equal(p.submit().prevented, true);
    assert.equal(p.field('[data-command-uuid-input]').value, uuid);
});

test('emptying the cart clears received cash and new sales start clean', () => {
    const p = pos();
    p.paymentInput('[data-payment-received]', '10000');
    p.quantity('decrease');
    assert.equal(p.field('[data-payment-inputs]').innerHTML, '');
    assert.equal(p.field('[data-cart-total]').textContent, '$0');
    assert.equal(p.submit().prevented, true);
    assert.equal(pos({ empty: true }).field('[data-payment-inputs]').innerHTML, '');
});

test('stock limits, missing cash session and empty cart all prevent checkout', () => {
    const p = pos();
    for (let i = 0; i < 10; i++) p.quantity('increase');
    assert.equal(p.field('[data-cart-total]').textContent, '$26.700');
    assert.match(p.field('[data-search-feedback]').textContent, /stock/);
    assert.equal(pos({ session: false }).submit().prevented, true);
    assert.equal(pos({ empty: true }).submit().prevented, true);
});

test('an expired cash session leaves the cart available but blocks checkout with the server notice', () => {
    const notice = 'Hay una sesión de caja anterior pendiente de cierre.';
    const p = pos({
        session: false,
        cashSessionNotice: notice,
        payments: [{ payment_method_id: 2, amount: '5340.00', payment_uuid: 'test-debit' }],
    });
    assert.equal(p.field('[data-search-input]').disabled, false);
    assert.equal(p.field('[data-cart-total]').textContent, '$5.340');
    assert.equal(p.field('[data-checkout-help]').textContent, notice);
    assert.equal(p.field('[data-checkout-button]').disabled, true);
    assert.equal(p.submit().prevented, true);
});

test('a repeated Enter while an exact scanner lookup is pending adds only once and restores focus', async () => {
    let calls = 0;
    let finish;
    const p = pos({ empty: true, fetch: () => { calls++; return new Promise((done) => { finish = done; }); } });
    p.field('[data-search-input]').value = '000123';
    const event = { preventDefault() {} };
    p.searchForm.listeners.get('submit')(event);
    p.searchForm.listeners.get('submit')(event);
    finish({ ok: true, json: async () => ({ exact_match: true, data: [p.product] }) });
    await new Promise(setImmediate);
    assert.equal(calls, 1);
    assert.equal(p.field('[data-cart-total]').textContent, '$5.340');
    assert.equal(p.field('[data-search-input]').value, '');
    assert.equal(p.field('[data-search-input]').focused, true);
});

test('no results and failed lookups leave the cart unchanged', async () => {
    for (const ok of [true, false]) {
        const p = pos({ empty: true, fetch: async () => ({ ok, json: async () => ({ data: [] }) }) });
        p.field('[data-search-input]').value = 'missing';
        p.searchForm.listeners.get('submit')({ preventDefault() {} });
        await new Promise(setImmediate);
        assert.match(p.field('[data-search-feedback]').textContent, ok ? /No encontramos/ : /No se pudo/);
        assert.equal(p.field('[data-cart-total]').textContent, '$0');
    }
});

test('cash arithmetic stays exact beyond floating point safe integers and preserves cents', () => {
    const p = pos();
    const output = runInNewContext("posPaymentState({ amount: '9007199254740993.01', received: '9007199254740994.02' }, { afecta_efectivo: true }).message", p.context);
    assert.equal(output, 'Vuelto a entregar: $1,01');
    assert.equal(runInNewContext("multiplyMoney('0.333', '10.00')", p.context), '3.33');
});
