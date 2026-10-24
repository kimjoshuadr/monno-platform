/* eslint-disable lingui/no-unlocalized-strings */
(function(scriptElement) {
    // monno embeds. Old `data-hievents-*` snippets keep working: every attribute,
    // class, message and return parameter is read under both names.
    const isScriptLoaded = () => !!(window.monnoEmbedLoaded || window.hiEventWidgetLoaded);

    const logError = (e) => {
        try { console.error('monno embed error:', e); } catch (ignored) { /* noop */ }
    };

    const safe = (fn) => function () {
        try {
            return fn.apply(this, arguments);
        } catch (e) {
            logError(e);
        }
    };

    const loadWidget = safe(() => {
        window.monnoEmbedLoaded = true;
        window.hiEventWidgetLoaded = true;

        let scriptOrigin;
        try {
            scriptOrigin = new URL(scriptElement.src).origin;
        } catch (e) {
            console.error('monno embed error: Invalid script URL');
            return;
        }

        const SANDBOX = [
            'allow-forms', 'allow-scripts', 'allow-same-origin', 'allow-popups',
            'allow-popups-to-escape-sandbox', 'allow-top-navigation',
            'allow-top-navigation-by-user-activation', 'allow-downloads', 'allow-modals',
            'allow-orientation-lock', 'allow-pointer-lock', 'allow-presentation'
        ].join(' ');
        const ALLOW = `payment ${scriptOrigin}; publickey-credentials-get ${scriptOrigin}`;
        const MOBILE_BREAKPOINT = 640;

        /** Ink or white, whichever reads better on a hex colour (WCAG luminance). */
        const textOn = (hex) => {
            const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
            if (!m) return '#ffffff';
            const h = m[1].length === 3 ? m[1].split('').map((c) => c + c).join('') : m[1];
            const lin = (i) => {
                const c = parseInt(h.slice(i, i + 2), 16) / 255;
                return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
            };
            const L = 0.2126 * lin(0) + 0.7152 * lin(2) + 0.0722 * lin(4);
            const onWhite = 1.05 / (L + 0.05);
            const onInk = (L + 0.05) / 0.0545;
            return onInk >= onWhite ? '#0b0b0c' : '#ffffff';
        };

        /** The monno mark, small, for the button. */
        const MARK_SVG = '<svg viewBox="0 0 512 512" aria-hidden="true"><defs><linearGradient id="mg" x1="12%" y1="8%" x2="88%" y2="96%">'
            + '<stop offset="0" stop-color="#0FCFF6"/><stop offset=".4" stop-color="#B2D64A"/><stop offset=".74" stop-color="#FEB52A"/><stop offset="1" stop-color="#FD5A3C"/>'
            + '</linearGradient></defs><rect x="58" y="58" width="396" height="396" rx="104" fill="none" stroke="url(#mg)" stroke-width="40"/>'
            + '<rect x="96" y="96" width="320" height="320" rx="72" fill="#fff"/>'
            + '<rect x="161" y="158" width="66" height="196" rx="33" fill="#0B0B0C"/><rect x="285" y="158" width="66" height="196" rx="33" fill="#0B0B0C"/></svg>';
        const MODAL_WIDTH = 760;

        const topParams = new URLSearchParams(window.location.search);
        const returnEventId = topParams.get('monno_event') || topParams.get('hievents_event');
        const returnOrderId = topParams.get('monno_order') || topParams.get('hievents_order');
        const returnSession = topParams.get('monno_session') || topParams.get('hievents_session');
        const isPaymentReturn = !!(returnEventId && returnOrderId);

        const stripParams = (keys) => {
            try {
                const url = new URL(window.location.href);
                keys.forEach((key) => url.searchParams.delete(key));
                return url.toString();
            } catch (e) {
                return window.location.href;
            }
        };

        const OWNED_PARAMS = ['monno_event', 'monno_order', 'monno_session', 'hievents_event', 'hievents_order', 'hievents_session'];
        const STRIPE_RETURN_PARAMS = ['payment_intent', 'payment_intent_client_secret', 'redirect_status'];
        const parentBaseUrl = stripParams(OWNED_PARAMS.concat(STRIPE_RETURN_PARAMS));

        const resizableIframes = new Set();
        let modal = null;

        const RESUME_KEY = 'hievents.checkout.resume';
        const topStorage = () => {
            try { return window.sessionStorage; } catch (e) { return null; }
        };
        const saveResume = (data) => {
            const store = topStorage();
            if (store) {
                try { store.setItem(RESUME_KEY, JSON.stringify(data)); } catch (e) { /* noop */ }
            }
        };
        const clearResume = () => {
            const store = topStorage();
            if (store) {
                try { store.removeItem(RESUME_KEY); } catch (e) { /* noop */ }
            }
        };
        const readResume = () => {
            const store = topStorage();
            if (!store) return null;
            try { return JSON.parse(store.getItem(RESUME_KEY) || 'null'); } catch (e) { return null; }
        };

        const setFullScreenFixed = (el) => {
            el.style.position = 'fixed';
            el.style.top = '0';
            el.style.left = '0';
            el.style.right = '0';
            el.style.bottom = '0';
        };

        const openCheckoutModal = (path) => {
            if (modal || typeof path !== 'string' || !(path.startsWith('/checkout/') || path.startsWith('/widget/'))) {
                return;
            }

            const separator = path.indexOf('?') > -1 ? '&' : '?';
            const src = scriptOrigin + path + separator
                + 'embed=modal&parentUrl=' + encodeURIComponent(parentBaseUrl);

            const abort = new AbortController();
            const prevFocus = document.activeElement;
            const scrollY = window.scrollY || window.pageYOffset || 0;
            const originalBody = {
                position: document.body.style.position,
                top: document.body.style.top,
                width: document.body.style.width,
                overflow: document.body.style.overflow,
            };

            const inerted = [];

            const host = document.createElement('div');
            host.id = 'hievents-checkout-modal';
            setFullScreenFixed(host);
            host.style.zIndex = '2147483647';
            const shadow = host.attachShadow({ mode: 'open' });

            const backdrop = document.createElement('div');
            const bs = backdrop.style;
            setFullScreenFixed(backdrop);
            bs.background = 'rgba(11, 11, 12, 0.66)';
            bs.display = 'flex';
            bs.flexDirection = 'column';
            bs.boxSizing = 'border-box';

            const dialog = document.createElement('div');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-label', 'Checkout');
            const ds = dialog.style;
            ds.position = 'relative';
            ds.background = '#ffffff';
            ds.overflow = 'hidden';
            ds.boxSizing = 'border-box';
            ds.display = 'flex';
            ds.flexDirection = 'column';
            ds.flex = '0 0 auto';
            ds.boxShadow = '0 24px 64px rgba(11, 11, 12, 0.35)';

            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'Close checkout');
            closeBtn.textContent = '×';
            const cs = closeBtn.style;
            cs.position = 'absolute';
            cs.top = '12px';
            cs.right = '12px';
            cs.zIndex = '2';
            cs.width = '36px';
            cs.height = '36px';
            cs.padding = '0';
            cs.border = 'none';
            cs.borderRadius = '50%';
            cs.cursor = 'pointer';
            cs.background = '#f5f6f8';
            cs.color = '#0b0b0c';
            cs.border = '1px solid #e4e6ea';
            cs.fontSize = '22px';
            cs.lineHeight = '34px';
            cs.fontFamily = 'system-ui, sans-serif';
            cs.textAlign = 'center';

            const spinner = document.createElement('div');
            const sp = spinner.style;
            setFullScreenFixed(spinner);
            sp.position = 'absolute';
            sp.display = 'flex';
            sp.alignItems = 'center';
            sp.justifyContent = 'center';
            sp.color = '#6e7178';
            sp.fontFamily = '"Plus Jakarta Sans", system-ui, sans-serif';
            sp.fontSize = '14px';
            spinner.textContent = 'Loading…';

            const iframe = document.createElement('iframe');
            iframe.setAttribute('sandbox', SANDBOX);
            iframe.setAttribute('allow', ALLOW);
            iframe.setAttribute('title', 'monno Checkout');
            const ifs = iframe.style;
            ifs.border = 'none';
            ifs.width = '100%';
            ifs.flex = '1 1 auto';
            ifs.background = '#ffffff';
            iframe.src = src;

            const applySizing = () => {
                const vw = window.innerWidth;
                const vh = (window.visualViewport && window.visualViewport.height) || window.innerHeight;

                if (vw <= MOBILE_BREAKPOINT) {
                    bs.alignItems = 'stretch';
                    bs.justifyContent = 'flex-start';
                    bs.padding = '0';
                    ds.width = '100%';
                    ds.maxWidth = 'none';
                    ds.borderRadius = '0';
                    ds.height = `${vh}px`;
                } else {
                    bs.alignItems = 'center';
                    bs.justifyContent = 'center';
                    bs.padding = '16px';
                    ds.width = `${MODAL_WIDTH}px`;
                    ds.maxWidth = '96vw';
                    ds.borderRadius = '12px';
                    ds.height = `${Math.round(vh * 0.92)}px`;
                }
            };

            const requestClose = () => {
                if (!modal) return;
                try {
                    iframe.contentWindow.postMessage({ type: 'hievents:request-close' }, scriptOrigin);
                } catch (e) {
                    closeCheckoutModal();
                    return;
                }
                if (modal.ackTimer) clearTimeout(modal.ackTimer);
                modal.ackTimer = setTimeout(safe(closeCheckoutModal), 4000);
            };

            backdrop.addEventListener('click', safe((e) => {
                if (e.target === backdrop) requestClose();
            }), { signal: abort.signal });
            closeBtn.addEventListener('click', safe(requestClose), { signal: abort.signal });
            document.addEventListener('keydown', safe((e) => {
                if (e.key === 'Escape') requestClose();
            }), { signal: abort.signal });
            window.addEventListener('resize', safe(applySizing), { signal: abort.signal });
            window.addEventListener('orientationchange', safe(applySizing), { signal: abort.signal });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', safe(applySizing), { signal: abort.signal });
            }
            iframe.addEventListener('load', safe(() => {
                spinner.style.display = 'none';
            }), { signal: abort.signal });

            dialog.appendChild(closeBtn);
            dialog.appendChild(spinner);
            dialog.appendChild(iframe);
            backdrop.appendChild(dialog);
            shadow.appendChild(backdrop);

            try {
                Array.from(document.body.children).forEach((el) => {
                    if (el.nodeType === 1 && !el.hasAttribute('inert')) {
                        el.setAttribute('inert', '');
                        inerted.push(el);
                    }
                });
                document.body.style.top = `-${scrollY}px`;
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.overflow = 'hidden';
                document.body.appendChild(host);
            } catch (e) {
                console.error('monno embed error: failed to open checkout modal');
                inerted.forEach((el) => {
                    try { el.removeAttribute('inert'); } catch (err) { /* noop */ }
                });
                document.body.style.position = originalBody.position;
                document.body.style.top = originalBody.top;
                document.body.style.width = originalBody.width;
                document.body.style.overflow = originalBody.overflow;
                try { abort.abort(); } catch (err) { /* noop */ }
                try { host.remove(); } catch (err) { /* noop */ }
                return;
            }

            applySizing();
            try {
                closeBtn.focus();
            } catch (e) {
                /* noop */
            }

            modal = { host, abort, prevFocus, scrollY, originalBody, inerted, ackTimer: null, requestClose };
        };

        const closeCheckoutModal = () => {
            if (!modal) return;
            const current = modal;
            modal = null;

            try { current.abort.abort(); } catch (e) { /* noop */ }
            if (current.ackTimer) clearTimeout(current.ackTimer);
            try { current.host.remove(); } catch (e) { /* noop */ }
            current.inerted.forEach((el) => {
                try { el.removeAttribute('inert'); } catch (e) { /* noop */ }
            });

            document.body.style.position = current.originalBody.position;
            document.body.style.top = current.originalBody.top;
            document.body.style.width = current.originalBody.width;
            document.body.style.overflow = current.originalBody.overflow;
            window.scrollTo(0, current.scrollY);

            try {
                if (current.prevFocus && current.prevFocus.focus) current.prevFocus.focus();
            } catch (e) {
                /* noop */
            }

            clearResume();
        };

        window.addEventListener('message', safe((event) => {
            if (event.origin !== scriptOrigin) {
                return;
            }
            const data = event.data || {};
            const type = typeof data.type === 'string' ? data.type.replace(/^monno:/, 'hievents:') : data.type;
            switch (type) {
                case 'resize': {
                    const height = Number(data.height);
                    if (Number.isFinite(height) && data.iframeId && resizableIframes.has(data.iframeId)) {
                        const clamped = Math.max(0, Math.min(height, 20000));
                        const targetIframe = document.getElementById(data.iframeId);
                        if (targetIframe) targetIframe.style.height = `${clamped}px`;
                    }
                    break;
                }
                case 'hievents:open-checkout':
                    // Card and calendar embeds send an event id; the widget
                    // sends the checkout path it has already built.
                    openCheckoutModal(data.path || (data.eventId ? widgetModalPath(data.eventId, data.params) : null));
                    break;
                case 'hievents:close-checkout':
                    closeCheckoutModal();
                    break;
                case 'hievents:close-pending':
                    if (modal && modal.ackTimer) {
                        clearTimeout(modal.ackTimer);
                        modal.ackTimer = null;
                    }
                    break;
                case 'hievents:checkout-progress':
                    if (data.eventId && data.orderShortId) {
                        saveResume({
                            eventId: data.eventId,
                            orderShortId: data.orderShortId,
                            sessionId: data.sessionId || null,
                            step: data.step || 'details',
                            reservedUntil: data.reservedUntil || null,
                        });
                    }
                    break;
                case 'hievents:checkout-cleared':
                    clearResume();
                    break;
            }
        }));

        const widgetInstanceId = Math.random().toString(36).slice(2);

        /** `data-monno-<name>`, falling back to the original `data-hievents-<name>`. */
        const attr = (el, name) => el.getAttribute(`data-monno-${name}`) ?? el.getAttribute(`data-hievents-${name}`);

        /** Every styling attribute becomes a query param the embed page reads
         *  (`data-monno-primary-color` → `PrimaryColor`, `…-theme` → `Theme`). */
        const styleParams = (el) => {
            const params = [];
            Array.from(el.attributes).forEach((a) => {
                const match = /^data-(monno|hievents)-(.+)$/.exec(a.name);
                if (!match || match[2] === 'id' || match[2] === 'type' || match[2] === 'organizer-id') return;
                const paramName = ('-' + match[2]).replace(/-([a-z])/g, (g) => g[1].toUpperCase());
                params.push(`${paramName}=${encodeURIComponent(a.value)}`);
            });
            return params;
        };

        /** The ticket picker, opened in the on-page modal: it navigates to
         *  checkout inside the same sheet. */
        const widgetModalPath = (eventId, params) =>
            `/widget/${encodeURIComponent(eventId)}?` + (Array.isArray(params) ? params.join('&') : '');

        const makeFrame = (el, path, params, index, title) => {
            const iframe = document.createElement('iframe');
            iframe.setAttribute('sandbox', SANDBOX);
            iframe.setAttribute('allow', ALLOW);
            iframe.setAttribute('title', title);
            iframe.style.border = 'none';
            iframe.style.width = '100%';
            iframe.style.display = 'block';

            const iframeId = `monno-iframe-${widgetInstanceId}-${index}`;
            iframe.id = iframeId;

            const base = [`iframeId=${iframeId}`, `parentUrl=${encodeURIComponent(parentBaseUrl)}`];
            iframe.src = `${scriptOrigin}${path}?` + base.concat(params).join('&');
            el.appendChild(iframe);

            if (attr(el, 'autoresize') !== 'false') {
                resizableIframes.add(iframeId);
            }
        };

        /** A self-contained pill in a closed shadow root, so the host page's CSS
         *  can neither break it nor be broken by it. */
        const makeButton = (el, eventId, params) => {
            const host = document.createElement('span');
            host.style.display = 'inline-block';
            const root = host.attachShadow({ mode: 'closed' });
            const accent = attr(el, 'accent') || '#0b0b0c';
            const variant = attr(el, 'variant') === 'outline' ? 'outline' : 'solid';
            const label = attr(el, 'label') || 'Get tickets';
            const radius = attr(el, 'radius') === 'soft' ? '12px' : '999px';
            const ink = textOn(accent);

            const style = document.createElement('style');
            style.textContent = `
                button{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:10px;
                  min-height:48px;padding:0 22px 0 14px;border-radius:${radius};cursor:pointer;
                  font:600 15px/1 "Outfit","Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;
                  letter-spacing:.005em;transition:transform .16s ease,box-shadow .16s ease,background-color .16s ease}
                button.solid{background:${accent};color:${ink};border:1px solid ${accent}}
                button.outline{background:transparent;color:${accent};border:1.5px solid ${accent}}
                button:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(11,11,12,.18)}
                button:focus-visible{outline:3px solid #1478c9;outline-offset:3px}
                svg{width:22px;height:22px;flex:none}
                @media (prefers-reduced-motion:reduce){button{transition:none}button:hover{transform:none}}`;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = variant;
            button.innerHTML = MARK_SVG;
            button.appendChild(document.createTextNode(label));
            button.addEventListener('click', safe(() => openCheckoutModal(widgetModalPath(eventId, params))));

            root.appendChild(style);
            root.appendChild(button);
            el.appendChild(host);
        };

        const embeds = document.querySelectorAll('.monno-embed, .hievents-widget');
        embeds.forEach((el, index) => {
            const rawType = (attr(el, 'type') || attr(el, 'widget-type') || 'tickets').toLowerCase();
            const type = rawType === 'widget' ? 'tickets' : rawType;
            const params = styleParams(el);

            if (type === 'calendar') {
                const organizerId = attr(el, 'organizer-id') || attr(el, 'id');
                if (!organizerId) {
                    console.error('monno embed error: data-monno-organizer-id is required for a calendar');
                    return;
                }
                makeFrame(el, `/embed/organizer/${encodeURIComponent(organizerId)}/calendar`, params, index, 'monno calendar');
                return;
            }

            const eventId = attr(el, 'id');
            if (!eventId) {
                console.error('monno embed error: data-monno-id is required');
                return;
            }

            if (type === 'button') {
                makeButton(el, eventId, params);
            } else if (type === 'card') {
                makeFrame(el, `/embed/event/${encodeURIComponent(eventId)}/card`, params, index, 'monno event');
            } else {
                makeFrame(el, `/widget/${encodeURIComponent(eventId)}`, params, index, 'monno tickets');
            }
        });

        if (isPaymentReturn) {
            const resumed = readResume();
            const recoveredSession = (resumed && String(resumed.orderShortId) === String(returnOrderId))
                ? resumed.sessionId
                : null;
            const sessionForReturn = returnSession || recoveredSession;

            const returnParams = new URLSearchParams();
            if (sessionForReturn) {
                returnParams.set('session_identifier', sessionForReturn);
            }
            STRIPE_RETURN_PARAMS.forEach((key) => {
                const value = topParams.get(key);
                if (value) {
                    returnParams.set(key, value);
                }
            });

            const query = returnParams.toString();
            const path = `/checkout/${encodeURIComponent(returnEventId)}/${encodeURIComponent(returnOrderId)}/payment_return`
                + (query ? `?${query}` : '');
            openCheckoutModal(path);

            try {
                window.history.replaceState({}, document.title, parentBaseUrl);
            } catch (e) {
                console.error('monno embed error: Unable to clean return URL');
            }
        } else {
            const resume = readResume();
            const stillReserved = resume && typeof resume.reservedUntil === 'number'
                && resume.reservedUntil > Date.now();

            if (stillReserved) {
                let path = `/checkout/${encodeURIComponent(resume.eventId)}/${encodeURIComponent(resume.orderShortId)}/${encodeURIComponent(resume.step || 'details')}`;
                if (resume.sessionId) {
                    path += `?session_identifier=${encodeURIComponent(resume.sessionId)}`;
                }
                openCheckoutModal(path);
            } else if (resume) {
                clearResume();
            }
        }
    });

    try {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadWidget);
        } else if (!isScriptLoaded()) {
            loadWidget();
        }
    } catch (e) {
        logError(e);
    }
})(document.currentScript);
