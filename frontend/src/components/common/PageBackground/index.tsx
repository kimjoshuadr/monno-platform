import {useEffect, useRef, useState} from "react";
import {HomepageThemeSettings} from "../../../types.ts";

/**
 * The page's media background: an image (including animated GIF) or a short video, with an
 * overlay for text contrast and optional blur.
 *
 * The theme colour is what the page paints behind this, so there is always something to look
 * at while media loads — and if a video is missing, on a reduced-motion preference, or on a
 * save-data connection, the poster frame stands in for it.
 */
const useMediaGuards = () => {
    const [reducedMotion, setReducedMotion] = useState(false);
    const [saveData, setSaveData] = useState(false);

    useEffect(() => {
        const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        setReducedMotion(Boolean(query?.matches));
        const onChange = (event: MediaQueryListEvent) => setReducedMotion(event.matches);
        query?.addEventListener?.('change', onChange);

        const connection = (navigator as unknown as {connection?: {saveData?: boolean}}).connection;
        setSaveData(Boolean(connection?.saveData));

        return () => query?.removeEventListener?.('change', onChange);
    }, []);

    return {reducedMotion, saveData};
};

interface PageBackgroundProps {
    theme?: Partial<HomepageThemeSettings> | null;
    /** The event's cover image, used by the MIRROR_COVER_IMAGE background type. */
    mirrorImageUrl?: string;
}

export const PageBackground = ({theme, mirrorImageUrl}: PageBackgroundProps) => {
    const {reducedMotion, saveData} = useMediaGuards();
    const layerRef = useRef<HTMLDivElement>(null);
    const [heroHeight, setHeroHeight] = useState(360);

    const type = theme?.background_type;
    const isMirror = type === 'MIRROR_COVER_IMAGE' && Boolean(mirrorImageUrl);
    const isImage = (type === 'IMAGE' && Boolean(theme?.background_image_url)) || isMirror;
    const isVideo = type === 'VIDEO' && Boolean(theme?.background_video_url);

    const placement = (isImage || isVideo) && theme?.background_placement === 'HERO' ? 'HERO' : 'PAGE';

    // Hero band: ends just under the title block (the poster straddles its edge), and the
    // room is flagged so its light-on-dark ink rules apply to what sits on the band.
    useEffect(() => {
        const room = layerRef.current?.parentElement;
        if (!room || placement !== 'HERO') {
            return;
        }
        room.dataset.hero = 'true';
        const measure = () => {
            const anchor = (room.querySelector('.event-tagline') ?? room.querySelector('.event-title, h1'));
            if (anchor) {
                setHeroHeight(Math.round(anchor.getBoundingClientRect().bottom - room.getBoundingClientRect().top + 28));
            }
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(room);
        // The title wraps once the font and data land, without the room's own size changing.
        const anchor = (room.querySelector('.event-tagline') ?? room.querySelector('.event-title, h1'));
        if (anchor) {
            observer.observe(anchor);
        }
        document.fonts?.ready.then(measure);
        return () => {
            observer.disconnect();
            delete room.dataset.hero;
        };
    }, [placement]);

    if (!isImage && !isVideo) {
        return null;
    }

    const overlay = theme?.background_overlay_opacity ?? 0.35;
    const blur = isMirror ? (theme?.background_blur || 18) : (theme?.background_blur ?? 0);
    const imageSrc = isMirror ? mirrorImageUrl : theme?.background_image_url;

    // Hero bands are the top of the page; approximating it here keeps the layer a single mount.
    const layer: React.CSSProperties = placement === 'HERO'
        ? {position: 'absolute', top: 0, left: 0, right: 0, height: heroHeight, zIndex: -1, overflow: 'hidden'}
        : {position: 'fixed', inset: 0, zIndex: -1, overflow: 'hidden'};

    const mediaStyle: React.CSSProperties = {
        width: '100%',
        height: '100%',
        objectFit: 'cover',
        filter: blur > 0 ? `blur(${blur}px)` : undefined,
        transform: blur > 0 ? 'scale(1.06)' : undefined,
    };

    const playVideo = isVideo && !reducedMotion && !saveData;

    return (
        <div ref={layerRef} style={layer} data-od-id="page-background" data-placement={placement} aria-hidden="true">
            {playVideo ? (
                <video
                    src={theme?.background_video_url ?? undefined}
                    poster={theme?.background_poster_url ?? undefined}
                    style={mediaStyle}
                    autoPlay
                    muted
                    loop
                    playsInline
                    preload="metadata"
                />
            ) : (theme?.background_poster_url || imageSrc) ? (
                <img src={((isImage ? imageSrc : theme?.background_poster_url) ?? undefined)} alt="" style={mediaStyle}/>
            ) : null}

            {overlay > 0 ? (
                <div style={{position: 'absolute', inset: 0, background: '#000', opacity: overlay}}/>
            ) : null}
        </div>
    );
};
