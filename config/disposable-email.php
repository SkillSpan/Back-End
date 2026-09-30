<?php

use Propaganistas\LaravelDisposableEmail\Fetcher\DefaultFetcher;

return [

    /*
    |--------------------------------------------------------------------------
    | JSON Source URLs
    |--------------------------------------------------------------------------
    |
    | The source URLs yielding a list of disposable email domains. Change these
    | to whatever source you like. Just make sure they all return a JSON array.
    |
    | A sensible default is provided using jsDelivr's services. jsDelivr is
    | a free service, so there are no uptime or support guarantees.
    |
    */

    'sources' => [
        'https://cdn.jsdelivr.net/gh/disposable/disposable-email-domains@master/domains.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fetch class
    |--------------------------------------------------------------------------
    |
    | The class responsible for fetching the contents of the source url.
    | The default implementation makes use of file_get_contents and
    | json_decode and will probably suffice for most applications.
    |
    | If your application has different needs (e.g. behind a proxy) then you
    | can define a custom fetch class here that carries out the fetching.
    | Your custom class should implement the Fetcher contract.
    |
    */

    'fetcher' => DefaultFetcher::class,

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The location where the retrieved domains list should be stored locally.
    | The path should be accessible and writable by the web server. A good
    | place for storing the list is in the framework's own storage path.
    |
    */

    'storage' => storage_path('framework/disposable_domains.json'),

    /*
    |--------------------------------------------------------------------------
    | Whitelist Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define a list of whitelist domains that should be allowed.
    | These domains will be removed from the list of disposable domains.
    |
    | Insert as "mydomain.com", without the @ symbol.
    |
    */

    /*
    | Escape hatch for false positives. The upstream list is community-maintained
    | and occasionally flags a legitimate domain. If a real organisation reports
    | that it cannot register, add its domain here — nothing else in the
    | registration flow needs to change.
    |
    | Keep this empty until it is actually needed.
    */

    'whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Include Subdomains
    |--------------------------------------------------------------------------
    |
    | Determines whether subdomains should be validated based on the disposability
    | status of their parent domains. Enabling this will treat any subdomain of
    | a disposable domain as disposable too (e.g., 'temp.abc.com' if 'abc.com'
    | is disposable).
    |
    | Enabled on purpose for this project. The package default (false) only
    | matches the exact domain, so "anything@inbox.mailinator.com" would slip
    | past a check that catches "anything@mailinator.com". Disposable providers
    | hand out subdomain inboxes, so the exact-match default leaves a real
    | bypass open. There is no false-positive cost: a subdomain is only rejected
    | when its parent is already on the disposable list, and the whitelist above
    | remains available as the escape hatch.
    |
    */

    'include_subdomains' => true,

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define whether the disposable domains list should be cached.
    | If you disable caching or when the cache is empty, the list will be
    | fetched from local storage instead.
    |
    | You can optionally specify an alternate cache connection or modify the
    | cache key as desired.
    |
    */

    'cache' => [
        'enabled' => true,
        'store' => 'default',
        'key' => 'disposable_email:domains',
    ],

];
