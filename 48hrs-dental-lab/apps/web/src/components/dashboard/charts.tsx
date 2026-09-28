import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis, type TooltipProps } from 'recharts';

/**
 * Chart palette validated for CVD separation and 3:1 contrast on white
 * (navy 500 + deep gold). Hues are assigned in this fixed order.
 */
export const SERIES_COLORS = ['#1c5aa8', '#b08a1e'] as const;

const AXIS = { fontSize: 11.5, fill: '#6b7688' };
const GRID = '#e2e7ee';

export interface SeriesDef {
  key: string;
  label: string;
}

function ChartTooltip({ active, payload, label, format }: TooltipProps<number, string> & { format?: (v: number) => string }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-md border border-line bg-card px-3 py-2 text-xs shadow-md">
      <p className="mb-1 font-bold text-ink">{label}</p>
      {payload.map((p) => (
        <p key={String(p.dataKey)} className="flex items-center gap-2 text-ink-2">
          <span className="size-2.5 rounded-sm" style={{ background: p.color }} aria-hidden />
          {p.name}: <b className="font-mono text-ink">{format ? format(Number(p.value)) : p.value}</b>
        </p>
      ))}
    </div>
  );
}

export function Legend({ series }: { series: SeriesDef[] }) {
  if (series.length < 2) return null;
  return (
    <ul className="flex flex-wrap gap-4 text-xs text-ink-2">
      {series.map((s, i) => (
        <li key={s.key} className="flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm" style={{ background: SERIES_COLORS[i] }} aria-hidden />
          {s.label}
        </li>
      ))}
    </ul>
  );
}

/** Grouped column chart, one y-axis, thin rounded bars, recessive grid. */
export function ColumnChart<T extends object>({ data, xKey, series, height = 240, format, xFormat, label }: { data: T[]; xKey: keyof T & string; series: SeriesDef[]; height?: number; format?: (v: number) => string; xFormat?: (v: string) => string; label: string }) {
  return (
    <div className="flex flex-col gap-3">
      <Legend series={series} />
      <div role="img" aria-label={label} style={{ height }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: -12 }} barGap={2} barCategoryGap="22%">
            <CartesianGrid vertical={false} stroke={GRID} />
            <XAxis dataKey={xKey} tick={AXIS} tickLine={false} axisLine={{ stroke: GRID }} tickFormatter={xFormat} interval="preserveStartEnd" minTickGap={8} />
            <YAxis tick={AXIS} tickLine={false} axisLine={false} allowDecimals={false} tickFormatter={format} width={format ? 64 : 40} />
            <Tooltip cursor={{ fill: 'rgba(23,73,138,.06)' }} content={<ChartTooltip format={format} />} labelFormatter={(l) => (xFormat ? xFormat(String(l)) : String(l))} />
            {series.map((s, i) => (
              <Bar key={s.key} dataKey={s.key} name={s.label} fill={SERIES_COLORS[i]} radius={[4, 4, 0, 0]} maxBarSize={24} isAnimationActive={false} />
            ))}
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}

/** Single-series horizontal bars with the value at the tip. */
export function BarList({ items, format }: { items: { label: string; value: number; href?: string }[]; format?: (v: number) => string }) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="flex flex-col gap-2.5">
      {items.map((i) => (
        <li key={i.label} className="grid grid-cols-[minmax(0,140px)_1fr_auto] items-center gap-3 text-[13px]">
          <span className="truncate text-ink-2">{i.label}</span>
          <span className="h-3 overflow-hidden rounded-r bg-gray-50" aria-hidden>
            <span className="block h-full rounded-r" style={{ width: `${(i.value / max) * 100}%`, background: SERIES_COLORS[0] }} />
          </span>
          <span className="font-mono font-bold text-ink tabular">{format ? format(i.value) : i.value}</span>
        </li>
      ))}
    </ul>
  );
}
