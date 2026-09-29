// A password change in one browser ends the tutor's other sessions: the other browser's
// next request is signed out, and its open relay connection is closed by the relay
// (not just left to expire). The browser that changed the password keeps working.
import { signUpTutor, totp } from './lib.mjs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8099';
const OUTBOX = process.env.MAIL_OUTBOX || '/var/lib/tutora/mail-outbox';
const EMAIL = `e2e-rev-${Date.now()}@example.org`;
const PW = 'a long enough passphrase';
const step = (m) => console.log('✓', m);
// TOTP codes are single-use per 30 s step (replay protection): wait for the next step
const nextStep = async () => { const s = Math.floor(Date.now() / 30000); while (Math.floor(Date.now() / 30000) === s) await new Promise((r) => setTimeout(r, 250)); };

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const a = await (await browser.newContext()).newPage();
let secret = '';
await signUpTutor(a, B, OUTBOX, EMAIL, PW, async (p) => { secret = (await p.textContent('p.secret code')).trim(); });
step('browser A: tutor signed up');

await a.fill('[name=title]', 'Revocation'); await a.click('form[action="/workshops"] button');
await a.selectOption('select[name=block_type]', 'poll');
await a.fill('form[action$="/blocks"] textarea[name=config]', '{"question":"Q?","options":["Yes","No"]}');
await a.click('form[action$="/blocks"] button[type=submit]');
const relayClosed = new Promise((resolve) => a.on('websocket', (ws) => { if (ws.url().includes('/ws')) ws.on('close', () => resolve(Date.now())); }));
await a.click('text=Start live session');
await a.waitForURL(/\/sessions\/\d+$/);
const sessionUrl = a.url();
await a.waitForFunction(() => /participants? connected/.test(document.getElementById('presence')?.textContent ?? ''), null, { timeout: 5000 });
step('browser A: live session open, relay connected');

const b = await (await browser.newContext()).newPage();
await nextStep();
await b.goto(B + '/login');
await b.fill('[name=email]', EMAIL); await b.fill('[name=password]', PW); await b.click('form[action="/login"] button');
await b.waitForURL(B + '/login/mfa');
await b.fill('[name=code]', totp(secret)); await b.click('form[action="/login/mfa"] button');
await b.waitForURL(B + '/dashboard');
step('browser B: signed in to the same account');

await nextStep();
await b.goto(B + '/account/password');
await b.fill('form[action="/account/password"] [name=current_password]', PW);
await b.fill('form[action="/account/password"] [name=new_password]', 'a brand new passphrase');
await b.fill('form[action="/account/password"] [name=code]', totp(secret));
const submittedAt = Date.now();
await b.click('form[action="/account/password"] button');
await b.waitForSelector('text=Your password has been changed.');
step('browser B: password changed');

const closedAt = await Promise.race([relayClosed, new Promise((_, rej) => setTimeout(() => rej(new Error('relay connection of browser A was not closed')), 5000))]);
step(`browser A: relay connection closed by the server ${closedAt - submittedAt} ms after submitting the change`);
await a.waitForURL(B + '/login', { timeout: 10000 });
step('browser A: tutor page noticed the lost sign-in and went to /login');
await a.goto(sessionUrl);
if (!a.url().endsWith('/login')) throw new Error('browser A still signed in');
step('browser A: session page requires signing in again');

await b.goto(B + '/dashboard');
if (!b.url().endsWith('/dashboard')) throw new Error('browser B lost its session');
step('browser B: still signed in');
await browser.close();
