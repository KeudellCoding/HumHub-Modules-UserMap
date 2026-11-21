# User Location Map manual

## Display and configuration

Open Administration → Modules → User Location Map → Configure. Choose the top menu or dashboard sidebar, sort order, tile server, initial map center and zoom. The top menu opens the interactive map. Select the dashboard preview to open the full map. Map viewers must sign in. The map applies HumHub's user visibility and blocking rules and requires permission to view each member's profile info page before locating that member.

The default tile server is `https://tile.openstreetmap.org/{z}/{x}/{y}.png`. A custom HTTP(S) raster tile URL must include `{z}`, `{x}` and either `{y}` or `{-y}`. It can also include `{s}` for the subdomain and `{r}` for the retina suffix. Replace any provider-specific placeholders with their actual values before saving. [OSM tile usage policy](https://operations.osmfoundation.org/policies/tiles/)

## Geocoding

The selected provider turns profile addresses into map markers. The module sends an HTTPS GET request and uses the first result.

| Provider | Endpoint | Query parameters | Coordinates in the JSON response | API reference |
| --- | --- | --- | --- | --- |
| Google | `https://maps.googleapis.com/maps/api/geocode/json` | `address`, `key` | `results[0].geometry.location` with `lat` and `lng` when `status` is `OK` | [Geocoding API v3](https://developers.google.com/maps/documentation/geocoding/guides-v3/requests-geocoding) |
| HERE | `https://geocode.search.hereapi.com/v1/geocode` | `q`, `apiKey`, `limit=1` | `items[0].position` with `lat` and `lng` | [Geocoding & Search v7](https://docs.here.com/geocoding-and-search/reference/get_geocode) |
| LocationIQ | `https://us1.locationiq.com/v1/search` | `q`, `key`, `format=json`, `limit=1` | `[0].lat` and `[0].lon` | [Free Form Query](https://docs.locationiq.com/reference/search) |
| OpenCage | `https://api.opencagedata.com/geocode/v1/json` | `q`, `key`, `limit=1`, `no_annotations=1`, `no_record=1` | `results[0].geometry` with `lat` and `lng` when `status.code` is `200` | [OpenCage API v1](https://opencagedata.com/api) |
| Photon (Komoot) | `https://photon.komoot.io/api/` | `q`, `limit=1` | `features[0].geometry.coordinates` in longitude, latitude order | [Photon API](https://github.com/komoot/photon/blob/master/docs/api-v1.md) |

Enable the Geocoding API in the Google Cloud project that provides the key. Google's terms depend on the billing account address. The EEA terms permit displaying geocoded latitude and longitude with the configured map. The standard terms for billing addresses outside the EEA require a Google map when displaying geocoded results on a map. Use Google only if your agreement permits the configured combination. [Google Maps Platform EEA terms](https://cloud.google.com/terms/maps-platform/eea/maps-service-terms#6-geocoding-api), [Google Maps Platform standard terms](https://cloud.google.com/maps-platform/terms/maps-service-terms#6-geocoding-api)

LocationIQ offers a free plan with attribution. OpenCage's free trial is intended for testing. Its request parameters omit annotations and ask the provider to skip logging the query. Photon needs no API key. Its public demo server is intended for moderate use and can throttle heavy usage. [LocationIQ attribution](https://help.locationiq.com/support/solutions/articles/36000216093-what-is-limited-commercial-use-), [OpenCage usage limits and parameters](https://opencagedata.com/api#rate-limiting), [Photon demo-server policy](https://github.com/komoot/photon#demo-server)

The API key field starts blank. Leave it blank to keep the key for the current provider. When switching to a provider that requires a key, enter that provider's key.

In HumHub's `params['fixed-settings']['usermap']`, set both `geocoding_provider` and `geocoding_api_key` when managing a key in configuration. A fixed provider alone can use a database key saved for that same provider. A generic fixed key alone leaves authenticated lookups disabled. The legacy fixed `google_geocoding_api_key` is already specific to Google and can be rotated in place.

The background map works without a geocoding provider. Select "No geocoding" to stop provider lookups; markers then require a custom coordinates callback. The module never switches automatically to another service.

## Profile addresses and external requests

The default address formatter uses only fields marked visible in HumHub. It accepts a ZIP code and city, or either one with a country. A marker based on a partial address may represent a general location. A visible street is included when "Use streets in geocoding" is enabled.

For each uncached address, the HumHub server sends the formatted address to the selected geocoding service using the configured API key, except for keyless Photon. A coordinates callback bypasses the provider entirely. Member names and profile links are not included in the default geocoding query.

The full map sends the display name, profile link, formatted address and coordinates for eligible members. The dashboard preview sends only their coordinates. Browsers load map tiles from the configured tile server. That server receives the visitor's IP address and the requested tile coordinates, which identify the displayed map area. OpenStreetMap operates the default tile server. HumHub serves Leaflet and Markercluster locally.

## Caching, limits and errors

Google coordinates expire after 30 days. HERE coordinates are cached only when the response headers allow shared caching, for up to 30 days. LocationIQ coordinates expire after 48 hours. OpenCage and Photon coordinates are cached until HumHub clears them. The cache identifies entries by provider, credentials and address. Photon uses a keyless cache. Clearing the cache causes new provider lookups the next time a map is displayed. [Google caching policy](https://developers.google.com/maps/documentation/geocoding/policies), [HERE platform terms](https://legal.here.com/us-en/terms/here-platform-terms), [LocationIQ caching policy](https://help.locationiq.com/support/solutions/articles/36000216698-can-i-cache-the-results-)

The map and dashboard show cached markers immediately. HumHub's background queue looks up missing addresses and groups identical lookups into one job. Open maps and dashboard previews check for completed jobs every five seconds and add new markers in place. Jobs contain member and viewer IDs and a lookup fingerprint. The worker checks the current profile permissions, address and credentials before contacting the provider. [HumHub background workers](https://docs.humhub.org/docs/admin/asynchronous-tasks/)

HERE can return coordinates that prohibit shared caching. For HERE, and for installations with a synchronous queue driver, the open map requests one missing address at a time from HumHub using viewer-bound, single-use tokens. Markers appear as results arrive. Leaving the page stops further requests.

Requests to LocationIQ, OpenCage and Photon are spaced by one second, coordinated through HumHub's shared cache and mutex. An HTTP 402 or 429 response pauses further lookups for that provider and key for at least five minutes. The pause also respects `Retry-After` and OpenCage's `X-RateLimit-Reset` for daily quota errors. Google's `OVER_QUERY_LIMIT` response also pauses lookups for five minutes. Cached coordinates remain available during a pause. [OpenCage quota headers](https://opencagedata.com/api#rate-limiting), [Google geocoding status codes](https://developers.google.com/maps/documentation/geocoding/guides-v3/requests-geocoding#StatusCodes)

Failed or empty lookups are cached for five minutes. A LocationIQ HTTP 404 response with `Unable to geocode` counts as an empty result. Outgoing requests use HumHub's cURL proxy settings, verify the TLS certificate and hostname, and time out after five seconds. Geocoding works with PHP's `allow_url_fopen` disabled. The map credits the selected geocoding provider. [LocationIQ error responses](https://docs.locationiq.com/docs/errors)

The configuration page displays the latest geocoding failure as text. The module displays errors from the current structured cache format, which redacts API keys from provider messages. The default geocoding HTTP client logs the provider endpoint with the query string removed.

## Rename the top-menu entry

To use a different label in U.S. English, add this message override to `protected/config/messages/en-US/UsermapModule.base.php` in your HumHub installation. Use the folder for the corresponding language to set a label for another locale. [HumHub message overrides](https://docs.humhub.org/docs/admin/translations/#overwrite-translation-messages)

```php
<?php

return [
    'Usermap' => 'Global Map',
];
```

## Customization callbacks

The following module properties are supported through HumHub's [advanced module configuration](https://docs.humhub.org/docs/admin/advanced-configuration/#module-configurations):

| Property | Signature and result |
| --- | --- |
| showOnMapCallback | Receives a User; return true to include that user, or false to exclude them. Exceptions exclude the user. |
| getFormatedAddressCallback | Receives a User; return an address string, or null when there is no address. The historical spelling is retained for compatibility. |
| getCoordinatesCallback | Receives a User; return an array with latitude and longitude, or null to omit the marker. This bypasses the geocoding provider and its cache. |

Callbacks run for members whose profiles the map viewer can access. Custom PHP callbacks determine which fields they use, so apply the appropriate field restrictions when supplying an address or coordinates. Coordinates must be finite numbers within latitude -90…90 and longitude -180…180. Zero coordinates are supported. A coordinates callback can provide markers without profile address fields or a geocoding API key. Popup names and addresses are rendered as text. Profile links must resolve to an HTTP(S) URL on the HumHub origin.
