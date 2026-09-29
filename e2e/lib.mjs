// Shared E2E helpers.
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const b32 = (s) => { const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s.replace(/ /g, '')) bits += A.indexOf(c).toString(2).padStart(5, '0'); return Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2))); };
export const totp = (secretB32) => { const secret = b32(secretB32); const c = Buffer.alloc(8); c.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000))); const h = crypto.createHmac('sha1', secret).update(c).digest(); const o = h[19] & 15; return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0'); };

/** Verification token from the newest mail to `email` in the dev file outbox (MAIL_DRIVER=file). */
async function tokenFromOutbox(outbox, email) {
  for (let i = 0; i < 50; i++) {
    const files = fs.existsSync(outbox) ? fs.readdirSync(outbox).filter((f) => f.endsWith('.eml')).sort().reverse() : [];
    for (const f of files) {
      const raw = fs.readFileSync(path.join(outbox, f), 'utf8');
      if (!raw.includes(`To: <${email}>`)) continue;
      const body = raw.split('\r\n\r\n').slice(1).join('\r\n\r\n').replace(/=\r\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
      const m = /#t=([A-Za-z0-9_-]+)/.exec(body);
      if (m) return m[1];
    }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`no verification mail for ${email} in ${outbox}`);
}

/** Signup -> email link (+ password) -> TOTP enrolment -> dashboard. */
export async function signUpTutor(page, base, outbox, email, password = 'a long enough passphrase') {
  await page.goto(base + '/signup');
  await page.fill('[name=display_name]', 'Tutor');
  await page.fill('[name=email]', email);
  await page.fill('[name=password]', password);
  await page.click('button[type=submit]');
  await page.waitForSelector('text=Check your inbox');
  const token = await tokenFromOutbox(outbox, email);
  await page.goto(`${base}/verify-email#t=${token}`);
  if (page.url().includes('#')) throw new Error('token not removed from the address bar');
  await page.fill('[name=password]', password);
  await page.click('#verify-form button');
  await page.waitForURL(base + '/login/enroll');
  await page.fill('[name=code]', totp(await page.textContent('p.secret code')));
  await page.click('form[action="/login/enroll"] button');
  await page.waitForURL(base + '/dashboard');
}
