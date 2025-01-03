<?php

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

Route::redirect('/', 'https://laravel.com/docs', 302);

Route::get('/{name}', function (Request $request, $name) {
    $availableServices = [
        'mysql',
        'pgsql',
        'mariadb',
        'redis',
        'valkey',
        'memcached',
        'meilisearch',
        'typesense',
        'minio',
        'mailpit',
        'selenium',
        'soketi',
    ];
    $validPhpVersions = ['74', '80', '81', '82', '83', '84'];
    $validLaravelVersions = [
        '74' => ['8'],
        '80' => ['8', '9'],
        '81' => ['8', '9', '10'],
        '82' => ['9', '10', '11'],
        '83' => ['10', '11'],
        '84' => ['11'],
    ];
    $defaultPhpVersion = end($validPhpVersions);

    $phpRequestQuery = $request->query('php');
    $php = $phpRequestQuery ?? $defaultPhpVersion;

    $version = $request->query('version');
    if ($phpRequestQuery === null && $version !== null) {
        $php = match ($version) {
            '8' => '81',
            '9' => '82',
            '10' => '83',
            '11' => '84',
            default => $defaultPhpVersion,
        };
    }
    
    if ($version === null) {
        if (in_array($php, $validPhpVersions)) {
            $version = end($validLaravelVersions[$php]);
        } else {
            $version = end($validLaravelVersions[$defaultPhpVersion]);
        }
    }

    $validLaravelVersionsForPhp = $validLaravelVersions[$php] ?? end($validLaravelVersions);

    $with = array_unique(explode(',', $request->query('with', 'mysql,redis,meilisearch,mailpit,selenium')));

    try {
        Validator::validate(
            [
                'name' => $name,
                'php' => $php,
                'version' => $version,
                'with' => $with,
            ],
            [
                'name' => 'string|alpha_dash',
                'php' => ['string', Rule::in($validPhpVersions)],
                'version' => ['string', Rule::in($validLaravelVersionsForPhp)],
                'with' => 'array',
                'with.*' => [
                    'required',
                    'string',
                    count($with) === 1 && in_array('none', $with) ? Rule::in(['none']) : Rule::in($availableServices)
                ],
            ]
        );
    } catch (ValidationException $e) {
        $errors = Arr::undot($e->errors());

        if (array_key_exists('name', $errors)) {
            return response('Invalid site name. Please only use alpha-numeric characters, dashes, and underscores.', 400);
        }

        if (array_key_exists('php', $errors)) {
            return response('Invalid PHP version. Please specify a supported version (74, 80, 81, 82, 83, or 84).', 400);
        }

        if (array_key_exists('version', $errors)) {
            // Format the PHP version to X.Y format
            $phpFormatted = substr($php, 0, 1) . '.' . substr($php, 1);
            // Format the valid versions to have "or" before the last version
            $formattedVersions = count($validLaravelVersions[$php]) > 1
                ? implode(', ', array_slice($validLaravelVersions[$php], 0, -1)).' or '.end($validLaravelVersions[$php])
                : $validLaravelVersions[$php][0];
            return response('Invalid Laravel version for PHP '.$phpFormatted.'. Please specify a supported version ('.$formattedVersions.').', 400);
        }

        if (array_key_exists('with', $errors)) {
            return response('Invalid service name. Please provide one or more of the supported services ('.implode(', ', $availableServices).') or "none".', 400);
        }
    }

    $services = implode(' ', $with);

    $version = '^'.$version.'0';

    $with = implode(',', $with);

    $devcontainer = $request->has('devcontainer') ? '--devcontainer' : '';

    $script = str_replace(
        ['{{ php }}', '{{ version }}', '{{ name }}', '{{ with }}', '{{ devcontainer }}', '{{ services }}'],
        [$php, $version, $name, $with, $devcontainer, $services],
        file_get_contents(resource_path('scripts/php.sh'))
    );

    return response($script, 200, ['Content-Type' => 'text/plain']);
});
