<?php

declare(strict_types=1);

return [
    'enabled' => env('COLLABORATION_ENABLED', 'disabled') === 'enabled',
    // Stable deployment identity, never a hostname or a client-provided Room name.
    'instance_id' => env('COLLABORATION_INSTANCE_ID', ''),
    'service_instance_id' => env('COLLABORATION_SERVICE_INSTANCE_ID', ''),
    'key_id' => env('COLLABORATION_SERVICE_KEY_ID', ''),
    'secret' => env('COLLABORATION_SECRET', ''),
    // Registered HTTPS service ingress. E1 managed loader supplies it; no loopback/TLS fallback.
    'control_origin' => env('COLLABORATION_CONTROL_ORIGIN', ''),
    // Public TLS ingress used by authorized browser transports.
    'public_wss_url' => env('COLLABORATION_PUBLIC_WSS_URL', ''),
    'ca_bundle_file' => env('COLLABORATION_CA_FILE', '') === 'private/collaboration/service/ca.pem'
        ? dirname(__DIR__, 5) . '/private/collaboration/service/ca.pem' : '',
    'ca_sha256' => env('COLLABORATION_CA_SHA256', ''),
    'clock_window_seconds' => 30,
    'connection_lease_seconds' => 15,
    'room_lease_seconds' => 30,
    'max_request_bytes' => 8388608,
    // Bounded native baseline/capture, base64 expansion and metadata.
    'max_response_bytes' => 33554432,
    'projector_version' => 'peanut.richtext-yjs/1',
];
