'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../js/bulk_actions.js'), 'utf8');
const location = new URL('https://itflow.test/agent/tickets.php');

function fixture(base, { modal = false, checked = true } = {}) {
    const attributes = new Map(modal ? [['href', '#'], ['data-modal-url', base]] : [['href', base]]);
    const trigger = {
        getAttribute: name => attributes.get(name) ?? null,
        hasAttribute: name => attributes.has(name),
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: name => attributes.delete(name),
    };
    const checkboxes = [
        { checked, name: 'ticket_ids[]', value: '12' },
        { checked, name: 'ticket_ids[]', value: '27' },
        { checked: false, name: 'ticket_ids[]', value: '99' },
    ];
    const listeners = [];
    const document = {
        querySelectorAll: () => checkboxes,
        getElementById: () => null,
        addEventListener: (type, callback, capture) => listeners.push({ type, callback, capture }),
    };
    vm.runInNewContext(source, { document, window: { location }, URL });
    listeners.find(listener => listener.type === 'DOMContentLoaded').callback();

    function click() {
        const event = {
            target: { closest: selector => selector === '[data-bulk="true"]' ? trigger : null },
            defaultPrevented: false,
            stopped: false,
            preventDefault() { this.defaultPrevented = true; },
            stopImmediatePropagation() { this.stopped = true; },
        };
        for (const listener of listeners.filter(listener => listener.type === 'click')
            .sort((a, b) => Number(Boolean(b.capture)) - Number(Boolean(a.capture)))) {
            listener.callback(event);
            if (event.stopped) break;
        }
        return event;
    }
    return { trigger, checkboxes, click };
}

test('normal links and modal links keep filters and replace selected IDs', () => {
    for (const modal of [false, true]) {
        const { trigger, checkboxes, click } = fixture('modals/ticket/bulk.php?category=4&ticket_ids[]=88#details', { modal });
        const event = click();
        assert.equal(event.defaultPrevented, false);
        assert.equal(event.stopped, false);
        const attribute = modal ? 'data-modal-url' : 'href';
        const url = new URL(trigger.getAttribute(attribute), location);
        assert.equal(url.origin, location.origin);
        assert.equal(url.pathname, '/agent/modals/ticket/bulk.php');
        assert.equal(url.searchParams.get('category'), '4');
        assert.deepEqual(url.searchParams.getAll('ticket_ids[]'), ['12', '27']);
        assert.equal(url.hash, '#details');
        checkboxes[0].checked = false;
        click();
        assert.deepEqual(new URL(trigger.getAttribute(attribute)).searchParams.getAll('ticket_ids[]'), ['27']);
    }
});

test('checkbox names and values cannot inject query fields or markup', () => {
    const { trigger, checkboxes, click } = fixture('/agent/bulk.php?note=%3Cscript%3E');
    checkboxes[0].value = '12&admin=true#<script>';
    checkboxes[1].name = 'ids[]&injected=1';
    click();
    const url = new URL(trigger.getAttribute('href'));
    assert.equal(url.searchParams.get('ticket_ids[]'), '12&admin=true#<script>');
    assert.equal(url.searchParams.get('ids[]&injected=1'), '27');
    assert.equal(url.searchParams.get('admin'), null);
    assert.equal(url.searchParams.get('injected'), null);
    assert.equal(url.searchParams.get('note'), '<script>');
    assert.equal(url.hash, '');
});

test('same-origin double-slash paths remain on the application origin', () => {
    const { trigger, click } = fixture('https://itflow.test//outside.test/bulk.php');
    click();
    const url = new URL(trigger.getAttribute('href'), location);
    assert.equal(url.origin, location.origin);
    assert.equal(url.pathname, '//outside.test/bulk.php');
});

test('unsafe destinations cancel navigation and downstream modal handling even without selection', () => {
    const unsafe = [
        'javascript:javascript:alert(1)//',
        'data:javascript:alert(1)//',
        'data:text/html,<script>alert(1)</script>',
        'vbscript:msgbox(1)',
        'file:///etc/passwd',
        'https://outside.test/bulk.php',
        '//outside.test/bulk.php',
        '/\\outside.test/bulk.php',
        'https://user:password@itflow.test/bulk.php',
        'http://itflow.test/bulk.php',
        'https://itflow.test:444/bulk.php',
        'http://[invalid',
    ];
    for (const base of unsafe) {
        for (const modal of [false, true]) {
            for (const checked of [false, true]) {
                const { trigger, click } = fixture(base, { modal, checked });
                const event = click();
                assert.equal(event.defaultPrevented, true, base);
                assert.equal(event.stopped, true, base);
                assert.equal(trigger.getAttribute('href'), null, base);
                assert.equal(trigger.getAttribute('data-modal-url'), null, base);
            }
        }
    }
});

test('safe links with no selection and placeholder links remain unchanged', () => {
    for (const base of ['/agent/bulk.php?category=4', '#']) {
        const { trigger, click } = fixture(base, { checked: false });
        const event = click();
        assert.equal(event.defaultPrevented, false);
        assert.equal(event.stopped, false);
        assert.equal(trigger.getAttribute('href'), base);
    }
});
