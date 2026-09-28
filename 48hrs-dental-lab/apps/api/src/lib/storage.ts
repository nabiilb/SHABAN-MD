/**
 * Local-disk file storage under UPLOAD_DIR. Files are addressed by opaque,
 * server-generated keys (YYYY/MM/<random>.<ext>); user-supplied names never
 * touch the filesystem, and every resolved path is checked to stay inside the
 * storage root. Swap this module for S3/GCS without touching callers.
 */
import { createReadStream, type ReadStream } from 'node:fs';
import { mkdir, open, rename, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, resolve, sep } from 'node:path';
import { env } from '../config/env.ts';
import { randomToken } from './tokens.ts';

export const storageRoot = resolve(env.UPLOAD_DIR);
/** Multer writes incoming uploads here before they are validated. */
export const incomingDir = join(storageRoot, '.incoming');

function resolveKey(key: string) {
  if (!/^[\w./-]+$/.test(key) || key.includes('..')) throw new Error('Invalid storage key');
  const full = resolve(storageRoot, key);
  if (!full.startsWith(storageRoot + sep)) throw new Error('Storage key escapes the storage root');
  return full;
}

export async function ensureStorage() {
  await mkdir(incomingDir, { recursive: true });
}

/** Deletes every stored object (demo re-seed only — the database rows are wiped in the same step). */
export async function clearStorage() {
  await rm(storageRoot, { recursive: true, force: true });
  await ensureStorage();
}

export function newStorageKey(extension: string, now = new Date()) {
  const ext = extension.toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 8) || 'bin';
  return `${now.getUTCFullYear()}/${String(now.getUTCMonth() + 1).padStart(2, '0')}/${randomToken(18)}.${ext}`;
}

/** Moves a validated upload from the incoming area to its permanent key. */
export async function commitUpload(tempPath: string, key: string) {
  const target = resolveKey(key);
  await mkdir(dirname(target), { recursive: true });
  await rename(tempPath, target);
}

export async function writeObject(key: string, content: string | Uint8Array) {
  const target = resolveKey(key);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, content);
}

export async function removeObject(key: string) {
  await rm(resolveKey(key), { force: true });
}

export async function discardTemp(path: string) {
  if (path.startsWith(incomingDir + sep)) await rm(path, { force: true });
}

export async function objectExists(key: string) {
  try {
    return (await stat(resolveKey(key))).isFile();
  } catch {
    return false;
  }
}

export function readObject(key: string): ReadStream {
  return createReadStream(resolveKey(key));
}

/** First bytes of a file, for content sniffing. */
export async function readHead(path: string, bytes = 16) {
  const fh = await open(path, 'r');
  try {
    const buf = Buffer.alloc(bytes);
    const { bytesRead } = await fh.read(buf, 0, bytes, 0);
    return buf.subarray(0, bytesRead);
  } finally {
    await fh.close();
  }
}
