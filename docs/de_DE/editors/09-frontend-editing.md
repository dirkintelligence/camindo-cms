# 9 Frontend-Editing und Vorschau

## Frontend-Editing

Mit ***Frontend-Editing*** bearbeiten Sie Inhalte direkt beim Surfen auf der Website:
Neben den Inhalten erscheinen Bearbeiten-Markierungen. Ein Klick darauf öffnet das
Backend mit der Eingabemaske genau dieses Datensatzes – ideal, um einen Tippfehler zu
korrigieren, den Sie gerade auf der Website entdeckt haben.

Sie schalten Frontend-Editing in den [persönlichen Einstellungen](./03-arbeitsoberflaeche.md#persönliche-einstellungen)
ein:

| Einstellung | Wirkung |
|---|---|
| „aus“ | keine Markierungen |
| „ein – bis zur Abmeldung …“ | Markierungen, bis Sie sich abmelden oder den Browser schließen |
| „ein – dauerhaft …“ | Markierungen auch nach der Abmeldung |

Die Einstellung gilt nur für den Browser, in dem Sie sie vorgenommen haben. Läuft
Ihr Backend unter einer anderen Adresse als die Websites (etwa `cms.example.com`),
überträgt das Speichern einer geänderten Einstellung sie auf alle Websites: Der
Browser ruft jede Website kurz auf und kehrt danach ins Backend zurück. Websites, die
nicht erreichbar sind, werden übersprungen. Unter „Frontend-Editing auf den Websites“
wiederholt die Schaltfläche ***„Auf allen Websites übernehmen“*** diesen Abgleich, etwa in
einem anderen Browser oder wenn die Markierungen verschwunden sind. Die Schaltflächen mit
einer Domain öffnen nur diese Website in einem neuen Tab. Auch eine Vorschau schaltet
die Markierungen auf ihrer Website ein. Besucher der Website sehen die Markierungen nie.
Wo Markierungen erscheinen, legt der Template-Programmierer fest. Zum Bearbeiten brauchen
Sie weiterhin eine gültige Anmeldung und die nötigen Rechte.

## Vorschau

Die Schaltfläche ![visibility](../_/visibility.svg) „Vorschau“ in der Detailansicht zeigt einen
Datensatz so, wie die Website ihn darstellt – mit seinem Template und in der gewählten
Sprachversion. Anders als die Website zeigt die Vorschau den Datensatz auch dann,
wenn er noch nicht veröffentlicht ist: deaktiviert, vordatiert oder abgelaufen. So
können Sie einen Artikel prüfen, bevor Sie ihn freischalten.

Listen innerhalb der Vorschauseite – etwa „Weitere Artikel“ – zeigen dagegen auch in der
Vorschau nur veröffentlichte Datensätze, genau wie auf der Website.

Die Vorschau ist nur mit gültiger Backend-Anmeldung erreichbar und wird von
Suchmaschinen nicht erfasst. Läuft Ihr Backend unter einer anderen Adresse als die
Website, öffnet sich die Vorschau unter der Hauptadresse der Website – nur dort sind
auch die Bilder abrufbar. Der Link gilt dann nur, solange Sie im Backend angemeldet
sind, und höchstens acht Stunden.
