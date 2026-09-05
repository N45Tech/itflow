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
  cippWebhook: { httpHeaderAuth: { id: 'replace-n45-cipp-webhook-auth', name: 'N45 CIPP Webhook' } },
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
      saveManualExecutions: false,
      saveDataSuccessExecution: 'none',
      saveDataErrorExecution: 'none',
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
const limitedText = (value, length, fallback = '') => text(value, fallback).slice(0, length);
const bool = (value, fallback = false) => {
  if (value === undefined || value === null || value === '') return fallback;
  return value === true || ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
};
const integer = (value) => {
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0;
};
const sensitiveKey = (key) => {
  const normalized = String(key || '').replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  if (['api_key', 'apikey', 'auth', 'authentication', 'authorization', 'bearer', 'client_secret',
    'cookie', 'credential', 'credentials', 'password', 'private_key', 'proxy_authorization',
    'refresh_token', 'secret', 'session', 'session_id', 'signature', 'signing_key', 'token',
    'webhook_secret', 'x_api_key'].includes(normalized)) return true;
  return ['_api_key', '_credential', '_password', '_secret', '_token'].some((suffix) => normalized.endsWith(suffix));
};
const sanitize = (value, depth = 0) => {
  if (depth > 5 || value === undefined || typeof value === 'function') return null;
  if (value === null || typeof value === 'boolean' || typeof value === 'number') return value;
  if (typeof value === 'string') return value.slice(0, 8000);
  if (Array.isArray(value)) return value.slice(0, 100).map((item) => sanitize(item, depth + 1));
  if (typeof value !== 'object') return String(value).slice(0, 8000);
  const clean = {};
  for (const [key, item] of Object.entries(value).slice(0, 100)) {
    if (!sensitiveKey(key)) clean[key] = sanitize(item, depth + 1);
  }
  return clean;
};
const sourceName = (value) => text(value).toLowerCase().replace(/[^a-z0-9._-]/g, '_').slice(0, 40);
const stateName = (value) => {
  const state = text(value, 'open').toLowerCase();
  if (!['open', 'update', 'resolved'].includes(state)) throw new Error('The canonical event state is invalid.');
  return state;
};
const severityName = (value, fallback) => {
  const severity = text(value, fallback).toLowerCase();
  return ['information', 'low', 'warning', 'medium', 'high', 'critical', 'emergency'].includes(severity)
    ? severity : fallback;
};
const timestamp = (value, offset = '') => {
  let raw = text(value);
  if (!raw) return new Date().toISOString();
  raw = raw.replace(' ', 'T');
  if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw)) {
    raw += /^[+-]\d{2}:\d{2}$/.test(text(offset)) ? text(offset) : 'Z';
  }
  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) throw new Error('The event timestamp is invalid.');
  return parsed.toISOString();
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
const record = (value) => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
const pick = (value, keys) => Object.fromEntries(keys
  .filter((key) => value[key] !== undefined && value[key] !== null && value[key] !== '')
  .map((key) => [key, sanitize(value[key])]));
const hardenIdentity = (identityInput = {}) => {
  const original = record(identityInput);
  const client = record(original.client);
  const location = record(original.location);
  const asset = record(original.asset);
  const domain = record(original.domain);
  const options = record(original.options);
  const originalClientName = text(client.name);
  const mappedClientName = canonicalClientName(originalClientName);
  return {
    ...pick(original, ['external_id', 'external_name', 'entity_type']),
    client: { ...pick(client, ['id', 'external_id', 'entity_type']), name: mappedClientName },
    location: pick(location, ['id', 'name', 'external_id', 'entity_type', 'description', 'address', 'city', 'state', 'zip']),
    asset: pick(asset, ['id', 'name', 'description', 'type', 'make', 'model', 'serial', 'os', 'ip', 'status', 'uri', 'notes']),
    domain: pick(domain, ['id', 'name', 'description', 'notes']),
    options: {
      create_client: bool(options.create_client, false),
      create_location: bool(options.create_location, false),
      create_asset: bool(options.create_asset, false),
      create_domain: bool(options.create_domain, false),
    },
    metadata: {
      ...sanitize(record(original.metadata)),
      original_client_name: originalClientName,
      client_alias_applied: originalClientName !== mappedClientName,
    },
  };
};
let routing = {};
const routingJson = text($vars.N45_EVENT_ROUTING_JSON);
if (routingJson) {
  try {
    const parsed = JSON.parse(routingJson);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('not an object');
    routing = parsed;
  } catch (error) {
    throw new Error('N45_EVENT_ROUTING_JSON must be a JSON object.');
  }
}
let source = sourceName(body.source || header('x-n45-source'));
if (!source && (body.monitor || body.heartbeat)) source = 'uptime_kuma';
if (!source && (body.job || body.backup_job)) source = 'backup';
if (!source) throw new Error('The event source is missing. Send x-n45-source or a canonical source field.');
const sourceRoute = record(routing[source]);
const routeValue = (key, fallback) => body[key] !== undefined && body[key] !== null && body[key] !== ''
  ? body[key] : (sourceRoute[key] !== undefined ? sourceRoute[key] : fallback);
const requestType = (fallback) => {
  const value = text(routeValue('request_type_key', fallback)).toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100);
  return value || fallback;
};
const contactMode = ['none', 'primary'].includes(text(routeValue('contact_mode', 'none')).toLowerCase())
  ? text(routeValue('contact_mode', 'none')).toLowerCase() : 'none';

if (body.identity && body.event_id && body.incident_key) {
  const canonicalState = stateName(body.state);
  const canonicalEventId = limitedText(body.event_id, 255);
  const canonicalIncidentKey = limitedText(body.incident_key, 255);
  if (!canonicalEventId || !canonicalIncidentKey) throw new Error('Canonical event_id and incident_key are required.');
  const fallbackRequestType = source === 'n8n' ? 'automation-failure'
    : (source === 'backup' ? 'backup-alert' : (source === 'uptime_kuma' ? 'monitoring-alert' : 'integration-alert'));
  return [{ json: {
    source,
    event_id: canonicalEventId,
    incident_key: canonicalIncidentKey,
    entity_type: sourceName(body.entity_type || body.identity.entity_type || 'service') || 'service',
    state: canonicalState,
    severity: severityName(body.severity, canonicalState === 'resolved' ? 'low' : 'medium'),
    title: limitedText(body.title, 500) || 'Integration alert',
    description: limitedText(body.description, 8000),
    occurred_at: timestamp(body.occurred_at, body.timezone_offset || body.timezoneOffset),
    url: limitedText(body.url, 2000),
    auto_resolve: bool(body.auto_resolve, true),
    assigned_to: integer(routeValue('assigned_to', 0)),
    category_id: integer(routeValue('category_id', 0)),
    contact_id: integer(routeValue('contact_id', 0)),
    request_type_key: requestType(fallbackRequestType),
    contact_mode: contactMode,
    service_id: integer(body.service_id),
    identity: hardenIdentity(body.identity),
    metadata: sanitize(record(body.metadata)),
  } }];
}

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
const sourceOffset = heartbeat.timezoneOffset || body.timezone_offset || body.timezoneOffset;
const occurredAt = body.occurred_at
  ? timestamp(body.occurred_at, sourceOffset)
  : (heartbeat.localDateTime && sourceOffset
    ? timestamp(heartbeat.localDateTime, sourceOffset)
    : timestamp(heartbeat.time || body.time || body.timestamp, sourceOffset));
const eventId = text(body.event_id || heartbeat.id, incidentKey + ':' + state + ':' + occurredAt);
const sourceUrl = text(body.url || monitor.url || body.source_url);
const host = text(monitor.hostname || body.hostname || body.host);
const target = host || sourceUrl.replace(/^https?:\/\//i, '').split('/')[0];
const rawDetails = text(body.description || body.message || body.msg || heartbeat.msg);
const cleanDetails = rawDetails.replace(/^(?:\[[^\]]*\]\s*)+/, '')
  .replace(/^(?:down|up|resolved|recovered)\s*[:\-–—]?\s*/i, '').trim();
const availability = serviceName + (target ? ' (' + target + ')' : '');
const details = source === 'uptime_kuma'
  ? availability + (state === 'resolved' ? ' has recovered.' : ' is unavailable.')
    + (cleanDetails ? '\n\nSource detail: ' + cleanDetails : '')
  : (rawDetails || serviceName + (state === 'resolved' ? ' recovered.' : ' reported ' + normalizedStatus + '.'));
const monitorTags = JSON.stringify(monitor.tags || body.tags || []).toLowerCase();
const defaultSeverity = state === 'resolved' ? 'low'
  : (/critical|emergency/.test(monitorTags) ? 'critical'
    : (/high/.test(monitorTags) || /\b(psa|firewall|internet|core)\b/i.test(serviceName) ? 'high' : 'medium'));
const defaultTitle = source === 'uptime_kuma' ? 'Monitoring alert: ' + serviceName
  : (source === 'backup' ? 'Backup alert: ' + serviceName : 'Integration alert: ' + serviceName);
const defaultRequestType = source === 'uptime_kuma' ? 'monitoring-alert'
  : (source === 'backup' ? 'backup-alert' : 'integration-alert');

const event = {
  source,
  event_id: eventId,
  incident_key: incidentKey,
  entity_type: source === 'uptime_kuma' ? 'monitor' : (source === 'backup' ? 'backup_job' : 'service'),
  state,
  severity: severityName(body.severity, defaultSeverity),
  title: limitedText(body.title, 500, defaultTitle),
  description: limitedText(details, 8000),
  occurred_at: occurredAt,
  url: sourceUrl,
  auto_resolve: bool(body.auto_resolve, true),
  assigned_to: integer(routeValue('assigned_to', 0)),
  category_id: integer(routeValue('category_id', 0)),
  contact_id: integer(routeValue('contact_id', 0)),
  request_type_key: requestType(defaultRequestType),
  contact_mode: contactMode,
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
  metadata: sanitize(record(body.metadata)),
};
return [{ json: event }];
`.trim();

const normalizeNetBoxDevices = String.raw`
const response = $input.first().json;
const devices = response.results || response.data?.results || [];
const DEFAULT_CLIENT = 'N45 Technology Solutions';
const CREATE_CLIENTS_FROM_TENANTS = false;
if (!Array.isArray(devices)) throw new Error('NetBox did not return a device results array.');
if (devices.length === 0) throw new Error('NetBox returned zero devices; refusing to report a successful reconciliation.');
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
  options: { create_client: false, create_location: site.name !== '', create_asset: true },
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
if (zones.length === 0) throw new Error('Cloudflare returned zero zones; refusing to report a successful reconciliation.');
const mappedZones = zones.filter((zone) => CLIENT_BY_ZONE[String(zone.name).toLowerCase()]);
if (mappedZones.length === 0) throw new Error('Cloudflare returned zones, but none have an explicit ITFlow client mapping.');
return mappedZones.map((zone) => ({ json: {
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
  request_type_key: 'automation-failure', contact_mode: 'none',
  identity: {
    external_id: workflowId, external_name: workflow.name || workflowId,
    client: { name: 'N45 Technology Solutions' }, location: { name: 'Infrastructure' },
    options: { create_client: false, create_location: false, create_asset: false },
    metadata: { execution_id: executionId, last_node: execution.lastNodeExecuted || '' },
  },
} }];
`.trim();

const normalizeCippAlert = String.raw`
const input = $input.first().json;
const body = input.body && typeof input.body === 'object' ? input.body : input;
const text = (value, fallback = '') => value === undefined || value === null ? fallback : String(value).trim();
const integer = (value) => {
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : 0;
};
const slug = (value) => text(value, 'alert').toLowerCase().replace(/[^a-z0-9]+/g, '-')
  .replace(/^-+|-+$/g, '').slice(0, 120) || 'alert';
const sensitiveKey = (key) => {
  const normalized = String(key || '').replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  return ['api_key', 'apikey', 'auth', 'authentication', 'authorization', 'client_secret', 'cookie',
    'credential', 'credentials', 'password', 'private_key', 'refresh_token', 'secret', 'signature',
    'token', 'webhook_secret'].includes(normalized)
    || ['_api_key', '_credential', '_password', '_secret', '_token'].some((suffix) => normalized.endsWith(suffix));
};
const sanitize = (value, depth = 0) => {
  if (depth > 4 || value === undefined) return null;
  if (value === null || typeof value === 'number' || typeof value === 'boolean') return value;
  if (typeof value === 'string') return value.slice(0, 1000);
  if (Array.isArray(value)) return value.slice(0, 25).map((item) => sanitize(item, depth + 1));
  if (typeof value !== 'object') return String(value).slice(0, 1000);
  return Object.fromEntries(Object.entries(value).slice(0, 30)
    .filter(([key]) => !sensitiveKey(key))
    .map(([key, item]) => [key, sanitize(item, depth + 1)]));
};
const schemaSource = text(body.source).toLowerCase();
if (!['cipp', 'send alert'].includes(schemaSource)) throw new Error('Expected a CIPP standardized alert source.');
if (text(body.schemaVersion) !== '1.0') throw new Error('Unsupported CIPP alert schema version. Expected 1.0.');
const tenant = text(body.tenant).toLowerCase();
const title = text(body.title);
if (!tenant) throw new Error('CIPP tenant is missing.');
if (!title) throw new Error('CIPP alert title is missing.');
let tenantMap = {};
try {
  tenantMap = JSON.parse(text($vars.N45_CIPP_ALERT_TENANT_MAP_JSON, '{}'));
} catch (error) {
  throw new Error('N45_CIPP_ALERT_TENANT_MAP_JSON must be valid JSON.');
}
const mapping = tenantMap && typeof tenantMap === 'object' ? tenantMap[tenant] : null;
if (!mapping || typeof mapping !== 'object' || integer(mapping.client_id) < 1) {
  throw new Error('CIPP tenant ' + tenant + ' has no explicit ITFlow client ID mapping.');
}
let generatedAt = text(body.generatedAt, new Date().toISOString()).replace(' ', 'T');
if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(generatedAt)) generatedAt += 'Z';
const parsedGeneratedAt = new Date(generatedAt);
if (Number.isNaN(parsedGeneratedAt.getTime())) throw new Error('CIPP generatedAt is invalid.');
generatedAt = parsedGeneratedAt.toISOString();
const rawPayload = Array.isArray(body.payload) ? body.payload : [];
const requestedCount = Number(body.alertCount);
const alertCount = Number.isFinite(requestedCount) ? Math.max(0, Math.floor(requestedCount)) : rawPayload.length;
if (alertCount < 1 && rawPayload.length < 1) throw new Error('CIPP sent an empty alert.');
const payload = sanitize(rawPayload);
const searchText = (title + ' ' + JSON.stringify(payload)).toLowerCase();
const highRisk = ['global admin', 'emergency access', 'break glass', 'security defaults',
  'conditional access', 'administrator without mfa', 'admin without mfa', 'compromised',
  'malware', 'phishing', 'ransomware', 'breach'].some((term) => searchText.includes(term));
const lowRisk = ['recommendation', 'informational', 'report only', 'report-only', 'alignment']
  .some((term) => searchText.includes(term));
const invoking = text(body.invoking, 'cipp-alert');
const incidentKey = 'cipp:' + slug(tenant) + ':' + slug(invoking) + ':' + slug(title);
const summaryLines = payload.slice(0, 20).map((item, index) => {
  if (typeof item === 'string') return (index + 1) + '. ' + item.slice(0, 500);
  const detail = item && typeof item === 'object' ? item : { value: item };
  const preferred = ['displayName', 'name', 'userPrincipalName', 'message', 'description', 'status', 'value']
    .filter((key) => detail[key] !== undefined && detail[key] !== null && detail[key] !== '')
    .map((key) => key + ': ' + String(detail[key]).slice(0, 300));
  return (index + 1) + '. ' + (preferred.length ? preferred.join('; ') : JSON.stringify(detail).slice(0, 500));
});
if (rawPayload.length > summaryLines.length) summaryLines.push('... ' + (rawPayload.length - summaryLines.length) + ' more result(s) omitted.');
const description = [
  'Microsoft 365 alert for ' + text(mapping.client_name, tenant) + '.',
  'Alert count: ' + alertCount,
  'Generated: ' + generatedAt,
  'Monitor: ' + invoking,
  '',
  ...summaryLines,
].join('\n').slice(0, 8000);
const event = {
  source: 'cipp', event_id: incidentKey + ':' + generatedAt, incident_key: incidentKey,
  entity_type: 'microsoft_365_tenant', state: 'open',
  severity: highRisk ? 'high' : (lowRisk ? 'low' : 'medium'),
  title: ('Microsoft 365 alert: ' + title).slice(0, 500), description, occurred_at: generatedAt,
  url: '', auto_resolve: false, request_type_key: 'microsoft-365-alert', contact_mode: 'none',
  identity: {
    external_id: tenant, external_name: text(mapping.client_name, tenant),
    client: { id: integer(mapping.client_id), name: text(mapping.client_name) },
    location: integer(mapping.location_id) > 0 ? { id: integer(mapping.location_id) } : {},
    domain: { name: tenant }, asset: {},
    options: { create_client: false, create_location: false, create_asset: false, create_domain: false },
    metadata: { tenant_domain: tenant, schema_version: text(body.schemaVersion), schema_source: schemaSource },
  },
  metadata: { invoking, alert_count: alertCount, tenant_domain: tenant },
};
if (integer(mapping.assigned_to) > 0) event.assigned_to = integer(mapping.assigned_to);
if (integer(mapping.category_id) > 0) event.category_id = integer(mapping.category_id);
if (integer(mapping.contact_id) > 0) event.contact_id = integer(mapping.contact_id);
return [{ json: event }];
`.trim();

const sourceRetry = { retryOnFail: true, maxTries: 5, waitBetweenTries: 5000 };

const operationsOutboxTable = {
  __rl: true,
  mode: 'name',
  value: 'N45 Operations Event Outbox',
  cachedResultName: 'N45 Operations Event Outbox',
};

const selectDueOperations = String.raw`
const rows = $input.all().map((item) => item.json).sort((left, right) => {
  const leftTime = Date.parse(left.occurred_at || left.createdAt || '') || 0;
  const rightTime = Date.parse(right.occurred_at || right.createdAt || '') || 0;
  return leftTime - rightTime || Number(left.id || 0) - Number(right.id || 0);
});
const firstIncidentSeen = new Set();
const selected = [];
const now = Date.now();
for (const row of rows) {
  const incidentKey = String(row.source || '') + '\u0000' + String(row.incident_key || 'row:' + row.id);
  if (firstIncidentSeen.has(incidentKey)) continue;
  firstIncidentSeen.add(incidentKey);
  if (!['pending', 'retry'].includes(String(row.status || '').toLowerCase())) continue;
  const dueAt = Date.parse(row.next_attempt_at || row.createdAt || '') || 0;
  if (dueAt > now) continue;
  let deliveryPayload = {};
  let payloadError = '';
  try {
    deliveryPayload = JSON.parse(String(row.payload || ''));
    if (!deliveryPayload || typeof deliveryPayload !== 'object' || Array.isArray(deliveryPayload)) {
      throw new Error('payload is not an object');
    }
  } catch (error) {
    payloadError = 'The queued event payload is invalid.';
  }
  selected.push({ json: { ...row, delivery_payload: deliveryPayload, payload_error: payloadError } });
  if (selected.length >= 25) break;
}
return selected;
`.trim();

const classifyOperationDelivery = String.raw`
const response = $json || {};
const row = $('Select Due Events').item.json;
const attempts = Math.max(0, Number(row.attempts || 0)) + 1;
const statusCode = Number(response.statusCode ?? response.status ?? response.error?.statusCode
  ?? response.error?.httpCode ?? 0);
const redact = (value) => String(value || '')
  .replace(/\b(Bearer|Basic|ApiToken)\s+[^\s,;]+/gi, '$1 [redacted]')
  .replace(/([?&](?:access_token|api[_-]?key|authorization|client_secret|password|refresh_token|secret|token)=)[^&\s]*/gi, '$1[redacted]')
  .slice(0, 2000);
let responseMessage = row.payload_error || response.body?.message || response.body?.error
  || response.error?.message || response.message || '';
if (responseMessage && typeof responseMessage === 'object') responseMessage = JSON.stringify(responseMessage);
if (statusCode >= 200 && statusCode < 300 && !row.payload_error) {
  return { json: { id: row.id, event_id: row.event_id, disposition: 'delivered', attempts,
    status: 'delivered', next_attempt_at: new Date().toISOString(), last_error: '' } };
}
const retryable = !row.payload_error && (statusCode === 0 || [408, 425, 429].includes(statusCode) || statusCode >= 500);
const exhausted = attempts >= 20;
if (retryable && !exhausted) {
  const delaySeconds = Math.min(3600, 30 * (2 ** Math.min(attempts, 7))) + (Number(row.id || 0) % 17);
  const message = redact(responseMessage || ('ITFlow delivery returned HTTP ' + (statusCode || 'network error')));
  return { json: { id: row.id, event_id: row.event_id, disposition: 'retry', attempts,
    status: 'retry', next_attempt_at: new Date(Date.now() + delaySeconds * 1000).toISOString(), last_error: message } };
}
const reason = row.payload_error || (exhausted
  ? 'ITFlow delivery exhausted 20 attempts.'
  : 'ITFlow delivery returned non-retryable HTTP ' + (statusCode || 'error') + '.');
const detail = redact(responseMessage);
return { json: { id: row.id, event_id: row.event_id, disposition: 'terminal', attempts,
  status: 'terminal', next_attempt_at: new Date().toISOString(),
  last_error: redact(reason + (detail ? ' ' + detail : '')) } };
`.trim();

const operationsBroker = workflow('N45 - ITFlow Operations Event Broker', [
  node({ id: '5de48c0c-a723-49ad-9b39-f276bc055e5e', name: 'Operations Webhook', type: 'n8n-nodes-base.webhook', typeVersion: 2.1, position: [-900, -240], nodeCredentials: credentials.webhook, parameters: { httpMethod: 'POST', path: 'n45-itflow-events', authentication: 'headerAuth', responseMode: 'responseNode', options: {} } }),
  node({ id: '1ce06b1c-76f2-43d4-a0ab-546a3ca85a7d', name: 'Normalize Event', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-650, -240], parameters: { jsCode: normalizeOperations } }),
  node({ id: 'b5b28290-c8f5-4f50-8dcc-d5369f0c5122', name: 'Queue Event', type: 'n8n-nodes-base.dataTable', typeVersion: 1.1, position: [-380, -240], parameters: {
    resource: 'row', operation: 'upsert', dataTableId: operationsOutboxTable, matchType: 'allConditions',
    filters: { conditions: [
      { keyName: 'source', condition: 'eq', keyValue: '={{ $json.source }}' },
      { keyName: 'event_id', condition: 'eq', keyValue: '={{ $json.event_id }}' },
    ] },
    columns: { mappingMode: 'defineBelow', matchingColumns: [], value: {
      event_id: '={{ $json.event_id }}', incident_key: '={{ $json.incident_key }}', source: '={{ $json.source }}',
      occurred_at: '={{ $json.occurred_at }}', payload: '={{ JSON.stringify($json) }}', status: 'pending',
      attempts: 0, next_attempt_at: '={{ $now.toISO() }}', last_error: '',
    } }, options: {},
  } }),
  node({ id: '65355185-a0ae-4ac6-a7a7-556309aafec9', name: 'Acknowledge Event', type: 'n8n-nodes-base.respondToWebhook', typeVersion: 1.4, position: [-100, -240], parameters: { respondWith: 'json', responseBody: { accepted: true, queued: true }, options: { responseCode: 202 } } }),
  node({ id: 'ad6ddcd3-c9ad-4298-bb62-4dad129d6221', name: 'Delivery Schedule', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.3, position: [-900, 160], parameters: { rule: { interval: [{ field: 'minutes', minutesInterval: 1 }] } } }),
  node({ id: 'cb937a65-cd54-46b3-a1a0-635415dbb62b', name: 'Read Event Queue', type: 'n8n-nodes-base.dataTable', typeVersion: 1.1, position: [-650, 160], parameters: { resource: 'row', operation: 'get', dataTableId: operationsOutboxTable, returnAll: true, orderBy: true, orderByColumn: 'createdAt', orderByDirection: 'ASC' } }),
  node({ id: '8c937a65-cd54-46b3-a1a0-635415dbb62c', name: 'Select Due Events', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-400, 160], parameters: { mode: 'runOnceForAllItems', jsCode: selectDueOperations } }),
  node({ id: '5afd7992-64e1-4c93-879a-8fe90025b22f', name: 'Deliver Event to ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-140, 160], nodeCredentials: credentials.itflow, onError: 'continueRegularOutput', parameters: {
    method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/event.php',
    authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true,
    specifyBody: 'json', jsonBody: '={{ JSON.stringify($json.delivery_payload) }}', options: {
      batching: { batch: { batchSize: 1, batchInterval: 100 } }, timeout: 20000,
      response: { response: { fullResponse: true, neverError: true, responseFormat: 'json' } },
    },
  } }),
  node({ id: '195c93c9-fd8d-48a1-8cc8-6fbfe3105c08', name: 'Classify Delivery', type: 'n8n-nodes-base.code', typeVersion: 2, position: [120, 160], parameters: { mode: 'runOnceForEachItem', jsCode: classifyOperationDelivery } }),
  node({ id: 'fd265b7d-0f9c-4014-bb0c-eef07b7f1b93', name: 'Route Delivery', type: 'n8n-nodes-base.switch', typeVersion: 3.3, position: [380, 160], parameters: { mode: 'expression', numberOutputs: 3, output: "={{ $json.disposition === 'delivered' ? 0 : ($json.disposition === 'retry' ? 1 : 2) }}" } }),
  node({ id: '96a230b9-60b9-4a07-87b1-1dabfa4d4939', name: 'Remove Delivered Event', type: 'n8n-nodes-base.dataTable', typeVersion: 1.1, position: [650, 40], parameters: { resource: 'row', operation: 'deleteRows', dataTableId: operationsOutboxTable, matchType: 'allConditions', filters: { conditions: [{ keyName: 'id', condition: 'eq', keyValue: '={{ $json.id }}' }] }, options: {} } }),
  node({ id: '852e6482-ef32-4671-a7af-6d4c0d4f01a9', name: 'Schedule Event Retry', type: 'n8n-nodes-base.dataTable', typeVersion: 1.1, position: [650, 160], parameters: { resource: 'row', operation: 'update', dataTableId: operationsOutboxTable, matchType: 'allConditions', filters: { conditions: [{ keyName: 'id', condition: 'eq', keyValue: '={{ $json.id }}' }] }, columns: { mappingMode: 'defineBelow', matchingColumns: [], value: { status: '={{ $json.status }}', attempts: '={{ $json.attempts }}', next_attempt_at: '={{ $json.next_attempt_at }}', last_error: '={{ $json.last_error }}' } }, options: {} } }),
  node({ id: '01a9801e-da74-4880-a415-28b5f47ce09a', name: 'Hold Terminal Event', type: 'n8n-nodes-base.dataTable', typeVersion: 1.1, position: [650, 280], parameters: { resource: 'row', operation: 'update', dataTableId: operationsOutboxTable, matchType: 'allConditions', filters: { conditions: [{ keyName: 'id', condition: 'eq', keyValue: '={{ $json.id }}' }] }, columns: { mappingMode: 'defineBelow', matchingColumns: [], value: { status: '={{ $json.status }}', attempts: '={{ $json.attempts }}', next_attempt_at: '={{ $json.next_attempt_at }}', last_error: '={{ $json.last_error }}' } }, options: {} } }),
], {
  'Operations Webhook': { main: [[{ node: 'Normalize Event', type: 'main', index: 0 }]] },
  'Normalize Event': { main: [[{ node: 'Queue Event', type: 'main', index: 0 }]] },
  'Queue Event': { main: [[{ node: 'Acknowledge Event', type: 'main', index: 0 }]] },
  'Delivery Schedule': { main: [[{ node: 'Read Event Queue', type: 'main', index: 0 }]] },
  'Read Event Queue': { main: [[{ node: 'Select Due Events', type: 'main', index: 0 }]] },
  'Select Due Events': { main: [[{ node: 'Deliver Event to ITFlow', type: 'main', index: 0 }]] },
  'Deliver Event to ITFlow': { main: [[{ node: 'Classify Delivery', type: 'main', index: 0 }]] },
  'Classify Delivery': { main: [[{ node: 'Route Delivery', type: 'main', index: 0 }]] },
  'Route Delivery': { main: [
    [{ node: 'Remove Delivered Event', type: 'main', index: 0 }],
    [{ node: 'Schedule Event Retry', type: 'main', index: 0 }],
    [{ node: 'Hold Terminal Event', type: 'main', index: 0 }],
  ] },
}, { timezone: 'UTC' });

const cippAlerts = workflow('N45 - CIPP Alerts to ITFlow', [
  node({ id: '8377f204-4b54-4662-8c45-171bc5b154cf', name: 'CIPP Alerts Webhook', type: 'n8n-nodes-base.webhook', typeVersion: 2.1, position: [-480, 0], nodeCredentials: credentials.cippWebhook, parameters: { httpMethod: 'POST', path: 'n45-cipp-alerts', authentication: 'headerAuth', responseMode: 'lastNode', options: {} } }),
  node({ id: 'f659b431-4aed-43d6-8d7a-bfd7f0a9c6df', name: 'Normalize CIPP Alert', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, 0], parameters: { jsCode: normalizeCippAlert } }),
  node({ id: 'd91ab17c-46ef-44d1-b8e4-94a0418c8863', name: 'Queue Through Operations Broker', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [80, 0], nodeCredentials: credentials.webhook, parameters: { method: 'POST', url: 'https://automate.n45tech.com/webhook/n45-itflow-events', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: { timeout: 10000 } }, ...sourceRetry }),
], connect('CIPP Alerts Webhook', 'Normalize CIPP Alert', 'Queue Through Operations Broker'), { timezone: 'UTC' });

const netboxReconciliation = workflow('N45 - NetBox Entity Reconciliation', [
  node({ id: '6c97a446-33e3-4f0b-95ae-e3aa88bf65db', name: 'Daily Reconciliation', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [-860, -160], parameters: { rule: { interval: [{ field: 'cronExpression', expression: '15 3 * * *' }] } } }),
  node({ id: 'de063a68-7a3b-4fbd-a7fc-4b44b2657afd', name: 'Manual Reconciliation', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [-860, 0], parameters: {} }),
  node({ id: '46c6aeca-d9ea-4435-a91f-a8b53c571fc9', name: 'Fetch NetBox Devices', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-600, -80], nodeCredentials: credentials.netbox, parameters: { url: 'https://netbox.n45tech.com/api/dcim/devices/?limit=1000', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: {} }, ...sourceRetry }),
  node({ id: '39e78e42-fe42-40d6-a313-2738e6d2a2b1', name: 'Normalize NetBox Devices', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-340, -80], parameters: { jsCode: normalizeNetBoxDevices } }),
  node({ id: '3847ee06-6643-4710-94b9-70c121192887', name: 'NetBox Event Webhook', type: 'n8n-nodes-base.webhook', typeVersion: 2.1, position: [-600, 160], nodeCredentials: credentials.webhook, parameters: { httpMethod: 'POST', path: 'n45-netbox-events', authentication: 'headerAuth', responseMode: 'lastNode', options: {} } }),
  node({ id: '9a272fb6-8a85-42d4-b371-bbbc6ed3a230', name: 'Normalize NetBox Event', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-340, 160], parameters: { jsCode: normalizeNetBoxEvent } }),
  node({ id: '0d4e728c-e61f-43a9-acb9-44b334337686', name: 'Resolve in ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-60, 40], nodeCredentials: credentials.itflow, parameters: { method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/resolve.php', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: { batching: { batch: { batchSize: 20, batchInterval: 250 } } } }, ...sourceRetry }),
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
  node({ id: 'c8ed8a12-f878-47ef-a714-5feaf6c3cbea', name: 'Fetch Cloudflare Zones', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [-400, 0], nodeCredentials: credentials.cloudflare, parameters: { url: 'https://api.cloudflare.com/client/v4/zones?per_page=50', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', options: {} }, ...sourceRetry }),
  node({ id: '9e2aa024-f1b5-4993-95c2-bb7a6846d124', name: 'Map Zones to Clients', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-140, 0], parameters: { jsCode: normalizeCloudflareZones } }),
  node({ id: '19165423-18d4-46cd-b453-2ba704817470', name: 'Resolve Domains in ITFlow', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [120, 0], nodeCredentials: credentials.itflow, parameters: { method: 'POST', url: 'https://psa.n45tech.com/api/v1/integrations/automation/resolve.php', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: { batching: { batch: { batchSize: 10, batchInterval: 250 } } } }, ...sourceRetry }),
], {
  'Daily Domain Reconciliation': { main: [[{ node: 'Fetch Cloudflare Zones', type: 'main', index: 0 }]] },
  'Manual Domain Reconciliation': { main: [[{ node: 'Fetch Cloudflare Zones', type: 'main', index: 0 }]] },
  'Fetch Cloudflare Zones': { main: [[{ node: 'Map Zones to Clients', type: 'main', index: 0 }]] },
  'Map Zones to Clients': { main: [[{ node: 'Resolve Domains in ITFlow', type: 'main', index: 0 }]] },
});

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
}, { executionTimeout: 3600, timezone: 'UTC' });

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
}, { executionTimeout: 3600, timezone: 'UTC' });

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
}, { executionTimeout: 3600, timezone: 'UTC' });

const n8nErrorWorkflow = workflow('N45 - Automation Failure to ITFlow', [
  node({ id: 'cb44be91-41cd-4b32-a69a-ce2ad33d6ab7', name: 'Workflow Error', type: 'n8n-nodes-base.errorTrigger', typeVersion: 1, position: [-460, 0], parameters: {} }),
  node({ id: 'db633e22-451f-4796-a19c-14d0bea75343', name: 'Normalize n8n Error', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, 0], parameters: { jsCode: normalizeN8nError } }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf5', name: 'Open ITFlow Incident', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [60, 0], nodeCredentials: credentials.webhook, parameters: { method: 'POST', url: 'https://automate.n45tech.com/webhook/n45-itflow-events', authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth', sendBody: true, specifyBody: 'json', jsonBody: '={{ JSON.stringify($json) }}', options: {} }, ...sourceRetry }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf6', name: 'Normalize Device Source Failure', type: 'n8n-nodes-base.code', typeVersion: 2, position: [-200, 180], parameters: { jsCode: normalizeDeviceSourceFailure } }),
  node({ id: '02b2831b-e105-4ef3-a978-fe0adea1caf7', name: 'Record Device Source Failure', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [60, 180], nodeCredentials: credentials.itflow, parameters: sourcePublishParameters, ...sourceRetry }),
], {
  'Workflow Error': { main: [[
    { node: 'Normalize n8n Error', type: 'main', index: 0 },
    { node: 'Normalize Device Source Failure', type: 'main', index: 0 },
  ]] },
  'Normalize n8n Error': { main: [[{ node: 'Open ITFlow Incident', type: 'main', index: 0 }]] },
  'Normalize Device Source Failure': { main: [[{ node: 'Record Device Source Failure', type: 'main', index: 0 }]] },
}, { timezone: 'UTC' });

const workflows = [
  ['operations-event-broker.json', operationsBroker],
  ['cipp-alerts-to-itflow.json', cippAlerts],
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
