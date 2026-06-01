<?php
/**
 * Lightweight i18n for RestoPOS (mirrors the parking app's I18n).
 *
 * Language is chosen by, in order:
 *   1. ?lang=it|en in the URL (the EN/IT switcher) — remembered in a cookie
 *   2. resto_lang cookie (the user's last choice)
 *   3. default 'en'
 *
 * Use t('key') anywhere after functions.php is included. Missing keys fall
 * back to the key itself, so partially-translated pages still render.
 */

const I18N_COOKIE   = 'resto_lang';
const I18N_FALLBACK = 'en';

function i18n_available(): array
{
    return ['en', 'it'];
}

/** Initialise the language and load its strings. Call once (functions.php does). */
function i18n_init(string $default = I18N_FALLBACK): string
{
    if (!empty($GLOBALS['__i18n_done'])) {
        return currentLang();
    }
    $available = i18n_available();
    $def = in_array($default, $available, true) ? $default : I18N_FALLBACK;

    $requested = $_GET['lang'] ?? $_COOKIE[I18N_COOKIE] ?? $def;
    $lang = in_array($requested, $available, true) ? $requested : $def;

    if (isset($_GET['lang']) && $_GET['lang'] === $lang && !headers_sent()) {
        setcookie(I18N_COOKIE, $lang, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
    }

    $file = __DIR__ . '/../lang/' . $lang . '.php';
    $GLOBALS['__i18n_lang']    = $lang;
    $GLOBALS['__i18n_strings'] = is_file($file) ? require $file : [];
    $GLOBALS['__i18n_done']    = true;
    return $lang;
}

function currentLang(): string
{
    return $GLOBALS['__i18n_lang'] ?? I18N_FALLBACK;
}

/** Translate a key. {placeholders} are replaced from $vars. */
function t(string $key, array $vars = []): string
{
    $s = $GLOBALS['__i18n_strings'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

/** Translate + HTML-escape (handy in templates). */
function te(string $key, array $vars = []): string
{
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}

/** label => code, for the switcher. */
function langLabels(): array
{
    return ['EN' => 'en', 'IT' => 'it'];
}

/** Build a URL to the current page with ?lang=<code> applied. */
function langSwitchUrl(string $code): string
{
    $path  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $query = $_GET;
    $query['lang'] = $code;
    return $path . '?' . http_build_query($query);
}
