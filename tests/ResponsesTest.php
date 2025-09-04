<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use Illuminate\Http\Response;
test('example response', function () {
    $response = makeResponse('get', '/response/example', [
        'emptyHandler' => true,
        'options'      => [
            'exampleResponse' => true,
        ],
    ]);
    expect($response->getStatusCode())->toBe(200);
});

test('example response post', function () {
    $response = makeResponse('post', '/response/example', [
        'emptyHandler' => true,
        'body'         => ['foo' => 'bar'],
        'options'      => [
            'exampleResponse' => true,
        ],
    ]);
    expect($response->getStatusCode())->toBe(201);
    $json = json($response);
    expect($json['foo'])->toBe('bar');
    expect($json['bar'])->toBe(100);
});

test('example response list', function () {
    $response = makeResponse('get', '/response/example/list', [
        'emptyHandler' => true,
        'options'      => [
            'exampleResponse' => true,
            'stripResponse'   => true,
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(200);
    expect($json[0]['foo'])->toBe('test');
    expect($json[0]['bar'])->toBe(100);
});

test('strip response', function () {
    $response = makeResponse('get', '/response/example', [
        'emptyHandler' => true,
        'options'      => [
            'exampleResponse' => true,
            'stripResponse'   => true,
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(200);
    expect($json['foo'])->toBe('test');
    expect($json['bar'])->toBe(100);
    expect(isset($json['extra']))->toBeFalse();
});

test('response missed header', function () {
    $response = makeResponse('get', '/missing/header', [
        'options' => [
            'validateResponseHeaders' => true,
        ],
    ]);
    expect($response->getStatusCode())->toBe(500);
    $error = json($response)['errors'][0];
    expect($error['name'])->toBe('X-Response-Id');
    expect($error['code'])->toBe('error_required');
    expect($error['in'])->toBe('header');
});

test('response invalid header format', function () {
    $response = makeResponse('get', '/missing/header', [
        'options'       => [
            'validateResponseHeaders' => true,
        ],
        'customHandler' => function ($request) {
            return response()->json(['ok' => true])
                ->header('X-Response-Id', 'foo')
                ->header('Content-type', 'application/json');
        },
    ]);
    expect($response->getStatusCode())->toBe(500);
    $error = json($response)['errors'][0];
    expect($error['name'])->toBe('X-Response-Id');
    expect($error['code'])->toBe('error_type');
    expect($error['expected'])->toBe('integer');
    expect($error['used'])->toBe('string');
    expect($error['in'])->toBe('header');
});

// public function testResponseWithNullableBodyAttributes()
// {
//     $response = $this->makeResponse('get', '/response/nullable', [
//         'customHandler' => function ($request, ResponseInterface $response) {
//             $response->getBody()->write(json_encode(['ok' => true]));
//             return $response->withHeader('Content-type', 'application/json');
//         },
//     ]);
//     $json = $this->json($response);
//     $this->assertSame(200, $response->getStatusCode());
//     $this->assertSame(null, $json['ok']);
// }

test('responses with any of body attribute', function () {
    $response = makeResponse('get', '/response/any-of', [
        'customHandler' => function ($request) {
            return response()->json(['value' => 15])
                ->header('Content-type', 'application/json');
        },
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(200);
    expect($json['value'])->toBe(15);
});
