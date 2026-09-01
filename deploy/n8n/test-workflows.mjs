import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const workflowDirectory = join(root, 'workflows');
const files = (await readdir(workflowDirectory)).filter((file) => file.endsWith('.json')).sort();
assert.equal(files.length, 7, 'Expected seven generated workflows');

const workflows = new Map();
for (const file of files) {
  const raw = await readFile(join(workflowDirectory, file), 'utf8');
  assert(!/(Bearer\s+[A-Za-z0-9_-]{20,}|Token\s+[A-Za-z0-9_-]{20,})/.test(raw), `${file} contains a credential-like value`);
  const workflow = JSON.parse(raw);
  workflows.set(workflow.name, workflow);
  const names = new Set(workflow.nodes.map((node) => node.name));
  assert.equal(names.size, workflow.nodes.length, `${file} contains duplicate node names`);
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
    monitor: { id: 42, name: 'Acme Dental :: Main Office :: Internet', hostname: 'edge-01', url: 'https://example.net/status' },
    heartbeat: { status: 0, time: '2026-08-25T10:00:00Z' },
  },
});
assert.equal(down[0].json.source, 'uptime_kuma');
assert.equal(down[0].json.state, 'open');
assert.equal(down[0].json.identity.client.name, 'Acme Dental');
assert.equal(down[0].json.identity.location.name, 'Main Office');
assert.equal(down[0].json.identity.external_name, 'Internet');
assert.equal(down[0].json.identity.options.create_client, false);
assert.equal(down[0].json.identity.options.create_location, false);
assert.equal(down[0].json.identity.options.create_asset, false);

const recovered = code(broker, 'Normalize Event', {
  body: {
    monitor: { name: 'Acme Dental :: Main Office :: Internet' },
    heartbeat: { monitorID: 42, status: 1, time: '2026-08-25T10:05:00Z' },
  },
});
assert.equal(recovered[0].json.state, 'resolved');
assert.equal(recovered[0].json.identity.external_id, '42');
assert.equal(recovered[0].json.incident_key, down[0].json.incident_key);

const canonical = {
  source: 'backup', event_id: 'backup-1', incident_key: 'backup:infra01',
  state: 'open', identity: { external_id: 'infra01', client: { name: 'N45 Technologies' } },
};
const hardenedCanonical = code(broker, 'Normalize Event', { body: canonical })[0].json;
assert.equal(hardenedCanonical.source, canonical.source);
assert.equal(hardenedCanonical.identity.client.name, 'N45 Technology Solutions');
assert.equal(hardenedCanonical.identity.options.create_client, false);
assert.equal(hardenedCanonical.identity.options.create_location, false);
assert.equal(hardenedCanonical.identity.metadata.client_alias_applied, true);

const netbox = code('N45 - NetBox Entity Reconciliation', 'Normalize NetBox Devices', {
  results: [{
    id: 8, name: 'switch-01', serial: 'ABC123',
    tenant: { id: 2, name: 'Acme Dental' }, site: { id: 3, name: 'Main Office' },
    device_type: { model: 'C9300', manufacturer: { name: 'Cisco' } },
    primary_ip: { address: '192.0.2.10/24' }, status: { label: 'Active' },
  }],
});
assert.equal(netbox[0].json.external_id, '8');
assert.equal(netbox[0].json.client.name, 'Acme Dental');
assert.equal(netbox[0].json.client.external_id, '2');
assert.equal(netbox[0].json.location.name, 'Main Office');
assert.equal(netbox[0].json.location.external_id, '3');
assert.equal(netbox[0].json.asset.serial, 'ABC123');
assert.equal(netbox[0].json.asset.ip, '192.0.2.10');

const zones = code('N45 - Cloudflare Domain Reconciliation', 'Map Zones to Clients', {
  result: [{ id: 'zone-1', name: 'n45tech.com', status: 'active' }, { id: 'zone-2', name: 'unmapped.example' }],
});
assert.equal(zones.length, 1);
assert.equal(zones[0].json.domain.name, 'n45tech.com');
assert.equal(zones[0].json.options.create_domain, true);

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

console.log(`Validated ${files.length} workflows and representative source payloads.`);
