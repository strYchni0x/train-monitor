<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Admin {
    private static $hook = '';

    public static function add_menu(): void {
        self::$hook = add_options_page(
            __('German Regional Train Monitor', 'german-regional-train-monitor'),
            __('Train Monitor', 'german-regional-train-monitor'),
            'manage_options',
            'trainmon-monitor',
            [self::class, 'render_page']
        );
    }

    public static function enqueue_admin(string $hook): void {
        if ($hook !== self::$hook) { return; }
        wp_enqueue_script('trainmon-admin', TRAINMON_URL . 'assets/admin.js', [], TRAINMON_VERSION, true);
        wp_localize_script('trainmon-admin', 'TrainMonAdmin', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('trainmon_admin'),
            'i18n'  => [
                'enterTerm'   => __('Please enter a search term.', 'german-regional-train-monitor'),
                'searching'   => __('Searching stations …', 'german-regional-train-monitor'),
                'searchFail'  => __('Search failed.', 'german-regional-train-monitor'),
                'noStations'  => __('No stations found.', 'german-regional-train-monitor'),
                'netSearch'   => __('Network error during search.', 'german-regional-train-monitor'),
                'scanning'    => __('Searching regional lines (this may take a moment) …', 'german-regional-train-monitor'),
                'scanFail'    => __('Line scan failed.', 'german-regional-train-monitor'),
                'chooseLine'  => __('-- choose a line --', 'german-regional-train-monitor'),
                'netScan'     => __('Network error during line scan.', 'german-regional-train-monitor'),
            ],
        ]);
    }

    public static function handle_manual_fetch(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You are not allowed to do this.', 'german-regional-train-monitor')); }
        check_admin_referer('trainmon_manual_fetch');
        TrainMon_Plugin::fetch_and_store();
        wp_safe_redirect(admin_url('options-general.php?page=trainmon-monitor&updated=1'));
        exit;
    }

    /** AJAX: station search. */
    public static function ajax_search_stations(): void {
        check_ajax_referer('trainmon_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('You are not allowed to do this.', 'german-regional-train-monitor')]); }
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if ($q === '') { wp_send_json_error(['message' => __('Please enter a search term.', 'german-regional-train-monitor')]); }
        try {
            $api = new TrainMon_API();
            wp_send_json_success($api->search_stations($q));
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /** AJAX: scan regional lines at the chosen station. */
    public static function ajax_scan_lines(): void {
        check_ajax_referer('trainmon_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('You are not allowed to do this.', 'german-regional-train-monitor')]); }
        $eva = isset($_POST['eva']) ? preg_replace('/[^0-9]/', '', (string) $_POST['eva']) : '';
        if ($eva === '') { wp_send_json_error(['message' => __('No station selected.', 'german-regional-train-monitor')]); }
        try {
            $api = new TrainMon_API();
            $lines = $api->scan_lines($eva, 24);
            if (!$lines) { wp_send_json_error(['message' => __('No regional trains were found at this station.', 'german-regional-train-monitor')]); }
            wp_send_json_success($lines);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /** Save the selection (station + line + directions). */
    public static function handle_save_selection(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You are not allowed to do this.', 'german-regional-train-monitor')); }
        check_admin_referer('trainmon_save_selection');

        $eva = isset($_POST['station_eva']) ? preg_replace('/[^0-9]/', '', (string) $_POST['station_eva']) : '';
        $station_name = isset($_POST['station_name']) ? sanitize_text_field(wp_unslash($_POST['station_name'])) : '';
        $line = isset($_POST['line']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['line']))) : '';

        $directions = [];
        for ($i = 0; $i < 2; $i++) {
            $key = isset($_POST["dir{$i}_key"]) ? sanitize_text_field(wp_unslash($_POST["dir{$i}_key"])) : '';
            if ($key === '') { continue; }
            $terminus = isset($_POST["dir{$i}_terminus"]) ? sanitize_text_field(wp_unslash($_POST["dir{$i}_terminus"])) : '';
            $label = isset($_POST["dir{$i}_label"]) ? sanitize_text_field(wp_unslash($_POST["dir{$i}_label"])) : '';
            if ($label === '') {
                /* translators: %s: destination station name */
                $label = $terminus !== '' ? sprintf(__('Toward %s', 'german-regional-train-monitor'), $terminus) : $key;
            }
            $directions[] = ['key' => $key, 'label' => $label, 'terminus' => $terminus];
        }

        if ($eva === '' || $line === '' || empty($directions)) {
            wp_safe_redirect(admin_url('options-general.php?page=trainmon-monitor&error=1'));
            exit;
        }

        update_option(TrainMon_Plugin::OPTION_SETTINGS, [
            'station_eva'  => $eva,
            'station_name' => $station_name !== '' ? $station_name : $eva,
            'line'         => $line,
            'directions'   => $directions,
        ]);
        delete_transient('re9d_recent_fetch'); // next fetch gets fresh data
        wp_safe_redirect(admin_url('options-general.php?page=trainmon-monitor&saved=1'));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $settings = TrainMon_Plugin::get_settings();
        $eva = (string) $settings['station_eva'];
        $line = (string) $settings['line'];
        $configured = TrainMon_Plugin::is_configured();
        $last_run = get_option(TrainMon_Plugin::OPTION_LAST_RUN, '');
        $last_status = (string) get_option(TrainMon_Plugin::OPTION_LAST_STATUS, '');
        $last_error = get_option(TrainMon_Plugin::OPTION_LAST_ERROR, '');
        $last_imported = get_option(TrainMon_Plugin::OPTION_LAST_IMPORTED, 0);
        $next_cron = wp_next_scheduled(TrainMon_Plugin::CRON_HOOK);
        $rows = $configured ? TrainMon_Storage::count_rows_for($eva, $line) : 0;
        $stats_30 = $configured ? TrainMon_Stats::get_stats($eva, $line, '30', null) : null;
        $dir_labels = array_map(function ($d) { return $d['label']; }, $settings['directions']);
        $status_map = [
            'ok'           => __('OK', 'german-regional-train-monitor'),
            'error'        => __('error', 'german-regional-train-monitor'),
            'unconfigured' => __('not configured', 'german-regional-train-monitor'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('German Regional Train Monitor', 'german-regional-train-monitor'); ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?php esc_html_e('Manual fetch completed.', 'german-regional-train-monitor'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['saved'])): ?><div class="notice notice-success"><p><?php esc_html_e('Selection saved. Please click "Fetch now" once.', 'german-regional-train-monitor'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['error'])): ?><div class="notice notice-error"><p><?php esc_html_e('Selection incomplete – please choose a station and a line.', 'german-regional-train-monitor'); ?></p></div><?php endif; ?>

            <h2><?php esc_html_e('Monitored connection', 'german-regional-train-monitor'); ?></h2>
            <?php if (!$configured): ?>
                <p><?php esc_html_e('No station selected yet. Please pick a station and line below.', 'german-regional-train-monitor'); ?></p>
            <?php else: ?>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><th style="width:220px"><?php esc_html_e('Station', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html($settings['station_name']); ?> <code><?php echo esc_html($eva); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Line', 'german-regional-train-monitor'); ?></th><td><strong><?php echo esc_html($line); ?></strong></td></tr>
                    <tr><th><?php esc_html_e('Directions', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html(implode('  /  ', $dir_labels)); ?></td></tr>
                </tbody>
            </table>
            <?php endif; ?>

            <h3><?php esc_html_e('Choose a different connection', 'german-regional-train-monitor'); ?></h3>
            <p class="description"><?php esc_html_e('First search for the station, then choose a regional line that stops there. The line scan reads the next 24 hours and may take a moment.', 'german-regional-train-monitor'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="trainmon-select-form">
                <?php wp_nonce_field('trainmon_save_selection'); ?>
                <input type="hidden" name="action" value="trainmon_save_selection">
                <input type="hidden" name="station_eva" id="trainmon-eva" value="">
                <input type="hidden" name="station_name" id="trainmon-name" value="">
                <input type="hidden" name="line" id="trainmon-line-value" value="">
                <input type="hidden" name="dir0_key" id="trainmon-dir0-key" value="">
                <input type="hidden" name="dir0_terminus" id="trainmon-dir0-terminus" value="">
                <input type="hidden" name="dir1_key" id="trainmon-dir1-key" value="">
                <input type="hidden" name="dir1_terminus" id="trainmon-dir1-terminus" value="">

                <p>
                    <label><strong><?php esc_html_e('1. Search station', 'german-regional-train-monitor'); ?></strong><br>
                        <input type="text" id="trainmon-q" class="regular-text" placeholder="<?php esc_attr_e('e.g. Osnabrück', 'german-regional-train-monitor'); ?>">
                    </label>
                    <button type="button" class="button" id="trainmon-search"><?php esc_html_e('Search station', 'german-regional-train-monitor'); ?></button>
                </p>
                <div id="trainmon-stations"></div>

                <div id="trainmon-line-wrap" style="display:none;margin-top:1em">
                    <p><label><strong><?php esc_html_e('2. Choose line', 'german-regional-train-monitor'); ?></strong><br>
                        <select id="trainmon-line" class="regular-text"></select>
                    </label></p>
                </div>

                <div id="trainmon-save-wrap" style="display:none;margin-top:1em">
                    <p><strong><?php esc_html_e('3. Directions (labels editable)', 'german-regional-train-monitor'); ?></strong></p>
                    <p><label><?php esc_html_e('Direction 1', 'german-regional-train-monitor'); ?><br><input type="text" name="dir0_label" id="trainmon-dir0-label" class="regular-text" value=""></label></p>
                    <p><label><?php esc_html_e('Direction 2', 'german-regional-train-monitor'); ?><br><input type="text" name="dir1_label" id="trainmon-dir1-label" class="regular-text" value=""></label></p>
                    <?php submit_button(__('Save selection', 'german-regional-train-monitor'), 'primary', 'submit', false); ?>
                </div>
                <p id="trainmon-msg" style="color:#b32d2e"></p>
            </form>

            <h2><?php esc_html_e('Status', 'german-regional-train-monitor'); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><th style="width:220px"><?php esc_html_e('API credentials', 'german-regional-train-monitor'); ?></th><td><?php echo self::credentials_ok()
                        ? '<span style="color:green;font-weight:700">' . esc_html__('found', 'german-regional-train-monitor') . '</span>'
                        : '<span style="color:red;font-weight:700">' . esc_html__('missing', 'german-regional-train-monitor') . '</span>'; ?></td></tr>
                    <tr><th><?php esc_html_e('Last fetch', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html($last_run !== '' ? $last_run : __('never', 'german-regional-train-monitor')); ?></td></tr>
                    <tr><th><?php esc_html_e('Status of last fetch', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html($status_map[$last_status] ?? __('unknown', 'german-regional-train-monitor')); ?></td></tr>
                    <tr><th><?php esc_html_e('Trips imported/updated last time', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html((string) $last_imported); ?></td></tr>
                    <tr><th><?php esc_html_e('Next WP-Cron', 'german-regional-train-monitor'); ?></th><td><?php echo $next_cron ? esc_html(wp_date('Y-m-d H:i:s', $next_cron)) : esc_html__('not scheduled', 'german-regional-train-monitor'); ?></td></tr>
                    <tr><th><?php esc_html_e('Stored records (this connection)', 'german-regional-train-monitor'); ?></th><td><?php echo esc_html((string) $rows); ?></td></tr>
                    <?php if ($last_error): ?><tr><th><?php esc_html_e('Last error', 'german-regional-train-monitor'); ?></th><td><code><?php echo esc_html((string) $last_error); ?></code></td></tr><?php endif; ?>
                </tbody>
            </table>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('trainmon_manual_fetch'); ?>
                    <input type="hidden" name="action" value="trainmon_manual_fetch">
                    <?php submit_button(__('Fetch now', 'german-regional-train-monitor'), 'primary', 'submit', false); ?>
                </form>
            </p>

            <?php if ($configured && $stats_30): ?>
            <h2><?php esc_html_e('Quick stats, last 30 days', 'german-regional-train-monitor'); ?></h2>
            <ul>
                <li><?php esc_html_e('On time:', 'german-regional-train-monitor'); ?> <strong><?php echo esc_html($stats_30['punctual_percent']); ?> %</strong></li>
                <li><?php esc_html_e('Average delay:', 'german-regional-train-monitor'); ?> <strong><?php echo esc_html($stats_30['avg_delay']); ?> <?php esc_html_e('minutes', 'german-regional-train-monitor'); ?></strong></li>
                <li><?php esc_html_e('Cancellations:', 'german-regional-train-monitor'); ?> <strong><?php echo esc_html((string) $stats_30['cancelled']); ?></strong></li>
            </ul>
            <?php endif; ?>

            <h2><?php esc_html_e('Embedding', 'german-regional-train-monitor'); ?></h2>
            <p><?php esc_html_e('Default:', 'german-regional-train-monitor'); ?></p>
            <code>[train_monitor]</code>
            <p><?php esc_html_e('With period filter:', 'german-regional-train-monitor'); ?></p>
            <code>[train_monitor period="7"]</code><br>
            <code>[train_monitor period="30"]</code><br>
            <code>[train_monitor period="90"]</code><br>
            <code>[train_monitor period="all"]</code>

            <h2><?php esc_html_e('Configuration', 'german-regional-train-monitor'); ?></h2>
            <p><?php
                printf(
                    /* translators: %s: wp-config.php file name */
                    esc_html__('The DB API credentials must be defined in %s:', 'german-regional-train-monitor'),
                    '<code>wp-config.php</code>'
                );
            ?></p>
            <pre>define('DB_TIMETABLES_CLIENT_ID', 'YOUR_CLIENT_ID');
define('DB_TIMETABLES_API_KEY', 'YOUR_API_KEY');</pre>
            <p><?php esc_html_e('Recommendation: for reliable statistics, set up a real server cron that calls WordPress cron regularly.', 'german-regional-train-monitor'); ?></p>
        </div>
        <?php
    }

    private static function credentials_ok(): bool {
        return defined('DB_TIMETABLES_CLIENT_ID') && defined('DB_TIMETABLES_API_KEY') && DB_TIMETABLES_CLIENT_ID && DB_TIMETABLES_API_KEY;
    }
}
