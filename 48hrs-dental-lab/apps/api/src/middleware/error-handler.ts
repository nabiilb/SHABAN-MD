import type { ErrorRequestHandler, RequestHandler } from 'express';
import multer from 'multer';
import { ZodError } from 'zod';
import { API_ERRORS } from '@48hrs/shared/errors';
import { fieldErrors } from '@48hrs/shared/schemas';
import { env } from '../config/env.ts';
import { Prisma } from '../generated/prisma/client.ts';
import { HttpError } from '../lib/errors.ts';
import { logger } from '../lib/logger.ts';

/** Unknown routes answer with the standard 404 body. */
export const notFoundHandler: RequestHandler = (_req, res) => {
  res.status(404).json({ message: API_ERRORS.notFound });
};

/**
 * Every error becomes { message, errors? } with the right status. Internal
 * details (stack traces, SQL) are logged, never sent to the client.
 */
export const errorHandler: ErrorRequestHandler = (err, req, res, _next) => {
  void _next;
  if (err instanceof HttpError) {
    res.status(err.status).json({ message: err.message, ...(err.errors ? { errors: err.errors } : {}), ...(err.extra ?? {}) });
    return;
  }
  if (err instanceof ZodError) {
    res.status(422).json({ message: API_ERRORS.validation, errors: fieldErrors(err) });
    return;
  }
  if (err instanceof multer.MulterError) {
    const message = err.code === 'LIMIT_FILE_SIZE' ? `Larger than the ${env.MAX_UPLOAD_MB} MB limit.` : 'The upload could not be read.';
    res.status(422).json({ message: API_ERRORS.validation, errors: { file: [message] } });
    return;
  }
  // Malformed JSON bodies and oversized payloads from express.json().
  const status = (err as { status?: number; type?: string }).status;
  if ((err as { type?: string }).type === 'entity.parse.failed') {
    res.status(400).json({ message: 'The request body is not valid JSON.' });
    return;
  }
  if (status === 413) {
    res.status(413).json({ message: 'The request is too large.' });
    return;
  }
  if (err instanceof Prisma.PrismaClientKnownRequestError) {
    if (err.code === 'P2025') {
      res.status(404).json({ message: API_ERRORS.notFound });
      return;
    }
    if (err.code === 'P2002') {
      res.status(409).json({ message: 'This record already exists.' });
      return;
    }
    if (err.code === 'P2003') {
      res.status(422).json({ message: 'This record is still referenced by other data.', errors: {} });
      return;
    }
  }
  logger.error({ err, method: req.method, url: req.originalUrl }, 'unhandled error');
  res.status(500).json({ message: API_ERRORS.server });
};
