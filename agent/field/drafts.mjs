// Note text alone is persisted. Keys remain in memory and are forgotten on cover.
// AES-GCM authenticated data binds every envelope to this origin, account and draft.
const utf8 = new TextEncoder();
const b64 = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const bytes = value => Uint8Array.from(atob(value), c => c.charCodeAt(0));
export class DraftVault {
  constructor(userId) { this.userId = String(userId); this.prefix = `n45-field:${this.userId}:`; this.key = null; }
  get configured() { return !!localStorage.getItem(this.prefix + 'config'); }
  async unlock(passphrase, create = false) {
    if (!crypto.subtle) throw new Error('Encrypted recovery requires a secure connection on this device.');
    if (create && passphrase.length < 10) throw new Error('Use a recovery passphrase with at least 10 characters.');
    let config = JSON.parse(localStorage.getItem(this.prefix + 'config') || 'null');
    if (!config && !create) throw new Error('Set up note recovery while signed in.');
    const salt = config ? bytes(config.salt) : crypto.getRandomValues(new Uint8Array(16));
    const material = await crypto.subtle.importKey('raw', utf8.encode(passphrase), 'PBKDF2', false, ['deriveKey']);
    const key = await crypto.subtle.deriveKey({name:'PBKDF2',salt,iterations:310000,hash:'SHA-256'}, material, {name:'AES-GCM',length:256}, false, ['encrypt','decrypt']);
    this.key = key;
    try {
      if (config) { if (await this.decrypt('check', config.check) !== 'n45-field-notes-v1') throw new Error('Invalid vault.'); }
      else { config = {salt:b64(salt),check:await this.encrypt('check','n45-field-notes-v1')}; localStorage.setItem(this.prefix + 'config',JSON.stringify(config)); }
    } catch { this.key = null; throw new Error('The recovery passphrase did not unlock these notes.'); }
  }
  aad(id) { return utf8.encode(`${location.origin}|${this.userId}|${id}`); }
  async encrypt(id, text) {
    if (!this.key) throw new Error('Unlock note recovery before saving a draft.');
    const iv = crypto.getRandomValues(new Uint8Array(12));
    return {iv:b64(iv),cipher:b64(await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:this.aad(id)},this.key,utf8.encode(text)))};
  }
  async decrypt(id, value) {
    if (!this.key) throw new Error('Unlock note recovery to open your drafts.');
    return new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:bytes(value.iv),additionalData:this.aad(id)},this.key,bytes(value.cipher)));
  }
  async save(id, note) { const envelope = await this.encrypt(id, JSON.stringify(note)); localStorage.setItem(this.prefix + 'draft:' + id, JSON.stringify(envelope)); }
  async list() {
    const rows = [];
    for (const key of Object.keys(localStorage).filter(k => k.startsWith(this.prefix + 'draft:'))) {
      const id = key.slice((this.prefix + 'draft:').length);
      try { rows.push({id, ...JSON.parse(await this.decrypt(id, JSON.parse(localStorage.getItem(key))))}); }
      catch { rows.push({id,unreadable:true}); }
    }
    return rows.sort((a,b)=>(b.saved_at||0)-(a.saved_at||0));
  }
  remove(id) { localStorage.removeItem(this.prefix + 'draft:' + id); }
  lock() { this.key = null; }
}
export function knownAccounts() {
  return Object.keys(localStorage).filter(k => /^n45-field:\d+:config$/.test(k)).map(k=>k.split(':')[1]);
}
