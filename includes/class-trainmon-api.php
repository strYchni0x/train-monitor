<?php
if (!defined('ABSPATH')) { exit; }

class TrainMon_API {
    /** Alle Halte einer bestimmten Linie am Bahnhof fuer die naechsten $hours Stunden. */
    public function get_stops(string $eva, string $line, int $hours = 4): array {
        $line = strtoupper(trim($line));
        $now = new DateTimeImmutable('now', wp_timezone());
        $planned = [];
        $errors = [];
        for ($i = 0; $i < $hours; $i++) {
            $hour = $now->modify('+' . $i . ' hours');
            // Ein fehlgeschlagener Stunden-Abruf (z. B. Timeout) verwirft nicht
            // den ganzen Durchlauf - die uebrigen Stunden werden trotzdem importiert.
            try {
                $planned = array_merge($planned, $this->fetch_plan($eva, $hour));
            } catch (RuntimeException $e) {
                $errors[] = $hour->format('H') . ':00 - ' . $e->getMessage();
            }
        }
        if (!$planned && $errors) {
            throw new RuntimeException(implode(' | ', array_unique($errors)));
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            foreach ($errors as $err) {
                error_log('German Regional Train Monitor (partial fetch failed): ' . $err);
            }
        }
        // Change data is optional: without fchg the planned times remain.
        try {
            $changes = $this->fetch_changes_indexed($eva);
        } catch (RuntimeException $e) {
            $changes = [];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('German Regional Train Monitor (fchg failed): ' . $e->getMessage());
            }
        }
        $result = [];
        foreach ($planned as $stop) {
            if (strtoupper((string) ($stop['line'] ?? '')) !== $line) { continue; }
            $id = $stop['id'] ?? '';
            if ($id && isset($changes[$id])) {
                $stop = $this->merge_stop_with_change($stop, $changes[$id]);
            }
            $result[] = $stop;
        }
        return $result;
    }

    /** Bahnhofssuche ueber den /station-Endpunkt: liefert [ ['eva'=>..,'name'=>..], ... ]. */
    public function search_stations(string $query): array {
        $query = trim($query);
        if ($query === '') { return []; }
        $body = $this->http_get(TrainMon_Plugin::API_BASE . '/station/' . rawurlencode($query));
        if (!$body) { return []; }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if (!$xml) {
            throw new RuntimeException(__('Could not read the station list from the DB API.', 'german-regional-train-monitor'));
        }
        $out = [];
        foreach ($xml->station as $st) {
            $a = $st->attributes();
            $eva = isset($a['eva']) ? (string) $a['eva'] : '';
            $name = isset($a['name']) ? (string) $a['name'] : '';
            if ($eva !== '' && $name !== '') {
                $out[] = ['eva' => $eva, 'name' => $name];
            }
        }
        return $out;
    }

    /**
     * Ermittelt alle Regionallinien, die am Bahnhof halten, samt der zwei
     * Fahrtrichtungen (naechster Halt als Schluessel, haeufigster Zielbahnhof als Label).
     * Rueckgabe: [ ['line'=>'RE9','directions'=>[ ['key','terminus','label','count'], ... ] ], ... ]
     */
    public function scan_lines(string $eva, int $hours = 24): array {
        $now = new DateTimeImmutable('now', wp_timezone());
        $planned = [];
        $errors = [];
        for ($i = 0; $i < $hours; $i++) {
            $hour = $now->modify('+' . $i . ' hours');
            try {
                $planned = array_merge($planned, $this->fetch_plan($eva, $hour));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (!$planned && $errors) {
            throw new RuntimeException(implode(' | ', array_unique($errors)));
        }

        // Pro Linie je "naechster Halt" die Zielbahnhoefe zaehlen.
        $lines = [];
        foreach ($planned as $stop) {
            if (!TrainMon_Helpers::is_regional((string) ($stop['category'] ?? ''))) { continue; }
            $line = strtoupper((string) ($stop['line'] ?? ''));
            $next = TrainMon_Helpers::next_station_of($stop);
            $term = TrainMon_Helpers::terminus_of($stop);
            if ($line === '' || $next === '') { continue; }
            if (!isset($lines[$line])) { $lines[$line] = []; }
            if (!isset($lines[$line][$next])) { $lines[$line][$next] = ['count' => 0, 'terms' => []]; }
            $lines[$line][$next]['count']++;
            $tkey = $term !== '' ? $term : $next;
            $lines[$line][$next]['terms'][$tkey] = ($lines[$line][$next]['terms'][$tkey] ?? 0) + 1;
        }

        $out = [];
        foreach ($lines as $line => $dirs) {
            // Nach Haeufigkeit sortieren, die zwei haeufigsten Richtungen behalten.
            uasort($dirs, function ($a, $b) { return $b['count'] - $a['count']; });
            $directions = [];
            foreach (array_slice($dirs, 0, 2, true) as $next => $info) {
                arsort($info['terms']);
                $terminus = (string) array_key_first($info['terms']);
                $directions[] = [
                    'key'      => $next,
                    'terminus' => $terminus,
                    /* translators: %s: destination station name */
                    'label'    => sprintf(__('Toward %s', 'german-regional-train-monitor'), $terminus),
                    'count'    => $info['count'],
                ];
            }
            $out[] = ['line' => $line, 'directions' => $directions];
        }
        // Linien natuerlich sortieren (RB1, RB2, RE9 ...).
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['line'], $b['line']);
        });
        return $out;
    }

    private function fetch_plan(string $eva, DateTimeImmutable $hour): array {
        $url = TrainMon_Plugin::API_BASE . '/plan/' . rawurlencode($eva) . '/' . $hour->format('ymd') . '/' . $hour->format('H');
        return $this->fetch_xml_stops($url);
    }

    private function fetch_changes_indexed(string $eva): array {
        $url = TrainMon_Plugin::API_BASE . '/fchg/' . rawurlencode($eva);
        $stops = $this->fetch_xml_stops($url);
        $indexed = [];
        foreach ($stops as $stop) {
            if (!empty($stop['id'])) { $indexed[$stop['id']] = $stop; }
        }
        return $indexed;
    }

    /** Authentifizierter GET gegen die DB API; liefert den Response-Body. */
    private function http_get(string $url): string {
        if (!defined('DB_TIMETABLES_CLIENT_ID') || !defined('DB_TIMETABLES_API_KEY')) {
            throw new RuntimeException(__('The DB Timetables API credentials are missing. Please define DB_TIMETABLES_CLIENT_ID and DB_TIMETABLES_API_KEY in wp-config.php.', 'german-regional-train-monitor'));
        }
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'DB-Client-Id' => DB_TIMETABLES_CLIENT_ID,
                'DB-Api-Key' => DB_TIMETABLES_API_KEY,
                'accept' => 'application/xml',
            ],
        ]);
        if (is_wp_error($response)) {
            /* translators: %s: error message from the HTTP request */
            throw new RuntimeException(sprintf(__('API error: %s', 'german-regional-train-monitor'), $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            /* translators: %d: HTTP status code */
            throw new RuntimeException(sprintf(__('API HTTP status: %d', 'german-regional-train-monitor'), $status));
        }
        return (string) wp_remote_retrieve_body($response);
    }

    private function fetch_xml_stops(string $url): array {
        $body = $this->http_get($url);
        if (!$body) { return []; }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if (!$xml) {
            throw new RuntimeException(__('Could not read the XML from the DB API.', 'german-regional-train-monitor'));
        }
        $stops = [];
        foreach ($xml->s as $s) {
            $attrs = $s->attributes();
            $tl = $s->tl ?? null;
            $tl_attrs = $tl ? $tl->attributes() : null;
            $ar = $s->ar ?? null;
            $dp = $s->dp ?? null;
            $ar_attrs = $ar ? $ar->attributes() : null;
            $dp_attrs = $dp ? $dp->attributes() : null;

            // Gattung (z. B. "RE") aus tl@c; Liniennummer ("9") aus ar/dp@l.
            // Zusammengesetzt ergibt das die Linienbezeichnung "RE9".
            $category = ($tl_attrs && isset($tl_attrs['c'])) ? (string) $tl_attrs['c'] : '';
            $line_no  = $this->attr_first($dp_attrs, $ar_attrs, 'l');
            $line = $category;
            if ($line_no !== null && $line_no !== '') {
                $line = ($category !== '' && stripos($line_no, $category) === 0)
                    ? strtoupper($line_no)
                    : $category . $line_no;
            }

            // Laufweg: ar@ppth = Stationen VOR diesem Halt (erste = Startbahnhof),
            // dp@ppth = Stationen danach (erste = naechster Halt, letzte = Ziel).
            $ar_path = ($ar_attrs && isset($ar_attrs['ppth'])) ? array_map('trim', explode('|', (string) $ar_attrs['ppth'])) : [];
            $dp_path = ($dp_attrs && isset($dp_attrs['ppth'])) ? array_map('trim', explode('|', (string) $dp_attrs['ppth'])) : [];

            $stops[] = [
                'id' => isset($attrs['id']) ? (string) $attrs['id'] : '',
                'category' => $category,
                'line' => $line,
                'from' => $ar_path ? $ar_path[0] : '',
                'to' => $dp_path ? (string) end($dp_path) : '',
                'next' => $dp_path ? $dp_path[0] : '',
                'arrival_planned' => ($ar_attrs && isset($ar_attrs['pt'])) ? (string) $ar_attrs['pt'] : null,
                'arrival_changed' => ($ar_attrs && isset($ar_attrs['ct'])) ? (string) $ar_attrs['ct'] : null,
                'departure_planned' => ($dp_attrs && isset($dp_attrs['pt'])) ? (string) $dp_attrs['pt'] : null,
                'departure_changed' => ($dp_attrs && isset($dp_attrs['ct'])) ? (string) $dp_attrs['ct'] : null,
                'platform_planned' => $this->attr_first($dp_attrs, $ar_attrs, 'pp'),
                'platform_changed' => $this->attr_first($dp_attrs, $ar_attrs, 'cp'),
                'cancelled' => $this->is_cancelled($ar_attrs) || $this->is_cancelled($dp_attrs),
                'raw' => wp_json_encode(json_decode(wp_json_encode($s), true), JSON_UNESCAPED_UNICODE),
            ];
        }
        return $stops;
    }

    private function attr_first($first, $second, string $name): ?string {
        if ($first && isset($first[$name])) { return (string) $first[$name]; }
        if ($second && isset($second[$name])) { return (string) $second[$name]; }
        return null;
    }

    private function is_cancelled($attrs): bool {
        return $attrs && isset($attrs['cs']) && (string) $attrs['cs'] === 'c';
    }

    private function merge_stop_with_change(array $planned, array $change): array {
        // Nur echte Aenderungswerte uebernehmen; Stammdaten (Linie, Laufweg) behalten.
        $keep = ['line', 'category', 'from', 'to', 'next'];
        foreach ($change as $key => $value) {
            if (in_array($key, $keep, true)) { continue; }
            if ($value !== null && $value !== '') { $planned[$key] = $value; }
        }
        return $planned;
    }
}
