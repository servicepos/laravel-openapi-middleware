<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use Opis\JsonSchema\Format;

class CustomFormat implements Format
{
    public function validate($data): bool
    {
        return 'OK' === $data;
    }
}

test('required parameters validation', function () {
    $response = makeResponse('get', '/parameters', []);
    $json     = json($response);
    $error    = $json['errors'][0];
    expect($error['name'])->toBe('foo');
    expect($error['code'])->toBe('error_required');

    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'aaa']]);
    $json     = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('enum parameter validation', function () {
    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'ccc']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(400);
    $error = $json['errors'][0];
    expect($error['name'])->toBe('foo');
    expect($error['in'])->toBe('query');
    expect($error['code'])->toBe('error_enum');
    expect($error['expected'])->toBe(['aaa', 'bbb']);
});

test('query boolean parameter validation', function () {
    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 'true', 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 'TrUe', 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 'false', 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 0, 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 1, 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 3, 'foo' => 'aaa']]);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(400);
    $error = $json['errors'][0];
    expect($error['expected'])->toBe('boolean');
    expect($error['value'])->toBe('3');

    $response = makeResponse('get', '/parameters', ['query' => ['boolean' => 'hello', 'foo' => 'aaa']]);
    $json     = json($response);
    $error    = $json['errors'][0];
    expect($response->getStatusCode())->toBe(400);
    expect($error['expected'])->toBe('boolean');
    expect($error['value'])->toBe('hello');
});

test('additional parameters validation', function () {
    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'aaa', 'bar' => 'aaa']]);
    expect($response->getStatusCode())->toBe(400);
    $json  = json($response);
    $error = $json['errors'][0];
    expect($error['name'])->toBe('bar');
    expect($error['code'])->toBe('error_additional');
    $response = makeResponse('get', '/parameters', [
        'options' => ['additionalParameters' => true],
        'query'   => ['foo' => 'aaa', 'bar' => 'aaa'],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('format validation', function () {
    $response = makeResponse('get', '/formats', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat()],
        ],
        'query'   => [
            'string'       => 'test',
            'integer'      => 10,
            'phone'        => '+358501234567',
            'email'        => 'foo@bar.com',
            'between'      => 15,
            'country-code' => 'FI',
            'customFormat' => 'OK',
        ],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('date format validation', function () {
    $response = makeResponse('get', '/formats', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat()],
        ],
        'query'   => [
            'date' => '2014-12-23',
        ],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/formats', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat()],
        ],
        'query'   => [
            'date' => '2014-02-31',
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(400);
});

test('default parameters setting', function () {
    $args = [
        'query'         => [
            'foo' => 'aaa',
        ],
        'options'       => [
            'setDefaultParameters' => false,
        ],
        'customHandler' => function ($request) {
            $query = $request->query->all();
            return response()->json(['ok' => 50 === ($query['default'] ?? null)])
                ->header('Content-type', 'application/json');
        },
    ];
    $response = makeResponse('get', '/parameters', $args);
    $res      = json($response);
    expect($res['ok'])->toBeFalse();

    $args['options']['setDefaultParameters'] = true;
    $response                                = makeResponse('get', '/parameters', $args);
    $res                                     = json($response);
    expect($res['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('path parameter validation', function () {
    $response = makeResponse('get', '/path/100/path/200');
    $json     = json($response);
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('get', '/path/100/path/string');
    $json     = json($response);
    expect($response->getStatusCode())->toBe(400);
    $json  = json($response);
    $error = $json['errors'][0];
    expect($error['in'])->toBe('path');
});

test('parameter style validation', function () {
    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'aaa', 'list' => 'item1,item3']]);
    $json     = json($response);
    expect($json['errors'][0]['value'])->toBe('item3');

    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'aaa', 'listPipe' => 'item1|item2']]);
    $json     = json($response);
    expect($json['ok'])->toBeTrue();
});

test('deep object parameter style validation', function () {
    $response = makeResponse('get', '/parameters', ['query' => ['foo' => 'aaa', 'filter' => ['ids' => [1, 'aaa', 2]]]]);
    $json     = json($response);
    $error    = $json['errors'][0];
    expect($error['name'])->toBe('filter.ids.1');
    expect($error['expected'])->toBe('integer');
    expect($error['used'])->toBe('string');
    expect($error['value'])->toBe('aaa');
});
