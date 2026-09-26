import { useState } from 'react';
import { Link } from 'react-router';
import { t } from '@lingui/macro';
import { IconMenu2, IconSearch, IconX } from '@tabler/icons-react';
import { getConfig } from '../../../utilites/config.ts';
import { CookieSettingsLink } from '../../common/CookieSettingsLink';
import { PoweredByFooter } from '../../common/PoweredByFooter';

/**
 * monno's site chrome around the event page.
 *
 * The room is monno's design, so the page it sits in is monno's too: the same
 * header and footer, with the content links pointed back at the website. The one
 * addition is the platform's legal row — privacy, terms, cookie settings and the
 * attribution the licence requires — because those obligations belong to the
 * surface that actually sells the ticket.
 */
const SITE = (getConfig('VITE_MONNO_SITE_URL', 'http://localhost:3000') ?? 'http://localhost:3000');

/** The website the chrome's content links point at, without a trailing slash. */
export const monnoSiteUrl = (): string => SITE.replace(/\/+$/, '');

/**
 * The main website's page for a platform event.
 *
 * The site shows any LIVE platform event at `/event/{id}/` — the id is the stable
 * key, because a platform slug is derived from the title and two events can share
 * one. Every event has a page there, so every share can point at it; the old
 * per-event path mapping is gone.
 */
export const monnoEventUrl = (eventId?: number | string | null): string | undefined => {
    if (eventId === undefined || eventId === null || eventId === '') return undefined;
    return `${monnoSiteUrl()}/event/${eventId}/`;
};

const NAV = [
    { href: '/discover/', label: t`Discover` },
    { href: '/o/', label: t`Calendars` },
    { href: '/#soon', label: t`What's on` },
];

const FOOTER_COLUMNS = [
    {
        title: t`Product`,
        links: [
            { href: '/discover/', label: t`Discover events` },
            { href: '/#soon', label: t`What's on` },
            { href: '/#categories', label: t`Browse categories` },
        ],
    },
    {
        title: t`Calendars`,
        links: [
            { href: '/o/fieldnotes/', label: 'Field Notes Collective' },
            { href: '/o/harbourrun/', label: 'Harbour Running Co.' },
            { href: '/o/commonground/', label: 'Common Ground Events' },
        ],
    },
    {
        title: t`Company`,
        links: [
            { href: '/#cities', label: t`Cities` },
        ],
    },
];

export const EventRoomHeader = () => {
    const [open, setOpen] = useState(false);

    return (
        <header className="site-header" data-od-id="site-header">
            <div className="page header-inner">
                <a className="brand" href={`${SITE}/`} aria-label="monno home">
                    <img src="/logos/monno-horizontal-light.svg" alt="monno" width={760} height={256}/>
                </a>

                <nav className="main-nav" aria-label={t`Primary`}>
                    {NAV.map((item) => (
                        <a key={item.href} className="nav-link" href={`${SITE}${item.href}`}>
                            {item.label}
                        </a>
                    ))}
                </nav>

                <form
                    className="header-search"
                    action={`${SITE}/discover/`}
                    method="get"
                    role="search"
                    data-od-id="header-search"
                >
                    <div className="search-field">
                        <IconSearch size={16}/>
                        <label className="sr-only" htmlFor="header-q">{t`Search events`}</label>
                        <input id="header-q" name="q" type="search" placeholder={t`Search events`} autoComplete="off"/>
                    </div>
                </form>

                <div className="header-actions">
                    <Link className="btn btn-outline btn-sm" to="/login" data-od-id="header-sign-in">
                        {t`Sign in`}
                    </Link>
                    <Link className="btn btn-outline btn-sm" to="/manage/events" data-od-id="header-create-event">
                        {t`Create event`}
                    </Link>
                    <button
                        type="button"
                        className="menu-toggle"
                        aria-expanded={open}
                        aria-controls="mobile-nav"
                        aria-label={open ? t`Close menu` : t`Open menu`}
                        onClick={() => setOpen((value) => !value)}
                    >
                        {open ? <IconX size={20}/> : <IconMenu2 size={20}/>}
                    </button>
                </div>
            </div>

            {open ? (
                <div className="mobile-nav" id="mobile-nav">
                    <form
                        className="mobile-search"
                        action={`${SITE}/discover/`}
                        method="get"
                        role="search"
                        data-od-id="mobile-search"
                    >
                        <div className="search-field">
                            <IconSearch size={16}/>
                            <label className="sr-only" htmlFor="mobile-q">{t`Search events`}</label>
                            <input id="mobile-q" name="q" type="search" placeholder={t`Search events`} autoComplete="off"/>
                        </div>
                    </form>
                    <nav className="page mobile-nav-inner" aria-label={t`Mobile`}>
                        {NAV.map((item) => (
                            <a key={item.href} href={`${SITE}${item.href}`} onClick={() => setOpen(false)}>
                                {item.label}
                            </a>
                        ))}
                        <Link to="/manage/events" onClick={() => setOpen(false)}>
                            {t`Create event`}
                        </Link>
                    </nav>
                </div>
            ) : null}
        </header>
    );
};

export const EventRoomChromeFooter = () => (
    <footer className="site-footer" data-od-id="site-footer">
        <div className="page">
            <div className="footer-grid">
                <div className="footer-brand">
                    <img
                        src="/logos/monno-horizontal-light.svg"
                        alt="monno"
                        width={760}
                        height={256}
                        style={{ height: 30, width: 'auto' }}
                    />
                    <p>
                        {t`A home for events worth showing up for. Find what's on, save the rooms you like, and show up knowing exactly where to go.`}
                    </p>
                </div>

                {FOOTER_COLUMNS.map((column) => (
                    <div className="footer-col" key={column.title}>
                        <h3>{column.title}</h3>
                        <ul>
                            {column.links.map((link) => (
                                <li key={link.label}>
                                    <a href={`${SITE}${link.href}`}>{link.label}</a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>

            <div className="footer-legal">
                <span>{t`© 2026 monno. All rights reserved.`}</span>
                <div className="event-foot-links">
                    <a href={getConfig('VITE_PRIVACY_URL', 'https://hi.events/privacy-policy')} target="_blank" rel="noreferrer">
                        {t`Privacy Policy`}
                    </a>
                    <a href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service')} target="_blank" rel="noreferrer">
                        {t`Terms of Service`}
                    </a>
                    <CookieSettingsLink/>
                    <PoweredByFooter/>
                </div>
            </div>
        </div>
    </footer>
);

export default EventRoomHeader;
