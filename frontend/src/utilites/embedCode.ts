/* eslint-disable lingui/no-unlocalized-strings -- generated code, not UI copy */
import {getConfig} from "./config.ts";

export type EmbedType = 'tickets' | 'button' | 'card' | 'calendar';

export interface EmbedOptions {
    type: EmbedType;
    eventId?: string | number;
    organizerId?: string | number;
    theme: 'light' | 'dark' | 'auto';
    accent: string;
    radius: 'round' | 'soft';
    label: string;
    variant: 'solid' | 'outline';
    layout: 'list' | 'cards';
    limit: number;
    continueText: string;
    autoResize: boolean;
}

/** Where the script lives — the ticket platform's own origin. */
export const embedScriptUrl = (): string => {
    const origin = getConfig('VITE_FRONTEND_URL')
        || (typeof window !== 'undefined' ? window.location.origin : '');
    return `${String(origin).replace(/\/+$/, '')}/embed.js`;
};

/** Only the attributes that differ from the defaults, so snippets stay short. */
export const embedAttributes = (o: EmbedOptions): [string, string][] => {
    const attrs: [string, string][] = [];
    if (o.type !== 'tickets') attrs.push(['type', o.type]);
    if (o.type === 'calendar') attrs.push(['organizer-id', String(o.organizerId ?? '')]);
    else attrs.push(['id', String(o.eventId ?? '')]);
    if (o.theme !== 'light') attrs.push(['theme', o.theme]);
    if (o.accent) attrs.push(['accent', o.accent]);
    if (o.radius !== 'round') attrs.push(['radius', o.radius]);
    if (o.type === 'button') {
        if (o.label && o.label !== 'Get tickets') attrs.push(['label', o.label]);
        if (o.variant !== 'solid') attrs.push(['variant', o.variant]);
    }
    if (o.type === 'calendar') {
        if (o.layout !== 'list') attrs.push(['layout', o.layout]);
        if (o.limit !== 6) attrs.push(['limit', String(o.limit)]);
    }
    if (o.type === 'tickets' && o.continueText && o.continueText !== 'Continue') {
        attrs.push(['continue-button-text', o.continueText]);
    }
    if (!o.autoResize && o.type !== 'button') attrs.push(['autoresize', 'false']);
    return attrs;
};

const escapeAttr = (value: string) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;');

export const htmlSnippet = (o: EmbedOptions) => {
    const attrs = embedAttributes(o)
        .map(([k, v]) => `\n     data-monno-${k}="${escapeAttr(v)}"`)
        .join('');
    return `<script async src="${embedScriptUrl()}"></script>\n\n<div class="monno-embed"${attrs}></div>`;
};

export const reactSnippet = (o: EmbedOptions) => {
    const attrs = embedAttributes(o)
        .map(([k, v]) => `      data-monno-${k}="${escapeAttr(v)}"`)
        .join('\n');
    return `import { useEffect } from "react";

export function MonnoEmbed() {
  useEffect(() => {
    if (document.querySelector('script[data-monno-embed]')) return;
    const script = document.createElement("script");
    script.src = "${embedScriptUrl()}";
    script.async = true;
    script.dataset.monnoEmbed = "";
    document.body.appendChild(script);
  }, []);

  return (
    <div
      className="monno-embed"
${attrs}
    />
  );
}`;
};

/** WordPress: one Custom HTML block — the same markup, nothing to install. */
export const wordpressSnippet = (o: EmbedOptions) => htmlSnippet(o);

/** Query params the embed pages read, for the dashboard's own preview. */
export const previewQuery = (o: EmbedOptions) => {
    const params = new URLSearchParams();
    if (o.theme) params.set('Theme', o.theme);
    if (o.accent) params.set('Accent', o.accent);
    params.set('Radius', o.radius);
    if (o.type === 'calendar') {
        params.set('Layout', o.layout);
        params.set('Limit', String(o.limit));
    }
    return params.toString();
};
