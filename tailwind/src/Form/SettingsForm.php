<?php

namespace Drupal\tailwind\Form;

use Drupal;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Url;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Render\Markup;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\tailwind\Binary\TailwindBinary;
use Drupal\tailwind\Compiler\TailwindCompiler;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SettingsForm extends FormBase
{
  protected ThemeExtensionList $themeExtensionList;
  protected ModuleExtensionList $moduleExtensionList;
  protected TailwindBinary $tailwindBinary;
  protected TailwindCompiler $tailwindCompiler;
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'tailwind.settings.form';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    $instance = parent::create($container);
    $instance->themeExtensionList = $container->get('extension.list.theme');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->tailwindBinary = $container->get('tailwind.tailwind_binary');
    $instance->tailwindCompiler = $container->get('tailwind.tailwind_compiler');
    $instance->state = $container->get('state');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $moduleConfiguration = $this->config('tailwind.settings');
    $lastStdout = $this->state->get('tailwind.last_stdout', [
      'message' => NULL,
      'timestamp' => NULL,
    ]);

    if (!empty($lastStdout['message']) && !empty($lastStdout['timestamp'])) $this->appendStdoutMessage($form, $lastStdout);

    $this->appendCompilerRunnableInfo($form);
    $this->appendCompilationSources($form, $moduleConfiguration);
    $this->appendCompilationOptions($form, $moduleConfiguration);
    $this->appendCompilerActions($form);

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $extensions = $form_state->getValue('extensions');
    if (empty($extensions)) {
      $form_state->setErrorByName('extensions', $this->t('The extensions field cannot be empty.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $extensions = $form_state->getValue('extensions');
    $extensions = preg_replace('/[^a-zA-Z,]/', '', $extensions);
    $extensions = trim($extensions, ',');
    $extensions = explode(',', $extensions);

    $theme = $form_state->getValue('theme');
    $baseTheme = $form_state->getValue('base_theme');
    $modules = $form_state->getValue('modules');
    $compileExtensions = $this->config('tailwind.settings')->get('compile_extensions') ?? [];

    if (empty($baseTheme)) $baseTheme = NULL;

    $removedExtensions = [];
    foreach ($compileExtensions as $key => &$sourceExtensions) {
      // if the key does not exist in the submitted values, we remove
      // the entries for compile_extensions setting.
      if (str_starts_with($key, 'theme:')) {
        $name = str_replace('theme:', '', $key);
        if ($name !== $theme) {
          unset($compileExtensions[$key]);
          var_dump($key);
          continue;
        }
      }

      if (str_starts_with($key, 'base_theme:')) {
        $name = str_replace('base_theme:', '', $key);
        if ($name !== $baseTheme) {
          unset($compileExtensions[$key]);
          continue;
        }
      }

      if (str_starts_with($key, 'module:')) {
        $name = str_replace('module:', '', $key);
        if (!in_array($name, $modules)) {
          unset($compileExtensions[$key]);
          continue;
        }
      }

      // if any of the extensions were removed,
      // we remove its entry from all occasions in the compile_extensions setting.
      foreach ($sourceExtensions as $extensionKey => &$extension) {
        if (!in_array($extension, $extensions)) {
          unset($sourceExtensions[$extensionKey]);
          if (!in_array($extension, $removedExtensions)) {
            $removedExtensions[] = $extension;
          }
        }
      }
    }

    $this->configFactory()->getEditable('tailwind.settings')
      ->set('theme', $form_state->getValue('theme'))
      ->set('base_theme', $baseTheme)
      ->set('modules', $form_state->getValue('modules'))
      ->set('extensions', $extensions)
      ->set('compile_extensions', $compileExtensions)
      ->set('autocompile', $form_state->getValue('autocompile'))
      ->set('minify', $form_state->getValue('minify'))
      ->set('optimize', $form_state->getValue('optimize'))
      ->set('generate_sourcemap', $form_state->getValue('generate_sourcemap'))
      ->set('recompile_on_cc', $form_state->getValue('recompile_clear_cache'))
      ->save();

    $this->messenger()->addMessage($this->t('Configuration saved successfully.'));

    if (!empty($removedExtensions)) {
      $this->messenger()->addWarning($this->t('The following extensions were removed from the theme/modules configuration: @extensions', ['@extensions' => implode(', ', $removedExtensions)]));
    }
  }

  protected function appendStdoutMessage(array &$form, array $lastStdout): void
  {
    $this->messenger()->addStatus(Markup::create(
      '<strong>' . $this->t('Last compiler output (@timestamp):', ['@timestamp' => date('d-m-Y H:i:s', $lastStdout['timestamp'])]) . '</strong>'
      . '<pre>' . htmlspecialchars($lastStdout['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
    ));
  }

  protected function appendCompilerRunnableInfo(array &$form): void
  {
    if (!$this->tailwindBinary->binaryExists()) {
      $this->messenger()->addWarning($this->t('The Tailwind compiler runnable was not found. Please download it to enable compilation.'));
    }

    $form['compiler'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Compiler Runnable'),
      '#description' => $this->t('The Tailwind compiler runnable is required to compile Tailwind. It will be downloaded directly from the GitHub source: @url with version: @version for @os.', [
        '@url' => 'https://github.com/tailwindlabs/tailwindcss/releases',
        '@version' => $this->tailwindBinary->getBinaryVersion(),
        '@os' => $this->tailwindBinary->getPlatform(),
      ])
    ];

    $form['compiler']['cdw'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'tailwind-cdw'],
    ];

    if (!$this->tailwindBinary->binaryExists()) {
      $form['compiler']['cdw']['status'] = [
        '#markup' => '<p>' . $this->t('The compiler runnable has not been downloaded yet.') . '</p>',
      ];

      $form['compiler']['cdw']['download_action'] = [
        '#type' => 'submit',
        '#value' => $this->t('Download compiler runnable'),
        '#submit' => ['::downloadCompilerRunnableAction'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::downloadCompilerRunnableAjaxCallback',
          'wrapper' => 'tailwind-cdw',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Runnable is being downloaded...'),
          ],
        ],
      ];
    } else {
      $form['compiler']['cdw']['status'] = [
        '#markup' => '<p>' . $this->t('Compiler is ready to use. You are all set.') . '</p>',
      ];
    }
  }

  protected function appendCompilationSources(array &$form, ImmutableConfig $moduleConfiguration): void
  {
    $themeInfo = $this->themeExtensionList->getAllAvailableInfo();
    $moduleInfo = $this->moduleExtensionList->getAllAvailableInfo();
    $extensions = $this->config('tailwind.settings')->get('extensions') ?? [];
    $extensions = !empty($extensions) ? implode(',', $extensions) : '';

    $themeOptions = [];
    foreach ($themeInfo as $machineName => $info) {
      $themeOptions[$machineName] = $info['name'] ?? $machineName;
    }

    $moduleOptions = [];
    foreach ($moduleInfo as $machineName => $info) {
      $moduleOptions[$machineName] = $info['name'] ?? $machineName;
    }

    $form['compilation_sources'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Compilation Sources'),
    ];

    $form['compilation_sources']['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme'),
      '#description' => $this->t('The theme Tailwind will be compiled for.'),
      '#options' => $themeOptions,
      '#default_value' => $moduleConfiguration->get('theme'),
      '#required' => FALSE,
    ];

    $form['compilation_sources']['base_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Base Theme'),
      '#description' => $this->t('The base theme Tailwind will be compiled for.'),
      '#options' => [NULL => $this->t('- No base theme -'), ...$themeOptions],
      '#default_value' => $moduleConfiguration->get('base_theme') ?? 0,
      '#required' => FALSE,
    ];

    $form['compilation_sources']['modules'] = [
      '#type' => 'select',
      '#title' => $this->t('Modules'),
      '#description' => $this->t('The modules Tailwind will be compiled for.'),
      '#options' => $moduleOptions,
      '#default_value' => $moduleConfiguration->get('modules') ?? [],
      '#multiple' => TRUE,
      '#required' => FALSE,
    ];

    $form['compilation_sources']['extensions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extensions'),
      '#description' => $this->t('Comma-separated list of file extensions to scan for Tailwind classes.'),
      '#default_value' => $extensions,
    ];
  }

  protected function appendCompilationOptions(array &$form, ImmutableConfig $moduleConfiguration): void
  {
    $form['compilation_options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Compilation Options'),
    ];

    $form['compilation_options']['autocompile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-compile on page load'),
      '#description' => $this->t('Automatically recompile Tailwind whenever a file changes.'),
      '#default_value' => $moduleConfiguration->get('autocompile') ?? FALSE,
    ];

    $form['compilation_options']['minify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Minify'),
      '#description' => $this->t('Optimize and minify the output'),
      '#default_value' => $moduleConfiguration->get('minify') ?? FALSE,
    ];

    $form['compilation_options']['optimize'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Optimize'),
      '#description' => $this->t('Optimize the output without minifying'),
      '#default_value' => $moduleConfiguration->get('optimize') ?? FALSE,
    ];

    $form['compilation_options']['generate_sourcemap'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate Source Map'),
      '#description' => $this->t('Generate a source map'),
      '#default_value' => $moduleConfiguration->get('generate_sourcemap') ?? FALSE,
    ];

    $form['compilation_options']['recompile_clear_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Recompile on Drupal cache clear'),
      '#description' => $this->t('Automatically recompile Tailwind whenever the Drupal cache is cleared.'),
      '#default_value' => $moduleConfiguration->get('recompile_on_cc') ?? FALSE,
    ];
  }

  protected function appendCompilerActions(array &$form): void
  {
    $form['compiler_actions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Compiler Actions'),
    ];

    $form['compiler_actions']['recompile'] = [
      '#type' => 'submit',
      '#value' => $this->t('Recompile Tailwind'),
      '#submit' => ['::recompileCompilerAction'],
      '#limit_validation_errors' => [],
    ];
  }

  public function downloadCompilerRunnableAction(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('download_success', $this->tailwindBinary->downloadBinary());
  }

  public function downloadCompilerRunnableAjaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse
  {
    $response = new AjaxResponse();

    if ($form_state->get('download_success')) {
      $response->addCommand(new RedirectCommand(Url::fromRoute('<current>')->toString()));
    } else {
      $response->addCommand(new ReplaceCommand('#tailwind-cdw', '<div id="tailwind-cdw"><p>' . $this->t('Failed to download the Tailwind compiler runnable.') . '</p></div>'));
    }

    return $response;
  }

  public function recompileCompilerAction(array &$form, FormStateInterface $form_state): void
  {
    if ($this->tailwindCompiler->compile()) {
      Drupal::messenger()->addStatus($this->t('Tailwind has been successfully recompiled.'));
    } else {
      Drupal::messenger()->addError($this->t('Failed to recompile Tailwind. Check the last compiler output for details.'));
    }
  }
}
