<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $udev = Merchant::firstOrCreate(
            ['merchant_code' => 'udev-central'],
            [
                'merchant_name' => 'UDEV Central Billing',
                'environment' => 'sandbox',
                'is_active' => true,
            ]
        );
        $udev->configuration()->firstOrCreate([], ['is_active' => true]);

        $larissama = Merchant::firstOrCreate(
            ['merchant_code' => 'larissama'],
            [
                'merchant_name' => 'Larissama',
                'environment' => 'sandbox',
                'is_active' => true,
            ]
        );
        $larissama->configuration()->firstOrCreate([], ['is_active' => true]);
    }
}
