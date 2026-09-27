import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { buildCrumbs } from '@/components/layout/breadcrumbs';
import { RowActions } from '@/components/tables/row-actions';
import { SimpleTable } from '@/components/ui/simple-table';
import { caseService } from '@/services/caseService';
import { loginAs } from './helpers';

describe('breadcrumbs', () => {
  it('maps routes to a trail ending with the record title', () => {
    expect(buildCrumbs('/dashboard', '')).toEqual([]);
    expect(buildCrumbs('/cases', 'Cases')).toEqual([{ label: 'Dashboard', to: '/dashboard' }, { label: 'Cases' }]);
    expect(buildCrumbs('/cases/new', 'New Case')).toEqual([{ label: 'Dashboard', to: '/dashboard' }, { label: 'Cases', to: '/cases' }, { label: 'New Case' }]);
    expect(buildCrumbs('/cases/cas_1', 'DL-2026-00130').at(-1)).toEqual({ label: 'DL-2026-00130' });
  });

  it('does not link to sections the user cannot open', () => {
    const crumbs = buildCrumbs('/technicians/tec_fatima', 'Fatima Nur', (path) => path !== '/technicians');
    expect(crumbs[1]).toEqual({ label: 'Technicians', to: undefined });
  });
});

describe('row actions', () => {
  it('hides actions the user may not perform and runs the chosen one', async () => {
    const user = userEvent.setup();
    let edited = false;
    render(<RowActions label="Row 1" actions={[{ label: 'Edit', onSelect: () => (edited = true) }, { label: 'Delete', tone: 'danger', onSelect: () => {}, hidden: true }]} />);
    await user.click(screen.getByRole('button', { name: 'Actions for Row 1' }));
    expect(screen.queryByRole('menuitem', { name: 'Delete' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('menuitem', { name: 'Edit' }));
    expect(edited).toBe(true);
  });

  it('renders nothing when every action is hidden', () => {
    const { container } = render(<RowActions label="x" actions={[{ label: 'Edit', onSelect: () => {}, hidden: true }]} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('simple table', () => {
  it('renders rows or the empty message', () => {
    const { rerender } = render(<SimpleTable rows={[{ id: 'a', n: 1 }]} getKey={(r) => r.id} columns={[{ header: 'N', cell: (r) => r.n }]} />);
    expect(screen.getByRole('columnheader', { name: 'N' })).toBeInTheDocument();
    rerender(<SimpleTable rows={[] as { id: string }[]} getKey={(r) => r.id} columns={[]} empty="Nothing here" />);
    expect(screen.getByText('Nothing here')).toBeInTheDocument();
  });
});

describe('due-date filter', () => {
  it('returns only cases whose 48-hour deadline falls in the range', async () => {
    await loginAs('omar@48hrs.lab');
    const all = await caseService.list({ perPage: 200, openOnly: true });
    const withDue = all.data.filter((c) => c.dueAt);
    const day = withDue[0].dueAt!;
    const d = new Date(day);
    const ymd = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const res = await caseService.list({ dueFrom: ymd, dueTo: ymd, perPage: 200 });
    expect(res.data.length).toBeGreaterThan(0);
    expect(res.data.every((c) => { const x = new Date(c.dueAt!); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}` === ymd; })).toBe(true);
  });
});
