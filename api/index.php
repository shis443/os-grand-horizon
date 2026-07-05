<?php
// Vercel's PHP runtime requires all serverless function entrypoints to live
// under /api. This app's real front controller is public/index.php (the
// nginx/local-dev setup serves it from there, and CI3's BASEPATH/APPPATH
// resolution depends on __FILE__ pointing at its actual location) — so this
// is a thin passthrough rather than a copy. __FILE__ inside the required
// file still evaluates to its own real path, so all of CI3's path math
// keeps working unchanged. index.php also resolves 'system'/'application'
// via realpath() against the process CWD (not __DIR__), so chdir() here
// first regardless of what CWD the runtime started us in.
chdir(__DIR__ . '/../public');
require __DIR__ . '/../public/index.php';
