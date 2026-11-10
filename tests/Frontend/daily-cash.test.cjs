const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/app.js'), 'utf8').replace(/^import .*;\r?$/gm, '');

function closing(counted, fund, expected = '140000.00') {
    const fields = new Map();
    const form = {
        dataset: { expected },
        querySelector(selector) {
            if (!fields.has(selector)) fields.set(selector, { value: '', addEventListener() {}, setCustomValidity(message) { this.validity = message; } });
            return fields.get(selector);
        },
    };
    form.querySelector('[data-close-counted]').value = counted;
    form.querySelector('[data-close-fund]').value = fund;
    runInNewContext(`${source}\ninitializeCashClosing(form);`, { form, document: { addEventListener() {} } });
    return (selector) => form.querySelector(selector);
}

test('closing previews exact final withdrawal without changing the pre-withdrawal difference', () => {
    const field = closing('140000', '50000');
    assert.equal(field('[data-close-difference]').textContent, 'CAJA CUADRADA: $0');
    assert.equal(field('[data-close-withdrawal]').textContent, 'Retiro final a realizar: $90.000');
    assert.equal(field('[data-close-observation]').required, false);
});

test('shortage keeps its negative sign and requires an observation', () => {
    const field = closing('139000', '50000');
    assert.match(field('[data-close-difference]').textContent, /FALTANTE DE CAJA: -\$1\.000/);
    assert.equal(field('[data-close-observation]').required, true);
    assert.equal(field('[data-close-withdrawal]').textContent, 'Retiro final a realizar: $89.000');
});

test('surplus is highlighted and requires an observation', () => {
    const field = closing('141000', '50000');
    assert.match(field('[data-close-difference]').textContent, /SOBRANTE DE CAJA: \$1\.000/);
    assert.equal(field('[data-close-observation]').required, true);
});

test('a fund above counted cash cannot be confirmed', () => {
    const field = closing('140000', '140001');
    assert.notEqual(field('[data-close-fund]').validity, '');
    assert.equal(field('[data-close-withdrawal]').textContent, 'Fondo superior al disponible');
});

test('explicit zero fund and zero withdrawal are supported without invented amounts', () => {
    const field = closing('0', '0', '0.00');
    assert.equal(field('[data-close-withdrawal]').textContent, 'Retiro final a realizar: $0');
    assert.equal(field('[data-close-fund]').validity, '');
    const empty = closing('', '');
    assert.notEqual(empty('[data-close-fund]').validity, '');
});

test('signed theoretical balance uses exact cents rather than floating point', () => {
    const field = closing('0.10', '0', '-0.20');
    assert.match(field('[data-close-difference]').textContent, /SOBRANTE DE CAJA/);
    assert.equal(field('[data-close-observation]').required, true);
    assert.equal(field('[data-close-fund]').validity, '');
});

function manualMovement(value = 'ingreso') {
    const descriptions = {
        ingreso: 'Dinero adicional que entra a caja. No utilizar para registrar ventas.',
        egreso: 'Dinero que sale para pagar un gasto operativo de la panadería.',
        retiro: 'Efectivo extraído de caja que no corresponde a un gasto operativo.',
    };
    const listeners = new Map();
    const select = {
        value,
        get selectedOptions() {
            return descriptions[this.value] ? [{ dataset: { description: descriptions[this.value] } }] : [];
        },
        addEventListener(name, callback) { listeners.set(name, callback); },
    };
    const help = { textContent: '' };
    const form = {
        querySelector(selector) {
            return { '[data-cash-movement-type]': select, '[data-cash-movement-help]': help }[selector];
        },
    };

    return { select, help, form, listeners, descriptions };
}

function initializeManualForms(...forms) {
    const document = {
        addEventListener() {},
        querySelectorAll(selector) {
            return selector === '[data-cash-manual-movement]' ? forms : [];
        },
    };
    runInNewContext(`${source}\ninitializeCashForms();`, { document });
}

for (const code of ['ingreso', 'egreso', 'retiro']) {
    test(`manual ${code} initializes its explanation without changing the posted code`, () => {
        const movement = manualMovement(code);
        initializeManualForms(movement.form);
        assert.equal(movement.help.textContent, movement.descriptions[code]);
        assert.equal(movement.select.value, code);
    });
}

test('changing the manual type updates only its contextual help', () => {
    const movement = manualMovement();
    initializeManualForms(movement.form);
    for (const code of ['egreso', 'retiro', 'ingreso']) {
        movement.select.value = code;
        movement.listeners.get('change')();
        assert.equal(movement.help.textContent, movement.descriptions[code]);
        assert.equal(movement.select.value, code);
    }
});

test('manual help is scoped to its own form', () => {
    const first = manualMovement('ingreso');
    const second = manualMovement('egreso');
    initializeManualForms(first.form, second.form);
    first.select.value = 'retiro';
    first.listeners.get('change')();
    assert.equal(first.help.textContent, first.descriptions.retiro);
    assert.equal(second.help.textContent, second.descriptions.egreso);
    assert.equal(second.select.value, 'egreso');
});

test('pages without manual forms still initialize safely', () => {
    assert.doesNotThrow(() => initializeManualForms());
});
