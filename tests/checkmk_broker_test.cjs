const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');

const root = join(__dirname, '..');
const workflow = JSON.parse(readFileSync(join(root, 'deploy/n8n/workflows/operations-event-broker.json')));
const execute = (name, json, vars = {}) => {
  const node = workflow.nodes.find(node => node.name === name);
  return new Function('$input', '$vars', node.parameters.jsCode)({ first: () => ({ json }) }, vars);
};
// Exercise the real sender's output through the generated broker code.
const event = JSON.parse(execFileSync('python3', ['-c', `
import importlib.util, json
spec=importlib.util.spec_from_file_location('sender','deploy/checkmk/n45_itflow.py')
sender=importlib.util.module_from_spec(spec); spec.loader.exec_module(sender)
config={'site':'cmk','checkmk_url':'https://monitor.example.net/cmk/','hosts':{'ops01':{'client':{'id':7},'asset':{'id':11}}}}
env={'NOTIFY_WHAT':'SERVICE','NOTIFY_HOSTNAME':'ops01','NOTIFY_SERVICEDESC':'Filesystem /','NOTIFY_NOTIFICATIONTYPE':'PROBLEM','NOTIFY_SERVICESTATE':'CRIT','NOTIFY_LASTSERVICESTATECHANGE':'1790956800','NOTIFY_SERVICEOUTPUT':'Disk full'}
print(json.dumps(sender.build_event(env,config)))
`], { cwd: root, encoding: 'utf8' }));

test('the dedicated ingress retains authenticated durable queue ordering', () => {
  const webhook = workflow.nodes.find(node => node.name === 'Checkmk Webhook');
  assert.equal(webhook.parameters.authentication, 'headerAuth');
  assert.equal(webhook.credentials.httpHeaderAuth.name, 'N45 Integration Webhook');
  assert.equal(webhook.parameters.path, 'n45-checkmk-events');
  for (const [from, to] of [['Checkmk Webhook', 'Require Checkmk Source'], ['Require Checkmk Source', 'Normalize Event'],
    ['Normalize Event', 'Queue Event'], ['Queue Event', 'Acknowledge Event']]) {
    assert.equal(workflow.connections[from].main[0][0].node, to);
  }
  assert.throws(() => execute('Require Checkmk Source', { body: { ...event, source: 'backup' } }), /only accepts Checkmk/);
});

test('the sender and broker preserve explicit host mappings and service lifecycle identity', () => {
  const ingress = execute('Require Checkmk Source', { body: event })[0].json;
  const normalized = execute('Normalize Event', ingress, {
    N45_EVENT_ROUTING_JSON: JSON.stringify({ checkmk: { assigned_to: 13, category_id: 15 } }),
  })[0].json;
  assert.equal(normalized.source, 'checkmk');
  assert.equal(normalized.incident_key, event.incident_key);
  assert.equal(normalized.event_id, event.event_id);
  assert.equal(normalized.identity.client.id, 7);
  assert.equal(normalized.identity.asset.id, 11);
  assert.equal(normalized.identity.external_id, event.identity.external_id);
  assert.equal(normalized.entity_type, 'host');
  assert.equal(normalized.request_type_key, 'monitoring-alert');
  assert.equal(normalized.contact_mode, 'none');
  assert.equal(normalized.assigned_to, 13);
  assert.equal(normalized.category_id, 15);
  assert.equal(normalized.severity, 'critical');
  const recovered = structuredClone(event);
  recovered.state = 'resolved'; recovered.metadata.checkmk.state = 'OK'; recovered.metadata.checkmk.notification_type = 'RECOVERY';
  assert.equal(execute('Normalize Event', { body: recovered })[0].json.state, 'resolved');
});

test('malformed, unmapped, non-lifecycle, and creation-enabled payloads fail before storage', () => {
  const mutations = [
    e => { delete e.identity; }, e => { e.identity.client = {}; }, e => { e.identity.options.create_asset = true; },
    e => { e.entity_type = 'service'; }, e => { e.identity.external_name = 'foreign-host'; },
    e => { e.metadata.checkmk.service = ''; }, e => { e.metadata.checkmk.state = 'OK'; },
    e => { e.metadata.checkmk.notification_type = 'ACKNOWLEDGEMENT'; }, e => { delete e.occurred_at; },
    e => { e.event_id = 'ambiguous'; }, e => { e.incident_key = 'ambiguous'; }, e => { e.metadata.checkmk.site = ''; },
  ];
  for (const mutate of mutations) {
    const invalid = structuredClone(event); mutate(invalid);
    assert.throws(() => execute('Normalize Event', { body: invalid }), /Invalid canonical Checkmk/);
  }
  assert.throws(() => execute('Normalize Event', { body: { source: 'checkmk', status: 0, host: 'ops01' } }), /Invalid canonical Checkmk/);
});
