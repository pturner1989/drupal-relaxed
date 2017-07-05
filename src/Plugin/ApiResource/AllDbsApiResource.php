<?php

namespace Drupal\relaxed\Plugin\ApiResource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\rest\ResourceResponse;
use Drupal\multiversion\Entity\Workspace;

/**
 * Implements http://docs.couchdb.org/en/latest/api/server/common.html#all-dbs
 */

/**
 * @ApiResource(
 *   id = "all_dbs",
 *   label = "All Workspaces",
 *   serialization_class = {
 *     "canonical" = "Drupal\multiversion\Entity\Workspace",
 *   },
 *   path = "/_all_dbs"
 * )
 */
class AllDbsApiResource extends ApiResourceBase {

  /**
   * Retrieve list of all entity types.
   *
   * @return \Drupal\rest\ResourceResponse
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function get() {
    /** @var \Drupal\multiversion\Entity\WorkspaceInterface[] $workspaces */
    $workspaces = Workspace::loadMultiple();

    $workspace_machine_names = [];
    foreach ($workspaces as $workspace) {
      if ($workspace->isPublished()) {
        $workspace_machine_names[] = $workspace->getMachineName();
      }
    }

    $response = new ResourceResponse($workspace_machine_names, 200);
    foreach ($workspaces as $workspace) {
      if ($workspace->isPublished()) {
        $response->addCacheableDependency($workspace);
      }
    }
    $workspace_entity_type = \Drupal::entityTypeManager()->getDefinition('workspace');
    $response->addCacheableDependency((new CacheableMetadata())
      ->addCacheTags($workspace_entity_type->getListCacheTags())
      ->addCacheContexts($workspace_entity_type->getListCacheContexts()));

    return $response;
  }

}
