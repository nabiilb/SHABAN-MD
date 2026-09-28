import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCheck } from 'lucide-react';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotifications } from '@/hooks/api/use-notifications';
import { usePageTitle } from '@/hooks/use-page-title';
import type { AppNotification } from '@/types/models';
import { NotificationItem } from '@/components/notifications/notification-item';
import { Pagination } from '@/components/tables/pagination';
import { Button } from '@/components/ui/button';
import { QueryError } from '@/components/ui/query-error';
import { Card } from '@/components/ui/card';
import { EmptyState, Skeleton } from '@/components/ui/feedback';
import { SegmentedControl } from '@/components/ui/segmented-control';

export default function NotificationsPage() {
  usePageTitle('Notifications', 'Case events that need your attention');
  const [tab, setTab] = useState<'all' | 'unread'>('all');
  const [page, setPage] = useState(1);
  const q = useNotifications({ page, perPage: 20, unreadOnly: tab === 'unread' });
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();
  const navigate = useNavigate();

  const open = (n: AppNotification) => {
    if (!n.readAt) markRead.mutate(n.id);
    if (n.caseId) navigate(`/cases/${n.caseId}`);
  };

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SegmentedControl
          label="Notification filter"
          value={tab}
          onChange={(v) => { setTab(v); setPage(1); }}
          options={[{ value: 'all', label: 'All' }, { value: 'unread', label: 'Unread', count: q.data?.unreadCount }]}
        />
        <Button variant="outline" onClick={() => markAll.mutate()} disabled={!q.data?.unreadCount} loading={markAll.isPending}>
          <CheckCheck /> Mark all read
        </Button>
      </div>
      <Card className="overflow-hidden">
        {q.error ? (
          <div className="p-4"><QueryError error={q.error} onRetry={() => void q.refetch()} /></div>
        ) : q.isLoading ? (
          <div className="flex flex-col gap-3 p-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-14" />)}</div>
        ) : !q.data?.data.length ? (
          <div className="p-4"><EmptyState title={tab === 'unread' ? 'You are all caught up.' : 'No notifications yet.'} description="New cases, assignments, deadline warnings, QC results and deliveries appear here." /></div>
        ) : (
          <>
            <ul className="divide-y divide-line">
              {q.data.data.map((n) => <li key={n.id}><NotificationItem n={n} onOpen={open} /></li>)}
            </ul>
            <Pagination meta={q.data.meta} onPageChange={setPage} />
          </>
        )}
      </Card>
    </div>
  );
}
