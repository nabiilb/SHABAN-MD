import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCheck } from 'lucide-react';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotifications } from '@/hooks/api/use-notifications';
import { usePageTitle } from '@/hooks/use-page-title';
import { errorMessage } from '@/services/api/errors';
import type { AppNotification } from '@/types/models';
import { NotificationItem } from '@/components/notifications/notification-item';
import { Pagination } from '@/components/tables/pagination';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/feedback';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

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
        <Tabs value={tab} onValueChange={(v) => { setTab(v as 'all' | 'unread'); setPage(1); }}>
          <TabsList label="Notification filter">
            <TabsTrigger value="all">All</TabsTrigger>
            <TabsTrigger value="unread" count={q.data?.unreadCount}>Unread</TabsTrigger>
          </TabsList>
        </Tabs>
        <Button variant="outline" onClick={() => markAll.mutate()} disabled={!q.data?.unreadCount} loading={markAll.isPending}>
          <CheckCheck /> Mark all read
        </Button>
      </div>
      <Card className="overflow-hidden">
        {q.error ? (
          <div className="p-4"><ErrorState message={errorMessage(q.error)} onRetry={() => void q.refetch()} /></div>
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
