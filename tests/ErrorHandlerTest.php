<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use Illuminate\Http\JsonResponse;

test('error handler', function () {
    $response = makeResponse('get', '/parameters', ['options' => [
        'errorHandler' => function (int $code, string $message, array $errors): JsonResponse {
            return new JsonResponse([
                'message' => 'custom error',
                'errors'  => $errors,
            ], $code, ['Content-type' => 'application/json']);
        },
    ]]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(400);
    expect($json['message'])->toBe('custom error');
    $error = $json['errors'][0];
    expect($error['name'])->toBe('foo');
    expect($error['code'])->toBe('error_required');
});
