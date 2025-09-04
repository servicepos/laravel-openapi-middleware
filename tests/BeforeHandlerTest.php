<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use Illuminate\Http\Request;

test('before handler', function () {
    $response = makeResponse('get', '/parameters', [
        'options'       => [
            'beforeHandler' => function (Request $request, array $errors): Request {
                $request->attributes->set('error', $errors[0]['code']);
                return $request;
            },
        ],
        'customHandler' => function ($request) {
            return response()->json(['ok' => true])
                ->header('Content-type', 'application/json')
                ->header('X-ERROR', $request->attributes->get('error'));
        },
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('X-ERROR'))->toBe('error_required');
});
