import { readFileSync } from 'node:fs';
import type { APIRequestContext } from '@playwright/test';

/**
 * Shared handles for the website specs.
 *
 * The website has no hand-written catalogue any more — every event comes from the
 * platform database, seeded by `php artisan monno:seed`. Ids change on every
 * reseed, so the specs resolve the event they care about from the public feed at
 * run time instead of hard-coding a slug or an id.
 */
const MONNO_ENV = '/Users/kimjoshuadr/monno/.env.local';

const fromMonnoEnv = (key: string): string | undefined => {
  try {
    const match = readFileSync(MONNO_ENV, 'utf8').match(new RegExp(`^${key}=(.*)$`, 'm'));
    return match?.[1]?.trim() || undefined;
  } catch {
    return undefined;
  }
};

/**
 * The main website's own origin, read from monno's env — that is the origin monno
 * bakes into its share links and calendar files.
 */
export const monnoSiteUrl = (): string =>
  (process.env.MONNO_SITE_URL ?? fromMonnoEnv('NEXT_PUBLIC_SITE_URL') ?? 'http://localhost:3000').replace(/\/+$/, '');

/** The event the website specs run against (see `MonnoSeedContent`). */
export const SEEDED_EVENT_TITLE = 'Night Sessions: Open-Air Music';

export type SeededEvent = {
  id: number;
  slug: string;
  title: string;
  organizerId: number;
  organizerName: string;
  organizerSlug: string;
};

/** Resolve the seeded event (and its organizer) from the platform's public feed. */
export async function findSeededEvent(
  publicApi: APIRequestContext,
  title: string = SEEDED_EVENT_TITLE,
): Promise<SeededEvent> {
  const res = await publicApi.get(
    '/public/events?view=card&per_page=250&sort_by=created_at&sort_direction=asc',
  );

  if (!res.ok()) {
    throw new Error(`Platform public feed returned ${res.status()} — is the API on :8080?`);
  }

  const body = (await res.json()) as { data?: Record<string, unknown>[] };
  const hit = (body.data ?? []).find((event) => event?.title === title);

  if (!hit) {
    throw new Error(
      `Seeded event "${title}" not found — run: cd "event platform/backend" && php artisan monno:seed`,
    );
  }

  const organizer = (hit.organizer ?? {}) as Record<string, unknown>;

  return {
    id: Number(hit.id),
    slug: String(hit.slug ?? ''),
    title: String(hit.title),
    organizerId: Number(organizer.id ?? 0),
    organizerName: String(organizer.name ?? ''),
    organizerSlug: String(organizer.slug ?? ''),
  };
}
