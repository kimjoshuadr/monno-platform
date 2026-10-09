/* eslint-disable lingui/no-unlocalized-strings -- image-type keys, date/format strings and
   analytics names only; every user-facing string goes through `t`. */
import {useMemo, useState} from "react";
import {Link} from "react-router";
import {t} from "@lingui/macro";
import {
    IconBookmark,
    IconBookmarkFilled,
    IconCalendar,
    IconCheck,
    IconClock,
    IconMapPin,
    IconPlus,
    IconShare,
    IconUsers,
} from "@tabler/icons-react";
import {AgendaItem, Event, EventType, HomepageBlock, Organizer, OrganizerStatus, VenueAddress} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {getGoogleMapsUrl, getShortLocationDisplay} from "../../../utilites/addressUtilities.ts";
import {organizerHomepageUrl} from "../../../utilites/urlHelper.ts";
import {socialMediaConfig} from "../../../constants/socialMediaConfig.ts";
import {getEventCategories} from "../../../constants/eventCategories.ts";
import {useRoomSaved} from "../OrganizerRoom/useRoomSaved.ts";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetMyFollows} from "../../../queries/useGetMyFollows.ts";
import {useFollowOrganizer} from "../../../mutations/useFollowOrganizer.ts";
import {useUnfollowOrganizer} from "../../../mutations/useUnfollowOrganizer.ts";
import {HomepageBlocks, AuthoredBlock} from "../HomepageBlocks";
import {PageBackground} from "../../common/PageBackground";
import {roomAttendance, roomCategoryColour, roomThemeStyle} from "../../../utilites/roomData.ts";
import {downloadICSFile} from "../../../utilites/calendar.ts";
import SelectProducts from "../../routes/product-widget/SelectProducts";
import {ShareModal} from "../../modals/ShareModal";
import {EventRoomHeader, monnoEventUrl, monnoSiteUrl} from "./EventRoomChrome.tsx";
import {getEventCoverImage, makeEventPosterSvg} from "../../../utilites/imageFallbacks.ts";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";

// The page speaks in the event's own timezone, not the visitor's — a 19:30 door time
// on the poster has to read 19:30 on the page wherever it is opened.
dayjs.extend(utc);
dayjs.extend(timezone);

/** `$38.00` → `$38`, the way the design writes a price in a badge. */
const compactPrice = (amount: number, currency: string): string =>
    formatCurrency(amount, currency).replace(/\.00$/, "");

interface EventRoomProps {
    event: Event;
    organizer?: Organizer | null;
    banner?: React.ReactNode;
    footer?: React.ReactNode;
    mode?: "light" | "dark";
    theme?: {accent?: string; background?: string; font_family?: string} | null;
    /** Theme variables from the shell; the ticket widget reads them. */
    style?: React.CSSProperties;
    promoCode?: string | null;
    promoCodeValid?: boolean;
    initialOccurrenceId?: number | null;
}

/** Block types the page itself renders, so the builder can arrange (and hide) them. */
const DATA_DRIVEN_TYPES = ["HERO", "ABOUT", "AGENDA", "TICKETS", "VENUE", "ORGANIZER", "ATTENDEES"];

const lowestPrice = (event: Event): number => {
    const products = getProductsFromEvent(event) ?? [];
    let lowest: number | null = null;

    for (const product of products) {
        const prices = product.prices?.length ? product.prices.map((price) => price.price ?? 0) : [product.price ?? 0];
        for (const price of prices) {
            if (lowest === null || price < lowest) lowest = price;
        }
    }

    return lowest ?? 0;
};

/**
 * The event page: monno's public event design (header and footer, cover column with the
 * host card, three content columns, sticky rail) with the platform's ticket widget in the
 * rail — where the design puts the register card, and where a buyer looks for it. The
 * organizer's page sections arrange themselves around it.
 */
export const EventRoom = ({
    event,
    organizer,
    banner,
    footer,
    mode = "light",
    theme,
    style,
    promoCode,
    promoCodeValid,
    initialOccurrenceId,
}: EventRoomProps) => {
    const {isSaved, toggle, ready} = useRoomSaved();
    const saved = ready && isSaved(`event:${event.id}`);
    const [shareOpen, setShareOpen] = useState(false);
    // Share hands out the main website's page for this event. Events without one
    // get no share button at all, rather than a link to this ticket surface or an
    // auth-gated preview.
    const shareUrl = monnoEventUrl(event.id);

    // Following is account-scoped on the platform, so a signed-out visitor is handed to the
    // organizer's room instead of being shown a button that would 401.
    const {data: me} = useGetMe();
    const {data: follows} = useGetMyFollows(Boolean(me?.id));
    const followMutation = useFollowOrganizer();
    const unfollowMutation = useUnfollowOrganizer();
    const followed = Boolean(
        organizer?.id && follows?.some((follow) => Number(follow.organizer_id) === Number(organizer.id)),
    );
    const toggleFollow = () => {
        if (!organizer?.id) return;
        if (followed) {
            unfollowMutation.mutate(organizer.id);
            return;
        }
        followMutation.mutate(organizer.id);
    };

    const squareImage = event.images?.find((image) => image.type === "EVENT_IMAGE")
        ?? event.images?.find((image) => image.type === "EVENT_COVER");
    // Uploaded image → curated category photo → typographic poster (never empty).
    const fallbackCoverUrl = getEventCoverImage(event)
        ?? makeEventPosterSvg(event.title || "Event", event.category);
    const eventCoverUrl = squareImage?.url ?? fallbackCoverUrl;
    // The MIRROR_COVER_IMAGE background blurs the cover — the wide one when it exists.
    const mirrorBackgroundImage = event.images?.find((image) => image.type === "EVENT_COVER") ?? squareImage ?? { url: fallbackCoverUrl };

    const price = useMemo(() => lowestPrice(event), [event]);
    const currency = event.currency || "USD";
    const attendance = useMemo(() => roomAttendance(event), [event]);
    const spotsLeft = useMemo(() => {
        const quantities = (getProductsFromEvent(event) ?? [])
            .map((product) => product.quantity_available)
            .filter((quantity): quantity is number => typeof quantity === "number");
        return quantities.length ? Math.min(...quantities) : null;
    }, [event]);

    const eventTimezone = event.timezone || "UTC";
    const start = event.start_date ? dayjs.utc(event.start_date).tz(eventTimezone) : null;
    const end = event.end_date ? dayjs.utc(event.end_date).tz(eventTimezone) : null;
    const address = (event.event_location?.location?.structured_address ?? null) as VenueAddress | null;
    const location = getShortLocationDisplay(address);
    // The crumb names the city, the way the website's does.
    const city = address?.city || address?.state_or_region || "";
    const mapUrl = address ? getGoogleMapsUrl(address) : null;
    // The host card carries the host's own location — the event's venue is in Details.
    const organizerAddress = (organizer?.location?.structured_address ?? null) as VenueAddress | null;
    const hostLocation = organizerAddress?.city
        || organizerAddress?.state_or_region
        || organizer?.location?.name
        || "";
    const isRecurring = event.type === EventType.RECURRING;
    const agenda: AgendaItem[] = event.agenda ?? [];
    const organizerLive = organizer?.status === OrganizerStatus.LIVE;
    const description = event.description || event.description_preview || "";

    const categoryLabel = getEventCategories().find((category) => category.id === event.category)?.name ?? '';
    const categoryAccent = roomCategoryColour(
        getEventCategories().map((category) => category.id),
        event.category as string,
    );
    const taken = attendance.capacity
        ? Math.round((attendance.registered / attendance.capacity) * 100)
        : 0;

    // The host's own mark, wherever the page draws it: their logo when uploaded, otherwise
    // their initials (two letters, the way the website writes a monogram).
    const organizerLogo = organizer?.images?.find((image) => image.type === "ORGANIZER_LOGO");
    const organizerInitials = (organizer?.name || "?")
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => word[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();

    /**
     * The page's own sections, so the builder's data-driven blocks (About, Agenda, Venue,
     * Host) can be arranged — and shown — in the organizer's order. About renders whenever the
     * block asks for it, even before a description exists, so adding it is visibly a change.
     */
    const aboutSection = (
        <section className="event-section" aria-labelledby="about-heading" id="about">
            <h2 className="block-title" id="about-heading">{t`About this event`}</h2>
            {description ? <div className="prose" dangerouslySetInnerHTML={{__html: description}}/> : null}
        </section>
    );

    const agendaSection = agenda.length ? (
        <section className="event-section" aria-labelledby="agenda-heading" id="agenda">
            <h2 className="block-title" id="agenda-heading">{t`Running order`}</h2>
            <ol className="agenda">
                {agenda.map((item, index) => (
                    <li className="agenda-row" key={`${item.title}-${index}`}>
                        <span className="agenda-time">{item.time}</span>
                        <div>
                            <h4>{item.title}</h4>
                            {item.detail ? <p>{item.detail}</p> : null}
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    ) : null;

    const detailsSection = (
        <section className="event-section" aria-labelledby="details-heading" id="details">
            <h2 className="block-title" id="details-heading">{t`Details`}</h2>
            <dl className="fact-list">
                {start ? (
                    <div className="fact">
                        <IconCalendar size={18}/>
                        <div>
                            <dt>{t`Date`}</dt>
                            <dd>{start.format("dddd, MMMM D, YYYY")}</dd>
                        </div>
                    </div>
                ) : null}
                {start ? (
                    <div className="fact">
                        <IconClock size={18}/>
                        <div>
                            <dt>{t`Time`}</dt>
                            <dd>
                                {start.format("HH:mm")}
                                {end && end.isAfter(start) ? ` – ${end.format("HH:mm")}` : ""}
                                {isRecurring ? ` · ${t`recurring`}` : ""}
                            </dd>
                        </div>
                    </div>
                ) : null}
                {location ? (
                    <div className="fact">
                        <IconMapPin size={18}/>
                        <div>
                            <dt>{t`Venue`}</dt>
                            <dd>
                                {mapUrl ? (
                                    <a href={mapUrl} target="_blank" rel="noopener noreferrer">{location}</a>
                                ) : location}
                            </dd>
                        </div>
                    </div>
                ) : null}
                {attendance.capacity ? (
                    <div className="fact">
                        <IconUsers size={18}/>
                        <div>
                            <dt>{t`Capacity`}</dt>
                            <dd>
                                {attendance.capacity.toLocaleString()} {t`capacity`} ·{" "}
                                {attendance.registered.toLocaleString()} {t`registered`}
                            </dd>
                        </div>
                    </div>
                ) : null}
            </dl>
        </section>
    );

    const hostPanel = organizer ? (
        <div className="event-host-panel">
            {organizerLogo ? (
                <span className="event-host-mark">
                    <img src={organizerLogo.url} alt="" width={48} height={48}/>
                </span>
            ) : (
                <span className="monogram">{organizerInitials}</span>
            )}
            <div>
                <h3>{organizer.name}</h3>
                {organizer.description ? <p className="bio">{organizer.description}</p> : null}
            </div>
            {organizerLive ? (
                me?.id ? (
                    <button
                        type="button"
                        className={`btn ${followed ? "btn-outline" : "btn-primary"} btn-sm`}
                        onClick={toggleFollow}
                        aria-pressed={followed}
                        data-od-id="event-follow"
                    >
                        {followed ? <IconCheck size={15}/> : <IconPlus size={15}/>}
                        {followed ? t`Following` : t`Follow`}
                    </button>
                ) : (
                    <Link
                        className="btn btn-primary btn-sm"
                        to={organizerHomepageUrl(organizer)}
                        data-od-id="event-follow"
                    >
                        <IconPlus size={15}/>
                        {t`Follow`}
                    </Link>
                )
            ) : null}
        </div>
    ) : null;

    const hostsSection = organizer ? (
        <section className="event-section" aria-labelledby="hosts-heading" id="hosts">
            <h2 className="block-title" id="hosts-heading">{t`Hosted by`}</h2>
            {hostPanel}
            {organizer.settings?.social_media_handles ? (
                <ul className="event-host-socials">
                    {Object.entries(organizer.settings.social_media_handles)
                        .filter(([platform, handle]) => Boolean(handle) && platform in socialMediaConfig)
                        .map(([platform, handle]) => {
                            const config = socialMediaConfig[platform as keyof typeof socialMediaConfig];
                            const Icon = config.icon;
                            const value = String(handle);
                            const href = /^https?:\/\//.test(value)
                                ? value
                                : `${config.baseUrl}${value.replace(/^@/, "")}`;
                            return (
                                <li key={platform}>
                                    <a href={href} target="_blank" rel="noopener noreferrer" aria-label={platform}>
                                        <Icon size={17}/>
                                    </a>
                                </li>
                            );
                        })}
                </ul>
            ) : null}
        </section>
    ) : null;

    const visibleBlocks = (event.settings?.homepage_blocks ?? []).filter((block) => block.visible !== false);
    // Only the data-driven blocks rearrange the page; authored ones append after its sections.
    const blockDriven = visibleBlocks.some((block) => DATA_DRIVEN_TYPES.includes(block.type));
    // Who's coming is the page's own panel, so the Attendees block shows or hides it. With no
    // blocks authored the room renders its default layout — which includes it.
    const attendeesVisible = !blockDriven || visibleBlocks.some((block) => block.type === "ATTENDEES");

    const renderBlock = (block: HomepageBlock) => {
        switch (block.type) {
            case "ABOUT":
                return aboutSection;
            case "AGENDA":
                return agendaSection;
            case "VENUE":
                return detailsSection;
            case "ORGANIZER":
                return hostsSection;
            case "TEXT":
            case "CTA":
            case "FAQ":
            case "LINEUP":
            case "GALLERY":
            case "EMBED":
                return <AuthoredBlock key={block.id} block={block}/>;
            default:
                // HERO, TICKETS and ATTENDEES are rendered by the page itself.
                return null;
        }
    };

    const markCount = Math.min(attendance.registered, 5);

    return (
        <div
            className="event-room"
            style={{
                ...(roomThemeStyle(theme) as React.CSSProperties),
                ...style,
                "--event-accent": categoryAccent,
            } as React.CSSProperties}
            data-mode={mode}
            data-od-id="event-room"
        >
            {banner}

            <EventRoomHeader/>

            <PageBackground theme={theme} mirrorImageUrl={mirrorBackgroundImage?.url}/>

            <div className="page event-page">
                <nav className="event-crumbs" aria-label={t`Breadcrumb`}>
                    <p className="meta-row">
                        <a className="link" href={`${monnoSiteUrl()}/discover/`}>{t`Discover`}</a>
                        {categoryLabel ? (
                            <>
                                <span aria-hidden="true">/</span>
                                <a
                                    className="link"
                                    href={`${monnoSiteUrl()}/discover/?category=${encodeURIComponent(categoryLabel)}`}
                                >
                                    {categoryLabel}
                                </a>
                            </>
                        ) : null}
                        {city ? (
                            <>
                                <span aria-hidden="true">/</span>
                                <span>{city}</span>
                            </>
                        ) : null}
                    </p>
                </nav>

                <div className="event-layout">
                    <div className="event-cover-col">
                        <div className="event-kicker">
                            <span className="spectrum-rule" aria-hidden="true"/>
                            <span className="eyebrow">
                                {categoryLabel ? `${categoryLabel} · ` : ""}
                                {start ? start.format("ddd MMM D") : ""}
                            </span>
                        </div>

                        <div className="event-cover-square" data-od-id="event-cover">
                            <figure className="event-cover-plate" data-od-id="event-cover-plate">
                                {eventCoverUrl ? (
                                    <img
                                        src={eventCoverUrl}
                                        alt={event.image_alt || ""}
                                        width={720}
                                        height={720}
                                    />
                                ) : (
                                    <span className="event-cover-empty" aria-hidden="true"/>
                                )}
                            </figure>
                        </div>

                        {organizer ? (
                            <div className="event-host-card" data-od-id="event-host-card">
                                <span className="event-host-mark">
                                    {organizerLogo ? (
                                        <img src={organizerLogo.url} alt="" width={48} height={48}/>
                                    ) : (
                                        <span className="monogram">{organizerInitials}</span>
                                    )}
                                </span>
                                <span className="event-host-copy">
                                    <span className="event-host-name">{organizer.name}</span>
                                    {/* The host's own city; the button beside it already says
                                        "View calendar", so an empty line beats repeating it. */}
                                    <span className="event-host-sub">{hostLocation || city || null}</span>
                                </span>
                                {organizerLive ? (
                                    <Link className="btn btn-outline btn-sm" to={organizerHomepageUrl(organizer)}>
                                        {t`View calendar`}
                                    </Link>
                                ) : null}
                            </div>
                        ) : null}
                    </div>

                    <div className="event-main">
                        <div className="meta-row">
                            <span className={`badge${price === 0 ? " badge-free" : ""}`}>
                                {price === 0 ? t`Free` : compactPrice(price, currency)}
                            </span>
                            {spotsLeft !== null && spotsLeft > 0 && spotsLeft <= 60 ? (
                                <span className="badge">
                                    {t`Only`} {spotsLeft} {t`spots left`}
                                </span>
                            ) : null}
                        </div>

                        <h1 id="event-title" className="event-title">{event.title}</h1>

                        {event.tagline ? <p className="event-tagline">{event.tagline}</p> : null}

                        {blockDriven ? visibleBlocks.map((block) => renderBlock(block)) : (
                            <>
                                {description ? (
                                    <section className="event-section" aria-labelledby="about-heading" id="about">
                                        <h2 className="block-title" id="about-heading">{t`About this event`}</h2>
                                        <div className="prose" dangerouslySetInnerHTML={{__html: description}}/>
                                    </section>
                                ) : null}

                                {agenda.length ? (
                                    <section className="event-section" aria-labelledby="agenda-heading" id="agenda">
                                        <h2 className="block-title" id="agenda-heading">{t`Running order`}</h2>
                                        <ol className="agenda">
                                            {agenda.map((item, index) => (
                                                <li className="agenda-row" key={`${item.title}-${index}`}>
                                                    <span className="agenda-time">{item.time}</span>
                                                    <div>
                                                        <h4>{item.title}</h4>
                                                        {item.detail ? <p>{item.detail}</p> : null}
                                                    </div>
                                                </li>
                                            ))}
                                        </ol>
                                    </section>
                                ) : null}

                                {detailsSection}

                                {organizer && organizerLive ? hostsSection : null}

                                {/* The organizer's authored blocks, in their authored order. */}
                                <HomepageBlocks blocks={event.settings?.homepage_blocks}/>
                            </>
                        )}
                    </div>

                    <aside className="register-rail" aria-label={t`Registration`}>
                        <div className="ticket-card" data-od-id="event-ticket-card">
                            <div className="price-row">
                                <div>
                                    <span className="price-label">{price === 0 ? t`Entry` : t`From`}</span>
                                    <div className="price-tag">
                                        {price === 0 ? t`Free` : formatCurrency(price, currency)}
                                    </div>
                                </div>
                                {start ? (
                                    <div className="price-when">
                                        <span>{start.format("ddd")}</span>
                                        <strong>{start.format("MMM D")}</strong>
                                    </div>
                                ) : null}
                            </div>

                            {attendance.capacity ? (
                                <div className="stack-xs" style={{marginTop: 18}}>
                                    <div
                                        className="progress"
                                        role="img"
                                        aria-label={t`${taken}% of capacity taken`}
                                    >
                                        <span style={{width: `${taken}%`}}/>
                                    </div>
                                    <p className="progress-note">
                                        <span>{attendance.registered.toLocaleString()} {t`going`}</span>
                                        <span>{attendance.capacity.toLocaleString()} {t`capacity`}</span>
                                    </p>
                                </div>
                            ) : null}

                            {/* The ticket picker: products, prices, promo and the checkout
                                hand-off, in the rail where the design puts the register card. */}
                            <div className="rail-tickets" data-od-id="event-ticket-picker">
                                <SelectProducts
                                    colors={{
                                        background: "transparent",
                                        primary: "var(--event-primary-color)",
                                        primaryText: "var(--event-primary-text-color)",
                                        secondary: "var(--event-primary-color)",
                                        secondaryText: "var(--event-accent-contrast)",
                                        bodyBackground: "transparent",
                                    }}
                                    continueButtonText={event.settings?.continue_button_text}
                                    padding={"0px"}
                                    event={event}
                                    promoCodeValid={promoCodeValid}
                                    promoCode={promoCode ?? undefined}
                                    initialOccurrenceId={initialOccurrenceId ?? null}
                                    showPoweredBy={false}
                                />
                            </div>

                            <div className="rail-actions">
                                <button
                                    type="button"
                                    className="btn btn-outline btn-block"
                                    aria-pressed={saved}
                                    onClick={() => toggle(`event:${event.id}`)}
                                    data-od-id="event-save"
                                >
                                    {saved ? <IconBookmarkFilled size={16}/> : <IconBookmark size={16}/>}
                                    {saved ? t`Saved` : t`Save for later`}
                                </button>
                                {shareUrl ? (
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-block"
                                        onClick={() => setShareOpen(true)}
                                        data-od-id="event-share"
                                    >
                                        <IconShare size={16}/>
                                        {t`Share event`}
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-block"
                                    onClick={() => downloadICSFile(event)}
                                    data-od-id="event-calendar"
                                >
                                    <IconCalendar size={16}/>
                                    {t`Add to calendar`}
                                </button>
                            </div>

                            <p className="rail-note">
                                {t`You'll finish checkout on the ticket platform.`}
                            </p>
                        </div>

                        {attendeesVisible ? (
                            <div className="panel who-coming" data-od-id="event-who-coming">
                                <h2 className="block-title">{t`Who's coming`}</h2>
                                <div className="who-coming-row">
                                    {markCount > 0 ? (
                                        <span className="avatar-stack" aria-hidden="true">
                                            {Array.from({length: markCount}, (_, index) => (
                                                <span className="monogram monogram-anon" key={index}/>
                                            ))}
                                            {attendance.registered > markCount ? (
                                                <span className="monogram avatar-stack-more">
                                                    +{attendance.registered - markCount}
                                                </span>
                                            ) : null}
                                        </span>
                                    ) : null}
                                    <p className="who-coming-count">
                                        {attendance.registered.toLocaleString()} {t`registered`}
                                    </p>
                                </div>
                            </div>
                        ) : null}
                    </aside>
                </div>
            </div>

            {footer}

            <ShareModal
                opened={shareOpen && !!shareUrl}
                onClose={() => setShareOpen(false)}
                url={shareUrl ?? ''}
                title={event.title}
                modalTitle={t`Share this event`}
                subtitle={t`Send it to someone who'd come with you.`}
            />
        </div>
    );
};

/** The site the chrome's content links point at — see EventRoomChrome. */
export default EventRoom;
