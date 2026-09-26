import { useEffect, useRef, useState } from 'react';
import { t } from '@lingui/macro';

/**
 * Share sheet for the event page — monno's, kept real.
 *
 * Always opens, always shows the link, and always gives a way to take it: relying
 * on `navigator.share()` alone silently did nothing wherever the Web Share API is
 * unavailable or refused.
 *
 * The link is handed in rather than read from the address bar: this sheet renders
 * on the public page and inside the builder's preview, where the current address is
 * either the ticket surface or an auth-gated draft link. Callers pass the main
 * website's page for the event.
 */
const TARGETS = [
    { key: 'x', label: 'X' },
    { key: 'linkedin', label: 'LinkedIn' },
    { key: 'whatsapp', label: 'WhatsApp' },
    { key: 'facebook', label: 'Facebook' },
    { key: 'email', label: 'Email' },
    { key: 'copy', label: 'Copy link' },
] as const;

const shareHref = (key: string, url: string, title: string) => {
    const encodedUrl = encodeURIComponent(url);
    const encodedTitle = encodeURIComponent(title);

    switch (key) {
        case 'x':
            return `https://x.com/intent/post?text=${encodedTitle}&url=${encodedUrl}`;
        case 'linkedin':
            return `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}`;
        case 'whatsapp':
            return `https://wa.me/?text=${encodeURIComponent(`${title} — ${url}`)}`;
        case 'facebook':
            return `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
        case 'email':
            return `mailto:?subject=${encodedTitle}&body=${encodedUrl}`;
        default:
            return '';
    }
};

export const EventRoomShare = ({ title, url, onClose }: { title: string; url: string; onClose: () => void }) => {
    const dialogRef = useRef<HTMLDivElement>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        dialogRef.current?.focus();

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard is refused in sandboxed contexts — select the field instead
            // of failing silently, so the link can still be taken by hand.
            dialogRef.current?.querySelector<HTMLInputElement>('.share-url')?.select();
        }
    };

    return (
        <div
            className="dialog-backdrop"
            data-od-id="share-dialog-backdrop"
            onClick={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <div
                className="dialog share-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="share-title"
                ref={dialogRef}
                tabIndex={-1}
                data-od-id="share-dialog"
            >
                <div className="dialog-head">
                    <div>
                        <h2 id="share-title">{t`Share this event`}</h2>
                        <p>{t`Send it to someone who'd come with you.`}</p>
                    </div>
                    <button type="button" className="icon-btn" onClick={onClose} aria-label={t`Close`}>
                        ×
                    </button>
                </div>

                <ul className="share-targets">
                    {TARGETS.map((target) =>
                        target.key === 'copy' ? (
                            <li key={target.key}>
                                <button
                                    type="button"
                                    className="share-target"
                                    onClick={copy}
                                    aria-live="polite"
                                    data-od-id="share-copy"
                                >
                                    {copied ? `✓ ${t`Link copied`}` : target.label}
                                </button>
                            </li>
                        ) : (
                            <li key={target.key}>
                                <a
                                    className="share-target"
                                    href={shareHref(target.key, url, title)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-od-id={`share-${target.key}`}
                                >
                                    {target.label}
                                </a>
                            </li>
                        ),
                    )}
                </ul>

                <div className="share-url-row">
                    <label className="sr-only" htmlFor="share-url">
                        {t`Event link`}
                    </label>
                    <input
                        id="share-url"
                        className="share-url"
                        readOnly
                        value={url}
                        onFocus={(event) => event.currentTarget.select()}
                    />
                </div>
            </div>
        </div>
    );
};

export default EventRoomShare;
