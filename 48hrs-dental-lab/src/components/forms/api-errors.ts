import type { FieldPath, FieldValues, UseFormSetError } from 'react-hook-form';
import { ApiError } from '@/services/api/errors';

/**
 * Maps a 422 response ({ errors: { field: [msg] } }) onto React Hook Form
 * fields. Returns the message for errors that do not belong to a field.
 */
export function applyApiErrors<T extends FieldValues>(err: unknown, setError: UseFormSetError<T>, fields: readonly string[]): string | null {
  if (!(err instanceof ApiError)) return err instanceof Error ? err.message : 'Something went wrong.';
  let unmatched: string | null = err.message;
  if (err.isValidation) {
    const leftovers: string[] = [];
    for (const [key, messages] of Object.entries(err.fieldErrors)) {
      if (fields.includes(key)) {
        setError(key as FieldPath<T>, { type: 'server', message: messages[0] });
      } else leftovers.push(messages[0]);
    }
    unmatched = leftovers.length ? leftovers.join(' ') : Object.keys(err.fieldErrors).length ? null : err.message;
  }
  return unmatched;
}
