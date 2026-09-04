const mappingHelpers = String.raw`
const text = (value) => value === undefined || value === null ? '' : String(value).trim();
const integer = (value, fallback = 0) => Number.isInteger(Number(value)) ? Math.max(0, Number(value)) : fallback;
const bool = (value, fallback = false) => value === undefined || value === null || value === ''
  ? fallback : value === true || ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
const parseMappings = (source) => {
  const raw = text($vars.N45_DEVICE_SOURCE_MAP_JSON);
  if (!raw) throw new Error('N45_DEVICE_SOURCE_MAP_JSON is required.');
  let document;
  try { document = JSON.parse(raw); } catch { throw new Error('N45_DEVICE_SOURCE_MAP_JSON is not valid JSON.'); }
  const entries = document && Array.isArray(document[source]) ? document[source] : [];
  if (!entries.length) throw new Error('No mappings are configured for ' + source + '.');
  const scopes = entries.map((entry, index) => {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) throw new Error(source + ' mapping ' + index + ' is not an object.');
    const scopeId = text(entry.scope_id || entry.tenant_filter || entry.site_id);
    const clientId = integer(entry.client_id);
    if (!scopeId || !clientId) throw new Error(source + ' mapping ' + index + ' requires scope_id and client_id.');
    return {
      source,
      scope_id: scopeId,
      scope_name: text(entry.scope_name || entry.tenant_name || entry.site_name || scopeId),
      tenant_filter: text(entry.tenant_filter || scopeId),
      site_id: text(entry.site_id || scopeId),
      client_id: clientId,
      location_id: integer(entry.location_id),
      create_asset: bool(entry.create_asset, false),
      retirement_guard_percent: Math.max(0, Math.min(100, integer(entry.retirement_guard_percent, 50))),
      allow_empty: bool(entry.allow_empty, false),
    };
  });
  const duplicates = scopes.filter((scope, index) => scopes.findIndex((other) => other.scope_id.toLowerCase() === scope.scope_id.toLowerCase()) !== index);
  if (duplicates.length) throw new Error('Duplicate ' + source + ' scope_id: ' + duplicates[0].scope_id);
  return scopes;
};
const httpsBase = (value, label) => {
  const base = text(value).replace(/\/+$/, '');
  const match = base.match(/^https:\/\/([^/?#\s]+)(\/[^?#\s]*)?$/i);
  if (!match || match[1].includes('@')) {
    throw new Error(label + ' must be an HTTPS origin or base path without credentials or query parameters.');
  }
  return base;
};
const queryString = (parameters) => Object.entries(parameters)
  .map(([key, value]) => encodeURIComponent(key) + '=' + encodeURIComponent(text(value)))
  .join('&');
`.trim();

export function loadCippSourceConfig(source, endpoint, select) {
  return String.raw`
${mappingHelpers}
const SOURCE = '${source}';
const base = httpsBase($vars.N45_CIPP_BASE_URL, 'N45_CIPP_BASE_URL');
return parseMappings(SOURCE).map((scope) => {
  const query = queryString({
    TenantFilter: scope.tenant_filter,
    Endpoint: '${endpoint}',
    Version: 'v1.0',
    manualPagination: 'true',
    '$top': '999',
    '$select': '${select}',
  });
  return { json: { ...scope, request_url: base + '/api/ListGraphRequest?' + query } };
});
`.trim();
}

const cycleHelpers = String.raw`
const cycleStartedAt = new Date().toISOString();
const cycleId = SOURCE + ':' + cycleStartedAt.replace(/[^0-9TZ]/g, '') + ':' + Math.random().toString(36).slice(2, 10);
const clean = (value, length = 255) => text(value).slice(0, length);
const iso = (value) => {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '' : date.toISOString();
};
const completion = (scope, count) => ({ json: {
  action: 'complete', source: SOURCE, scope_id: scope.scope_id, scope_name: scope.scope_name,
  cycle_id: cycleId, cycle_started_at: cycleStartedAt, client_id: scope.client_id,
  reported_count: count, retirement_guard_percent: scope.retirement_guard_percent,
  allow_empty: scope.allow_empty,
} });
`.trim();

export const normalizeIntune = String.raw`
${mappingHelpers}
const SOURCE = 'intune';
${cycleHelpers}
const scopes = parseMappings(SOURCE);
const byTenant = new Map(scopes.map((scope) => [scope.tenant_filter.toLowerCase(), scope]));
const seenTenants = new Set();
const devices = new Map();
for (const item of $input.all()) {
  const response = item.json;
  if (!response || typeof response !== 'object' || Array.isArray(response) || !Array.isArray(response.Results)) {
    throw new Error('CIPP Intune response must contain a Results array.');
  }
  const tenant = text(response.Metadata?.TenantFilter || response.Metadata?.tenantFilter).toLowerCase();
  const scope = byTenant.get(tenant);
  if (!scope) throw new Error('CIPP returned an unmapped Intune tenant: ' + (tenant || '(missing)'));
  seenTenants.add(tenant);
  for (const device of response.Results) {
    const externalId = text(device?.id);
    if (!externalId) throw new Error('An Intune managed device is missing id.');
    devices.set(scope.scope_id + '\u0000' + externalId, { scope, device });
  }
}
for (const scope of scopes) {
  if (!seenTenants.has(scope.tenant_filter.toLowerCase())) throw new Error('CIPP returned no Intune page for ' + scope.tenant_filter + '.');
}
const output = [];
const counts = new Map(scopes.map((scope) => [scope.scope_id, 0]));
for (const { scope, device } of devices.values()) {
  const management = text(device.managementState).toLowerCase();
  const sourceStatus = /(pending|issued|failed|unhealthy)/.test(management) ? 'stale' : 'active';
  const healthAttestation = device.deviceHealthAttestationState && typeof device.deviceHealthAttestationState === 'object'
    ? device.deviceHealthAttestationState : {};
  const secureBootRaw = text(healthAttestation.secureBoot).toLowerCase();
  const secureBoot = ['true', 'enabled', 'on', '1'].includes(secureBootRaw)
    ? 'enabled' : (['false', 'disabled', 'off', '0'].includes(secureBootRaw) ? 'disabled' : 'unknown');
  const os = clean(device.operatingSystem || '');
  const osLower = os.toLowerCase();
  const type = /(windows|macos|linux)/.test(osLower) ? 'Computer'
    : (/(ios|android)/.test(osLower) ? 'Mobile Device' : 'Other');
  const interfaces = [];
  if (text(device.wiFiMacAddress)) interfaces.push({ key: 'wifi', name: 'Wi-Fi', type: 'Wireless', mac: device.wiFiMacAddress });
  if (text(device.ethernetMacAddress)) interfaces.push({ key: 'ethernet', name: 'Ethernet', type: 'Ethernet', mac: device.ethernetMacAddress });
  output.push({ json: {
    action: 'publish', source: SOURCE, scope_id: scope.scope_id, scope_name: scope.scope_name,
    cycle_id: cycleId, cycle_started_at: cycleStartedAt, client_id: scope.client_id,
    location_id: scope.location_id, create_asset: scope.create_asset,
    external_id: clean(device.id), external_name: clean(device.deviceName || device.managedDeviceName || device.id),
    status: sourceStatus, observed_at: cycleStartedAt,
    asset: {
      name: clean(device.deviceName || device.managedDeviceName || device.id),
      description: 'Managed by Microsoft Intune.', type,
      make: clean(device.manufacturer || 'Unknown'), model: clean(device.model || ''),
      serial: clean(device.serialNumber || ''), os, status: 'Active',
    },
    facts: {
      assigned_user: {
        id: clean(device.userId || ''), name: clean(device.userDisplayName || ''),
        email: clean(device.userPrincipalName || device.emailAddress || '', 320).toLowerCase(),
      },
      entra_device_id: clean(device.azureADDeviceId || device.azureAdDeviceId || ''),
      intune_device_id: clean(device.id), health_state: 'unknown',
      compliance_state: clean(device.complianceState || 'unknown'),
      is_encrypted: device.isEncrypted,
      secure_boot_state: secureBoot,
      operating_system: os, os_version: clean(device.osVersion || '', 100),
      lifecycle_state: 'active', last_seen_at: iso(device.lastSyncDateTime),
      details: { management_state: clean(device.managementState || ''), platform: os },
    },
    network_interfaces: interfaces,
    metadata: {
      scope_kind: 'tenant', tenant_id: scope.scope_id, tenant_name: scope.scope_name,
      source_status: sourceStatus, management_state: clean(device.managementState || ''),
    },
  } });
  counts.set(scope.scope_id, (counts.get(scope.scope_id) || 0) + 1);
}
for (const scope of scopes) output.push(completion(scope, counts.get(scope.scope_id) || 0));
return output;
`.trim();

export const normalizeEntra = String.raw`
${mappingHelpers}
const SOURCE = 'entra';
${cycleHelpers}
const scopes = parseMappings(SOURCE);
const byTenant = new Map(scopes.map((scope) => [scope.tenant_filter.toLowerCase(), scope]));
const seenTenants = new Set();
const devices = new Map();
for (const item of $input.all()) {
  const response = item.json;
  if (!response || typeof response !== 'object' || Array.isArray(response) || !Array.isArray(response.Results)) {
    throw new Error('CIPP Entra response must contain a Results array.');
  }
  const tenant = text(response.Metadata?.TenantFilter || response.Metadata?.tenantFilter).toLowerCase();
  const scope = byTenant.get(tenant);
  if (!scope) throw new Error('CIPP returned an unmapped Entra tenant: ' + (tenant || '(missing)'));
  seenTenants.add(tenant);
  for (const device of response.Results) {
    const externalId = text(device?.id);
    if (!externalId) throw new Error('An Entra device is missing id.');
    devices.set(scope.scope_id + '\u0000' + externalId, { scope, device });
  }
}
for (const scope of scopes) {
  if (!seenTenants.has(scope.tenant_filter.toLowerCase())) throw new Error('CIPP returned no Entra page for ' + scope.tenant_filter + '.');
}
const output = [];
const counts = new Map(scopes.map((scope) => [scope.scope_id, 0]));
for (const { scope, device } of devices.values()) {
  const enabled = device.accountEnabled !== false;
  const os = clean(device.operatingSystem || '');
  output.push({ json: {
    action: 'publish', source: SOURCE, scope_id: scope.scope_id, scope_name: scope.scope_name,
    cycle_id: cycleId, cycle_started_at: cycleStartedAt, client_id: scope.client_id,
    location_id: scope.location_id, create_asset: scope.create_asset,
    external_id: clean(device.id), external_name: clean(device.displayName || device.id),
    status: enabled ? 'active' : 'unmanaged', observed_at: cycleStartedAt,
    asset: {
      name: clean(device.displayName || device.id), description: 'Registered in Microsoft Entra ID.',
      type: 'Computer', make: clean(device.manufacturer || 'Unknown'), model: clean(device.model || ''),
      os, status: 'Active',
    },
    facts: {
      entra_device_id: clean(device.deviceId || ''), health_state: 'unknown',
      is_compliant: device.isCompliant, operating_system: os,
      os_version: clean(device.operatingSystemVersion || '', 100), lifecycle_state: 'active',
      last_seen_at: iso(device.approximateLastSignInDateTime),
      details: { management_state: enabled ? 'registered' : 'disabled', platform: os },
    },
    network_interfaces: [],
    metadata: {
      scope_kind: 'tenant', tenant_id: scope.scope_id, tenant_name: scope.scope_name,
      source_status: enabled ? 'active' : 'unmanaged', management_state: enabled ? 'registered' : 'disabled',
    },
  } });
  counts.set(scope.scope_id, (counts.get(scope.scope_id) || 0) + 1);
}
for (const scope of scopes) output.push(completion(scope, counts.get(scope.scope_id) || 0));
return output;
`.trim();

export const loadSentinelOneConfig = String.raw`
${mappingHelpers}
const SOURCE = 'sentinelone';
const base = httpsBase($vars.N45_SENTINELONE_BASE_URL, 'N45_SENTINELONE_BASE_URL');
const scopes = parseMappings(SOURCE);
const siteIds = scopes.map((scope) => scope.site_id);
const query = queryString({ limit: '1000', siteIds: siteIds.join(',') });
return [{ json: {
  source: SOURCE, site_ids: siteIds, site_ids_csv: siteIds.join(','),
  sites_url: base + '/web/api/v2.1/sites?' + query,
  agents_url: base + '/web/api/v2.1/agents?' + queryString({
    limit: '1000', siteIds: siteIds.join(','), isDecommissioned: 'false',
  }),
} }];
`.trim();

export const validateSentinelOneSites = String.raw`
${mappingHelpers}
const SOURCE = 'sentinelone';
const scopes = parseMappings(SOURCE);
const expected = new Set(scopes.map((scope) => scope.site_id));
const observed = new Map();
for (const item of $input.all()) {
  const response = item.json;
  if (!response || typeof response !== 'object' || Array.isArray(response)) throw new Error('SentinelOne sites response is invalid.');
  const sites = Array.isArray(response.data?.sites) ? response.data.sites
    : (Array.isArray(response.data) ? response.data : (Array.isArray(response.results) ? response.results : []));
  for (const site of sites) {
    const id = text(site?.id || site?.siteId);
    if (id) observed.set(id, text(site.name || site.siteName || id));
  }
}
for (const scope of scopes) {
  if (!observed.has(scope.site_id)) throw new Error('SentinelOne did not return configured site ' + scope.site_id + '.');
}
const base = httpsBase($vars.N45_SENTINELONE_BASE_URL, 'N45_SENTINELONE_BASE_URL');
const siteIds = [...expected];
return [{ json: {
  source: SOURCE, site_ids: siteIds, observed_sites: Object.fromEntries(observed),
  agents_url: base + '/web/api/v2.1/agents?' + queryString({
    limit: '1000', siteIds: siteIds.join(','), isDecommissioned: 'false',
  }),
} }];
`.trim();

export const normalizeSentinelOne = String.raw`
${mappingHelpers}
const SOURCE = 'sentinelone';
${cycleHelpers}
const scopes = parseMappings(SOURCE);
const bySite = new Map(scopes.map((scope) => [scope.site_id, scope]));
const agents = new Map();
for (const item of $input.all()) {
  const response = item.json;
  if (!response || typeof response !== 'object' || Array.isArray(response)) throw new Error('SentinelOne agents response is invalid.');
  const page = Array.isArray(response.data?.agents) ? response.data.agents
    : (Array.isArray(response.data) ? response.data : (Array.isArray(response.results) ? response.results : []));
  for (const agent of page) {
    const externalId = text(agent?.id || agent?.agentId);
    const siteId = text(agent?.siteId || agent?.site?.id || agent?.site?.siteId);
    if (!externalId) throw new Error('A SentinelOne agent is missing id.');
    const scope = bySite.get(siteId) || (scopes.length === 1 ? scopes[0] : null);
    if (!scope) throw new Error('SentinelOne returned agent ' + externalId + ' for unmapped site ' + (siteId || '(missing)') + '.');
    agents.set(scope.scope_id + '\u0000' + externalId, { scope, agent });
  }
}
const output = [];
const counts = new Map(scopes.map((scope) => [scope.scope_id, 0]));
for (const { scope, agent } of agents.values()) {
  const threats = integer(agent.activeThreats ?? agent.activeThreatsCount ?? agent.threatCount);
  const active = agent.isActive !== false;
  const infected = agent.infected === true || threats > 0;
  const decommissioned = agent.isDecommissioned === true;
  const health = infected ? 'critical' : (active ? 'healthy' : 'offline');
  const interfaces = [];
  const rawInterfaces = Array.isArray(agent.networkInterfaces) ? agent.networkInterfaces : [];
  for (let index = 0; index < rawInterfaces.length; index += 1) {
    const nic = rawInterfaces[index] || {};
    const addresses = [
      ...(Array.isArray(nic.inet) ? nic.inet : []),
      ...(Array.isArray(nic.inet6) ? nic.inet6 : []),
      ...(Array.isArray(nic.ipAddresses) ? nic.ipAddresses : []),
    ].map((value) => text(value)).filter(Boolean);
    interfaces.push({
      key: clean(nic.id || nic.name || ('interface-' + index)),
      name: clean(nic.name || nic.id || ('Interface ' + (index + 1))),
      type: clean(nic.type || ''), mac: clean(nic.physical || nic.macAddress || ''),
      ip_addresses: addresses,
    });
  }
  const os = clean(agent.osName || agent.osType || '');
  output.push({ json: {
    action: 'publish', source: SOURCE, scope_id: scope.scope_id, scope_name: scope.scope_name,
    cycle_id: cycleId, cycle_started_at: cycleStartedAt, client_id: scope.client_id,
    location_id: scope.location_id, create_asset: scope.create_asset,
    external_id: clean(agent.id || agent.agentId), external_name: clean(agent.computerName || agent.id),
    status: decommissioned ? 'retired' : 'active', observed_at: cycleStartedAt,
    asset: {
      name: clean(agent.computerName || agent.id), description: 'Protected by SentinelOne.',
      type: 'Computer', make: clean(agent.manufacturer || 'Unknown'), model: clean(agent.modelName || ''),
      serial: clean(agent.serialNumber || ''), os, status: 'Active',
    },
    facts: {
      health_state: health, operating_system: os,
      os_version: clean(agent.osRevision || agent.osVersion || '', 100),
      agent_version: clean(agent.agentVersion || '', 100), lifecycle_state: decommissioned ? 'retired' : 'active',
      last_seen_at: iso(agent.lastActiveDate || agent.updatedAt),
      details: {
        agent_version: clean(agent.agentVersion || '', 100), edr_state: health,
        firewall_state: agent.firewallEnabled === true ? 'enabled' : (agent.firewallEnabled === false ? 'disabled' : 'unknown'),
        platform: os, threat_count: threats,
      },
    },
    network_interfaces: interfaces,
    metadata: {
      scope_kind: 'site', site_id: scope.scope_id, site_name: scope.scope_name,
      source_status: decommissioned ? 'retired' : 'active',
    },
  } });
  if (!decommissioned) counts.set(scope.scope_id, (counts.get(scope.scope_id) || 0) + 1);
}
for (const scope of scopes) output.push(completion(scope, counts.get(scope.scope_id) || 0));
return output;
`.trim();

export const normalizeDeviceSourceFailure = String.raw`
${mappingHelpers}
const input = $input.first().json;
const workflow = input.workflow || {};
const execution = input.execution || {};
const name = text(workflow.name).toLowerCase();
const SOURCE = name.includes('intune') ? 'intune'
  : (name.includes('entra') ? 'entra' : (name.includes('sentinelone') ? 'sentinelone' : ''));
if (!SOURCE) return [];
const redact = (value) => text(value)
  .replace(/\b(Bearer|Basic|ApiToken)\s+[^\s,;]+/gi, '$1 [redacted]')
  .replace(/([?&](?:access_token|api[_-]?key|authorization|client_secret|code|password|refresh_token|secret|token)=)[^&\s]*/gi, '$1[redacted]')
  .slice(0, 2000);
const started = new Date(execution.startedAt || Date.now());
const cycleStartedAt = Number.isNaN(started.getTime()) ? new Date().toISOString() : started.toISOString();
const cycleId = SOURCE + ':failed:' + text(execution.id || Date.now()).replace(/[^A-Za-z0-9._:-]/g, '_');
const error = execution.error || input.trigger?.error || input.error || {};
const message = redact(error.message || error.description || 'The source reconciliation workflow failed.');
return parseMappings(SOURCE).map((scope) => ({ json: {
  action: 'failure', source: SOURCE, scope_id: scope.scope_id, scope_name: scope.scope_name,
  cycle_id: cycleId, cycle_started_at: cycleStartedAt, client_id: scope.client_id, error: message,
} }));
`.trim();
