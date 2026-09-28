/**
 * Blob storage for uploaded case files in mock mode. Uses IndexedDB (so files
 * survive a reload) and falls back to memory where IndexedDB is unavailable.
 * The Node API stores files under UPLOAD_DIR and streams them back.
 */
import { del, get, set } from 'idb-keyval';
import { samplePrescriptionPdf, sampleStlText } from '@48hrs/shared/demo-files';

const memory = new Map<string, Blob>();
const hasIdb = typeof indexedDB !== 'undefined';

export const fileStore = {
  async put(id: string, blob: Blob) {
    memory.set(id, blob);
    if (hasIdb) {
      try {
        await set(`file:${id}`, blob);
      } catch {
        /* keep the in-memory copy */
      }
    }
  },
  async get(id: string): Promise<Blob | undefined> {
    if (memory.has(id)) return memory.get(id);
    if (!hasIdb) return undefined;
    try {
      const b = await get<Blob>(`file:${id}`);
      if (b) memory.set(id, b);
      return b;
    } catch {
      return undefined;
    }
  },
  async remove(id: string) {
    memory.delete(id);
    if (hasIdb) {
      try {
        await del(`file:${id}`);
      } catch {
        /* ignore */
      }
    }
  },
};

/* ---------- Sample content for seeded attachments (no bytes are seeded) ---------- */

async function samplePng(title: string): Promise<Blob> {
  if (typeof document === 'undefined') return new Blob([title], { type: 'text/plain' });
  const canvas = document.createElement('canvas');
  canvas.width = 960;
  canvas.height = 640;
  const g = canvas.getContext('2d');
  if (!g) return new Blob([title], { type: 'text/plain' });
  g.fillStyle = '#0a1424';
  g.fillRect(0, 0, 960, 640);
  g.strokeStyle = '#2f6fc4';
  g.lineWidth = 3;
  g.beginPath();
  g.ellipse(480, 330, 300, 200, 0, Math.PI * 1.03, Math.PI * 1.97);
  g.stroke();
  g.fillStyle = '#ffffff';
  g.font = '600 30px sans-serif';
  g.fillText('Intraoral photo', 60, 90);
  g.fillStyle = '#aecbec';
  g.font = '20px sans-serif';
  g.fillText(title, 60, 130);
  g.fillStyle = '#d9b53f';
  g.fillText('Sample record — 48HRS Dental Lab demo data', 60, 590);
  return new Promise((resolve) => canvas.toBlob((b) => resolve(b ?? new Blob([title], { type: 'text/plain' })), 'image/png'));
}

export async function sampleContent(name: string, ext: string, caseNumber: string): Promise<Blob> {
  if (ext === 'pdf') return new Blob([samplePrescriptionPdf(caseNumber)], { type: 'application/pdf' });
  if (ext === 'stl') return new Blob([sampleStlText(name)], { type: 'model/stl' });
  return samplePng(`${caseNumber} · ${name}`);
}
