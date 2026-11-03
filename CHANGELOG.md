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
  `SettingsManager` (`get`, `set`, `has`, `forget`, `site()->all()`, which
  returns stored values ordered by name). A read returns the stored value,
  then the call-site default, then the enum's `default()`.
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
- Settings per model ("settings types"): one table per owner model with a
  cascading foreign key, registered under `types` in `config/settings.php`
  (`owner`, `enum`, `model`, optional `owner_key`). Value models extend
  `Models\OwnedSettingValue`; owner models use `Concerns\HasSettings`
  (`$owner->setting()`, `$owner->saveSetting()`).
- `setting()->for($owner)` for `get`, `set`, `has`, `forget` and `all` on one
  owner.
- `setting(ClientSetting::Theme)` reads the current owner: the logged-in user
  by default, or whatever `Settings::resolveOwnerUsing(Client::class, fn ()
  => ...)` returns. With no current owner, reads return the defaults and
  saves throw `MissingSettingsOwner`.
- Clear exceptions for a bad `types` entry (`InvalidSettingsType`), a model
  without exactly one type (`UnregisteredSettingsOwner`), and a setting used
  with another enum's settings (`UnregisteredSettingName`).
