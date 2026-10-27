let active = false;
let registration;
let syncing;
const enabled = () => localStorage.getItem('onchat.aviso') !== 'nao';
const user = () => document.querySelector('meta[name="onchat-usuario"]')?.content;
const supported = () => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
async function api(method, body) {
    const response = await fetch('/browser-push/subscriptions', {
        method, credentials: 'same-origin', headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        }, body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('Não foi possível salvar as notificações. Atualize a página e tente novamente.');
}
function bytes(value) {
    return Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), char => char.charCodeAt(0));
}
export const browserPushActive = () => active;
export async function enableBrowserPush() {
    if (!user() || !supported()) throw new Error('Este navegador não oferece notificações em segundo plano.');
    if (Notification.permission !== 'granted' && await Notification.requestPermission() !== 'granted') {
        throw new Error('Permita as notificações nas configurações do site no navegador.');
    }
    registration = await navigator.serviceWorker.register('/virtus-push-sw.js', { scope: '/', updateViaCache: 'none' });
    await navigator.serviceWorker.ready;
    const response = await fetch('/browser-push/key', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error('Notificações temporariamente indisponíveis.');
    const { publicKey } = await response.json();
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(publicKey) });
    await api('POST', subscription.toJSON());
    active = true;
}
export async function disableBrowserPush() {
    if (syncing) await syncing;
    active = false;
    if (!supported()) return;
    registration = registration || await navigator.serviceWorker.getRegistration('/virtus-push-sw.js');
    const subscription = await registration?.pushManager.getSubscription();
    if (subscription) {
        await api('DELETE', { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
    }
    const notifications = await registration?.getNotifications();
    notifications?.forEach(notification => notification.close());
}
async function restore() {
    if (syncing || !user() || !supported()) return;
    if (!enabled()) {
        syncing = disableBrowserPush().catch(() => {}).finally(() => { syncing = null; });
        return syncing;
    }
    if (Notification.permission !== 'granted') return;
    syncing = enableBrowserPush().catch(() => { active = false; }).finally(() => { syncing = null; });
    return syncing;
}
document.addEventListener('livewire:navigated', restore);
window.addEventListener('focus', restore);
window.addEventListener('storage', event => { if (event.key === 'onchat.aviso') restore(); });
setInterval(restore, 6 * 60 * 60 * 1000);
restore();

export async function testBrowserPush() {
    const subscription = await registration?.pushManager.getSubscription();
    const response = await fetch('/browser-push/test', {
        method: 'POST', credentials: 'same-origin', headers: {
            'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        }, body: JSON.stringify({ endpoint: subscription?.endpoint }),
    });
    return response.ok;
}
