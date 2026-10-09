# CMS:CSP

Ändert die Script-Policy (Content-Security-Policy) dieser Seite: weitere Script-Quellen,
Formularziele oder ein anderer Modus.

```cmscode
(CMS:CSP [parameter=wert …])
```

***Methode:*** `Cms::cmsCsp()`

## Parameter

| Parameter | Wert | Standard | Pflicht | Beschreibung |
|---|---|---|:-:|---|
| `script` | `Quellen` | – |  | Weitere Script-Quellen, durch Leerzeichen oder Komma getrennt. |
| `form` | `Quellen` | – |  | Weitere Formularziele (schaltet `form-action` ein). |
| `mode` | `off / report / enforce` | – |  | Modus für diese Seite; leer: Einstellung der Website. |

## Anwendung

Für jede Website lässt sich im Backend eine ***Script-Policy*** einschalten (Instanz,
Abschnitt „Script-Policy (CSP)“). Dann laufen nur noch Scripts,

* die die ***Nonce*** der Seite tragen (ein Zufallswert je Aufruf) – im Template
  schreiben Sie dafür `<script(CMS:NONCE)>`, siehe [CMS:NONCE](nonce.md), oder
* die von der eigenen Website oder aus den eingetragenen Quellen geladen werden
  (`<script src="/js/app.js">`).

Code, der über Inhalte in die Seite gerät (ein Kommentar mit `<script>`, ein Bild mit
`onerror`), trägt keine Nonce und läuft nicht. Voreinstellung ist „aus“; „Nur melden“
lässt alles laufen und schreibt Verstöße ins Protokoll `csp` – so sieht man vor dem
Durchsetzen, was blockiert würde.

`CMS:CSP` passt die Policy für ***eine*** Seite an, etwa die Kasse mit dem Script des
Zahlungsanbieters:

```cms
(CMS:CSP script="https://js.stripe.com" form="https://checkout.stripe.com")
```

Der Befehl muss vor der ersten Ausgabe stehen, am besten ganz oben im Template; steht er
später oder ist `mode` unbekannt, legt er eine Fehlermeldung ab ([CMS:MESSAGE](message.md)).
Mehrere Aufrufe sammeln Quellen, getrennt durch Leerzeichen oder Komma. Erlaubt sind
Host-Angaben (`https://js.example.com`,
`*.example.com`, mit Port oder Pfad); Schlüsselwörter wie `'unsafe-inline'` und reine
Schemata wie `https:` werden verworfen – sie höben die Policy auf.

***Was die Policy blockiert und wie es richtig geht:***

| blockiert | stattdessen |
|---|---|
| `<button onclick="…">`, `<form onsubmit="…">` | `data-…`-Attribut und `addEventListener` in einem `<script>` des Templates |
| `<a href="javascript:…">` | `<a href="#" data-…>` + Listener |
| `<script>` ohne Nonce | `<script(CMS:NONCE)>` |
| `echo '<script>…</script>'` in PHP | `(CMS:NONCE set=nonce)` und `echo '<script' . $set['nonce'] . '>'` – siehe [CMS:NONCE](nonce.md) |
| fremde Scripts (Tag-Manager, Widgets) | Quelle in der Instanz eintragen oder `CMS:CSP script=…`; lädt ein Script weitere nach, „Nachgeladene Scripts erlauben“ einschalten |

Beim Speichern eines Templates meldet der Editor jedes `<script>` ohne `(CMS:NONCE)`,
jedes `on…=`-Attribut und jedes `javascript:` als Hinweis; auf der Startseite sammelt sie
das Widget „Template-Meldungen“ – auch für Templates, die im Dateisystem bearbeitet
werden.

Wer eine ganz eigene Policy will (auch für Styles, Bilder, Verbindungen), sendet sie mit
[CMS:HEADER](header.md) und holt die Nonce mit `(CMS:NONCE format=value)`.

## Parameter im Einzelnen

### `script`

```cms
(CMS:CSP script="https://maps.example.com https://cdn.example.com")
```

### `form`

```cms
(CMS:CSP form="https://www.paypal.com")
```

### `mode`

Eine Seite mit einem alten Widget vorübergehend ausnehmen oder nur melden lassen:

```cms
(CMS:CSP mode=report)
(CMS:CSP mode=off)
```

---

[← Übersicht](README.md)
