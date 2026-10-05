<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Service;

use Contenir\FormBuilder\Service\FormSubmissionService;
use Override;

use function is_file;

/**
 * Treats any existing file as an HTTP upload, so the upload path can run
 * outside a real request.
 */
final class AcceptingUploadsSubmissionService extends FormSubmissionService
{
    #[Override]
    protected function isUploadedFile(string $path): bool
    {
        return is_file($path);
    }
}
