# CMS:CONTINUE

Setzt die Template-Abarbeitung trotz eines Success-Templates fort.

```cms
(CMS:CONTINUE)
```

***Methode:*** `Cms::cmsContinue()`

## Parameter

Dieser Befehl hat keine Parameter.

## Anwendung

Befehle mit `successtemplate` (`CMS:USER_LOGIN`, `CMS:USER_LOGIN_CODE`,
`CMS:USER_INPUT`, `CMS:USER_REGISTER`, `CMS:USER_REGISTER_CONFIRM`, `CMS:USER_PW`,
`CMS:USER_DELETE`, `CMS:EMAIL`) binden im Erfolgsfall ihr Template ein und ***beenden
danach die Seite***; ebenso `CMS:USER_PROTECT` und `CMS:USER_PW` mit ihrem
`errortemplate` und `CMS:USER_LOGIN` mit dem `codetemplate`. Steht `CMS:CONTINUE` vor dem
Befehl, läuft das Template nach dem eingebundenen Template stattdessen weiter:

```cms
(CMS:CONTINUE)
(CMS:USER_INPUT
  container=comments
  submit="$submit"
  successtemplate=comment_saved
)
<h2>Alle Kommentare</h2>
(CMS:SELECT comment_list
  filter[area]=comments
)
```

Ohne `CMS:CONTINUE` würde nach `comment_saved` nichts mehr ausgegeben - mit ihm
erscheint die Bestätigung oberhalb der Liste. Das Flag gilt genau einmal: Der nächste
dieser Befehle, der tatsächlich ein Template einbindet, verbraucht es.

---

[← Übersicht](README.md)
