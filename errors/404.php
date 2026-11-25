<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/app.php';

abort_request(
    404,
    'Page not found',
    'The page you requested does not exist, has moved, or is not available to this account.'
);
