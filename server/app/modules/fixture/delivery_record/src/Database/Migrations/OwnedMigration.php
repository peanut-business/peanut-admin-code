<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Database\Migrations;

/** Static ownership metadata for the append-only SQL migration beside this class. */
final class OwnedMigration
{
    public const KEY = '20260814050101_create_fixture_delivery_records';
    public const MODULE_KEY = 'fixture.delivery-record';
    public const OWNED_TABLES = ['pa_fixture_delivery_record'];
    public const REVERSIBLE = false;

    private function __construct()
    {
    }
}
