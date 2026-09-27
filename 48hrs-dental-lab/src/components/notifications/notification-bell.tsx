import { Bell } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotifications } from '@/hooks/api/use-notifications';
import type { AppNotification } from '@/types/models';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/feedback';
import { useState } from 'react';
import { NotificationItem } from './notification-item';

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const { data, isLoading } = useNotifications({ perPage: 8 }, 45_000);
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();
  const navigate = useNavigate();
  const unread = data?.unreadCount ?? 0;

  const openItem = (n: AppNotification) => {
    if (!n.readAt) markRead.mutate(n.id);
    setOpen(false);
    if (n.caseId) navigate(`/cases/${n.caseId}`);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button type="button" className="relative flex size-10 items-center justify-center rounded-full border border-line bg-card text-brand hover:bg-navy-50" aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}>
          <Bell className="size-[19px]" aria-hidden />
          {unread > 0 && (
            <span className="absolute -top-1 -right-1 flex h-[19px] min-w-[19px] items-center justify-center rounded-full bg-danger px-1 text-[11px] font-bold text-white" aria-hidden>
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-[min(380px,calc(100vw-24px))] p-0">
        <div className="flex items-center justify-between px-3 py-2.5">
          <span className="text-[13px] font-bold">Notifications</span>
          <Button variant="link" size="sm" className="text-xs" onClick={() => markAll.mutate()} disabled={!unread || markAll.isPending}>
            Mark all read
          </Button>
        </div>
        <div className="max-h-[400px] overflow-y-auto border-t border-line">
          {isLoading ? (
            <div className="flex justify-center py-6"><Spinner /></div>
          ) : !data?.data.length ? (
            <p className="px-3 py-6 text-center text-[13px] text-ink-3">No notifications yet.</p>
          ) : (
            <ul className="divide-y divide-line">
              {data.data.map((n) => (
                <li key={n.id}>
                  <NotificationItem n={n} onOpen={openItem} compact />
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="border-t border-line p-1.5">
          <Link to="/notifications" onClick={() => setOpen(false)} className="block rounded-sm px-2 py-1.5 text-center text-[13px] font-semibold text-navy-500 hover:bg-navy-50">
            View all notifications
          </Link>
        </div>
      </PopoverContent>
    </Popover>
  );
}
