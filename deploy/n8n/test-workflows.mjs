import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const workflowDirectory = join(root, 'workflows');
const files = (await readdir(workflowDirectory)).filter((file) => file.endsWith('.json')).sort();
assert.equal(files.length, 4, 'Expected four generated workflows');

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

function code(workflowName, nodeName, input) {
  const workflow = workflows.get(workflowName);
  assert(workflow, `Missing workflow: ${workflowName}`);
  const codeNode = workflow.nodes.find((node) => node.name === nodeName);
  assert(codeNode, `Missing node: ${nodeName}`);
  const execute = new Function('$input', codeNode.parameters.jsCode);
  return execute({ first: () => ({ json: input }) });
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

console.log(`Validated ${files.length} workflows and representative source payloads.`);
