<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\WeeklyTargetsBundle\Form\WeeklyTargetsForm;
use KimaiPlugin\WeeklyTargetsBundle\Reporting\WeeklyTargetsCalculator;
use KimaiPlugin\WeeklyTargetsBundle\Reporting\WeeklyTargetsQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/reporting/weekly_targets')]
#[IsGranted('view_reporting')]
#[IsGranted('report:project')]
final class WeeklyTargetsController extends AbstractController
{
    #[Route(path: '', name: 'weekly_targets_report', methods: ['GET'])]
    public function report(Request $request, WeeklyTargetsCalculator $calculator): Response
    {
        $user = $this->getUser();
        $dateTimeFactory = $this->getDateTimeFactory($user);

        $values = new WeeklyTargetsQuery();
        $values->setDate($dateTimeFactory->getStartOfWeek());

        $form = $this->createFormForGetRequest(WeeklyTargetsForm::class, $values, [
            'timezone' => $dateTimeFactory->getTimezone()->getName(),
            'start_date' => $values->getDate(),
        ]);
        $form->submit($request->query->all(), false);

        // an invalid date in the URL shows the current week
        $date = $values->getDate() ?? $dateTimeFactory->getStartOfWeek();
        $begin = $dateTimeFactory->getStartOfWeek($date);
        $end = $dateTimeFactory->getEndOfWeek($date);

        return $this->render('@WeeklyTargets/report.html.twig', [
            'report_title' => 'weekly_targets.title',
            'form' => $form->createView(),
            'begin' => $begin,
            'end' => $end,
            'report' => $calculator->calculate($user, $begin, $end),
        ]);
    }
}
