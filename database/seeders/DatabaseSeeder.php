<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ────────────────────────────────────────────────
        $roles = [
            ['name' => 'Administrator', 'description' => 'Full system access'],
            ['name' => 'Manager',       'description' => 'Manage daily operations'],
            ['name' => 'Cashier',       'description' => 'Handle sales and POS'],
            ['name' => 'Accountant',    'description' => 'Manage financial records'],
        ];
        foreach ($roles as $r) Role::firstOrCreate(['name' => $r['name']], $r);

        $adminRole   = Role::where('name', 'Administrator')->first();
        $managerRole = Role::where('name', 'Manager')->first();
        $cashierRole = Role::where('name', 'Cashier')->first();

        // ── Users ────────────────────────────────────────────────
        User::firstOrCreate(['username' => 'admin'], [
            'employee_id' => 'EMP-0001',
            'name'        => 'System Administrator',
            'email'       => 'admin@cosmeticsshop.ug',
            'phone'       => '+256700000001',
            'role_id'     => $adminRole->id,
            'password'    => Hash::make('admin123'),
            'status'      => 'active',
        ]);
        User::firstOrCreate(['username' => 'manager'], [
            'employee_id' => 'EMP-0002',
            'name'        => 'Shop Manager',
            'email'       => 'manager@cosmeticsshop.ug',
            'phone'       => '+256700000002',
            'role_id'     => $managerRole->id,
            'password'    => Hash::make('manager123'),
            'status'      => 'active',
        ]);
        User::firstOrCreate(['username' => 'cashier1'], [
            'employee_id' => 'EMP-0003',
            'name'        => 'Sarah Namukasa',
            'email'       => 'sarah@cosmeticsshop.ug',
            'phone'       => '+256700000003',
            'role_id'     => $cashierRole->id,
            'password'    => Hash::make('cashier123'),
            'status'      => 'active',
        ]);

        // ── Categories ───────────────────────────────────────────
        $categoryNames = [
            'Skin Care', 'Hair Care', 'Fragrances', 'Makeup',
            'Nail Care', 'Personal Care', 'Baby Care', 'Men\'s Grooming',
        ];
        foreach ($categoryNames as $name) {
            Category::firstOrCreate(['name' => $name], ['status' => 'active']);
        }

        // ── Brands ───────────────────────────────────────────────
        $brands = [
            ['name' => 'Nivea',      'country_of_origin' => 'Germany'],
            ['name' => 'Vaseline',   'country_of_origin' => 'USA'],
            ['name' => 'Dove',       'country_of_origin' => 'UK'],
            ['name' => 'CeraVe',     'country_of_origin' => 'USA'],
            ['name' => 'Garnier',    'country_of_origin' => 'France'],
            ['name' => "L'Oréal",    'country_of_origin' => 'France'],
            ['name' => 'Maybelline', 'country_of_origin' => 'USA'],
            ['name' => 'Neutrogena', 'country_of_origin' => 'USA'],
            ['name' => 'Olay',       'country_of_origin' => 'USA'],
            ['name' => 'Revlon',     'country_of_origin' => 'USA'],
            ['name' => 'ORS',        'country_of_origin' => 'USA'],
            ['name' => 'Dark & Lovely', 'country_of_origin' => 'USA'],
        ];
        foreach ($brands as $b) {
            Brand::firstOrCreate(['name' => $b['name']], $b + ['status' => 'active']);
        }

        // ── Suppliers ────────────────────────────────────────────
        $suppliers = [
            [
                'supplier_code'  => 'SUP-0001',
                'name'           => 'Kampala Cosmetics Wholesale',
                'contact_person' => 'John Ssemakula',
                'phone'          => '+256712000001',
                'email'          => 'kampala@cosmeticswholesale.ug',
                'address'        => 'Kikuubo, Kampala',
            ],
            [
                'supplier_code'  => 'SUP-0002',
                'name'           => 'Beauty Supplies Uganda Ltd',
                'contact_person' => 'Mary Nakabuye',
                'phone'          => '+256712000002',
                'email'          => 'info@beautysupplies.ug',
                'address'        => 'Industrial Area, Kampala',
            ],
        ];
        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(['supplier_code' => $s['supplier_code']], $s + ['balance' => 0, 'status' => 'active']);
        }

        // ── Products ─────────────────────────────────────────────
        $skinCare  = Category::where('name', 'Skin Care')->first();
        $hairCare  = Category::where('name', 'Hair Care')->first();
        $fragrance = Category::where('name', 'Fragrances')->first();
        $makeup    = Category::where('name', 'Makeup')->first();
        $personal  = Category::where('name', 'Personal Care')->first();

        $nivea     = Brand::where('name', 'Nivea')->first();
        $vaseline  = Brand::where('name', 'Vaseline')->first();
        $dove      = Brand::where('name', 'Dove')->first();
        $loreal    = Brand::where('name', "L'Oréal")->first();
        $maybelline= Brand::where('name', 'Maybelline')->first();
        $supplier1 = Supplier::where('supplier_code', 'SUP-0001')->first();

        $products = [
            [
                'sku' => 'SKU-NBLOT400', 'name' => 'Nivea Body Lotion 400ml',
                'category_id' => $skinCare->id, 'brand_id' => $nivea->id,
                'unit' => 'bottle', 'purchase_price' => 12000, 'selling_price' => 18000,
                'reorder_level' => 10, 'opening_stock' => 50,
            ],
            [
                'sku' => 'SKU-NBLOT200', 'name' => 'Nivea Soft Cream 200ml',
                'category_id' => $skinCare->id, 'brand_id' => $nivea->id,
                'unit' => 'jar', 'purchase_price' => 8000, 'selling_price' => 13000,
                'reorder_level' => 10, 'opening_stock' => 35,
            ],
            [
                'sku' => 'SKU-VASB250', 'name' => 'Vaseline Body Lotion 250ml',
                'category_id' => $skinCare->id, 'brand_id' => $vaseline->id,
                'unit' => 'bottle', 'purchase_price' => 7500, 'selling_price' => 12000,
                'reorder_level' => 10, 'opening_stock' => 40,
            ],
            [
                'sku' => 'SKU-DOVESH200', 'name' => 'Dove Shower Gel 200ml',
                'category_id' => $personal->id, 'brand_id' => $dove->id,
                'unit' => 'bottle', 'purchase_price' => 9000, 'selling_price' => 15000,
                'reorder_level' => 8, 'opening_stock' => 25,
            ],
            [
                'sku' => 'SKU-DOVEBAR', 'name' => 'Dove Beauty Bar Soap',
                'category_id' => $personal->id, 'brand_id' => $dove->id,
                'unit' => 'piece', 'purchase_price' => 3500, 'selling_price' => 5500,
                'reorder_level' => 20, 'opening_stock' => 80,
            ],
            [
                'sku' => 'SKU-LHAIR250', 'name' => "L'Oréal Elvive Shampoo 250ml",
                'category_id' => $hairCare->id, 'brand_id' => $loreal->id,
                'unit' => 'bottle', 'purchase_price' => 15000, 'selling_price' => 24000,
                'reorder_level' => 8, 'opening_stock' => 20,
            ],
            [
                'sku' => 'SKU-MAYLIP', 'name' => 'Maybelline Lipstick',
                'category_id' => $makeup->id, 'brand_id' => $maybelline->id,
                'unit' => 'piece', 'purchase_price' => 18000, 'selling_price' => 30000,
                'reorder_level' => 5, 'opening_stock' => 15,
            ],
            [
                'sku' => 'SKU-NDEOD', 'name' => 'Nivea Deodorant Roll-on 50ml',
                'category_id' => $fragrance->id, 'brand_id' => $nivea->id,
                'unit' => 'piece', 'purchase_price' => 6000, 'selling_price' => 10000,
                'reorder_level' => 10, 'opening_stock' => 45,
            ],
            [
                'sku' => 'SKU-VASJELLY', 'name' => 'Vaseline Petroleum Jelly 100g',
                'category_id' => $skinCare->id, 'brand_id' => $vaseline->id,
                'unit' => 'jar', 'purchase_price' => 4500, 'selling_price' => 7000,
                'reorder_level' => 15, 'opening_stock' => 60,
            ],
            [
                'sku' => 'SKU-NLIP', 'name' => 'Nivea Lip Balm',
                'category_id' => $skinCare->id, 'brand_id' => $nivea->id,
                'unit' => 'piece', 'purchase_price' => 3000, 'selling_price' => 5000,
                'reorder_level' => 10, 'opening_stock' => 5, // Low stock on purpose
            ],
        ];

        $adminUser = User::where('username', 'admin')->first();

        foreach ($products as $p) {
            if (!Product::where('sku', $p['sku'])->exists()) {
                $openingStock = $p['opening_stock'];
                unset($p['opening_stock']);
                $product = Product::create($p + [
                    'supplier_id' => $supplier1->id,
                    'status'      => 'active',
                    'barcode'     => $p['sku'],
                ]);
                Inventory::create(['product_id' => $product->id, 'quantity' => $openingStock]);
            }
        }

        // ── Customers ────────────────────────────────────────────
        $customers = [
            ['customer_code' => 'CUST-0001', 'name' => 'Grace Namutebi',  'phone' => '+256772000001', 'customer_type' => 'retail',     'credit_limit' => 0],
            ['customer_code' => 'CUST-0002', 'name' => 'Agnes Nakitto',   'phone' => '+256772000002', 'customer_type' => 'credit',     'credit_limit' => 500000],
            ['customer_code' => 'CUST-0003', 'name' => 'Beauty Salon Ltd','phone' => '+256772000003', 'customer_type' => 'wholesale',  'credit_limit' => 2000000],
            ['customer_code' => 'CUST-0004', 'name' => 'Prossy Nakiggwe', 'phone' => '+256772000004', 'customer_type' => 'vip',        'credit_limit' => 1000000],
        ];
        foreach ($customers as $c) {
            Customer::firstOrCreate(['customer_code' => $c['customer_code']], $c + ['balance' => 0, 'status' => 'active']);
        }

        // ── Expense Categories ───────────────────────────────────
        $expCats = ['Rent', 'Electricity', 'Water', 'Internet', 'Transport',
                    'Salaries', 'Packaging', 'Advertising', 'Repairs',
                    'Stationery', 'Bank Charges', 'Mobile Money Charges', 'Other'];
        foreach ($expCats as $cat) {
            ExpenseCategory::firstOrCreate(['name' => $cat], ['status' => 'active']);
        }

        // ── System Settings ──────────────────────────────────────
        $settings = [
            ['key' => 'shop_name',            'value' => 'Beauty & Glow Cosmetics', 'group' => 'business'],
            ['key' => 'shop_address',          'value' => 'Kampala Road, Kampala, Uganda', 'group' => 'business'],
            ['key' => 'shop_phone',            'value' => '+256700123456', 'group' => 'business'],
            ['key' => 'shop_email',            'value' => 'info@beautyandglow.ug', 'group' => 'business'],
            ['key' => 'currency',              'value' => 'UGX', 'group' => 'sales'],
            ['key' => 'receipt_prefix',        'value' => 'CS', 'group' => 'sales'],
            ['key' => 'tax_rate',              'value' => '0', 'group' => 'sales'],
            ['key' => 'allow_negative_stock',  'value' => 'no', 'group' => 'inventory'],
            ['key' => 'reorder_level_default', 'value' => '10', 'group' => 'inventory'],
            ['key' => 'expiry_warning_days',   'value' => '30', 'group' => 'inventory'],
            ['key' => 'max_discount_percent',  'value' => '20', 'group' => 'sales'],
            ['key' => 'return_policy_days',    'value' => '7', 'group' => 'sales'],
        ];
        foreach ($settings as $s) {
            Setting::firstOrCreate(['key' => $s['key']], $s);
        }

        $this->command->info('✅ Database seeded successfully!');
        $this->command->info('   Admin login  → username: admin    | password: admin123');
        $this->command->info('   Manager login→ username: manager  | password: manager123');
        $this->command->info('   Cashier login→ username: cashier1 | password: cashier123');
    }
}
