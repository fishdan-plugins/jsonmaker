# fishdan Jsonmaker

[![WordPress](https://img.shields.io/badge/WordPress-%5E6.0-blue)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

fishdan Jsonmaker provides hierarchical link trees for WordPress pages: drop in the `[jsonmaker]` shortcode, manage nested URLs inline, and expose any node as live JSON.

[![Watch the fishdan Jsonmaker walkthrough on Loom](https://cdn.loom.com/sessions/thumbnails/7d4b76327b22488985f079cb27f70f2c-78c9589b2983a335-full-play.gif)](https://www.loom.com/share/7d4b76327b22488985f079cb27f70f2c?sid=3b2665fb-bd60-43b2-8a18-dc4989b0a67f).

---

## ✨ Features

- **Editable on the page** – admins get inline “Add”, “Edit”, and “Delete” controls right beside each node.
- **Slugged JSON endpoints** – visit `/json/<node-slug>.json` to retrieve that branch of the tree; perfect for toolbars or dashboards.
- **Flexible values** – store either a URL or plain text; empty values create container nodes.
- **Role-based capability** – grants a dedicated `jsonmaker_manage` capability to administrators (extendable to custom roles).
- **Single-option storage** – the entire tree persists in `jsonmaker_tree`, avoiding extra tables or posts.

---

## 📦 Installation

1. Copy the `fishdan-jsonmaker` folder into your WordPress `wp-content/plugins` directory, or upload the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **fishdan Jsonmaker** under **Plugins → Installed Plugins**.
3. (Optional) Visit **Settings → Permalinks** and click **Save** to flush rewrite rules if `/json/<slug>.json` 404s.

---

## 🚀 Usage

1. Insert the shortcode `[jsonmaker]` into any page or post.
2. View the page while logged in as an administrator.
3. Use the **Add Node** buttons to create child nodes. Titles must be unique; values are optional (leave blank to create a container).
4. Use **Edit** to rename a node (its slug + JSON endpoint update automatically).
5. Use **Delete** to remove a leaf node. Nodes with children must be emptied first.
6. Access any node’s data at `https://your-site.com/json/<slug>.json`.

---

## 🔧 Developer Notes

- **Capability tweaks** – hook into activation or `map_meta_cap` to grant `jsonmaker_manage` to additional roles.
- **Data shape** – node arrays include `title`, `slug`, optional `value`, and optional `children` arrays.
- **Customization** – override the inline styles by dequeuing them and enqueuing a custom stylesheet if desired.
- **Freemius SDK** – access the telemetry/licensing SDK via the `jm_fs()` helper if you need to hook into its events.

---

## 🧪 Local Testing

```bash
php -l jsonmaker.php   # Syntax check
```

To exercise the JSON endpoint locally:

```bash
curl http://wpdev.local/json/fishdan.json
```

Replace `fishdan` with the slug shown in the add/edit forms.

---

## 📬 Support

Questions or ideas? Email [dan@fishdan.com](mailto:dan@fishdan.com).

---

## 📝 License

MIT. See `LICENSE` for details.
