=== Jsonmaker ===
Contributors: fishdan
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT
Tags: links, navigation, json, shortcode

Manage hierarchical link collections via a shortcode, edit them inline, and expose any node as JSON.

== Description ==

Jsonmaker lets administrators curate a nested tree of links directly on the front end. Drop the `[jsonmaker]` shortcode onto a page, expand nodes to add children, rename or remove items inline, and fetch any branch at `/json/<slug>.json`.

**Highlights**

* Inline “Add”, “Edit”, and “Delete” controls for administrators (capability `jsonmaker_manage`).
* Clean JSON endpoint for each node (`/json/<slug>.json`).
* Store either URLs or plain text values; an empty value keeps a node as a container.
* All data persists in a single WordPress option—no custom tables.

== Installation ==

1. Upload the `jsonmaker` folder to `wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Jsonmaker** in **Plugins → Installed Plugins**.
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

= 0.1.1 =
* Addressed WordPress Plugin Check feedback and improved inline asset handling.

= 0.1.0 =
* Initial release.
