<?php

declare(strict_types=1);

namespace Crab\Arc;

/**
 * A template that cannot be found or compiled. The message names the file,
 * and the line when there is one.
 */
final class ArcException extends \RuntimeException
{
    public static function at(string $file, int $line, string $message): self
    {
        return new self("{$message} in {$file} on line {$line}.");
    }

    public static function runtime(string $file, int $line, \Throwable $previous): self
    {
        if ($previous instanceof self) {
            return $previous;
        }
        return new self("{$previous->getMessage()} in {$file} on line {$line}.", 0, $previous);
    }
}
