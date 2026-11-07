// Loaded from the fixed qualification tool bundle in the IAB-owning CUA session.
// IAB does not expose independent BrowserContexts; same-origin users log in serially.
async ({ cua, collectorTab, bundle, controller }) => {
  const relay = await collectorTab.capabilities.get('cdp');
  const runtime = async (cdp, expression) => {
    const result = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error('IAB runtime action failed');
    return result.result.value;
  };
  const exchange = (method, value) => runtime(relay, `location.origin === ${JSON.stringify(bundle.collector)} && fetch('/step', {
    method: ${JSON.stringify(method)},
    headers: {Authorization: ${JSON.stringify(`Bearer ${bundle.key}`)}, 'Content-Type': 'application/json'},
    body: ${JSON.stringify(value === undefined ? undefined : JSON.stringify(value))},
    cache: 'no-store', redirect: 'error'
  }).then(response => { if (!response.ok) throw Error('qualification exchange failed'); return response.json(); })`).then((value) => {
    if (value === false) throw new Error('IAB collector tab left its registered origin');
    return value;
  });
  const tabs = new Set();
  const sessions = new Map();
  let owner;
  let lastId = 0;
  const wait = { state: 'visible', timeoutMs: 20000 };
  const networkIdle = (tab) => tab.playwright.waitForLoadState({ state: 'networkidle', timeoutMs: 20000 });
  const current = (cdp) => runtime(cdp, '({ url: location.href, title: document.title })');
  const screenshot = async (cdp) => (await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data;
  const newTab = async (url) => {
    const tab = await cua.createBrowserTab('iab', 'about:blank', { visible: false });
    tabs.add(tab);
    await tab.goto(url);
    return { tab, cdp: await tab.capabilities.get('cdp') };
  };

  const sessionState = ({ cdp, tokenKey, generationKey }) => runtime(cdp, `[
    localStorage.getItem(${JSON.stringify(tokenKey)}), localStorage.getItem(${JSON.stringify(generationKey)})
  ]`);
  const close = async () => {
    const results = await Promise.allSettled([...tabs].map(async (tab) => {
      const session = sessions.get(tab);
      if (session) {
        // The native logout expires the access-bound HttpOnly refresh cookie.
        // Restore only these two storage keys, and only while both match our login.
        const { cdp, ...state } = session;
        const restored = await runtime(cdp, `(async () => {
          const { origin, tokenKey, generationKey, initial, owned, tokens, logout, platform } = ${JSON.stringify(state)};
          const keys = [tokenKey, generationKey];
          const matches = (values) => location.origin === origin && keys.every((key, i) => localStorage.getItem(key) === values[i]);
          if (!matches(owned)) return false;
          for (const token of tokens) {
            if (!matches(owned)) return false;
            const response = await fetch(logout, { method: 'POST', credentials: 'same-origin', redirect: 'error',
              headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, body: '{}' });
            if (platform ? response.status !== 200 || ![2, 20000].includes((await response.json()).code) : response.status !== 204) return false;
          }
          if (!matches(owned)) return false;
          keys.forEach((key, i) => {
            if (initial[i] === null) localStorage.removeItem(key);
            else localStorage.setItem(key, initial[i]);
          });
          return matches(initial);
        })()`);
        if (!restored) throw new Error('IAB session changed or native logout failed');
        sessions.delete(tab);
      }
      await tab.close();
      tabs.delete(tab);
    }));
    if (results.some(({ status }) => status === 'rejected')) throw new Error('IAB task session or tab cleanup failed');
  };

  // Read the actual Web request and response rather than sending a duplicate API call.
  const responseTo = async (cdp, method, expectedUrl, action) => {
    await cdp.send('Network.enable');
    const methods = ['Network.requestWillBeSent', 'Network.responseReceived', 'Network.loadingFinished', 'Network.loadingFailed'];
    let { cursor } = await cdp.readEvents({ methods });
    await action();
    const requests = new Set();
    const responses = new Map();
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
      const page = await cdp.readEvents({ afterSequence: cursor, methods, timeoutMs: 1000 });
      if (page.truncated) throw new Error('IAB response evidence was truncated');
      cursor = page.cursor;
      for (const { method: event, params } of page.events) {
        const id = params.requestId;
        if (event === 'Network.requestWillBeSent' && params.request.method === method
          && (params.request.url === expectedUrl || params.request.url.startsWith(`${expectedUrl}?`))) requests.add(id);
        if (!requests.has(id)) continue;
        if (event === 'Network.responseReceived') responses.set(id, params.response.status);
        if (event === 'Network.loadingFailed') throw new Error('IAB browser API request failed');
        if (event === 'Network.loadingFinished' && responses.has(id)) {
          const body = await cdp.send('Network.getResponseBody', { requestId: id });
          // Browser API responses have a JSON envelope. Read only the result code,
          // so role, file and settings details never cross the evidence channel.
          if (body.base64Encoded) throw new Error('IAB API response was not plain JSON');
          const parsed = JSON.parse(body.body);
          if (!parsed || typeof parsed.code !== 'number') throw new Error('IAB API response had no business status');
          return { status: responses.get(id), json: { code: parsed.code } };
        }
      }
    }
    throw new Error('IAB UI did not issue the expected API request');
  };

  const run = async (step) => {
    if (step.kind === 'login') {
      const platform = step.label === 'platform';
      const url = `${step.url}${platform ? '/platform/' : '/admin/login'}`;
      const target = step.label === 'restricted' ? owner : await newTab(url);
      const { tab, cdp } = target;
      if (step.label === 'admin') owner = target;
      if (step.label === 'restricted') await tab.goto(url);
      const landing = await current(cdp);
      const actual = new URL(landing.url);
      if (actual.origin !== step.url || actual.pathname !== new URL(url).pathname) throw new Error('IAB login left the registered entry');
      const tokenKey = platform ? 'peanut-platform-token' : 'token';
      const generationKey = platform ? 'peanut-platform-session' : 'peanut-admin-session';
      const keys = { cdp, tokenKey, generationKey };
      const previous = await sessionState(keys);
      let session = sessions.get(tab);
      if (step.label === 'restricted') {
        if (!session || previous.some((value, i) => value !== session.owned[i])) throw new Error('unexpected IAB owner session');
      } else {
        if (previous[0] !== null) throw new Error('unexpected IAB origin session');
        const logout = platform ? '/platformapi/session/logout' : '/adminapi/tenant/session/logout';
        session = { ...keys, origin: step.url, initial: previous, owned: previous, tokens: [], logout, platform };
        sessions.set(tab, session);
      }
      const [email, password] = bundle.credentials[step.label];
      const initial = step.label === 'admin' ? await tab.getAXState({ emit: false }) : undefined;
      const form = platform ? tab.playwright : tab.playwright.locator('.login-form');
      await form.locator('input').nth(0).fill(email);
      await form.locator('input[type="password"]').fill(password);
      const submit = form.getByRole('button', { name: platform ? /登录实例平台/i : /登录|login/i });
      await submit.click();
      if (platform) await tab.playwright.getByText('概览', { exact: true }).first().waitFor(wait);
      else {
        const menu = tab.playwright.locator('.user-menu-trigger');
        const select = form.locator('.el-select');
        const transition = await Promise.race([menu.waitFor(wait).then(() => 'menu'), select.waitFor(wait).then(() => 'select')]);
        if (transition === 'select') {
          await select.click();
          await tab.playwright.locator('.el-select-dropdown:visible .el-select-dropdown__item').first().click();
          await submit.click();
          await menu.waitFor(wait);
        }
      }
      const authenticated = await sessionState(keys);
      const [token, generation] = authenticated;
      if (!token || token === previous[0] || !generation || generation === previous[1]) throw new Error('IAB form did not establish a new login');
      session.owned = authenticated;
      session.tokens.push(token);
      await networkIdle(tab);
      return { ...await current(cdp), token, png: await screenshot(cdp), initial };
    }
    if (step.kind === 'navigate') {
      const response = await responseTo(owner.cdp, 'GET', step.api, async () => {
        await owner.tab.goto(step.url);
        await networkIdle(owner.tab);
      });
      return { ...await current(owner.cdp), response };
    }
    if (step.kind === 'website') {
      return responseTo(owner.cdp, 'POST', step.api, async () => {
        await owner.tab.playwright.locator('.el-form-item').filter({ hasText: '网站名称' }).locator('input').fill(step.marker);
        await owner.tab.playwright.getByRole('button', { name: '保存', exact: true }).click();
      });
    }
    if (step.kind === 'render') {
      const { tab, cdp } = await newTab(step.url);
      await networkIdle(tab);
      if (step.label === 'docs') await tab.playwright.getByRole('link', { name: 'README.md', exact: true }).waitFor(wait);
      const status = await runtime(cdp, "performance.getEntriesByType('navigation')[0]?.responseStatus");
      const text = await tab.playwright.locator('body').innerText({ timeoutMs: 20000 });
      return { ...await current(cdp), status, text_length: text.trim().length, png: await screenshot(cdp),
        readme_link: step.label === 'docs', snapshot: step.label === 'docs' ? await tab.getAXState({ emit: false }) : undefined };
    }
    if (step.kind === 'finish' || step.kind === 'abort') {
      await close();
      return {};
    }
    throw new Error('unexpected IAB qualification step');
  };

  return { close, async next() {
      const step = await exchange('GET');
      if (!step) return { status: 'waiting' };
      if (step.id === lastId) return { status: 'already-dispatched', id: lastId };
      lastId = step.id;
      let evidence;
      try {
        evidence = await run(step);
      } catch {
        let cleanupFailed = step.kind === 'finish' || step.kind === 'abort';
        if (!cleanupFailed) {
          try { await close(); } catch { cleanupFailed = true; }
        }
        await exchange('POST', { id: step.id, controller, failed: true, cleanupFailed });
        throw new Error(`IAB qualification failed in ${step.kind} ${step.label ?? ''}`);
      }
      await exchange('POST', { id: step.id, controller, evidence });
      if (step.kind === 'finish') return { status: 'evidence-delivered', id: lastId };
      if (step.kind === 'abort') return { status: 'run-aborted', id: lastId };
      return { status: 'step-delivered', id: lastId, kind: step.kind, label: step.label };
  } };
}
