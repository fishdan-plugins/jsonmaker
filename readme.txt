=== fishdan Jsonmaker ===
Contributors: fishdan
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.7
License: MIT
License URI: https://opensource.org/licenses/MIT
Tags: links, navigation, json, shortcode

Manage hierarchical link collections via a shortcode, edit them inline, and expose any node as JSON.

== Description ==

fishdan Jsonmaker lets administrators curate a nested tree of links directly on the front end. Drop the `[jsonmaker]` shortcode onto a page, expand nodes to add children, rename or remove items inline, and fetch any branch at `/json/<slug>.json`.

**Highlights**

* Inline “Add”, “Edit”, and “Delete” controls for administrators (capability `jsonmaker_manage`).
* Clean JSON endpoint for each node (`/json/<slug>.json`).
* Store either URLs or plain text values; an empty value keeps a node as a container.
* All data persists in a single WordPress option—no custom tables.

== Installation ==

1. Upload the `fishdan-jsonmaker` folder to `wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **fishdan Jsonmaker** in **Plugins → Installed Plugins**.
3. Add the `[jsonmaker]` shortcode to a page.
4. (Optional) Visit **Settings → Permalinks** and click **Save** to refresh rewrite rules if `/json/<slug>.json` returns 404.

== Frequently Asked Questions ==

= Who can edit the tree? =

Administrators receive the `jsonmaker_manage` capability on activation. Grant it to other roles as needed.

= How do I fetch JSON for a node? =

Hit `/json/<node-slug>.json`. The slug appears in the add/edit form IDs (e.g., `jsonmaker-form-slug`).

= Why do titles have to be unique? =

Each node’s slug is derived from its title. Enforcing unique titles guarantees stable JSON endpoints.

== Changelog ==

= 0.2.7 =
* Added “Import current toolbar” button that can pull JSON directly from the Subscribed Toolbar extension.
* Optional extension ID field plus visible error feedback when the extension isn’t reachable.
* Successful extension imports now auto-select “Replace entire tree” to overwrite the current tree.

= 0.2.6 =
* Adjusted Freemius configuration to use the wp.org-compliant free SDK settings and bumped the plugin version for release.

= 0.2.5 =
* Reworked the admin screen with a dedicated license card that shows active plan details, remaining term, and a “Buy Jsonmaker Basic” button, plus an AJAX license-entry form.
* Added toolbar auto-insertion for “Host Your Own Toolbar” and “Edit your toolbar source” links inside an About folder for every change, ensuring free toolbars promote the upgrade path.
* Refined the tree editor UI with compact +/- toggles, cookie-persisted open/closed state, and collapsible action forms that never overlap.

= 0.2.4 =
* Added a prominent login call-to-action so returning users can access their trees without hunting for the WordPress screen.
* Ensured successful registrations redirect back to the `[jsonmaker]` page instead of dropping users on the default dashboard.

= 0.2.3 =
* Enabled Freemius org-compliance mode, renamed helper APIs, and documented the change.
* Added nonce enforcement helpers, centralized input sanitization, and escaped shortcode output to satisfy Plugin Check.
* Bundled Bootstrap assets locally to comply with wp.org’s CDN restrictions.

= 0.2.2.1 =
* Bulk import now accepts copies of the “Current JSON” output (e.g., `{ "username": { ... } }`) and unwraps the username wrapper automatically.

= 0.2.2 =
* Introduced per-user JSON trees with personalised `/json/<username>/<node>.json` endpoints and a dedicated JSON role plus registration flow.
* Refreshed the shortcode UI with Bootstrap styling, actionable onboarding guidance, and centred collapsible toggles.
* Seed new accounts with a “Popular” starter library and ensured sample endpoints are linked for quick testing.

= 0.2.1 =
* Addressed WordPress Plugin Check feedback by adding translators comments, tightening escaping, and removing debug logging for a compliance-focused release.

= 0.2.0 =
* Renamed the plugin to fishdan Jsonmaker and aligned the text domain, Freemius slug, and packaging directory with the new branding.

= 0.1.7 =
* Wrapped bulk import, JSON preview, and the editing tree in collapsible sections with remembered state per user.
* Added “View Node” shortcut links beside each node’s actions for quick JSON inspection.
* Defaulted management panels to start closed for new visitors while keeping the editor open.
* Refined Freemius admin fallbacks and section styling for a more consistent dashboard experience.

= 0.1.6 =
* Added a published JSON schema and linked helper text for quick validation.
* Introduced bulk import with append/replace modes, optional append targeting, and duplicate-title safeguards.
* Added slug normalization for JSON endpoint requests so mixed-case and spaced URLs resolve correctly.
* Hardened Freemius admin bootstrapping by pre-populating the screen title and falling back to the bundled icon.

= 0.1.5 =
* Added uninstall cleanup for the stored tree and custom capability, plus deactivation rewrite flushing.
* Registered inline assets with explicit versions and localized delete warnings.
* Declared metadata updates for WordPress.org compliance, including current tested version.
* Bundled the Freemius SDK for future distribution and telemetry tooling.

= 0.1.4 =
* Added canonical redirect bypass and ensured CORS headers are sent during redirects for the JSON endpoint.

= 0.1.3 =
* Updated plugin metadata to credit Daniel Fishman.

= 0.1.2 =
* Added CORS headers and OPTIONS handling for the JSON endpoint to support browser extensions.

= 0.1.1 =
* Addressed WordPress Plugin Check feedback and improved inline asset handling.

= 0.1.0 =
* Initial release.
