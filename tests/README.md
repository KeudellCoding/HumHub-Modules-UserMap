# User Location Map tests

## PHPUnit suite

The suite uses HumHub's installed PHPUnit and simulated provider responses. It covers geocoding, cache and quota behavior, proxy settings, profile access, credentials, tile templates and the map view's HTML encoding. The upgrade and queue integration tests use PDO SQLite with a private in-memory database. They exercise the HumHub settings migration, job deduplication, permission and key changes before execution, and viewer-bound single-use lookups.

Run the suite from the Docker development workspace.

```sh
docker compose exec -T app php -d allow_url_fopen=0 /opt/humhub/protected/vendor/bin/phpunit \
  -c /data/modules/usermap/phpunit.xml.dist
```

For another HumHub installation, set `HUMHUB_PROTECTED_PATH` to its `protected` directory and run PHPUnit from the module root.

## Popup browser test

The test opens popups with the bundled Leaflet libraries. It verifies literal name and address text, same-origin profile links, and a preview with sequential lookups that are cancelled when the widget is removed. The fixture supplies its own map data, responses and tiles.

Serve the fixture on loopback from the development workspace.

```sh
python3 -m http.server 8120 --bind 127.0.0.1 --directory modules/usermap
```

The page shows the test result when loaded in a browser. [Open the popup test](http://127.0.0.1:8120/tests/browser_smoke.html).

## Coding style

Run the HumHub-provided PHP-CS-Fixer with the module configuration. It reuses the core rules and excludes views, translations and dependencies.

```sh
docker compose exec -T -w /data/modules/usermap -e HUMHUB_ROOT=/opt/humhub app \
  php /opt/humhub/protected/vendor/bin/php-cs-fixer fix \
  --config=/data/modules/usermap/.php-cs-fixer.php --using-cache=no --dry-run --diff
```

Remove `--dry-run --diff` to apply formatting. Set `HUMHUB_ROOT` to the HumHub source directory for other layouts.
