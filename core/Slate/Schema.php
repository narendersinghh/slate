<?php
declare(strict_types=1);

namespace Slate;

/**
 * Database schema + migrations. Content model definitions (post types, taxonomies,
 * field groups) live in autoloaded options, so a typical page view needs very few queries.
 * Custom field values are stored as JSON on the post/term row itself: no meta-table joins.
 */
final class Schema
{
    public static function tables(): array
    {
        $sqlite = DB::$driver === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $text = $sqlite ? 'TEXT' : 'LONGTEXT';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            "CREATE TABLE IF NOT EXISTS {{options}} (
                name VARCHAR(191) NOT NULL PRIMARY KEY,
                value $text,
                autoload TINYINT NOT NULL DEFAULT 1
            )$tail",
            "CREATE TABLE IF NOT EXISTS {{users}} (
                id $pk,
                username VARCHAR(60) NOT NULL UNIQUE,
                email VARCHAR(191) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                display_name VARCHAR(191) NOT NULL DEFAULT '',
                role VARCHAR(40) NOT NULL DEFAULT 'subscriber',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                totp_secret VARCHAR(255) NULL,
                meta $text NULL,
                last_login DATETIME NULL,
                created_at DATETIME NOT NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS {{posts}} (
                id $pk,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                slug VARCHAR(191) NOT NULL,
                content $text NULL,
                excerpt TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                author_id $int NULL,
                parent_id $int NULL,
                featured_media_id $int NULL,
                template VARCHAR(100) NULL,
                menu_order INT NOT NULL DEFAULT 0,
                fields $text NULL,
                seo TEXT NULL,
                published_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (type, slug)
            )$tail",
            "CREATE INDEX idx_posts_list ON {{posts}} (type, status, published_at)",
            "CREATE INDEX idx_posts_parent ON {{posts}} (parent_id)",
            "CREATE TABLE IF NOT EXISTS {{terms}} (
                id $pk,
                taxonomy VARCHAR(40) NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                description TEXT NULL,
                parent_id $int NULL,
                count INT NOT NULL DEFAULT 0,
                fields TEXT NULL,
                seo TEXT NULL,
                UNIQUE (taxonomy, slug)
            )$tail",
            "CREATE TABLE IF NOT EXISTS {{term_relationships}} (
                post_id $int NOT NULL,
                term_id $int NOT NULL,
                PRIMARY KEY (post_id, term_id)
            )$tail",
            "CREATE INDEX idx_tr_term ON {{term_relationships}} (term_id)",
            "CREATE TABLE IF NOT EXISTS {{media}} (
                id $pk,
                path VARCHAR(255) NOT NULL,
                thumb_path VARCHAR(255) NULL,
                original_name VARCHAR(255) NOT NULL DEFAULT '',
                mime VARCHAR(100) NOT NULL,
                size $int NOT NULL DEFAULT 0,
                original_size $int NOT NULL DEFAULT 0,
                width INT NULL,
                height INT NULL,
                alt VARCHAR(255) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL DEFAULT '',
                caption TEXT NULL,
                uploaded_by $int NULL,
                created_at DATETIME NOT NULL
            )$tail",
            "CREATE INDEX idx_media_created ON {{media}} (created_at)",
            "CREATE TABLE IF NOT EXISTS {{login_attempts}} (
                id $pk,
                ip VARCHAR(45) NOT NULL,
                username VARCHAR(191) NOT NULL DEFAULT '',
                attempted_at DATETIME NOT NULL
            )$tail",
            "CREATE INDEX idx_login_ip ON {{login_attempts}} (ip, attempted_at)",
            "CREATE TABLE IF NOT EXISTS {{activity_log}} (
                id $pk,
                user_id $int NULL,
                action VARCHAR(60) NOT NULL,
                object VARCHAR(191) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL
            )$tail",
        ];
    }

    public static function install(): void
    {
        foreach (self::tables() as $sql) {
            try {
                DB::exec($sql);
            } catch (\PDOException $e) {
                // Index already exists (CREATE INDEX lacks IF NOT EXISTS on MySQL).
                if (!str_contains($sql, 'CREATE INDEX')) {
                    throw $e;
                }
            }
        }
    }

    /** Run pending migrations. Each key is the DB version it upgrades to. */
    public static function migrate(): void
    {
        $current = (int)Options::get('db_version', 0);
        if ($current >= SLATE_DB_VERSION) {
            return;
        }
        $migrations = [
            1 => static function (): void {
                self::install();
            },
        ];
        foreach ($migrations as $version => $fn) {
            if ($version > $current) {
                $fn();
                Options::set('db_version', $version);
            }
        }
    }
}
