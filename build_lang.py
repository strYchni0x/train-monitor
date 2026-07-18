# -*- coding: utf-8 -*-
"""Builds .pot / de_DE.po / de_DE.mo (and de_DE_formal) for German Regional
Train Monitor. Extracts msgids from the PHP files, validates them against the
EN->DE table below (aborts on any missing/extra), then writes the catalogs.
JS strings are provided via wp_localize_script (PHP __()), so no Jed JSON is
needed. This script is a dev tool and is NOT shipped in the wordpress.org ZIP.
"""
import re
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DOMAIN = "german-regional-train-monitor"
LANG_DIR = ROOT / "languages"
VERSION = "1.0.0"

DE = {
    "German Regional Train Monitor": "German Regional Train Monitor",
    "Train Monitor": "Train Monitor",
    "Please enter a search term.": "Bitte einen Suchbegriff eingeben.",
    "Searching stations …": "Suche Bahnhöfe …",
    "Search failed.": "Fehler bei der Suche.",
    "No stations found.": "Keine Bahnhöfe gefunden.",
    "Network error during search.": "Netzwerkfehler bei der Suche.",
    "Searching regional lines (this may take a moment) …": "Suche Regionallinien (kann einen Moment dauern) …",
    "Line scan failed.": "Fehler beim Linien-Scan.",
    "-- choose a line --": "– Linie wählen –",
    "Network error during line scan.": "Netzwerkfehler beim Linien-Scan.",
    "You are not allowed to do this.": "Keine Berechtigung.",
    "No station selected.": "Kein Bahnhof gewählt.",
    "No regional trains were found at this station.": "An diesem Bahnhof wurden keine Regionalzüge gefunden.",
    "Toward %s": "Richtung %s",
    "OK": "OK",
    "error": "Fehler",
    "not configured": "nicht konfiguriert",
    "Manual fetch completed.": "Manueller Abruf wurde ausgeführt.",
    'Selection saved. Please click "Fetch now" once.': "Auswahl gespeichert. Bitte einmal „Jetzt abrufen“ klicken.",
    "Selection incomplete – please choose a station and a line.": "Auswahl unvollständig – bitte Bahnhof und Linie wählen.",
    "Monitored connection": "Überwachte Verbindung",
    "No station selected yet. Please pick a station and line below.": "Noch kein Bahnhof gewählt. Bitte unten Bahnhof und Linie auswählen.",
    "Station": "Bahnhof",
    "Line": "Linie",
    "Directions": "Fahrtrichtungen",
    "Choose a different connection": "Andere Verbindung wählen",
    "First search for the station, then choose a regional line that stops there. The line scan reads the next 24 hours and may take a moment.":
        "Zuerst den Bahnhof suchen, dann eine dort haltende Regionallinie wählen. Der Linien-Scan wertet die nächsten 24 Stunden aus und kann einen Moment dauern.",
    "1. Search station": "1. Bahnhof suchen",
    "e.g. Osnabrück": "z. B. Osnabrück",
    "Search station": "Bahnhof suchen",
    "2. Choose line": "2. Linie wählen",
    "3. Directions (labels editable)": "3. Fahrtrichtungen (Beschriftung anpassbar)",
    "Direction 1": "Richtung 1",
    "Direction 2": "Richtung 2",
    "Save selection": "Auswahl speichern",
    "Status": "Status",
    "API credentials": "API-Zugangsdaten",
    "found": "gefunden",
    "missing": "fehlen",
    "Last fetch": "Letzter Abruf",
    "never": "nie",
    "Status of last fetch": "Status letzter Abruf",
    "unknown": "unbekannt",
    "Trips imported/updated last time": "Zuletzt importierte/aktualisierte Fahrten",
    "Next WP-Cron": "Nächster WP-Cron",
    "not scheduled": "nicht geplant",
    "Stored records (this connection)": "Gespeicherte Datensätze (diese Verbindung)",
    "Last error": "Letzter Fehler",
    "Fetch now": "Jetzt abrufen",
    "Quick stats, last 30 days": "Kurzstatistik letzte 30 Tage",
    "On time:": "Pünktlich:",
    "Average delay:": "Durchschnittliche Verspätung:",
    "minutes": "Minuten",
    "Cancellations:": "Ausfälle:",
    "Embedding": "Einbindung",
    "Default:": "Standard:",
    "With period filter:": "Mit Zeitraumfilter:",
    "Configuration": "Konfiguration",
    "The DB API credentials must be defined in %s:": "Die DB-Zugangsdaten müssen in %s stehen:",
    "Recommendation: for reliable statistics, set up a real server cron that calls WordPress cron regularly.":
        "Empfehlung: Für eine zuverlässige Statistik einen echten Server-Cron einrichten, der WordPress-Cron regelmäßig aufruft.",
    "Could not read the station list from the DB API.": "Stationsliste der DB-API konnte nicht gelesen werden.",
    "The DB Timetables API credentials are missing. Please define DB_TIMETABLES_CLIENT_ID and DB_TIMETABLES_API_KEY in wp-config.php.":
        "DB-Timetables-API-Zugangsdaten fehlen. Bitte DB_TIMETABLES_CLIENT_ID und DB_TIMETABLES_API_KEY in wp-config.php setzen.",
    "API error: %s": "API-Fehler: %s",
    "API HTTP status: %d": "API-HTTP-Status: %d",
    "Could not read the XML from the DB API.": "XML der DB-API konnte nicht gelesen werden.",
    "Every five minutes": "Alle fünf Minuten",
    "German Regional Train Monitor is not configured yet. Please choose a station and line under Settings.":
        "German Regional Train Monitor ist noch nicht konfiguriert. Bitte unter Einstellungen einen Bahnhof und eine Linie wählen.",
    "%1$s from %2$s": "%1$s ab %2$s",
    "Upcoming departures and recorded punctuality.": "Nächste Verbindungen und gespeicherte Pünktlichkeitswerte.",
    "Punctuality: %1$s from %2$s": "Pünktlichkeit: %1$s ab %2$s",
    "Overall": "Gesamt",
    "On time means: at most %d minutes delay. Cancellations are counted separately and are not included in the average delay.":
        "Pünktlich bedeutet hier: maximal %d Minuten Verspätung. Ausfälle werden separat gezählt und nicht in die Durchschnittsverspätung eingerechnet.",
    "No upcoming departure found.": "Aktuell keine Verbindung gefunden.",
    "Train cancelled": "Zug fällt aus",
    "+%d min delay": "+%d Min Verspätung",
    "on time": "pünktlich",
    "Platform %s": "Gleis %s",
    "scheduled: %s": "geplant: %s Uhr",
    "min": "Min",
    "average": "Durchschnitt",
    "5-10 min": "5-10 Min",
    "11-20 min": "11-20 Min",
    "21+ min": "ab 21 Min",
    "cancellations": "Ausfälle",
    "total trips": "Fahrten gesamt",
    "entire recorded period": "gesamter gespeicherter Zeitraum",
    "last %s days": "letzte %s Tage",
    "Year %s": "Jahr %s",
    "All": "Alle",
    "Year": "Jahr",
    "Month": "Monat",
    "Show": "Anzeigen",
}

FUNCS = r"(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)"
RE_SQ = re.compile(FUNCS + r"\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'" + DOMAIN + "'")
RE_DQ = re.compile(FUNCS + r'\(\s*"((?:\\.|[^"\\])*)"\s*,\s*\'' + DOMAIN + "'")


def unescape_sq(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")


def extract(path):
    text = path.read_text(encoding="utf-8")
    ids = [unescape_sq(m) for m in RE_SQ.findall(text)] + RE_DQ.findall(text)
    calls = len(re.findall(FUNCS + r"\(\s*['\"]", text))
    if calls != len(ids):
        print(f"WARNING: {path.name}: {calls} i18n calls but {len(ids)} extracted!")
        sys.exit(1)
    return ids


def po_escape(s):
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def po_entry(msgid, msgstr):
    return f'msgid "{po_escape(msgid)}"\nmsgstr "{po_escape(msgstr)}"\n'


def write_mo(path, catalog):
    items = sorted(catalog.items())
    ids = b""
    strs = b""
    offsets = []
    for mid, mstr in items:
        idb = mid.encode("utf-8")
        strb = mstr.encode("utf-8")
        offsets.append((len(ids), len(idb), len(strs), len(strb)))
        ids += idb + b"\x00"
        strs += strb + b"\x00"
    n = len(items)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets = []
    voffsets = []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]
    output = struct.pack("Iiiiiii", 0x950412DE, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    output += struct.pack("i" * n * 2, *koffsets)
    output += struct.pack("i" * n * 2, *voffsets)
    output += ids + strs
    path.write_bytes(output)


def main():
    files = sorted(ROOT.glob("*.php")) + sorted((ROOT / "includes").glob("*.php"))
    all_ids = []
    for f in files:
        for i in extract(f):
            if i not in all_ids:
                all_ids.append(i)

    missing = [i for i in all_ids if i not in DE]
    extra = [k for k in DE if k not in all_ids]
    if missing:
        print("MISSING translations:")
        for m in missing:
            print("  " + repr(m))
        sys.exit(1)
    if extra:
        print("EXTRA entries in DE table:")
        for e in extra:
            print("  " + repr(e))
        sys.exit(1)

    LANG_DIR.mkdir(exist_ok=True)
    rev_date = "2026-07-18 12:00+0200"

    pot = (
        'msgid ""\nmsgstr ""\n'
        f'"Project-Id-Version: German Regional Train Monitor {VERSION}\\n"\n'
        f'"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/{DOMAIN}\\n"\n'
        '"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n'
        '"Content-Transfer-Encoding: 8bit\\n"\n'
        f'"POT-Creation-Date: {rev_date}\\n"\n'
        f'"X-Domain: {DOMAIN}\\n"\n\n'
    )
    pot += "\n".join(po_entry(i, "") for i in all_ids)
    (LANG_DIR / f"{DOMAIN}.pot").write_text(pot, encoding="utf-8", newline="\n")

    for locale in ("de_DE", "de_DE_formal"):
        po_header = (
            'msgid ""\nmsgstr ""\n'
            f'"Project-Id-Version: German Regional Train Monitor {VERSION}\\n"\n'
            '"MIME-Version: 1.0\\n"\n'
            '"Content-Type: text/plain; charset=UTF-8\\n"\n'
            '"Content-Transfer-Encoding: 8bit\\n"\n'
            f'"PO-Revision-Date: {rev_date}\\n"\n'
            f'"Language: {locale}\\n"\n'
            '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n'
            f'"X-Domain: {DOMAIN}\\n"\n\n'
        )
        po = po_header + "\n".join(po_entry(i, DE[i]) for i in all_ids)
        (LANG_DIR / f"{DOMAIN}-{locale}.po").write_text(po, encoding="utf-8", newline="\n")

        meta = (
            f"Project-Id-Version: German Regional Train Monitor {VERSION}\n"
            "MIME-Version: 1.0\n"
            "Content-Type: text/plain; charset=UTF-8\n"
            "Content-Transfer-Encoding: 8bit\n"
            f"PO-Revision-Date: {rev_date}\n"
            f"Language: {locale}\n"
            "Plural-Forms: nplurals=2; plural=(n != 1);\n"
        )
        catalog = {"": meta}
        catalog.update({i: DE[i] for i in all_ids})
        write_mo(LANG_DIR / f"{DOMAIN}-{locale}.mo", catalog)

    print(f"OK: {len(all_ids)} strings.")
    for f in sorted(LANG_DIR.iterdir()):
        print("  ", f.name, f.stat().st_size, "bytes")


if __name__ == "__main__":
    main()
