<?php

/**
 * OpenAPI Validation Middleware for Laravel Tests.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use C1st\Middleware\OpenApiValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;



test('middleware handles valid request', function () {
    $middleware = new OpenApiValidation(getOpenapiFile(), [
        'missingFormatException' => false,
    ]);

    $data    = ['foo' => 'test', 'bar' => 123];
    $request = Request::create('/request/body', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($data)
    );

    $response = $middleware->handle($request, function ($req) {
        return response()->json(['ok' => true]);
    });

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
});

test('middleware returns error for invalid request', function () {
    $middleware = new OpenApiValidation(getOpenapiFile(), [
        'pathNotFoundException' => true,
    ]);

    $request = Request::create('/api/nonexistent', 'GET');
    $request->headers->set('Content-Type', 'application/json');

    expect(function () use ($middleware, $request) {
        $middleware->handle($request, function ($req) {
            return response()->json(['ok' => true]);
        });
    })->toThrow(\C1st\Middleware\OpenApiValidation\Exception\PathNotFoundException::class);
});

test('middleware with custom error handler', function () {
    $middleware = new OpenApiValidation(getOpenapiFile(), [
        'pathNotFoundException'  => false,
        'validateRequest'        => true,
        'missingFormatException' => false,
        'errorHandler'           => function (int $code, string $message, array $errors) {
            return response()->json([
                'custom_error'      => $message,
                'validation_errors' => $errors,
            ], $code);
        },
    ]);

    $data    = ['invalid' => 'data']; // Missing required fields
    $request = Request::create('/request/body', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($data)
    );

    $response = $middleware->handle($request, function ($req) {
        return response()->json(['ok' => true]);
    });

    expect($response)->toBeInstanceOf(JsonResponse::class);
});

test('middleware with before handler', function () {
    $middleware = new OpenApiValidation(getOpenapiFile(), [
        'validateRequest' => true,
        'beforeHandler'   => function (Request $request, array $errors) {
            // Modify request before passing to next middleware
            $request->merge(['modified' => true]);
            return $request;
        },
    ]);

    $request = Request::create('/parameters', 'GET');
    $request->headers->set('Content-Type', 'application/json');

    $response = $middleware->handle($request, function ($req) {
        return response()->json(['modified' => $req->has('modified')]);
    });

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
});
