import {generateColors} from "@mantine/colors-generator";
import {MantineColorsTuple} from "@mantine/core";
import {getConfig} from "./config.ts";

export type ThemeColors = Record<"primary" | "secondary", MantineColorsTuple>;

// monno brand defaults. Ink drives the interface (it matches the two black
// pills in the mark), so the ink ramp is authored explicitly to keep shade 8
// genuinely near-black. The secondary is the spectrum's cyan-blue, authored as
// an explicit ramp so shades 5+ stay AA-readable as text on light surfaces.
// Either can be overridden at build time with VITE_APP_PRIMARY_COLOR /
// VITE_APP_SECONDARY_COLOR.
const MONNO_INK: MantineColorsTuple = [
    "#F5F5F6",
    "#E7E7E9",
    "#CFD0D2",
    "#B2B3B6",
    "#909196",
    "#6E6F74",
    "#4E4F54",
    "#333438",
    "#1B1C1F",
    "#0B0B0C",
];

const MONNO_SPECTRUM_BLUE: MantineColorsTuple = [
    "#EDF6FD",
    "#D6EAFB",
    "#ADD5F7",
    "#7EBBF0",
    "#4C9FE6",
    "#1478C9",
    "#0E64AA",
    "#0A4F86",
    "#073B62",
    "#052740",
];

export const generateThemeColors = (): ThemeColors => {
    const primaryOverride = getConfig("VITE_APP_PRIMARY_COLOR");
    const secondaryOverride = getConfig("VITE_APP_SECONDARY_COLOR");

    return {
        primary: primaryOverride ? generateColors(primaryOverride) : MONNO_INK,
        secondary: secondaryOverride ? generateColors(secondaryOverride) : MONNO_SPECTRUM_BLUE,
    };
};
