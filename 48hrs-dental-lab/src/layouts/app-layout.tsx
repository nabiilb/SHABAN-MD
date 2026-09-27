import { Suspense, useEffect } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Dialog, SheetContent } from '@/components/ui/dialog';
import { PageLoader } from '@/components/ui/feedback';
import { GlobalSearch } from '@/components/layout/global-search';
import { Header } from '@/components/layout/header';
import { Sidebar } from '@/components/layout/sidebar';
import { useSettings } from '@/hooks/api/use-admin';
import { useAuthStore } from '@/stores/auth-store';
import { useUiStore } from '@/stores/ui-store';
import { RouteErrorBoundary } from '@/routes/error-boundary';

export function AppLayout() {
  const mobileNavOpen = useUiStore((s) => s.mobileNavOpen);
  const setMobileNav = useUiStore((s) => s.setMobileNav);
  const refresh = useAuthStore((s) => s.refresh);
  const location = useLocation();
  useSettings();

  // Close the drawer on navigation; re-validate the session when the tab regains focus.
  useEffect(() => setMobileNav(false), [location.pathname, setMobileNav]);
  useEffect(() => {
    const onFocus = () => void refresh();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [refresh]);

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
      <a href="#main" className="sr-only z-50 rounded-md bg-card px-3 py-2 focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
        Skip to content
      </a>
      <aside className="no-print sticky top-0 hidden h-dvh lg:block" aria-label="Sidebar">
        <Sidebar />
      </aside>

      <Dialog open={mobileNavOpen} onOpenChange={setMobileNav}>
        <SheetContent label="Navigation">
          <Sidebar onNavigate={() => setMobileNav(false)} />
        </SheetContent>
      </Dialog>

      <div className="flex min-w-0 flex-col">
        <Header />
        <main id="main" className="mx-auto w-full max-w-[1400px] flex-1 px-3 py-4 sm:px-5 sm:py-6 lg:px-7" tabIndex={-1}>
          <RouteErrorBoundary key={location.pathname}>
            <Suspense fallback={<PageLoader />}>
              <Outlet />
            </Suspense>
          </RouteErrorBoundary>
        </main>
      </div>
      <GlobalSearch />
    </div>
  );
}
