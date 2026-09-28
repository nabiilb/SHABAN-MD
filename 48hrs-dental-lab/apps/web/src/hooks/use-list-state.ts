import { useMemo } from 'react';
import type { SortDir } from '@48hrs/shared/types';
import { useUrlState } from './use-url-state';

type Defaults = Record<string, string | undefined> & { sort: string; dir: string; page: string; perPage: string; search: string };

/**
 * URL-backed list state (filters, search, sort, page) for server-driven
 * tables. Returns the query params for the service call and the props that
 * wire a DataTable's sorting and pagination, so pages never repeat that code.
 */
export function useListState<T extends Defaults>(defaults: T) {
  const [state, set, reset] = useUrlState(defaults);

  const listParams = useMemo(
    () => ({ search: state.search || undefined, sort: state.sort, dir: state.dir as SortDir, page: Number(state.page), perPage: Number(state.perPage) }),
    [state.search, state.sort, state.dir, state.page, state.perPage],
  );

  const tableProps = {
    sort: { key: state.sort, dir: state.dir as SortDir },
    onSortChange: (s: { key?: string; dir?: SortDir }) => set({ sort: s.key ?? defaults.sort, dir: s.dir ?? defaults.dir } as Partial<T>),
    onPageChange: (p: number) => set({ page: String(p) } as Partial<T>, { resetPage: false }),
    onPerPageChange: (n: number) => set({ perPage: String(n) } as Partial<T>),
  };

  const filterKeys = Object.keys(defaults).filter((k) => !['sort', 'dir', 'page', 'perPage'].includes(k));
  const filtersActive = filterKeys.some((k) => state[k] !== defaults[k]);

  return { state, set, reset, listParams, tableProps, filtersActive };
}
