'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let filter;
const jquery = {
    ajaxPrefilter(callback) { filter = callback; },
    param(object) { return new URLSearchParams(object).toString(); }
};
class FakeFormData {
    constructor() { this.entries = []; }
    append(key, value) { this.entries.push([key, value]); }
}
vm.runInNewContext(fs.readFileSync(require.resolve('../static/js/csrf.js'), 'utf8'), {
    window: {jQuery: jquery}, FormData: FakeFormData,
    document: {querySelector(selector) {
        return {content: selector.includes('csrf-name') ? 'csrf_test_name' : 'fixture-token'};
    }}
});
for (const data of [undefined, '', {url: 'https://example.com/a?x=1&y=2'}, 'imgid=abcdef']) {
    const options = {type: 'POST', data};
    filter(options);
    assert.equal(typeof options.data, 'string');
    assert.equal(new URLSearchParams(options.data).get('csrf_test_name'), 'fixture-token');
    if (data && data.url) assert.equal(new URLSearchParams(options.data).get('url'), data.url);
}
const form = new FakeFormData();
filter({type: 'POST', data: form});
assert.equal(form.entries[0][0], 'csrf_test_name');
const crossDomain = {type: 'POST', data: 'x=1', crossDomain: true};
filter(crossDomain);
assert.equal(crossDomain.data, 'x=1');
const get = {type: 'GET', data: 'x=1'};
filter(get);
assert.equal(get.data, 'x=1');
console.log('CSRF request serialization checks passed');
