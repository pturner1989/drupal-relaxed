<?php

namespace Drupal\relaxed\Plugin\RemoteCheck;

use Drupal\Core\Site\Settings;
use Drupal\relaxed\Entity\RemoteInterface;
use Drupal\relaxed\Plugin\RemoteCheckBase;

/**
 * @RemoteCheck(
 *   id = "valid_remote",
 *   label = "Valid remote"
 * )
 */
Class ValidRemote extends RemoteCheckBase {

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(RemoteInterface $remote) {
    $url = (string) $remote->uri();
    $client = \Drupal::httpClient();
    $options = [];
    // If self signed certificates are allowed then verify value should be FALSE.
    if (Settings::get('allow_self_signed_certificates', FALSE)) {
      $options = ['verify' => FALSE];
    }
    try {
      $response = $client->request('GET', $url . '/_all_dbs', $options);
      if ($response->getStatusCode() === 200) {
        $databases = json_decode($response->getBody());
        if (!empty($databases)) {
          $this->result = TRUE;
          $this->message = t('Remote is valid.');
        }
        else {
          $this->message = t('Invalid remote');
        }
      }
      else {
        $this->message = t('Remote returns status code @status.', ['@status' => $response->getStatusCode()]);
      }
    }
    catch (\Exception $e) {
      $this->message = $e->getMessage();
      watchdog_exception('relaxed', $e);
    }
  }
}
