import {ReactNode} from "react";
import {t} from "@lingui/macro";
import {HomepageBlock} from "../../../types.ts";

/**
 * The builder's authored blocks.
 *
 * The seven data-driven types (HERO, ABOUT, AGENDA, TICKETS, VENUE, ORGANIZER, ATTENDEES) are
 * the pages' own sections; these are the six an organizer writes by hand, which until now were
 * stored in `homepage_blocks` and rendered nowhere.
 */

const AUTHORED_TYPES = ["TEXT", "CTA", "FAQ", "LINEUP", "GALLERY", "EMBED"];

const isAuthoredBlock = (block: HomepageBlock): boolean =>
    AUTHORED_TYPES.includes(block.type);

interface BlockProps {
    block: HomepageBlock;
}

const TextBlock = ({block}: BlockProps) => {
    const body = String(block.settings?.body ?? "");
    if (!body.trim()) return null;

    return (
        <section className="event-section" data-od-id={`block-${block.id}`}>
            <div className="prose" dangerouslySetInnerHTML={{__html: body}}/>
        </section>
    );
};

const CtaBlock = ({block}: BlockProps) => {
    const label = String(block.settings?.label ?? "");
    const url = String(block.settings?.url ?? "");
    if (!label || !url || url === "https://") return null;

    return (
        <section className="event-section block-cta" data-od-id={`block-${block.id}`}>
            <a className="btn btn-primary" href={url} target="_blank" rel="noopener noreferrer">
                {label}
            </a>
        </section>
    );
};

const FaqBlock = ({block}: BlockProps) => {
    const items = (block.settings?.items ?? []) as {question?: string; answer?: string}[];
    const filled = items.filter((item) => item?.question?.trim());
    if (!filled.length) return null;

    return (
        <section className="event-section" data-od-id={`block-${block.id}`}>
            <h2 className="block-title">{t`Frequently asked questions`}</h2>
            <dl className="block-faq">
                {filled.map((item, index) => (
                    <div className="block-faq-item" key={`${item.question}-${index}`}>
                        <dt>{item.question}</dt>
                        {item.answer ? <dd>{item.answer}</dd> : null}
                    </div>
                ))}
            </dl>
        </section>
    );
};

const LineupBlock = ({block}: BlockProps) => {
    const artists = (block.settings?.artists ?? []) as {name?: string}[];
    const filled = artists.filter((artist) => artist?.name?.trim());
    if (!filled.length) return null;

    return (
        <section className="event-section" data-od-id={`block-${block.id}`}>
            <h2 className="block-title">{t`Lineup`}</h2>
            <ul className="block-lineup">
                {filled.map((artist, index) => <li key={`${artist.name}-${index}`}>{artist.name}</li>)}
            </ul>
        </section>
    );
};

const GalleryBlock = ({block}: BlockProps) => {
    const images = (block.settings?.images ?? []) as {url?: string; alt?: string}[];
    const filled = images.filter((image) => image?.url && image.url !== "https://");
    if (!filled.length) return null;

    return (
        <section className="event-section" data-od-id={`block-${block.id}`}>
            <h2 className="block-title">{t`Gallery`}</h2>
            <div className="block-gallery">
                {filled.map((image, index) => (
                    <img
                        key={`${image.url}-${index}`}
                        src={image.url}
                        alt={image.alt || ""}
                        loading="lazy"
                        decoding="async"
                    />
                ))}
            </div>
        </section>
    );
};

const EmbedBlock = ({block}: BlockProps) => {
    const url = String(block.settings?.url ?? "");
    if (!url || url === "https://") return null;

    return (
        <section className="event-section block-embed" data-od-id={`block-${block.id}`}>
            <iframe src={url} title={t`Embedded content`} loading="lazy" allowFullScreen/>
        </section>
    );
};

const RENDERERS: Record<string, (props: BlockProps) => ReactNode> = {
    TEXT: TextBlock,
    CTA: CtaBlock,
    FAQ: FaqBlock,
    LINEUP: LineupBlock,
    GALLERY: GalleryBlock,
    EMBED: EmbedBlock,
};

/** Renders one authored block (TEXT / CTA / FAQ / LINEUP / GALLERY / EMBED). */
export const AuthoredBlock = ({block}: {block: HomepageBlock}) => {
    const Renderer = RENDERERS[block.type];
    return Renderer ? <Renderer block={block}/> : null;
};

/** Renders the authored blocks in their authored order, skipping hidden and empty ones. */
export const HomepageBlocks = ({blocks}: {blocks?: HomepageBlock[] | null}) => {
    const authored = (blocks ?? []).filter((block) => block.visible !== false && isAuthoredBlock(block));
    if (!authored.length) return null;

    return (
        <>
            {authored.map((block) => <AuthoredBlock key={block.id} block={block}/>)}
        </>
    );
};
