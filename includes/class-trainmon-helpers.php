<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_Helpers {
    public static function db_time_to_datetime(?string $value): ?DateTimeImmutable {
        if (!$value) { return null; }
        $dt = DateTimeImmutable::createFromFormat('!ymdHi', $value, wp_timezone());
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }

    /** Gattung ist ein Regionalzug (alles ausser Fernverkehr). */
    public static function is_regional(string $category): bool {
        $category = strtoupper(trim($category));
        return $category !== '' && !in_array($category, TrainMon_Plugin::LONG_DISTANCE, true);
    }

    /** Naechster Halt nach dem ueberwachten Bahnhof (Richtungs-Schluessel). */
    public static function next_station_of(array $stop): string {
        return isset($stop['next']) ? trim((string) $stop['next']) : '';
    }

    /** Zielbahnhof der Fahrt (letzter Halt im Laufweg). */
    public static function terminus_of(array $stop): string {
        return isset($stop['to']) ? trim((string) $stop['to']) : '';
    }

    /**
     * Ordnet einen Halt einer der beiden gespeicherten Fahrtrichtungen zu.
     * Rueckgabe: Richtungs-Schluessel oder null (unbekannte/dritte Richtung).
     */
    public static function classify_direction(array $stop, array $settings): ?string {
        // Legacy-Auswahl (RE9 ab Diepholz): weiter ueber Bremen/Osnabrueck-Namen,
        // damit Bestandsdaten und neue Zeilen dieselben Schluessel HB/OS nutzen.
        if (TrainMon_Plugin::is_legacy_selection($settings)) {
            return self::legacy_direction($stop['from'] ?? '', $stop['to'] ?? '');
        }
        $next = self::next_station_of($stop);
        if ($next === '') { return null; }
        foreach ($settings['directions'] as $dir) {
            if (!empty($dir['key']) && $dir['key'] === $next) {
                return (string) $dir['key'];
            }
        }
        return null;
    }

    /** Alte, fest verdrahtete Richtungslogik der RE9 ab Diepholz. */
    private static function legacy_direction(string $from, string $to): ?string {
        $from_l = mb_strtolower($from, 'UTF-8');
        $to_l = mb_strtolower($to, 'UTF-8');
        $from_os = mb_stripos($from_l, 'osnabrueck', 0, 'UTF-8') !== false || mb_stripos($from_l, 'osnabrück', 0, 'UTF-8') !== false;
        $to_os = mb_stripos($to_l, 'osnabrueck', 0, 'UTF-8') !== false || mb_stripos($to_l, 'osnabrück', 0, 'UTF-8') !== false;
        $from_hb = mb_stripos($from_l, 'bremen', 0, 'UTF-8') !== false || mb_stripos($from_l, 'bremerhaven', 0, 'UTF-8') !== false;
        $to_hb = mb_stripos($to_l, 'bremen', 0, 'UTF-8') !== false || mb_stripos($to_l, 'bremerhaven', 0, 'UTF-8') !== false;
        if ($from_os && $to_hb) { return 'HB'; }
        if ($from_hb && $to_os) { return 'OS'; }
        return null;
    }

    /** Label einer Richtung anhand des Schluessels aus den Einstellungen. */
    public static function direction_label(array $settings, string $key): string {
        foreach ($settings['directions'] as $dir) {
            if ((string) ($dir['key'] ?? '') === $key) {
                return (string) ($dir['label'] ?? $key);
            }
        }
        return $key;
    }

    public static function period_to_sql(?string $period): array {
        $period = $period ?: '30';
        if ($period === 'all') {
            return ['', []];
        }
        $days = in_array($period, ['7','30','90','365'], true) ? (int) $period : 30;
        $from = (new DateTimeImmutable('now', wp_timezone()))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        return [' AND planned_time >= %s ', [$from]];
    }

    public static function format_decimal(float $value): string {
        return number_format($value, 1, ',', '.');
    }
}
