<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $this->importCountries();
        $this->importStates();
        Cache::forget('reference.countries');
        Cache::forget('reference.countries.v2');
    }

    private function importCountries(): void
    {
        $this->readCsv(database_path('data/countries.csv'), function (array $rows): void {
            $now = now();
            $records = array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'code' => $row['iso2'],
                'iso3' => $row['iso3'] ?: null,
                'phone_code' => $row['phonecode'] ?: null,
                'currency_code' => $row['currency'] ?: null,
                'currency_name' => $row['currency_name'] ?: null,
                'region' => $row['region'] ?: null,
                'subregion' => $row['subregion'] ?: null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);

            DB::table('countries')->upsert($records, ['id'], [
                'name', 'code', 'iso3', 'phone_code', 'currency_code',
                'currency_name', 'region', 'subregion', 'is_active', 'updated_at',
            ]);
        });
    }

    private function importStates(): void
    {
        $this->readCsv(database_path('data/states.csv'), function (array $rows): void {
            $now = now();
            $records = array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'country_id' => (int) $row['country_id'],
                'name' => $row['name'],
                'code' => $row['iso2'] ?: null,
                'type' => $row['type'] ?: null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows);

            DB::table('states')->upsert($records, ['id'], [
                'country_id', 'name', 'code', 'type', 'is_active', 'updated_at',
            ]);
        });
    }

    /**
     * @param  callable(array<int, array<string, string>>): void  $consume
     */
    private function readCsv(string $path, callable $consume): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open location data file: {$path}");
        }

        $headers = fgetcsv($handle, escape: '');
        $batch = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }

            $batch[] = array_combine($headers, $values);

            if (count($batch) === 500) {
                $consume($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $consume($batch);
        }

        fclose($handle);
    }
}
