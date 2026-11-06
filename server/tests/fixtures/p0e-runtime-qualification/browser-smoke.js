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
// run-code exposes page, not Node host globals. Resolve URLs in the browser realm.
const browserUrl = async (targetPage, value, base) => targetPage.evaluate(({ value, base }) => {
  const url = base === undefined ? new URL(value) : new URL(value, base);
  return { href: url.href, origin: url.origin, protocol: url.protocol, hostname: url.hostname, port: url.port,
    username: url.username, password: url.password, pathname: url.pathname };
}, { value, base });

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

const checkPublicDocuments = async (targetPage) => {
  const contract = JSON.parse(required('P0E_BROWSER_DOCUMENTATION_CONTRACT'));
  if (contract.delivery !== 'versioned-markdown-and-openapi'
    || !contract.browser_documents || Object.keys(contract.browser_documents).length !== 5) throw new Error('invalid released documentation contract');
  const index = await assertPage(targetPage, `${docsUrl}/`, 'docs');
  await targetPage.getByRole('link', { name: 'README.md', exact: true }).waitFor({ state: 'visible' });
  const documents = [];
  for (const [path, expected] of Object.entries(contract.browser_documents)) {
    if (!/^(?:README\.md|public\/(?:installation|api-and-sdk|release-channels)\.md|development\/standard\.md)$/u.test(path)
      || !/^[0-9a-f]{64}$/u.test(expected)) throw new Error('invalid public document identity');
    const response = await targetPage.request.get(`${docsUrl}/${path}`, { maxRedirects: 0 });
    if (response.status() !== 200 || response.url() !== `${docsUrl}/${path}`) throw new Error(`public document unavailable: ${path}`);
    const text = await response.text();
    const actual = await targetPage.evaluate(async (value) => Array.from(new Uint8Array(
      await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)),
    )).map((byte) => byte.toString(16).padStart(2, '0')).join(''), text);
    if (actual !== expected || !text.startsWith('# ')) throw new Error(`released document bytes differ: ${path}`);
    documents.push({ path, sha256: actual });
  }
  return { ...index, delivery: contract.delivery, released_bytes_verified: true, documents,
    api_contract: { version: contract.api_version, openapi_sha256: contract.files['server/generated/openapi.json'],
      catalog_sha256: contract.files['server/generated/api-catalog.json'] } };
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
  const url = await browserUrl(session.page, path, `${baseUrl}/`);
  const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}`, ...(options.headers || {}) };
  const requestOptions = { method, headers, failOnStatusCode: false };
  if (options.query !== undefined) requestOptions.params = Object.fromEntries(
    Object.entries(options.query).map(([key, value]) => [key, String(value)]),
  );
  if (options.data !== undefined) requestOptions.data = options.data;
  if (options.multipart !== undefined) requestOptions.multipart = options.multipart;
  return responseValue(await session.page.request.fetch(url.href, requestOptions));
};
const listItems = (data, label) => {
  const items = data?.lists ?? data?.items;
  if (!Array.isArray(items)) throw new Error(`${label} returned no list`);
  return items;
};

const checkRoleRevocation = async (owner, restricted, origin, roleId, marker) => {
  const endpoint = '/adminapi/peanut.file.category.add';
  const permission = 'peanut.file.category.add';
  const original = assertSuccess(await request(owner, origin, 'GET', '/adminapi/role/permissions', {
    query: { role_id: roleId },
  }), 'read revocation fixture role');
  const replacePermissions = async (keys) => {
    const current = assertSuccess(await request(owner, origin, 'GET', '/adminapi/role/permissions', {
      query: { role_id: roleId },
    }), 'read role permission revision');
    return assertSuccess(await request(owner, origin, 'PUT', '/adminapi/role/permissions', {
      data: { role_id: roleId, expected_revision: current.revision, permission_keys: keys },
    }), 'replace revocation fixture permissions');
  };
  const categories = async () => assertSuccess(await request(owner, origin, 'GET', '/adminapi/peanut.file.category.list', {
    query: { type: 10 },
  }), 'read revocation fixture categories');
  const audit = async () => assertSuccess(await request(owner, origin, 'GET', '/adminapi/log/lists', {
    query: { uri: 'peanut.file.category.add', method: 'POST', page_no: 1, page_size: 100 },
  }), 'read category write audit');
  const name = `${marker.slice(-16)}-r`;
  let categoryId = null;
  try {
    await replacePermissions([...new Set([...original.permission_keys, permission])]);
    assertSuccess(await request(restricted, origin, 'POST', endpoint, {
      data: { name, type: 10, pid: 0 },
    }), 'allow category write before role revocation');
    const tree = await categories();
    const flatten = (items) => items.flatMap((item) => [item, ...flatten(item.children || [])]);
    const created = flatten(tree).filter((item) => item.name === name);
    if (created.length !== 1 || !Number.isInteger(created[0].id)) throw new Error('allowed category write was not retained');
    categoryId = created[0].id;
    await replacePermissions(original.permission_keys.filter((key) => key !== permission));
    const before = { categories: await categories(), audit: await audit() };
    const denied = await request(restricted, origin, 'POST', endpoint, {
      data: { name: `${name}-x`, type: 10, pid: 0 },
    });
    if (denied.status !== 403 || envelopeSuccess(denied)) throw new Error('existing token survived role permission revocation');
    const after = { categories: await categories(), audit: await audit() };
    if (JSON.stringify(before) !== JSON.stringify(after)) throw new Error('denied revoked write changed categories or write audit');
    return { allowed_before_revocation: true, old_token_denied: true, status: denied.status,
      business_state_unchanged: true, write_audit_unchanged: true };
  } finally {
    await replacePermissions(original.permission_keys);
    if (categoryId !== null) assertSuccess(await request(owner, origin, 'POST', '/adminapi/peanut.file.category.delete', {
      data: { id: categoryId },
    }), 'clean revocation fixture category');
  }
};

const checkCategoryAndFile = async (alpha, beta, betaUrl, marker, results) => {
  // Article recycle/restore belongs to the optional Article application. This
  // selected-module browser path exercises File's actual tenant-owned category.
  const categoryName = marker.slice(-18);
  const categoryList = async (session, origin) => {
    const tree = assertSuccess(await request(session, origin, 'GET', '/adminapi/peanut.file.category.list', {
      query: { type: 10 },
    }), 'read File categories');
    if (!Array.isArray(tree)) throw new Error('File category tree is missing');
    const flatten = (items) => items.flatMap((item) => [item, ...flatten(item.children || [])]);
    return flatten(tree);
  };
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/peanut.file.category.add', {
    data: { name: categoryName, type: 10, pid: 0 },
  }), 'create File category');
  const category = (await categoryList(alpha, tenantAdminUrl)).find((item) => item.name === categoryName);
  if (!category || !Number.isInteger(category.id)) throw new Error('created File category is missing');
  const categoryId = category.id;
  const updatedName = `${categoryName}-u`;
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/peanut.file.category.edit', {
    data: { id: categoryId, name: updatedName },
  }), 'update File category');
  const beforeForeignEdit = await categoryList(alpha, tenantAdminUrl);
  if (beforeForeignEdit.find((item) => item.id === categoryId)?.name !== updatedName) throw new Error('File category update was not retained');
  if (beta) {
    if ((await categoryList(beta, betaUrl)).some((item) => item.id === categoryId)) throw new Error('Tenant Beta observed the owner File category');
    const foreignEdit = await request(beta, betaUrl, 'POST', '/adminapi/peanut.file.category.edit', {
      data: { id: categoryId, name: 'foreign' },
    });
    if (envelopeSuccess(foreignEdit) || ![400, 403, 404].includes(foreignEdit.status)) {
      throw new Error('foreign Tenant File category edit was not rejected');
    }
    if (JSON.stringify(await categoryList(alpha, tenantAdminUrl)) !== JSON.stringify(beforeForeignEdit)) throw new Error('foreign Tenant edit changed owner File categories');
  }
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/peanut.file.category.delete', { data: { id: categoryId } }), 'delete File category');
  if ((await categoryList(alpha, tenantAdminUrl)).some((item) => item.id === categoryId)) throw new Error('deleted File category remained visible');
  results.lifecycle = { owner_module: 'peanut.file', category_id: categoryId, updated: true,
    cross_tenant_hidden: beta !== null, foreign_write_unchanged: beta !== null, cleaned: true };

  const png = await alpha.page.screenshot({ type: 'png' });
  const fileName = `${marker}.png`;
  const upload = assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/peanut.file.upload.image', {
    multipart: { cid: '0', file: { name: fileName, mimeType: 'image/png', buffer: png } },
  }), 'upload file');
  if (!Number.isInteger(upload?.id) || typeof upload?.file_key !== 'string' || upload.file_key === ''
    || typeof upload?.url !== 'string' || upload.url === '') throw new Error('file upload returned no stable identity');
  const deliveryUrl = await browserUrl(alpha.page, upload.url, `${tenantAdminUrl}/`);
  if (!(deliveryUrl.hostname === '127.0.0.1' || deliveryUrl.hostname === '::1'
    || deliveryUrl.hostname === 'localhost' || deliveryUrl.hostname.endsWith('.localhost'))) {
    throw new Error('qualification file delivery left the registered local instance');
  }
  if (profile === 'baseline') {
    const allowedHosts = new Set(['127.0.0.1', 'localhost',
      ...(await Promise.all([tenantAdminUrl, tenantBetaUrl, platformUrl]
        .map((origin) => browserUrl(alpha.page, origin)))).map((origin) => origin.hostname)]);
    if (deliveryUrl.protocol !== 'http:' || deliveryUrl.port !== (await browserUrl(alpha.page, tenantAdminUrl)).port
      || !allowedHosts.has(deliveryUrl.hostname) || deliveryUrl.username || deliveryUrl.password) {
      throw new Error('baseline file delivery left the lease-bound HTTP endpoint');
    }
  }
  const delivered = await alpha.page.request.get(deliveryUrl.href, { failOnStatusCode: false });
  const downloaded = await delivered.body();
  const deliveredBytes = downloaded.length;
  if (delivered.status() < 200 || delivered.status() >= 300 || deliveredBytes === 0) {
    throw new Error(`uploaded file delivery failed with HTTP ${delivered.status()}`);
  }
  if (!downloaded.equals(png)) throw new Error('downloaded file bytes differ from the uploaded file');
  const alphaFiles = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/peanut.file.list', {
    query: { type: 10, name: marker, page_no: 1, page_size: 20 },
  }), 'list uploaded file'), 'Alpha file list');
  if (!alphaFiles.some((item) => item.id === upload.id && item.file_key === upload.file_key)) throw new Error('uploaded file is absent from owner list');
  if (beta) {
    const betaFiles = listItems(assertSuccess(await request(beta, betaUrl, 'GET', '/adminapi/peanut.file.list', {
      query: { type: 10, name: marker, page_no: 1, page_size: 20 },
    }), 'cross-Tenant file list'), 'Beta file list');
    if (betaFiles.some((item) => item.name === fileName || item.file_key === upload.file_key)) throw new Error('Tenant Beta observed Tenant Alpha file');
  }
  const visibility = assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/api/v1/files/visibility/preview', {
    data: { file_keys: [upload.file_key] },
  }), 'preview fixture private visibility');
  const restrictedFile = assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/api/v1/files/visibility/batches', {
    headers: { 'Idempotency-Key': `p0e-private-${marker}` },
    data: { confirmation: visibility.confirmation, signature: visibility.signature, impact_confirmed: true },
  }), 'make only the uploaded fixture private');
  if (restrictedFile.status !== 'completed') throw new Error('fixture visibility conversion did not complete');
  const afterPrivate = await alpha.page.request.get(deliveryUrl.href, { failOnStatusCode: false });
  if (afterPrivate.status() >= 200 && afterPrivate.status() < 300) throw new Error('old public delivery URL survived private conversion');
  const privateAccess = assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/api/v1/files/resolve', {
    data: { file_key: upload.file_key, operation: 'download_original' },
  }), 'resolve owner private download');
  const privateUrl = await browserUrl(alpha.page, privateAccess.url, `${tenantAdminUrl}/`);
  if (privateUrl.origin !== (await browserUrl(alpha.page, tenantAdminUrl)).origin) throw new Error('private fixture delivery left its registered origin');
  const privateDelivered = await alpha.page.request.get(privateUrl.href, { failOnStatusCode: false });
  if (privateDelivered.status() !== 200 || !(await privateDelivered.body()).equals(png)) throw new Error('owner private download did not preserve exact bytes');
  if (beta) {
    const deniedPrivate = await request(beta, betaUrl, 'POST', '/adminapi/api/v1/files/resolve', {
      data: { file_key: upload.file_key, operation: 'download_original' },
    });
    if (envelopeSuccess(deniedPrivate) || ![403, 404].includes(deniedPrivate.status)) throw new Error('foreign Tenant resolved a private owner file');
  }
  assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/peanut.file.delete', { data: { ids: [upload.id] } }), 'delete uploaded file');
  const afterDeliveryDelete = await alpha.page.request.get(deliveryUrl.href, { failOnStatusCode: false });
  if (afterDeliveryDelete.status() >= 200 && afterDeliveryDelete.status() < 300) throw new Error('deleted file remained available from its delivery URL');
  const afterPrivateDelete = await alpha.page.request.get(privateUrl.href, { failOnStatusCode: false });
  if (afterPrivateDelete.status() >= 200 && afterPrivateDelete.status() < 300) throw new Error('deleted file retained its private delivery grant');
  const afterFileDelete = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/peanut.file.list', {
    query: { type: 10, name: marker, page_no: 1, page_size: 20 },
  }), 'list after file deletion'), 'file list after deletion');
  if (afterFileDelete.some((item) => item.id === upload.id)) throw new Error('deleted file remained visible');
  results.file = { file_id: upload.id, delivered_bytes: deliveredBytes, tenant_isolated: beta !== null,
    private_conversion_completed: true, old_public_url_revoked: true, owner_private_download_exact: true,
    deleted: true, delivery_revoked: true, private_grant_revoked: true, download_exact: true };

  return { categoryId, upload };
};

if (profile === 'baseline') {
  const adminEmail = required('P0E_ADMIN_INITIAL_EMAIL');
  const adminPassword = required('P0E_ADMIN_INITIAL_PASSWORD');
  const platformEmail = required('P0E_PLATFORM_INITIAL_EMAIL');
  const platformPassword = required('P0E_PLATFORM_INITIAL_PASSWORD');
  const runId = required('P0E_BROWSER_RUN_ID');
  if (!/^[a-z0-9]{1,11}$/u.test(runId)) throw new Error('invalid native qualification run');
  const marker = `p0e-${runId}-${Date.now().toString(36)}`;
  const results = { profile: 'baseline', scope: 'live-synthetic-business-before-cleanup', run_id: runId };
  const alpha = await loginTenant(page, tenantAdminUrl, adminEmail, adminPassword, 'admin');
  /* Retain the authenticated browser session; all business mutations below use
     the real application routes in the native lease-owned qualification DB. */
  results.admin = { url: page.url(), title: await page.title() };
  const browser = page.context().browser();
  if (!browser) throw new Error('baseline business qualification requires a browser');
  const businessContexts = [];
  const isolatedPage = async () => {
    const context = await browser.newContext();
    businessContexts.push(context);
    return context.newPage();
  };
  let beta = null;
  let platform = null;
  try {
    if (mode === 'multi-tenant') {
      const platformPage = await isolatedPage();
      await platformPage.goto(`${platformUrl}/platform/`, { waitUntil: 'networkidle' });
      await platformPage.locator('input').nth(0).fill(platformEmail);
      await platformPage.locator('input').nth(1).fill(platformPassword);
      await platformPage.getByRole('button', { name: /登录实例平台/i }).click();
      await platformPage.getByText('概览', { exact: true }).first().waitFor({ state: 'visible', timeout: 20000 });
      platform = { page: platformPage, token: await platformPage.evaluate(() => localStorage.getItem('peanut-platform-token')) };
      if (!platform.token) throw new Error('platform login produced no token');
      const betaEmail = required('P0E_TENANT_BETA_EMAIL');
      const betaPassword = required('P0E_TENANT_BETA_PASSWORD');
      const issued = assertSuccess(await request(platform, platformUrl, 'POST', '/platformapi/tenants/provision', {
        data: { tenant_code: marker, tenant_name: marker, owner_email: betaEmail, owner_display_name: 'P0E Beta' },
      }), 'provision qualification Tenant Beta');
      if (!Number.isInteger(issued?.tenant_id) || !/^[A-Za-z0-9_-]{43}$/u.test(issued?.accept_token || '')) {
        throw new Error('manual invitation returned no native tenant/token');
      }
      const accepted = assertSuccess(await request({ page: platformPage, token: '' }, platformUrl, 'POST', '/adminapi/tenant/owner-invitations/accept', {
        data: { token: issued.accept_token, new_account_password: betaPassword },
      }), 'accept qualification owner invitation');
      if (accepted?.tenant_id !== issued.tenant_id || accepted?.status !== 'accepted') throw new Error('owner invitation did not establish Beta');
      const tenant = assertSuccess(await request(platform, platformUrl, 'GET', '/platformapi/tenants/detail', {
        query: { id: issued.tenant_id },
      }), 'read qualification Tenant Beta revision');
      assertSuccess(await request(platform, platformUrl, 'POST', '/platformapi/tenants/activate', {
        data: { tenant_id: issued.tenant_id, expected_revision: tenant.revision, change_reason: marker },
      }), 'activate qualification Tenant Beta');
      // A tenant consumes a current plan/license, never the retired Module switch.
      const alphaLicense = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/core.tenant.license'), 'read Alpha license').license;
      const betaLicense = assertSuccess(await request(platform, platformUrl, 'GET', '/platformapi/tenants/license', {
        query: { tenant_id: issued.tenant_id },
      }), 'read qualification Beta license').license;
      if (!Number.isInteger(alphaLicense?.plan_id)) throw new Error('Alpha license has no current plan');
      assertSuccess(await request(platform, platformUrl, 'PUT', '/platformapi/tenants/license', {
        data: { tenant_id: issued.tenant_id, plan_id: alphaLicense.plan_id,
          expected_revision: betaLicense?.revision ?? 0, status: 'active',
          effective_at: new Date(Date.now() - 60000).toISOString(), expires_at: null, change_reason: marker },
      }), 'assign qualification Beta plan/license');
      for (const clientKey of ['admin-web', 'member-api']) {
        assertSuccess(await request(platform, platformUrl, 'POST', '/platformapi/tenant-entry-bindings/enable', {
          data: { tenant_id: issued.tenant_id, host: (await browserUrl(platformPage, tenantBetaUrl)).hostname, client_key: clientKey, change_reason: marker },
        }), `bind qualification Beta ${clientKey}`);
      }
      beta = await loginTenant(await isolatedPage(), tenantBetaUrl, betaEmail, betaPassword, 'tenant-beta');
      if (beta.token === alpha.token) throw new Error('two Tenant logins shared a token');
      for (const [session, origin] of [[alpha, tenantBetaUrl], [beta, tenantAdminUrl]]) {
        const value = await request(session, origin, 'GET', '/adminapi/login/info');
        if (envelopeSuccess(value) || ![401, 403].includes(value.status)) throw new Error('cross-Host Tenant token was not rejected by authentication');
      }
      const platformOverreach = await request(alpha, platformUrl, 'GET', '/platformapi/session/info');
      if (envelopeSuccess(platformOverreach) || ![401, 403].includes(platformOverreach.status)) throw new Error('Tenant token accepted by Platform');
      results.tenant_boundary = { independent_logins: true, cross_host_rejected: true, tenant_on_platform_rejected: true };
      await platformPage.screenshot({ path: screenshotPath('platform'), fullPage: true });
      results.platform = { url: platformPage.url() };
    }

    const permissionCatalog = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/role/permissions/catalog', {
      query: { search: 'workbench/index', page_no: 1, page_size: 20 },
    }), 'read assignable permission bundles'), 'assignable permission bundles');
    if (!permissionCatalog.some((permission) => permission.key === 'workbench/index')) throw new Error('workbench permission is not assignable');
    const restrictedPermissions = ['workbench/index'];
    const restrictedName = `p0e-${runId}-restricted`;
    let roles = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/role/all'), 'read restricted role');
    if (!roles.some((item) => item.name === restrictedName)) {
      assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/role/add', {
        data: { name: restrictedName, desc: 'P0E restricted business qualification', sort: 0 },
      }), 'create restricted role');
      roles = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/role/all'), 'read created restricted role');
    }
    const matchingRoles = roles.filter((item) => item.name === restrictedName);
    if (matchingRoles.length !== 1) throw new Error('restricted role identity is ambiguous');
    const role = matchingRoles[0];
    if (!Number.isInteger(role?.id)) throw new Error('restricted role was not retained');
    const rolePermissions = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/role/permissions', {
      query: { role_id: role.id },
    }), 'read restricted permission revision');
    const assignedPermissions = assertSuccess(await request(alpha, tenantAdminUrl, 'PUT', '/adminapi/role/permissions', {
      data: { role_id: role.id, expected_revision: rolePermissions.revision, permission_keys: restrictedPermissions },
    }), 'assign restricted permission bundles');
    const roleDetail = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/role/detail', { query: { id: role.id } }), 'read assigned role permissions');
    if (JSON.stringify(roleDetail.permission_keys) !== JSON.stringify(restrictedPermissions)
      || assignedPermissions.revision !== rolePermissions.revision + 1
      || roleDetail.desc !== 'P0E restricted business qualification' || roleDetail.status !== 'active') throw new Error('restricted role identity or permission-bundle assignment changed');
    const restrictedEmail = required('P0E_TENANT_RESTRICTED_EMAIL');
    const restrictedPassword = required('P0E_TENANT_RESTRICTED_PASSWORD');
    let administrators = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/admin/lists', {
      query: { account: restrictedEmail, page_no: 1, page_size: 20 },
    }), 'read restricted administrator'), 'restricted administrators');
    if (!administrators.some((item) => item.account === restrictedEmail)) {
      assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/admin/add', {
        data: { account: restrictedEmail, name: restrictedName, password: restrictedPassword, password_confirm: restrictedPassword,
          dept_id: [], jobs_id: [], role_id: [role.id], disable: 0, multipoint_login: 1 },
      }), 'create restricted administrator');
      administrators = listItems(assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/admin/lists', {
        query: { account: restrictedEmail, page_no: 1, page_size: 20 },
      }), 'read created restricted administrator'), 'restricted administrators');
    }
    const matchingAdministrators = administrators.filter((item) => item.account === restrictedEmail);
    if (matchingAdministrators.length !== 1) throw new Error('restricted administrator identity is ambiguous');
    const restrictedAdministrator = matchingAdministrators[0];
    if (restrictedAdministrator.name !== restrictedName || restrictedAdministrator.root !== 0
      || restrictedAdministrator.disable !== 0 || restrictedAdministrator.role_id.length !== 1
      || restrictedAdministrator.role_id[0] !== role.id) throw new Error('restricted administrator identity or grants changed');
    const restricted = await loginTenant(await isolatedPage(), tenantAdminUrl, restrictedEmail, restrictedPassword, 'restricted');
    if (restricted.token === alpha.token) throw new Error('restricted login shared the owner token');
    const restrictedRoutes = assertSuccess(await request(restricted, tenantAdminUrl, 'GET', '/adminapi/menu/route'), 'restricted navigation');
    for (const forbidden of ['/system/role', '/app-setting/website', '/system/file']) {
      if (JSON.stringify(restrictedRoutes).includes(forbidden)) throw new Error('restricted menu exposed privileged navigation');
    }
    results.permissions = { stable_permission_assignment: true, restricted_navigation_hidden: true, denied: [] };
    for (const [method, path, data] of [
      ['GET', '/adminapi/role/lists'], ['GET', '/adminapi/config/website'],
      ['POST', '/adminapi/config/website/save', { name: marker }],
      ['POST', '/adminapi/peanut.file.category.add', { name: marker.slice(0, 20), type: 10, pid: 0 }],
    ]) {
      const value = await request(restricted, tenantAdminUrl, method, path, data ? { data } : {});
      if (envelopeSuccess(value) || !(value.status === 403 || value.json?.code === 40300)) throw new Error(`restricted permission was not denied: ${path}`);
      results.permissions.denied.push(path);
    }
    const deniedUpload = await request(restricted, tenantAdminUrl, 'POST', '/adminapi/peanut.file.upload.image', {
      multipart: { cid: '0', file: { name: `${marker}.png`, mimeType: 'image/png', buffer: await restricted.page.screenshot({ type: 'png' }) } },
    });
    if (envelopeSuccess(deniedUpload) || !(deniedUpload.status === 403 || deniedUpload.json?.code === 40300)) throw new Error('restricted file upload was not denied');
    results.permissions.denied.push('/adminapi/peanut.file.upload.image');
    results.permissions.revocation = await checkRoleRevocation(alpha, restricted, tenantAdminUrl, role.id, marker);

    results.navigation = [];
    for (const [route, api] of [['/system/role', '/adminapi/role/lists'], ['/system/file', '/adminapi/peanut.file.category.list'], ['/app-setting/website', '/adminapi/config/website']]) {
      const apiUrl = (await browserUrl(page, api, `${tenantAdminUrl}/`)).href;
      // Retain responses during bounded navigation; the response wait starts after
      // page bootstrap and still validates the actual page's exact GET endpoint.
      const matches = (response) => (response.url() === apiUrl
        || response.url().startsWith(`${apiUrl}?`)) && response.request().method() === 'GET';
      let loaded = null;
      const retain = (response) => { if (matches(response)) loaded = response; };
      page.on('response', retain);
      try {
        await page.goto(`${tenantAdminUrl}/admin${route}`, { waitUntil: 'networkidle' });
        assertSuccess(await responseValue(loaded || await page.waitForResponse(matches)), `navigate ${route}`);
        if ((await browserUrl(page, page.url())).pathname !== `/admin${route}`) throw new Error(`navigation left ${route}`);
        results.navigation.push(route);
      } finally {
        page.off('response', retain);
      }
    }
    const before = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/config/website'), 'read original website');
    const betaBefore = beta ? assertSuccess(await request(beta, tenantBetaUrl, 'GET', '/adminapi/config/website'), 'read Beta website') : null;
    try {
      // The actual browser form owns the save; API read-back proves persistence.
      await page.locator('.el-form-item').filter({ hasText: '网站名称' }).locator('input').fill(marker);
      const saveUrl = (await browserUrl(page, '/adminapi/config/website/save', `${tenantAdminUrl}/`)).href;
      const saved = page.waitForResponse((response) => (response.url() === saveUrl
        || response.url().startsWith(`${saveUrl}?`)) && response.request().method() === 'POST');
      await page.getByRole('button', { name: '保存', exact: true }).click();
      assertSuccess(await responseValue(await saved), 'save website through browser form');
      const after = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/config/website'), 'read saved website');
      if (after.name !== marker) throw new Error('website browser save was not retained');
      if (beta) {
        const betaAfter = assertSuccess(await request(beta, tenantBetaUrl, 'GET', '/adminapi/config/website'), 'read isolated Beta website');
        if (JSON.stringify(betaAfter) !== JSON.stringify(betaBefore)) throw new Error('Alpha website mutation changed Beta settings');
      }
      const publicConfig = assertSuccess(await responseValue(await page.request.get(`${tenantAdminUrl}/api/index/config`)), 'public website config');
      if (publicConfig?.website?.name !== marker) throw new Error('public website config did not retain the saved brand');
      results.pc = await assertPage(page, `${tenantAdminUrl}/pc/`, 'pc');
      results.h5 = await assertPage(page, `${tenantAdminUrl}/mobile/`, 'h5');
    } finally {
      assertSuccess(await request(alpha, tenantAdminUrl, 'POST', '/adminapi/config/website/save', { data: before }), 'restore website settings');
      const restored = assertSuccess(await request(alpha, tenantAdminUrl, 'GET', '/adminapi/config/website'), 'verify restored website');
      if (JSON.stringify(restored) !== JSON.stringify(before)) throw new Error('original website settings were not preserved');
    }
    results.website = { browser_saved: true, read_back: true, public_read: true, original_restored: true, beta_unchanged: beta !== null };
    await checkCategoryAndFile(alpha, beta, tenantBetaUrl, marker, results);
    results.docs = await checkPublicDocuments(page);
    return { schema_version: 1, mode, profile, status: 'passed', results };
  } finally {
    await Promise.all(businessContexts.map((context) => context.close().catch(() => undefined)));
  }
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
  const restrictedOrigin = identity.tenants.restricted.admin_url.replace(/\/$/u, '');
  const restrictedOwner = restrictedOrigin === tenantAdminUrl ? alpha : beta;
  if (!restrictedOwner) throw new Error('restricted account has no registered owner session');
  const restrictedMembers = listItems(assertSuccess(await request(restrictedOwner, restrictedOrigin, 'GET', '/adminapi/admin/lists', {
    query: { account: restrictedEmail, page_no: 1, page_size: 20 },
  }), 'read release restricted administrator'), 'release restricted administrators')
    .filter((member) => member.account === restrictedEmail);
  if (restrictedMembers.length !== 1 || restrictedMembers[0].root !== 0
    || restrictedMembers[0].role_id?.length !== 1 || !Number.isInteger(restrictedMembers[0].role_id[0])) {
    throw new Error('revocation requires one dedicated non-owner restricted role');
  }
  results.role_revocation = await checkRoleRevocation(restrictedOwner, restricted, restrictedOrigin,
    restrictedMembers[0].role_id[0], marker);
  const { categoryId, upload } = await checkCategoryAndFile(alpha, beta, tenantBetaUrl, marker, results);

  const variables = { run_marker: marker, alpha_category_id: categoryId, alpha_file_id: upload.id, alpha_file_key: upload.file_key };
  for (const kind of ['rbac_denials', 'scope_allowed', 'scope_denials']) {
    results.probes[kind] = [];
    for (const probe of identity.probes[kind]) results.probes[kind].push(await runProbe(probe, kind, sessions, variables));
  }

  const assertSsr = async (targetPage, baseUrl, expected, other, label) => {
    const value = await responseValue(await targetPage.request.get((await browserUrl(targetPage, identity.ssr.path, `${baseUrl}/`)).href, { failOnStatusCode: false }));
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
  return { schema_version: 2, mode, profile, status: 'passed', results };
} finally {
  await Promise.all(contexts.map((context) => context.close().catch(() => undefined)));
}
