<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Renderer {
    /** Bars based on fewer trips are drawn faded (not yet meaningful). */
    private const CHART_MIN_TRIPS = 5;

    public static function render_shortcode($atts): string {
        $atts = shortcode_atts([
            'period' => '30',
            'refresh' => '120',
            'charts' => ''
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
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is assembled and escaped inside connection_card().
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
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is assembled and escaped inside filter_form().
                echo self::filter_form($eva, $line, $sel_year, $sel_month);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is assembled and escaped inside stats_block().
                echo self::stats_block(__('Overall', 'german-regional-train-monitor'), $stats_all);
                ?>
                <div class="trainmon-direction-stats">
                    <?php foreach ($directions as $dir):
                        $stats_dir = TrainMon_Stats::get_stats($eva, $line, $period, (string) $dir['key'], $sel_year, $sel_month);
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is assembled and escaped inside stats_block().
                        echo self::stats_block((string) $dir['label'], $stats_dir, true);
                    endforeach; ?>
                </div>
                <?php foreach (self::parse_charts((string) $atts['charts']) as $group):
                    $dist = TrainMon_Stats::get_distribution($eva, $line, $group, $period, null, $sel_year, $sel_month);
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is assembled and escaped inside chart_block().
                    echo self::chart_block($group, $dist);
                endforeach; ?>
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

    /** Shortcode value "hour,weekday" / "all" -> list of chart groups in that order. */
    private static function parse_charts(string $value): array {
        $parts = array_map('trim', explode(',', strtolower($value)));
        if (in_array('all', $parts, true)) { return ['hour', 'weekday']; }
        return array_values(array_unique(array_intersect($parts, ['hour', 'weekday'])));
    }

    /**
     * Pure CSS bar chart of the average delay per departure hour or weekday.
     * Bar colour follows the delay level, faded bars have too few trips.
     */
    private static function chart_block(string $group, array $dist): string {
        $title = $group === 'weekday'
            ? __('Average delay by weekday', 'german-regional-train-monitor')
            : __('Average delay by departure hour', 'german-regional-train-monitor');
        $html = '<div class="trainmon-stat-section trainmon-chart-section"><h4>' . esc_html($title) . '</h4>';
        if (empty($dist)) {
            return $html . '<p class="trainmon-small">' . esc_html__('Not enough data for this chart yet.', 'german-regional-train-monitor') . '</p></div>';
        }

        // Weekdays: always Mon-Sun. Hours: from the first to the last hour with trips.
        $buckets = $group === 'weekday' ? range(0, 6) : range(min(array_keys($dist)), max(array_keys($dist)));
        $max = max(1.0, max(array_column($dist, 'avg_delay')));
        $threshold = TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES;

        $cols = '';
        foreach ($buckets as $b) {
            if ($group === 'weekday') {
                // WEEKDAY(): 0 = Monday; WP_Locale::get_weekday(): 0 = Sunday.
                $label = $GLOBALS['wp_locale']->get_weekday_abbrev($GLOBALS['wp_locale']->get_weekday(($b + 1) % 7));
                $long_label = $GLOBALS['wp_locale']->get_weekday(($b + 1) % 7);
            } else {
                $label = sprintf('%02d', $b);
                $long_label = sprintf('%02d:00–%02d:59', $b, $b);
            }
            $d = $dist[$b] ?? null;
            if (!$d) {
                $cols .= '<div class="trainmon-chart-col trainmon-chart-empty" role="listitem" aria-label="' . esc_attr($long_label . ': –') . '">'
                    . '<div class="trainmon-chart-plot"></div><div class="trainmon-chart-label">' . esc_html($label) . '</div></div>';
                continue;
            }
            $avg = $d['avg_delay'];
            $level = $avg <= $threshold ? 'ok' : ($avg <= 10 ? 'warn' : 'bad');
            $sparse = $d['total'] < self::CHART_MIN_TRIPS ? ' trainmon-chart-sparse' : '';
            $height = max(2, (int) round($avg / $max * 100));
            $info = sprintf(
                /* translators: 1: weekday or hour range, 2: average delay in minutes, 3: share of delayed trips in percent, 4: number of trips, 5: number of cancellations */
                __('%1$s: average %2$s min delay, %3$s %% delayed, %4$d trips, %5$d cancelled', 'german-regional-train-monitor'),
                $long_label,
                TrainMon_Helpers::format_decimal($avg),
                TrainMon_Helpers::format_decimal($d['delayed_percent']),
                $d['total'],
                $d['cancelled']
            );
            $cols .= '<div class="trainmon-chart-col' . esc_attr($sparse) . '" role="listitem" title="' . esc_attr($info) . '" aria-label="' . esc_attr($info) . '">'
                . '<div class="trainmon-chart-plot">'
                . '<span class="trainmon-chart-value">' . esc_html(TrainMon_Helpers::format_decimal($avg)) . '</span>'
                . '<div class="trainmon-chart-bar trainmon-level-' . esc_attr($level) . '" style="height:' . esc_attr((string) $height) . '%"></div>'
                . '</div>'
                . '<div class="trainmon-chart-label">' . esc_html($label) . '</div>'
                . '</div>';
        }

        return $html
            . '<div class="trainmon-chart trainmon-chart-' . esc_attr($group) . '" role="list" style="--trainmon-cols:' . esc_attr((string) count($buckets)) . '">' . $cols . '</div>'
            . '<p class="trainmon-small">' . esc_html(sprintf(
                /* translators: %d: minimum number of trips per bar */
                __('Bar height: average delay in minutes (cancellations excluded). Faded bars are based on fewer than %d trips.', 'german-regional-train-monitor'),
                self::CHART_MIN_TRIPS
            )) . '</p></div>';
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
