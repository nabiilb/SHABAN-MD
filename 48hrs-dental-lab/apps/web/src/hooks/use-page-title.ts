import { useEffect } from 'react';
import { useUiStore } from '@/stores/ui-store';

/** Sets the header title/subtitle (as in the prototype top bar) and the document title. */
export function usePageTitle(title: string, subtitle?: string) {
  const setPage = useUiStore((s) => s.setPage);
  useEffect(() => {
    setPage(title, subtitle);
    document.title = title ? `${title} · 48HRS Dental Lab` : '48HRS Dental Lab';
  }, [title, subtitle, setPage]);
}
