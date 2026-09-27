// Authenticated route and responsive smoke checks against disposable fixture data.
// Screenshots are review evidence, not pixel-diff baselines.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const shots = process.env.N45_ROUTE_SHOTS || path.resolve(__dirname, '../../.impeccable/review/route-smoke');
const agentRoutes = [
  '/agent/dashboard.php', '/agent/clients.php', '/agent/operations.php',
  '/agent/tickets.php', '/agent/agreements.php', '/agent/invoices.php',
  '/agent/assets.php', '/agent/software.php', '/agent/documentation.php',
  '/agent/contacts.php', '/agent/locations.php', '/agent/projects.php',
  '/agent/quotes.php', '/agent/vendors.php', '/agent/products.php',
  '/agent/services.php', '/agent/notifications.php',
  `/agent/business_reviews.php?client_id=${fixture.client}`,
  `/agent/client_overview.php?client_id=${fixture.client}`,
  `/agent/ticket.php?ticket_id=${fixture.ticket}`,
];
// Every global technician navigation destination plus the client sidebar's
// scoped destinations. Detail records without a disposable fixture stay separate.
const agentAdditionalRoutes = [
  '/agent/accounts.php', '/agent/billing_review.php', '/agent/calendar.php',
  '/agent/expenses.php', '/agent/income.php', '/agent/portal_requests.php',
  '/agent/profitability.php', '/agent/purchasing.php', '/agent/recurring_expenses.php',
  '/agent/recurring_invoices.php', '/agent/recurring_tickets.php',
  '/agent/subscriptions.php', '/agent/transactions.php', '/agent/transfers.php',
  '/agent/trips.php',
  ...[
    'agreements', 'assets', 'calendar', 'certificates', 'contacts',
    'credentials', 'documentation', 'domains', 'files', 'income',
    'invoices', 'locations', 'networks', 'projects', 'quotes', 'racks',
    'recurring_invoices', 'recurring_tickets', 'services', 'software',
    'tickets', 'trips', 'vendors',
  ].map(name => `/agent/${name}.php?client_id=${fixture.client}`),
];
const adminRoutes = ['/admin/users.php', '/admin/audit_logs.php', '/admin/api_keys.php'];
const portalRoutes = ['/client/index.php', '/client/tickets.php',
  '/client/documents.php', '/client/invoices.php', '/client/requests.php',
  '/client/reviews.php', '/client/assets.php', '/client/domains.php',
  '/client/certificates.php', '/client/contacts.php', '/client/profile.php',
  '/client/quotes.php', '/client/recurring_invoices.php',
  '/client/unpaid_invoices.php', '/client/statement.php',
  '/client/saved_payment_methods.php', '/client/activity.php',
  '/client/ticket_view_all.php',
  `/client/ticket.php?id=${fixture.ticket}`, '/client/ticket_add.php',
  `/client/document.php?id=${fixture.doc}`];
const cases = [
  {name: 'agent-light-desktop', session: fixture.sessions[0], width: 1440, routes: agentRoutes.concat(agentAdditionalRoutes, adminRoutes), theme: 'light', capture: true},
  {name: 'agent-dark-tablet', session: fixture.sessions[2], width: 768, routes: agentRoutes.concat(adminRoutes), theme: 'dark'},
  {name: 'agent-dark-narrow', session: fixture.sessions[2], width: 393, routes: agentRoutes.concat(agentAdditionalRoutes, adminRoutes), theme: 'dark', capture: true},
  {name: 'portal-desktop', session: fixture.portal_session, width: 1440, routes: portalRoutes, capture: true},
  {name: 'portal-narrow', session: fixture.portal_session, width: 393, routes: portalRoutes, capture: true},
];

(async () => {
  fs.mkdirSync(shots, {recursive: true});
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  let checked = 0;
  const failures = [];
  try {
    for (const sample of cases) {
      const context = await browser.newContext({viewport: {width: sample.width, height: 900}});
      await context.addCookies([{name: 'PHPSESSID', value: sample.session, url: fixture.base}]);
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('console', message => {
        if (message.type() === 'error') errors.push(`console: ${message.text()}`);
      });
      page.on('response', response => {
        const request = response.request();
        if (response.status() >= 400 && new URL(response.url()).origin === new URL(fixture.base).origin &&
            ['script', 'stylesheet', 'image', 'font'].includes(request.resourceType())) {
          errors.push(`${request.resourceType()} HTTP ${response.status()}: ${new URL(response.url()).pathname}`);
        }
      });
      for (const route of sample.routes) {
        const label = `${sample.name} ${route}`;
        const slug = path.basename(new URL(route, fixture.base).pathname, '.php') +
          (new URL(route, fixture.base).search ? '-' + new URL(route, fixture.base).searchParams.keys().next().value : '');
        errors.length = 0;
        try {
          const response = await page.goto(fixture.base + route, {waitUntil: 'load'});
          assert.equal(response?.status(), 200, `${label}: HTTP ${response?.status()}`);
          assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, route, `${label}: unexpected redirect`);
          const result = await page.evaluate(() => ({
            theme: document.documentElement.getAttribute('data-bs-theme'),
            title: document.title,
            heading: [...document.querySelectorAll('main h1, main h2, main h3, .content-wrapper h1, .content-wrapper h2, .content-wrapper h3')]
              .some(element => element.getClientRects().length > 0 && element.textContent.trim()),
            overflow: document.documentElement.scrollWidth - window.innerWidth,
          }));
          assert.ok(result.title, `${label}: missing document title`);
        assert.ok(result.heading, `${label}: no visible page heading`);
        if (route.startsWith('/agent/ticket.php?')) {
          const badge = await page.locator('.ticket-field-value .n45-status-badge').first().evaluate(element => {
            const styles = getComputedStyle(element);
            return {text: element.textContent.trim(), color: styles.color, background: styles.backgroundColor};
          });
          assert.ok(badge.text && badge.color !== badge.background,
            `${label}: ticket status is not readable`);
        }
        if (route === '/client/requests.php' && sample.width <= 393) {
          const empty = await page.locator('.n45-table-scroll > .n45-table-empty').first().evaluate(element => ({
            message: element.textContent.trim(),
            clipped: element.parentElement.scrollWidth > element.parentElement.clientWidth + 1,
          }));
          assert.ok(empty.message.includes('Nothing to show here yet') && !empty.clipped,
            `${label}: empty requests are clipped on a phone`);
        }
        if (route === '/client/saved_payment_methods.php') {
          assert.ok(await page.getByRole('heading', {name: 'Automatic payments are not available right now'}).isVisible(),
            `${label}: payment configuration has no usable portal state`);
          assert.ok(await page.locator('[aria-labelledby="payment-unavailable-heading"]').getByRole('link', {name: 'Contact service desk'}).isVisible(),
            `${label}: payment fallback has no next action`);
        }
        if (route === '/client/index.php' && sample.width <= 393) {
          const menu = page.locator('.n45-portal-menu-button');
          await menu.click();
          assert.equal(await menu.getAttribute('aria-expanded'), 'true', `${label}: mobile navigation did not open`);
          assert.ok(await page.getByRole('navigation', {name: 'Primary'}).getByRole('link', {name: 'Request help'}).isVisible(),
            `${label}: mobile navigation is missing portal destinations`);
          await page.keyboard.press('Escape');
          assert.equal(await menu.getAttribute('aria-expanded'), 'false', `${label}: mobile navigation did not close`);
        }
          if (sample.theme) assert.equal(result.theme, sample.theme, `${label}: theme did not match account`);
          if (result.overflow > 1) {
            await page.screenshot({path: path.join(shots, `${sample.name}-${slug}-overflow.png`), fullPage: true});
          }
          assert.ok(result.overflow <= 1, `${label}: document overflows ${result.overflow}px`);
          assert.deepEqual(errors, [], `${label}: browser console or asset error`);
          if (sample.capture) {
            await page.evaluate(() => document.fonts.ready);
            await page.screenshot({path: path.join(shots, `${sample.name}-${slug}.png`), fullPage: true});
          }
          checked++;
        } catch (error) {
          failures.push(`${label}: ${error.message}`);
          try {
            await page.screenshot({path: path.join(shots, `${sample.name}-${slug}-failed.png`), fullPage: true});
          } catch { /* A navigation failure may leave no document to capture. */ }
        }
      }
      await context.close();
    }
    if (failures.length) {
      throw new Error(`${failures.length} authenticated UI cases failed:\n${failures.join('\n')}`);
    }
    console.log(`Authenticated route smoke: ${checked} route/viewport cases passed (${agentRoutes.length + agentAdditionalRoutes.length} agent, ${adminRoutes.length} admin, ${portalRoutes.length} portal routes).`);
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
