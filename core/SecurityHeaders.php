<?php

namespace Core;

class SecurityHeaders
{
    public function __construct(private Config $config)
    {
    }

    public function apply(?Request $request = null): void
    {
        $remove = $this->config->get('security.remove_headers', []);
        if (is_array($remove)) {
            foreach ($remove as $header) {
                if (is_string($header) && $header !== '') {
                    header_remove($header);
                }
            }
        }

        $headers = $this->config->get('security.headers', []);
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                header($name . ': ' . (string)$value);
            }
        }

        $this->applyHsts($request);
        $this->applyCsp();
    }

    private function applyHsts(?Request $request): void
    {
        $hsts = $this->config->get('security.hsts', []);
        if (!is_array($hsts) || empty($hsts['enable'])) {
            return;
        }

        if (!$this->isHttps($request)) {
            return;
        }

        $maxAge = (int)($hsts['max_age'] ?? 0);
        if ($maxAge <= 0) {
            return;
        }

        $value = 'max-age=' . $maxAge;
        if (!empty($hsts['include_subdomains'])) {
            $value .= '; includeSubDomains';
        }

        header('Strict-Transport-Security: ' . $value);
    }

    private function applyCsp(): void
    {
        $csp = $this->config->get('security.csp', []);
        if (!is_array($csp)) {
            return;
        }

        $directives = $csp['directives'] ?? [];
        if (!is_array($directives) || $directives === []) {
            return;
        }

        $parts = [];
        foreach ($directives as $directive => $value) {
            if (!is_string($directive) || $directive === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(' ', $value);
            }
            $parts[] = $directive . ' ' . (string)$value;
        }

        if ($parts === []) {
            return;
        }

        $mode = strtolower((string)($csp['mode'] ?? 'enforce'));
        $header = $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        header($header . ': ' . implode('; ', $parts));
    }

    private function isHttps(?Request $request): bool
    {
        $https = null;
        $forwardedProto = null;
        if ($request) {
            $https = $request->server('HTTPS');
            $forwardedProto = $request->server('HTTP_X_FORWARDED_PROTO');
        } else {
            $https = $_SERVER['HTTPS'] ?? null;
            $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        }

        if (is_string($https) && strtolower($https) !== 'off' && $https !== '') {
            return true;
        }

        if (is_string($forwardedProto)) {
            return str_contains(strtolower($forwardedProto), 'https');
        }

        return false;
    }
}
