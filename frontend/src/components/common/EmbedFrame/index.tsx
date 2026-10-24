/* eslint-disable lingui/no-unlocalized-strings -- query keys and CSS variable names only */
import React, {useEffect, useMemo, useRef} from "react";
import {useLocation} from "react-router";
import {embedPalette, resolveEmbedTheme} from "../../../utilites/colour.ts";
import {sendHeightToParent} from "../../../utilites/iframeResize.ts";
import {PoweredByFooter} from "../PoweredByFooter";
import '../../../styles/widget/default.scss';
import './EmbedFrame.scss';

/**
 * The shell every monno embed page renders in: the monno palette (light, dark
 * or the visitor's system setting) tinted with the organizer's accent, the
 * height reported to the host page so there is never an inner scrollbar, and
 * the attribution footer the licence requires — always, on every embed.
 */
export const EmbedFrame = ({children}: { children: React.ReactNode }) => {
    const location = useLocation();
    const ref = useRef<HTMLDivElement>(null);

    const {theme, palette, radius} = useMemo(() => {
        const params = new URLSearchParams(location.search);
        const theme = resolveEmbedTheme(params.get('Theme'));
        return {
            theme,
            palette: embedPalette(theme, params.get('Accent')),
            radius: params.get('Radius') === 'soft' ? 'soft' : 'round',
        };
    }, [location.search]);

    // Transparent all the way down — the app's global body colour (the
    // dashboard grey, `!important`) would otherwise frame the embed in a box.
    // The card and the calendar sit straight on the host page; Theme picks the
    // text colour that suits it.
    useEffect(() => {
        const targets = [document.documentElement, document.body];
        targets.forEach((node) => {
            node.style.setProperty('background-color', 'transparent', 'important');
            node.style.setProperty('margin', '0');
            // A colour-scheme mismatch with the host page makes the browser
            // paint the iframe opaque; `normal` keeps it see-through.
            node.style.setProperty('color-scheme', 'normal', 'important');
        });
        return () => targets.forEach((node) => node.style.removeProperty('background-color'));
    }, []);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const report = () => sendHeightToParent(el.getBoundingClientRect().height);
        report();
        const observer = new ResizeObserver(report);
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const style = {
        '--e-bg': palette.background,
        '--e-surface': palette.surface,
        '--e-text': palette.text,
        '--e-muted': palette.muted,
        '--e-border': palette.border,
        '--e-accent': palette.accent,
        '--e-accent-text': palette.accentText,
    } as React.CSSProperties;

    return (
        <div ref={ref} className="monno-embed-root monno-embed-frame" data-theme={theme} data-radius={radius}
             style={style}>
            {children}
            <PoweredByFooter className="monno-embed-footer"/>
        </div>
    );
};
