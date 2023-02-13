<?php

declare(strict_types=1);

use ParaTest\WrapperRunner\ApplicationForWrapperWorker;
use ParaTest\WrapperRunner\WrapperWorker;
use Pest\ConfigLoader;
use Pest\Kernel;
use Pest\Plugins\Actions\CallsHandleArguments;
use Pest\TestCaseMethodFilters\TodoTestCaseFilter;
use Pest\TestSuite;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

$bootPest = (static function (): void {
    $workerArgv = new ArgvInput();
    $masterArgv = new ArgvInput(json_decode($_SERVER['PEST_PARALLEL_ARGV']));

    $rootPath = dirname(PHPUNIT_COMPOSER_INSTALL, 2);
    $testSuite = TestSuite::getInstance($rootPath, $workerArgv->getParameterOption(
        '--test-directory',
        (new ConfigLoader($rootPath))->getTestsDirectory()
    ));

    if ($masterArgv->hasParameterOption('--todo')) {
        $testSuite->tests->addTestCaseMethodFilter(new TodoTestCaseFilter());
    }

    $input = new ArgvInput();

    $output = new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, true);

    Kernel::boot($testSuite, $input, $output);
});

(static function () use ($bootPest): void {
    $getopt = getopt('', [
        'status-file:',
        'progress-file:',
        'testresult-file:',
        'teamcity-file:',
        'testdox-file:',
        'testdox-color',
        'phpunit-argv:',
    ]);

    $composerAutoloadFiles = [
        dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'autoload.php',
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php',
        dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php',
    ];

    foreach ($composerAutoloadFiles as $file) {
        if (file_exists($file)) {
            require_once $file;
            define('PHPUNIT_COMPOSER_INSTALL', $file);

            break;
        }
    }

    require_once __DIR__.'/../overrides/Runner/TestSuiteLoader.php';
    require_once __DIR__.'/../overrides/Runner/Filter/NameFilterIterator.php';

    /**
     * PHPUnit has no understanding of "todo" tests, so the ProgressPrinter will not show
     * TODO items by default. We use our own implementation here to add support for TODO.
     */
    require_once __DIR__.'/../overrides/TextUI/Output/Default/ProgressPrinter/TestSkippedSubscriber.php';

    assert(isset($getopt['status-file']) && is_string($getopt['status-file']));
    $statusFile = fopen($getopt['status-file'], 'wb');
    assert(is_resource($statusFile));

    assert(isset($getopt['progress-file']) && is_string($getopt['progress-file']));
    assert(isset($getopt['testresult-file']) && is_string($getopt['testresult-file']));
    assert(! isset($getopt['teamcity-file']) || is_string($getopt['teamcity-file']));
    assert(! isset($getopt['testdox-file']) || is_string($getopt['testdox-file']));

    assert(isset($getopt['phpunit-argv']) && is_string($getopt['phpunit-argv']));
    $phpunitArgv = unserialize($getopt['phpunit-argv'], ['allowed_classes' => false]);
    assert(is_array($phpunitArgv));

    $bootPest();

    $phpunitArgv = (new CallsHandleArguments())($phpunitArgv);

    $application = new ApplicationForWrapperWorker(
        $phpunitArgv,
        $getopt['progress-file'],
        $getopt['testresult-file'],
        $getopt['teamcity-file'] ?? null,
        $getopt['testdox-file'] ?? null,
        isset($getopt['testdox-color']),
    );

    while (true) {
        if (feof(STDIN)) {
            $application->end();
            exit;
        }

        $testPath = fgets(STDIN);
        if ($testPath === false || $testPath === WrapperWorker::COMMAND_EXIT) {
            $application->end();

            exit;
        }

        $exitCode = $application->runTest(trim($testPath));

        fwrite($statusFile, (string) $exitCode);
        fflush($statusFile);
    }
})();
