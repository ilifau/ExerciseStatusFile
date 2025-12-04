# Test-Sicherheit und Cleanup

## Sicherheitsmaßnahmen gegen versehentliches Löschen von Produktionsdaten

Um zu verhindern, dass Test-Cleanup-Routinen versehentlich Produktionsdaten löschen, verwenden wir **eindeutige Präfixe** für alle Test-Objekte.

### Verwendete Präfixe

**Übungen:**
- Präfix: `AUTOTEST_ExStatusFile_`
- Beispiel: `AUTOTEST_ExStatusFile_1732563421_Individual`
- Format: `AUTOTEST_ExStatusFile_<timestamp><suffix>`

**Test-User:**
- Präfix: `autotest_exstatusfile_`
- Beispiel: `autotest_exstatusfile_675a1b2c3d4e5.678910_1`
- Format: `autotest_exstatusfile_<uniqid>_<number>`

### Warum diese Präfixe sicher sind

1. **Sehr spezifisch**: Die Kombination aus "AUTOTEST", "ExStatusFile" und Plugin-Name ist praktisch nie in Produktionsdaten zu finden
2. **Eindeutig identifizierbar**: Jeder kann sofort erkennen, dass es sich um automatisch generierte Test-Daten handelt
3. **Timestamp/Unique-ID**: Zusätzliche Eindeutigkeit durch Zeitstempel und IDs

### Mehrfache Sicherheitsebenen

Der Code verwendet **drei Sicherheitsebenen**:

#### 1. Tracking während des Test-Laufs
```php
$this->created_objects = [];  // Speichert nur selbst erstellte Objekte
$this->created_users = [];    // Speichert nur selbst erstellte User
```

#### 2. Präfix-Validierung beim Cleanup
```php
// Safety check: Verify this is actually a test object
if (strpos($check_row['title'], 'AUTOTEST_ExStatusFile_') !== 0) {
    echo "⚠️ WARNUNG: Überspringe Objekt - kein Test-Objekt\n";
    continue;
}
```

#### 3. Notfall-Cleanup nur nach Präfix
```php
WHERE od.title LIKE 'AUTOTEST_ExStatusFile_%'
WHERE login LIKE 'autotest_exstatusfile_%'
```

## Cleanup-Optionen

### 1. Normaler Cleanup (Standard)
Löscht nur die Objekte, die während des aktuellen Test-Laufs erstellt wurden.

```bash
# Automatisch nach jedem Test-Lauf
php run-all-tests.php
```

### 2. Daten behalten (--keep-data)
Für manuelle Inspektion der Test-Daten in der ILIAS-GUI.

```bash
php run-all-tests.php --keep-data
```

### 3. Notfall-Cleanup
Löscht ALLE Test-Objekte mit den definierten Präfixen (nützlich nach abgestürzten Tests).

```bash
# Via CLI
php emergency-cleanup.php

# Via Browser
https://your-ilias.de/.../emergency-cleanup.php?confirm=yes
```

## Überprüfung vorhandener Test-Daten

### SQL-Abfragen zur Überprüfung

**Alle Test-Übungen anzeigen:**
```sql
SELECT od.obj_id, od.title, oref.ref_id
FROM object_data od
LEFT JOIN object_reference oref ON od.obj_id = oref.obj_id
WHERE od.type = 'exc'
AND od.title LIKE 'AUTOTEST_ExStatusFile_%';
```

**Alle Test-User anzeigen:**
```sql
SELECT usr_id, login, firstname, lastname
FROM usr_data
WHERE login LIKE 'autotest_exstatusfile_%';
```

**exc_members-Einträge für Test-Übungen:**
```sql
SELECT em.*, od.title
FROM exc_members em
JOIN object_data od ON em.obj_id = od.obj_id
WHERE od.title LIKE 'AUTOTEST_ExStatusFile_%';
```

## Best Practices

1. **Nach jedem Test-Lauf**: Cleanup läuft automatisch (außer mit --keep-data)
2. **Vor Produktiv-Deployment**: Notfall-Cleanup durchführen
3. **Regelmäßige Überprüfung**: SQL-Queries ausführen, um verwaiste Test-Daten zu finden
4. **Niemals auf Produktion**: Tests nur auf Test-/Entwicklungssystemen ausführen

## Was passiert beim Cleanup?

Für jedes Test-Objekt werden folgende Datenbank-Einträge gelöscht:

**Übungen:**
- `exc_members` (Status-Einträge für User)
- `object_reference` (Repository-Referenzen)
- `object_data` (Objekt-Metadaten)
- Dateisystem: `/data/<client>/ilExercise/<exercise_id>/`

**User:**
- `usr_data` (User-Daten)
- `object_data` (User als Objekt)

## Fehlerbehandlung

Der Cleanup ist **fehlerresistent**:
- Fehlende Objekte werden übersprungen (keine Exceptions)
- Jedes Objekt wird einzeln behandelt (ein Fehler stoppt nicht den gesamten Cleanup)
- Alle Fehler werden geloggt und ausgegeben

## Migration von alten Präfixen

Falls du noch alte Test-Daten mit den Präfixen `TEST_Exercise_` oder `test_user_` hast:

```sql
-- Vorsicht: Nur auf Test-Systemen ausführen!
-- Alte Test-Übungen löschen
DELETE oref FROM object_reference oref
JOIN object_data od ON oref.obj_id = od.obj_id
WHERE od.type = 'exc' AND od.title LIKE 'TEST_Exercise_%';

DELETE FROM object_data
WHERE type = 'exc' AND title LIKE 'TEST_Exercise_%';

-- Alte Test-User löschen
DELETE FROM usr_data WHERE login LIKE 'test_user_%';
DELETE FROM object_data WHERE obj_id IN (
    SELECT usr_id FROM usr_data WHERE login LIKE 'test_user_%'
);
```
