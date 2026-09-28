/**
 * Blob storage for uploaded case files in mock mode. Uses IndexedDB (so files
 * survive a reload) and falls back to memory where IndexedDB is unavailable.
 * A real backend stores files on disk/S3 and returns signed URLs instead.
 */
import { del, get, set } from 'idb-keyval';

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

function pdfEscape(s: string) {
  return s.replace(/[\\()]/g, (m) => `\\${m}`);
}

/** A small but valid one-page PDF. */
function samplePdf(lines: string[]): Blob {
  const text = lines.map((l, i) => `BT /F1 ${i === 0 ? 16 : 11} Tf 60 ${760 - i * 22} Td (${pdfEscape(l)}) Tj ET`).join('\n');
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${text.length} >>\nstream\n${text}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let out = '%PDF-1.4\n';
  const offsets: number[] = [];
  objs.forEach((o, i) => {
    offsets.push(out.length);
    out += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
  offsets.forEach((o) => (out += `${String(o).padStart(10, '0')} 00000 n \n`));
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
  return new Blob([out], { type: 'application/pdf' });
}

/** ASCII STL of a small cube — opens in any STL viewer. */
function sampleStl(name: string): Blob {
  const v = [
    [0, 0, 0], [10, 0, 0], [10, 10, 0], [0, 10, 0], [0, 0, 10], [10, 0, 10], [10, 10, 10], [0, 10, 10],
  ];
  const f = [[0, 2, 1], [0, 3, 2], [4, 5, 6], [4, 6, 7], [0, 1, 5], [0, 5, 4], [1, 2, 6], [1, 6, 5], [2, 3, 7], [2, 7, 6], [3, 0, 4], [3, 4, 7]];
  const facets = f.map((t) => `  facet normal 0 0 0\n    outer loop\n${t.map((i) => `      vertex ${v[i].join(' ')}`).join('\n')}\n    endloop\n  endfacet`).join('\n');
  return new Blob([`solid ${name}\n${facets}\nendsolid ${name}\n`], { type: 'model/stl' });
}

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
  if (ext === 'pdf') {
    return samplePdf([
      '48HRS Dental Lab — Prescription',
      `Case ${caseNumber}`,
      'Sample document generated for demo data.',
      'Uploaded files you add yourself are stored and returned byte-for-byte.',
    ]);
  }
  if (ext === 'stl') return sampleStl(name.replace(/\W+/g, '_'));
  return samplePng(`${caseNumber} · ${name}`);
}
