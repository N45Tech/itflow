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
];
const adminRoutes = ['/admin/users.php', '/admin/audit_logs.php', '/admin/api_keys.php'];
const portalRoutes = ['/client/index.php', '/client/tickets.php',
  '/client/documents.php', '/client/invoices.php'];
const cases = [
  {name: 'agent-light-desktop', session: fixture.sessions[0], width: 1440, routes: agentRoutes.concat(adminRoutes), theme: 'light', capture: true},
  {name: 'agent-dark-tablet', session: fixture.sessions[2], width: 768, routes: agentRoutes.concat(adminRoutes), theme: 'dark'},
  {name: 'agent-dark-narrow', session: fixture.sessions[2], width: 393, routes: agentRoutes.concat(adminRoutes), theme: 'dark', capture: true},
  {name: 'portal-desktop', session: fixture.portal_session, width: 1440, routes: portalRoutes, capture: true},
  {name: 'portal-narrow', session: fixture.portal_session, width: 393, routes: portalRoutes, capture: true},
];

(async () => {
  fs.mkdirSync(shots, {recursive: true});
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  let checked = 0;
  try {
    for (const sample of cases) {
      const context = await browser.newContext({viewport: {width: sample.width, height: 900}});
      await context.addCookies([{name: 'PHPSESSID', value: sample.session, url: fixture.base}]);
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      for (const route of sample.routes) {
        const label = `${sample.name} ${route}`;
        errors.length = 0;
        const response = await page.goto(fixture.base + route, {waitUntil: 'domcontentloaded'});
        assert.equal(response?.status(), 200, `${label}: HTTP ${response?.status()}`);
        assert.equal(new URL(page.url()).pathname, route, `${label}: unexpected redirect`);
        const result = await page.evaluate(() => ({
          theme: document.documentElement.getAttribute('data-bs-theme'),
          title: document.title,
          heading: [...document.querySelectorAll('main h1, main h2, main h3, .content-wrapper h1, .content-wrapper h2, .content-wrapper h3')]
            .some(element => element.getClientRects().length > 0 && element.textContent.trim()),
          overflow: document.documentElement.scrollWidth - window.innerWidth,
          overflowing: [...document.querySelectorAll('body *')].map(element => ({
            tag: element.tagName.toLowerCase(),
            className: typeof element.className === 'string' ? element.className.slice(0, 100) : '',
            right: Math.round(element.getBoundingClientRect().right),
          })).filter(element => element.right > window.innerWidth + 1).slice(0, 12),
        }));
        assert.ok(result.title, `${label}: missing document title`);
        assert.ok(result.heading, `${label}: no visible page heading`);
        if (sample.theme) assert.equal(result.theme, sample.theme, `${label}: theme did not match account`);
        if (result.overflow > 1) {
          await page.screenshot({path: path.join(shots, `${sample.name}-${path.basename(route, '.php')}-overflow.png`), fullPage: true});
        }
        assert.ok(result.overflow <= 1, `${label}: document overflows ${result.overflow}px; elements: ${JSON.stringify(result.overflowing)}`);
        assert.deepEqual(errors, [], `${label}: browser runtime error`);
        if (sample.capture) {
          await page.evaluate(() => document.fonts.ready);
          await page.screenshot({path: path.join(shots, `${sample.name}-${path.basename(route, '.php')}.png`), fullPage: true});
        }
        checked++;
      }
      await context.close();
    }
    console.log(`Authenticated route smoke: ${checked} route/viewport cases passed (${agentRoutes.length} agent, ${adminRoutes.length} admin, ${portalRoutes.length} portal routes).`);
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
