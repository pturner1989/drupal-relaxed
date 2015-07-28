#!/bin/sh

set -ev
DRUPAL_ROOT=$1
DRUPAL_DOMAIN=$2

# Enable dependencies.
cp -R $DRUPAL_ROOT/core/modules/system/tests/modules/entity_test $DRUPAL_ROOT/modules/
cp -R $DRUPAL_ROOT/modules/relaxed/tests/modules/relaxed_test $DRUPAL_ROOT/modules/

drush en --yes entity_test, relaxed_test || true

# Create a target database and do the replication.
curl -X PUT localhost:5984/source

# Load documents from documents.txt and save them in the 'source' database.
while read document
do
  curl -X POST \
       -H "Content-Type: application/json" \
       -d "$document" \
       localhost:5984/source;
  sleep 2;
done < $DRUPAL_ROOT/modules/relaxed/tests/fixtures/documents.txt

# Get all docs from couchdb db.
curl -X GET http://localhost:5984/source/_all_docs

drush cr

# Run the replication.
nohup curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" -d '{"source": "http://localhost:5984/source", "target": "http://$DRUPAL_DOMAIN/relaxed/default", "worker_processes": 1}' http://localhost:5984/_replicate &
sleep 120

curl -X GET http://$DRUPAL_DOMAIN/relaxed/default/_all_docs | tee /tmp/all_docs.txt

<<<<<<< HEAD
COUNT=$(wc -l < $DRUPAL_ROOT/modules/relaxed/tests/fixtures/documents.txt)
=======
#-----------------------------------
sudo cat /var/log/couchdb/couch.log
#-----------------------------------
sudo cat /var/log/apache2/error.log
#-----------------------------------

COUNT=$(wc -l < $TRAVIS_BUILD_DIR/tests/fixtures/documents.txt)
>>>>>>> Show log files content after couuch-to-drupal replication.
USERS=2
COUNT=$(($COUNT + $USERS));
test 1 -eq $(egrep -c "(\"total_rows\"\:$COUNT)" /tmp/all_docs.txt)
