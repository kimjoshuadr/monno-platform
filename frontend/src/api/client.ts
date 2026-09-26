import axios, {type InternalAxiosRequestConfig} from "axios";
import {isSsr} from "../utilites/helpers.ts";
import {getConfig} from "../utilites/config.ts";

const BASE_URL = isSsr()
    ? getConfig('VITE_API_URL_SERVER')
    : getConfig('VITE_API_URL_CLIENT');
const LOGIN_PATH = "/auth/login";
const PREVIOUS_URL_KEY = 'previous_url';

// todo - This isn't scalable, we need to better way to manage this
const ALLOWED_UNAUTHENTICATED_PATHS = [
    'auth/login',
    'accept-invitation',
    'register',
    'forgot-password',
    'auth',
    'account/payment',
    'checkout',
    '/event/',
    'print',
    '/order/',
    'widget',
    '/product/',
    'check-in',
    '/events/',
    'my-tickets',
];

export const AUTH_TOKEN_KEY = 'token';

/**
 * Attach the stored auth token to a request. Shared by the authenticated client and the
 * public client: public endpoints treat an authenticated request as the owner, which is
 * how a designer preview renders a draft event — relying on the auth cookie alone breaks
 * that as soon as the cookie expires, even though the token still works everywhere else.
 */
export const applyAuthToken = <T extends InternalAxiosRequestConfig>(config: T): T => {
    if (typeof window !== 'undefined') {
        const token = window.localStorage?.getItem(AUTH_TOKEN_KEY);
        if (token && !config.headers['Authorization']) {
            config.headers['Authorization'] = `Bearer ${token}`;
        }
    }
    return config;
};

export const api = axios.create({
    baseURL: BASE_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

if (typeof window !== 'undefined') {
    const existingToken = window.localStorage?.getItem(AUTH_TOKEN_KEY);
    if (existingToken) {
        api.defaults.headers.common['Authorization'] = `Bearer ${existingToken}`;
    }
}

api.interceptors.request.use((config) => applyAuthToken(config));

api.interceptors.response.use(
    (response) => {
        const token = response.headers?.['x-auth-token'] || response.data?.token;
        if (token && typeof window !== 'undefined') {
            window.localStorage?.setItem(AUTH_TOKEN_KEY, token);
            api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        }
        return response;
    },
    (error) => {
        if (!error.response) {
            return Promise.reject(error);
        }
        const { status } = error.response;
        const currentPath = window?.location.pathname;
        const isAllowedUnauthenticatedPath = ALLOWED_UNAUTHENTICATED_PATHS.some(path => currentPath.includes(path));
        const isManageEventPath = currentPath.startsWith('/manage/event/');
        const isAuthError = status === 401 || status === 403;

        if (status === 403 && error.response.data?.error_code === 'ACCOUNT_PENDING_DELETION') {
            if (!currentPath.startsWith('/account')) {
                window?.location?.replace('/account/danger-zone');
            }
            return Promise.reject(error);
        }

        if (isAuthError && (!isAllowedUnauthenticatedPath || isManageEventPath)) {
            if (typeof window !== 'undefined') {
                window.localStorage?.removeItem(AUTH_TOKEN_KEY);
                delete api.defaults.headers.common['Authorization'];
            }
            window?.localStorage?.setItem(PREVIOUS_URL_KEY, window?.location.href);
            const searchParams = window?.location?.search || '';
            window?.location?.replace(LOGIN_PATH + searchParams);
        }

        return Promise.reject(error);
    }
);

axios.defaults.withCredentials = true;

export const clearAuthToken = () => {
    if (typeof window !== 'undefined') {
        window.localStorage?.removeItem(AUTH_TOKEN_KEY);
        delete api.defaults.headers.common['Authorization'];
    }
};

export const redirectToPreviousUrl = () => {
    const previousUrl = window?.localStorage?.getItem(PREVIOUS_URL_KEY) || '/manage/events';
    window?.localStorage?.removeItem(PREVIOUS_URL_KEY);
    if (typeof window !== "undefined") {
        window.location.href = previousUrl;
    }
};
