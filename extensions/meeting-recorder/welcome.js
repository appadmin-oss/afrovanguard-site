/* welcome.js — first-run setup: the microphone permission (asked here because
 * the offscreen recorder cannot show Chrome's prompt) and the account link. */
const $ = (id) => document.getElementById(id);

async function micState() {
  try { return (await navigator.permissions.query({ name: 'microphone' })).state; } catch (e) { return 'prompt'; }
}
async function paint() {
  const s = await micState();
  if (s === 'granted') { $('mic-state').textContent = 'Allowed.'; $('mic-state').className = 'ok'; $('mic').hidden = true; }
  else if (s === 'denied') { $('mic-state').textContent = 'Blocked. Click the icon at the right of the address bar, allow the microphone, then reload this page.'; }
  const st = await chrome.runtime.sendMessage({ type: 'status' });
  if (st && st.connected) { $('who').textContent = 'Connected as ' + ((st.user && (st.user.name || st.user.email)) || 'you') + '.'; $('who').className = 'ok'; $('connect').hidden = true; }
}
$('mic').onclick = async () => {
  try {
    const s = await navigator.mediaDevices.getUserMedia({ audio: true });
    s.getTracks().forEach((t) => t.stop());
    await chrome.runtime.sendMessage({ type: 'mic-granted' });
  } catch (e) { $('mic-state').textContent = 'Chrome did not allow it (' + e.name + ').'; }
  paint();
};
$('connect').onclick = () => chrome.runtime.sendMessage({ type: 'connect' });
document.addEventListener('visibilitychange', () => { if (!document.hidden) paint(); });
paint();
