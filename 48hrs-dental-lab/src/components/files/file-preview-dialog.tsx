import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { PageLoader } from '@/components/ui/feedback';

export interface PreviewTarget {
  name: string;
  url: string | null;
  kind: 'image' | 'pdf' | 'other';
  loading?: boolean;
  onDownload?: () => void;
}

export function FilePreviewDialog({ target, onClose }: { target: PreviewTarget | null; onClose: () => void }) {
  return (
    <Dialog open={!!target} onOpenChange={(o) => !o && onClose()}>
      {target && (
        <DialogContent
          size="xl"
          title={target.name}
          footer={
            target.onDownload && (
              <Button variant="outline" onClick={target.onDownload}>
                <Download /> Download
              </Button>
            )
          }
        >
          <div className="flex min-h-[40vh] items-center justify-center rounded-md bg-gray-50 p-2">
            {target.loading || !target.url ? (
              <PageLoader label="Loading preview…" />
            ) : target.kind === 'pdf' ? (
              <iframe src={target.url} title={target.name} className="h-[70vh] w-full rounded-md border-0 bg-white" />
            ) : target.kind === 'image' ? (
              <img src={target.url} alt={target.name} className="max-h-[70vh] max-w-full rounded-md" />
            ) : (
              <p className="text-sm text-ink-3">No preview for this file type. Download it to open in your CAD software.</p>
            )}
          </div>
        </DialogContent>
      )}
    </Dialog>
  );
}
