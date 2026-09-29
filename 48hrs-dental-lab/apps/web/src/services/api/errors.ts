import type { ValidationErrorBody } from '@48hrs/shared/types';

export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;

  constructor(status: number, message: string, fieldErrors: Record<string, string[]> = {}) {
    super(message || defaultMessage(status));
    this.name = 'ApiError';
    this.status = status;
    this.fieldErrors = fieldErrors;
  }

  static fromBody(status: number, body: Partial<ValidationErrorBody> | null | undefined) {
    const message = body?.message || defaultMessage(status);
    // 409: say what the case is now ("Invalid workflow transition. The case is currently "In review".").
    const detail = status === 409 ? body?.errors?.status?.[0] : undefined;
    return new ApiError(status, detail ? `${message} ${detail}` : message, body?.errors ?? {});
  }

  get isUnauthorized() {
    return this.status === 401;
  }

  get isForbidden() {
    return this.status === 403;
  }

  get isNotFound() {
    return this.status === 404;
  }

  get isValidation() {
    return this.status === 422;
  }

  get isConflict() {
    return this.status === 409;
  }
}

function defaultMessage(status: number) {
  if (status === 0) return 'Cannot reach the server. Check your connection and try again.';
  if (status === 401) return 'Your session has ended. Please sign in again.';
  if (status === 403) return 'You do not have permission to do that.';
  if (status === 404) return 'The requested record was not found.';
  if (status === 419) return 'The page expired. Please try again.';
  if (status === 422) return 'Some fields need attention.';
  if (status === 429) return 'Too many attempts. Wait a minute and try again.';
  if (status >= 500) return 'The server had a problem. Please try again.';
  return 'Request failed.';
}

export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return 'Something went wrong.';
}
