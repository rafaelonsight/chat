/* Apenas notificações: não intercepta nem armazena páginas ou conversas. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let payload;
        try { payload = event.data?.json(); } catch (_) { return; }
        if (!payload) return;
        await self.registration.showNotification(payload.title || 'Virtus Chat', {
            body: payload.body || 'Nova mensagem recebida.',
            icon: '/apple-touch-icon.png', badge: '/apple-touch-icon.png',
            tag: payload.tag || 'virtus-mensagem',
            data: { url: payload.url, userId: payload.userId },
        });
    })());
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil((async () => {
        const url = new URL(event.notification.data?.url || '/admin', self.location.origin);
        if (url.origin !== self.location.origin || url.pathname !== '/admin') return;
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && new URL(client.url).pathname.startsWith('/admin')) {
                await client.navigate(url.href);
                await client.focus();
                return;
            }
        }
        await self.clients.openWindow(url.href);
    })());
});
