<?php

/**
 * OpenAPI Validation Middleware.
 *
 * @see       https://github.com/hkarlstrom/openapi-validation-middleware
 *
 * @copyright Copyright (c) 2018 Henrik Karlström
 * @license   MIT
 */

use C1st\Middleware\OpenApiValidation\Helpers;

test('additional properties', function () {
    $json = [
        'type'       => 'object',
        'properties' => [
            'foo' => [
                'type'       => 'object',
                'properties' => [
                    'bar' => [
                        'type' => 'number',
                    ],
                ],
            ],
        ],
    ];
    $json = Helpers\Json::additionalProperties($json, false);
    expect($json)->toHaveKey('additionalProperties');
    expect($json['additionalProperties'])->toBeFalse();
    expect($json['properties']['foo'])->toHaveKey('additionalProperties');
    expect($json['properties']['foo']['additionalProperties'])->toBeFalse();
});
