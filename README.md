# DruBuzz

DruBuzz is a Drupal 11 application for planning, previewing, and publishing
social media posts. Editors draft a single "Post," see a platform-accurate
preview for each network, route it through an editorial review workflow, and
schedule it to go out automatically to LinkedIn and X.

## Features

- **Post authoring & preview** — Author a post once and preview it exactly as
  it will look on LinkedIn, X, Instagram, Facebook, and Mastodon, each with
  its own character limits and layout, rendered in a modal from the node
  page.
- **AI-assisted copywriting** — Generate LinkedIn/Facebook/Instagram and
  X/Mastodon copy from a short idea using the site's configured AI provider
  (OpenAI or Gemini), via a "Generate with AI" panel on the post form.
- **Editorial workflow** — Content moves through `draft → ready_for_review →
  published` (with a `changes_requested` state) using core Content
  Moderation, gated by dedicated **Content Editor** and **Content Reviewer**
  roles.
- **Content calendar** — A FullCalendar-based `/events/calendar` view for
  scheduling events and posts, with hover tooltips showing full event
  details.
- **Automated publishing** — Connect LinkedIn and X accounts via OAuth 2.0,
  schedule a post's publish time and target channels, and let cron push it
  live (text + image) through each platform's API. A per-post "Publish" tab
  also allows sending immediately with a delivery log.
- **Console-style front-end theme** — A standalone dark "editorial console"
  theme (`drubuzz_theme`) drives the landing page, app shell, calendar,
  composer, and review queue screens, independent of the Claro admin theme.

## Tech stack

- **Drupal 11** (PHP 8.4), managed with Composer
- **DDEV** for local development (nginx-fpm, MariaDB 11.8)
- **Configuration management** — the site is config-sync managed; all
  structural changes are exported to `config/sync` via `drush cex` / `drush
  cim`
- Key contrib modules: `drupal/ai` (+ OpenAI / Gemini providers),
  `drupal/smart_date` (+ calendar kit), `drupal/field_group`,
  `drupal/media_library_form_element`

## Custom modules

| Module | Purpose |
| --- | --- |
| `drubuzz_social_preview` | Renders a Posts node as platform-accurate social previews (LinkedIn, X, Instagram, Facebook, Mastodon) in a modal. |
| `drubuzz_ai` | Generates per-platform post copy from a short idea using the site's AI provider. |
| `drubuzz_publish` | OAuth 2.0 connections to LinkedIn/X plus cron-driven, queue-based publishing of scheduled posts (text + image). |
| `drubuzz_calendar_tooltip` | Adds hover tooltips with full event details to the FullCalendar events view. |

## Custom theme

`drubuzz_theme` is a standalone front-end theme (Claro remains the admin
theme) covering the marketing landing page, the authenticated app shell
(sidebar nav, topbar), the calendar, the post composer, and the moderation
queue.

## Content model

- **Posts** (`posts`) — a social post authored once with per-platform
  description fields (`field_description_x` for X/Mastodon,
  `field_description_linkedin` for LinkedIn/Facebook/Instagram), a shared
  image (`field_graphic`), scheduling (`field_publish_at`), and target
  channels (`field_publish_channels`).
- **Event** (`event`) — calendar events with `smart_date` fields, a category,
  and a body field shown in calendar tooltips.

## Getting started

This project uses [DDEV](https://ddev.com/) for local development.

```bash
ddev start
ddev composer install
ddev drush cim -y      # import configuration
ddev drush uli          # generate a one-time login link
```

The site will be available at **https://drubuzz.ddev.site**.

Configuration changes made in the UI should be exported before committing:

```bash
ddev drush cex -y
```

## Roles & workflow

- **Content Editor** — creates and edits own posts/events, submits them for
  review.
- **Content Reviewer** — edits any post/event, requests changes, or
  publishes.
- **Administrator** — full site access.

All content moderation runs through the `editorial_review` workflow applied
to the `posts` and `event` content types.

## Publishing setup

To enable automated publishing, configure OAuth credentials for each network
at `/admin/config/services/drubuzz-publish` (requires the `administer
drubuzz publish` permission), then connect each account. Cron will pick up
published posts whose `field_publish_at` has arrived and send them to the
selected channels.

## Roadmap

Planned future integrations:

- **Slack app** — post notifications and/or direct publishing to Slack
  channels.
- **WhatsApp messaging** — sending post updates or approvals via WhatsApp
  Business messaging.

## License

See [LICENSE.txt](LICENSE.txt).
