import { useRef, useState } from 'react';
import { UploadCloud } from 'lucide-react';
import { cn } from '@/lib/cn';
import { ALLOWED_FILE_EXTENSIONS, MAX_FILE_MB } from '@48hrs/shared/constants';

export function FileDropzone({ onFiles, disabled, compact, title = 'Drag & drop files here' }: { onFiles: (files: File[]) => void; disabled?: boolean; compact?: boolean; title?: string }) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);
  const accept = ALLOWED_FILE_EXTENSIONS.map((e) => `.${e}`).join(',');

  return (
    <div
      onDragOver={(e) => {
        e.preventDefault();
        if (!disabled) setOver(true);
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setOver(false);
        if (!disabled && e.dataTransfer.files.length) onFiles(Array.from(e.dataTransfer.files));
      }}
      className={cn(
        'flex flex-col items-center gap-1.5 rounded-md border-[1.5px] border-dashed text-center transition-colors',
        compact ? 'px-4 py-4' : 'px-5 py-8',
        over ? 'border-brand bg-navy-50' : 'border-line-strong bg-gray-50',
        disabled && 'opacity-60',
      )}
    >
      <span className="flex size-9 items-center justify-center rounded-full bg-navy-50 text-brand">
        <UploadCloud className="size-[18px]" aria-hidden />
      </span>
      <p className="text-[14.5px] font-bold text-ink">{title}</p>
      <p className="text-[13px] text-ink-2">
        or{' '}
        <button type="button" disabled={disabled} onClick={() => inputRef.current?.click()} className="font-semibold text-navy-500 underline-offset-2 hover:underline">
          browse your device
        </button>
      </p>
      <p className="font-mono text-[11.5px] tracking-[.04em] text-ink-3">JPG · PNG · PDF · STL · DICOM — max {MAX_FILE_MB} MB per file</p>
      <input
        ref={inputRef}
        type="file"
        multiple
        accept={accept}
        className="sr-only"
        tabIndex={-1}
        aria-hidden
        onChange={(e) => {
          if (e.target.files?.length) onFiles(Array.from(e.target.files));
          e.target.value = '';
        }}
      />
    </div>
  );
}
