# CMS:ZIP

Bietet die Dateien mehrerer Datensätze als ZIP-Archiv zum Download an und beendet die
Seite.

```cmscode
(CMS:ZIP field=feld[,feld…] filter[…]=wert | relation=feld | ids=… | source=basket [parameter=wert …])
```

***Methode:*** `CmsZip::cmsZip()` (nachgeladener Dienst, siehe unten)  
***Ohne Schlüssel:*** der erste Wert ohne `=` gilt als `field`  
***Pflicht:*** `field` und eine Auswahl: `filter`, `relation`, `ids` oder `source`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `field` ¹ | `Feldname(n), kommasepariert` | – | ja | Upload- oder Thumbnail-Feld(er). Je Datensatz gilt das erste, das der Besucher abrufen darf. |
| `filter[…]` | `Wert` | – | ² | Auswahl der Datensätze wie bei [CMS:SELECT](select.md). |
| `filter_op[…]` | `Operator` | `=` |  | Vergleichsoperator wie bei CMS:SELECT. |
| `filter_mode[…]` | `AND / OR` | `OR` |  | Verknüpfung mehrerer Werte wie bei CMS:SELECT. |
| `relation` | `Feldname` | – | ² | Die verknüpften Datensätze eines Datensatzes. |
| `src_id` | `Datensatz-Id` | aktueller Datensatz |  | Quell-Datensatz für `relation`. Leer oder `0`: keine Dateien. |
| `ids` | `Datensatz-Ids, kommasepariert` | – | ² | Ausdrücklich genannte Datensätze. |
| `source` | `basket` | – | ² | Die Datensätze im Warenkorb der Session – etwa ein Leuchtkasten. |
| `category` | `Zahl` | `0` |  | Warenkorb-Kategorie für `source=basket`. |
| `sort[…]` | `Feldname` | – |  | Reihenfolge wie bei CMS:SELECT; ohne Angabe die von `ids` bzw. des Warenkorbs. |
| `order[…]` | `asc / desc` | `asc` |  | Sortierrichtung. |
| `name` | `Text` | `download` |  | Name des Archivs ohne `.zip`. |
| `filenames` | `Muster` | `{slug}.{extension}` |  | Dateinamen im Archiv (siehe unten). |
| `credits` | `Feldnamen, kommasepariert` | – |  | Legt eine `CREDITS.txt` bei: je Datei ihr Name und die Inhalte dieser Felder. |
| `maxfiles` | `Zahl` | Einstellung |  | Höchstzahl der Dateien – nur kleiner als die Einstellung `user.zip.max_files`. |
| `maxsize` | `MB` | Einstellung |  | Höchstgröße in MB – nur kleiner als die Einstellung `user.zip.max_mb`. |
| `future` | `true / false` | `false` |  | Auch vordatierte Datensätze (wie bei CMS:SELECT). |
| `archive` | `true / false` | `false` |  | Auch abgelaufene Datensätze (wie bei CMS:SELECT). |
| `cookiename` | `Cookiename` | `usess` |  | Session-Cookie, wie bei [CMS:USER_LOGIN](user-login.md). |
| `message[…]` | `Text` | Core-Snippet |  | Eigene Meldungen: `message[empty]`, `message[limit]`, `message[throttled]`. |

¹ kann ohne Schlüssel als erster Wert angegeben werden.  
² mindestens einer der mit ² markierten Parameter muss angegeben werden.

`[…]` – der Parameter nimmt Unterschlüssel an, z. B. `name[schlüssel]=wert`.

## Anwendung

Pressebilder einer Meldung, alle Motive eines Modells, die Auswahl aus einem
***Leuchtkasten***: `CMS:ZIP` packt die Dateien mehrerer Datensätze in ein Archiv und
schickt es dem Besucher als Download. Die Seite endet damit – was danach im Template
steht, erscheint nur, wenn kein Archiv entstanden ist.

Der Befehl gehört deshalb an den ***Anfang eines eigenen Templates***, vor jede Ausgabe
(das Frontend puffert nicht; nach der ersten Ausgabe meldet der Befehl einen Fehler).
Ein eigenständiges Template, das ohne Datensatz aufgerufen wird, eignet sich gut:

```cms
(CMS:NUMBER useval="{$_GET['id']}" set=zip_id)
(CMS:ZIP relation=bilder src_id="{$set['zip_id']}" sort="_custom asc"
         field="fil_druck,fil_web" name="Pressebilder" credits="titel,bildunterschrift,bildnachweis")
(CMS:INCLUDE layout_kopf)
(CMS:MESSAGE prefix="<div class='fehler'>" suffix="</div>")
(CMS:INCLUDE layout_fuss)
```

Verlinkt wird es mit der Id des Datensatzes, dessen Bilder gemeint sind:

```cms
(CMS:URL id=0 template=pressebilder_zip set=zip_url)
<a href="<?php echo $set['zip_url']; ?>?id=(CMS:TEXT record_id)">Alle Bilder als ZIP</a>
```

`CMS:NUMBER` macht aus dem Adressparameter eine Zahl. Fehlt er, ist `src_id` leer –
dann gibt es keine Dateien, nie „alle“.

***Welche Datensätze.*** Die Auswahl folgt denselben Regeln wie [CMS:SELECT](select.md):
`filter`, `relation` mit `src_id` oder `ids`. Es zählen nur veröffentlichte Datensätze
aus den Instanzen der Website (`future` und `archive` wie bei CMS:SELECT). Eine Angabe
ist Pflicht – ohne Auswahl entsteht kein Archiv.

Mit `source=basket` kommen die Datensätze aus dem ***Warenkorb*** der Session
([CMS:USER_BASKET](user-basket.md)). Eine eigene Kategorie trennt den Leuchtkasten vom
Einkauf:

```cms
(CMS:USER_BASKET add variable=bilder category=2)       im Template des Leuchtkastens
(CMS:ZIP source=basket category=2 field=fil_web name=Auswahl)   im Download-Template
```

***Welche Datei.*** `field` nennt ein oder mehrere Datei-Felder. Je Datensatz kommt die
erste Datei ins Archiv, die der Besucher auch einzeln abrufen dürfte – dieselbe Prüfung
wie beim Download über `file.php`. Ist ein Feld ***nur für angemeldete Besucher***
freigegeben, erhält es nur, wer mit einem passenden Profil angemeldet ist; alle anderen
bekommen das nächste Feld der Liste. So liefert `field="fil_druck,fil_web"` der
angemeldeten Presse die Druckversion, allen anderen die Web-Version. Datensätze ohne
erlaubte Datei werden übergangen.

***Dateinamen.*** `filenames` legt die Namen im Archiv fest:

| Platzhalter | Inhalt |
|---|---|
| `{slug}` | Slug des Datensatzes (ohne Slug seine Id) |
| `{id}` | Id des Datensatzes |
| `{filename}` | ursprünglicher Dateiname ohne Endung |
| `{extension}` | Endung der Datei |
| `{feldname}` | Inhalt eines Feldes des Datensatzes |

Die Endung wird ergänzt, wenn das Muster sie nicht enthält; doppelte Namen erhalten
`-2`, `-3` …

***Bildnachweise.*** `credits` legt eine `CREDITS.txt` bei – je Datei ihr Name und
darunter die Inhalte der genannten Felder, etwa Titel, Bildunterschrift und Fotograf.

***Grenzen.*** Ein Archiv enthält höchstens `user.zip.max_files` Dateien (Standard 100)
mit zusammen `user.zip.max_mb` MB (Standard 500). `maxfiles` und `maxsize` können diese
Werte für ein Template nur verkleinern. Überschreitet die Auswahl die Größe, entsteht
kein Archiv.

Fertige Archive bleiben `user.zip.retention_hours` Stunden (Standard 24) im Zwischenspeicher;
dieselbe Auswahl wird aus dem Speicher geliefert. Neue Archive sind je Herkunft gedrosselt
(`user.throttle.zip_ip`, Standard 30 je Stunde).

***Meldungen.*** Entsteht kein Archiv, läuft das Template weiter. `CMS:MESSAGE` gibt die
Meldung aus, `$set['zip_error']` nennt den Grund:

| Grund | Bedeutung |
|---|---|
| `empty` | keine Datei, die der Besucher abrufen darf |
| `limit` | Auswahl größer als die erlaubte Größe |
| `throttled` | zu viele Archive in kurzer Zeit |

Die Texte kommen aus den Core-Snippets `zip_error_empty`, `zip_error_limit` und
`zip_error_throttled`; `message[empty]=…` usw. ersetzt sie im Template.

---

[← Übersicht](README.md)
