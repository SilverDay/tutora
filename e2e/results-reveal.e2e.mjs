// Owner decision 11: a block set to "results": "on_reveal" shows participants nothing of the
// aggregate (page and WebSocket frames) until the tutor reveals it; then it appears live.
import { signUpTutor } from './lib.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const OUTBOX = process.env.MAIL_OUTBOX || '/var/lib/tutora/mail-outbox';
const problems = [];
const step = (m) => console.log('✓', m);
const watch = (page, name) => { page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`${name}: ${m.text()}`); }); page.on('pageerror', (e) => problems.push(`${name} pageerror: ${e.message}`)); };

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const tutor = await (await browser.newContext()).newPage(); watch(tutor, 'tutor');
await signUpTutor(tutor, B, OUTBOX, `e2e-reveal-${Date.now()}@example.org`);
await tutor.fill('[name=title]', 'Reveal'); await tutor.click('form[action="/workshops"] button');
await tutor.selectOption('select[name=block_type]', 'poll');
await tutor.fill('form[action$="/blocks"] textarea[name=config]', '{"question":"Guess first?","options":["Yes","No"],"results":"on_reveal"}');
await tutor.click('form[action$="/blocks"] button[type=submit]');
await tutor.click('text=Start live session');
await tutor.waitForURL(/\/sessions\/\d+$/);
const code = (await tutor.textContent('.joincode code')).trim();
step('poll with results "on_reveal" running');

const frames = { p1: [], p2: [] };
const mk = async (name) => {
  const p = await (await browser.newContext()).newPage(); watch(p, name);
  p.on('websocket', (ws) => ws.on('framereceived', (f) => frames[name].push(String(f.payload))));
  await p.goto(B + '/join'); await p.fill('[name=code]', code); await p.click('#join-form button');
  await p.waitForSelector('#session-view:not([hidden])'); return p;
};
const p1 = await mk('p1'); const p2 = await mk('p2');
await p2.waitForSelector('[data-testid=results-hidden]');
step('participants see "results later", not the (empty) chart');

await p1.check('input[value=o1]'); await p1.click('.activity button[type=submit]');
await p1.waitForFunction(() => document.getElementById('feedback').textContent === 'Answer saved.');
await tutor.waitForFunction(() => document.querySelector('#tutor-results')?.textContent.includes('1 response'), null, { timeout: 5000 });
if (!(await tutor.isVisible('[data-testid=results-hidden]'))) throw new Error('tutor does not see the hidden notice');
step('tutor sees the live count and the "hidden" notice');
await p2.waitForTimeout(1000); // give a leaked broadcast time to arrive
for (const p of [p1, p2]) {
  if ((await p.textContent('#results')).includes('response')) throw new Error('count visible to a participant before reveal');
}
const leaked = [...frames.p1, ...frames.p2].filter((f) => f.includes('activity_aggregate_update'));
if (leaked.length) throw new Error('aggregate frames reached participants before reveal: ' + leaked.length);
await p2.reload(); await p2.waitForSelector('[data-testid=results-hidden]');
step('no count in participant pages or WebSocket frames before reveal (still hidden after reload)');

await tutor.click('[data-testid=results-hidden] button');
await tutor.waitForSelector('[data-testid=results-revealed]');
for (const p of [p1, p2]) await p.waitForFunction(() => document.getElementById('results')?.textContent.includes('1 response'), null, { timeout: 5000 });
// proves the frame capture above would have seen a leak
if (!frames.p1.some((f) => f.includes('activity_aggregate_update'))) throw new Error('frame capture saw no aggregate update after reveal');
step('tutor reveals: both participants see the result live (update arrived over the relay)');
await p2.reload(); await p2.waitForFunction(() => document.getElementById('results')?.textContent.includes('1 response'), null, { timeout: 5000 });
step('still visible after reload');

console.log('problems:', JSON.stringify(problems));
if (problems.length) process.exitCode = 1;
await browser.close();
