/** Mock-backend seed: the shared demo dataset plus salted SHA-256 demo password hashes. */
import { buildDemoDataset } from '@48hrs/shared/demo-data';
import type { MockDatabase } from './db';
import { DB_VERSION } from './db';

/** Salted SHA-256 hashes of the demo password — no plain-text passwords are stored. */
const HASHES: Record<string, [string, string]> = {
  'khalid@48hrs.lab': ['3e4ecea61dda1ce5', '3db03ff24fc2e21f1f51d3662fe444fbca7c26cfd10849742d61004f81dcc6f0'],
  'hodan@48hrs.lab': ['198fe95ad5eb06a5', 'fb0bcb4da1fb44216a260f477d916a2518039fa27fd6e34533e7a52ac6894c96'],
  'omar@48hrs.lab': ['504d26f747d8e0e0', '4b9b2d2a30a1d5e0498cb28999acd62111bd6c4bb3dc3801a5ef72f06e6cf7ac'],
  'sagal@48hrs.lab': ['931beb4026addb92', 'a9038482c2e173c78bb7f297f9ec99628539daee7df157c8ab4da305f7ac3adf'],
  'fatima@48hrs.lab': ['ad1259e5b1f2fc5d', 'e2a8b9222d0f550da1bb5bc1b19c4a218003672b6c343a09e1c2a11d0d22073a'],
  'ahmed@48hrs.lab': ['bd79d968a8f327e4', '4021a173a20924628b77050a308229f79334ccc36803317179ad2fc225d95760'],
  'ali@48hrs.lab': ['e44734d080334b7f', '1f2c533828b920e46f9fc5456717bbcee0fcbe8d30fb1d1c8cd7b06ee07b1153'],
  'maryan@48hrs.lab': ['a63e9881f5213148', '0c1ebbcbbb7cb39fb55b41db1d26cf544eb63e5868b3a008bd3a8ec1cd148e1f'],
  'idil@48hrs.lab': ['4759215081e36819', 'ab55e37cb9db432e3f05f5e720cf6f61d17d4a27703eca19ccb2f8ab0eedcbe1'],
  'bashir@48hrs.lab': ['5da3dcef3da3d8c0', 'd86451e410e23f496f332f8f4bb24f4fa14652b84772462bc16816908fc952de'],
  'amina@smiledental.so': ['53a2b0dbfb2371bd', '2eba9220d7cede2d691afb9c7cb8502400f6c6e010085dde8b5564cad1afcc3d'],
  'layla@horizondental.so': ['6170bfc27112a5ee', 'b754565fe4195978aa223215d4de09cf3f08b0ae89b5a9f537d983d531c0cc96'],
};

export function buildSeed(now: number): MockDatabase {
  const data = buildDemoDataset(now);
  return {
    version: DB_VERSION,
    ...data,
    users: data.users.map((u) => ({ ...u, passwordSalt: HASHES[u.email][0], passwordHash: HASHES[u.email][1] })),
    activity: [],
    sessions: [],
    resetTokens: [],
  };
}
