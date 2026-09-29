/**
 * The Laravel backend's demo data and parity fixtures are generated from these
 * rules (npm run contract:export). If a rule changes without regenerating them,
 * the PHP parity tests would be checking yesterday's rules — fail here instead.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { contractFiles } from '../scripts/contract-files';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

describe('backend contract files', () => {
  for (const [path, content] of Object.entries(contractFiles())) {
    it(`${path} is up to date (npm run contract:export)`, () => {
      expect(readFileSync(resolve(root, path), 'utf8') === content, `${path} is stale: run npm run contract:export`).toBe(true);
    });
  }
});
