export { default as ContentViewer } from './ContentViewer.vue';
export { default as ContentEditor } from './ContentEditor.vue';
export { ContentToolRegistry } from './registry';
export { createLockedContentToolRegistry } from './contributions';
export { parseSurfaceCapabilities } from './capabilities';
export type {
  AuthorizedPreviewAccess,
  ContentDescriptor,
  ContentKind,
  ContentSurfaceProps,
  ContentToolsContribution,
  FileResourceContent,
  RichTextContent,
  SurfaceAdapterRegistration,
  SurfaceCallbacks,
  SurfaceCapabilities,
  SurfaceIntent,
  SurfaceState,
} from './contracts';
