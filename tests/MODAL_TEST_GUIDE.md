# Modal Test Guide - "🧪 Run Tests" Button

## Quick Start

### 1. Test-Modal öffnen

1. **Als Admin einloggen** in ILIAS
2. **Gehe zu einer beliebigen Übung** (Exercise) mit einer Aufgabe
3. **Klicke auf "Abgaben und Noten"**
4. **Klicke auf den gelben Button "🧪 Run Tests"**

Das Modal öffnet sich mit Test-Optionen.

### 2. Tests ausführen

**Empfohlene Einstellungen:**
- ✅ **Mit Cleanup** (Test-Daten werden gelöscht)
- **Parent Ref-ID:** `1` (oder eine Kategorie deiner Wahl)

**Dann:** Klicke auf **"▶️ Tests starten"**

### 3. Ausgabe prüfen

Du siehst die Live-Ausgabe der Tests im Modal:

```
📧 Test 6: E-Mail Benachrichtigungen (Team + Individual)
───────────────────────────────────────────────────────
✅ DEBUG_EMAIL_NOTIFICATIONS = true (keine echten E-Mails)
   Alle Notifications werden nur geloggt

→ Test 6.1: Team-Benachrichtigung bei Feedback-Upload
   ✅ Team mit 3 Mitgliedern erstellt
   ✅ Team-Abgabe erstellt
   → Lade Feedback-ZIP hoch (triggert Benachrichtigungen)...
   ℹ️  Im Debug-Modus: Prüfe Log-Einträge...

→ Test 6.2: Mehrere Teams erhalten separate Benachrichtigungen
   ✅ 2 Teams erstellt (2 und 3 Mitglieder)
   ✅ Feedback hochgeladen
   ℹ️  Im Debug-Modus: Team 1 (2 User) + Team 2 (3 User) = 5 Benachrichtigungen

→ Test 6.3: Individual-Benachrichtigung bei Feedback-Upload
   ✅ 3 Individual-Abgaben erstellt
   → Lade Individual-Feedback hoch (triggert Benachrichtigungen)...
   ℹ️  Im Debug-Modus: 3 Individual-Benachrichtigungen

✅ Test abgeschlossen: Benachrichtigungs-Tests erfolgreich

📋 Zusammenfassung:
───────────────────────────────────────────────────────
✅ Team-Benachrichtigungen funktionieren
✅ Alle Team-Mitglieder werden benachrichtigt
✅ Individual-Benachrichtigungen funktionieren
✅ Duplicate-Prevention verhindert Mehrfach-Mails
✅ Mehrere Teams erhalten separate Benachrichtigungen

ℹ️  Tests im Debug-Modus durchgeführt (keine echten E-Mails)
   Für echte E-Mail-Tests: DEBUG_EMAIL_NOTIFICATIONS = false setzen
```

## Was wird getestet?

### Test 6.1: Team-Benachrichtigung
- Team mit 3 Mitgliedern
- Feedback-Upload triggert Benachrichtigungen
- **Erwartet:** Alle 3 Mitglieder werden benachrichtigt

### Test 6.2: Mehrere Teams
- 2 Teams (2 und 3 Mitglieder)
- Feedback für beide Teams
- **Erwartet:** 5 separate Benachrichtigungen (2 + 3)

### Test 6.3: Individual-Benachrichtigungen
- 3 Individual-Abgaben
- Feedback-Upload
- **Erwartet:** 3 separate Benachrichtigungen (1 pro User)

## Debug-Modus

### ✅ Aktuell: DEBUG_EMAIL_NOTIFICATIONS = true

**Das bedeutet:**
- ✅ Keine echten E-Mails werden verschickt
- ✅ Nur Log-Einträge in `/var/www/StudOn/data/studon/ilias.log`
- ✅ Sicher für Produktion

**Log-Einträge prüfen:**
```bash
tail -f /var/www/StudOn/data/studon/ilias.log | grep -i "DEBUG MODE\|notification"
```

**Erwartete Log-Einträge:**
```
DEBUG MODE: E-Mail notification suppressed for assignment 'Test Assignment'
DEBUG: Would notify 3 user(s): 100, 101, 102
DEBUG: Exercise: 'Test Exercise' (ID: 456, Ref: 789), Team: Yes
```

### ⚠️ Produktiv-Modus: DEBUG_EMAIL_NOTIFICATIONS = false

**Nur für Test-Systeme!**

Wenn du echte E-Mails testen willst:
1. Setze `DEBUG_EMAIL_NOTIFICATIONS = false` in `class.ilExerciseStatusFilePlugin.php`
2. **ACHTUNG:** Tests verschicken dann echte E-Mails an Test-User
3. Stelle sicher, dass Test-User gültige E-Mail-Adressen haben

## Vorteile des Modals

✅ **Läuft direkt in ILIAS** - Keine Permission-Probleme
✅ **Live-Ausgabe** - Siehst den Fortschritt in Echtzeit
✅ **Admin-only** - Nur für Admins sichtbar
✅ **Flexible Optionen** - Mit/ohne Cleanup, verschiedene Parent-Kategorien
✅ **Alle Tests integriert** - Individual, Team, Checksum, CSV, **und Notifications!**

## Unterschied zum Web-Runner

| Feature | Modal (🧪 Run Tests) | Web-Runner (`web-runner.php`) |
|---------|---------------------|-------------------------------|
| **Zugriff** | In ILIAS UI | Direkter URL-Aufruf |
| **Permissions** | ✅ Funktioniert immer | ⚠️ ILIAS blockiert möglicherweise |
| **Integration** | ✅ Native ILIAS | ⚠️ Externes Script |
| **Live-Output** | ✅ Streaming | ✅ Streaming |
| **Empfohlen** | **JA** | Nur als Fallback |

## Troubleshooting

### Problem: "🧪 Run Tests" Button nicht sichtbar

**Ursache:** Du bist nicht als Admin eingeloggt

**Lösung:**
- Einloggen als Admin-User
- Prüfe Rechte: Administration → Benutzer & Rollen

### Problem: Modal zeigt "Insufficient permissions"

**Ursache:** Keine Admin-Rechte

**Lösung:**
- Rolle: Administrator
- Oder: System-Ordner-Zugriffsrecht

### Problem: Tests zeigen "⚠️ DEBUG_EMAIL_NOTIFICATIONS = false"

**Das ist korrekt wenn:**
- Du absichtlich echte E-Mails testen willst
- Du auf einem Test-System bist

**Für normale Tests:**
```bash
vim classes/class.ilExerciseStatusFilePlugin.php

# Ändere Zeile 17:
const DEBUG_EMAIL_NOTIFICATIONS = true;  // ← true = sicher
```

### Problem: Keine Notification-Logs sichtbar

**Prüfe:**
1. Ist `DEBUG_EMAIL_NOTIFICATIONS = true`?
2. Wurden Feedback-**Dateien** hochgeladen? (Nicht nur Status)
3. ILIAS-Log prüfen:
   ```bash
   tail -100 /var/www/StudOn/data/studon/ilias.log | grep -i notification
   ```

## Nächste Schritte

1. **Tests im Modal ausführen** ✅
2. **Logs prüfen** (bei Debug-Modus)
3. **Alle Tests erfolgreich?** → Branch kann gemerged werden! 🎉

## Siehe auch

- `tests/NOTIFICATION_TEST_GUIDE.md` - Ausführliche Notification-Doku
- `tests/integration/NOTIFICATION_TESTING.md` - Quick Start für CLI/Web
- `tests/MANUAL_TEST_GUIDE.md` - Manuelle Test-Anleitung
- `docs/ADMIN_GUIDE_TESTS.md` - Admin-Guide
