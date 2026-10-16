<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Form;

use App\Form\Type\WeekPickerType;
use KimaiPlugin\WeeklyTargetsBundle\Reporting\WeeklyTargetsQuery;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The week picker of the report (with previous / next week), like Kimai's weekly user report.
 *
 * @extends AbstractType<WeeklyTargetsQuery>
 */
final class WeeklyTargetsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('date', WeekPickerType::class, [
            'model_timezone' => $options['timezone'],
            'view_timezone' => $options['timezone'],
            'start_date' => $options['start_date'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WeeklyTargetsQuery::class,
            'timezone' => date_default_timezone_get(),
            'start_date' => new \DateTime(),
            'csrf_protection' => false,
            'method' => 'GET',
        ]);
    }
}
