export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type QueryValue = string | number | boolean | null | undefined | (string | number)[];
export type QueryParams = Record<string, QueryValue>;

export interface UploadProgress {
  loaded: number;
  total: number;
  percent: number;
}

export interface ApiRequest {
  method: HttpMethod;
  path: string;
  params?: QueryParams;
  body?: unknown;
  /** multipart upload */
  formData?: FormData;
  signal?: AbortSignal;
  onUploadProgress?: (p: UploadProgress) => void;
  /** Download the response as a Blob instead of JSON. */
  responseType?: 'json' | 'blob';
  token?: string | null;
}

/** A transport turns a request into a parsed response or throws ApiError. */
export type Transport = (req: ApiRequest) => Promise<unknown>;
