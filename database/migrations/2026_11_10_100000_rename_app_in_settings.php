<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('pwa.pwa_app_name', fn (): string => 'کابل جدید');
        $this->migrator->update('pwa.pwa_short_name', fn (): string => 'کابل جدید');
        $this->migrator->update('sites.site_name', fn (): string => 'کابل جدید');
    }
};
