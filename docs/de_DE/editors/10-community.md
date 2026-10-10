# 10 Beiträge von Website-Besuchern prüfen

Websites mit ***Community-Funktionen*** lassen Besucher selbst Inhalte anlegen: Sie
registrieren sich, pflegen ihr Profil und schreiben Kommentare, Bewertungen oder
Anzeigen. Dabei ist die Rolle nicht festgelegt – es kann sich um Forenmitglieder, Kunden, Mitarbeiter o. a. handeln.
Zusammengefasst werden sie in camindo CMS mit dem englischen Begriff ***Frontend-User***
bezeichnet.

## Wo die Inhalte der Frontend-User liegen

Frontend-User sind gewöhnliche Datensätze in sogenannten Profil-Bereichen.

- ***Profil-Bereiche*** (Symbol ![face](../_/face.svg)) enthalten je Frontend-User
  einen Datensatz mit E-Mail-Adresse, Anmeldename, Passwort und weiteren Profildaten.
- ***Beitrags-Bereiche*** (Symbol ![chat_bubble](../_/chat_bubble.svg)) enthalten, was
  Frontend-User schreiben: Kommentare, Anzeigen, Bilder.

Sie bearbeiten, suchen und exportieren diese Datensätze wie alle anderen. Die
Detailansicht nennt, welchem Frontend-User ein Beitrag gehört.

## Inhalte prüfen

Alles, was ein Frontend-User anlegt oder ändert, ist mit ***„Prüfung nötig“*** markiert
(Symbol ![emergency](../_/emergency.svg)), sofern Ihr Template-Programmierer das eingerichtet hat. So behalten Sie den Überblick, was noch geprüft werden muss:

- Das Widget ***„Ungeprüfte Inhalte von Frontend-Usern“*** auf der Startseite listet alle
  markierten Datensätze (einrichten in den [persönlichen Einstellungen](./03-arbeitsoberflaeche.md#widgets-der-startseite)).
- In der Datensatzliste zeigt das Menü der Status-Spalte mit
  „Ungeprüft“ nur die markierten Datensätze.

Haben Sie einen Beitrag geprüft, schalten Sie „Prüfung nötig“ in der Eingabemaske
aus. Ist ein Beitrag nicht in Ordnung, deaktivieren Sie ihn über den Status oder
löschen ihn.

## Datenexport personenbezogener Daten

Möchte ein Website-User wissen, welche Daten über ihn gespeichert sind (Art. 15
DSGVO), oder verlangt er eine Kopie zur Mitnahme (Art. 20 DSGVO), erstellen Sie einen
***Datenexport***:

1. Öffnen Sie den Profil-Datensatz in der Detailansicht und wählen Sie im Menü rechts
   ![more_vert](../_/more_vert.svg) ***„Datenexport personenbezogener Daten“*** – oder öffnen Sie das Modul
   „Datenschutz“ ![shield](../_/shield.svg) → „Datenexport“,
   suchen das Profil mit ![search](../_/search.svg) und haken es in der Liste an. Ein
   Profil, das schon im Papierkorb liegt, wählen Sie darunter aus der Liste „oder ein
   Profil im Papierkorb“.
2. Prüfen Sie die Person. Gibt es für die Instanz Vorlagen in mehreren Sprachen,
   wählen Sie die ***Sprache der Auskunft***. Fehlt eine Vorlage, lässt sich noch keine
   Auskunft erstellen – wenden Sie sich an Ihren Administrator.
3. Lesen Sie den Warnhinweis: Die Auskunft darf nur eine dazu berechtigte Person
   erstellen (in der Regel der Datenschutzbeauftragte), und der User erfährt in jeder
   späteren Auskunft davon. Kreuzen Sie ***„Ich bin berechtigt, die Datenauskunft zu
   erstellen.“*** an und klicken Sie auf ***„Datenauskunft erstellen“***.
4. Laden Sie das Zip über ***„Herunterladen“*** – der Link gilt genau einmal und
   höchstens einen Tag.

Das Zip enthält `report.html` (lesbar, zum Ausdrucken oder Versenden), `data.json`
(dieselben Daten maschinenlesbar) und den Ordner `files/` mit den hochgeladenen
Dateien. Enthalten sind alle Felder des Profils, die Beiträge des Users nach Bereich,
endgültig gelöschte Beiträge, die das Änderungsprotokoll noch kennt, Datensätze, die
auf sein Profil verweisen (etwa Bestellungen), Geräte und Sitzungen samt
Speicherdauer, fehlgeschlagene Anmeldeversuche, das Änderungsprotokoll, die
Speicherfristen und was beim Löschen des Profils geschieht. Namen anderer Personen
erscheinen darin nur als „Admin“ oder „User“.

Gut zu wissen:

- Der Export ist ein ***Teilauszug***. Ergänzen Sie ihn vor der Weitergabe – etwa um
  die Rechtsgrundlagen, Server-Logfiles und Daten aus anderen Systemen (Buchhaltung,
  E-Mail). Fragen Sie im Zweifel Ihren Datenschutzbeauftragten.
- Die Auskunft entsteht in der Sprache der gewählten Vorlage – unabhängig von Ihrer
  Backend-Sprache. Wählen Sie also die Sprache des Users, sofern es dafür eine Vorlage gibt.
- Das Zip enthält personenbezogene Daten. Geben Sie es nur über einen sicheren Weg
  weiter – nicht als einfachen E-Mail-Anhang an eine Adresse, deren Inhaber Sie nicht
  geprüft haben.
- Antworten Sie innerhalb eines Monats. Jeder Export wird im Audit-Log vermerkt.
- Den Menüpunkt sehen Sie nur mit Zugriff auf das Modul „Datenschutz“ und dem Recht
  „Personenbezogene Daten exportieren“. Fehlt es Ihnen, wenden Sie sich an Ihren
  Administrator.

## Ein Profil löschen

Wie bei jedem Datensatz bestimmt die Lösch-Policy des Profil-Bereichs, was beim Löschen
geschieht; die Rückfrage zeigt es vorher – auch, was mit den Beiträgen des Users
passiert. Mit der Standard-Policy kommen Profil und Beiträge in den Papierkorb und
werden nach 7 Tagen endgültig gelöscht; bekannte Geräte und Sitzungen des Users sind
sofort weg. Verlangt der User die sofortige Löschung, kann ein Administrator mit dem
passenden Recht seinen Papierkorb gleich leeren.

## Registrierung und Status

Nach der Registrierung hat ein Profil zunächst den Status „Unbestätigt“, bis der Frontend-User den Link
in seiner Bestätigungs-E-Mail anklickt. Erst dann kann er sich anmelden. Über den
Status können Sie ein Profil auch von Hand freischalten oder sperren.
