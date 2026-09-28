/**
 * Password hashing with scrypt (memory-hard, built into Node — no native
 * add-on). Stored format: scrypt$N$r$p$<salt b64>$<hash b64>. Parameters are
 * part of the hash so they can be raised later without breaking old hashes.
 */
import { randomBytes, scrypt as scryptCb, timingSafeEqual, type ScryptOptions } from 'node:crypto';

const scrypt = (password: string, salt: Buffer, keylen: number, options: ScryptOptions) =>
  new Promise<Buffer>((resolve, reject) => scryptCb(password, salt, keylen, options, (err, key) => (err ? reject(err) : resolve(key))));

const N = 2 ** 15;
const R = 8;
const P = 1;
const KEY_LENGTH = 64;
/** scrypt needs 128·N·r bytes; leave headroom. */
const MAX_MEM = 256 * N * R;

export async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(16);
  const key = await scrypt(password.normalize('NFKC'), salt, KEY_LENGTH, { N, r: R, p: P, maxmem: MAX_MEM });
  return ['scrypt', N, R, P, salt.toString('base64'), key.toString('base64')].join('$');
}

export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const [algo, n, r, p, saltB64, keyB64] = stored.split('$');
  if (algo !== 'scrypt' || !saltB64 || !keyB64) return false;
  const expected = Buffer.from(keyB64, 'base64');
  const cost = { N: Number(n), r: Number(r), p: Number(p) };
  const key = await scrypt(password.normalize('NFKC'), Buffer.from(saltB64, 'base64'), expected.length, { ...cost, maxmem: 256 * cost.N * cost.r });
  return key.length === expected.length && timingSafeEqual(key, expected);
}

/** A hash that never matches — verified against when the e-mail is unknown, so timing does not reveal accounts. */
let dummyHash: Promise<string> | null = null;
export function dummyPasswordHash() {
  dummyHash ??= hashPassword(randomBytes(16).toString('hex'));
  return dummyHash;
}
