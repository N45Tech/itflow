'use strict';

const path = require('path');
const { chromium } = require('playwright');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const requestCounts = { modal: 0, flaky: 0 };
    const submissions = [];
    let nativeDialogs = 0;
    let recordSubmission;
    const firstSubmission = new Promise(resolve => {
        recordSubmission = resolve;
    });

    const fixtureHtml = `
        <!doctype html>
        <html>
        <head><meta charset="utf-8"></head>
        <body>
            <main class="app-main">
                <button id="open-modal" class="ajax-modal" data-modal-url="/modal">Open details</button>
                <button id="open-flaky" class="ajax-modal" data-modal-url="/flaky">Open flaky panel</button>
                <form id="save-form" action="/submit" method="post" target="submission-frame" data-itflow-submit>
                    <button id="save" type="submit" name="action" value="save" data-busy-label="Saving record…">Save</button>
                </form>
                <iframe name="submission-frame" hidden></iframe>
            </main>
            <script>
                window.bootstrap = {
                    Modal: {
                        getOrCreateInstance: function (element) {
                            return {
                                show: function () {
                                    element.classList.add('show');
                                    element.dispatchEvent(new Event('shown.bs.modal', { bubbles: true }));
                                }
                            };
                        }
                    }
                };
                window.itflowNormalizeModalControls = function () {};
            </script>
        </body>
        </html>
    `;

    page.on('dialog', async dialog => {
        nativeDialogs += 1;
        await dialog.dismiss();
    });

    await page.route('https://itflow.test/**', async route => {
        const request = route.request();
        const url = new URL(request.url());

        if (url.pathname === '/' && request.method() === 'GET') {
            await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: fixtureHtml });
            return;
        }

        if (url.pathname === '/submit') {
            submissions.push(request.postData() || '');
            recordSubmission();
            await route.fulfill({ status: 200, contentType: 'text/html', body: 'submitted' });
            return;
        }

        if (url.pathname === '/modal') {
            requestCounts.modal += 1;
            await new Promise(resolve => setTimeout(resolve, 120));
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    content: '<div class="modal-header"><h5 class="modal-title">Edit record</h5></div>' +
                        '<div class="modal-body"><label for="record-name">Name</label>' +
                        '<input id="record-name" autofocus></div>'
                })
            });
            return;
        }

        if (url.pathname === '/flaky') {
            requestCounts.flaky += 1;
            if (requestCounts.flaky === 1) {
                await route.fulfill({ status: 500, contentType: 'text/plain', body: 'failed' });
            } else {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        content: '<div class="modal-header"><h5 class="modal-title">Recovered</h5></div>' +
                            '<div class="modal-body"><input id="recovered-field" autofocus></div>'
                    })
                });
            }
            return;
        }

        await route.abort();
    });

    await page.goto('https://itflow.test/');

    const root = path.resolve(__dirname, '..');
    await page.addScriptTag({ path: path.join(root, 'js/interaction_feedback.js') });
    await page.addScriptTag({ path: path.join(root, 'js/ajax_modal.js') });

    await page.click('#save');
    await Promise.race([
        firstSubmission,
        new Promise((resolve, reject) => setTimeout(() => reject(new Error('Form submission timed out')), 2000))
    ]);
    const busyForm = await page.evaluate(() => ({
        formBusy: document.querySelector('#save-form').getAttribute('aria-busy'),
        buttonBusy: document.querySelector('#save').getAttribute('aria-busy'),
        disabled: document.querySelector('#save').disabled,
        label: document.querySelector('#save').textContent.trim(),
        mirror: document.querySelector('[data-itflow-submitter-mirror]')?.value || null,
        duplicatePrevented: !document.querySelector('#save-form').dispatchEvent(new SubmitEvent('submit', {
            bubbles: true,
            cancelable: true,
            submitter: document.querySelector('#save')
        }))
    }));
    assert(busyForm.formBusy === 'true', 'Submitting form did not expose aria-busy');
    assert(busyForm.buttonBusy === 'true' && busyForm.disabled, 'Submitter was not made busy and disabled');
    assert(busyForm.label === 'Saving record…',
        `Submitter did not show its specific progress copy; received ${JSON.stringify(busyForm.label)}`);
    assert(busyForm.mirror === 'save', 'Submitter value was not preserved before disabling');
    assert(busyForm.duplicatePrevented, 'A repeated form submission was not cancelled');
    assert(submissions.some(body => body.includes('action=save')), 'The submitted request lost its action field');

    await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
    const restoredForm = await page.evaluate(() => ({
        busy: document.querySelector('#save-form').hasAttribute('aria-busy'),
        disabled: document.querySelector('#save').disabled,
        label: document.querySelector('#save').textContent.trim()
    }));
    assert(!restoredForm.busy && !restoredForm.disabled && restoredForm.label === 'Save',
        'Back-forward restoration left the form in a stale busy state');

    await page.click('#open-modal');
    const loadingState = await page.evaluate(() => ({
        busy: document.querySelector('.modal')?.getAttribute('aria-busy'),
        copy: document.querySelector('.n45-modal-state')?.textContent.trim(),
        role: document.querySelector('.n45-modal-state')?.getAttribute('role')
    }));
    assert(loadingState.busy === 'true' && loadingState.copy === 'Loading details…' && loadingState.role === 'status',
        'AJAX modal did not expose an immediate accessible loading state');

    await page.waitForSelector('#record-name');
    assert(await page.evaluate(() => document.activeElement?.id === 'record-name'),
        'Loaded AJAX modal did not focus its first field');
    await page.evaluate(() => document.querySelector('#open-modal').click());
    await page.waitForTimeout(40);
    assert(requestCounts.modal === 1, 'Repeated activation fetched the same AJAX modal twice');

    await page.evaluate(() => {
        document.querySelector('.modal').dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));
    });
    assert(await page.evaluate(() => document.activeElement?.id === 'open-modal'),
        'Closing an AJAX modal did not restore focus to its trigger');

    await page.click('#open-flaky');
    await page.waitForSelector('.n45-modal-state-error[role="alert"]');
    assert(await page.getByRole('button', { name: 'Retry' }).isVisible(),
        'AJAX modal failure did not offer Retry');
    assert(await page.getByRole('button', { name: 'Close', exact: true }).isVisible(),
        'AJAX modal failure did not offer Close');

    await page.getByRole('button', { name: 'Retry' }).click();
    await page.waitForSelector('#recovered-field');
    assert(requestCounts.flaky === 2, 'Retry did not make exactly one replacement request');
    assert(await page.evaluate(() => document.activeElement?.id === 'recovered-field'),
        'Recovered AJAX modal did not focus its first field');
    assert(nativeDialogs === 0, 'Interaction feedback opened a native browser dialog');

    await browser.close();
    console.log('Interaction feedback browser checks passed');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
