import {useEffect, useState} from "react";
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

    const type = theme?.background_type;
    const isMirror = type === 'MIRROR_COVER_IMAGE' && Boolean(mirrorImageUrl);
    const isImage = (type === 'IMAGE' && Boolean(theme?.background_image_url)) || isMirror;
    const isVideo = type === 'VIDEO' && Boolean(theme?.background_video_url);

    if (!isImage && !isVideo) {
        return null;
    }

    const placement = theme?.background_placement === 'HERO' ? 'HERO' : 'PAGE';
    const overlay = theme?.background_overlay_opacity ?? 0.35;
    const blur = isMirror ? (theme?.background_blur || 18) : (theme?.background_blur ?? 0);
    const imageSrc = isMirror ? mirrorImageUrl : theme?.background_image_url;

    // Hero bands are the top of the page; approximating it here keeps the layer a single mount.
    const layer: React.CSSProperties = placement === 'HERO'
        ? {position: 'absolute', top: 0, left: 0, right: 0, height: 'min(70vh, 620px)', zIndex: 0, overflow: 'hidden'}
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
        <div style={layer} data-od-id="page-background" data-placement={placement} aria-hidden="true">
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
