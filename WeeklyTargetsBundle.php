<?php

namespace KimaiPlugin\WeeklyTargetsBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * "Weekly target" fields on projects and customers and a report comparing them with the time tracked in a week (see
 * README.md).
 */
class WeeklyTargetsBundle extends Bundle implements PluginInterface
{
    /** Name of the project / customer meta field holding the weekly target, in seconds */
    public const META_NAME = 'weekly_target';
    /** Name of the meta field holding the kind of target: TYPE_AT_LEAST (default) or TYPE_AT_MOST */
    public const META_TYPE = 'weekly_target_type';

    /** A goal to reach: fine when the tracked time is at least the target */
    public const TYPE_AT_LEAST = 'at_least';
    /** A limit: fine while the tracked time is at most the target */
    public const TYPE_AT_MOST = 'at_most';
}
