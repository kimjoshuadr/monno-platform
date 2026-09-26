<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * Section types a homepage can be assembled from. The public site renders each
 * one; the organizer app's builder only edits them, so both sides key off this.
 */
enum HomepageBlockType
{
    use BaseEnum;

    /** Cover image + tagline — data-driven from the event/organizer. */
    case HERO;
    /** Long description paragraphs. */
    case ABOUT;
    /** Run of show, from the event's agenda rows. */
    case AGENDA;
    /** Product/ticket picker. */
    case TICKETS;
    /** Venue name, address and map pin. */
    case VENUE;
    /** Host card: avatar, bio, socials. */
    case ORGANIZER;
    /** Uploaded images with alt text. */
    case GALLERY;
    /** Question & answer list. */
    case FAQ;
    /** Artists/lineup list. */
    case LINEUP;
    /** Free-form rich text. */
    case TEXT;
    /** Button with a label and destination. */
    case CTA;
    /** Social proof / attendee count. */
    case ATTENDEES;
    /** External URL embed (video, map, form). */
    case EMBED;
}
