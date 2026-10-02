# Authenticated route matrix

The PR-to-`next` database job runs `tests/field/route-smoke.cjs` against a disposable MariaDB install and PHP server. Accounts, sessions, clients, tickets, and portal records are synthetic. The check visits each route directly after authentication and requires HTTP 200, no redirect, a visible page heading, a document title, no browser console error, no failed same-origin script/stylesheet/image/font response, and no page-wide horizontal overflow. Agent and admin pages must render the signed-in account's light or dark theme. The suite collects route failures before failing the job so one run exposes the whole batch.

| Surface | Routes | Viewports and account |
| --- | --- | --- |
| Agent | All 25 global sidebar destinations, every client sidebar destination with a synthetic client, representative client and ticket details, and other primary workspaces (58 routes) | 1440px light admin and 393px dark admin; the 20 core routes also run at 768px dark |
| Agent | Dashboard, clients, operations, tickets, agreements, agreement setup, two competing agreement details, business reviews, invoices, assets, software, documentation | 1440px light admin; 768px dark admin; 393px dark admin |
| Admin | Users, audit logs, API keys | 1440px light admin; 768px dark admin; 393px dark admin |
| Client portal | Overview, requests, tickets and detail, document list and detail, billing and payment configuration, reviews, technology, contacts, activity, account and ticket creation (21 routes) | 1440px and 393px portal contact |

The job uploads synthetic screenshots as the `n45-authenticated-route-smoke` artifact for seven days. Captures are review evidence, not an approved pixel baseline. The narrow agent case uses a desktop browser at a narrow width; physical Android and PWA routing remain separate acceptance checks. Ticket status badges and empty portal request tables receive additional readable-state checks.

The agreement fixture publishes two definitions for one synthetic client with different effective dates. The browser checks that the older detail page identifies the newer agreement as the current ticket rule, that the selected detail page identifies itself, that setup explains the separate client-acceptance gate, and that Business Reviews lists both schedules. These are synthetic selection and rendering checks; they do not verify any customer-approved source.

The focused `tests/field/record-controls.cjs` check uses the same disposable fixture. At 1440px light, 768px dark and 393px dark it verifies all four ticket visibility choices, native keyboard selection, Work note focus, draft preservation, cancel behavior and absence of accidental submissions. It opens the actual AJAX domain modal and verifies tagged DNS/WHOIS fields grow when their hidden tab opens, shrink with shorter content, and retain a scroll fallback for long records. Captures wait for the tab's fade to finish.

## Remaining coverage

- Extend signed-in navigation and actions to other forms, modals, and failed data requests. Ticket/Field Mode and record-control checks cover selected interactions only.
- Extend to admin settings, login, other detail records, payment-provider-enabled states, add/edit flows, and intentionally excluded or redirected routes. Visible navigation destinations do not cover every internal PHP endpoint.
- Capture both account themes at each relevant width, review the images, and establish a maintained visual baseline with explicit change approval.
- Witness signed-in portal and Field Mode behavior on physical Android Chrome/PWA, including offline, camera, location, and recovery paths.
