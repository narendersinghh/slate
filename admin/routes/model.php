<?php
/** Content model extras: duplicate a field group (the copy starts hidden, so two groups never fight over the same field names). */
use Slate\Auth;
use Slate\ContentModel;
use Slate\Options;
use Slate\Router;
use Slate\Session;

return static function (Router $r): void {
    $r->post('/model/fields/{id}/duplicate', static function (string $id) {
        Auth::require('manage_content_model');
        $group = (Options::get('field_groups') ?: [])[$id] ?? null;
        if (!$group) {
            admin_404();
        }
        $group['id'] = 'g' . bin2hex(random_bytes(4));
        $group['title'] = ($group['title'] ?? 'Fields') . ' (copy)';
        $group['location'] = ['post_types' => [], 'taxonomies' => []];
        ContentModel::saveFieldGroup($group);
        log_activity('field_group_duplicated', $id);
        Session::flash('success', 'Copy created. Choose where it shows before using it.');
        redirect(admin_url('model/fields/' . $group['id']));
    });
};
