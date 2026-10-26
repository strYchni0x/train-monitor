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
     * Verspaetung je Abfahrtsstunde, einmal ueber alle Tage und je Wochentag.
     * Gleiche Filter wie get_stats(). Eine Abfrage (Wochentag x Stunde), die
     * Summen fuer "alle Tage" werden hier gebildet.
     * @return array<string|int, array<int, array{total:int, cancelled:int, delayed:int, avg_delay:float, delayed_percent:float}>>
     *         Schluessel: 'all' und Wochentag 0 (Mo) - 6 (So), darin Stunde 0-23; nur Stunden mit Fahrten.
     */
    public static function get_hourly_by_weekday(string $eva, string $line, string $period = '30', ?string $direction = null, string $year = '', string $month = ''): array {
        global $wpdb;
        $table = TrainMon_Storage::table_name();
        [$where, $where_args] = self::build_where($eva, $line, $period, $direction, $year, $month);
        // Reihenfolge der Platzhalter: Schwellwert (SELECT), dann WHERE-Werte.
        $args = array_merge([TrainMon_Plugin::PUNCTUAL_THRESHOLD_MINUTES], $where_args);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT WEEKDAY(planned_time) AS wd, HOUR(planned_time) AS hr,
                COUNT(*) AS total,
                SUM(is_cancelled) AS cancelled,
                SUM(CASE WHEN is_cancelled = 0 THEN delay_minutes ELSE 0 END) AS delay_sum,
                SUM(CASE WHEN is_cancelled = 0 AND delay_minutes > %d THEN 1 ELSE 0 END) AS delayed_count
            FROM $table $where
            GROUP BY wd, hr",
            $args
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Rohsummen je Variante, damit "alle Tage" exakt gewichtet ist.
        $sums = ['all' => []];
        foreach ((array) $rows as $row) {
            $hr = (int) $row['hr'];
            foreach (['all', (int) $row['wd']] as $key) {
                $s = $sums[$key][$hr] ?? ['total' => 0, 'cancelled' => 0, 'delay_sum' => 0, 'delayed' => 0];
                $s['total'] += (int) $row['total'];
                $s['cancelled'] += (int) $row['cancelled'];
                $s['delay_sum'] += (int) $row['delay_sum'];
                $s['delayed'] += (int) $row['delayed_count'];
                $sums[$key][$hr] = $s;
            }
        }

        $result = [];
        foreach ($sums as $key => $hours) {
            ksort($hours);
            foreach ($hours as $hr => $s) {
                $running = $s['total'] - $s['cancelled'];
                $result[$key][$hr] = [
                    'total' => $s['total'],
                    'cancelled' => $s['cancelled'],
                    'delayed' => $s['delayed'],
                    'avg_delay' => $running > 0 ? $s['delay_sum'] / $running : 0.0,
                    'delayed_percent' => $running > 0 ? ($s['delayed'] / $running) * 100 : 0.0,
                ];
            }
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
