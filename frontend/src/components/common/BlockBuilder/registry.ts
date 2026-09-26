import {t} from "@lingui/macro";
import {
    IconArticle,
    IconBuildingStore,
    IconCode,
    IconLayoutGrid,
    IconListCheck,
    IconMapPin,
    IconMicrophone,
    IconPhoto,
    IconPointer,
    IconQuestionMark,
    IconTicket,
    IconTypography,
    IconUsers,
} from "@tabler/icons-react";
import {HomepageBlockType} from "../../../types.ts";

export interface BlockDefinition {
    type: HomepageBlockType;
    label: string;
    description: string;
    /**
     * Data-driven sections have no fields of their own: their content comes
     * from the event/organizer records. The rest are authored right here.
     */
    dataDriven: boolean;
    defaultSettings: Record<string, any>;
    icon: any;
}

export const blockRegistry: Record<HomepageBlockType, BlockDefinition> = {
    HERO: {
        type: 'HERO',
        label: t`Hero`,
        description: t`Cover image and tagline at the top of the page.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconPhoto,
    },
    ABOUT: {
        type: 'ABOUT',
        label: t`About`,
        description: t`The description written on your event or organizer.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconArticle,
    },
    AGENDA: {
        type: 'AGENDA',
        label: t`Agenda`,
        description: t`Your running order, from the Agenda section in settings.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconListCheck,
    },
    TICKETS: {
        type: 'TICKETS',
        label: t`Tickets`,
        // The ticket picker lives in the page's rail, so it is no longer offered as a
        // section — the entry stays so blocks authored before the move still resolve.
        description: t`The ticket picker, always in the page's rail.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconTicket,
    },
    VENUE: {
        type: 'VENUE',
        label: t`Venue`,
        description: t`Venue name, address and map.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconMapPin,
    },
    ORGANIZER: {
        type: 'ORGANIZER',
        label: t`Host`,
        description: t`Your logo, bio and social links.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconBuildingStore,
    },
    ATTENDEES: {
        type: 'ATTENDEES',
        label: t`Attendees`,
        description: t`How many people are going.`,
        dataDriven: true,
        defaultSettings: {},
        icon: IconUsers,
    },
    TEXT: {
        type: 'TEXT',
        label: t`Text`,
        description: t`A paragraph of your own copy.`,
        dataDriven: false,
        defaultSettings: {body: ''},
        icon: IconTypography,
    },
    CTA: {
        type: 'CTA',
        label: t`Button`,
        description: t`A button linking somewhere.`,
        dataDriven: false,
        defaultSettings: {label: '', url: 'https://'},
        icon: IconPointer,
    },
    FAQ: {
        type: 'FAQ',
        label: t`FAQ`,
        description: t`Questions and answers.`,
        dataDriven: false,
        defaultSettings: {items: [{question: '', answer: ''}]},
        icon: IconQuestionMark,
    },
    LINEUP: {
        type: 'LINEUP',
        label: t`Lineup`,
        description: t`Artists or speakers, in order.`,
        dataDriven: false,
        defaultSettings: {artists: [{name: ''}]},
        icon: IconMicrophone,
    },
    GALLERY: {
        type: 'GALLERY',
        label: t`Gallery`,
        description: t`A grid of images, each with alt text.`,
        dataDriven: false,
        defaultSettings: {images: [{url: 'https://', alt: ''}]},
        icon: IconLayoutGrid,
    },
    EMBED: {
        type: 'EMBED',
        label: t`Embed`,
        description: t`A video, map or form from another site.`,
        dataDriven: false,
        defaultSettings: {url: 'https://'},
        icon: IconCode,
    },
};

export const blockTypeOrder: HomepageBlockType[] = [
    'HERO', 'ABOUT', 'AGENDA', 'VENUE', 'ORGANIZER', 'ATTENDEES',
    'TEXT', 'CTA', 'FAQ', 'LINEUP', 'GALLERY', 'EMBED',
];

export const newBlockId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID().slice(0, 8)
        : `b${Math.random().toString(36).slice(2, 10)}`;
