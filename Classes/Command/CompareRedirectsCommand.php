<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand('better-redirects:compare', 'Compare redirect behaviour between two sites by sampling sys_redirect rows')]
final class CompareRedirectsCommand extends Command
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Base URL of the site without the extension (e.g. http://site-a.local)')
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Base URL of the site with the extension (e.g. http://site-b.local)')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Number of redirects to test', '1000')
            ->addOption('source-host', null, InputOption::VALUE_REQUIRED, 'Filter sys_redirect.source_host to this value (default: all hosts)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds', '10')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Integer seed for reproducible random sampling')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Number of concurrent HTTP request pairs', '10')
            ->addOption('include-regex', null, InputOption::VALUE_NONE, 'Also test regexp-based redirects (skipped by default)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baselineUrl = rtrim((string)$input->getOption('baseline'), '/');
        $testUrl = rtrim((string)$input->getOption('test'), '/');

        if ($baselineUrl === '') {
            $output->writeln('<error>--baseline is required</error>');
            return Command::FAILURE;
        }
        if ($testUrl === '') {
            $output->writeln('<error>--test is required</error>');
            return Command::FAILURE;
        }

        $sampleSize = (int)$input->getOption('sample');
        $seed = $input->getOption('seed') !== null ? (int)$input->getOption('seed') : null;
        $includeRegex = (bool)$input->getOption('include-regex');
        $sourceHostFilter = $input->getOption('source-host');

        $output->writeln('Sampling redirects from database…');
        $redirects = $this->sampleRedirects($sampleSize, $seed, $includeRegex, $sourceHostFilter);

        $total = count($redirects);
        if ($total === 0) {
            $output->writeln('<comment>No matching redirects found.</comment>');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('Testing %d redirect(s)…', $total));

        $timeout = (int)$input->getOption('timeout');
        $concurrency = max(1, (int)$input->getOption('concurrency'));

        $client = new Client([
            'allow_redirects' => false,
            'timeout' => $timeout,
            'http_errors' => false,
        ]);

        $progressBar = new ProgressBar($output, $total);
        $progressBar->start();

        $differences = $this->runComparisons(
            $redirects,
            $baselineUrl,
            $testUrl,
            $client,
            $concurrency,
            $progressBar,
        );

        $progressBar->finish();
        $output->writeln('');

        if ($differences !== []) {
            $output->writeln(sprintf('<error>%d difference(s) found:</error>', count($differences)));
            $this->renderDifferencesTable($output, $differences);
        } else {
            $output->writeln(sprintf('<info>%d/%d tested — no differences found.</info>', $total, $total));
        }

        return $differences !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<array{uid: int, source_host: string, source_path: string}>
     */
    private function sampleRedirects(
        int $sampleSize,
        ?int $seed,
        bool $includeRegex,
        ?string $sourceHostFilter,
    ): array {
        $now = time();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');

        $queryBuilder
            ->select('uid', 'source_host', 'source_path')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->eq('disabled', 0),
                $queryBuilder->expr()->eq('respect_query_parameters', 0),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('starttime', 0),
                    $queryBuilder->expr()->lte('starttime', $queryBuilder->createNamedParameter($now)),
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', 0),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now)),
                ),
            );

        if (!$includeRegex) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('is_regexp', 0));
        }
        if ($sourceHostFilter !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($sourceHostFilter)),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        if ($seed !== null) {
            mt_srand($seed);
        }

        shuffle($rows);

        return array_map(
            static fn (array $row): array => [
                'uid' => (int)$row['uid'],
                'source_host' => (string)$row['source_host'],
                'source_path' => (string)$row['source_path'],
            ],
            array_slice($rows, 0, $sampleSize),
        );
    }

    /**
     * @param list<array{uid: int, source_host: string, source_path: string}> $redirects
     * @return list<array{uid: int, source_path: string, baseline_status: int, test_status: int, baseline_error: string|null, test_error: string|null, baseline_location: string, test_location: string}>
     */
    private function runComparisons(
        array $redirects,
        string $baselineUrl,
        string $testUrl,
        Client $client,
        int $concurrency,
        ProgressBar $progressBar,
    ): array {
        $differences = [];

        // Build one Guzzle async request per redirect per site.
        // We send them in pairs to keep baseline/test results aligned.
        $chunkSize = max(1, $concurrency);
        $chunks = array_chunk($redirects, $chunkSize);

        foreach ($chunks as $chunk) {
            $chunkResults = [];

            $requests = static function () use ($chunk, $baselineUrl, $testUrl): \Generator {
                foreach ($chunk as $i => $redirect) {
                    $path = $redirect['source_path'];
                    yield "{$i}_baseline" => new Request('GET', $baselineUrl . $path);
                    yield "{$i}_test" => new Request('GET', $testUrl . $path);
                }
            };

            $pool = new Pool($client, $requests(), [
                'concurrency' => $concurrency * 2,
                'fulfilled' => static function (ResponseInterface $response, string $key) use (&$chunkResults): void {
                    [$i, $site] = explode('_', $key, 2);
                    $chunkResults[(int)$i][$site] = [
                        'status' => $response->getStatusCode(),
                        'location' => $response->getHeaderLine('Location'),
                    ];
                },
                'rejected' => static function (GuzzleException $reason, string $key) use (&$chunkResults): void {
                    [$i, $site] = explode('_', $key, 2);
                    $chunkResults[(int)$i][$site] = [
                        'status' => 0,
                        'error' => get_class($reason),
                        'reason' => $reason->getMessage(),
                        'location' => '',
                    ];
                },
            ]);

            $pool->promise()->wait();

            foreach ($chunk as $i => $redirect) {
                $progressBar->advance();

                $baseline = $chunkResults[$i]['baseline'] ?? ['status' => 0, 'location' => '', 'error' => 'RequestNotSent', 'reason' => 'No response received'];
                $test = $chunkResults[$i]['test'] ?? ['status' => 0, 'location' => '', 'error' => 'RequestNotSent', 'reason' => 'No response received'];

                $baselineLocation = $this->normalizeLocation((string)$baseline['location'], $baselineUrl);
                $testLocation = $this->normalizeLocation((string)$test['location'], $testUrl);

                if ($baseline['status'] !== $test['status'] || $baselineLocation !== $testLocation) {
                    $differences[] = [
                        'uid' => $redirect['uid'],
                        'source_path' => $redirect['source_path'],
                        'baseline_status' => $baseline['status'],
                        'baseline_error' => $baseline['status'] > 0 ? null : sprintf('%s - %s', $baseline['error'], $baseline['reason']),
                        'test_status' => $test['status'],
                        'test_error' => $test['status'] > 0 ? null : sprintf('%s - %s', $test['error'], $test['reason']),
                        'baseline_location' => $baselineLocation,
                        'test_location' => $testLocation,
                    ];
                }
            }
        }

        return $differences;
    }

    private function normalizeLocation(string $location, string $baseUrl): string
    {
        if ($location === '') {
            return '';
        }
        // Strip the base URL prefix so absolute and relative locations compare equal
        if (str_starts_with($location, $baseUrl)) {
            return substr($location, strlen($baseUrl));
        }
        return $location;
    }

    /**
     * @param list<array{uid: int, source_path: string, baseline_status: int, test_status: int, baseline_error: string|null, test_error: string|null, baseline_location: string, test_location: string}> $differences
     */
    private function renderDifferencesTable(OutputInterface $output, array $differences): void
    {
        $table = new Table($output);
        $table->setHeaders(['UID', 'Source path', 'Baseline status', 'Test status', 'Baseline location', 'Test location']);

        foreach ($differences as $diff) {
            $table->addRow([
                $diff['uid'],
                $diff['source_path'],
                $diff['baseline_status'] ?: '<error>ERR - ' . $diff['baseline_error'] . '</error>',
                $diff['test_status'] ?: '<error>ERR - ' . $diff['test_error'] . '</error>',
                $diff['baseline_location'],
                $diff['test_location'],
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<comment>%d difference(s) in total.</comment>', count($differences)));
    }
}
