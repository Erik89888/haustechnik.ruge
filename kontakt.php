<?php
/**
 * ---------------------------------------------------------------------------
 * Kontaktformular für R. Ruge Sanitär Heizung Regenerative Energien
 * ---------------------------------------------------------------------------
 * Läuft auf jedem üblichen Webhosting mit PHP 7.4 oder neuer.
 * Einstellungen (Empfänger, Absender, SMTP, Spamschutz) stehen in config.php.
 *
 * Versand über PHPMailer (libs/PHPMailer) statt der eingebauten mail()-
 * Funktion: sauberere Header, funktionierendes SMTP mit Login/TLS und damit
 * deutlich bessere Zustellraten. Ohne SMTP-Zugangsdaten in config.php nutzt
 * PHPMailer intern weiterhin mail(), zum Testen ausreichend, siehe Hinweis
 * dort.
 *
 * Ablauf: Formular -> Prüfung -> Versand -> Weiterleitung (Post/Redirect/Get),
 * damit ein Neuladen der Seite die Anfrage nicht doppelt verschickt.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';
require_once __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

// Ohne feste Zeitzone laufen viele Hosting-Server auf UTC - die Uhrzeit in
// der Mail waere dann um ein bis zwei Stunden verschoben.
date_default_timezone_set('Europe/Berlin');

if (session_status() === PHP_SESSION_NONE) {
    // Sitzungs-Cookie absichern. Es dient ausschließlich dem Spamschutz
    // (CSRF-Token, Ausfülldauer, Anfragelimit) und ist damit technisch
    // notwendig im Sinne von § 25 Abs. 2 TDDDG, es braucht keine Einwilligung.
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

/** Entfernt Zeilenumbrüche und Steuerzeichen, Schutz vor Header-Injection. */
function kopfzeile_saeubern(string $wert): string
{
    return trim(str_replace(array("\r", "\n", "\0", "\t"), ' ', $wert));
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

/**
 * Verschickt eine einzelne Mail über PHPMailer.
 *
 * Nutzt SMTP, sobald in config.php ein 'smtp_host' eingetragen ist,
 * andernfalls fällt PHPMailer intern auf den lokalen mail()-Transport
 * zurück (gleiches Verhalten wie vorher, nur mit sauberer MIME-Struktur).
 *
 * @return true bei Erfolg, sonst ein Fehlertext (String) zur Protokollierung.
 */
function mail_senden(
    array $config,
    string $empfaengerMail,
    string $empfaengerName,
    string $betreff,
    string $text,
    string $replyToMail = '',
    string $replyToName = ''
) {
    $mail = new PHPMailer(true);

    try {
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        if (!empty($config['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = (string) $config['smtp_host'];
            $mail->Port       = (int) $config['smtp_port'];
            $mail->SMTPAuth   = true;
            $mail->Username   = (string) $config['smtp_benutzer'];
            $mail->Password   = (string) $config['smtp_passwort'];
            $mail->SMTPSecure = (string) $config['smtp_verschluesselung'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // Kein SMTP hinterlegt -> lokaler mail()-Transport des Servers.
            $mail->isMail();
        }

        $mail->setFrom((string) $config['absender_mail'], (string) $config['absender_name']);
        $mail->addAddress($empfaengerMail, $empfaengerName);

        if ($replyToMail !== '') {
            $mail->addReplyTo($replyToMail, $replyToName);
        }

        $mail->Subject = $betreff;
        $mail->isHTML(false);
        $mail->Body = $text;

        $mail->send();
        return true;
    } catch (MailerException $exception) {
        return $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $exception->getMessage();
    }
}

/* ───────────────────────── Grunddaten ───────────────────────── */

$anredeOptionen = array('Frau', 'Herr', 'Familie', 'Firma', 'Keine Angabe');

$anliegenOptionen = array(
    'Bad & Sanitär'               => 'Sanitär',
    'Heizung & Brennwerttechnik'  => 'Heizung',
    'Regenerative Energien'       => 'Energien',
    'Flaschnerei'                 => 'Flaschnerei',
    'Wartung & Reparatur'         => 'Wartung',
    'Trinkwasserhygiene'          => 'Trinkwasser',
    'Sonstiges'                   => 'Sonstiges',
);

$anliegenIcons = array(
    'Bad & Sanitär'              => '<path d="M4 12h16M8 4a4 4 0 0 1 8 0"/><line x1="8" y1="16" x2="8" y2="18"/><line x1="12" y1="16" x2="12" y2="19"/><line x1="16" y1="16" x2="16" y2="18"/>',
    'Heizung & Brennwerttechnik' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    'Regenerative Energien'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'Flaschnerei'                => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'Wartung & Reparatur'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'Trinkwasserhygiene'         => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5-2-1.6-3.5-4-4-6.5-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
    'Sonstiges'                  => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
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
        if ($werte['anliegen'] !== '' && !array_key_exists($werte['anliegen'], $anliegenOptionen)) {
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

        $betreff = $config['betreff_prefix'] . ': '
            . ($werte['anliegen'] !== '' ? $werte['anliegen'] : 'Allgemeine Anfrage')
            . ' von ' . $werte['name'];

        $zeile = str_repeat('-', 58);
        $text  = "Neue Anfrage über haustechnik-ruge.de\n" . $zeile . "\n\n"
               . 'Anrede:        ' . ($werte['anrede']   !== '' ? $werte['anrede']   : 'keine Angabe') . "\n"
               . 'Name:          ' . $werte['name'] . "\n"
               . 'E-Mail:        ' . $werte['email'] . "\n"
               . 'Telefon:       ' . ($werte['telefon']  !== '' ? $werte['telefon']  : 'keine Angabe') . "\n"
               . 'Objektadresse: ' . ($werte['adresse']  !== '' ? $werte['adresse']  : 'keine Angabe') . "\n"
               . 'Anliegen:      ' . ($werte['anliegen'] !== '' ? $werte['anliegen'] : 'keine Angabe') . "\n"
               . 'Zeitraum:      ' . ($werte['zeitraum'] !== '' ? $werte['zeitraum'] : 'keine Angabe') . "\n\n"
               . $zeile . "\nNachricht:\n" . $zeile . "\n\n"
               . $werte['nachricht'] . "\n\n"
               . $zeile . "\n"
               . 'Gesendet am ' . date('d.m.Y \u\m H:i') . " Uhr\n"
               . "Antworten Sie einfach auf diese E-Mail. Sie geht direkt an den Absender.\n";

        $versandOk = mail_senden(
            $config,
            (string) $config['empfaenger'],
            (string) $config['absender_name'],
            $betreff,
            $text,
            $besucherMail,
            $werte['name']
        );

        /* Optionales Protokoll als Ausfallsicherung */
        if (!empty($config['protokoll_datei'])) {
            @file_put_contents(
                (string) $config['protokoll_datei'],
                '[' . date('Y-m-d H:i:s') . '] ' . ($versandOk === true ? 'OK' : ('FEHLER: ' . $versandOk)) . "\n"
                . $text . "\n\n",
                FILE_APPEND | LOCK_EX
            );
        }

        if ($versandOk === true) {

            /* Eingangsbestätigung an den Interessenten */
            if (!empty($config['eingangsbestaetigung'])) {
                $antwortText =
                      'Guten Tag ' . $werte['name'] . ",\n\n"
                    . "vielen Dank für Ihre Anfrage. Sie ist bei uns eingegangen und wir\n"
                    . "melden uns persönlich bei Ihnen zurück.\n\n"
                    . $zeile . "\nIhre Angaben\n" . $zeile . "\n\n"
                    . 'Anliegen:  ' . ($werte['anliegen'] !== '' ? $werte['anliegen'] : 'Allgemeine Anfrage') . "\n"
                    . 'Zeitraum:  ' . ($werte['zeitraum'] !== '' ? $werte['zeitraum'] : 'keine Angabe') . "\n\n"
                    . $werte['nachricht'] . "\n\n"
                    . $zeile . "\n\n"
                    . "Sie erreichen uns auch direkt:\n"
                    . "Telefon 07144 3345604\n"
                    . "E-Mail  reinholdruge@t-online.de\n\n"
                    . "Freundliche Grüße\n"
                    . "Reinhold Ruge\n\n"
                    . "R. Ruge, Sanitär · Heizung · Regenerative Energien\n"
                    . "Zeppelinstraße 44, 71711 Steinheim an der Murr\n";

                mail_senden(
                    $config,
                    $besucherMail,
                    $werte['name'],
                    'Ihre Anfrage bei R. Ruge: Eingangsbestätigung',
                    $antwortText,
                    'reinholdruge@t-online.de',
                    'R. Ruge'
                );
            }

            /* Zähler für das Stundenlimit hochsetzen */
            $_SESSION['sendungen'][] = time();

            /* Post/Redirect/Get verhindert doppeltes Absenden beim Neuladen */
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

/* Keine Zwischenspeicherung, sonst zeigt der Zurück-Button veraltete Zustände */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title><?php echo $erfolgreich ? 'Danke für Ihre Anfrage' : 'Kontakt'; ?> | R. Ruge Sanitär Heizung Regenerative Energien</title>
<meta name="description" content="Kontakt zum Meisterbetrieb R. Ruge in Steinheim an der Murr: Anfrage für Bad, Heizung, Solar oder Flaschnerei, kostenlos und unverbindlich." />
<meta name="theme-color" content="#01328F" />
<link rel="icon" type="image/png" href="favicon.ruge.png" />
<link rel="apple-touch-icon" href="favicon.ruge.png" />
<meta name="robots" content="noindex,follow" />

<link rel="preload" href="inter-400-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="preload" href="montserrat-800-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="stylesheet" href="fonts.css" />
<script>document.documentElement.classList.add('js');</script>

<style>
  :root {
    --orange:        #FF6B35;
    --orange-dark:   #E85A28;
    --orange-glow:   rgba(255, 107, 53, 0.3);
    --brand-blue:    #0A2647;
    --brand-blue-d:  #030D1E;
    --navy:          #050609;
    --navy-blue:     #0b1330;
    --navy-mid:      #07091a;
    --card-bg:       #0a0e20;
    --surface:       rgba(255,255,255,0.04);
    --border:        rgba(255,255,255,0.08);
    --text-primary:  #FFFFFF;
    --text-secondary:#A7AFC6;
    --text-muted:    #5A6280;
    --header-h: 86px;
  }
  *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'Inter', system-ui, sans-serif;
    background: linear-gradient(165deg, var(--navy) 0%, var(--navy-blue) 45%, var(--navy) 100%);
    background-attachment: fixed;
    color: var(--text-primary);
    line-height: 1.7;
    overflow-x: hidden;
  }
  body.nav-open { overflow: hidden; }
  h1, h2, h3, h4 { font-family: 'Montserrat', sans-serif; line-height: 1.2; text-wrap: balance; }
  img, svg { max-width: 100%; }
  img { height: auto; display: block; }
  a { color: inherit; }

  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: var(--navy); }
  ::-webkit-scrollbar-thumb { background: var(--orange); border-radius: 3px; }
  ::selection { background: var(--orange); color: #fff; }
  :focus-visible { outline: 2px solid var(--orange); outline-offset: 3px; border-radius: 3px; }

  .skip-link {
    position: absolute; left: -9999px; top: 0; z-index: 2000;
    background: var(--orange); color: #fff; padding: 0.8rem 1.4rem; font-weight: 600; text-decoration: none;
  }
  .skip-link:focus { left: 12px; top: 12px; }
  .cursor-glow {
    position: fixed; width: 420px; height: 420px; border-radius: 50%;
    background: radial-gradient(circle, rgba(255,107,53,0.07) 0%, transparent 70%);
    pointer-events: none; transform: translate(-50%, -50%);
    z-index: 9999; transition: opacity 0.3s ease;
  }

  /* ── BUTTONS ── */
  .btn-primary {
    background: var(--orange); color: white;
    padding: 0.85rem 1.8rem; border-radius: 6px; text-decoration: none;
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.82rem;
    letter-spacing: 1.3px; text-transform: uppercase; border: none; cursor: pointer;
    transition: all 0.3s ease; position: relative; overflow: hidden;
    display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
  }
  .btn-primary svg { width: 16px; height: 16px; flex-shrink: 0; }
  .btn-primary:hover { background: var(--orange-dark); transform: translateY(-2px); box-shadow: 0 8px 28px var(--orange-glow); }
  .btn-primary[aria-busy="true"] { opacity: 0.75; cursor: wait; transform: none; }
  .btn-outline {
    background: rgba(255,255,255,0.03); color: var(--text-primary);
    padding: 0.85rem 1.8rem; border-radius: 6px; text-decoration: none;
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.82rem;
    letter-spacing: 1.3px; text-transform: uppercase; border: 1px solid var(--border);
    cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
  }
  .btn-outline svg { width: 16px; height: 16px; flex-shrink: 0; }
  .btn-outline:hover { border-color: var(--orange); color: var(--orange); box-shadow: 0 0 22px var(--orange-glow); }
  .btn-block { width: 100%; }

  /* ── HEADER ── */
  header.site-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
    background: linear-gradient(100deg, var(--brand-blue) 0%, var(--brand-blue-d) 100%);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 12px 34px rgba(0,0,0,0.45);
  }
  .header-inner {
    max-width: 1320px; margin-inline: auto;
    min-height: var(--header-h);
    padding: 0.6rem 6%; display: flex; align-items: center; justify-content: space-between; gap: 1.5rem;
  }
  .logo { display: inline-flex; align-items: center; flex-shrink: 0; }
  .logo img { height: 58px; width: auto; }
  .header-nav { display: flex; align-items: center; gap: 2.2rem; }
  .header-nav a {
    color: rgba(255,255,255,0.82); text-decoration: none; font-size: 0.85rem;
    font-weight: 600; letter-spacing: 1px; text-transform: uppercase;
    transition: color 0.3s; position: relative; padding: 0.3rem 0;
  }
  .header-nav a::after {
    content: ''; position: absolute; bottom: -2px; left: 0;
    width: 0; height: 2px; background: var(--orange); border-radius: 2px; transition: width 0.3s;
  }
  .header-nav a:hover, .header-nav a[aria-current="page"] { color: #fff; }
  .header-nav a:hover::after, .header-nav a[aria-current="page"]::after { width: 100%; }
  .hamburger {
    display: none; flex-direction: column; gap: 5px; cursor: pointer; padding: 4px;
    background: none; border: 0;
  }
  .hamburger span { width: 24px; height: 2px; background: #fff; border-radius: 2px; transition: all 0.3s; display: block; }
  .hamburger[aria-expanded="true"] span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
  .hamburger[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
  .hamburger[aria-expanded="true"] span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
  .mobile-nav {
    position: fixed; inset: 0; background: rgba(5,6,9,0.97); backdrop-filter: blur(20px);
    z-index: 999; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.6rem;
    transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.77,0,0.175,1);
    padding: 2rem;
  }
  .mobile-nav.open { transform: translateX(0); }
  .mobile-nav a {
    font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800;
    color: var(--text-primary); text-decoration: none; text-transform: uppercase;
    letter-spacing: 2px; transition: color 0.3s;
  }
  .mobile-nav a:hover, .mobile-nav a[aria-current="page"] { color: var(--orange); }
  .mobile-nav .mobile-actions { display: flex; flex-direction: column; gap: 0.9rem; margin-top: 1rem; align-items: center; }
  .mobile-nav .mobile-actions .btn-primary,
  .mobile-nav .mobile-actions .btn-outline {
    padding: 0.55rem 1.1rem; font-size: 0.68rem; letter-spacing: 0.8px; white-space: nowrap;
  }
  .mobile-meta { margin-top: 1.5rem; font-size: 0.82rem; color: var(--text-secondary); text-align: center; line-height: 1.8; }

  /* ── SEITENKOPF ── */
  .page-head {
    position: relative; overflow: hidden;
    padding: calc(var(--header-h) + 5rem) 6% 4rem;
    background: linear-gradient(165deg, #04050a 0%, #0b1330 55%, #04050a 100%);
  }
  .page-head::after {
    content: ''; position: absolute; top: -120px; right: -100px; width: 500px; height: 500px; border-radius: 50%;
    background: radial-gradient(circle, rgba(255,107,53,0.10) 0%, transparent 70%); pointer-events: none;
  }
  .page-head .inner { position: relative; z-index: 1; max-width: 760px; }
  .page-head .eyebrow {
    display: inline-flex; align-items: center; gap: 0.7rem;
    font-size: 0.7rem; font-weight: 600; letter-spacing: 3px; text-transform: uppercase;
    color: var(--orange); margin-bottom: 1.2rem;
  }
  .page-head .eyebrow::before { content: ''; width: 28px; height: 1px; background: var(--orange); }
  .page-head h1 { font-size: clamp(2.2rem, 5vw, 3.4rem); font-weight: 800; letter-spacing: -0.5px; margin-bottom: 1rem; }
  .page-head .inner > p { color: var(--text-secondary); font-size: 1.02rem; }

  /* ── KONTAKT-GRID ── */
  .section { padding: 0 6% clamp(4rem, 8vw, 6rem); background: var(--navy-mid); }
  .contact-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 2.5rem; max-width: 1180px; margin-inline: auto; align-items: start; }

  /* ── FORM CARD ── */
  .form-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
  .form-card-head { padding: 1.8rem 2rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; }
  .form-card-icon {
    width: 46px; height: 46px; background: var(--orange); border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
  }
  .form-card-icon svg { width: 21px; height: 21px; stroke: #fff; }
  .form-card-head h2 { font-size: 1.15rem; font-weight: 800; }
  .form-card-head p { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.15rem; }

  form#kontaktForm { padding: 2rem; }

  .form-status { display: none; padding: 0.9rem 1.1rem; border-radius: 8px; font-size: 0.88rem; margin-bottom: 1.4rem; }
  .form-status.show { display: block; }
  .form-status.err { background: rgba(220,60,60,0.12); border: 1px solid rgba(220,60,60,0.35); color: #ffb3b3; }

  .field-divider { display: flex; align-items: center; gap: 0.9rem; margin: 0 0 1.2rem; }
  .field-divider:not(:first-child) { margin-top: 2.2rem; }
  .field-divider::before, .field-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .field-divider span { font-size: 0.68rem; letter-spacing: 3px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; }

  /* Anliegen als Kachel-Auswahl */
  .topic-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.65rem; }
  .topic-option input { position: absolute; opacity: 0; width: 0; height: 0; }
  .topic-option label {
    display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
    padding: 0.9rem 0.5rem; background: var(--surface); border: 1.5px solid var(--border); border-radius: 10px;
    cursor: pointer; transition: all 0.2s; text-align: center;
  }
  .topic-option label:hover { border-color: rgba(255,107,53,0.4); }
  .topic-option input:checked + label { border-color: var(--orange); background: rgba(255,107,53,0.1); }
  .topic-option svg { width: 20px; height: 20px; stroke: var(--orange); }
  .topic-option span.label-text { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.4px; color: var(--text-secondary); }
  .topic-option input:checked + label span.label-text { color: #fff; }

  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
  .field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.1rem; }
  .field.full { grid-column: 1 / -1; }
  .field label { font-size: 0.72rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); }
  .field .req { color: var(--orange); }
  .field input, .field select, .field textarea {
    width: 100%; background: var(--surface); border: 1.5px solid var(--border); border-radius: 8px;
    padding: 0.85rem 1rem; color: var(--text-primary); font-family: inherit; font-size: 0.92rem;
    transition: all 0.2s; outline: none; appearance: none; -webkit-appearance: none;
  }
  .field select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%235A6280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.4rem;
  }
  .field select option { background: #0e1638; }
  .field input::placeholder, .field textarea::placeholder { color: var(--text-muted); }
  .field input:focus, .field select:focus, .field textarea:focus {
    border-color: var(--orange); background: rgba(255,107,53,0.05); box-shadow: 0 0 0 3px var(--orange-glow);
  }
  .field textarea { resize: none; min-height: 130px; line-height: 1.6; }
  .field-error { font-size: 0.78rem; color: #ff9b9b; }
  .field[aria-invalid], .field input[aria-invalid="true"] { border-color: #d9534f; }

  .consent { display: flex; align-items: flex-start; gap: 0.8rem; margin: 0.4rem 0 1.6rem; cursor: pointer; }
  .consent input {
    width: 19px; height: 19px; min-width: 19px; margin-top: 2px; background: var(--surface);
    border: 1.5px solid var(--border); border-radius: 4px; cursor: pointer; appearance: none; -webkit-appearance: none;
    position: relative; flex-shrink: 0;
  }
  .consent input:checked { background: var(--orange); border-color: var(--orange); }
  .consent input:checked::after {
    content: ''; position: absolute; left: 5px; top: 1px; width: 5px; height: 9px;
    border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
  }
  .consent span { font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; }
  .consent a { color: var(--orange); text-decoration: none; }
  .consent a:hover { text-decoration: underline; }

  .form-note { font-size: 0.76rem; color: var(--text-muted); margin-top: 0.9rem; line-height: 1.6; }

  /* ── SUCCESS ── */
  .success-panel { padding: 2.8rem 2.2rem; text-align: left; }
  .success-icon {
    width: 60px; height: 60px; border-radius: 50%; background: rgba(255,107,53,0.12); border: 2px solid var(--orange);
    display: flex; align-items: center; justify-content: center; margin-bottom: 1.4rem;
  }
  .success-icon svg { width: 26px; height: 26px; stroke: var(--orange); }
  .success-panel h2 { font-size: 1.5rem; font-weight: 800; margin-bottom: 0.8rem; }
  .success-panel p { color: var(--text-secondary); max-width: 46ch; margin-bottom: 1.8rem; }
  .cta-actions { display: flex; gap: 1rem; flex-wrap: wrap; }

  /* ── ASIDE ── */
  .contact-aside { display: flex; flex-direction: column; gap: 1.2rem; }
  .contact-box { background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; padding: 1.6rem; }
  .contact-box.brand { border-color: rgba(255,107,53,0.3); }
  .contact-box h2 { font-size: 0.95rem; font-weight: 700; margin-bottom: 1rem; }
  .f-row { display: flex; align-items: flex-start; gap: 0.7rem; font-size: 0.88rem; color: var(--text-secondary); margin-bottom: 0.7rem; }
  .f-row:last-child { margin-bottom: 0; }
  .f-row svg { width: 17px; height: 17px; stroke: var(--orange); flex-shrink: 0; margin-top: 2px; }
  .f-row a { color: var(--text-secondary); text-decoration: none; transition: color 0.3s; }
  .f-row a:hover { color: var(--orange); }
  .steps { list-style: none; display: grid; gap: 0.9rem; }

  /* ── FOOTER ── */
  /* Seitenabschluss: durchgehend schwarz, kein Verlauf */
  footer.site-footer { background: #000; border-top: 1px solid var(--border); padding: 3.5rem 6% 2rem; }
  .footer-top { display: grid; grid-template-columns: 1.6fr 1fr 1fr; gap: 3rem; padding-bottom: 2.5rem; border-bottom: 1px solid var(--border); margin-bottom: 1.8rem; }
  .footer-logo { display: inline-block; margin-bottom: 1.1rem; }
  .footer-logo img { height: 46px; width: auto; }
  .footer-brand p { font-size: 0.85rem; color: var(--text-secondary); line-height: 1.7; max-width: 300px; }
  .footer-col h4 { font-size: 0.68rem; letter-spacing: 3px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 1.1rem; }
  .footer-col ul { list-style: none; }
  .footer-col ul li { margin-bottom: 0.65rem; }
  .footer-col ul li a { color: var(--text-secondary); text-decoration: none; font-size: 0.85rem; transition: color 0.3s; }
  .footer-col ul li a:hover { color: var(--orange); }
  .footer-contact { display: flex; flex-direction: column; gap: 0.8rem; }
  .footer-bottom { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; max-width: 1320px; margin-inline: auto; }
  .footer-bottom p { font-size: 0.78rem; color: var(--text-muted); }
  .footer-bottom-links { display: flex; gap: 1.5rem; }
  .footer-bottom-links a { color: var(--text-muted); text-decoration: none; font-size: 0.78rem; transition: color 0.3s; }
  .footer-bottom-links a:hover { color: var(--orange); }

  /* ── MOBILE ACTION BAR ── */
  .action-bar {
    display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 900;
    background: rgba(5,6,9,0.92); backdrop-filter: blur(14px); border-top: 1px solid var(--border);
    padding: 0.7rem 5%; gap: 0.7rem;
  }
  .action-bar a {
    flex: 1; min-width: 0; display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    padding: 0.85rem 0.5rem; border-radius: 8px; text-decoration: none; white-space: nowrap;
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.76rem;
    letter-spacing: 0.3px; text-transform: uppercase;
  }
  .action-bar svg { width: 16px; height: 16px; }
  .ab-call { background: rgba(255,255,255,0.06); color: #fff; border: 1px solid var(--border); }
  .ab-mail { background: var(--orange); color: #fff; }

  .reveal { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
  .reveal.visible { opacity: 1; transform: translateY(0); }

  /* ── RESPONSIVE ── */
  @media (max-width: 1024px) {
    .footer-top { grid-template-columns: 1fr 1fr; }
    .contact-grid { grid-template-columns: 1fr; }
    .topic-grid { grid-template-columns: repeat(4, 1fr); }
  }
  @media (max-width: 768px) {
    :root { --header-h: 68px; }
    .logo img { height: 42px; }
    .header-nav, .header-inner .btn-primary { display: none; }
    .hamburger { display: flex; }
    .page-head { padding-top: calc(var(--header-h) + 3rem); }
    .form-grid { grid-template-columns: 1fr; }
    .topic-grid { grid-template-columns: repeat(3, 1fr); }
    .footer-top { grid-template-columns: 1fr; }
    .footer-bottom { flex-direction: column; text-align: center; }
    .action-bar { display: flex; }
    body { padding-bottom: 68px; }
    .cursor-glow { display: none; }
    form#kontaktForm, .form-card-head { padding: 1.4rem; }
  }
</style>
<link rel="stylesheet" href="motion.css" />
<link rel="stylesheet" href="motion.v2.css" />
</head>

<body>
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<div class="cursor-glow" id="cursorGlow"></div>

<!-- ══ MOBILE NAV ══ -->
<nav class="mobile-nav" id="mobileNav" aria-label="Mobile Navigation">
  <a href="leistungen.html">Leistungen</a>
  <a href="energien.html">Energien</a>
  <a href="index.html#bereiche">Bereiche</a>
  <a href="referenzen.html">Referenzen</a>
  <a href="index.html#ueber">Über uns</a>
  <a href="kontakt.php" aria-current="page">Kontakt</a>
  <div class="mobile-actions">
    <a href="tel:+4971443345604" class="btn-primary">07144 3345604</a>
    <a href="mailto:reinholdruge@t-online.de" class="btn-outline">E-Mail schreiben</a>
  </div>
  <div class="mobile-meta">
    R. Ruge Sanitär Heizung Regenerative Energien<br />
    Zeppelinstraße 44 · 71711 Steinheim an der Murr
  </div>
</nav>

<!-- ══ HEADER ══ -->
<header class="site-header" id="header">
  <div class="header-inner">
    <a href="index.html" class="logo" aria-label="Zur Startseite von R. Ruge">
      <img src="logo-ruge.png" width="712" height="424" alt="Logo R. Ruge Sanitär Heizung Regenerative Energien" />
    </a>
    <nav class="header-nav" aria-label="Hauptnavigation">
      <a href="leistungen.html">Leistungen</a>
      <a href="energien.html">Energien</a>
      <a href="index.html#bereiche">Bereiche</a>
      <a href="referenzen.html">Referenzen</a>
      <a href="index.html#ueber">Über uns</a>
      <a href="kontakt.php" aria-current="page">Kontakt</a>
    </nav>
    <a href="tel:+4971443345604" class="btn-primary">07144 3345604</a>
    <button class="hamburger" id="hamburger" type="button" aria-label="Menü öffnen" aria-expanded="false" aria-controls="mobileNav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

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
      uns kurz Ihr Anliegen. Wir melden uns persönlich bei Ihnen zurück, kostenlos
      und unverbindlich.
    </p>
<?php endif; ?>
  </div>
</section>

<!-- ══ FORMULAR + KONTAKTDATEN ══ -->
<section class="section">
  <div class="contact-grid">

    <div class="form-card reveal" id="formular">
<?php if ($erfolgreich): ?>

      <div class="form-card-head">
        <div class="form-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div><h2>Nachricht versendet</h2></div>
      </div>

      <!-- ── Erfolgsmeldung nach dem Versand ── -->
      <div class="success-panel">
        <div class="success-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Vielen Dank, Ihre Anfrage ist angekommen.</h2>
        <p>
          Wir haben Ihnen eine Eingangsbestätigung per E-Mail geschickt und melden
          uns persönlich bei Ihnen zurück. Sollte es eilig sein, erreichen Sie uns
          jederzeit direkt am Telefon.
        </p>
        <div class="cta-actions">
          <a href="tel:+4971443345604" class="btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13.1 19.79 19.79 0 0 1 1.62 4.44 2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l1.88-1.88a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            07144 3345604
          </a>
          <a href="index.html" class="btn-outline">Zurück zur Startseite</a>
        </div>
      </div>

<?php else: ?>

      <div class="form-card-head">
        <div class="form-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <h2>Nachricht senden</h2>
          <p>Antwort in der Regel innerhalb von 24 Stunden</p>
        </div>
      </div>

      <form method="post" action="kontakt.php#formular" accept-charset="UTF-8" id="kontaktForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>" />

<?php if ($hinweis !== '' || !empty($fehler)): ?>
        <div class="form-status show err" role="alert">
          <?php echo $hinweis !== ''
              ? e($hinweis)
              : 'Bitte prüfen Sie die rot markierten Felder, dann klappt es gleich.'; ?>
        </div>
<?php endif; ?>

        <!-- Anliegen als Kacheln -->
        <div class="field-divider"><span>Worum geht es?</span></div>
        <div class="topic-grid">
<?php foreach ($anliegenOptionen as $wert_option => $kurzname): ?>
          <div class="topic-option">
            <input type="radio" name="anliegen" id="anliegen-<?php echo e(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($kurzname))); ?>"
                   value="<?php echo e($wert_option); ?>"
                   <?php echo $werte['anliegen'] === $wert_option ? 'checked' : ''; ?> />
            <label for="anliegen-<?php echo e(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($kurzname))); ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $anliegenIcons[$wert_option]; ?></svg>
              <span class="label-text"><?php echo e($kurzname); ?></span>
            </label>
          </div>
<?php endforeach; ?>
        </div>

        <!-- Kontaktdaten -->
        <div class="field-divider"><span>Ihre Daten</span></div>
        <div class="form-grid">

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

          <div class="field">
            <label for="adresse">Adresse des Objekts</label>
            <input type="text" id="adresse" name="adresse" autocomplete="street-address"
                   placeholder="Straße, PLZ und Ort"
                   value="<?php echo e($werte['adresse']); ?>" />
          </div>

        </div>

        <!-- Nachricht -->
        <div class="field-divider"><span>Ihre Nachricht</span></div>
        <div class="form-grid">

          <div class="field full">
            <label for="zeitraum">Gewünschter Zeitraum</label>
            <input type="text" id="zeitraum" name="zeitraum"
                   placeholder="z. B. im Frühjahr, so bald wie möglich"
                   value="<?php echo e($werte['zeitraum']); ?>" />
          </div>

          <div class="field full">
            <label for="nachricht">Ihre Nachricht <span class="req" aria-hidden="true">*</span></label>
            <textarea id="nachricht" name="nachricht" required
                      placeholder="Beschreiben Sie kurz, worum es geht: Baujahr, aktuelle Anlage, Raumgröße oder was Ihnen sonst wichtig ist."
                      <?php echo isset($fehler['nachricht']) ? 'aria-invalid="true" aria-describedby="fehler-nachricht"' : ''; ?>><?php echo e($werte['nachricht']); ?></textarea>
<?php if (isset($fehler['nachricht'])): ?>
            <p class="field-error" id="fehler-nachricht"><?php echo e($fehler['nachricht']); ?></p>
<?php endif; ?>
          </div>

        </div>

        <!-- Spam-Falle: für Menschen unsichtbar, bitte nicht entfernen -->
        <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
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

        <button type="submit" class="btn-primary btn-block">
          Anfrage absenden
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <p class="form-note">
          Mit <span aria-hidden="true">*</span> gekennzeichnete Felder sind Pflichtfelder.
          Ihre Daten werden ausschließlich zur Bearbeitung Ihrer Anfrage verwendet
          und nicht an Dritte weitergegeben.
        </p>
      </form>

<?php endif; ?>
    </div>

    <!-- Kontaktdaten -->
    <aside class="contact-aside reveal">

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
        <ol class="steps">
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Anfrage:</strong> Sie schildern Ihr Anliegen.</span>
          </li>
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Rückmeldung:</strong> Wir klären offene Punkte, bei Bedarf vor Ort.</span>
          </li>
          <li class="f-row" style="align-items:flex-start">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span><strong style="color:#DCE5F5">Angebot:</strong> schriftlich, mit klaren Positionen.</span>
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
      <a href="index.html" class="footer-logo" aria-label="Zur Startseite von R. Ruge">
        <img src="logo-ruge.png" width="712" height="424"
             alt="Logo R. Ruge Sanitär Heizung Regenerative Energien" loading="lazy" />
      </a>
      <p>
        Inhabergeführter Meisterbetrieb für Sanitär, Heizung, regenerative
        Energien und Flaschnerei in Steinheim an der Murr.
      </p>
    </div>

    <div class="footer-col">
      <h4>Leistungen</h4>
      <ul>
        <li><a href="leistungen.html#heizung">Heizung &amp; Brennwerttechnik</a></li>
        <li><a href="leistungen.html#sanitaer">Sanitär &amp; Badumbau</a></li>
        <li><a href="energien.html">Regenerative Energien</a></li>
        <li><a href="leistungen.html#flaschnerei">Flaschnerei</a></li>
        <li><a href="leistungen.html#wartung">Wartung &amp; Kundendienst</a></li>
        <li><a href="leistungen.html#trinkwasser">Trinkwasserhygiene</a></li>
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
    <nav aria-label="Rechtliches" class="footer-bottom-links">
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

<script>
  var glow = document.getElementById('cursorGlow');
  if (glow && window.matchMedia('(pointer:fine)').matches) {
    document.addEventListener('mousemove', function (e) {
      glow.style.left = e.clientX + 'px';
      glow.style.top  = e.clientY + 'px';
    });
  }

  var header = document.getElementById('header');
  if (header) {
    var onScroll = function () { header.classList.toggle('scrolled', window.scrollY > 20); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  var burger = document.getElementById('hamburger');
  var mobileNav = document.getElementById('mobileNav');
  function setNav(open) {
    mobileNav.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    document.body.classList.toggle('nav-open', open);
  }
  if (burger && mobileNav) {
    burger.addEventListener('click', function () { setNav(!mobileNav.classList.contains('open')); });
    mobileNav.addEventListener('click', function (e) { if (e.target.closest('a')) setNav(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setNav(false); });
    var mq = window.matchMedia('(min-width: 901px)');
    var resetNav = function (ev) { if (ev.matches) setNav(false); };
    mq.addEventListener ? mq.addEventListener('change', resetNav) : mq.addListener(resetNav);
  }

  var revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var revealObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('visible'); revealObs.unobserve(en.target); } });
    }, { threshold: 0.1 });
    revealEls.forEach(function (el) { revealObs.observe(el); });
    setTimeout(function () { revealEls.forEach(function (el) { if (el.getBoundingClientRect().top < window.innerHeight) el.classList.add('visible'); }); }, 1200);
  } else {
    revealEls.forEach(function (el) { el.classList.add('visible'); });
  }

  var form = document.getElementById('kontaktForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!form.checkValidity()) {
        e.preventDefault();
        var ersteLuecke = form.querySelector(':invalid');
        if (ersteLuecke) { ersteLuecke.focus(); ersteLuecke.reportValidity(); }
        return;
      }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.setAttribute('aria-busy', 'true'); btn.textContent = 'Wird gesendet …'; }
    });

    var ersterFehler = document.querySelector('.form-status.err, .field-error');
    if (ersterFehler && !window.location.hash) {
      ersterFehler.scrollIntoView({ block: 'center' });
    }
  }

  document.querySelectorAll('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });
</script>
<script src="motion.js" defer></script>
<script src="motion.v2.js" defer></script>
</body>
</html>
