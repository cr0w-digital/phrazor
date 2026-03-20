<?php

if (!function_exists('t')) {
    function t(string $pattern, array $vars = [], ?string $locale = null): string
    {
        return \phrazor\t($pattern, $vars, $locale);
    }
} else {
    trigger_error(
        'phrazor: Could not define global t() — function already exists. Use \phrazor\t() instead.',
        E_USER_NOTICE,
    );
}
