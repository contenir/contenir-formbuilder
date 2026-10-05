<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Storage;

use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\ResolvedUpload;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Override;

/**
 * Records every upload handed to the storage backend, then resolves it with
 * the default resolver. The default resolver ignores the client MIME type, so
 * this is how a test sees what FormBuilder passed along.
 */
final class RecordingUploadResolver implements UploadResolverInterface
{
    /** @var list<UploadInput> */
    public array $uploads = [];

    private readonly DefaultUploadResolver $inner;

    public function __construct()
    {
        $this->inner = new DefaultUploadResolver();
    }

    #[Override]
    public function resolve(UploadInput $upload): ResolvedUpload
    {
        $this->uploads[] = $upload;

        return $this->inner->resolve($upload);
    }
}
