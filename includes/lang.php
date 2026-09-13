<?php
declare(strict_types=1);

const DEFAULT_LANGUAGE = 'fa';

/**
 * Discovers installed languages from lang/*.php and reads each one's
 * display name from its own 'app.native_name' key, so adding a language
 * only requires dropping a new lang/<code>.php file — no code edit.
 */
function supportedLanguages(): array
{
    static $languages = null;
    if ($languages !== null) {
        return $languages;
    }

    $languages = [];
    foreach (glob(APP_ROOT . '/lang/*.php') as $file) {
        $code = basename($file, '.php');
        $languages[$code] = loadTranslations($code)['app.native_name'] ?? $code;
    }
    return $languages;
}

function currentLanguage(): string
{
    $lang = (string) ($_SESSION['lang'] ?? DEFAULT_LANGUAGE);
    return array_key_exists($lang, supportedLanguages()) ? $lang : DEFAULT_LANGUAGE;
}

function setLanguage(string $lang): void
{
    if (array_key_exists($lang, supportedLanguages())) {
        $_SESSION['lang'] = $lang;
    }
}

function loadTranslations(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $file = APP_ROOT . '/lang/' . $lang . '.php';
    $cache[$lang] = is_file($file) ? require $file : [];
    return $cache[$lang];
}

/**
 * Translate a key for the current language, falling back to the default
 * language and finally to the raw key itself if nothing matches.
 */
function t(string $key, array $vars = []): string
{
    $lang = currentLanguage();
    $translations = loadTranslations($lang);
    $text = $translations[$key] ?? (loadTranslations(DEFAULT_LANGUAGE)[$key] ?? $key);

    foreach ($vars as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
}

function currentTextDirection(): string
{
    return t('app.direction');
}
