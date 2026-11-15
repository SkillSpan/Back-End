<?php

use Propaganistas\LaravelDisposableEmail\Fetcher\DefaultFetcher;

return [

    'sources' => [
        'https://cdn.jsdelivr.net/gh/disposable/disposable-email-domains@master/domains.json',
    ],

    'fetcher' => DefaultFetcher::class,

    'storage' => storage_path('framework/disposable_domains.json'),

    'whitelist' => [],

    'include_subdomains' => true,

    'cache' => [
        'enabled' => true,
        'store' => 'default',
        'key' => 'disposable_email:domains',
    ],

];
