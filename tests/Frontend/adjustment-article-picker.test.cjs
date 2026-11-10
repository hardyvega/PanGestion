const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(require.resolve('../../resources/js/adjustment-article-picker.js'), 'utf8');
const fixtures = [
    { id: '1', name: 'Bebida de prueba', sku: 'beb-lat-002', barcode: '00001234', stock: '7.500', stockLabel: '7,5 un', unit: 'un' },
    { id: '2', name: 'Bebida naranja', sku: 'prueba-bebida-b-002', barcode: '', stock: '3.000', stockLabel: '3 un', unit: 'un' },
];

function element() {
    const events = new Map();
    return {
        children: [], dataset: {}, attributes: {}, value: '', hidden: false, textContent: '',
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        append(...children) { this.children.push(...children); },
        replaceChildren() { this.children = []; },
        setCustomValidity(value) { this.validityMessage = value; },
        reportValidity() { this.dispatchEvent({ type: 'invalid' }); },
        scrollIntoView() {},
        focus() { this.dispatchEvent({ type: 'focus' }); },
        addEventListener(type, listener) { events.set(type, listener); },
        dispatchEvent(event) { events.get(event.type)?.(event); },
        fire(type, props = {}) {
            const event = { type, prevented: false, preventDefault() { this.prevented = true; }, ...props };
            this.dispatchEvent(event);
            return event;
        },
    };
}

function picker(articles = fixtures, previous = '') {
    const form = element(), select = element(), fallback = element(), search = element();
    const input = element(), list = element(), status = element(), clear = element();
    list.id = 'adjustment-suggestions';
    list.hidden = true;
    select.options = [{ value: '' }, ...articles.map(({ id, ...dataset }) => ({ value: id, dataset }))];
    select.value = previous;
    form.querySelector = (key) => ({
        '[name="articulo_id"]': select, '[data-adjustment-fallback]': fallback, '[data-adjustment-search]': search,
    })[key];
    search.querySelector = (key) => ({
        '[role="combobox"]': input, '[role="listbox"]': list, '[role="status"]': status, '[data-adjustment-clear]': clear,
    })[key];
    runInNewContext(source, {
        document: { querySelectorAll: (selector) => selector === 'form[data-adjustment-form]' ? [form] : [], createElement: element },
        Event: class { constructor(type) { this.type = type; } },
    });
    return {
        form, select, input, list, status, clear, fallback,
        type(value) { input.value = value; input.fire('input'); },
        key(key) { return input.fire('keydown', { key }); },
        choose(index) { list.fire('click', { target: { closest: () => list.children[index] } }); },
        names() { return list.children.map((option) => option.children[0].children[0].textContent); },
    };
}

test('partial name and SKU matches ignore case and show stock and unit', () => {
    const p = picker();
    p.type('bEBIdA');
    assert.deepEqual(p.names(), ['Bebida de prueba', 'Bebida naranja']);
    p.type('BEBIDA-B');
    assert.deepEqual(p.names(), ['Bebida naranja']);
    assert.match(p.list.children[0].children[0].children[1].textContent, /prueba-bebida-b-002.*3 un/);
});

test('a scanned code keeps its zeros and Enter selects without submitting', () => {
    const p = picker();
    p.type('00001234');
    assert.deepEqual(p.names(), ['Bebida de prueba']);
    assert.equal(p.select.value, '');
    assert.equal(p.key('Enter').prevented, true);
    assert.equal(p.input.value, '00001234');
    assert.equal(p.select.value, '1');
    assert.equal(p.key('Enter').prevented, true);
    assert.equal(p.form.fire('submit').prevented, false);
});

test('mouse selection sets the ID and typing again invalidates it', () => {
    const p = picker();
    p.type('bebida');
    p.choose(1);
    assert.equal(p.select.value, '2');
    assert.match(p.status.textContent, /Seleccionado: Bebida naranja/);
    p.type('prueba');
    assert.equal(p.select.value, '');
    assert.equal(p.form.fire('submit').prevented, true);
    assert.match(p.status.textContent, /Selecciona un artículo/);
    p.choose(0);
    assert.equal(p.select.value, '1');
    p.clear.fire('click');
    assert.equal(p.select.value, '');
    assert.equal(p.input.value, '');
    assert.equal(p.list.hidden, true);
});

test('arrows highlight an option, Escape closes, and Tab is not intercepted', () => {
    const p = picker();
    p.type('bebida');
    assert.equal(p.key('ArrowDown').prevented, true);
    assert.equal(p.key('ArrowDown').prevented, true);
    assert.equal(p.input.attributes['aria-activedescendant'], 'adjustment-suggestions-1');
    assert.equal(p.key('Escape').prevented, true);
    assert.equal(p.input.attributes['aria-expanded'], 'false');
    assert.equal(p.input.attributes['aria-activedescendant'], undefined);
    assert.equal(p.key('Enter').prevented, true);
    assert.equal(p.select.value, '');
    assert.equal(p.key('Tab').prevented, false);
    p.key('ArrowUp');
    p.key('Enter');
    assert.equal(p.select.value, '2');
});

test('text with no match cannot be saved', () => {
    const p = picker();
    p.type('No existe');
    assert.match(p.status.textContent, /No se encontraron/);
    assert.equal(p.key('Enter').prevented, true);
    assert.equal(p.select.value, '');
    assert.equal(p.form.fire('submit').prevented, true);
});

test('a previous valid selection is recovered without opening the results', () => {
    const p = picker(fixtures, '1');
    assert.equal(p.input.value, fixtures[0].name);
    assert.equal(p.input.validityMessage, '');
    assert.equal(p.fallback.hidden, true);
    assert.equal(p.list.hidden, true);
});

test('large catalogs cap DOM results and rank an exact barcode before partial matches', () => {
    const catalog = Array.from({ length: 3000 }, (_, i) => ({
        ...fixtures[0], id: String(i + 10), name: 'Bebida 00001234 ' + i, barcode: '',
    }));
    const p = picker([...catalog, fixtures[0]]);
    p.type('00001234');
    assert.equal(p.list.children.length, 12);
    assert.equal(p.names()[0], fixtures[0].name);
    assert.match(p.status.textContent, /3001/);
    p.type('bebida 00001234 2999');
    p.key('Enter');
    assert.equal(p.select.value, '3009');
});

test('Enter on other inputs cannot submit implicitly, but button activation remains native', () => {
    const p = picker(fixtures, '1');
    assert.equal(p.form.fire('keydown', { key: 'Enter', target: { matches: () => true } }).prevented, true);
    assert.equal(p.form.fire('keydown', { key: 'Enter', target: { matches: () => false } }).prevented, false);
});
