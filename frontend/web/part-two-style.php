<?php
header("Content-Type: text/css; charset=UTF-8");
$file = __DIR__ . "/part-two.css";
if (is_file($file)) {
    readfile($file);
    exit;
}
echo "/* part-two.css not found */";
