<?php

namespace Drupal\relaxed\Tests;

use Drupal\Component\Serialization\Json;
use Drupal\multiversion\Entity\Workspace;

/**
 * Tests the /_all_dbs resource.
 *
 * @group relaxed
 */
class AllDbsResourceTest extends ResourceTestBase {

  public function testGet() {
    // Create a user with the correct permissions.
    $permissions[] = 'perform pull replication';
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    $workspaces = [];
    foreach (Workspace::loadMultiple() as $workspace) {
      $workspaces[] = $workspace->getMachineName();
    }

    for ($i = 0; $i < 3; $i++) {
      $machine_name = $this->randomMachineName();
      $entity = Workspace::create(['machine_name' => $machine_name, 'type' => 'basic']);
      $entity->save();
      if ($i % 2 == 0) {
        $entity->setUnpublished()->save();
        continue;
      }
      $workspaces[] = $machine_name;
    }

    $response = $this->httpRequest('_all_dbs', 'GET');
    $this->assertResponse('200', 'HTTP response code is correct.');
    $this->assertHeader('content-type', $this->defaultMimeType);
    $data = Json::decode($response);
    sort($data);
    sort($workspaces);
    $this->assertEqual($data, $workspaces, 'All workspaces names received.');
  }

}
