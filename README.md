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

## Tests

Das Plugin verfügt über umfassende automatisierte Tests für Qualitätssicherung.

### Integration Tests (NEU!)

**Vollautomatisierte Tests** des gesamten Multi-Feedback Workflows:
- ✅ Individual & Team Assignments
- ✅ Download → Upload Workflow
- ✅ Status-File Verarbeitung (XLSX + CSV)
- ✅ Checksum-basierte Datei-Umbenennung
- ✅ Negative Tests (Error Handling)
- ✅ Performance-Optimierungen

**Tests ausführen:**

```bash
cd tests/integration/

# CLI (empfohlen)
php run-all-tests.php --parent-ref=12345

# Web Interface
# Browser: /Customizing/.../ExerciseStatusFile/tests/integration/web-runner.php
```

**Features:**
- 🎯 **Parent RefID Support:** Tests erstellen Objekte in eigenem Ordner (nicht Root!)
- 🧹 **Auto-Cleanup:** Test-Daten werden automatisch aufgeräumt
- 📊 **15+ Test-Szenarien:** Individual, Team, CSV, Negative Tests
- ⚡ **Schnell:** ~30 Sekunden für alle Tests

**Wichtig für Admins:**
- Siehe [docs/ADMIN_GUIDE_TESTS.md](docs/ADMIN_GUIDE_TESTS.md) für detaillierte Anleitung
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

1.3.0 - 2025-01-30

### Changelog

**1.3.0** (2025-01-30)
- ✅ **Integration Tests:** Vollautomatisiertes Test-Framework mit 15+ Szenarien
- ✅ **Negative Tests:** Error Handling Tests für robusteren Code
- ✅ **CSV Status-File Support:** Tests für CSV zusätzlich zu Excel
- ✅ **Parent RefID:** Tests können in eigenem Ordner erstellt werden (nicht Root)
- ✅ **Checksum-basierte Status-File Auswahl:** Intelligente Erkennung welches File verwendet werden soll
- ✅ **Performance:** Optimiertes Batch-Loading von Team-Daten
- ✅ **Code-Cleanup:** Entfernte Debug-Logs, optimierte Methoden
- 📚 **Dokumentation:** Admin Guide für Tests, KI-Infos für Entwickler

**1.2.0** (2025-01-20)
- Feedback-Upload ohne Status-Updates möglich
- Verbesserte Team-ID Erkennung
- Optimierte Performance (weniger DB-Abfragen)
- Bug-Fix: ZIP-Validierung jetzt optional

**1.1.0**
- Multi-Feedback Upload für Teams
- Automatische Filterung von Submissions

**1.0.0**
- Initial Release
- Status-File Export/Import
- Multi-Feedback Download
