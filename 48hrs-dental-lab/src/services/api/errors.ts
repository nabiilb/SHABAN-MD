import type { ValidationErrorBody } from '@/types/api';

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
    return new ApiError(status, body?.message || defaultMessage(status), body?.errors ?? {});
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
}

function defaultMessage(status: number) {
  if (status === 0) return 'Cannot reach the server. Check your connection and try again.';
  if (status === 401) return 'Your session has ended. Please sign in again.';
  if (status === 403) return 'You do not have permission to do that.';
  if (status === 404) return 'The requested record was not found.';
  if (status === 422) return 'Some fields need attention.';
  if (status >= 500) return 'The server had a problem. Please try again.';
  return 'Request failed.';
}

export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return 'Something went wrong.';
}
