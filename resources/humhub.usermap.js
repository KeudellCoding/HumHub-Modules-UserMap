humhub.module('usermap', function (module, require, $) {
    'use strict';

    const Widget = require('ui.widget').Widget;
    const UserMap = Widget.extend();
    const instances = new Set();

    function coordinates(value) {
        if (!value || value.latitude === null || value.longitude === null
            || value.latitude === '' || value.longitude === '') {
            return null;
        }
        const latitude = Number(value.latitude);
        const longitude = Number(value.longitude);
        return Number.isFinite(latitude) && Number.isFinite(longitude)
            && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180
            ? [latitude, longitude] : null;
    }

    function getProfileUrl(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        try {
            const url = new URL(value, window.location.href);
            return url.origin === window.location.origin && (url.protocol === 'http:' || url.protocol === 'https:')
                ? url.href : null;
        } catch {
            return null;
        }
    }

    function popup(user) {
        // Leaflet accepts a DOM element, keeping profile values as text.
        const content = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = user.displayname ?? '';

        const profileUrl = getProfileUrl(user.profileurl);
        if (profileUrl !== null) {
            const link = document.createElement('a');
            link.href = profileUrl;
            link.appendChild(name);
            content.appendChild(link);
        } else {
            content.appendChild(name);
        }

        if (user.formatedaddress) {
            const address = document.createElement('div');
            address.textContent = user.formatedaddress;
            content.appendChild(address);
        }
        return content;
    }

    function addUsers(markers, users, preview) {
        users.forEach((user) => {
            const position = coordinates(user.coords);
            if (position) {
                const marker = L.marker(position);
                if (!preview) {
                    marker.bindPopup(popup(user));
                }
                markers.addLayer(marker);
            }
        });
    }

    function requestLookup(url, token, signal) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            mode: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': yii.getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({token}),
            signal
        });
    }

    function watchUpdates(widget, options, preview, signal) {
        let token = options.updatesToken;
        let failures = 0;
        const notice = widget.$.find('.usermap-pending')[0];
        const poll = async () => {
            try {
                const response = await requestLookup(options.updatesUrl, token, signal);
                if (!response.ok) {
                    if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                        token = null;
                    }
                    throw new Error('Map update failed');
                }
                const result = await response.json();
                if (signal.aborted) {
                    return;
                }
                if (!Array.isArray(result.users) || !(result.next === null || typeof result.next === 'string')) {
                    throw new Error('Unexpected map update');
                }
                addUsers(widget.markers, result.users, preview);
                token = result.next;
                failures = 0;
            } catch {
                if (signal.aborted) {
                    return;
                }
                if (!token || ++failures >= 3) {
                    if (notice) {
                        notice.classList.add('usermap-update-error');
                        notice.querySelector('.usermap-status-text').textContent = notice.dataset.error;
                    }
                    return;
                }
            }
            if (signal.aborted) {
                return;
            }
            if (token) {
                widget.pollTimer = setTimeout(poll, 5000);
            } else {
                notice?.remove();
            }
        };
        widget.pollTimer = setTimeout(poll, 5000);
    }

    async function loadLookups(widget, options, preview, signal) {
        for (const token of options.lookupTokens) {
            if (signal.aborted) {
                return;
            }
            try {
                const response = await requestLookup(options.lookupUrl, token, signal);
                if (!response.ok) {
                    return;
                }
                const result = await response.json();
                if (!signal.aborted && Array.isArray(result.users)) {
                    addUsers(widget.markers, result.users, preview);
                }
            } catch {
                // Navigation or a lost connection ends this widget's outstanding work.
                return;
            }
        }
    }

    UserMap.prototype.init = function () {
        const options = this.options.mapOptions;
        const canvas = this.$.find('.usermap-map')[0];
        const preview = Boolean(canvas.querySelector('.usermap-preview-link'));

        this.map = L.map(canvas, {
            maxZoom: 19,
            zoomControl: !preview,
            dragging: !preview,
            touchZoom: !preview,
            scrollWheelZoom: !preview,
            doubleClickZoom: !preview,
            boxZoom: !preview,
            keyboard: !preview
        }).setView(options.center, options.zoom);

        if (options.tileServer) {
            L.tileLayer(options.tileServer, {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(this.map);
        }

        if (options.geocodingAttribution) {
            const credit = document.createElement('a');
            credit.href = options.geocodingAttribution.url;
            credit.textContent = options.geocodingAttribution.label;
            credit.translate = false;
            this.map.attributionControl.addAttribution(credit.outerHTML);
        }

        if (preview) {
            this.map.getPane('markerPane').inert = true;
        }

        this.markers = L.markerClusterGroup();
        addUsers(this.markers, options.users || [], preview);
        this.map.addLayer(this.markers);
        if (options.updatesToken || options.lookupTokens?.length) {
            this.lookupAbort = new AbortController();
            if (options.updatesToken) {
                watchUpdates(this, options, preview, this.lookupAbort.signal);
            } else {
                loadLookups(this, options, preview, this.lookupAbort.signal);
            }
        }

        // Panels can start collapsed and layouts can resize after PJAX has finished.
        this.resizeObserver = new ResizeObserver(() => {
            if (this.map) {
                this.map.invalidateSize({pan: false});
            }
        });
        this.resizeObserver.observe(canvas);
        instances.add(this);
    };

    UserMap.prototype.destroy = function () {
        clearTimeout(this.pollTimer);
        if (this.lookupAbort) {
            this.lookupAbort.abort();
        }
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }
        if (this.map) {
            this.map.remove();
        }
        this.map = null;
        instances.delete(this);
    };

    $(document).on('pjax:beforeReplace.usermap', function (event, contents, options) {
        const container = options && options.container
            ? document.querySelector(options.container) : event.target;
        instances.forEach((widget) => {
            if (container && container.contains(widget.$[0])) {
                widget.destroy();
            }
        });
    });

    module.export({
        UserMap: UserMap
    });
});
