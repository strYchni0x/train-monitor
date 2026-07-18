<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Renderer {
    public static function render_shortcode($atts): string {
        $atts = shortcode_atts([
            'period' => '30',
            'refresh' => '120'
        ], $atts, 'train_monitor');

        if (!TrainMon_Plugin::is_configured()) {
            return '<div class="trainmon-monitor"><div class="trainmon-card">'
                . esc_html__('German Regional Train Monitor is not configured yet. Please choose a station and line under Settings.', 'german-regional-train-monitor')
                . '</div></div>';
        }

        $refresh_seconds = max(60, (int) $atts['refresh']);
        if (!get_transient('re9d_recent_fetch')) {
            TrainMon_Plugin::fetch_and_store();
            set_transient('re9d_recent_fetch', 1, $refresh_seconds);
        }
        $period = sanitize_text_field((string) $atts['period']);
        $settings = TrainMon_Plugin::get_settings();
        $eva = (string) $settings['station_eva'];
        $line = (string) $settings['line'];
        $directions = $settings['directions'];

        // Optional calendar filter (year/month) from the query string. Read-only
        // display filter, no state change -> no nonce needed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sel_year = isset($_GET['trainmon_year']) ? sanitize_text_field(wp_unslash($_GET['trainmon_year'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sel_month = isset($_GET['trainmon_month']) ? sanitize_text_field(wp_unslash($_GET['trainmon_month'])) : '';
        if (!TrainMon_Helpers::valid_year($sel_year)) { $sel_year = ''; }
        if (!TrainMon_Helpers::valid_month($sel_month)) { $sel_month = ''; }

        $active_label = ($sel_year !== '')
            ? TrainMon_Helpers::range_label($sel_year, $sel_month)
            : self::period_label($period);

        $stats_all = TrainMon_Stats::get_stats($eva, $line, $period, null, $sel_year, $sel_month);

        ob_start();
        ?>
        <div class="trainmon-monitor">
            <div class="trainmon-header">
                <h2><?php
                    /* translators: 1: line name (e.g. RE9), 2: station name */
                    echo esc_html(sprintf(__('%1$s from %2$s', 'german-regional-train-monitor'), $line, $settings['station_name']));
                ?></h2>
                <p><?php esc_html_e('Upcoming departures and recorded punctuality.', 'german-regional-train-monitor'); ?></p>
            </div>
            <div class="trainmon-current">
                <?php foreach ($directions as $dir):
                    $conn = TrainMon_Storage::get_next_connection($eva, $line, (string) $dir['key']);
                    echo self::connection_card((string) $dir['label'], $conn);
                endforeach; ?>
            </div>
            <div class="trainmon-stats">
                <div class="trainmon-stats-title">
                    <h3><?php
                        /* translators: 1: line name, 2: station name */
                        echo esc_html(sprintf(__('Punctuality: %1$s from %2$s', 'german-regional-train-monitor'), $line, $settings['station_name']));
                    ?></h3>
                    <span><?php echo esc_html($active_label); ?></span>
                </div>
                <?php echo self::filter_form($eva, $line, $sel_year, $sel_month); ?>
                <?php echo self::stats_block(__('Overall', 'german-regional-train-monitor'), $stats_all); ?>
                <div class="trainmon-direction-stats">
                    <?php foreach ($directions as $dir):
                        $stats_dir = TrainMon_Stats::get_stats($eva, $line, $period, (string) $dir['key'], $sel_year, $sel_month);
                        echo self::stats_block((string) $dir['label'], $stats_dir, true);
                    endforeach; ?>
                </div>
                <p class="trainmon-small"><?php
                    /* translators: %d: punctuality threshold in minutes */
                    echo esc_html(sprintf(__('On time means: at most %d minutes delay. Cancellations are counted separately and are not included in the average delay.', 'german-regional-train-monitor'), TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES));
                ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function connection_card(string $label, ?array $connection): string {
        if (!$connection) {
            return '<div class="trainmon-card"><div class="trainmon-direction">' . esc_html($label) . '</div><div>' . esc_html__('No upcoming departure found.', 'german-regional-train-monitor') . '</div></div>';
        }
        $delay = (int) $connection['delay_minutes'];
        $is_delayed = $delay > TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES || !empty($connection['is_cancelled']);
        $planned = new DateTimeImmutable($connection['planned_time'], wp_timezone());
        $changed = !empty($connection['changed_time']) ? new DateTimeImmutable($connection['changed_time'], wp_timezone()) : $planned;
        $class = $is_delayed ? 'trainmon-delayed' : 'trainmon-punctual';
        if (!empty($connection['is_cancelled'])) {
            $delay_text = __('Train cancelled', 'german-regional-train-monitor');
        } elseif ($is_delayed) {
            /* translators: %d: delay in minutes */
            $delay_text = sprintf(__('+%d min delay', 'german-regional-train-monitor'), $delay);
        } else {
            $delay_text = __('on time', 'german-regional-train-monitor');
        }
        $platform = $connection['platform_changed'] ?: $connection['platform_planned'];
        /* translators: %s: platform number */
        $platform_text = $platform ? '<div class="trainmon-small">' . esc_html(sprintf(__('Platform %s', 'german-regional-train-monitor'), $platform)) . '</div>' : '';
        /* translators: %s: scheduled time (HH:MM) */
        $changed_hint = $changed->format('H:i') !== $planned->format('H:i') ? '<div class="trainmon-small">' . esc_html(sprintf(__('scheduled: %s', 'german-regional-train-monitor'), $planned->format('H:i'))) . '</div>' : '';
        return '<div class="trainmon-card ' . esc_attr($class) . '">
            <div class="trainmon-direction">' . esc_html($label) . '</div>
            <div class="trainmon-time">' . esc_html($changed->format('H:i')) . '</div>
            ' . $changed_hint . $platform_text . '
            <div class="trainmon-delay">' . esc_html($delay_text) . '</div>
        </div>';
    }

    private static function stats_block(string $title, array $stats, bool $compact = false): string {
        $compact_class = $compact ? ' trainmon-compact' : '';
        return '<div class="trainmon-stat-section' . esc_attr($compact_class) . '">
            <h4>' . esc_html($title) . '</h4>
            <div class="trainmon-stat-grid">
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html($stats['punctual_percent']) . ' %</div><div class="trainmon-small">' . esc_html__('on time', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html($stats['avg_delay']) . ' ' . esc_html__('min', 'german-regional-train-monitor') . '</div><div class="trainmon-small">' . esc_html__('average', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html((string) $stats['delay_5_10']) . '</div><div class="trainmon-small">' . esc_html__('5-10 min', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html((string) $stats['delay_11_20']) . '</div><div class="trainmon-small">' . esc_html__('11-20 min', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html((string) $stats['delay_21_plus']) . '</div><div class="trainmon-small">' . esc_html__('21+ min', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html((string) $stats['cancelled']) . '</div><div class="trainmon-small">' . esc_html__('cancellations', 'german-regional-train-monitor') . '</div></div>
                <div class="trainmon-stat-box"><div class="trainmon-stat-value">' . esc_html((string) $stats['total']) . '</div><div class="trainmon-small">' . esc_html__('total trips', 'german-regional-train-monitor') . '</div></div>
            </div>
        </div>';
    }

    /** Year + month picker (GET form) so visitors can inspect a specific month. */
    private static function filter_form(string $eva, string $line, string $sel_year, string $sel_month): string {
        $years = TrainMon_Storage::recorded_years($eva, $line);
        if (empty($years)) { return ''; }
        $all = esc_html__('All', 'german-regional-train-monitor');

        $year_opts = '<option value="">' . $all . '</option>';
        foreach ($years as $y) {
            $year_opts .= '<option value="' . esc_attr($y) . '"' . selected($sel_year, $y, false) . '>' . esc_html($y) . '</option>';
        }
        $month_opts = '<option value="">' . $all . '</option>';
        for ($m = 1; $m <= 12; $m++) {
            $mm = sprintf('%02d', $m);
            $name = $GLOBALS['wp_locale']->get_month($mm);
            $month_opts .= '<option value="' . esc_attr($mm) . '"' . selected($sel_month, $mm, false) . '>' . esc_html($name) . '</option>';
        }

        return '<form method="get" class="trainmon-filter">'
            . '<select name="trainmon_year" aria-label="' . esc_attr__('Year', 'german-regional-train-monitor') . '">' . $year_opts . '</select>'
            . '<select name="trainmon_month" aria-label="' . esc_attr__('Month', 'german-regional-train-monitor') . '">' . $month_opts . '</select>'
            . '<button type="submit" class="trainmon-btn">' . esc_html__('Show', 'german-regional-train-monitor') . '</button>'
            . '</form>';
    }

    private static function period_label(string $period): string {
        if ($period === 'all') { return __('entire recorded period', 'german-regional-train-monitor'); }
        $days = in_array($period, ['7','30','90','365'], true) ? $period : '30';
        /* translators: %s: number of days */
        return sprintf(__('last %s days', 'german-regional-train-monitor'), $days);
    }
}
