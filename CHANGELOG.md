# Changelog

Alle wichtigen Änderungen am ExerciseStatusFile Plugin werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [1.2.0] - 2025-01-04

### Hinzugefügt

#### E-Mail Benachrichtigungen 📧
- **Automatische E-Mail-Benachrichtigungen** beim Feedback-Upload
  - Studenten werden benachrichtigt wenn Tutor Feedback-Dateien hochlädt
  - Funktioniert für Individual- und Team-Assignments
  - Alle Team-Mitglieder werden bei Team-Feedback benachrichtigt
  - Duplicate-Prevention verhindert Mehrfach-Mails innerhalb eines Requests

- **Debug-Modus für sichere Tests**
  - Neue Konstante `DEBUG_EMAIL_NOTIFICATIONS` in `class.ilExerciseStatusFilePlugin.php`
  - `true` = Debug-Modus (nur Logs, keine echten E-Mails) - **Standard**
  - `false` = Produktiv-Modus (echte E-Mails werden verschickt)
  - Admin-Benachrichtigungen im Browser (nur für Admins sichtbar)
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
  - "🧪 Run Tests" Button in ILIAS UI (Übung → Abgaben und Noten)
  - Live-Output im Browser
  - Automatisches Cleanup
  - Neue Option: "📧 Nur E-Mail-Benachrichtigungs-Tests"

- **Test-Ergebnisse:** 12/12 Tests bestanden in 8.93s ✅

#### Dokumentation
- `tests/MODAL_TEST_GUIDE.md` - Guide für Modal-basierte Tests
- `tests/NOTIFICATION_TEST_GUIDE.md` - Ausführliche Notification-Dokumentation
- `tests/integration/NOTIFICATION_TESTING.md` - Quick Start für CLI/Web
- `ki_infos/integration_tests_updated_2025_01_04.md` - Update-Dokumentation
- `ki_infos/branch_status_fix_and_performance.md` - Branch-Status Report
- `CHANGELOG.md` - Changelog-Datei (diese Datei)

### Geändert

#### Performance-Optimierungen
- **Batch-Loading für Team-Daten**
  - Verwendet `ilExAssignmentTeam::getInstancesFromMap()` statt einzelne Queries
  - Reduziert DB-Queries von O(n) auf O(1)
  - ~10x schneller bei Team-Assignments mit vielen Mitgliedern
  - N+1 Query Problem gelöst

#### Code-Verbesserungen
- `ilExFeedbackUploadHandler`: Integration von Benachrichtigungen
  - Zeile 920-921: Benachrichtigung nach ResourceStorage-Upload
  - Zeile 981-982: Benachrichtigung nach Filesystem-Upload
- `TestHelper.php`: Fix für `downloadMultiFeedbackZip()`
  - Eigene Implementierung statt nicht-existierende ILIAS-Methode
  - Erstellt korrekte ZIP-Struktur (`exc_teams_X/` oder `user_X/`)
  - Unterstützt Teams und Individual Assignments

### Behoben
- **TestHelper.downloadMultiFeedbackZip() Fehler**
  - Call to undefined method `ilExMultiFeedbackDownloadHandler::generateMultiFeedbackZip()`
  - Lösung: Manuelle ZIP-Erstellung mit korrekter Struktur

### Sicherheit
- Debug-Modus standardmäßig aktiviert (`DEBUG_EMAIL_NOTIFICATIONS = true`)
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

#### Dokumentation
- `tests/integration/README.md` - Vollständige Test-Dokumentation
- `tests/integration/QUICKSTART.md` - Schneller Einstieg
- `tests/MANUAL_TEST_GUIDE.md` - Manuelle Test-Anleitung
- `docs/ADMIN_GUIDE_TESTS.md` - Admin-Guide
- `ki_infos/integration_tests.md` - Test-Übersicht

### Geändert
- **Code-Cleanup**
  - Entfernte übermäßige Debug-Logs (nur Info-Level für wichtige Events)
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

## Geplante Features (Roadmap)

### v1.3.0 (Optional)
- [ ] Admin-UI für Debug-Modus Toggle
- [ ] Notification-Statistiken Dashboard
- [ ] Batch-Benachrichtigungen (optional)

### Zukünftige Versionen
- [ ] User-Preference UI für Notifications
- [ ] Erweiterte Checksum-Optionen
- [ ] CI/CD Integration für Tests

---

## Migration Notes

### Von 1.1.0 zu 1.2.0

**Breaking Changes:** Keine

**Neue Abhängigkeiten:** Keine

**Empfohlene Schritte:**

1. **Update durchführen:**
   ```bash
   git pull origin main
   composer install
   php setup/setup.php update
   ```

2. **Plugin aktivieren:**
   - Administration → Plugins → UI Component Hook Plugins
   - ExerciseStatusFile aktivieren (falls deaktiviert)

3. **Tests ausführen (empfohlen):**
   - In ILIAS: Übung öffnen → "Abgaben und Noten" → "🧪 Run Tests"
   - Oder via Web: `tests/integration/web-runner.php`
   - Erwartetes Ergebnis: 12/12 Tests bestanden

4. **Debug-Modus prüfen:**
   - Datei: `classes/class.ilExerciseStatusFilePlugin.php`
   - Zeile 17: `DEBUG_EMAIL_NOTIFICATIONS = true` (sollte `true` sein für sicheren Start)

5. **Monitoring einrichten:**
   - ILIAS-Logs überwachen: `tail -f /var/www/StudOn/data/studon/ilias.log | grep notification`
   - Erste Woche: Tägliche Log-Prüfung

6. **Optional: Produktiv-Modus aktivieren (nach Tests):**
   - Setze `DEBUG_EMAIL_NOTIFICATIONS = false`
   - Opcode-Cache leeren: `service php8.2-fpm reload`

### Von 1.0.0 zu 1.1.0

**Breaking Changes:** Keine

**Empfohlene Schritte:**
1. Plugin-Update durchführen
2. Tests ausführen (web-runner.php)
3. Integration Tests dokumentieren

---

## Support

Bei Fragen oder Problemen:
- GitHub Issues: [Repository Issues](https://github.com/yourusername/ExerciseStatusFile/issues)
- E-Mail: cornel.musielak@fau.de
- Dokumentation: `README.md`, `tests/*.md`, `ki_infos/*.md`
