<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Database\Migrations;

/** SQL 仍在模块根 database/migrations；此类只提供稳定的迁移归属元数据。 */
final class OwnedMigration
{
    public const KEY = '20260814050101_create_fixture_delivery_records';
    public const MODULE_KEY = 'fixture.delivery-record';
    public const OWNED_TABLES = ['pa_fixture_delivery_record'];
    public const REVERSIBLE = false;

    private function __construct() {}
}
