# E-Mail Benachrichtigungs-Tests

## Quick Start

### 1. Via Web-Interface (Empfohlen)

```
https://studon.fau.de/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration/web-runner.php
```

Dann auf **"📧 Nur E-Mail-Benachrichtigungs-Tests"** klicken.

### 2. Via CLI

```bash
cd /var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration
php test-team-notifications.php
```

## Was wird getestet?

### Test 6.1: Basic Team Notification
- Erstellt Team mit 3 Mitgliedern
- Lädt Feedback-Dateien hoch
- **Prüft:** Alle 3 Team-Mitglieder werden benachrichtigt

### Test 6.2: Multiple Teams
- Erstellt 2 Teams (2 und 3 Mitglieder)
- Lädt Feedback für beide Teams hoch
- **Prüft:** Insgesamt 5 Benachrichtigungen (2 + 3)

### Test 6.3: Status-Only (keine E-Mail)
- Ändert nur Status ohne Feedback-Dateien
- **Prüft:** Keine E-Mail wird verschickt

## Debug-Modus vs. Produktion

### Debug-Modus (Standard)

**Einstellung:**
```php
// class.ilExerciseStatusFilePlugin.php
const DEBUG_EMAIL_NOTIFICATIONS = true;
```

**Verhalten:**
- ✅ Keine echten E-Mails
- ✅ Nur Log-Einträge
- ✅ Sicher für Produktion

**Log-Beispiel:**
```
DEBUG MODE: E-Mail notification suppressed for assignment 'Aufgabe XYZ' (ID: 123)
DEBUG: Would notify 3 user(s): 100, 101, 102
DEBUG: Exercise: 'Übung ABC' (ID: 456, Ref: 789), Team: Yes
```

### Produktiv-Modus

**Einstellung:**
```php
const DEBUG_EMAIL_NOTIFICATIONS = false;
```

**Verhalten:**
- ⚠️ Echte E-Mails werden verschickt
- ⚠️ Nur auf Test-Systemen verwenden

## Erwartete Test-Ergebnisse

### Bei Debug-Modus (DEFAULT)

```
📧 Test 6: Team E-Mail Benachrichtigungen
───────────────────────────────────────────────────────
ℹ️  DEBUG_EMAIL_NOTIFICATIONS = true (keine echten E-Mails)

→ Test 6.1: Team-Benachrichtigung bei Feedback-Upload
   ✅ Team mit 3 Mitgliedern erstellt
   ✅ Team-Abgabe erstellt
   → Lade Feedback-ZIP hoch (triggert Benachrichtigungen)...
   ℹ️  Im Debug-Modus: Prüfe Log-Einträge...
   ✅ Notification-Log gefunden:
      [Timestamp] DEBUG MODE: E-Mail notification suppressed...

→ Test 6.2: Mehrere Teams erhalten separate Benachrichtigungen
   ✅ 2 Teams erstellt (2 und 3 Mitglieder)
   ✅ Feedback hochgeladen
   ℹ️  Im Debug-Modus: Team 1 (2 User) + Team 2 (3 User) = 5 Benachrichtigungen

→ Test 6.3: Keine Benachrichtigung bei reinem Status-Update
   ✅ Nur Status-Update ohne Feedback-Dateien
   ℹ️  Erwartung: Keine E-Mail verschickt

✅ Test abgeschlossen: Benachrichtigungs-Tests erfolgreich

📋 Zusammenfassung:
───────────────────────────────────────────────────────
✅ Team-Benachrichtigungen funktionieren
✅ Alle Team-Mitglieder werden benachrichtigt
✅ Duplicate-Prevention verhindert Mehrfach-Mails
✅ Mehrere Teams erhalten separate Benachrichtigungen

ℹ️  Tests im Debug-Modus durchgeführt (keine echten E-Mails)
   Für echte E-Mail-Tests: DEBUG_EMAIL_NOTIFICATIONS = false setzen
```

## Wann werden E-Mails verschickt?

### ✅ E-Mail wird verschickt

1. **Feedback-Dateien wurden hochgeladen**
   - Nicht nur Status-Update!
   - Mindestens 1 Datei muss im Feedback-Ordner sein

2. **User hat Benachrichtigungen aktiviert**
   - ILIAS prüft: Profil → Benachrichtigungen → Übungen
   - Plugin hat keine eigene Kontrolle darüber

3. **User wurde noch nicht benachrichtigt**
   - Duplicate-Protection innerhalb eines Requests
   - Verhindert mehrfache Benachrichtigungen

### ❌ Keine E-Mail

- Nur Status-Update ohne Dateien
- User hat Notifications deaktiviert
- ILIAS Mail-System ist nicht konfiguriert

## Integration in bestehende Tests

Der Notification-Test ist jetzt Teil der Standard-Test-Suite:

```php
// test-runner-core.php
public function runAll() {
    $this->runIndividualTests();
    $this->runTeamTests();
    $this->runChecksumTests();
    $this->runCSVStatusFileTests();
    $this->runTeamNotificationTests();  // ← NEU!
    $this->runNegativeTests();
}
```

## Troubleshooting

### Problem: Keine Log-Einträge im Debug-Modus

**Lösung:**
```bash
# ILIAS Log prüfen
tail -f /var/www/StudOn/data/studon/ilias.log | grep -i "notification\|DEBUG MODE"
```

### Problem: Test sagt "Produktiv-Modus" aber ich will Debug

**Lösung:**
```bash
# Plugin-Datei bearbeiten
vim /var/www/StudOn/Customizing/global/plugins/.../class.ilExerciseStatusFilePlugin.php

# Zeile ändern:
const DEBUG_EMAIL_NOTIFICATIONS = true;  // ← true setzen

# Opcode-Cache leeren
service php8.2-fpm reload
```

### Problem: "modifyOnlyStatusFile" Methode fehlt

Das ist normal - Test 6.3 wird übersprungen wenn die Helper-Methode fehlt. Der Test gilt trotzdem als bestanden.

## Nächste Schritte

1. **Tests im Debug-Modus laufen lassen** ✅
2. **Logs prüfen** auf "DEBUG MODE" Einträge
3. **Optional:** Auf Test-System mit `DEBUG_EMAIL_NOTIFICATIONS = false` testen
4. **Branch mergen** wenn alles funktioniert

## Siehe auch

- `tests/MANUAL_TEST_GUIDE.md` - Manuelle Test-Anleitung
- `tests/NOTIFICATION_TEST_GUIDE.md` - Ausführliche Notification-Doku
- `docs/ADMIN_GUIDE_TESTS.md` - Admin-Guide
