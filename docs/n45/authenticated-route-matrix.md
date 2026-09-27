# Authenticated route matrix

The PR-to-`next` database job runs `tests/field/route-smoke.cjs` against a disposable MariaDB install and PHP server. Accounts, sessions, clients, tickets, and portal records are synthetic. The check visits each route directly after authentication and requires HTTP 200, no redirect, a visible page heading, a document title, no browser console error, no failed same-origin script/stylesheet/image/font response, and no page-wide horizontal overflow. Agent and admin pages must render the signed-in account's light or dark theme. The suite collects route failures before failing the job so one run exposes the whole batch.

| Surface | Routes | Viewports and account |
| --- | --- | --- |
| Agent | All 25 global sidebar destinations, every client sidebar destination with a synthetic client, representative client and ticket details, and other primary workspaces (58 routes) | 1440px light admin and 393px dark admin; the 20 core routes also run at 768px dark |
| Admin | Users, audit logs, API keys | 1440px light admin; 768px dark admin; 393px dark admin |
| Client portal | Overview, requests, tickets and detail, document list and detail, billing and payment configuration, reviews, technology, contacts, activity, account and ticket creation (21 routes) | 1440px and 393px portal contact |

The job uploads synthetic screenshots as the `n45-authenticated-route-smoke` artifact for seven days. Captures are review evidence, not an approved pixel baseline. The narrow agent case uses a desktop browser at a narrow width; physical Android and PWA routing remain separate acceptance checks. Ticket status badges and empty portal request tables receive additional readable-state checks.

## Remaining coverage

- Add signed-in navigation and actions, including forms, modals, and failed data requests. The existing ticket/Field Mode browser suite covers selected interactions; this route suite now checks failed same-origin page assets.
- Extend to admin settings, login, other detail records, payment-provider-enabled states, add/edit flows, and intentionally excluded or redirected routes. The suite covers visible navigation destinations rather than claiming every internal PHP endpoint is an application page.
- Capture both account themes at each relevant width, review the images, and establish a maintained visual baseline with explicit change approval.
- Witness signed-in portal and Field Mode behavior on physical Android Chrome/PWA, including offline, camera, location, and recovery paths.
