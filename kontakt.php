<?php
/**
 * ---------------------------------------------------------------------------
 * Kontaktformular – R. Ruge Sanitär Heizung Regenerative Energien
 * ---------------------------------------------------------------------------
 * Läuft auf jedem üblichen Webhosting mit PHP 7.4 oder neuer.
 * Einstellungen (Empfänger, Absender, Spamschutz) stehen in config.php.
 *
 * Ablauf: Formular -> Prüfung -> Versand -> Weiterleitung (Post/Redirect/Get),
 * damit ein Neuladen der Seite die Anfrage nicht doppelt verschickt.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

// Ohne feste Zeitzone laufen viele Hosting-Server auf UTC - die Uhrzeit in
// der Mail waere dann um ein bis zwei Stunden verschoben.
date_default_timezone_set('Europe/Berlin');

if (session_status() === PHP_SESSION_NONE) {
    // Sitzungs-Cookie absichern. Es dient ausschließlich dem Spamschutz
    // (CSRF-Token, Ausfülldauer, Anfragelimit) und ist damit technisch
    // notwendig im Sinne von § 25 Abs. 2 TDDDG – es braucht keine Einwilligung.
    $sicher = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $sicher,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, '/', '', $sicher, true);
    }

    session_name('rugeform');
    session_start();
}

/* ───────────────────────── Hilfsfunktionen ───────────────────────── */

/** Ausgabe im HTML maskieren. */
function e($wert): string
{
    return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
}

/** Entfernt Zeilenumbrüche und Steuerzeichen – Schutz vor Header-Injection. */
function kopfzeile_saeubern(string $wert): string
{
    return trim(str_replace(array("\r", "\n", "\0", "\t"), ' ', $wert));
}

/** Kodiert Betreff bzw. Anzeigenamen nach RFC 2047, falls Sonderzeichen enthalten sind. */
function kopfzeile_kodieren(string $wert): string
{
    $wert = kopfzeile_saeubern($wert);
    if (preg_match('/[^\x20-\x7E]/', $wert) === 1) {
        return '=?UTF-8?B?' . base64_encode($wert) . '?=';
    }
    return $wert;
}

/**
 * Baut den Anzeigenamen für From/Reply-To.
 * Reiner ASCII-Text wird in Anführungszeichen gesetzt, damit Sonderzeichen wie
 * ":" oder "," nicht als Header-Syntax missverstanden werden können.
 */
function anzeigename(string $wert): string
{
    $wert = kopfzeile_saeubern($wert);
    if ($wert === '') {
        return '';
    }
    if (preg_match('/[^\x20-\x7E]/', $wert) === 1) {
        return '=?UTF-8?B?' . base64_encode($wert) . '?=';
    }
    return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $wert) . '"';
}

/** Holt ein Feld aus dem POST, gekürzt und von Steuerzeichen befreit. */
function feld(string $name, int $maxLaenge): string
{
    $wert = isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
    $wert = str_replace("\0", '', $wert);

    // Browser senden in der Zeichenkodierung der Seite (UTF-8). Schickt ein
    // fehlerhafter Client dennoch Latin-1, waere die Mail sonst unlesbar.
    if ($wert !== '' && preg_match('//u', $wert) !== 1) {
        if (function_exists('mb_convert_encoding')) {
            $wert = mb_convert_encoding($wert, 'UTF-8', 'Windows-1252');
        }
    }

    $wert = trim($wert);
    if (function_exists('mb_substr')) {
        return mb_substr($wert, 0, $maxLaenge, 'UTF-8');
    }
    return substr($wert, 0, $maxLaenge);
}

/** Zeichenlänge ermitteln (mit Umlaut-Unterstützung, falls mbstring vorhanden). */
function laenge(string $wert): int
{
    return function_exists('mb_strlen') ? mb_strlen($wert, 'UTF-8') : strlen($wert);
}

/* ───────────────────────── Grunddaten ───────────────────────── */

$anredeOptionen = array('Frau', 'Herr', 'Familie', 'Firma', 'Keine Angabe');

$anliegenOptionen = array(
    'Bad & Sanitär',
    'Heizung & Brennwerttechnik',
    'Regenerative Energien',
    'Flaschnerei',
    'Wartung & Reparatur',
    'Trinkwasserhygiene',
    'Sonstiges',
);

$werte = array(
    'anrede'    => '',
    'name'      => '',
    'email'     => '',
    'telefon'   => '',
    'adresse'   => '',
    'anliegen'  => '',
    'zeitraum'  => '',
    'nachricht' => '',
);

$fehler       = array();   // feldname => Meldung
$hinweis      = '';        // allgemeine Fehlermeldung über dem Formular
$erfolgreich  = isset($_GET['gesendet']);

/* CSRF-Token bereitstellen */
if (empty($_SESSION['csrf_token'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/* ───────────────────────── Verarbeitung ───────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* --- 1. CSRF-Token prüfen ------------------------------------- */
    $gesendetesToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token'] : '';

    if (!hash_equals((string) $_SESSION['csrf_token'], $gesendetesToken)) {
        $hinweis = 'Ihre Sitzung ist abgelaufen. Bitte senden Sie das Formular noch einmal ab.';
    }

    /* --- 2. Spamschutz: Honigtopf --------------------------------- */
    // Das Feld "website" ist für Menschen unsichtbar. Ist es ausgefüllt,
    // war ein Bot am Werk. Wir tun so, als sei alles gut gegangen.
    if ($hinweis === '' && feld('website', 200) !== '') {
        header('Location: kontakt.php?gesendet=1', true, 303);
        exit;
    }

    /* --- 3. Spamschutz: Mindest-Ausfülldauer ---------------------- */
    $startzeit = isset($_SESSION['formular_start']) ? (int) $_SESSION['formular_start'] : 0;
    if ($hinweis === '' && $startzeit > 0 && (time() - $startzeit) < (int) $config['mindestdauer']) {
        $hinweis = 'Das ging uns etwas zu schnell. Bitte senden Sie das Formular noch einmal ab.';
    }

    /* --- 4. Spamschutz: Anfragen pro Stunde begrenzen ------------- */
    if ($hinweis === '') {
        $bisherige = isset($_SESSION['sendungen']) && is_array($_SESSION['sendungen'])
            ? $_SESSION['sendungen'] : array();
        $aktuelle = array();
        foreach ($bisherige as $zeitpunkt) {
            if ((int) $zeitpunkt > time() - 3600) {
                $aktuelle[] = (int) $zeitpunkt;
            }
        }
        $_SESSION['sendungen'] = $aktuelle;

        if (count($aktuelle) >= (int) $config['limit_pro_stunde']) {
            $hinweis = 'Es wurden bereits mehrere Anfragen von diesem Gerät gesendet. '
                     . 'Bitte rufen Sie uns direkt an: 07144 3345604.';
        }
    }

    /* --- 5. Eingaben einlesen ------------------------------------- */
    $werte['anrede']    = feld('anrede', 30);
    $werte['name']      = feld('name', 100);
    $werte['email']     = feld('email', 150);
    $werte['telefon']   = feld('telefon', 50);
    $werte['adresse']   = feld('adresse', 200);
    $werte['anliegen']  = feld('anliegen', 60);
    $werte['zeitraum']  = feld('zeitraum', 100);
    $werte['nachricht'] = feld('nachricht', 5000);

    /* --- 6. Prüfen ------------------------------------------------ */
    if ($hinweis === '') {

        if ($werte['name'] === '') {
            $fehler['name'] = 'Bitte geben Sie Ihren Namen an.';
        } elseif (laenge($werte['name']) < 2) {
            $fehler['name'] = 'Der Name ist zu kurz.';
        }

        if ($werte['email'] === '') {
            $fehler['email'] = 'Bitte geben Sie Ihre E-Mail-Adresse an.';
        } elseif (!filter_var($werte['email'], FILTER_VALIDATE_EMAIL)) {
            $fehler['email'] = 'Diese E-Mail-Adresse scheint nicht zu stimmen.';
        }

        if ($werte['nachricht'] === '') {
            $fehler['nachricht'] = 'Bitte beschreiben Sie kurz Ihr Anliegen.';
        } elseif (laenge($werte['nachricht']) < 10) {
            $fehler['nachricht'] = 'Ein paar Worte mehr helfen uns bei der Einschätzung.';
        }

        if ($werte['anrede'] !== '' && !in_array($werte['anrede'], $anredeOptionen, true)) {
            $werte['anrede'] = '';
        }
        if ($werte['anliegen'] !== '' && !in_array($werte['anliegen'], $anliegenOptionen, true)) {
            $werte['anliegen'] = '';
        }

        if (empty($_POST['datenschutz'])) {
            $fehler['datenschutz'] = 'Ohne Ihre Einwilligung dürfen wir die Anfrage leider nicht bearbeiten.';
        }
    }

    /* --- 7. Versenden --------------------------------------------- */
    if ($hinweis === '' && empty($fehler)) {

        $absenderMail = kopfzeile_saeubern((string) $config['absender_mail']);
        $besucherMail = kopfzeile_saeubern($werte['email']);
        $besucherName = anzeigename($werte['name']);

        $betreff = kopfzeile_kodieren(
            $config['betreff_prefix'] . ': '
            . ($werte['anliegen'] !== '' ? $werte['anliegen'] : 'Allgemeine Anfrage')
            . ' – ' . $werte['name']
        );

        $zeile = str_repeat('-', 58);
        $text  = "Neue Anfrage über haustechnik-ruge.de\n" . $zeile . "\n\n"
               . 'Anrede:        ' . ($werte['anrede']   !== '' ? $werte['anrede']   : '–') . "\n"
               . 'Name:          ' . $werte['name'] . "\n"
               . 'E-Mail:        ' . $werte['email'] . "\n"
               . 'Telefon:       ' . ($werte['telefon']  !== '' ? $werte['telefon']  : '–') . "\n"
               . 'Objektadresse: ' . ($werte['adresse']  !== '' ? $werte['adresse']  : '–') . "\n"
               . 'Anliegen:      ' . ($werte['anliegen'] !== '' ? $werte['anliegen'] : '–') . "\n"
               . 'Zeitraum:      ' . ($werte['zeitraum'] !== '' ? $werte['zeitraum'] : '–') . "\n\n"
               . $zeile . "\nNachricht:\n" . $zeile . "\n\n"
               . $werte['nachricht'] . "\n\n"
               . $zeile . "\n"
               . 'Gesendet am ' . date('d.m.Y \u\m H:i') . " Uhr\n"
               . "Antworten Sie einfach auf diese E-Mail – sie geht direkt an den Absender.\n";

        $kopfzeilen = array(
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . anzeigename((string) $config['absender_name']) . ' <' . $absenderMail . '>',
            'Reply-To: ' . $besucherName . ' <' . $besucherMail . '>',
            'X-Mailer: PHP/' . phpversion(),
        );

        $versandOk = @mail(
            (string) $config['empfaenger'],
            $betreff,
            $text,
            implode("\r\n", $kopfzeilen),
            '-f' . $absenderMail
        );

        /* Optionales Protokoll als Ausfallsicherung */
        if (!empty($config['protokoll_datei'])) {
            @file_put_contents(
                (string) $config['protokoll_datei'],
                '[' . date('Y-m-d H:i:s') . '] ' . ($versandOk ? 'OK' : 'FEHLER') . "\n"
                . $text . "\n\n",
                FILE_APPEND | LOCK_EX
            );
        }

        if ($versandOk) {

            /* Eingangsbestätigung an den Interessenten */
            if (!empty($config['eingangsbestaetigung'])) {
                $antwortText =
                      'Guten Tag ' . $werte['name'] . ",\n\n"
                    . "vielen Dank für Ihre Anfrage. Sie ist bei uns eingegangen und wir\n"
                    . "melden uns persönlich bei Ihnen zurück.\n\n"
                    . $zeile . "\nIhre Angaben\n" . $zeile . "\n\n"
                    . 'Anliegen:  ' . ($werte['anliegen'] !== '' ? $werte['anliegen'] : 'Allgemeine Anfrage') . "\n"
                    . 'Zeitraum:  ' . ($werte['zeitraum'] !== '' ? $werte['zeitraum'] : '–') . "\n\n"
                    . $werte['nachricht'] . "\n\n"
                    . $zeile . "\n\n"
                    . "Sie erreichen uns auch direkt:\n"
                    . "Telefon 07144 3345604\n"
                    . "E-Mail  reinholdruge@t-online.de\n\n"
                    . "Freundliche Grüße\n"
                    . "Reinhold Ruge\n\n"
                    . "R. Ruge – Sanitär · Heizung · Regenerative Energien\n"
                    . "Zeppelinstraße 44, 71711 Steinheim an der Murr\n";

                $antwortKopf = array(
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    'From: ' . anzeigename((string) $config['absender_name']) . ' <' . $absenderMail . '>',
                    'Reply-To: R. Ruge <reinholdruge@t-online.de>',
                    'Auto-Submitted: auto-replied',
                    'X-Mailer: PHP/' . phpversion(),
                );

                @mail(
                    $besucherMail,
                    kopfzeile_kodieren('Ihre Anfrage bei R. Ruge – Eingangsbestätigung'),
                    $antwortText,
                    implode("\r\n", $antwortKopf),
                    '-f' . $absenderMail
                );
            }

            /* Zähler für das Stundenlimit hochsetzen */
            $_SESSION['sendungen'][] = time();

            /* Post/Redirect/Get – verhindert doppeltes Absenden beim Neuladen */
            header('Location: kontakt.php?gesendet=1', true, 303);
            exit;
        }

        $hinweis = 'Die Nachricht konnte technisch leider nicht versendet werden. '
                 . 'Bitte schreiben Sie uns direkt an reinholdruge@t-online.de '
                 . 'oder rufen Sie an: 07144 3345604.';
    }
}

/* Startzeitpunkt für den Spamschutz merken (gilt für das gleich gerenderte Formular) */
$_SESSION['formular_start'] = time();

/* Keine Zwischenspeicherung – sonst zeigt der Zurück-Button veraltete Zustände */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title><?php echo $erfolgreich ? 'Danke für Ihre Anfrage' : 'Kontakt'; ?> – R. Ruge Sanitär Heizung Regenerative Energien</title>
<meta name="description" content="Kontakt zum Meisterbetrieb R. Ruge in Steinheim an der Murr: Anfrage für Bad, Heizung, Solar oder Flaschnerei – kostenlos und unverbindlich." />
<meta name="theme-color" content="#01328F" />
<link rel="icon" type="image/png" href="assets/favicon.png" />
<link rel="apple-touch-icon" href="assets/favicon.png" />
<meta name="robots" content="<?php echo $erfolgreich ? 'noindex,follow' : 'index,follow'; ?>" />

<script>document.documentElement.className += " js";</script>
<link rel="preload" href="assets/fonts/inter-400-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="preload" href="assets/fonts/montserrat-800-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="stylesheet" href="assets/fonts.css" />
<link rel="stylesheet" href="assets/style.css" />
</head>

<body>
<a class="skip-link" href="#main">Zum Inhalt springen</a>

<!-- ══ HEADER ══ -->
<header class="site-header" id="header">
  <div class="header-inner">
    <a href="index.html" class="logo" aria-label="R. Ruge – Startseite">
      <img src="assets/logo-ruge.png" width="712" height="424"
           alt="Logo R. Ruge – Sanitär, Heizung, Regenerative Energien" />
    </a>

    <nav class="header-nav" aria-label="Hauptnavigation">
      <a href="index.html#leistungen">Leistungen</a>
      <a href="index.html#energien">Energien</a>
      <a href="index.html#bereiche">Bereiche</a>
      <a href="index.html#referenzen">Referenzen</a>
      <a href="index.html#ueber">Über uns</a>
      <a href="kontakt.php" aria-current="page">Kontakt</a>
    </nav>

    <div class="header-cta">
      <a href="tel:+4971443345604" class="btn btn-primary">07144 3345604</a>
    </div>

    <button class="hamburger" id="hamburger" type="button"
            aria-label="Menü öffnen" aria-expanded="false" aria-controls="mobileNav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<!-- ══ MOBILE NAV ══ -->
<nav class="mobile-nav" id="mobileNav" aria-label="Mobile Navigation">
  <a href="index.html#leistungen">Leistungen</a>
  <a href="index.html#energien">Regenerative Energien</a>
  <a href="index.html#bereiche">Bereiche</a>
  <a href="index.html#referenzen">Referenzen</a>
  <a href="index.html#ueber">Über uns</a>
  <a href="index.html#faq">FAQ</a>
  <a href="kontakt.php" aria-current="page">Kontakt</a>
  <div class="mobile-actions">
    <a href="tel:+4971443345604" class="btn btn-primary">07144 3345604</a>
    <a href="mailto:reinholdruge@t-online.de" class="btn btn-outline">E-Mail schreiben</a>
  </div>
  <div class="mobile-meta">
    R. Ruge Sanitär Heizung Regenerative Energien<br />
    Zeppelinstraße 44 · 71711 Steinheim an der Murr
  </div>
</nav>

<main id="main">

<!-- ══ SEITENKOPF ══ -->
<section class="page-head">
  <div class="inner">
<?php if ($erfolgreich): ?>
    <p class="eyebrow">Anfrage eingegangen</p>
    <h1>Danke für Ihr Vertrauen.</h1>
    <p>
      Ihre Nachricht liegt bei uns im Postfach. Wir schauen sie uns an und melden
      uns persönlich bei Ihnen zurück.
    </p>
<?php else: ?>
    <p class="eyebrow">Kontakt</p>
    <h1>Wie können wir Ihnen helfen?</h1>
    <p>
      Ob neues Bad, Heizungstausch, Solaranlage oder eine Reparatur: Schildern Sie
      uns kurz Ihr Anliegen. Wir melden uns persönlich bei Ihnen zurück – kostenlos
      und unverbindlich.
    </p>
<?php endif; ?>
  </div>
</section>

<!-- ══ FORMULAR + KONTAKTDATEN ══ -->
<section class="section" style="padding-top:clamp(2.5rem,5vw,4rem)">
  <div class="contact-grid">

    <div class="form-card" id="formular">
<?php if ($erfolgreich): ?>

      <!-- ── Erfolgsmeldung nach dem Versand ── -->
      <div class="success-panel">
        <div class="success-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Vielen Dank – Ihre Anfrage ist angekommen.</h2>
        <p>
          Wir haben Ihnen eine Eingangsbestätigung per E-Mail geschickt und melden
          uns persönlich bei Ihnen zurück. Sollte es eilig sein, erreichen Sie uns
          jederzeit direkt am Telefon.
        </p>
        <div class="cta-actions" style="justify-content:flex-start">
          <a href="tel:+4971443345604" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1 19.79 19.79 0 0 1 1.62 4.44 2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l1.88-1.88a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            07144 3345604
          </a>
          <a href="index.html" class="btn btn-outline">Zurück zur Startseite</a>
        </div>
      </div>

<?php else: ?>

      <form method="post" action="kontakt.php#formular" accept-charset="UTF-8" id="kontaktForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>" />

        <div class="form-grid">

<?php if ($hinweis !== '' || !empty($fehler)): ?>
          <div class="form-status show err" role="alert">
            <?php echo $hinweis !== ''
                ? e($hinweis)
                : 'Bitte prüfen Sie die rot markierten Felder – dann klappt es gleich.'; ?>
          </div>
<?php endif; ?>

          <div class="field">
            <label for="anrede">Anrede</label>
            <select id="anrede" name="anrede">
              <option value="">Bitte wählen</option>
<?php foreach ($anredeOptionen as $option): ?>
              <option<?php echo $werte['anrede'] === $option ? ' selected' : ''; ?>><?php echo e($option); ?></option>
<?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="name">Name <span class="req" aria-hidden="true">*</span></label>
            <input type="text" id="name" name="name" required autocomplete="name"
                   placeholder="Vor- und Nachname"
                   value="<?php echo e($werte['name']); ?>"
                   <?php echo isset($fehler['name']) ? 'aria-invalid="true" aria-describedby="fehler-name"' : ''; ?> />
<?php if (isset($fehler['name'])): ?>
            <p class="field-error" id="fehler-name"><?php echo e($fehler['name']); ?></p>
<?php endif; ?>
          </div>

          <div class="field">
            <label for="email">E-Mail <span class="req" aria-hidden="true">*</span></label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   inputmode="email" placeholder="name@beispiel.de"
                   value="<?php echo e($werte['email']); ?>"
                   <?php echo isset($fehler['email']) ? 'aria-invalid="true" aria-describedby="fehler-email"' : ''; ?> />
<?php if (isset($fehler['email'])): ?>
            <p class="field-error" id="fehler-email"><?php echo e($fehler['email']); ?></p>
<?php endif; ?>
          </div>

          <div class="field">
            <label for="telefon">Telefon</label>
            <input type="tel" id="telefon" name="telefon" autocomplete="tel"
                   inputmode="tel" placeholder="Für schnelle Rückfragen"
                   value="<?php echo e($werte['telefon']); ?>" />
          </div>

          <div class="field full">
            <label for="adresse">Adresse des Objekts</label>
            <input type="text" id="adresse" name="adresse" autocomplete="street-address"
                   placeholder="Straße, PLZ und Ort"
                   value="<?php echo e($werte['adresse']); ?>" />
          </div>

          <div class="field">
            <label for="anliegen">Ihr Anliegen</label>
            <select id="anliegen" name="anliegen">
              <option value="">Bitte wählen</option>
<?php foreach ($anliegenOptionen as $option): ?>
              <option<?php echo $werte['anliegen'] === $option ? ' selected' : ''; ?>><?php echo e($option); ?></option>
<?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="zeitraum">Gewünschter Zeitraum</label>
            <input type="text" id="zeitraum" name="zeitraum"
                   placeholder="z. B. im Frühjahr, so bald wie möglich"
                   value="<?php echo e($werte['zeitraum']); ?>" />
          </div>

          <div class="field full">
            <label for="nachricht">Ihre Nachricht <span class="req" aria-hidden="true">*</span></label>
            <textarea id="nachricht" name="nachricht" required
                      placeholder="Beschreiben Sie kurz, worum es geht – Baujahr, aktuelle Anlage, Raumgröße oder was Ihnen sonst wichtig ist."
                      <?php echo isset($fehler['nachricht']) ? 'aria-invalid="true" aria-describedby="fehler-nachricht"' : ''; ?>><?php echo e($werte['nachricht']); ?></textarea>
<?php if (isset($fehler['nachricht'])): ?>
            <p class="field-error" id="fehler-nachricht"><?php echo e($fehler['nachricht']); ?></p>
<?php endif; ?>
          </div>

          <!-- Spam-Falle: für Menschen unsichtbar, bitte nicht entfernen -->
          <div class="field full" aria-hidden="true"
               style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
            <label for="website">Bitte leer lassen</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" />
          </div>

          <label class="consent" for="datenschutz">
            <input type="checkbox" id="datenschutz" name="datenschutz" value="1" required
                   <?php echo !empty($_POST['datenschutz']) ? 'checked' : ''; ?> />
            <span>
              Ich habe die <a href="datenschutz.html">Datenschutzerklärung</a> gelesen und bin
              damit einverstanden, dass meine Angaben zur Bearbeitung meiner Anfrage
              gespeichert werden. <span class="req" aria-hidden="true">*</span>
<?php if (isset($fehler['datenschutz'])): ?>
              <span class="field-error" style="display:block"><?php echo e($fehler['datenschutz']); ?></span>
<?php endif; ?>
            </span>
          </label>

          <div class="field full">
            <button type="submit" class="btn btn-primary btn-block">
              Anfrage absenden
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </button>
            <p class="form-note">
              Mit <span aria-hidden="true">*</span> gekennzeichnete Felder sind Pflichtfelder.
              Ihre Daten werden ausschließlich zur Bearbeitung Ihrer Anfrage verwendet
              und nicht an Dritte weitergegeben.
            </p>
          </div>

        </div>
      </form>

<?php endif; ?>
    </div>

    <!-- Kontaktdaten -->
    <aside class="contact-aside">

      <div class="contact-box brand">
        <h2>Lieber direkt sprechen?</h2>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1 19.79 19.79 0 0 1 1.62 4.44 2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l1.88-1.88a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <a href="tel:+4971443345604">07144 3345604</a>
        </div>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <a href="mailto:reinholdruge@t-online.de">reinholdruge@t-online.de</a>
        </div>
      </div>

      <div class="contact-box">
        <h2>Anschrift</h2>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>
            R. Ruge<br />
            Sanitär Heizung Regenerative Energien<br />
            Zeppelinstraße 44<br />
            71711 Steinheim an der Murr
          </span>
        </div>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span>Termine nach Vereinbarung</span>
        </div>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>Inhaber: Reinhold Ruge · Meisterbetrieb</span>
        </div>
      </div>

      <div class="contact-box">
        <h2>So geht es weiter</h2>
        <ol style="list-style:none;display:grid;gap:0.9rem">
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Anfrage</strong> – Sie schildern Ihr Anliegen.</span>
          </li>
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Rückmeldung</strong> – wir klären offene Punkte, bei Bedarf vor Ort.</span>
          </li>
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Angebot</strong> – schriftlich, mit klaren Positionen.</span>
          </li>
        </ol>
      </div>

    </aside>
  </div>
</section>

</main>

<!-- ══ FOOTER ══ -->
<footer class="site-footer">
  <div class="footer-top">
    <div class="footer-brand">
      <a href="index.html" class="footer-logo" aria-label="R. Ruge – Startseite">
        <img src="assets/logo-ruge.png" width="712" height="424"
             alt="Logo R. Ruge – Sanitär, Heizung, Regenerative Energien" loading="lazy" />
      </a>
      <p>
        Inhabergeführter Meisterbetrieb für Sanitär, Heizung, regenerative
        Energien und Flaschnerei in Steinheim an der Murr.
      </p>
    </div>

    <div class="footer-col">
      <h4>Leistungen</h4>
      <ul>
        <li><a href="index.html#leistungen">Heizung &amp; Brennwerttechnik</a></li>
        <li><a href="index.html#leistungen">Sanitär &amp; Badumbau</a></li>
        <li><a href="index.html#energien">Regenerative Energien</a></li>
        <li><a href="index.html#leistungen">Flaschnerei</a></li>
        <li><a href="index.html#leistungen">Wartung &amp; Kundendienst</a></li>
        <li><a href="index.html#leistungen">Trinkwasserhygiene</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Kontakt</h4>
      <div class="footer-contact">
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>R. Ruge Sanitär Heizung Regenerative Energien<br />Zeppelinstraße 44<br />71711 Steinheim an der Murr</span>
        </div>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1 19.79 19.79 0 0 1 1.62 4.44 2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l1.88-1.88a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <a href="tel:+4971443345604">07144 3345604</a>
        </div>
        <div class="f-row">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <a href="mailto:reinholdruge@t-online.de">reinholdruge@t-online.de</a>
        </div>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <p>© <span data-year><?php echo date('Y'); ?></span> R. Ruge Sanitär Heizung Regenerative Energien</p>
    <nav aria-label="Rechtliches">
      <a href="impressum.html">Impressum</a>
      <a href="datenschutz.html">Datenschutz</a>
      <a href="index.html">Startseite</a>
    </nav>
  </div>
</footer>

<!-- ══ MOBILE ACTION BAR ══ -->
<div class="action-bar" aria-label="Schnellkontakt">
  <a class="ab-call" href="tel:+4971443345604">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1 19.79 19.79 0 0 1 1.62 4.44 2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l1.88-1.88a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
    Anrufen
  </a>
  <a class="ab-mail" href="mailto:reinholdruge@t-online.de">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    E-Mail
  </a>
</div>

<script src="assets/app.js" defer></script>
</body>
</html>
