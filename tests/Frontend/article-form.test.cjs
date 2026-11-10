const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/article-form.js'), 'utf8');

function articleForm(mode) {
    const fieldListeners = new Map();
    const formListeners = new Map();
    const buttonAttributes = new Map();
    const barcode = {
        value: '',
        addEventListener: (name, listener) => fieldListeners.set(name, listener),
    };
    const button = {
        disabled: false,
        setAttribute: (name, value) => buttonAttributes.set(name, value),
    };
    const form = {
        action: mode === 'create' ? '/articulos' : '/articulos/17',
        fields: { nombre: 'Articulo revisado', sku: 'scan-001', precio_venta: '1100' },
        querySelector: (selector) => selector === '[name="codigo_barras"]' ? barcode : null,
        querySelectorAll: (selector) => selector === 'button[type="submit"]' ? [button] : [],
        addEventListener: (name, listener) => formListeners.set(name, listener),
    };
    const document = {
        querySelectorAll: (selector) => selector === 'form[data-article-form]' ? [form] : [],
    };
    runInNewContext(source, { document });
    const event = (extra = {}) => ({
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
        ...extra,
    });
    const submit = (extra) => {
        const e = event(extra);
        formListeners.get('submit')(e);
        return e;
    };
    const key = (key, extra) => {
        const e = event({ key, ...extra });
        fieldListeners.get('keydown')(e);
        return e;
    };
    return { form, barcode, button, buttonAttributes, key, submit };
}

for (const mode of ['create', 'edit']) {
    test(`${mode}: scanner Enter is canceled without changing the barcode or other fields`, () => {
        const page = articleForm(mode);
        const fields = { ...page.form.fields };
        for (const code of ['2000000009018', '0007501013118056']) {
            page.barcode.value = code;
            assert.equal(page.key('Enter').defaultPrevented, true);
            assert.equal(page.key('Enter', { repeat: true }).defaultPrevented, true);
            assert.equal(page.barcode.value, code);
        }
        assert.deepEqual(page.form.fields, fields);
        assert.equal(page.button.disabled, false);
    });

    test(`${mode}: Tab, Shift+Tab and ordinary input retain their native behavior`, () => {
        const page = articleForm(mode);
        for (const key of ['Tab', '0', 'Backspace', 'ArrowLeft', 'Escape']) {
            assert.equal(page.key(key).defaultPrevented, false);
        }
        assert.equal(page.key('Tab', { shiftKey: true }).defaultPrevented, false);
    });

    test(`${mode}: deliberate submission is allowed once without rewriting the payload`, () => {
        const page = articleForm(mode);
        page.barcode.value = '0001234567890';
        assert.equal(page.submit({ submitter: page.button }).defaultPrevented, false);
        assert.equal(page.button.disabled, true);
        assert.equal(page.buttonAttributes.get('aria-busy'), 'true');
        assert.equal(page.submit({ submitter: page.button }).defaultPrevented, true);
        assert.equal(page.submit().defaultPrevented, true);
        assert.equal(page.barcode.value, '0001234567890');
    });

    test(`${mode}: an already canceled submit does not lock the Save button`, () => {
        const page = articleForm(mode);
        page.submit({ defaultPrevented: true });
        assert.equal(page.button.disabled, false);
        assert.equal(page.submit({ submitter: page.button }).defaultPrevented, false);
    });
}

test('other pages and POS receive no barcode or submit handlers', () => {
    const selectors = [];
    runInNewContext(source, { document: { querySelectorAll(selector) {
        selectors.push(selector);
        return [];
    } } });
    assert.deepEqual(selectors, ['form[data-article-form]']);
});
