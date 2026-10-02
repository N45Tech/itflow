'use strict';

// Actual ticket and AJAX domain controls against the disposable HTTP fixture.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const shots = process.env.N45_ROUTE_SHOTS || path.resolve(__dirname, '../../.impeccable/review/route-smoke');

(async () => {
    fs.mkdirSync(shots, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        for (const sample of [
            { name: 'light-wide', width: 1440, actor: 0 },
            { name: 'dark-tablet', width: 768, actor: 2 },
            { name: 'dark-narrow', width: 393, actor: 2 },
        ]) {
            const context = await browser.newContext({ viewport: { width: sample.width, height: 1000 } });
            await context.addCookies([{ name: 'PHPSESSID', value: fixture.sessions[sample.actor], url: fixture.base }]);
            const page = await context.newPage();
            const errors = [];
            let posts = 0;
            page.on('pageerror', error => errors.push(error.message));
            page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
            page.on('request', request => { if (request.method() === 'POST') posts++; });
            await page.goto(`${fixture.base}/agent/ticket.php?ticket_id=${fixture.ticket}`);
            const picker = page.locator('#replyTypePicker');
            assert.equal(await picker.locator('input:checked').count(), 0, 'A visibility choice was preselected');
            const layout = await picker.evaluate(element => {
                const parent = element.getBoundingClientRect();
                return [...element.querySelectorAll('label')].map(label => {
                    const box = label.getBoundingClientRect();
                    return { height: box.height, inside: box.left >= parent.left - 1 && box.right <= parent.right + 1,
                        readable: label.scrollWidth <= label.clientWidth + 1 };
                });
            });
            assert.equal(layout.length, 4, 'The email visibility choice is missing');
            assert.ok(layout.every(label => label.height >= 44 && label.inside && label.readable),
                `${sample.name}: visibility labels are cramped or clipped`);
            await picker.screenshot({ path: path.join(shots, `record-controls-${sample.name}-picker.png`) });

            await page.locator('label[for="public_reply_type_opt3"]').click();
            await page.waitForFunction(() => document.activeElement?.id === 'work_action');
            await page.getByLabel('Action taken', { exact: true }).fill('Checked the branch resolver');
            await page.locator('label[for="public_reply_type_opt0"]').click();
            assert.equal(await page.locator('#public_reply_type_opt0').isChecked(), true);
            await page.locator('#public_reply_type_opt0').focus();
            await page.keyboard.press('ArrowRight');
            assert.equal(await page.locator('#public_reply_type_opt1').isChecked(), true,
                'Native keyboard navigation did not select the public reply');
            await page.locator('label[for="public_reply_type_opt3"]').click();
            assert.equal(await page.getByLabel('Action taken', { exact: true }).inputValue(), 'Checked the branch resolver',
                'Switching visibility discarded the draft');
            await page.locator('#cancelReply').click();
            await page.locator('#replyComposer').waitFor({ state: 'hidden' });
            assert.equal(await picker.locator('input:checked').count(), 0, 'Cancel left a visibility choice selected');
            assert.equal(posts, 0, 'Changing visibility sent a ticket update');

            await page.goto(`${fixture.base}/agent/domains.php?client_id=${fixture.client}`);
            await page.locator(`.ajax-modal[data-modal-url*="domain_edit.php"][data-modal-url*="id=${fixture.domain}"]`).first().click();
            const dialog = page.getByRole('dialog');
            await dialog.getByRole('link', { name: 'Records', exact: true }).click();
            const txt = dialog.getByLabel('TXT Records', { exact: true });
            await txt.waitFor({ state: 'visible' });
            await page.waitForFunction(id => {
                const pane = document.getElementById(id);
                return pane.classList.contains('active') && pane.classList.contains('show')
                    && getComputedStyle(pane).opacity === '1';
            }, `pills-records${fixture.domain}`);
            await page.waitForFunction(id => document.getElementById(id).offsetHeight > 60, `txt_records${fixture.domain}`);
            const fields = await dialog.locator('textarea[data-itflow-autosize]').evaluateAll(elements => elements.map(field => {
                const styles = getComputedStyle(field);
                return { height: field.offsetHeight, maximum: parseFloat(styles.maxHeight),
                    fits: field.scrollHeight <= field.clientHeight + 1, scrollable: styles.overflowY === 'auto' };
            }));
            assert.equal(fields.length, 5);
            assert.ok(fields.every(field => field.height <= field.maximum + 1 && (field.fits || field.scrollable)),
                `${sample.name}: record fields hide content or grow beyond the modal`);
            assert.ok(fields[4].scrollable, 'Long WHOIS content has no scroll fallback');
            await dialog.screenshot({ path: path.join(shots, `record-controls-${sample.name}-dns.png`), animations: 'disabled' });

            const before = await txt.evaluate(element => element.offsetHeight);
            await txt.evaluate(element => { element.value = 'v=spf1 -all'; element.dispatchEvent(new Event('input', { bubbles: true })); });
            assert.ok(await txt.evaluate(element => element.offsetHeight) < before, 'Autosizing did not shrink after shorter content');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), true);
            assert.deepEqual(errors, [], `${sample.name}: browser errors`);
            await context.close();
        }
        console.log('Ticket visibility and domain records: sizing, hidden tabs, keyboard choice, draft preservation and no-send checks passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
