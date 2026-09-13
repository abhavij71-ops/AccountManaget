<?php
declare(strict_types=1);

const SUPPORTED_LANGUAGES = ['fa' => 'فارسی', 'en' => 'English'];
const DEFAULT_LANGUAGE = 'fa';

function currentLanguage(): string
{
    $lang = (string) ($_SESSION['lang'] ?? DEFAULT_LANGUAGE);
    return array_key_exists($lang, SUPPORTED_LANGUAGES) ? $lang : DEFAULT_LANGUAGE;
}

function setLanguage(string $lang): void
{
    if (array_key_exists($lang, SUPPORTED_LANGUAGES)) {
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
