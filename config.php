<?php
/**
 * ---------------------------------------------------------------------------
 * Einstellungen für das Kontaktformular (kontakt.php)
 * ---------------------------------------------------------------------------
 * Beim Livegang muss NUR diese Datei angepasst werden.
 * Sie gibt ein Array zurück und erzeugt selbst keine Ausgabe – ein direkter
 * Aufruf im Browser zeigt daher eine leere Seite.
 */

return array(

    /* -----------------------------------------------------------------
     * Empfänger – hier landen die Anfragen aus dem Formular.
     * Mehrere Adressen mit Komma trennen:
     *   'empfaenger' => 'buero@example.de, chef@example.de',
     * ----------------------------------------------------------------- */
    'empfaenger' => 'reinholdruge@t-online.de',

    /* -----------------------------------------------------------------
     * Absender der E-Mail.
     *
     * WICHTIG: Das muss eine Adresse der EIGENEN Domain sein, die beim
     * Hoster tatsächlich existiert. Trägt man hier die Adresse des
     * Website-Besuchers ein, wird die Mail wegen SPF/DMARC von vielen
     * Providern als Spam einsortiert oder ganz abgewiesen.
     *
     * Die Adresse des Absenders steht als "Reply-To" in der Mail – auf
     * "Antworten" zu klicken schreibt also direkt an den Interessenten.
     * ----------------------------------------------------------------- */
    'absender_mail' => 'noreply@haustechnik-ruge.de',
    'absender_name' => 'Website Haustechnik Ruge',

    /* Vorangestellter Text in der Betreffzeile */
    'betreff_prefix' => 'Website-Anfrage',

    /* -----------------------------------------------------------------
     * Automatische Eingangsbestätigung an den Interessenten senden?
     * Auf false setzen, wenn das nicht gewünscht ist.
     * ----------------------------------------------------------------- */
    'eingangsbestaetigung' => true,

    /* -----------------------------------------------------------------
     * Spamschutz
     * limit_pro_stunde  – wie viele Anfragen ein Besucher pro Stunde senden darf
     * mindestdauer      – Sekunden, die das Ausfüllen mindestens dauern muss
     *                     (Bots senden praktisch sofort ab)
     * ----------------------------------------------------------------- */
    'limit_pro_stunde' => 5,
    'mindestdauer'     => 3,

    /* -----------------------------------------------------------------
     * Kopie aller Anfragen zusätzlich in eine Textdatei schreiben.
     * Praktisch als Ausfallsicherung, falls der Mailversand streikt.
     * Pfad möglichst AUSSERHALB des öffentlichen Web-Verzeichnisses legen,
     * sonst ist die Datei über den Browser abrufbar!
     * Zum Deaktivieren: null
     * ----------------------------------------------------------------- */
    'protokoll_datei' => null,
    // Beispiel: 'protokoll_datei' => __DIR__ . '/../anfragen.log',

);
