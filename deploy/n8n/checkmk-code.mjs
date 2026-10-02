// The sender owns native Checkmk translation; the broker accepts a bounded,
// explicit canonical contract before any event can reach its durable queue.
export function validateCheckmkEvent(event) {
  const object = (value) => value && typeof value === 'object' && !Array.isArray(value);
  const fail = () => { throw new Error('Invalid canonical Checkmk event.'); };
  if (!object(event) || event.source !== 'checkmk' || !object(event.identity)
    || !object(event.metadata) || !object(event.metadata.checkmk)) fail();
  const context = event.metadata.checkmk;
  if (!/^[A-Za-z0-9_-]{1,64}$/.test(context.site || '')
    || typeof context.host !== 'string' || !context.host || context.host.length > 255
    || !['HOST', 'SERVICE'].includes(context.what)
    || typeof context.service !== 'string' || context.service.length > 1024
    || (context.what === 'SERVICE' ? !context.service : context.service !== '')) fail();
  const healthy = context.what === 'HOST' ? ['UP'] : ['OK'];
  const unhealthy = context.what === 'HOST' ? ['DOWN', 'UNREACH', 'UNREACHABLE'] : ['WARN', 'CRIT', 'UNKNOWN'];
  if (context.notification_type === 'RECOVERY') {
    if (event.state !== 'resolved' || !healthy.includes(context.state)) fail();
  } else if (context.notification_type === 'PROBLEM') {
    if (event.state !== 'open' || !unhealthy.includes(context.state)) fail();
  } else fail();
  if (event.entity_type !== 'host' || event.identity.entity_type !== 'host'
    || event.identity.external_name !== context.host
    || !/^checkmk:host:[a-f0-9]{64}$/.test(event.identity.external_id || '')
    || !/^checkmk:object:[a-f0-9]{64}$/.test(event.incident_key || '')
    || !/^checkmk:event:[a-f0-9]{64}$/.test(event.event_id || '')
    || typeof event.occurred_at !== 'string' || !event.occurred_at
    || Number.isNaN(Date.parse(event.occurred_at))) fail();
  const client = event.identity.client;
  if (!object(client) || !(Number.isSafeInteger(client.id) && client.id > 0
    || typeof client.name === 'string' && client.name.trim())) fail();
  const options = event.identity.options;
  if (!object(options) || ['create_client', 'create_location', 'create_asset', 'create_domain']
    .some((key) => options[key] !== undefined && options[key] !== false)) fail();
  return event;
}
