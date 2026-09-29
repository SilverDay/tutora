// Phase 8: tutor-triggered AI summary of Write responses (server must run with AI_PROVIDER=stub
// or a real provider). Raw responses stay with the tutor; participants only see the summary
// after the tutor shares it, pushed live over the relay.
import { signUpTutor } from './lib.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const OUTBOX = process.env.MAIL_OUTBOX || '/var/lib/tutora/mail-outbox';
const problems = [];
const step = (m) => console.log('✓', m);
const watch = (page, name) => { page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`${name}: ${m.text()}`); }); page.on('pageerror', (e) => problems.push(`${name} pageerror: ${e.message}`)); page.on('dialog', (d) => { problems.push(`${name} DIALOG ${d.message()}`); d.dismiss(); }); };

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const tutor = await (await browser.newContext()).newPage(); watch(tutor, 'tutor');
await signUpTutor(tutor, B, OUTBOX, `e2e-ai-${Date.now()}@example.org`);
await tutor.fill('[name=title]', 'AI'); await tutor.click('form[action="/workshops"] button');
await tutor.selectOption('select[name=block_type]', 'write');
await tutor.fill('form[action$="/blocks"] textarea[name=config]', '{"prompt":"What was unclear?"}');
await tutor.click('form[action$="/blocks"] button[type=submit]');
await tutor.click('text=Start live session');
await tutor.waitForURL(/\/sessions\/\d+$/);
const code = (await tutor.textContent('.joincode code')).trim();

const mk = async (name) => { const p = await (await browser.newContext()).newPage(); watch(p, name);
  await p.goto(B + '/join'); await p.fill('[name=code]', code); await p.click('#join-form button');
  await p.waitForSelector('#session-view:not([hidden])'); return p; };
const p1 = await mk('p1'); const p2 = await mk('p2');
for (const [p, text] of [[p1, 'Token rotation was unclear <img src=x onerror=alert(1)>'], [p2, 'Token lifetimes and rotation']]) {
  await p.fill('.activity textarea', text); await p.click('.activity button[type=submit]');
  await p.waitForFunction(() => document.getElementById('feedback').textContent === 'Response sent.');
}
await tutor.waitForFunction(() => document.querySelectorAll('.response-text').length === 2, null, { timeout: 5000 });
step('two Write responses, visible to the tutor only');

await tutor.click('[data-testid=ai-summary] button:has-text("Generate AI summary")');
await tutor.waitForURL(/\/sessions\/\d+$/);
await tutor.waitForSelector('[data-testid=ai-summary] .summary-text');
const summary = await tutor.textContent('[data-testid=ai-summary] .summary-text');
if (!summary.includes('2 responses')) throw new Error('unexpected summary: ' + summary);
if (await tutor.locator('.response-text').count() !== 2) throw new Error('raw responses not shown next to the summary');
step('tutor generated the summary; raw responses still shown alongside');
await p1.waitForTimeout(800);
if (await p1.locator('[data-testid=shared-summary]').count() !== 0) throw new Error('summary visible before sharing');
step('participants do not see an unshared summary');

if (!(await tutor.isVisible('[data-testid=summary-not-shareable]'))) throw new Error('2-response summary offered for sharing');
if (await tutor.locator('button:has-text("Share summary with participants")').count() !== 0) throw new Error('share button shown below the minimum');
step('summary of 2 responses cannot be shared (minimum 3)');

const p3 = await mk('p3');
await p3.fill('.activity textarea', 'Rotation of refresh tokens'); await p3.click('.activity button[type=submit]');
await p3.waitForFunction(() => document.getElementById('feedback').textContent === 'Response sent.');
await tutor.waitForFunction(() => document.querySelectorAll('.response-text').length === 3, null, { timeout: 5000 });
await tutor.click('[data-testid=ai-summary] button:has-text("Regenerate AI summary")');
await tutor.waitForURL(/\/sessions\/\d+$/);
await tutor.waitForFunction(() => document.querySelector('[data-testid=ai-summary] .summary-text')?.textContent.includes('3 responses'), null, { timeout: 5000 });
await tutor.click('[data-testid=ai-summary] button:has-text("Share summary with participants")');
await tutor.waitForSelector('[data-testid=summary-shared]');
for (const p of [p1, p2, p3]) await p.waitForSelector('[data-testid=shared-summary]', { timeout: 5000 });
step('with 3 responses: shared, all participants received the summary live');
for (const p of [p1, p2]) {
  const body = await p.textContent('body');
  if (body.includes('Token lifetimes and rotation') && p === p1) throw new Error('other participant response leaked');
  if (await p.locator('img').count() !== 0) throw new Error('markup rendered');
}
step('no raw responses of others and no markup on participant pages');

console.log('problems:', JSON.stringify(problems));
if (problems.length) process.exitCode = 1;
await browser.close();
