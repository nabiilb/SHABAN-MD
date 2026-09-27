import { CheckCircle2, Eye, RotateCcw, Trash2 } from 'lucide-react';
import { ATTACHMENT_CATEGORY_LABELS, extensionOf } from '@/lib/constants';
import type { AttachmentCategory } from '@/types/models';
import { formatBytes } from '@/utils/format';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/input';
import { ProgressBar } from '@/components/ui/feedback';
import type { QueuedFile } from './use-upload-queue';

export function FileTypeTag({ name }: { name: string }) {
  return (
    <span className="flex h-7 min-w-11 shrink-0 items-center justify-center rounded-md bg-navy-50 px-2 font-mono text-[11px] font-extrabold tracking-[.06em] text-brand uppercase">
      {extensionOf(name) || 'FILE'}
    </span>
  );
}

export function UploadQueueList({ items, onRemove, onRetry, onCategory, onPreview }: { items: QueuedFile[]; onRemove: (id: string) => void; onRetry: (id: string) => void; onCategory: (id: string, c: AttachmentCategory) => void; onPreview: (item: QueuedFile) => void }) {
  if (!items.length) return null;
  return (
    <ul className="flex flex-col gap-2.5" aria-label="Files to upload">
      {items.map((f) => (
        <li key={f.localId} className="flex flex-col gap-2.5 rounded-md border border-line bg-card px-3.5 py-3">
          <div className="flex flex-wrap items-start gap-3">
            <FileTypeTag name={f.file.name} />
            <div className="flex min-w-0 flex-1 basis-44 flex-col gap-0.5">
              <span className="text-[13.5px] font-bold break-all text-ink">{f.file.name}</span>
              <span className="font-mono text-xs text-ink-2">{formatBytes(f.file.size)}</span>
            </div>
            <label className="w-40">
              <span className="sr-only">File category</span>
              <NativeSelect value={f.category} onChange={(e) => onCategory(f.localId, e.target.value as AttachmentCategory)} className="h-8 text-[12.5px]" disabled={f.status === 'uploading' || f.status === 'done'}>
                {Object.entries(ATTACHMENT_CATEGORY_LABELS).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </NativeSelect>
            </label>
          </div>

          {f.status === 'uploading' && (
            <div className="flex flex-col gap-1">
              <ProgressBar value={f.progress / 100} label={`Uploading ${f.file.name}`} />
              <span className="font-mono text-[11.5px] text-ink-2">
                Uploading {f.progress}% · {formatBytes(Math.round((f.file.size * f.progress) / 100))} / {formatBytes(f.file.size)}
              </span>
            </div>
          )}

          <div className="flex flex-wrap items-center gap-2">
            {f.status === 'queued' && <span className="text-xs font-semibold text-ink-3">Ready — uploads when the case is saved</span>}
            {f.status === 'done' && (
              <span className="inline-flex items-center gap-1 text-xs font-bold text-success">
                <CheckCircle2 className="size-3.5" /> Uploaded
              </span>
            )}
            {f.status === 'failed' && <span className="rounded-md bg-danger-bg px-2 py-1 text-xs font-bold text-danger">Upload failed — {f.error}</span>}
            <div className="ml-auto flex gap-2">
              {f.previewUrl && (
                <Button variant="outline" size="sm" onClick={() => onPreview(f)}>
                  <Eye /> Preview
                </Button>
              )}
              {f.status === 'failed' && !f.error?.includes('not accepted') && !f.error?.includes('limit') && (
                <Button variant="outline" size="sm" onClick={() => onRetry(f.localId)}>
                  <RotateCcw /> Retry
                </Button>
              )}
              {f.status !== 'done' && (
                <Button variant="outline" size="sm" onClick={() => onRemove(f.localId)} aria-label={`Remove ${f.file.name}`}>
                  <Trash2 /> Remove
                </Button>
              )}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}
