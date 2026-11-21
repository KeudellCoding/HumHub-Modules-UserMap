# User Location Map installation and upgrades

## Requirements

- HumHub 1.19 or later, with its supported PHP and database versions.
- The HumHub server needs HTTPS access to the selected geocoding provider when using address lookups.
- Visitors' browsers need access to the configured raster tile server.

The module uses HumHub's existing PHP dependencies; it does not ship its own Composer packages or require a separate Composer installation. The distribution includes Leaflet 1.9.4 and Leaflet.markercluster 1.5.3 with their licenses in `resources/leaflet/LICENSE` and `resources/leaflet.markercluster/LICENSE`. The module license is in [LICENSE.md](LICENSE.md).

## Installation

Install through HumHub's Marketplace, or place the files in a module directory named `usermap` in your custom module path. Enable User Location Map in Administration → Modules.

Open the module configuration and choose the map placement, background tile server, center and zoom. Review the [data notice](README.md) before selecting a geocoding provider. Photon works without an API key; the other services require their own key. Provider configuration and customization are described in the [manual](MANUAL.md).

## Upgrading to 1.3.0

Marketplace updates apply the module migration automatically. For a manual upgrade, replace the module files in place and run `php protected/yii migrate/up` from the HumHub installation directory. Refresh HumHub's published assets after replacing JavaScript and CSS. Clearing the application cache also removes cached coordinates.

The former default `https://{s}.tile.osm.org/...` and subdomain-based OpenStreetMap tile URLs are normalized to `https://tile.openstreetmap.org/{z}/{x}/{y}.png`; custom tile URLs are retained. The removed directory-menu position resolves to the top menu. Save the module configuration to persist these normalized settings. The update event also persists the normalized menu position when the module is updated through HumHub.

An unsupported saved geocoding provider is rejected before reading coordinates or making requests. It never falls back to another service. Its configuration form shows "No geocoding" with an empty key; save to persist those values, or explicitly choose a supported provider and its key. Old coordinate-cache entries are not reused after this upgrade.

The migration pairs database keys with their provider and deletes the legacy error-cache entry that could contain an API key. File-based keys continue to come from HumHub's configuration. If you set `geocoding_api_key` in `fixed-settings`, also set `geocoding_provider` there. The legacy fixed `google_geocoding_api_key` continues to work for Google. Leave the admin key field blank to retain the current provider's key.

The map shows cached markers as soon as it opens. With an asynchronous HumHub queue, keep the worker running so missing locations can appear automatically on the open map. HERE and synchronous queues load missing markers while the map is open. The module rechecks profile access before resolving an address. Existing customization callbacks remain supported, including the historical `getFormatedAddressCallback` spelling.

The map now uses HumHub's Bootstrap 5 APIs and initializes as a HumHub widget, including after PJAX navigation. Multiple maps, resizing and collapsed dashboard panels are supported.
