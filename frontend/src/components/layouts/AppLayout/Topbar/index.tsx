import React from "react";
import {NavLink} from "react-router";
import {Breadcrumbs, Burger} from '@mantine/core';
import classes from './Topbar.module.scss';
import {BreadcrumbItem} from "../types";
import {GlobalMenu} from "../../../common/GlobalMenu";
import { getConfig } from "../../../../utilites/config";
import {monnoSiteUrl} from "../../../../utilites/monnoSite.ts";
import {IconArrowUpRight} from "@tabler/icons-react";
import {t} from "@lingui/macro";

interface TopbarProps {
    sidebarOpen: boolean;
    setSidebarOpen: (open: boolean) => void;
    topBarShadow: boolean;
    breadcrumbItems: BreadcrumbItem[];
    topBarContent?: React.ReactNode;
    breadcrumbContentRight?: React.ReactNode;
    actionGroupContent?: React.ReactNode;
}

export const Topbar: React.FC<TopbarProps> = ({
                                                  sidebarOpen,
                                                  setSidebarOpen,
                                                  topBarShadow,
                                                  breadcrumbItems,
                                                  topBarContent = null,
                                                  breadcrumbContentRight = null,
                                                  actionGroupContent = null,
                                              }) => {
    return (
        <div className={`${classes.topBar} ${topBarShadow ? classes.withShadow : ''}`}>
            <div className={classes.topBarMain}>
                <div className={classes.burger}>
                    <Burger
                        color={'#fff'}
                        opened={sidebarOpen}
                        onClick={() => setSidebarOpen(!sidebarOpen)}
                        size={'sm'}
                    />
                </div>
                {/* The logo is the way home — and home is the website, where
                    every event and room actually lives. The dashboard root
                    keeps its own link right beside it. */}
                <div className={classes.logo}>
                    <a href={monnoSiteUrl('/')} title={t`Go to the monno website`}>
                        <img src={getConfig("VITE_APP_LOGO_LIGHT", "/logos/monno-text-dark.svg")} alt={`${getConfig("VITE_APP_NAME", "monno")} logo`} className={classes.logo}/>
                    </a>
                </div>
                <nav className={classes.siteNav} aria-label={t`monno`}>
                    <NavLink to="/manage/events" className={({isActive}) => isActive ? classes.active : undefined}>
                        {t`Dashboard`}
                    </NavLink>
                    <a href={monnoSiteUrl('/')} target="_blank" rel="noreferrer">
                        {t`Website`}
                        <IconArrowUpRight size={14} aria-hidden="true"/>
                    </a>
                </nav>

                {topBarContent}
                <div className={classes.actionGroup}>
                    {actionGroupContent}

                    <div className={classes.menu}>
                        <GlobalMenu/>
                    </div>
                </div>
            </div>

            <div className={classes.breadcrumbsRow}>
                <div className={classes.breadcrumbs}>
                    <Breadcrumbs separator={<span style={{margin: '0 4px', color: '#aaa'}}>/</span>}>
                        {breadcrumbItems.map((item, index) => (
                            <NavLink key={index} to={item.link ?? '#'}>
                                {item.content}
                            </NavLink>
                        ))}
                    </Breadcrumbs>
                </div>
                {breadcrumbContentRight && (
                    <div className={classes.breadcrumbContentRight}>
                        {breadcrumbContentRight}
                    </div>
                )}
            </div>
        </div>
    );
};
