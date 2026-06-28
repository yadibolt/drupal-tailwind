<?php

namespace Drupal\tailwind\Binary;

use RuntimeException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TailwindBinary implements ContainerInjectionInterface
{
  private CONST string BINARY_VERSION = '4.3.1';

  private string $platform;

  public function __construct(
    protected ModuleExtensionList $moduleExtensionList,
    protected FileSystemInterface $fileSystem,
  ) {
    $this->platform = PHP_OS_FAMILY;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('extension.list.module'),
      $container->get('file_system'),
    );
  }

  /**
   * Ensure the binary folder exists, creating it if necessary.
   * Permissions are also set to 0755 for the whole folder recursively.
   *
   * @return void
   * @throws RuntimeException If the binary folder cannot be created.
   */
  protected function ensureBinaryFolderExists(): void {
    $binaryPath = $this->getBinaryPath();
    if (empty($binaryPath)) {
      throw new RuntimeException('Failed to determine binary path for Tailwind binary.');
    }

    $binaryFolder = dirname($binaryPath);
    if (!$this->fileSystem->prepareDirectory($binaryFolder, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException('Failed to create bin folder for Tailwind binary at path: ' . $binaryFolder);
    }
  }

  /**
   * Ensures that the binary is executable.
   * If it is not executable, an attempt will be made to set its permissions to 0755.
   *
   * @return void
   * @throws RuntimeException
   */
  public function ensureBinaryExecutable(): void {
    $binaryPath = $this->getBinaryPath();

    if (empty($binaryPath) || !$this->binaryExists()) {
      throw new RuntimeException('Tailwind binary does not exist at path: ' . $binaryPath);
    }

    if (!is_executable($binaryPath)) {
      if (!chmod($binaryPath, 0755)) {
        throw new RuntimeException('Failed to make Tailwind binary executable at path: ' . $binaryPath);
      }
    }
  }

  /**
   * Checks if the Tailwind binary exists.
   *
   * @return bool TRUE if the binary exists, FALSE otherwise.
   */
  public function binaryExists(): bool {
    if (!($binaryPath = $this->getBinaryPath())) return FALSE;
    if (!file_exists($binaryPath)) return FALSE;

    return TRUE;
  }

  /**
   * Downloads the Tailwind binary from the official GitHub releases page based on the current platform and version.
   *
   * @return bool TRUE if the binary was successfully downloaded, FALSE otherwise.
   * @throws RuntimeException If the binary path cannot be determined or if the platform is unsupported.
   */
  public function downloadBinary(): bool {
    $binaryPath = $this->getBinaryPath();
    $binaryVersion = $this->getBinaryVersion();

    if (empty($binaryPath)) {
      throw new RuntimeException('Failed to determine binary path for Tailwind binary download.');
    }

    if ($this->binaryExists()) return TRUE;

    $githubURL = match($this->platform) {
      'Windows' => 'https://github.com/tailwindlabs/tailwindcss/releases/download/v' . $binaryVersion . '/tailwindcss-windows-x64.exe',
      'Linux' => 'https://github.com/tailwindlabs/tailwindcss/releases/download/v' . $binaryVersion . '/tailwindcss-linux-x64',
      'Darwin' => 'https://github.com/tailwindlabs/tailwindcss/releases/download/v' . $binaryVersion . '/tailwindcss-macos-x64',
      default => throw new RuntimeException('Unsupported platform for Tailwind binary download: ' . $this->platform)
    };

    $this->ensureBinaryFolderExists();

    $binary = file_get_contents($githubURL);
    if (empty($binary)) return FALSE;

    if (file_put_contents($binaryPath, $binary) === FALSE) return FALSE;

    $this->ensureBinaryExecutable();

    return TRUE;
  }

  /**
   * Checks if the Tailwind binary is executable.
   *
   * @return bool TRUE if the binary is executable, FALSE otherwise.
   */
  public function binaryIsExecutable(): bool {
    $binaryPath = $this->getBinaryPath();
    if (empty($binaryPath)) return FALSE;

    return is_executable($binaryPath);
  }

  /**
   * Tries to get a predefined binary path for the current platform.
   *
   * @return string|null The path to the Tailwind binary for the current platform, or NULL if the platform is unsupported or the module path cannot be determined.
   */
  public function getBinaryPath(): ?string {
    $modulePath = DRUPAL_ROOT . '/' . ($this->moduleExtensionList->getPath('tailwind') ?? '');
    if ($modulePath === DRUPAL_ROOT . '/') return NULL;

    return match($this->platform) {
      'Windows' => $modulePath . '/bin/tailwindcss.exe',
      'Linux', 'Darwin' => $modulePath . '/bin/tailwindcss',
      default => NULL
    };
  }

  /**
   * Returns the current version of the Tailwind binary used by this module.
   * It is also used for the download, so modifying this value will change the version
   * of the release being downloaded.
   *
   * @return string
   */
  public function getBinaryVersion(): string {
    return self::BINARY_VERSION;
  }

  /**
   * Returns the current platform (OS) the module is running on.
   *
   * @return string the platform name, e.g., 'Windows', 'Linux', 'Darwin' (macOS).
   */
  public function getPlatform(): string {
    return $this->platform;
  }
}
