<?php

use App\Core\Cslabs;

Cslabs::boot([
    'stream' => $web->stream ?? null,
]);

ob_start(function ($buffer) {
    $buffer = Cslabs::injectInfoIntoJson($buffer);
    Cslabs::finalizeInteraction($buffer);
    return $buffer;
});
