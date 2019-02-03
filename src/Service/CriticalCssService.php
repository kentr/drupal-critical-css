<?php

namespace Drupal\critical_css\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountProxy;

/**
 * Gets a node's critical CSS.
 */
/**
 * Class CriticalCssService.
 *
 * @package Drupal\critical_css\Service
 */
class CriticalCssService {

  /**
   * Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Current Route Match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * Current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPathStack;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Flag set when this request has already been processed.
   *
   * @var bool
   */
  protected $alreadyProcessed;

  /**
   * Critical CSS data to be inlined.
   *
   * @var string
   */
  protected $criticalCss;

  /**
   * Possible file paths to find css contents.
   *
   * @var array
   */
  protected $filePaths = [];

  /**
   * File used for critical css.
   *
   * @var string
   */
  protected $matchedFilePath;

  /**
   * CriticalCssService constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Request.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   Config Factory.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $currentRouteMatch
   *   Current route.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPathStack
   *   Current path.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *   Current user.
   */
  public function __construct(
    RequestStack $requestStack,
    ConfigFactory $configFactory,
    CurrentRouteMatch $currentRouteMatch,
    CurrentPathStack $currentPathStack,
    AccountProxy $currentUser
  ) {
    $this->request = $requestStack->getCurrentRequest();
    $this->configFactory = $configFactory;
    $this->currentRouteMatch = $currentRouteMatch;
    $this->currentPathStack = $currentPathStack;
    $this->currentUser = $currentUser;
  }

  /**
   * Get critical css contents.
   *
   * @return string
   *   The critical css contents
   */
  public function getCriticalCss() {

    // Return previous result, if any.
    if ($this->isAlreadyProcessed() && $this->criticalCss) {
      return $this->criticalCss;
    }

    $this->setAlreadyProcessed(TRUE);

    // Get possible file paths and return first match.
    $filePaths = $this->getFilePaths();
    foreach ($filePaths as $filePath) {
      if (is_file($filePath)) {
        $this->criticalCss = trim(file_get_contents($filePath));
        $this->matchedFilePath = $filePath;
        break;
      }
    }
    return $this->criticalCss;
  }

  /**
   * Tells if this request has already been processed.
   *
   * @return bool
   *   True if this request has already been processed.
   */
  public function isAlreadyProcessed() {
    return $this->alreadyProcessed;
  }

  /**
   * Set that this request has already been processed.
   *
   * @param bool $alreadyProcessed
   *   True or false.
   */
  protected function setAlreadyProcessed($alreadyProcessed) {
    $this->alreadyProcessed = $alreadyProcessed;
  }

  /**
   * Check if module is enabled.
   *
   * @return bool
   *   True if this module is enabled
   */
  public function isEnabled() {
    $config = $this->configFactory->get('critical_css.settings');
    return (bool) $config->get('enabled');
  }

  /**
   * Check if module is enabled for logged-in users.
   *
   * @return bool
   *   True if this module is enabled for logged-in users.
   */
  public function isEnabledForLoggedInUsers() {
    $config = $this->configFactory->get('critical_css.settings');
    return (bool) $config->get('enabled_for_logged_in_users');
  }

  /**
   * Check if entity id is excluded by configuration.
   *
   * @param int $entityId
   *   Entity ID (integer).
   *
   * @return bool
   *   True if entity is excluded.
   */
  public function isEntityIdExcluded($entityId) {
    $config = $this->configFactory->get('critical_css.settings');
    $excludedIds = explode("\n", $config->get('excluded_ids'));
    $excludedIds = array_map(function ($item) {
      return trim($item);
    }, $excludedIds);
    return (
      is_array($excludedIds) &&
      in_array($entityId, $excludedIds)
    );
  }

  /**
   * Get critical css file path by a key (id, string, etc).
   *
   * @param string $key
   *   Key to search.
   *
   * @return string
   *   Critical css string.
   */
  public function getFilePathByKey($key) {
    if (empty($key)) {
      return NULL;
    }

    $themeName = $this->configFactory->get('system.theme')->get('default');
    $themePath = drupal_get_path('theme', $themeName);
    $criticalCssDirPath = str_replace(
      '..',
      '',
      $this->configFactory->get('critical_css.settings')->get('dir_path')
    );
    $criticalCssDir = $themePath . $criticalCssDirPath;

    return $criticalCssDir . '/' . $key . '.css';
  }

  /**
   * Get all possible paths to search, relatives to theme.
   *
   * @return array
   *   Array with all possible paths.
   */
  public function getFilePaths() {
    // Check if module is enabled.
    if (!$this->isEnabled()) {
      // Empty array.
      return $this->filePaths;
    }

    // Check if module is enabled for logged-in users.
    if (!$this->currentUser->isAnonymous() && !$this->isEnabledForLoggedInUsers()) {
      // Empty array.
      return $this->filePaths;
    }

    // Return previous result, if any.
    if ($this->isAlreadyProcessed() && count($this->filePaths)) {
      return $this->filePaths;
    }

    $this->setAlreadyProcessed(TRUE);

    $entity = NULL;
    $entityId = NULL;
    $bundleName = NULL;
    $sanitizedPath = NULL;
    $sanitizedPathInfo = NULL;

    // Get current entity's data
    // Try node and taxonomy_term.
    $entitiesToTry = ['node', 'taxonomy_term'];
    foreach ($entitiesToTry as $entityToTry) {
      $entity = $this->currentRouteMatch->getParameter($entityToTry);
      if ($entity) {
        break;
      }
    }

    if ($entity) {
      $entityId = $entity->id();
      $bundleName = $entity->bundle();
    }

    // Get $sanitizedPath.
    $currentPath = $this->currentPathStack->getPath();
    $sanitizedPath = preg_replace("/^\//", "", $currentPath);
    $sanitizedPath = preg_replace("/[^a-zA-Z0-9\/-]/", "", $sanitizedPath);
    $sanitizedPath = str_replace("/", "-", $sanitizedPath);
    if (empty($sanitizedPath)) {
      $sanitizedPath = 'front';
    }

    // Get $sanitizedPathInfo.
    $requestUri = $this->request->getPathInfo();
    $sanitizedPathInfo = preg_replace("/^\//", "", $requestUri);
    $sanitizedPathInfo = preg_replace("/[^a-zA-Z0-9\/-]/", "", $sanitizedPathInfo);
    $sanitizedPathInfo = str_replace("/", "-", $sanitizedPathInfo);
    if (empty($sanitizedPathInfo)) {
      $sanitizedPathInfo = 'front';
    }

    // Check if this entity id is excluded.
    if ($entityId && $this->isEntityIdExcluded($entityId)) {
      return $this->filePaths;
    }

    // Get file paths by entity id.
    $filePathByEntityId = $this->getFilePathByKey($entityId);
    if (!in_array($filePathByEntityId, $this->filePaths)) {
      $this->filePaths[] = $filePathByEntityId;
    }

    // Get file paths by $sanitizedPath.
    $filePathBySanitizedPath = $this->getFilePathByKey($sanitizedPath);
    if (!in_array($filePathBySanitizedPath, $this->filePaths)) {
      $this->filePaths[] = $filePathBySanitizedPath;
    }

    // Get file paths by $sanitizedPathInfo.
    $filePathBySanitizedPathInfo = $this->getFilePathByKey($sanitizedPathInfo);
    if (!in_array($filePathBySanitizedPathInfo, $this->filePaths)) {
      $this->filePaths[] = $filePathBySanitizedPathInfo;
    }

    // Get file paths by $bundleName.
    if ($filePathByBundleName = $this->getFilePathByKey($bundleName)) {
      $this->filePaths[] = $filePathByBundleName;
    }

    // Search for default fallback CSS
    if ($filePathDefault = $this->getFilePathByKey('critical-css-default')) {
      $this->filePaths[] = $filePathDefault;
    }

    return $this->filePaths;
  }

  /**
   * Get matched file path.
   *
   * @return string|null
   *   Matched file path, or null if nothing found.
   */
  public function getMatchedFilePath() {
    // Ensure $this->getCriticalCss() is called before returning anything.
    if (!$this->isAlreadyProcessed()) {
      $this->getCriticalCss();
    }

    return $this->matchedFilePath;
  }

}
