<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Storage {
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 're9_diepholz_delays';
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stop_id VARCHAR(191) NOT NULL,
            station_eva VARCHAR(20) NOT NULL DEFAULT '',
            train_line VARCHAR(20) NOT NULL,
            direction VARCHAR(191) NOT NULL,
            planned_time DATETIME NOT NULL,
            changed_time DATETIME NULL,
            delay_minutes INT NOT NULL DEFAULT 0,
            is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
            platform_planned VARCHAR(20) NULL,
            platform_changed VARCHAR(20) NULL,
            raw_json LONGTEXT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY stop_unique (stop_id),
            KEY station_idx (station_eva),
            KEY train_line_idx (train_line),
            KEY planned_time_idx (planned_time),
            KEY direction_idx (direction),
            KEY delay_idx (delay_minutes),
            KEY cancelled_idx (is_cancelled)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /** Bestandszeilen ohne Bahnhofszuordnung dem Standard-Bahnhof zuordnen. */
    public static function backfill_station_eva(string $eva): void {
        global $wpdb;
        $table = self::table_name();
        // Nur ausfuehren, wenn die Spalte existiert (nach create_table gegeben).
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET station_eva = %s WHERE station_eva = '' OR station_eva IS NULL",
            $eva
        ));
    }

    public static function upsert_stop(array $stop, string $direction, string $eva, string $line): bool {
        global $wpdb;
        if (empty($stop['id'])) { return false; }
        $planned_raw = $stop['departure_planned'] ?: $stop['arrival_planned'];
        $changed_raw = $stop['departure_changed'] ?: $stop['arrival_changed'];
        $planned = TrainMon_Helpers::db_time_to_datetime($planned_raw);
        $changed = TrainMon_Helpers::db_time_to_datetime($changed_raw);
        if (!$planned) { return false; }
        $delay = 0;
        if ($changed) {
            $delay = max(0, (int) round(($changed->getTimestamp() - $planned->getTimestamp()) / 60));
        }
        $table = self::table_name();
        $now = current_time('mysql');
        $data = [
            'stop_id' => sanitize_text_field($stop['id']),
            'station_eva' => sanitize_text_field($eva),
            'train_line' => strtoupper(sanitize_text_field($line)),
            'direction' => sanitize_text_field($direction),
            'planned_time' => $planned->format('Y-m-d H:i:s'),
            'changed_time' => $changed ? $changed->format('Y-m-d H:i:s') : null,
            'delay_minutes' => $delay,
            'is_cancelled' => !empty($stop['cancelled']) ? 1 : 0,
            'platform_planned' => isset($stop['platform_planned']) ? sanitize_text_field((string) $stop['platform_planned']) : null,
            'platform_changed' => isset($stop['platform_changed']) ? sanitize_text_field((string) $stop['platform_changed']) : null,
            'raw_json' => $stop['raw'] ?? null,
            'last_seen' => $now,
        ];
        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE stop_id = %s", $data['stop_id']));
        if ($existing_id) {
            $result = $wpdb->update($table, $data, ['id' => (int) $existing_id]);
        } else {
            $data['first_seen'] = $now;
            $result = $wpdb->insert($table, $data);
        }
        return $result !== false;
    }

    public static function get_next_connection(string $eva, string $line, string $direction): ?array {
        global $wpdb;
        $table = self::table_name();
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT * FROM $table
             WHERE station_eva = %s
               AND train_line = %s
               AND direction = %s
               AND COALESCE(changed_time, planned_time) >= %s
             ORDER BY COALESCE(changed_time, planned_time) ASC
             LIMIT 1",
            $eva,
            strtoupper($line),
            $direction,
            $now
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        return $row ?: null;
    }

    public static function count_rows(): int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    /** Anzahl gespeicherter Zeilen fuer die aktuell gewaehlte Verbindung. */
    public static function count_rows_for(string $eva, string $line): int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE station_eva = %s AND train_line = %s",
            $eva,
            strtoupper($line)
        ));
    }

    /** Gibt es ueberhaupt gespeicherte Zeilen (aus einer Vorgaenger-Installation)? */
    public static function has_rows(): bool {
        global $wpdb;
        $table = self::table_name();
        return (bool) $wpdb->get_var("SELECT EXISTS(SELECT 1 FROM $table LIMIT 1)");
    }

    /** Jahre (YYYY), fuer die Daten dieser Verbindung vorliegen, neueste zuerst. */
    public static function recorded_years(string $eva, string $line): array {
        global $wpdb;
        $table = self::table_name();
        $years = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT YEAR(planned_time) AS y FROM $table
             WHERE station_eva = %s AND train_line = %s
             ORDER BY y DESC",
            $eva,
            strtoupper($line)
        ));
        return array_values(array_filter(array_map('strval', (array) $years)));
    }
}
