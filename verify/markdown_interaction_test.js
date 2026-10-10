'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const listeners = [];
const document = { readyState: 'loading', addEventListener: (kind, callback) => listeners.push({ kind, callback }),
  querySelectorAll: () => [], getElementById: () => null, querySelector: () => null };
vm.runInNewContext(fs.readFileSync(__dirname + '/../public/js/feddit.js', 'utf8'), {
  document, window: { addEventListener() {} }, Image: function Image() {},
});
const attrs = { 'aria-expanded': 'false', 'aria-label': 'Reveal spoiler' };
const text = { setAttribute: (key, value) => { text[key] = value; } };
const button = { getAttribute: key => attrs[key], setAttribute: (key, value) => { attrs[key] = value; },
  removeAttribute: key => { delete attrs[key]; }, querySelector: () => text };
const click = () => listeners.filter(x => x.kind === 'click').forEach(x => x.callback({ target: { closest: selector => selector === '.md-spoiler' ? button : null } }));
click();
assert.equal(attrs['aria-expanded'], 'true'); assert.equal(text['aria-hidden'], 'false');
assert.equal(attrs['aria-label'], undefined);
click();
assert.equal(attrs['aria-expanded'], 'false'); assert.equal(text['aria-hidden'], 'true');
assert.equal(attrs['aria-label'], 'Reveal spoiler');
// Event delegation also handles a button introduced after initial page load.
click(); assert.equal(attrs['aria-expanded'], 'true');
console.log('PASS: spoiler reveal/hide accessibility state and delegated interaction.');
