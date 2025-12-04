# E-Mail Notification Testing Guide

## Übersicht

Dieses Dokument beschreibt, wie E-Mail-Benachrichtigungen beim Feedback-Upload getestet werden können.

## Wichtige Konzepte

### Debug-Modus

Der Debug-Modus ist in `class.ilExerciseStatusFilePlugin.php` definiert:

```php
const DEBUG_EMAIL_NOTIFICATIONS = false;  // false = Produktion, true = Debug
```

**Debug-Modus aktiviert (`true`):**
- ✅ Keine echten E-Mails werden verschickt
- ✅ Alle Notification-Vorgänge werden ins Log geschrieben
- ✅ Sicher für Tests auf Produktionssystemen

**Produktion (`false`):**
- ⚠️ Echte E-Mails werden über ILIAS verschickt
- ⚠️ Nur für Live-Betrieb verwenden

## Wie Team-Benachrichtigungen funktionieren

### Ablauf bei Team-Feedback-Upload

1. **Tutor lädt Feedback-ZIP hoch** mit Dateien für Teams
2. **Feedback wird verarbeitet:**
   ```
   processTeamSpecificFeedback()
   ├─> Für jedes Team-Mitglied:
   │   ├─> addFeedbackFilesViaResourceStorage() oder
   │   │   addFeedbackFilesViaFilesystem()
   │   └─> sendNotification(assignment_id, member_id, is_team=true)
   │       └─> Holt ALLE Team-Mitglieder via ilExSubmission
   │           └─> Sendet E-Mail an jedes Mitglied (einzeln)
   ```

3. **Duplicate-Protection:**
   - Im `ilExFeedbackNotificationSender` wird ein Array `$notified_users` geführt
   - Verhindert mehrfache Benachrichtigung **innerhalb eines Requests**
   - Bei 3-köpfigem Team: 1. Iteration sendet an alle 3, weitere Iterationen werden übersprungen

### Wann wird eine E-Mail verschickt?

✅ **E-Mail wird verschickt wenn:**
- Feedback-**Dateien** wurden hochgeladen (nicht nur Status-Update)
- User hat Benachrichtigungen in ILIAS aktiviert (wird von ILIAS geprüft)
- User wurde noch nicht in diesem Request benachrichtigt (Duplicate-Protection)

❌ **Keine E-Mail wenn:**
- Nur Status-Update ohne Feedback-Dateien
- User hat Notifications deaktiviert
- User wurde bereits benachrichtigt (gleicher Request)

## Tests ausführen

### 1. Automatischer Test (mit Debug-Modus)

```bash
cd /var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration

# Test für Team-Notifications
php test-team-notifications.php
```

**Was wird getestet:**
- ✅ Alle Team-Mitglieder werden benachrichtigt
- ✅ Mehrere Teams bekommen unabhängige Benachrichtigungen
- ✅ Duplicate-Prevention funktioniert
- ✅ Keine echten E-Mails (Debug-Modus)

### 2. Manueller Test (mit echten E-Mails)

⚠️ **ACHTUNG:** Dieser Test verschickt echte E-Mails!

**Vorbereitung:**
1. Stelle sicher, dass du auf einem **Test-System** bist
2. Setze `DEBUG_EMAIL_NOTIFICATIONS = false`
3. Erstelle Test-User mit gültigen E-Mail-Adressen

**Durchführung:**

```bash
# 1. Erstelle Übung mit Team-Aufgabe
#    - Erstelle 1 Team mit 3 Test-Usern
#    - User sollten Test-E-Mail-Adressen haben (z.B. test1@example.com)

# 2. Team reicht Abgabe ein
#    - Als ein Team-Mitglied anmelden
#    - Datei(en) hochladen

# 3. Tutor lädt Feedback-ZIP herunter
#    - Als Tutor anmelden
#    - "Mehrfach-Feedback herunterladen" klicken

# 4. Tutor modifiziert Feedback
#    - ZIP entpacken
#    - Dateien im exc_teams_XXX/ Ordner ändern
#    - Neue Feedback-Datei hinzufügen (z.B. feedback.txt)
#    - ZIP wieder packen

# 5. Tutor lädt Feedback-ZIP hoch
#    - "Mehrfach-Feedback hochladen" klicken
#    - Modifiziertes ZIP auswählen

# 6. Prüfe E-Mail-Postfächer
#    - JEDES Team-Mitglied sollte GENAU EINE E-Mail bekommen
#    - E-Mail sollte etwa so aussehen:
#      Betreff: "Feedback zu Ihrer Abgabe"
#      Text: "Für Ihre Abgabe in ... wurde Feedback bereitgestellt"
```

**Erwartetes Ergebnis:**
- ✅ 3 E-Mails verschickt (eine pro Team-Mitglied)
- ✅ Jeder User bekommt genau 1 E-Mail
- ✅ E-Mail enthält Link zur Übung

**Log prüfen:**
```bash
# ILIAS Log prüfen
tail -f /var/www/StudOn/data/studon/ilias.log | grep -i "notification\|feedback"

# Erwartete Log-Einträge:
# "Sent 3 feedback notification(s) for assignment 'Aufgabe XYZ'"
```

## Troubleshooting

### Problem: Keine E-Mails werden verschickt

**Mögliche Ursachen:**

1. **Debug-Modus ist aktiv**
   - Lösung: `DEBUG_EMAIL_NOTIFICATIONS = false` setzen

2. **User hat Benachrichtigungen deaktiviert**
   - Prüfen: Profil → Benachrichtigungen → Übungen
   - Lösung: User muss "Feedback zu Abgaben" aktivieren

3. **ILIAS Mail-System nicht konfiguriert**
   - Prüfen: Administration → Kommunikation → Mail
   - Lösung: SMTP-Server konfigurieren

4. **Keine Feedback-Dateien hochgeladen**
   - E-Mails werden NUR bei Feedback-**Dateien** verschickt
   - Status-Updates alleine triggern keine E-Mail

### Problem: Mehrfache E-Mails an gleichen User

**Das sollte nicht passieren!**

Wenn ein User mehrfach benachrichtigt wird:
1. Prüfe ILIAS-Log auf "skipped" Einträge
2. Prüfe ob Duplicate-Protection funktioniert (siehe Code Zeile 102-105 in ilExFeedbackNotificationSender.php)
3. Melde Bug mit genauen Reproduktionsschritten

### Problem: Nur ein Team-Mitglied bekommt E-Mail

**Prüfe:**
1. Haben alle Team-Mitglieder Notifications aktiviert?
2. Sind alle Team-Mitglieder korrekt im Team registriert?
3. Prüfe Log: "Would notify X user(s): [IDs]" sollte alle Mitglieder zeigen

## Log-Analyse

### Wichtige Log-Muster

**Debug-Modus:**
```
DEBUG MODE: E-Mail notification suppressed for assignment 'Aufgabe XYZ' (ID: 123)
DEBUG: Would notify 3 user(s): 100, 101, 102
DEBUG: Exercise: 'Übung ABC' (ID: 456, Ref: 789), Team: Yes
```

**Produktion:**
```
Sent 3 feedback notification(s) for assignment 'Aufgabe XYZ'
```

**Duplicate-Prevention:**
```
# Keine spezifische Log-Message
# Aber: "Sent X" sollte nicht größer sein als Anzahl Team-Mitglieder
```

**Fehler:**
```
Failed to send notification to user 123: [Error message]
Could not find ref_id for exercise 456 - notification skipped
No recipients found for notification (assignment=123, user=100)
```

## Best Practices

### Für Entwickler

1. **Immer mit Debug-Modus testen**
   - Erst Debug-Modus, dann Produktion testen

2. **Test-User verwenden**
   - Keine echten User-Adressen für Tests

3. **Logs prüfen**
   - Vor und nach Upload Log-Einträge vergleichen

### Für Admins

1. **Dokumentiere Notification-Settings**
   - Welche Übungen haben Notifications aktiviert?
   - Welche User haben Notifications aktiviert?

2. **Monitoring nach Deployment**
   - Erste Woche: Täglich Logs prüfen
   - Feedback von Tutoren/Studenten einholen

3. **User-Kommunikation**
   - Informiere User über neue Notification-Funktion
   - Erkläre wie man Notifications deaktiviert

## FAQ

**Q: Bekomme ich bei jedem Status-Update eine E-Mail?**
A: Nein! Nur wenn der Tutor Feedback-**Dateien** hochlädt.

**Q: Wie deaktiviere ich Notifications als Student?**
A: Profil → Benachrichtigungen → Übungen → "Feedback zu Abgaben" deaktivieren

**Q: Kann der Tutor sehen, ob E-Mails verschickt wurden?**
A: Ja, im ILIAS-Log (für Admins) steht "Sent X feedback notification(s)".

**Q: Was passiert wenn ILIAS-Mail-System down ist?**
A: Der Fehler wird geloggt, aber der Feedback-Upload funktioniert trotzdem.

**Q: Werden E-Mails auch bei Re-Upload verschickt?**
A: Ja, jedes Mal wenn neue/modifizierte Feedback-Dateien hochgeladen werden.

## Code-Referenz

**Wichtige Dateien:**

- `class.ilExerciseStatusFilePlugin.php:17` - Debug-Modus-Konstante
- `class.ilExFeedbackNotificationSender.php` - Notification-Logik
  - Zeile 45-47: Duplicate-Check
  - Zeile 68-70: Team-Mitglieder holen
  - Zeile 100-118: E-Mail-Versand-Loop
- `class.ilExFeedbackUploadHandler.php` - Feedback-Upload
  - Zeile 921-924: Notification-Aufruf (ResourceStorage)
  - Zeile 984-987: Notification-Aufruf (Filesystem)

## Weiterführende Dokumentation

- `tests/MANUAL_TEST_GUIDE.md` - Allgemeine Test-Anleitung
- `docs/ADMIN_GUIDE_TESTS.md` - Admin-Guide für Tests
- `README.md` - Plugin-Hauptdokumentation
