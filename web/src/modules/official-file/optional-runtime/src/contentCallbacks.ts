import { getToken } from '@/utils/auth';
import type {
  FileResourceContent,
  SurfaceCallbacks,
  AuthorizedPreviewAccess,
} from '../../../official-content-tools/src/contracts';

export interface FileContentCallbackOptions {
  readonly documentId?: number;
  readonly viewerAdapterId: string;
  readonly canDownloadOriginal: () => boolean;
}

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};

const deliveryUrl = (value: unknown): string => {
  if (typeof value !== 'string' || /\s|\\/.test(value))
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  const url = new URL(value, window.location.origin);
  if (
    url.origin !== window.location.origin ||
    url.pathname !== '/api/storage/delivery' ||
    url.username !== '' ||
    url.password !== '' ||
    url.hash !== ''
  )
    throw new Error('FILE_ACCESS_RESPONSE_INVALID');
  return url.href;
};

/** File/Document supplies the identity and explicit tool choice; callbacks never store URLs. */
export const createFileContentCallbacks = (
  options: FileContentCallbackOptions
): SurfaceCallbacks => {
  const resolve = async (
    content: FileResourceContent,
    operation: 'preview' | 'download_original',
    signal: AbortSignal
  ): Promise<Record<string, unknown>> => {
    const token = getToken();
    const response = await fetch('/adminapi/api/v1/files/resolve', {
      method: 'POST',
      credentials: 'same-origin',
      signal,
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({
        file_key: content.fileKey,
        operation,
        ...(operation === 'preview'
          ? {
              viewer_adapter_id: options.viewerAdapterId,
              ...(options.documentId === undefined
                ? {}
                : { document_id: options.documentId }),
            }
          : {}),
      }),
    });
    const envelope = record(await response.json());
    if (!response.ok || envelope.code !== 20000)
      throw new Error('FILE_ACCESS_DENIED');
    const data = record(envelope.data);
    if (data.state !== 'available') throw new Error('FILE_PREVIEW_UNAVAILABLE');
    if (
      data.file_key !== content.fileKey ||
      data.source_sha256 !== content.sourceSha256 ||
      data.purpose !== operation ||
      typeof data.media_type !== 'string' ||
      typeof data.expires_at !== 'number' ||
      !Number.isSafeInteger(data.expires_at) ||
      data.expires_at <= Date.now() ||
      !['original', 'derivative'].includes(String(data.bytes))
    )
      throw new Error('FILE_ACCESS_RESPONSE_INVALID');
    if (
      operation === 'download_original' &&
      (data.bytes !== 'original' ||
        data.can_download_original !== true ||
        data.delivery_file_key !== content.fileKey)
    )
      throw new Error('FILE_ACCESS_RESPONSE_INVALID');
    deliveryUrl(data.url);
    return data;
  };

  return {
    resolvePreview: async (content, signal): Promise<AuthorizedPreviewAccess> => {
      const data = await resolve(content, 'preview', signal);
      if (!options.canDownloadOriginal() && data.bytes !== 'derivative')
        throw new Error('FILE_PREVIEW_ORIGINAL_FORBIDDEN');
      return {
        url: deliveryUrl(data.url),
        fileKey: content.fileKey,
        sourceSha256: content.sourceSha256,
        mimeType: data.media_type as string,
        purpose: 'preview',
        bytes: data.bytes as 'original' | 'derivative',
        expiresAt: data.expires_at as number,
        dispose: () => {},
      };
    },
    downloadOriginal: async (content, signal): Promise<void> => {
      if (!options.canDownloadOriginal())
        throw new Error('FILE_DOWNLOAD_ORIGINAL_FORBIDDEN');
      const data = await resolve(content, 'download_original', signal);
      const response = await fetch(deliveryUrl(data.url), {
        credentials: 'same-origin',
        signal,
      });
      if (!response.ok) throw new Error('FILE_DOWNLOAD_ORIGINAL_DENIED');
      const blob = await response.blob();
      if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = content.displayName;
      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 0);
    },
  };
};
