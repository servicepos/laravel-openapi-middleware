<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

test('cors request', function () {
    $response = makeResponse('options', '/does/not/exist', [
        'cors' => true,
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});
