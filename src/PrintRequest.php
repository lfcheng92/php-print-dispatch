<?php
declare(strict_types=1);

namespace Lfcheng\PrintBridge;

final readonly class PrintRequest
{
    public function __construct(
        public string $printer,
        public string $bytes,
        public string $mediaType = 'application/pdf',
        public int $copies = 1,
    ) {
        self::validatePrinter($printer);
        if ($copies < 1 || $copies > 99) {
            throw new \InvalidArgumentException('Copies must be between 1 and 99.');
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('Document must not be empty.');
        }
        if (!in_array($mediaType, ['application/pdf', 'text/plain'], true)) {
            throw new \InvalidArgumentException('Only PDF and UTF-8 plain text are supported.');
        }
        if ($mediaType === 'application/pdf' && !str_starts_with($bytes, '%PDF-')) {
            throw new \InvalidArgumentException('PDF header is missing.');
        }
        if ($mediaType === 'text/plain'
            && (preg_match('//u', $bytes) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bytes) === 1)) {
            throw new \InvalidArgumentException('Text must be valid UTF-8 without control codes.');
        }
    }

    public static function validatePrinter(string $printer): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,126}\z/D', $printer) !== 1) {
            throw new \InvalidArgumentException('Printer must be a simple local allowlist name.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->printer, $this->mediaType, $this->copies, hash('sha256', $this->bytes),
        ], JSON_THROW_ON_ERROR));
    }
}
