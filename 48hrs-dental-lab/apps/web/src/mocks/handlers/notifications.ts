import { authenticate } from '../auth-context';
import { notFound, paginate, qBool, route } from '../router';

route('GET', '/notifications', (raw) => {
  const ctx = authenticate(raw);
  const mine = ctx.db.notifications.filter((n) => n.userId === ctx.user.id);
  const list = qBool(raw.query, 'unreadOnly') ? mine.filter((n) => !n.readAt) : mine;
  const sorted = [...list].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return { ...paginate(sorted, raw.query), unreadCount: mine.filter((n) => !n.readAt).length };
});

route('POST', '/notifications/read-all', (raw) => {
  const ctx = authenticate(raw);
  const at = new Date(ctx.now).toISOString();
  ctx.db.notifications.forEach((n) => {
    if (n.userId === ctx.user.id && !n.readAt) n.readAt = at;
  });
  return null;
});

route('POST', '/notifications/:id/read', (raw) => {
  const ctx = authenticate(raw);
  const n = ctx.db.notifications.find((x) => x.id === raw.params.id && x.userId === ctx.user.id);
  if (!n) throw notFound();
  n.readAt ??= new Date(ctx.now).toISOString();
  return null;
});
