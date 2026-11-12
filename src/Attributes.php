<?php

declare(strict_types=1);

namespace Crab\Arc;

/**
 * The HTML attributes a component did not declare as props, written with
 * @attributes($attributes, ['class' => 'btn']).
 *
 * The caller's values override the component's defaults, except class, which is
 * joined: the defaults' classes first. A class can be a string or a list where
 * a string key is kept only when its value is truthy: ['btn', 'active' => $on].
 * True writes a bare attribute, false and null leave it out, and every value is
 * escaped for a quoted attribute.
 */
final class Attributes
{
    /**
     * The attributes as HTML, separated by spaces, without a leading space.
     *
     * @param array<string, mixed> $attributes The caller's.
     * @param array<string, mixed> $defaults The component's.
     */
    public static function render(array $attributes, array $defaults = []): string
    {
        $html = [];
        foreach (self::merge($defaults, $attributes) as $name => $value) {
            if (!is_string($name) || preg_match('/^[^\s"\'<>\/=\x00-\x1F\x7F]+$/', $name) !== 1) {
                throw new ArcException('"' . $name . '" is not an HTML attribute name.');
            }
            if ($name === 'class') {
                $value = self::classes($value);
                if ($value === '') {
                    continue;
                }
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $html[] = $name;
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new ArcException(
                    "The attribute {$name} needs a string, number or boolean, not " . get_debug_type($value) . '.',
                );
            }
            $html[] = $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE) . '"';
        }
        return implode(' ', $html);
    }

    /**
     * $attributes over $defaults, with class joined. Compiled templates call it to
     * forward one component's attributes to another.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function merge(array $defaults, array $attributes): array
    {
        foreach ($attributes as $name => $value) {
            if ($name === 'class' && array_key_exists('class', $defaults)) {
                $defaults['class'] = trim(self::classes($defaults['class']) . ' ' . self::classes($value));
                continue;
            }
            $defaults[$name] = $value;
        }
        return $defaults;
    }

    private static function classes(mixed $value): string
    {
        if ($value === null || $value === false || $value === true) {
            return '';
        }
        if (!is_array($value)) {
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new ArcException(
                    'The attribute class needs a string or a list, not ' . get_debug_type($value) . '.',
                );
            }
            return trim((string) $value);
        }
        $classes = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                if ($item) {
                    $classes[] = $key;
                }
                continue;
            }
            $class = self::classes($item);
            if ($class !== '') {
                $classes[] = $class;
            }
        }
        return implode(' ', $classes);
    }
}
