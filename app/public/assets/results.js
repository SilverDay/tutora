// Renders anonymous activity aggregates (shared by participant and tutor views).
import { el, svg } from './dom.js';

function bar(label, value, max, suffix) {
  return el('div', { class: 'bar' },
    el('span', { class: 'bar-label', text: label }),
    el('meter', { min: 0, max: Math.max(max, 1), value }),
    el('span', { class: 'bar-value', text: suffix }));
}

const renderers = {
  poll: (c, a) => a.options.map(o => bar(o.label, o.count, a.responses, `${o.count} (${o.percent}%)`)),
  word: (c, a) => a.options.map(o => bar(o.label, o.count, a.responses, `${o.count}`)),
  meter: (c, a) => a.mean === null ? [] : [
    el('p', { text: `Mean ${a.mean} · median ${a.median}` }),
    ...a.distribution.map(d => bar(String(d.from), d.count, a.responses, String(d.count))),
  ],
  rate: (c, a) => a.items.map(i => bar(i.label, i.average ?? 0, c.scale, i.average === null ? '–' : `${i.average} / ${c.scale}`)),
  rank: (c, a) => [el('ol', {}, a.ranking.map(r => el('li', { text: `${r.label} (${r.points} pts)` })))],
  word_cloud: (c, a) => {
    const max = Math.max(1, ...a.words.map(w => w.count));
    return [el('p', { class: 'cloud' }, a.words.map(w =>
      el('span', { class: `cloud-word size-${1 + Math.round(4 * (w.count - 1) / Math.max(1, max - 1))}`, text: w.word, title: `${w.count}` })))];
  },
  plot: (c, a) => {
    const W = 300, H = 300, pad = 30;
    const sx = x => pad + (x - c.x_axis.min) / (c.x_axis.max - c.x_axis.min) * (W - 2 * pad);
    const sy = y => H - pad - (y - c.y_axis.min) / (c.y_axis.max - c.y_axis.min) * (H - 2 * pad);
    const g = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'plot', role: 'img', 'aria-label': `${c.x_axis.label} vs ${c.y_axis.label}` },
      svg('rect', { x: pad, y: pad, width: W - 2 * pad, height: H - 2 * pad, class: 'plot-frame' }),
      svg('text', { x: W / 2, y: H - 5, class: 'plot-axis', 'text-anchor': 'middle', text: c.x_axis.label }),
      svg('text', { x: 10, y: H / 2, class: 'plot-axis', 'text-anchor': 'middle', transform: `rotate(-90 10 ${H / 2})`, text: c.y_axis.label }));
    a.items.forEach((item, idx) => {
      for (const p of item.points) g.append(svg('circle', { cx: sx(p.x), cy: sy(p.y), r: 4, class: `plot-pt series-${idx % 6}` }));
      if (item.centroid) g.append(svg('text', { x: sx(item.centroid.x) + 6, y: sy(item.centroid.y) - 6, class: 'plot-label', text: item.label }));
    });
    return [g];
  },
  write: () => [],
};

export function renderAggregate(container, type, config, aggregate) {
  container.replaceChildren();
  if (!aggregate) return;
  container.append(el('p', { class: 'muted', text: `${aggregate.responses} response${aggregate.responses === 1 ? '' : 's'}` }));
  const r = renderers[type];
  if (r) container.append(...r(config, aggregate));
}
