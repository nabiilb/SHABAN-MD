import { demoExport, rulesExport } from './backend-contract';

/** Repository-relative path → exact file content (deterministic, so staleness is a plain string compare). */
export function contractFiles(): Record<string, string> {
  return {
    'backend/database/data/demo.json': `${JSON.stringify(demoExport())}\n`,
    'backend/tests/Fixtures/shared-rules.json': `${JSON.stringify(rulesExport(), null, 1)}\n`,
  };
}
