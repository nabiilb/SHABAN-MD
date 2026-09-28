import type { z } from 'zod';
import { fieldErrors } from '@48hrs/shared/schemas';
import { validation } from '../lib/errors.ts';

/** Parses a request body with a shared schema; failures become a 422 keyed by field path. */
export function parseBody<S extends z.ZodTypeAny>(schema: S, body: unknown): z.output<S> {
  const result = schema.safeParse(body ?? {});
  if (!result.success) throw validation(fieldErrors(result.error));
  return result.data;
}
