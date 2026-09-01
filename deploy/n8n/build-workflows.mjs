import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
  loadCippSourceConfig,
  loadSentinelOneConfig,
  normalizeDeviceSourceFailure,
  normalizeEntra,
  normalizeIntune,
  normalizeSentinelOne,
  validateSentinelOneSites,
} from './device-source-code.mjs';

const root = dirname(fileURLToPath(import.meta.url));
const output = join(root, 'workflows');

const credentials = {
  webhook: { httpHeaderAuth: { id: 'replace-n45-webhook-auth', name: 'N45 Integration Webhook' } },
  itflow: { httpHeaderAuth: { id: 'replace-n45-itflow-api', name: 'N45 ITFlow API' } },
  netbox: { httpHeaderAuth: { id: 'replace-n45-netbox-api', name: 'N45 NetBox API' } },
  cloudflare: { httpHeaderAuth: { id: 'replace-n45-cloudflare-api', name: 'N45 Cloudflare API' } },
  cipp: { oAuth2Api: { id: 'replace-n45-cipp-api', name: 'N45 CIPP API' } },
  sentinelone: { httpHeaderAuth: { id: 'replace-n45-sentinelone-api', name: 'N45 SentinelOne API' } },
};

function node({ id, name, type, typeVersion, position, parameters = {}, nodeCredentials, ...properties }) {
  const value = { id, name, type, typeVersion, position, parameters, ...properties };
  if (nodeCredentials) value.credentials = nodeCredentials;
  return value;
}

function workflow(name, nodes, connections, settings = {}) {
  return {
    name,
    nodes,
    connections,
    pinData: {},
    settings: {
      executionOrder: 'v1',
      saveManualExecutions: true,
      callerPolicy: 'workflowsFromSameOwner',
      errorWorkflow: '',
      ...settings,
    },
    active: false,
    tags: [],
  };
}

function connect(...names) {
  const result = {};
  for (let index = 0; index < names.length - 1; index += 1) {
    result[names[index]] = { main: [[{ node: names[index + 1], type: 'main', index: 0 }]] };
  }
  return result;
}

const normalizeOperations = String.raw`
const input = $input.first().json;
const body = input.body && typeof input.body === 'object' ? input.body : input;
const headers = Object.fromEntries(Object.entries(input.headers || {}).map(([key, value]) => [key.toLowerCase(), value]));
const header = (name) => String(headers[name.toLowerCase()] ?? '').trim();
const text = (value, fallback = '') => value === undefined || value === null ? fallback : String(value).trim();
const bool = (value, fallback = false) => {
  if (value === undefined || value === null || value === '') return fallback;
  return value === true || ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
};
const clientAliases = new Map([
  ['n45 technologies', 'N45 Technology Solutions'],
  ['n45 tech solutions', 'N45 Technology Solutions'],
  ['n45 technology solutions', 'N45 Technology Solutions'],
]);
const canonicalClientName = (value) => {
  const original = text(value);
  return clientAliases.get(original.toLowerCase()) || original;
};
const hardenIdentity = (identityInput = {}) => {
  const identity = identityInput && typeof identityInput === 'object' ? { ...identityInput } : {};
  const originalClientName = text(identity.client?.name);
  const mappedClientName = canonicalClientName(originalClientName);
  const options = identity.options && typeof identity.options === 'object' ? identity.options : {};
  const identityMetadata = identity.metadata && typeof identity.metadata === 'object' ? identity.metadata : {};
  identity.client = { ...(identity.client || {}), name: mappedClientName };
  identity.options = {
    ...options,
    create_client: bool(options.create_client, false),
    create_location: bool(options.create_location, false),
    create_asset: bool(options.create_asset, false),
  };
  identity.metadata = {
    ...identityMetadata,
    original_client_name: originalClientName,
    client_alias_applied: originalClientName !== mappedClientName,
  };
  return identity;
};

if (body.identity && body.source && body.event_id && body.incident_key) {
  return [{ json: { ...body, identity: hardenIdentity(body.identity) } }];
}

let source = text(body.source || header('x-n45-source')).toLowerCase().replace(/[^a-z0-9._-]/g, '_');
if (!source && (body.monitor || body.heartbeat)) source = 'uptime_kuma';
if (!source && (body.job || body.backup_job)) source = 'backup';
if (!source) throw new Error('The event source is missing. Send x-n45-source or a canonical source field.');

const monitor = body.monitor || {};
const heartbeat = body.heartbeat || {};
const monitorName = text(monitor.name || body.monitor_name || body.service_name || body.name || body.job || body.backup_job, 'Unnamed service');
const parts = monitorName.split(/\s+::\s+/).map((part) => part.trim()).filter(Boolean);
const explicitClient = text(body.client_name || header('x-itflow-client'));
const explicitLocation = text(body.location_name || header('x-itflow-location'));
const originalClientName = explicitClient || (parts.length >= 3 ? parts[0] : '');
const clientName = canonicalClientName(originalClientName);
const locationName = explicitLocation || (parts.length >= 3 ? parts[1] : '');
const serviceName = parts.length >= 3 ? parts.slice(2).join(' :: ') : monitorName;

const rawStatus = body.state ?? body.status ?? heartbeat.status ?? body.result;
const normalizedStatus = typeof rawStatus === 'number'
  ? ({ 0: 'open', 1: 'resolved', 2: 'update', 3: 'resolved' }[rawStatus] || '')
  : text(rawStatus).toLowerCase();
const healthy = ['up', 'ok', 'healthy', 'success', 'successful', 'resolved', 'recovered', 'operational', 'passed'].includes(normalizedStatus);
const unhealthy = ['down', 'failed', 'failure', 'error', 'critical', 'unhealthy', 'open', 'alerting'].includes(normalizedStatus);
if (!healthy && !unhealthy && normalizedStatus !== 'update') {
  throw new Error('The event status could not be mapped to open, update, or resolved.');
}
const state = healthy ? 'resolved' : (normalizedStatus === 'update' ? 'update' : 'open');

const externalId = text(monitor.id ?? monitor.monitorID ?? heartbeat.monitorID ?? body.monitor_id ?? body.job_id ?? body.workflow_id ?? body.external_id, serviceName);
const incidentKey = text(body.incident_key, source + ':' + externalId);
const occurredAt = text(body.occurred_at || heartbeat.time || body.time || body.timestamp, new Date().toISOString());
const eventId = text(body.event_id || heartbeat.id, incidentKey + ':' + state + ':' + occurredAt);
const sourceUrl = text(body.url || monitor.url || body.source_url);
const host = text(monitor.hostname || body.hostname || body.host);
const details = text(body.description || body.message || body.msg || heartbeat.msg,
  serviceName + (state === 'resolved' ? ' recovered.' : ' reported ' + normalizedStatus + '.'));

const event = {
  source,
  event_id: eventId,
  incident_key: incidentKey,
  entity_type: source === 'uptime_kuma' ? 'monitor' : (source === 'backup' ? 'backup_job' : 'service'),
  state,
  severity: text(body.severity, state === 'resolved' ? 'low' : 'high'),
  title: text(body.title, '[' + source.replaceAll('_', ' ') + '] ' + serviceName),
  description: details,
  occurred_at: occurredAt,
  url: sourceUrl,
  auto_resolve: bool(body.auto_resolve, true),
  assigned_to: Number(body.assigned_to || 0),
  identity: {
    external_id: externalId,
    external_name: serviceName,
    client: { name: clientName },
    location: { name: locationName },
    asset: host ? { name: host, uri: sourceUrl } : {},
    options: {
      create_client: bool(body.create_client ?? header('x-itflow-create-client'), false),
      create_location: bool(body.create_location ?? header('x-itflow-create-location'), false),
      create_asset: bool(body.create_asset ?? header('x-itflow-create-asset'), false),
    },
    metadata: {
      monitor_type: monitor.type || '',
      raw_status: rawStatus ?? '',
      original_client_name: originalClientName,
      client_alias_applied: originalClientName !== clientName,
    },
  },
  metadata: body.metadata && typeof body.metadata === 'object' ? body.metadata : {},
};
return [{ json: event }];
`.trim();

const normalizeNetBoxDevices = String.raw`
const response = $input.first().json;
const devices = response.results || response.data?.results || [];
const DEFAULT_CLIENT = 'N45 Technology Solutions';
const CREATE_CLIENTS_FROM_TENANTS = true;
if (!Array.isArray(devices)) throw new Error('NetBox did not return a device results array.');
return devices.map((device) => {
  const site = device.site || {};
  const tenant = device.tenant || site.tenant || {};
  const clientName = device.custom_fields?.itflow_client || tenant.name || DEFAULT_CLIENT;
  const primaryIp = String(device.primary_ip?.address || '').split('/')[0];
  return { json: {
    source: 'netbox',
    entity_type: 'device',
    external_id: String(device.id),
    external_name: device.name || device.display || ('NetBox device ' + device.id),
    client: { name: clientName, entity_type: 'tenant', external_id: tenant.id ? String(tenant.id) : '' },
    location: {
      name: site.name || '',
      entity_type: 'site',
      external_id: site.id ? String(site.id) : '',
      description: site.description || '',
      address: site.physical_address || site.shipping_address || '',
    },
    asset: {
      name: device.name || device.display,
      description: device.description || '',
      type: device.role?.name || device.device_role?.name || 'Network',
      make: device.device_type?.manufacturer?.name || 'Unknown',
      model: device.device_type?.model || '',
      serial: device.serial || '',
      os: device.platform?.name || '',
      ip: primaryIp,
      status: device.status?.label || device.status?.value || 'Active',
      uri: 'https://netbox.n45tech.com/dcim/devices/' + device.id + '/',
      notes: 'Managed in NetBox. ITFlow retains client ownership and service context.',
    },
    options: {
      create_client: CREATE_CLIENTS_FROM_TENANTS && clientName !== '',
      create_location: site.name !== '',
      create_asset: true,
    },
    metadata: { netbox_last_updated: device.last_updated || '', tenant_id: tenant.id || 0, site_id: site.id || 0 },
  } };
});
`.trim();

const normalizeNetBoxEvent = String.raw`
const input = $input.first().json;
const body = input.body || input;
const device = body.data || {};
const model = String(body.model || body.object_type || '').toLowerCase();
if (!model.includes('device') && !device.device_type) return [];
const site = device.site || {};
const tenant = device.tenant || site.tenant || {};
const clientName = device.custom_fields?.itflow_client || tenant.name || 'N45 Technology Solutions';
const primaryIp = String(device.primary_ip?.address || '').split('/')[0];
return [{ json: {
  source: 'netbox', entity_type: 'device', external_id: String(device.id),
  external_name: device.name || device.display || ('NetBox device ' + device.id),
  client: { name: clientName, entity_type: 'tenant', external_id: tenant.id ? String(tenant.id) : '' },
  location: { name: site.name || '', entity_type: 'site', external_id: site.id ? String(site.id) : '', description: site.description || '', address: site.physical_address || '' },
  asset: {
    name: device.name || device.display, description: device.description || '',
    type: device.role?.name || device.device_role?.name || 'Network',
    make: device.device_type?.manufacturer?.name || 'Unknown', model: device.device_type?.model || '',
    serial: device.serial || '', os: device.platform?.name || '', ip: primaryIp,
    status: device.status?.label || device.status?.value || 'Active',
    uri: 'https://netbox.n45tech.com/dcim/devices/' + device.id + '/',
  },
  options: { create_client: clientName !== '', create_location: site.name !== '', create_asset: true },
  metadata: { netbox_event: body.event || '', netbox_request_id: body.request_id || '' },
} }];
`.trim();

const normalizeCloudflareZones = String.raw`
const response = $input.first().json;
const zones = response.result || [];
const CLIENT_BY_ZONE = {
  'n45tech.com': 'N45 Technology Solutions',
};
if (!Array.isArray(zones)) throw new Error('Cloudflare did not return a zone result array.');
return zones.filter((zone) => CLIENT_BY_ZONE[String(zone.name).toLowerCase()]).map((zone) => ({ json: {
  source: 'cloudflare', entity_type: 'zone', external_id: String(zone.id), external_name: zone.name,
  client: { name: CLIENT_BY_ZONE[String(zone.name).toLowerCase()] },
  domain: {
    name: zone.name,
    description: 'Cloudflare-managed DNS zone.',
    notes: 'Cloudflare zone ID: ' + zone.id + '. ITFlow native refresh jobs maintain registration and DNS observations.',
  },
  options: { create_client: false, create_location: false, create_asset: false, create_domain: true },
  metadata: { cloudflare_status: zone.status || '', account_id: zone.account?.id || '', account_name: zone.account?.name || '' },
} }));
`.trim();

const normalizeN8nError = String.raw`
const input = $input.first().json;
const execution = input.execution || {};
const workflow = input.workflow || {};
const trigger = input.trigger || {};
const error = execution.error || trigger.error || input.error || {};
const executionId = String(execution.id || Date.now());
const workflowId = String(workflow.id || 'unknown');
const redact = (value) => String(value || '')
  .replace(/\b(Bearer|Basic|ApiToken)\s+[^\s,;]+/gi, '$1 [redacted]')
  .replace(/([?&](?:access_token|api[_-]?key|authorization|client_secret|code|password|refresh_token|secret|token)=)[^&\s]*/gi, '$1[redacted]')
  .slice(0, 2000);
return [{ json: {
  source: 'n8n', event_id: 'execution:' + executionId,
  incident_key: 'workflow:' + workflowId, entity_type: 'workflow', state: 'open', severity: 'high',
  title: '[n8n] Workflow failed: ' + (workflow.name || workflowId),
  description: redact(error.message || error.description || 'The n8n workflow failed.') +
    (execution.lastNodeExecuted ? '\nLast node: ' + execution.lastNodeExecuted : ''),
  occurred_at: new Date().toISOString(),
  url: execution.url || '', auto_resolve: false,
  identity: {
    external_id: workflowId, external_name: workflow.name || workflowId,
    client: { name: 'N45 Technology Solutions' }, location: { name: 'Infrastructure' },
    options: { create_client: false, create_location: false, create_asset: false },
    metadata: { execution_id: executionId, last_node: execution.lastNodeExecuted || '' },
  },
} }];
`.trim();

const operationsBroker = workflow('N45 - ITFlow Operations Event Broker', [
  node({ id: '5de48c0c-a723-49ad-9b39-f276bc055e5e', name: 'Operations Webhook', type: 'n8n-nodes-base.webhook', typeVersion: 2.1, position: [-720, 0], nodeCredentials: credentials.webhook, parameters: { httpMethod: 'POST', path: 'n45-itflow-events', authentication: 'headerAuth', responseMode: 'responseNode', options: {} } }),
  node({ id: '1ce06b1c-76f2-43d4-a0ab-546a3ca85a7d', name: 'Normalize Event', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-460, 0], parameters: { jsCode: normalizeOperations } }),
  node({ id: 'b5b28290-c8f5-4f50-8dcc-d5369f0c5122', name: 'Send to ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-200, 0], nodeCredentials: credentials.itflow, parameters: { method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/event.php', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: {} } }),
  node({ id: '65355185-a0ae-4ac6-a7a7-556309aafec9', name: 'Acknowledge Event', type: 'n8n-nodes-base.respondToWebhook', typeVersion: 1.4, position: [60, 0], parameters: { respondWith: 'json', responseBody: '={{ JSON.stringify($json) }}', options: {} } }),
], connect('Operations Webhook', 'Normalize Event', 'Send to ITFlow', 'Acknowledge Event'));

const netboxReconciliation = workflow('N45 - NetBox Entity Reconciliation', [
  node({ id: '6c97a446-33e3-4f0b-95ae-e3aa88bf65db', name: 'Daily Reconciliation', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-860, -160], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '15 3 * * *' }] } } }),
  node({ id: 'de063a68-7a3b-4fbd-a7fc-4b44b2657afd', name: 'Manual Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-860, 0], parameters: {} }),
  node({ id: '46c6aeca-d9ea-4435-a91f-a8b53c571fc9', name: 'Fetch NetBox Devices', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-600, -80], nodeCredentials: credentials.netbox, parameters: { url: 'https://netbox.n45tech.com/api/dcim/devices/?limit=1000', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: {} } }),
  node({ id: '39e78e42-fe42-40d6-a313-2738e6d2a2b1', name: 'Normalize NetBox Devices', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-340, -80], parameters: { jsCode: normalizeNetBoxDevices } }),
  node({ id: '3847ee06-6643-4710-94b9-70c121192887', name: 'NetBox Event Webhook', type: 'n8n-nodes-base.webhook', typeVersion: 2.1, position: [-600, 160], nodeCredentials: credentials.webhook, parameters: { httpMethod: 'POST', path: 'n45-netbox-events', authentication: 'headerAuth', responseMode: 'lastNode', options: {} } }),
  node({ id: '9a272fb6-8a85-42d4-b371-bbbc6ed3a230', name: 'Normalize NetBox Event', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-340, 160], parameters: { jsCode: normalizeNetBoxEvent } }),
  node({ id: '0d4e728c-e61f-43a9-acb9-44b334337686', name: 'Resolve in ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-60, 40], nodeCredentials: credentials.itflow, parameters: { method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/resolve.php', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: { batching: { batch: { batchSize: 20, batchInterval: 250 } } } } }),
], {
  'Daily Reconciliation': { main: [[{ node: 'Fetch NetBox Devices', type: 'main', index: 0 }]] },
  'Manual Reconciliation': { main: [[{ node: 'Fetch NetBox Devices', type: 'main', index: 0 }]] },
  'Fetch NetBox Devices': { main: [[{ node: 'Normalize NetBox Devices', type: 'main', index: 0 }]] },
  'Normalize NetBox Devices': { main: [[{ node: 'Resolve in ITFlow', type: 'main', index: 0 }]] },
  'NetBox Event Webhook': { main: [[{ node: 'Normalize NetBox Event', type: 'main', index: 0 }]] },
  'Normalize NetBox Event': { main: [[{ node: 'Resolve in ITFlow', type: 'main', index: 0 }]] },
});

const cloudflareReconciliation = workflow('N45 - Cloudflare Domain Reconciliation', [
  node({ id: 'f48f4f06-c1b1-493b-ad04-28155af91e1d', name: 'Daily Domain Reconciliation', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-660, -100], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '45 3 * * *' }] } } }),
  node({ id: '3f005428-b835-41b8-8a5b-105a8d111ca4', name: 'Manual Domain Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-660, 80], parameters: {} }),
  node({ id: 'c8ed8a12-f878-47ef-a714-5feaf6c3cbea', name: 'Fetch Cloudflare Zones', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-400, 0], nodeCredentials: credentials.cloudflare, parameters: { url: 'https://api.cloudflare.com/client/v4/zones?per_page=50', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: {} } }),
  node({ id: '9e2aa024-f1b5-4993-95c2-bb7a6846d124', name: 'Map Zones to Clients', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-140, 0], parameters: { jsCode: normalizeCloudflareZones } }),
  node({ id: '19165423-18d4-46cd-b453-2ba704817470', name: 'Resolve Domains in ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [120, 0], nodeCredentials: credentials.itflow, parameters: { method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/resolve.php', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: { batching: { batch: { batchSize: 10, batchInterval: 250 } } } } }),
], {
  'Daily Domain Reconciliation': { main: [[{ node: 'Fetch Cloudflare Zones', type: 'main', index: 0 }]] },
  'Manual Domain Reconciliation': { main: [[{ node: 'Fetch Cloudflare Zones', type: 'main', index: 0 }]] },
  'Fetch Cloudflare Zones': { main: [[{ node: 'Map Zones to Clients', type: 'main', index: 0 }]] },
  'Map Zones to Clients': { main: [[{ node: 'Resolve Domains in ITFlow', type: 'main', index: 0 }]] },
});

const sourceRetry = { retryOnFail: true, maxTries: 5, waitBetweenTries: 5000 };
const cippPagination = {
  pagination: {
    pagination: {
      paginationMode: 'responseContainsNextURL',
      nextURL: "={{ $response.body.Metadata && $response.body.Metadata.nextLink ? $request.url.split('&nextLink=')[0] + '&nextLink=' + encodeURIComponent($response.body.Metadata.nextLink) : $request.url.split('&nextLink=')[0] }}",
      paginationCompleteWhen: 'other',
      completeExpression: '={{ !$response.body.Metadata || !$response.body.Metadata.nextLink }}',
      requestInterval: 250,
    },
  },
};
const sentinelOnePagination = {
  pagination: {
    pagination: {
      paginationMode: 'responseContainsNextURL',
      nextURL: "={{ $response.body.pagination && $response.body.pagination.nextCursor ? $request.url.split('&cursor=')[0] + '&cursor=' + encodeURIComponent($response.body.pagination.nextCursor) : $request.url.split('&cursor=')[0] }}",
      paginationCompleteWhen: 'other',
      completeExpression: '={{ !$response.body.pagination || !$response.body.pagination.nextCursor }}',
      requestInterval: 500,
    },
  },
};
const sourcePublishParameters = {
  method: 'POST',
  url: 'https://psa.n45tech.com/api/v1/integrations/device_source/update.php',
  authentication: 'genericCredentialType',
  genericAuthType: 'httpHeaderAuth',
  sendBody: true,
  specifyBody: 'json',
  jsonBody: '={{ JSON.stringify($json) }}',
  options: { batching: { batch: { batchSize: 1, batchInterval: 100 } } },
};

const intuneReconciliation = workflow('N45 - Microsoft Intune Device Reconciliation', [
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f001', name: 'Every 6 Hours', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-760, -120], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '10 */6 * * *' }] } } }),
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f002', name: 'Manual Intune Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-760, 40], parameters: {} }),
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f003', name: 'Load Intune Tenant Map', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-500, -40], parameters: { jsCode: loadCippSourceConfig('intune', 'deviceManagement/managedDevices', 'id,deviceName,managedDeviceName,managementState,lastSyncDateTime,operatingSystem,complianceState,osVersion,azureADDeviceId,isEncrypted,userId,userDisplayName,userPrincipalName,emailAddress,model,manufacturer,serialNumber,wiFiMacAddress,ethernetMacAddress,deviceHealthAttestationState') } }),
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f004', name: 'Fetch Intune Pages Through CIPP', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-220, -40], nodeCredentials: credentials.cipp, parameters: { url: '={{ $json.request_url }}', authentication: 'genericCredentialType', genericAuthType: 'oAuth2Api', options: cippPagination }, ...sourceRetry }),
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f005', name: 'Normalize Intune Devices', type: 'n8n-nodes-base.code', typeVersion: 2, position: [60, -40], parameters: { jsCode: normalizeIntune } }),
  node({ id: '43f14cb4-e9f4-4f78-a6c1-84acef63f006', name: 'Publish Intune Cycle to ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [340, -40], nodeCredentials: credentials.itflow, parameters: sourcePublishParameters, ...sourceRetry }),
], {
  'Every 6 Hours': { main: [[{ node: 'Load Intune Tenant Map', type: 'main', index: 0 }]] },
  'Manual Intune Reconciliation': { main: [[{ node: 'Load Intune Tenant Map', type: 'main', index: 0 }]] },
  'Load Intune Tenant Map': { main: [[{ node: 'Fetch Intune Pages Through CIPP', type: 'main', index: 0 }]] },
  'Fetch Intune Pages Through CIPP': { main: [[{ node: 'Normalize Intune Devices', type: 'main', index: 0 }]] },
  'Normalize Intune Devices': { main: [[{ node: 'Publish Intune Cycle to ITFlow', type: 'main', index: 0 }]] },
}, { executionTimeout: 7200, timezone: 'UTC' });

const entraReconciliation = workflow('N45 - Microsoft Entra Device Reconciliation', [
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314001', name: 'Every 6 Hours', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-760, -120], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '40 */6 * * *' }] } } }),
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314002', name: 'Manual Entra Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-760, 40], parameters: {} }),
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314003', name: 'Load Entra Tenant Map', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-500, -40], parameters: { jsCode: loadCippSourceConfig('entra', 'devices', 'id,deviceId,displayName,accountEnabled,operatingSystem,operatingSystemVersion,approximateLastSignInDateTime,registrationDateTime,manufacturer,model,isManaged,isCompliant') } }),
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314004', name: 'Fetch Entra Pages Through CIPP', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-220, -40], nodeCredentials: credentials.cipp, parameters: { url: '={{ $json.request_url }}', authentication: 'genericCredentialType', genericAuthType: 'oAuth2Api', options: cippPagination }, ...sourceRetry }),
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314005', name: 'Normalize Entra Devices', type: 'n8n-nodes-base.code', typeVersion: 2, position: [60, -40], parameters: { jsCode: normalizeEntra } }),
  node({ id: 'af645055-1b1c-45da-a96d-d02bec314006', name: 'Publish Entra Cycle to ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [340, -40], nodeCredentials: credentials.itflow, parameters: sourcePublishParameters, ...sourceRetry }),
], {
  'Every 6 Hours': { main: [[{ node: 'Load Entra Tenant Map', type: 'main', index: 0 }]] },
  'Manual Entra Reconciliation': { main: [[{ node: 'Load Entra Tenant Map', type: 'main', index: 0 }]] },
  'Load Entra Tenant Map': { main: [[{ node: 'Fetch Entra Pages Through CIPP', type: 'main', index: 0 }]] },
  'Fetch Entra Pages Through CIPP': { main: [[{ node: 'Normalize Entra Devices', type: 'main', index: 0 }]] },
  'Normalize Entra Devices': { main: [[{ node: 'Publish Entra Cycle to ITFlow', type: 'main', index: 0 }]] },
}, { executionTimeout: 7200, timezone: 'UTC' });

const sentinelOneReconciliation = workflow('N45 - SentinelOne Agent Reconciliation', [
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15001', name: 'Every 6 Hours', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-980, -120], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '55 */6 * * *' }] } } }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15002', name: 'Manual SentinelOne Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-980, 40], parameters: {} }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15003', name: 'Load SentinelOne Site Map', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-720, -40], parameters: { jsCode: loadSentinelOneConfig } }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15004', name: 'Fetch SentinelOne Sites', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-460, -40], nodeCredentials: credentials.sentinelone, parameters: { url: '={{ $json.sites_url }}', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: sentinelOnePagination }, ...sourceRetry }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15005', name: 'Validate SentinelOne Sites', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, -40], parameters: { jsCode: validateSentinelOneSites } }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15006', name: 'Fetch SentinelOne Agent Pages', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [60, -40], nodeCredentials: credentials.sentinelone, parameters: { url: '={{ $json.agents_url }}', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: sentinelOnePagination }, ...sourceRetry }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15007', name: 'Normalize SentinelOne Agents', type: 'n8n-nodes-base.code', typeVersion: 2, position: [320, -40], parameters: { jsCode: normalizeSentinelOne } }),
  node({ id: '64ddad76-fec6-4bca-8831-a2b0e9b15008', name: 'Publish SentinelOne Cycle to ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [580, -40], nodeCredentials: credentials.itflow, parameters: sourcePublishParameters, ...sourceRetry }),
], {
  'Every 6 Hours': { main: [[{ node: 'Load SentinelOne Site Map', type: 'main', index: 0 }]] },
  'Manual SentinelOne Reconciliation': { main: [[{ node: 'Load SentinelOne Site Map', type: 'main', index: 0 }]] },
  'Load SentinelOne Site Map': { main: [[{ node: 'Fetch SentinelOne Sites', type: 'main', index: 0 }]] },
  'Fetch SentinelOne Sites': { main: [[{ node: 'Validate SentinelOne Sites', type: 'main', index: 0 }]] },
  'Validate SentinelOne Sites': { main: [[{ node: 'Fetch SentinelOne Agent Pages', type: 'main', index: 0 }]] },
  'Fetch SentinelOne Agent Pages': { main: [[{ node: 'Normalize SentinelOne Agents', type: 'main', index: 0 }]] },
  'Normalize SentinelOne Agents': { main: [[{ node: 'Publish SentinelOne Cycle to ITFlow', type: 'main', index: 0 }]] },
}, { executionTimeout: 7200, timezone: 'UTC' });

const n8nErrorWorkflow = workflow('N45 - Automation Failure to ITFlow', [
  node({ id: 'cb44be91-41cd-4b32-a69a-ce2ad33d6ab7', name: 'Workflow Error', type: 'n8n-nodes-base.errorTrigger', typeVersion: 1, position: [-460, 0], parameters: {} }),
  node({ id: 'db633e22-451f-4796-a19c-14d0bea75343', name: 'Normalize n8n Error', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, 0], parameters: { jsCode: normalizeN8nError } }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf5', name: 'Open ITFlow Incident', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [60, 0], nodeCredentials: credentials.webhook, parameters: { method: 'POST', url: 'https://automate.n45tech.com/webhook/n45-itflow-events', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: {} } }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf6', name: 'Normalize Device Source Failure', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, 180], parameters: { jsCode: normalizeDeviceSourceFailure } }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf7', name: 'Record Device Source Failure', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [60, 180], nodeCredentials: credentials.itflow, parameters: sourcePublishParameters, ...sourceRetry }),
], {
  'Workflow Error': { main: [[
    { node: 'Normalize n8n Error', type: 'main', index: 0 },
    { node: 'Normalize Device Source Failure', type: 'main', index: 0 },
  ]] },
  'Normalize n8n Error': { main: [[{ node: 'Open ITFlow Incident', type: 'main', index: 0 }]] },
  'Normalize Device Source Failure': { main: [[{ node: 'Record Device Source Failure', type: 'main', index: 0 }]] },
});

const workflows = [
  ['operations-event-broker.json', operationsBroker],
  ['netbox-reconciliation.json', netboxReconciliation],
  ['cloudflare-domain-reconciliation.json', cloudflareReconciliation],
  ['n8n-error-to-itflow.json', n8nErrorWorkflow],
  ['intune-device-reconciliation.json', intuneReconciliation],
  ['entra-device-reconciliation.json', entraReconciliation],
  ['sentinelone-agent-reconciliation.json', sentinelOneReconciliation],
];

await mkdir(output, { recursive: true });
for (const [file, value] of workflows) {
  await writeFile(join(output, file), JSON.stringify(value, null, 2) + '\n', 'utf8');
}
console.log(`Wrote ${workflows.length} workflows to ${output}`);
