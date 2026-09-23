=== Outbound UTM Tagger ===
Contributors: TODO-your-wporg-username
Tags: utm, analytics, outbound links, campaign tracking, referral
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds UTM parameters to links that point to other websites, so the receiving site's analytics can attribute the visit to you.

== Description ==

Outbound UTM Tagger adds UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content) to links on your site that point to *other* websites. When someone follows a tagged link, the destination site's analytics can see the visit came from you.

Links that already carry a `utm_` parameter are never changed, and links to your own site are left alone.

**Two ways to add the tags**

* **HTML mode** — the tags are written into the page on the server (using the WordPress 6.9 HTML enhancement pipeline and the HTML API, so no fragile regular expressions). Works for every visitor, is visible in View Source, and is seen by search engines.
* **JavaScript mode** — the page keeps clean links and a small script adds the tags at the moment a link is activated. Crawlers and anyone copying a link get the clean version.

**Placeholders** let one setting adapt per page — for example `{slug}`, `{path}`, `{post_type}`, `{category}`, `{author}`, `{link_text}`, and `{term:your_taxonomy}`. Every placeholder describes the page, never the visitor, so it stays correct on cached pages.

**Other details**

* Tagging starts switched off — activating the plugin changes nothing until you turn it on and save.
* A per-site skip list excludes any domains you choose; your own domain is always skipped.
* Add the CSS class `noutm` to a link (or a block containing it) to skip it.
* On SiteGround, saving clears the Speed Optimizer cache automatically; elsewhere, clear your page cache after saving.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the Plugins screen.
2. Activate it.
3. Go to Settings → Outbound UTMs, set your defaults, turn it on, and save.

== Frequently Asked Questions ==

= Does it change links to my own site? =
No. Your site's domain (and its www variant) are always skipped, plus any domains you add to the skip list.

= Will it overwrite links that already have UTM parameters? =
No. Any link that already carries a `utm_` parameter is left exactly as written.

= Does it require WordPress 6.9? =
HTML mode uses a WordPress 6.9 feature, so on older versions it shows an admin notice and does nothing. JavaScript mode's requirements are lighter.

== Changelog ==

= 0.2.1 =
* Stage 1: sitewide defaults, placeholders, HTML and JavaScript modes, and the skip list.

== Upgrade Notice ==

= 0.2.1 =
Initial public release.
