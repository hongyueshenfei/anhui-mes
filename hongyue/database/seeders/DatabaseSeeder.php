<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 数据库总 Seeder（一期）
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminSeeder::class,
            WorkshopSeeder::class,
            ShiftConfigSeeder::class,
            OpTypeSeeder::class,
            MaterialSeeder::class, // 物料主数据必须先于产品 BOM（spec 匹配）
            ProductSeeder::class,
        ]);
    }
}