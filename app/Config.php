<?php

declare(strict_types=1);

final class Config
{
    private static $items = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException('Config file missing. Please run installer.');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('Invalid config file.');
        }
        self::$items = $config;
    }

    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
