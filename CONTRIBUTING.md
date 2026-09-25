# Contributing

This public repository is a publication mirror of private source. Open an issue
here for a bug or proposal; include a reproduction and, if helpful, a patch.
Maintainers apply accepted changes in source and publish a mirror release.
Direct mirror pull requests do not update source. See the
[organization contribution guide](https://github.com/nvl-laravel-suite/.github/blob/main/CONTRIBUTING.md).

Preserve Laravel 13 compatibility, strict PHP types, host-owned principal and policy contracts, and tenant-scoped action boundaries. Keep task storage focused on lifecycle and assignee facts; use Media, Content, and Metafields for their own data. Do not expose raw models through the optional management API.

Add focused Pest coverage for behavior and failure paths, then run the package tests, PHPStan at max level, Pint, and `composer packages:validate`. Public API, migration, route, or configuration changes also require updated README, changelog, and upgrade guidance.
