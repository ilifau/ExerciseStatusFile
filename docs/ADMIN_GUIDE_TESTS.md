# Admin Guide: Integration Tests

## 🎯 Für wen ist dieser Guide?

Dieser Guide ist für **ILIAS-Administratoren**, die im System einen neuen Button "Run Tests" sehen und wissen möchten:
- Was macht dieser Button?
- Ist das sicher?
- Soll ich den verwenden?
- Was passiert mit meinem System?

## ❓ Was sind diese Tests?

Die Integration Tests sind **automatisierte Qualitätsprüfungen** für das ExerciseStatusFile-Plugin. Sie simulieren den kompletten Workflow:
1. Erstellen von Test-Übungen
2. Erstellen von Test-Usern und Teams
3. Hochladen von Abgaben
4. Download des Multi-Feedback ZIPs
5. Upload von korrigiertem Feedback
6. Prüfung ob alles korrekt funktioniert

## ✅ Ist das sicher?

**JA, absolut sicher!** Die Tests:
- ✅ Erstellen nur temporäre Test-Objekte mit Präfix `TEST_`
- ✅ Löschen alle Test-Daten automatisch nach Beendigung
- ✅ Beeinflussen KEINE echten Kurse, User oder Daten
- ✅ Laufen isoliert in einem eigenen Bereich
- ✅ Haben keinen Zugriff auf echte Produktiv-Daten

**ABER:** Empfehlung für Produktiv-Systeme siehe unten!

## 📋 Wann sollte ich Tests ausführen?

**Empfohlene Szenarien:**

1. **Nach Plugin-Update**
   - Stellen Sie sicher, dass das Update funktioniert
   - Führen Sie Tests vor Freigabe für Tutoren aus

2. **Vor wichtigen Prüfungsphasen**
   - Validieren Sie, dass Multi-Feedback funktioniert
   - Rechtzeitig vor kritischen Deadlines

3. **Nach ILIAS-Update**
   - Prüfen Sie Plugin-Kompatibilität
   - Vor allem bei Major-Updates (ILIAS 8 → 9)

4. **Bei Verdacht auf Probleme**
   - Tutor meldet Fehler → Tests zeigen ob Problem reproduzierbar ist
   - Systematische Fehlersuche

**NICHT ausführen bei:**
- ❌ Hoher System-Last (Tests dauern ~30 Sekunden)
- ❌ Während aktiver Prüfungen
- ❌ "Einfach mal so" ohne Grund

## 🚀 Wie führe ich Tests aus?

### Option 1: Web Interface (Einfach)

**URL:** `https://ihr-ilias.de/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration/web-runner.php`

**Schritte:**

1. **Test-Ordner erstellen (WICHTIG!):**
   - Gehen Sie in ILIAS zu einem Magazin-Bereich
   - Erstellen Sie einen Ordner "Integration Tests" oder "Plugin Tests"
   - Notieren Sie die RefID (z.B. aus der URL: `ref_id=12345`)

2. **Tests ausführen:**
   - Öffnen Sie die Test-URL im Browser
   - Geben Sie die RefID in das Feld "Parent Category" ein
   - Klicken Sie "▶️ Alle Tests ausführen"
   - Warten Sie ~30 Sekunden
   - Prüfen Sie das Ergebnis

3. **Ergebnis prüfen:**
   ```
   ✅ Test 1.1: Individual Upload - PASSED
   ✅ Test 1.2: Team Upload - PASSED
   ...
   ═══════════════════════════════════════
   ✅ ALL TESTS PASSED!
   Tests: 15 | Passed: 15 | Failed: 0
   ═══════════════════════════════════════
   ```

4. **Cleanup (falls nötig):**
   - Normalerweise automatisch
   - Bei Abbruch: Button "🗑️ Test-Daten aufräumen" klicken

### Option 2: Command Line (Für Tech-Admins)

```bash
cd /var/www/StudOn/Customizing/.../ExerciseStatusFile/tests/integration/

# Tests ausführen (mit Parent RefID)
php run-all-tests.php --parent-ref=12345

# Cleanup (falls Tests abgebrochen wurden)
php run-all-tests.php --cleanup-only
```

## ⚙️ Best Practices für Admins

### 1. IMMER Parent RefID verwenden!

**❌ FALSCH:**
```
Parent Category: 1 (Root)
```
→ Test-Übungen landen im Root-Ordner → unübersichtlich!

**✅ RICHTIG:**
```
Parent Category: 12345 (Ihr Test-Ordner)
```
→ Alle Tests in eigenem Ordner → sauber organisiert!

### 2. Produktiv-System vs. Test-System

**Test-System / Staging:**
- ✅ Jederzeit Tests ausführen
- ✅ Auch auf Root-Ebene (egal)
- ✅ Für Entwickler zum Testen

**Produktiv-System:**
- ⚠️ Nur bei Bedarf ausführen
- ⚠️ IMMER Parent RefID setzen
- ⚠️ Nicht während Stoßzeiten
- ⚠️ Vorher Backup empfohlen (Best Practice)

### 3. Was tun bei Fehlern?

**Test schlägt fehl:**
```
❌ Test 2.1: Team Upload - FAILED
Error: Could not create team assignment
```

**Aktionen:**
1. Screenshot machen
2. Cleanup durchführen (Button oder `--cleanup-only`)
3. Entwickler kontaktieren mit Fehlermeldung
4. Nicht mehrfach wiederholen (macht es nur schlimmer)

**Test-Daten bleiben zurück:**
```
Hinweis: 3 Test-Objekte gefunden
```

**Aktionen:**
1. Button "🗑️ Test-Daten aufräumen" klicken
2. ODER: `php run-all-tests.php --cleanup-only`
3. Im ILIAS prüfen ob Objekte weg sind
4. Notfalls manuell löschen (Präfix `TEST_`)

## 📊 Was wird getestet?

### Funktionale Tests

1. **Individual Assignments:**
   - Download von Abgaben
   - Upload von Feedback
   - Status-File Verarbeitung (Excel + CSV)
   - Datei-Umbenennung (`_korrigiert` Suffix)

2. **Team Assignments:**
   - Team-Download mit Ordner-Struktur
   - Team-Feedback Upload
   - Multi-User Status-Updates

3. **Checksum Detection:**
   - Geänderte Dateien erkennen
   - Unveränderte Dateien beibehalten
   - Checksums.json korrekt auswerten

4. **Negative Tests (Error Handling):**
   - Ungültige Status-Werte → Fehler korrekt abfangen
   - Leere Dateien → Nicht abstürzen
   - Falsche ZIP-Struktur → Sinnvolle Fehlermeldung
   - Korrupte ZIPs → Graceful degradation

### Performance Tests

- Batch-Loading von Team-Daten
- Optimierte DB-Queries
- Checksum-Caching

## 🛡️ Sicherheits-Features

**Was wird NICHT getestet:**
- ❌ Echte User-Daten
- ❌ Echte Kurse/Übungen
- ❌ Produktiv-Abgaben
- ❌ Bewertungen von echten Studenten

**Was passiert mit Test-Daten:**
- Alle Objekte haben Präfix `TEST_`
- Werden automatisch gelöscht
- Keine Spuren in echten Kursen

**Berechtigungen:**
- Tests laufen mit Admin-Rechten
- Erstellen temporäre Objekte
- Kein Zugriff auf fremde Daten

## ❓ FAQ

### "Ich sehe den Button in ILIAS - soll ich draufklicken?"

**Nein, nicht einfach so!** Der Button ist für Admins/Entwickler. Wenn Sie nicht wissen wofür er da ist, kontaktieren Sie Ihren Admin.

### "Tests dauern ewig - ist das normal?"

**Ja!** Tests dauern ~30 Sekunden. Bei langsamen Systemen auch länger. Einfach warten.

### "Test-Objekte sind noch da - warum?"

Mögliche Gründe:
1. Browser-Tab geschlossen während Tests liefen
2. Timeout wegen langsamen Server
3. Fehler während Cleanup

**Lösung:** Cleanup-Button verwenden oder manuell löschen.

### "Kann ich Tests in Production ausführen?"

**Technisch ja, empfohlen nein.**

Besser:
1. Test-System / Staging verwenden
2. Bei Bedarf in Production: Parent RefID setzen!
3. Außerhalb der Stoßzeiten
4. Backup vorher (Best Practice)

### "Was kostet das Performance-mäßig?"

**Während der Tests:**
- CPU: Medium (ZIP-Generierung, Checksums)
- DB: Medium (~50 Queries für 15 Tests)
- Disk I/O: Low (kleine Test-Dateien)
- Dauer: ~30 Sekunden

**Nach den Tests:**
- Keine Auswirkung (alles gelöscht)

### "Werden echte User benachrichtigt?"

**Nein!** Test-User sind temporär und bekommen keine E-Mails.

### "Kann ich Tests automatisieren?"

**Ja!** Für CI/CD:

```bash
# GitLab CI / Jenkins / etc.
php run-all-tests.php --parent-ref=12345
```

Exit Codes:
- `0` = Alle Tests bestanden
- `1` = Tests fehlgeschlagen
- `2` = Fatal Error

## 📞 Support

**Bei Problemen:**
1. Screenshot machen
2. Fehlermeldung kopieren
3. Entwickler kontaktieren
4. GitHub Issue erstellen: [Link]

**Häufige Probleme & Lösungen:**
- "Permission denied" → PHP-Berechtigungen prüfen
- "Cannot create directory" → Disk Space prüfen
- "Database error" → DB-Verbindung prüfen
- "Timeout" → PHP max_execution_time erhöhen

## 📚 Weiterführende Dokumentation

- [Integration Tests Doku](../ki_infos/integration_tests.md) - Technische Details
- [README.md](../tests/integration/README.md) - Test-Framework Dokumentation
- [QUICKSTART.md](../tests/integration/QUICKSTART.md) - Schnelleinstieg für Entwickler

## ✅ Zusammenfassung

**Für Admins gilt:**

1. ✅ Tests sind sicher und hilfreich
2. ✅ IMMER Parent RefID setzen (nicht Root!)
3. ✅ Nach Updates/Problemen ausführen
4. ✅ Cleanup durchführen falls nötig
5. ⚠️ Nicht während Stoßzeiten in Production

**Bei Unsicherheit:** Entwickler fragen! 😊
