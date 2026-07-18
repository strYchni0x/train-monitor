=== German Regional Train Monitor ===
Contributors: strychni0x
Tags: train, railway, delay, punctuality, timetable
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show the next regional train departures at a station you choose and record punctuality statistics per direction, using the Deutsche Bahn Timetables API.

== Description ==

German Regional Train Monitor displays the next regional train departures at a
German station of your choice and builds up punctuality statistics over time. It
uses the official Deutsche Bahn Timetables API (which you connect with your own
free API credentials).

In the admin area you pick a station and one of the regional lines that stop
there. Both travel directions are detected automatically and labelled with their
destination (the labels can be edited). Every observed departure is stored in a
custom database table, so you can show punctuality statistics per direction and
over configurable periods.

= Features =

* Free choice of station and regional line (everything except long-distance
  trains: RE, RB, S-Bahn, IRE, RS, ...), selected in the admin area.
* Two-step picker: search a station, then choose from the regional lines that
  actually stop there (discovered by scanning the timetable).
* Both directions detected automatically, labelled with the destination station.
* Front-end display of the next departure per direction (green when on time, red
  on delay or cancellation) via the shortcode `[train_monitor]`.
* Punctuality statistics per direction with a period filter (7, 30, 90, 365 days
  or the entire recorded period). Cancellations are counted separately.
* Data is collected every 5 minutes via WP-Cron.
* Source language English, with a bundled German translation (de_DE).

= Shortcode =

`[train_monitor]` — default (last 30 days).
`[train_monitor period="7"]`, `period="90"`, `period="365"`, `period="all"`.

== External services ==

This plugin relies on the **Deutsche Bahn Timetables API**, a third-party service
operated by Deutsche Bahn (DB), to retrieve train schedules and delay data. The
plugin cannot work without it.

* **What is sent and when:** whenever data is fetched (every five minutes via
  WP-Cron, and once when a page with the shortcode is viewed), the plugin sends a
  request to `https://apis.deutschebahn.com/` containing the configured station's
  EVA number, a date and an hour, and the API credentials you entered
  (`DB-Client-Id` and `DB-Api-Key` request headers). When you search for a
  station or scan lines in the admin area, the text you type is also sent.
* **What is not sent:** no data about your site's visitors is transmitted.
* **Credentials:** you must obtain your own free API credentials from the DB API
  Marketplace and add them to `wp-config.php`. By doing so you accept DB's terms.

Deutsche Bahn API Marketplace: https://developers.deutschebahn.com/
DB terms of use: https://developers.deutschebahn.com/db-api-marketplace/apis/
DB privacy information: https://www.deutschebahn.com/de/konzern/datenschutz

*This plugin is not affiliated with or endorsed by Deutsche Bahn.*

== Installation ==

1. Upload and activate the plugin.
2. Obtain free credentials for the "Timetables" API at the DB API Marketplace
   (https://developers.deutschebahn.com/) and add them to `wp-config.php`:

   `define('DB_TIMETABLES_CLIENT_ID', 'YOUR_CLIENT_ID');`
   `define('DB_TIMETABLES_API_KEY', 'YOUR_API_KEY');`

3. Go to Settings → Train Monitor, search a station, choose a line, save, then
   click "Fetch now".
4. Add the shortcode `[train_monitor]` to a page or post.

Note: set the WordPress timezone (Settings → General) to your local German time
(e.g. Berlin); the API returns local times.

== Frequently Asked Questions ==

= Which trains are supported? =
All regional trains covered by the DB Timetables API: RE, RB, S-Bahn, IRE, RS and
similar. Long-distance trains (ICE, IC, EC, ...) are excluded.

= Do I need API credentials? =
Yes. They are free from the DB API Marketplace. Subscribe to the "Timetables"
API and put the client id and API key into `wp-config.php`.

= Why are there no statistics yet? =
Statistics build up over time as the plugin records departures every five
minutes. Meaningful numbers appear after a few days.

== Screenshots ==

1. The front-end display: next departure per direction plus punctuality stats.
2. The admin page: pick a station and a regional line.

== Changelog ==

= 1.0.0 =
* First public release.
