/* eslint-disable lingui/no-unlocalized-strings -- query keys and colour codes only */
import {useLocation, useParams} from "react-router";
import '../../../styles/widget/default.scss';
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import SelectProducts from "../../routes/product-widget/SelectProducts";
import {useMemo} from "react";
import {Loader} from "@mantine/core";
import {t} from "@lingui/macro";
import {embedPalette, resolveEmbedTheme} from "../../../utilites/colour.ts";
import {getEmbedMode} from "../../../utilites/iframeResize.ts";

const ProductWidget = () => {
    const {eventId} = useParams();
    const location = useLocation();

    const eventOccurrenceId = useMemo(() => {
        const raw = new URLSearchParams(location.search).get('occurrence_id');
        if (raw === null) return null;
        const parsed = Number(raw);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }, [location.search]);

    // Opened in the on-page modal (button, card, calendar): checkout continues in
    // the same sheet instead of asking the page for another modal. Reading the
    // mode here also remembers it for the checkout pages that follow.
    const inModal = useMemo(() => {
        const fromUrl = new URLSearchParams(location.search).get('embed') === 'modal';
        if (fromUrl) getEmbedMode();
        return fromUrl;
    }, [location.search]);

    const checkoutMode = useMemo(() => {
        return new URLSearchParams(location.search).get('Checkout') === 'new-tab' ? 'new-tab' : 'modal';
    }, [location.search]);

    const eventQuery = useGetEventPublic(eventId, true, false, null, eventOccurrenceId);

    const settings = useMemo(() => {
        const searchParams = new URLSearchParams(location.search);
        // monno's ground tinted with the organizer's accent. Colours passed by an
        // older snippet (`data-hievents-primary-color` …) still win, one by one.
        const theme = resolveEmbedTheme(searchParams.get("Theme"));
        const palette = embedPalette(theme, searchParams.get("Accent"));

        return {
            theme,
            radius: searchParams.get("Radius") === 'soft' ? 'soft' : 'round',
            colors: {
                background: searchParams.get("BackgroundColor") || palette.background,
                primary: searchParams.get("PrimaryColor") || palette.text,
                primaryText: searchParams.get("PrimaryTextColor") || palette.text,
                secondary: searchParams.get("SecondaryColor") || palette.accent,
                secondaryText: searchParams.get("SecondaryTextColor") || palette.accentText,
                bodyBackground: searchParams.get("BackgroundColor") || palette.background,
            },
            continueButtonText: searchParams.get("ContinueButtonText") || 'Continue',
            padding: searchParams.get("Padding") || '10px',
        };
    }, [location.search]);

    if (eventQuery.isError || (eventQuery.isFetched && !eventQuery.data)) {
        return (
            <div style={{
                display: 'flex',
                justifyContent: 'center',
                alignItems: 'center',
                height: '100vh',
                padding: '20px',
                textAlign: 'center',
                backgroundColor: settings.colors.background,
                color: settings.colors.primaryText,
            }}>
                <div>
                    <p style={{fontWeight: 600, margin: '0 0 4px'}}>{t`This event is not available`}</p>
                    <p style={{margin: 0, opacity: 0.7}}>{t`It may have been unpublished or removed. Please check the link and try again.`}</p>
                </div>
            </div>
        )
    }

    if (!eventQuery.isFetched || !eventQuery.data) {
        return (
            <div style={{
                display: 'flex',
                justifyContent: 'center',
                alignItems: 'center',
                height: '100vh',
                backgroundColor: settings.colors.background
            }}>
                <Loader color={settings.colors.primaryText} size="md" type="dots"/>
            </div>
        )
    }

    return (
        <div
            className={'full-height monno-embed-root'}
            data-theme={settings.theme}
            data-radius={settings.radius}
            style={{backgroundColor: settings.colors.bodyBackground}}
        >
            <SelectProducts
                widgetMode={inModal ? 'normal' : 'embedded'}
                checkoutMode={checkoutMode}
                event={eventQuery.data}
                colors={settings.colors}
                continueButtonText={settings.continueButtonText}
                padding={settings.padding}
            />
        </div>
    );
};

export default ProductWidget;
