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

class CustomFormat2 implements Format
{
    public function validate($data): bool
    {
        return 'OK' === $data;
    }
}
test('request body validation', function () {
    $response = makeResponse('post', '/request/body', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat2()],
        ],
        'body'    => [
            'foo'    => 'test',
            'bar'    => 123,
            'person' => [
                'name'  => 'Donald',
                'email' => 'aaa@aaa.com',
            ],
            'custom' => 'OK',
        ],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);

    $response = makeResponse('post', '/request/body', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat2()],
        ],
        'body'    => [
            'foo'    => 'test',
            'bar'    => 123,
            'person' => [
            ],
            'custom' => 'OK',
        ],
        'options' => [
            'strictEmptyArrayValidation' => true,
        ],
    ]);
    $json   = json($response);
    $errors = $json['errors'];
    expect($errors[0]['code'])->toBe('error_type');
    expect($errors[0]['in'])->toBe('body');
    expect($errors[0]['name'])->toBe('person');

    $response = makeResponse('post', '/request/body', [
        'formats' => [
            ['string', 'customFormat', new CustomFormat2()],
        ],
        'body'    => [
            'foo'    => 123,
            'bar'    => 'test',
            'person' => [
                'email' => 'aaaaa.com',
                'extra' => 'hmm',
            ],
            'custom' => 'NOT',
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(400);
    $json = json($response);

    $errors = $json['errors'];
    expect($errors[0]['code'])->toBe('error_type');
    expect($errors[1]['code'])->toBe('error_type');
    expect($errors[2]['code'])->toBe('error_required');
    expect($errors[3]['code'])->toBe('error_format');
    expect($errors[3]['format'])->toBe('customFormat');
    expect($errors[0]['in'])->toBe('body');
    expect($errors[1]['in'])->toBe('body');
    expect($errors[2]['in'])->toBe('body');
    expect($errors[3]['in'])->toBe('body');
});

test('request body path validation', function () {
    $response = makeResponse('post', '/request/body/path/test', [
        'body' => [
            'bar' => 123,
        ],
    ]);
    $json = json($response);
    $err  = $json['errors'][0];
    expect($response->getStatusCode())->toBe(400);
    expect($err['code'])->toBe('error_type');
    expect($err['name'])->toBe('foo');
    expect($err['value'])->toBe('test');
    expect($err['in'])->toBe('path');
    expect($err['expected'])->toBe('integer');
    expect($err['used'])->toBe('string');
});

test('empty request body validation', function () {
    $response = makeResponse('post', '/request/body/empty');
    $json     = json($response);
    expect($json['ok'])->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

test('all of composition validation', function () {
    $response = makeResponse('put', '/all/of', [
        'body' => [
            'data' => [
                'id'          => 'a',
                'first_name'  => 'Jane',
                'last_name'   => 'Doe',
                'phone'       => '3333-11111111',
                'nationality' => 'IE',
            ],
        ],
    ]);
    $json = json($response);
    expect($json['errors'][0]['name'])->toBe('data.e_mail');
    expect($json['errors'][0]['code'])->toBe('error_required');
    expect($response->getStatusCode())->toBe(400);
    expect($json['errors'][0]['in'])->toBe('body');
});

test('additional attributes validation', function () {
    $response = makeResponse('post', '/additionalProperties', [
        'body' => [
            'foo' => [
                'bar'        => 100,
                'additional' => 'test',
            ],
        ],
    ]);
    $json = json($response);
    expect($json['errors'][0]['name'])->toBe('foo');
    expect($json['errors'][0]['code'])->toBe('error_additionalProperties');
    expect($response->getStatusCode())->toBe(400);
});

test('hash map string validation', function () {
    $response = makeResponse('post', '/additionalProperties/hashmap/string', [
        'body' => [
            'en' => 'Hello',
            'sv' => 'Tjena',
            'fi' => 100,
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(400);
    expect($json['errors'][0]['name'])->toBe('fi');
    expect($json['errors'][0]['code'])->toBe('error_type');
    expect($json['errors'][0]['used'])->toBe('integer');
});

test('hash map object validation', function () {
    $response = makeResponse('post', '/additionalProperties/hashmap/object', [
        'body' => [
            'aa' => [
                'id'  => 10,
                'foo' => 'text',
                'bar' => 10,
            ],
            'bb' => [
                'foo' => 10,
                'bar' => 'abc',
            ],
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(400);
    expect($json['errors'][0]['name'])->toBe('aa.foo');
    expect($json['errors'][0]['code'])->toBe('error_type');
    expect($json['errors'][0]['used'])->toBe('string');
    expect($json['errors'][1]['name'])->toBe('aa.bar');
    expect($json['errors'][1]['code'])->toBe('error_type');
    expect($json['errors'][1]['used'])->toBe('integer');
    expect($json['errors'][2]['name'])->toBe('bb.id');
    expect($json['errors'][2]['code'])->toBe('error_required');
    expect($json['errors'][2]['in'])->toBe('body');
});

test('empty body validation', function () {
    $response = makeResponse('post', '/request/body/empty/required');
    $json     = json($response);
    expect($response->getStatusCode())->toBe(400);
    expect($json['errors'][0]['name'])->toBe('requestBody');
    expect($json['errors'][0]['code'])->toBe('error_required');

    $response = makeResponse('post', '/request/body/empty/required', ['body' => '{}']);
    $json     = json($response);
    expect($response->getStatusCode())->toBe(400);
    expect($json['errors'][0]['name'])->toBe('foo');
    expect($json['errors'][0]['code'])->toBe('error_required');
    expect($json['errors'][0]['in'])->toBe('body');
});

test('empty object validation', function () {
    $response = makeResponse('patch', '/request/empty-object', [
        'body' => [
            'metadata' => [],
        ],
    ]);
    expect($response->getStatusCode())->toBe(200);
});

test('request body nullable one of validation', function () {
    $response = makeResponse('post', '/request/body/nullable-oneof', [
        'body' => [
            'foo' => null,
        ],
    ]);
    $json = json($response);
    expect($response->getStatusCode())->toBe(200);
    expect($json['ok'])->toBeTrue();
    expect($json)->not->toHaveKey('errors');
});
