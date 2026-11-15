<?php
declare(strict_types=1);

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Catalog.php';

function config(): array
{
    $defaults = require dirname(__DIR__) . '/config.example.php';
    $local = dirname(__DIR__) . '/config.local.php';
    return is_file($local) ? array_replace($defaults, require $local) : $defaults;
}

function pretty(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
