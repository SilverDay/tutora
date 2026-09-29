// End-to-end slide import test: browser upload -> conversion daemon (real sandboxed
// container) -> thumbnails -> slide blocks -> live session -> participant sees the slide.
// Requires the conversion daemon to be running. See e2e/README.md.
import { signUpTutor } from './lib.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const OUTBOX = process.env.MAIL_OUTBOX || '/var/lib/tutora/mail-outbox';
const DECK = process.env.DECK || new URL('../app/tests/fixtures/sample.pptx', import.meta.url).pathname;
const problems = []; const watch = (p, n) => { p.on('console', m => { if (['error', 'warning'].includes(m.type())) problems.push(`${n}: ${m.text()}`); }); p.on('pageerror', e => problems.push(`${n}: ${e.message}`)); };
const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const tutor = await (await browser.newContext()).newPage(); watch(tutor, 'tutor');
await signUpTutor(tutor, B, OUTBOX, `slides-${Date.now()}@example.org`);
await tutor.fill('[name=title]', 'Slide deck'); await tutor.click('form[action="/workshops"] button');
await tutor.setInputFiles('input[name=deck]', DECK);
const t0 = Date.now();
await tutor.click('form[action$="/slides"] button[type=submit]');
await tutor.waitForSelector('text=Converting');
console.log('✓ uploaded via browser, job queued');
for (let i = 0; i < 40 && !(await tutor.locator('.thumbs img').count()); i++) { await tutor.waitForTimeout(1000); await tutor.reload(); }
const thumbs = await tutor.locator('.thumbs img').count();
console.log(`✓ daemon converted in sandbox: ${thumbs} page thumbnails after ${((Date.now() - t0) / 1000).toFixed(1)}s`);
const w = await tutor.locator('.thumbs img').first().evaluate(img => img.naturalWidth);
console.log('  thumbnail naturalWidth:', w);
await tutor.click('text=Add all pages as slide blocks');
await tutor.click('text=Start live session'); await tutor.waitForURL(/\/sessions\/\d+$/);
const tw = await tutor.locator('img.slide').evaluate(img => img.naturalWidth);
console.log('✓ tutor console shows current slide, naturalWidth', tw);
const code = (await tutor.textContent('.joincode code')).trim();
const p = await (await browser.newContext()).newPage(); watch(p, 'participant');
await p.goto(B + '/join'); await p.fill('[name=code]', code); await p.click('#join-form button');
await p.waitForSelector('img.slide');
await p.waitForFunction(() => document.querySelector('img.slide').naturalWidth > 0);
const src = await p.locator('img.slide').getAttribute('src');
console.log('✓ participant sees slide via authenticated blob URL:', src.slice(0, 5) + '…');
await tutor.click('form[action$="/navigate"] >> text=Next');
await p.waitForFunction((old) => document.querySelector('img.slide')?.src !== old && document.querySelector('img.slide')?.naturalWidth > 0, src, { timeout: 8000 });
console.log('✓ navigation to page 2 updates the participant slide live');
if (thumbs !== 3) throw new Error(`expected 3 pages, got ${thumbs}`);
console.log('problems:', JSON.stringify(problems));
if (problems.length) process.exitCode = 1;
await browser.close();
