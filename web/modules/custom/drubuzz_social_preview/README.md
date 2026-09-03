# DruBuzz Social Preview

Turns a **Posts** node into platform-accurate social media post previews.

## What it adds

| Piece | Where |
|---|---|
| `field_description_x` | Body text for **X** and **Mastodon** (share a 280-char format) |
| `field_description_linkedin` | Body text for **LinkedIn**, **Facebook** and **Instagram** |
| `field_graphic` | Media (image) shown with the post on every platform |
| 3 form sections | `field_group` details: *X / Mastodon*, *LinkedIn / Facebook / Instagram*, *Media* |
| Preview tabs on the node page | Inline tabbed preview, LinkedIn first (pseudo-field `social_preview_links`) |
| Modal preview | `/node/{node}/social-preview/{platform}` rendered in that platform's layout |

The raw description/graphic fields are hidden on the node view; only the button
row shows. Each tab renders the matching social-post-preview--<platform>.html.twig inline.
`data-dialog-type="modal"`) containing the matching
`social-post-preview--<platform>.html.twig`.

## Platform layouts

- **X** – `Name @handle · time`, body ≤ 280, 16px-radius media, reply/repost/like/views bar.
- **Mastodon** – same shared body as X, full `@user@instance` handle, 500-char budget, boost/favourite bar.
- **LinkedIn** – 48px avatar, "· 1st" degree, headline, `time · globe`, "+ Follow", reactions row, Like/Comment/Repost/Send.
- **Facebook** – 40px avatar, `time · globe`, reaction pill, Like/Comment/Share.
- **Instagram** – compact header, **image is the hero** (a note shows when `field_graphic` is empty), heart/comment/share + bookmark, then `username caption`.

Every preview ends with a character-budget line (`used / limit`) that turns red
when the shared description is too long for the platform being viewed.

## Preview identity (settings form)

**Configuration → Content authoring → Social preview identity**
(`/admin/config/content/social-preview`, permission
*administer drubuzz social preview*) sets the posting account shown on every
preview:

| Field | Used for | Empty falls back to |
|---|---|---|
| Display name | Bold name on LinkedIn / X / Facebook / Mastodon | Node author's display name |
| Username / handle | `@handle` on X & Mastodon, Instagram username | Slug of the display name |
| LinkedIn headline | Grey line under the name on LinkedIn | Hidden |
| Mastodon instance | Host in the full `@user@instance` handle | `mastodon.social` |
| **Profile avatar** | Avatar disc on every platform | Author's `user_picture`, then initials |

Stored in `drubuzz_social_preview.settings` (config, in `config/sync`). The
**Profile avatar** is a **Media entity** (`image` bundle) picked with the media
library (`#type => 'media_library'`, provided by the
`media_library_form_element` contrib module — core's element is field-widget
only). Config holds its `profile_avatar_media` entity ID.
`SocialPostBuilder::build()` renders the media's `field_media_image` at the
`thumbnail` style for the avatar and adds the config cache tag to each preview.

This avatar is **completely independent of the post's own image** (`field_graphic`
on the node): different entity, different form, one is the posting account, the
other is the media attached to that specific post.

The settings form uses no service injection: the media library element caches
the form, so anything fetched in `create()` would be lost when the cached form
object is unserialized on submit.

## Setup scripts (already run on this site)

- `scripts/drubuzz_social_fields.php` – creates the fields + form/view display + `field_group` sections. Idempotent.
- `scripts/drubuzz_social_demo.php` – creates one demo Posts node (node 2) with a graphic.

Config for the fields, displays and the `image` media type is committed in
`config/sync`.
