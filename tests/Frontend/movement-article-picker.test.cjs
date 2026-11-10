const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(require.resolve('../../resources/js/movement-article-picker.js'), 'utf8');
const sprite = { id: '1', nombre: 'Bebida Sprite de prueba', sku: 'prueba-bebida-a-001' };
const jumex = { id: '2', nombre: 'Jugo Jumex de prueba', sku: 'prueba-bebida-b-002' };

function node() {
    const listeners = new Map();
    return {
        children: [], attributes: {}, dataset: {}, value: '', hidden: true,
        append(...children) { this.children.push(...children); },
        replaceChildren() { this.children = []; },
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        addEventListener(event, fn) { listeners.set(event, fn); },
        dispatchEvent(event) { listeners.get(event.type)?.(event); },
        fire(type, props) {
            const event = { type, prevented: false, preventDefault() { this.prevented = true; }, ...props };
            this.dispatchEvent(event);
            return event.prevented;
        },
        focus() { this.fire('focus'); },
        scrollIntoView() {},
    };
}

function setup(previous = '') {
    const picker = node(), input = node(), id = node(), clear = node(), list = node(), popup = node(), status = node();
    const calls = [];
    let timer;
    id.value = previous;
    list.id = 'suggestions';
    picker.dataset.movementArticlePicker = '/inventario/movimientos/articulos';
    picker.querySelector = (selector) => ({
        '[name="q"]': input, '[name="articulo_id"]': id, '[data-movement-article-clear]': clear,
        '.movement-article-popup': popup, '[role="listbox"]': list, '[role="status"]': status,
    })[selector];
    runInNewContext(source, {
        document: { querySelectorAll: () => [picker], createElement: node },
        window: { location: { href: 'http://example.test/inventario/movimientos' } },
        Event, AbortController, URL,
        setTimeout(fn, delay) { assert.equal(delay, 250); timer = fn; return fn; },
        clearTimeout(fn) { if (timer === fn) timer = undefined; },
        fetch(url, options) {
            return new Promise(resolve => calls.push({
                url, signal: options.signal, respond: (data, ok = true) => resolve({ ok, json: async () => ({ data }) }),
            }));
        },
    });
    return {
        input, id, clear, list, popup, status, calls,
        type(value) { input.value = value; input.fire('input'); },
        key(key) { return input.fire('keydown', { key }); },
        tick() { const fn = timer; timer = undefined; return fn?.(); },
        click(index) { list.fire('click', { target: { closest: () => list.children[index] } }); },
    };
}

test('typing debounces the request, preserves barcode zeros, and clears the previous ID', async () => {
    const picker = setup('2');
    picker.type('00');
    picker.type('0000123456789');
    assert.equal(picker.id.disabled, true);
    assert.equal(picker.calls.length, 0);
    const pending = picker.tick();
    assert.equal(picker.calls[0].url.searchParams.get('q'), '0000123456789');
    assert.equal(picker.key('Enter'), true);
    assert.equal(picker.id.value, '');
    picker.calls[0].respond([sprite]);
    await pending;
    assert.equal(picker.key('Enter'), true);
    assert.equal(picker.id.value, '1');
    assert.equal(picker.input.value, sprite.nombre);
});

test('obsolete responses never replace current suggestions, and clicking selects an article', async () => {
    const picker = setup();
    picker.type('spr');
    const first = picker.tick();
    picker.type('jum');
    const second = picker.tick();
    assert.equal(picker.calls[0].signal.aborted, true);
    picker.calls[1].respond([jumex]);
    await second;
    picker.calls[0].respond([sprite]);
    await first;
    picker.click(0);
    assert.equal(picker.id.value, '2');
    assert.equal(picker.input.value, jumex.nombre);
    picker.clear.fire('click');
    assert.equal(picker.input.value, '');
    assert.equal(picker.id.disabled, true);
});

test('arrows and Enter select the active option, Escape cancels, and Tab remains native', async () => {
    const picker = setup();
    picker.type('pr');
    assert.equal(picker.key('Escape'), true);
    assert.equal(picker.tick(), undefined);
    picker.type('pr');
    const pending = picker.tick();
    picker.calls[0].respond([sprite, jumex]);
    await pending;
    assert.equal(picker.key('ArrowUp'), true);
    assert.equal(picker.input.attributes['aria-activedescendant'], 'suggestions-1');
    assert.equal(picker.key('Tab'), false);
    assert.equal(picker.key('Enter'), true);
    assert.equal(picker.id.value, '2');
    assert.equal(picker.popup.hidden, true);
});

test('no matches and network errors preserve usable free-text filtering', async () => {
    const picker = setup();
    picker.type('sin coincidencias');
    const empty = picker.tick();
    picker.calls[0].respond([]);
    await empty;
    assert.match(picker.status.textContent, /No se encontraron/);
    assert.equal(picker.list.hidden, true);
    picker.type('sin servicio');
    const error = picker.tick();
    picker.calls[1].respond([], false);
    await error;
    assert.match(picker.status.textContent, /Puedes usar Filtrar/);
    assert.equal(picker.input.value, 'sin servicio');
    assert.equal(picker.id.disabled, true);
});
