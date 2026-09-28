import { useState } from 'react';
import { Check, CircleHelp, X } from 'lucide-react';
import quadrantGuide from '@/assets/teeth-quadrants.webp';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { cn } from '@/lib/cn';
import { QUADRANTS, TOOTH_GEOMETRY } from '@/lib/teeth';

interface ToothChartProps {
  value: number[];
  onChange?: (teeth: number[]) => void;
  readOnly?: boolean;
  invalid?: boolean;
  describedBy?: string;
}

/**
 * Interactive universal-numbering chart (1–32) from the prototype. Each tooth
 * is a real toggle button, so the chart works with keyboard and screen readers.
 */
export function ToothChart({ value, onChange, readOnly, invalid, describedBy }: ToothChartProps) {
  const [guideOpen, setGuideOpen] = useState(false);
  const selected = new Set(value);
  const toggle = (n: number) => {
    if (readOnly || !onChange) return;
    onChange(selected.has(n) ? value.filter((t) => t !== n) : [...value, n].sort((a, b) => a - b));
  };
  const toggleQuadrant = (teeth: number[]) => {
    if (!onChange) return;
    const all = teeth.every((t) => selected.has(t));
    onChange(all ? value.filter((t) => !teeth.includes(t)) : [...new Set([...value, ...teeth])].sort((a, b) => a - b));
  };

  return (
    <div className="flex flex-col gap-4">
      {!readOnly && (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
          {QUADRANTS.map((q) => {
            const all = q.teeth.every((t) => selected.has(t));
            return (
              <button
                key={q.label}
                type="button"
                onClick={() => toggleQuadrant(q.teeth)}
                aria-pressed={all}
                className={cn('rounded-md border bg-card px-3 py-2.5 text-[12.5px] font-bold transition-colors hover:border-brand', all ? 'border-brand text-brand' : 'border-line-strong text-ink-2')}
              >
                {q.label}
              </button>
            );
          })}
          <button type="button" onClick={() => onChange?.([])} className="col-span-2 rounded-md border border-dashed border-line-strong px-3 py-2.5 text-[12.5px] font-bold text-ink-3 hover:text-ink-2 sm:col-span-1">
            Clear all
          </button>
        </div>
      )}

      <div className={cn('overflow-hidden rounded-md border bg-gray-50 p-[clamp(4px,1.6vw,14px)]', invalid ? 'border-danger' : 'border-line')}>
        <div
          role="group"
          aria-label="Tooth chart, universal numbering 1 to 32"
          aria-describedby={describedBy}
          className="relative mx-auto aspect-[480/400] w-full max-w-[660px] [container-type:inline-size]"
        >
          <div className="absolute top-[8%] bottom-[57%] left-1/2 w-px bg-line-strong opacity-70" aria-hidden />
          <div className="absolute top-[65%] bottom-[3%] left-1/2 w-px bg-line-strong opacity-70" aria-hidden />
          <span className="absolute top-1/2 left-0 -translate-y-1/2 text-[2.5cqw] font-extrabold tracking-[.14em] text-ink-3" aria-hidden>RIGHT</span>
          <span className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 text-[2.3cqw] font-extrabold tracking-[.14em] text-ink-3" aria-hidden>MIDLINE</span>
          <span className="absolute top-1/2 right-0 -translate-y-1/2 text-[2.5cqw] font-extrabold tracking-[.14em] text-ink-3" aria-hidden>LEFT</span>

          {TOOTH_GEOMETRY.map((g) => {
            const on = selected.has(g.n);
            const pad = (5 / 480) * 100;
            return (
              <button
                key={g.n}
                type="button"
                disabled={readOnly}
                onClick={() => toggle(g.n)}
                aria-pressed={on}
                aria-label={`Tooth ${g.n}`}
                title={`Tooth ${g.n}`}
                className="absolute flex -translate-x-1/2 -translate-y-1/2 touch-manipulation items-center justify-center p-0 enabled:hover:opacity-75 disabled:cursor-default"
                style={{ left: `${g.x}%`, top: `${g.y}%`, width: `${g.w + pad * 2}cqw`, height: `${g.h + pad * 2}cqw` }}
              >
                <span
                  aria-hidden
                  className={cn('absolute transition-colors duration-150', on ? 'border-2 border-brand bg-brand' : 'border-[1.5px] border-line-strong bg-card')}
                  style={{ inset: `${pad}cqw`, borderRadius: `${g.radius}cqw`, transform: `rotate(${g.rotation}deg)` }}
                />
                <span aria-hidden className={cn('relative font-mono leading-none font-bold', on ? 'text-white' : 'text-ink-2')} style={{ fontSize: `max(9px, ${(12.5 / 480) * 100}cqw)` }}>
                  {g.n}
                </span>
                {on && (
                  <span
                    aria-hidden
                    className="absolute flex translate-x-[35%] -translate-y-[35%] items-center justify-center rounded-full bg-brand text-white ring-2 ring-gray-50"
                    style={{ top: `${pad}cqw`, right: `${pad}cqw`, width: `max(11px, ${(11.5 / 480) * 100}cqw)`, height: `max(11px, ${(11.5 / 480) * 100}cqw)` }}
                  >
                    <Check className="size-[70%]" strokeWidth={3.5} />
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {!readOnly && (
        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-2">
            <span className="eyebrow">Selected teeth</span>
            <Button variant="link" size="sm" onClick={() => setGuideOpen(true)}>
              <CircleHelp /> Numbering guide
            </Button>
          </div>
          {value.length ? (
            <div className="flex flex-wrap gap-1.5">
              {value.map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => toggle(n)}
                  className="inline-flex items-center gap-1.5 rounded-full border border-navy-100 bg-navy-50 px-3 py-1.5 font-mono text-[13px] font-bold text-brand hover:border-navy-300"
                  aria-label={`Remove tooth ${n}`}
                >
                  {n}
                  <X className="size-3 opacity-60" aria-hidden />
                </button>
              ))}
            </div>
          ) : (
            <span className="text-[13px] text-ink-3">No teeth selected yet — tap the chart above.</span>
          )}
        </div>
      )}

      <Dialog open={guideOpen} onOpenChange={setGuideOpen}>
        <DialogContent size="lg" title="Universal numbering" description="Teeth 1–16 run across the upper arch from the patient's right; 17–32 return along the lower arch. Screen left is the patient's right.">
          <img src={quadrantGuide} alt="Dental chart showing universal tooth numbers 1 to 32 in four quadrants, with the patient's right on the left" className="mx-auto w-full max-w-[520px] rounded-md border border-line" />
        </DialogContent>
      </Dialog>
    </div>
  );
}
