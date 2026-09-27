import { useCallback, useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { categoryForExtension, extensionOf, validateFile } from '@/lib/constants';
import { qk } from '@/lib/query-keys';
import { caseService } from '@/services/caseService';
import { errorMessage } from '@/services/api/errors';
import type { AttachmentCategory } from '@/types/models';

export interface QueuedFile {
  localId: string;
  file: File;
  category: AttachmentCategory;
  status: 'queued' | 'uploading' | 'done' | 'failed';
  progress: number;
  error: string | null;
  previewUrl: string | null;
}

let seq = 0;

/**
 * Upload queue. With a caseId, files upload immediately; without one (new
 * case form) they wait until uploadAll(caseId) is called after the case exists.
 */
export function useUploadQueue(caseId?: string) {
  const [items, setItems] = useState<QueuedFile[]>([]);
  const qc = useQueryClient();
  const controllers = useRef(new Map<string, AbortController>());
  const itemsRef = useRef(items);
  itemsRef.current = items;

  useEffect(
    () => () => {
      itemsRef.current.forEach((i) => i.previewUrl && URL.revokeObjectURL(i.previewUrl));
      controllers.current.forEach((c) => c.abort());
    },
    [],
  );

  const patch = (localId: string, p: Partial<QueuedFile>) => setItems((list) => list.map((i) => (i.localId === localId ? { ...i, ...p } : i)));

  const upload = useCallback(
    async (item: QueuedFile, targetCaseId: string) => {
      const ctrl = new AbortController();
      controllers.current.set(item.localId, ctrl);
      patch(item.localId, { status: 'uploading', progress: 0, error: null });
      try {
        await caseService.uploadAttachment(targetCaseId, { file: item.file, category: item.category }, (p) => patch(item.localId, { progress: p.percent }), ctrl.signal);
        patch(item.localId, { status: 'done', progress: 100 });
        return true;
      } catch (err) {
        patch(item.localId, { status: 'failed', error: errorMessage(err) });
        return false;
      } finally {
        controllers.current.delete(item.localId);
      }
    },
    [],
  );

  const refreshCase = useCallback(
    (id: string) => {
      void qc.invalidateQueries({ queryKey: qk.cases.detail(id) });
      void qc.invalidateQueries({ queryKey: qk.cases.all });
    },
    [qc],
  );

  const add = useCallback(
    (files: File[]) => {
      const next: QueuedFile[] = files.map((file) => {
        const error = validateFile(file);
        const ext = extensionOf(file.name);
        return {
          localId: `f${++seq}`,
          file,
          category: categoryForExtension(ext),
          status: error ? 'failed' : 'queued',
          progress: 0,
          error,
          previewUrl: !error && ['jpg', 'jpeg', 'png', 'webp', 'pdf'].includes(ext) ? URL.createObjectURL(file) : null,
        };
      });
      setItems((list) => [...list, ...next]);
      if (caseId) {
        void Promise.all(next.filter((n) => n.status === 'queued').map((n) => upload(n, caseId))).then(() => refreshCase(caseId));
      }
    },
    [caseId, upload, refreshCase],
  );

  const remove = useCallback((localId: string) => {
    controllers.current.get(localId)?.abort();
    setItems((list) => {
      const found = list.find((i) => i.localId === localId);
      if (found?.previewUrl) URL.revokeObjectURL(found.previewUrl);
      return list.filter((i) => i.localId !== localId);
    });
  }, []);

  const setCategory = useCallback((localId: string, category: AttachmentCategory) => patch(localId, { category }), []);

  const retry = useCallback(
    (localId: string) => {
      const item = itemsRef.current.find((i) => i.localId === localId);
      if (!item || validateFile(item.file) || !caseId) {
        if (item && !caseId) patch(localId, { status: 'queued', error: null });
        return;
      }
      void upload(item, caseId).then(() => refreshCase(caseId));
    },
    [caseId, upload, refreshCase],
  );

  /** Uploads every queued file to a freshly created case. Returns the number that failed. */
  const uploadAll = useCallback(
    async (targetCaseId: string) => {
      let failed = 0;
      for (const item of itemsRef.current.filter((i) => i.status === 'queued')) {
        if (!(await upload(item, targetCaseId))) failed++;
      }
      refreshCase(targetCaseId);
      return failed;
    },
    [upload, refreshCase],
  );

  const clearDone = useCallback(() => setItems((list) => list.filter((i) => i.status !== 'done')), []);

  return {
    items,
    add,
    remove,
    retry,
    setCategory,
    uploadAll,
    clearDone,
    busy: items.some((i) => i.status === 'uploading'),
    pending: items.filter((i) => i.status === 'queued').length,
    invalid: items.filter((i) => i.status === 'failed').length,
  };
}
