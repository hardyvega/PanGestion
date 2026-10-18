const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/private-page.js'), 'utf8');

function page(isPrivate) {
    const listeners = new Map();
    const reloads = [];
    const document = {
        body: { classList: { contains: (name) => isPrivate && name === 'authenticated-page' } },
        documentElement: { hidden: false },
    };
    const window = {
        addEventListener: (name, listener) => listeners.set(name, listener),
        location: { reload: () => reloads.push({ hidden: document.documentElement.hidden }) },
    };
    runInNewContext(source, { document, window });

    return { document, reloads, listeners };
}

test('ordinary private navigation stays visible without an extra request', () => {
    const current = page(true);
    current.listeners.get('pageshow')({ persisted: false });
    assert.equal(current.document.documentElement.hidden, false);
    assert.equal(current.reloads.length, 0);
});

test('BFCache restoration hides private content before one server reload', () => {
    const restored = page(true);
    restored.listeners.get('pageshow')({ persisted: true });
    assert.deepEqual(restored.reloads, [{ hidden: true }]);

    const revalidated = page(true);
    revalidated.listeners.get('pageshow')({ persisted: false });
    assert.equal(revalidated.reloads.length, 0);
    assert.equal(revalidated.document.documentElement.hidden, false);
});

test('public login pages do not register a BFCache reload handler', () => {
    const login = page(false);
    assert.equal(login.listeners.size, 0);
    assert.equal(login.document.documentElement.hidden, false);
    assert.equal(login.reloads.length, 0);
});

const loginSource = readFileSync(resolve(__dirname, '../../resources/js/login-form.js'), 'utf8');

function loginPage(isLogin = true) {
    const listeners = new Map();
    const username = { value: 'synthetic_operator', defaultValue: 'synthetic_operator' };
    const password = { value: 'SyntheticTestOnly123!', defaultValue: '', type: 'password' };
    const label = { textContent: 'Mostrar' };
    const attributes = new Map([['aria-pressed', 'false']]);
    const toggle = {
        querySelector: () => label,
        setAttribute: (name, value) => attributes.set(name, value),
    };
    const fields = new Map([
        ['[name="nombre_usuario"]', username], ['[name="password"]', password],
        ['[data-password-toggle]', toggle],
    ]);
    const form = { querySelector: (selector) => fields.get(selector) };
    const document = { querySelector: () => isLogin ? form : null };
    const window = { addEventListener: (name, listener) => listeners.set(name, listener) };
    runInNewContext(loginSource, { document, window });

    return { listeners, username, password, label, attributes };
}

test('login departure clears both live and default values before history restoration', () => {
    const login = loginPage();
    login.listeners.get('pagehide')({ persisted: true });
    assert.equal(login.username.value, '');
    assert.equal(login.username.defaultValue, '');
    assert.equal(login.password.value, '');
    assert.equal(login.password.defaultValue, '');
});

test('ordinary login pageshow and typing preserve values without timers or extra requests', () => {
    const login = loginPage();
    login.listeners.get('pageshow')({ persisted: false });
    assert.equal(login.username.value, 'synthetic_operator');
    assert.equal(login.password.value, 'SyntheticTestOnly123!');
    login.password.value = 'ActivelyTyping456!';
    assert.equal(login.password.value, 'ActivelyTyping456!');
    assert.deepEqual([...login.listeners.keys()], ['pagehide', 'pageshow']);
});

test('restored login clears credentials without reloading and resets password visibility', () => {
    const login = loginPage();
    login.password.type = 'text';
    login.label.textContent = 'Ocultar';
    login.attributes.set('aria-pressed', 'true');
    login.listeners.get('pageshow')({ persisted: true });
    assert.equal(login.username.value, '');
    assert.equal(login.password.value, '');
    assert.equal(login.password.type, 'password');
    assert.equal(login.label.textContent, 'Mostrar');
    assert.equal(login.attributes.get('aria-pressed'), 'false');
    assert.equal(login.attributes.get('aria-label'), 'Mostrar contrase\u00f1a');
});

test('login cleanup does not run on private pages or other forms', () => {
    assert.equal(loginPage(false).listeners.size, 0);
});

test('login values are not cleared during form submission before navigation', () => {
    const login = loginPage();
    assert.equal(login.listeners.has('submit'), false);
    assert.equal(login.password.value, 'SyntheticTestOnly123!');
    login.listeners.get('pagehide')({ persisted: false });
    assert.equal(login.password.value, '');
});
