<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */
test('header required missing', function () {
    $response = makeResponse('get', '/headers', []);
    $json     = json($response);
    $error    = $json['errors'][0];
    expect($error['name'])->toBe('X-Required');
    expect($error['code'])->toBe('error_required');
    expect($error['in'])->toBe('header');
});

test('header required invalid', function () {
    $options = [
        'headers' => [
            'X-Required' => '999999',
        ],
    ];

    $response = makeResponse('get', '/headers', $options);
    $json     = json($response);

    $error = $json['errors'][0];

    expect($error['name'])->toBe('X-Required');
    expect($error['code'])->toBe('error_pattern');
    expect($error['in'])->toBe('header');
});

test('header required valid', function () {
    $options = [
        'headers' => [
            'X-Required' => 'TST',
        ],
    ];

    $response = makeResponse('get', '/headers', $options);
    $json     = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});
