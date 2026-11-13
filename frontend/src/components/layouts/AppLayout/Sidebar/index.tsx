import React, {useEffect, useState} from "react";
import {Badge, UnstyledButton, VisuallyHidden} from '@mantine/core';
import {IconChevronLeft, IconCompass, IconLayoutDashboard, IconTicket, IconWorld} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import classes from './Sidebar.module.scss';
import {NavItem} from "../types";
import {NavLink} from "react-router";
import classNames from "classnames";
import {useMediaQuery} from "@mantine/hooks";
import {getConfig} from "../../../../utilites/config.ts";
import {monnoSiteUrl} from "../../../../utilites/monnoSite.ts";

interface SidebarProps {
    sidebarOpen: boolean;
    setSidebarOpen: (open: boolean) => void;
    navItems: NavItem[];
    sidebarFooter?: React.ReactNode;
}

export const Sidebar: React.FC<SidebarProps> = ({
                                                    sidebarOpen,
                                                    setSidebarOpen,
                                                    navItems,
                                                    sidebarFooter,
                                                }) => {
    const isMobile = useMediaQuery('(max-width: 768px)');
    // Media queries are client-only; the drawer's website links wait for
    // mount so the server HTML and the first client render match.
    const [mounted, setMounted] = useState(false);
    useEffect(() => setMounted(true), []);

    const renderLinks = () => {
        return navItems.map((item) => {

            if (!item.link && item.link !== "" && item.onClick === undefined) {
                return (
                    <div className={classes.sectionHeading} key={item.label}>
                        {item.label}
                    </div>
                );
            }

            if (item.showWhen && !item.showWhen()) {
                return null;
            }

            if (item.loading) {
                return <a key={item.label} className={classNames(classes.loading, classes.link)}>&nbsp;</a>;
            }


            return (
                <NavLink
                    to={item.comingSoon ? '#' : (item.link ?? '#')}
                    key={item.label}
                    onClick={() => {
                        if (isMobile) {
                            setSidebarOpen(false);
                        }
                        if (item.onClick) item.onClick();
                    }}
                    className={({isActive}) =>
                        `${((item.isActive ? item.isActive(isActive) : isActive) && !item.comingSoon)
                            ? classes.linkActive
                            : ""} ${classes.link}`
                    }
                >
                    {item.icon && <item.icon size={20} className={classes.linkIcon} stroke={1.5}/>}
                    <span>{item.label}</span>
                    {item.badge !== undefined && (
                        <Badge
                            size="xs"
                            radius="xl"
                            className={item.badgeColor ? classes.navBadgeAlert : classes.navBadge}
                        >
                            {item.badge}
                        </Badge>
                    )}
                    {item.comingSoon &&
                        <Badge ml={'4px'} size={'xs'} className={classes.comingSoonBadge}>{t`Coming Soon`}</Badge>}
                </NavLink>
            );
        });
    };

    return (
        <div className={classNames(`${classes.sidebar} ${sidebarOpen ? classes.open : classes.closed}`)}>
            <div className={classes.logo}>
                <a href={monnoSiteUrl('/')} title={t`Go to the monno website`}>
                    <img
                        src={getConfig("VITE_APP_LOGO_LIGHT", "/logos/monno-text-dark.svg")}
                        alt={t`${getConfig("VITE_APP_NAME", "monno")} logo`}
                    />
                </a>
            </div>
            <div className={classes.nav}>
                {renderLinks()}
                {/* On phones the topbar has no room for the website links, so
                    the drawer carries them — the same set as the user menu. */}
                {mounted && isMobile && (
                    <>
                        <div className={classes.sectionHeading}>{t`monno`}</div>
                        <NavLink to="/manage/events" className={classes.link} onClick={() => setSidebarOpen(false)}>
                            <IconLayoutDashboard size={20} className={classes.linkIcon} stroke={1.5}/>
                            <span>{t`Dashboard`}</span>
                        </NavLink>
                        <a href={monnoSiteUrl('/')} className={classes.link}>
                            <IconWorld size={20} className={classes.linkIcon} stroke={1.5}/>
                            <span>{t`monno website`}</span>
                        </a>
                        <a href={monnoSiteUrl('/profile/')} className={classes.link}>
                            <IconTicket size={20} className={classes.linkIcon} stroke={1.5}/>
                            <span>{t`My tickets`}</span>
                        </a>
                        <a href={monnoSiteUrl('/discover/')} className={classes.link}>
                            <IconCompass size={20} className={classes.linkIcon} stroke={1.5}/>
                            <span>{t`Discover events`}</span>
                        </a>
                    </>
                )}
            </div>
            {sidebarFooter && (
                <div className={classes.sidebarFooter}>
                    {sidebarFooter}
                </div>
            )}
            {sidebarOpen && (
                <UnstyledButton
                    className={classes.sidebarClose}
                    onClick={() => setSidebarOpen(!sidebarOpen)}>
                    <IconChevronLeft size={20}/>
                    <VisuallyHidden>{t`Close sidebar`}</VisuallyHidden>
                </UnstyledButton>
            )}
        </div>
    );
};
