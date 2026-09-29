// End-to-end browser test of the tutor console and participant client against a running
// stack (PHP app + relay + MariaDB). See e2e/README.md.
import { signUpTutor } from './lib.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const OUTBOX = process.env.MAIL_OUTBOX || '/var/lib/tutora/mail-outbox';
const EMAIL = `e2e-${Date.now()}@example.org`;
const problems = [];
const watch = (page, name) => { page.on('console', m => { if (['error', 'warning'].includes(m.type())) problems.push(`${name}: ${m.text()}`); }); page.on('pageerror', e => problems.push(`${name} pageerror: ${e.message}`)); page.on('dialog', d => { problems.push(`${name} DIALOG ${d.message()}`); d.dismiss(); }); };
const step = (m) => console.log('✓', m);

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const tutor = await (await browser.newContext()).newPage(); watch(tutor, 'tutor');
await signUpTutor(tutor, B, OUTBOX, EMAIL); step('tutor signed up (email link + password) with TOTP');

await tutor.fill('[name=title]', 'E2E <i>workshop</i>'); await tutor.click('form[action="/workshops"] button');
const blocks = [
  ['poll', '{"question":"Ready?","options":["Yes","No"]}'],
  ['write', '{"prompt":"Reflect"}'],
  ['wall', '{"columns":["Good","Improve"]}'],
  ['quiz', '{"pacing":"tutor","questions":[{"id":"q1","type":"single","prompt":"Best password hash?","options":["MD5","Argon2id"],"correct_answer":"o2","time_limit_seconds":60}]}'],
];
for (const [type, cfg] of blocks) {
  await tutor.selectOption('select[name=block_type]', type);
  await tutor.fill('form[action$="/blocks"] textarea[name=config]', cfg);
  await tutor.click('form[action$="/blocks"] button[type=submit]');
}
await tutor.click('text=Start live session');
await tutor.waitForURL(/\/sessions\/\d+$/);
const code = (await tutor.textContent('.joincode code')).trim(); step(`session started, code ${code}`);

const mk = async (name) => { const p = await (await browser.newContext()).newPage(); watch(p, name);
  await p.goto(B + '/join'); await p.fill('[name=code]', code); await p.click('#join-form button');
  await p.waitForSelector('#session-view:not([hidden])'); return p; };
const p1 = await mk('p1'); const p2 = await mk('p2'); step('two participants joined');
await tutor.waitForFunction(() => document.getElementById('presence').textContent.startsWith('2 participants'), null, { timeout: 5000 }); step('tutor sees live presence "2 participants connected"');

// Poll
await p1.check('input[value=o1]'); await p1.click('.activity button[type=submit]');
await p1.waitForFunction(() => document.getElementById('feedback').textContent === 'Answer saved.');
await tutor.waitForFunction(() => document.querySelector('#tutor-results')?.textContent.includes('1 response'), null, { timeout: 5000 });
await p2.waitForFunction(() => document.getElementById('results').textContent.includes('1 response'), null, { timeout: 5000 });
step('poll answer -> live aggregate on tutor and other participant (no reload)');

// Write
await tutor.click('form[action$="/navigate"] >> text=Next');
await p1.waitForSelector('textarea', { timeout: 5000 });
await p1.fill('textarea', 'private <img src=x onerror=alert(1)> note'); await p1.click('.activity button[type=submit]');
await tutor.waitForFunction(() => document.querySelector('.response-text')?.textContent.includes('private <img'), null, { timeout: 5000 });
if (await tutor.locator('#tutor-results img').count() !== 0) throw new Error('markup rendered on tutor console');
await p2.waitForFunction(() => document.getElementById('results').textContent.includes('1 response'), null, { timeout: 5000 });
if ((await p2.textContent('body')).includes('private')) throw new Error('Write text leaked to other participant');
step('write response: tutor sees it as text, other participant sees only the count');

// Wall
await tutor.click('form[action$="/navigate"] >> text=Next');
await p2.waitForSelector('.activity textarea', { timeout: 5000 });
await p2.fill('.activity textarea', 'spam card'); await p2.click('.activity button[type=submit]');
await p1.waitForFunction(() => document.querySelector('.wall')?.textContent.includes('spam card'), null, { timeout: 5000 });
await tutor.waitForFunction(() => document.querySelector('#tutor-results')?.textContent.includes('spam card'), null, { timeout: 5000 });
await tutor.click('#tutor-results button:has-text("Remove this participant")');
await tutor.waitForURL(/\/sessions\/\d+$/);
await p1.waitForFunction(() => !document.querySelector('.wall')?.textContent.includes('spam card'), null, { timeout: 5000 });
step('wall card appears live for others; tutor removal propagates');

// Quiz
await tutor.click('form[action$="/navigate"] >> text=Next');
await tutor.waitForSelector('form[action$="/quiz/start"] button');
await tutor.click('form[action$="/quiz/start"] button');
await p1.waitForSelector('.quiz-q button:has-text("Argon2id")', { timeout: 5000 });
const qHtml = await p1.content(); if (qHtml.includes('correct_answer')) throw new Error('answer key in page');
await p1.click('.quiz-q button:has-text("Argon2id")');
await p1.waitForFunction(() => document.body.textContent.includes('Answer received'), null, { timeout: 5000 });
await p2.waitForSelector('.quiz-q button:has-text("MD5")'); await p2.click('.quiz-q button:has-text("MD5")');
await tutor.waitForSelector('form[action$="/quiz/reveal"] button'); await tutor.click('form[action$="/quiz/reveal"] button');
await p1.waitForFunction(() => document.body.textContent.includes('You were right!'), null, { timeout: 5000 });
await p2.waitForFunction(() => document.body.textContent.includes('Your answer: MD5'), null, { timeout: 5000 });
await tutor.waitForFunction(() => document.querySelector('#tutor-results')?.textContent.includes('2 answer(s), 1 correct'), null, { timeout: 5000 });
step('tutor-paced quiz: start -> answers -> reveal, results on all screens');


// ---- second session: remaining activity types + self-paced quiz
await tutor.goto(B + '/dashboard');
await tutor.fill('[name=title]', 'E2E all types'); await tutor.click('form[action="/workshops"] button');
for (const [type, cfg] of [
  ['meter', '{"prompt":"Confidence","min":0,"max":10,"step":1}'],
  ['rate', '{"items":["Pace","Clarity"],"scale":5}'],
  ['rank', '{"items":["A","B","C"]}'],
  ['word', '{"items":["Fun","Hard","Useful"],"max_selections":2}'],
  ['plot', '{"x_axis":{"label":"Effort","min":0,"max":10},"y_axis":{"label":"Impact","min":0,"max":10},"items":["Idea"]}'],
  ['word_cloud', '{"prompt":"One word","max_words_per_participant":2}'],
  ['quiz', '{"pacing":"self","questions":[{"type":"true_false","prompt":"Self Q1?","correct_answer":true,"time_limit_seconds":60},{"type":"numeric","prompt":"Self Q2?","correct_answer":42}]}'],
]) {
  await tutor.selectOption('select[name=block_type]', type);
  await tutor.fill('form[action$="/blocks"] textarea[name=config]', cfg);
  await tutor.click('form[action$="/blocks"] button[type=submit]');
}
await tutor.click('text=Start live session'); await tutor.waitForURL(/\/sessions\/\d+$/);
const code2 = (await tutor.textContent('.joincode code')).trim();
const p3 = await (await browser.newContext()).newPage(); watch(p3, 'p3');
await p3.goto(B + '/join'); await p3.fill('[name=code]', code2); await p3.click('#join-form button');
await p3.waitForSelector('#session-view:not([hidden])');
const saved = async (label) => {
  await p3.waitForFunction(() => { const f = document.getElementById('feedback'); return !f.hidden && f.className === 'notice'; }, null, { timeout: 5000 });
  await tutor.waitForFunction(() => document.querySelector('#tutor-results')?.textContent.includes('1 response'), null, { timeout: 5000 });
  step(`${label}: submitted, tutor sees live result`);
};
const next = async (sel) => { await tutor.click('form[action$="/navigate"] >> text=Next'); await tutor.waitForURL(/\/sessions\/\d+$/); await p3.waitForSelector(sel, { timeout: 5000 }); };

await p3.waitForSelector('input[type=range]');
await p3.fill('input[type=range]', '7'); await p3.click('.activity button[type=submit]'); await saved('meter');
await next('.activity select'); await p3.selectOption('.activity select[name=i1]', '4'); await p3.click('.activity button[type=submit]'); await saved('rate');
await next('ol.rank'); await p3.click('ol.rank li:nth-child(3) button[aria-label="Move up"]'); await p3.click('.activity button[type=submit]'); await saved('rank');
if (!(await tutor.textContent('#tutor-results')).includes('A (2 pts)')) throw new Error('Borda result not shown');
await next('.activity input[value=i1]'); await p3.check('input[value=i1]'); await p3.check('input[value=i3]'); await p3.click('.activity button[type=submit]'); await saved('word');
await next('fieldset[data-item=i1]'); await p3.fill('fieldset[data-item=i1] [name=x]', '3'); await p3.fill('fieldset[data-item=i1] [name=y]', '8'); await p3.click('.activity button[type=submit]'); await saved('plot');
if (await tutor.locator('#tutor-results svg circle').count() !== 1) throw new Error('plot point not drawn');
await next('.activity input[aria-label="Word 1"]'); await p3.fill('[aria-label="Word 1"]', 'Zero Trust'); await p3.click('.activity button[type=submit]'); await saved('word cloud');

await next('button:has-text("Start question")');
await p3.click('button:has-text("Start question")');
await p3.waitForSelector('.quiz-q button:has-text("True")'); await p3.click('.quiz-q button:has-text("True")');
await p3.waitForSelector('button:has-text("Start question")'); await p3.click('button:has-text("Start question")');
await p3.waitForSelector('.quiz-q input[type=number]'); await p3.fill('.quiz-q input[type=number]', '41'); await p3.click('.quiz-q button:has-text("Answer")');
await p3.waitForFunction(() => document.body.textContent.includes('Finished — score 1 / 2'), null, { timeout: 5000 });
step('self-paced quiz: sequential questions, review + score only at the end');

console.log('problems:', JSON.stringify(problems));
if (problems.length) process.exitCode = 1;
await browser.close();
