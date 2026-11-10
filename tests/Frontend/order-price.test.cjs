const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../resources/js/app.js'), 'utf8').replace(/^import .*;\r?$/gm, '');
const parse = runInNewContext(`${source}\nparseAgreedOrderTotal`, { document: { addEventListener() {} } });

test('agreed price accepts whole Chilean pesos with or without thousands separators', () => {
    assert.equal(parse('25001'), '25001.00');
    assert.equal(parse('$ 25.001'), '25001.00');
    assert.equal(parse('999.999.999.999'), '999999999999.00');
});

test('agreed price never treats cents, malformed groups or excessive values as whole pesos', () => {
    for (const value of ['', '0', '-1', '25.00', '25.000,50', '1e3', '1.000.000.000.000']) {
        assert.equal(parse(value), null, value);
    }
});
