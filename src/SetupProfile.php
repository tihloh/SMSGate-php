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

    /**
     * Return a ready-to-display pairing QR image (no filesystem or host-side
     * QR library needed). SVG is the default to avoid requiring the GD extension.
     * The QR encodes this exact setup JSON and therefore contains a bearer token.
     */
    public function qrSvg(int $size = 400): string
    {
        self::checkQrSize($size);
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        return (new \BaconQrCode\Writer($renderer))->writeString($this->toJson());
    }

    /** Return PNG image bytes when the server has PHP GD enabled. */
    public function qrPng(int $size = 400): string
    {
        self::checkQrSize($size);
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD is required to generate a PNG QR image. Use qrSvg() instead.');
        }
        return (new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\GDLibRenderer($size)
        ))->writeString($this->toJson());
    }

    /** Embed directly into a private HTML <img src="..."> without image files. */
    public function qrDataUri(string $format = 'svg', int $size = 400): string
    {
        return match (strtolower($format)) {
            'svg' => 'data:image/svg+xml;base64,' . base64_encode($this->qrSvg($size)),
            'png' => 'data:image/png;base64,' . base64_encode($this->qrPng($size)),
            default => throw new \InvalidArgumentException('QR image format must be svg or png.'),
        };
    }

    private static function checkQrSize(int $size): void
    {
        if ($size < 240 || $size > 1600) {
            throw new \InvalidArgumentException('QR image size must be between 240 and 1600 pixels.');
        }
    }
    public function __debugInfo(): array { return ['url' => $this->url, 'operator' => $this->operator, 'token' => '[redacted]']; }
}
