# Handbuch für Template-Programmierer

camindo CMS liefert Inhalte mittels Templates aus, beispielsweise HTML auf einer Website.
***CMS-Befehle*** bereiten Inhalte auf und fügen diese dynamisch im Template ein.
Beispiel für ein stark vereinfachtes Seitentemplate:

```cms
<html>
<body>
<h1>(CMS:TEXT title)</h1>
<div>(CMS:TEXT text)</div>
<div>(CMS:MEDIA field=bild_klein attr[id]=bild1)</div>
<div>erstellt: (CMS:DATE created_at format="dd.MM.y")</div>
</body>
</html>
```

CMS-Befehle dienen dabei nicht nur als Platzhalter. Sie stellen komplexe
***gekapselte Funktionalitäten*** dar, die mittels Parametern
angepasst und miteinander kombiniert werden können. Das spart viel Entwicklungszeit.
Zudem sind sie erprobt und gewährleisten Stabilität.  

Beispiele dafür, was CMS-Befehle leisten:

- Inhalte bestimmter Kriterien aus der Datenbank ermitteln
- Inhalte aufbereiten
- Benutzereingaben prüfen, speichern oder versenden
- Registrierung von Websitebesuchern, An- und Abmeldung
- weitere Templates einbinden

***Ausgabeformate***: Neben Websites mit HTML-Seiten sind weitere Ausgabemedien und
Publikationswege möglich. Inhalte können etwa als XML-Dateien, PDF-Dateien, E-Mails… formatiert
werden und über eine URL zum Abruf bereitgestellt, als Dateien ins Dateisystem
geschrieben oder über ein Netzwerk geschoben werden.

***Template-Satz***: Nicht jeder muss das Rad neu erfinden. Inhaltsbereiche (ihre Konfiguration inkl.
Eingabefeldern, auf Wunsch mit Inhalten) und die zugehörigen Templates können im XML-Format exportiert werden.
Beim Import in eine andere camindo-Installation bleiben die Beziehungen zwischen Bereichen und Templates
bestehen. Der komplette Anwendungsteil ist sofort einsatzbereit. Das ermöglicht die einfache Integration
***fertiger Bausätze*** (Forum, Newsfeed, ...) oder auch den Export selbst entwickelter Anwendungen
für die Wiederverwendung in späteren Projekten.

## Inhalt

1. [Grundlagen](01-grundlagen.md) – wie eine Seite entsteht, Adressen, Syntax,
   Listen, Verknüpfungen, das Konzept „jeder Datensatz hat eine Seite“, Filter,
   Mehrsprachigkeit, Sicherheits-Header (CSS/JS/JSON ausliefern, Einbetten in
   Partnerwebsites), zeitgesteuerte Templates, Templates im Dateisystem
2. [Template-Sätze](02-template-saetze.md) – Funktionen kapseln, portabel halten,
   weitergeben und anpassen
3. [Trigger-Templates](03-trigger-templates.md) – PHP-Methoden für die Ereignisse der
   Datensätze eines Bereichs (Speichern, Löschen …) und für die Aktionen der
   Lösch-Policies
4. Befehlsreferenz – eine Seite je Befehl (unten)

Wie Templates im Backend organisiert und den Inhaltsbereichen zugewiesen werden,
beschreibt das [Handbuch für Administratoren](../admins/06-templates.md).

## Befehlsreferenz

```cmscode
(CMS:BEFEHL wert parameter=wert parameter[schlüssel]="wert" …)
```

Befehlsname und Parameternamen sind unabhängig von Groß- und Kleinschreibung. Der
erste Wert ohne `=` gilt als ***Hauptparameter*** des Befehls (`(CMS:TEXT titel)` =
`(CMS:TEXT field=titel)`). Werte in Anführungszeichen dürfen PHP-Ausdrücke enthalten,
so wie sie in einem PHP-String üblich sind (`"{$set['x']}"`).

Ausgabeparameter sind bei den meisten Befehlen dieselben:  
`ifempty` (Ersatz bei leerem Wert),  
`instead` (Ersatz bei nicht leerem Wert),  
`prefix` / `suffix` (nur bei nicht leerem Wert),  
`set` (Ergebnis wird in die Variable `$set[…]` geschrieben, statt auf die Seite),  
`id` (anderer Datensatz als Quelle),  
`replace` (Teiltexte ersetzen) bei TEXT, DATE, DATETIME, TIME und NUMBER.

### Ausgabe

| Befehl | |
|---|---|
| [CMS:DATE](date.md) | Datum ausgeben |
| [CMS:DATETIME](datetime.md) | Datum und Uhrzeit ausgeben |
| [CMS:EMAIL](email.md) | E-Mail versenden, Formular-Pflichtfelder prüfen |
| [CMS:MEDIA](media.md) | Bild oder Mediendatei ausgeben |
| [CMS:MESSAGE](message.md) | Fehlermeldungen ausgeben |
| [CMS:NUMBER](number.md) | Numerischen Inhalt ausgeben |
| [CMS:RAW](raw.md) | Inhalt unformatiert ausgeben |
| [CMS:SNIPPET](snippet.md) | Snippet (Textbaustein) in der Sprache des Aufrufs ausgeben |
| [CMS:TEXT](text.md) | Textinhalt ausgeben |
| [CMS:TIME](time.md) | Uhrzeit ausgeben |
| [CMS:URL](url.md) | URL eines Datensatzes erzeugen |
| [CMS:XML](xml.md) | XML-Deklaration ausgeben |
| [CMS:ZIP](zip.md) | Dateien mehrerer Datensätze als ZIP-Archiv herunterladen |

### Verknüpfungen

| Befehl | |
|---|---|
| [CMS:RELATION](relation.md) | Hauptfeld verknüpfter Datensätze ausgeben (nur für interne Vorschau!) |

### Abfragen

| Befehl | |
|---|---|
| [CMS:SELECT](select.md) | Datensätze abfragen |
| [CMS:SELECT_LANGVERSION](select-langversion.md) | Sprachen der Instanz als Ergebnismenge |
| [CMS:RESULT_START](result-start.md) | Beginn der Ergebnisschleife |
| [CMS:RESULT_END](result-end.md) | Ende der Ergebnisschleife |

### Navigation

| Befehl | |
|---|---|
| [CMS:JUMP_LIST](jump-list.md) | Blätter-Navigation erzeugen |
| [CMS:JUMP_NEXT](jump-next.md) | Weiterblättern-Verweis erzeugen |
| [CMS:JUMP_PREV](jump-prev.md) | Zurückblättern-Verweis erzeugen |

### Steuerung

| Befehl | |
|---|---|
| [CMS:CONTINUE](continue.md) | Nach einem Success-Template weitermachen |
| [CMS:CSP](csp.md) | Script-Policy (CSP) der Seite anpassen |
| [CMS:HEADER](header.md) | HTTP-Header senden |
| [CMS:INCLUDE](include.md) | Template einbinden, wahlweise mit Ausgabe-Cache |
| [CMS:NONCE](nonce.md) | Nonce für per PHP erzeugte Scripts |
| [CMS:SAVEDATA](savedata.md) | Datensatz in einer PHP-Variable ablegen |

### Frontend-Editing

| Befehl | |
|---|---|
| [CMS:FED](fed.md) | Bearbeiten-Verweis bei Frontend-Editing |
| [CMS:FED_CODE](fed-code.md) | HTML nur bei Frontend-Editing ausgeben |
| [CMS:FED_SCRIPT](fed-script.md) | Script für die Bearbeiten-Verweise (einmal je Seite) |

### Community

| Befehl | |
|---|---|
| [CMS:USER_2FA](user-2fa.md) | Zweiten Faktor verwalten |
| [CMS:USER_AUTH](user-auth.md) | Session des Frontend-Users prüfen |
| [CMS:USER_BASKET](user-basket.md) | Warenkorb bearbeiten |
| [CMS:USER_DELETE](user-delete.md) | Datensatz des Users löschen |
| [CMS:USER_INPUT](user-input.md) | Datensatz aus Usereingaben speichern |
| [CMS:USER_LOGIN](user-login.md) | Frontend-User anmelden |
| [CMS:USER_LOGIN_CODE](user-login-code.md) | Anmeldung mit zweitem Faktor abschließen |
| [CMS:USER_LOGOUT](user-logout.md) | Frontend-User abmelden |
| [CMS:USER_PASSWORD_RULES](user-password-rules.md) | Passwort-Regeln für die Anzeige im Formular |
| [CMS:USER_REGISTER](user-register.md) | Frontend-User registrieren (Profil-Datensatz anlegen) |
| [CMS:USER_REGISTER_CONFIRM](user-register-confirm.md) | Registrierung freischalten |
| [CMS:USER_PROTECT](user-protect.md) | Template für nicht angemeldete Besucher sperren |
| [CMS:USER_PW](user-pw.md) | Passwort-Reset vorbereiten |
| [CMS:USER_TOKEN](user-token.md) | Sicherheits-Token für Formulare |

Die Community-Funktionen richtet der Administrator ein (siehe
[Handbuch für Administratoren](../admins/13-community.md)).

### Zusatzmodul PDF

Das Zusatzmodul PDF (`camindo/module-pdf`) ergänzt die Befehle `(CMS:PDF_…)`, mit
denen Templates PDF-Dokumente erzeugen – Seite für Seite, auf Wunsch auf einer
PDF-Vorlage wie einem Briefbogen, mit Texten, HTML, Bildern, Linien und Tabellen:

```cms
(CMS:PDF_INIT margins=25,20,30,20)
(CMS:PDF_PAGE briefbogen.pdf)
(CMS:PDF_FONT helvetica style=B size=16)
(CMS:PDF_TEXT "Rechnung {$cms['nummer']}" y=50)
(CMS:PDF_PRINT rechnung.pdf)
```

Die vollständige Befehlsliste enthält die Dokumentation des Moduls.
