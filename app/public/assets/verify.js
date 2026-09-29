// Moves the one-time token from the URL fragment (never sent to the server, so it cannot
// appear in access logs) into the form, then removes it from the address bar.
const m = /(?:^#|&)t=([A-Za-z0-9_-]{20,100})/.exec(location.hash);
const input = document.getElementById('verify-token');
if (m && input) {
  input.value = m[1];
  history.replaceState(null, '', location.pathname);
} else if (input && !input.value) {
  document.getElementById('verify-missing').hidden = false;
}
