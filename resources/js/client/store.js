import { reactive } from 'vue';
import { get, post, ApiError } from './api';

const EMPTY_TIER = { background_image: null, login_background_image: null, badge_label: null, support: { email: null, phone: null, hours: null } };

let toastId = 0;

let booting = null;

export const store = reactive({
    branding: window.__BRANDING__ ?? { app_name: 'Helpdesk ID', primary_color: '#36495d', palette: {}, tiers: {} },
    loginMethods: { magic_link: false, otp_sms: false, adfs: false, entra: false },
    newsOnOverview: true,
    user: null,
    booted: false,

    /** Set when the portal could not reach the server while starting; the app shows a retry screen. */
    networkError: false,

    /** Which entrance the visitor used before signing in: 'standard' | 'premium'. */
    entry: 'standard',

    /** A one-off message for the login page (e.g. after being signed out). */
    notice: null,

    /** When the identification code was last generated (ms), survives route changes. */
    codeGeneratedAt: null,

    toasts: [],

    get tier() {
        return this.user?.tier ?? null;
    },

    /** The tier that decides the look: the real one when signed in, the entrance otherwise. */
    get look() {
        return this.tier ?? this.entry;
    },

    get isPremium() {
        return this.look === 'premium';
    },

    get tierTheme() {
        return this.branding.tiers?.[this.look] ?? EMPTY_TIER;
    },

    /** Support contact of the current tier / entrance. */
    get support() {
        const contact = (this.user?.support ?? this.tierTheme.support) ?? {};
        return contact.email || contact.phone || contact.hours ? contact : null;
    },

    get premiumBadgeLabel() {
        return this.branding.tiers?.premium?.badge_label || null;
    },

    async boot() {
        // One start-up at a time: a 403 during the /me probe navigates to the
        // login page, whose guard would otherwise start a second boot.
        if (!booting) booting = this.startUp().finally(() => { booting = null; });
        return booting;
    },

    async startUp() {
        try {
            const info = await get('/branding');
            this.branding = info.branding;
            this.loginMethods = info.login_methods;
            this.newsOnOverview = info.news_on_overview ?? true;
            document.title = this.branding.app_name;
        } catch {
            /* keep the inline defaults */
        }

        try {
            await this.refreshUser();
            this.networkError = false;
        } catch {
            // A 500 or an unreachable server must not leave an empty page behind.
            this.user = null;
            this.networkError = true;
        }
        this.booted = true;
        this.applyTheme();
    },

    /** Try the start-up again after a connection problem. */
    async retryBoot() {
        this.booted = false;
        await this.boot();
        return !this.networkError;
    },

    async refreshUser() {
        try {
            this.user = (await get('/client/me')).data;
            if (this.user?.tier) this.entry = this.user.tier;
        } catch (e) {
            if (e instanceof ApiError && (e.status === 401 || e.status === 403)) this.user = null;
            else throw e;
        }
        this.applyTheme();
        return this.user;
    },

    /** Drop the signed-in state locally (session expired, access lost). */
    forgetUser(notice = null) {
        if (this.tier) this.entry = this.tier;
        this.user = null;
        this.codeGeneratedAt = null;
        if (notice) this.notice = notice;
        this.applyTheme();
    },

    async logout() {
        try {
            await post('/client/logout');
        } catch {
            /* the local state is cleared either way */
        } finally {
            this.forgetUser();
        }
    },

    /** Ends this session and every other one: mobile tokens and browser sessions alike. */
    async logoutAll() {
        try {
            await post('/client/logout-all');
        } catch {
            /* the local state is cleared either way */
        } finally {
            this.forgetUser();
        }
    },

    /** Push palette, tier and background onto <html> so that CSS can theme by them. */
    applyTheme() {
        const root = document.documentElement;
        Object.entries(this.branding.palette ?? {}).forEach(([name, value]) => root.style.setProperty(`--brand-${name}`, value));
        root.dataset.tier = this.look;

        const theme = this.tierTheme;
        const image = this.user ? theme.background_image : (theme.login_background_image ?? theme.background_image);
        if (image) {
            root.style.setProperty('--page-bg-image', `url("${image}")`);
            root.dataset.hasBg = 'true';
        } else {
            root.style.removeProperty('--page-bg-image');
            delete root.dataset.hasBg;
        }
    },

    toast(message, type = 'success', timeout = 4000) {
        const id = ++toastId;
        this.toasts.push({ id, message, type });
        if (timeout) setTimeout(() => this.dismissToast(id), timeout);
        return id;
    },

    dismissToast(id) {
        this.toasts = this.toasts.filter((toast) => toast.id !== id);
    },
});
