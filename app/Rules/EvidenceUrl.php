<?php

namespace App\Rules;

use App\Models\SkillEvidence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EvidenceUrl implements ValidationRule
{
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

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must be an http or https URL.');

            return;
        }

        if ($this->isNonPublicHost($host)) {
            $fail('The :attribute must point to a publicly reachable host.');

            return;
        }

        match ($this->type) {
            SkillEvidence::TYPE_GITHUB_REPOSITORY => $this->validateGithub($scheme, $host, $parts, $fail),
            SkillEvidence::TYPE_DESIGN_FILE => $this->validateDesignFile($scheme, $fail),
            SkillEvidence::TYPE_LIVE_DEPLOYMENT => null,
            default => null,
        };
    }

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

    private function validateDesignFile(string $scheme, Closure $fail): void
    {
        if ($scheme !== 'https') {
            $fail('The :attribute must use https for a design file.');
        }
    }

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
