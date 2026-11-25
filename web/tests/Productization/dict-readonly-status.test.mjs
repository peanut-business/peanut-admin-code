import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
const require=createRequire(new URL('../../package.json',import.meta.url));
const {createSSRApp,compile,h}=require('vue');
const {renderToString}=require('vue/server-renderer');
const {parse}=require('vue/compiler-sfc');
const root=fileURLToPath(new URL('../../',import.meta.url));
const source=readFileSync(root+'src/views/system/dict/index.vue','utf8');
const {descriptor,errors}=parse(source,{filename:'src/views/system/dict/index.vue'});
assert.deepEqual(errors,[]);
function find(n,fn,out=[]){if(n.type===1&&fn(n))out.push(n);for(const c of n.children??[])find(c,fn,out);return out;}
function expression(n,k,arg){return n.props.find(p=>p.type===7&&p.name===k&&(arg===undefined||p.arg?.content===arg))?.exp?.content;}
const col=find(descriptor.template.ast,n=>n.tag==='el-table-column'&&expression(n,'bind','label')?.includes('systemDict.columns.status'))[0];
assert.ok(col);
test('reference-code type status shows read-only enabled/disabled and preserves manager switch',async()=>{
  const tsEvent='(v: string | number | boolean) => handleStatus(row, v as boolean)';
  assert.ok(col.loc.source.includes(tsEvent));
  const render=compile('<div>'+col.loc.source.replace(tsEvent,'(v) => handleStatus(row,v)')+'</div>');
  const tr={'systemDict.columns.status':'状态','systemDict.status.enabled':'启用','systemDict.status.disabled':'禁用'};
  async function view(canEdit,is_disable){
    const calls=[];
    const app=createSSRApp({setup:()=>({canManageDict:()=>{calls.push('peanut.reference-codes.manage');return canEdit;},handleStatus:()=>{throw Error('readonly render cannot mutate');}}),render});
    app.config.globalProperties.$t=k=>tr[k]??k;
    app.component('el-table-column',{setup(_,ctx){return()=>h('div',{},ctx.slots.default?.({row:{id:7,is_disable}}))}});
    app.component('el-switch',{props:['modelValue'],setup(p){return()=>h('button',{'data-test':'dict-edit'},p.modelValue?'on':'off')}});
    app.component('el-tag',{setup(_,ctx){return()=>h('span',{'data-test':'dict-readonly'},ctx.slots.default?.())}});
    return {markup:await renderToString(app),calls};
  }
  for(const [disabled,label] of [[0,'启用'],[1,'禁用']]){
    const out=await view(false,disabled);
    assert.match(out.markup,/data-test="dict-readonly"/);
    assert.ok(out.markup.includes(label));
    assert.doesNotMatch(out.markup,/data-test="dict-edit"/);
    assert.deepEqual(out.calls,['peanut.reference-codes.manage']);
  }
  const owner=await view(true,0);
  assert.match(owner.markup,/data-test="dict-edit"/);
  assert.doesNotMatch(owner.markup,/data-test="dict-readonly"/);
});
test('ReferenceCodes read route and manage mutation remain independently guarded',()=>{
  const r=readFileSync(root+'src/router/routes/modules/system.ts','utf8');
  const backend=readFileSync(root+'../server/route/admin.php','utf8');
  assert.match(r,/name: 'SystemDict'/);
  assert.match(r,/tenantModuleKey: 'peanut.reference-codes'/);
  assert.match(r,/requiredPermissions: \['peanut.reference-codes.read'\]/);
  assert.match(backend,/Route::get\('dict\/type\/lists',[^\n]*'peanut_permission' => 'peanut.reference-codes.read'/);
  assert.match(backend,/Route::post\('dict\/type\/status',[^\n]*'peanut_permission' => 'peanut.reference-codes.manage'/);
  assert.match(source,/hasPermission\('peanut.reference-codes.manage', route.path\)/);
  const locale=readFileSync(root+'src/views/system/dict/locale/zh-CN.ts','utf8');
  assert.match(locale,/'systemDict.status.enabled': '启用'/);
  assert.match(locale,/'systemDict.status.disabled': '禁用'/);
});

test('ReferenceCodes data drawer also keeps status visible without manage permission',async()=>{
  const drawerSource=readFileSync(root+'src/views/system/dict/components/DataDrawer.vue','utf8');
  const drawer=parse(drawerSource,{filename:'src/views/system/dict/components/DataDrawer.vue'});
  assert.deepEqual(drawer.errors,[]);
  const cell=find(drawer.descriptor.template.ast,n=>
    n.tag==='el-table-column'&&expression(n,'bind','label')?.includes('systemDict.data.columns.status'))[0];
  assert.ok(cell);
  const event='(v: string | number | boolean) => handleStatus(row, v as boolean)';
  assert.ok(cell.loc.source.includes(event));
  const render=compile('<div>'+cell.loc.source.replace(event,'(v) => handleStatus(row,v)')+'</div>');
  async function view(manage,disabled){
    const app=createSSRApp({setup:()=>({
      canManageDict:()=>manage,
      handleStatus:()=>{throw Error('SSR cannot mutate')}
    }),render});
    app.config.globalProperties.$t=k=>({'systemDict.status.enabled':'启用','systemDict.status.disabled':'禁用','systemDict.data.columns.status':'状态'}[k]??k);
    app.component('el-table-column',{setup(_,ctx){return()=>h('div',{},ctx.slots.default?.({row:{id:4,is_disable:disabled}}))}});
    app.component('el-switch',{setup(){return()=>h('button',{'data-test':'dict-change'},'edit')}});
    app.component('el-tag',{setup(_,ctx){return()=>h('span',{'data-test':'dict-data-readonly'},ctx.slots.default?.())}});
    return renderToString(app);
  }
  assert.match(await view(false,0),/启用/);
  assert.match(await view(false,1),/禁用/);
  assert.doesNotMatch(await view(false,1),/data-test="dict-change"/);
  assert.match(await view(true,0),/data-test="dict-change"/);
  assert.match(drawerSource,/hasPermission\('peanut.reference-codes.manage', route.path\)/);
});
