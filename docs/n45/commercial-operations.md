# Commercial operations

N45's commercial operations layer closes the gap between sold work, delivery, vendor cost, and billing without replacing ITFlow's native records. Quotes, invoices, recurring invoices, products, stock movements, vendors, projects, tickets, expenses, contracts, and ticket time remain authoritative.

## Billing review

`agent/billing_review.php` presents resolved billable tickets, client-attributed expenses, and fully received customer purchase items before invoicing. Ticket candidates use the immutable agreement decision when one exists, fall back to an agreement effective on the service date, and use the agreement's standard rate before the global hourly rate. Fulfilled products carry their native product, purchase-order cost, customer price, and receiving provenance into the same queue. Reviewers can adjust quantity, price, and known cost; approve, hold, or exclude selected work; and create a traceable draft invoice for one client. Holds return to the queue. Approved sources are locked and linked when the draft invoice is created so a source cannot be billed twice.

## Subscription reconciliation and profitability

`agent/subscriptions.php` stores observations against native vendors, clients, products, and optional agreements, then compares purchased, managed, and actively billed recurring quantities. The vendor ID plus external subscription ID is the stable source identity. Applying an exception updates exactly one matching active recurring-invoice line and recalculates that invoice. Product commercial profiles supply unit cost, quantity basis, recurring-service status, preferred vendor, SKU, and reorder threshold to every commercial workflow.

`agent/profitability.php` reports monthly issued revenue against ticket-time labor cost, active subscription cost, and client-attributed expenses for each client and agreement. Reviewed revenue follows its recorded agreement, labor follows the ticket's immutable agreement decision, and subscription cost follows the agreement selected on its native vendor record. Legacy invoice revenue, unclassified ticket time, and client expenses remain visible in an explicit outside-agreement/unallocated row. Draft and cancelled invoices are excluded, and subscription cost stays separate from expenses so operators can avoid double-counting vendor spend.

## Approved quote to delivery

Acceptance creates a hash-addressed snapshot of the approved quote scope and line items. `agent/quote_delivery.php` then creates one owned project, applies a selected project template with pinned runbook versions, creates the template's tickets, and carries the approved scope into each delivery stage. In the same transaction it can reuse the invoice created by ITFlow's existing quote conversion, create a draft invoice when none exists, create recurring service lines for profiled products, and create vendor purchase orders for stock shortages.

The delivery plan is locked before outputs are created. Replays cannot create a second project, and later quote edits cannot alter the approved snapshot. A purchase-order option fails closed when a shortage has no preferred vendor.

## Purchasing and fulfillment

`agent/purchasing.php` is intentionally lightweight. Purchase orders reference existing vendors, products, quotes, projects, clients, and optional invoice provenance. Operators mark a draft ordered, receive positive whole-unit quantities, and post those receipts into native `product_stock`. When a customer purchase item is fully received it becomes a traceable billing-review candidate; invoicing links the purchase item and native invoice so it cannot be billed twice. Status advances from `draft` to `ordered`, `partially_received`, and `received`; over-receipt is rejected transactionally.

## Rollout and recovery

Migration `n45-0027-commercial-operations` is additive. It creates only review, snapshot, profile, reconciliation, and fulfillment records. Apply it through the N45 migration runner before exposing the new pages. The release gate validates its full column/index fingerprint against `db.sql`, exercises the commercial integration contract, and runs the existing disposable-database suite.

Rollback requires matching application and database snapshots. Preserve billing decisions, approved-scope snapshots, subscription observations, and fulfillment history; do not delete those records while invoices, projects, tickets, or stock movements created from them remain in use.
