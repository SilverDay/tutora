// Draws the TOTP provisioning URI as a QR code on a canvas (owner decision 2a).
// Everything stays in the browser: the secret is never sent to a third-party QR service.
import qrcode from './vendor/qrcode-generator-2.0.4.js';

const canvas = document.getElementById('totp-qr');
const uri = canvas?.dataset.otpauth ?? '';
if (canvas && uri.startsWith('otpauth://totp/')) {
  const qr = qrcode(0, 'M'); // smallest version that fits, medium error correction
  qr.addData(uri, 'Byte'); // URI is ASCII (percent-encoded)
  qr.make();
  const modules = qr.getModuleCount();
  const quiet = 4; // quiet zone required by the QR spec
  const scale = 5;
  const size = (modules + quiet * 2) * scale;
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, size, size);
  ctx.fillStyle = '#000';
  for (let row = 0; row < modules; row++) {
    for (let col = 0; col < modules; col++) {
      if (qr.isDark(row, col)) ctx.fillRect((col + quiet) * scale, (row + quiet) * scale, scale, scale);
    }
  }
  canvas.hidden = false;
}
