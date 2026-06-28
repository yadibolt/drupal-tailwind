<?php

namespace Drupal\tailwind\Form;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SourcesSettingsForm extends FormBase
{
  protected ThemeExtensionList $themeExtensionList;
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tailwind.sources_settings.form';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->themeExtensionList = $container->get('extension.list.theme');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $moduleConfiguration = $this->config('tailwind.settings');
    $theme = $moduleConfiguration->get('theme');
    $modules = $moduleConfiguration->get('modules') ?? [];
    $extensions = $moduleConfiguration->get('extensions') ?? [];
    $sourceExtensions = $moduleConfiguration->get('compile_extensions') ?? [];

    $extensionOptions = [];
    foreach ($extensions as $extension) {
      $extensionOptions[$extension] = ".$extension";
    }

    if (empty($theme)) {
      $this->messenger()->addError($this->t('No theme configured yet. Please navigate to the settings and set your theme.'));
      return $form;
    }

    $this->appendThemeExtensionConfiguration($form, $theme, $extensionOptions, $sourceExtensions);
    if (!empty($modules)) $this->appendModuleExtensionConfiguration($form, $modules, $extensionOptions, $sourceExtensions);

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
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $themeExtensions = $form_state->getValue('theme');
    $moduleExtensions = $form_state->getValue('modules');

    $values = [];
    foreach ($themeExtensions as $key => $value) {
      $extensions = $value['extensions'] ?? [];

      if (!empty($extensions)) {
        $selected = array_keys(array_filter($extensions));
        $values[$key] = $selected;
      }
    }

    foreach ($moduleExtensions as $key => $value) {
      $extensions = $value['extensions'] ?? [];

      if (!empty($extensions)) {
        $selected = array_keys(array_filter($extensions));
        $values[$key] = $selected;
      }
    }

    $this->configFactory->getEditable('tailwind.settings')
      ->set('compile_extensions', $values)
      ->save();

    $this->messenger()->addStatus('Configuration saved successfully.');
  }

  protected function appendThemeExtensionConfiguration(array &$form, string $theme, array $extensionOptions, array $sourceExtensions): void {
    $themeInfo = $this->themeExtensionList->getAllAvailableInfo();
    $themeName = $themeInfo[$theme]['name'] ?? $theme;

    $form['theme'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Theme'),
      '#description' => $this->t('Select the file extensions to compile for theme.'),
      '#tree' => TRUE,
    ];

    $form['theme']["theme:$theme"] = ['#type' => 'container'];
    $form['theme']["theme:$theme"]['extensions'] = [
      '#type' => 'checkboxes',
      '#title' => $themeName,
      '#options' => $extensionOptions,
      '#default_value' => $sourceExtensions['theme:' . $theme] ?? [],
    ];
  }

  protected function appendModuleExtensionConfiguration(array &$form, array $modules, array $extensionOptions, array $sourceExtensions): void {
    $moduleInfo = $this->moduleExtensionList->getAllAvailableInfo();

    $form['modules'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Modules'),
      '#description' => $this->t('Select the file extensions to compile for each module.'),
      '#tree' => TRUE,
    ];

    foreach ($modules as $module) {
      $moduleName = $moduleInfo[$module]['name'] ?? $module;

      $form['modules']["module:$module"] = ['#type' => 'container'];
      $form['modules']["module:$module"]['extensions'] = [
        '#type' => 'checkboxes',
        '#title' => $moduleName,
        '#options' => $extensionOptions,
        '#default_value' => $sourceExtensions['module:' . $module] ?? [],
      ];
    }
  }
}
