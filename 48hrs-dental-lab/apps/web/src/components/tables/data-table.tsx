import { useMemo, useState, type ReactNode } from 'react';
import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type RowData,
  type RowSelectionState,
  type VisibilityState,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3 } from 'lucide-react';
import { cn } from '@/lib/cn';
import type { PageMeta, SortDir } from '@48hrs/shared/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState, Skeleton } from '@/components/ui/feedback';
import { QueryError } from '@/components/ui/query-error';
import { Menu, MenuCheckboxItem, MenuContent, MenuLabel, MenuTrigger } from '@/components/ui/menu';
import { Pagination } from './pagination';

declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends RowData, TValue> {
    /** Server sort key; the header becomes a sort button when set. */
    sortKey?: string;
    label?: string;
    align?: 'left' | 'right' | 'center';
    headerClassName?: string;
    cellClassName?: string;
    /** Hidden until enabled from the column menu. */
    defaultHidden?: boolean;
  }
}

export interface SortState {
  key?: string;
  dir?: SortDir;
}

interface DataTableProps<T> {
  columns: ColumnDef<T, unknown>[];
  data: T[] | undefined;
  meta?: PageMeta;
  getRowId: (row: T) => string;
  isLoading?: boolean;
  isFetching?: boolean;
  error?: unknown;
  onRetry?: () => void;
  sort?: SortState;
  onSortChange?: (s: SortState) => void;
  onPageChange?: (page: number) => void;
  onPerPageChange?: (n: number) => void;
  onRowClick?: (row: T) => void;
  /** Enables checkboxes and renders bulkActions for the selected rows. */
  bulkActions?: (selected: T[], clear: () => void) => ReactNode;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  toolbar?: ReactNode;
  enableColumnToggle?: boolean;
  /** Card layout for narrow screens; falls back to a horizontally scrolling table. */
  renderMobileCard?: (row: T) => ReactNode;
  caption?: string;
  className?: string;
}

export function DataTable<T>({
  columns,
  data,
  meta,
  getRowId,
  isLoading,
  isFetching,
  error,
  onRetry,
  sort,
  onSortChange,
  onPageChange,
  onPerPageChange,
  onRowClick,
  bulkActions,
  emptyTitle = 'Nothing to show',
  emptyDescription,
  emptyAction,
  toolbar,
  enableColumnToggle,
  renderMobileCard,
  caption,
  className,
}: DataTableProps<T>) {
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(() =>
    Object.fromEntries(columns.filter((c) => c.meta?.defaultHidden && c.id).map((c) => [c.id!, false])),
  );

  const allColumns = useMemo<ColumnDef<T, unknown>[]>(() => {
    if (!bulkActions) return columns;
    const select: ColumnDef<T, unknown> = {
      id: '__select',
      enableHiding: false,
      header: ({ table }) => (
        <Checkbox
          aria-label="Select all rows on this page"
          checked={table.getIsAllPageRowsSelected() ? true : table.getIsSomePageRowsSelected() ? 'indeterminate' : false}
          onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)}
        />
      ),
      cell: ({ row }) => (
        <Checkbox aria-label="Select row" checked={row.getIsSelected()} onCheckedChange={(v) => row.toggleSelected(!!v)} onClick={(e) => e.stopPropagation()} />
      ),
      meta: { headerClassName: 'w-10', cellClassName: 'w-10' },
    };
    return [select, ...columns];
  }, [columns, bulkActions]);

  const table = useReactTable({
    data: data ?? [],
    columns: allColumns,
    getRowId,
    state: { rowSelection, columnVisibility },
    onRowSelectionChange: setRowSelection,
    onColumnVisibilityChange: setColumnVisibility,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualSorting: true,
    enableRowSelection: !!bulkActions,
  });

  const selectedRows = table.getSelectedRowModel().rows.map((r) => r.original);
  const clearSelection = () => setRowSelection({});
  const rows = table.getRowModel().rows;
  const visibleCount = table.getVisibleLeafColumns().length;
  const hideable = table.getAllLeafColumns().filter((c) => c.getCanHide() && c.id !== '__select' && c.columnDef.meta?.label);

  const toggleSort = (key: string) => {
    if (!onSortChange) return;
    if (sort?.key !== key) onSortChange({ key, dir: 'asc' });
    else if (sort.dir === 'asc') onSortChange({ key, dir: 'desc' });
    else onSortChange({ key: undefined, dir: undefined });
  };

  const showToolbar = toolbar || enableColumnToggle;

  return (
    <div className={cn('overflow-hidden rounded-md border border-line bg-card shadow-sm', className)}>
      {showToolbar && (
        <div className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-3 sm:px-4">
          <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">{toolbar}</div>
          {enableColumnToggle && hideable.length > 0 && (
            <Menu>
              <MenuTrigger asChild>
                <Button variant="outline" size="sm" className="hidden md:inline-flex">
                  <Columns3 /> Columns
                </Button>
              </MenuTrigger>
              <MenuContent>
                <MenuLabel>Visible columns</MenuLabel>
                {hideable.map((c) => (
                  <MenuCheckboxItem key={c.id} checked={c.getIsVisible()} onCheckedChange={(v) => c.toggleVisibility(v)}>
                    {c.columnDef.meta?.label}
                  </MenuCheckboxItem>
                ))}
              </MenuContent>
            </Menu>
          )}
        </div>
      )}

      {bulkActions && selectedRows.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 border-b border-navy-100 bg-navy-50 px-4 py-2.5 text-[13px]" role="region" aria-label="Bulk actions">
          <span className="font-semibold text-brand">{selectedRows.length} selected</span>
          <div className="flex flex-wrap gap-2">{bulkActions(selectedRows, clearSelection)}</div>
          <Button variant="link" size="sm" onClick={clearSelection} className="ml-auto">
            Clear selection
          </Button>
        </div>
      )}

      <div className="relative">
        {isFetching && !isLoading && <div className="absolute inset-x-0 top-0 z-10 h-0.5 animate-pulse bg-navy-400" aria-hidden />}

        {error ? (
          <div className="p-4">
            <QueryError error={error} onRetry={onRetry} />
          </div>
        ) : !isLoading && rows.length === 0 ? (
          <div className="p-4">
            <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
          </div>
        ) : (
          <>
            {renderMobileCard && (
              <ul className="divide-y divide-line md:hidden" aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: 5 }, (_, i) => (
                      <li key={i} className="p-4">
                        <Skeleton className="mb-2 h-4 w-1/3" />
                        <Skeleton className="h-4 w-2/3" />
                      </li>
                    ))
                  : rows.map((row) => (
                      <li key={row.id}>
                        {onRowClick ? (
                          <button type="button" className="block w-full p-4 text-left hover:bg-gray-50" onClick={() => onRowClick(row.original)}>
                            {renderMobileCard(row.original)}
                          </button>
                        ) : (
                          <div className="p-4">{renderMobileCard(row.original)}</div>
                        )}
                      </li>
                    ))}
              </ul>
            )}
            <div className={cn('overflow-x-auto', renderMobileCard && 'hidden md:block')} tabIndex={0} role="region" aria-label={caption ? `${caption} table` : 'Table'}>
              <table className="w-full min-w-[640px] border-collapse text-sm" aria-busy={isLoading}>
                {caption && <caption className="sr-only">{caption}</caption>}
                <thead>
                  {table.getHeaderGroups().map((hg) => (
                    <tr key={hg.id} className="bg-gray-50">
                      {hg.headers.map((h) => {
                        const m = h.column.columnDef.meta;
                        const sortKey = m?.sortKey;
                        const active = sortKey && sort?.key === sortKey;
                        const content = h.isPlaceholder ? null : flexRender(h.column.columnDef.header, h.getContext());
                        return (
                          <th
                            key={h.id}
                            scope="col"
                            aria-sort={active ? (sort?.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                            className={cn(
                              'px-4 py-3 text-left text-[11px] font-bold tracking-[.12em] whitespace-nowrap text-ink-3 uppercase',
                              m?.align === 'right' && 'text-right',
                              m?.align === 'center' && 'text-center',
                              m?.headerClassName,
                            )}
                          >
                            {sortKey && onSortChange ? (
                              <button type="button" onClick={() => toggleSort(sortKey)} className={cn('inline-flex items-center gap-1 uppercase hover:text-ink', active && 'text-ink')}>
                                {content}
                                {active ? sort?.dir === 'asc' ? <ArrowUp className="size-3.5" /> : <ArrowDown className="size-3.5" /> : <ArrowUpDown className="size-3.5 opacity-40" />}
                              </button>
                            ) : (
                              content
                            )}
                          </th>
                        );
                      })}
                    </tr>
                  ))}
                </thead>
                <tbody>
                  {isLoading
                    ? Array.from({ length: 6 }, (_, i) => (
                        <tr key={i} className="border-t border-line">
                          {Array.from({ length: visibleCount }, (_, j) => (
                            <td key={j} className="px-4 py-3.5">
                              <Skeleton className="h-4 w-full max-w-[140px]" />
                            </td>
                          ))}
                        </tr>
                      ))
                    : rows.map((row) => (
                        <tr
                          key={row.id}
                          onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                          className={cn('border-t border-line transition-colors', onRowClick && 'cursor-pointer hover:bg-navy-50/60', row.getIsSelected() && 'bg-navy-50')}
                        >
                          {row.getVisibleCells().map((cell) => {
                            const m = cell.column.columnDef.meta;
                            return (
                              <td
                                key={cell.id}
                                className={cn('px-4 py-3 align-middle', m?.align === 'right' && 'text-right', m?.align === 'center' && 'text-center', m?.cellClassName)}
                                onClick={cell.column.id === '__select' || cell.column.id === 'actions' ? (e) => e.stopPropagation() : undefined}
                              >
                                {flexRender(cell.column.columnDef.cell, cell.getContext())}
                              </td>
                            );
                          })}
                        </tr>
                      ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>

      {meta && meta.total > 0 && onPageChange && !error && <Pagination meta={meta} onPageChange={onPageChange} onPerPageChange={onPerPageChange} />}
    </div>
  );
}
