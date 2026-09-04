-- Reconcile the live ITFlow service catalog with N45's approved August 2026 rate card.
-- This script is idempotent. It updates matching service codes, inserts missing
-- services, and archives superseded starter services without deleting history.

START TRANSACTION;

CREATE TEMPORARY TABLE service_catalog (
    product_name VARCHAR(200) NOT NULL,
    product_code VARCHAR(200) NOT NULL,
    product_price DECIMAL(15,2) NOT NULL,
    category_name VARCHAR(200) NOT NULL,
    product_description TEXT NOT NULL,
    PRIMARY KEY (product_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO service_catalog
    (product_name, product_code, product_price, category_name, product_description)
VALUES
    ('Managed Care', 'MS-CARE', 175.00, 'Managed Services', 'Per user, per month; $1,750 monthly minimum. Business-hours support, endpoint and Microsoft 365 administration, monitoring, documentation, vendor coordination and technology reviews. Licenses, backup, projects and after-hours work are separate.'),
    ('Managed Server', 'MS-SRV', 250.00, 'Managed Services', 'Per server, per month. Monitoring, patching and administration. Backup, security licensing and project work are separate.'),
    ('Managed Network - Site', 'MS-NET-SITE', 249.00, 'Managed Services', 'Per site, per month. Includes management of one firewall, one switch and up to three access points. Vendor subscriptions are separate.'),
    ('Additional Network Device', 'MS-NET', 25.00, 'Managed Services', 'Per additional switch or access point, per month. Monitoring, firmware and configuration management.'),
    ('Additional Managed Firewall', 'MS-FW', 75.00, 'Managed Services', 'Per additional firewall, per month. Monitoring, firmware and routine rule management; vendor subscriptions are separate.'),
    ('Co-Managed IT', 'MS-COMG', 75.00, 'Managed Services', 'Per user, per month. Tooling, documentation and escalation support alongside an internal IT team.'),
    ('Microsoft 365 Management', 'MS-M365', 25.00, 'Managed Services', 'Per user, per month; $375 monthly minimum when sold outside Managed Care. Microsoft licenses and project work are separate.'),
    ('Managed Automation Support', 'AUTO-MGD', 250.00, 'Managed Services', 'Starting monthly price for monitoring and minor maintenance of documented production automations. New workflows and material changes are separate projects.'),
    ('Virtual CIO', 'MS-VCIO', 750.00, 'Consulting', 'Starting monthly price for strategic planning, budgeting, roadmap ownership and quarterly business reviews when sold separately.'),

    ('Endpoint Protection', 'SEC-EDR', 15.00, 'Security', 'Per endpoint, per month. Activate only after an approved endpoint protection platform has been assigned and tested.'),
    ('DNS Filtering', 'SEC-DNS', 5.00, 'Security', 'Per endpoint, per month. Malicious and category-based web filtering.'),
    ('Email Security', 'SEC-MAIL', 6.00, 'Security', 'Per mailbox, per month. Spam, phishing and malware filtering.'),
    ('Security Awareness Training', 'SEC-SAT', 5.00, 'Security', 'Per user, per month. Training campaigns and simulated phishing.'),
    ('Password Manager', 'SEC-PWD', 5.00, 'Security', 'Per user, per month. Managed password vault.'),
    ('Dark Web Monitoring', 'SEC-DWM', 25.00, 'Security', 'Per client, per month. Credential exposure monitoring for client domains.'),
    ('Microsoft 365 Security Triage', 'SEC-M365-TRIAGE', 495.00, 'Security', 'Prepaid assessment for one Microsoft 365 tenant with up to 25 users. Includes findings, priority actions and a review call.'),
    ('Security & Continuity Assessment', 'SEC-ASSESS', 1950.00, 'Security', 'Starting price for one site with up to 25 users. Covers security posture, identity, network, backup and continuity risks with a prioritized remediation plan; not a certification audit.'),

    ('Managed Backup - Workstation', 'BU-WKS', 25.00, 'Backup', 'Per workstation, per month. Image-based backup with offsite copy; storage and retention limits apply.'),
    ('Managed Backup - Server', 'BU-SRV', 125.00, 'Backup', 'Per server, per month. Image-based backup with offsite copy and scheduled restore validation; storage and retention limits apply.'),
    ('Microsoft 365 Backup', 'BU-M365', 8.00, 'Backup', 'Per user, per month. Mail, calendar, contacts, OneDrive and SharePoint; storage and retention limits apply.'),
    ('Offsite Backup Storage', 'BU-STOR', 25.00, 'Backup', 'Per terabyte, per month. Offsite retention beyond the included allowance.'),

    ('Microsoft 365 Business Basic', 'LIC-M365BB', 7.00, 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'),
    ('Microsoft 365 Business Standard', 'LIC-M365BS', 14.00, 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'),
    ('Microsoft 365 Business Premium', 'LIC-M365BP', 22.00, 'Licensing', 'Per user, per month on an annual commitment. Confirm the current vendor price before quoting.'),
    ('Microsoft 365 Exchange Online Plan 1', 'LIC-M365EX', 4.00, 'Licensing', 'Per mailbox, per month on an annual commitment. Confirm the current vendor price before quoting.'),
    ('Third Party Software Subscription', 'LIC-3P', 0.00, 'Software Sales', 'Generic resold subscription. Set the name and price per client.'),

    ('Remote Support', 'LAB-REM', 175.00, 'Labor', 'Per hour. Remote support outside an agreement, billed in 15-minute increments.'),
    ('Onsite Support', 'LAB-ONS', 195.00, 'Labor', 'Per hour. Onsite support with a two-hour minimum.'),
    ('After Hours Support', 'LAB-AH', 275.00, 'Labor', 'Per hour. Scheduled work outside business hours with a two-hour minimum.'),
    ('Emergency Response', 'LAB-EMERG', 450.00, 'Labor', 'Per hour. Priority-one emergency response with a two-hour minimum.'),
    ('Project Engineering', 'LAB-PRJ', 225.00, 'Labor', 'Per hour. Project delivery, implementation and automation work.'),
    ('Network Engineering', 'LAB-NET', 225.00, 'Labor', 'Per hour. Network, firewall and server engineering.'),
    ('Consulting', 'LAB-CON', 225.00, 'Consulting', 'Per hour. Advisory, design and assessment work.'),
    ('Data Recovery', 'LAB-REC', 225.00, 'Labor', 'Per hour. Best-effort recovery; successful recovery is not guaranteed.'),
    ('End User Training', 'LAB-TRN', 175.00, 'Training', 'Per hour. Group or one-to-one training.'),
    ('Block Hours - 10 Hours', 'LAB-BLK10', 1650.00, 'Labor', 'Prepaid block of ten support hours, valid for twelve months.'),
    ('Block Hours - 20 Hours', 'LAB-BLK20', 3200.00, 'Labor', 'Prepaid block of twenty support hours, valid for twelve months.'),
    ('Travel - Hour', 'LAB-TRAVEL', 125.00, 'Reimbursable Expenses', 'Per hour of travel outside the included service radius.'),
    ('Mileage', 'LAB-MILE', 0.76, 'Reimbursable Expenses', 'Per mile beyond the included service radius. Uses the IRS business mileage rate effective July 1, 2026.'),

    ('Managed Care Onboarding', 'ONB-CLIENT', 1750.00, 'Onboarding', 'Starting price equal to one month of Managed Care. Discovery, documentation, tooling deployment and security baseline; $1,750 minimum.'),
    ('Stabilization Sprint', 'PRJ-STABILIZE', 2500.00, 'Projects', 'Starting price for a fixed-scope remediation sprint. Typical engagements range from $2,500 to $5,000.'),
    ('Stabilization Sprint Deposit', 'PRJ-STABILIZE-DEP', 1250.00, 'Projects', 'Standard 50 percent deposit against a $2,500 Stabilization Sprint. Adjust when the approved project total is higher.'),
    ('User Onboarding', 'ONB-USER', 250.00, 'Onboarding', 'Per user outside Managed Care. Account, licensing, access, security enrollment and documentation. Hardware and device deployment are separate.'),
    ('User Offboarding', 'ONB-OFFBOARD', 250.00, 'Onboarding', 'Per user outside Managed Care. Access revocation, session reset, license recovery, data handoff and documentation.'),
    ('Device Deployment', 'PRJ-WKS', 350.00, 'Projects', 'Per workstation. Build, enrollment, standard application setup, data migration and deployment; hardware is separate.'),
    ('Server Deployment', 'PRJ-SRV', 2500.00, 'Projects', 'Starting price per server. Build, configuration, migration and documentation; licenses and hardware are separate.'),
    ('Firewall Deployment', 'PRJ-FW', 850.00, 'Projects', 'Per firewall. Configuration, cutover and documentation.'),
    ('Wireless Survey and Deployment', 'PRJ-WIFI', 1500.00, 'Projects', 'Starting price per site. Survey, design, installation and tuning; hardware and cabling are separate.'),
    ('Microsoft 365 Migration', 'PRJ-MAIL', 150.00, 'Projects', 'Per user with a $1,500 minimum. Migration, cutover and endpoint reconfiguration; licenses are separate.'),
    ('Data Migration', 'PRJ-DATA', 1500.00, 'Projects', 'Starting price for a file share or data platform migration. Final pricing follows discovery.'),
    ('Equipment Disposal', 'PRJ-DISP', 25.00, 'Projects', 'Per device. Secure data destruction and certified recycling.'),
    ('Network Assessment & Diagram', 'PRJ-NET-ASSESS', 1500.00, 'Projects', 'Starting price for an onsite configuration review, inventory, diagram, risk-ranked findings and client readout.'),
    ('Small Office Network Deployment', 'PRJ-NET-BUILD', 4500.00, 'Projects', 'Starting price for a small-office network replacement. Hardware, subscriptions and structured cabling are quoted separately.'),
    ('Automation Discovery', 'AUTO-DISC', 750.00, 'Consulting', 'Process mapping, system review and a prioritized automation plan with scope and expected business value.'),
    ('Automation Sprint', 'AUTO-SPRINT', 2500.00, 'Projects', 'Starting price for one production workflow with testing, documentation and handoff. Material third-party license costs are separate.'),
    ('Same-Day Remote Rescue', 'SUP-RESCUE', 450.00, 'Support', 'Prepaid same-day remote response covering the first two hours. Additional time is billed at the Remote Support rate.');

-- Preserve the existing travel item ID while adopting the approved name and code.
UPDATE products
SET product_code = 'LAB-TRAVEL'
WHERE product_code = 'LAB-TRIP'
  AND NOT EXISTS (
      SELECT 1
      FROM products AS existing_travel
      WHERE existing_travel.product_code = 'LAB-TRAVEL'
  );

UPDATE products AS product
JOIN service_catalog AS catalog
  ON catalog.product_code = product.product_code
LEFT JOIN categories AS category
  ON category.category_name = catalog.category_name
 AND category.category_type = 'Income'
SET product.product_name = catalog.product_name,
    product.product_type = 'service',
    product.product_price = catalog.product_price,
    product.product_category_id = COALESCE(category.category_id, 0),
    product.product_description = catalog.product_description,
    product.product_currency_code = 'USD',
    product.product_tax_id = 0,
    product.product_archived_at = NULL;

INSERT INTO products (
    product_name,
    product_type,
    product_code,
    product_price,
    product_category_id,
    product_description,
    product_currency_code,
    product_tax_id
)
SELECT
    catalog.product_name,
    'service',
    catalog.product_code,
    catalog.product_price,
    COALESCE(category.category_id, 0),
    catalog.product_description,
    'USD',
    0
FROM service_catalog AS catalog
LEFT JOIN categories AS category
  ON category.category_name = catalog.category_name
 AND category.category_type = 'Income'
WHERE NOT EXISTS (
    SELECT 1
    FROM products AS existing_product
    WHERE existing_product.product_code = catalog.product_code
);

-- These generic starter entries duplicate the approved catalog or represent
-- services N45 does not currently sell as standard offers. Archive them so
-- they remain recoverable and historical references remain intact.
UPDATE products
SET product_archived_at = COALESCE(product_archived_at, CURRENT_TIMESTAMP)
WHERE product_type = 'service'
  AND product_code IN (
      'MS-WKS',
      'MS-HD',
      'MS-RMM',
      'MS-PATCH',
      'CLD-SRV',
      'WEB-HOST',
      'WEB-MAIL',
      'WEB-DOM',
      'WEB-SSL',
      'TEL-SEAT',
      'TEL-DID',
      'TEL-SIP',
      'LAB-WKD',
      'LAB-WEB',
      'PRJ-WEB'
  );

DROP TEMPORARY TABLE service_catalog;

COMMIT;
