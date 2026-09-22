<?php
// Development server only. Production uses IIS web.config.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~^/seo-manager/(lib|cli|tests|deploy|private)(/|$)|\.(json|md|ps1|tmp)$~i', $path) || str_contains($path, '..')) { http_response_code(404); exit; }
header('Cache-Control: no-cache, max-age=0, must-revalidate');
return false;
