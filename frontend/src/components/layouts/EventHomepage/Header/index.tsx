import classes from './Header.module.scss'
import { FC } from 'react';
import { Event } from '../../../../types.ts';
import { getEventCoverImage, makeEventPosterSvg } from '../../../../utilites/imageFallbacks.ts';

export const Header: FC<{
    event: Event
}> = ({ event }) => {

    // Uploaded cover → curated category photo → typographic poster.
    const coverImage = getEventCoverImage(event)
        ?? makeEventPosterSvg(event.title ?? "", event.category);

    if (!coverImage) {
        return <></>;
    }

    return (
        <>
            <header className={classes.header}>
                <img
                    style={{maxWidth: '1000px'}}
                    alt={event?.title}
                    src={coverImage}
                />
            </header>
        </>
    )
}
