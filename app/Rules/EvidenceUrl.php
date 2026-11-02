<?php

namespace App\Rules;

use App\Models\SkillEvidence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Type-aware validation for `evidence_url` on POST /api/v1/evidence.
 *
 * Laravel's built-in `url` rule only asks whether the string parses as a URL.
 * That is not enough here, for three reasons:
 *
 *  1. It accepts ANY scheme — `ftp://`, `javascript:`, `file://` and friends
 *     all pass `filter_var(..., FILTER_VALIDATE_URL)`. None of them is a usable
 *     proof of skill, and a `javascript:` value echoed back into an admin
 *     panel is a stored-XSS waiting to happen.
 *  2. It accepts `http://localhost` and private-range hosts. Nothing is fetched
 *     server-side today, so this is not SSRF yet — but "a live deployment at
 *     10.0.0.5" is not something a reviewer can open, and the rule should not
 *     pretend otherwise.
 *  3. It knows nothing about the declared `evidence_type`. A GitHub repository
 *     must be a github.com repository, not any URL the learner pasted.
 *
 * The type is passed in rather than read from the request so this rule stays a
 * pure function of (type, value) and can be unit-tested on its own.
 *
 * `$type` may be NULL: that is the legacy contract, where a client sends
 * `evidence_url` without declaring an evidence type. Those requests keep the
 * old behaviour (any http/https public URL) and are not held to a shape the
 * caller never declared.
 */
class EvidenceUrl implements ValidationRule
{
    /** Hosts accepted for `github_repository`. */
    private const GITHUB_HOSTS = ['github.com', 'www.github.com'];

    public function __construct(private readonly ?string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $parts = parse_url(trim($value));

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        /*
         * Only web URLs. See (1) above — this is the check Laravel's `url`
         * rule does not make.
         */
        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must be an http or https URL.');

            return;
        }

        /*
         * See (2) above. Applied to every type: no legitimate proof of skill
         * lives on the loopback interface or inside a private network.
         */
        if ($this->isNonPublicHost($host)) {
            $fail('The :attribute must point to a publicly reachable host.');

            return;
        }

        match ($this->type) {
            SkillEvidence::TYPE_GITHUB_REPOSITORY => $this->validateGithub($scheme, $host, $parts, $fail),
            SkillEvidence::TYPE_DESIGN_FILE => $this->validateDesignFile($scheme, $fail),
            SkillEvidence::TYPE_LIVE_DEPLOYMENT => null,
            /*
             * NULL (legacy) and `document` need no shape check beyond the
             * generic ones above. A `document` carrying a URL is already
             * refused by StoreEvidenceRequest before this rule matters.
             */
            default => null,
        };
    }

    /**
     * A GitHub repository link must actually be a GitHub repository:
     * https, github.com, and at least the `/{owner}/{repo}` path.
     *
     * The two-segment minimum is what separates a repository from a profile
     * page (`github.com/owner`) or a marketing page (`github.com/features`) —
     * neither of which a reviewer can inspect as a project.
     */
    private function validateGithub(string $scheme, string $host, array $parts, Closure $fail): void
    {
        if ($scheme !== 'https') {
            $fail('The :attribute must use https for a GitHub repository.');

            return;
        }

        if (! in_array($host, self::GITHUB_HOSTS, true)) {
            $fail('The :attribute must be a github.com repository URL.');

            return;
        }

        $segments = array_values(array_filter(
            explode('/', trim($parts['path'] ?? '', '/')),
            fn (string $segment) => $segment !== ''
        ));

        if (count($segments) < 2) {
            $fail('The :attribute must point to a repository, e.g. https://github.com/owner/project.');
        }
    }

    /**
     * Design files are served by several products (Figma, Canva, Adobe,
     * Sketch Cloud, ...), so the host is deliberately NOT allow-listed — only
     * the transport is pinned to https.
     */
    private function validateDesignFile(string $scheme, Closure $fail): void
    {
        if ($scheme !== 'https') {
            $fail('The :attribute must use https for a design file.');
        }
    }

    /**
     * Loopback, private-range and reserved hostnames.
     *
     * IP literals are checked with FILTER_FLAG_NO_PRIV_RANGE |
     * FILTER_FLAG_NO_RES_RANGE, which covers 127.0.0.0/8, 10/8, 172.16/12,
     * 192.168/16, 169.254/16 and ::1. Named hosts are matched on the suffixes
     * that only ever resolve internally.
     */
    private function isNonPublicHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        foreach (['.local', '.internal', '.intranet', '.lan', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        return false;
    }
}
