/**
 * Tiny JSON client for /api/v1. Same-origin, cookie-based (Sanctum SPA).
 */
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const xsrfCookie = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
};

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message ?? `Request failed (${status})`);
        this.status = status;
        this.body = body ?? {};
        this.errors = body?.errors ?? {};
        this.reason = body?.reason ?? null;
    }

    /** First validation message, or the generic message. */
    get firstError() {
        return Object.values(this.errors).flat()[0] ?? this.message;
    }

    /** The request never reached the server (offline, DNS, aborted). */
    get isNetwork() {
        return this.status === 0;
    }
}

/**
 * Hooks the app registers so that the client stays free of router/store imports:
 *  - onNotEntitled(error): the account lost portal access
 *  - onUnauthenticated(error): the session expired while signed in
 *  - networkErrorMessage(): translated text for a failed connection
 */
export const hooks = { onNotEntitled: null, onUnauthenticated: null, networkErrorMessage: null };

async function send(method, path, data) {
    const headers = { Accept: 'application/json' };
    if (data !== undefined) headers['Content-Type'] = 'application/json';
    const xsrf = xsrfCookie();
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
    else headers['X-CSRF-TOKEN'] = csrfToken();

    try {
        return await fetch(`/api/v1${path}`, {
            method,
            headers,
            credentials: 'same-origin',
            body: data === undefined ? undefined : JSON.stringify(data),
        });
    } catch {
        throw new ApiError(0, { message: hooks.networkErrorMessage?.() ?? 'No connection. Check your network and try again.', reason: 'network' });
    }
}

/** A fresh CSRF cookie after the session token went stale (419). */
async function refreshCsrf() {
    try {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch {
        /* the retry below reports the real problem */
    }
}

export async function api(method, path, data, { retried = false } = {}) {
    const response = await send(method, path, data);

    if (response.status === 419 && !retried) {
        await refreshCsrf();
        return api(method, path, data, { retried: true });
    }

    const body = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        const error = new ApiError(response.status, body);
        if (error.status === 403 && ['not_entitled', 'account_closed'].includes(error.reason)) hooks.onNotEntitled?.(error);
        if (error.status === 401) hooks.onUnauthenticated?.(error);
        throw error;
    }

    return body;
}

export const get = (path) => api('GET', path);
export const post = (path, data = {}) => api('POST', path, data);
export const put = (path, data = {}) => api('PUT', path, data);
export const del = (path) => api('DELETE', path);
