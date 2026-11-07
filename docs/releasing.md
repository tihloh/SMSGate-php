# Composer distribution

The package lives in `packages/php`, separate from the Android and example-server projects. Its proposed Composer name is `tihloh/smsgate`. A package directory plus `composer.json` is installable using a Composer path repository now; creating it does not reserve its name or publish it on Packagist.

## Before publishing

1. Run `composer check`, the concurrency tests, and the MySQL CI suite. Review API names, supported PHP versions, README examples, license, and operational boundaries.
2. Confirm the public [SMSGate-php](https://github.com/tihloh/SMSGate-php) repository mirrors `packages/php` at the repository root. Never point Packagist at the private Android monorepo.
3. Verify the Composer package name is available under your Packagist account. The package's public repository and support URLs are recorded in `composer.json`.
4. In the **public** PHP repository, use **Actions → Release PHP package → Run workflow** with a reviewed semantic version (for example `0.1.0`). This runs package tests, creates a `v0.1.0` tag, and publishes a GitHub release. Do not hardcode a `version` field in composer.json; Composer derives it from Git tags.
5. Submit `https://github.com/tihloh/SMSGate-php` to Packagist once using your publisher account, and configure the GitHub/Packagist update hook. Only after publication can developers use `composer require tihloh/smsgate` without a custom repository.

The public repository exists, but neither a GitHub release tag nor Packagist registration is implied by copying these files. Perform the release workflow and Packagist submission explicitly.

CI is available both in the source monorepo and the public package repository. Public CI runs from the Composer package root. The MySQL test job uses a disposable MySQL 8.0 service. For a local disposable MySQL test database, set `SMSGATE_TEST_MYSQL_DSN`, `SMSGATE_TEST_MYSQL_USER`, and `SMSGATE_TEST_MYSQL_PASSWORD` before `composer test`; each test uses and drops only its own randomly prefixed tables.

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
