<?php

declare(strict_types=1);

namespace App\Support;

use Core\Config;

class SecurityHeaders
{
    public function __construct(private Config $config)
    {
    }

    public function apply(): void
    {
        $settings = $this->config->get('security', []);
        if (!is_array($settings)) {
            return;
        }

        if (!empty($settings['remove_x_powered_by'])) {
            header_remove('X-Powered-By');
        }

        $headers = $settings['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if ($name !== '' && $value !== null && $value !== '') {
                    header($name . ': ' . $value);
                }
            }
        }

        $hsts = $settings['hsts'] ?? [];
        if (is_array($hsts) && !empty($hsts['enable'])) {
            $maxAge = max(0, (int)($hsts['max_age'] ?? 0));
            $headerValue = 'max-age=' . $maxAge;
            if (!empty($hsts['include_subdomains'])) {
                $headerValue .= '; includeSubDomains';
            }
            header('Strict-Transport-Security: ' . $headerValue);
        }

        $csp = $settings['csp'] ?? [];
        if (!is_array($csp)) {
            return;
        }

        $directives = [];
        $raw = $csp['directives'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $directive => $value) {
                if ($directive === '' || $value === null || $value === '') {
                    continue;
                }
                $directives[] = $directive . ' ' . $value;
            }
        }

        if ($directives === []) {
            return;
        }

        $mode = strtolower((string)($csp['mode'] ?? 'enforce')) === 'report-only'
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        header($mode . ': ' . implode('; ', $directives));
    }
}
