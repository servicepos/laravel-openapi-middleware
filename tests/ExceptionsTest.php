<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use C1st\Middleware\OpenApiValidation;
use Illuminate\Http\Request;

test('file not exist exception', function () {
    try {
        $mw = new OpenApiValidation('not_a_file.json');
    } catch (\C1st\Middleware\OpenApiValidation\Exception\FileNotFoundException $e) {
        expect($e->filename())->toBe('not_a_file.json');
    }
});

test('invalid option exception', function () {
    try {
        $mw = new OpenApiValidation(getOpenapiFile(), ['invalidOption' => true]);
    } catch (\C1st\Middleware\OpenApiValidation\Exception\InvalidOptionException $e) {
        expect($e->option())->toBe('invalidOption');
    }
});

test('path not found exception', function () {
    try {
        $response = makeResponse('get', '/not/defined');
    } catch (\C1st\Middleware\OpenApiValidation\Exception\PathNotFoundException $e) {
        expect($e->method())->toBe('GET');
        expect($e->path())->toBe('/not/defined');
    }
});

test('invalid before handler return value exception', function () {
    try {
        $response = makeResponse('get', '/parameters', [
            'options' => [
                'beforeHandler' => function (Request $request, array $errors) {
                    return 'no';
                },
            ],
        ]);
    } catch (\C1st\Middleware\OpenApiValidation\Exception\BeforeHandlerException $e) {
        expect($e->type())->toBe('string');
    }
});

test('format missing exception', function () {
    expect(function () {
        $response = makeResponse('get', '/missing/format', [
            'query'   => ['test' => 'foo'],
            'options' => ['missingFormatException' => true],
        ]);
    })->toThrow(\C1st\Middleware\OpenApiValidation\Exception\MissingFormatException::class);

    try {
        $response = makeResponse('get', '/missing/format', [
            'query'   => ['test' => 'foo'],
            'options' => ['missingFormatException' => true],
        ]);
    } catch (\C1st\Middleware\OpenApiValidation\Exception\MissingFormatException $e) {
        expect($e->type())->toBe('string');
        expect($e->format())->toBe('uid');
    }
});

test('path not found no exception', function () {
    $response = makeResponse('get', '/not/defined', [
        'options' => ['pathNotFoundException' => false],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
});

test('format missing exception no exception', function () {
    $response = makeResponse('get', '/missing/format', [
        'options' => ['missingFormatException' => false],
        'query'   => ['test' => 'foo'],
    ]);
    $json = json($response);
    expect($json['ok'])->toBeTrue();
});
