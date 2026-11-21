# User Location Map

Display active, visible HumHub members on an interactive map or in a dashboard sidebar. Requires HumHub 1.19 or later.

- [Feature overview and data notice](docs/README.md)
- [Installation, requirements and upgrades](docs/INSTALLATION.md)
- [Configuration, providers and customization](docs/MANUAL.md)
- [Changelog](docs/CHANGELOG.md)

## Release ZIP

The **Release ZIP** GitHub Action runs when a tag starting with `v` is pushed. The tag must match `module.json`, for example `v1.3.0` for version `1.3.0`.

Commit and push the module changes, including `.github/workflows/release.yml` and `.gitattributes`, before creating the version tag. Run these commands from the module repository.

```sh
git tag v1.3.0
git push origin v1.3.0
```

The action attaches `usermap-1.3.0.zip` to a draft GitHub release. The ZIP contains a single `usermap/` directory with `module.json` at its root. Download this asset for the HumHub partner portal.

The archive contains committed runtime code, resources, documentation and licenses. `.gitattributes` excludes tests, development configuration, caches, Git metadata and macOS metadata.

Tags that fail version validation stop the workflow. Tags such as `v1.3.0-rc.1` mark the draft as a prerelease. To rebuild, use **Actions → Release ZIP → Run workflow** and select the tag. If the release exists, the workflow replaces its ZIP asset.

After committing the changes, build the same ZIP locally with the version from `module.json`.

```sh
git archive --format=zip --prefix=usermap/ --output=usermap-1.3.0.zip HEAD
```

See the [test and coding style instructions](tests/README.md).
