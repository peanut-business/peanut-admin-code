const required = (name) => {
  const value = process.env[name];
  if (!value) throw new Error(`missing browser environment: ${name}`);
  return value;
};
const mode = required('P0E_BROWSER_MODE');
const profile = process.env.P0E_BROWSER_PROFILE || 'baseline';
const tenantAdminUrl = required('P0E_BROWSER_TENANT_ADMIN_URL').replace(/\/$/u, '');
const tenantBetaUrl = (process.env.P0E_BROWSER_TENANT_BETA_URL || '').replace(/\/$/u, '');
const platformUrl = required('P0E_BROWSER_PLATFORM_URL').replace(/\/$/u, '');
const docsUrl = (process.env.P0E_BROWSER_DOCS_URL || '').replace(/\/$/u, '');
const outputDir = required('P0E_BROWSER_OUTPUT_DIR');
if (!['standalone', 'multi-tenant'].includes(mode)) throw new Error(`invalid mode: ${mode}`);
if (!['baseline', 'release'].includes(profile)) throw new Error(`invalid profile: ${profile}`);
if (profile === 'baseline' && docsUrl === '') throw new Error('missing browser environment: P0E_BROWSER_DOCS_URL');
const screenshotPath = (label) => profile === 'baseline'
  ? `${outputDir}/${mode}-${label}.png`
  : `${outputDir}/${mode}-${profile}-${label}.png`;

const assertPage = async (targetPage, url, label, minimumText = 20) => {
  const response = await targetPage.goto(url, { waitUntil: 'networkidle' });
  if (!response || response.status() >= 400) throw new Error(`${label} returned ${response?.status()}`);
  const text = (await targetPage.locator('body').innerText()).trim();
  if (text.length < minimumText) throw new Error(`${label} rendered insufficient content`);
  await targetPage.screenshot({ path: screenshotPath(label), fullPage: true });
  return { url: targetPage.url(), status: response.status(), text_length: text.length };
};

const loginTenant = async (targetPage, url, email, password, label, menu = null) => {
  await targetPage.goto(`${url}/admin/login`, { waitUntil: 'networkidle' });
  const form = targetPage.locator('.login-form');
  await form.locator('input').nth(0).fill(email);
  await form.locator('input[type="password"]').fill(password);
  await form.getByRole('button', { name: /登录|login/i }).click();
  const transition = await Promise.race([
    form.locator('.el-select').waitFor({ state: 'visible', timeout: 20000 }).then(() => 'select'),
    targetPage.waitForURL((value) => !value.pathname.endsWith('/login'), { timeout: 20000 }).then(() => 'navigated'),
  ]);
  if (transition === 'select') {
    await form.locator('.el-select').click();
    await targetPage.locator('.el-select-dropdown:visible .el-select-dropdown__item').first().click();
    await form.getByRole('button', { name: /登录|login/i }).click();
  }
  await targetPage.waitForURL((value) => !value.pathname.endsWith('/login'), { timeout: 20000 });
  await targetPage.locator('.user-menu-trigger').waitFor({ state: 'visible', timeout: 20000 });
  await targetPage.waitForLoadState('networkidle');
  const body = await targetPage.locator('body').innerText();
  if (menu) {
    for (const expected of menu.required) {
      if (!body.includes(expected)) throw new Error(`${label} required menu text is missing: ${expected}`);
    }
    for (const forbidden of menu.forbidden) {
      if (body.includes(forbidden)) throw new Error(`${label} forbidden menu text is visible: ${forbidden}`);
    }
  }
  const token = await targetPage.evaluate(() => localStorage.getItem('token'));
  if (!token || !token.startsWith('pa_tat_')) throw new Error(`${label} did not receive a Tenant access token`);
  await targetPage.screenshot({ path: screenshotPath(label), fullPage: true });
  return { page: targetPage, token, body };
};

const responseValue = async (response) => {
  const text = await response.text();
  let json = null;
  try { json = text === '' ? null : JSON.parse(text); } catch { /* status and body checks still apply */ }
  return { status: response.status(), headers: response.headers(), text, json };
};
const envelopeSuccess = (value) => value.status >= 200 && value.status < 300
  && (!value.json || typeof value.json.code !== 'number' || [2, 20000].includes(value.json.code));
const assertSuccess = (value, label) => {
  if (!envelopeSuccess(value)) throw new Error(`${label} failed with HTTP ${value.status} / code ${value.json?.code ?? 'none'}`);
  return value.json?.data ?? value.json;
};
const request = async (session, baseUrl, method, path, options = {}) => {
  const url = new URL(path, `${baseUrl}/`);
  for (const [key, value] of Object.entries(options.query || {})) url.searchParams.set(key, String(value));
  const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}`, ...(options.headers || {}) };
  const requestOptions = { method, headers, failOnStatusCode: false };
  if (options.data !== undefined) requestOptions.data = options.data;
  if (options.multipart !== undefined) requestOptions.multipart = options.multipart;
  return responseValue(await session.page.request.fetch(url.toString(), requestOptions));
};
const listItems = (data, label) => {
  const items = data?.lists ?? data?.items;
  if (!Array.isArray(items)) throw new Error(`${label} returned no list`);
  return items;
};

if (profile === 'baseline') {
  const adminEmail = required('P0E_ADMIN_INITIAL_EMAIL');
  const adminPassword = required('P0E_ADMIN_INITIAL_PASSWORD');
  const platformEmail = required('P0E_PLATFORM_INITIAL_EMAIL');
  const platformPassword = required('P0E_PLATFORM_INITIAL_PASSWORD');
  const results = {};
  await page.goto(`${tenantAdminUrl}/admin/login`, { waitUntil: 'networkidle' });
  await page.locator('input').nth(0).fill(adminEmail);
  await page.locator('input[type="password"]').fill(adminPassword);
  await page.getByRole('button', { name: /登录|login/i }).click();
  if (mode === 'multi-tenant') {
    const tenantTransition = await Promise.race([
      page.locator('.login-form .el-select').waitFor({ state: 'visible', timeout: 20000 }).then(() => 'select'),
      page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }).then(() => 'navigated'),
    ]);
    if (tenantTransition === 'select') {
      const tenantSelector = page.locator('.login-form .el-select');
      await tenantSelector.click();
      await page.locator('.el-select-dropdown:visible .el-select-dropdown__item').first().click();
      await page.getByRole('button', { name: /登录|login/i }).waitFor({ state: 'visible' });
      await page.getByRole('button', { name: /登录|login/i }).click();
    }
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  if (page.url().includes('/login')) throw new Error('tenant administrator login did not leave the login page');
  await page.screenshot({ path: screenshotPath('admin'), fullPage: true });
  results.admin = { url: page.url(), title: await page.title() };
  if (mode === 'multi-tenant') {
    await page.goto(`${platformUrl}/platform/`, { waitUntil: 'networkidle' });
    const inputs = page.locator('input');
    await inputs.nth(0).fill(platformEmail);
    await inputs.nth(1).fill(platformPassword);
    await page.getByRole('button', { name: /登录实例平台/i }).click();
    await page.getByText('概览', { exact: true }).first().waitFor({ state: 'visible', timeout: 20000 });
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: screenshotPath('platform'), fullPage: true });
    results.platform = { url: page.url(), title: await page.title() };
  }
  results.pc = await assertPage(page, `${tenantAdminUrl}/pc/`, 'pc');
  results.h5 = await assertPage(page, `${tenantAdminUrl}/mobile/`, 'h5');
  results.docs = await assertPage(page, `${docsUrl}/`, 'docs');
  console.log(JSON.stringify({ schema_version: 1, mode, status: 'passed', results }));
  return;
}

const identity = JSON.parse(required('P0E_BROWSER_IDENTITY_JSON'));
const alphaEmail = required('P0E_TENANT_ALPHA_EMAIL');
const alphaPassword = required('P0E_TENANT_ALPHA_PASSWORD');
const betaEmail = mode === 'multi-tenant' ? required('P0E_TENANT_BETA_EMAIL') : '';
const betaPassword = mode === 'multi-tenant' ? required('P0E_TENANT_BETA_PASSWORD') : '';
const restrictedEmail = required('P0E_TENANT_RESTRICTED_EMAIL');
const restrictedPassword = required('P0E_TENANT_RESTRICTED_PASSWORD');
const platformEmail = mode === 'multi-tenant' ? required('P0E_PLATFORM_INITIAL_EMAIL') : '';
const platformPassword = mode === 'multi-tenant' ? required('P0E_PLATFORM_INITIAL_PASSWORD') : '';
const browser = page.context().browser();
if (!browser) throw new Error('release browser qualification requires a browser-backed page');
const contexts = [];
const results = { package_identity: null, tenant_sessions: {}, lifecycle: {}, file: {}, probes: {}, ssr: {}, logout: {} };
const newPage = async () => {
  const context = await browser.newContext();
  contexts.push(context);
  return context.newPage();
};
const replace = (value, variables) => {
  if (typeof value === 'string') return value.replace(/\{\{([a-z0-9_]+)\}\}/gu, (_, key) => {
    if (!(key in variables)) throw new Error(`unknown probe variable: ${key}`);
    return String(variables[key]);
  });
  if (Array.isArray(value)) return value.map((item) => replace(item, variables));
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, replace(item, variables)]));
  return value;
};
const runProbe = async (probe, kind, sessions, variables) => {
  if (!probe || typeof probe.name !== 'string' || !['alpha', 'beta', 'restricted'].includes(probe.actor)
    || !['alpha', 'beta'].includes(probe.base) || !/^(GET|POST|PUT|PATCH|DELETE)$/u.test(probe.method || '')
    || typeof probe.path !== 'string' || !probe.path.startsWith('/')) throw new Error(`invalid ${kind} probe`);
  const expectedStatuses = probe.expect?.statuses;
  const expectedCodes = probe.expect?.codes;
  if ((!Array.isArray(expectedStatuses) || expectedStatuses.length === 0)
    && (!Array.isArray(expectedCodes) || expectedCodes.length === 0)) throw new Error(`${probe.name} has no explicit expected status/code`);
  const baseUrl = identity.tenants[probe.base].admin_url.replace(/\/$/u, '');
  const value = await request(sessions[probe.actor], baseUrl, probe.method, replace(probe.path, variables), {
    query: replace(probe.query || {}, variables), data: replace(probe.body, variables),
  });
  if (Array.isArray(expectedStatuses) && !expectedStatuses.includes(value.status)) throw new Error(`${probe.name} returned unexpected HTTP ${value.status}`);
  if (Array.isArray(expectedCodes) && !expectedCodes.includes(value.json?.code)) throw new Error(`${probe.name} returned unexpected code ${value.json?.code ?? 'none'}`);
  const denied = kind.endsWith('denials');
  if (denied === envelopeSuccess(value)) throw new Error(`${probe.name} ${denied ? 'unexpectedly succeeded' : 'did not succeed'}`);
  const serialized = JSON.stringify(value.json ?? value.text);
  for (const expected of probe.expect?.contains || []) {
    const rendered = replace(expected, variables);
    if (!serialized.includes(rendered)) throw new Error(`${probe.name} is missing required response marker`);
  }
  for (const forbidden of probe.expect?.absent || []) {
    const rendered = replace(forbidden, variables);
    if (serialized.includes(rendered)) throw new Error(`${probe.name} exposed a forbidden response marker`);
  }
  return { name: probe.name, status: value.status, code: value.json?.code ?? null };
};

try {
  const betaPage = mode === 'multi-tenant' ? await newPage() : null;
  const restrictedPage = await newPage();
  const platformPage = mode === 'multi-tenant' ? await newPage() : null;
  const tenantLogins = [
    loginTenant(page, tenantAdminUrl, alphaEmail, alphaPassword, 'tenant-alpha', identity.tenants.alpha.menu),
    loginTenant(restrictedPage, identity.tenants.restricted.admin_url.replace(/\/$/u, ''), restrictedEmail, restrictedPassword, 'tenant-restricted', identity.tenants.restricted.menu),
  ];
  if (betaPage) tenantLogins.push(loginTenant(betaPage, tenantBetaUrl, betaEmail, betaPassword, 'tenant-beta', identity.tenants.beta.menu));
  const loggedIn = await Promise.all(tenantLogins);
  const [alpha, restricted, beta = null] = loggedIn;
  const sessions = { alpha, restricted, ...(beta ? { beta } : {}) };
  if (alpha.token === restricted.token || (beta && (alpha.token === beta.token || beta.token === restricted.token))) {
    throw new Error('independent Tenant logins returned a shared access token');
  }
  results.tenant_sessions = { alpha: 'authenticated', restricted: 'authenticated', ...(beta ? { beta: 'authenticated' } : {}) };

  let platformToken = null;
  if (platformPage) {
    await platformPage.goto(`${platformUrl}/platform/`, { waitUntil: 'networkidle' });
    const platformInputs = platformPage.locator('input');
    await platformInputs.nth(0).fill(platformEmail);
    await platformInputs.nth(1).fill(platformPassword);
    await platformPage.getByRole('button', { name: /登录实例平台/i }).click();
    await platformPage.getByText('概览', { exact: true }).first().waitFor({ state: 'visible', timeout: 20000 });
    platformToken = await platformPage.evaluate(() => localStorage.getItem('peanut-platform-token'));
    if (!platformToken) throw new Error('Platform login did not produce an access token');
    const statusValue = await responseValue(await platformPage.request.get(`${platformUrl}/platformapi/v1/ops/status`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${platformToken}` }, failOnStatusCode: false,
    }));
    const statusData = assertSuccess(statusValue, 'package identity');
    const actualIdentity = statusData?.version;
    for (const key of ['commit', 'tree', 'release_key']) {
      if (actualIdentity?.[key] !== identity.package_identity[key]) throw new Error(`package identity mismatch: ${key}`);
    }
    results.package_identity = { source: 'platform-ops-http', ...actualIdentity };
    await platformPage.screenshot({ path: screenshotPath('platform'), fullPage: true });
  } else {
    const workbench = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/workbench/index'), 'standalone application version');
    const applicationVersion = workbench?.version?.version;
    if (applicationVersion !== identity.package_identity.application_version) {
      throw new Error(`standalone application version mismatch: ${applicationVersion ?? 'missing'}`);
    }
    const unavailableStatuses = identity.standalone_platform_unavailable_statuses;
    const [platformEntry, platformSession] = await Promise.all([
      responseValue(await alpha.page.request.get(`${platformUrl}/platform/`, { failOnStatusCode: false })),
      responseValue(await alpha.page.request.get(`${platformUrl}/platformapi/session/info`, { failOnStatusCode: false })),
    ]);
    if (!unavailableStatuses.includes(platformEntry.status) || !unavailableStatuses.includes(platformSession.status)) {
      throw new Error(`standalone exposed Platform entry/API: ${platformEntry.status}/${platformSession.status}`);
    }
    results.package_identity = {
      source: 'admin-workbench-http+external-host-runtime-receipt',
      application_version: applicationVersion,
      expected_commit: identity.package_identity.commit,
      expected_tree: identity.package_identity.tree,
      expected_release_key: identity.package_identity.release_key,
      host_runtime_receipt_sha256: identity.host_runtime_receipt_sha256,
      platform_unavailable: { entry_status: platformEntry.status, session_status: platformSession.status },
    };
  }

  if (beta) {
    const alphaOnBeta = await request(alpha, tenantBetaUrl, 'GET', '/adminapi/login/info');
    const betaOnAlpha = await request(beta, tenantAdminUrl, 'GET', '/adminapi/login/info');
    if (envelopeSuccess(alphaOnBeta) || envelopeSuccess(betaOnAlpha)) throw new Error('cross-Host Tenant token was accepted');
    results.host_overreach = [
      { direction: 'alpha-to-beta', status: alphaOnBeta.status, code: alphaOnBeta.json?.code ?? null },
      { direction: 'beta-to-alpha', status: betaOnAlpha.status, code: betaOnAlpha.json?.code ?? null },
    ];
  }

  const marker = `p0e-${Date.now().toString(36)}`;
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.article.category.add', {
    data: { name: marker, sort: 0, is_show: 1 },
  }), 'create soft-delete fixture');
  const active = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.article.category.list', {
    query: { name: marker, page_no: 1, page_size: 20 },
  }), 'find soft-delete fixture'), 'active category list');
  const category = active.find((item) => item.name === marker);
  if (!category || !Number.isInteger(category.id)) throw new Error('created soft-delete fixture is missing');
  const categoryId = category.id;
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.article.category.delete', { data: { id: categoryId } }), 'soft delete category');
  const afterDelete = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.article.category.list', {
    query: { name: marker, page_no: 1, page_size: 20 },
  }), 'list after soft delete'), 'active category list after delete');
  if (afterDelete.some((item) => item.id === categoryId)) throw new Error('soft-deleted category remained active');
  const recycled = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.article.category.recycle.list', {
    query: { name: marker, page_no: 1, page_size: 20 },
  }), 'list recycle bin'), 'category recycle list');
  if (!recycled.some((item) => item.id === categoryId)) throw new Error('soft-deleted category is absent from recycle list');
  if (beta) {
    const betaRecycle = listItems(assertSuccess(await request(beta, tenantBetaUrl, 'GET', '/adminapi/official.article.category.recycle.list', {
      query: { name: marker, page_no: 1, page_size: 20 },
    }), 'cross-Tenant recycle list'), 'cross-Tenant category recycle list');
    if (betaRecycle.some((item) => item.name === marker)) throw new Error('Tenant Beta observed Tenant Alpha recycled data');
    const betaDetail = assertSuccess(await request(beta, tenantBetaUrl, 'GET', '/adminapi/official.article.category.recycle.detail', {
      query: { id: categoryId },
    }), 'cross-Tenant recycle detail');
    if (betaDetail && (Array.isArray(betaDetail) ? betaDetail.length > 0 : Object.keys(betaDetail).length > 0)) {
      throw new Error('Tenant Beta observed Tenant Alpha recycled detail');
    }
  }
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.article.category.restore', { data: { ids: [categoryId] } }), 'restore category');
  const restored = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.article.category.list', {
    query: { name: marker, page_no: 1, page_size: 20 },
  }), 'list restored category'), 'restored category list');
  if (!restored.some((item) => item.id === categoryId)) throw new Error('restored category did not return to active list');
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.article.category.delete', { data: { id: categoryId } }), 'cleanup soft delete');
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.article.category.force-delete', { data: { ids: [categoryId] } }), 'cleanup force delete');
  results.lifecycle = { category_id: categoryId, default_hidden: true, recycle_visible: true, restored: true, cross_tenant_hidden: beta !== null, cleaned: true };

  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  const fileName = `${marker}.png`;
  const upload = assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.file.upload.image', {
    multipart: { cid: '0', file: { name: fileName, mimeType: 'image/png', buffer: png } },
  }), 'upload file');
  if (!Number.isInteger(upload?.id) || typeof upload?.file_key !== 'string' || upload.file_key === ''
    || typeof upload?.url !== 'string' || upload.url === '') throw new Error('file upload returned no stable identity');
  const deliveryUrl = new URL(upload.url, `${tenantAdminUrl}/`);
  if (!(deliveryUrl.hostname === '127.0.0.1' || deliveryUrl.hostname === '::1'
    || deliveryUrl.hostname === 'localhost' || deliveryUrl.hostname.endsWith('.localhost'))) {
    throw new Error('qualification file delivery left the registered local instance');
  }
  const delivered = await alpha.page.request.get(deliveryUrl.toString(), { failOnStatusCode: false });
  const deliveredBytes = (await delivered.body()).length;
  if (delivered.status() < 200 || delivered.status() >= 300 || deliveredBytes === 0) {
    throw new Error(`uploaded file delivery failed with HTTP ${delivered.status()}`);
  }
  const alphaFiles = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.file.list', {
    query: { type: 10, name: marker, page_no: 1, page_size: 20 },
  }), 'list uploaded file'), 'Alpha file list');
  if (!alphaFiles.some((item) => item.id === upload.id && item.file_key === upload.file_key)) throw new Error('uploaded file is absent from owner list');
  if (beta) {
    const betaFiles = listItems(assertSuccess(await request(beta, tenantBetaUrl, 'GET', '/adminapi/official.file.list', {
      query: { type: 10, name: marker, page_no: 1, page_size: 20 },
    }), 'cross-Tenant file list'), 'Beta file list');
    if (betaFiles.some((item) => item.name === fileName || item.file_key === upload.file_key)) throw new Error('Tenant Beta observed Tenant Alpha file');
  }
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/official.file.delete', { data: { ids: [upload.id] } }), 'delete uploaded file');
  const afterDeliveryDelete = await alpha.page.request.get(deliveryUrl.toString(), { failOnStatusCode: false });
  if (afterDeliveryDelete.status() >= 200 && afterDeliveryDelete.status() < 300) throw new Error('deleted file remained available from its delivery URL');
  const afterFileDelete = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/official.file.list', {
    query: { type: 10, name: marker, page_no: 1, page_size: 20 },
  }), 'list after file deletion'), 'file list after deletion');
  if (afterFileDelete.some((item) => item.id === upload.id)) throw new Error('deleted file remained visible');
  results.file = { file_id: upload.id, delivered_bytes: deliveredBytes, tenant_isolated: beta !== null, deleted: true, delivery_revoked: true };

  const variables = { run_marker: marker, alpha_category_id: categoryId, alpha_file_id: upload.id, alpha_file_key: upload.file_key };
  for (const kind of ['rbac_denials', 'scope_allowed', 'scope_denials']) {
    results.probes[kind] = [];
    for (const probe of identity.probes[kind]) results.probes[kind].push(await runProbe(probe, kind, sessions, variables));
  }

  const assertSsr = async (targetPage, baseUrl, expected, other, label) => {
    const value = await responseValue(await targetPage.request.get(new URL(identity.ssr.path, `${baseUrl}/`).toString(), { failOnStatusCode: false }));
    if (value.status < 200 || value.status >= 400 || !/text\/html/i.test(value.headers['content-type'] || '')
      || !value.text.trimStart().startsWith('<')) {
      throw new Error(`${label} SSR request failed with HTTP ${value.status}`);
    }
    for (const text of expected.required) if (!value.text.includes(text)) throw new Error(`${label} SSR response lacks its Tenant marker`);
    for (const text of [...expected.forbidden, ...(other?.required || [])]) if (value.text.includes(text)) throw new Error(`${label} SSR response contains another Tenant marker`);
    return value.text.length;
  };
  const rounds = Number.isInteger(identity.ssr.parallel_rounds) ? identity.ssr.parallel_rounds : 3;
  if (rounds < 2 || rounds > 10) throw new Error('SSR parallel_rounds must be between 2 and 10');
  const ssrLengths = [];
  for (let round = 0; round < rounds; round += 1) {
    ssrLengths.push(await Promise.all(beta ? [
      assertSsr(alpha.page, tenantAdminUrl, identity.ssr.alpha, identity.ssr.beta, `Alpha round ${round + 1}`),
      assertSsr(beta.page, tenantBetaUrl, identity.ssr.beta, identity.ssr.alpha, `Beta round ${round + 1}`),
    ] : [
      assertSsr(alpha.page, tenantAdminUrl, identity.ssr.alpha, null, `Standalone A round ${round + 1}`),
      assertSsr(restricted.page, tenantAdminUrl, identity.ssr.alpha, null, `Standalone B round ${round + 1}`),
    ]));
  }
  results.ssr = { path: identity.ssr.path, parallel_rounds: rounds, response_lengths: ssrLengths };

  const oldAlphaToken = alpha.token;
  await alpha.page.locator('.user-menu-trigger').click();
  await alpha.page.getByText(/退出登录|logout/i, { exact: true }).click();
  await alpha.page.waitForURL((value) => value.pathname.endsWith('/login'), { timeout: 20000 });
  const revoked = await request({ page: alpha.page, token: oldAlphaToken }, tenantAdminUrl, 'GET', '/adminapi/login/info');
  if (envelopeSuccess(revoked)) throw new Error('Tenant logout did not revoke the previous access token');
  results.logout = { tenant: { status: revoked.status, code: revoked.json?.code ?? null, revoked: true } };
  if (platformPage && platformToken) {
    const oldPlatformToken = platformToken;
    await platformPage.getByRole('button', { name: '退出登录', exact: true }).click();
    await platformPage.getByRole('button', { name: /登录实例平台/i }).waitFor({ state: 'visible', timeout: 20000 });
    const revokedPlatform = await responseValue(await platformPage.request.get(`${platformUrl}/platformapi/session/info`, {
      headers: { Authorization: `Bearer ${oldPlatformToken}` }, failOnStatusCode: false,
    }));
    if (envelopeSuccess(revokedPlatform)) throw new Error('Platform logout did not revoke the previous access token');
    results.logout.platform = { status: revokedPlatform.status, code: revokedPlatform.json?.code ?? null, revoked: true };
  }

  results.h5 = await assertPage(beta?.page || restricted.page, `${beta ? tenantBetaUrl : tenantAdminUrl}/mobile/`, 'h5');
  if (docsUrl !== '') results.docs = await assertPage(beta?.page || restricted.page, `${docsUrl}/`, 'docs');
  console.log(JSON.stringify({ schema_version: 2, mode, profile, status: 'passed', results }));
} finally {
  await Promise.all(contexts.map((context) => context.close().catch(() => undefined)));
}
