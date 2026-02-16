<?php

declare(strict_types=1);

namespace LaraPardakht\DTOs;

/**
 * Represents a redirect response to a payment gateway page.
 */
class RedirectResponse
{
    /**
     * @param string $url The URL to redirect to
     * @param array<string, mixed> $data Additional data to pass (for POST redirects)
     * @param string $method HTTP method for redirect (GET or POST)
     */
    public function __construct(
        protected readonly string $url,
        protected readonly array $data = [],
        protected readonly string $method = 'GET',
    ) {}

    /**
     * Get the redirect URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get additional data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Render the redirect as an HTTP redirect response.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function render(): \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
    {
        if ($this->method === 'GET') {
            return redirect()->away($this->url);
        }

        // For POST redirects, render an auto-submitting form
        $inputs = '';
        foreach ($this->data as $key => $value) {
            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
            );
        }

        $html = sprintf(
            '<html><body><form id="payment-form" action="%s" method="POST">%s</form>'
            . '<script>document.getElementById("payment-form").submit();</script></body></html>',
            htmlspecialchars($this->url, ENT_QUOTES, 'UTF-8'),
            $inputs
        );

        return response($html);
    }

    /**
     * Get the redirect data as JSON.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toJson(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'url' => $this->url,
            'method' => $this->method,
            'data' => $this->data,
        ]);
    }
}
