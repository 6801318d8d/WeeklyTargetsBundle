<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Reporting;

use App\Entity\Customer;
use App\Entity\CustomerMeta;
use App\Entity\Project;
use App\Entity\ProjectMeta;
use App\Entity\Timesheet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\WeeklyTargetsBundle\WeeklyTargetsBundle;

/**
 * Weekly targets vs. tracked time of one user in one week.
 *
 * A target is "at least" (a goal: fine once reached) or "at most" (a limit: fine until exceeded). It can be set on a
 * project and on a customer; targets of hidden projects or customers are ignored.
 * - A project target compares with the time tracked on that project.
 * - A customer target compares with all the time tracked on the customer's projects, those without a target too.
 *   A customer without its own target gets a subtotal of its project targets when they are all of the same kind.
 * - The total adds up the "at least" targets only (the planned time): the customer target where there is one,
 *   otherwise its projects' "at least" targets. Limits are not added up.
 * Time on projects without a target, of customers without a target, is listed apart. Tracked time counts every
 * timesheet that starts within the week; a running timer counts up to now.
 */
final class WeeklyTargetsCalculator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * groups: list of {customer, line (the customer row), own_target (bool), rows: list of {project, line}};
     * total: line of the "at least" targets or null; untargeted: list of {project, actual}; untargeted_actual;
     * by_customer: list of {customer, actual}, all the time tracked in the period by customer, targets or not,
     * longest first; tracked_total. A line is {target, type, actual, diff, percent, ok}, target/type/diff/percent/ok
     * null without a target.
     *
     * @return array<string, mixed>
     */
    public function calculate(User $user, \DateTimeInterface $begin, \DateTimeInterface $end): array
    {
        $projectTargets = $this->getTargets(ProjectMeta::class, 'project');
        $customerTargets = $this->getTargets(CustomerMeta::class, 'customer');
        $actual = $this->getTrackedSeconds($user, $begin, $end);

        $ids = array_unique(array_merge(array_keys($projectTargets), array_keys($actual)));
        /** @var Project[] $projects */
        $projects = $ids === [] ? [] : $this->entityManager->getRepository(Project::class)->findBy(['id' => $ids]);
        /** @var Customer[] $targetCustomers */
        $targetCustomers = $customerTargets === [] ? [] : $this->entityManager->getRepository(Customer::class)->findBy(['id' => array_keys($customerTargets)]);

        // one bucket per customer: its own target and its projects with a target or with tracked time
        $buckets = [];
        foreach ($targetCustomers as $customer) {
            if ($customer->isVisible()) {
                $buckets[$customer->getId()] = ['customer' => $customer, 'target' => $customerTargets[$customer->getId()], 'projects' => []];
            }
        }
        foreach ($projects as $project) {
            $customer = $project->getCustomer();
            $key = $customer?->getId() ?? 0;
            $buckets[$key] ??= ['customer' => $customer, 'target' => null, 'projects' => []];
            $visible = $project->isVisible() && $customer?->isVisible();
            $buckets[$key]['projects'][] = [
                'project' => $project,
                'target' => $visible ? ($projectTargets[$project->getId()] ?? null) : null,
                'actual' => $actual[$project->getId()] ?? 0,
            ];
        }

        $groups = [];
        $untargeted = [];
        $totalTarget = 0;
        $totalActual = 0;
        foreach ($buckets as $bucket) {
            $own = $bucket['target'];
            $rows = [];
            $allActual = 0;
            foreach ($bucket['projects'] as $p) {
                $allActual += $p['actual'];
                if ($p['target'] !== null) {
                    $rows[] = ['project' => $p['project'], 'line' => $this->line($p['target']['seconds'], $p['target']['type'], $p['actual'])];
                } elseif ($p['actual'] > 0) {
                    if ($own !== null) {
                        // no target of its own, but its time counts for the customer's target
                        $rows[] = ['project' => $p['project'], 'line' => $this->line(null, null, $p['actual'])];
                    } else {
                        $untargeted[] = ['project' => $p['project'], 'actual' => $p['actual']];
                    }
                }
            }
            if ($own === null && $rows === []) {
                continue;
            }

            $targeted = array_filter($rows, static fn (array $r): bool => $r['line']['target'] !== null);
            if ($own !== null) {
                $line = $this->line($own['seconds'], $own['type'], $allActual);
                if ($own['type'] === WeeklyTargetsBundle::TYPE_AT_LEAST) {
                    $totalTarget += $own['seconds'];
                    $totalActual += $allActual;
                }
            } else {
                $types = array_unique(array_map(static fn (array $r): string => $r['line']['type'], $targeted));
                $sumTarget = array_sum(array_map(static fn (array $r): int => $r['line']['target'], $targeted));
                $sumActual = array_sum(array_map(static fn (array $r): int => $r['line']['actual'], $targeted));
                $line = \count($types) === 1 ? $this->line($sumTarget, reset($types), $sumActual) : $this->line(null, null, $sumActual);
                foreach ($targeted as $r) {
                    if ($r['line']['type'] === WeeklyTargetsBundle::TYPE_AT_LEAST) {
                        $totalTarget += $r['line']['target'];
                        $totalActual += $r['line']['actual'];
                    }
                }
            }
            $groups[] = ['customer' => $bucket['customer'], 'line' => $line, 'own_target' => $own !== null, 'rows' => $rows];
        }

        $collator = class_exists(\Collator::class) ? new \Collator('') : null;
        $byName = static fn (?string $a, ?string $b): int => $collator ? $collator->compare($a ?? '', $b ?? '') : strcasecmp($a ?? '', $b ?? '');
        $byProject = static fn (array $a, array $b): int => $byName($a['project']->getName(), $b['project']->getName());
        usort($groups, static fn (array $a, array $b): int => $byName($a['customer']?->getName(), $b['customer']?->getName()));
        foreach ($groups as &$group) {
            // projects with a target first, then those counted only for the customer's target
            usort($group['rows'], static fn (array $a, array $b): int => (($a['line']['target'] === null) <=> ($b['line']['target'] === null)) ?: $byProject($a, $b));
        }
        unset($group);
        usort($untargeted, static fn (array $a, array $b): int => $byName($a['project']->getCustomer()?->getName(), $b['project']->getCustomer()?->getName()) ?: $byProject($a, $b));

        $untargetedActual = array_sum(array_column($untargeted, 'actual'));

        // every customer worked on, hidden ones too (the time was tracked all the same)
        $byCustomer = [];
        foreach ($projects as $project) {
            if (($seconds = $actual[$project->getId()] ?? 0) > 0) {
                $customer = $project->getCustomer();
                $byCustomer[$customer?->getId() ?? 0] ??= ['customer' => $customer, 'actual' => 0];
                $byCustomer[$customer?->getId() ?? 0]['actual'] += $seconds;
            }
        }
        $byCustomer = array_values($byCustomer);
        usort($byCustomer, static fn (array $a, array $b): int => ($b['actual'] <=> $a['actual']) ?: $byName($a['customer']?->getName(), $b['customer']?->getName()));

        return [
            'groups' => $groups,
            'total' => $totalTarget > 0 ? $this->line($totalTarget, WeeklyTargetsBundle::TYPE_AT_LEAST, $totalActual) : null,
            'untargeted' => $untargeted,
            'untargeted_actual' => $untargetedActual,
            'by_customer' => $byCustomer,
            'tracked_total' => array_sum($actual),
        ];
    }

    /**
     * @return array{target: int|null, type: string|null, actual: int, diff: int|null, percent: float|null, ok: bool|null}
     */
    private function line(?int $target, ?string $type, int $actual): array
    {
        if ($target === null || $target <= 0) {
            return ['target' => null, 'type' => null, 'actual' => $actual, 'diff' => null, 'percent' => null, 'ok' => null];
        }

        return [
            'target' => $target,
            'type' => $type,
            'actual' => $actual,
            'diff' => $actual - $target,
            'percent' => $actual / $target * 100,
            'ok' => $type === WeeklyTargetsBundle::TYPE_AT_MOST ? $actual <= $target : $actual >= $target,
        ];
    }

    /**
     * Weekly target per entity id (only targets above zero), from the meta fields of $metaClass.
     *
     * @param class-string $metaClass
     * @return array<int, array{seconds: int, type: string}>
     */
    private function getTargets(string $metaClass, string $relation): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select("IDENTITY(m.$relation) AS id", 'm.name AS name', 'm.value AS value')
            ->from($metaClass, 'm')
            ->where('m.name IN (:names)')
            ->setParameter('names', [WeeklyTargetsBundle::META_NAME, WeeklyTargetsBundle::META_TYPE])
            ->getQuery()
            ->getArrayResult();

        $seconds = [];
        $types = [];
        foreach ($rows as $row) {
            if ($row['name'] === WeeklyTargetsBundle::META_NAME) {
                $seconds[(int) $row['id']] = (int) $row['value'];
            } else {
                $types[(int) $row['id']] = $row['value'];
            }
        }

        $targets = [];
        foreach ($seconds as $id => $value) {
            if ($value > 0) {
                $type = ($types[$id] ?? null) === WeeklyTargetsBundle::TYPE_AT_MOST ? WeeklyTargetsBundle::TYPE_AT_MOST : WeeklyTargetsBundle::TYPE_AT_LEAST;
                $targets[$id] = ['seconds' => $value, 'type' => $type];
            }
        }

        return $targets;
    }

    /**
     * Seconds tracked per project id by the user in timesheets starting between $begin and $end.
     *
     * @return array<int, int>
     */
    private function getTrackedSeconds(User $user, \DateTimeInterface $begin, \DateTimeInterface $end): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $rows = $qb
            ->select('IDENTITY(t.project) AS project', 'SUM(t.duration) AS duration')
            ->from(Timesheet::class, 't')
            ->where('t.user = :user')
            ->andWhere($qb->expr()->between('t.begin', ':begin', ':end'))
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->groupBy('t.project')
            ->setParameter('user', $user)
            ->setParameter('begin', $begin)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();

        $seconds = [];
        foreach ($rows as $row) {
            $seconds[(int) $row['project']] = (int) $row['duration'];
        }

        // A running timer has no duration yet: count it up to now (or to the end of the week)
        $qb = $this->entityManager->createQueryBuilder();
        $running = $qb
            ->select('IDENTITY(t.project) AS project', 't.begin AS begin')
            ->from(Timesheet::class, 't')
            ->where('t.user = :user')
            ->andWhere($qb->expr()->between('t.begin', ':begin', ':end'))
            ->andWhere($qb->expr()->isNull('t.end'))
            ->setParameter('user', $user)
            ->setParameter('begin', $begin)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();

        $now = min(time(), $end->getTimestamp());
        foreach ($running as $row) {
            $project = (int) $row['project'];
            $seconds[$project] = ($seconds[$project] ?? 0) + max(0, $now - $row['begin']->getTimestamp());
        }

        return $seconds;
    }
}
