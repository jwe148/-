<?php
declare(strict_types=1);

function offeringTimes(array $offering): array
{
    return $offering['times'] ?? (
        isset($offering['day'], $offering['time'])
            ? [['day' => $offering['day'], 'time' => $offering['time']]]
            : []
    );
}

function offeringTimeLabel(array $offering): string
{
    return implode('、', array_map(
        static fn(array $time): string => $time['day'] . ' ' . $time['time'],
        offeringTimes($offering)
    ));
}

function offeringHasDay(array $offering, string $day): bool
{
    foreach (offeringTimes($offering) as $time) {
        if ($time['day'] === $day) {
            return true;
        }
    }
    return false;
}

function offeringTimeConflict(array $first, array $second): bool
{
    foreach (offeringTimes($first) as $firstTime) {
        foreach (offeringTimes($second) as $secondTime) {
            if ($firstTime['day'] !== $secondTime['day']) {
                continue;
            }
            [$firstStart, $firstEnd] = explode('—', $firstTime['time']);
            [$secondStart, $secondEnd] = explode('—', $secondTime['time']);
            if ($firstStart < $secondEnd && $secondStart < $firstEnd) {
                return true;
            }
        }
    }
    return false;
}

function offeringPrerequisites(array $offering): array
{
    if (isset($offering['prerequisites'])) {
        return $offering['prerequisites'];
    }
    return ($offering['prerequisite'] ?? '无') === '无' ? [] : [$offering['prerequisite']];
}
