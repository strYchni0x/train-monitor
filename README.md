# German Regional Train Monitor

A WordPress plugin that shows the next regional train departures at a German
station of your choice and records punctuality statistics per direction, using
the official [Deutsche Bahn Timetables API](https://developers.deutschebahn.com/).

> Source language is English; a complete German translation (`de_DE`) is bundled.
> Not affiliated with or endorsed by Deutsche Bahn.

## Features

- Free choice of **station and regional line** (everything except long-distance:
  RE, RB, S-Bahn, IRE, RS …), selected in the admin area.
- Two-step picker: search a station, then choose from the regional lines that
  actually stop there (discovered by scanning the timetable).
- **Both directions** detected automatically and labelled with the destination
  station (labels are editable).
- Front-end display of the next departure per direction (green = on time,
  red = delay/cancellation) via the shortcode `[train_monitor]`.
- **Punctuality statistics** per direction with a period filter (7 / 30 / 90 /
  365 days or the entire recorded period); cancellations counted separately.
- Data collected every 5 minutes via WP-Cron.

## Installation

1. Install and activate the plugin.
2. Get free credentials for the **Timetables** API at the
   [DB API Marketplace](https://developers.deutschebahn.com/) and add them to
   `wp-config.php`:

   ```php
   define('DB_TIMETABLES_CLIENT_ID', 'YOUR_CLIENT_ID');
   define('DB_TIMETABLES_API_KEY', 'YOUR_API_KEY');
   ```

3. Go to **Settings → Train Monitor**, search a station, choose a line, save,
   then click *Fetch now*.
4. Add `[train_monitor]` to a page or post.

Set the WordPress timezone (Settings → General) to your local German time
(e.g. Berlin); the API returns local times.

## Shortcode

```text
[train_monitor]
[train_monitor period="7"]
[train_monitor period="30"]
[train_monitor period="90"]
[train_monitor period="365"]
[train_monitor period="all"]
```

Default is `period="30"`.

## External service

The plugin calls the Deutsche Bahn Timetables API (`apis.deutschebahn.com`) using
your own credentials to retrieve schedules and delays. No data about your site's
visitors is sent. See the "External services" section of `readme.txt` for the
full disclosure.

## Development

- Source strings are English and wrapped for i18n (text domain
  `german-regional-train-monitor`).
- `build_lang.py` regenerates the translation files: it extracts the msgids from
  the PHP sources, validates them against its EN→DE table (extend the table when
  you add strings) and writes `.pot`, `.po` and `.mo` under `languages/`.
  This script is a dev tool and is not part of the distributed plugin.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
