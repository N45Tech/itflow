# Authenticated route matrix

The PR-to-`next` database job runs `tests/field/route-smoke.cjs` against a disposable MariaDB install and PHP server. Accounts, sessions, clients, tickets, and portal records are synthetic. The check visits each route directly after authentication and requires HTTP 200, no redirect, a visible page heading, a document title, no uncaught browser error, and no page-wide horizontal overflow. Agent and admin pages must render the signed-in account's light or dark theme.

| Surface | Routes | Viewports and account |
| --- | --- | --- |
| Agent | Dashboard, clients, operations, tickets, agreements, invoices, assets, software, documentation | 1440px light admin; 768px dark admin; 393px dark admin |
| Admin | Users, audit logs, API keys | 1440px light admin; 768px dark admin; 393px dark admin |
| Client portal | Overview, tickets, documents, invoices | 1440px and 393px portal contact |

The job uploads synthetic screenshots as the `n45-authenticated-route-smoke` artifact for seven days. Captures are review evidence, not an approved pixel baseline. The narrow agent case uses a desktop browser at a narrow width; physical Android and PWA routing remain separate acceptance checks.

## Remaining coverage

- Add signed-in navigation and actions, including forms, modals, broken same-origin assets, and failed requests. The existing ticket/Field Mode browser suite covers selected interactions.
- Extend to the remaining agent, admin, and portal routes, including login, settings, client-scoped detail views, and intentionally excluded or redirected routes.
- Capture both account themes at each relevant width, review the images, and establish a maintained visual baseline with explicit change approval.
- Witness signed-in portal and Field Mode behavior on physical Android Chrome/PWA, including offline, camera, location, and recovery paths.
