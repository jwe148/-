<?php
declare(strict_types=1);

function csvSafeCell(string $value): string
{
    return preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

function writeCsvRow($stream, array $values): void
{
    $safe = array_map(static fn($value): string => csvSafeCell((string) $value), $values);
    fputcsv($stream, $safe, ',', '"', '\\');
}
