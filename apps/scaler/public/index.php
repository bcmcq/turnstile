<?php

declare(strict_types=1);

// phase 0 placeholder. Real scaler (docker run clones of the worker template) lands in phase 5.
header('Content-Type: application/json');
$out = [];
exec('docker ps --filter label=com.docker.compose.service=worker --format "{{.Names}}" 2>&1', $out, $code);
echo json_encode(['service' => 'scaler', 'docker' => $code === 0 ? 'ok' : 'unavailable', 'workers' => $out]);
