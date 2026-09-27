/**
 * Universal numbering 1–32 dental arch, ported from the prototype's tooth chart.
 * Screen left is the patient's right. Geometry is expressed in a 480×400 box so
 * the chart scales fluidly with its container.
 */
export const ARCH = {
  upperRight: [1, 2, 3, 4, 5, 6, 7, 8],
  upperLeft: [9, 10, 11, 12, 13, 14, 15, 16],
  lowerLeft: [17, 18, 19, 20, 21, 22, 23, 24],
  lowerRight: [25, 26, 27, 28, 29, 30, 31, 32],
} as const;

export const QUADRANTS = [
  { label: 'Upper right', teeth: [...ARCH.upperRight] },
  { label: 'Upper left', teeth: [...ARCH.upperLeft] },
  { label: 'Lower left', teeth: [...ARCH.lowerLeft] },
  { label: 'Lower right', teeth: [...ARCH.lowerRight] },
];

export const ALL_TEETH = Array.from({ length: 32 }, (_, i) => i + 1);

const GEO = { cx: 240, rx: 190, ry: 135, upperCy: 196, lowerCy: 224, from: 186, to: 354 };
export const CHART_W = 480;
export const CHART_H = 400;

function toothSize(n: number) {
  const pos = n <= 8 ? 9 - n : n <= 16 ? n - 8 : n <= 24 ? 25 - n : n - 24;
  if (pos <= 2) return { w: 21, h: 24, r: 7 };
  if (pos === 3) return { w: 22, h: 27, r: 8 };
  if (pos <= 5) return { w: 25, h: 24, r: 8 };
  return { w: 28, h: 23, r: 8 };
}

/** Equal arc-length spacing so molars never crowd at the ends of the arch. */
const ANGLES: number[] = (() => {
  const steps = 3000;
  const d = (GEO.to - GEO.from) / steps;
  const pts: { th: number; len: number }[] = [];
  let len = 0;
  let px: number | null = null;
  let py: number | null = null;
  for (let i = 0; i <= steps; i++) {
    const th = GEO.from + i * d;
    const rad = (th * Math.PI) / 180;
    const x = GEO.rx * Math.cos(rad);
    const y = GEO.ry * Math.sin(rad);
    if (px !== null && py !== null) len += Math.hypot(x - px, y - py);
    pts.push({ th, len });
    px = x;
    py = y;
  }
  const out: number[] = [];
  for (let k = 0; k < 16; k++) {
    const target = (len * k) / 15;
    let j = 0;
    while (j < pts.length - 1 && pts[j].len < target) j++;
    out.push(pts[j].th);
  }
  return out;
})();

export interface ToothGeometry {
  n: number;
  /** Centre, in % of the chart box. */
  x: number;
  y: number;
  /** Size, in % of chart width. */
  w: number;
  h: number;
  radius: number;
  rotation: number;
}

export function toothGeometry(n: number): ToothGeometry {
  const upper = n <= 16;
  const idx = upper ? n - 1 : 32 - n;
  const th = upper ? ANGLES[idx] : 360 - ANGLES[idx];
  const rad = (th * Math.PI) / 180;
  const x = GEO.cx + GEO.rx * Math.cos(rad);
  const y = (upper ? GEO.upperCy : GEO.lowerCy) + GEO.ry * Math.sin(rad);
  const s = toothSize(n);
  return {
    n,
    x: (x / CHART_W) * 100,
    y: (y / CHART_H) * 100,
    w: (s.w / CHART_W) * 100,
    h: (s.h / CHART_W) * 100,
    radius: (s.r / CHART_W) * 100,
    rotation: th + 90,
  };
}

export const TOOTH_GEOMETRY = ALL_TEETH.map(toothGeometry);
