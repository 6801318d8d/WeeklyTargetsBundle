<?php

namespace KimaiPlugin\WeeklyTargetsBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use KimaiPlugin\WeeklyTargetsBundle\Reporting\DateTimeFormat;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Adds a "Weekly targets" section to Kimai's settings (System → Settings) with the plugin's date and time format.
 */
final class SettingsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [SystemConfigurationEvent::class => ['onSystemConfiguration', 100]];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        // every choice shown as an example date and time (a day above 12, so day and month can't be mixed up)
        $example = new \DateTimeImmutable('2026-12-31 18:30');
        $choices = [];
        foreach (DateTimeFormat::FORMATS as $name => [, $dateTime]) {
            $choices[$example->format($dateTime)] = $name;
        }
        $event->addConfiguration(
            (new SystemConfiguration('weekly_targets'))
                ->setTranslation('weekly_targets.title')
                ->setTranslationDomain('messages')
                ->setConfiguration([
                    (new Configuration(DateTimeFormat::SETTING))
                        ->setLabel('weekly_targets.datetime_format')
                        ->setTranslationDomain('messages')
                        ->setType(ChoiceType::class)
                        ->setValue(DateTimeFormat::DEFAULT)
                        ->setRequired(true)
                        ->setOptions([
                            'choices' => $choices,
                            'choice_translation_domain' => false,
                            'help' => 'weekly_targets.datetime_format_help',
                        ]),
                ])
        );
    }
}
