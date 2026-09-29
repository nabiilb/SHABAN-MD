/** Writes the backend contract files (see backend-contract.ts). Usage: npm run contract:export */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { contractFiles } from './contract-files';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
for (const [path, content] of Object.entries(contractFiles())) {
  const target = resolve(root, path);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, content);
  console.log(`wrote ${path} (${(content.length / 1024).toFixed(0)} KB)`);
}
