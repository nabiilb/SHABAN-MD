/**
 * Upload rules beyond the shared extension/size check: the file's leading
 * bytes must match its extension, so a renamed executable or HTML page is
 * rejected even when it carries an allowed extension.
 */

const startsWith = (buf: Buffer, bytes: number[], offset = 0) => bytes.every((b, i) => buf[offset + i] === b);

const SIGNATURES: Record<string, (head: Buffer) => boolean> = {
  jpg: (h) => startsWith(h, [0xff, 0xd8, 0xff]),
  jpeg: (h) => startsWith(h, [0xff, 0xd8, 0xff]),
  png: (h) => startsWith(h, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  webp: (h) => startsWith(h, [0x52, 0x49, 0x46, 0x46]) && startsWith(h, [0x57, 0x45, 0x42, 0x50], 8),
  pdf: (h) => startsWith(h, [0x25, 0x50, 0x44, 0x46]),
  // Word 97 (OLE) and Office Open XML (zip).
  doc: (h) => startsWith(h, [0xd0, 0xcf, 0x11, 0xe0]),
  docx: (h) => startsWith(h, [0x50, 0x4b, 0x03, 0x04]),
  // DICOM: 128-byte preamble then "DICM"; some exports omit the preamble, so only reject obvious HTML/scripts below.
};

/** Content that must never be stored, whatever the extension says. */
function looksExecutableOrMarkup(head: Buffer) {
  const text = head.toString('latin1').trimStart().toLowerCase();
  return (
    startsWith(head, [0x4d, 0x5a]) || // PE / DOS executable
    startsWith(head, [0x7f, 0x45, 0x4c, 0x46]) || // ELF
    text.startsWith('<!doctype html') ||
    text.startsWith('<html') ||
    text.startsWith('<script') ||
    text.startsWith('<svg') ||
    text.startsWith('#!')
  );
}

/** Returns an error message, or null when the content is acceptable for the extension. */
export function checkFileContent(extension: string, head: Buffer): string | null {
  if (looksExecutableOrMarkup(head)) return 'This file type is not accepted.';
  const check = SIGNATURES[extension];
  if (check && !check(head)) return `The file content does not match its .${extension} extension.`;
  return null;
}

/** MIME types we answer with on download, keyed by extension (never the client's claim). */
export const DOWNLOAD_MIME: Record<string, string> = {
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  pdf: 'application/pdf',
  stl: 'model/stl',
  ply: 'application/octet-stream',
  obj: 'model/obj',
  dcm: 'application/dicom',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};
