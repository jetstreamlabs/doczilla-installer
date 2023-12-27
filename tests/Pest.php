<?php

/**
 * TEST CASE
 *
 * The closure you provide to your test functions is always bound to a specific PHPUnit test
 * case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
 * need to change it using the "uses()" function to bind a different classes or traits.
 */

// uses(Tests\TestCase::class)->in('Feature');

/**
 * EXPECTATIONS
 *
 * When you're writing tests, you often need to check that values meet certain conditions. The
 * "expect()" function gives you access to a set of "expectations" methods that you can use
 * to assert different things. Of course, you may extend the Expectation API at any time.
 */

/**
 * FUNCTIONS
 *
 * While Pest is very powerful out-of-the-box, you may have some testing code specific to your
 * project that you don't want to repeat in every file. Here you can also expose helpers as
 * global functions to help you to reduce the number of lines of code in your test files.
 */
if (! function_exists('appPath')) {
  function appPath()
  {
    $name = 'tests-output/test_app';
    $directory = dirname(__DIR__).'/'.$name;

    return $directory;
  }
}

if (! function_exists('appName')) {
  function appName()
  {
    $path = appPath();

    $list = [
      basename(dirname($path)),
      basename($path),
    ];

    return implode('/', $list);
  }
}

if (! function_exists('removeTestApp')) {
  function removeTestApp()
  {
    $directory = appPath();

    if (! deleteDirectory($directory)) {
      throw new Exception("Failed to delete directory: $directory");
    }
  }
}

if (! function_exists('deleteDirectory')) {
  function deleteDirectory($dir)
  {
    if (! file_exists($dir)) {
      return true;
    }

    if (! is_dir($dir)) {
      return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
      if ($item == '.' || $item == '..') {
        continue;
      }

      if (! deleteDirectory($dir.DIRECTORY_SEPARATOR.$item)) {
        return false;
      }
    }

    return rmdir($dir);
  }

  if (! function_exists('dd')) {
    function dd()
    {
      array_map(function ($param) {
        dump($param);
      }, func_get_args());
      exit(1);
    }
  }
}
