<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools\Service;

use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;

/** Request/application composition scoped registry; only trusted provider bindings register. */
final class ContentToolRegistry
{
    /** @var array<string,SurfaceAdapterRegistration> */
    private array $adapters = [];

    public function register(SurfaceAdapterRegistration $adapter): void
    {
        if (isset($this->adapters[$adapter->id])) {
            throw new \LogicException('CONTENT_ADAPTER_DUPLICATE');
        }
        $this->adapters[$adapter->id] = $adapter;
    }

    /** @return list<SurfaceAdapterRegistration> */
    public function registrations(): array
    {
        return array_values($this->adapters);
    }

    public function selected(string $id, AuthorizedContentContext $context, string $intent): ?SurfaceAdapterRegistration
    {
        $adapter = $this->adapters[$id] ?? null;
        if ($adapter === null || !in_array($intent, $adapter->intents, true)
            || !in_array($context->contentKind, $adapter->contentKinds, true)
            || ($context->contentKind === 'file_resource'
                && !in_array($context->verifiedMimeType, $adapter->mimeTypes, true))) {
            return null;
        }
        return $adapter;
    }

    public static function withBuiltins(): self
    {
        $registry = new self();
        foreach ([
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
            'pdf' => ['application/pdf'],
            'audio' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4'],
            'video' => ['video/mp4', 'video/webm', 'video/ogg'],
        ] as $kind => $mimeTypes) {
            $registry->register(new SurfaceAdapterRegistration(
                'builtin.' . $kind,
                'official.content-tools',
                '1.0.0',
                ['file_resource'],
                $mimeTypes,
                ['view'],
            ));
        }
        return $registry;
    }
}
