# Manual Testing Guide - Multi-Feedback Workflow

Dieser Guide hilft dir, den kompletten Multi-Feedback Workflow manuell zu testen.

## Warum manuelles Testen?

Die automatisierten Tests sind komplex wegen ILIAS CLI/Session-Handling. Dieser manuelle Workflow ist:
- ✅ Schnell durchführbar (5-10 Minuten)
- ✅ Deckt alle kritischen Funktionen ab
- ✅ Einfach zu wiederholen
- ✅ Zeigt dir genau was funktioniert

---

## Test 1: Individual Assignment - Basis-Workflow

### Setup (2 Minuten)

1. **Erstelle Übung:**
   - Repository → Neues Objekt → Übung
   - Titel: `TEST_MultiFeedback_Individual`

2. **Erstelle Assignment:**
   - In der Übung → Neues Assignment
   - Typ: **Upload (Individual)**
   - Titel: `TEST_Upload_Individual`
   - Abgabefrist: In 1 Woche

3. **Erstelle 3 Test-User** (oder nutze existierende):
   - User 1: `test_student_1`
   - User 2: `test_student_2`
   - User 3: `test_student_3`
   - Füge alle zur Übung hinzu

4. **Erstelle Abgaben:**
   - Logge dich als User 1 ein
   - Gehe zur Übung → Assignment
   - Lade eine Datei hoch: `hausaufgabe.txt`
   - Inhalt: `Das ist meine Lösung - Student 1`
   - Wiederhole für User 2 und 3

### Test-Durchführung (3 Minuten)

5. **Download Multi-Feedback ZIP:**
   - Logge dich als Tutor/Admin ein
   - Gehe zur Übung → Assignment
   - Klicke **Multi-Feedback Button** (sollte mit 0ms erscheinen!)
   - Warte auf ZIP-Download
   - **✅ Erwartung:** ZIP wird heruntergeladen

6. **Entpacke und prüfe Struktur:**
   ```
   feedback_zip/
   ├── test_student_1/
   │   ├── hausaufgabe.txt
   │   └── feedback.txt
   ├── test_student_2/
   │   ├── hausaufgabe.txt
   │   └── feedback.txt
   └── test_student_3/
       ├── hausaufgabe.txt
       └── feedback.txt
   ```
   - **✅ Erwartung:** Ordner-Struktur korrekt

7. **Ändere Dateien (simuliere Korrekturen):**
   - Öffne `test_student_1/hausaufgabe.txt`
   - **ÄNDERE** den Inhalt: `KORRIGIERT: Das ist jetzt richtig!`
   - Speichere die Datei
   - **WICHTIG:** Ändere NUR die Datei von User 1!
   - User 2 und 3 bleiben unverändert

8. **Erstelle neues ZIP:**
   - Markiere alle 3 User-Ordner
   - Erstelle ZIP: `feedback_modified.zip`

9. **Upload geändertes ZIP:**
   - Gehe zurück zur Übung (als Tutor)
   - Upload das `feedback_modified.zip`
   - Warte auf Verarbeitung
   - **✅ Erwartung:** "Upload erfolgreich" Message

10. **Verifiziere Ergebnisse:**
    - Gehe zu Assignment → Submissions
    - Prüfe User 1:
      - **✅ Erwartung:** Datei heißt jetzt `hausaufgabe_korrigiert.txt`
      - **✅ Erwartung:** Inhalt ist geändert
    - Prüfe User 2 & 3:
      - **✅ Erwartung:** Dateien heißen noch `hausaufgabe.txt` (OHNE _korrigiert!)
      - **✅ Erwartung:** Inhalt unverändert

### ✅ Test 1 Checkliste

- [ ] Multi-Feedback Button erscheint sofort (0ms)
- [ ] ZIP-Download funktioniert
- [ ] ZIP-Struktur korrekt
- [ ] Upload funktioniert
- [ ] Geänderte Datei hat `_korrigiert` Suffix
- [ ] Unveränderte Dateien behalten Original-Namen
- [ ] Checksum-Detection funktioniert

---

## Test 2: Team Assignment - Team-Workflow

### Setup (2 Minuten)

1. **Erstelle Team-Übung:**
   - Repository → Neues Objekt → Übung
   - Titel: `TEST_MultiFeedback_Team`

2. **Erstelle Team-Assignment:**
   - Typ: **Upload (Team)**
   - Titel: `TEST_Upload_Team`
   - Min Team Size: 2
   - Max Team Size: 3

3. **Erstelle 6 Test-User** (oder nutze existierende)

4. **Erstelle 2 Teams:**
   - Team 1: "Gruppe A" (User 1, 2, 3)
   - Team 2: "Gruppe B" (User 4, 5, 6)

5. **Erstelle Team-Abgaben:**
   - Als User 1 (Team 1): Lade `team_bericht.pdf` hoch
   - Als User 4 (Team 2): Lade `team_bericht.pdf` hoch

### Test-Durchführung (3 Minuten)

6. **Download Multi-Feedback ZIP:**
   - Klicke Multi-Feedback Button
   - **✅ Erwartung:** ZIP mit 2 Team-Ordnern

7. **Prüfe Struktur:**
   ```
   feedback_zip/
   ├── Gruppe_A/
   │   ├── team_bericht.pdf
   │   └── feedback.txt
   └── Gruppe_B/
       ├── team_bericht.pdf
       └── feedback.txt
   ```

8. **Ändere Team 1 Datei:**
   - Ändere `Gruppe_A/team_bericht.pdf`
   - Lasse Team 2 unverändert

9. **Upload geändertes ZIP**

10. **Verifiziere:**
    - Team 1: `team_bericht_korrigiert.pdf`
    - Team 2: `team_bericht.pdf` (unverändert)

### ✅ Test 2 Checkliste

- [ ] Team-ZIP enthält Team-Namen als Ordner
- [ ] Team-Abgaben korrekt zugeordnet
- [ ] Geänderte Team-Datei hat `_korrigiert` Suffix
- [ ] Unveränderte Team-Datei behält Original-Namen

---

## Test 3: Verschiedene Dateitypen

### Setup

1. Erstelle Assignment mit Multiple File Types
2. Lade verschiedene Dateitypen hoch:
   - `code.php` (Text)
   - `solution.txt` (Text)
   - `diagram.png` (Binär)
   - `report.pdf` (Binär)

### Test

3. Download Multi-Feedback ZIP
4. Ändere `code.php` und `solution.txt`
5. Lasse `diagram.png` und `report.pdf` unverändert
6. Upload ZIP

### Erwartung

- `code_korrigiert.php` ✅
- `solution_korrigiert.txt` ✅
- `diagram.png` (unverändert) ✅
- `report.pdf` (unverändert) ✅

### ✅ Test 3 Checkliste

- [ ] Text-Dateien korrekt erkannt
- [ ] Binär-Dateien korrekt behandelt
- [ ] Alle Dateitypen funktionieren

---

## Test 4: Performance Test (Optional)

### Setup

1. Erstelle Assignment mit vielen Submissions (20-50 User)

### Test

2. Klicke Multi-Feedback Button
3. **Messe Zeit:**
   - Button erscheint: **< 100ms** ✅
   - ZIP generiert: **< 30 Sekunden** ✅
   - Modal lädt: **< 3 Sekunden** ✅

### ✅ Test 4 Checkliste

- [ ] Button erscheint instant (0ms delay)
- [ ] Große ZIPs generieren in akzeptabler Zeit
- [ ] Keine Timeouts
- [ ] Gzip-Kompression aktiv (check Network Tab)

---

## Häufige Probleme & Lösungen

### Problem: ZIP wird nicht generiert
**Lösung:**
- Prüfe Logs: `/var/log/ilias/ilias.log`
- Prüfe PHP memory_limit
- Prüfe tmp-Verzeichnis Permissions

### Problem: Dateien werden nicht umbenannt
**Lösung:**
- Prüfe ob Datei wirklich geändert wurde
- Prüfe Logs für Checksum-Meldungen
- Verifiziere dass physical rename funktioniert

### Problem: Upload schlägt fehl
**Lösung:**
- Prüfe ZIP-Struktur (muss Original-Struktur haben)
- Prüfe Dateirechte
- Prüfe Upload-Größen-Limit

### Problem: README.md wird nicht umbenannt
**Bekannt:** README.md hat spezielle ILIAS-Behandlung
**Status:** Akzeptabel, andere Dateien funktionieren

---

## Quick Test Checklist (Minimal)

Für schnelle Smoke-Tests nach Code-Änderungen:

1. [ ] Erstelle Übung mit 2 Studenten
2. [ ] Download Multi-Feedback ZIP
3. [ ] Ändere 1 Datei
4. [ ] Upload ZIP
5. [ ] Prüfe: Datei hat `_korrigiert` Suffix

**Zeit:** ~3 Minuten

---

## Test Report Template

Nach dem Testen kannst du diesen Report ausfüllen:

```
# Test Report - [Datum]

## Test 1: Individual Workflow
- [ ] PASS / [ ] FAIL
- Kommentar: ___________

## Test 2: Team Workflow
- [ ] PASS / [ ] FAIL
- Kommentar: ___________

## Test 3: Dateitypen
- [ ] PASS / [ ] FAIL
- Kommentar: ___________

## Test 4: Performance
- [ ] PASS / [ ] FAIL
- Button Delay: ___ms
- ZIP Generation: ___s

## Gefundene Bugs:
1. ___________
2. ___________

## Notizen:
___________
```

---

## Nächste Schritte

Nach erfolgreichem Manual Testing:
- [ ] Teste auf Production-ähnlicher Umgebung
- [ ] Teste mit echten User-Daten (klein)
- [ ] Erstelle Video-Demo für Dokumentation
- [ ] Schreibe User-Guide für Tutoren

---

**Happy Testing! 🎉**

Fragen? Probleme? Check die Logs:
```bash
tail -f /var/log/ilias/ilias.log
```
