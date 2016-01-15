<?php

namespace Drupal\relaxed\Normalizer;

use Drupal\Component\Utility\Random;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\file\Entity\File;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\multiversion\Entity\Index\RevisionTreeIndexInterface;
use Drupal\multiversion\Entity\Index\UuidIndexInterface;
use Drupal\rest\LinkManager\LinkManagerInterface;
use Drupal\file\FileInterface;
use Drupal\serialization\Normalizer\NormalizerBase;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class ContentEntityNormalizer extends NormalizerBase implements DenormalizerInterface {

  /**
   * @var string[]
   */
  protected $supportedInterfaceOrClass = array('Drupal\Core\Entity\ContentEntityInterface');

  /**
   * @var \Drupal\multiversion\Entity\Index\UuidIndexInterface
   */
  protected $uuidIndex;

  /**
   * @var \Drupal\multiversion\Entity\Index\RevisionTreeIndexInterface
   */
  protected $revTree;

  /**
   * @var \Drupal\rest\LinkManager\LinkManagerInterface
   */
  protected $linkManager;

  /**
   * @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface
   */
  protected $selectionManager;

  /**
   * @var string[]
   */
  protected $format = array('json');

  /**
   * @var int
   */
  protected $entity_id = null;

  /**
   * @var string
   */
  protected $entity_uuid = null;

  /**
   * @var string
   */
  protected $id_key = '';

  /**
   * @var string
   */
  protected $entity_type_id = '';

  /**
   * @var string
   */
  protected $bundle_key = '';

  /**
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entity_type;

  /**
   * @var array
   */
  protected $files = [];

  /**
   * @var string
   */
  protected $rev;

  /**
   * @var array
   */
  protected $revisions = [];

  /**
   * @var array
   */
  protected $existing_users_names = [];

  /**
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   * @param \Drupal\multiversion\Entity\Index\UuidIndexInterface $uuid_index
   * @param \Drupal\multiversion\Entity\Index\RevisionTreeIndexInterface $rev_tree
   * @param \Drupal\rest\LinkManager\LinkManagerInterface $link_manager
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection_manager
   */
  public function __construct(EntityManagerInterface $entity_manager, UuidIndexInterface $uuid_index, RevisionTreeIndexInterface $rev_tree, LinkManagerInterface $link_manager, LanguageManagerInterface $language_manager, SelectionPluginManagerInterface $selection_manager = NULL) {
    $this->entityManager = $entity_manager;
    $this->uuidIndex = $uuid_index;
    $this->revTree = $rev_tree;
    $this->linkManager = $link_manager;
    $this->languageManager = $language_manager;
    $this->selectionManager = $selection_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = array()) {
    $this->entity_type_id = $context['entity_type'] = $entity->getEntityTypeId();
    $this->entity_type = $this->entityManager->getDefinition($this->entity_type_id);

    $id_key = $this->entity_type->getKey('id');
    $revision_key = $this->entity_type->getKey('revision');
    $uuid_key = $this->entity_type->getKey('uuid');

    $this->entity_uuid = $entity->uuid();
    $entity_default_language = $entity->language();
    $entity_languages = $entity->getTranslationLanguages();

    // Create the basic data array with JSON-LD data.
    $data = array(
      '@context' => array(
        '_id' => '@id',
        $this->entity_type_id => $this->linkManager->getTypeUri($this->entity_type_id, $entity->bundle()),
        '@language' => $entity_default_language->getId(),
      ),
      '@type' => $this->entity_type_id,
      '_id' => $this->entity_uuid,
    );

    // New or mocked entities might not have a rev yet.
    if (!empty($entity->_rev->value)) {
      $data['_rev'] = $entity->_rev->value;
    }

    // Loop through each language of the entity
    $field_definitions = $entity->getFieldDefinitions();
    foreach ($entity_languages as $entity_language) {
      $translation = $entity->getTranslation($entity_language->getId());
      // Add the default language
      $data[$entity_language->getId()] =
        [
          '@context' => [
            '@language' => $entity_language->getId(),
          ]
        ];
      foreach ($translation as $name => $field) {
        // Add data for each field (through the field's normalizer.
        $field_type = $field_definitions[$name]->getType();
        $items = $this->serializer->normalize($field, $format, $context);
        // Add file and image field types into _attachments key.
        if ($field_type == 'file' || $field_type == 'image') {
          if ($items !== NULL) {
            if (!isset($data['_attachments']) && !empty($items)) {
              $data['_attachments'] = array();
            }
            foreach ($items as $item) {
              $data['_attachments'] = array_merge($data['_attachments'], $item);
            }
          }
          continue;
        }
        if ($field_type == 'password') {
          continue;
        }

        if ($items !== NULL) {
          $data[$entity_language->getId()][$name] = $items;
        }
      }
      // Override the normalization for the _deleted special field, just so that we
      // follow the API spec.
      if (isset($translation->_deleted->value) && $translation->_deleted->value == TRUE) {
        $data[$entity_language->getId()]['_deleted'] = TRUE;
        $data['_deleted'] = TRUE;
      }
      elseif (isset($data[$entity_language->getId()]['_deleted'])) {
        unset($data[$entity_language->getId()]['_deleted']);
      }
    }

    // @todo: {@link https://www.drupal.org/node/2599938 Needs test.}
    if (!empty($context['query']['revs']) || !empty($context['query']['revs_info'])) {
      $default_branch = $this->revTree->getDefaultBranch($this->entity_uuid);

      $i = 0;
      foreach (array_reverse($default_branch) as $rev => $status) {
        // Build data for _revs_info.
        if (!empty($context['query']['revs_info'])) {
          $data['_revs_info'][] = array('rev' => $rev, 'status' => $status);
        }
        if (!empty($context['query']['revs'])) {
          list($start, $hash) = explode('-', $rev);
          $data['_revisions']['ids'][] = $hash;
          if ($i == 0) {
            $data['_revisions']['start'] = (int) $start;
          }
        }
        $i++;
      }
    }

    if (!empty($context['query']['conflicts'])) {
      $conflicts = $this->revTree->getConflicts($this->entity_uuid);
      foreach ($conflicts as $rev => $status) {
        $data['_conflicts'][] = $rev;
      }
    }

    // Finally we remove certain fields that are "local" to this host.
    unset($data['workspace'], $data[$id_key], $data[$revision_key], $data[$uuid_key]);
    foreach ($entity_languages as $entity_language) {
      $langcode = $entity_language->getId();
      unset($data[$langcode]['workspace'], $data[$langcode][$id_key], $data[$langcode][$revision_key], $data[$langcode][$uuid_key]);
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    // Make sure these values start as NULL
    $this->entity_type_id = NULL;
    $this->entity_uuid = NULL;
    $this->entity_id = NULL;

    // Get the default language of the entity
    $default_langcode = $data['@context']['@language'];
    // Get all of the configured languages of the site
    $site_languages = $this->languageManager->getLanguages();

    // Resolve the UUID.
    if (empty($this->entity_uuid) && !empty($data['_id'])) {
      $this->entity_uuid = $data['_id'];
    }
    else {
      throw new UnexpectedValueException('The uuid value is missing.');
    }

    // Resolve the entity type ID.
    if (isset($data['@type'])) {
      $this->entity_type_id = $data['@type'];
    }
    elseif (!empty($context['entity_type'])) {
      $this->entity_type_id = $context['entity_type'];
    }

    // Map data from the UUID index.
    // @todo: {@link https://www.drupal.org/node/2599938 Needs test.}
    if (!empty($this->entity_uuid)) {
      if ($record = $this->uuidIndex->get($this->entity_uuid)) {
        $this->entity_id = $record['entity_id'];
        if (empty($this->entity_type_id)) {
          $this->entity_type_id = $record['entity_type_id'];
        }
        elseif ($this->entity_type_id != $record['entity_type_id']) {
          throw new UnexpectedValueException('The entity_type value does not match the existing UUID record.');
        }
      }
    }

    if (empty($this->entity_type_id)) {
      throw new UnexpectedValueException('The entity_type value is missing.');
    }

    // Add the _rev field to the $data array.
    if (isset($data['_rev'])) {
      $this->rev = $data['_rev'];
    }
    if (isset($data['_revisions']['start']) && isset($data['_revisions']['ids'])) {
      $this->revisions = $data['_revisions'];
    }

    $this->entity_type = $this->entityManager->getDefinition($this->entity_type_id);
    $this->id_key = $this->entity_type->getKey('id');
    $revision_key = $this->entity_type->getKey('revision');
    $this->bundle_key = $this->entity_type->getKey('bundle');

    // Denormalize File and Image field types.
    if (isset($data['_attachments'])) {
      foreach ($data['_attachments'] as $key => $value) {
        list($field_name, $delta, $file_uuid, $scheme, $filename) = explode('/', $key);
        $uri = "$scheme://$filename";
        // Check if exists a file with this uuid.
        $file = $this->entityManager->loadEntityByUuid('file', $file_uuid);
        if (!$file) {
          // Check if exists a file with this $uri, if it exists then
          // change the URI and save the new file.
          $existing_files = entity_load_multiple_by_properties('file', array('uri' => $uri));
          if (count($existing_files)) {
            $uri = file_destination($uri, FILE_EXISTS_RENAME);
          }
          $file_context = array(
            'uri' => $uri,
            'uuid' => $file_uuid,
            'status' => FILE_STATUS_PERMANENT,
            'uid' => \Drupal::currentUser()->id(),
          );
          $file = \Drupal::getContainer()->get('serializer')->deserialize($value['data'], '\Drupal\file\FileInterface', 'base64_stream', $file_context);
          if ($file instanceof FileInterface) {
            $this->files[$field_name][$delta] = [
              'target_id' => NULL,
              'entity' => $file,
            ];
          }
          continue;
        }
        $this->files[$field_name][$delta]['target_id'] = $file->id();
      }
    }

    // For the user entity type set a random name if an user with the same name
    // already exists in the database.
    if ($this->entity_type_id == 'user') {
      $query = db_select('users', 'u');
      $query->fields('u', ['uuid']);
      $query->join('users_field_data', 'ufd', 'u.uid = ufd.uid');
      $query->fields('ufd', ['name']);
      $this->existing_users_names = $query->execute()->fetchAllKeyed(1, 0);
    }

    $translations = [];
    foreach ($data as $key => $item) {
      // Skip any keys that start with '_' or '@'.
      if (in_array($key{0}, ['_', '@'])) {
        continue;
      }
      // When language is configured or undefined go ahead with denormalization.
      elseif (isset($site_languages[$key]) || $key === 'und') {
        $translations[$key] = $this->denormalizeTranslation($item);
      }
      // Configure then language then do denormalization.
      else {
        $language = ConfigurableLanguage::createFromLangcode($key);
        $language->save();
        $translations[$key] = $this->denormalizeTranslation($item);
      }
    }

    // @todo {@link https://www.drupal.org/node/2599946 Move the below update
    // logic to the resource plugin instead.}
    $storage = $this->entityManager->getStorage($this->entity_type_id);


    // @todo {@link https://www.drupal.org/node/2599926 Use the passed $class to instantiate the entity.}

    if ($this->entity_id) {
      if ($entity = $storage->load($this->entity_id) ?: $storage->loadDeleted($this->entity_id)) {
        if (!empty($translations[$entity->language()->getId()])) {
          foreach ($translations[$entity->language()->getId()] as $name => $value) {
            if ($name == 'default_langcode') {
              continue;
            }
            $entity->{$name} = $value;
          }
        }
      }
      elseif (isset($translations[$default_langcode][$this->id_key])) {
        unset($translations[$default_langcode][$this->id_key], $translations[$default_langcode][$revision_key]);
        $entity_id = NULL;
        $entity = $storage->create($translations[$default_langcode]);
      }

      foreach ($site_languages as $site_language) {
        $langcode = $site_language->getId();
        if ($entity->language()->getId() != $langcode) {
          $entity->addTranslation($langcode, $translations[$langcode]);
        }
      }
    }
    else {
      $entity = NULL;
      $this->entity_types_to_create = ['user'];
      if (!empty($this->bundle_key) && !empty($translations[$default_langcode][$this->bundle_key]) || in_array($this->entity_type_id, $this->entity_types_to_create)) {
        unset($translations[$default_langcode][$this->id_key], $translations[$default_langcode][$revision_key]);
        $entity = $storage->create($translations[$default_langcode]);
      }
    }

    if ($this->entity_id) {
      $entity->enforceIsNew(FALSE);
      $entity->setNewRevision(FALSE);
      $entity->_rev->is_stub = FALSE;
    }

    Cache::invalidateTags(array($this->entity_type_id . '_list'));

    return $entity;
  }

  /**
   * @param $translation
   * @return mixed
   */
  private function denormalizeTranslation($translation) {
    // Add the _rev field to the $translation array.
    if (isset($this->rev)) {
      $translation['_rev'] = array(array('value' => $this->rev));
    }
    if (isset($this->revisions['start']) && isset($this->revisions['ids'])) {
      $translation['_rev'][0]['revisions'] = $this->revisions['ids'];
    }
    if (isset($this->entity_uuid)) {
      $translation['uuid'][0]['value'] = $this->entity_uuid;
    }

    // We need to nest the data for the _deleted field in its Drupal-specific
    // structure since it's un-nested to follow the API spec when normalized.
    // @todo {@link https://www.drupal.org/node/2599938 Needs test for situation when a replication overwrites delete.}
    $deleted = isset($translation['_deleted']) ? $translation['_deleted'] : FALSE;
    $translation['_deleted'] = array(array('value' => $deleted));

    if ($this->entity_id) {
      // @todo {@link https://www.drupal.org/node/2599938 Needs test.}
      $translation[$this->id_key] = $this->entity_id;
    }

    $bundle_id = $this->entity_type_id;
    if ($this->entity_type->hasKey('bundle')) {
      if (!empty($translation[$this->bundle_key][0]['value'])) {
        // Add bundle info when entity is not new.
        $bundle_id = $translation[$this->bundle_key][0]['value'];
        $translation[$this->bundle_key] = $bundle_id;
      }
      elseif (!empty($translation[$this->bundle_key][0]['target_id'])) {
        // Add bundle info when entity is new.
        $bundle_id = $translation[$this->bundle_key][0]['target_id'];
        $translation[$this->bundle_key] = $bundle_id;
      }
    }

    if ($this->entity_type_id === 'user') {
      $random = new Random();
      if (empty($translation['name'][0]['value'])) {
        $translation['name'][0]['value'] = 'anonymous_' . $random->name(8, TRUE);
      }
      else {
        $name = $translation['name'][0]['value'];
        if (in_array($name, array_keys($this->existing_users_names)) && $this->existing_users_names[$name] != $this->entity_uuid) {
          $translation['name'][0]['value'] = $name . '_' . $random->name(8, TRUE);
        }
      }
    }

    if (!empty($this->files)) {
      array_merge($translation, $this->files);
    }

    // Denormalize entity reference fields.
      foreach ($translation as $field_name => $field_info) {
        if (!is_array($field_info)) {
          continue;
        }
        foreach ($field_info as $delta => $item) {
          if (isset($item['target_uuid'])) {
            $fields = $this->entityManager->getFieldDefinitions($this->entity_type_id, $bundle_id);
            // Figure out what bundle we should use when creating the stub.
            $settings = $fields[$field_name]->getSettings();

            // Find the target entity type and target bundle IDs and figure out if
            // the referenced entity exists or not.
            $target_entity_uuid = $item['target_uuid'];
            $target_entity_type_id = $settings['target_type'];

            if (isset($settings['handler_settings']['target_bundles'])) {
              $target_bundle_id = reset($settings['handler_settings']['target_bundles']);
            }
            else {
              // @todo: Update when {@link https://www.drupal.org/node/2412569
              // this setting is configurable}.
              $bundles = $this->entityManager->getBundleInfo($target_entity_type_id);
              $target_bundle_id = key($bundles);
            }
            $target_storage = $this->entityManager->getStorage($target_entity_type_id);
            $target_entity = $target_storage->loadByProperties(['uuid' => $target_entity_uuid]);
            $target_entity = !empty($target_entity) ? reset($target_entity) : NULL;

            if ($target_entity) {
              $translation[$field_name][$delta] = array(
                'target_id' => $target_entity->id(),
              );
            }
            // If the target entity doesn't exist we need to create a stub entity
            // in its place to ensure that the replication continues to work.
            // The stub entity will be updated when it's full entity comes around
            // later in the replication.
            else {
              $options = [
                'target_type' => $target_entity_type_id,
                'handler_settings' => $settings['handler_settings'],
              ];
              /** @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionWithAutocreateInterface $selection_instance */
              $selection_instance = $this->selectionManager->getInstance($options);
              // We use a temporary label and entity owner ID as this will be
              // backfilled later anyhow, when the real entity comes around.
              $target_entity = $selection_instance
                ->createNewEntity($target_entity_type_id, $target_bundle_id, rand(), 1);

              // Set the UUID to what we received to ensure it gets updated when
              // the full entity comes around later.
              $target_entity->uuid->value = $target_entity_uuid;
              // Indicate that this revision is a stub.
              $target_entity->_rev->is_stub = TRUE;

              // Populate the data field.
              $translation[$field_name][$delta] = array(
                'target_id' => NULL,
                'entity' => $target_entity,
              );
            }
          }
        }
      }


    // Exclude "name" field (the user name) for comment entity type because
    // we'll change it during replication if it's a duplicate.
    if ($this->entity_type_id == 'comment' && isset($translation['name'])) {
      unset($translation['name']);
    }

    // Clean-up attributes we don't needs anymore.
    // Remove changed info, otherwise we can get validation errors when the
    // 'changed' value for existing entity is higher than for the new entity (revision).
    // @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator::validate().
    foreach (array('@context', '@type', '_id', '_attachments', '_revisions', 'changed') as $key) {
      if (isset($translation[$key])) {
        unset($translation[$key]);
      }
    }

    return $translation;
  }

}
