import { createRouter, createWebHistory } from 'vue-router';
import { store } from './store';
import { hooks } from './api';
import { t } from './i18n';
import Login from './views/Login.vue';
import Home from './views/Home.vue';
import Questions from './views/Questions.vue';
import Pin from './views/Pin.vue';
import Phones from './views/Phones.vue';
import MobileCode from './views/MobileCode.vue';
import News from './views/News.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', component: Login, meta: { guest: true, entry: 'standard' } },
        { path: '/premium/login', component: Login, meta: { guest: true, entry: 'premium' } },
        { path: '/premium', redirect: () => (store.user ? '/' : '/premium/login') },
        { path: '/', component: Home },
        { path: '/questions', component: Questions },
        { path: '/pin', component: Pin },
        { path: '/phones', component: Phones },
        { path: '/mobile-code', component: MobileCode },
        { path: '/news', component: News },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
    scrollBehavior: () => ({ top: 0 }),
});

export const loginPath = () => (store.entry === 'premium' ? '/premium/login' : '/login');

router.beforeEach(async (to) => {
    if (to.meta.entry || to.path.startsWith('/premium')) {
        store.entry = to.meta.entry ?? 'premium';
        store.applyTheme();
    }

    if (!store.booted) await store.boot();

    // Could not reach the server: let the shell render its retry screen.
    if (store.networkError) return true;

    if (to.meta.guest) return store.user ? '/' : true;
    if (!store.user) return { path: loginPath(), query: to.fullPath !== '/' ? { next: to.fullPath } : {} };

    return true;
});

hooks.onNotEntitled = (error) => {
    store.forgetUser(error?.reason === 'account_closed' ? 'account_closed' : 'not_entitled');
    router.push(loginPath());
};

// The session ran out while the client was signed in: back to the login page
// with a notice. A 401 for a guest (the boot-time /me probe) is not an event.
hooks.onUnauthenticated = () => {
    if (!store.user) return;
    store.forgetUser('session_expired');
    router.push({ path: loginPath(), query: router.currentRoute.value.path !== '/' ? { next: router.currentRoute.value.fullPath } : {} });
};

hooks.networkErrorMessage = () => t('No connection. Check your network and try again.');

export default router;
