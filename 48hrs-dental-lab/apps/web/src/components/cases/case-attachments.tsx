import { useState } from 'react';
import { Download, Eye, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ATTACHMENT_CATEGORY_LABELS, PREVIEWABLE_EXTENSIONS } from '@48hrs/shared/constants';
import { PERMISSIONS } from '@48hrs/shared/permissions';
import { useDeleteAttachment } from '@/hooks/api/use-cases';
import { useAuth } from '@/hooks/use-auth';
import { caseService } from '@/services/caseService';
import { errorMessage } from '@/services/api/errors';
import type { CaseAttachment } from '@48hrs/shared/types';
import { downloadBlob } from '@/utils/download';
import { formatBytes, formatDateTime } from '@/utils/format';
import { FileDropzone } from '@/components/files/file-dropzone';
import { FilePreviewDialog, type PreviewTarget } from '@/components/files/file-preview-dialog';
import { FileTypeTag, UploadQueueList } from '@/components/files/upload-queue-list';
import { useUploadQueue } from '@/components/files/use-upload-queue';
import { Button } from '@/components/ui/button';
import { useConfirmedDelete } from '@/components/ui/use-confirmed-delete';
import { Alert } from '@/components/ui/feedback';

export function CaseAttachments({ caseId, attachments }: { caseId: string; attachments: CaseAttachment[] }) {
  const { can, user } = useAuth();
  const queue = useUploadQueue(caseId);
  const del = useDeleteAttachment(caseId);
  const [preview, setPreview] = useState<PreviewTarget | null>(null);
  const { request: requestDelete, element: deleteDialog } = useConfirmedDelete<CaseAttachment>({
    title: 'Delete file?',
    confirmLabel: 'Delete file',
    describe: (a) => <>“{a.name}” will be removed from this case. The removal is written to the activity log.</>,
    remove: (a) => del.mutateAsync(a.id),
    successMessage: () => 'File deleted',
  });

  if (!can(PERMISSIONS.FILES_VIEW)) {
    return <Alert tone="warning">Patient files are restricted for your role. A Super Admin must grant the “View case files” permission.</Alert>;
  }

  const fetchBlob = async (a: CaseAttachment) => {
    try {
      return await caseService.downloadAttachment(caseId, a.id);
    } catch (err) {
      toast.error(errorMessage(err));
      return null;
    }
  };

  const download = async (a: CaseAttachment) => {
    const blob = await fetchBlob(a);
    if (blob) downloadBlob(blob, a.name);
  };

  const openPreview = async (a: CaseAttachment) => {
    setPreview({ name: a.name, url: null, kind: a.extension === 'pdf' ? 'pdf' : 'image', loading: true });
    const blob = await fetchBlob(a);
    if (!blob) return setPreview(null);
    const url = URL.createObjectURL(blob);
    setPreview({ name: a.name, url, kind: a.extension === 'pdf' ? 'pdf' : 'image', onDownload: () => downloadBlob(blob, a.name) });
  };

  const closePreview = () => {
    if (preview?.url) URL.revokeObjectURL(preview.url);
    setPreview(null);
  };

  const canDelete = (a: CaseAttachment) => can(PERMISSIONS.FILES_DELETE) || (a.uploadedById === user?.id && can(PERMISSIONS.FILES_UPLOAD));

  return (
    <div className="flex flex-col gap-3">
      {attachments.length === 0 && queue.items.length === 0 && <p className="text-[13px] text-ink-3">No files attached to this case.</p>}
      <ul className="flex flex-col gap-2.5">
        {attachments.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center gap-3 rounded-md border border-line px-3.5 py-3">
            <FileTypeTag name={a.name} />
            <div className="flex min-w-0 flex-1 basis-48 flex-col gap-0.5">
              <span className="text-[13.5px] font-bold break-all">{a.name}</span>
              <span className="font-mono text-xs text-ink-2">{ATTACHMENT_CATEGORY_LABELS[a.category]} · {formatBytes(a.size)}</span>
              <span className="text-xs text-ink-3">Uploaded by {a.uploadedByName} · {formatDateTime(a.createdAt)}</span>
            </div>
            <div className="flex gap-1.5">
              {(PREVIEWABLE_EXTENSIONS as readonly string[]).includes(a.extension) && (
                <Button variant="outline" size="sm" onClick={() => void openPreview(a)} aria-label={`Preview ${a.name}`}><Eye /> <span className="hidden sm:inline">Preview</span></Button>
              )}
              <Button variant="outline" size="sm" onClick={() => void download(a)} aria-label={`Download ${a.name}`}><Download /> <span className="hidden sm:inline">Download</span></Button>
              {canDelete(a) && (
                <Button variant="ghost" size="icon-sm" onClick={() => requestDelete(a)} aria-label={`Delete ${a.name}`}><Trash2 className="text-danger" /></Button>
              )}
            </div>
          </li>
        ))}
      </ul>
      <UploadQueueList items={queue.items.filter((i) => i.status !== 'done')} onRemove={queue.remove} onRetry={queue.retry} onCategory={queue.setCategory} onPreview={(f) => setPreview({ name: f.file.name, url: f.previewUrl, kind: f.file.name.endsWith('.pdf') ? 'pdf' : 'image' })} />
      {can(PERMISSIONS.FILES_UPLOAD) && <FileDropzone onFiles={queue.add} compact title="Add files to this case" />}

      <FilePreviewDialog target={preview} onClose={closePreview} />
      {deleteDialog}
    </div>
  );
}
