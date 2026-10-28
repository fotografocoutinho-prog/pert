import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { appUrl } from './utils/base';
import '../css/app.css';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');

// PWA: o service worker só guarda o shell da aplicação, nunca respostas da API.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(appUrl('/sw.js'), { scope: appUrl('/') }).catch(() => undefined);
    });
}
