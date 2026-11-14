=== Smart Plugin Inspector ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Shows which plugin generates each element on your pages, so you can decide which plugins (especially Elementor add-ons) you really need.

== How to use ==

1. Plugins > Add New > Upload Plugin > choose smart-plugin-inspector.zip > Install > Activate.

2. SITE REPORT: go to Tools > Smart Plugin Inspector and click "Scan site now".
   You get:
   - Section 1: every Elementor widget, shortcode and block in your content, grouped by the plugin that provides it, with links to the pages that use it.
     Red "Missing" rows = elements whose plugin is inactive/deleted (broken leftovers).
   - Section 2: active plugins with NO elements found in content (a shortlist to check, not an automatic delete list).
   - Section 3: each Elementor add-on's widgets: how many are registered vs how many you actually use.

3. LIVE PAGE CHECK: open any page on the front end while logged in as admin and click "Inspect Plugins" in the top admin bar.
   - Every plugin element gets a coloured outline. Hover it to see the plugin name.
   - The panel (bottom right) lists the plugins on the page. Click one to highlight only its elements.
   - The "CSS/JS files" tab shows which plugins load files on this page.
   - This also covers Elementor Pro header/footer/popup templates.
   Click "Stop Inspecting" to return to normal. Visitors never see any of this.

== Good to know ==

* Only administrators can see or run anything. Normal visitors are never affected.
* "No elements found" does not mean "safe to delete". SEO, security, cache, backup, SMTP, payment and similar plugins work without placing elements on pages. Always test removals on a staging copy first.
* Elementor "extensions" that only add settings to existing widgets (e.g. motion effects, particles, sticky options from add-ons) are not widgets, so they are not listed. Check the CSS/JS tab on the live page for these.
* Content added by PHP template files or by widgets in Appearance > Widgets is only visible through the live page check, not the site scan.
* You can delete this plugin when you have finished the clean-up.

== Changelog ==

= 1.1.0 =
* Renamed from "Plugin Element Inspector" to "Smart Plugin Inspector". The plugin folder is now smart-plugin-inspector and the report is under Tools > Smart Plugin Inspector.
* Upgrading from Plugin Element Inspector: deactivate and delete the old plugin, then install and activate this one, and click "Scan site now" again. If the old plugin is still active, this one shows a notice and waits instead of causing an error.

= 1.0.0 =
* First release.
