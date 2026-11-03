<?php
/**
 * Slate CMS — front controller.
 * Every request that is not a static file is routed through here.
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

Slate\App::run();
