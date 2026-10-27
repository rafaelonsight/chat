<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#734cf7">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Virtus Chat">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<script>
if ('serviceWorker' in navigator && window.isSecureContext) {
 window.addEventListener('load', () => navigator.serviceWorker.register('/virtus-push-sw.js', {scope:'/', updateViaCache:'none'}).catch(() => {}), {once:true});
}
</script>
