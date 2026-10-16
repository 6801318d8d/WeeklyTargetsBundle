<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Reporting;

/**
 * Form data of the report: any day of the week to show.
 */
final class WeeklyTargetsQuery
{
    private ?\DateTime $date = null;

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(?\DateTime $date): void
    {
        $this->date = $date;
    }
}
