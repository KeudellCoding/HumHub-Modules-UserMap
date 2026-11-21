# User Location Map

Display active, visible HumHub members on an interactive map with clustered markers and profile links. Open the map from the top menu or show a preview in the dashboard sidebar. Choose your map background and use Google, HERE, LocationIQ, OpenCage or Photon to resolve profile addresses, or provide coordinates through a custom callback. Guests cannot access the map.

Geocoding sends visible profile ZIP codes, cities, countries and optionally streets from the HumHub server to the selected provider. The map applies HumHub's profile permissions and blocking rules. The full map sends names, formatted addresses and coordinates for eligible members. The dashboard preview sends only coordinates. Browsers request map tiles from the configured tile server, which receives the visitor's IP address and the requested tile coordinates. HumHub stores geocoding API keys on the server. Review the provider and street settings before enabling geocoding.

- [Installation, requirements and upgrades](INSTALLATION.md)
- [Configuration, providers, data handling and customization](MANUAL.md)
- [Changelog](CHANGELOG.md)
- [License](LICENSE.md)
