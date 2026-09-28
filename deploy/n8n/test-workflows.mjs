import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const workflowDirectory = join(root, 'workflows');
const files = (await readdir(workflowDirectory)).filter((file) => file.endsWith('.json')).sort();
assert.equal(files.length, 7, 'Expected seven generated workflows');
assert(!files.includes('netbox-reconciliation.json'), 'Retired NetBox workflow is still shipped');

const workflows = new Map();
for (const file of files) {
  const raw = await readFile(join(workflowDirectory, file), 'utf8');
  assert(!/(Bearer\s+[A-Za-z0-9_-]{20,}|Token\s+[A-Za-z0-9_-]{20,})/.test(raw), `${file} contains a credential-like value`);
  assert(!/new URL(?:SearchParams)?\s*\(/.test(raw), `${file} uses URL globals unavailable in the n8n task runner`);
  const workflow = JSON.parse(raw);
  workflows.set(workflow.name, workflow);
  const names = new Set(workflow.nodes.map((node) => node.name));
  assert.equal(names.size, workflow.nodes.length, `${file} contains duplicate node names`);
  assert.equal(workflow.settings.saveDataSuccessExecution, 'none', `${file} retains successful execution payloads`);
  assert.equal(workflow.settings.saveDataErrorExecution, 'none', `${file} retains failed execution payloads`);
  assert.equal(workflow.settings.saveManualExecutions, false, `${file} retains manual execution payloads`);
  for (const [source, outputs] of Object.entries(workflow.connections)) {
    assert(names.has(source), `${file} has a connection from an unknown node: ${source}`);
    for (const channel of Object.values(outputs)) {
      for (const branch of channel) {
        for (const target of branch) {
          assert(names.has(target.node), `${file} connects to an unknown node: ${target.node}`);
        }
      }
    }
  }
}

function code(workflowName, nodeName, input, vars = {}) {
  const workflow = workflows.get(workflowName);
  assert(workflow, `Missing workflow: ${workflowName}`);
  const codeNode = workflow.nodes.find((node) => node.name === nodeName);
  assert(codeNode, `Missing node: ${nodeName}`);
  const values = Array.isArray(input) ? input : [input];
  const items = values.map((json) => ({ json }));
  const execute = new Function('$input', '$vars', codeNode.parameters.jsCode);
  return execute({ first: () => items[0], all: () => items }, vars);
}

const broker = 'N45 - ITFlow Operations Event Broker';
const down = code(broker, 'Normalize Event', {
  body: {
    monitor_id: '0123456789abcdef0123456789abcdef',
    monitor_name: 'Acme Dental :: Main Office :: Internet',
    monitor_target: 'https://example.net/status',
    monitor_type: 'website',
    monitor_category: 'Customer edge',
    monitor_status: 'offline',
    timestamp: 1787652000,
    monitor_errors: { 'New York': 'timeout', London: 'http code 503' },
  },
});
assert.equal(down[0].json.source, 'hetrix');
assert.equal(down[0].json.state, 'open');
assert.equal(down[0].json.severity, 'high');
assert.equal(down[0].json.occurred_at, '2026-08-25T10:00:00.000Z');
assert.equal(down[0].json.identity.client.name, 'Acme Dental');
assert.equal(down[0].json.identity.location.name, 'Main Office');
assert.equal(down[0].json.identity.external_name, 'Internet');
assert.equal(down[0].json.identity.external_id, '0123456789abcdef0123456789abcdef');
assert.equal(down[0].json.identity.metadata.monitor_type, 'website');
assert.equal(down[0].json.identity.metadata.monitor_category, 'Customer edge');
assert.match(down[0].json.description, /New York: timeout/);
assert.match(down[0].json.description, /London: http code 503/);
assert.equal(down[0].json.identity.options.create_client, false);
assert.equal(down[0].json.identity.options.create_location, false);
assert.equal(down[0].json.identity.options.create_asset, false);
assert.equal(down[0].json.request_type_key, 'monitoring-alert');
assert.equal(down[0].json.contact_mode, 'none');

const routedHetrix = code(broker, 'Normalize Event', {
  body: {
    monitor_id: 'fedcba9876543210fedcba9876543210',
    monitor_name: 'N45 Technologies :: Infrastructure :: PSA',
    monitor_target: 'https://psa.n45tech.com',
    monitor_type: 'website',
    monitor_category: 'Core',
    monitor_status: 'offline',
    timestamp: 1788646610,
    monitor_errors: { Dallas: 'connection refused' },
  },
}, {
  N45_EVENT_ROUTING_JSON: JSON.stringify({
    hetrix: { assigned_to: 7, category_id: 12, contact_id: 19, request_type_key: 'Infrastructure Alert', contact_mode: 'none' },
  }),
});
assert.equal(routedHetrix[0].json.occurred_at, '2026-09-05T22:16:50.000Z');
assert.equal(routedHetrix[0].json.title, 'Monitoring alert: PSA');
assert.match(routedHetrix[0].json.description, /^PSA \(psa\.n45tech\.com\) is unavailable\./);
assert.equal(routedHetrix[0].json.assigned_to, 7);
assert.equal(routedHetrix[0].json.category_id, 12);
assert.equal(routedHetrix[0].json.contact_id, 19);
assert.equal(routedHetrix[0].json.request_type_key, 'infrastructure-alert');

const recovered = code(broker, 'Normalize Event', {
  body: {
    monitor_id: '0123456789abcdef0123456789abcdef',
    monitor_name: 'Acme Dental :: Main Office :: Internet',
    monitor_target: 'https://example.net/status',
    monitor_type: 'website',
    monitor_category: 'Customer edge',
    monitor_status: 'online',
    timestamp: 1787652300,
  },
});
assert.equal(recovered[0].json.state, 'resolved');
assert.equal(recovered[0].json.identity.external_id, '0123456789abcdef0123456789abcdef');
assert.equal(recovered[0].json.incident_key, down[0].json.incident_key);

assert.throws(() => code(broker, 'Normalize Event', {
  body: {
    source: 'uptime_kuma',
    event_id: 'retired-source-event',
    incident_key: 'uptime_kuma:retired-monitor',
    state: 'open',
    identity: { external_id: 'retired-monitor' },
  },
}), /event source has been retired/);

const canonical = {
  source: 'backup', event_id: 'backup-1', incident_key: 'backup:example-host',
  state: 'open', identity: { external_id: 'example-host', client: { name: 'N45 Technologies' } },
};
const hardenedCanonical = code(broker, 'Normalize Event', { body: canonical })[0].json;
assert.equal(hardenedCanonical.source, canonical.source);
assert.equal(hardenedCanonical.identity.client.name, 'N45 Technology Solutions');
assert.equal(hardenedCanonical.identity.options.create_client, false);
assert.equal(hardenedCanonical.identity.options.create_location, false);
assert.equal(hardenedCanonical.identity.metadata.client_alias_applied, true);

const sanitizedCanonical = code(broker, 'Normalize Event', { body: {
  ...canonical,
  metadata: { api_key: 'must-not-pass', useful: 'retained' },
  identity: {
    ...canonical.identity,
    metadata: { accessToken: 'must-not-pass', device_id: 'device-1' },
  },
  authorization: 'must-not-pass',
} })[0].json;
assert.doesNotMatch(JSON.stringify(sanitizedCanonical), /must-not-pass/);
assert.equal(sanitizedCanonical.metadata.useful, 'retained');
assert.equal(sanitizedCanonical.identity.metadata.device_id, 'device-1');

const brokerWorkflow = workflows.get(broker);
const hetrixWebhook = brokerWorkflow.nodes.find((entry) => entry.name === 'Hetrix Webhook');
assert(hetrixWebhook, 'The broker does not expose a dedicated Hetrix webhook');
assert.equal(hetrixWebhook.parameters.path, 'n45-hetrix-events');
assert.equal(hetrixWebhook.parameters.authentication, 'headerAuth');
assert.equal(hetrixWebhook.credentials.httpHeaderAuth.name, 'N45 Hetrix Webhook');
assert(brokerWorkflow.nodes.some((entry) => entry.name === 'Queue Event' && entry.type === 'n8n-nodes-base.dataTable'));
const queueNode = brokerWorkflow.nodes.find((entry) => entry.name === 'Queue Event');
assert.deepEqual(queueNode.parameters.filters.conditions.map((condition) => condition.keyName), ['source', 'event_id']);
assert(brokerWorkflow.nodes.some((entry) => entry.name === 'Delivery Schedule' && entry.type === 'n8n-nodes-base.scheduleTrigger'));
const deliveryNode = brokerWorkflow.nodes.find((entry) => entry.name === 'Deliver Event to ITFlow');
assert.equal(deliveryNode.onError, 'continueRegularOutput');
assert.equal(deliveryNode.parameters.options.response.response.neverError, true);
assert.equal(deliveryNode.parameters.options.response.response.fullResponse, true);
const dueEvents = code(broker, 'Select Due Events', [
  { id: 1, event_id: 'a-open', incident_key: 'incident:a', occurred_at: '2026-01-01T00:00:00Z', status: 'terminal', next_attempt_at: '2026-01-01T00:00:00Z', payload: JSON.stringify(canonical) },
  { id: 2, event_id: 'a-resolved', incident_key: 'incident:a', occurred_at: '2026-01-01T00:05:00Z', status: 'pending', next_attempt_at: '2026-01-01T00:00:00Z', payload: JSON.stringify(canonical) },
  { id: 3, event_id: 'b-open', incident_key: 'incident:b', occurred_at: '2026-01-01T00:00:00Z', status: 'retry', next_attempt_at: '2026-01-01T00:00:00Z', payload: JSON.stringify(canonical) },
  { id: 4, event_id: 'b-resolved', incident_key: 'incident:b', occurred_at: '2026-01-01T00:05:00Z', status: 'pending', next_attempt_at: '2026-01-01T00:00:00Z', payload: JSON.stringify(canonical) },
]);
assert.deepEqual(dueEvents.map((item) => item.json.event_id), ['b-open']);

const zones = code('N45 - Cloudflare Domain Reconciliation', 'Map Zones to Clients', {
  result: [{ id: 'zone-1', name: 'n45tech.com', status: 'active' }, { id: 'zone-2', name: 'unmapped.example' }],
});
assert.equal(zones.length, 1);
assert.equal(zones[0].json.domain.name, 'n45tech.com');
assert.equal(zones[0].json.options.create_domain, true);
assert.throws(() => code('N45 - Cloudflare Domain Reconciliation', 'Map Zones to Clients', { result: [] }),
  /zero zones/);

const error = code('N45 - Automation Failure to ITFlow', 'Normalize n8n Error', {
  execution: { id: 99, error: { message: 'Connection refused' }, lastNodeExecuted: 'Fetch' },
  workflow: { id: 7, name: 'Nightly backup' },
});
assert.equal(error[0].json.event_id, 'execution:99');
assert.equal(error[0].json.incident_key, 'workflow:7');
assert.match(error[0].json.description, /Connection refused/);
assert.equal(error[0].json.identity.client.name, 'N45 Technology Solutions');
assert.equal(error[0].json.identity.options.create_client, false);
assert.equal(error[0].json.identity.options.create_location, false);

const sourceMappings = {
  intune: [{ scope_id: 'contoso.onmicrosoft.com', tenant_filter: 'contoso.onmicrosoft.com', scope_name: 'Contoso', client_id: 42, location_id: 7, create_asset: false }],
  entra: [{ scope_id: 'contoso.onmicrosoft.com', tenant_filter: 'contoso.onmicrosoft.com', scope_name: 'Contoso', client_id: 42, location_id: 7, create_asset: false }],
  sentinelone: [{ scope_id: 'site-123', site_id: 'site-123', scope_name: 'Contoso HQ', client_id: 42, location_id: 7, create_asset: false }],
};
const sourceVars = {
  N45_DEVICE_SOURCE_MAP_JSON: JSON.stringify(sourceMappings),
  N45_CIPP_BASE_URL: 'https://cipp.example.test',
  N45_SENTINELONE_BASE_URL: 'https://usea1.example.sentinelone.net',
};

const intuneWorkflow = 'N45 - Microsoft Intune Device Reconciliation';
const intuneConfig = code(intuneWorkflow, 'Load Intune Tenant Map', {}, sourceVars);
assert.equal(intuneConfig.length, 1);
assert.equal(intuneConfig[0].json.client_id, 42);
assert.match(intuneConfig[0].json.request_url, /ListGraphRequest/);
assert.match(intuneConfig[0].json.request_url, /manualPagination=true/);
const intune = code(intuneWorkflow, 'Normalize Intune Devices', [{
  Results: [{
    id: 'intune-1', deviceName: 'WS-01', managementState: 'managed',
    lastSyncDateTime: '2026-09-01T10:00:00Z', operatingSystem: 'Windows',
    complianceState: 'compliant', osVersion: '10.0.26100', azureADDeviceId: 'entra-device-1',
    isEncrypted: true, userId: 'user-1', userDisplayName: 'Example User',
    userPrincipalName: 'USER@CONTOSO.TEST', manufacturer: 'Example', model: 'Model 1',
    serialNumber: 'SERIAL-1', wiFiMacAddress: '00:11:22:33:44:55',
    deviceHealthAttestationState: { secureBoot: 'enabled' }, api_key: 'must-not-pass',
  }],
  Metadata: { TenantFilter: 'contoso.onmicrosoft.com' },
}], sourceVars);
assert.equal(intune.length, 2);
assert.equal(intune[0].json.action, 'publish');
assert.equal(intune[0].json.source, 'intune');
assert.equal(intune[0].json.facts.compliance_state, 'compliant');
assert.equal(intune[0].json.facts.secure_boot_state, 'enabled');
assert.equal(intune[0].json.facts.assigned_user.email, 'user@contoso.test');
assert.equal(intune[1].json.action, 'complete');
assert.equal(intune[1].json.reported_count, 1);
assert.doesNotMatch(JSON.stringify(intune), /must-not-pass/);

const entraWorkflow = 'N45 - Microsoft Entra Device Reconciliation';
const entra = code(entraWorkflow, 'Normalize Entra Devices', [{
  Results: [{
    id: 'object-1', deviceId: 'entra-device-1', displayName: 'WS-01', accountEnabled: true,
    operatingSystem: 'Windows', operatingSystemVersion: '10.0.26100',
    approximateLastSignInDateTime: '2026-09-01T09:30:00Z', isManaged: true, isCompliant: true,
  }],
  Metadata: { TenantFilter: 'contoso.onmicrosoft.com' },
}], sourceVars);
assert.equal(entra.length, 2);
assert.equal(entra[0].json.external_id, 'object-1');
assert.equal(entra[0].json.facts.entra_device_id, 'entra-device-1');
assert.equal(entra[1].json.action, 'complete');

const sentinelWorkflow = 'N45 - SentinelOne Agent Reconciliation';
const sentinelConfig = code(sentinelWorkflow, 'Load SentinelOne Site Map', {}, sourceVars);
assert.deepEqual(sentinelConfig[0].json.site_ids, ['site-123']);
assert.match(sentinelConfig[0].json.sites_url, /siteIds=site-123/);
const validatedSites = code(sentinelWorkflow, 'Validate SentinelOne Sites', [{
  data: { sites: [{ id: 'site-123', name: 'Contoso HQ' }] }, pagination: { nextCursor: null },
}], sourceVars);
assert.match(validatedSites[0].json.agents_url, /isDecommissioned=false/);
const sentinel = code(sentinelWorkflow, 'Normalize SentinelOne Agents', [{
  data: [{
    id: 'agent-1', siteId: 'site-123', computerName: 'WS-01', isActive: true,
    infected: false, activeThreats: 0, osName: 'Windows 11', osRevision: '26100',
    agentVersion: '24.1.2', lastActiveDate: '2026-09-01T09:45:00Z',
    serialNumber: 'SERIAL-1', networkInterfaces: [{
      id: 'nic-1', name: 'Ethernet', physical: '00:11:22:33:44:55', inet: ['192.0.2.10'],
    }], access_token: 'must-not-pass',
  }],
  pagination: { nextCursor: null },
}], sourceVars);
assert.equal(sentinel.length, 2);
assert.equal(sentinel[0].json.source, 'sentinelone');
assert.equal(sentinel[0].json.facts.health_state, 'healthy');
assert.equal(sentinel[0].json.network_interfaces[0].mac, '00:11:22:33:44:55');
assert.equal(sentinel[1].json.action, 'complete');
assert.doesNotMatch(JSON.stringify(sentinel), /must-not-pass/);

for (const workflowName of [intuneWorkflow, entraWorkflow, sentinelWorkflow]) {
  const sourceWorkflow = workflows.get(workflowName);
  const fetchNodes = sourceWorkflow.nodes.filter((entry) => entry.name.startsWith('Fetch '));
  const publishNode = sourceWorkflow.nodes.find((entry) => entry.name.startsWith('Publish '));
  assert(fetchNodes.length > 0, `${workflowName} has no source fetch node`);
  for (const fetchNode of fetchNodes) {
    assert.equal(fetchNode.retryOnFail, true, `${fetchNode.name} does not retry`);
    assert.equal(fetchNode.maxTries, 5, `${fetchNode.name} has an unsafe retry count`);
    assert.equal(fetchNode.parameters.options.pagination.pagination.paginationMode, 'responseContainsNextURL');
  }
  assert.equal(publishNode.parameters.options.batching.batch.batchSize, 1, `${workflowName} does not publish serially`);
  assert.equal(sourceWorkflow.settings.timezone, 'UTC');
  assert.equal(sourceWorkflow.settings.executionTimeout, 3600, `${workflowName} exceeds the n8n instance timeout ceiling`);
  assert(sourceWorkflow.nodes.some((entry) => entry.type === 'n8n-nodes-base.scheduleTrigger'), `${workflowName} is not scheduled`);
}

for (const workflowName of [intuneWorkflow, entraWorkflow]) {
  const fetchNode = workflows.get(workflowName).nodes.find((entry) => entry.name.startsWith('Fetch '));
  assert.equal(fetchNode.parameters.authentication, 'genericCredentialType');
  assert.equal(fetchNode.parameters.genericAuthType, 'oAuth2Api');
  assert.equal(fetchNode.credentials.oAuth2Api.name, 'N45 CIPP API');
}
const sentinelFetchNodes = workflows.get(sentinelWorkflow).nodes.filter((entry) => entry.name.startsWith('Fetch SentinelOne'));
assert.equal(sentinelFetchNodes.length, 2);
for (const sentinelFetchNode of sentinelFetchNodes) {
  assert.equal(sentinelFetchNode.parameters.genericAuthType, 'httpHeaderAuth');
  assert.equal(sentinelFetchNode.credentials.httpHeaderAuth.name, 'N45 SentinelOne API');
}

const sourceFailure = code('N45 - Automation Failure to ITFlow', 'Normalize Device Source Failure', {
  execution: { id: 101, startedAt: '2026-09-01T10:00:00Z', error: { message: 'Bearer must-not-pass failed' } },
  workflow: { id: 8, name: intuneWorkflow },
}, sourceVars);
assert.equal(sourceFailure.length, 1);
assert.equal(sourceFailure[0].json.action, 'failure');
assert.equal(sourceFailure[0].json.source, 'intune');
assert.doesNotMatch(sourceFailure[0].json.error, /must-not-pass/);

const cipp = code('N45 - CIPP Alerts to ITFlow', 'Normalize CIPP Alert', {
  body: {
    source: 'CIPP', schemaVersion: '1.0', tenant: 'contoso.onmicrosoft.com',
    title: 'Administrator without MFA', generatedAt: '2026-09-05T12:00:00Z',
    alertCount: 1, invoking: 'mfa-report',
    payload: [{ displayName: 'Example Admin', status: 'At risk', accessToken: 'must-not-pass' }],
  },
}, {
  N45_CIPP_ALERT_TENANT_MAP_JSON: JSON.stringify({
    'contoso.onmicrosoft.com': { client_id: 42, client_name: 'Contoso', location_id: 7, assigned_to: 3, category_id: 12, contact_id: 19 },
  }),
});
assert.equal(cipp[0].json.identity.client.id, 42);
assert.equal(cipp[0].json.identity.location.id, 7);
assert.equal(cipp[0].json.assigned_to, 3);
assert.equal(cipp[0].json.category_id, 12);
assert.equal(cipp[0].json.contact_id, 19);
assert.equal(cipp[0].json.severity, 'high');
assert.equal(cipp[0].json.request_type_key, 'microsoft-365-alert');
assert.doesNotMatch(JSON.stringify(cipp), /must-not-pass/);
assert.throws(() => code('N45 - CIPP Alerts to ITFlow', 'Normalize CIPP Alert', {
  body: { source: 'CIPP', schemaVersion: '1.0', tenant: 'unmapped.onmicrosoft.com', title: 'Alert', alertCount: 1 },
}, { N45_CIPP_ALERT_TENANT_MAP_JSON: '{}' }), /no explicit ITFlow client ID mapping/);

console.log(`Validated ${files.length} workflows and representative source payloads.`);
