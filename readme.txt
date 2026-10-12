=== Local PR Outbound UTM Tagger ===
Contributors: daniowens
Tags: utm, analytics, outbound links, campaign tracking, referral
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds UTM parameters to links that point to other websites, so the receiving site's analytics can attribute the visit to you.

== Description ==

Outbound UTM Tagger adds UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content) to links on your site that point to *other* websites. When someone follows a tagged link, the destination site's analytics can see the visit came from you.

Links that already carry a `utm_` parameter are never changed, and links to your own site are left alone.

**Two ways to add the tags**

* **HTML mode**: the tags are written into the page on the server (using the WordPress 6.9 HTML enhancement pipeline and the HTML API, so no fragile regular expressions). Works for every visitor, is visible in View Source, and is seen by search engines.
* **JavaScript mode**: the page keeps clean links and a small script adds the tags at the moment a link is activated. Crawlers and anyone copying a link get the clean version.

**Placeholders** let one setting adapt per page, for example `{slug}`, `{path}`, `{post_type}`, `{category}`, `{author}`, `{link_text}`, and `{term:your_taxonomy}`. Every placeholder describes the page, never the visitor, so it stays correct on cached pages.

**Other details**

* Tagging starts switched off: activating the plugin changes nothing until you turn it on and save.
* A per-site skip list excludes any domains you choose; your own domain is always skipped.
* Add the CSS class `noutm` to a link (or a block containing it) to skip it.
* On SiteGround, saving clears the Speed Optimizer cache automatically; elsewhere, clear your page cache after saving.

**From Local PR**

Outbound UTM Tagger is one of [Local PR's free SEO and AI search tools](https://localpr.com/tools/?utm_source=wordpress.org&utm_medium=referral&utm_campaign=local-pr-outbound-utm-tagger).

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
Yes. HTML mode is built on a feature added in WordPress 6.9, so the plugin requires WordPress 6.9 or newer in both modes.

= Do HTML mode and JavaScript mode tag links the same way? =
Yes. Both modes skip the same links and produce the same UTM values, including values built from link text. Only the moment the tags are added differs.

= Does it collect or send any data? =
No. The plugin makes no external requests and stores nothing about your visitors. It only adds parameters to links on your pages, and every value describes the page, never the person viewing it.

== Screenshots ==

1. The settings screen: switch tagging on, choose HTML or JavaScript mode, set the UTM values and placeholders, and list domains to skip.

== Changelog ==

= 0.2.2 =
* Page mode no longer buffers the whole page while tagging is switched off.
* Protocol-relative links to other sites (//example.com/page) are now tagged in HTML mode, as they already were in JavaScript mode.
* International domain names match the skip list whether they are written in Unicode or punycode.
* HTML and JavaScript modes now produce identical values from link text: the same handling of non-breaking spaces, punctuation, emoji, and long text.
* Text inside a link can no longer be mistaken for a placeholder.
* {term:...} placeholders are looked up once per page instead of once per link.
* Deleting the plugin on a multisite network removes its settings from every site.
* The settings screen's styles now load as a stylesheet on that screen only.

= 0.2.1 =
* First version: sitewide defaults, placeholders, HTML and JavaScript modes, and the skip list.

== Upgrade Notice ==

= 0.2.2 =
First public release on WordPress.org.
