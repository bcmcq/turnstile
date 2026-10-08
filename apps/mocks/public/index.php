<?php

declare(strict_types=1);

// ponytail: phase 0 placeholder. Real mock marketplaces land in phase 2.
header('Content-Type: application/json');
echo json_encode(['service' => 'mocks', 'platforms' => ['tixhub', 'seatswap', 'passmarket'], 'status' => 'ok']);
