<?php

namespace Tests\Feature\Seeders;

use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DemoSeeder com os discos do Cloudflare R2 (MEDIA_DISK=r2, INVOICES_DISK=r2-invoices): é assim
 * que uma máquina passa para o R2 (voltar a correr o seeder). Logótipos, portefólio e PDFs vão
 * para os discos configurados e nada fica nos discos locais. Os discos r2 são fakes locais.
 */
class DemoSeederStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('r2', ['url' => 'https://media.test']);
        Storage::fake('r2-invoices');
        config(['filesystems.media_disk' => 'r2', 'filesystems.invoices_disk' => 'r2-invoices']);
    }

    private function runDemoSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function logos_and_portfolio_images_go_to_the_media_disk(): void
    {
        $this->runDemoSeeder();

        $profiles = MemberProfile::listedInDirectory()->with('portfolios')->get();
        $this->assertNotEmpty($profiles);

        foreach ($profiles as $profile) {
            $this->assertNotNull($profile->logo_path, $profile->business_name);
            Storage::disk('r2')->assertExists($profile->logo_path);

            foreach ($profile->portfolios as $image) {
                Storage::disk('r2')->assertExists($image->image_path);
            }
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function demo_invoice_pdfs_go_to_the_invoices_disk(): void
    {
        $this->runDemoSeeder();

        $invoices = Invoice::where('stripe_invoice_id', 'like', 'in_demo_%')->get();
        $this->assertNotEmpty($invoices);

        foreach ($invoices as $invoice) {
            Storage::disk('r2-invoices')->assertExists($invoice->provider_invoice_id);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function running_again_deletes_old_demo_pdfs_on_the_invoices_disk(): void
    {
        $this->runDemoSeeder();

        $member = User::where('email', 'demo.membro@aspec.test')->firstOrFail();
        $stale = Invoice::factory()->for($member)->demo()->issued()->create(['stripe_invoice_id' => 'in_demo_antiga']);
        Storage::disk('r2-invoices')->put($stale->provider_invoice_id, '%PDF-1.4 fatura demo antiga');

        $this->runDemoSeeder();

        $this->assertDatabaseMissing('invoices', ['id' => $stale->id]);
        Storage::disk('r2-invoices')->assertMissing($stale->provider_invoice_id);
    }
}
