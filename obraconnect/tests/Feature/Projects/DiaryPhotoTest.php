<?php

namespace Tests\Feature\Projects;

use App\Models\Media;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DiaryPhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /** @return array{0: User, 1: Tenant, 2: int, 3: int} dono, empresa, obra, registo */
    private function draftEntry(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $entry = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['entry_date' => now('Europe/Lisbon')->toDateString()])
            ->json('data.id');

        return [$owner, $tenant, $project, $entry];
    }

    private function upload(User $user, int $project, int $entry, UploadedFile $file, ?string $caption = null)
    {
        return $this->actingAs($user)->post("/api/v1/projects/{$project}/diary/{$entry}/photos", array_filter([
            'photo' => $file,
            'caption' => $caption,
        ]), ['Accept' => 'application/json']);
    }

    private function storageUsed(Tenant $tenant): int
    {
        return (int) $tenant->fresh()->storage_used_bytes;
    }

    /**
     * JPEG com um bloco EXIF mínimo: Orientation e DateTimeOriginal, como os de um telemóvel.
     * TIFF little-endian: IFD0 (2 entradas) em 8, IFD Exif (1 entrada) em 38, texto em 56.
     */
    private function jpegWithExif(int $width, int $height, int $orientation, string $takenAt): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $tiff = "II*\0".pack('V', 8)
            .pack('v', 2)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            .pack('vvVV', 0x8769, 4, 1, 38)
            .pack('V', 0)
            .pack('v', 1)
            .pack('vvVV', 0x9003, 2, 20, 56)
            .pack('V', 0)
            .$takenAt."\0";
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    public function test_original_is_kept_intact_and_previews_are_generated(): void
    {
        [$owner, $tenant, $project, $entry] = $this->draftEntry();
        $file = UploadedFile::fake()->image('laje-piso-1.jpg', 2400, 1800);
        $hash = hash_file('sha256', $file->getRealPath());

        $photo = $this->upload($owner, $project, $entry, $file, 'Armadura antes de betonar')
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.caption', 'Armadura antes de betonar')
            ->assertJsonPath('data.sha256', $hash)
            ->assertJsonPath('data.original_name', 'laje-piso-1.jpg')
            ->json('data');

        $original = $this->actingAs($owner)->get($photo['urls']['original'])->assertOk();
        $this->assertSame($hash, hash('sha256', $original->streamedContent()));
        $this->assertStringContainsString('attachment', (string) $original->headers->get('Content-Disposition'));

        $preview = getimagesizefromstring($this->actingAs($owner)->get($photo['urls']['preview'])->assertOk()->streamedContent());
        $thumb = getimagesizefromstring($this->actingAs($owner)->get($photo['urls']['thumb'])->assertOk()->streamedContent());
        $this->assertSame([1600, 1200, 'image/jpeg'], [$preview[0], $preview[1], $preview['mime']]);
        $this->assertSame([400, 300], [$thumb[0], $thumb[1]]);

        // Quota: original + versões reduzidas.
        $media = app(TenantContext::class)->runAs($tenant, fn () => Media::query()->findOrFail($photo['id']));
        $this->assertGreaterThan(0, $media->derived_bytes);
        $this->assertSame($media->size_bytes + $media->derived_bytes, $this->storageUsed($tenant));

        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/diary/{$entry}")
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.id', $photo['id']);
        $this->actingAs($owner)->getJson("/api/v1/projects/{$project}/diary")->assertJsonPath('data.0.photos_count', 1);
    }

    public function test_phone_orientation_and_date_come_from_exif(): void
    {
        [$owner, , $project, $entry] = $this->draftEntry();
        // Orientação 6: o sensor gravou a foto deitada; tem de ficar ao alto.
        $file = UploadedFile::fake()->createWithContent('IMG_0001.jpg', $this->jpegWithExif(300, 200, 6, '2026:11:02 14:30:00'));

        $this->upload($owner, $project, $entry, $file)
            ->assertCreated()
            ->assertJsonPath('data.width', 200)
            ->assertJsonPath('data.height', 300)
            ->assertJsonPath('data.taken_at', '2026-11-02T14:30:00+00:00');
    }

    public function test_only_photos_are_accepted(): void
    {
        [$owner, , $project, $entry] = $this->draftEntry();

        $this->upload($owner, $project, $entry, UploadedFile::fake()->create('caderno.pdf', 100, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        // A extensão não engana: conta o conteúdo.
        $this->upload($owner, $project, $entry, UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo 1;'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_storage_quota_of_the_plan_is_enforced(): void
    {
        [$owner, $tenant, $project, $entry] = $this->draftEntry();
        app(TenantContext::class)->runAs($tenant, fn () => Subscription::query()->latest('id')->first()->forceFill(['custom_limits' => ['storage_mb' => 1]])->save());
        $tenant->forceFill(['storage_used_bytes' => 1048576])->save();

        $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 800, 600))
            ->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');
        Storage::disk('private')->assertDirectoryEmpty('/');
    }

    public function test_photos_follow_the_rules_of_the_entry_and_the_project(): void
    {
        [$owner, $tenant, $project, $entry] = $this->draftEntry();
        $photo = $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 640, 480))->json('data');

        // Quem não está na equipa não vê os ficheiros; outra empresa nem sabe que existem.
        $outsider = $this->addMember($tenant, 'encarregado');
        $this->actingAs($outsider)->get($photo['urls']['thumb'], ['Accept' => 'application/json'])->assertForbidden();
        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->get($photo['urls']['original'], ['Accept' => 'application/json'])->assertNotFound();

        // Quem consulta o diário vê as fotografias, mas não as altera.
        $inspector = $this->addMember($tenant, 'fiscal');
        $membershipId = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $inspector->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membershipId])->assertCreated();
        $this->actingAs($inspector)->get($photo['urls']['preview'])->assertOk();
        $this->upload($inspector, $project, $entry, UploadedFile::fake()->image('b.jpg', 640, 480))->assertForbidden();

        // A legenda corrige-se enquanto é rascunho.
        $this->actingAs($owner)->patchJson("/api/v1/projects/{$project}/diary/{$entry}/photos/{$photo['id']}", ['caption' => 'Fachada norte'])
            ->assertOk()->assertJsonPath('data.caption', 'Fachada norte');

        // Submetido: fotografias fechadas com o registo.
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary/{$entry}/submit")->assertOk();
        $this->upload($owner, $project, $entry, UploadedFile::fake()->image('c.jpg', 640, 480))
            ->assertConflict()->assertJsonPath('code', 'DIARY_SUBMITTED');
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/diary/{$entry}/photos/{$photo['id']}")->assertConflict();
        $this->actingAs($owner)->get($photo['urls']['preview'])->assertOk();
    }

    public function test_photo_must_belong_to_the_entry_in_the_url(): void
    {
        [$owner, , $project, $entry] = $this->draftEntry();
        $other = $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/diary", ['entry_date' => now('Europe/Lisbon')->subDay()->toDateString()])->json('data.id');
        $photo = $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 640, 480))->json('data.id');

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/diary/{$other}/photos/{$photo}")->assertNotFound();
    }

    public function test_removing_photos_and_drafts_frees_files_and_quota(): void
    {
        [$owner, $tenant, $project, $entry] = $this->draftEntry();
        $first = $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 1200, 900))->json('data.id');
        $this->upload($owner, $project, $entry, UploadedFile::fake()->image('b.jpg', 1200, 900))->assertCreated();
        $paths = app(TenantContext::class)->runAs($tenant, fn () => Media::query()->get()->flatMap(fn ($m) => [$m->original_path, $m->preview_path, $m->thumb_path]));
        $this->assertCount(6, $paths->filter());

        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/diary/{$entry}/photos/{$first}")->assertNoContent();
        $remaining = app(TenantContext::class)->runAs($tenant, fn () => Media::query()->firstOrFail());
        $this->assertSame($remaining->size_bytes + $remaining->derived_bytes, $this->storageUsed($tenant));

        // Apagar o rascunho apaga as fotografias que tinha.
        $this->actingAs($owner)->deleteJson("/api/v1/projects/{$project}/diary/{$entry}")->assertNoContent();
        $paths->each(fn ($path) => Storage::disk('private')->assertMissing($path));
        $this->assertSame(0, $this->storageUsed($tenant));
        $this->assertSame(0, app(TenantContext::class)->runAs($tenant, fn () => Media::query()->count()));
    }

    public function test_photo_count_per_entry_is_limited(): void
    {
        config(['obraconnect.files.photos_per_entry' => 1]);
        [$owner, , $project, $entry] = $this->draftEntry();

        $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 640, 480))->assertCreated();
        $this->upload($owner, $project, $entry, UploadedFile::fake()->image('b.jpg', 640, 480))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_photo_list_is_in_the_data_export(): void
    {
        Mail::fake();
        [$owner, $tenant, $project, $entry] = $this->draftEntry();
        $hash = $this->upload($owner, $project, $entry, UploadedFile::fake()->image('a.jpg', 640, 480))->json('data.sha256');

        $exportId = $this->actingAs($owner)->postJson('/api/v1/exports')->assertCreated()->json('data.id');
        $path = app(TenantContext::class)->runAs($tenant, fn () => TenantExport::query()->findOrFail($exportId)->path);

        $local = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($local, Storage::disk('private')->get($path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local));
        $photos = json_decode($zip->getFromName('photos.json'), true);
        $zip->close();
        unlink($local);

        $this->assertSame($hash, $photos[0]['sha256']);
        $this->assertSame(now('Europe/Lisbon')->toDateString(), $photos[0]['diary_date']);
    }
}
