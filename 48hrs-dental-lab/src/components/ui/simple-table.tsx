import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface SimpleColumn<T> {
  header: ReactNode;
  cell: (row: T) => ReactNode;
  align?: 'left' | 'right';
  className?: string;
}

/**
 * Static, read-only table for small in-card datasets (invoice lines, report
 * breakdowns, price list). Server-driven lists use DataTable instead.
 */
export function SimpleTable<T>({ columns, rows, getKey, empty = 'Nothing to show.', caption, minWidth = 520, footer }: { columns: SimpleColumn<T>[]; rows: T[]; getKey: (row: T, i: number) => string; empty?: ReactNode; caption?: string; minWidth?: number; footer?: ReactNode }) {
  if (!rows.length) return <p className="text-[13px] text-ink-3">{empty}</p>;
  return (
    <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={caption ?? 'Table'}>
      <table className="w-full text-sm" style={{ minWidth }}>
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr className="border-b border-line">
            {columns.map((c, i) => (
              <th key={i} scope="col" className={cn('py-2.5 pr-4 text-[11px] font-bold tracking-[.12em] whitespace-nowrap text-ink-3 uppercase last:pr-0', c.align === 'right' ? 'text-right' : 'text-left')}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, r) => (
            <tr key={getKey(row, r)} className="border-b border-line last:border-0">
              {columns.map((c, i) => (
                <td key={i} className={cn('py-2.5 pr-4 align-middle last:pr-0', c.align === 'right' && 'text-right', c.className)}>
                  {c.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {footer && <tfoot>{footer}</tfoot>}
      </table>
    </div>
  );
}
