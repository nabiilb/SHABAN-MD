import { basename } from 'node:path';
import { categoryForExtension, extensionOf, validateFile } from '@48hrs/shared/constants';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { ATTACHMENT_CATEGORIES } from '@48hrs/shared/schemas';
import type { AttachmentCategory, CaseAttachment } from '@48hrs/shared/types';
import { DOWNLOAD_MIME, checkFileContent } from '../lib/file-rules.ts';
import { forbidden, notFound, validation } from '../lib/errors.ts';
import { prisma } from '../lib/prisma.ts';
import { commitUpload, discardTemp, newStorageKey, objectExists, readHead, readObject, removeObject } from '../lib/storage.ts';
import { can, caseScope } from '../policies/case-policy.ts';
import { logActivity } from '../repositories/activity-repository.ts';
import { attachmentInclude, toAttachment } from '../repositories/mappers.ts';
import type { AuthContext } from '../types/auth.ts';

/** Display name only: no directories, no control characters, bounded length. */
function safeName(original: string) {
  // Browsers send the name as latin1 on the wire; multer hands it over undecoded.
  const decoded = Buffer.from(original, 'latin1').toString('utf8');
  const printable = [...decoded].filter((ch) => ch.charCodeAt(0) >= 32 && ch.charCodeAt(0) !== 127 && ch !== '"').join('');
  const name = basename(printable.replace(/\\/g, '/')).trim();
  return (name || 'upload').slice(0, 180);
}

export async function findVisibleCase(auth: AuthContext, idOrNumber: string) {
  const c = await prisma.dentalCase.findFirst({ where: { AND: [caseScope(auth), { OR: [{ id: idOrNumber }, { caseNumber: idOrNumber }] }] }, select: { id: true, caseNumber: true } });
  if (!c) throw notFound();
  return c;
}

export const attachmentService = {
  /** Validates and stores a file multer has already written to the incoming area. */
  async upload(auth: AuthContext, caseRef: { id: string; caseNumber: string }, file: Express.Multer.File | undefined, rawCategory: unknown): Promise<CaseAttachment> {
    if (!file) throw validation({ file: ['Choose a file to upload.'] });
    try {
      const name = safeName(file.originalname);
      const ext = extensionOf(name);
      const ruleError = validateFile({ name, size: file.size }) ?? checkFileContent(ext, await readHead(file.path, 64));
      if (ruleError) throw validation({ file: [ruleError] });
      const category = (typeof rawCategory === 'string' && rawCategory ? rawCategory : categoryForExtension(ext)) as AttachmentCategory;
      if (!ATTACHMENT_CATEGORIES.includes(category)) throw validation({ category: ['Choose a file category.'] });

      const storageKey = newStorageKey(ext);
      await commitUpload(file.path, storageKey);
      try {
        const row = await prisma.$transaction(async (tx) => {
          const created = await tx.caseAttachment.create({
            data: { caseId: caseRef.id, name, storageKey, mimeType: DOWNLOAD_MIME[ext] ?? 'application/octet-stream', extension: ext, size: file.size, category, uploadedById: auth.user.id },
            include: attachmentInclude,
          });
          await logActivity(tx, auth.user, { action: 'case.file_upload', description: `Uploaded ${name} to ${caseRef.caseNumber}`, subjectType: 'case', subjectId: caseRef.id, subjectLabel: caseRef.caseNumber });
          return created;
        });
        return toAttachment(row);
      } catch (err) {
        await removeObject(storageKey);
        throw err;
      }
    } finally {
      await discardTemp(file.path);
    }
  },

  /** Uploaders may remove their own files; anyone else needs files.delete. */
  async remove(auth: AuthContext, caseIdOrNumber: string, attachmentId: string) {
    const c = await findVisibleCase(auth, caseIdOrNumber);
    const att = await prisma.caseAttachment.findFirst({ where: { id: attachmentId, caseId: c.id } });
    if (!att) throw notFound();
    const own = att.uploadedById === auth.user.id && can(auth, PERMISSIONS.FILES_UPLOAD);
    if (!own && !can(auth, PERMISSIONS.FILES_DELETE)) throw forbidden();
    await prisma.$transaction(async (tx) => {
      await tx.caseAttachment.delete({ where: { id: att.id } });
      await logActivity(tx, auth.user, { action: 'case.file_delete', description: `Removed ${att.name} from ${c.caseNumber}`, subjectType: 'case', subjectId: c.id, subjectLabel: c.caseNumber });
    });
    await removeObject(att.storageKey);
  },

  async open(auth: AuthContext, caseIdOrNumber: string, attachmentId: string) {
    const c = await findVisibleCase(auth, caseIdOrNumber);
    const att = await prisma.caseAttachment.findFirst({ where: { id: attachmentId, caseId: c.id } });
    if (!att || !(await objectExists(att.storageKey))) throw notFound();
    return { stream: readObject(att.storageKey), name: att.name, size: att.size, mimeType: DOWNLOAD_MIME[att.extension] ?? 'application/octet-stream' };
  },
};
