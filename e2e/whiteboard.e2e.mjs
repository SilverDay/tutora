// End-to-end whiteboard test against a running stack (PHP app + relay + whiteboard sidecar):
// collaborative drawing both ways, annotate tags, presenter mode over the relay, snapshots
// (manual and on block exit), clear board. Verifies what the *other* browser renders by
// sampling canvas pixels. See e2e/README.md.
import crypto from 'node:crypto';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const b32 = s => { const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s.replace(/ /g, '')) bits += A.indexOf(c).toString(2).padStart(5, '0'); return Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2))); };
const totp = (secret) => { const c = Buffer.alloc(8); c.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000))); const h = crypto.createHmac('sha1', secret).update(c).digest(); const o = h[19] & 15; return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0'); };
const problems = [];
// the willReadFrequently hint is triggered by this test's own getImageData pixel sampling
const watch = (p, n) => { p.on('console', m => { if (['error', 'warning'].includes(m.type()) && !m.text().includes('willReadFrequently')) problems.push(`${n}: ${m.text()}`); }); p.on('pageerror', e => problems.push(`${n}: ${e.message}`)); };
const step = (m) => console.log('✓', m);

/** Non-white pixels inside the normalised box [x0,y0,x1,y1] of the first canvas in `scope`. */
async function inked(page, scope, box) {
  return page.evaluate(([sel, [x0, y0, x1, y1]]) => {
    const c = document.querySelector(`${sel} canvas`);
    if (!c) return -1;
    const d = c.getContext('2d').getImageData(Math.floor(x0 * c.width), Math.floor(y0 * c.height), Math.max(1, Math.floor((x1 - x0) * c.width)), Math.max(1, Math.floor((y1 - y0) * c.height))).data;
    let n = 0;
    for (let i = 0; i < d.length; i += 4) if (d[i] < 200 || d[i + 1] < 200 || d[i + 2] < 200) n++;
    return n;
  }, [scope, box]);
}
const waitInk = async (page, scope, box, want = true, ms = 6000) => {
  const end = Date.now() + ms;
  while (Date.now() < end) { const n = await inked(page, scope, box); if ((n > 0) === want) return n; await page.waitForTimeout(100); }
  throw new Error(`${scope} ${want ? 'expected ink' : 'expected blank'} in ${JSON.stringify(box)}`);
};
async function drag(page, scope, from, to) {
  const r = await page.locator(`${scope} canvas`).boundingBox();
  await page.mouse.move(r.x + from[0] * r.width, r.y + from[1] * r.height);
  await page.mouse.down();
  for (let i = 1; i <= 8; i++) await page.mouse.move(r.x + (from[0] + (to[0] - from[0]) * i / 8) * r.width, r.y + (from[1] + (to[1] - from[1]) * i / 8) * r.height);
  await page.mouse.up();
}
async function click(page, scope, at) {
  const r = await page.locator(`${scope} canvas`).boundingBox();
  await page.mouse.click(r.x + at[0] * r.width, r.y + at[1] * r.height);
}

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const tutor = await (await browser.newContext({ viewport: { width: 1100, height: 1400 } })).newPage(); watch(tutor, 'tutor');
await tutor.goto(B + '/signup');
await tutor.fill('[name=display_name]', 'K'); await tutor.fill('[name=email]', `wb-${Date.now()}@example.org`); await tutor.fill('[name=password]', 'a long enough passphrase');
await tutor.click('button[type=submit]');
await tutor.fill('[name=code]', totp(b32(await tutor.textContent('p.secret code')))); await tutor.click('form[action="/login/enroll"] button');
await tutor.waitForURL(B + '/dashboard');
await tutor.fill('[name=title]', 'Boards'); await tutor.click('form[action="/workshops"] button');
for (const [type, cfg] of [
  ['whiteboard', '{"mode":"collaborative"}'],
  ['annotate', '{"prompt":"Label the risks","tags":["Risk","Asset"]}'],
  ['whiteboard', '{"mode":"presenter"}'],
  ['write', '{"prompt":"done"}'],
]) {
  await tutor.selectOption('select[name=block_type]', type);
  await tutor.fill('form[action$="/blocks"] textarea[name=config]', cfg);
  await tutor.click('form[action$="/blocks"] button[type=submit]');
}
await tutor.click('text=Start live session'); await tutor.waitForURL(/\/sessions\/\d+$/);
const code = (await tutor.textContent('.joincode code')).trim();
const p = await (await browser.newContext({ viewport: { width: 1100, height: 1400 } })).newPage(); watch(p, 'participant');
await p.goto(B + '/join'); await p.fill('[name=code]', code); await p.click('#join-form button');
await p.waitForSelector('.wb canvas');
await tutor.waitForSelector('#whiteboard canvas');
await p.waitForFunction(() => !document.querySelector('.wb-status')?.textContent.includes('connecting'), null, { timeout: 8000 });
step('collaborative board mounted for tutor and participant');

// participant draws a stroke top-left -> tutor sees it
await drag(p, '.wb', [0.1, 0.1], [0.3, 0.3]);
await waitInk(tutor, '#whiteboard', [0.12, 0.12, 0.28, 0.28]);
step('participant stroke appears on the tutor board');
// tutor draws a box bottom-right -> participant sees it
await tutor.click('#whiteboard .wb-tool[data-tool="rect"]');
await drag(tutor, '#whiteboard', [0.6, 0.6], [0.9, 0.9]);
await waitInk(p, '.wb', [0.59, 0.59, 0.61, 0.91]);
step('tutor box appears on the participant board');
// participant cannot erase the tutor's box (client restricts, server enforces)
await p.click('.wb .wb-tool[data-tool="eraser"]');
await click(p, '.wb', [0.6, 0.75]);
await p.waitForTimeout(500);
await waitInk(tutor, '#whiteboard', [0.59, 0.59, 0.61, 0.91]);
// participant erases own stroke
await click(p, '.wb', [0.2, 0.2]);
await waitInk(tutor, '#whiteboard', [0.12, 0.12, 0.28, 0.28], false);
step('participant can erase own stroke only');

// manual snapshot
await tutor.click('#whiteboard >> text=Save snapshot');
await tutor.waitForFunction(() => document.querySelector('#whiteboard .wb-status')?.textContent === 'Snapshot saved.', null, { timeout: 5000 });
step('manual snapshot saved');

// navigate to annotate: pre-navigation capture, then the gallery shows 2 snapshots
await tutor.click('form[action$="/navigate"] >> text=Next');
await tutor.waitForFunction(() => document.querySelector('h2')?.textContent.includes('annotate'), null, { timeout: 10000 });
await tutor.waitForSelector('#whiteboard canvas');
const shots = await tutor.locator('text=Whiteboard snapshots').count() ? await tutor.locator('img[src^="/snapshots/"]').count() : 0;
if (shots !== 2) throw new Error(`expected 2 snapshots (manual + block exit), got ${shots}`);
const snapW = await tutor.locator('img[src^="/snapshots/"]').first().evaluate(i => i.naturalWidth);
step(`block-exit snapshot captured before navigation (${shots} snapshots, ${snapW}px wide)`);

// annotate: participant places a tag -> tutor sees it
await p.waitForSelector('.wb .wb-tool[data-tool="tag:t1"]', { timeout: 8000 });
if (await p.locator('.wb .wb-tool[data-tool="pen"]').count() !== 0) throw new Error('annotate board offers freehand tools');
await p.waitForFunction(() => !document.querySelector('.wb-status')?.textContent.includes('connecting'), null, { timeout: 8000 });
await click(p, '.wb', [0.5, 0.5]);
await waitInk(tutor, '#whiteboard', [0.5, 0.47, 0.6, 0.53]);
step('annotate: participant tag appears on the tutor board');

// presenter mode
await tutor.click('form[action$="/navigate"] >> text=Next');
await tutor.waitForFunction(() => document.querySelector('#whiteboard')?.dataset.mode === 'presenter', null, { timeout: 10000 });
await tutor.waitForSelector('#whiteboard canvas');
await p.waitForFunction(() => document.querySelector('.wb canvas') && document.querySelectorAll('.wb .wb-tool').length === 0, null, { timeout: 8000 });
await tutor.waitForTimeout(800); // relay connection for strokes
await drag(tutor, '#whiteboard', [0.2, 0.7], [0.4, 0.9]);
await waitInk(p, '.wb', [0.22, 0.72, 0.38, 0.88]);
step('presenter mode: tutor stroke reaches the read-only participant via the relay');
await tutor.click('#whiteboard >> text=Clear');
await waitInk(p, '.wb', [0.22, 0.72, 0.38, 0.88], false);
step('presenter clear propagates');

console.log('problems:', JSON.stringify(problems));
if (problems.length) process.exitCode = 1;
await browser.close();
