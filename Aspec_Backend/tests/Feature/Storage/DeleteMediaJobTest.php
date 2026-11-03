<?php

namespace Tests\Feature\Storage;

use App\Jobs\DeleteMediaJob;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Apagar imagens com novas tentativas (revisão de segurança ASPEC-444): com o R2 o disco pode falhar
 * sem lançar, e a imagem de um membro anonimizado ficava pública no bucket.
 */
class DeleteMediaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.media_disk' => 'r2']);
        Storage::fake('r2', ['url' => 'https://media.test']);
    }

    #[Test]
    public function deletes_a_file_from_the_media_disk(): void
    {
        Storage::disk('r2')->put('logos/abc/logo.png', 'x');
        Storage::disk('r2')->put('logos/abc/outro.png', 'x');

        (new DeleteMediaJob('logos/abc/logo.png'))->handle();

        Storage::disk('r2')->assertMissing('logos/abc/logo.png');
        Storage::disk('r2')->assertExists('logos/abc/outro.png');
    }

    #[Test]
    public function deletes_a_whole_directory_from_the_media_disk(): void
    {
        Storage::disk('r2')->put('portfolios/abc/1.jpg', 'x');
        Storage::disk('r2')->put('portfolios/abc/2.jpg', 'x');
        Storage::disk('r2')->put('portfolios/outro/1.jpg', 'x');

        (new DeleteMediaJob('portfolios/abc', directory: true))->handle();

        $this->assertSame([], Storage::disk('r2')->allFiles('portfolios/abc'));
        Storage::disk('r2')->assertExists('portfolios/outro/1.jpg');
    }

    #[Test]
    public function a_path_that_no_longer_exists_counts_as_deleted(): void
    {
        (new DeleteMediaJob('logos/abc/ja-apagado.png'))->handle();
        (new DeleteMediaJob('portfolios/abc', directory: true))->handle();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_disk_that_fails_without_throwing_makes_the_job_throw_so_the_queue_retries(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('logos/abc/logo.png')->andReturn(false);
        Storage::set('r2', $disk);

        $this->expectException(RuntimeException::class);

        (new DeleteMediaJob('logos/abc/logo.png'))->handle();
    }

    #[Test]
    public function retries_five_times_with_growing_waits(): void
    {
        $job = new DeleteMediaJob('logos/abc/logo.png');

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
    }

    #[Test]
    public function after_the_last_attempt_the_path_is_logged_for_manual_deletion(): void
    {
        Log::spy();

        (new DeleteMediaJob('portfolios/abc', directory: true))->failed(new RuntimeException('falhou'));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $context === ['path' => 'portfolios/abc', 'directory' => true]
        );
    }

    #[Test]
    public function anonymisation_queues_the_deletion_of_both_member_folders(): void
    {
        Queue::fake([DeleteMediaJob::class]);
        $user = User::factory()->create();

        $user->anonymizeAndDelete();

        Queue::assertPushed(DeleteMediaJob::class, 2);
        Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job) => $job->path === "logos/{$user->id}" && $job->directory);
        Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job) => $job->path === "portfolios/{$user->id}" && $job->directory);
    }
}
