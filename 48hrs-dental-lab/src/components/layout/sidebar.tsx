import { NavLink } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { cn } from '@/lib/cn';
import { ROLE_LABELS } from '@/lib/permissions';
import { useAuth } from '@/hooks/use-auth';
import { useNavCounts } from '@/hooks/api/use-cases';
import { visibleNav } from '@/routes/nav';
import { Wordmark } from './brand';

const PERSONA_LINE: Record<string, string> = {
  super_admin: 'Users, access and system activity.',
  admin: 'Runs the lab end to end.',
  lab_manager: 'Assignment, production and quality control.',
  reception: 'Intake, payment and delivery.',
  technician: 'Only the cases assigned to you.',
  qc: 'Inspects finished work before it leaves.',
  delivery: 'Dispatch and hand-over.',
  client: 'Sees only its own cases.',
};

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { user, can } = useAuth();
  const { data: counts } = useNavCounts(!!user);
  const groups = visibleNav(can);
  if (!user) return null;

  return (
    <div className="flex h-full flex-col gap-5 overflow-y-auto bg-sidebar px-4 py-5 text-white">
      <Wordmark className="px-1.5" />

      <div className="flex flex-col gap-1.5">
        <span className="px-1.5 text-[10px] font-bold tracking-[.16em] text-navy-300">SIGNED IN AS</span>
        <div className="flex flex-col gap-0.5 rounded-md border border-navy-700 bg-navy-800 px-3 py-2.5">
          <span className="truncate text-sm font-bold">{user.name}</span>
          <span className="text-xs text-navy-200">{ROLE_LABELS[user.role]}</span>
        </div>
        <span className="px-1.5 text-xs leading-snug text-navy-200">{PERSONA_LINE[user.role]}</span>
      </div>

      <nav aria-label="Main" className="flex flex-col gap-4">
        {groups.map((g) => (
          <div key={g.label} className="flex flex-col gap-1">
            <span className="px-3 pb-1 text-[10px] font-bold tracking-[.16em] text-navy-300/80 uppercase">{g.label}</span>
            {g.items.map((item) => {
              const count = item.count && counts ? counts[item.count] : 0;
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  onClick={onNavigate}
                  className={({ isActive }) =>
                    cn(
                      'group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold transition-colors',
                      isActive ? 'bg-navy-800 text-white' : 'text-navy-200 hover:bg-navy-800/70 hover:text-white',
                    )
                  }
                >
                  {({ isActive }) => (
                    <>
                      <item.icon className={cn('size-[18px] shrink-0', isActive ? 'text-gold-500' : 'text-navy-300')} aria-hidden />
                      <span className="flex-1 truncate">{item.label}</span>
                      {count > 0 && (
                        <span className={cn('rounded-full px-2 py-0.5 font-mono text-[11px] font-bold text-white', isActive ? 'bg-navy-600' : 'bg-navy-800')} aria-label={`${count} pending`}>
                          {count}
                        </span>
                      )}
                    </>
                  )}
                </NavLink>
              );
            })}
          </div>
        ))}
      </nav>

      <div className="mt-auto flex flex-col gap-3 border-t border-navy-800 px-2.5 pt-3">
        <NavLink to="/logout" onClick={onNavigate} className="inline-flex w-fit items-center gap-2 rounded-md border border-navy-700 px-3 py-1.5 text-[12.5px] font-semibold text-navy-200 hover:bg-navy-800">
          <LogOut className="size-3.5" aria-hidden /> Sign out
        </NavLink>
        <div className="flex flex-col gap-1">
          <span className="text-[11px] font-bold tracking-[.14em] text-gold-500">BRAND PROMISE</span>
          <span className="text-xs leading-snug text-navy-200">Every case. Within 48 hours.</span>
        </div>
      </div>
    </div>
  );
}
