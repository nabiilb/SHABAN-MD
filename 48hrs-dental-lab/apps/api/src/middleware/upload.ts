import multer from 'multer';
import { env } from '../config/env.ts';
import { incomingDir } from '../lib/storage.ts';
import { randomToken } from '../lib/tokens.ts';

/**
 * Multipart parsing for case files: one file per request, written to the
 * incoming area under a random name, size-capped. The attachment service then
 * checks extension and content before moving it to permanent storage.
 */
export const singleFileUpload = multer({
  storage: multer.diskStorage({
    destination: incomingDir,
    filename: (_req, _file, cb) => cb(null, `${randomToken(18)}.upload`),
  }),
  limits: { fileSize: env.MAX_UPLOAD_MB * 1_048_576, files: 1, fields: 5, fieldSize: 1024, parts: 7 },
}).single('file');
