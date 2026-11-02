# Changelog

All notable changes to `coyote6/laravel-settings` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Site-wide settings in a `settings` table (UUID `id`, unique `name`, JSON
  `value`), named by the cases of an enum that implements
  `Contracts\SettingName` (`label()` and `default()`).
- `setting()` and `saveSetting()` helpers, the `Settings` facade and
  `SettingsManager` (`get`, `set`, `has`, `forget`, `site()->all()`). A read
  returns the stored value, then the call-site default, then the enum's
  `default()`.
- Settings load one at a time, only when read, and are remembered for the
  rest of the request (including "no stored value"), in a scoped binding so
  queued jobs and Octane requests start fresh.
- `php artisan settings:install`: publishes the config and migration, creates
  `App\Enums\Settings\Setting`, and offers to run that one migration
  (`--migrate` to skip the question). Safe to re-run: it does not publish the
  migration twice (plain `vendor:publish` would, because it renames the file
  to the current date), and it will not publish it over an existing
  `settings` table.
- Publishable stubs (`--tag=settings-stubs`).
