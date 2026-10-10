/**
 * Fallback image and avatar generator for events and organizers.
 *
 * Implements Option 3 (Hybrid):
 * 1. Categorized events without an uploaded photo use curated high-resolution photography.
 * 2. Uncategorized events generate dynamic typographic gradient posters.
 * 3. Organizers and users without logos get deterministic vibrant brand gradients with initials.
 */
/* eslint-disable lingui/no-unlocalized-strings -- palette names, hex colors and SVG
   markup are data/format strings, never rendered as translatable copy. */

import { Event } from "../types.ts";

export type PaletteTone = {
  name: string;
  primary: string;
  end: string;
  gradient: string;
};

export const ORGANIZER_PALETTES: PaletteTone[] = [
  { name: "Terracotta", primary: "#D2601A", end: "#EA7A33", gradient: "linear-gradient(135deg, #D2601A 0%, #EA7A33 100%)" },
  { name: "Slate Blue", primary: "#3B6E8F", end: "#4E88AE", gradient: "linear-gradient(135deg, #3B6E8F 0%, #4E88AE 100%)" },
  { name: "Forest Emerald", primary: "#2E7D5B", end: "#3AA176", gradient: "linear-gradient(135deg, #2E7D5B 0%, #3AA176 100%)" },
  { name: "Crimson Coral", primary: "#C24B3A", end: "#DE6351", gradient: "linear-gradient(135deg, #C24B3A 0%, #DE6351 100%)" },
  { name: "Deep Violet", primary: "#5945B1", end: "#755FE0", gradient: "linear-gradient(135deg, #5945B1 0%, #755FE0 100%)" },
  { name: "Electric Azure", primary: "#1478C9", end: "#2993EC", gradient: "linear-gradient(135deg, #1478C9 0%, #2993EC 100%)" },
  { name: "Orchid Magenta", primary: "#B0479A", end: "#C961B3", gradient: "linear-gradient(135deg, #B0479A 0%, #C961B3 100%)" },
  { name: "Amber Gold", primary: "#B87B0E", end: "#D6951F", gradient: "linear-gradient(135deg, #B87B0E 0%, #D6951F 100%)" },
  { name: "Teal Ocean", primary: "#128282", end: "#1CA1A1", gradient: "linear-gradient(135deg, #128282 0%, #1CA1A1 100%)" },
  { name: "Berry Rose", primary: "#A83259", end: "#C64874", gradient: "linear-gradient(135deg, #A83259 0%, #C64874 100%)" },
];

export const CATEGORY_DEFAULT_IMAGE: Record<string, string> = {
  MUSIC: "/images/categories/hero-festival.jpg",
  NIGHTLIFE: "/images/categories/event-nightlife.jpg",
  FESTIVAL: "/images/categories/event-festival.jpg",
  SEASONAL: "/images/categories/event-christmas-market.jpg",
  FITNESS: "/images/categories/event-run-club.jpg",
  COMEDY: "/images/categories/event-comedy.jpg",
  THEATER: "/images/categories/event-theater.jpg",
  FILM: "/images/categories/event-film.jpg",
  DANCE: "/images/categories/event-dance.jpg",
  ART: "/images/categories/event-gallery.jpg",
  SOCIAL: "/images/categories/event-coffee.jpg",
  FAMILY: "/images/categories/event-family-craft.jpg",
  HOBBIES: "/images/categories/event-board-games.jpg",
  FOOD_DRINK: "/images/categories/event-market.jpg",
  WELLNESS: "/images/categories/event-yoga.jpg",
  SPIRITUALITY: "/images/categories/event-spirituality.jpg",
  OUTDOORS: "/images/categories/event-seedlings.jpg",
  TOURS: "/images/categories/event-tours.jpg",
  CHARITY: "/images/categories/event-charity.jpg",
  BUSINESS: "/images/categories/event-conference.jpg",
  TECH: "/images/categories/event-hackathon.jpg",
  EDUCATION: "/images/categories/event-bookclub.jpg",
  WORKSHOP: "/images/categories/event-workshop.jpg",
  Music: "/images/categories/hero-festival.jpg",
  Nightlife: "/images/categories/event-nightlife.jpg",
  Festival: "/images/categories/event-festival.jpg",
  Seasonal: "/images/categories/event-christmas-market.jpg",
  Fitness: "/images/categories/event-run-club.jpg",
  Comedy: "/images/categories/event-comedy.jpg",
  Theater: "/images/categories/event-theater.jpg",
  Film: "/images/categories/event-film.jpg",
  Dance: "/images/categories/event-dance.jpg",
  Art: "/images/categories/event-gallery.jpg",
  Social: "/images/categories/event-coffee.jpg",
  Family: "/images/categories/event-family-craft.jpg",
  Hobbies: "/images/categories/event-board-games.jpg",
  "Food & Drink": "/images/categories/event-market.jpg",
  Wellness: "/images/categories/event-yoga.jpg",
  Spirituality: "/images/categories/event-spirituality.jpg",
  Outdoors: "/images/categories/event-seedlings.jpg",
  Tours: "/images/categories/event-tours.jpg",
  Charity: "/images/categories/event-charity.jpg",
  Business: "/images/categories/event-conference.jpg",
  Tech: "/images/categories/event-hackathon.jpg",
  Education: "/images/categories/event-bookclub.jpg",
  Workshop: "/images/categories/event-workshop.jpg",
};

export function hashString(str: string): number {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = (hash << 5) - hash + str.charCodeAt(i);
    hash |= 0;
  }
  return Math.abs(hash);
}

export function getOrganizerPalette(name?: string | null): PaletteTone {
  if (!name || !name.trim()) return ORGANIZER_PALETTES[0];
  const index = hashString(name.trim()) % ORGANIZER_PALETTES.length;
  return ORGANIZER_PALETTES[index];
}

export function getInitials(name?: string | null, max = 2): string {
  if (!name || !name.trim()) return "?";
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 1) {
    return words[0].slice(0, max).toUpperCase();
  }
  return words
    .slice(0, max)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

/** Escape text for safe interpolation into SVG/XML element content or attributes. */
export function escapeXml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

export function getEventCoverImage(event?: Partial<Event> | null): string | null {
  if (!event) return null;
  const customCover = event.images?.find((img) => img.type === "EVENT_COVER" || img.type === "EVENT_IMAGE")?.url;
  if (customCover && customCover.trim() && customCover !== "/images/hero-festival.jpg") {
    return customCover;
  }
  if (event.category && CATEGORY_DEFAULT_IMAGE[event.category]) {
    return CATEGORY_DEFAULT_IMAGE[event.category];
  }
  return null;
}

export function makeOrganizerAvatarSvg(name: string, initials?: string): string {
  const palette = getOrganizerPalette(name);
  const mark = escapeXml(initials || getInitials(name));
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="${palette.primary}"/>
      <stop offset="100%" stop-color="${palette.end}"/>
    </linearGradient>
  </defs>
  <rect width="128" height="128" rx="38" fill="url(#g)"/>
  <rect width="126" height="126" x="1" y="1" rx="37" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="2"/>
  <text x="50%" y="54%" text-anchor="middle" dominant-baseline="central" fill="#ffffff" font-family="system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-weight="700" font-size="48" letter-spacing="1">${mark}</text>
</svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

export function makeEventPosterSvg(title: string, category?: string | null): string {
  const palette = getOrganizerPalette(title);
  // Only letters/digits survive into the monogram so it always reads as initials.
  const initials = escapeXml(getInitials(title).replace(/[^A-Z0-9]/gi, "").slice(0, 2) || getInitials(title));
  const cleanTitle = escapeXml((title || "Event").slice(0, 36));
  const cleanCategory = escapeXml((category || "Event").toUpperCase().slice(0, 36));

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000" width="1600" height="1000">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="${palette.primary}"/>
      <stop offset="100%" stop-color="${palette.end}"/>
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="35%" r="70%">
      <stop offset="0%" stop-color="rgba(255,255,255,0.18)"/>
      <stop offset="100%" stop-color="rgba(0,0,0,0.32)"/>
    </radialGradient>
    <linearGradient id="glass" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="rgba(255,255,255,0.22)"/>
      <stop offset="100%" stop-color="rgba(255,255,255,0.08)"/>
    </linearGradient>
  </defs>
  <rect width="1600" height="1000" fill="url(#g)"/>
  <rect width="1600" height="1000" fill="url(#glow)"/>
  <circle cx="800" cy="450" r="150" fill="url(#glass)" stroke="rgba(255,255,255,0.3)" stroke-width="3"/>
  <text x="800" y="465" text-anchor="middle" dominant-baseline="central" fill="#ffffff" font-family="system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-weight="800" font-size="104" letter-spacing="4">${initials}</text>
  <text x="800" y="690" text-anchor="middle" dominant-baseline="central" fill="rgba(255,255,255,0.95)" font-family="system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-weight="600" font-size="34" letter-spacing="1">${cleanTitle}</text>
  <text x="800" y="750" text-anchor="middle" dominant-baseline="central" fill="rgba(255,255,255,0.7)" font-family="system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-weight="700" font-size="20" letter-spacing="4">${cleanCategory}</text>
</svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}
