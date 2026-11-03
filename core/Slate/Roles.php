<?php
declare(strict_types=1);

namespace Slate;

/** WordPress-style roles and capabilities. Stored in options so they can be customised. */
final class Roles
{
    public const CAPS = [
        'access_admin' => 'Access the admin dashboard',
        'edit_posts' => 'Create and edit own content',
        'publish_posts' => 'Publish own content',
        'edit_others_posts' => 'Edit content of other users',
        'delete_posts' => 'Delete own content',
        'delete_others_posts' => 'Delete content of other users',
        'unfiltered_html' => 'Use any HTML / scripts in content',
        'upload_media' => 'Upload media files',
        'manage_media' => 'Edit and delete any media',
        'manage_terms' => 'Manage categories, tags and custom taxonomies',
        'manage_menus' => 'Manage navigation menus',
        'manage_content_model' => 'Create post types, taxonomies and custom fields',
        'manage_users' => 'Create, edit and delete users',
        'manage_settings' => 'Change site settings (SEO, integrations, etc.)',
        'manage_modules' => 'Install and activate modules',
        'manage_updates' => 'Update Slate core',
        'use_ai' => 'Use the AI assistant',
        'frontend_edit' => 'Edit content on the live site',
    ];

    public static function defaults(): array
    {
        $all = array_keys(self::CAPS);
        return [
            'administrator' => ['label' => 'Administrator', 'caps' => $all],
            'editor' => ['label' => 'Editor', 'caps' => [
                'access_admin', 'edit_posts', 'publish_posts', 'edit_others_posts', 'delete_posts',
                'delete_others_posts', 'unfiltered_html', 'upload_media', 'manage_media', 'manage_terms',
                'manage_menus', 'use_ai', 'frontend_edit',
            ]],
            'author' => ['label' => 'Author', 'caps' => [
                'access_admin', 'edit_posts', 'publish_posts', 'delete_posts', 'upload_media', 'use_ai', 'frontend_edit',
            ]],
            'contributor' => ['label' => 'Contributor', 'caps' => ['access_admin', 'edit_posts', 'delete_posts']],
            'subscriber' => ['label' => 'Subscriber', 'caps' => []],
        ];
    }

    public static function all(): array
    {
        $roles = Options::get('roles');
        return is_array($roles) && $roles ? $roles : self::defaults();
    }

    public static function label(string $role): string
    {
        return self::all()[$role]['label'] ?? ucfirst($role);
    }

    public static function can(string $role, string $cap): bool
    {
        return in_array($cap, self::all()[$role]['caps'] ?? [], true);
    }
}
