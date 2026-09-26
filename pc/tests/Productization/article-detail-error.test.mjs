import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

// 执行真实 API 和页面的详情加载表达式；不模拟 Nuxt 渲染，也不发起数据库请求。
const root = fileURLToPath(new URL('../..', import.meta.url));
const toolRoot = process.env.PEANUT_PC_TEST_TOOL_ROOT || root;
const require = createRequire(resolve(toolRoot, 'package.json'));
const ts = require('typescript');
const source = readFileSync(
  new URL('../../api/article.ts', import.meta.url),
  'utf8'
);
const compiled = ts.transpileModule(source, {
  compilerOptions: {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.ESNext,
  },
}).outputText;
const api = await import(
  `data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`
);
const page = readFileSync(
  new URL('../../pages/information/detail/[id].vue', import.meta.url),
  'utf8'
);
const expression = page.match(/const article = ref\(\n([\s\S]*?),\n\)/)?.[1];
assert.ok(
  expression,
  'detail load expression changed; review the real page before updating the probe'
);
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const evaluate = new AsyncFunction(
  'getArticleDetail',
  'getArticleDetailOrNull',
  'request',
  'id',
  `return (${expression});`
);
const load = (request, id = 7) =>
  evaluate(api.getArticleDetail, api.getArticleDetailOrNull, request, id);

const article = {
  id: 7,
  cid: 3,
  title: 'Synthetic article',
  image: '',
  desc: '',
  click: 0,
  create_time: '2026-09-25',
  content: '<p>body</p>',
  collect: true,
};

test('valid public detail retains content without authenticated collection state', async () => {
  const calls = [];
  const result = await load({
    get: async (...args) => {
      calls.push(args);
      return article;
    },
  });
  assert.deepEqual(calls, [['api/article/detail', { id: 7 }, false]]);
  assert.equal(result.content, article.content);
  assert.equal(Object.hasOwn(result, 'collect'), false);
});
test('known not-found becomes a missing article', async () => {
  assert.equal(
    await load({
      get: async () => {
        throw { kind: 'business', code: '40400' };
      },
    }),
    null
  );
});
for (const [name, error] of [
  ['upstream server failure', { kind: 'business', code: '50000' }],
  ['module permission rejection', { kind: 'business', code: '40300' }],
  ['session rejection', { kind: 'unauthorized', code: '40100' }],
  ['transport failure', { kind: 'transport', code: '40400' }],
  ['decoder failure', { kind: 'decoder', code: 'CLIENT_DECODER_INVALID' }],
  ['unclassified exception', new Error('synthetic failure')],
]) {
  test(`${name} is not converted to a false 404`, async () => {
    await assert.rejects(
      load({
        get: async () => {
          throw error;
        },
      }),
      (actual) => actual === error
    );
  });
}
test('malformed successful payload is not silently changed into missing content', async () => {
  await assert.rejects(load({ get: async () => null }), TypeError);
});
test('invalid route id does not call the API', async () => {
  for (const id of [0, -1, NaN, 0.5]) {
    assert.equal(
      await load(
        {
          get: async () => {
            assert.fail('unexpected API request');
          },
        },
        id
      ),
      null
    );
  }
});
