/* eslint-disable lingui/no-unlocalized-strings -- option values, storage keys and colour codes only; UI copy goes through `t` */
import classes from './WidgetEditor.module.scss';
import SelectProducts from "../../routes/product-widget/SelectProducts";
import {
    Button,
    ColorInput,
    CopyButton as MantineCopy,
    NumberInput,
    Select,
    SegmentedControl,
    Switch,
    Tabs,
    Text,
    TextInput,
} from "@mantine/core";
import {t} from "@lingui/macro";
import {useEffect, useState} from "react";
import {useParams} from "react-router";
import {
    IconCalendarEvent,
    IconCheck,
    IconCopy,
    IconDeviceDesktop,
    IconDeviceMobile,
    IconExternalLink,
    IconId,
    IconPointer,
    IconTicket,
} from "@tabler/icons-react";
import QRCode from "react-qr-code";
import {useGetEventSettings} from "../../../queries/useGetEventSettings.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetOrganizerEvents} from "../../../queries/useGetOrganizerEvents.ts";
import {LoadingMask} from '../LoadingMask';
import {Event} from '../../../types.ts';
import {embedPalette, resolveEmbedTheme} from "../../../utilites/colour.ts";
import {
    EmbedOptions,
    EmbedType,
    htmlSnippet,
    previewQuery,
    reactSnippet,
    wordpressSnippet,
} from "../../../utilites/embedCode.ts";
import {monnoEventUrl, monnoOrganizerUrl} from "../../../utilites/monnoSite.ts";
import '../../../styles/widget/default.scss';

/** The brand's own swatches: ink, then the spectrum. */
const SWATCHES = ['#0B0B0C', '#1478C9', '#0FCFF6', '#2BE09D', '#B2D64A', '#F6D52C', '#FEB52A', '#FD7E2B', '#FD5A3C'];

const embedTypes = (): { value: EmbedType; label: string; hint: string; icon: typeof IconTicket }[] => [
    {value: 'tickets', label: t`Ticket widget`, hint: t`The full ticket picker, inline on your page.`, icon: IconTicket},
    {value: 'button', label: t`Button`, hint: t`One pill that opens checkout on your site.`, icon: IconPointer},
    {value: 'card', label: t`Event card`, hint: t`Photo, date and price — for blogs and link pages.`, icon: IconId},
    {value: 'calendar', label: t`Calendar`, hint: t`Every upcoming date from your organizer.`, icon: IconCalendarEvent},
];

const storageKey = (scope: string) => `monno.embed.${scope}`;

/**
 * The Embeds page: choose what to put on your own site, style it in monno's
 * language with your accent, copy the code. The preview is the real thing —
 * the ticket picker itself, or the embed page the snippet loads.
 *
 * Event scope (`/manage/event/:eventId/widget`) embeds that event; organizer
 * scope (`/manage/organizer/:organizerId/embeds`) starts on the calendar and
 * picks an event for the other types.
 */
export const WidgetEditor = ({organizerId: organizerScopeId}: { organizerId?: string } = {}) => {
    const params = useParams();
    const isOrganizerScope = !!organizerScopeId;
    const [pickedEventId, setPickedEventId] = useState<string | null>(null);
    const eventId = isOrganizerScope ? pickedEventId ?? undefined : params.eventId;

    const eventQuery = useGetEvent(eventId);
    const {data: eventSettings} = useGetEventSettings(eventId);
    const organizerId = organizerScopeId ?? (eventQuery.data?.organizer?.id ? String(eventQuery.data.organizer.id) : undefined);

    const {data: organizerEvents} = useGetOrganizerEvents(organizerScopeId ?? '', {
        pageNumber: 1,
        perPage: 100,
        sortBy: 'start_date',
        sortDirection: 'desc',
        filterFields: {},
    });

    const scope = isOrganizerScope ? `organizer.${organizerScopeId}` : `event.${params.eventId}`;
    const [options, setOptions] = useState<EmbedOptions>({
        type: isOrganizerScope ? 'calendar' : 'tickets',
        theme: 'light',
        accent: '#0B0B0C',
        radius: 'round',
        label: t`Get tickets`,
        variant: 'solid',
        layout: 'list',
        limit: 6,
        continueText: t`Continue`,
        autoResize: true,
    });
    const [restored, setRestored] = useState(false);

    // Saved choices load after mount, so the server render and the first
    // client render agree.
    useEffect(() => {
        try {
            const saved = window.localStorage.getItem(storageKey(scope));
            if (saved) setOptions((current) => ({...current, ...JSON.parse(saved)}));
        } catch {
            /* storage unavailable — start from the defaults */
        }
        setRestored(true);
    }, [scope]);
    const [eventAccent, setEventAccent] = useState<string | null>(null);
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [pageTone, setPageTone] = useState<'light' | 'dark'>('light');
    const [frameHeight, setFrameHeight] = useState<number | null>(null);

    // Card and calendar previews report their height the way they do on a real
    // site, so the frame fits them exactly instead of leaving a blank band.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) return;
            const data = event.data || {};
            if (data.type === 'resize' && data.iframeId === 'monno-preview' && Number.isFinite(Number(data.height))) {
                setFrameHeight(Math.min(Number(data.height), 4000));
            }
        };
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);

    const set = <K extends keyof EmbedOptions>(key: K, value: EmbedOptions[K]) =>
        setOptions((current) => ({...current, [key]: value}));

    // Choices are remembered per event / organizer on this device only.
    useEffect(() => {
        if (!restored) return;
        try {
            window.localStorage.setItem(storageKey(scope), JSON.stringify(options));
        } catch {
            /* storage unavailable — choices simply do not persist */
        }
    }, [options, scope, restored]);

    // The event's own accent (its page theme) is the default tint.
    useEffect(() => {
        const accent = eventSettings?.homepage_theme_settings?.accent;
        if (accent) {
            setEventAccent(accent);
            setOptions((current) => current.accent === '#0B0B0C' ? {...current, accent} : current);
        }
    }, [eventSettings]);

    // Organizer scope: preselect the soonest event for the per-event types.
    useEffect(() => {
        if (isOrganizerScope && !pickedEventId && organizerEvents?.data?.length) {
            setPickedEventId(String(organizerEvents.data[0].id));
        }
    }, [isOrganizerScope, pickedEventId, organizerEvents]);

    const full: EmbedOptions = {...options, eventId, organizerId};
    const needsEvent = options.type !== 'calendar';
    const ready = needsEvent ? !!eventId : !!organizerId;

    const snippets = {
        html: htmlSnippet(full),
        react: reactSnippet(full),
        wordpress: wordpressSnippet(full),
    };

    const previewKey = `${options.type}-${eventId}-${organizerId}-${previewQuery(full)}`;
    // A new preview starts unsized until it reports its own height.
    useEffect(() => setFrameHeight(null), [previewKey]);

    const publicUrl = options.type === 'calendar' ? monnoOrganizerUrl(organizerId) : monnoEventUrl(eventId);
    const palette = embedPalette(resolveEmbedTheme(options.theme), options.accent);

    return (
        <div className={classes.container}>
            <div className={classes.sidebar}>
                <header className={classes.header}>
                    <h2>{t`Embeds`}</h2>
                    <Text c="dimmed" size="sm">
                        {t`Sell from your own website. Checkout opens right on your page, and buyers get monno tickets.`}
                    </Text>
                </header>

                <section className={classes.section}>
                    <h3 className={classes.sectionTitle}>{t`What to embed`}</h3>
                    <div className={classes.typeGrid} role="radiogroup" aria-label={t`Embed type`}>
                        {embedTypes().map((type) => (
                            <button
                                key={type.value}
                                type="button"
                                role="radio"
                                aria-checked={options.type === type.value}
                                className={classes.typeCard}
                                onClick={() => set('type', type.value)}
                            >
                                <type.icon size={22} stroke={1.6}/>
                                <strong>{type.label}</strong>
                                <span>{type.hint}</span>
                            </button>
                        ))}
                    </div>

                    {isOrganizerScope && needsEvent ? (
                        <Select
                            mt="md"
                            label={t`Event`}
                            placeholder={t`Choose an event`}
                            data={(organizerEvents?.data ?? []).map((event) => ({value: String(event.id), label: event.title}))}
                            value={pickedEventId}
                            onChange={setPickedEventId}
                            searchable
                        />
                    ) : null}
                </section>

                <section className={classes.section}>
                    <h3 className={classes.sectionTitle}>{t`Style`}</h3>
                    <Text size="sm" fw={600} mb={6}>{t`Appearance`}</Text>
                    <SegmentedControl
                        fullWidth
                        radius="xl"
                        value={options.theme}
                        onChange={(value) => set('theme', value as EmbedOptions['theme'])}
                        data={[
                            {value: 'light', label: t`Light`},
                            {value: 'dark', label: t`Dark`},
                            {value: 'auto', label: t`Match visitor`},
                        ]}
                    />

                    <ColorInput
                        mt="md"
                        label={t`Accent`}
                        description={t`Used on the button and selected tickets. Text colour adjusts itself.`}
                        value={options.accent}
                        onChange={(value) => set('accent', value)}
                        swatches={SWATCHES}
                        swatchesPerRow={9}
                        format="hex"
                    />
                    {eventAccent && options.accent.toLowerCase() !== eventAccent.toLowerCase() ? (
                        <button type="button" className={classes.textLink} onClick={() => set('accent', eventAccent)}>
                            {t`Reset to the event's colour`}
                        </button>
                    ) : null}

                    <Text size="sm" fw={600} mt="md" mb={6}>{t`Corners`}</Text>
                    <SegmentedControl
                        fullWidth
                        radius="xl"
                        value={options.radius}
                        onChange={(value) => set('radius', value as EmbedOptions['radius'])}
                        data={[
                            {value: 'round', label: t`Round`},
                            {value: 'soft', label: t`Soft`},
                        ]}
                    />

                    {options.type === 'button' ? (
                        <>
                            <TextInput mt="md" label={t`Button label`} value={options.label}
                                       onChange={(e) => set('label', e.currentTarget.value)}/>
                            <Text size="sm" fw={600} mt="md" mb={6}>{t`Button style`}</Text>
                            <SegmentedControl
                                fullWidth
                                radius="xl"
                                value={options.variant}
                                onChange={(value) => set('variant', value as EmbedOptions['variant'])}
                                data={[{value: 'solid', label: t`Solid`}, {value: 'outline', label: t`Outline`}]}
                            />
                        </>
                    ) : null}

                    {options.type === 'calendar' ? (
                        <>
                            <Text size="sm" fw={600} mt="md" mb={6}>{t`Layout`}</Text>
                            <SegmentedControl
                                fullWidth
                                radius="xl"
                                value={options.layout}
                                onChange={(value) => set('layout', value as EmbedOptions['layout'])}
                                data={[{value: 'list', label: t`List`}, {value: 'cards', label: t`Cards`}]}
                            />
                            <NumberInput mt="md" label={t`Dates to show`} min={1} max={24} value={options.limit}
                                         onChange={(value) => set('limit', Number(value) || 6)}/>
                        </>
                    ) : null}

                    {options.type === 'tickets' ? (
                        <TextInput mt="md" label={t`Continue button text`} value={options.continueText}
                                   onChange={(e) => set('continueText', e.currentTarget.value)}/>
                    ) : null}

                    {options.type !== 'button' ? (
                        <Switch
                            mt="md"
                            checked={options.autoResize}
                            onChange={(e) => set('autoResize', e.currentTarget.checked)}
                            label={t`Fit height to content`}
                            description={t`Off: the embed fills the height of its container.`}
                        />
                    ) : null}
                </section>

                <section className={classes.section}>
                    <h3 className={classes.sectionTitle}>{t`Add it to your site`}</h3>
                    {!ready ? (
                        <Text size="sm" c="dimmed">{t`Choose an event to get the code.`}</Text>
                    ) : (
                        <Tabs defaultValue="html" radius="xl" variant="pills" classNames={{list: classes.tabs}}>
                            <Tabs.List>
                                <Tabs.Tab value="html">{t`HTML`}</Tabs.Tab>
                                <Tabs.Tab value="wordpress">{t`WordPress`}</Tabs.Tab>
                                <Tabs.Tab value="react">{t`React`}</Tabs.Tab>
                                <Tabs.Tab value="link">{t`Link`}</Tabs.Tab>
                            </Tabs.List>

                            <Tabs.Panel value="html" pt="sm">
                                <Text size="xs" c="dimmed" mb={8}>{t`Paste where the embed should appear. The script line can go once in your page's <head>.`}</Text>
                                <CodeBlock code={snippets.html}/>
                            </Tabs.Panel>
                            <Tabs.Panel value="wordpress" pt="sm">
                                <Text size="xs" c="dimmed" mb={8}>{t`In the block editor add a "Custom HTML" block and paste this. Nothing to install.`}</Text>
                                <CodeBlock code={snippets.wordpress}/>
                            </Tabs.Panel>
                            <Tabs.Panel value="react" pt="sm">
                                <Text size="xs" c="dimmed" mb={8}>{t`A drop-in component. Render <MonnoEmbed /> where it should appear.`}</Text>
                                <CodeBlock code={snippets.react}/>
                            </Tabs.Panel>
                            <Tabs.Panel value="link" pt="sm">
                                <Text size="xs" c="dimmed" mb={8}>{t`No website? Share the page — or print the QR code.`}</Text>
                                {publicUrl ? (
                                    <div className={classes.linkPanel}>
                                        <CodeBlock code={publicUrl}/>
                                        <div className={classes.qr}>
                                            <QRCode value={publicUrl} size={132}/>
                                        </div>
                                        <Button component="a" href={publicUrl} target="_blank" rel="noreferrer"
                                                variant="default" radius="xl" leftSection={<IconExternalLink size={16}/>}>
                                            {t`Open page`}
                                        </Button>
                                    </div>
                                ) : null}
                            </Tabs.Panel>
                        </Tabs>
                    )}
                </section>
            </div>

            <div className={classes.previewContainer}>
                <div className={classes.previewBar}>
                    <h2>{t`Preview`}</h2>
                    <div className={classes.previewControls}>
                        <SegmentedControl
                            size="xs"
                            radius="xl"
                            value={device}
                            onChange={(value) => setDevice(value as 'desktop' | 'mobile')}
                            data={[
                                {value: 'desktop', label: <span className={classes.segIcon}><IconDeviceDesktop size={15}/>{t`Desktop`}</span>},
                                {value: 'mobile', label: <span className={classes.segIcon}><IconDeviceMobile size={15}/>{t`Mobile`}</span>},
                            ]}
                        />
                        <SegmentedControl
                            size="xs"
                            radius="xl"
                            value={pageTone}
                            onChange={(value) => setPageTone(value as 'light' | 'dark')}
                            data={[{value: 'light', label: t`Light site`}, {value: 'dark', label: t`Dark site`}]}
                        />
                    </div>
                </div>

                <section className={classes.browser} data-device={device} data-tone={pageTone}>
                    <div className={classes.browserChrome}>
                        <span className={classes.dots}><i/><i/><i/></span>
                        <span className={classes.address}>{t`your-website.com`}</span>
                    </div>
                    <div className={classes.page}>
                        <div className={classes.fakeNav}><i/><span/><span/><span/></div>
                        <div className={classes.fakeHero}><span/><span/></div>
                        <div className={classes.embedSlot} data-type={options.type}>
                            {!ready || (needsEvent && !eventQuery.isFetched) ? (
                                <LoadingMask/>
                            ) : options.type === 'tickets' ? (
                                <div className="monno-embed-root" data-radius={options.radius}>
                                    <SelectProducts
                                        event={eventQuery.data as Event}
                                        widgetMode={'preview'}
                                        colors={{
                                            primary: palette.text,
                                            primaryText: palette.text,
                                            secondary: palette.accent,
                                            secondaryText: palette.accentText,
                                            background: palette.background,
                                        }}
                                        continueButtonText={options.continueText}
                                        padding={'16px'}
                                    />
                                </div>
                            ) : options.type === 'button' ? (
                                <ButtonPreview options={options}/>
                            ) : (
                                <iframe
                                    key={`${options.type}-${eventId}-${organizerId}-${previewQuery(full)}`}
                                    className={classes.previewFrame}
                                    title={t`Embed preview`}
                                    style={frameHeight ? {height: frameHeight, minHeight: 0} : undefined}
                                    src={(options.type === 'card'
                                        ? `/embed/event/${eventId}/card?`
                                        : `/embed/organizer/${organizerId}/calendar?`)
                                        + `${previewQuery(full)}&iframeId=monno-preview`}
                                    data-card={options.type === 'card' || undefined}
                                />
                            )}
                        </div>
                        <div className={classes.fakeText}><span/><span/><span/></div>
                    </div>
                </section>
            </div>
        </div>
    );
};

/** The button as the snippet draws it (the real one lives in a shadow root). */
const ButtonPreview = ({options}: { options: EmbedOptions }) => {
    const palette = embedPalette('light', options.accent);
    const solid = options.variant === 'solid';
    return (
        <button
            type="button"
            className={classes.buttonPreview}
            style={{
                background: solid ? palette.accent : 'transparent',
                color: solid ? palette.accentText : palette.accent,
                borderColor: palette.accent,
                borderRadius: options.radius === 'soft' ? 12 : 999,
            }}
        >
            <img src="/favicon.svg" alt="" width={22} height={22}/>
            {options.label || t`Get tickets`}
        </button>
    );
};

const CodeBlock = ({code}: { code: string }) => (
    <div className={classes.code}>
        <pre><code>{code}</code></pre>
        <MantineCopy value={code} timeout={2000}>
            {({copied, copy}) => (
                <Button
                    size="xs"
                    radius="xl"
                    variant={copied ? 'light' : 'filled'}
                    color={copied ? 'teal' : 'dark'}
                    leftSection={copied ? <IconCheck size={14}/> : <IconCopy size={14}/>}
                    onClick={copy}
                    className={classes.copy}
                >
                    {copied ? t`Copied` : t`Copy`}
                </Button>
            )}
        </MantineCopy>
    </div>
);
