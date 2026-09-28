/** Messages of the API error contract: every non-2xx body is { message, errors? }. */
export const API_ERRORS = {
  unauthenticated: 'Please sign in to continue.',
  sessionExpired: 'Your session has expired. Please sign in again.',
  forbidden: 'Access restricted.',
  notFound: 'Resource not found.',
  invalidTransition: 'Invalid workflow transition.',
  validation: 'Some fields need attention.',
  rateLimited: 'Too many attempts. Wait a minute and try again.',
  server: 'Unexpected server error.',
} as const;
