<?php

namespace KimaiPlugin\WeeklyTargetsBundle\Command;

use App\Repository\UserRepository;
use App\Timesheet\DateTimeFactory;
use KimaiPlugin\WeeklyTargetsBundle\Reporting\WeeklyTargetsCalculator;
use KimaiPlugin\WeeklyTargetsBundle\WeeklyTargetsBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pushes the weekly targets of one user and one week (by default the previous one) to ntfy: a line per target, the
 * planned ("at least") total, the time without a target and the week's total. Meant to run once a week from cron.
 *
 * Settings come from the environment: WEEKLY_TARGETS_NTFY_URL (e.g. https://ntfy.example.com, required),
 * WEEKLY_TARGETS_NTFY_TOPIC (default "kimai-weekly-targets"), WEEKLY_TARGETS_NTFY_TOKEN (access token, optional) and
 * WEEKLY_TARGETS_KIMAI_URL (Kimai's public address, optional: tapping the notification opens the week's report).
 */
#[AsCommand(name: 'weekly-targets:notify', description: 'Push the weekly targets of a week (default: the previous one) to ntfy')]
final class NotifyCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WeeklyTargetsCalculator $calculator,
        private readonly HttpClientInterface $client,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Username whose tracked time is compared with the targets')
            ->addOption('week', null, InputOption::VALUE_REQUIRED, 'Any day of the week to report, YYYY-MM-DD (default: the previous week)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the notification instead of sending it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getOption('user');
        $user = $username === '' ? null : $this->users->findByUsername($username);
        if ($user === null) {
            $output->writeln('<error>Unknown or missing --user</error>');

            return Command::INVALID;
        }

        $dateTimeFactory = DateTimeFactory::createByUser($user);
        if (null !== ($week = $input->getOption('week'))) {
            $date = \DateTime::createFromFormat('!Y-m-d', (string) $week, $dateTimeFactory->getTimezone());
            if ($date === false) {
                $output->writeln('<error>--week must be a date as YYYY-MM-DD</error>');

                return Command::INVALID;
            }
        } else {
            $date = $dateTimeFactory->getStartOfWeek()->modify('-1 week');
        }
        $begin = $dateTimeFactory->getStartOfWeek($date);
        $end = $dateTimeFactory->getEndOfWeek($date);
        $report = $this->calculator->calculate($user, $begin, $end);

        $locale = $user->getLanguage();
        $t = fn (string $id, array $parameters = []): string => $this->translator->trans($id, $parameters, 'messages', $locale);

        $lines = [];
        $count = 0;
        $met = 0;
        foreach ($report['groups'] as $group) {
            $targets = [];
            if ($group['own_target']) {
                $targets[] = [$group['customer']?->getName() ?? '', $group['line']];
            }
            foreach ($group['rows'] as $row) {
                if ($row['line']['target'] !== null) {
                    $targets[] = [$row['project']->getName(), $row['line']];
                }
            }
            foreach ($targets as [$name, $line]) {
                $count++;
                $met += $line['ok'] ? 1 : 0;
                $lines[] = $t('weekly_targets.notify_line', [
                    '%name%' => $name,
                    '%status%' => $line['ok'] ? '✅' : '❌',
                    '%tracked%' => $this->duration($line['actual']),
                    '%target%' => ($line['type'] === WeeklyTargetsBundle::TYPE_AT_MOST ? '≤ ' : '≥ ') . $this->duration($line['target']),
                    '%diff%' => $this->duration($line['diff'], true),
                ]);
            }
        }
        if ($count === 0) {
            $output->writeln('No weekly targets: nothing to send.');

            return Command::SUCCESS;
        }

        $message = [$t('weekly_targets.notify_week', ['%begin%' => $begin->format('Y-m-d'), '%end%' => $end->format('Y-m-d')])];
        array_push($message, ...$lines);
        $message[] = '';
        if ($report['total'] !== null) {
            $message[] = $t('weekly_targets.notify_planned', [
                '%tracked%' => $this->duration($report['total']['actual']),
                '%target%' => $this->duration($report['total']['target']),
                '%percent%' => (string) round($report['total']['percent']),
            ]);
        }
        if ($report['untargeted_actual'] > 0) {
            $message[] = $t('weekly_targets.notify_untargeted', ['%tracked%' => $this->duration($report['untargeted_actual'])]);
        }
        $message[] = $t('weekly_targets.notify_total', ['%tracked%' => $this->duration($report['tracked_total'])]);
        // the local date and time at the bottom: the ntfy Android app shows dates only in the phone's locale format
        $message[] = '';
        $message[] = '🕒 ' . $dateTimeFactory->createDateTime()->format('Y-m-d H:i');

        $env = static fn (string $name): string => (string) ($_SERVER[$name] ?? getenv($name) ?: '');
        $payload = [
            'topic' => $env('WEEKLY_TARGETS_NTFY_TOPIC') ?: 'kimai-weekly-targets',
            // not "%count%": with that parameter Symfony's translator treats "Kimai:" as a plural label and drops it
            'title' => $t('weekly_targets.notify_title', ['%met%' => (string) $met, '%targets%' => (string) $count]),
            'message' => implode("\n", $message),
            'tags' => [$met === $count ? 'white_check_mark' : 'warning'],
            'priority' => 3,
        ];
        if (($kimai = rtrim($env('WEEKLY_TARGETS_KIMAI_URL'), '/')) !== '') {
            $payload['click'] = $kimai . '/' . rawurlencode($locale) . '/reporting/weekly_targets?date=' . $begin->format('Y-m-d');
        }

        if ($input->getOption('dry-run')) {
            $output->writeln(json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $url = rtrim($env('WEEKLY_TARGETS_NTFY_URL'), '/');
        if ($url === '') {
            $output->writeln('<error>WEEKLY_TARGETS_NTFY_URL is not set</error>');

            return Command::FAILURE;
        }
        $headers = ['Content-Type' => 'application/json'];
        if (($token = $env('WEEKLY_TARGETS_NTFY_TOKEN')) !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        try {
            // JSON publishing (POST to the server root): title and message can hold any UTF-8 text
            $response = $this->client->request('POST', $url, ['headers' => $headers, 'json' => $payload, 'timeout' => 15]);
            $status = $response->getStatusCode();
            if ($status >= 300) {
                $output->writeln(\sprintf('<error>ntfy answered %d: %s</error>', $status, $response->getContent(false)));

                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>ntfy notification failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('Sent "%s" to topic %s (%d of %d targets met).', $payload['title'], $payload['topic'], $met, $count));

        return Command::SUCCESS;
    }

    /** HH:MM:SS, with a leading + or − when $signed */
    private function duration(?int $seconds, bool $signed = false): string
    {
        $seconds ??= 0;
        $sign = $signed ? ($seconds < 0 ? '−' : '+') : ($seconds < 0 ? '−' : '');
        $seconds = abs($seconds);

        return \sprintf('%s%02d:%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }
}
