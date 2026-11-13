import {Avatar, Menu, UnstyledButton} from "@mantine/core";
import {getInitials} from "../../../utilites/helpers.ts";
import {
    IconCompass,
    IconLifebuoy,
    IconTicket,
    IconWorld,
    IconLogout,
    IconPlus,
    IconSettingsCog,
    IconShield,
    IconUser,
    IconUsers,
} from "@tabler/icons-react";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {NavLink} from "react-router";
import {t} from "@lingui/macro";
import {authClient} from "../../../api/auth.client.ts";
import {useDisclosure} from "@mantine/hooks";
import {AboutModal} from "../../modals/AboutModal";
import {getConfig} from "../../../utilites/config.ts";
import {CreateOrganizerModal} from "../../modals/CreateOrganizerModal";
import {monnoSiteUrl} from "../../../utilites/monnoSite.ts";

interface Link {
    label: string;
    icon: any;
    link?: string;
    target?: string;
    onClick?: (event: any) => void;
    /** A link off the dashboard (the website), rendered as a plain anchor. */
    href?: string;
    divider?: boolean;
}

export const GlobalMenu = () => {
    const {data: me} = useGetMe();
    const [aboutModalOpen, {open: openAboutModal, close: closeAboutModal}] = useDisclosure(false);
    const [createOrganizerModalOpen, {
        open: openCreateOrganizerModal,
        close: closeCreateOrganizerModal
    }] = useDisclosure(false);


    const links: Link[] = [
        {
            label: t`My Profile`,
            icon: IconUser,
            link: "/manage/profile",
        },
        {
            label: t`Account Settings`,
            icon: IconSettingsCog,
            link: `/account/settings`,
        },
    ];

    if (me?.role === 'ADMIN' || me?.role === 'SUPERADMIN') {
        links.push({
            label: t`User Management`,
            icon: IconUsers,
            link: `/account/users`
        })
    }

    if (me?.role === 'SUPERADMIN') {
        links.push({
            label: t`Admin Dashboard`,
            icon: IconShield,
            link: `/admin`
        })
    }

    if (!getConfig("VITE_HIDE_ABOUT_LINK")) {
        links.push({
            label: `About & Support`,
            icon: IconLifebuoy,
            onClick: openAboutModal,
        });
    }

    links.push({
        label: t`Create Organizer`,
        icon: IconPlus,
        onClick: (event: any) => {
            event.preventDefault();
            openCreateOrganizerModal();
        }
    });

    // The way back out to the website: home, your tickets, and what's on.
    links.push(
        {label: t`monno website`, icon: IconWorld, href: monnoSiteUrl('/'), divider: true},
        {label: t`My tickets`, icon: IconTicket, href: monnoSiteUrl('/profile/')},
        {label: t`Discover events`, icon: IconCompass, href: monnoSiteUrl('/discover/')},
    );

    links.push({
        divider: true,
        label: t`Logout`,
        icon: IconLogout,
        onClick: async (event: any) => {
            event.preventDefault();
            await authClient.logout();
            localStorage.removeItem("token");
            window.location.href = "/auth/login";
        },
    });

    return (
        <>
            <Menu shadow="md" width={200}>
                <Menu.Target>
                    <UnstyledButton>
                        <Avatar color={"primary.1"} radius="xl">
                            {me ? getInitials(me.first_name + " " + me.last_name) : ".."}
                        </Avatar>
                    </UnstyledButton>
                </Menu.Target>

                <Menu.Dropdown>
                    {links.map((link) => [
                        link.divider ? <Menu.Divider key={`${link.label}-divider`}/> : null,
                        link.href ? (
                            <Menu.Item
                                key={link.label}
                                component="a"
                                href={link.href}
                                leftSection={<link.icon/>}
                            >
                                {link.label}
                            </Menu.Item>
                        ) : (
                            <NavLink
                                onClick={link.onClick}
                                to={link.link ?? "#"}
                                key={link.label}
                                target={link.target ?? ""}
                            >
                                <Menu.Item component={"div"} leftSection={<link.icon/>}>
                                    {link.label}
                                </Menu.Item>
                            </NavLink>
                        ),
                    ])}
                </Menu.Dropdown>
            </Menu>
            {aboutModalOpen && <AboutModal onClose={closeAboutModal}/>}
            {createOrganizerModalOpen && <CreateOrganizerModal onClose={closeCreateOrganizerModal}/>}
        </>
    );
};
