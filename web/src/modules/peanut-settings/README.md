# Settings runtime

`@peanut-admin/peanut-settings/runtime` is the module's exported frontend
entry (`package.json` → `runtime/src/index.ts`). It exports the existing
transport, runtime, types, injection key and `SettingsPage`. In a source
checkout, import the same barrel by relative path; package-name imports require
the host to resolve this module package. This is a source module, not a separately
published npm package.

The real consumer is `RuntimePage.vue`, mounted by `contribution.ts` at
`/system/settings`. It supplies the host's tenant-session fetch and live permission
callbacks. The backend setting definitions and values remain owned by
`peanut.settings`; consumers do not access its tables or models.

```ts
import { onBeforeUnmount, provide } from 'vue';
import {
  createSettingsFetchTransport,
  createSettingsRuntime,
  settingsRuntimeKey,
} from './runtime/src';

// fetchWithTenantSession and canRead/canManage come from the authenticated host.
const runtime = createSettingsRuntime({
  transport: createSettingsFetchTransport({
    baseUrl: apiBaseUrl,
    fetch: fetchWithTenantSession,
  }),
  canRead,
  canManage,
});
provide(settingsRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
```

Render the exported `SettingsPage` under this provider, or use
`useSettingsRuntime()` in a custom view. The page loads on mount; a custom view
calls `await runtime.load()`. Do not share a runtime across users or tenants:
dispose it when its authenticated host context ends.

- `load()` reads the settings list and replaces all local forms. It deliberately
  discards drafts; use it to refresh the whole page.
- Records and groups are available in `runtime.state`. A resource key is
  `moduleKey/settingKey`, for example `example.catalog/page-size`.
- `updateForm(key, value)` edits a local draft; `save(key)` sends that value using
  the existing create/replace precondition and an idempotency key. `unset(key)`
  removes the current override with its ETag, allowing the backend's inherited
  value to become effective.
- `reload(key)` uses the existing list API but applies only that setting. It
  discards the selected draft/conflict, preserves other drafts and their original
  ETags, and hides the selected secret input. It does not silently accept newer
  versions for other pending edits.
- Read requires `peanut.settings.read`; writes require
  `peanut.settings.manage`. Callbacks guide the UI; the server independently
  enforces trusted scope and permissions. Transport requests must retain the
  host's tenant session. No tenant ID is accepted from the editor.
- Request/protocol/validation errors are stored in `state.errors`; HTTP 412 is
  stored in `state.conflicts`. An HTTP failure may resolve the operation promise,
  so inspect state before showing success. Invalid runtime usage (missing
  provider, unknown key or overlapping mutation) can throw. A targeted reload
  reports ordinary failures against that setting; 401/403 clear the page state.
- Boolean, numeric, scalar enum, string and secret fields have editors. Other
  schemas remain explicitly read-only. Secrets are never returned by the API;
  only a newly typed value is held locally, and `dispose()` clears runtime state
  and aborts outstanding requests.

The module's own `setting-definitions.json` is empty: this page displays settings
contributed by installed modules. Website/application settings retain their
existing dedicated menus and backend public contracts; an empty extension list
is not a reason to create duplicate setting definitions.
