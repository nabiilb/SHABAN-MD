import type { ReactNode } from 'react';
import { Avatar } from '@/components/ui/avatar';
import { Card, CardBody } from '@/components/ui/card';

export function DetailHeader({ name, subtitle, badges, actions, children }: { name: string; subtitle?: ReactNode; badges?: ReactNode; actions?: ReactNode; children?: ReactNode }) {
  return (
    <Card>
        <CardBody className="flex flex-col gap-5">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-center gap-3.5">
              <Avatar name={name} size="lg" />
              <div className="flex min-w-0 flex-col gap-1">
                <h2 className="text-xl font-bold">{name}</h2>
                {subtitle && <div className="text-[13px] text-ink-2">{subtitle}</div>}
                {badges && <div className="flex flex-wrap gap-1.5">{badges}</div>}
              </div>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
          </div>
          {children}
        </CardBody>
    </Card>
  );
}
