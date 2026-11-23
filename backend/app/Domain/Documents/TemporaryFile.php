<?php

namespace App\Domain\Documents;

use RuntimeException;

/** Paths for generated files in the system temporary directory. */
final class TemporaryFile
{
    /**
     * Create an empty, uniquely named temporary file and return its path.
     *
     * @throws RuntimeException
     */
    public static function create(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        return $path;
    }
}
