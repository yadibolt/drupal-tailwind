<?php

namespace Drupal\tailwind\Compiler;

use Drupal;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystem;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tailwind\Binary\TailwindBinary;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TailwindCompiler
{
  use StringTranslationTrait;

  public string $version;
  protected string $executablePath;
  protected ImmutableConfig $moduleConfiguration;

  public function __construct(protected TailwindBinary $tailwindBinary,
                              protected FileSystem $fileSystem,
                              protected ConfigFactory $configurationFactory,
                              protected ThemeExtensionList $themeExtensionList,
                              protected ModuleExtensionList $moduleExtensionList,
                              protected Messenger $messenger) {
    $this->moduleConfiguration = $this->configurationFactory->get('tailwind.settings');
    $this->executablePath = $this->tailwindBinary->getBinaryPath();
    $this->version = $this->tailwindBinary->getBinaryVersion();
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tailwind.tailwind_binary'),
      $container->get('file_system'),
      $container->get('config.factory'),
      $container->get('extension.list.theme'),
      $container->get('extension.list.module'),
      $container->get('messenger')
    );
  }

  /**
   * Checks if the Tailwind compiler is ready for use.
   *
   * @return bool TRUE if the compiler is ready, FALSE otherwise.
   */
  public function ready(): bool {
    return $this->tailwindBinary->getBinaryPath()
      && $this->tailwindBinary->binaryExists()
      && $this->tailwindBinary->binaryIsExecutable();
  }

  /**
   * Compiles the Tailwind for the configured theme and modules.
   *
   * @return bool TRUE if compilation was successful, FALSE otherwise.
   */
  public function compile(): bool {
    $theme = $this->moduleConfiguration->get('theme');
    $themePath = DRUPAL_ROOT . '/' . ($this->themeExtensionList->getPath($theme) ?? '');

    if ($themePath === DRUPAL_ROOT . '/') {
      $this->messenger->addStatus(
        $this->t('The configured theme path is invalid. Please check your Tailwind module settings.')
      );
      return FALSE;
    }

    $this->prepareThemeDirectories();
    $this->createTailwindFile();
    $this->createUserThemeFile();

    $inputFile = $themePath . '/tailwind/tailwind.css';
    $outputFile = $themePath . '/dist/css/tailwind.css';

    if (!file_exists($inputFile)) {
      $this->messenger->addError(
        $this->t('The input file for Tailwind compilation does not exist: @file', ['@file' => $inputFile])
      );
      return FALSE;
    }

    $command = escapeshellarg($this->executablePath);
    $command .= ' -i ' . escapeshellarg($inputFile);
    $command .= ' -o ' . escapeshellarg($outputFile);

    if ($this->moduleConfiguration->get('minify')) {
      $command .= ' --minify';
    }

    if ($this->moduleConfiguration->get('optimize')) {
      $command .= ' --optimize';
    }

    if ($this->moduleConfiguration->get('generate_sourcemap')) {
      $command .= ' --map';
    }

    exec($command . ' 2>&1', $lines, $returnCode);

    if ($returnCode > 0) return FALSE;

    Drupal::state()->set('tailwind.last_stdout', [
      'message' => implode("\n", array_filter($lines, fn($line) => trim($line) !== '')),
      'timestamp' => time(),
    ]);

    Drupal::state()->set('tailwind.last_compile_time', time());

    return TRUE;
  }

  /**
   * Determines whether the Tailwind should be recompiled based on the last compile time and the modification times of relevant files.
   *
   * @return bool TRUE if recompilation is needed, FALSE otherwise.
   */
  public function shouldRecompile(): bool {
    $lastCompileTime = Drupal::state()->get('tailwind.last_compile_time', 0);

    $theme = $this->moduleConfiguration->get('theme');
    $themeExtensions = $this->moduleConfiguration->get('compile_extensions')["theme:$theme"] ?? [];
    if (!empty($themeExtensions)) {
      $themePath = DRUPAL_ROOT . '/' . ($this->themeExtensionList->getPath($theme) ?? '');
      foreach ($this->getFilesFromDirectory($themePath, $themeExtensions) as $file) {
        if (filemtime($file) > $lastCompileTime) {
          return TRUE;
        }
      }
    }

    $modules = $this->moduleConfiguration->get('modules') ?? [];
    foreach ($modules as $module) {
      $moduleExtensions = $this->moduleConfiguration->get('compile_extensions')["module:$module"] ?? [];
      if (!empty($moduleExtensions)) {
        $modulePath = DRUPAL_ROOT . '/' . ($this->moduleExtensionList->getPath($module) ?? '');
        foreach ($this->getFilesFromDirectory($modulePath, $moduleExtensions) as $file) {
          if (filemtime($file) > $lastCompileTime) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Recursively retrieves files from a directory that match the specified extensions.
   *
   * @param string $directory The directory to search in.
   * @param array $extensions An array of file extensions to filter by (without the dot).
   *
   * @return array An array of file paths that match the specified extensions.
   */
  public function getFilesFromDirectory(string $directory, array $extensions): array {
    $files = [];
    if (!is_dir($directory)) return $files;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
      if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
        $files[] = $file->getPathname();
      }
    }

    return $files;
  }

  /**
   * Prepares the necessary directories for Tailwind compilation within the theme.
   * Creates the 'tailwind', 'dist', and 'dist/css' directories if they do not exist.
   */
  public function prepareThemeDirectories(): void {
    $theme = $this->moduleConfiguration->get('theme');
    $themePath = DRUPAL_ROOT . '/' . ($this->themeExtensionList->getPath($theme) ?? '');

    $tailwindDirectory = $themePath . '/tailwind';
    $this->fileSystem->prepareDirectory($tailwindDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $distDirectory = $themePath . '/dist';
    $this->fileSystem->prepareDirectory($distDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $distCSSDirectory = $themePath . '/dist/css';
    $this->fileSystem->prepareDirectory($distCSSDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  }

  /**
   * Creates the main Tailwind file that imports the necessary sources based on the configured theme and modules.
   * The generated file is located at 'tailwind/tailwind.css' within the theme directory.
   */
  public function createTailwindFile(): void {
    $theme = $this->moduleConfiguration->get('theme');
    $modules = $this->moduleConfiguration->get('modules');
    $compileExtensions = $this->moduleConfiguration->get('compile_extensions') ?? [];

    $fileContent = '/** This file is generated by the Tailwind module.' . PHP_EOL . '    Do not edit it directly. Generated at ' . time() . ' */' . PHP_EOL . PHP_EOL;
    $fileContent .= '@import "tailwindcss" source(none);' . PHP_EOL;

    // theme
    $fileContent .= PHP_EOL. '/** Theme (' . $theme . ') */' . PHP_EOL;
    $themePath = DRUPAL_ROOT . '/' . ($this->themeExtensionList->getPath($theme) ?? '');
    if (!is_dir($themePath . '/tailwind')) {
      $this->prepareThemeDirectories();
    }
    $themeExtensions = $compileExtensions["theme:$theme"] ?? [];
    $fileContent .= $this->buildSourceStringFromExtensions($themeExtensions) . PHP_EOL;

    // modules
    $fileContent .= PHP_EOL. '/** Modules (' . implode(', ', $modules) . ') */' . PHP_EOL;
    foreach ($modules as $module) {
      $modulePath = DRUPAL_ROOT . '/' . ($this->moduleExtensionList->getPath($module) ?? '');
      $moduleExtensions = $compileExtensions["module:$module"] ?? [];
      $moduleRelativePath = $this->getRelativePathByPaths($themePath . '/tailwind', $modulePath);
      $fileContent .= $this->buildSourceStringFromExtensions($moduleExtensions, $moduleRelativePath) . PHP_EOL;
    }

    // user theme
    $fileContent .= PHP_EOL. '/** User theme */' . PHP_EOL;
    $fileContent .= '@import "./theme.css";';

    $file = fopen($themePath . '/tailwind/tailwind.css', 'w');
    fwrite($file, $fileContent);
    fclose($file);
  }

  /**
   * Creates a user-editable theme file at 'tailwind/theme.css' within the theme directory.
   * If the file already exists, it will not be overwritten.
   */
  public function createUserThemeFile(): void {
    $theme = $this->moduleConfiguration->get('theme');
    $themePath = DRUPAL_ROOT . '/' . ($this->themeExtensionList->getPath($theme) ?? '');
    if (!file_exists($themePath . '/tailwind/theme.css')) {
      $fileContent = '/** This file was generated by the Tailwind module.' . PHP_EOL .
        '    You may modify this file as you wish. In case you delete this file, new will be created upon compilation.' . PHP_EOL .
        '    Here are some resources that may help you to get started:' . PHP_EOL .
        '    Dark mode: https://tailwindcss.com/docs/dark-mode' . PHP_EOL .
        '    Theme: https://tailwindcss.com/docs/theme' . PHP_EOL .
        '    Colors: https://tailwindcss.com/docs/colors' . PHP_EOL .
        '    Custom styles: https://tailwindcss.com/docs/adding-custom-styles */';

      $file = fopen($themePath . '/tailwind/theme.css', 'w');
      fwrite($file, $fileContent);
      fclose($file);
    }
  }

  /**
   * Builds a Tailwind source string for the given file extensions and path.
   *
   * @param array $extensions An array of file extensions to include in the source string.
   * @param string $path The relative path to the directory containing the files (default is empty).
   *
   * @return string The constructed Tailwind source string.
   */
  protected function buildSourceStringFromExtensions(array $extensions, string $path = ''): string {
    if (empty($extensions)) return PHP_EOL;
    if (empty($path)) {
      $path = '..';
    }

    return '@source "' . $path . '/**/*.{' . implode(',', $extensions) . '}";';
  }

  /**
   * Calculates the relative path from one directory to another.
   *
   * @param string $fromPath The starting directory path.
   * @param string $toPath The target directory path.
   *
   * @return string The relative path from the starting directory to the target directory.
   */
  protected function getRelativePathByPaths(string $fromPath, string $toPath): string {
    $fromPath = explode('/', rtrim($fromPath, '/'));
    $toPath = explode('/', rtrim($toPath, '/'));
    while ($fromPath && $toPath && $fromPath[0] === $toPath[0]) {
      array_shift($fromPath);
      array_shift($toPath);
    }
    return str_repeat('../', count($fromPath)) . implode('/', $toPath);
  }
}
