declare const __APP_VERSION__: string;

interface ChatwootSDK {
    run: (options: { websiteToken: string; baseUrl: string }) => void;
}

interface ChatwootUser {
    setUser: (id: string, attributes: Record<string, unknown>) => void;
    setCustomAttributes: (attributes: Record<string, unknown>) => void;
}

interface Window {
    chatwootSDK: ChatwootSDK;
    $chatwoot?: ChatwootUser;
}
