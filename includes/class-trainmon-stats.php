<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Stats {
    /**
     * Statistik fuer eine Verbindung.
     * @param string      $eva       Bahnhof (EVA)
     * @param string      $line      Linie (z. B. RE9)
     * @param string      $period    7|30|90|365|all
     * @param string|null $direction Richtungs-Schluessel oder null (beide Richtungen)
     * @param string      $year      Jahr (YYYY) oder '' (dann Zeitraum-Filter)
     * @param string      $month     Monat (MM) oder '' (dann ganzes Jahr, wenn Jahr gesetzt)
     */
    public static function get_stats(string $eva, string $line, string $period = '30', ?string $direction = null, string $year = '', string $month = ''): array {
        global $wpdb;
        $table = TrainMon_Storage::table_name();
        [$where, $args] = self::build_where($eva, $line, $period, $direction, $year, $month);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
            // $condition is one of the fixed literals passed below (no user input).
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table $where AND is_cancelled = 0 AND $condition",
                $args
            ));
        };
        $cancelled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table $where AND is_cancelled = 1",
            $args
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

    /**
     * Verspaetungs-Verteilung nach Abfahrtsstunde oder Wochentag.
     * Gleiche Filter wie get_stats().
     * @param string $group hour|weekday
     * @return array<int, array{total:int, cancelled:int, delayed:int, avg_delay:float, delayed_percent:float}>
     *         Schluessel: Stunde 0-23 bzw. Wochentag 0 (Mo) - 6 (So); nur Buckets mit Fahrten.
     */
    public static function get_distribution(string $eva, string $line, string $group, string $period = '30', ?string $direction = null, string $year = '', string $month = ''): array {
        global $wpdb;
        $table = TrainMon_Storage::table_name();
        [$where, $where_args] = self::build_where($eva, $line, $period, $direction, $year, $month);
        // Reihenfolge der Platzhalter: Schwellwert (SELECT), dann WHERE-Werte.
        $args = array_merge([TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES], $where_args);
        // Feste Ausdruecke, keine Nutzereingabe.
        $bucket_sql = $group === 'weekday' ? 'WEEKDAY(planned_time)' : 'HOUR(planned_time)';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $bucket_sql AS bucket,
                COUNT(*) AS total,
                SUM(is_cancelled) AS cancelled,
                AVG(CASE WHEN is_cancelled = 0 THEN delay_minutes END) AS avg_delay,
                SUM(CASE WHEN is_cancelled = 0 AND delay_minutes > %d THEN 1 ELSE 0 END) AS delayed_count
            FROM $table $where
            GROUP BY bucket
            ORDER BY bucket",
            $args
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $result = [];
        foreach ((array) $rows as $row) {
            $total = (int) $row['total'];
            $cancelled = (int) $row['cancelled'];
            $running = $total - $cancelled;
            $delayed = (int) $row['delayed_count'];
            $result[(int) $row['bucket']] = [
                'total' => $total,
                'cancelled' => $cancelled,
                'delayed' => $delayed,
                'avg_delay' => (float) $row['avg_delay'],
                'delayed_percent' => $running > 0 ? ($delayed / $running) * 100 : 0.0,
            ];
        }
        return $result;
    }

    /**
     * Gemeinsame WHERE-Klausel fuer alle Abfragen. Besteht ausschliesslich aus
     * festen Klauseln + Platzhaltern; alle Werte gehen ueber die Argumentliste
     * durch $wpdb->prepare(). Der Tabellenname stammt aus $wpdb->prefix (kann
     * kein Platzhalter sein). Custom-Table -> kein Cache.
     * @return array{0:string, 1:array}
     */
    private static function build_where(string $eva, string $line, string $period, ?string $direction, string $year, string $month): array {
        // Kalenderauswahl (Jahr/Monat) hat Vorrang vor dem Zeitraum-Filter.
        [$range_sql, $range_args] = TrainMon_Helpers::range_to_sql($year, $month);
        if ($range_sql === '') {
            [$range_sql, $range_args] = TrainMon_Helpers::period_to_sql($period);
        }
        $where = " WHERE station_eva = %s AND train_line = %s ";
        $args = [$eva, strtoupper($line)];
        if ($direction !== null && $direction !== '') {
            $where .= " AND direction = %s ";
            $args[] = $direction;
        }
        $where .= $range_sql;
        // Nur bereits abgefahrene Zuege: kuenftige Fahrten sind schon gespeichert,
        // haben aber noch 0 Minuten Verspaetung und wuerden als puenktlich zaehlen.
        $where .= " AND planned_time <= %s ";
        return [$where, array_merge($args, $range_args, [current_time('mysql')])];
    }
}
