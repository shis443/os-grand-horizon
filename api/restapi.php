<?php
// Passthrough to the legacy REST API sub-app (moved to /restapi so /api
// could hold Vercel's required function entrypoints — see api/index.php).
// chdir() first: restapi/index.php resolves 'system'/'application' via
// realpath() against the process CWD, not __DIR__.
chdir(__DIR__ . '/../restapi');
require __DIR__ . '/../restapi/index.php';
