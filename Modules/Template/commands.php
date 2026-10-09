<?php
/**
 * Befehlsdefinitionen der CMS-Template-Sprache.
 *
 * Diese Datei ist reine Beschreibung - sie enthält keinen ausführbaren Code, sondern
 * gibt ein Array zurück, in dem für jeden Befehl steht, wie er heißt, welche Parameter
 * er kennt und welcher PHP-Aufruf daraus wird. Der Parser (TemplateParserTrait) kennt
 * keinen einzigen Befehl; ein neuer Befehl bedeutet einen Eintrag hier und eine
 * passende Methode in Cms, Pagination bzw. RecordUser.
 *
 * WAS AUS EINEM BEFEHL WIRD
 * --------------------
 * Aus  (CMS:TEXT field=titel prefix="<h1>")  erzeugt der Parser sinngemäß
 *
 *     <?php $_a = []; $_a["field"]="titel"; $_a["prefix"]="<h1>";
 *           $_r = $this->cms->cmsText($_a); ?>
 *
 * Alle Parameter landen also im Array $_a und gehen als einziges Argument an die in
 * 'method' genannte Methode.
 *
 * SCHLÜSSEL EINER BEFEHLSDEFINITION
 * --------------------
 *     method       Zielmethode als 'klasse.methode'; 'cms.' meint $this->cms.
 *                  Fehlt der Punkt, wird 'cms' angenommen. Zur Verfügung stehen
 *                  cms, db (immer da) sowie pagination, recordUser und email -
 *                  die drei werden erst beim ersten Zugriff nachgeladen, ein
 *                  Template ohne den passenden Befehl fasst sie also nie an.
 *                  Siehe $lazyServices in Camindo\Core\Page.
 *     do           Name des Parameters, unter dem ein Wert OHNE Schlüssel abgelegt
 *                  wird. Damit lässt sich (CMS:TEXT titel) statt
 *                  (CMS:TEXT field=titel) schreiben. Höchstens einer je Befehl.
 *     oneof        Kommaliste: mindestens einer dieser Parameter muss angegeben sein.
 *                  Für Befehle, die mehrere gleichwertige Quellen kennen - CMS:TEXT
 *                  etwa braucht 'field', 'useval' oder 'id'.
 *     params       die erlaubten Parameter (siehe unten)
 *     no_action    true: es wird KEIN Methodenaufruf erzeugt. Nur für Befehle, deren
 *                  Wirkung ausschließlich in den PHP-Schnipseln steckt -
 *                  CMS:RESULT_START und CMS:RESULT_END sind Schleifenklammern.
 *     php_prefix   Name(n) von Dateien aus Modules/Template/php/, deren Inhalt VOR dem
 *     php_suffix   bzw. NACH dem Methodenaufruf eingesetzt wird. Vorhanden sind
 *                  push_result, pop_result, loop_start, loop_end, include,
 *                  include_cached (nur CMS:INCLUDE - schließt den Ausgabe-Cache mit
 *                  Cms::includeEnd() ab), include_and_stop, select_count (nur
 *                  CMS:SELECT - übernimmt mit hits=false die Gesamtzahl,
 *                  Cms::selectCountEnd()).
 *     buffer_set   true: der Parameter 'set' fängt die GESAMTE Ausgabe des Befehls
 *                  samt php_suffix (eingebundenes Template, Ergebnisschleife) ab und
 *                  legt sie in $set[...] - Cms::setBufferStart()/setBufferEnd()
 *                  (CMS:SELECT, CMS:SELECT_LANGVERSION, CMS:INCLUDE).
 *     note         Kurzbeschreibung für die Befehlsreferenz (vom Parser nicht gelesen)
 *
 * SCHLÜSSEL EINES PARAMETERS
 * --------------------
 *     mandatory    Zwingend erforderlich? Der Parser meldet das Fehlen als Fehler.
 *     subparam     erlaubte Tiefe von Unterschlüsseln: 0 = keine (nur key=wert),
 *                  1 = key['a']=wert, 2 = key['a']['b']=wert. Leere Klammern (key[]=…)
 *                  zählen nicht als Ebene, sie erzwingen nur das Anhängen an ein Array.
 *                  Gebraucht wird das vor allem von CMS:SELECT (filter, sort, …).
 *     default      Standardwert - zur Dokumentation; eingesetzt wird er nicht hier,
 *                  sondern in der aufgerufenen Methode.
 *     operator     true: zwischen Schlüssel und Wert darf statt '=' ein Vergleichsoperator
 *                  stehen (filter[preis] >= 100); der Parser stellt ihn dem Wert voran
 *                  (TemplateParserTrait::operatorPrefix()). Bei allen anderen
 *                  Parametern meldet er einen Operator als Fehler. Gesetzt bei 'filter'
 *                  (CMS:SELECT, CMS:ZIP, CMS:USER_AUTH, CMS:USER_PROTECT).
 *     php_value    true: ein ungequoteter Wert, der nur aus einer Variablen besteht
 *                  ($params, $cms['id'], {$set['x']}), wird unverändert als PHP-Ausdruck
 *                  übergeben statt in einen String interpoliert - Arrays und Zahlen
 *                  kommen so typtreu an (CMS:INCLUDE cacheparams).
 *     ref          Der Wert verweist auf einen anderen Datensatz - Grundlage für
 *                  TemplateParserTrait::mapReferences() (Export/Import von
 *                  Template-Sätzen): 'template' (Name oder Id), 'area' (Name oder
 *                  Id), 'record' (Datensatz-Id; ein Nicht-Zahlenwert ist ein Slug
 *                  und bleibt). Bei Parametern mit Unterschlüsseln ein Array
 *                  erster Unterschlüssel => Typ, z. B. filter: ['area' => 'area',
 *                  'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'].
 *     value        Art des erwarteten Wertes, für die Befehlsreferenz
 *     description  Beschreibung, für die Befehlsreferenz
 *     extra_*      Steuerungsangaben für die Befehlsreferenz
 *
 * Vom Parser tatsächlich ausgewertet werden nur method, do, oneof, params,
 * params[*].mandatory, params[*].subparam, params[*].operator, params[*].php_value,
 * no_action, php_prefix, php_suffix und buffer_set.
 * Alles andere - note, value, description, default, extra_*, include - ist
 * Dokumentation für die (noch zu bauende) Befehlsreferenz im Backend; 'include' ist in
 * allen Einträgen ein leeres Array.
 *
 * WIEDERKEHRENDE PARAMETER
 * --------------------
 * Viele Ausgabebefehle teilen sich dieselben Zusatzparameter:
 *
 *     ifempty   Ersatztext, wenn die Quelle leer ist
 *     instead   Text ANSTELLE der Quelle, wenn diese nicht leer ist
 *     prefix    wird vorangestellt, aber nur bei nicht leerem Wert
 *     suffix    dito, angehängt
 *     set       Ergebnis nicht ausgeben, sondern in $set[...] ablegen
 *     replace   Teiltexte ersetzen ("suchen?ersetzen" oder replace[suchen]=ersetzen);
 *               TEXT, DATE, DATETIME, TIME, NUMBER - bei den Format-Befehlen auf dem
 *               fertig formatierten Wert
 *     id        Datensatz, auf den sich der Befehl bezieht - ohne Angabe der gerade
 *               laufende. Der Wert ist dasselbe, was auch in der URL stehen kann:
 *               eine reine Ziffernfolge ist eine record_id, alles andere ein Slug
 *               (der Nachfolger des C8-'idname'). Ausgewertet in Cms::_record().
 *               Bei CMS:SELECT läuft das stattdessen über 'filter'.
 *
 * Das Muster "prefix/suffix nur bei nicht leerem Wert" erspart im Template die
 * Fallunterscheidung: ein <h1> wird nicht ausgegeben, wenn es leer bliebe.
 *
 * DIE BEFEHLE (alphabetisch, so wie sie hier stehen)
 * --------------------
 *     Ausgabe        text, raw, number, date, datetime, time, media, url, email, xml,
 *                    message
 *     Verknüpfungen  relation
 *     Abfragen       select, select_langversion, result_start, result_end
 *     Navigation     jump_list, jump_next, jump_prev
 *     Steuerung      include, continue, header, savedata
 *     Frontend-Edit  fed, fed_code
 *     Community      user_auth, user_basket, user_delete, user_input, user_login,
 *                    user_logout, user_protect, user_pw, user_register,
 *                    user_register_confirm
 *
 * Die CMS:USER_*-Befehle laufen in Camindo\Core\Modules\Record\RecordUser
 * (Sessions: Camindo\Core\UserSession) - Semantik, Berechtigungen und Meldungen
 * in docs/developers/11-community.md.
 *
 * @see Camindo\Core\Modules\Template\TemplateParserTrait  wertet diese Datei aus
 * @see Camindo\Core\Cms                                     die cms*-Methoden
 * @see Camindo\Core\Pagination                              die CMS:JUMP_*-Methoden
 * @see Camindo\Core\Modules\Record\RecordUser              die CMS:USER_*-Methoden
 * @see docs/developers/06-templates.md                     Syntax und Compiler
 * @see docs/templateprogrammers/README.md                            Befehlsreferenz (eine Seite je Befehl)
 */

return [

    // Setzt die Abarbeitung fort, nachdem ein Befehl mit eigenem Erfolgs-Template
    // (z. B. aus der user_*-Gruppe) gegriffen hat - ohne CONTINUE endet das Template
    // an dieser Stelle.
    'continue' => [
        'method' => 'cms.cmsContinue',
        'note' => 'Setzt die Template-Abarbeitung trotz eines Success-Templates fort.',
        'do' => 'field',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    // DATE, DATETIME und TIME sind derselbe Befehl mit unterschiedlichem Ausschnitt:
    // DATE zeigt nur den Datumsteil, TIME nur die Uhrzeit, DATETIME beides. Die Quelle
    // ist in allen drei Fällen ein Datums-/Zeitwert aus der Datenbank (ISO-Format).
    'date' => [
        'method' => 'cms.cmsDate',
        'note' => 'Gibt den Datumsteil eines Datumswertes formatiert aus.',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datumswert',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Formatangaben',
                'description' => 'ICU-Muster, z. B. "dd.MM.y". Ohne Angabe entscheidet die Locale der Seite über die Schreibweise.'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Name der Zeitzone wird angehängt (z. B. "Europe/Berlin").'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. "Jan?Januar").'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, falls die Quelle (\'field\' oder \'useval\') leer ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der anstelle der Quelle ausgegeben wird, sofern diese nicht leer ist.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'datetime' => [
        'method' => 'cms.cmsDatetime',
        'note' => 'Gibt Datum und Uhrzeit eines Datumswertes formatiert aus.',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datumswert',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Formatangaben',
                'description' => 'ICU-Muster, z. B. "dd.MM.y HH:mm". Ohne Angabe entscheidet die Locale der Seite über die Schreibweise.'
            ],
            'autotime' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Wenn keine Uhrzeit vorhanden ist (00:00), wird diese nicht angezeigt.'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Name der Zeitzone wird angehängt (z. B. "Europe/Berlin").'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. "Jan?Januar").'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, falls die Quelle (\'field\' oder \'useval\') leer ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der anstelle der Quelle ausgegeben wird, sofern diese nicht leer ist.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'email' => [
        'method' => 'email.cmsEmail',
        'php_suffix' => 'include_and_stop',
        'note' => 'Versendet eine E-Mail. Prüft auf Wunsch vorab die Pflichtfelder eines Formulars.',
        'do' => 'do',
        'oneof' => 'subject,subjecttemplate,body,bodytemplate',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false / Sekunden',
                'description' => 'true: Formularschutz gegen Robots - das Formular muss die Felder aus (CMS:USER_TOKEN formguard=true) enthalten (Zeitstempel, leeres Köderfeld) und darf frühestens nach user.formguard_min_seconds (3) abgeschickt werden. Eine Zahl setzt diese Mindestzeit für das Formular (formguard=5); 0 oder false schaltet den Schutz ab. Sonst Meldung "throttled", wie bei der Drosselung.'
            ],
            'throttle' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'true / Bucket-Name',
                'description' => 'Drosselung je Herkunft (IP): true = Grenze user.throttle.email_ip (5 je Stunde), sonst ein eigener Bucket aus user.throttle. Gedrosselt: Meldung "throttled", keine E-Mail. Für Kontaktformulare, in die jeder eine Adresse eintragen kann.'
            ],
            'to' => [
                'mandatory' => true,
                'subparam' => 1,
                'default' => '',
                'value' => 'E-Mail-Adresse',
                'description' => 'Empfänger-E-Mail-Adresse. Mehrere Empfänger als to[]=… oder kommasepariert (höchstens 20).'
            ],
            'toname' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Empfängername (nur bei genau einem Empfänger).'
            ],
            'from' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'email.noreply bzw. email.reply (.camindo.php)',
                'value' => 'E-Mail-Adresse',
                'description' => 'Absender-E-Mail-Adresse.'
            ],
            'fromname' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Absendername.'
            ],
            'contenttype' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'text/plain',
                'value' => 'text/plain oder text/html',
                'description' => 'MIME-Type der E-Mail.'
            ],
            'subject' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(no subject)',
                'value' => 'Text',
                'description' => 'Betreff der E-Mail.'
            ],
            'subjecttemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Template-Name',
                'description' => 'Template für den Betreff (Tags und Zeilenumbrüche fallen weg). Hat Vorrang vor subject.'
            ],
            'body' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Nachrichtentext (Text oder HTML).'
            ],
            'bodytemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Template-Name',
                'description' => 'Template für den E-Mail-Body. Hat Vorrang vor body.'
            ],
            'paths_absolute' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Alle serverrelativen Pfade im E-Mail-Body werden in absolute Pfade umgewandelt (nur bei contenttype="text/html").'
            ],
            'sender_url' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'Protokoll und Hauptdomain der Instanz',
                'value' => '(http://)Domain',
                'description' => '(Optional mit Protokoll) + Domain, um serverrelative Pfade in absolute Pfade wandeln zu können.',
                'extra_1' => 0,
                'extra_2' => ''
            ],
            'debug' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: E-Mail wird in das Logfile var/log/{Datum}_mail-debug.log geschrieben, aber nicht versendet.'
            ],
            'requiredfields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feldnamen, kommasepariert',
                'description' => 'Angabe der Pflichtfelder. Feldnamen (Index-Namen) im Request-Array data[…] (siehe Parameter data).'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des Request-Arrays, das die von \'requiredfields\' erwarteten Daten enthält (Standard data, also $_POST[\'data\']).'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => '\'requiredfields\' wird nur ausgewertet, wenn \'submit\' einen Wert enthält. Ansonsten wird der CMS:EMAIL-Befehl ignoriert.'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Template, das nach erfolgreichem Absenden eingebunden wird; danach endet die Seite (außer mit CMS:CONTINUE).'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text',
                'description' => 'Fehlermeldung. Im Index: Anwendungsfall (required, to, from, content, attachment, send); im Wert: Meldungstext.'
            ],
            'attachment' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '',
                'description' => 'Dateien, die angehängt werden sollen. Pfad relativ zum var-Verzeichnis, nur aus download/, upload/ und modules/.'
            ]
        ],
        'include' => []
    ],

    // FED, FED_SCRIPT und FED_CODE gehören zum Frontend-Editing: sie geben nur dann
    // etwas aus, wenn der Betrachter es in seinen persönlichen Einstellungen
    // eingeschaltet hat (Cookie 'fed', siehe Cms::fedActive()). FED erzeugt den
    // Bearbeiten-Knopf, FED_SCRIPT die zugehörige Javascript-Funktion cFED()
    // (einmal je Seite, üblicherweise im Footer), FED_CODE beliebiges HTML (etwa
    // eine Werkzeugleiste).
    'fed' => [
        'method' => 'cms.cmsFed',
        'note' => 'Erzeugt einen Knopf zum Bearbeiten eines Datensatzes, wenn das Frontend-Editing aktiviert ist. Braucht CMS:FED_SCRIPT auf derselben Seite.',
        'do' => 'id',
        'oneof' => '',
        'params' => [
            'label' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '[&raquo;]',
                'value' => 'Text',
                'description' => 'Beschriftung des Knopfes.'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, der geöffnet wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'info' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Knopf öffnet die Detailansicht des Datensatzes statt der Eingabemaske.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der (ohne Knopf) vor dem Knopf ausgegeben wird.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der (ohne Knopf) nach dem Knopf ausgegeben wird.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Knopf wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'fed_script' => [
        'method' => 'cms.cmsFedScript',
        'note' => 'Stellt die Javascript-Funktion cFED() und den Klick-Listener für die Knöpfe aus CMS:FED bereit (mit der Nonce der Seite) - einmal je Seite, üblicherweise im Footer.',
        'do' => 'set',
        'oneof' => '',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Script-Block wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'fed_code' => [
        'method' => 'cms.cmsFedCode',
        'note' => 'Blendet HTML-Code ein, wenn das Frontend-Editing aktiviert ist.',
        'do' => 'code',
        'oneof' => '',
        'params' => [
            'code' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'HTML-Code',
                'description' => 'HTML-Code, der ausgegeben werden soll, wenn das Frontend-Editing eingeschaltet ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'HTML-Code',
                'description' => 'Alternative Ausgabe, wenn das Frontend-Editing ausgeschaltet ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Code wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'header' => [
        'method' => 'cms.cmsHeader',
        'note' => 'Sendet einen HTTP-Header.',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Textzeile, die als Header an den Browser gesendet werden soll. Ohne Wert ("Name:") wird der Header entfernt.'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'false: Ein vorausgegangener Header desselben Typs wird nicht überschrieben; es wird ein neuer Header hinzugefügt.'
            ]
        ],
        'include' => []
    ],

    'csp' => [
        'method' => 'cms.cmsCsp',
        'note' => 'Ändert die Script-Policy (Content-Security-Policy) dieser Seite: weitere Script-Quellen, Formularziele oder ein anderer Modus. Muss vor der ersten Ausgabe stehen.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'script' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Quellen',
                'description' => 'Weitere Script-Quellen, durch Leerzeichen oder Komma getrennt (https://js.example.com, *.example.com). Schlüsselwörter wie \'unsafe-inline\' werden verworfen.'
            ],
            'form' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Quellen',
                'description' => 'Weitere Formularziele (schaltet form-action ein), z. B. der Zahlungsanbieter.'
            ],
            'mode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'off / report / enforce',
                'description' => 'Modus für diese Seite: off = keine Script-Policy, report = nur melden, enforce = durchsetzen. Leer: Einstellung der Instanz.'
            ],
        ],
        'include' => []
    ],

    'include' => [
        'method' => 'cms.cmsInclude',
        'buffer_set' => true,
        'php_suffix' => 'include_cached',
        'note' => 'Bindet ein Template ein - mit caching aus dem Ausgabe-Cache (IncludeCache).',
        'do' => 'template',
        'oneof' => '',
        'params' => [
            'caching' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Sekunden, 30s, 5m, 1h, 1d oder infinite',
                'description' => 'Die Ausgabe des Templates wird so lange zwischengespeichert und danach ohne erneute Abarbeitung ausgeliefert. Ohne Angabe, leer oder 0: kein Cache.'
            ],
            'cacheparams' => [
                'mandatory' => false,
                'subparam' => 0,
                'php_value' => true,
                'default' => '',
                'value' => 'null, bool, int, string oder Array (auch als Variable: cacheparams=$params)',
                'description' => 'Alles, wovon die Ausgabe abhängt (Datensatz, Seite, Kunde …): je Wert entsteht eine eigene Cache-Datei. Instanz und Sprache zählen von selbst mit.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ],
            'template' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Detailtemplate, das eingebunden wird.'
            ]
        ],
        'include' => []
    ],

    'jump_list' => [
        'method' => 'pagination.cmsJumpList',
        'note' => 'Erzeugt eine Blätter-Navigation (Pagination), z. B. 1 … 5 | 6 | 7 | 8 | 9 … 15.',
        'do' => 'separator',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => 'Zahl >= 0',
                'description' => 'Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip.'
            ],
            'maximum' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '7',
                'value' => 'Zahl >= 1',
                'description' => 'Anzahl der gleichzeitig angezeigten (mitwandernden) Seitenverweise, ohne die Verweise auf erste und letzte Seite.'
            ],
            'boundaries' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Verweise auf die erste und die letzte Seite werden immer angezeigt, sobald sie außerhalb des mitwandernden Bereichs liegen.'
            ],
            'separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' ',
                'value' => 'Trennzeichen',
                'description' => 'Text zwischen zwei Seitenverweisen. Vor und hinter der Auslassung (\'ellipsis\') wird er nicht eingefügt.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;a href="{href}"&gt;{page}&lt;/a&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für einen Seitenverweis. {page} wird durch die Seitennummer ersetzt, {href} durch die Seiten-URL.'
            ],
            'tpl_active' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;strong&gt;{page}&lt;/strong&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für die aktuelle Seite. {page} wird durch die Seitennummer ersetzt, {href} durch die Seiten-URL.'
            ],
            'ellipsis' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' … ',
                'value' => 'Code',
                'description' => 'Platzhalter für ausgelassene Seiten - erscheint zwischen einem Boundary-Verweis und dem mitwandernden Bereich.'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen".'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text vor der Navigation, nur wenn eine Navigation ausgegeben wird.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text nach der Navigation, nur wenn eine Navigation ausgegeben wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Ausgabe, wenn das Ergebnis auf eine einzige Seite passt oder das CMS:SELECT kein limit hat und deshalb keine Navigation nötig ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Navigation wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'jump_next' => [
        'method' => 'pagination.cmsJumpNext',
        'note' => 'Erzeugt einen Weiterblättern-Verweis.',
        'do' => 'tpl_link',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => 'Zahl >= 0',
                'description' => 'Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip.'
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;a href="{href}"&gt;&lt;strong&gt;❯❯&lt;/strong&gt;&lt;/a&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für den Verweis, solange es eine nächste Seite gibt. {href} wird durch die Ziel-URL ersetzt.'
            ],
            'tpl_inactive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;span&gt;❯❯&lt;/span&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für den nicht anwählbaren Verweis, wenn es keine nächste Seite gibt. {href} wird ebenfalls ersetzt.'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen".'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text vor dem Verweis, nur wenn ein Verweis ausgegeben wird.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text nach dem Verweis, nur wenn ein Verweis ausgegeben wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Ausgabe, wenn das CMS:SELECT kein limit hat (dann gibt es keine Seiten). Passt alles auf eine Seite, erscheint tpl_inactive.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Verweis wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'jump_prev' => [
        'method' => 'pagination.cmsJumpPrev',
        'note' => 'Erzeugt einen Zurückblättern-Verweis.',
        'do' => 'tpl_link',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => 'Zahl >= 0',
                'description' => 'Versatz der aktuellen Seite (Anzahl übersprungener Ergebnisse). Ohne Angabe gilt der Request-Parameter skip.'
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;a href="{href}"&gt;&lt;strong&gt;❮❮&lt;/strong&gt;&lt;/a&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für den Verweis, solange es eine vorherige Seite gibt. {href} wird durch die Ziel-URL ersetzt.'
            ],
            'tpl_inactive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' &lt;span&gt;❮❮&lt;/span&gt; ',
                'value' => 'HTML-Vorlage',
                'description' => 'Vorlage für den nicht anwählbaren Verweis, wenn es keine vorherige Seite gibt. {href} wird ebenfalls ersetzt.'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Einfache Textersetzung im fertigen HTML. Trenner ist das Fragezeichen: "suchen?ersetzen".'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text vor dem Verweis, nur wenn ein Verweis ausgegeben wird.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text nach dem Verweis, nur wenn ein Verweis ausgegeben wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Ausgabe, wenn das CMS:SELECT kein limit hat (dann gibt es keine Seiten). Passt alles auf eine Seite, erscheint tpl_inactive.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Verweis wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'media' => [
        'method' => 'cms.cmsMedia',
        'note' => 'Erzeugt ein Image-Tag oder gibt den Inhalt einer Mediendatei in Textform aus.',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Name eines Upload- oder Thumbnail-Feldes.'
            ],
            'subfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'path / file / filename / mime_type / extension / size / width / height / version',
                'description' => 'Einzelwert statt IMG-Tag: path (Pfad ab DOCUMENT_ROOT für src/href, mit sprechendem Dateinamen; bei auth/download/user_auth über file.php), file (absoluter Pfad im Dateisystem), filename (ursprünglicher Dateiname), mime_type, extension (ohne Punkt), size (Bytes; mit autoformat lesbar), width / height (Pixel, 0 wenn unbekannt), version (Versionszähler der Datei).'
            ],
            'auth' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Verweis zeigt auf /file.php statt ins Medienverzeichnis. Für Felder mit "Nur für angemeldete Frontend-User" (user_auth) gilt das automatisch - file.php prüft dort die Frontend-Session.'
            ],
            'download' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Datei wird über /file.php unter dem angegebenen Dateinamen zum Download angeboten (Dateiendung wird automatisch ergänzt).'
            ],
            'width' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Breite des IMG-Tags, mit oder ohne CSS-Einheit (Standard: px).'
            ],
            'height' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Höhe des IMG-Tags, mit oder ohne CSS-Einheit (Standard: px).'
            ],
            'attr' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Array',
                'description' => 'Zusätzliche Attribute für das IMG-Tag (Schlüssel: Attributname, Wert: Attributwert).'
            ],
            'autoformat' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der via \'subfield\' gewählte Inhalt wird je nach Typ formatiert (z. B. Dateigrößen in Bytes/KB/MB dynamisch nach Wert).',
                'extra_1' => 'text',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Überschreibt den formatierten Wert, sofern dieser nicht leer ist.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Alternativtext, falls der formatierte Wert leer (oder "0") ist.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern der formatierte Wert nicht leer (oder "0") ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern der formatierte Wert nicht leer (oder "0") ist.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array',
                'description' => 'Datei-Array (etwa aus CMS:SAVEDATA) samt field_id und record_id - nur per PHP-Aufruf, ohne field (siehe Beispiel).'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'message' => [
        'method' => 'cms.cmsMessage',
        'note' => 'Erzeugt eine Textausgabe aus aktuellen Fehlermeldungen.',
        'do' => 'useval',
        'oneof' => '',
        'params' => [
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vor den Meldungen ausgegeben wird, sofern es welche gibt.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der nach den Meldungen ausgegeben wird, sofern es welche gibt.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, falls keine Meldung vorhanden ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der anstelle der Meldungen ausgegeben wird.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Meldung wird nicht angezeigt, sondern in die Variable $set geschrieben.'
            ]
        ],
        'include' => []
    ],

    'nonce' => [
        'method' => 'cms.cmsNonce',
        'note' => 'Gibt die Nonce der Seite aus: <script(CMS:NONCE)>. Jedes Script im Template braucht sie, sobald die Script-Policy der Website durchgesetzt wird.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '"attribute"',
                'value' => '"attribute" / "value"',
                'description' => 'attribute: nonce="…" (mit führendem Leerzeichen, für ein <script>-Tag). value: nur der Wert.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Ausgabe wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ],
        ],
        'include' => []
    ],

    'number' => [
        'method' => 'cms.cmsNumber',
        'note' => 'Gibt einen Zahlenwert formatiert aus.',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zahl',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, wenn die Quelle leer ist oder der Zahlenwert (nach mul/add) 0 ergibt.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zahl',
                'description' => 'Zahl, die zur Quelle wird, sofern die ursprüngliche Quelle nicht leer ist.'
            ],
            'mul' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1',
                'value' => 'Zahl',
                'description' => 'Zahl, mit der die Quelle multipliziert wird.'
            ],
            'add' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => 'Zahl',
                'description' => 'Zahl, die zur Quelle addiert wird.'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Formatierung',
                'description' => 'Formatierung analog zum PHP-Befehl sprintf().'
            ],
            'decimals' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zahl 0-10',
                'description' => 'Feste Anzahl Nachkommastellen (gerundet); decimal und thousands_separator wirken weiter. Ohne Angabe so viele, wie die Zahl hat.'
            ],
            'decimal' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '.',
                'value' => 'Zeichen',
                'description' => 'Zeichen, durch das der Dezimalpunkt ersetzt wird.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'thousands_separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zeichen',
                'description' => 'Zeichen, das als Tausendertrennzeichen (alle drei Stellen von rechts nach links) eingefügt wird.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'nullcent' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, durch den ein fehlender Nachkommawert ersetzt wird.'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. ".00?" entfernt leere Nachkommastellen).'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der dem formatierten Wert vorangestellt wird, sofern dieser nicht "0" oder leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der dem formatierten Wert angehängt wird, sofern dieser nicht "0" oder leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern stattdessen in der Variable $set gespeichert.'
            ],
            'sizefactor' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Anzahl Bytes eines Kilobytes',
                'description' => '1000 oder 1024. Wenn > 0, wird eine lesbare Dateigröße berechnet (z. B. 15.2 GB).'
            ]
        ],
        'include' => []
    ],

    // RAW gibt den Feldinhalt ohne jede Aufbereitung aus - kein Maskieren, keine
    // Umbruch-Umwandlung. Gedacht für Felder, die bereits HTML enthalten; entsprechend
    // muss man dem Inhalt vertrauen.
    'raw' => [
        'method' => 'cms.cmsRaw',
        'note' => 'Gibt einen Inhalt unformatiert aus.',
        'do' => 'field',
        'oneof' => '',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name des Feldes, dessen Inhalt ausgegeben wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, falls das Feld leer ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der anstelle des Feldinhalts ausgegeben wird, sofern dieser nicht leer ist.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern der Feldinhalt nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern der Feldinhalt nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Inhalt wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'relation' => [
        'method' => 'cms.cmsRelation',
        'note' => 'Gibt das Haupt-Feld (Titel) aller Datensätze aus, die mit dem aktuellen Datensatz verknüpft sind.',
        'do' => 'field',
        'oneof' => '',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, dessen Verweise ausgegeben werden. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name des Verweisfeldes (Feldtyp relation, pointer oder link), dessen Ziele ausgegeben werden. Ein anderer Feldtyp ergibt eine Fehlermeldung.'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1000',
                'value' => 'Zahl &gt;0',
                'description' => 'Maximale Anzahl der einbezogenen Datensätze in der Ergebnismenge.'
            ],
            'future' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Auch vordatierte Ziele (online_at noch nicht erreicht) werden ausgegeben (wie bei CMS:SELECT).'
            ],
            'archive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Auch abgelaufene Ziele (offline_at erreicht) werden ausgegeben (wie bei CMS:SELECT).'
            ],
            'state' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => 'Status-Id oder any',
                'description' => 'Ohne Angabe nur freigegebene Ziele (status_id 0). Eine Status-Id > 0 nur Ziele mit diesem Status, "any" Ziele jeden Status (wie bei CMS:SELECT).'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktuelle Sprache)',
                'value' => 'Sprachkürzel',
                'description' => 'Kürzel der Sprachversion, deren Inhalte angefordert werden (wirkt sich auf mehrsprachige Felder aus). Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '|',
                'value' => 'Trennzeichen',
                'description' => 'Text, der zwischen den Ergebnissen stehen soll.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der der Quelle vorangestellt wird, sofern diese nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der an die Quelle angehängt wird, sofern diese nicht leer ist.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der zur Quelle wird, wenn die ursprüngliche Quelle leer ist (weitere Parameter, außer \'set\', werden ignoriert).'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Überschreibt die Ausgabe, falls diese nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Ausgabe der Quelle wird umgeleitet und in $set eingetragen.'
            ]
            // Hier fehlt noch 'id' => 
        ],
        'include' => []
    ],
    
    // RESULT_START / RESULT_END sind Klammern, keine Befehle im eigentlichen Sinn:
    // no_action verhindert den Methodenaufruf, die Wirkung steckt allein in den
    // Schnipseln php/loop_start.php und php/loop_end.php, die die foreach-Schleife
    // über die Treffer öffnen und schließen.
    'result_start' => [
        'method' => 'cms.cmsResultStart',
        'no_action' => true,
        'php_suffix' => 'loop_start',
        'note' => 'Markiert den Beginn einer Schleife zur Verarbeitung von CMS:SELECT-Ergebnissen.',
        'do' => 'do',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    'result_end' => [
        'method' => 'cms.cmsResultEnd',
        'no_action' => true,
        'php_suffix' => 'loop_end',
        'note' => 'Markiert das Ende einer Schleife zur Verarbeitung von CMS:SELECT-Ergebnissen.',
        'do' => 'do',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    'savedata' => [
        'method' => 'cms.cmsSaveData',
        'note' => 'Speichert die Daten des aktuellen Datensatzes in der angegebenen Variable.',
        'do' => 'phpvar',
        'oneof' => '',
        'params' => [
            'phpvar' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Index innerhalb $GLOBALS',
                'description' => 'Variable, in der die Daten des aktuellen Datensatzes abgelegt werden sollen.'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, dessen Daten geladen werden sollen. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. Ohne Angabe der aktuell geladene Datensatz.',
                'extra_1' => 'text',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$lang',
                'value' => 'Sprachversions-Kürzel',
                'description' => 'Gibt an, in welcher Sprache die Inhalte mehrsprachiger Felder geladen werden sollen. Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs.',
                'extra_1' => 'userlang',
                'extra_2' => 1,
                'extra_3' => ''
            ]
        ],
        'include' => []
    ],

    // SELECT ist der aufwendigste Befehl: eine Abfrage, deren Treffer an ein zweites
    // Template ('resulttemplate') übergeben werden.
    //
    // Die drei Schnipsel drumherum erklären den Ablauf:
    //     push_result   das laufende Abfrageergebnis beiseitelegen - ein SELECT kann
    //                   innerhalb einer Ergebnisschleife stehen und würde es sonst
    //                   überschreiben
    //     include       das resulttemplate einbinden, das die Treffer ausgibt
    //     pop_result    das äußere Ergebnis zurückholen, die äußere Schleife läuft
    //                   weiter
    //     select_count  nur mit hits=false: die gezählte Gesamtzahl ins aufrufende
    //                   Template übernehmen (es gibt kein resulttemplate, in dem sie
    //                   sonst stünde)
    //
    // Die Filterangaben brauchen Unterschlüssel (filter['area_id'], sort['name']),
    // deshalb steht bei ihnen subparam auf 1 bzw. 2.
    'select' => [
        'method' => 'cms.cmsSelect',
        'buffer_set' => true,
        'php_prefix' => 'push_result',
        'php_suffix' => ['include', 'pop_result', 'select_count'],
        'note' => 'Durchsucht die Datenbank anhand der angegebenen (Filter-)Kriterien und liefert die gefundenen Datensätze zurück.',
        'do' => 'resulttemplate',
        'oneof' => 'filter,relation',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ],
            'resulttemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Listentemplate, das zur Verarbeitung der Ergebnisse eingebunden wird.'
            ],
            'noresulttemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Detailtemplate, das eingebunden wird, falls die Abfrage keine Ergebnisse liefert.'
            ],
            'noresult' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der unmittelbar ausgegeben wird, falls die Abfrage keine Ergebnisse liefert.'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => 'Wert oder EMPTY',
                'description' => 'Im Index: Name des zu durchsuchenden Feldes (relation_any für ein beliebiges Verweisfeld; area für den Namen des Inhaltsbereichs, eine Zahl gilt dort als area_id). Im Wert: der zu suchende Wert.'
            ],
            'filter_mode' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'OR',
                'value' => 'AND / OR',
                'description' => 'Modus, mit dem mehrere Werte desselben Feldes verknüpft werden (filter_mode[feld]=AND).'
            ],
            'filter_op' => [
                'mandatory' => false,
                'subparam' => 2,
                'default' => '=',
                'value' => '=, ==, !=, !!, &gt;, &lt;, &gt;&gt;, &lt;&lt;, &gt;=, &lt;=',
                'description' => 'Vergleichsoperator für das angegebene Feld (filter_op[feld]=">"). Alternativ steht der Operator zwischen Schlüssel und Wert (filter[preis] > 100) oder direkt vor dem Wert (filter[preis]=">100").',
            ],
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => 'Zahl &gt;=0',
                'description' => 'Anzahl der übersprungenen Datensätze in der Ergebnismenge. Wirkt nur zusammen mit limit.'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zahl &gt;0',
                'description' => 'Maximale Anzahl der einbezogenen Datensätze in der Ergebnismenge - zugleich die Seitengröße für CMS:JUMP_LIST, CMS:JUMP_PREV und CMS:JUMP_NEXT. Ohne Angabe oder 0: unbegrenzt, keine Blätterleiste.'
            ],
            'sort' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'changed_at',
                'value' => 'Feldname(n), kommasepariert',
                'description' => 'Feld, nach dem sortiert wird (vgl. order); mehrere kommasepariert oder als sort[]=…; area sortiert nach dem Namen des Inhaltsbereichs, verweisfeld.feld nach einem Feld des verknüpften Datensatzes.'
            ],
            'order' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'asc',
                'value' => 'desc / asc',
                'description' => 'Reihenfolge, in der sortiert wird (vgl. sort). Ohne sort gilt changed_at desc.'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktuelle Sprache)',
                'value' => 'Sprachkürzel',
                'description' => 'Kürzel der Sprachversion, deren Inhalte angefordert werden (wirkt sich auf mehrsprachige Felder aus). Noch nicht umgesetzt - derzeit gilt immer die Sprache des Aufrufs.',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'relation' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => "Feldname(n) kommasepariert oder 'ANY'",
                'description' => 'Feldnamen von Beziehungs-/Verweisfeldern, in denen die gesuchten Datensätze gespeichert sind (mit src_id); mit dest_id die Felder, über die die gesuchten Datensätze auf dest_id verweisen. ANY: irgendein Verweisfeld.',
                'extra_1' => 'relationfield',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'src_id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'aktueller Datensatz',
                'value' => 'Datensatz-ID(s)',
                'description' => 'Quell-Datensatz, dessen Verknüpfungen ausgewertet werden (ohne Angabe: aktueller Datensatz).',
                'extra_1' => 'ids',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'dest_id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-ID',
                'description' => 'Rückrichtung zu relation: die Datensätze, die über relation auf diesen Datensatz verweisen (relation=ANY: über irgendein Verweisfeld). Ohne src_id wird dann kein aktueller Datensatz als Quelle eingesetzt.',
                'extra_1' => 'ids',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'nextof' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-ID',
                'description' => 'Nur mit relation: Nachbarn des angegebenen Datensatzes in der Verweisliste - die Einträge hinter ihm (order=asc) bzw. vor ihm (order=desc), in der Reihenfolge der Liste; mit limit=1 der nächste bzw. vorige Datensatz.'
            ],
            'count' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Die Anzahl der Datensätze in der Ergebnismenge wird ermittelt, unabhängig von "limit" und "skip" (im resulttemplate in $this->db->num_rows_unlimited; Grundlage der JUMP-Befehle).'
            ],
            'hits' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'false: Es wird nur gezählt (schließt count=true ein), es werden keine Datensätze geladen und kein resulttemplate eingebunden. Die Gesamtzahl steht danach im aufrufenden Template in $this->db->num_rows_unlimited; noresult/noresulttemplate gelten, wenn sie 0 ist.'
            ],
            'archive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Auch abgelaufene Datensätze (offline_at erreicht) werden in die Abfrage einbezogen.'
            ],
            'future' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Auch vordatierte Datensätze (online_at noch nicht erreicht) werden in die Abfrage einbezogen.'
            ],
            'debug' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der erzeugte Datenbank-Query wird für Support-Analysen ausgegeben.'
            ],
            'sql' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Raw SQL-Zusatz in der WHERE-Klausel (Achtung: nur für den Support!).'
            ],
            'state' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => 'Status-Id oder any',
                'description' => 'Ohne Angabe nur freigegebene Datensätze (status_id 0). Eine Status-Id > 0 findet nur Datensätze mit diesem Status, "any" Datensätze jeden Status.'
            ]
        ],
        'include' => []
    ],

    'select_langversion' => [
        'method' => 'cms.cmsSelectLanguages',
        'buffer_set' => true,
        'php_prefix' => 'push_result',
        'php_suffix' => ['include', 'pop_result'],
        'note' => 'Liefert die verfügbaren Sprachen als Datensätze zurück.',
        'do' => 'resulttemplate',
        'oneof' => '',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die gesamte Ausgabe (samt eingebundenem Template) wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ],
            'resulttemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Listentemplate, das zur Verarbeitung der Ergebnisse eingebunden wird.'
            ]
        ],
        'include' => []
    ],

    'snippet' => [
        'method' => 'cms.cmsSnippet',
        'note' => 'Gibt ein Snippet (Textbaustein) in der Sprache des Aufrufs aus.',
        'do' => 'key',
        'oneof' => '',
        'params' => [
            'key' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Snippet-Schlüssel',
                'description' => 'Schlüssel des Snippets, z. B. custom.weiterlesen (Snippet-Set der Instanz) oder ein Core-Snippet ohne Präfix.'
            ],
            'param' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text',
                'description' => 'Werte für Platzhalter: param[name]=Wert ersetzt {name} im Snippet und füllt ICU-Parameter (plural, select).'
            ],
            'specialchars' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Sonderzeichen werden in ihr HTML-Äquivalent umgewandelt (für Attribute).'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, wenn es das Snippet nicht gibt (ohne ifempty erscheint der Schlüssel).'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern das Snippet nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern das Snippet nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der Text wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'text' => [
        'method' => 'cms.cmsText',
        'note' => 'Gibt einen Text formatiert aus.',
        'do' => 'field',
        'oneof' => 'field,useval,id',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL. Ohne \'field\' wird das Feld "text" gelesen.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der zur Quelle wird, wenn die ursprüngliche Quelle leer ist (weitere Parameter, außer \'set\', werden dann ignoriert).'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der zur Quelle wird, wenn die ursprüngliche Quelle nicht leer ist.'
            ],
            'entitydecode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Entities im Format &...; werden zurück in Unicode-Zeichen gewandelt.'
            ],
            'trim' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Leerzeichen am Textanfang und -ende werden entfernt.'
            ],
            'striptags' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: HTML- und Script-Tags werden entfernt.'
            ],
            'if' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Wenn die Quelle dem Text vor dem Fragezeichen entspricht, wird der Text nach dem Fragezeichen zur neuen Quelle.'
            ],
            'else' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Trifft keine der vorherigen IF-Abfragen zu, wird dieser Wert zur Quelle.'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Kommt der Text vor dem Fragezeichen in der Quelle vor, wird er durch den Text nach dem Fragezeichen ersetzt.'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zahl &gt;=0',
                'description' => 'Kürzt den Text auf die angegebene Anzahl an Zeichen und hängt "…" an; mit angehängtem s (z. B. 200s) wird am letzten Wortende gekürzt.'
            ],
            'lbrconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Zeilenumbrüche werden in HTML-Zeilenumbrüche (&lt;br&gt;-Tags) umgewandelt.'
            ],
            'specialchars' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Sonderzeichen werden in ihr HTML-Äquivalent umgewandelt.'
            ],
            'urlconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Internet-Adressen werden automatisch in klickbare Links umgewandelt.'
            ],
            'emailconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false / encrypt',
                'description' => 'true: E-Mail-Adressen werden verlinkt. encrypt: Die Links werden mittels JavaScript leicht verschlüsselt.'
            ],
            'emaillimit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '>= 0',
                'description' => 'Kürzt automatisch verlinkte E-Mail-Adressen auf x sichtbare Zeichen.'
            ],
            'emailtext' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Verlinkter Text, der anstelle einer E-Mail-Adresse angezeigt wird (nur bei aktiviertem "emailconvert").'
            ],
            'target' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '_self',
                'value' => 'Fenster / Frame',
                'description' => 'Setzt das Link-Target für automatisch erzeugte Links (urlconvert, emailconvert).'
            ],
            'markdown' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Text ist Markdown und wird in HTML umgewandelt (HTML im Text wird maskiert); die übrigen Umwandlungen entfallen.'
            ],
            'urlencode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Text wird nach RFC 1738 kodiert.'
            ],
            'urllimit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '80',
                'value' => 'Anzahl Zeichen',
                'description' => 'Maximale Länge des sichtbaren Textes für automatisch erzeugte Links (nur für externe Links geeignet!).'
            ],
            'ampconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Ein alleinstehendes & wird automatisch in das HTML-Entity &amp; umgewandelt.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der der Quelle vorangestellt wird, sofern diese nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der an die Quelle angehängt wird, sofern diese nicht leer ist.'
            ],
            'iso' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Die Ausgabe wird in den Zeichensatz ISO-8859-1 konvertiert.'
            ],
            'charset' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'utf-8',
                'value' => 'Text',
                'description' => 'Zeichensatz, in den die Ausgabe konvertiert werden soll.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'time' => [
        'method' => 'cms.cmsTime',
        'note' => 'Gibt die Uhrzeit eines Datums- oder Zeitwertes formatiert aus.',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, aus dem gelesen wird. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feld',
                'description' => 'Name eines Datensatzfeldes, dessen Inhalt als Quelle genutzt wird.'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Zeitwert',
                'description' => 'Wert in Textform, der als Quelle genutzt wird.'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Formatangaben',
                'description' => 'ICU-Muster, z. B. "HH:mm". Ohne Angabe entscheidet die Locale der Seite über die Schreibweise; volle Minuten werden ohne Sekunden gezeigt.'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Name der Zeitzone wird angehängt (z. B. "Europe/Berlin").'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text?Text',
                'description' => 'Kommt der Text vor dem Fragezeichen im formatierten Wert vor, wird er durch den Text nach dem Fragezeichen ersetzt (z. B. "Jan?Januar").'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der ausgegeben wird, falls die Quelle (\'field\' oder \'useval\') leer ist.'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der anstelle der Quelle ausgegeben wird, sofern diese nicht leer ist.'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der vorangestellt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text, der angehängt wird, sofern der formatierte Wert nicht leer ist.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Der formatierte Wert wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'url' => [
        'method' => 'cms.cmsUrl',
        'note' => 'Erzeugt die URL eines Datensatzes.',
        'do' => 'id',
        'oneof' => '',
        'params' => [
            'area' => [
                'ref' => 'area',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Bereichsname oder -Id',
                'description' => 'Verweis auf die Übersicht des Bereichs: die Adresse aus seinem Slug-Präfix (/de/modelle), die sein Übersichts-Template zeigt.'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktueller Datensatz)',
                'value' => 'Datensatz-Id oder Slug',
                'description' => 'Datensatz, auf den verwiesen werden soll. Reine Ziffernfolge = record_id, alles andere = Slug - dieselbe Regel wie in der URL.'
            ],
            'template' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Template, mit dem der Datensatz gezeigt werden soll (zweiter URL-Teil nach dem Komma): Name oder bereich.name. Ohne Angabe das Template, das der Bereich zuweist.'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(aktuelle Sprache)',
                'value' => 'Sprachkürzel',
                'description' => 'Sprachversion, auf die verwiesen werden soll (standardmäßig die des laufenden Aufrufs). Führt die Instanz mehr als eine Sprache, steht das Kürzel immer in der URL; bei nur einer Sprache nie. Ein Kürzel, das die Website nicht führt, wird übergangen.'
            ],
            'protocol' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(Protokoll der Instanz)',
                'value' => 'Übertragungsprotokoll',
                'description' => 'http oder https, nur mit absolute bzw. host. Ohne Angabe das Protokoll der Instanz, sonst das des Aufrufs.'
            ],
            'host' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Hostname',
                'description' => 'z. B. example.com für den Wechsel der Domain, über die die Website aufgerufen wird.'
            ],
            'absolute' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der Pfad wird inkl. Protokoll und Domain erzeugt. false: Der Pfad ist serverrelativ und beginnt mit einem Slash.'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die URL wird nicht ausgegeben, sondern in $set eingetragen.'
            ]
        ],
        'include' => []
    ],

    'xml' => [
        'method' => 'cms.cmsXml',
        'note' => 'Erzeugt eine XML-Deklaration.',
        'do' => 'encoding',
        'oneof' => '',
        'params' => [
            'encoding' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'utf-8',
                'value' => 'encoding',
                'description' => 'Zeichenkodierung, die verwendet werden soll.'
            ],
            'version' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1.0',
                'value' => 'version',
                'description' => 'XML-Version.'
            ]
        ],
        'include' => []
    ],

    // ---- Community Features -----

    // ==========================================================================
    // CMS:USER_* - Frontend-Community
    //
    // Anmeldung, Registrierung, Beiträge, Warenkorb und Zugriffsschutz für
    // Website-Besucher. Laufzeit: Camindo\Core\Modules\Record\RecordUser; Profile
    // liegen in Bereichen vom Typ 'profile', Beiträge in Bereichen vom Typ 'ugc'.
    // Ergebnisse stehen in $set (user_id, user, user_session_id, hash, record_id,
    // basket ...), Meldungen lassen sich je Befehl über 'message[index]' ersetzen.
    // Siehe docs/developers/11-community.md.
    // ==========================================================================

    'zip' => [
        'method' => 'zip.cmsZip',
        'note' => 'Bietet die Dateien mehrerer Datensätze als ZIP-Archiv zum Download an und beendet die Seite.',
        'do' => 'field',
        'oneof' => 'filter,relation,ids,source',
        'params' => [
            'field' => [
                'mandatory' => true, 'subparam' => 0, 'default' => '',
                'value' => 'Feldname(n), kommasepariert',
                'description' => 'Upload-/Thumbnail-Feld(er). Je Datensatz gilt das erste, das der Besucher abrufen darf (etwa fil_print,fil_upload: Druckversion für die Presse, sonst Web-Version).'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record'],
                'operator' => true,
                'mandatory' => false, 'subparam' => 2, 'default' => '', 'value' => 'Wert',
                'description' => 'Auswahl der Datensätze wie bei CMS:SELECT.'
            ],
            'filter_op' => ['mandatory' => false, 'subparam' => 2, 'default' => '=', 'value' => 'Operator', 'description' => 'Vergleichsoperator wie bei CMS:SELECT.'],
            'filter_mode' => ['mandatory' => false, 'subparam' => 1, 'default' => 'OR', 'value' => 'AND / OR', 'description' => 'Verknüpfung mehrerer Werte wie bei CMS:SELECT.'],
            'relation' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => 'Feldname', 'description' => 'Verknüpfte Datensätze wie bei CMS:SELECT.'],
            'src_id' => ['ref' => 'record', 'mandatory' => false, 'subparam' => 0, 'default' => '(aktueller Datensatz)', 'value' => 'Datensatz-Id', 'description' => 'Quell-Datensatz für relation; leer oder 0: keine Dateien.'],
            'ids' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => 'Datensatz-Ids, kommasepariert', 'description' => 'Ausdrücklich genannte Datensätze (veröffentlicht, aus dieser Website).'],
            'source' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => 'basket', 'description' => 'basket: die Datensätze im Warenkorb der Session (Kategorie aus category) - etwa ein Leuchtkasten.'],
            'category' => ['mandatory' => false, 'subparam' => 0, 'default' => '0', 'value' => 'Zahl', 'description' => 'Warenkorb-Kategorie für source=basket.'],
            'sort' => ['mandatory' => false, 'subparam' => 1, 'default' => '', 'value' => 'Feldname', 'description' => 'Reihenfolge wie bei CMS:SELECT; ohne Angabe die von ids bzw. des Warenkorbs.'],
            'order' => ['mandatory' => false, 'subparam' => 1, 'default' => 'asc', 'value' => 'asc / desc', 'description' => 'Sortierrichtung.'],
            'name' => ['mandatory' => false, 'subparam' => 0, 'default' => 'download', 'value' => 'Text', 'description' => 'Name des Archivs ohne .zip.'],
            'filenames' => ['mandatory' => false, 'subparam' => 0, 'default' => '{slug}.{extension}', 'value' => 'Muster', 'description' => 'Dateinamen im Archiv: {slug}, {id}, {filename} (ursprünglicher Name), {extension}, {feldname}; doppelte Namen erhalten -2, -3 ...'],
            'credits' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => 'Feldnamen, kommasepariert', 'description' => 'Legt eine CREDITS.txt bei: je Datei ihr Name und die Inhalte dieser Felder (z. B. Titel, Bildunterschrift, Bildnachweis).'],
            'maxfiles' => ['mandatory' => false, 'subparam' => 0, 'default' => '(user.zip.max_files)', 'value' => 'Zahl', 'description' => 'Höchstzahl der Dateien (nur kleiner als die Einstellung).'],
            'maxsize' => ['mandatory' => false, 'subparam' => 0, 'default' => '(user.zip.max_mb)', 'value' => 'MB', 'description' => 'Höchstgröße in MB (nur kleiner als die Einstellung).'],
            'future' => ['mandatory' => false, 'subparam' => 0, 'default' => 'false', 'value' => 'true / false', 'description' => 'Auch vordatierte Datensätze (wie bei CMS:SELECT).'],
            'archive' => ['mandatory' => false, 'subparam' => 0, 'default' => 'false', 'value' => 'true / false', 'description' => 'Auch abgelaufene Datensätze (wie bei CMS:SELECT).'],
            'cookiename' => ['mandatory' => false, 'subparam' => 0, 'default' => 'usess', 'value' => 'Cookiename', 'description' => 'Session-Cookie (CMS:USER_LOGIN cookiename).'],
            'message' => ['mandatory' => false, 'subparam' => 1, 'default' => '', 'value' => 'Text', 'description' => 'Meldungen: message[empty] (keine Datei), message[limit] (zu groß), message[throttled] (zu viele Archive).'],
        ],
        'include' => []
    ],

    'user_token' => [
        'method' => 'recordUser.cmsToken',
        'note' => 'Gibt das CSRF-Token für ein Formular mit schreibendem CMS:USER_*-Befehl aus (verstecktes Feld _token).',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: zusätzlich Zeitstempel und ein unsichtbares Köderfeld ausgeben (Formularschutz gegen Robots) - für Formulare mit USER_REGISTER, USER_PW oder CMS:EMAIL formguard=true.'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '"field"',
                'value' => '"field" / "value"',
                'description' => 'field: verstecktes Eingabefeld <input type="hidden" name="_token">. value: nur das Token (für Ajax: Header X-Camindo-Token oder POST-Feld _token).'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Ausgabe wird nicht ausgegeben, sondern in der Variable $set gespeichert. Das Token steht zusätzlich immer in $set[\'user_token\'].'
            ]
        ],
        'include' => []
    ],

    'user_password_rules' => [
        'method' => 'recordUser.cmsPasswordRules',
        'note' => 'Gibt Passwort-Regeln und Texte als JSON für das Attribut data-c9-password aus (Extension Password, inline eingebunden) - dieselben Regeln, die der Server beim Speichern prüft.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-ID oder -Name',
                'description' => 'Profil-Container, dessen Passwort-Feld gemeint ist.'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'Anmelde-Passwortfeld',
                'value' => 'Feldname',
                'description' => 'Passwort-Feld des Containers. Trägt es die Extension Password, gelten deren Regeln, sonst security.password_policy (der Server prüft dann nicht).'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Array-Index von $set',
                'description' => 'Die Ausgabe wird nicht ausgegeben, sondern in der Variable $set gespeichert.'
            ]
        ],
        'include' => []
    ],

    'user_auth' => [
        'method' => 'recordUser.cmsAuth',
        'note' => 'Prüft eine Session-ID auf Gültigkeit.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Session-ID',
                'description' => 'Session des Frontend-Users anhand der angegebenen sessionid wieder aufnehmen anstatt über ein Cookie.'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => 'vgl. Parameter "filter[]" von CMS:SELECT',
                'description' => 'Filterkriterien für den Profil-Datensatz, die beim angemeldeten Frontend-User erfüllt sein müssen.'
            ],
            'mode' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'OR',
                'value' => 'AND / OR',
                'description' => 'Modus, mit dem Werte verknüpft werden, die dasselbe Feld betreffen.'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ]
        ],
        'include' => []
    ],

    'user_basket' => [
        'method' => 'recordUser.cmsBasket',
        'note' => 'Bearbeiten eines Warenkorbs.',
        'do' => 'function',
        'oneof' => '',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'function' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '"load"',
                'value' => '"load" / "add" / "update" / "update_addon"',
                'description' => 'Funktion, die ausgeführt werden soll.'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'false / true',
                'description' => 'function=add + overwrite=true: Die Anzahl der bereits gespeicherten Artikel wird beim Hinzufügen aktualisiert.'
            ],
            'category' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => 'Kategorie-ID',
                'description' => 'Warenkorb-Kategorie.'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-IDs oder -Namen, kommasepariert',
                'description' => 'Nur Datensätze aus diesen Containern kommen in den Warenkorb. Ohne Angabe: veröffentlichte Datensätze aus Containern vom Typ "record" oder "ugc" (nie Profile).'
            ],
            'variable' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Variablenname',
                'description' => 'Name der Variable, in der Artikel-IDs als Array gespeichert sind.'
            ],
            'duration' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '30',
                'value' => 'Anzahl Tage',
                'description' => 'Anzahl der Tage, die der Warenkorb gespeichert bleibt, sofern keine Änderungen durchgeführt werden.'
            ],
            'basket_addon' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Text (serialisierte Daten), die gespeichert werden sollen.'
            ]
        ],
        'include' => [
        ]
    ],

    'user_delete' => [
        'method' => 'recordUser.cmsDelete',
        'note' => 'Löscht einen Datensatz, der dem angemeldeten Frontend-User gehört.',
        'do' => 'id',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-ID',
                'description' => 'Datensatz, der gelöscht werden soll.'
            ],
            'ignoreuser' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der zu löschende Datensatz muss nicht dem angemeldeten Frontend-User gehören (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Template, das eingebunden wird, wenn der Eintrag erfolgreich gelöscht wurde.'
            ],
            'message_error' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'Zugriff verweigert!',
                'value' => 'Text',
                'description' => 'Fehlermeldung, falls der Benutzer keinen Zugriff auf den Datensatz hat.'
            ]
        ],
        'include' => [
        ]
    ],

    'user_input' => [
        'method' => 'recordUser.cmsInput',
        'note' => 'Erzeugt oder aktualisiert einen Datensatz aus Daten, die ein Frontend-User angegeben hat.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Template, das eingebunden wird, wenn der Eintrag erfolgreich gespeichert wurde.'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des PHP-Arrays, das die zu speichernden Daten enthält.'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Schlüssel-Wert-Paare',
                'description' => 'Explizites Überschreiben der Eingaben des Frontend-Users (auch Metafelder, außer record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Hier keine Eingaben des Frontend-Users weiterreichen!'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-ID oder -Name',
                'description' => 'ID oder Name des Content-Containers, in dem der neue Datensatz gespeichert werden soll.'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'Standardtext',
                'value' => 'Text',
                'description' => 'Fehlermeldung abhängig vom Index (siehe Tabelle).'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist.'
            ],
            'fields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'kommaseparierte Liste',
                'description' => 'Namen der Eingabefelder, die geschrieben werden dürfen. Leer = alle definierten Felder des Containers.'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-ID',
                'description' => 'Falls angegeben, wird versucht diesen Datensatz zu aktualisieren (UPDATE). Andernfalls wird ein neuer erstellt (CREATE).'
            ],
            'ignoreuser' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Der anzupassende Datensatz muss nicht dem angemeldeten Frontend-User gehören (Achtung: Sicherheitshinweise beachten!).'
            ],
            'forceupdate' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Es wird erzwungen, den über Parameter "id" angegebenen Datensatz zu aktualisieren. Wirft im Fehlerfall eine Meldung.'
            ],
            'masterid' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Datensatz-ID',
                'description' => 'Nur beim Anlegen in Bereichen mit Baumstruktur: Elternknoten des neuen Datensatzes (parent_id).'
            ],
            'owner' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Benutzer-ID',
                'description' => 'Nur beim Anlegen: admin_id des Backend-Owners (record.owner_id, created_by). Ohne Angabe gilt user.default_owner_id.'
            ],
            'removefiles' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'kommaseparierte Liste',
                'description' => 'Namen der Upload-Felder, deren Inhalt gelöscht werden soll.'
            ],
            'currentfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'current_password',
                'value' => 'Feldname',
                'description' => 'Nur Profil-Container: Name des Formularfeldes data[…] mit dem bisherigen Passwort. Ein neues Passwort wird nur mit dem bisherigen gespeichert - außer direkt nach der Anmeldung über den Reset-Link ($set[\'password_reset\']). Meldung "current".'
            ]
        ],
        'include' => [
        ]
    ],

    'user_login' => [
        'method' => 'recordUser.cmsLogin',
        'note' => 'Überprüft die Anmeldedaten eines Frontend-Users und erzeugt eine neue Session.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'codetemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Template für den zweiten Schritt der Anmeldung, wenn der Profil-Bereich einen zweiten Faktor verlangt (Code aus der App oder per E-Mail). Es verschickt beim Code per E-Mail $set[\'login_code\'] mit CMS:EMAIL und zeigt das Code-Formular für CMS:USER_LOGIN_CODE. Pflicht, sobald der Bereich einen zweiten Faktor einsetzt.'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das nach einer erfolgreichen Anmeldung eingebunden wird.'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-ID oder -Name',
                'description' => 'ID oder Name des Profil-Containers, in dem die Daten der Frontend-User gespeichert sind.'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des PHP-Arrays, in dem die Anmeldedaten enthalten sind.'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Standardmeldung',
                'description' => 'Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle).'
            ],
            'hash' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Hash',
                'description' => 'Ein Login kann auch mittels Hash durchgeführt werden (Token aus CMS:USER_PW, Passwort vergessen).'
            ],
            'trim' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: Leerzeichen am Anfang und Ende der Eingaben entfernen.'
            ],
            'cookie' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Es wird standardmäßig ein Cookie gesetzt, um die Anmeldung über mehrere Seiten hinweg aufrechtzuerhalten.'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist. Entfällt bei der Anmeldung per "hash".'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ]
        ],
        'include' => [
        ]
    ],

    'user_login_code' => [
        'method' => 'recordUser.cmsLoginCode',
        'note' => 'Zweiter Schritt der Anmeldung: prüft den Code aus der Authenticator-App, per E-Mail oder einen Wiederherstellungscode und meldet den Frontend-User an.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'codetemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Template, das nach "resend" eingebunden wird und den neuen Code ($set[\'login_code\']) mit CMS:EMAIL verschickt - üblicherweise dasselbe wie "codetemplate" von CMS:USER_LOGIN.'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das nach der erfolgreichen Anmeldung eingebunden wird.'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Der Code wird nur geprüft, wenn in diesem Feld ein Text enthalten ist.'
            ],
            'resend' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => 'true: neuen Code per E-Mail erzeugen (gedrosselt). Er steht danach in $set[\'login_code\'], eingebunden wird "codetemplate", das ihn mit CMS:EMAIL verschickt.'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des PHP-Arrays, in dem die Eingaben enthalten sind.'
            ],
            'codefield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'code',
                'value' => 'Feldname',
                'description' => 'Name des Eingabefelds für den Code in data[…].'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Standardmeldung',
                'description' => 'Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle).'
            ],
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Session-Id',
                'description' => 'Session der wartenden Anmeldung ohne Cookie (Aufrufe aus Apps) - $set[\'user_session_id\'] nach CMS:USER_LOGIN.'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ],
        ],
        'include' => []
    ],

    'user_2fa' => [
        'method' => 'recordUser.cmsTwoFactor',
        'note' => 'Zweiten Faktor des angemeldeten Frontend-Users anzeigen, einrichten (Authenticator-App oder Code per E-Mail) und abschalten.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'function' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'status',
                'value' => 'status / totp_setup / totp_enable / code_enable / disable / recovery_renew',
                'description' => 'status: nur lesen. totp_setup: neuen Schlüssel für die App erzeugen ($set[\'totp_setup\']). totp_enable: data[token] und data[code] prüfen und speichern ($set[\'recovery_codes\']). code_enable: Code per E-Mail wählen (bestätigt mit dem Passwort). disable: abschalten (App-Code bzw. Passwort). recovery_renew: neue Wiederherstellungscodes (App-Code).'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Schreibende Funktionen laufen nur, wenn in diesem Feld ein Text enthalten ist.'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des PHP-Arrays, in dem die Eingaben enthalten sind.'
            ],
            'codefield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'code',
                'value' => 'Feldname',
                'description' => 'Name des Eingabefelds für den Code in data[…].'
            ],
            'currentfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'current_password',
                'value' => 'Feldname',
                'description' => 'Name des Eingabefelds für das Passwort in data[…] (code_enable, disable beim Code).'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Standardmeldung',
                'description' => 'Fehlermeldungen. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle).'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ],
        ],
        'include' => []
    ],

    'user_logout' => [
        'method' => 'recordUser.cmsLogout',
        'note' => 'Beendet und löscht die Session eines angemeldeten Frontend-Users.',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Ist der Parameter angegeben, meldet der Befehl nur ab, wenn er einen Text enthält (z. B. submit="$logout" aus dem Abmelde-Formular) - leer passiert nichts, auch keine Meldung token. Ohne den Parameter meldet der Befehl bei jedem Aufruf ab (gültiges Token vorausgesetzt).'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'deletecookie' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'false: Nur die serverseitige Session wird gelöscht. true: Auch der Browser-Cookie wird gelöscht.'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ]
        ],
        'include' => [
        ]
    ],

    'user_protect' => [
        'method' => 'recordUser.cmsProtect',
        'note' => 'Schützt ein Template vor dem Zugriff nicht angemeldeter Website-Besucher.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'errortemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Templatename',
                'description' => 'Detailtemplate, das eingebunden wird, falls der Frontend-User keinen Zugriff auf den Container hat oder nicht angemeldet ist.'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => 'vgl. Parameter "filter[]" von CMS:SELECT',
                'description' => 'Filterkriterien für den Profil-Datensatz, die beim angemeldeten Frontend-User erfüllt sein müssen.'
            ],
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Session-ID',
                'description' => 'Session des Frontend-Users anhand der angegebenen sessionid wieder aufnehmen anstatt über ein Cookie.'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => 'Cookiename',
                'description' => 'Name des Session-Cookies [a-z0-9].'
            ]
        ],
        'include' => [
        ]
    ],

    'user_pw' => [
        'method' => 'recordUser.cmsPw',
        'note' => 'Bereitet das Zurücksetzen eines Passworts vor.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false / Sekunden',
                'description' => 'true: Formularschutz gegen Robots - das Formular muss die Felder aus (CMS:USER_TOKEN formguard=true) enthalten (Zeitstempel, leeres Köderfeld) und darf frühestens nach user.formguard_min_seconds (3) abgeschickt werden. Eine Zahl setzt diese Mindestzeit für das Formular (formguard=5); 0 oder false schaltet den Schutz ab. Sonst Meldung "throttled", wie bei der Drosselung.'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das eingebunden wird, falls "uniquevalue" im Feld "uniquefield" einem Profil zugeordnet werden konnte.'
            ],
            'errortemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das eingebunden wird, falls "uniquevalue" angegeben wurde, aber keinem Profil zugeordnet werden konnte.'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-ID oder -Name',
                'description' => 'ID oder Name des Profil-Containers, in dem die abzugleichenden Profile gespeichert sind.'
            ],
            'uniquefield' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feldname',
                'description' => 'Name des Feldes, über das gesucht wird (Feldtyp chars, text oder encrypted, z. B. "email"). Der Wert muss genau ein Profil treffen.'
            ],
            'uniquevalue' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Text',
                'description' => 'Gesuchter Wert im Feld "uniquefield", z. B. $_POST[email].'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist.'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Text',
                'description' => 'Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle).'
            ]
        ],
        'include' => [
        ]
    ],

    'user_register' => [
        'method' => 'recordUser.cmsRegister',
        'note' => 'Erzeugt einen Profil-Datensatz aus den angegebenen Daten und setzt den Status auf status=2.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false / Sekunden',
                'description' => 'true: Formularschutz gegen Robots - das Formular muss die Felder aus (CMS:USER_TOKEN formguard=true) enthalten (Zeitstempel, leeres Köderfeld) und darf frühestens nach user.formguard_min_seconds (3) abgeschickt werden. Eine Zahl setzt diese Mindestzeit für das Formular (formguard=5); 0 oder false schaltet den Schutz ab. Sonst Meldung "throttled", wie bei der Drosselung.'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => 'true: Der Request muss ein gültiges Token aus CMS:USER_TOKEN enthalten (Schutz gegen CSRF). false schaltet die Prüfung für diesen Befehl ab (Achtung: Sicherheitshinweise beachten!).'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das nach erfolgreicher Registrierung eingebunden wird.'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Container-ID oder -Name',
                'description' => 'ID oder Name des Profil-Containers, in dem die Daten der Frontend-User gespeichert werden sollen.'
            ],
            'emailfield' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Feldname',
                'description' => 'Feldname, in dem die E-Mail des Frontend-Users gespeichert ist (dorthin wird der Freischalt-Link gesendet).'
            ],
            'fields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'kommaseparierte Liste',
                'description' => 'Namen der Eingabefelder, die geschrieben werden dürfen. Leer = alle definierten Felder des Containers.'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Schlüssel-Wert-Paare',
                'description' => 'Explizites Überschreiben der Eingaben des Frontend-Users (auch Metafelder, außer record_id, area_id, instance_id, tmp, deleted, origin_id, owner_id, created_by, changed_by). Hier keine Eingaben des Frontend-Users weiterreichen!'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Meldung',
                'description' => 'Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle bei CMS:USER_INPUT).'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Textwert',
                'description' => 'Der Befehl wird nur ausgeführt, wenn in diesem Feld ein Text enthalten ist.'
            ],
            'owner' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'Benutzer-ID',
                'description' => 'admin_id des Backend-Owners des neuen Profils (record.owner_id, created_by). Ohne Angabe gilt user.default_owner_id.'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => 'PHP-Variablenname',
                'description' => 'Name des PHP-Arrays, das die zu speichernden Daten enthält.'
            ]
        ],
        'include' => [
        ]
    ],

    'user_register_confirm' => [
        'method' => 'recordUser.cmsRegisterConfirm',
        'note' => 'Schaltet einen Profil-Datensatz frei und setzt status=0.',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => 'Detailtemplate, das eingebunden wird, wenn die Registrierung erfolgreich abgeschlossen werden konnte.'
            ],
            'hash' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => 'Hash-ID',
                'description' => 'Hash-ID, die mittels CMS:USER_REGISTER erzeugt und per E-Mail versendet wurde.'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => 'Meldung',
                'description' => 'Fehlermeldung. Im Index: Anwendungsfall; im Wert: Meldungstext (vgl. Tabelle).'
            ]
        ],
        'include' => [
        ]
    ],
];