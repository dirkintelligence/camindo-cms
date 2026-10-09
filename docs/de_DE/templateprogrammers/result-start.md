# CMS:RESULT_START

Markiert den Beginn einer Schleife zur Verarbeitung von CMS:SELECT-Ergebnissen.

```cms
(CMS:RESULT_START)
```

***Kein Methodenaufruf*** – die Wirkung steckt allein im PHP-Schnipsel  
***PHP-Schnipsel:*** `loop_start` (danach)

## Parameter

Dieser Befehl hat keine Parameter.

## Anwendung

Öffnet die Schleife über die Treffer eines `CMS:SELECT` (bzw. `CMS:SELECT_LANGVERSION`,
`CMS:USER_BASKET function=load`). Steht im ***Listentemplate***; alles zwischen
`CMS:RESULT_START` und `CMS:RESULT_END` wird je Treffer ausgeführt, die Befehle darin
lesen aus der aktuellen Zeile:

```cms
<table>
(CMS:RESULT_START)
<tr>
  <td><?php echo $this->cms->row_no + 1; ?></td>
  <td><a href="(CMS:URL)">(CMS:TEXT title)</a></td>
  <td>(CMS:DATE online_at)</td>
</tr>
(CMS:RESULT_END)
</table>
```

Innerhalb der Schleife stehen zur Verfügung: `$cms` (die aktuelle Zeile als Array),
`$this->cms->row_no` (0-basierte Zeilennummer), `$this->db->num_rows` (Treffer auf
dieser Seite) und `$this->db->num_rows_unlimited` (Gesamtzahl bei `count=true`).

Der Befehl hat keine Parameter und erzeugt keinen Methodenaufruf - er wird direkt zu
einer `for`-Schleife kompiliert.

---

[← Übersicht](README.md)
