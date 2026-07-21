<?php
header("Content-Type: text/css; charset=UTF-8");
$file = __DIR__ . "/style.css";
if (is_file($file)) {
    readfile($file);
    exit;
}
echo "/* style.css not found */";
