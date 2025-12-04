# ExerciseStatusFile Plugin für ILIAS/StudOn

Status-File und Multi-Feedback Erweiterung für ILIAS Übungen (Exercises).

## Features

### 1. Status-File Export/Import
- Export von Bewertungen als Excel (.xlsx) oder CSV
- Bearbeitung von Status, Noten, Kommentaren außerhalb von ILIAS
- Import mehrerer Änderungen auf einmal (markierte Zeilen mit `update=1`)
- Unterstützt Individual- und Team-Assignments

### 2. Multi-Feedback Download
- Download aller Abgaben mit einem Klick
- Automatische Ordnerstruktur:
  - Individual: `Lastname_Firstname_Login_ID/`
  - Teams: `Team_ID/Lastname_Firstname_Login_ID/`
- Inklusive Status-File für Bewertungen

### 3. Multi-Feedback Upload
- Upload von Feedback-Dateien für mehrere Teilnehmer gleichzeitig
- **Feedback OHNE Status-Updates möglich** - einfach Dateien in User-Ordner legen
- Automatische Filterung: Submissions werden nicht als Feedback hochgeladen
- Unterstützt Individual- und Team-Assignments

### 4. E-Mail Benachrichtigungen ✨ NEU in v1.2.0
- **Automatische E-Mail-Benachrichtigung** beim Feedback-Upload
- Studenten werden benachrichtigt wenn Tutor Feedback-Dateien hochlädt
- Funktioniert für Individual- und Team-Assignments
- Bei Teams: Alle Team-Mitglieder werden benachrichtigt
- **Debug-Modus für sichere Tests** (keine echten E-Mails während Entwicklung)

## Installation

### Voraussetzungen
- ILIAS 8.x oder höher
- PHP 8.1 oder höher
- Composer

### Schritte

1. **Plugin klonen:**
```bash
cd Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/comusielak/ExerciseStatusFile.git
```

2. **Composer & Setup:**
```bash
composer du
php setup/setup.php update
```

3. **Plugin aktivieren:**
- Administration → Plugins → UI Component Hook Plugins
- ExerciseStatusFile aktivieren

## Verwendung

### Multi-Feedback Download

1. In einer Übung → Bewertung/Noten
2. Button "Multi-Feedback Download" klicken
3. Teams/User auswählen
4. ZIP wird generiert mit:
   - Alle Abgaben in User-Ordnern
   - `status.xlsx` und `status.csv` für Bewertungen
   - `README.md` mit Anleitung

### Feedback hinzufügen und hochladen

1. **ZIP entpacken**
2. **Feedback-Dateien hinzufügen:**
   - Legen Sie neue Dateien in die User-Ordner
   - **WICHTIG:** Ordner-Namen NICHT ändern!
   - Format: `Lastname_Firstname_Login_ID/` für Individual
   - Format: `Team_X/Lastname_Firstname_Login_ID/` für Teams

   ⚠️ **WICHTIG: Feedback-Dateien müssen ANDERE Namen haben als die Submissions!**

   Dateien mit identischen Namen werden automatisch gefiltert und NICHT als Feedback hochgeladen.

   **Beispiel:**
   - ❌ **FALSCH:** Student hat `Aufgabe1.java` abgegeben → Sie legen ebenfalls `Aufgabe1.java` als Feedback ab
     → Wird automatisch gefiltert (erkannt als Submission)
   - ✅ **RICHTIG:** Student hat `Aufgabe1.java` abgegeben → Sie legen `Aufgabe1_korrigiert.java` oder `Feedback.pdf` als Feedback ab
     → Wird als Feedback hochgeladen

3. **Status bearbeiten (optional):**
   - Öffne `status.xlsx` oder `status.csv`
   - Bei `update` eine `1` eintragen für Zeilen die aktualisiert werden sollen
   - Status/Note/Kommentar ändern
   - **Hinweis:** Feedback-Upload funktioniert auch OHNE Status-Updates!

4. **ZIP wieder hochladen:**
   - Ganzes ZIP-Archiv erneut erstellen
   - In ILIAS hochladen
   - Feedback-Dateien werden automatisch verarbeitet
   - **Studenten erhalten E-Mail-Benachrichtigung** (wenn aktiviert)

### Was wird hochgeladen?

**Als Feedback hochgeladen:**
- ✅ Neue Dateien in User-Ordnern
- ✅ Dateien die NICHT als Submission existieren

**NICHT als Feedback hochgeladen:**
- ❌ Submissions (bereits abgegebene Dateien)
- ❌ System-Dateien (status.xlsx, status.csv, README.md)
- ❌ Hidden Files (.DS_Store, __MACOSX)

## Wichtige Hinweise

⚠️ **Ordner-Namen dürfen NICHT geändert werden!**

Die Ordner-Namen werden zur korrekten Zuordnung benötigt:
- `Lastname_Firstname_Login_ID/` → Format muss erhalten bleiben
- `Team_13/` → Team-Nummern müssen korrekt sein
- Bei Namensänderungen können Dateien nicht zugeordnet werden!

⚠️ **ZIP-Struktur muss erhalten bleiben!**

Bei Team-Assignments:
```
Multi_Feedback_AssignmentName/
├── status.xlsx
├── status.csv
├── README.md
└── Team_13/
    ├── Lastname_Firstname_Login_ID/
    │   ├── submission.java        # Wird NICHT als Feedback hochgeladen
    │   └── korrektur.pdf          # Wird als Feedback hochgeladen
    └── Othername_Otherfirst_login2_ID/
        └── feedback.txt
```

Bei Individual-Assignments:
```
Multi_Feedback_AssignmentName/
├── status.xlsx
├── status.csv
├── README.md
├── Lastname_Firstname_Login_ID/
│   ├── submission.java
│   └── feedback.pdf
└── Othername_Otherfirst_login2_ID/
    └── feedback.txt
```

## Technische Details

### Status-File Format

**Excel/CSV Spalten:**
- `update`: `1` = Zeile wird aktualisiert, `0` = ignorieren
- `team_id` / `user_id`: ID des Teams/Users
- `logins`: Login-Namen (Komma-getrennt bei Teams)
- `status`: `passed`, `failed`, `notgraded`
- `mark`: Note (Text/Zahl)
- `notice`: Feedback-Text
- `comment`: Interner Kommentar

### Filterlogik

Beim Upload werden Feedback-Dateien mit bestehenden Submissions verglichen:
- Vergleich mit und ohne Zeitstempel-Prefix (`20250130120000_datei.txt`)
- Bei Teams: Submissions aller Team-Mitglieder werden berücksichtigt
- Nur neue Dateien werden als Feedback hochgeladen

## E-Mail Benachrichtigungen 📧

### Funktionsweise

Studenten erhalten automatisch eine E-Mail wenn der Tutor Feedback-Dateien hochlädt.

**Wann wird benachrichtigt:**
- ✅ Beim Upload von Feedback-**Dateien** (nicht nur Status-Updates)
- ✅ Bei Individual-Assignments: Jeder Student einzeln
- ✅ Bei Team-Assignments: Alle Team-Mitglieder

**Wann NICHT:**
- ❌ Bei reinen Status-Updates ohne Feedback-Dateien
- ❌ Wenn User Benachrichtigungen deaktiviert hat (ILIAS Profil-Einstellung)

### Debug-Modus ⚠️

**Standard-Einstellung:** Debug-Modus ist **aktiviert**

```php
// classes/class.ilExerciseStatusFilePlugin.php (Zeile 17)
const DEBUG_EMAIL_NOTIFICATIONS = true;  // ← Standard: sicher
```

**Im Debug-Modus:**
- ❌ **Keine echten E-Mails** werden verschickt
- ✅ Ausführliche Logs in `/var/www/StudOn/data/studon/ilias.log`
- ✅ Admin-Benachrichtigungen im Browser (nur für Admins sichtbar)
- ✅ **Sicher für Produktion** - keine Spam-Mails während Tests

**Produktiv-Modus aktivieren (nach erfolgreichen Tests):**

```php
const DEBUG_EMAIL_NOTIFICATIONS = false;  // Echte E-Mails
```

**Empfohlener Workflow:**
1. Erste 1-2 Wochen: Debug-Modus aktiv lassen
2. Logs überwachen: `tail -f /var/www/StudOn/data/studon/ilias.log | grep notification`
3. User-Feedback sammeln
4. Optional: Debug-Modus deaktivieren

**Weitere Informationen:**
- [tests/NOTIFICATION_TEST_GUIDE.md](tests/NOTIFICATION_TEST_GUIDE.md) - Ausführliche Dokumentation
- [tests/MODAL_TEST_GUIDE.md](tests/MODAL_TEST_GUIDE.md) - Test-Guide

## Tests

Das Plugin verfügt über umfassende automatisierte Tests für Qualitätssicherung.

### Integration Tests

**Vollautomatisierte Tests** des gesamten Multi-Feedback Workflows:
- ✅ Individual & Team Assignments
- ✅ Download → Upload Workflow
- ✅ Status-File Verarbeitung (XLSX + CSV)
- ✅ Checksum-basierte Datei-Umbenennung
- ✅ **E-Mail Benachrichtigungen** (Team + Individual) ✨ NEU
- ✅ Negative Tests (Error Handling)
- ✅ Performance-Optimierungen

**Tests ausführen:**

**Option 1: Modal in ILIAS UI** (Empfohlen)
1. Als Admin einloggen
2. Übung öffnen → "Abgaben und Noten"
3. Gelber Button **"🧪 Run Tests"** klicken
4. Tests laufen automatisch im Modal

**Option 2: CLI**
```bash
cd tests/integration/
php run-all-tests.php --parent-ref=12345
```

**Option 3: Web Interface**
```
Browser: /Customizing/.../ExerciseStatusFile/tests/integration/web-runner.php
```

**Test-Ergebnisse (v1.2.0):**
- ✅ **12/12 Tests bestanden**
- ⏱️ Dauer: ~9 Sekunden
- 🧹 Automatisches Cleanup

**Features:**
- 🎯 **Parent RefID Support:** Tests erstellen Objekte in eigenem Ordner (nicht Root!)
- 🧹 **Auto-Cleanup:** Test-Daten werden automatisch aufgeräumt
- 📊 **12 Test-Szenarien:** Individual, Team, CSV, Notifications, Negative Tests
- 📧 **Notification-Tests im Debug-Modus** (keine echten E-Mails)
- ⚡ **Schnell:** ~9 Sekunden für alle Tests

**Wichtig für Admins:**
- Siehe [tests/MODAL_TEST_GUIDE.md](tests/MODAL_TEST_GUIDE.md) für Modal-Anleitung
- Siehe [docs/ADMIN_GUIDE_TESTS.md](docs/ADMIN_GUIDE_TESTS.md) für detaillierte Dokumentation
- **Immer Parent RefID setzen!** (z.B. Test-Ordner RefID)
- Tests sind sicher und löschen alle temporären Daten

### Smoke Tests

Grundlegende Struktur- und Syntax-Tests:

```bash
cd tests/
php smoke-test.php
```

**Was wird getestet:**
- ✅ Dateistruktur (Plugin-Dateien vorhanden)
- ✅ PHP-Syntax (keine Syntax-Fehler)
- ✅ Klassen-Struktur (erforderliche Methoden vorhanden)
- ✅ Security-Features (Path Traversal Prevention)

### Manuelle Tests

Für umfassende Funktionstests siehe [tests/MANUAL_TEST_GUIDE.md](tests/MANUAL_TEST_GUIDE.md).

Diese beinhalten:
- Individual und Team Assignments
- Feedback Upload mit/ohne Status-Updates
- Security-Tests (Path Traversal)
- Performance-Tests (große Dateien)

## Support

- GitHub: https://github.com/comusielak/ExerciseStatusFile
- Issues: https://github.com/comusielak/ExerciseStatusFile/issues

## Lizenz

GPL-3.0

## Version

**Aktuelle Version:** 1.2.0 - 2025-01-04

### Changelog

Siehe [CHANGELOG.md](CHANGELOG.md) für vollständige Version-Historie.

**Highlights v1.2.0:**
- ✅ **E-Mail Benachrichtigungen:** Automatische Notifications beim Feedback-Upload
- ✅ **Debug-Modus:** Sichere Tests ohne echte E-Mails
- ✅ **Performance:** Batch-Loading für Team-Daten (~10x schneller)
- ✅ **Tests:** 12/12 Integration Tests bestanden in 8.93s
- ✅ **Modal-Integration:** "🧪 Run Tests" Button in ILIAS UI
- 📚 **Dokumentation:** Umfassende Guides für Notifications und Tests
