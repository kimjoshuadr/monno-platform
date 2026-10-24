/* eslint-disable lingui/no-unlocalized-strings -- colour codes and theme names only */
/**
 * Colour helpers for the embeds: pick readable text for an organizer's accent,
 * and build the monno palette an embed is drawn in.
 */
const HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

export const isHex = (value?: string | null): value is string => !!value && HEX.test(value.trim());

const luminance = (hex: string): number => {
    const m = HEX.exec(hex.trim());
    if (!m) return 1;
    const h = m[1].length === 3 ? m[1].split('').map((c) => c + c).join('') : m[1];
    const lin = (i: number) => {
        const c = parseInt(h.slice(i, i + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * lin(0) + 0.7152 * lin(2) + 0.0722 * lin(4);
};

export const contrastRatio = (a: string, b: string): number => {
    const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
};

/** Ink or white — whichever reads better on the colour. */
export const textOn = (hex: string): string =>
    contrastRatio(hex, '#0b0b0c') >= contrastRatio(hex, '#ffffff') ? '#0b0b0c' : '#ffffff';

export type EmbedTheme = 'light' | 'dark';

export interface EmbedPalette {
    background: string;
    surface: string;
    text: string;
    muted: string;
    border: string;
    accent: string;
    accentText: string;
}

/**
 * The monno ground (canvas/ink) with the organizer's accent on the things that
 * act: the selected state, the primary button, focus and progress. An accent
 * that would vanish on the ground (white on light, ink on dark) falls back to
 * the ground's own ink so the button never disappears.
 */
export const embedPalette = (theme: EmbedTheme, accent?: string | null): EmbedPalette => {
    const dark = theme === 'dark';
    const ground = dark ? '#18181b' : '#ffffff';
    const ink = dark ? '#f5f6f8' : '#0b0b0c';
    const chosen = isHex(accent) ? (accent.startsWith('#') ? accent : `#${accent}`) : ink;
    const usable = contrastRatio(chosen, ground) >= 1.6 ? chosen : ink;

    return {
        background: ground,
        surface: dark ? '#202024' : '#ffffff',
        text: ink,
        muted: dark ? '#a6a9b0' : '#6e7178',
        border: dark ? '#2e2e33' : '#e4e6ea',
        accent: usable,
        accentText: textOn(usable),
    };
};

/** `light` / `dark`, or follow the visitor's system setting for `auto`. */
export const resolveEmbedTheme = (value?: string | null): EmbedTheme => {
    if (value === 'dark' || value === 'light') return value;
    if (typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches) return 'dark';
    return 'light';
};
