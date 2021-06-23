<?php

namespace Lemurro\Installer;

use Exception;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Exception\ProcessFailedException;
use ZipArchive;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class NewCommand extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;
    protected QuestionHelper $question_helper;
    protected Filesystem $filesystem;

    /**
     * Каталог, где мы находимся
     */
    protected string $directory;

    /**
     * Имя нового проекта
     */
    protected string $arg_name;

    /**
     * Версия Lemurro для установки
     */
    protected string $option_lv;

    /**
     * Установить модуль API
     */
    protected bool $option_api;

    /**
     * Установить модуль WEB (client-metronic)
     */
    protected bool $option_web;

    /**
     * Установить модуль MOBILE (client-framework7)
     */
    protected bool $option_mobile;

    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Lemurro application')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Project name'
            )
            ->addOption(
                'lv',
                null,
                InputOption::VALUE_REQUIRED,
                'Lemurro version'
            )
            ->addOption(
                'api',
                null,
                InputOption::VALUE_NONE,
                'Install API module'
            )
            ->addOption(
                'web',
                null,
                InputOption::VALUE_NONE,
                'Install WEB module (client-metronic)'
            )
            // ->addOption(
            //     'mobile',
            //     null,
            //     InputOption::VALUE_NONE,
            //     'Install MOBILE module (client-framework7)'
            // )
            ->addOption(
                'skip',
                null,
                InputOption::VALUE_NONE,
                'Skip questions'
            )
            ->addOption(
                'silent',
                null,
                InputOption::VALUE_NONE,
                'Silent Installation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->question_helper = $this->getHelper('question');
        $this->filesystem = new Filesystem();

        if (!extension_loaded('zip')) {
            $this->output->writeln('<error>                                                                           </error>');
            $this->output->writeln('<error>  The Zip PHP extension is not installed. Please install it and try again  </error>');
            $this->output->writeln('<error>                                                                           </error>');

            return 500;
        }

        $this->prepareParams();

        $this->output->writeln([
            '  _                                        ',
            ' | |    ___ _ __ ___  _   _ _ __ _ __ ___  ',
            " | |   / _ \ '_ ` _ \| | | | '__| '__/ _ \ ",
            ' | |__|  __/ | | | | | |_| | |  | | | (_) |',
            ' |_____\___|_| |_| |_|\__,_|_|  |_|  \___/ ',
            '',
            'Lemurro Installer ' . Config::VERSION,
            '',
            '<info>Install Lemurro:</info>',
            '<info>  Project name</info> = <comment>' . $this->arg_name . '</comment>',
            '<info>  Project path</info> = <comment>' . $this->directory . '</comment>',
            '<info>  Version</info> = <comment>' . $this->option_lv . '</comment>',
            '<info>  API module</info> = <comment>' . ($this->option_api ? 'yes' : 'no') . '</comment>',
            '<info>  WEB module</info> = <comment>' . ($this->option_web ? 'yes' : 'no') . '</comment>',
            //'<info>  MOBILE module</info> = <comment>' . ($this->option_mobile ? 'yes' : 'no') . '</comment>',
        ]);

        if (empty($this->input->getOption('silent'))) {
            $this->output->writeln('');

            $question = new Question('<comment>Continue installation (y|n) {default: n}: </comment>', 'n');
            $answer = strtolower(trim($this->question_helper->ask($this->input, $this->output, $question)));
            if ($answer !== 'y') {
                $this->output->writeln('');
                $this->output->writeln('Installation aborted by user');

                return 0;
            }
        }

        $this->output->writeln('');
        $this->output->writeln('<info>Creating application</info>');

        try {
            $this->verifyProjectFolder();

            $this->installModuleApi();
            $this->installModuleWeb();
            //$this->installModuleMobile();
        } catch (Exception $e) {
            $length = mb_strlen($e->getMessage(), 'UTF-8') + 4;
            $separator = str_pad('', $length);

            $this->output->writeln('<error>' . $separator . '</error>');
            $this->output->writeln('<error>  ' . $e->getMessage() . '  </error>');
            $this->output->writeln('<error>' . $separator . '</error>');

            return 500;
        }

        $this->output->writeln([
            '',
            '<info>Application ready!</info>',
            'https://lemurro.github.io/docs',
        ]);

        return 0;
    }

    protected function prepareParams(): void
    {
        $this->arg_name = $this->getArgName();

        $this->option_lv = $this->getOptionLV();
        $this->option_api = $this->getOptionApi();
        $this->option_web = $this->getOptionWeb();
        //$this->option_mobile = $this->getOptionMobile();

        $this->directory = getcwd() . DIRECTORY_SEPARATOR . $this->arg_name;
    }

    protected function getArgName(): string
    {
        return $this->input->getArgument('name');
    }

    protected function getOptionLV(): string
    {
        if (empty($this->input->getOption('lv'))) {
            $question = new Question('<comment>Lemurro version (X.Y|vX.Y): </comment>');
            $version = $this->question_helper->ask($this->input, $this->output, $question);
            $exception_in_fail = false;
        } else {
            $version = $this->input->getOption('lv');
            $exception_in_fail = true;
        }

        if (preg_match('/(\d+\.\d+)/', $version, $matches)) {
            return $matches[1] . '.0';
        }

        if ($exception_in_fail) {
            throw new Exception('Expected string in format "X.Y" or "vX.Y", try again');
        } else {
            $this->output->writeln('<error>                                                        </error>');
            $this->output->writeln('<error>  Expected string in format "X.Y" or "vX.Y", try again  </error>');
            $this->output->writeln('<error>                                                        </error>');
        }

        return $this->getOptionLV();
    }

    protected function getOptionApi(): bool
    {
        if (empty($this->input->getOption('api')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install API module (y|n) {default: y}: </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('api');
        }
    }

    protected function getOptionWeb(): bool
    {
        if (empty($this->input->getOption('web')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install WEB module (client-metronic) (y|n) {default: y}: </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('web');
        }
    }

    protected function getOptionMobile(): bool
    {
        if (empty($this->input->getOption('mobile')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install MOBILE module (client-framework7) (y|n) {default: y}: </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('mobile');
        }
    }

    protected function getOptionResult(string $value): bool
    {
        if (strtolower(trim($value)) === 'n') {
            return false;
        }

        return true;
    }

    protected function verifyProjectFolder(): void
    {
        if (!$this->filesystem->exists($this->directory)) {
            $this->filesystem->mkdir($this->directory, 0755);
        }
    }

    /**
     * @throws Exception
     */
    protected function installModuleApi(): void
    {
        if ($this->option_api) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Install API module...</comment>');

            $module = 'api';

            $zip_file_name = $this->getZipFilename($module);
            $folder = $this->getFolder($module);

            try {
                $this->download($module, $zip_file_name)
                    ->extract($zip_file_name, $folder)
                    ->prepareFolder($module, $folder)
                    ->cleanUp($zip_file_name)
                    ->runCommands([
                        'composer install --working-dir=' . $this->directory . DIRECTORY_SEPARATOR . 'api',
                    ]);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

            $this->output->writeln([
                '<comment>API module successfully installed</comment>',
            ]);
        }
    }

    /**
     * @throws Exception
     */
    protected function installModuleWeb(): void
    {
        if ($this->option_web) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Install WEB module (client-metronic)...</comment>');

            $module = 'client-metronic';

            $zip_file_name = $this->getZipFilename($module);
            $folder = $this->getFolder($module);

            try {
                $this->download($module, $zip_file_name)
                    ->extract($zip_file_name, $folder)
                    ->prepareFolder($module, $folder)
                    ->cleanUp($zip_file_name)
                    ->runCommands([
                        'cd ' . $this->directory . DIRECTORY_SEPARATOR . 'web',
                        'npm install',
                        'cd ..',
                    ]);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

            $this->output->writeln('<comment>WEB module (client-metronic) successfully installed</comment>');
        }
    }

    /**
     * @throws Exception
     */
    protected function installModuleMobile(): void
    {
        if ($this->option_mobile) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Install MOBILE module (client-framework7)...</comment>');

            $module = 'client-framework7';

            $zip_file_name = $this->getZipFilename($module);
            $folder = $this->getFolder($module);

            try {
                $this->download($module, $zip_file_name)
                    ->extract($zip_file_name, $folder)
                    ->prepareFolder($module, $folder)
                    ->cleanUp($zip_file_name)
                    ->runCommands([
                        'cd ' . $this->directory . DIRECTORY_SEPARATOR . 'mobile',
                        'npm install',
                        'cd ..',
                    ]);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

            $this->output->writeln('<comment>MOBILE module (client-framework7) successfully installed</comment>');
        }
    }

    protected function getZipFilename(string $module): string
    {
        return $this->directory . '/lemurro_' . $module . '_' . md5(time() . uniqid()) . '.zip';
    }

    protected function getFolder(string $module): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $module . '-' . $this->option_lv;
    }

    /**
     * @return $this
     *
     * @throws Exception
     */
    protected function download(string $module, string $filename)
    {
        $this->output->write('Downloading archive...');

        $url = "https://github.com/Lemurro/$module/archive/v$this->option_lv.zip";
        file_put_contents($filename, (new Client())->get($url)->getBody());

        if (!is_readable($filename)) {
            $this->output->writeln('');

            throw new Exception('Archive not downloaded');
        }

        $this->output->writeln(' <info>[OK]</info>');
        return $this;
    }

    /**
     * @return $this
     *
     * @throws Exception
     */
    protected function extract(string $zip_file_name, string $folder)
    {
        $this->output->write('Extracting archive...');

        $archive = new ZipArchive();

        $archive->open($zip_file_name);
        $archive->extractTo($this->directory);
        $archive->close();

        if (!is_readable($folder)) {
            $this->output->writeln('');

            throw new Exception('Archive not extracted');
        }

        $this->output->writeln(' <info>[OK]</info>');

        return $this;
    }

    /**
     * @return $this
     *
     * @throws Exception
     */
    protected function prepareFolder(string $module, string $folder)
    {
        $this->output->write('Preparing folder...');

        $module_dir = pathinfo($folder, PATHINFO_DIRNAME);

        switch ($module) {
            case 'api':
                $newname = '/api';
                break;

            case 'client-metronic':
                $newname = '/web';
                break;

            case 'client-framework7':
                $newname = '/mobile';
                break;

            default:
                $newname = '/' . $module;
                break;
        }

        try {
            $this->filesystem->rename($folder, $module_dir . $newname);

            $this->output->writeln(' <info>[OK]</info>');
        } catch (IOExceptionInterface $e) {
            $this->output->writeln(' <error>Directory is not renamed, you can rename the directory "/' . $module . '" manually to  "' . $newname . '"</error>');
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function cleanUp(string $zip_file_name)
    {
        @chmod($zip_file_name, 0777);
        @unlink($zip_file_name);

        return $this;
    }

    /**
     * @return $this
     *
     * @throws Exception
     */
    protected function runCommands(array $commands)
    {
        $this->output->writeln('Executing commands...');

        $progress_bar = new ProgressBar($this->output);
        $progress_bar->start();

        try {
            $process = Process::fromShellCommandline(implode(' && ', $commands), $this->directory, null, null, null);

            if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
                $process->setTty(true);
            }

            $process->run(function () use ($progress_bar) {
                $progress_bar->advance();
            });

            if (!$process->isSuccessful()) {
                $progress_bar->finish();

                throw new ProcessFailedException($process);
            }
        } catch (Exception $e) {
            $progress_bar->finish();

            throw new Exception($e->getMessage());
        }

        $progress_bar->finish();

        $this->output->writeln('');

        return $this;
    }
}
