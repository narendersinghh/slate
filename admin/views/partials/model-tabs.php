<?php
/**
 * Shared head of the content model lists: title, the main action of the current tab and the three tabs.
 * @var string $cmTab types|taxonomies|fields
 */
use Slate\AdminUi;
use Slate\ContentModel;

$cmTab ??= 'types';
$cmNew = [
    'types' => ['New post type', 'model/types/new'],
    'taxonomies' => ['New taxonomy', 'model/taxonomies/new'],
    'fields' => ['New field group', 'model/fields/new'],
][$cmTab];
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/model.css')) ?>">
<?= AdminUi::header('Content model', 'Decide what kinds of content your site has, how it is grouped and which details each item stores.', 'layers',
    [$cmNew[0] => [admin_url($cmNew[1]), 'primary', 'plus']]) ?>
<?= AdminUi::tabs([
    'types' => ['Post types', admin_url('model/types'), count(ContentModel::types())],
    'taxonomies' => ['Taxonomies', admin_url('model/taxonomies'), count(ContentModel::taxonomies())],
    'fields' => ['Custom fields', admin_url('model/fields'), count(ContentModel::fieldGroups())],
], $cmTab) ?>
