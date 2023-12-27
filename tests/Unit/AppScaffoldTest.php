<?php

use Doczilla\Installer\Console\NewCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
  removeTestApp();
});

it('can scaffold a new doczilla app', function () {
  $name = appName();
  $directory = appPath();

  $app = new Application('Doczilla Installer');
  $app->add(new NewCommand);

  $tester = new CommandTester($app->find('new'));

  $statusCode = $tester->execute(
    ['name' => $name],
    ['interactive' => false]
  );

  expect($statusCode)->toBe(0);
  expect($directory.'/vendor')->toBeDirectory();
  expect($directory.'/.env')->toBeFile();
});
