<?php
declare(strict_types=1);
namespace Tihloh\SmsGate;

/** Explicit exports contain credentials; never log or expose them publicly. */
final class SetupProfile
{
    private readonly string $url;

    public function __construct(string $url, private readonly string $operator, #[\SensitiveParameter] private readonly string $token)
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)
            || trim($operator) === '' || strlen($operator) > 200 || preg_match('/[\x00-\x1f\x7f]/', $operator)
            || !preg_match('/^[\x21-\x7e]{1,512}$/D', $token)) throw new \InvalidArgumentException('Invalid setup configuration');
        $this->url = $url;
    }

    public function toArray(): array { return ['version' => 1, 'url' => $this->url, 'operator' => $this->operator, 'token' => $this->token]; }
    public function toJson(): string { return Support::json($this->toArray()); }
    public function __debugInfo(): array { return ['url' => $this->url, 'operator' => $this->operator, 'token' => '[redacted]']; }
}
