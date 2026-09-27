import { Link, useLocation } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';
import { NAV } from '@/routes/nav';
import { useAuth } from '@/hooks/use-auth';
import { useUiStore } from '@/stores/ui-store';

const NAV_ITEMS = NAV.flatMap((g) => g.items);
const SECTION_LABELS: Record<string, string> = Object.fromEntries(NAV_ITEMS.map((i) => [i.to, i.label]));

export interface Crumb {
  label: string;
  to?: string;
}

/** Pure mapping from a pathname (+ current page title) to breadcrumb items. */
export function buildCrumbs(pathname: string, pageTitle: string, canOpen: (path: string) => boolean = () => true): Crumb[] {
  const parts = pathname.split('/').filter(Boolean);
  if (!parts.length || pathname === '/dashboard') return [];
  const crumbs: Crumb[] = [{ label: 'Dashboard', to: '/dashboard' }];
  const section = `/${parts[0]}`;
  const sectionLabel = SECTION_LABELS[section];
  if (!sectionLabel) return [];
  if (parts.length === 1) {
    crumbs.push({ label: sectionLabel });
    return crumbs;
  }
  crumbs.push({ label: sectionLabel, to: canOpen(section) ? section : undefined });
  const full = `/${parts.join('/')}`;
  crumbs.push({ label: SECTION_LABELS[full] ?? (pageTitle || 'Details') });
  return crumbs;
}

export function Breadcrumbs() {
  const { pathname } = useLocation();
  const title = useUiStore((s) => s.pageTitle);
  const { can } = useAuth();
  const crumbs = buildCrumbs(pathname, title, (path) => {
    const item = NAV_ITEMS.find((i) => i.to === path);
    return !item || item.permissions.length === 0 || can(item.permissions, 'any');
  });
  if (crumbs.length < 2) return null;
  return (
    <nav aria-label="Breadcrumb" className="no-print mb-4">
      <ol className="flex flex-wrap items-center gap-1 text-[12.5px]">
        {crumbs.map((c, i) => (
          <li key={`${c.label}-${i}`} className="flex items-center gap-1">
            {i > 0 && <ChevronRight className="size-3.5 text-ink-3" aria-hidden />}
            {c.to && i < crumbs.length - 1 ? (
              <Link to={c.to} className="font-semibold text-ink-2 hover:text-brand hover:underline">
                {c.label}
              </Link>
            ) : (
              <span aria-current="page" className="max-w-[40ch] truncate font-semibold text-ink">
                {c.label}
              </span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
