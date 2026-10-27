<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Reporting;

use App\Configuration\SystemConfiguration;

/**
 * The plugin's one date and time format, chosen in Kimai's settings (System → Settings → Weekly targets): every date
 * and time of the report and of the ntfy summary follows it. Durations are always HH:MM:SS, as everywhere in Kimai.
 */
final class DateTimeFormat
{
    public const SETTING = 'weekly_targets.datetime_format';
    public const DEFAULT = 'iso8601';
    /** name => [date, date and time] as PHP date() formats */
    public const FORMATS = [
        'iso8601' => ['Y-m-d', 'Y-m-d H:i'],
        'dmy' => ['d/m/Y', 'd/m/Y H:i'],
        'dmy_dot' => ['d.m.Y', 'd.m.Y H:i'],
        'mdy' => ['m/d/Y', 'm/d/Y g:i A'],
    ];

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function date(\DateTimeInterface $date): string
    {
        return $date->format($this->formats()[0]);
    }

    public function dateTime(\DateTimeInterface $date): string
    {
        return $date->format($this->formats()[1]);
    }

    /** @return array{string, string} the chosen format, the default one while none is saved */
    private function formats(): array
    {
        return self::FORMATS[(string) $this->configuration->find(self::SETTING)] ?? self::FORMATS[self::DEFAULT];
    }
}
