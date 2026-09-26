import {useEffect, useMemo, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconCheck, IconMail, IconMapPin, IconPlus, IconSearch} from "@tabler/icons-react";
import {Event, HomepageBlock, Organizer} from "../../../types.ts";
import {socialMediaConfig} from "../../../constants/socialMediaConfig.ts";
import {
    RoomHost,
    RoomSocial,
    groupRoomDays,
    roomCategoryOrder,
    roomDateParts,
    roomEventsFrom,
    roomThemeStyle,
    toRoomHost,
} from "../../../utilites/roomData.ts";
import {useGetMyFollows} from "../../../queries/useGetMyFollows.ts";
import {useFollowOrganizer} from "../../../mutations/useFollowOrganizer.ts";
import {useUnfollowOrganizer} from "../../../mutations/useUnfollowOrganizer.ts";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {downloadRoomIcs} from "../../../utilites/roomIcs.ts";
import {ContactOrganizerModal} from "../../common/ContactOrganizerModal";
import {HomepageBlocks, AuthoredBlock} from "../HomepageBlocks";
import {PageBackground} from "../../common/PageBackground";
import {RoomTimeline} from "./RoomTimeline.tsx";
import {RoomMonthPicker} from "./RoomMonthPicker.tsx";
import {RoomMonthView} from "./RoomMonthView.tsx";
import {RoomDayPanel} from "./RoomDayPanel.tsx";

/** The sticky top bar plus the sticky toolbar — land below both. */
const RESULTS_OFFSET = 140;

/** Events revealed per "Show more". */
const PAGE = 12;

type When = "upcoming" | "past";
type View = "list" | "calendar";
type Price = "any" | "free" | "paid";

/** Block types this page knows how to render, so the builder can arrange (and hide) them. */
const DATA_DRIVEN_TYPES = ["HERO", "ABOUT", "AGENDA", "TICKETS", "VENUE", "ORGANIZER", "ATTENDEES"];
const AUTHORED_TYPES = ["TEXT", "CTA", "FAQ", "LINEUP", "GALLERY", "EMBED"];

interface OrganizerRoomProps {
    organizer: Organizer;
    events: Event[];
    /** Accurate totals from the paginated envelope, when the caller has them. */
    totals?: { upcoming?: number; past?: number };
    /** Rendered above the identity card — the designer's publish affordance. */
    banner?: React.ReactNode;
    /** Rendered under the room — footer links, attribution, cookie settings. */
    footer?: React.ReactNode;
    mode?: "light" | "dark";
    theme?: { accent?: string; background?: string; font_family?: string } | null;
    /** Sections to render; falls back to the organizer's saved settings. */
    blocks?: HomepageBlock[] | null;
}

/**
 * The organizer room: monno's organizer design, driven by the platform's data.
 *
 * A cover and an identity card, then a wide main column (search, view switch, filters and
 * the active view) beside a sticky rail holding the month grid, the calendar's stats and
 * the .ics export.
 */
export const OrganizerRoom = ({
    organizer,
    events,
    totals,
    banner,
    footer,
    mode = "light",
    theme,
    blocks,
}: OrganizerRoomProps) => {
    const [when, setWhen] = useState<When>("upcoming");
    const [view, setView] = useState<View>("list");
    const [query, setQuery] = useState("");
    const [category, setCategory] = useState("all");
    const [city, setCity] = useState("all");
    const [price, setPrice] = useState<Price>("any");
    const [day, setDay] = useState<string | null>(null);
    const [panelDay, setPanelDay] = useState<string | null>(null);
    const [visible, setVisible] = useState(PAGE);
    const [contactOpen, setContactOpen] = useState(false);

    const resultsRef = useRef<HTMLDivElement | null>(null);
    const shouldLand = useRef(false);

    const {data: me} = useGetMe();
    const {data: follows} = useGetMyFollows(Boolean(me?.id));
    const followMutation = useFollowOrganizer();
    const unfollowMutation = useUnfollowOrganizer();

    const rows = useMemo(() => events.flatMap((event) => roomEventsFrom(event)), [events]);
    const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

    const upcoming = useMemo(
        () => rows
            .filter((row) => row.date >= today)
            .sort((a, b) => a.startsAt.localeCompare(b.startsAt)),
        [rows, today],
    );
    const past = useMemo(
        () => rows
            .filter((row) => row.date < today)
            .sort((a, b) => b.startsAt.localeCompare(a.startsAt)),
        [rows, today],
    );

    const categories = useMemo(() => roomCategoryOrder(rows), [rows]);
    const cities = useMemo(
        () => [...new Set(rows.map((row) => row.city).filter(Boolean))].sort(),
        [rows],
    );

    const scoped = when === "past" ? past : upcoming;

    const byFilters = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return scoped.filter((row) => {
            if (category !== "all" && row.category !== category) return false;
            if (city !== "all" && row.city !== city) return false;
            if (price === "free" && row.price !== 0) return false;
            if (price === "paid" && row.price === 0) return false;
            if (needle && !`${row.title} ${row.venue} ${row.city} ${row.category}`.toLowerCase().includes(needle)) {
                return false;
            }
            return true;
        });
    }, [scoped, category, city, price, query]);

    const filtered = useMemo(
        () => (day ? byFilters.filter((row) => row.date === day) : byFilters),
        [byFilters, day],
    );
    const panelEvents = useMemo(
        () => (panelDay ? byFilters.filter((row) => row.date === panelDay) : []),
        [byFilters, panelDay],
    );

    const shown = filtered.slice(0, visible);
    const days = groupRoomDays(shown, when === "past" ? "back" : "forward");

    const upcomingCount = totals?.upcoming ?? upcoming.length;
    const nextUp = upcoming[0] ? roomDateParts(upcoming[0].date) : null;
    const dayParts = day ? roomDateParts(day) : null;
    const filtersOn = Boolean(day || query || category !== "all" || city !== "all" || price !== "any");

    const host: RoomHost = useMemo(() => toRoomHost(organizer, organizer.settings), [organizer]);
    const followed = Boolean(follows?.some((follow) => follow.organizer_id === organizer.id));

    /** Sections the builder arranged: About is the room's own description, rendered as prose. */
    const roomBlocks = (blocks ?? organizer.settings?.homepage_blocks ?? []).filter((block) => block.visible !== false);
    const roomBlockDriven = roomBlocks.some((block) => DATA_DRIVEN_TYPES.includes(block.type));

    const aboutSection = (
        <section className="event-section" aria-labelledby="organizer-about-heading" id="organizer-about"
                 data-od-id="organizer-about">
            <h2 className="block-title" id="organizer-about-heading">{t`About`}</h2>
            {organizer.description ? (
                <div className="prose" dangerouslySetInnerHTML={{__html: organizer.description}}/>
            ) : null}
        </section>
    );

    const renderRoomBlock = (block: HomepageBlock) => {
        if (block.type === "ABOUT") return aboutSection;
        if (AUTHORED_TYPES.includes(block.type)) return <AuthoredBlock key={block.id} block={block}/>;
        // HERO, AGENDA, TICKETS, VENUE, HOST and ATTENDEES are rendered by the page itself.
        return null;
    };

    useEffect(() => {
        if (!shouldLand.current) return;
        shouldLand.current = false;
        const element = resultsRef.current;
        if (element) {
            window.scrollTo({top: Math.max(0, element.getBoundingClientRect().top + window.scrollY - RESULTS_OFFSET)});
        }
    }, [day, view]);

    const changeWhen = (next: When) => {
        setWhen(next);
        setDay(null);
        setPanelDay(null);
        setVisible(PAGE);
    };

    const changeView = (next: View) => {
        setView(next);
        setVisible(PAGE);
        if (next !== "calendar") setPanelDay(null);
    };

    const clearAll = () => {
        setDay(null);
        setPanelDay(null);
        setQuery("");
        setCategory("all");
        setCity("all");
        setPrice("any");
        setVisible(PAGE);
    };

    const toggleFollow = () => {
        if (!organizer.id) return;
        if (followed) {
            unfollowMutation.mutate(organizer.id);
            return;
        }
        followMutation.mutate(organizer.id);
    };

    const iconsFor = (social: RoomSocial) =>
        socialMediaConfig[social.platform as keyof typeof socialMediaConfig]?.icon;

    return (
        <div
            className="organizer-room"
            style={roomThemeStyle(theme) as React.CSSProperties}
            data-mode={mode}
            data-od-id="organizer-calendar"
        >
            {banner}

            <PageBackground theme={theme}/>

            <div className="page">
                <section className="cal-cover" data-od-id="organizer-cover">
                    {host.cover ? (
                        <img src={host.cover} alt="" width={1600} height={1000} fetchPriority="high"/>
                    ) : (
                        <span className="cal-cover-empty" aria-hidden="true"/>
                    )}
                </section>

                <div className="cal-id" data-od-id="organizer-identity">
                    <div className="cal-id-main">
                        <span className="cal-id-mark">
                            {host.avatar ? (
                                <img src={host.avatar} alt="" width={64} height={64} decoding="async"/>
                            ) : (
                                <span className="cal-id-mark-initial">{(host.name || "?").slice(0, 1)}</span>
                            )}
                        </span>

                        <div className="cal-id-copy">
                            <h1 id="organizer-title" className="cal-id-name">{host.name}</h1>
                            <p className="cal-id-meta">
                                {host.handle ? <span>@{host.handle}</span> : null}
                                {host.location ? (
                                    <>
                                        <span className="dot-sep"/>
                                        <span>
                                            <IconMapPin size={13} style={{verticalAlign: "-2px", marginRight: 4}}/>
                                            {host.location}
                                        </span>
                                    </>
                                ) : null}
                                <span className="dot-sep"/>
                                <span>
                                    {upcomingCount} {upcomingCount === 1 ? t`upcoming date` : t`upcoming dates`}
                                </span>
                                <span className="dot-sep"/>
                                <span>{cities.length} {cities.length === 1 ? t`city` : t`cities`}</span>
                            </p>
                        </div>

                        <div className="cal-id-actions">
                            <button
                                type="button"
                                className="btn btn-outline"
                                onClick={() => setContactOpen(true)}
                                data-od-id="organizer-contact"
                            >
                                <IconMail size={16}/> {t`Contact`}
                            </button>

                            {me?.id ? (
                                <button
                                    type="button"
                                    className={followed ? "btn btn-outline" : "btn btn-primary"}
                                    onClick={toggleFollow}
                                    aria-pressed={followed}
                                    data-od-id="organizer-subscribe"
                                >
                                    {followed ? (
                                        <>
                                            <IconCheck size={16}/> {t`Subscribed`}
                                        </>
                                    ) : (
                                        <>
                                            <IconPlus size={16}/> {t`Subscribe`}
                                        </>
                                    )}
                                </button>
                            ) : null}
                        </div>
                    </div>

                    {host.bio ? (
                        <div className="cal-id-bio" dangerouslySetInnerHTML={{__html: host.bio}}/>
                    ) : null}

                    <div className="cal-id-foot">
                        {host.socials.length ? (
                            <ul className="cal-socials" data-od-id="organizer-socials">
                                {host.socials.map((social) => {
                                    const Icon = iconsFor(social);
                                    if (!Icon) return null;
                                    return (
                                        <li key={social.platform}>
                                            <a
                                                className="cal-social"
                                                href={social.href}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={social.label}
                                                title={social.label}
                                                data-od-id={`social-${social.platform}`}
                                            >
                                                <Icon size={17}/>
                                            </a>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : null}

                        {nextUp ? (
                            <span className="cal-id-next" data-od-id="organizer-next">
                                {t`Next up`} · {nextUp.month} {nextUp.day}
                            </span>
                        ) : null}
                    </div>
                </div>

                <div className="cal-layout">
                    <div className="cal-main">
                        <div className="cal-toolbar" data-od-id="organizer-toolbar">
                            <div className="search-field">
                                <IconSearch size={18}/>
                                <label className="sr-only" htmlFor="cal-search">{t`Search this calendar`}</label>
                                <input
                                    id="cal-search"
                                    type="search"
                                    value={query}
                                    onChange={(event) => {
                                        setQuery(event.target.value);
                                        setVisible(PAGE);
                                    }}
                                    placeholder={t`Search this calendar`}
                                    autoComplete="off"
                                />
                            </div>

                            <div className="view-switch" role="group" aria-label={t`View`}>
                                {(["list", "calendar"] as View[]).map((option) => (
                                    <button
                                        key={option}
                                        type="button"
                                        aria-pressed={view === option}
                                        onClick={() => changeView(option)}
                                        data-od-id={`view-${option}`}
                                    >
                                        {option === "list" ? t`List` : t`Calendar`}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="cal-filters" data-od-id="organizer-filters">
                            <div className="cal-seg" role="group" aria-label={t`When`}>
                                <button
                                    type="button"
                                    aria-pressed={when === "upcoming"}
                                    onClick={() => changeWhen("upcoming")}
                                    data-od-id="when-upcoming"
                                >
                                    {t`Upcoming`} ({upcomingCount})
                                </button>
                                <button
                                    type="button"
                                    aria-pressed={when === "past"}
                                    onClick={() => changeWhen("past")}
                                    data-od-id="when-past"
                                >
                                    {t`Past`} ({totals?.past ?? past.length})
                                </button>
                            </div>

                            <label className="cal-field">
                                <span className="cal-field-label">{t`Category`}</span>
                                <select
                                    value={category}
                                    onChange={(event) => {
                                        setCategory(event.target.value);
                                        setVisible(PAGE);
                                    }}
                                    data-od-id="filter-category"
                                >
                                    <option value="all">{t`All categories`}</option>
                                    {categories.map((item) => <option key={item} value={item}>{item}</option>)}
                                </select>
                            </label>

                            <label className="cal-field">
                                <span className="cal-field-label">{t`City`}</span>
                                <select
                                    value={city}
                                    onChange={(event) => {
                                        setCity(event.target.value);
                                        setVisible(PAGE);
                                    }}
                                    data-od-id="filter-city"
                                >
                                    <option value="all">{t`All cities`}</option>
                                    {cities.map((item) => <option key={item} value={item}>{item}</option>)}
                                </select>
                            </label>

                            <label className="cal-field">
                                <span className="cal-field-label">{t`Price`}</span>
                                <select
                                    value={price}
                                    onChange={(event) => {
                                        setPrice(event.target.value as Price);
                                        setVisible(PAGE);
                                    }}
                                    data-od-id="filter-price"
                                >
                                    <option value="any">{t`Any price`}</option>
                                    <option value="free">{t`Free`}</option>
                                    <option value="paid">{t`Paid`}</option>
                                </select>
                            </label>

                            <span className="cal-count" aria-live="polite">
                                {filtered.length} {filtered.length === 1 ? t`event` : t`events`}
                            </span>

                            {filtersOn ? (
                                <button
                                    type="button"
                                    className="btn btn-quiet btn-sm"
                                    onClick={clearAll}
                                    data-od-id="filter-clear"
                                >
                                    {t`Clear all`}
                                </button>
                            ) : null}
                        </div>

                        {dayParts ? (
                            <div className="filter-bar" data-od-id="organizer-day-bar">
                                <div className="filter-bar-chips">
                                    <button
                                        type="button"
                                        className="chip"
                                        onClick={() => setDay(null)}
                                        aria-label={`${t`Remove filter`}: ${dayParts.full}`}
                                        data-od-id="organizer-clear-day"
                                    >
                                        {dayParts.weekday} {dayParts.day} {dayParts.month}
                                        <span aria-hidden="true">×</span>
                                    </button>
                                </div>
                            </div>
                        ) : null}

                        <div className="cal-results" ref={resultsRef}>
                            {view === "calendar" ? (
                                <RoomMonthView
                                    key={when}
                                    events={byFilters}
                                    categories={categories}
                                    selected={panelDay}
                                    onSelectDay={setPanelDay}
                                />
                            ) : filtered.length === 0 ? (
                                <div className="empty-state" data-od-id="organizer-empty">
                                    <h3>{day ? t`Nothing on that day` : t`Nothing matches that yet`}</h3>
                                    <p>
                                        {day
                                            ? t`Clear the day filter to see the rest of the calendar.`
                                            : t`Try another category or city — or clear the filters and start again.`}
                                    </p>
                                    <button type="button" className="btn btn-outline" onClick={clearAll}>
                                        {t`Clear filters`}
                                    </button>
                                </div>
                            ) : (
                                <>
                                    <RoomTimeline days={days} categories={categories}/>

                                    {filtered.length > visible ? (
                                        <div className="cal-more" data-od-id="organizer-show-more">
                                            <button
                                                type="button"
                                                className="btn btn-outline"
                                                onClick={() => setVisible((value) => value + PAGE)}
                                            >
                                                {t`Show`} {Math.min(PAGE, filtered.length - visible)} {t`more`}
                                            </button>
                                            <span>{filtered.length - visible} {t`still to come`}</span>
                                        </div>
                                    ) : null}
                                </>
                            )}
                        </div>
                    </div>

                    <aside className="cal-rail" aria-label={t`Calendar tools`}>
                        {view === "calendar" && panelDay ? (
                            <RoomDayPanel
                                date={panelDay}
                                events={panelEvents}
                                categories={categories}
                                onClose={() => setPanelDay(null)}
                            />
                        ) : (
                            <>
                                <RoomMonthPicker
                                    key={when}
                                    events={scoped}
                                    selected={day}
                                    onSelect={(date) => {
                                        setDay(date);
                                        if (date) shouldLand.current = true;
                                    }}
                                />

                                <div className="cal-rail-card">
                                    <h2 className="cal-rail-title">{t`This calendar`}</h2>
                                    <dl className="cal-rail-stats">
                                        <div>
                                            <dt>{t`Upcoming`}</dt>
                                            <dd>{upcomingCount} {t`dates`}</dd>
                                        </div>
                                        <div>
                                            <dt>{t`Cities`}</dt>
                                            <dd>{cities.length}</dd>
                                        </div>
                                        <div>
                                            <dt>{t`Next up`}</dt>
                                            <dd>{nextUp ? `${nextUp.month} ${nextUp.day}` : "—"}</dd>
                                        </div>
                                    </dl>
                                    <button
                                        type="button"
                                        className="btn btn-outline"
                                        onClick={() => downloadRoomIcs(`${host.handle || organizer.id}.ics`, upcoming)}
                                        disabled={!upcoming.length}
                                        data-od-id="export-ics-organizer"
                                    >
                                        {t`Export .ics`}
                                    </button>
                                </div>
                            </>
                        )}
                    </aside>
                </div>

                {/* The organizer's authored blocks, in their authored order. The designer's
                    preview passes them explicitly; the live page falls back to saved settings. */}
                {roomBlockDriven
                    ? roomBlocks.map((block) => renderRoomBlock(block))
                    : <HomepageBlocks blocks={roomBlocks}/>}
            </div>

            {organizer.id ? (
                <ContactOrganizerModal
                    opened={contactOpen}
                    onClose={() => setContactOpen(false)}
                    organizer={organizer}
                />
            ) : null}

            {footer}
        </div>
    );
};
