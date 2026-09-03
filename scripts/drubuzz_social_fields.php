<?php

/**
 * @file
 * One-off setup: adds the social-post fields + form/view display config to the
 * `posts` content type. Safe to run more than once.
 *
 * Run with: ddev drush scr scripts/drubuzz_social_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$fields = [
  'field_description_x' => [
    'type' => 'string_long',
    'label' => 'Description (X / Mastodon)',
    'description' => 'Body text used for the X and Mastodon posts. Keep it within 280 characters so it fits X.',
  ],
  'field_description_linkedin' => [
    'type' => 'string_long',
    'label' => 'Description (LinkedIn / Facebook / Instagram)',
    'description' => 'Body text used for the LinkedIn, Facebook and Instagram posts.',
  ],
  'field_graphic' => [
    'type' => 'entity_reference',
    'label' => 'Graphic',
    'description' => 'Image attached to the post on every platform.',
  ],
];

foreach ($fields as $name => $info) {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    $storage = [
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $info['type'],
      'cardinality' => 1,
    ];
    if ($info['type'] === 'entity_reference') {
      $storage['settings'] = ['target_type' => 'media'];
    }
    FieldStorageConfig::create($storage)->save();
    echo "Created field storage: $name\n";
  }

  if (!FieldConfig::loadByName('node', 'posts', $name)) {
    $field = [
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'posts',
      'label' => $info['label'],
      'description' => $info['description'],
      'required' => FALSE,
    ];
    if ($info['type'] === 'entity_reference') {
      $field['settings'] = [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['image' => 'image'],
          'sort' => ['field' => '_none'],
          'auto_create' => FALSE,
        ],
      ];
    }
    FieldConfig::create($field)->save();
    echo "Created field on posts: $name\n";
  }
}

/** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $repo */
$repo = \Drupal::service('entity_display.repository');

// --- Form display -----------------------------------------------------------
$form = $repo->getFormDisplay('node', 'posts', 'default');
$form->setComponent('field_description_x', [
  'type' => 'string_textarea',
  'weight' => 1,
  'region' => 'content',
  'settings' => ['rows' => 3, 'placeholder' => ''],
]);
$form->setComponent('field_description_linkedin', [
  'type' => 'string_textarea',
  'weight' => 3,
  'region' => 'content',
  'settings' => ['rows' => 6, 'placeholder' => ''],
]);
$form->setComponent('field_graphic', [
  'type' => 'media_library_widget',
  'weight' => 5,
  'region' => 'content',
  'settings' => ['media_types' => []],
]);

$groups = [
  'group_x_mastodon' => [
    'label' => 'X / Mastodon',
    'children' => ['field_description_x'],
    'weight' => 0,
    'format_settings' => [
      'classes' => 'social-post-group social-post-group--x',
      'open' => TRUE,
      'description' => 'One short description (≤ 280 chars) shared by the X and Mastodon posts.',
      'required_fields' => TRUE,
    ],
  ],
  'group_meta_platforms' => [
    'label' => 'LinkedIn / Facebook / Instagram',
    'children' => ['field_description_linkedin'],
    'weight' => 2,
    'format_settings' => [
      'classes' => 'social-post-group social-post-group--meta',
      'open' => TRUE,
      'description' => 'Longer description shared by the LinkedIn, Facebook and Instagram posts.',
      'required_fields' => TRUE,
    ],
  ],
  'group_media' => [
    'label' => 'Media',
    'children' => ['field_graphic'],
    'weight' => 4,
    'format_settings' => [
      'classes' => 'social-post-group social-post-group--media',
      'open' => TRUE,
      'description' => 'Graphic shown with the post on every platform. Required for Instagram.',
      'required_fields' => TRUE,
    ],
  ],
];
foreach ($groups as $group_name => $group) {
  $form->setThirdPartySetting('field_group', $group_name, [
    'children' => $group['children'],
    'parent_name' => '',
    'weight' => $group['weight'],
    'format_type' => 'details',
    'format_settings' => $group['format_settings'],
    'label' => $group['label'],
    'region' => 'content',
  ]);
}
$form->save();
echo "Saved form display with 3 field_group sections.\n";

// --- View display ----------------------------------------------------------
// The raw description/graphic fields are not rendered on the node page; the
// drubuzz_social_preview module adds the platform buttons instead.
$view = $repo->getViewDisplay('node', 'posts', 'default');
foreach (['field_description_x', 'field_description_linkedin', 'field_graphic'] as $name) {
  $view->removeComponent($name);
}
$view->save();
echo "Saved view display (raw fields hidden).\n";

echo "Done.\n";
