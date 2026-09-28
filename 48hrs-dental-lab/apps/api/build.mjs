// Production bundle: dist/server.js and dist/worker.js. Runtime dependencies stay
// external (installed with `npm ci --omit=dev`); the shared workspace package and
// the generated Prisma client (TypeScript sources) are compiled in.
import { readFileSync } from 'node:fs';
import { build } from 'esbuild';

const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));
const external = Object.keys(pkg.dependencies).filter((d) => !d.startsWith('@48hrs/'));

await build({
  entryPoints: { server: 'src/server.ts', worker: 'src/jobs/worker.ts' },
  outdir: 'dist',
  bundle: true,
  platform: 'node',
  target: 'node22',
  format: 'esm',
  sourcemap: true,
  external: [...external, '@prisma/client/*'],
  banner: { js: "import { createRequire as __cr } from 'node:module'; const require = __cr(import.meta.url);" },
  logLevel: 'info',
});
