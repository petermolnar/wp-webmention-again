=== wp-webmention-again ===
Contributors: cadeyrn
Donate link: https://paypal.me/petermolnar/3
Tags: webmention, pingback, indieweb
Requires at least: 4.3
Tested up to: 4.5.3
Stable tag: 0.6
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Alternative [Webmentions](http://indiewebcamp.com/webmention-spec) plugin for WordPress

== Description ==

The plugin add [Webmention](http://indiewebcamp.com/webmention-spec) sending and receiving to WordPress. All processing is done via WordPress Cron, therefore it should be able to operate without distrupting site operation.

It also extends comment types and supports [Reacji](https://indiewebcamp.com/reacji).

It doesn't do anything with displaying the comments, but it does try to Microformats2 parse the incoming mentions.

== Installation ==

1. Upload contents of `wp-webmention-again.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress


== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.6 =
*2016-07-09*

* converted in-database queue to real queue
* keeping incoming and outgoing webmentions in webmentions.log in the plugin directory
* trigger archive.org save of source in case of incoming and target in case of outgoing webmentions


= 0.5.1 =
*2016-03-08*

* fixed bug of dropping mentions when structure wasn't in the expected form
* better debugging


= 0.5=
*2016-03-01*

* major bugfix, externally available send_webmention wasn't doing what it should have been doing

= 0.4 =
*2016-02-22*

* adding more polished parsing of incoming comments

= 0.3 =
*2016-01-14*

* split into 3 files: base, sender & receiver for readability


= 0.2 =
*2016-01-13*

* moved to webmentions table from meta entries; this is to have the option of queuing outgoing messages indepentently from posts (eg. from comments)

= 0.1 =
*2016-01-12*

* initial working release
