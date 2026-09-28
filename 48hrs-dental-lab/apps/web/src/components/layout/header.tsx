import { Link, useNavigate } from 'react-router-dom';
import { LogOut, Menu as MenuIcon, UserCog } from 'lucide-react';
import logo from '@/assets/logo-48hrs.png';
import { ROLE_LABELS, PERMISSIONS } from '@48hrs/shared/permissions';
import { useAuth } from '@/hooks/use-auth';
import { useNow } from '@/hooks/use-now';
import { useUiStore } from '@/stores/ui-store';
import { formatTime } from '@/utils/format';
import { Avatar } from '@/components/ui/avatar';
import { Menu, MenuContent, MenuItem, MenuLabel, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import { NotificationBell } from '@/components/notifications/notification-bell';
import { SearchTrigger } from './global-search';

function LabClock() {
  const now = useNow(15_000);
  return (
    <div className="hidden flex-col items-end gap-px md:flex" aria-label="Lab time">
      <span className="font-mono text-sm font-bold tabular">{formatTime(new Date(now))}</span>
      <span className="text-[11px] font-semibold tracking-[.1em] text-ink-3">LAB TIME</span>
    </div>
  );
}

export function Header() {
  const { user, can } = useAuth();
  const title = useUiStore((s) => s.pageTitle);
  const subtitle = useUiStore((s) => s.pageSubtitle);
  const setMobileNav = useUiStore((s) => s.setMobileNav);
  const navigate = useNavigate();

  return (
    <header className="no-print sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-line bg-card px-3 py-3 sm:px-5 lg:px-7">
      <div className="flex min-w-0 items-center gap-3">
        <button type="button" onClick={() => setMobileNav(true)} className="flex size-10 shrink-0 items-center justify-center rounded-md border border-line text-brand hover:bg-navy-50 lg:hidden" aria-label="Open navigation">
          <MenuIcon className="size-5" />
        </button>
        <Link to="/dashboard" className="hidden shrink-0 sm:block" aria-label="48HRS Dental Lab — dashboard">
          <img src={logo} alt="" className="h-11 w-auto" />
        </Link>
        <div className="hidden h-8 w-px bg-line sm:block" aria-hidden />
        <div className="flex min-w-0 flex-col">
          <h1 className="truncate text-[17px] font-bold tracking-[-.01em] text-ink">{title}</h1>
          {subtitle && <p className="hidden truncate text-[12.5px] text-ink-2 sm:block">{subtitle}</p>}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-2 sm:gap-2.5">
        <SearchTrigger />
        <LabClock />
        <NotificationBell />
        {user && (
          <Menu>
            <MenuTrigger asChild>
              <button type="button" className="rounded-full focus-visible:outline-2" aria-label={`Account menu for ${user.name}`}>
                <Avatar name={user.name} />
              </button>
            </MenuTrigger>
            <MenuContent>
              <MenuLabel>
                <span className="block text-[13px] font-bold tracking-normal text-ink normal-case">{user.name}</span>
                <span className="block text-xs font-medium tracking-normal text-ink-3 normal-case">{user.email} · {ROLE_LABELS[user.role]}</span>
              </MenuLabel>
              <MenuSeparator />
              {user.technicianId && (
                <MenuItem onSelect={() => navigate(`/technicians/${user.technicianId}`)}>
                  <UserCog /> My performance
                </MenuItem>
              )}
              {can(PERMISSIONS.SETTINGS_VIEW) && (
                <MenuItem onSelect={() => navigate('/settings')}>
                  <UserCog /> Settings
                </MenuItem>
              )}
              <MenuItem onSelect={() => navigate('/logout')} tone="danger">
                <LogOut /> Sign out
              </MenuItem>
            </MenuContent>
          </Menu>
        )}
      </div>
    </header>
  );
}
