# 3 Trigger-Templates

Ein ***Trigger-Template*** enthält PHP-Methoden, die camindo CMS bei Ereignissen der
Datensätze eines Inhaltsbereichs aufruft – beim Anlegen, Speichern, Löschen,
Wiederherstellen und bei den Aktionen einer Lösch-Policy. So lassen sich Inhalte beim
Speichern aufbereiten (mehrere Felder zu einem zusammenfassen, Werte prüfen),
verbundene Daten nachziehen oder personenbezogene Daten nach eigenen Regeln
anonymisieren.

Die Methoden laufen, gleich woher die Änderung kommt: Eingabemaske, Frontend
(`CMS:SAVEDATA`, `CMS:USER_…`), API, Import, Stapelverarbeitung, Papierkorb-Cronjob
und Lösch-Policies.

## Zuweisen

Ein Trigger-Template ist ein gewöhnliches Template in einem Template-Bereich. Sie
weisen es in den Einstellungen des Inhaltsbereichs unter ***„Trigger-Templates“*** zu
(siehe [Handbuch für Administratoren](../admins/04-struktur.md#einstellungen-eines-inhaltsbereichs)).
Ein Bereich kann ***mehrere Trigger-Templates*** haben, und dasselbe Template darf
mehreren Bereichen zugewiesen sein. So lassen sich wiederverwendbare Bausteine bilden
– etwa ein Template, das in allen Bereichen Slugs pflegt, und daneben eines mit den
Regeln eines einzelnen Bereichs.

### Reihenfolge

Die Templates eines Bereichs laufen bei jedem Ereignis ***nacheinander in der
angezeigten Reihenfolge***; Sie ändern sie mit den Pfeil-Schaltflächen
![arrow_upward](../_/arrow_upward.svg) ![arrow_downward](../_/arrow_downward.svg)
neben jedem Eintrag.

- Ein Template ohne die Methode des Ereignisses wird übergangen.
- Alle bekommen dieselben Variablen: Was ein Template an `$data` oder
  `$affectedFields` ändert, sieht das nächste schon.
- Wirft ein Template eine Ausnahme, laufen die folgenden nicht mehr, und der Vorgang
  wird abgebrochen (siehe [Abbrechen](#abbrechen)).
- Jedes Template ist ein eigenes Objekt: Eigenschaften und Hilfsmethoden gleichen
  Namens in verschiedenen Templates stören sich nicht. Daten zwischen Templates
  tauschen Sie über `$data` aus.

## Aufbau

Das Template enthält ***nur Methoden-Definitionen*** – keine Ausgabe, keine Anweisungen
außerhalb der Methoden. camindo CMS setzt den übersetzten Code in den Rumpf einer
Klasse; jede Methode wird so zur Methode eines Objekts:

```php
<?php
// Trigger-Template "mitglieder_trigger" für den Bereich Mitglieder

function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    // Anzeigename aus Vor- und Nachname
    $data['name'] = trim(($data['vorname'] ?? '') . ' ' . ($data['nachname'] ?? ''));
    if (!in_array('name', $affectedFields, true)) $affectedFields[] = 'name';
}

private function normalize(string $text): string
{
    return trim(preg_replace('/\s+/', ' ', $text));
}
```

- Alle Ereignis-Methoden sind optional. Fehlt eine, passiert bei dem Ereignis nichts.
- Eigene Hilfsmethoden (`private function …`), Eigenschaften und Konstanten sind
  erlaubt.
- CMS:SELECT ist erlaubt, um Inhalte verknüpfter Datensätze zu ermitteln (Anmerkungen unten beachten!)
- Klassen sprechen Sie mit vollem Namen an (`\Camindo\Core\Cms`, `\DateTimeImmutable`)
  – `use` geht nicht, weil der Code mitten in einer Klasse steht.
- Ein Template, das sich nicht laden lässt (Syntaxfehler, Ausgabe außerhalb der
  Methoden), bricht den Vorgang mit einer Fehlermeldung ab. Datensätze ohne die
  vorgesehene Aufbereitung zu speichern wäre schlimmer.

### Was im Template bereitsteht

| | |
|---|---|
| `$this->cms` | Cms – `snippet()`, `error()`, `select()`, `fetch()` … |
| `$this->db` | Database – eigene Abfragen |
| `$this->record` | Record – das Dataset, das das Ereignis auslöst (`load()`, `save()` …) |
| `$this->areaId` | Bereich, für den das Template geladen wurde. Ist es mehreren Bereichen zugewiesen, nehmen Sie den Bereich aus `$data['area_id']` |
| `$this->temporary` | `true` beim Zwischenspeichern einer Arbeitskopie (Reiterwechsel in der Eingabemaske) |
| `$this->email`, `$this->pagination` … | die Dienste, die auch Templates kennen |
| `$cms['record_id']` | Nummer des auslösenden Datensatzes – nur sie, für die CMS-Befehle; in `onSave()` die seiner Arbeitskopie (siehe [Verknüpfte Inhalte abfragen](#verknüpfte-inhalte-abfragen)) |

## Ereignisse

| Methode | Wann |
|---|---|
| `onCreate(array &$data): void` | ein neuer Datensatz wird vorbelegt – Werte in `$data` erscheinen in der leeren Maske |
| `onLoaded(array &$data): void` | nach jedem Laden eines Datensatzes (Eingabemaske, Stand vor dem Speichern …) – nicht bei `CMS:SELECT` im Frontend |
| `onSave(array &$affectedFields, array &$data, array &$previousData): void` | vor der Prüfung und dem Schreiben. Ein Feld, das die Methode neu setzt, muss sie in `$affectedFields` eintragen |
| `onSaved(array &$affectedFields, array &$data, array &$previousData): void` | nach dem Schreiben, vor dem Abschluss der Transaktion |
| `onSoftDelete(array $data): void` | der Datensatz kommt in den Papierkorb |
| `onRestore(array $data): void` | der Datensatz kommt aus dem Papierkorb zurück |
| `onDelete(array $data): void` | endgültiges Löschen, vor dem Löschen in der Datenbank |
| `onDeleted(array $data): void` | endgültiges Löschen, danach |
| `onPrivacyAction(string $action, array &$data, array $context): void` | Aktion einer Lösch-Policy – siehe [unten](#onprivacyaction-lösch-policies) |

`$previousData` ist der gespeicherte Stand vor der Änderung (leer bei einem neuen
Datensatz).

### Abbrechen

Alle Ereignisse außer `onCreate` und `onLoaded` laufen in der Transaktion des Vorgangs.
Eine Ausnahme bricht ihn ab und macht ihn vollständig rückgängig; die Meldung erscheint
in der Eingabemaske bzw. als Fehler der API:

```php
function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    if (($data['ende'] ?? '') !== '' && $data['ende'] < ($data['beginn'] ?? '')) {
        throw new \Exception('Das Ende liegt vor dem Beginn.');
    }
}
```

### Zwischenspeichern

Wechselt ein Redakteur in der Eingabemaske den Reiter, speichert camindo CMS den
Zwischenstand der Arbeitskopie – auch dabei laufen `onSave` und `onSaved`, mit
`$this->temporary = true`. Aufwendige oder nach außen wirkende Schritte (E-Mails,
Schnittstellen) gehören hinter `if ($this->temporary) return;`.

### Verschieben

Wird ein Datensatz in einen anderen Bereich verschoben, gelten die Trigger-Templates
des ***Zielbereichs***. `$data` enthält dort schon die Felder des Zielbereichs,
`$previousData` den Stand im Herkunftsbereich mit dessen Feldnamen.

### Wiedereintritt

Speichert ein Template in `onSaved()` denselben Datensatz erneut, liefe es endlos.
Ein Ereignis wird deshalb je Template für denselben Datensatz nicht verschachtelt
ausgeführt: Beim erneuten Speichern laufen die übrigen Trigger-Templates des Bereichs,
das gerade laufende nicht. Andere Datensätze – auch desselben Bereichs – lösen ihre
Trigger normal aus.

## Verknüpfte Inhalte abfragen

Die Inhalte des auslösenden Datensatzes stehen in `$data` (neuer Stand) und
`$previousData` (vorheriger Stand) – mehrsprachige Felder mit allen Sprachen. Dort lesen
und ändern Sie sie, nicht über `CMS:TEXT`.

Für andere Datensätze, etwa die verknüpften, können Sie [CMS:SELECT](select.md) wie in
jedem Template verwenden. Während eine Methode läuft, enthält `$cms` nur
`record_id`, die Nummer des auslösenden Datensatzes. Ein `CMS:SELECT` mit `relation`
braucht deshalb kein `src_id` – und sollte keines haben (siehe unten):

```cms
function onSave(array &$affectedFields, array &$data, array &$previousData): void
{
    $isVariant = false;
?>
(CMS:SELECT
  relation=variante_von
  state=any archive=true future=true
  resulttemplate=shop.trigger_variante_gefunden
)
<?php
    $data['ist_variante'] = $isVariant ? 'ja' : '';
    if (!in_array('ist_variante', $affectedFields, true)) $affectedFields[] = 'ist_variante';
}
```

Das Ergebnis-Template (`shop.trigger_variante_gefunden`) läuft im Rumpf der Methode: Es
sieht deren Variablen und `$this` und setzt hier nur `$isVariant = true;`. Es darf
nichts ausgeben.

Beachten Sie die Grenzen:

- ***Neuer Stand der Verknüpfungen über `$cms['record_id']`.*** Während ein
  vorhandener Datensatz bearbeitet wird, hängen seine Verknüpfungen – ausgehende wie
  eingehende – an einer ***Arbeitskopie***; erst nach dem Speichern gehen sie auf den
  Datensatz über. In `onSave()` – und in `onSaved()` beim Zwischenspeichern – enthält
  `$cms['record_id']` deshalb die Nummer der Arbeitskopie: `CMS:SELECT relation=…` und
  [CMS:RELATION](relation.md) ohne Quellangabe sehen so schon die geänderten
  Verknüpfungen, ebenso die Gegenrichtung („Wer verweist auf diesen Datensatz?“) mit
  `dest_id="{$cms['record_id']}"` bzw. `filter[feld]="{$cms['record_id']}"`.
  `$data['record_id']` ist dagegen immer die Nummer des Datensatzes selbst – damit
  erhalten Sie in `onSave()` den bisherigen Stand. In `onSaved()` nach dem
  endgültigen Speichern und in allen übrigen Methoden sind beide Nummern gleich.
- ***Eingebettete Inhalte beim Zwischenspeichern:*** Verknüpfungen eines Feldes mit
  eingebetteten Inhalten zeigen beim Zwischenspeichern auf deren Arbeitskopien, die
  `CMS:SELECT` nicht findet. Beim endgültigen Speichern sind diese Inhalte schon
  übernommen und werden gefunden.
- ***Veröffentlichung:*** `CMS:SELECT` findet auch hier nur, was die Website zeigen
  würde – freigegeben, Erscheinungsdatum erreicht, nicht abgelaufen. Geht es um die
  Struktur („Ist überhaupt ein Datensatz verknüpft?“), setzen Sie
  `state=any archive=true future=true`; sonst fehlen gesperrte, vordatierte und
  abgelaufene Datensätze. Datensätze im Papierkorb findet `CMS:SELECT` nie.
- ***Rechte:*** Die Abfrage sieht, was der Auslöser sehen darf. Das Ergebnis kann sich
  deshalb unterscheiden, je nachdem, wer speichert:

  | Auslöser | Was `CMS:SELECT` findet |
  |---|---|
  | Benutzer im Backend oder über die API | nur Inhalte der Instanzen, Bereiche und Sprachen, die seine Benutzergruppe lesen darf (Superuser: alle) |
  | Website (`CMS:SAVEDATA`, `CMS:USER_…`) | Inhalte der Instanz und ihrer lesbaren Instanzen |
  | Zeitplaner (auch später fällige Blöcke einer Lösch-Policy) | alle |

- ***Sprache:*** Mehrsprachige Felder der gefundenen Datensätze erscheinen in einer
  Sprache – auf der Website in der Sprache der Seite, im Backend in der
  Inhaltssprache des Benutzers. Alle Sprachen liefert `$this->record->load($id)`.

## onPrivacyAction: Lösch-Policies

Eine [Lösch-Policy](../admins/15-datenschutz.md#löschen-nach-lösch-policy) legt fest,
was beim Löschen eines Datensatzes geschieht. Was sich mit „Einzelne Felder überschreiben“
nicht ausdrücken lässt – verknüpfte Datensätze, Texte, die nur teilweise
personenbezogen sind, mehrsprachige Inhalte je Sprache –, erledigt das Trigger-Template
mit zwei Aktionen:

| Aktion der Policy | Aufruf |
|---|---|
| „Trigger-Template auf den Datensatz anwenden“ | einmal, `$data` ist der Datensatz |
| „Trigger-Template auf das Audit-Log anwenden“ | einmal je Eintrag im Audit-Log des Datensatzes, `$data` ist der Eintrag |

Jeder solche Block hat eine ***Kennung*** (Kleinbuchstaben, Ziffern, `_`). Sie kommt
als `$action` an – daran unterscheidet das Template die Blöcke, etwa einen sofortigen
Schritt und einen nach einem Jahr.

```php
function onPrivacyAction(string $action, array &$data, array $context): void
```

| `$context` | |
|---|---|
| `target` | `record` oder `audit` |
| `record_id` | der gelöschte Datensatz |
| `user_id` | der Website-User, dem er gehört (`record.user_id`), sonst 0 |
| `area_id` | sein Bereich |
| `source` | Auslöser: `record` (Datensatz wird gelöscht), `profile` (UGC: Profil wurde gelöscht) oder `area` (Bereich wurde gelöscht) |
| `policy_id`, `job_id` | Policy und Auftrag |
| `deleted` | nur bei `record`: `true`, wenn der Datensatz zu dem Zeitpunkt im Papierkorb liegt |

### Auf den Datensatz

`$data` enthält alle Felder des Datensatzes – ***mehrsprachige Felder als Array
`language_id => Wert`***, Dateifelder mit ihren Metadaten. Was die Methode an `$data`
ändert, speichert camindo CMS anschließend:

- im ***Policy-Modus***: ohne Pflichtfeld- und Formatprüfung und ohne Rechte der
  Benutzergruppe – „unique“ gilt weiter;
- Dateifelder, `record_id`, `area_id`, `user_id` und die übrigen Kennungen nicht. Eine
  Datei entfernen Sie mit „Einzelne Felder überschreiben“ (`bild = ""`) im selben oder einem
  späteren Block;
- im Audit-Log als Aktion „nach Lösch-Policy angepasst“ (`privacy`), ohne die
  vorherigen Werte.

Liegt der Datensatz im Papierkorb (`$context['deleted']`), läuft die Methode ebenfalls
– etwa um verknüpfte Daten aufzuräumen –, gespeichert wird am Datensatz dann aber
nichts. Andere Datensätze bearbeitet die Methode selbst über `$this->record` oder
`$this->db`.

### Auf das Audit-Log

Für jeden Eintrag im Verlauf des Datensatzes wird die Methode mit dem Eintrag
aufgerufen:

| Schlüssel | |
|---|---|
| `id`, `t` | Nummer und Zeitpunkt – bleiben unverändert |
| `action` | `create`, `update`, `delete`, `restore`, `purge`, `privacy` … |
| `actor` | `type` (`admin`, `user`, `system`), `admin_id`, `user_id`, `ip`, `origin` – Handelnde nur als Nummer, ohne Namen |
| `label` | Bezeichnung des Datensatzes zu dem Zeitpunkt |
| `changes` | Liste der Änderungen: `k` Feldname (mehrsprachig `feld@de_DE`), `l` Beschriftung, `t` Feldtyp, `g` Sprache, `b` vorher, `a` nachher |

Geänderte Einträge schreibt camindo CMS zurück – in die Datei unter `var/audit/` mit
einem neuen Kontrollpunkt der Prüfsummenkette, `label` auch in die Datenbank. Einträge, die erst später entstehen (etwa beim endgültigen Löschen),
durchlaufen die Methode nicht; planen Sie dafür einen späteren Block.

Hat der Bereich mehrere Trigger-Templates mit `onPrivacyAction()`, laufen sie
nacheinander mit derselben Kennung; jedes prüft selbst, ob `$action` es betrifft.

### Fehler

Hat keines der Trigger-Templates des Bereichs `onPrivacyAction()` oder wirft die
Methode eine Ausnahme, scheitert der Auftrag: Er steht mit der Meldung im Log `privacy` und
wird beim nächsten Lauf der Zeitplaner-Aufgabe „Lösch-Policies anwenden“ erneut versucht –
höchstens 20-mal. Scheitert ein sofort fälliger Block, bricht das Löschen nicht ab:
Die übrigen Blöcke laufen, der gescheiterte folgt mit dem nächsten Lauf.
