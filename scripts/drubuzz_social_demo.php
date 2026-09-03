<?php

/**
 * @file
 * Creates one demo Posts node (with a graphic) so the previews can be checked.
 * Run: ddev drush scr scripts/drubuzz_social_demo.php
 */

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

$fs = \Drupal::service('file_system');
$source = DRUPAL_ROOT . '/core/misc/druplicon.png';
$uri = 'public://social-demo-graphic.png';
if (!file_exists($fs->realpath($uri))) {
  $fs->copy($source, $uri, \Drupal\Core\File\FileExists::Replace);
}
$file = File::create(['uri' => $uri, 'status' => 1]);
$file->save();

$media = Media::create([
  'bundle' => 'image',
  'name' => 'Social demo graphic',
  'field_media_image' => ['target_id' => $file->id(), 'alt' => 'DruBuzz launch graphic'],
  'status' => 1,
]);
$media->save();

$node = Node::create([
  'type' => 'posts',
  'title' => 'DruBuzz launch announcement',
  'field_description_x' => "We just shipped DruBuzz \u{1F41D}\n\nPlan, preview and publish the same post to every network from one screen. Try it today.",
  'field_description_linkedin' => "Today we're launching DruBuzz.\n\nSocial teams waste hours reformatting the same update for LinkedIn, X, Instagram, Facebook and Mastodon. DruBuzz lets you write once, preview each network exactly as it will appear, and schedule from a single calendar.\n\nWe'd love your feedback — link in the comments.",
  'field_graphic' => ['target_id' => $media->id()],
  'status' => 1,
  'uid' => 1,
]);
$node->save();

echo "Created node " . $node->id() . ": /node/" . $node->id() . "\n";
