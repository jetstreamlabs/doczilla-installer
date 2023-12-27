<?php

/**
 * Copyright (c) Jetstream Labs, LLC. All Rights Reserved.
 *
 * This software is licensed under the MIT License and free to use,
 * guided by the included LICENSE file. For any required original
 * licenses, see the licenses directory.
 *
 * Made with ♥ in the QC.
 */

namespace Doczilla\Installer\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\ProcessUtils;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
  use Concerns\ConfiguresPrompts;

  /**
   * The Composer instance.
   *
   * @var Composer
   */
  protected $composer;

  /**
   * Configure the command options.
   *
   * @return void
   */
  protected function configure()
  {
    $this
        ->setName('new')
        ->setDescription('Create a new Doczilla application')
        ->addArgument('name', InputArgument::REQUIRED)
        ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
        ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
        ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
        ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
        ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
  }

  /**
   * Interact with the user before validating the input.
   *
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @return void
   */
  protected function interact(InputInterface $input, OutputInterface $output)
  {
    parent::interact($input, $output);

    $this->configurePrompts($input, $output);

    $output->write(PHP_EOL.'  <fg=bright-green>
  ________                 ________________
  ___  __ \___________________(_)__  /__  /_____ _
  __  / / /  __ \  ___/__  /_  /__  /__  /_  __ `/
  _  /_/ // /_/ / /__ __  /_  / _  / _  / / /_/ /
  /_____/ \____/\___/ _____/_/  /_/  /_/  \__,_/</>'.PHP_EOL.PHP_EOL);

    if (! $input->getArgument('name')) {
      $input->setArgument('name', text(
        label: 'What is the name of your project?',
        placeholder: 'E.g. example-app',
        required: 'The project name is required.',
        validate: fn ($value) => preg_match('/[^\pL\pN\-_.]/', $value) !== 0
            ? 'The name may only contain letters, numbers, dashes, underscores, and periods.'
            : null,
      ));
    }

    if (! $input->getOption('git') && $input->getOption('github') === false && Process::fromShellCommandline('git --version')->run() === 0) {
      $input->setOption('git', confirm(label: 'Would you like to initialize a Git repository?', default: false));
    }
  }

  /**
   * Execute the command.
   *
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @return int
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $name = $input->getArgument('name');

    $directory = $name !== '.' ? getcwd().'/'.$name : '.';

    $this->composer = new Composer(new Filesystem(), $directory);

    if (! $input->getOption('force')) {
      $this->verifyApplicationDoesntExist($directory);
    }

    if ($input->getOption('force') && $directory === '.') {
      throw new RuntimeException('Cannot use --force option when using current directory for installation!');
    }

    $composer = $this->findComposer();

    putenv('APP_ENV='.getenv('APP_ENV'));

    $commands = [
      $composer." create-project doczilla/doczilla \"$directory\" --remove-vcs --prefer-dist",
    ];

    if ($directory != '.' && $input->getOption('force')) {
      if (PHP_OS_FAMILY == 'Windows') {
        array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
      } else {
        array_unshift($commands, "rm -rf \"$directory\"");
      }
    }

    if (PHP_OS_FAMILY != 'Windows') {
      $commands[] = "chmod 755 \"$directory/artisan\"";
    }

    if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
      if ($name !== '.') {
        $this->replaceInFile(
          'APP_DOMAIN=localhost',
          'APP_DOMAIN='.$this->generateAppUrl($name),
          $directory.'/.env'
        );
      }

      if ($input->getOption('git') || $input->getOption('github') !== false) {
        $this->createRepository($directory, $input, $output);
      }

      if ($input->getOption('github') !== false) {
        $this->pushToGitHub($name, $directory, $input, $output);
        $output->writeln('');
      }

      $output->writeln("  <bg=blue;fg=white> INFO </> Application ready in <options=bold>[{$name}]</>. Build something amazing.".PHP_EOL);
    }

    return $process->getExitCode();
  }

  /**
   * Return the local machine's default Git branch if set or default to `main`.
   *
   * @return string
   */
  protected function defaultBranch()
  {
    $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

    $process->run();

    $output = trim($process->getOutput());

    return $process->isSuccessful() && $output ? $output : 'main';
  }

  /**
   * Create a Git repository and commit the base Laravel skeleton.
   *
   * @param  string  $directory
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @return void
   */
  protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
  {
    $branch = $this->defaultBranch();

    $commands = [
      'git init -q',
      'git add .',
      'git commit -q -m "Set up a fresh Doczilla app"',
      "git branch -M {$branch}",
    ];

    $this->runCommands($commands, $input, $output, workingPath: $directory);
  }

  /**
   * Commit any changes in the current working directory.
   *
   * @param  string  $message
   * @param  string  $directory
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @return void
   */
  protected function commitChanges(string $message, string $directory, InputInterface $input, OutputInterface $output)
  {
    if (! $input->getOption('git') && $input->getOption('github') === false) {
      return;
    }

    $commands = [
      'git add .',
      "git commit -q -m \"$message\"",
    ];

    $this->runCommands($commands, $input, $output, workingPath: $directory);
  }

  /**
   * Create a GitHub repository and push the git log to it.
   *
   * @param  string  $name
   * @param  string  $directory
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @return void
   */
  protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output)
  {
    $process = new Process(['gh', 'auth', 'status']);
    $process->run();

    if (! $process->isSuccessful()) {
      $output->writeln('  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...'.PHP_EOL);

      return;
    }

    $name = $input->getOption('organization') ? $input->getOption('organization')."/$name" : $name;
    $flags = $input->getOption('github') ?: '--private';

    $commands = [
      "gh repo create {$name} --source=. --push {$flags}",
    ];

    $this->runCommands($commands, $input, $output, workingPath: $directory, env: ['GIT_TERMINAL_PROMPT' => 0]);
  }

  /**
   * Verify that the application does not already exist.
   *
   * @param  string  $directory
   * @return void
   */
  protected function verifyApplicationDoesntExist($directory)
  {
    if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
      throw new RuntimeException('Application already exists!');
    }
  }

  /**
   * Generate a valid APP_URL for the given application name.
   *
   * @param  string  $name
   * @return string
   */
  protected function generateAppUrl($name)
  {
    $hostname = mb_strtolower($name).'.test';

    return $this->canResolveHostname($hostname) ? $hostname : 'localhost';
  }

  /**
   * Determine whether the given hostname is resolvable.
   *
   * @param  string  $hostname
   * @return bool
   */
  protected function canResolveHostname($hostname)
  {
    return gethostbyname($hostname.'.') !== $hostname.'.';
  }

  /**
   * Get the composer command for the environment.
   *
   * @return string
   */
  protected function findComposer()
  {
    return implode(' ', $this->composer->findComposer());
  }

  /**
   * Get the path to the appropriate PHP binary.
   *
   * @return string
   */
  protected function phpBinary()
  {
    $phpBinary = (new PhpExecutableFinder)->find(false);

    return $phpBinary !== false
        ? ProcessUtils::escapeArgument($phpBinary)
        : 'php';
  }

  /**
   * Run the given commands.
   *
   * @param  array  $commands
   * @param InputInterface  $input
   * @param OutputInterface  $output
   * @param  string|null  $workingPath
   * @param  array  $env
   * @return Process
   */
  protected function runCommands($commands, InputInterface $input, OutputInterface $output, string $workingPath = null, array $env = [])
  {
    $env = array_merge($_ENV, $env);

    if (! $output->isDecorated()) {
      $commands = array_map(function ($value) {
        if (str_starts_with($value, 'chmod')) {
          return $value;
        }

        if (str_starts_with($value, 'git')) {
          return $value;
        }

        return $value.' --no-ansi';
      }, $commands);
    }

    if ($input->getOption('quiet')) {
      $commands = array_map(function ($value) {
        if (str_starts_with($value, 'chmod')) {
          return $value;
        }

        if (str_starts_with($value, 'git')) {
          return $value;
        }

        return $value.' --quiet';
      }, $commands);
    }

    $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

    if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
      try {
        $process->setTty(true);
      } catch (RuntimeException $e) {
        $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
      }
    }

    $process->run(function ($type, $line) use ($output) {
      $output->write('    '.$line);
    });

    return $process;
  }

  /**
   * Replace the given file.
   *
   * @param  string  $replace
   * @param  string  $file
   * @return void
   */
  protected function replaceFile(string $replace, string $file)
  {
    $stubs = dirname(__DIR__).'/stubs';

    file_put_contents(
      $file,
      file_get_contents("$stubs/$replace"),
    );
  }

  /**
   * Replace the given string in the given file.
   *
   * @param  string|array  $search
   * @param  string|array  $replace
   * @param  string  $file
   * @return void
   */
  protected function replaceInFile(string|array $search, string|array $replace, string $file)
  {
    file_put_contents(
      $file,
      str_replace($search, $replace, file_get_contents($file))
    );
  }
}
