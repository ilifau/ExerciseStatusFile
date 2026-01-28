# Changelog

Alle wichtigen Änderungen am ExerciseStatusFile Plugin werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [1.3.0] - 2026-01-28

### Hinzugefügt

#### Plugin-Konfigurationsseite
- **Neue Admin-Konfigurationsseite** in ILIAS-Administration
  - Zugriff: Administration → Plugins → ExerciseStatusFile → Konfigurieren
  - Debug-Modus für E-Mail-Benachrichtigungen per Checkbox umschaltbar
  - Persistente Speicherung in ILIAS-Datenbank (ilSetting)
  - Status-Anzeige: Aktueller Modus wird angezeigt

#### Verbesserte E-Mail Steuerung
- **Admin-UI für Debug-Modus**
  - Keine Code-Änderungen mehr nötig
  - Sofort umschaltbar ohne Server-Neustart
  - Nur für Administratoren zugänglich

### Geändert

#### Debug-Modus Verwaltung
- **Priorität der Einstellungen:**
  1. Datenbank-Setting (Admin-UI) → Primäre Quelle
  2. PHP-Konstante → Legacy-Fallback
  3. Default: true (sicher)
- Legacy-Konstante `DEBUG_EMAIL_NOTIFICATIONS` wird weiterhin als Fallback unterstützt

#### Neue Sprach-Keys
- `plugin_configuration` - Plugin-Konfiguration
- `config_section_notifications` - E-Mail-Benachrichtigungen
- `config_debug_email` - Debug-Modus aktivieren
- `config_debug_email_info` - Info-Text
- `config_current_status` - Aktueller Status
- `config_debug_mode_active` - Debug-Modus aktiv
- `config_production_mode_active` - Produktiv-Modus

### Behoben
- UI-Meldungen bei Integration-Tests unterdrückt (keine störenden Meldungen mehr)

---

## [1.2.0] - 2025-01-04

### Hinzugefügt

#### E-Mail Benachrichtigungen
- **Automatische E-Mail-Benachrichtigungen** beim Feedback-Upload
  - Studenten werden benachrichtigt wenn Tutor Feedback-Dateien hochlädt
  - Funktioniert für Individual- und Team-Assignments
  - Alle Team-Mitglieder werden bei Team-Feedback benachrichtigt
  - Duplicate-Prevention verhindert Mehrfach-Mails innerhalb eines Requests

- **Debug-Modus für sichere Tests**
  - Konstante `DEBUG_EMAIL_NOTIFICATIONS` in `class.ilExerciseStatusFilePlugin.php`
  - `true` = Debug-Modus (nur Logs, keine echten E-Mails) - **Standard**
  - `false` = Produktiv-Modus (echte E-Mails werden verschickt)
  - Ausführliche Logs mit allen Details

- **Neue Klasse: `ilExFeedbackNotificationSender`**
  - Zentrale Notification-Logik
  - Verwendet ILIAS Standard `NotificationManager`
  - Intelligente Empfänger-Erkennung (Team vs. Individual)
  - Duplicate-Prevention via `$notified_users` Array

#### Integration Tests
- **Test 6: E-Mail Benachrichtigungen** (3 neue Tests)
  - Test 6.1: Team-Benachrichtigung (3 Mitglieder)
  - Test 6.2: Mehrere Teams (2 Teams mit 2+3 Mitgliedern)
  - Test 6.3: Individual-Benachrichtigung (3 Users)
  - Alle Tests im Debug-Modus (keine echten E-Mails)

- **Modal-Integration für Tests**
  - "Run Tests" Button in ILIAS UI (Übung → Abgaben und Noten)
  - Live-Output im Browser
  - Automatisches Cleanup

- **Test-Ergebnisse:** 12/12 Tests bestanden

### Geändert

#### Performance-Optimierungen
- **Batch-Loading für Team-Daten**
  - Verwendet `ilExAssignmentTeam::getInstancesFromMap()` statt einzelne Queries
  - Reduziert DB-Queries von O(n) auf O(1)
  - ~10x schneller bei Team-Assignments mit vielen Mitgliedern

### Sicherheit
- Debug-Modus standardmäßig aktiviert
- Keine echten E-Mails während Tests/Entwicklung
- Sicher für Deployment auf Produktionssystemen

---

## [1.1.0] - 2025-01-30

### Hinzugefügt

#### Integration Tests
- **Vollständiges automatisiertes Test-Framework**
  - Test 1-2: Individual und Team Assignments
  - Test 3: Checksum-basierte Datei-Erkennung
  - Test 4: CSV Status-File Support
  - Test 5: Negative Tests (Error Handling, 5 Tests)
  - CLI-Runner: `run-all-tests.php`
  - Web-Runner: `web-runner.php`

- **Negative Tests für Error Handling**
  - Test 5.1: Invalid Status Values
  - Test 5.2: Empty Status Files
  - Test 5.3: Missing User in Status File
  - Test 5.4: Malformed ZIP Upload
  - Test 5.5: Wrong ZIP Structure

#### Features
- **CSV Status-File Support**
  - Tutoren können CSV statt Excel bearbeiten
  - Intelligente Auswahl: xlsx vs. csv basierend auf Checksums
  - Warnung wenn beide Dateien geändert wurden

- **Checksum-basierte Status-File Auswahl**
  - Automatische Erkennung welche Datei (xlsx/csv) verwendet werden soll
  - Basiert auf `checksums.json` Vergleich

- **Parent RefID Support für Tests**
  - Tests können in eigenem Ordner statt Root erstellt werden
  - CLI: `--parent-ref=123`
  - Web: Input-Feld für Parent RefID

### Geändert
- **Code-Cleanup**
  - Entfernte übermäßige Debug-Logs
  - Reduzierte Log-Verbosity im Produktiv-Betrieb

### Performance
- Checksum-Caching (keine redundanten File-Reads)
- Optimierte DB-Queries

---

## [1.0.0] - Initial Release

### Features
- **Status-File Export/Import**
  - Export als Excel (.xlsx) oder CSV
  - Batch-Updates mit `update=1` Flag
  - Unterstützt Individual- und Team-Assignments

- **Multi-Feedback Download**
  - Download aller Abgaben mit einem Klick
  - Automatische Ordnerstruktur
  - Inklusive Status-Files

- **Multi-Feedback Upload**
  - Upload von Feedback für mehrere Teilnehmer
  - Automatische Filterung von Submissions
  - Feedback ohne Status-Updates möglich

- **Checksum-basierte Datei-Erkennung**
  - Geänderte Dateien → `_korrigiert` Suffix
  - Unveränderte Dateien → Original-Namen
  - `checksums.json` für Vergleich

---

## Migration Notes

### Von 1.2.0 zu 1.3.0

**Breaking Changes:** Keine

**Neue Abhängigkeiten:** Keine

**Empfohlene Schritte:**

1. **Update durchführen:**
   ```bash
   git pull
   composer install
   php setup/setup.php update
   ```

2. **Plugin aktualisieren:**
   - Administration → Plugins → UI Component Hook Plugins
   - ExerciseStatusFile → **Aktualisieren** klicken
   - Danach erscheint "Konfigurieren" in den Aktionen

3. **Debug-Modus prüfen:**
   - ExerciseStatusFile → Aktionen → **Konfigurieren**
   - Standard: Debug-Modus aktiviert (sicher)
   - Bei Bedarf: Checkbox deaktivieren für Produktiv-Betrieb

### Von 1.1.0 zu 1.2.0

**Breaking Changes:** Keine

**Empfohlene Schritte:**
1. Plugin-Update durchführen
2. Tests ausführen (Modal oder web-runner.php)
3. Debug-Modus initial aktiv lassen

### Von 1.0.0 zu 1.1.0

**Breaking Changes:** Keine

**Empfohlene Schritte:**
1. Plugin-Update durchführen
2. Tests ausführen (web-runner.php)

---

## Support

Bei Fragen oder Problemen:
- GitHub Issues
- E-Mail: cornel.musielak@fau.de
