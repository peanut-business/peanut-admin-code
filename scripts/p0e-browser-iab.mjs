import { createHash, randomBytes } from 'node:crypto';
import { readFile, unlink, writeFile } from 'node:fs/promises';
import { createServer, request as httpRequest } from 'node:http';
import { once } from 'node:events';
import { join } from 'node:path';

const [collector, controller, fixturePath, uiPath] = process.argv.slice(2);
const origin = new URL(collector);
if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' || !origin.port || origin.origin !== collector) {
  throw new Error('invalid lease-bound IAB collector origin');
}
let inputText = '';
for await (const chunk of process.stdin.setEncoding('utf8')) inputText += chunk;
const input = JSON.parse(inputText);
const mode = input.P0E_BROWSER_MODE;
const output = input.P0E_BROWSER_OUTPUT_DIR;
const bundle = join(output, 'iab-input.json');
const key = randomBytes(32).toString('hex');
const tenant = input.P0E_BROWSER_TENANT_ADMIN_URL.replace(/\/$/u, '');
const beta = input.P0E_BROWSER_TENANT_BETA_URL.replace(/\/$/u, '');
const platform = input.P0E_BROWSER_PLATFORM_URL.replace(/\/$/u, '');
const docs = input.P0E_BROWSER_DOCS_URL.replace(/\/$/u, '');
const origins = new Set([tenant, beta, platform, docs, `http://127.0.0.1:${new URL(tenant).port}`, `http://localhost:${new URL(tenant).port}`]);
const credentials = Object.fromEntries([
  ['admin', 'P0E_ADMIN_INITIAL'], ['restricted', 'P0E_TENANT_RESTRICTED'],
  ['tenant-beta', 'P0E_TENANT_BETA'], ['platform', 'P0E_PLATFORM_INITIAL'],
].map(([label, prefix]) => [label, [input[`${prefix}_EMAIL`], input[`${prefix}_PASSWORD`]]]));
const pngSignature = Buffer.from('89504e470d0a1a0a', 'hex');

async function apiFetch(value, options = {}, redirects = options.maxRedirects ?? 20) {
  const url = new URL(value);
  if (!origins.has(url.origin) || url.username || url.password) throw new Error('IAB API request left its qualification origins');
  for (const [name, parameter] of Object.entries(options.params ?? {})) url.searchParams.set(name, parameter);
  const headers = { ...options.headers };
  let body;
  if (options.multipart) {
    const form = new FormData();
    for (const [name, item] of Object.entries(options.multipart)) {
      if (typeof item === 'string') form.append(name, item);
      else form.append(name, new Blob([item.buffer], { type: item.mimeType }), item.name);
    }
    const encoded = new Response(form);
    body = Buffer.from(await encoded.arrayBuffer());
    headers['Content-Type'] = encoded.headers.get('content-type');
  } else if (options.data !== undefined) {
    body = Buffer.from(JSON.stringify(options.data));
    headers['Content-Type'] = 'application/json';
  }
  if (body) headers['Content-Length'] = body.length;
  const response = await new Promise((resolve, reject) => {
    // These registered listeners bind IPv4 loopback. Preserve the Host while resolving .localhost locally.
    const req = httpRequest(url, { method: options.method ?? 'GET', headers,
      lookup: (_hostname, lookupOptions, callback) => lookupOptions.all
        ? callback(null, [{ address: '127.0.0.1', family: 4 }]) : callback(null, '127.0.0.1', 4),
    }, resolve);
    req.on('error', () => reject(new Error('IAB qualification API transport failed')));
    req.setTimeout(30000, () => req.destroy());
    req.end(body);
  });
  if (redirects > 0 && [301, 302, 303, 307, 308].includes(response.statusCode) && response.headers.location) {
    response.resume();
    const next = new URL(response.headers.location, url);
    if (next.origin !== url.origin) throw new Error('IAB qualification API redirected to a different origin');
    const preserve = [307, 308].includes(response.statusCode) || !options.method || options.method === 'GET';
    return apiFetch(next, preserve ? { ...options, params: undefined } : { headers: options.headers }, redirects - 1);
  }
  const parts = [];
  for await (const chunk of response) parts.push(chunk);
  const bytes = Buffer.concat(parts);
  return { url: () => url.href, status: () => response.statusCode, headers: () => response.headers,
    text: async () => bytes.toString('utf8'), body: async () => bytes };
}

let sequence = 0;
let pending;
let controllerSession;
let uiStopped = false;
const reply = (res, status, value) => res.writeHead(status, {
  'Content-Type': 'application/json', 'Cache-Control': 'no-store',
}).end(JSON.stringify(value));
const server = createServer(async (req, res) => {
  if (req.headers.host !== origin.host) return reply(res, 400, {});
  if (req.method === 'GET' && req.url === '/') {
    return res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' })
      .end('<title>Peanut IAB qualification</title><label>Qualification input <input type="file"></label>');
  }
  if (req.url !== '/step' || req.headers.authorization !== `Bearer ${key}`) return reply(res, 404, {});
  if (req.method === 'GET') return reply(res, 200, pending?.step ?? null);
  if (req.method !== 'POST') return reply(res, 405, {});
  try {
    let bytes = 0;
    const parts = [];
    for await (const part of req) {
      bytes += part.length;
      if (bytes > 18 * 1024 * 1024) throw new Error('oversized IAB evidence');
      parts.push(part);
    }
    const result = JSON.parse(Buffer.concat(parts).toString('utf8'));
    if (!pending || pending.step.id !== result.id || result.controller !== controller) return reply(res, 409, {});
    controllerSession = result.controller;
    const current = pending;
    pending = undefined;
    reply(res, 200, {});
    if (result.failed) {
      uiStopped = true;
      current.reject(new Error(`IAB UI failed in ${current.step.kind} ${current.step.label ?? ''}${result.cleanupFailed ? '; session/tab cleanup incomplete' : ''}`));
    } else current.resolve(result.evidence);
  } catch {
    reply(res, 400, {});
  }
});
const step = (kind, arguments_) => new Promise((resolve, reject) => {
  const id = ++sequence;
  const timeout = setTimeout(() => {
    pending = undefined;
    uiStopped = true; // The outstanding browser step may still have taken effect.
    reject(new Error(`IAB UI timed out in ${kind} ${arguments_.label ?? ''}`));
  }, id === 1 ? 600000 : 90000);
  pending = { step: { id, kind, ...arguments_ },
    resolve: (value) => { clearTimeout(timeout); resolve(value); },
    reject: (error) => { clearTimeout(timeout); reject(error); } };
});
const screenshot = async (label, data) => {
  const bytes = Buffer.from(data, 'base64');
  if (!bytes.subarray(0, 8).equals(pngSignature)) throw new Error(`missing browser PNG: ${label}`);
  await writeFile(join(output, `${mode}-${label}.png`), bytes, { mode: 0o600, flag: 'wx' });
  return bytes;
};
const driver = {
  sha256: (value) => createHash('sha256').update(value).digest('hex'),
  request: { fetch: apiFetch, get: (url, options) => apiFetch(url, options) },
  async login(label, url) {
    const evidence = await step('login', { label, url });
    const actual = new URL(evidence.url);
    if (actual.origin !== url || !actual.pathname.startsWith(label === 'platform' ? '/platform/' : '/admin/')
      || actual.pathname.endsWith('/login') || !evidence.token || !evidence.title) throw new Error(`invalid ${label} login evidence`);
    if (label === 'admin') await writeFile(join(output, `${mode}-initial-snapshot.txt`), evidence.initial, { mode: 0o600, flag: 'wx' });
    return { url: evidence.url, title: evidence.title, token: evidence.token, png: await screenshot(label, evidence.png) };
  },
  async navigate(url, api) {
    const evidence = await step('navigate', { url, api });
    if (evidence.url !== url || !Number.isInteger(evidence.response?.status)) throw new Error('IAB navigation response is missing');
    return evidence;
  },
  async saveWebsite(marker, api) {
    const evidence = await step('website', { marker, api });
    if (!Number.isInteger(evidence.status)) throw new Error('IAB website save response is missing');
    return evidence;
  },
  async render(label, url) {
    const evidence = await step('render', { label, url });
    if (new URL(evidence.url).origin !== new URL(url).origin) throw new Error(`IAB ${label} left its qualification origin`);
    await screenshot(label, evidence.png);
    if (label === 'docs') await writeFile(join(output, `${mode}-final-snapshot.txt`), evidence.snapshot, { mode: 0o600, flag: 'wx' });
    return evidence;
  },
};

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
let bundleWritten = false;
const cleanup = async () => {
  if (server.listening) {
    server.close();
  }
  if (bundleWritten) {
    bundleWritten = false;
    await unlink(bundle);
  }
};
for (const [signal, code] of [['SIGINT', 130], ['SIGTERM', 143]]) {
  process.once(signal, async () => {
    try { await cleanup(); } finally { process.exit(code); }
  });
}
try {
  const fixture = new AsyncFunction('page', 'process', 'iabDriver', await readFile(fixturePath, 'utf8'));
  server.listen(Number(origin.port), origin.hostname);
  await once(server, 'listening');
  await writeFile(bundle, JSON.stringify({ collector, key, credentials, source: await readFile(uiPath, 'utf8') }), { mode: 0o600, flag: 'wx' });
  bundleWritten = true;
  process.stdout.write(`iab_input=${bundle}\niab_collector=${collector}\n`);
  let result;
  try {
    result = await fixture(null, { env: input }, driver);
  } catch (error) {
    if (sequence > 0 && !uiStopped) {
      try { await step('abort', {}); }
      catch (cleanupError) { throw new AggregateError([error, cleanupError], 'IAB fixture and session cleanup failed'); }
    }
    throw error;
  }
  result.results.browser_session.controller_session = controllerSession;
  await step('finish', {});
  process.stdout.write(`${JSON.stringify(result)}\n`);
} finally {
  await cleanup();
}
