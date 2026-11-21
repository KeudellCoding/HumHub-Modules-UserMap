Changelog
=========

1.3.0 (November 21, 2026)
------------------------
- Fix (security): Prevent stored cross-site scripting in map popups (CWE-79) by rendering names and addresses as text and restricting profile links to valid same-origin HTTP(S) URLs. Thanks to **[sanktsimone](https://vuldb.com/user/102457)** for reporting the issue via [VulDB submission #1055276](https://vuldb.com/submit/1055276).
- Fix (security): Prevent disclosure of restricted profile locations by enforcing HumHub's profile permissions, field visibility and user blocks. The dashboard preview receives only coordinates.
- Protect geocoding credentials by leaving the admin key field blank, requiring a new key when changing to a provider that needs one, and saving each provider and key together. Bind fixed keys to their provider, keep them in configuration and purge the legacy error-cache entry during upgrade.
- Fix (security): Require a CSRF-validated POST to save module settings.
- Require HumHub 1.19 and migrate forms, menus and buttons to the current APIs. Thanks to **[schneiderws](https://github.com/schneiderws)** for reporting the upgrade blocker in [#17](https://github.com/KeudellCoding/HumHub-Modules-UserMap/issues/17).
- Update Leaflet to 1.9.4 and Leaflet.markercluster to 1.5.3, including local assets and licenses.
- Initialize maps through HumHub widgets; support PJAX navigation, resizing, collapsed panels and multiple maps.
- Use the current OpenStreetMap tile URL and fix saving custom tile servers. Thanks to **[RoundAboutWEB](https://github.com/RoundAboutWEB)** for asking about a setup without Google and **[codiflow](https://github.com/codiflow)** for pointing out the OpenStreetMap tile option in [#14](https://github.com/KeudellCoding/HumHub-Modules-UserMap/issues/14).
- Update HERE to Geocoding & Search v7.
- Remove Mapbox support to keep the map background independent of Mapbox: its geocoding API requires results to be used with a Mapbox map. See the [Mapbox requirement](https://docs.mapbox.com/api/search/geocoding/#geocoding-api-pricing).
- Limit geocoding to Google, HERE, LocationIQ, OpenCage and Photon; disable unsupported saved providers without falling back to another service.
- Add LocationIQ, OpenCage and keyless Photon, including provider attribution, free-service request pacing, quota backoff and LocationIQ's 48-hour cache expiry. Thanks to **[Nowis411](https://github.com/Nowis411)** for suggesting these providers in [#14](https://github.com/KeudellCoding/HumHub-Modules-UserMap/issues/14).
- Isolate geocoding caches by provider and credentials; bound requests and cache failed lookups temporarily.
- Resolve uncached addresses outside the page request, deduplicate background jobs and recheck profile access before contacting the provider.
- Use HumHub's outgoing proxy for geocoding and validate custom tile URLs against Leaflet's supported placeholders.
- Follow Google and HERE cache limits, credit both providers on the map and respect OpenCage's daily quota reset.
- Support zero coordinates, missing address fields and existing customization callbacks.
- Accept partial profile addresses with a country and either a ZIP code or a city. Thanks to **[codiflow](https://github.com/codiflow)** for the idea in [PR #15](https://github.com/KeudellCoding/HumHub-Modules-UserMap/pull/15).
- Preserve existing configuration and add isolated regression tests.
- Standardize the PHPUnit configuration as phpunit.xml.dist in the module root.
- Keep docs/README.md as the feature overview with an external-data notice; move configuration to docs/MANUAL.md and requirements and upgrades to docs/INSTALLATION.md.
- Resolve shared cached geocoding errors when displayed in the module settings.
- Allow administrators to rename the top-menu entry and other text elements through HumHub's message overrides. Thanks to **[itsgucci](https://github.com/itsgucci)** for the report and **[codiflow](https://github.com/codiflow)** for identifying the hard-coded label in [#13](https://github.com/KeudellCoding/HumHub-Modules-UserMap/issues/13).
- Format PHP code with HumHub's PHP-CS-Fixer configuration and exclude development tooling from release archives.
- Build the Marketplace ZIP in a draft GitHub release and document the release and test workflows.
- Add a GitHub link for bug reports and feature requests to the module settings.

1.2.5 (June 23, 2023)
----------------------
- Fix (security): Access as a guest was possible if guest access was activated.
- Enh #4, #12: Possibility to exclude streets from geolocation.

1.2.4 (May 14, 2022)
----------------------
- Fix #8: Getting unknown property: humhub\modules\usermap\Module
- Fix #9: Removal of the problems related to the removal of the obsolete "Directory" module (since HumHub 1.11).

1.2.3 (March 17, 2022)
----------------------
- Enh #4, #5, #7: Adding the overridable methods showOnMapCallback, getFormatedAddressCallback and getCoordinatesCallback.
- Enh #6: Add the possibility to specify an custom tile server.

1.2.2 (October 28, 2021)
------------------------
- Enh: This module is now available in HumHum Marketplace.
- Chng: Removal of the update mechanism.
