<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Stats {
    /**
     * Statistik fuer eine Verbindung.
     * @param string      $eva       Bahnhof (EVA)
     * @param string      $line      Linie (z. B. RE9)
     * @param string      $period    7|30|90|365|all
     * @param string|null $direction Richtungs-Schluessel oder null (beide Richtungen)
     */
    public static function get_stats(string $eva, string $line, string $period = '30', ?string $direction = null): array {
        global $wpdb;
        $table = TrainMon_Storage::table_name();
        [$period_sql, $period_args] = TrainMon_Helpers::period_to_sql($period);

        // Gemeinsame WHERE-Klausel fuer alle Teilabfragen.
        $where = " WHERE station_eva = %s AND train_line = %s ";
        $args = [$eva, strtoupper($line)];
        if ($direction !== null && $direction !== '') {
            $where .= " AND direction = %s ";
            $args[] = $direction;
        }
        $where .= $period_sql;
        $args = array_merge($args, $period_args);

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table $where", $args));
        if ($total === 0) {
            return [
                'total' => 0,
                'punctual_percent_raw' => 0.0,
                'punctual_percent' => '0,0',
                'avg_delay' => '0,0',
                'delay_5_10' => 0,
                'delay_11_20' => 0,
                'delay_21_plus' => 0,
                'cancelled' => 0,
            ];
        }

        $punctual = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table $where AND is_cancelled = 0 AND delay_minutes <= %d",
            array_merge($args, [TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES])
        ));
        $avg_delay = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(delay_minutes) FROM $table $where AND is_cancelled = 0",
            $args
        ));
        $bucket = function (string $condition) use ($wpdb, $table, $where, $args): int {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table $where AND is_cancelled = 0 AND $condition",
                $args
            ));
        };
        $cancelled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table $where AND is_cancelled = 1",
            $args
        ));
        $punctual_percent_raw = ($punctual / $total) * 100;

        return [
            'total' => $total,
            'punctual_percent_raw' => $punctual_percent_raw,
            'punctual_percent' => TrainMon_Helpers::format_decimal($punctual_percent_raw),
            'avg_delay' => TrainMon_Helpers::format_decimal($avg_delay),
            'delay_5_10' => $bucket('delay_minutes BETWEEN 5 AND 10'),
            'delay_11_20' => $bucket('delay_minutes BETWEEN 11 AND 20'),
            'delay_21_plus' => $bucket('delay_minutes >= 21'),
            'cancelled' => $cancelled,
        ];
    }
}
