# Composer distribution

The package lives in `packages/php`, separate from the Android and example-server projects. Its proposed Composer name is `tihloh/smsgate`. A package directory plus `composer.json` is installable using a Composer path repository now; creating it does not reserve its name or publish it on Packagist.

## Before publishing

1. Run `composer check`, the concurrency tests, and the MySQL CI suite. Review API names, supported PHP versions, README examples, license, and operational boundaries.
2. Put the **contents of `packages/php` at the root of a dedicated package repository**, or use a subtree-split release process. Pointing Packagist at the Android monorepo root will not discover a nested composer.json automatically.
3. Verify the package name is available under your publisher account. Add actual package repository/support URLs to composer.json once created.
4. Tag a release only after review (for example an initial `v0.1.0`). Do not hardcode a `version` field in composer.json; Composer derives it from tags.
5. Submit that repository to Packagist and configure its update hook. Only after publication can developers use a normal `composer require tihloh/smsgate` without a custom repository.

No repository, tag, Packagist submission, or deployment is created by the local implementation. Those publishing actions require the owner's account and chosen repository destination.

CI is defined in the monorepo's `.github/workflows/php-package.yml`. When splitting to a dedicated repository, copy that workflow and remove its `packages/php` working-directory/path prefixes. The MySQL test job uses a disposable MySQL 8.0 service. For a local disposable MySQL test database, set `SMSGATE_TEST_MYSQL_DSN`, `SMSGATE_TEST_MYSQL_USER`, and `SMSGATE_TEST_MYSQL_PASSWORD` before `composer test`; each test uses and drops only its own randomly prefixed tables.

## Private installation

Before Packagist publication, a dedicated repository can also be consumed through a Composer `vcs` repository entry plus an explicit development constraint. While it remains inside this monorepo, use the documented **path** repository or a reviewed package archive; a VCS entry pointing at the monorepo root is insufficient.

## Release checks

```sh
composer validate --strict
composer install
composer test
composer archive --format=zip --dir=../../artifacts
```

Inspect the archive: it should contain source, examples, documentation and license, not vendor dependencies, local databases, credentials, or test caches. Runtime users obtain dependencies through Composer. `composer.lock` is used locally for reproducibility but is not committed for this library; consumers maintain their own lockfile.
