<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Plugin {
    // Built-in fallback used only for backward compatibility with the author's
    // original RE9/Diepholz data; fresh installs start unconfigured.
    public const DEFAULT_EVA = '8001443';   // Diepholz
    public const DEFAULT_LINE = 'RE9';
    public const API_BASE = 'https://apis.deutschebahn.com/db-api-marketplace/apis/timetables/v1';
    public const CRON_HOOK = 're9d_fetch_event';
    public const CRON_INTERVAL = 're9d_five_minutes';
    public const OPTION_LAST_STATUS = 're9d_last_status';
    public const OPTION_LAST_ERROR = 're9d_last_error';
    public const OPTION_LAST_RUN = 're9d_last_run';
    public const OPTION_LAST_IMPORTED = 're9d_last_imported';
    public const OPTION_SETTINGS = 're9d_settings';
    public const OPTION_DB_VERSION = 're9d_db_version';
    public const PUNCTUAL_THRESHOLD_MINUTES = 4;

    // Long-distance categories; everything else counts as a regional train (RE, RB, S, IRE, RS, ...).
    public const LONG_DISTANCE = ['ICE', 'IC', 'EC', 'ECE', 'RJ', 'RJX', 'TGV', 'NJ', 'EN', 'FLX', 'IR', 'D'];

    public static function init(): void {
        add_action('init', [self::class, 'load_textdomain']);
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);
        add_action(self::CRON_HOOK, [self::class, 'fetch_and_store']);
        add_shortcode('train_monitor', ['TrainMon_Renderer', 'render_shortcode']);
        add_shortcode('re9_diepholz', ['TrainMon_Renderer', 'render_shortcode']); // alias for existing pages
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_menu', ['TrainMon_Admin', 'add_menu']);
        add_action('admin_enqueue_scripts', ['TrainMon_Admin', 'enqueue_admin']);
        add_action('admin_post_trainmon_manual_fetch', ['TrainMon_Admin', 'handle_manual_fetch']);
        add_action('admin_post_trainmon_save_selection', ['TrainMon_Admin', 'handle_save_selection']);
        add_action('wp_ajax_trainmon_search_stations', ['TrainMon_Admin', 'ajax_search_stations']);
        add_action('wp_ajax_trainmon_scan_lines', ['TrainMon_Admin', 'ajax_scan_lines']);
        add_action('plugins_loaded', [self::class, 'maybe_upgrade']);
        register_activation_hook(TRAINMON_FILE, [self::class, 'activate']);
        register_deactivation_hook(TRAINMON_FILE, [self::class, 'deactivate']);
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('german-regional-train-monitor', false, dirname(plugin_basename(TRAINMON_FILE)) . '/languages');
    }

    public static function activate(): void {
        TrainMon_Storage::create_table();
        TrainMon_Storage::backfill_station_eva(self::DEFAULT_EVA);
        update_option(self::OPTION_DB_VERSION, TRAINMON_VERSION);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /** Runs schema migrations after a plugin update (without reactivation). */
    public static function maybe_upgrade(): void {
        if (get_option(self::OPTION_DB_VERSION, '') === TRAINMON_VERSION) {
            return;
        }
        TrainMon_Storage::create_table();
        TrainMon_Storage::backfill_station_eva(self::DEFAULT_EVA);
        update_option(self::OPTION_DB_VERSION, TRAINMON_VERSION);
    }

    /** Default settings: unconfigured. The admin has to pick a station and line first. */
    public static function default_settings(): array {
        return [
            'station_eva'  => '',
            'station_name' => '',
            'line'         => '',
            'directions'   => [],
        ];
    }

    /** Current selection (station + line + up to two directions). */
    public static function get_settings(): array {
        $defaults = self::default_settings();
        $s = get_option(self::OPTION_SETTINGS, null);
        if (!is_array($s)) {
            return $defaults;
        }
        $s = wp_parse_args($s, $defaults);
        if (empty($s['directions']) || !is_array($s['directions'])) {
            $s['directions'] = [];
        }
        return $s;
    }

    /** True if a station and line have been selected. */
    public static function is_configured(): bool {
        $s = self::get_settings();
        return !empty($s['station_eva']) && !empty($s['line']) && !empty($s['directions']);
    }

    /**
     * Backward compatibility: the author's original data used the fixed directions
     * Bremen(HB)/Osnabrück(OS) for RE9 at Diepholz. Only ever true for that exact
     * stored selection; never for new installs.
     */
    public static function is_legacy_selection(array $settings): bool {
        return (string) $settings['station_eva'] === self::DEFAULT_EVA
            && strtoupper((string) $settings['line']) === self::DEFAULT_LINE;
    }

    public static function add_cron_interval(array $schedules): array {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 300,
            'display' => __('Every five minutes', 'german-regional-train-monitor'),
        ];
        return $schedules;
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style('trainmon-style', TRAINMON_URL . 'assets/style.css', [], TRAINMON_VERSION);
    }

    public static function fetch_and_store(): int {
        $settings = self::get_settings();
        if (empty($settings['station_eva']) || empty($settings['line'])) {
            update_option(self::OPTION_LAST_STATUS, 'unconfigured');
            return 0;
        }
        update_option(self::OPTION_LAST_RUN, current_time('mysql'));
        $imported = 0;
        try {
            $api = new TrainMon_API();
            $stops = $api->get_stops($settings['station_eva'], $settings['line'], 4);
            foreach ($stops as $stop) {
                $direction = TrainMon_Helpers::classify_direction($stop, $settings);
                if (!$direction) {
                    continue;
                }
                if (TrainMon_Storage::upsert_stop($stop, $direction, $settings['station_eva'], $settings['line'])) {
                    $imported++;
                }
            }
            update_option(self::OPTION_LAST_STATUS, 'ok');
            update_option(self::OPTION_LAST_ERROR, '');
            update_option(self::OPTION_LAST_IMPORTED, $imported);
        } catch (Throwable $e) {
            update_option(self::OPTION_LAST_STATUS, 'error');
            update_option(self::OPTION_LAST_ERROR, $e->getMessage());
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('German Regional Train Monitor: ' . $e->getMessage());
            }
        }
        return $imported;
    }
}
