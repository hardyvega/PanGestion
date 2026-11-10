const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { runInNewContext } = require('node:vm');
const { test } = require('node:test');
const source = readFileSync(resolve(__dirname, '../../resources/js/role-permissions.js'), 'utf8');

class Element {
    constructor() { this.listeners = new Map(); this.fields = new Map(); this.dataset = {}; this.children = []; this.hidden = false; this.open = false; this.value = ''; this.checked = false; this.classes = new Set(); this.classList = { toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name) }; }
    querySelector(name) { return this.fields.get(name); }
    addEventListener(name, fn) { this.listeners.set(name, fn); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    focus() { this.focused = true; }
    setAttribute(name, value) { this[name] = value; }
}

function reply(status = 200, data = { saved:true }, extra = {}) {
    return { status, ok:status >= 200 && status < 300, redirected:false, json:async () => data, ...extra };
}

function editor({ saved = ['1'], checked = ['1'], readonly = false, responses = [reply()] } = {}) {
    const root = new Element();
    root.dataset.savedIds = JSON.stringify(saved);
    Object.assign(root.dataset, { userId:'7', roleId:'12', sessionMinutes:'120', sessionUrl:'/administracion/roles/12' });
    const form = new Element();
    root.fields.set('[data-role-permission-form]', readonly ? null : form);
    for (const name of ['permission-result-count','permission-no-results','selected-total','selected-empty','selection-preview','selected-categories','selected-permissions','permission-save-status','permission-feedback','permission-recovery','permission-recheck']) root.fields.set(`[data-${name}]`, new Element());
    root.querySelector('[data-permission-feedback]').hidden = true;
    root.querySelector('[data-permission-recovery]').hidden = true;
    form.action = '/administracion/roles/12/permisos';
    const token = Object.assign(new Element(), {value:'original-csrf'});
    form.fields.set('[name="_token"]', token);
    const search = new Element();
    form.fields.set('[data-permission-search]', search);
    const button = new Element();
    form.fields.set('[data-save-role-permissions]', button);
    const filters = ['all','assigned','unassigned'].map((value) => Object.assign(new Element(), { value, checked: value === 'all' }));
    const groups = ['Articulos','Caja'].map((label) => {
        const group = new Element(); group.dataset.categoryLabel = label;
        group.fields.set('[data-category-count]', new Element());
        return group;
    });
    const rows = [
        ['1', 'Ver artículos', 'Consultar catálogo sin modificarlo.', 'articulos.ver', 0],
        ['2', 'Gestionar artículos', 'Crear y editar productos.', 'articulos.gestionar', 0],
        ['3', 'Abrir caja', 'Iniciar una sesión de caja.', 'caja.sesiones.abrir', 1],
    ].map(([id,name,description,code,groupIndex]) => {
        const row = new Element();
        row.dataset = { permissionName:name, permissionDescription:description, permissionCode:code };
        const checkbox = Object.assign(new Element(), { value:id, checked:checked.includes(id), disabled:false });
        checkbox.closest = () => row;
        row.fields.set('input',checkbox); row.fields.set('input[type="checkbox"]',checkbox);
        row.groupIndex = groupIndex;
        return row;
    });
    groups.forEach((g,index) => { g.querySelectorAll = () => rows.filter(row => row.groupIndex === index); });
    form.querySelectorAll = (selector) => ({ '[data-permission-filter]':filters, '[data-permission-category]':groups, '[data-role-permission]':rows })[selector];
    const logoutToken = Object.assign(new Element(), {value:'original-csrf'});
    const csrfMeta = new Element();
    const document = {
        activeElement:null, listeners:new Map(),
        addEventListener(name,fn) {this.listeners.set(name,fn);},
        createElement:() => new Element(),
        querySelectorAll:() => [token,logoutToken],
        querySelector:() => csrfMeta,
    };
    const calls = [];
    const timers = [];
    let now = 0;
    const context = {
        document,
        Date: {now:() => now},
        window: { setTimeout:(fn,ms) => timers.push({fn,ms}) },
        FormData: class {
            constructor() {
                this.permissions = rows.filter(r => r.querySelector('input').checked).map(r => r.querySelector('input').value);
                this.token = token.value;
            }
            getAll(name) { return name === 'permissions[]' ? this.permissions : []; }
        },
        fetch:async (url,options) => {
            calls.push({url,...options});
            const response = responses.shift();
            if (response instanceof Error) throw response;
            return await response;
        },
    };
    runInNewContext(`${source}\ninitializeRolePermissions(root);`, { ...context, root });
    const change = () => form.listeners.get('change')({ target:{ matches:() => true } });
    const query = (value) => { search.value = value; search.listeners.get('input')(); };
    const filter = (value) => { filters.forEach(f => { f.checked = f.value === value; }); change(); };
    const toggle = (id) => { const c = rows.find(r=>r.querySelector('input').value===id).querySelector('input'); c.checked = !c.checked; change(); };
    const selected = () => rows.filter(r=>r.querySelector('input').checked).map(r=>r.querySelector('input').value);
    const visible = () => rows.filter(r=>!r.hidden).map(r=>r.querySelector('input').value);
    const submit = () => {
        const event = {prevented:false,preventDefault(){this.prevented=true;}};
        const done = form.listeners.get('submit')(event);
        return {event,done};
    };
    const recheck = () => root.querySelector('[data-permission-recheck]').listeners.get('click')();
    const message = () => root.querySelector('[data-permission-feedback]').textContent;
    const advance = (minutes) => {now += minutes * 60000;timers.forEach(t => t.fn());};
    return { root,form,rows,groups,search,button,document,query,filter,toggle,selected,visible,submit,recheck,calls,message,token,logoutToken,csrfMeta,advance,timers };
}

for (const [term, expected] of [['ARTICULOS',['1','2']], ['catálogo',['1']], ['articulos.ver',['1']], ['sesion',['3']]]) {
    test(`search matches names, descriptions and hidden codes without altering selection: ${term}`, () => {
        const e = editor(); e.query(term);
        assert.deepEqual(e.visible(), expected);
        assert.deepEqual(e.selected(), ['1']);
        assert.equal(e.rows[0].querySelector('input').disabled,false);
    });
}

test('filters combine with search and never clear hidden selections', () => {
    const e = editor({saved:['1','3'],checked:['1','3']});
    e.filter('unassigned'); assert.deepEqual(e.visible(),['2']);
    e.query('crear'); assert.deepEqual(e.visible(),['2']);
    assert.deepEqual(e.selected(),['1','3']);
    e.filter('assigned'); assert.deepEqual(e.visible(),[]);
    e.query(''); assert.deepEqual(e.visible(),['1','3']);
    e.filter('all'); assert.deepEqual(e.visible(),['1','2','3']);
    assert.deepEqual(e.selected(),['1','3']);
});

test('collapsed categories preserve selection and pre-filter open state is restored', () => {
    const e = editor();
    e.groups[0].open = true;
    e.query('sesion'); assert.equal(e.groups[1].open,true);
    assert.equal(e.groups[0].hidden,true);
    e.query(''); assert.equal(e.groups[0].open,true); assert.equal(e.groups[1].open,false);
    e.groups[0].open = false;
    assert.deepEqual(e.selected(),['1']);
});

test('counts, summary and unsaved indicator follow checked state instead of filtered visibility', () => {
    const e = editor();
    e.toggle('3'); e.query('crear');
    assert.equal(e.root.querySelector('[data-selected-total]').textContent,'2');
    assert.equal(e.root.querySelector('[data-selected-permissions]').children.length,2);
    assert.equal(e.root.querySelector('[data-selected-categories]').children.length,2);
    assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Cambios sin guardar');
    e.toggle('3');
    assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Sin cambios pendientes');
});

test('empty roles show a compact empty state without selecting any permission', () => {
    const e = editor({saved:[],checked:[]});
    assert.deepEqual(e.selected(),[]);
    assert.equal(e.root.querySelector('[data-selected-empty]').hidden,false);
    assert.equal(e.root.querySelector('[data-selection-preview]').hidden,true);
});

test('no-result filters show a message and the full selection remains intact', () => {
    const e = editor(); e.query('does-not-exist');
    assert.equal(e.root.querySelector('[data-permission-no-results]').hidden,false);
    assert.deepEqual(e.selected(),['1']);
    e.query(''); assert.equal(e.root.querySelector('[data-permission-no-results]').hidden,true);
});

test('old unsaved selection is marked as dirty relative to the actual saved IDs', () => {
    const e = editor({saved:['1'],checked:[]});
    assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Cambios sin guardar');
});

test('changing checkboxes and filters never submits; only one explicit submit is allowed', async () => {
    const e = editor(); e.toggle('2'); e.filter('assigned');
    assert.equal(e.button.disabled,undefined);
    assert.equal(e.calls.length,0);
    const first = e.submit();
    assert.equal(first.event.prevented,true);
    assert.equal(e.form.inert,true);
    assert.equal(e.button.disabled,true);
    const second = e.submit();
    assert.equal(second.event.prevented,true);
    assert.equal(e.calls.length,1);
    assert.deepEqual(e.selected(),['1','2']);
    assert.equal(e.rows[0].querySelector('input').disabled,false);
    await first.done;
    assert.equal(e.form.inert,false);
    assert.equal(e.button.disabled,false);
    assert.equal(e.message(),'Permisos del rol actualizados correctamente.');
});

test('save includes searched, filtered and collapsed selections rather than only visible rows', async () => {
    const e = editor({checked:['1','3']});
    e.query('crear'); e.filter('unassigned'); e.groups.forEach(g => {g.open=false;});
    await e.submit().done;
    assert.deepEqual(e.calls[0].body.permissions,['1','3']);
    assert.equal(e.calls[0].body.token,'original-csrf');
    assert.equal(e.calls[0].method,'POST');
    assert.equal(e.calls[0].credentials,'same-origin');
    assert.equal(e.calls[0].headers.Accept,'application/json');
    assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Sin cambios pendientes');
});

for (const status of [401,419]) {
    test(`${status} preserves selection in the open page and requires explicit session checking`, async () => {
        const e = editor({checked:['1','3'],responses:[reply(status)]});
        await e.submit().done;
        assert.deepEqual(e.selected(),['1','3']);
        assert.equal(e.button.disabled,true);
        assert.equal(e.form.inert,false);
        assert.equal(e.root.querySelector('[data-permission-recovery]').hidden,false);
        assert.match(e.message(),status === 401 ? /sesión ha expirado/ : /CSRF/);
        await e.submit().done;
        assert.equal(e.calls.length,1);
        assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Cambios sin guardar');
    });
}

test('reauthentication never resubmits, refreshes CSRF for the same user and requires a fresh Save', async () => {
    const e = editor({checked:['1','3'],responses:[reply(401),reply(200,{user_id:'7',role_id:'12',csrf_token:'renewed-csrf'}),reply()]});
    await e.submit().done;
    await e.recheck();
    assert.equal(e.calls.length,2);
    assert.equal(e.calls[1].method,undefined);
    assert.equal(e.calls[1].cache,'no-store');
    assert.equal(e.token.value,'renewed-csrf');
    assert.equal(e.logoutToken.value,'renewed-csrf');
    assert.equal(e.csrfMeta.content,'renewed-csrf');
    assert.equal(e.button.disabled,false);
    assert.deepEqual(e.selected(),['1','3']);
    assert.match(e.message(),/Revisa la selección/);
    assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Cambios sin guardar');
    await e.submit().done;
    assert.equal(e.calls.length,3);
    assert.equal(e.calls[2].body.token,'renewed-csrf');
    assert.match(e.message(),/actualizados correctamente/);
});

test('another account or role cannot recover the in-page selection', async () => {
    for (const identity of [{user_id:'8',role_id:'12'},{user_id:'7',role_id:'99'}]) {
        const e = editor({checked:['1','3'],responses:[reply(401),reply(200,{...identity,csrf_token:'different'})]});
        await e.submit().done;
        await e.recheck();
        assert.deepEqual(e.selected(),[]);
        assert.equal(e.button.disabled,true);
        assert.equal(e.token.value,'original-csrf');
        assert.match(e.message(),/descartó por seguridad/);
        assert.equal(e.calls.length,2);
    }
});

for (const [status,data,pattern] of [
    [403,{},/No tienes autorización/],
    [422,{errors:{'permissions.0':['Permiso ya no disponible.']}},/Permiso ya no disponible/],
    [422,{message:'No puedes dejar sin administrador.'},/No puedes dejar sin administrador/],
    [500,{},/No se pudo confirmar/],
    [200,{saved:false},/No se pudo confirmar/],
]) {
    test(`failed save ${status} displays the actual outcome without clearing selection`, async () => {
        const e = editor({checked:['1','3'],responses:[reply(status,data)]});
        await e.submit().done;
        assert.match(e.message(),pattern);
        assert.deepEqual(e.selected(),['1','3']);
        assert.equal(e.button.disabled,false);
        assert.equal(e.root.querySelector('[data-permission-feedback]').classes.has('is-success'),false);
        assert.equal(e.root.querySelector('[data-permission-save-status]').textContent,'Cambios sin guardar');
    });
}

test('network failure keeps selection, unlocks the form and never retries automatically', async () => {
    const e = editor({checked:['1','3'],responses:[new Error('offline')]});
    await e.submit().done;
    assert.match(e.message(),/No se pudo confirmar/);
    assert.equal(e.calls.length,1);
    assert.deepEqual(e.selected(),['1','3']);
    assert.equal(e.form.inert,false);
});

test('local expiry warning neither refreshes the session nor moves keyboard focus', () => {
    const e = editor();
    e.advance(117);
    assert.equal(e.root.querySelector('[data-permission-feedback]').hidden,true);
    e.advance(1);
    assert.match(e.message(),/sesión puede vencer/);
    assert.equal(e.root.querySelector('[data-permission-feedback]').focused,undefined);
    assert.equal(e.calls.length,0);
    assert.deepEqual(e.selected(),['1']);
});

test('no browser storage contains a draft or credentials', () => {
    assert.doesNotMatch(source,/localStorage|sessionStorage|indexedDB/);
});

test('readonly role details do not install editing handlers', () => {
    const e = editor({readonly:true});
    assert.equal(e.form.listeners.size,0);
});
