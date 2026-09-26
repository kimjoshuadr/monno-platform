import {api, AUTH_TOKEN_KEY} from "../api/client.ts";
import {publicApi} from "../api/public-client.ts";

export const setAuthToken = (token?: string | undefined | null) => {
    if (!token) {
        delete api.defaults.headers.common['Authorization'];
        delete publicApi.defaults.headers.common['Authorization'];
        if (typeof window !== 'undefined') {
            window.localStorage?.removeItem(AUTH_TOKEN_KEY);
        }
        return;
    }

    api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    publicApi.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    if (typeof window !== 'undefined') {
        window.localStorage?.setItem(AUTH_TOKEN_KEY, token);
    }
};
