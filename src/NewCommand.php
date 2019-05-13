<?php
/**
 * Основная команда
 *
 * @version 13.05.2019
 * @author  Дмитрий Щербаков <atomcms@ya.ru>
 */

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

/**
 * Class NewCommand
 *
 * @package Lemurro\Installer
 *
 * @version 02.05.2019
 * @author  Дмитрий Щербаков <atomcms@ya.ru>
 */
class NewCommand extends Command
{
    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var QuestionHelper
     */
    protected $question_helper;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string Каталог, где мы находимся
     */
    protected $directory;

    /**
     * @var string Имя нового проекта
     */
    protected $default_version = 'master';

    /**
     * @var string Имя нового проекта
     */
    protected $arg_name;

    /**
     * @var string Версия Lemurro для установки
     */
    protected $option_lv;

    /**
     * @var boolean Установить модуль API
     */
    protected $option_api;

    /**
     * @var boolean Установить модуль WEB (client-metronic)
     */
    protected $option_web;

    /**
     * @var boolean Установить модуль MOBILE (client-framework7)
     */
    protected $option_mobile;

    /**
     * Конфигурация команды
     *
     * @return void
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function configure()
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
            ->addOption(
                'mobile',
                null,
                InputOption::VALUE_NONE,
                'Install MOBILE module (client-framework7)'
            )
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

    /**
     * Выполняем команду
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     *
     * @version 13.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->question_helper = $this->getHelper('question');
        $this->filesystem = new Filesystem;

        if (!extension_loaded('zip')) {
            $this->output->writeln('<error>The Zip PHP extension is not installed. Please install it and try again</error>');

            return;
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
            '<info>  MOBILE module</info> = <comment>' . ($this->option_mobile ? 'yes' : 'no') . '</comment>',
        ]);

        if (empty($this->input->getOption('silent'))) {
            $this->output->writeln('');

            $question = new Question('<comment>Continue installation (y|n): </comment>', 'n');
            $answer = strtolower(trim($this->question_helper->ask($this->input, $this->output, $question)));
            if ($answer !== 'y') {
                $this->output->writeln('');
                $this->output->writeln('Installation aborted by user');

                return;
            }
        }

        $this->output->writeln('');
        $this->output->writeln('<info>Creating application</info>');

        try {
            $this->verifyProjectFolder();

            $this->installModuleApi();
            $this->installModuleWeb();
            $this->installModuleMobile();
        } catch (Exception $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');

            return;
        }

        $this->output->writeln([
            '',
            '<info>Application ready!</info>',
            'https://lemurro.github.io/docs',
        ]);
    }

    /**
     * Подготовим параметры
     *
     * @return void
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function prepareParams()
    {
        $this->arg_name = $this->getArgName();

        $this->option_lv = $this->getOptionLV();
        $this->option_api = $this->getOptionApi();
        $this->option_web = $this->getOptionWeb();
        $this->option_mobile = $this->getOptionMobile();

        $this->directory = getcwd() . DIRECTORY_SEPARATOR . $this->arg_name;
    }

    /**
     * Получаем аргумент name
     *
     * @return string
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getArgName()
    {
        return $this->input->getArgument('name');
    }

    /**
     * Получаем опцию --lv
     *
     * @return string
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getOptionLV()
    {
        if (empty($this->input->getOption('lv'))) {
            $question = new Question(
                '<comment>Lemurro version (latest|X.Y.Z|vX.Y.Z) {default: latest}: </comment>',
                'latest'
            );

            $version = $this->question_helper->ask($this->input, $this->output, $question);
        } else {
            $version = $this->input->getOption('lv');
        }

        if (preg_match('/(\d+\.\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return $this->default_version;
    }

    /**
     * Получаем опцию --api
     *
     * @return boolean
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getOptionApi()
    {
        if (empty($this->input->getOption('api')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install API module (y|n): </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('api');
        }
    }

    /**
     * Получаем опцию --web
     *
     * @return boolean
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getOptionWeb()
    {
        if (empty($this->input->getOption('web')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install WEB module (client-metronic) (y|n): </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('web');
        }
    }

    /**
     * Получаем опцию --mobile
     *
     * @return boolean
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getOptionMobile()
    {
        if (empty($this->input->getOption('mobile')) && empty($this->input->getOption('skip'))) {
            $question = new Question('<comment>Install MOBILE module (client-framework7) (y|n): </comment>', 'y');
            $answer = $this->question_helper->ask($this->input, $this->output, $question);

            return $this->getOptionResult($answer);
        } else {
            return $this->input->getOption('mobile');
        }
    }

    /**
     * Получаем результат значения опции
     *
     * @param string $value
     *
     * @return boolean
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getOptionResult($value)
    {
        if (strtolower(trim($value)) === 'n') {
            return false;
        }

        return true;
    }

    /**
     * Проверяем каталог проекта, создаём если он отсутствует
     *
     * @return void
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function verifyProjectFolder()
    {
        if (!$this->filesystem->exists($this->directory)) {
            $this->filesystem->mkdir($this->directory, 0755);
        }
    }

    /**
     * Установка модуля API
     *
     * @return void
     *
     * @throws Exception
     *
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     * @version 02.05.2019
     */
    protected function installModuleApi()
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
     * Установка модуля WEB
     *
     * @return void
     *
     * @throws Exception
     *
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     * @version 02.05.2019
     */
    protected function installModuleWeb()
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
     * Установка модуля MOBILE
     *
     * @return void
     *
     * @throws Exception
     *
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     * @version 02.05.2019
     */
    protected function installModuleMobile()
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

    /**
     * Получаем имя файла архива
     *
     * @param string $module
     *
     * @return string
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getZipFilename($module)
    {
        return $this->directory . '/lemurro_' . $module . '_' . md5(time() . uniqid()) . '.zip';
    }

    /**
     * Получаем имя каталога модуля
     *
     * @param string $module
     *
     * @return string
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function getFolder($module)
    {
        return $this->directory . DIRECTORY_SEPARATOR . $module . '-' . $this->option_lv;
    }

    /**
     * Скачиваем архив с GitHub
     *
     * @param string $module
     * @param string $filename
     *
     * @return $this
     *
     * @throws Exception
     *
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     * @version 02.05.2019
     */
    protected function download($module, $filename)
    {
        $this->output->writeln('Downloading archive...');

        $branch = $this->option_lv;

        if ($branch !== $this->default_version) {
            $branch = 'v' . $branch;
        }

        $url = 'https://github.com/Lemurro/' . $module . '/archive/' . $branch . '.zip';

        file_put_contents($filename, (new Client)->get($url)->getBody());

        if (!is_readable($filename)) {
            throw new Exception('Archive not downloaded');
        }

        $this->output->writeln('Archive successfully downloaded');

        return $this;
    }

    /**
     * Распаковка архива в каталог
     *
     * @param string $zip_file_name
     * @param string $folder
     *
     * @return $this
     *
     * @throws Exception
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function extract($zip_file_name, $folder)
    {
        $this->output->writeln('Extracting archive...');

        $archive = new ZipArchive;

        $archive->open($zip_file_name);
        $archive->extractTo($this->directory);
        $archive->close();

        if (!is_readable($folder)) {
            throw new Exception('Archive not extracted');
        }

        $this->output->writeln('Archive successfully extracted');

        return $this;
    }

    /**
     * Подготовка распакованного каталога
     *
     * @param string $module
     * @param string $folder
     *
     * @return $this
     *
     * @throws Exception
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function prepareFolder($module, $folder)
    {
        $this->output->writeln('Preparing folder...');

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

            $this->output->writeln('Folder successfully prepared');
        } catch (IOExceptionInterface $e) {
            $this->output->writeln('<comment>Directory is not renamed, you can rename the directory "/' . $module . '" manually to  "' . $newname . '"</comment>');
        }

        return $this;
    }

    /**
     * Удаление zip-файла
     *
     * @param string $zip_file_name
     *
     * @return $this
     *
     * @version 02.05.2019
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     */
    protected function cleanUp($zip_file_name)
    {
        @chmod($zip_file_name, 0777);
        @unlink($zip_file_name);

        return $this;
    }

    /**
     * Выполним набор консольных команд
     *
     * @param array $commands
     *
     * @return $this
     *
     * @throws Exception
     *
     * @author  Дмитрий Щербаков <atomcms@ya.ru>
     * @version 02.05.2019
     */
    protected function runCommands($commands)
    {
        $this->output->writeln('Executing commands...');

        $progress_bar = new ProgressBar($this->output);
        $progress_bar->start();

        try {
            $process = new Process(implode(' && ', $commands), $this->directory, null, null, null);

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
        $this->output->writeln('Commands successfully executed');

        return $this;
    }
}
