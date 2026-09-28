/**
 * HTTP errors thrown by services and turned into the API's error contract by
 * the error-handler middleware: { message, errors? }.
 */
import { API_ERRORS } from '@48hrs/shared/errors';

export type FieldErrors = Record<string, string[]>;

export class HttpError extends Error {
  readonly status: number;
  readonly errors?: FieldErrors;
  /** Extra top-level fields for the response body (e.g. currentStatus on 409). */
  readonly extra?: Record<string, unknown>;

  constructor(status: number, message: string, errors?: FieldErrors, extra?: Record<string, unknown>) {
    super(message);
    this.name = 'HttpError';
    this.status = status;
    this.errors = errors;
    this.extra = extra;
  }
}

export const unauthorized = (message: string = API_ERRORS.unauthenticated) => new HttpError(401, message);
export const forbidden = () => new HttpError(403, API_ERRORS.forbidden);
export const notFound = () => new HttpError(404, API_ERRORS.notFound);
export const conflict = (message: string = API_ERRORS.invalidTransition, extra?: Record<string, unknown>, errors?: FieldErrors) =>
  new HttpError(409, message, errors, extra);
export const tooManyRequests = (message: string = API_ERRORS.rateLimited) => new HttpError(429, message);

/** 422 with per-field messages. */
export function validation(errors: FieldErrors, message: string = API_ERRORS.validation) {
  return new HttpError(422, message, errors);
}

/** 422 for a business rule that is not tied to one field (e.g. "this patient has cases"). */
export function unprocessable(message: string) {
  return new HttpError(422, message, {});
}

/** Throws a 422 when the collector has any entries. */
export function throwIfErrors(errors: FieldErrors) {
  if (Object.keys(errors).length) throw validation(errors);
}
