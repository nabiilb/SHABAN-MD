/**
 * Content for the demo dataset's attachments (the dataset has file metadata
 * only). The mock serves these on download; the Laravel demo seed writes the
 * same files to UPLOAD_DIR so seeded files open like real uploads.
 */

function pdfEscape(s: string) {
  return s.replace(/[\\()]/g, (m) => `\\${m}`);
}

/** A small but valid one-page PDF (ASCII only). */
export function samplePdfText(lines: string[]): string {
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
  return out;
}

export function samplePrescriptionPdf(caseNumber: string) {
  return samplePdfText([
    '48HRS Dental Lab - Prescription',
    `Case ${caseNumber}`,
    'Sample document generated for demo data.',
    'Files you upload yourself are stored and returned byte-for-byte.',
  ]);
}

/** ASCII STL of a small cube — opens in any STL viewer. */
export function sampleStlText(name: string): string {
  const v = [
    [0, 0, 0], [10, 0, 0], [10, 10, 0], [0, 10, 0], [0, 0, 10], [10, 0, 10], [10, 10, 10], [0, 10, 10],
  ];
  const f = [[0, 2, 1], [0, 3, 2], [4, 5, 6], [4, 6, 7], [0, 1, 5], [0, 5, 4], [1, 2, 6], [1, 6, 5], [2, 3, 7], [2, 7, 6], [3, 0, 4], [3, 4, 7]];
  const solid = name.replace(/\W+/g, '_');
  const facets = f.map((t) => `  facet normal 0 0 0\n    outer loop\n${t.map((i) => `      vertex ${v[i].join(' ')}`).join('\n')}\n    endloop\n  endfacet`).join('\n');
  return `solid ${solid}\n${facets}\nendsolid ${solid}\n`;
}
