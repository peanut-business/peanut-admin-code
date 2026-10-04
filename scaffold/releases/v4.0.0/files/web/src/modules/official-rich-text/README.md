# official.rich-text

`official.rich-text` 是独立 Tenant Module。它拥有 `pa_rich_text_document`，通过 Module 路由、
RBAC 和菜单提供文档 CRUD；编辑器页面和 `RichTextEditor` 组件都通过动态 `import()` 加载。

## Vue 使用

```ts
import { RichTextEditor } from '@/modules/official-rich-text/src/editor';
```

`RichTextEditor` 是异步组件；Tiptap、Yjs、Hocuspocus 和 DOMPurify 只在组件挂载时加载。
它使用 `v-model` 读写 `peanut.richtext/1` JSON，使用
`v-model:collaboration-state` 读写 Yjs Snapshot。管理页是完整调用示例。

## 协同配置

- `RICH_TEXT_COLLABORATION_URL`：Hocuspocus `ws://` 或 `wss://` 地址；
- `RICH_TEXT_COLLABORATION_SECRET`：至少 32 字节，只存在于 PHP 与 Hocuspocus 服务；
- 两项都不配置时，编辑器使用数据库持久化与 `revision` 乐观锁；只配置一项会拒绝协同；
- Hocuspocus `onAuthenticate` 必须以同一 Secret 校验 API 返回的
  `<base64url-payload>.<base64url-hmac-sha256>`，并核对 `document_name`、`expires_at` 与
  `scope=read-write`。Secret 不下发到浏览器。

Module 只保存结构 JSON、Yjs Snapshot 和版本，不拥有文章、文件或审核工作流。
