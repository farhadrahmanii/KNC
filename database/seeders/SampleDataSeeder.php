<?php

namespace Database\Seeders;

use App\Models\CustomerNumeraha;
use App\Models\Customers;
use App\Models\Employees;
use App\Models\Finance;
use App\Models\Markets;
use App\Models\Numeraha;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Demo rows for a local or staging back office.
 *
 * php artisan db:seed --class=SampleDataSeeder
 *
 * Sample plots have numera_id DEMO-0001 … and sample customers have
 * mobile numbers 07990000NN, so they can be found and removed later.
 */
class SampleDataSeeder extends Seeder
{
    private const PLOT_PREFIX = 'DEMO-';

    private const PLOT_COUNT = 30;

    private const CUSTOMER_IMAGE = 'Customers_images/sample-customer.png';

    private const RESPONSIBLE_IMAGE = 'responsable_images/sample-responsible.png';

    /** @var array<string, int> plot type => square metres */
    private const PLOT_TYPES = [
        '10 بسوه ای' => 1000,
        '9 بسوه ای' => 900,
        '8 بسوه ای' => 800,
        '7 بسوه ای' => 700,
        '6 بسوه ای' => 600,
        '5 بسوه ای' => 500,
        '4 بسوه ای' => 400,
        '3 بسوه ای' => 300,
        '2.5 بسوه ای' => 250,
    ];

    private const PROVINCES = ['کابل', 'ننگرهار', 'قندهار', 'هرات', 'بلخ', 'غزنی', 'لوگر', 'خوست', 'کندز', 'هلمند'];

    private const NAMES = [
        ['احمد', 'محمد', 'عبدالله'], ['محمود', 'عبدالرحمن', 'ګل محمد'], ['حمیدالله', 'نورالله', 'شیر محمد'],
        ['فرید', 'عبدالقادر', 'خان محمد'], ['ذبیح الله', 'سید احمد', 'سید جان'], ['نجیب الله', 'عبدالغنی', 'دوست محمد'],
        ['روح الله', 'عبدالحکیم', 'فیض محمد'], ['اجمل', 'محمد نعیم', 'عبدالصمد'], ['جاوید', 'غلام سخی', 'محمد عمر'],
        ['سمیع الله', 'حاجی نادر', 'ملا عزیز'], ['عبدالباري', 'عبدالواحد', 'محمد اکبر'], ['شفیق الله', 'محمد ګل', 'سردار محمد'],
        ['وحید', 'عبدالستار', 'نور احمد'], ['کریم', 'عبدالجبار', 'محمد یوسف'], ['اسدالله', 'محمد هاشم', 'ګل احمد'],
        ['مرتضی', 'غلام حیدر', 'محمد ابراهیم'], ['بشیر احمد', 'عبدالمنان', 'حاجی موسی'], ['ناصر', 'محمد ظاهر', 'عبدالرشید'],
        ['عزیزالله', 'محمد رفیق', 'خوشحال خان'], ['حکمت الله', 'عبدالمجید', 'محمد صدیق'],
    ];

    private const JOBS = ['سوداګر', 'ښوونکی', 'انجنیر', 'ډاکټر', 'دوکاندار', 'کروندګر', 'دولتی مامور', 'قراردادی'];

    private const VILLAGES = ['ده سبز', 'شکردره', 'پغمان', 'خاک جبار', 'بګرامی', 'قلعه وزیر', 'چهار آسیاب', 'میربچه کوټ'];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('SampleDataSeeder does not run in production.');

            return;
        }

        if (Numeraha::withArchived()->where('numera_id', 'like', self::PLOT_PREFIX.'%')->exists()) {
            $this->command->warn('Sample data already exists (plots starting with '.self::PLOT_PREFIX.'). Nothing was added.');

            return;
        }

        mt_srand(20261110);
        [$customerImage, $responsibleImage] = $this->writePlaceholderImages();

        DB::transaction(function () use ($customerImage, $responsibleImage): void {
            $plots = $this->seedPlots();
            $customers = $this->seedCustomers($customerImage, $responsibleImage);
            $sold = $this->seedSales($plots, $customers);
            $this->seedMarkets($sold);
            $this->seedEmployees();
            $this->seedFinance();
            $this->archiveTwoUnsoldPlots($plots, $sold);
        });

        $this->command->info('Sample data added.');
    }

    /**
     * @return list<Numeraha>
     */
    private function seedPlots(): array
    {
        $types = array_keys(self::PLOT_TYPES);
        $sides = ['سړک ۱۲ متره', 'سړک ۸ متره', 'ویاله', 'شنه ساحه', 'جومات', 'ښوونځی', 'پارک'];
        $plots = [];

        for ($i = 1; $i <= self::PLOT_COUNT; $i++) {
            $type = $types[($i - 1) % count($types)];
            $code = self::PLOT_PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $created = $this->dateInLastYear();

            $plot = new Numeraha([
                'numera_id' => $code,
                'numera_type' => $type,
                'Land_Area' => (string) self::PLOT_TYPES[$type],
                'date' => $created->toDateString(),
                'sharwali_tarifa_price' => (string) (self::PLOT_TYPES[$type] * 50),
                'north' => $sides[mt_rand(0, count($sides) - 1)],
                'south' => self::PLOT_PREFIX.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT).' نمره',
                'east' => $sides[mt_rand(0, count($sides) - 1)],
                'west' => self::PLOT_PREFIX.str_pad((string) max(1, $i - 1), 4, '0', STR_PAD_LEFT).' نمره',
                'description' => '<p>نمونه نمره د ازموینی لپاره.</p>',
            ]);
            $plot->created_at = $created;
            $plot->updated_at = $created;
            $plot->save();

            $plots[] = $plot;
        }

        return $plots;
    }

    /**
     * @return list<Customers>
     */
    private function seedCustomers(?string $customerImage, ?string $responsibleImage): array
    {
        $customers = [];

        foreach (self::NAMES as $index => [$name, $father, $grandFather]) {
            $number = $index + 1;
            $hasResponsible = $number % 3 === 0;
            $responsible = self::NAMES[(count(self::NAMES) - 1) - $index];

            $customer = Customers::create([
                'name' => $name,
                'father_name' => $father,
                'grand_father_name' => $grandFather,
                'province' => self::PROVINCES[$index % count(self::PROVINCES)],
                'village' => self::VILLAGES[$index % count(self::VILLAGES)],
                'tazkira' => '1400-'.str_pad((string) (1100 + $number), 4, '0', STR_PAD_LEFT).'-'.str_pad((string) mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT),
                'mobile_number' => '07990000'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'job' => self::JOBS[$index % count(self::JOBS)],
                'Customer_image' => $customerImage,
                'responsable_name' => $hasResponsible ? $responsible[0] : null,
                'responsable_father_name' => $hasResponsible ? $responsible[1] : null,
                'responsable_grand_father_name' => $hasResponsible ? $responsible[2] : null,
                'responsable_province' => $hasResponsible ? self::PROVINCES[($index + 4) % count(self::PROVINCES)] : null,
                'responsable_village' => $hasResponsible ? self::VILLAGES[($index + 3) % count(self::VILLAGES)] : null,
                'responsable_tazkira' => $hasResponsible ? '1401-'.str_pad((string) (2200 + $number), 4, '0', STR_PAD_LEFT).'-'.mt_rand(10000, 99999) : null,
                'responsable_mobile_number' => $hasResponsible ? '07980000'.str_pad((string) $number, 2, '0', STR_PAD_LEFT) : null,
                'responsable_image' => $hasResponsible ? $responsibleImage : null,
                'responsable_job' => $hasResponsible ? self::JOBS[($index + 2) % count(self::JOBS)] : null,
            ]);

            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * Sells 18 of the 30 plots. Customers 1–3 buy two plots each,
     * customers 16–20 buy nothing.
     *
     * @param  list<Numeraha>  $plots
     * @param  list<Customers>  $customers
     * @return list<CustomerNumeraha>
     */
    private function seedSales(array $plots, array $customers): array
    {
        $buyers = [0, 0, 1, 1, 2, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];
        $remarks = [null, 'پاتی قسطونه به په راتلونکی میاشت کی ورکړی.', 'ټولی پیسی نغدی ورکړل شوی.', 'د سند کاپی په دفتر کی ده.'];
        $sales = [];

        foreach ($buyers as $saleIndex => $customerIndex) {
            $plot = $plots[$saleIndex];
            $total = (int) round(self::PLOT_TYPES[$plot->numera_type] * mt_rand(900, 2600) / 10000) * 10000;
            $paidPhases = $saleIndex % 5 === 0 ? 6 : mt_rand(1, 5);
            $phases = $this->installments($total, $paidPhases);

            $sale = new CustomerNumeraha([
                'customer_id' => $customers[$customerIndex]->id,
                'numeraha_id' => $plot->id,
                'total_price' => (string) $total,
                'first_phase' => $phases[0],
                'second_phase' => $phases[1],
                'third_phase' => $phases[2],
                'fourth_phase' => $phases[3],
                'fifth_phase' => $phases[4],
                'sixth_phase' => $phases[5],
                'payed_price' => (string) array_sum(array_map('intval', array_filter($phases))),
                'remarks' => $remarks[$saleIndex % count($remarks)],
            ]);
            $soldAt = $plot->created_at->addDays(mt_rand(1, 20));
            $sale->created_at = $soldAt->isFuture() ? now() : $soldAt;
            $sale->updated_at = $sale->created_at;
            $sale->save();

            $sales[] = $sale;
        }

        return $sales;
    }

    /**
     * First installment is 30% of the price, the rest is split over five.
     * A fully paid sale sums exactly to the total.
     *
     * @return list<string|null>
     */
    private function installments(int $total, int $paidPhases): array
    {
        $first = (int) (round($total * 0.3 / 1000) * 1000);
        $rest = (int) (round(($total - $first) / 5 / 1000) * 1000);
        $phases = [$first, $rest, $rest, $rest, $rest, $total - $first - (4 * $rest)];

        return array_map(
            fn (int $amount, int $index): ?string => $index < $paidPhases ? (string) $amount : null,
            $phases,
            array_keys($phases),
        );
    }

    /**
     * @param  list<CustomerNumeraha>  $sales
     */
    private function seedMarkets(array $sales): void
    {
        $names = ['د ښار مارکیټ', 'د ګل مارکیټ', 'سوداګریز مرکز', 'د نوی ښار مارکیټ', 'لوی مارکیټ'];

        foreach ($names as $index => $name) {
            $sale = $sales[$index * 3];
            $customer = Customers::find($sale->customer_id);

            Markets::create([
                'name' => $name,
                'description' => 'نمونه مارکیټ، '.($index + 2).' پوړیز.',
                'lengthm_m2' => (string) (200 + ($index * 75)),
                'nomerah_number' => $sale->numeraha_id,
                'nomerah_owner' => (string) $customer->id,
                'owner_phone_number' => $customer->mobile_number,
            ]);
        }
    }

    private function seedEmployees(): void
    {
        $staff = [
            ['نصیر', 'احمدزی', 'عبدالله', 'مدیر', 'ماستری', '45000'],
            ['فهیم', 'سلطانی', 'محمد نعیم', 'محاسب', 'لیسانس', '30000'],
            ['ګلالۍ', 'وردګ', 'عبدالرحیم', 'منشی', 'لیسانس', '22000'],
            ['زبیر', 'کاکړ', 'محمد اکبر', 'انجنیر', 'لیسانس', '38000'],
            ['ولی', 'صافی', 'شیر احمد', 'ساتونکی', 'بکلوریا', '15000'],
            ['رحمت', 'نوری', 'حاجی ګل', 'موټر چلوونکی', 'بکلوریا', '16000'],
        ];

        foreach ($staff as $index => [$name, $lastName, $father, $position, $education, $salary]) {
            $start = CarbonImmutable::now()->subMonths(18 - ($index * 2))->startOfMonth();

            Employees::create([
                'name' => $name,
                'lastName' => $lastName,
                'FatherName' => $father,
                'Position' => $position,
                'Education' => $education,
                'salary' => $salary,
                'tazkira' => (string) (13990000000 + ($index * 1111)),
                'date_of_contract' => $start->toDateString(),
                'end_date_of_contract' => $start->addYears(2)->toDateString(),
                'phone_number' => '07970000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'Address' => 'کابل، '.self::VILLAGES[$index % count(self::VILLAGES)],
            ]);
        }
    }

    private function seedFinance(): void
    {
        $userId = User::query()->orderBy('id')->value('id');
        if ($userId === null) {
            $this->command->warn('No user exists, so no finance rows were added.');

            return;
        }

        $expenses = [
            ['سمنټ (بوجۍ)', 120, 450, 0, 0],
            ['سیخ (ټن)', 3, 52000, 0, 0],
            ['د سړک جوړولو کرایه', 1, 0, 1, 2500],
            ['د دفتر کرایه', 1, 35000, 0, 0],
            ['تیل (لیتر)', 200, 75, 0, 0],
            ['د اوبو څاه', 1, 0, 1, 4200],
            ['برښنا بل', 1, 8200, 0, 0],
            ['د نقشی جوړول', 1, 0, 1, 900],
        ];

        foreach ($expenses as $index => [$item, $quantity, $unitAfghani, $dollars, $dollarUnit]) {
            Finance::create([
                'description' => '<p>'.$item.'</p>',
                'quantity' => $quantity,
                'unit' => $unitAfghani,
                'dollor' => $dollars,
                'dollor_unit' => $dollarUnit,
                'dollor_price' => 71,
                'phone_number' => 799100000 + $index,
                'user_id' => $userId,
                'date_purchase' => CarbonImmutable::now()->subDays(($index + 1) * 11)->toDateString(),
            ]);
        }
    }

    /**
     * @param  list<Numeraha>  $plots
     * @param  list<CustomerNumeraha>  $sales
     */
    private function archiveTwoUnsoldPlots(array $plots, array $sales): void
    {
        $soldIds = array_map(fn (CustomerNumeraha $sale): int => $sale->numeraha_id, $sales);
        $unsold = array_values(array_filter($plots, fn (Numeraha $plot): bool => ! in_array($plot->id, $soldIds, true)));

        foreach (array_slice($unsold, -2) as $plot) {
            $plot->archive();
        }
    }

    /**
     * The Word sale document embeds these photos, and PhpWord fails on a missing file.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function writePlaceholderImages(): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->command->warn('GD is not installed: sample customers have no photo, and their Word download will fail.');

            return [null, null];
        }

        $disk = Storage::disk('public');
        $disk->put(self::CUSTOMER_IMAGE, $this->silhouettePng([37, 99, 235]));
        $disk->put(self::RESPONSIBLE_IMAGE, $this->silhouettePng([22, 163, 74]));

        return [self::CUSTOMER_IMAGE, self::RESPONSIBLE_IMAGE];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function silhouettePng(array $rgb): string
    {
        $image = imagecreatetruecolor(300, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 229, 231, 235));
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledellipse($image, 150, 115, 110, 110, $color);
        imagefilledellipse($image, 150, 290, 220, 190, $color);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function dateInLastYear(): CarbonImmutable
    {
        $date = CarbonImmutable::now()
            ->subMonths(mt_rand(0, 11))
            ->startOfMonth()
            ->addDays(mt_rand(0, 25))
            ->setTime(mt_rand(8, 16), mt_rand(0, 59));

        return $date->isFuture() ? CarbonImmutable::now()->subHour() : $date;
    }
}
