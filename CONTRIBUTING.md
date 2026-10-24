# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Setup

```bash
composer install
mysql -e "CREATE DATABASE tracing_test"
```

Database credentials are read from `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` and `DB_DATABASE`.
Copy `phpunit.xml.dist` to a git-ignored `phpunit.xml` if you want to keep them there.

## Before opening a pull request

```bash
DB_DATABASE=tracing_test vendor/bin/pest   # all suites must pass, including tests/Privacy
vendor/bin/phpstan analyse                 # level 8, no new errors
vendor/bin/pint                            # code style
```

## Guidelines

- `declare(strict_types=1);` in every file; classes are `final` unless designed for extension.
- Program to the interfaces in `src/Contracts`; bind implementations in the matching provider in
  `src/Providers`.
- Value objects in `src/Data` are `final readonly`; fixed sets are backed enums in `src/Enums`.
- Nothing slow in the request path: work happens after the response or on the queue.
- Privacy is non-negotiable: never store or send request bodies, headers, cookies, session data or
  unredacted values. Changes touching capture, storage or outbound payloads need privacy tests.
- Add tests for every change and an entry under `Unreleased` in `CHANGELOG.md`.
