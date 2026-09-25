import { createModuleClient } from '../../generated/openapi';

export type SettingSourceScope = 'deployment' | 'tenant' | 'default' | null;
export type SettingEditorKind =
  | 'boolean'
  | 'enum'
  | 'number'
  | 'secret'
  | 'string'
  | 'unsupported';
export type SettingScalar = boolean | number | string;
export type SettingSchemaType =
  | 'array'
  | 'boolean'
  | 'integer'
  | 'null'
  | 'number'
  | 'object'
  | 'string';

export interface SettingSchema {
  readonly type: SettingSchemaType | readonly SettingSchemaType[];
  readonly enum?: readonly unknown[];
  readonly [key: string]: unknown;
}

export interface SettingRecord {
  readonly moduleKey: string;
  readonly settingKey: string;
  readonly name: string;
  readonly description: string;
  readonly schema: SettingSchema;
  readonly required: boolean;
  readonly secret: boolean;
  readonly configured: boolean;
  readonly sourceScope: SettingSourceScope;
  readonly value: unknown;
  readonly effectiveAt: string | null;
  readonly expiresAt: string | null;
  readonly revision: string;
  readonly etag: string | null;
}

export interface SettingGroup {
  readonly moduleKey: string;
  readonly definitions: readonly SettingRecord[];
}

export interface SettingsTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}

export type SettingPrecondition =
  | { readonly kind: 'create' }
  | { readonly kind: 'replace'; readonly etag: string };

export interface ReplaceSettingRequest {
  readonly value: unknown;
  readonly idempotencyKey: string;
  readonly precondition: SettingPrecondition;
  readonly signal: AbortSignal;
}

export interface UnsetSettingRequest {
  readonly idempotencyKey: string;
  readonly etag: string;
  readonly signal: AbortSignal;
}

export interface SettingsTransport {
  list: (signal: AbortSignal) => Promise<SettingsTransportResult>;
  replace: (
    moduleKey: string,
    settingKey: string,
    request: ReplaceSettingRequest
  ) => Promise<SettingsTransportResult>;
  unset: (
    moduleKey: string,
    settingKey: string,
    request: UnsetSettingRequest
  ) => Promise<SettingsTransportResult>;
}

const moduleKeyPattern =
  /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/;
const settingKeyPattern = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const revisionPattern = /^[1-9][0-9]*$/;
const strongEtagPattern = /^"[^"\r\n]+"$/;
const schemaTypes = new Set<SettingSchemaType>([
  'array',
  'boolean',
  'integer',
  'null',
  'number',
  'object',
  'string',
]);
const sourceScopes = new Set<Exclude<SettingSourceScope, null>>([
  'deployment',
  'tenant',
  'default',
]);

const invalidResponse = (): never => {
  throw new Error('SETTINGS_RESPONSE_INVALID');
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const stringField = (value: unknown, maximum: number): string => {
  if (typeof value !== 'string' || value === '' || value.length > maximum)
    return invalidResponse();
  return value;
};

const nullableDate = (value: unknown): string | null => {
  if (value === null) return null;
  if (
    typeof value !== 'string' ||
    !value.endsWith('Z') ||
    Number.isNaN(Date.parse(value))
  ) {
    return invalidResponse();
  }
  return value;
};

const strongEtag = (value: unknown): string | null => {
  if (value === null) return null;
  if (typeof value !== 'string' || !strongEtagPattern.test(value))
    return invalidResponse();
  return value;
};

const enumValues = (
  schema: Record<string, unknown>,
  type: SettingSchemaType
): readonly unknown[] | undefined => {
  if (!Object.prototype.hasOwnProperty.call(schema, 'enum')) return undefined;
  if (!Array.isArray(schema.enum) || schema.enum.length === 0)
    return invalidResponse();
  if (type === 'array' || type === 'object' || type === 'null')
    return [...schema.enum];

  const values: SettingScalar[] = [];
  const seen = new Set<string>();
  for (const value of schema.enum) {
    const valid =
      type === 'string'
        ? typeof value === 'string'
        : type === 'boolean'
        ? typeof value === 'boolean'
        : type === 'number'
        ? typeof value === 'number' && Number.isFinite(value)
        : type === 'integer'
        ? typeof value === 'number' && Number.isInteger(value)
        : false;
    if (!valid) return invalidResponse();
    const digest = `${typeof value}:${String(value)}`;
    if (seen.has(digest)) return invalidResponse();
    seen.add(digest);
    values.push(value as SettingScalar);
  }
  return values;
};

const parseSchema = (value: unknown): SettingSchema => {
  if (!isRecord(value)) return invalidResponse();
  const type =
    typeof value.type === 'string'
      ? schemaTypes.has(value.type as SettingSchemaType)
        ? (value.type as SettingSchemaType)
        : invalidResponse()
      : Array.isArray(value.type) &&
        value.type.length > 0 &&
        new Set(value.type).size === value.type.length &&
        value.type.every(
          (item) =>
            typeof item === 'string' &&
            schemaTypes.has(item as SettingSchemaType)
        )
      ? (value.type as SettingSchemaType[])
      : invalidResponse();
  const parsedEnum = Array.isArray(type)
    ? Object.prototype.hasOwnProperty.call(value, 'enum')
      ? Array.isArray(value.enum) && value.enum.length > 0
        ? [...value.enum]
        : invalidResponse()
      : undefined
    : enumValues(value, type);

  return parsedEnum === undefined
    ? { ...value, type }
    : { ...value, type, enum: parsedEnum };
};

const parseSourceScope = (value: unknown): SettingSourceScope => {
  if (value === null) return null;
  if (
    typeof value !== 'string' ||
    !sourceScopes.has(value as Exclude<SettingSourceScope, null>)
  ) {
    return invalidResponse();
  }
  return value as Exclude<SettingSourceScope, null>;
};

const parseSettingValue = (
  value: unknown,
  schema: SettingSchema,
  allowsMissingValue: boolean
): unknown => {
  if (
    Array.isArray(schema.type) ||
    schema.type === 'array' ||
    schema.type === 'object' ||
    schema.type === 'null'
  ) {
    return value;
  }
  if (value === null) return allowsMissingValue ? null : invalidResponse();

  const valid =
    schema.type === 'boolean'
      ? typeof value === 'boolean'
      : schema.type === 'string'
      ? typeof value === 'string'
      : schema.type === 'number'
      ? typeof value === 'number' && Number.isFinite(value)
      : typeof value === 'number' && Number.isInteger(value);
  if (!valid) return invalidResponse();
  if (
    schema.enum !== undefined &&
    !schema.enum.some((candidate) => Object.is(candidate, value))
  ) {
    return invalidResponse();
  }
  return value;
};

export const parseSettingRecord = (value: unknown): SettingRecord => {
  if (!isRecord(value)) return invalidResponse();
  const moduleKey = stringField(value.module_key, 96);
  const settingKey = stringField(value.setting_key, 64);
  if (!moduleKeyPattern.test(moduleKey) || !settingKeyPattern.test(settingKey))
    return invalidResponse();
  if (
    typeof value.required !== 'boolean' ||
    typeof value.secret !== 'boolean' ||
    typeof value.configured !== 'boolean' ||
    typeof value.revision !== 'string' ||
    !revisionPattern.test(value.revision)
  ) {
    return invalidResponse();
  }

  const schema = parseSchema(value.schema);
  const sourceScope = parseSourceScope(value.source_scope);
  if (value.secret && (schema.type !== 'string' || value.value !== undefined)) {
    throw new Error('SETTINGS_SECRET_RESPONSE_EXPOSED');
  }
  if (!value.secret && !Object.prototype.hasOwnProperty.call(value, 'value'))
    return invalidResponse();

  return {
    moduleKey,
    settingKey,
    name: stringField(value.name, 160),
    description: stringField(value.description, 500),
    schema,
    required: value.required,
    secret: value.secret,
    configured: value.configured,
    sourceScope,
    value: value.secret
      ? null
      : parseSettingValue(
          value.value,
          schema,
          !value.required && !value.configured && sourceScope === null
        ),
    effectiveAt: nullableDate(value.effective_at),
    expiresAt: nullableDate(value.expires_at),
    revision: value.revision,
    etag: strongEtag(value.etag),
  };
};

export const parseSettingsList = (value: unknown): SettingRecord[] => {
  if (
    !isRecord(value) ||
    !isRecord(value.data) ||
    !Array.isArray(value.data.items)
  ) {
    return invalidResponse();
  }
  return value.data.items.map(parseSettingRecord);
};

export const parseSettingResponse = (
  value: unknown,
  responseEtag?: string
): SettingRecord => {
  if (!isRecord(value) || !isRecord(value.data)) return invalidResponse();
  return parseSettingRecord(
    responseEtag === undefined
      ? value.data
      : { ...value.data, etag: responseEtag }
  );
};

export const groupSettingRecords = (
  records: readonly SettingRecord[]
): SettingGroup[] => {
  const groups = new Map<string, SettingRecord[]>();
  for (const record of records) {
    const definitions = groups.get(record.moduleKey) ?? [];
    definitions.push(record);
    groups.set(record.moduleKey, definitions);
  }

  return [...groups.entries()]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([moduleKey, definitions]) => ({
      moduleKey,
      definitions: [...definitions].sort((left, right) =>
        left.settingKey.localeCompare(right.settingKey)
      ),
    }));
};

export const settingEditorKind = (record: SettingRecord): SettingEditorKind => {
  if (record.secret) return 'secret';
  if (Array.isArray(record.schema.type)) return 'unsupported';
  if (
    record.schema.type !== 'array' &&
    record.schema.type !== 'object' &&
    record.schema.enum !== undefined
  )
    return 'enum';
  if (record.schema.type === 'boolean') return 'boolean';
  if (record.schema.type === 'integer' || record.schema.type === 'number')
    return 'number';
  if (record.schema.type === 'string') return 'string';
  return 'unsupported';
};

export interface SettingsFetchTransportOptions {
  readonly baseUrl: string;
  readonly fetch: (request: Request) => Promise<Response>;
}

export const createSettingsFetchTransport = (
  options: SettingsFetchTransportOptions
): SettingsTransport => {
  const sdk = createModuleClient({
    baseUrl: options.baseUrl,
    fetch: options.fetch,
  });
  const result = async (
    request: Promise<{ data?: unknown; error?: unknown; response: Response }>
  ): Promise<SettingsTransportResult> => {
    const { data, error, response } = await request;
    return {
      body: data ?? error ?? null,
      headers: response.headers,
      status: response.status,
    };
  };

  return {
    list(signal) {
      return result(
        sdk.listModuleSettings({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          redirect: 'manual',
          signal,
        })
      );
    },
    replace(moduleKey, settingKey, request) {
      const headers = new Headers({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Idempotency-Key': request.idempotencyKey,
      });
      if (request.precondition.kind === 'create') {
        headers.set('If-None-Match', '*');
      } else {
        headers.set('If-Match', request.precondition.etag);
      }
      return result(
        sdk.replaceModuleSetting({
          body: { value: request.value } as never,
          credentials: 'include',
          headers,
          params: {
            header:
              request.precondition.kind === 'create'
                ? {
                    'Idempotency-Key': request.idempotencyKey,
                    'If-None-Match': '*',
                  }
                : {
                    'Idempotency-Key': request.idempotencyKey,
                    'If-Match': request.precondition.etag,
                  },
            path: { moduleKey, settingKey },
          },
          redirect: 'manual',
          signal: request.signal,
        })
      );
    },
    unset(moduleKey, settingKey, request) {
      return result(
        sdk.unsetModuleSetting({
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Idempotency-Key': request.idempotencyKey,
            'If-Match': request.etag,
          },
          params: {
            header: {
              'Idempotency-Key': request.idempotencyKey,
              'If-Match': request.etag,
            },
            path: { moduleKey, settingKey },
          },
          redirect: 'manual',
          signal: request.signal,
        })
      );
    },
  };
};
