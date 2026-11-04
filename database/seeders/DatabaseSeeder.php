<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([GeoSeeder::class, RolesSeeder::class]);

        $municipality = (string) env('ERP_COMPANY_MUNICIPALITY', '11001');
        $isPerson = filter_var(env('ERP_COMPANY_IS_PERSON', false), FILTER_VALIDATE_BOOLEAN);
        $firstName = trim((string) env('ERP_COMPANY_NAME', ''));
        $surname = trim((string) env('ERP_COMPANY_SURNAME', ''));
        $businessName = $isPerson
            ? trim("$firstName $surname") ?: 'Persona natural'
            : env('ERP_COMPANY_NAME', 'Mi empresa');
        $tradeName = trim((string) env('ERP_COMPANY_TRADE_NAME', '')) ?: $businessName;

        $companyAttributes = [
            'business_name' => $businessName,
            'trade_name' => $tradeName,
            'municipality_code' => $municipality,
            'department_code' => (string) env('ERP_COMPANY_DEPARTMENT', substr($municipality, 0, 2)),
        ];
        if ($isPerson) {
            $companyAttributes += [
                'person_type' => 'natural',
                'identification_type' => '13',
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $surname !== '' ? $surname : null,
            ];
        }

        $company = Company::firstOrCreate(
            ['nit' => env('ERP_COMPANY_NIT', '000000000')],
            $companyAttributes,
        );

        $email = env('ERP_ADMIN_EMAIL', 'admin@example.com');
        if (! User::where('email', $email)->exists()) {
            $password = Str::password(14, symbols: false);
            $user = User::create([
                'company_id' => $company->id,
                'name' => env('ERP_ADMIN_NAME', 'Administrador'),
                'email' => $email,
                'password' => $password,
                'active' => true,
            ]);
            $user->assignRole('Administrador');
            $this->command->warn("Usuario administrador: $email / $password");
        }

        if (! \App\Models\Account::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $this->command->info('PUC: '.app(\App\Services\Accounting\PucLoader::class)->load($company->id).' cuentas cargadas');
        }

        $this->call(TaxSeeder::class);
        $this->operation($company->id);
    }

    /** Bodega y formas de pago mínimas para poder guardar el primer documento. */
    private function operation(int $companyId): void
    {
        $now = now();
        if (! \Illuminate\Support\Facades\DB::table('warehouses')->where('company_id', $companyId)->exists()) {
            \Illuminate\Support\Facades\DB::table('warehouses')->insert([
                'company_id' => $companyId, 'name' => 'Principal', 'is_default' => true, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if (\Illuminate\Support\Facades\DB::table('payment_methods')->where('company_id', $companyId)->exists()) {
            return;
        }
        $accounts = \Illuminate\Support\Facades\DB::table('accounts')->where('company_id', $companyId)->pluck('id', 'code');
        $rows = [
            ['Efectivo', 'CarteraProveedor', '10', '11050501', false, ['FV', 'FC', 'RC']],
            ['Banco', 'CarteraProveedor', '47', '11100501', false, ['FV', 'FC', 'RC']],
            ['Crédito a clientes', 'Cartera', 'ZZZ', '13050501', true, ['FV']],
            ['Crédito de proveedores', 'Cartera', 'ZZZ', '22050501', true, ['FC']],
        ];
        foreach ($rows as [$name, $type, $means, $code, $due, $docs]) {
            if (! isset($accounts[$code])) {
                continue;
            }
            \Illuminate\Support\Facades\DB::table('payment_methods')->insert([
                'company_id' => $companyId, 'name' => $name, 'type' => $type, 'means_code' => $means,
                'account_id' => $accounts[$code], 'due_date' => $due, 'document_types' => json_encode($docs),
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
