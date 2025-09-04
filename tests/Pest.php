<?php

/**
 * OpenAPI Validation Middleware for Laravel.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use C1st\Middleware\OpenApiValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Test Helpers
|--------------------------------------------------------------------------
|
| Here we expose helpers as global functions to help reduce the number of
| lines of code in test files.
|
*/

function getOpenapiFile(): string
{
    return __DIR__ . '/testapi.json';
}

function convertArrayToStrings($data)
{
    if (is_array($data)) {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = convertArrayToStrings($value);
        }
        return $result;
    } else {
        return (string) $data;
    }
}

function json($response): array
{
    $content = $response instanceof JsonResponse ? $response->getData(true) : json_decode($response->getContent(), true);
    return $content ?: [];
}

function makeResponse(string $method, string $uri, array $args = []): \Illuminate\Http\Response  | \Illuminate\Http\JsonResponse
{
    $openapiFile = getOpenapiFile();
    $options     = array_merge(['missingFormatException' => false], $args['options'] ?? []);
    $middleware  = new OpenApiValidation($openapiFile, $options);

    // Add custom formats if provided
    foreach ($args['formats'] ?? [] as $f) {
        $middleware->addFormat($f[0], $f[1], $f[2]);
    }

    // Parse URI and query parameters
    $uriParts    = parse_url($uri);
    $path        = $uriParts['path'] ?? '/';
    $queryParams = [];
    if (isset($uriParts['query'])) {
        parse_str($uriParts['query'], $queryParams);
    }

    // Merge with additional query parameters from args
    $queryParams = array_merge($queryParams, $args['query'] ?? []);

    // Convert all query parameters to strings (like real HTTP requests)
    $queryParams = convertArrayToStrings($queryParams);

    // Set headers
    $headerArgs = $args['headers'] ?? [];
    if (! isset($headerArgs['Content-Type'])) {
        $headerArgs['Content-Type'] = 'application/json;charset=utf8';
    }

    // Set request body content
    $content = '';
    if (isset($args['body'])) {
        $content = is_array($args['body']) ? json_encode($args['body']) : $args['body'];
    }

    // Create Laravel request with proper content
    $request = Request::create($path, $method, $queryParams, [], [],
        ['CONTENT_TYPE' => $headerArgs['Content-Type']],
        $content
    );

    // Apply all headers
    foreach ($headerArgs as $key => $value) {
        $request->headers->set($key, $value);
    }

    // Handle CORS preflight
    if (isset($args['cors'])) {
        $request->headers->set('Access-Control-Request-Method', 'GET');
    }

    // Define the next handler
    if ($args['emptyHandler'] ?? false) {
        $next = function ($req) {
            return response('');
        };
    } elseif ($args['customHandler'] ?? false) {
        $customHandler = $args['customHandler'];
        $next          = function ($req) use ($customHandler) {
            return $customHandler($req);
        };
    } else {
        $next = function ($req) {
            return response()->json(['ok' => true]);
        };
    }

    try {
        // Execute middleware
        $response = $middleware->handle($request, $next);
        return $response;
    } catch (\Exception $e) {
        // Convert exceptions to error responses for consistency with tests
        if ($e instanceof \C1st\Middleware\OpenApiValidation\Exception\PathNotFoundException  ||
            $e instanceof \C1st\Middleware\OpenApiValidation\Exception\BeforeHandlerException  ||
            $e instanceof \C1st\Middleware\OpenApiValidation\Exception\MissingFormatException) {
            throw $e;
        }

        return response()->json(['error' => $e->getMessage()], 500);
    }
}
