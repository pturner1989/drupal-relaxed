<?php

namespace Drupal\relaxed\Plugin\rest\resource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\multiversion\Entity\WorkspaceInterface;
use Drupal\rest\ResourceResponse;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * @RestResource(
 *   id = "relaxed:db",
 *   label = "Workspace",
 *   serialization_class = {
 *     "canonical" = "Drupal\multiversion\Entity\WorkspaceInterface",
 *     "post" = "Drupal\Core\Entity\ContentEntityInterface",
 *   },
 *   uri_paths = {
 *     "canonical" = "/{db}",
 *   }
 * )
 */
class DbResource extends ResourceBase {

  /**
   * @param $entity
   *
   * @return ResourceResponse
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function head($entity) {
    if (!$entity instanceof WorkspaceInterface) {
      throw new NotFoundHttpException();
    }
    return new ResourceResponse(NULL, 200);
  }

  /**
   * @param $entity
   *
   * @return ResourceResponse
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function get($entity) {
    if (!$entity instanceof WorkspaceInterface) {
      throw new NotFoundHttpException();
    }
    // @todo: Access check.
    $response =  new ResourceResponse($entity, 200);
    $response->addCacheableDependency($entity);

    return $response;
  }

  /**
   * @param $name
   *
   * @return ResourceResponse
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException
   */
  public function put($name) {
    // If the name parameter was upcasted to an entity it means it an entity
    // already exists.
    if ($name instanceof WorkspaceInterface) {
      throw new PreconditionFailedHttpException(t('The database could not be created, it already exists'));
    }
    elseif (!is_string($name)) {
      throw new BadRequestHttpException(t('Database name is missing'));
    }

    try {
      // @todo Consider using the container injected in parent::create()
      $entity = \Drupal::service('entity.manager')
        ->getStorage('workspace')
        ->create(array('id' => $name))
        ->save();
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, t('Internal server error'), $e);
    }
    return new ResourceResponse(array('ok' => TRUE), 201);
  }

  /**
   * @param $workspace
   * @param ContentEntityInterface $entity
   *
   * @return ResourceResponse
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  public function post($workspace, ContentEntityInterface $entity = NULL) {
    // If the workspace parameter is a string it means it could not be upcasted
    // to an entity because none existed.
    if (is_string($workspace)) {
      throw new NotFoundHttpException(t('Database does not exist')); 
    }
    elseif (empty($entity)) {
      throw new BadRequestHttpException(t('No content received'));
    }
    $uuid = $entity->uuid();

    // Check for conflicts.
    /*if ($uuid) {
      $entry = \Drupal::service('entity.index.uuid')->get($uuid);
      if (!empty($entry)) {
        throw new ConflictHttpException();
      }
    }*/

    // Check entity and field level access.
    if (!$entity->access('create')) {
      throw new AccessDeniedHttpException();
    }
    foreach ($entity as $field_name => $field) {
      if ($entity instanceof UserInterface) {
        // For user fields we need to check 'edit' permissions.
        if (!$field->access('edit')) {
          throw new AccessDeniedHttpException(t('Access denied on creating field @field.', array('@field' => $field_name)));
        }
      }
      elseif (!$field->access('create')) {
        throw new AccessDeniedHttpException(t('Access denied on creating field @field.', array('@field' => $field_name)));
      }
    }

    // This will save stub entities in case the entity has entity reference
    // fields and a referenced entity does not exist or will update stub
    // entities with the correct values.
    \Drupal::service('relaxed.stub_entity_processor')->processEntity($entity);

    // Validate the received data before saving.
    $this->validate($entity);
    try {
      $entity->save();
      $rev = $entity->_rev->value;
      return new ResourceResponse(array('ok' => TRUE, 'id' => $uuid, 'rev' => $rev), 201, array('ETag' => $rev));
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, NULL, $e);
    }
  }

  /**
   * @param WorkspaceInterface $entity
   *
   * @return ResourceResponse
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function delete(WorkspaceInterface $entity) {
    try {
      // @todo: Access check.
      $entity->delete();
    }
    catch (\Exception $e) {
      throw new HttpException(500, NULL, $e);
    }
    return new ResourceResponse(array('ok' => TRUE), 200);
  }
}
