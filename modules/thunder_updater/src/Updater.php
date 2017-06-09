<?php

namespace Drupal\thunder_updater;

use Drupal\Component\Utility\NestedArray;
use Drupal\config_update\ConfigRevertInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\MissingDependencyException;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\thunder_updater\Entity\Update;
use Drupal\user\SharedTempStoreFactory;
use Drupal\Component\Utility\DiffArray;
use Drupal\checklistapi\ChecklistapiChecklist;

/**
 * Helper class to update configuration.
 */
class Updater implements UpdaterInterface {
  use StringTranslationTrait;
  /**
   * Site configFactory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Temp store factory.
   *
   * @var \Drupal\user\SharedTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Module installer service.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * Config reverter service.
   *
   * @var \Drupal\config_update\ConfigRevertInterface
   */
  protected $configReverter;

  /**
   * Configuration handler service.
   *
   * @var \Drupal\thunder_updater\ConfigHandler
   */
  protected $configHandler;

  /**
   * The account object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Logger service.
   *
   * @var \Drupal\thunder_updater\UpdateLogger
   */
  protected $logger;

  /**
   * Constructs the PathBasedBreadcrumbBuilder.
   *
   * @param \Drupal\user\SharedTempStoreFactory $tempStoreFactory
   *   A temporary key-value store service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory service.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller
   *   Module installer service.
   * @param \Drupal\config_update\ConfigRevertInterface $configReverter
   *   Config reverter service.
   * @param \Drupal\thunder_updater\ConfigHandler $configHandler
   *   Configuration handler service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\thunder_updater\UpdateLogger $logger
   *   Update logger.
   */
  public function __construct(SharedTempStoreFactory $tempStoreFactory, ConfigFactoryInterface $configFactory, ModuleInstallerInterface $moduleInstaller, ConfigRevertInterface $configReverter, ConfigHandler $configHandler, AccountInterface $account, UpdateLogger $logger) {
    $this->tempStoreFactory = $tempStoreFactory;
    $this->configFactory = $configFactory;
    $this->moduleInstaller = $moduleInstaller;
    $this->configReverter = $configReverter;
    $this->configHandler = $configHandler;
    $this->account = $account;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function logger() {
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function updateEntityBrowserConfig($browser, array $configuration, array $oldConfiguration = []) {

    if ($this->updateConfig('entity_browser.browser.' . $browser, $configuration, $oldConfiguration)) {
      $this->updateTempConfigStorage('entity_browser', $browser, $configuration);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function updateConfig($configName, array $configuration, array $expectedConfiguration = [], array $removeKeys = []) {
    $config = $this->configFactory->getEditable($configName);

    $configData = $config->get();

    // Check that configuration exists before executing update.
    if (empty($configData)) {
      return FALSE;
    }

    // Check if configuration is already in new state.
    $mergedData = NestedArray::mergeDeep($expectedConfiguration, $configuration);
    if (empty(DiffArray::diffAssocRecursive($mergedData, $configData))) {
      return TRUE;
    }

    if (!empty($expectedConfiguration) && DiffArray::diffAssocRecursive($expectedConfiguration, $configData)) {
      return FALSE;
    }

    // Remove configuration keys from config.
    if (!empty($removeKeys)) {
      foreach ($removeKeys as $keyPath) {
        NestedArray::unsetValue($configData, $keyPath);
      }
    }

    $config->setData(NestedArray::mergeDeep($configData, $configuration));
    $config->save();

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function executeUpdate(array $updateDefinitions) {
    $successfulUpdate = TRUE;

    foreach ($updateDefinitions as $configName => $configChange) {
      $expectedConfig = $configChange['expected_config'];
      $updateActions = $configChange['update_actions'];

      // Define configuration keys that should be removed.
      $removeKeys = [];
      if (isset($updateActions['remove'])) {
        $removeKeys = $this->getFlatKeys($updateActions['remove']);
      }

      $newConfig = [];
      // Add configuration that is changed.
      if (isset($updateActions['change'])) {
        $newConfig = NestedArray::mergeDeep($newConfig, $updateActions['change']);
      }

      // Add configuration that is added.
      if (isset($updateActions['add'])) {
        $newConfig = NestedArray::mergeDeep($newConfig, $updateActions['add']);
      }

      if ($this->updateConfig($configName, $newConfig, $expectedConfig, $removeKeys)) {
        $this->logger->info($this->t('Configuration @configName has been successfully updated.', ['@configName' => $configName]));
      }
      else {
        $successfulUpdate = FALSE;
        $this->logger->warning($this->t('Unable to update configuration for @configName.', ['@configName' => $configName]));
      }
    }

    return $successfulUpdate;
  }

  /**
   * Execute list of updates.
   *
   * @param array $updateList
   *   List of modules and updates that should be executed.
   *
   * @return bool
   *   Returns if update execution was successful.
   */
  public function executeUpdates(array $updateList) {
    $updateDefinitions = [];

    foreach ($updateList as $updateEntry) {
      $updateDefinitions = array_merge($updateDefinitions, $this->configHandler->loadUpdate($updateEntry[0], $updateEntry[1]));
    }

    return $this->executeUpdate($updateDefinitions);
  }

  /**
   * Get flatten array keys as list of paths.
   *
   * Example:
   *   $nestedArray = [
   *      'a' => [
   *          'b' => [
   *              'c' => 'c1',
   *          ],
   *          'bb' => 'bb1'
   *      ],
   *      'aa' => 'aa1'
   *   ]
   *
   * Result: [
   *   ['a', 'b', 'c'],
   *   ['a', 'bb']
   *   ['aa']
   * ]
   *
   * @param array $nestedArray
   *   Array with nested keys.
   *
   * @return array
   *   List of flattened keys.
   */
  public function getFlatKeys(array $nestedArray) {
    $keys = [];
    foreach ($nestedArray as $key => $value) {
      if (is_array($value) && !empty($value)) {
        $listOfSubKeys = $this->getFlatKeys($value);

        foreach ($listOfSubKeys as $subKeys) {
          $keys[] = array_merge([$key], $subKeys);
        }
      }
      else {
        $keys[] = [$key];
      }
    }

    return $keys;
  }

  /**
   * Update CTools edit form state.
   *
   * @param string $configType
   *   Configuration type.
   * @param string $configName
   *   Configuration name.
   * @param array $configuration
   *   Configuration what should be set for CTools form.
   */
  protected function updateTempConfigStorage($configType, $configName, array $configuration) {
    $entityBrowserConfig = $this->tempStoreFactory->get($configType . '.config');

    $storage = $entityBrowserConfig->get($configName);

    if (!empty($storage)) {
      foreach ($configuration as $key => $value) {
        $part = $storage[$configType]->getPluginCollections()[$key];

        $part->setConfiguration(NestedArray::mergeDeep($part->getConfiguration(), $value));
      }

      $entityBrowserConfig->set($configName, $storage);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function markUpdatesSuccessful(array $names, $checkListPoints = TRUE) {

    foreach ($names as $name) {

      if ($update = Update::load($name)) {
        $update->setSuccessfulByHook(TRUE)
          ->save();
      }
      else {
        Update::create([
          'id' => $name,
          'successful_by_hook' => TRUE,
        ])->save();
      }
    }

    if ($checkListPoints) {
      $this->checkListPoints($names);
    }

  }

  /**
   * {@inheritdoc}
   */
  public function markUpdatesFailed(array $names) {

    foreach ($names as $name) {

      if ($update = Update::load($name)) {
        $update->setSuccessfulByHook(FALSE)
          ->save();
      }
      else {
        Update::create([
          'id' => $name,
          'successful_by_hook' => FALSE,
        ])->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function markAllUpdates($status = TRUE) {

    $checklist = checklistapi_checklist_load('thunder_updater');

    foreach ($checklist->items as $versionItems) {
      foreach ($versionItems as $key => $item) {

        if (!is_array($item)) {
          continue;
        }

        if ($update = Update::load($key)) {
          $update->setSuccessfulByHook($status)
            ->save();
        }
        else {
          Update::create([
            'id' => $key,
            'successful_by_hook' => $status,
          ])->save();
        }
      }
    }

    $this->checkAllListPoints($status);
  }

  /**
   * Checks an array of bulletpoints on a checklist.
   *
   * @param array $names
   *   Array of the bulletpoints.
   */
  protected function checkListPoints(array $names) {

    /** @var Drupal\Core\Config\Config $thunderUpdaterConfig */
    $thunderUpdaterConfig = $this->configFactory
      ->getEditable('checklistapi.progress.thunder_updater');

    $user = $this->account->id();
    $time = time();

    foreach ($names as $name) {
      if ($thunderUpdaterConfig && !$thunderUpdaterConfig->get(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items.$name")) {

        $thunderUpdaterConfig
          ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items.$name", [
            '#completed' => time(),
            '#uid' => $user,
          ]);

      }
    }

    $thunderUpdaterConfig
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#completed_items', count($thunderUpdaterConfig->get(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items")))
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#changed', $time)
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#changed_by', $user)
      ->save();
  }

  /**
   * Checks all the bulletpoints on a checklist.
   *
   * @param bool $status
   *   Checkboxes enabled or disabled.
   */
  protected function checkAllListPoints($status = TRUE) {

    /** @var Drupal\Core\Config\Config $thunderUpdaterConfig */
    $thunderUpdaterConfig = $this->configFactory
      ->getEditable('checklistapi.progress.thunder_updater');

    $user = $this->account->id();
    $time = time();

    $thunderUpdaterConfig
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#changed', $time)
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#changed_by', $user);

    $checklist = checklistapi_checklist_load('thunder_updater');

    $exclude = [
      '#title',
      '#description',
      '#weight',
    ];

    foreach ($checklist->items as $versionItems) {
      foreach ($versionItems as $itemName => $item) {
        if (!in_array($itemName, $exclude)) {
          if ($status) {
            $thunderUpdaterConfig
              ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items.$itemName", [
                '#completed' => $time,
                '#uid' => $user,
              ]);
          }
          else {
            $thunderUpdaterConfig
              ->clear(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items.$itemName");
          }
        }
      };
    }

    $thunderUpdaterConfig
      ->set(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . '.#completed_items', count($thunderUpdaterConfig->get(ChecklistapiChecklist::PROGRESS_CONFIG_KEY . ".#items")))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function installModules(array $modules) {

    $successful = [];

    foreach ($modules as $update => $module) {
      try {
        if ($this->moduleInstaller->install([$module])) {
          $successful[] = $update;

          $this->logger->info($this->t('Module @module is successfully enabled.', ['@module' => $module]));
        }
        else {
          $this->logger->warning($this->t('Unable to enable @module.', ['@module' => $module]));
          $this->markUpdatesFailed([$update]);
        }
      }
      catch (MissingDependencyException $e) {
        $this->markUpdatesFailed([$update]);
        $this->logger->warning($this->t('Unable to enable @module because of missing dependencies.', ['@module' => $module]));
      }
    }
    $this->markUpdatesSuccessful($successful);
  }

  /**
   * List of full configuration names to import.
   *
   * @param array $configList
   *   List of configurations.
   *
   * @return bool
   *   Returns if import was successful.
   */
  public function importConfigs(array $configList) {
    $successfulImport = TRUE;

    // Import configurations.
    foreach ($configList as $fullConfigName) {
      try {
        $configName = ConfigName::createByFullName($fullConfigName);

        $this->configReverter->import($configName->getType(), $configName->getName());
        $this->logger->info($this->t('Configuration @full_name has been successfully imported.', [
          '@full_name' => $fullConfigName,
        ]));
      }
      catch (\Exception $e) {
        $successfulImport = FALSE;

        $this->logger->warning($this->t('Unable to import @full_name config.', [
          '@full_name' => $fullConfigName,
        ]));
      }
    }

    return $successfulImport;
  }

}
