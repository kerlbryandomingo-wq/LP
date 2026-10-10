<?php
/**
 * LP System - root entry point.
 *
 * Normally the root .htaccess rewrites every request into public/ and this
 * file is never executed. It exists so that:
 *   - extracting the package straight into public_html/ gives the web root
 *     an index.php, as shared hosts expect, and
 *   - if a host serves this directory without applying .htaccess rewrites,
 *     the home page still loads the real application instead of a listing
 *     or a blank page.
 */

declare(strict_types=1);

require __DIR__ . '/public/index.php';
