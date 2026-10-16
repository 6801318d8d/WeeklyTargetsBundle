<?php

namespace KimaiPlugin\WeeklyTargetsBundle\EventSubscriber;

use App\Entity\CustomerMeta;
use App\Entity\MetaTableTypeInterface;
use App\Entity\ProjectMeta;
use App\Event\CustomerMetaDefinitionEvent;
use App\Event\CustomerMetaDisplayEvent;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\ProjectMetaDisplayEvent;
use App\Event\ReportingEvent;
use App\Form\Type\DurationType;
use App\Reporting\Report;
use KimaiPlugin\WeeklyTargetsBundle\WeeklyTargetsBundle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds the "Weekly target" and "Kind of target" fields to the project and customer forms (the target also as a column
 * in their lists and exports), and the "Weekly targets" report to the reporting menu.
 */
final class WeeklyTargetsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProjectMetaDefinitionEvent::class => ['onProjectMetaDefinition', 200],
            ProjectMetaDisplayEvent::class => ['onProjectMetaDisplay', 200],
            CustomerMetaDefinitionEvent::class => ['onCustomerMetaDefinition', 200],
            CustomerMetaDisplayEvent::class => ['onCustomerMetaDisplay', 200],
            ReportingEvent::class => ['onReporting', 100],
        ];
    }

    public function onProjectMetaDefinition(ProjectMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->targetField(new ProjectMeta(), true));
        $event->getEntity()->setMetaField($this->typeField(new ProjectMeta()));
    }

    public function onProjectMetaDisplay(ProjectMetaDisplayEvent $event): void
    {
        $event->addField($this->targetField(new ProjectMeta(), false));
    }

    public function onCustomerMetaDefinition(CustomerMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->targetField(new CustomerMeta(), true));
        $event->getEntity()->setMetaField($this->typeField(new CustomerMeta()));
    }

    public function onCustomerMetaDisplay(CustomerMetaDisplayEvent $event): void
    {
        $event->addField($this->targetField(new CustomerMeta(), false));
    }

    public function onReporting(ReportingEvent $event): void
    {
        if ($this->security->isGranted('report:project')) {
            $event->addReport(new Report('weekly_targets', 'weekly_targets_report', 'weekly_targets.title', 'project'));
        }
    }

    private function targetField(MetaTableTypeInterface $meta, bool $withHelp): MetaTableTypeInterface
    {
        $meta->setName(WeeklyTargetsBundle::META_NAME)
            ->setLabel('weekly_targets.field')
            ->setType(DurationType::class)
            ->setIsRequired(false)
            ->setIsVisible(true)
            ->setOrder(100);
        if ($withHelp) {
            $meta->setOptions(['help' => 'weekly_targets.field_help']);
        }

        return $meta;
    }

    private function typeField(MetaTableTypeInterface $meta): MetaTableTypeInterface
    {
        return $meta->setName(WeeklyTargetsBundle::META_TYPE)
            ->setLabel('weekly_targets.type')
            ->setType(ChoiceType::class)
            ->setIsRequired(false)
            ->setIsVisible(true)
            ->setOrder(101)
            ->setOptions([
                'choices' => [
                    'weekly_targets.type_at_least' => WeeklyTargetsBundle::TYPE_AT_LEAST,
                    'weekly_targets.type_at_most' => WeeklyTargetsBundle::TYPE_AT_MOST,
                ],
                // no empty choice: a target without a kind is "at least"
                'placeholder' => false,
                'empty_data' => WeeklyTargetsBundle::TYPE_AT_LEAST,
                'search' => false,
                'help' => 'weekly_targets.type_help',
            ]);
    }
}
